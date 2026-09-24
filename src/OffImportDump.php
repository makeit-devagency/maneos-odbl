<?php

namespace App\Console\Commands;

use App\Services\Off\OffProductCuration;
use App\Services\Off\OffProductMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import UNIC al produselor Open Food Facts vandute in RO/MD.
 *
 * De ce dump si nu API: API-ul de cautare taie la ~10.000 de rezultate per
 * interogare (peste offset 10k raspunde 401/503), iar Romania are ~46.000 de
 * produse. Paginarea simpla nu poate ajunge la ele, oricat de rabdatori am fi.
 *
 * Rulare unica, nu sincronizare. Nu exista job programat, nu se urmareste
 * `last_delta`: dupa import lucram din baza noastra, iar produsele din afara
 * setului local raman pe calea veche (cache la cerere, la prima scanare).
 *
 * Nu STERGE nimic: produsele deja cache-uite prin scanari — inclusiv cele care
 * NU sunt vandute in RO/MD — sunt pastrate. De aceea finalul e un upsert din
 * staging, nu un `TRUNCATE` + rename, care ar fi aruncat exact produsele pe
 * care utilizatorii nostri le-au scanat deja.
 */
class OffImportDump extends Command
{
    protected $signature = 'off:import
        {--file= : Dump local (.csv.gz sau .jsonl.gz). Fara el, se descarca.}
        {--source=csv : Ce export se descarca: `csv` (1,2 GB) sau `jsonl` (12 GB).}
        {--keep-file : Pastreaza dump-ul descarcat dupa import.}
        {--limit=0 : Opreste dupa N produse acceptate (pentru probe).}
        {--dry-run : Parcurge si raporteaza, fara sa scrie in baza.}';

    protected $description = 'Importa o singura data produsele OFF vandute in RO/MD din dump-ul oficial.';

    /*
     | DOUA exporturi, acelasi continut pentru ce ne trebuie noua.
     |
     | Implicit e CSV-ul, si nu din comoditate:
     |  - 1,19 GB fata de 11,97 GB, deci de zece ori mai putin timp pe retea si
     |    de zece ori mai putina expunere la o transmisie corupta — prima
     |    incercare pe JSONL a adus 12 GB completi ca dimensiune, dar cu fluxul
     |    deteriorat la ~7 GB, iar `gzcat` a confirmat: „data stream error";
     |  - are coloana `image_url` gata construita, in timp ce JSONL-ul cere
     |    reconstructie din `images.selected`.
     |
     | JSONL-ul ramane disponibil prin `--source=jsonl` fiindca are campuri pe
     | limbi (`product_name_en`) pe care CSV-ul nu le expune.
     */
    private const CSV_URL = 'https://static.openfoodfacts.org/data/en.openfoodfacts.org.products.csv.gz';

    private const JSONL_URL = 'https://static.openfoodfacts.org/data/openfoodfacts-products.jsonl.gz';

    /** Cate randuri trimitem odata catre staging. */
    private const BATCH = 1000;

    public function handle(OffProductMapper $mapper): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $path = $this->option('file');
        $downloaded = false;

        if ($path === null) {
            $csv = $this->option('source') !== 'jsonl';
            $path = storage_path($csv ? 'app/off-dump.csv.gz' : 'app/off-dump.jsonl.gz');

            if (! $this->download($csv ? self::CSV_URL : self::JSONL_URL, $path)) {
                return self::FAILURE;
            }
            $downloaded = true;
        }

        if (! is_readable($path)) {
            $this->error("Fisierul nu poate fi citit: {$path}");

            return self::FAILURE;
        }

        /*
         | Integritatea se verifica INAINTE de parcurgere.
         |
         | La incercarea anterioara, arhiva corupta a fost descoperita abia dupa
         | ce importul a mers pana la capat — adica dupa ore. O trecere de
         | verificare peste 1,2 GB dureaza sub un minut si transforma „ore
         | pierdute" in „un minut pierdut".
         */
        if (! $this->verifyArchive($path)) {
            return self::FAILURE;
        }

        $result = $this->ingest($path, $mapper, $limit, $dryRun);

        if ($downloaded && ! $this->option('keep-file')) {
            @unlink($path);
            $this->line('Dump-ul a fost sters (ruleaza cu --keep-file ca sa-l pastrezi).');
        }

        return $result;
    }

    /**
     * Descarcare cu reluare.
     *
     * 12 GB pe o conexiune casnica inseamna zeci de minute; o intrerupere la
     * 90% ar fi costat tot. Cu `Range` reluam de unde am ramas.
     */
    private function download(string $url, string $path): bool
    {
        $existing = is_file($path) ? filesize($path) : 0;

        if ($existing > 0) {
            $this->info('Reiau descarcarea de la '.$this->humanBytes($existing).'.');
        } else {
            $this->info(sprintf(
                'Descarc exportul OFF (%s, ~%s).',
                str_contains($url, '.csv.') ? 'CSV' : 'JSONL',
                str_contains($url, '.csv.') ? '1,2 GB' : '12 GB'
            ));
        }

        $context = stream_context_create(['http' => [
            'follow_location' => 1,
            'timeout' => 120,
            'header' => array_filter([
                'User-Agent: maneos/1.0 (import unic RO/MD)',
                $existing > 0 ? "Range: bytes={$existing}-" : null,
            ]),
        ]]);

        $in = @fopen($url, 'rb', false, $context);
        if ($in === false) {
            $this->error('Nu am putut deschide '.$url);

            return false;
        }

        // 206 = serverul a acceptat reluarea; 200 = trimite tot de la zero, deci
        // ce aveam pe disc nu mai e un prefix valid si trebuie rescris.
        $resumed = $existing > 0 && $this->statusOf($http_response_header ?? []) === 206;
        $out = fopen($path, $resumed ? 'ab' : 'wb');
        if ($out === false) {
            fclose($in);
            $this->error('Nu pot scrie in '.$path);

            return false;
        }

        $expected = $this->expectedTotal($http_response_header ?? []);
        $written = $resumed ? $existing : 0;
        $lastReport = 0;

        while (! feof($in)) {
            $chunk = fread($in, 1 << 20);

            if ($chunk === false) {
                break;
            }

            /*
             | O citire goala NU inseamna sfarsitul fisierului.
             |
             | Pe un socket lent, `fread` poate intoarce '' fara ca `feof` sa fie
             | adevarat. Daca am iesi aici, am obtine un dump trunchiat despre
             | care comanda ar raporta succes — iar importul ar rula pe jumatate
             | de baza, tacut. Iesim doar pe EOF real, verificat mai jos.
             */
            if ($chunk === '') {
                continue;
            }

            fwrite($out, $chunk);
            $written += strlen($chunk);

            if ($written - $lastReport >= 250 * (1 << 20)) {
                $this->line('  ...'.$this->humanBytes($written));
                $lastReport = $written;
            }
        }

        fclose($in);
        fclose($out);

        /*
         | Verificarea care face diferenta intre „gata" si „pare gata".
         |
         | Fisierul ramane pe disc: o reluare continua de unde s-a oprit, in loc
         | sa reia 12 GB de la zero.
         */
        if ($expected !== null && $written < $expected) {
            $this->error(sprintf(
                'Descarcare incompleta: %s din %s. Reruleaza comanda — continua de unde a ramas.',
                $this->humanBytes($written), $this->humanBytes($expected)
            ));

            return false;
        }

        $this->info('Descarcat: '.$this->humanBytes($written));

        return $written > 0;
    }

    /**
     * Dimensiunea totala asteptata, din anteturile RASPUNSULUI FINAL.
     *
     * Cu `follow_location`, `$http_response_header` contine anteturile TUTUROR
     * raspunsurilor, in ordine. Prima versiune lua primul `Content-Length`
     * gasit — adica pe al redirectului 302 catre S3, care e `145`. Verificarea
     * compara asadar 12 GB cu 145 de octeti si trecea intotdeauna, exact cand
     * ar fi trebuit sa prinda un dump trunchiat.
     *
     * Deci: resetam la fiecare linie de status si pastram ultima valoare.
     * `Content-Range` are prioritate — la o reluare, totalul e dupa slash, in
     * timp ce `Content-Length` e doar restul ramas de descarcat.
     *
     * @param  list<string>  $headers
     */
    private function expectedTotal(array $headers): ?int
    {
        $length = null;
        $rangeTotal = null;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/#i', $header)) {
                // Raspuns nou (redirect urmat) — ce am strans pana acum
                // apartine raspunsului precedent si nu mai e valabil.
                $length = null;
                $rangeTotal = null;

                continue;
            }

            if (preg_match('#^Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $header, $m)) {
                $rangeTotal = (int) $m[1];
            } elseif (preg_match('#^Content-Length:\s*(\d+)#i', $header, $m)) {
                $length = (int) $m[1];
            }
        }

        return $rangeTotal ?? $length;
    }

    /** @param  list<string>  $headers */
    private function statusOf(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Parcurge dump-ul si umple staging-ul, apoi comuta in `off_products`.
     */
    private function ingest(string $path, OffProductMapper $mapper, int $limit, bool $dryRun): int
    {
        /*
         | Formatul se deduce din nume, nu din `--source`: acesta din urma
         | priveste doar descarcarea, iar un fisier dat cu `--file` poate fi
         | oricare dintre cele doua.
         */
        $isCsv = str_contains(strtolower($path), '.csv');

        $reader = $isCsv ? $this->csvRows($path) : $this->jsonlRows($path);

        if (! $dryRun) {
            DB::statement('TRUNCATE off_products_staging');
        }

        $seen = 0;
        $candidates = 0;
        $accepted = 0;
        $rejected = 0;
        $buffer = [];
        $now = now();
        $started = microtime(true);

        foreach ($reader as $product) {
            $seen++;

            if (! is_array($product)) {
                continue;
            }

            $tags = array_map(
                static fn ($t) => strtolower(trim((string) $t)),
                (array) ($product['countries_tags'] ?? [])
            );
            if (! array_intersect($tags, ['en:romania', 'en:moldova'])) {
                continue;
            }

            $candidates++;

            $row = $mapper->map($product);
            if ($row === null) {
                // Fara cod, fara nume sau fara calorii — mapper-ul le respinge
                // din aceleasi motive ca la scanare: un card gol nu ajuta.
                $rejected++;
                continue;
            }

            $buffer[] = $row + ['created_at' => $now, 'updated_at' => $now];
            $accepted++;

            if (count($buffer) >= self::BATCH) {
                if (! $dryRun) {
                    DB::table('off_products_staging')->insert($buffer);
                }
                $buffer = [];
                $this->line(sprintf(
                    '  %s linii citite · %s acceptate · %s respinse',
                    number_format($seen), number_format($accepted), number_format($rejected)
                ));
            }

            if ($limit > 0 && $accepted >= $limit) {
                break;
            }
        }

        // Ultimul lot, cel care nu a ajuns la BATCH. Fara el, un import mai mic
        // decat un lot intreg nu scrie absolut nimic.
        if ($buffer !== [] && ! $dryRun) {
            DB::table('off_products_staging')->insert($buffer);
        }

        $elapsed = round(microtime(true) - $started);

        $this->newLine();
        $this->info(sprintf(
            'Parcurse %s linii in %ss · %s candidate RO/MD · %s acceptate · %s respinse',
            number_format($seen), $elapsed, number_format($candidates),
            number_format($accepted), number_format($rejected)
        ));

        if ($dryRun) {
            $this->warn('--dry-run: nu s-a scris nimic in baza.');

            return self::SUCCESS;
        }

        $upserted = $this->promote();
        $this->recordState($upserted);

        $this->info('Promovate in off_products: '.number_format($upserted));
        $this->info('Total off_products: '.number_format(DB::table('off_products')->count()));

        return self::SUCCESS;
    }

    /**
     * Dimensiunea necomprimata declarata de arhiva, din ultimii 4 octeti.
     *
     * E campul ISIZE al formatului gzip, little-endian, modulo 4 GB. Pe o
     * arhiva trunchiata acei octeti sunt de fapt mijlocul unui bloc deflate,
     * deci nu se vor potrivi — exact ce vrem sa prindem.
     *
     * Presupune o arhiva cu un singur membru (ce produce `gzip`). Daca dump-ul
     * ar deveni vreodata multi-membru, verificarea ar da alarma fals — dar
     * zgomotos, nu tacut, si asta e partea care conteaza.
     */
    private function gzipUncompressedSize(string $path): ?int
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }

        if (fseek($fp, -4, SEEK_END) !== 0) {
            fclose($fp);

            return null;
        }

        $raw = fread($fp, 4);
        fclose($fp);

        if ($raw === false || strlen($raw) !== 4) {
            return null;
        }

        /** @var array{1: int}|false $parsed */
        $parsed = unpack('V', $raw);

        return $parsed === false ? null : $parsed[1];
    }

    /**
     * Randurile din exportul CSV, in aceeasi forma pe care o produce JSONL-ul.
     *
     * Conversia se face aici, nu in mapper: mapper-ul e folosit si de calea de
     * scanare (API-ul OFF), iar el trebuie sa ramana cu o singura forma de
     * intrare. Aici traducem CSV -> forma API.
     *
     * TSV FARA caracter de incadrare, deci `explode`, nu `fgetcsv`.
     *
     * `fgetcsv` trata `"` drept ghilimea de deschidere si inghitea randurile
     * urmatoare pana la urmatorul `"`. Exportul OFF nu citeaza insa nimic: o
     * ghilimea ramasa intr-un nume de produs e text obisnuit. Masurat pe
     * exportul din 15 septembrie: 39.005 candidate RO/MD citite cu `fgetcsv`
     * fata de 39.072 cu `explode("\t")` — 61 de produse bune pierdute tacut,
     * plus randurile innecate in campul care le-a inghitit.
     *
     * Fara incadrare, un camp nu poate contine separatorul sau un sfarsit de
     * rand — nici nu are cum, in formatul asta.
     *
     * @return \Generator<int, array<string,mixed>|null>
     */
    private function csvRows(string $path): \Generator
    {
        $fp = fopen('compress.zlib://'.$path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException('Nu pot deschide arhiva: '.$path);
        }

        try {
            $headerLine = fgets($fp);
            if ($headerLine === false) {
                throw new \RuntimeException('Exportul CSV nu are antet.');
            }

            $header = explode("\t", rtrim($headerLine, "\r\n"));
            $index = array_flip($header);
            $columns = count($header);

            while (($line = fgets($fp)) !== false) {
                $row = explode("\t", rtrim($line, "\r\n"));

                // Un rand cu alt numar de coloane e deteriorat; il sarim, dar
                // nu oprim importul pentru el.
                yield count($row) === $columns ? $this->csvRowToProduct($index, $row) : null;
            }

            if (! feof($fp)) {
                throw new \RuntimeException('Citirea CSV s-a oprit inainte de sfarsitul arhivei.');
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param  array<string,int>  $index
     * @param  list<string>  $row
     * @return array<string,mixed>
     */
    private function csvRowToProduct(array $index, array $row): array
    {
        $get = static function (string $column) use ($index, $row): ?string {
            if (! isset($index[$column])) {
                return null;
            }
            $value = trim((string) ($row[$index[$column]] ?? ''));

            return $value === '' ? null : $value;
        };

        $tags = static function (?string $value): array {
            return $value === null ? [] : array_filter(array_map('trim', explode(',', $value)));
        };

        $nutriments = [];
        foreach ([
            'energy-kcal_100g', 'energy_100g', 'proteins_100g', 'carbohydrates_100g',
            'fat_100g', 'fiber_100g', 'sugars_100g', 'sodium_100g', 'salt_100g',
        ] as $key) {
            $value = $get($key);
            if ($value !== null) {
                $nutriments[$key] = $value;
            }
        }

        return [
            'code' => $get('code'),
            'product_name' => $get('product_name'),
            'brands' => $get('brands'),
            'quantity' => $get('quantity'),
            // CSV-ul da URL-ul gata construit; mapper-ul il prefera oricum
            // in fata reconstructiei din `images.selected`.
            'image_front_url' => $get('image_url'),
            'categories_tags' => $tags($get('categories_tags')),
            'countries_tags' => $tags($get('countries_tags')),
            'nutriments' => $nutriments,
            // Doua campuri diferite: `serving_quantity` e portia in grame (ce
            // citeste mapper-ul), `serving_size` e textul de pe eticheta
            // („1 pahar (200 ml)"). Trimis doar al doilea, `serving_size_g`
            // ramanea null la TOATE produsele.
            'serving_quantity' => $get('serving_quantity'),
            'serving_size' => $get('serving_size'),
            'nutriscore_grade' => $get('nutriscore_grade'),
            'last_modified_t' => $get('last_modified_t'),
        ];
    }

    /**
     * Randurile din exportul JSONL.
     *
     * Randurile care nu trec pre-filtrul ies ca `null`: apelantul le numara ca
     * linii citite, dar nu le desface. Decodarea a ~4 milioane de obiecte JSON
     * mari ar dura ore si ar fi aruncata pentru 99% dintre ele.
     *
     * @return \Generator<int, array<string,mixed>|null>
     */
    private function jsonlRows(string $path): \Generator
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('Nu pot deschide arhiva: '.$path);
        }

        try {
            while (($line = gzgets($gz)) !== false) {
                if (! $this->looksLocal($line)) {
                    yield null;

                    continue;
                }

                $decoded = json_decode($line, true);
                yield is_array($decoded) ? $decoded : null;
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Decomprima arhiva in intregime si compara cu dimensiunea pe care o
     * declara ea insasi in ultimii 4 octeti (campul ISIZE al formatului gzip).
     *
     * Nu e paranoia: incercarea anterioara a descarcat 12.852.483.017 octeti —
     * exact cat anunta serverul — dar fluxul comprimat era deteriorat pe la 7 GB.
     * `gzcat` a confirmat independent: „data stream error / uncompress failed".
     * O dimensiune corecta nu spune nimic despre continut.
     */
    private function verifyArchive(string $path): bool
    {
        $this->line('Verific integritatea arhivei...');

        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            $this->error('Nu pot deschide arhiva: '.$path);

            return false;
        }

        $bytes = 0;
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 1 << 20);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
        }
        gzclose($gz);

        $declared = $this->gzipUncompressedSize($path);

        if ($declared !== null && ($bytes % 4294967296) !== $declared) {
            $this->error(sprintf(
                'Arhiva e corupta: decomprimati %s octeti, dar arhiva declara %s (mod 4 GB).',
                number_format($bytes),
                number_format($declared)
            ));
            $this->warn('Sterge fisierul si reia descarcarea — continutul primit nu e valid.');

            return false;
        }

        $this->info('Arhiva e intacta ('.number_format($bytes).' octeti necomprimati).');

        return true;
    }

    /** Tagurile sunt ASCII: un test pe textul brut evita un json_decode inutil. */
    private function looksLocal(string $line): bool
    {
        return str_contains($line, 'en:romania') || str_contains($line, 'en:moldova');
    }

    /**
     * Staging -> `off_products`, pastrand ce exista deja.
     *
     * `DISTINCT ON` nu e optional: daca dump-ul contine doua randuri cu acelasi
     * gtin14 (coduri normalizate care se ciocnesc), Postgres refuza intreg
     * upsert-ul cu „ON CONFLICT DO UPDATE command cannot affect row a second
     * time". Pastram randul cel mai recent.
     *
     * CURATAREA MANUALA SUPRAVIETUIESTE IMPORTULUI. Fara asta, o corectie facuta
     * din dashboard ar fi disparut aici, tacut: fara eroare si fara log, doar
     * valoarea veche inapoi pe ecran la urmatoarea rulare. Concret:
     *
     *  - campurile trecute in `overrides` isi pastreaza valoarea, restul se
     *    actualizeaza normal — un produs corectat la nume primeste in continuare
     *    caloriile si poza noua de la OFF;
     *  - randurile `is_manual` nu se ating deloc: nu au pereche in upstream, iar
     *    o „actualizare" ar fi inlocuit munca omului cu date despre alt produs;
     *  - `hidden_at` si `overrides` nu apar in staging, deci nu pot fi rescrise
     *    nici din greseala — stergerea reversibila si lista de corectii sunt
     *    inaccesibile importului prin constructie, nu prin grija.
     */
    private function promote(): int
    {
        $raw = $this->stagingColumns();
        $list = implode(', ', array_map(static fn ($c) => '"'.$c.'"', $raw));

        $updates = implode(', ', array_map(
            static function (string $column): string {
                $quoted = '"'.$column.'"';

                if (! in_array($column, OffProductCuration::EDITABLE, true)) {
                    return "{$quoted} = EXCLUDED.{$quoted}";
                }

                /*
                 | `jsonb_exists(...)`, nu operatorul `?`.
                 |
                 | Sunt echivalente in Postgres, dar interogarea trece prin PDO,
                 | care citeste `?` ca marcaj de parametru. Cu operatorul, fiecare
                 | coloana editabila ar fi cerut un parametru inexistent si
                 | comanda ar fi murit cu „invalid parameter number".
                 */
                return "{$quoted} = CASE WHEN jsonb_exists(off_products.overrides, '{$column}')"
                    ." THEN off_products.{$quoted} ELSE EXCLUDED.{$quoted} END";
            },
            array_filter($raw, static fn ($c) => $c !== 'gtin14' && $c !== 'created_at')
        ));

        return DB::affectingStatement(<<<SQL
            INSERT INTO off_products ({$list})
            SELECT DISTINCT ON (gtin14) {$list}
              FROM off_products_staging
             ORDER BY gtin14, off_last_modified DESC NULLS LAST
            ON CONFLICT (gtin14) DO UPDATE SET {$updates}
             WHERE NOT off_products.is_manual
        SQL);
    }

    /** @return list<string> */
    private function stagingColumns(): array
    {
        $rows = DB::select(
            "SELECT column_name FROM information_schema.columns
              WHERE table_name = 'off_products_staging'
              ORDER BY ordinal_position"
        );

        return array_map(static fn ($r) => $r->column_name, $rows);
    }

    private function recordState(int $upserted): void
    {
        DB::table('off_sync_state')->update([
            // `last_delta` ramane null intentionat: importul e unic, nu exista
            // lant de delta-uri de continuat.
            'last_run_at' => now(),
            'last_status' => 'import unic RO/MD reusit',
            'last_rows_upserted' => $upserted,
            'updated_at' => now(),
        ]);
    }

    private function humanBytes(int $bytes): string
    {
        return number_format($bytes / (1 << 30), 2).' GB';
    }
}

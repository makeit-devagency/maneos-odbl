<?php

namespace App\Services\Off;

use Illuminate\Support\Facades\DB;

/**
 * Rezolva un cod de bare la un produs, cu cache local peste API-ul OFF.
 *
 * STRATEGIA (decizie de produs, in locul importului complet din brief):
 * nu incarcam 4 milioane de randuri si nu sincronizam zilnic. Tabela
 * `off_products` tine produsele vandute in RO/MD, aduse o singura data prin
 * `off:import`, plus cele pe care utilizatorii nostri chiar le scaneaza:
 *
 *   1. cautare locala prin `off_lookup()` — sub o milisecunda;
 *   2. daca lipseste, O SINGURA cerere la OFF, salvata local;
 *   3. urmatorul care scaneaza acelasi produs il primeste instant.
 *
 * Compromisul, spus pe fata: primul om care scaneaza un produs nou asteapta
 * ~200-500 ms si depinde de disponibilitatea OFF. Toti ceilalti, nu. In schimb
 * dispar 4-6 GB de stocare permanenta si intreaga infrastructura de
 * sincronizare zilnica, cu alertele ei.
 */
class OffProductResolver
{
    /**
     * Dupa cate zile re-interogam OFF pentru un produs deja salvat.
     *
     * Valorile nutritionale ale unui produs ambalat se schimba rar, dar se
     * schimba (reformulari, corecturi in comunitate). O luna e un compromis
     * intre prospetime si numarul de cereri — care sunt resursa limitata aici.
     */
    private const REFRESH_AFTER_DAYS = 30;

    public function __construct(
        private OffApiClient $api,
        private OffProductMapper $mapper
    ) {}

    /**
     * @return array<string,mixed>|null  randul din `off_products`, sau null daca
     *         produsul nu exista nici local, nici la OFF.
     */
    public function resolve(string $barcode): ?array
    {
        $local = $this->findLocal($barcode);

        if ($local !== null) {
            /*
             | Ascuns de un admin = inexistent, si ne OPRIM aici.
             |
             | Tentant ar fi fost sa lasam codul sa curga mai departe si sa
             | intoarca `null` la final. Ar fi insemnat insa o cerere la OFF,
             | urmata de un upsert care readuce produsul — adica exact stergerea
             | anulata de scanarea urmatoare, fara ca cineva sa fi cerut asta.
             */
            if (($local['hidden_at'] ?? null) !== null) {
                return null;
            }

            /*
             | Rand introdus manual din dashboard: nu are pereche in upstream, deci
             | nu are de unde sa se improspateze. O cerere la OFF ar fi intors
             | „produs inexistent" in cel mai bun caz, iar in cel mai rau alt
             | produs cu acelasi cod.
             */
            if ((bool) ($local['is_manual'] ?? false)) {
                return $local;
            }

            if (! $this->isStale($local)) {
                return $local;
            }
        }

        $remote = $this->api->fetchProduct($barcode);
        if ($remote === null) {
            // OFF n-a raspuns sau nu are produsul. Daca aveam o copie invechita,
            // e mai buna decat nimic — datele vechi bat un ecran gol.
            return $local;
        }

        $mapped = $this->mapper->map($remote);
        if ($mapped === null) {
            return $local;
        }

        $this->persist($mapped, $local);

        return $this->findLocal($barcode) ?? $mapped;
    }

    /** Cautare strict locala, fara sa atinga reteaua. */
    public function findLocal(string $barcode): ?array
    {
        $row = DB::selectOne('SELECT * FROM off_lookup(?)', [$barcode]);

        return $row ? (array) $row : null;
    }

    private function isStale(array $row): bool
    {
        $updatedAt = $row['updated_at'] ?? null;

        return $updatedAt === null
            || strtotime((string) $updatedAt) < strtotime('-'.self::REFRESH_AFTER_DAYS.' days');
    }

    /**
     * @param  array<string,mixed>  $data  randul mapat din raspunsul OFF
     * @param  array<string,mixed>|null  $existing  randul din baza, daca exista
     */
    private function persist(array $data, ?array $existing): void
    {
        // Randurile manuale nu se rescriu niciodata din upstream. `resolve()` se
        // opreste deja inainte sa ajunga aici; garda ramane fiindca pretul unei
        // verificari e nul, iar pretul unei greseli e munca altcuiva pierduta.
        if ($existing !== null && (bool) ($existing['is_manual'] ?? false)) {
            return;
        }

        $now = now();

        /*
         | Coloanele corectate manual raman pe loc.
         |
         | Restul se actualizeaza normal: un produs caruia adminul i-a corectat
         | numele isi primeste in continuare poza si caloriile noi de la OFF.
         | Fara filtrul asta, calea de scanare ar fi sters corectiile chiar mai
         | des decat importul — la prima scanare de dupa 30 de zile.
         */
        $locked = $existing === null ? [] : OffProductCuration::lockedColumns($existing);
        $update = array_values(array_diff(array_keys($data), $locked, ['gtin14']));

        /*
         | `updated_at` se scrie CHIAR daca toate campurile sunt blocate: el e si
         | marcajul de prospetime citit de `isStale`. Lipsa lui ar fi insemnat o
         | cerere la OFF la fiecare scanare a unui produs complet corectat.
         |
         | `array_merge`, nu `+`: operatorul de uniune pastreaza cheile, deci
         | ar fi produs un array mixt in care „updated_at" ajungea VALOARE, nu
         | nume de coloana — iar Postgres primea string-ul ca timestamp.
         */
        DB::table('off_products')->upsert(
            [$data + ['created_at' => $now, 'updated_at' => $now]],
            ['gtin14'],
            array_merge($update, ['updated_at'])
        );
    }
}

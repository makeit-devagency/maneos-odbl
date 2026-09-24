<?php

namespace App\Services\Off;

/**
 * Transforma un produs din API-ul Open Food Facts in randul nostru din
 * `off_products`.
 *
 * Functii pure, fara I/O — de aceea sunt testabile direct, iar fiecare regula
 * de mai jos are un test dedicat. Nu e zel: fiecare dintre ele corecteaza o
 * eroare TACUTA, din categoria celor care nu dau exceptii, ci doar date gresite
 * afisate cu incredere utilizatorului.
 */
class OffProductMapper
{
    /** Limbile pentru nume, in ordinea preferintei. */
    private const NAME_LOCALES = ['ro', 'en', 'fr', 'de', 'it', 'es'];

    /** Tarile care fac un produs „local" (prioritate in cautare). */
    private const LOCAL_COUNTRIES = ['en:romania', 'en:moldova'];

    /**
     * Tag OFF -> categoria maneos. Prima potrivire castiga, deci ordinea
     * conteaza: `en:cheeses` trebuie sa prinda `dairy` inaintea unui tag mai
     * generic care ar duce spre `proteins`.
     *
     * Lista e deliberat scurta: acopera cazurile frecvente, iar restul ramane
     * `null`. O categorie gresita e mai rea decat una lipsa — utilizatorul
     * filtreaza dupa ea.
     */
    private const CATEGORY_RULES = [
        'beverages' => ['en:beverages', 'en:waters', 'en:juices', 'en:sodas', 'en:teas', 'en:coffees'],
        'dairy' => ['en:dairies', 'en:milks', 'en:cheeses', 'en:yogurts', 'en:creams', 'en:butters'],
        'proteins' => ['en:meats', 'en:poultry', 'en:fishes', 'en:seafood', 'en:eggs', 'en:legumes', 'en:tofu'],
        'grains' => ['en:cereals', 'en:breads', 'en:pastas', 'en:rice', 'en:flours', 'en:breakfast-cereals'],
        'fruits' => ['en:fruits', 'en:berries', 'en:dried-fruits'],
        'vegetables' => ['en:vegetables', 'en:legume-vegetables', 'en:canned-vegetables'],
        'fats' => ['en:fats', 'en:vegetable-oils', 'en:olive-oils', 'en:margarines'],
        'snacks' => ['en:snacks', 'en:biscuits', 'en:chocolates', 'en:confectioneries', 'en:crisps', 'en:spreads'],
        'prepared' => ['en:meals', 'en:prepared-meals', 'en:sandwiches', 'en:pizzas', 'en:soups'],
    ];

    /**
     * Normalizeaza orice cod de bare la GTIN-14.
     *
     * Cea mai frecventa cauza de „produs negasit" FALS: OFF poate stoca un
     * produs ca UPC-12, iar scanner-ul intoarce EAN-13 cu zero in fata. Fara o
     * forma canonica, aceleasi cifre nu se regasesc.
     */
    public static function normalizeBarcode(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if ($digits === '' || strlen($digits) > 14) {
            return null;
        }

        return str_pad($digits, 14, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string,mixed>  $product  nodul `product` din raspunsul API
     * @return array<string,mixed>|null  null daca produsul nu e utilizabil
     */
    public function map(array $product): ?array
    {
        $gtin14 = self::normalizeBarcode($product['code'] ?? null);
        $name = $this->pickName($product);
        $nutriments = $product['nutriments'] ?? [];
        $calories = $this->calories($nutriments);

        /*
         | Produsele fara cod valid, fara nume sau fara calorii sunt respinse.
         |
         | Nu din pedanterie: un card de produs scanat fara nume sau fara
         | calorii nu-i spune utilizatorului nimic si nu poate fi adaugat in
         | jurnal. Mai bine „nu gasim acest produs", cu optiunea de a-l adauga
         | manual, decat un card gol.
         */
        if ($gtin14 === null || $name === null || $calories === null) {
            return null;
        }

        $countries = $this->countries($product);

        return [
            'gtin14' => $gtin14,
            'barcode' => (string) ($product['code'] ?? ''),
            'name' => mb_substr($name, 0, 300),
            'brand' => $this->firstBrand($product),
            'quantity' => $this->trimmedOrNull($product['quantity'] ?? null, 60),
            'image_url' => $this->imageUrl($product),
            'category' => $this->category($product),
            'off_categories' => implode(',', array_slice((array) ($product['categories_tags'] ?? []), 0, 40)),

            'calories_per_100g' => $calories,
            'protein_per_100g' => $this->macro($nutriments, 'proteins_100g'),
            'carbs_per_100g' => $this->macro($nutriments, 'carbohydrates_100g'),
            'fat_per_100g' => $this->macro($nutriments, 'fat_100g'),
            'fiber_per_100g' => $this->macro($nutriments, 'fiber_100g'),
            'sugar_per_100g' => $this->macro($nutriments, 'sugars_100g'),
            'sodium_per_100g_mg' => $this->sodiumMg($nutriments),

            'serving_size_g' => $this->servingGrams($product),
            'nutriscore' => $this->nutriscore($product),

            'countries' => implode(',', $countries),
            'is_local' => (bool) array_intersect($countries, self::LOCAL_COUNTRIES),
            'off_last_modified' => isset($product['last_modified_t'])
                ? date('Y-m-d H:i:sP', (int) $product['last_modified_t'])
                : null,
        ];
    }

    /**
     * URL-ul imaginii din fata.
     *
     * DOUA surse, fiindca API-ul si dump-ul nu au aceeasi schema:
     *  - API-ul intoarce `image_front_url` gata construit;
     *  - dump-ul NU are campul deloc (verificat: 0 din 400 de inregistrari).
     *    Are in schimb `images.selected.front.<limba>` cu `rev`, din care URL-ul
     *    se poate construi.
     *
     * Prima varianta a acestui mapper citea doar campurile API-ului, iar la
     * primul import niciun produs n-a primit poza.
     */
    private function imageUrl(array $product): ?string
    {
        $direct = $this->trimmedOrNull(
            $product['image_front_url'] ?? $product['image_url'] ?? null,
            500
        );
        if ($direct !== null) {
            return $this->usableImage($direct);
        }

        $front = $product['images']['selected']['front'] ?? null;
        if (! is_array($front) || $front === []) {
            return null;
        }

        // Aceeasi ordine de limbi ca la nume, ca poza sa fie a ambalajului pe
        // care il vede utilizatorul, nu a unei alte piete.
        $chosen = null;
        foreach (array_merge(self::NAME_LOCALES, array_keys($front)) as $locale) {
            if (isset($front[$locale]) && is_array($front[$locale])) {
                $chosen = $front[$locale];
                $lang = $locale;
                break;
            }
        }

        if ($chosen === null || ! isset($chosen['rev'])) {
            return null;
        }

        $code = preg_replace('/\D/', '', (string) ($product['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        return $this->trimmedOrNull(sprintf(
            'https://images.openfoodfacts.org/images/products/%s/front_%s.%s.400.jpg',
            $this->imagePath($code),
            $lang,
            (string) $chosen['rev']
        ), 500);
    }

    /**
     * Filtreaza URL-urile de imagine care sunt moarte prin constructie.
     *
     * Open Food Facts pune „invalid" in locul caii cand codul produsului nu e
     * valid, iar rezultatul e garantat 404 — verificat:
     *   .../products/invalid/front_en.6.400.jpg        -> HTTP 404
     *   .../products/000/000/000/1000/front_en...jpg   -> HTTP 200
     *
     * In exportul CSV, 28% dintre URL-urile de imagine arata asa. Un link mort
     * e mai rau decat lipsa lui: UI-ul are deja un substituent curat pentru
     * „fara poza", dar pentru „poza care nu se incarca" arata o casuta rupta.
     */
    private function usableImage(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return str_contains($url, '/products/invalid/') ? null : $url;
    }

    /**
     * Codul, in structura de directoare folosita de serverele de imagini OFF.
     *
     * Codurile de peste 8 cifre se sparg 3/3/3/rest; restul raman intregi.
     * Verificat fata de URL-urile pe care le intoarce API-ul:
     *   3017620422003 -> 301/762/042/2003   (Nutella)
     *   5449000000996 -> 544/900/000/0996   (Coca-Cola)
     */
    private function imagePath(string $code): string
    {
        if (strlen($code) <= 8) {
            return $code;
        }

        return implode('/', [
            substr($code, 0, 3),
            substr($code, 3, 3),
            substr($code, 6, 3),
            substr($code, 9),
        ]);
    }

    /** Numele, in ordinea de preferinta a limbilor. */
    private function pickName(array $product): ?string
    {
        foreach (self::NAME_LOCALES as $locale) {
            $value = $this->trimmedOrNull($product["product_name_{$locale}"] ?? null, 300);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->trimmedOrNull($product['product_name'] ?? null, 300);
    }

    /**
     * Marca. OFF le tine ca lista separata prin virgula, adesea cu duplicate
     * („Nutella, Ferrero, Yum yum") — pastram prima, care e cea principala.
     */
    private function firstBrand(array $product): ?string
    {
        $brands = $this->trimmedOrNull($product['brands'] ?? null, 200);
        if ($brands === null) {
            return null;
        }

        return trim(explode(',', $brands)[0]) ?: null;
    }

    /**
     * Caloriile per 100 g.
     *
     * Multe produse din UE declara DOAR kilojouli. Fara conversia kJ ÷ 4,184
     * am pierde complet campul pentru ele — si, cum caloriile sunt obligatorii,
     * am pierde produsul.
     */
    private function calories(array $nutriments): ?int
    {
        $kcal = $this->numeric($nutriments['energy-kcal_100g'] ?? null);

        if ($kcal === null) {
            $kj = $this->numeric($nutriments['energy-kj_100g'] ?? $nutriments['energy_100g'] ?? null);
            $kcal = $kj !== null ? $kj / 4.184 : null;
        }

        if ($kcal === null) {
            return null;
        }

        // Peste 900 kcal/100 g e imposibil fizic: grasimea pura are ~900.
        // O valoare mai mare inseamna date gresite, nu un aliment exceptional.
        return ($kcal < 0 || $kcal > 900) ? null : (int) round($kcal);
    }

    /** Macro in grame. Peste 100 g per 100 g e imposibil — se arunca doar campul. */
    private function macro(array $nutriments, string $key): ?float
    {
        $value = $this->numeric($nutriments[$key] ?? null);

        return ($value === null || $value < 0 || $value > 100)
            ? null
            : round($value, 2);
    }

    /**
     * Sodiul, in miligrame.
     *
     * OFF il tine in GRAME, coloana noastra e in mg. Fara inmultirea cu 1000,
     * toate valorile ar iesi de 1000x prea mici — greseala care nu da nicio
     * eroare, doar cifre linistitor de mici pe un produs foarte sarat.
     *
     * Cand lipseste, il derivam din sare: sodiu = sare ÷ 2,5. Multe etichete
     * declara doar sarea.
     */
    private function sodiumMg(array $nutriments): ?float
    {
        $sodiumG = $this->numeric($nutriments['sodium_100g'] ?? null);

        if ($sodiumG === null) {
            $saltG = $this->numeric($nutriments['salt_100g'] ?? null);
            $sodiumG = $saltG !== null ? $saltG / 2.5 : null;
        }

        if ($sodiumG === null || $sodiumG < 0) {
            return null;
        }

        $mg = $sodiumG * 1000;

        // 100 g de sodiu per 100 g de produs e imposibil.
        return $mg > 100000 ? null : round($mg, 1);
    }

    private function servingGrams(array $product): ?int
    {
        $value = $this->numeric($product['serving_quantity'] ?? null);

        return ($value === null || $value <= 0 || $value > 5000) ? null : (int) round($value);
    }

    /**
     * Nutri-Score ca litera. Stocam doar textul, nu logo-ul: logo-ul e marca
     * inregistrata a Santé publique France.
     */
    private function nutriscore(array $product): ?string
    {
        $grade = strtolower(trim((string) ($product['nutriscore_grade'] ?? '')));

        return in_array($grade, ['a', 'b', 'c', 'd', 'e'], true) ? $grade : null;
    }

    /** @return list<string> */
    private function countries(array $product): array
    {
        return array_values(array_filter(array_map(
            static fn ($t) => strtolower(trim((string) $t)),
            (array) ($product['countries_tags'] ?? [])
        )));
    }

    /** Prima categorie care se potriveste; `null` daca niciuna. */
    private function category(array $product): ?string
    {
        $tags = array_map(
            static fn ($t) => strtolower(trim((string) $t)),
            (array) ($product['categories_tags'] ?? [])
        );

        foreach (self::CATEGORY_RULES as $category => $needles) {
            if (array_intersect($tags, $needles)) {
                return $category;
            }
        }

        return null;
    }

    /** Numeric tolerant: OFF trimite si numere, si string-uri, si `""`. */
    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function trimmedOrNull(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}

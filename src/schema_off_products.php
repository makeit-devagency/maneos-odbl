<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L1 — Schema pentru produsele Open Food Facts.
 *
 * SEPARARE DE `foods`, nu amestecare. Trei motive, in ordinea importantei:
 *
 *  1. LICENTA. Datele OFF sunt sub ODbL, care cere ca baza derivata sa fie
 *     disponibila sub aceeasi licenta. Tabel separat = granita clara intre ce e
 *     derivat din OFF si cele ~355 de alimente proprii. Turnate impreuna,
 *     granita devine imposibil de argumentat.
 *  2. CHEI DIFERITE. `foods` are UUID; produsele scanabile au cod de bare.
 *     Identificatori cu semantici diferite.
 *  3. CICLURI DE ACTUALIZARE DIFERITE. `off_products` se rescrie zilnic din
 *     upstream; `foods` il edităm noi manual. Un sync automat n-are ce cauta
 *     peste datele noastre curate.
 *
 * Cautarea le uneste in view-ul `foods_searchable`, cu alimentele proprii pe
 * prima pozitie.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | ATENTIE LA TIPUL CHEII PRIMARE: `text`, NU `char(14)`.
         |
         | Lookup-ul compara `gtin14` cu o expresie `lpad(...)`, care are tipul
         | `text`. Cu un PK `bpchar`, planner-ul converteste coloana la text ca sa
         | poata compara, iar indexul devine inutilizabil — seq scan pe 4 milioane
         | de randuri la FIECARE scanare. Criteriul de acceptanta din brief
         | verifica exact asta prin EXPLAIN.
         */
        Schema::create('off_products', function ($table) {
            $table->text('gtin14')->primary();

            // Codul asa cum vine de la OFF, pentru afisare si depanare.
            $table->string('barcode', 20)->nullable();

            $table->string('name', 300);
            $table->string('brand', 200)->nullable();
            // Cantitatea din ambalaj, ca text liber: „1 L", „330 ml", „500 g".
            $table->string('quantity', 60)->nullable();
            $table->string('image_url', 500)->nullable();

            // Categoria mapata la cele 9 categorii maneos. Tagurile brute stau
            // separat, ca sa putem extinde maparea fara re-descarcare.
            $table->string('category', 24)->nullable();
            $table->text('off_categories')->nullable();

            // ACELEASI tipuri ca in `foods`, ca view-ul unificat sa nu ceara cast-uri.
            $table->integer('calories_per_100g')->nullable();
            $table->decimal('protein_per_100g')->nullable();
            $table->decimal('carbs_per_100g')->nullable();
            $table->decimal('fat_per_100g')->nullable();
            $table->decimal('fiber_per_100g')->nullable();
            $table->decimal('sugar_per_100g')->nullable();
            $table->decimal('sodium_per_100g_mg')->nullable();

            $table->integer('serving_size_g')->nullable();
            $table->string('nutriscore', 1)->nullable();

            // Tarile in care se vinde, ca text brut de la OFF.
            $table->text('countries')->nullable();
            /*
             | Precalculat la import, nu dedus in view.
             |
             | View-ul pune produsele locale inaintea celorlalte. Un `countries
             | LIKE '%romania%'` la fiecare cautare ar insemna scan pe tot
             | tabelul; un boolean indexat rezolva acelasi lucru dintr-o citire.
             */
            $table->boolean('is_local')->default(false);

            // Momentul ultimei modificari la OFF. Merge-ul zilnic il compara ca
            // sa nu suprascrie niciodata cu date mai vechi decat cele existente.
            $table->timestampTz('off_last_modified')->nullable();
            $table->timestampsTz();
        });

        DB::statement("CREATE INDEX off_products_local_index ON off_products (is_local) WHERE is_local");
        DB::statement('CREATE INDEX off_products_name_trgm_index ON off_products USING gin (lower(name) gin_trgm_ops)');

        /*
         | Staging pentru import si sync.
         |
         | UNLOGGED: continutul e complet regenerabil din upstream si e golit la
         | fiecare rulare, deci nu merita costul WAL-ului. Diferenta e
         | substantiala la 4 milioane de randuri incarcate cu COPY.
         */
        DB::statement('CREATE UNLOGGED TABLE off_products_staging (LIKE off_products INCLUDING DEFAULTS)');

        /*
         | Starea sincronizarii. Un singur rand.
         |
         | `last_delta` e cel mai important camp: OFF pastreaza delta-urile doar
         | 14 zile, deci daca sync-ul se opreste mai mult, intervalul nu mai poate
         | fi acoperit si baza ramane in urma TACUT. Comanda de sync compara si
         | iese cu cod de eroare in acest caz.
         */
        Schema::create('off_sync_state', function ($table) {
            $table->id();
            $table->string('last_delta', 120)->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->string('last_status', 255)->nullable();
            $table->integer('last_rows_upserted')->default(0);
            $table->timestampsTz();
        });
        DB::table('off_sync_state')->insert(['created_at' => now(), 'updated_at' => now()]);

        $this->createLookupFunction();
        $this->createSearchableView();
    }

    /**
     * `off_lookup(text)` — normalizeaza ORICE format de cod de bare la aceeasi
     * cheie si intoarce produsul.
     *
     * Normalizarea e cea mai frecventa cauza de „produs negasit" fals: un produs
     * stocat de OFF ca UPC-12 nu se gaseste cand scanner-ul intoarce EAN-13 cu
     * zero in fata. Aducem totul la GTIN-14 si comparam o singura forma.
     */
    private function createLookupFunction(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION off_lookup(p_code text)
            RETURNS SETOF off_products
            LANGUAGE sql
            STABLE
            AS $$
                SELECT *
                  FROM off_products
                 WHERE gtin14 = lpad(regexp_replace(coalesce(p_code, ''), '[^0-9]', '', 'g'), 14, '0')
                 LIMIT 1;
            $$;
        SQL);
    }

    /**
     * `foods_searchable` — cautarea unificata.
     *
     * Ordinea de prioritate e intentionata:
     *   0 = alimentele noastre (curate, verificate, cu imagini proprii)
     *   1 = produse OFF vandute in RO/MD
     *   2 = restul produselor OFF
     *
     * Clientul sorteaza dupa `priority`, apoi dupa relevanta. Fara asta, o
     * cautare de „lapte" ar returna intai produse germane obscure, in locul
     * alimentelor pe care le-am ingrijit noi.
     */
    private function createSearchableView(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW foods_searchable AS
            SELECT
                f.id::text            AS id,
                0                     AS priority,
                'maneos'              AS origin,
                f.name, f.brand, f.barcode, f.image_url, f.category,
                f.calories_per_100g, f.protein_per_100g, f.carbs_per_100g,
                f.fat_per_100g, f.fiber_per_100g, f.sugar_per_100g,
                f.sodium_per_100g_mg,
                NULL::text            AS quantity,
                NULL::varchar(1)      AS nutriscore
              FROM foods f
             WHERE f.deleted_at IS NULL

            UNION ALL

            SELECT
                o.gtin14              AS id,
                CASE WHEN o.is_local THEN 1 ELSE 2 END AS priority,
                'openfoodfacts'       AS origin,
                o.name, o.brand, o.barcode, o.image_url, o.category,
                o.calories_per_100g, o.protein_per_100g, o.carbs_per_100g,
                o.fat_per_100g, o.fiber_per_100g, o.sugar_per_100g,
                o.sodium_per_100g_mg,
                o.quantity,
                o.nutriscore
              FROM off_products o;
        SQL);
    }

    public function down(): void
    {
        // Ordinea conteaza: view-ul depinde de ambele tabele.
        DB::statement('DROP VIEW IF EXISTS foods_searchable');
        DB::statement('DROP FUNCTION IF EXISTS off_lookup(text)');
        DB::statement('DROP TABLE IF EXISTS off_products_staging');
        Schema::dropIfExists('off_sync_state');
        Schema::dropIfExists('off_products');
        // `foods` ramane neatinsa — criteriu de acceptanta din brief.
    }
};

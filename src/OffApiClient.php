<?php

namespace App\Services\Off;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Client pentru API-ul Open Food Facts.
 *
 * LIMITA DE RATA E CONSTRANGEREA CENTRALA, nu latenta. OFF permite 15 cereri pe
 * minut per IP pentru produse, iar tot backend-ul nostru iese pe o singura
 * adresa — deci plafonul e de 15 scanari pe minut pentru TOTI utilizatorii la un
 * loc. Depasirea repetata poate duce la blocarea IP-ului.
 *
 * De aceea limitarea e aplicata aici, inainte de cerere, si esecul e „nu acum",
 * nu o exceptie: apelantul cade inapoi pe „produs negasit", iar utilizatorul
 * poate reincerca sau adauga produsul manual.
 */
class OffApiClient
{
    private const BASE = 'https://world.openfoodfacts.org/api/v2/product';

    /** Cerut explicit de OFF; fara el, cererile pot fi respinse. */
    private const USER_AGENT = 'maneos/1.0 (contact@maneos.io)';

    /**
     * Sub plafonul real de 15/min, cu marja.
     *
     * Marja nu e prudenta excesiva: plafonul e per IP, iar in productie pot
     * exista mai multe procese (web + worker de coada) care ies pe aceeasi
     * adresa fara sa stie unele de altele. Limitatorul e partajat prin cache,
     * deci acopera si acest caz.
     */
    private const MAX_PER_MINUTE = 12;

    /**
     * Doar campurile de care avem nevoie.
     *
     * Un produs complet din OFF are sute de campuri si zeci de KB; cerand doar
     * ce mapam, raspunsul scade de cateva ori. Conteaza pentru latenta si
     * pentru banda, la volume mari.
     */
    private const FIELDS = 'code,product_name,product_name_ro,product_name_en,product_name_fr,'
        .'product_name_de,product_name_it,product_name_es,brands,quantity,image_front_url,image_url,'
        .'categories_tags,countries_tags,nutriscore_grade,serving_quantity,last_modified_t,nutriments';

    /**
     * Aduce un produs dupa cod de bare.
     *
     * @return array<string,mixed>|null  nodul `product`, sau null daca produsul
     *         nu exista, limita a fost atinsa sau OFF nu raspunde. Toate trei
     *         inseamna acelasi lucru pentru apelant: nu avem produsul acum.
     */
    public function fetchProduct(string $barcode): ?array
    {
        $code = preg_replace('/\D/', '', $barcode);
        if ($code === '' || strlen($code) > 14) {
            return null;
        }

        if (RateLimiter::tooManyAttempts('off-api', self::MAX_PER_MINUTE)) {
            Log::info('[off] limita de rata atinsa, cerere amanata', ['barcode' => $code]);

            return null;
        }
        RateLimiter::hit('off-api', 60);

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                // Timeout scurt: utilizatorul asteapta in fata unui raion. Mai
                // bine „nu gasim produsul" in 4 secunde decat un ecran blocat.
                ->timeout(4)
                ->connectTimeout(2)
                ->get(self::BASE."/{$code}.json", ['fields' => self::FIELDS]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            // OFF raspunde 200 si pentru produse inexistente, cu `status: 0`.
            if (($body['status'] ?? 0) !== 1) {
                return null;
            }

            $product = $body['product'] ?? null;

            return is_array($product) ? $product : null;
        } catch (Throwable $e) {
            Log::warning('[off] cerere esuata', ['barcode' => $code, 'error' => $e->getMessage()]);

            return null;
        }
    }
}

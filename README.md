# maneos — transformările aplicate datelor Open Food Facts

Acest pachet există ca să respecte obligația de **share-alike** din licența
[ODbL v1.0](https://opendatacommons.org/licenses/odbl/1-0/), sub care sunt
publicate datele [Open Food Facts](https://world.openfoodfacts.org/).

ODbL §4.6 cere ca o bază derivată folosită public să fie disponibilă sub aceeași
licență, iar §4.7 acceptă, ca alternativă la publicarea bazei întregi,
**punerea la dispoziție a modificărilor aplicate** — algoritmul prin care s-a
ajuns de la datele originale la cele derivate. Pachetul acesta e exact asta:
codul care face transformarea, plus descrierea lui în text.

Nu conține date Open Food Facts. Conține doar codul maneos care le prelucrează.

---

## Ce facem, pe scurt

Baza noastră derivată se formează pe două căi.

**1. Un import unic din dump-ul oficial.** Descărcăm exportul complet Open Food
Facts (`en.openfoodfacts.org.products.csv.gz`, ~1,2 GB) și păstrăm **doar
produsele marcate de OFF ca fiind vândute în România sau Republica Moldova** —
cele care au `en:romania` sau `en:moldova` în `countries_tags`. Restul celor
peste patru milioane de produse nu ajunge niciodată în baza noastră. E o rulare
unică, nu o sincronizare: nu există job programat și nu urmărim delte.

La importul din 15 septembrie 2026 au fost citite ~39.000 de produse RO/MD, din
care au intrat 4.706. Diferența e respinsă în întregime, după regulile din
secțiunea „Produse respinse complet" de mai jos — 26.307 dintre ele n-aveau
nicio valoare calorică.

**2. Interogarea API-ului public, produs cu produs, pentru codurile scanate.**
Un cod de bare care nu e în setul importat e cerut de la API-ul OFF chiar în
momentul scanării, iar rezultatul mapat se păstrează local. Un produs salvat e
re-interogat după 30 de zile.

Pe ambele căi scrierea e un **upsert după `gtin14`**, dintr-o tabelă de staging,
**fără nicio ștergere**: produsele cache-uite prin scanări anterioare — inclusiv
cele care nu se vând în RO/MD — supraviețuiesc oricărui import. Un produs
corectat manual de noi își păstrează câmpurile corectate, restul se actualizează
de la OFF.

Consecința pentru licență: baza noastră derivată e un subset al OFF — produsele
vândute în România și Moldova, plus cele scanate de utilizatorii maneos — în
forma descrisă mai jos.

---

## Transformările aplicate, câmp cu câmp

Sursa de adevăr e codul din `src/` (inclus în acest pachet). Lista de mai jos
descrie ce face, ca să poată fi verificat fără să citești PHP.

### Identificator

| Din OFF | Devine | Cum |
|---|---|---|
| `code` | `gtin14` | Se elimină tot ce nu e cifră, apoi se completează cu zerouri la stânga până la 14 caractere. Un produs stocat de OFF ca UPC-12 și scanat ca EAN-13 ajunge astfel la aceeași cheie. Codurile cu peste 14 cifre sunt respinse. |
| `code` | `barcode` | Păstrat și în forma originală, pentru afișare și depanare. |

### Text

| Din OFF | Devine | Cum |
|---|---|---|
| `product_name_ro/en/fr/de/it/es`, `product_name` | `name` | Prima variantă nevidă, în această ordine de limbi. Tăiat la 300 de caractere. |
| `brands` | `brand` | OFF ține marca drept listă separată prin virgulă, adesea cu duplicate („Nutella, Ferrero"). Păstrăm **prima** valoare. Tăiat la 200. |
| `quantity` | `quantity` | Text liber, tăiat la 60. |
| `image_front_url`, `image_url` | `image_url` | Prima disponibilă. **URL, nu copie** — imaginile rămân servite de Open Food Facts, nu sunt redistribuite de noi. |
| `categories_tags` | `off_categories` | Primele 40 de etichete, concatenate cu virgulă. Păstrate brute, ca maparea să poată fi refăcută fără re-interogare. |
| `countries_tags` | `countries` | Toate, minusculizate. |

### Valori nutriționale (toate la 100 g)

| Din OFF | Devine | Cum |
|---|---|---|
| `energy-kcal_100g` | `calories_per_100g` | Direct, rotunjit la întreg. |
| `energy-kj_100g` / `energy_100g` | `calories_per_100g` | **Doar când lipsesc kcal:** kilojouli ÷ 4,184. Multe produse din UE declară exclusiv kJ. |
| `proteins_100g`, `carbohydrates_100g`, `fat_100g`, `fiber_100g`, `sugars_100g` | omonimele `_per_100g` | Direct. |
| `sodium_100g` | `sodium_per_100g_mg` | OFF îl ține în **grame**, coloana noastră e în **miligrame**: × 1000. |
| `salt_100g` | `sodium_per_100g_mg` | **Doar când lipsește sodiul:** sare ÷ 2,5, apoi × 1000. Multe etichete declară doar sarea. |
| `serving_quantity` | `serving_size_g` | Rotunjit la întreg. |
| `nutriscore_grade` | `nutriscore` | Doar litera (a–e), ca **text**. Logo-ul colorat nu e reprodus nicăieri — e marcă înregistrată Santé publique France, separată de licența datelor. |

### Praguri de plauzibilitate

Valorile imposibile fizic sunt **eliminate, nu corectate**:

- **peste 900 kcal/100 g** → produsul e respins în întregime (grăsimea pură are
  ~900; o valoare mai mare înseamnă o eroare de introducere în amonte);
- **macronutrient peste 100 g în 100 g de produs** → câmpul devine `null`;
- **sodiu peste 100 000 mg/100 g** → câmpul devine `null`;
- **porție ≤ 0 sau > 5000 g** → câmpul devine `null`.

Un câmp lipsă rămâne `null`, **niciodată 0**. Absența unei valori nu înseamnă că
produsul conține zero din acel nutrient, iar interfața afișează „—" pentru
`null` și o cifră pentru 0.

### Produse respinse complet

Un produs nu intră în baza derivată dacă îi lipsește **codul valid**, **numele**
sau **caloriile**. Un card de produs fără nume sau fără calorii nu spune nimic
utilizatorului și nu poate fi adăugat în jurnal.

### Câmpuri calculate de noi

| Câmp | Cum |
|---|---|
| `category` | Prima potrivire între `categories_tags` și tabelul de reguli din `OffProductMapper::CATEGORY_RULES` (9 categorii maneos). `null` dacă niciuna. |
| `is_local` | `true` dacă `countries_tags` conține `en:romania` sau `en:moldova`. Precalculat, ca sortarea rezultatelor să nu ceară scanarea tabelei. |

---

## Conținutul pachetului

```
LICENSE-ODbL.txt   textul canonic al licenței, sub care se publică acest pachet
README.md          fișierul de față
src/               codul care face transformarea, exact cel rulat în producție
```

Fișierele din `src/` sunt copiate la generare din arborele maneos, deci nu pot
rămâne în urmă față de ce rulează efectiv. Regenerare:

```
php artisan off:export-transform
```

---

## Licență

Acest pachet e publicat sub **ODbL v1.0**, aceeași licență ca datele din care
derivă. Textul integral e în `LICENSE-ODbL.txt`.

Datele despre produse aparțin contribuitorilor Open Food Facts. Fotografiile
produselor sunt sub CC BY-SA, cu autor per imagine, și **nu** sunt redistribuite
de maneos — sunt afișate prin legătură directă către serverele Open Food Facts.

## Contact

Baza derivată (produsele vândute în România și Moldova, plus cele scanate de
utilizatorii maneos, în forma descrisă mai sus) poate fi obținută la cerere, în
format prelucrabil automat, scriind la adresa din secțiunea „Surse de date și
licențe" din aplicație.

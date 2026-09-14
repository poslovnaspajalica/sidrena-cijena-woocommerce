# Sidrena cijena za WooCommerce

WordPress/WooCommerce plugin za dvije obveze koje vrijede od **1. 10. 2026.**:

1. **Isticanje sidrene (dodatne) cijene** uz aktualnu cijenu na svim mjestima gdje se cijena prikazuje
   (Odluka o isticanju dodatne cijene kao mjera izravne kontrole cijena, NN 101/2026).
2. **Objava strojno čitljivog cjenika** (.csv i .xml) na web stranici, dnevno do 8:00, dostupno 30 dana
   (Odluka o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena, NN 101/2026).

Pravni temelj: Zakon o iznimnim mjerama kontrole cijena (NN 40/2025), čl. 6, 8, 15 i 20.

## Instalacija

1. Kopiraj mapu `sidrena-cijena` u `wp-content/plugins/` (ili instaliraj zip kroz Dodaci → Dodaj novi).
2. Aktiviraj plugin. Potreban je WooCommerce 8.0+ i PHP 8.1+.
3. Otvori **WooCommerce → Sidrena cijena**.

## Prvi koraci (redoslijed je bitan)

1. **Postavke → Cjenik: podaci o prodajnom objektu**: upiši naziv trgovca, adresu i oznaku objekta.
   Od toga se gradi naziv datoteke prema točki VI. Odluke.
2. **Status → Zabilježi sidrene cijene**: kopira redovnu cijenu (bez akcije) svakog proizvoda i varijacije
   u polje sidrene cijene. **Napravi to odmah**, dok su cijene u shopu još one koje su vrijedile 10. 9. 2026.
   Ako su cijene već mijenjane, koristi karticu *Uvoz / izvoz*: preuzmi radni CSV, upiši točne sidrene
   cijene iz vlastite evidencije i uvezi.
3. **Status → Generiraj cjenik sada**: prvi cjenik. Dalje se generira automatski svaki dan u 04:00.
   Plugin pri aktivaciji sam zabilježi sidrene cijene (minutu nakon aktivacije, u pozadini), ali cjenik
   ne generira sam dok ga prvi put ne pokreneš ili dok ne dođe zakazano vrijeme.
4. Provjeri javnu stranicu `https://tvoja-domena.hr/cjenik/`.

## Prekidač prikaza
U Postavkama: *Automatski uključi od datuma* (zadano 1. 10. 2026.), *Uključen odmah* ili *Isključen*.
Dok je prikaz isključen plugin normalno bilježi sidrene cijene, generira cjenik i čuva podatke, samo
kupci ne vide oznaku. Plugin se dakle može instalirati i pripremiti unaprijed.

## Višejezičnost
Jezik frontenda se prepoznaje redom iz Polylanga, WPML-a, TranslatePressa, parametra `?lang=`, pa WordPress
locale-a. Tekst oznake po jeziku upisuje se u Postavkama (`en: Anchor price ({datum}): {cijena}`, jedan jezik po
retku). Za jezik koji nije naveden koristi se engleski, a ako ni njega nema, hrvatski. `{datum}` se za nehrvatske
jezike formatira prema WordPress formatu datuma. Filteri: `sidrena_cijena_current_language`, `sidrena_cijena_label`.

## Izuzimanje proizvoda
- Na proizvodu (kartica Općenito): *Ne ističi sidrenu cijenu* (npr. preorder, proizvod nije postojao na
  referentni dan) i *Ne uključuj u cjenik*.
- Masovno: Proizvodi → označi → Masovne radnje → Uredi → polja *Sidrena cijena* i *Cjenik*.
- Cijele kategorije: Postavke → *Kategorije izuzete iz isticanja*.
- Izuzet proizvod ostaje u cjeniku s praznim poljem sidrene cijene (osim ako je izuzet i iz cjenika).

## Što plugin radi

### Isticanje
- Ispod svake cijene dodaje npr. `Sidrena cijena (10. 9. 2026.): 27,00 €` (tekst je podesiv).
- Radi na stranici proizvoda, listinzima, pretrazi, povezanim proizvodima, widgetima i svugdje gdje tema
  koristi standardni `get_price_html()`.
- Varijabilni proizvodi: raspon sidrenih cijena na roditelju, točna cijena po varijaciji.
- Košarica, mini-košarica i checkout: klasični shortcode i novi blokovi (Store API proširenje).
- Akcijski proizvod: prikazuju se akcijska cijena, precrtana redovna, najniža u 30 dana (WooCommerce) i
  sidrena cijena. Sidrena je uvijek **redovna** cijena na referentni dan, ne akcijska.
- Shortcode `[sidrena_cijena id="123"]` za bannere i landing stranice.
- Novi proizvodi (kreirani nakon referentnog datuma) automatski dobivaju sidrenu cijenu = prva redovna
  cijena, s datumom kreiranja.
- Kategorije iz stare Odluke NN 75/2025 (hrana, piće, kozmetika, čišćenje, toaletne potrepštine,
  kućanstvo) mogu zadržati referentni datum 2. 5. 2025.

### Uvoz sidrenih cijena (kad su cijene mijenjane nakon referentnog datuma)
Kartica *Uvoz / izvoz*: preuzmi radni CSV, upiši točne sidrene cijene, uvezi. Uvoz mijenja **samo** polje
sidrene cijene i datum; sve ostalo na proizvodu ostaje. Proizvod se prepoznaje po SKU-u, GTIN/EAN-u ili ID-u;
proizvodi koji ne postoje u webshopu se preskaču i ispisuju. Prihvaća decimalni zarez, separator ; ili ,,
i dodatne stupce (ignoriraju se), pa se može uvesti i izvoz iz ERP-a.

### Cjenik
- Datoteke: `wp-content/uploads/sidrena-cijena/cjenik/{oblik}_{adresa}_{oznaka}_{broj-pohrane}_{GGGGMMDD_HHMM}.csv|.xml`
- Javno: `/cjenik/` (popis), `/cjenik/latest.csv`, `/cjenik/latest.xml`, `/cjenik/index.json`.
- Stupci (točka III. Odluke, redom): naziv; šifra; marka; jedinica mjere; cijena za jedinicu mjere;
  maloprodajna cijena; posebni oblik prodaje (DA/NE); naziv posebnog oblika prodaje; sidrena cijena;
  barkod; dostupnost. Dodatno: datum sidrene cijene; kategorija; url.
- CSV: UTF-8 s BOM-om, separator `;`, sve vrijednosti u navodnicima. Decimalni znak podesiv.
- Cijene u cjeniku su **s PDV-om** (maloprodajne), bez obzira kako su unesene u WooCommerce.
- Generira se u batchevima po 500 proizvoda, radi i na katalozima s više desetaka tisuća proizvoda
  (test: 23.700 proizvoda u 40 s, 110 MB memorije).
- Datoteke starije od zadanog broja dana (min. 31) se brišu.

### Automatika (cron)
Zadano vrijeme generiranja je **04:00** po vremenskoj zoni WordPressa (Postavke → Općenito). Tri mehanizma:

1. **Vanjski okidač** (preporučeno, radi bez posjeta i bez WP-Crona): u adminu na kartici Status je gotov
   URL oblika `https://domena.hr/?sidrena_cron=TOKEN`. Zakaži ga u cPanel/Plesk cronu ili na cron-job.org:
   ```
   0 4 * * * curl -s "https://domena.hr/?sidrena_cron=TOKEN" > /dev/null
   ```
   Uz `&only_due=1` može se zvati i češće, generira samo ako današnji cjenik ne postoji.
2. **WP-Cron**: zakazan za isto vrijeme, pokreće ga prvi posjet nakon toga.
3. **Rezerva**: ako je vrijeme prošlo a današnji cjenik ne postoji, generira se u pozadini na kraju prvog
   sljedećeg zahtjeva (nakon što je stranica isporučena posjetitelju), i kad je WP-Cron loopback blokiran.

WP-CLI alternativa: `0 4 * * * cd /putanja/do/wp && wp sidrena export`.

Okidač odmah vraća JSON odgovor i generira u pozadini, pa ne ovisi o timeoutu web servera. Za sinkrono
izvršavanje (testiranje) dodaj `&wait=1`. Ako je zadnji cjenik stariji od 24 h, admin vidi crveno upozorenje.

Važno: WordPress i WooCommerce nemaju vlastiti pravi scheduler. WP-Cron i Action Scheduler se pokreću
samo unutar HTTP zahtjeva. Jedini način da se nešto dogodi točno u 04:00 bez ikakvog posjeta je vanjski
cron (hosting ili servis), zato je mehanizam 1 preporučen. Mehanizmi 2 i 3 su rezerva.

## WP-CLI
- `wp sidrena snapshot [--overwrite]` – zabilježi sidrene cijene
- `wp sidrena export` – generiraj cjenik
- `wp sidrena status`

## Meta polja
- `_sidrena_cijena` – iznos, unesen isto kao redovna cijena (s ili bez PDV-a prema postavkama trgovine)
- `_sidrena_cijena_datum` – GGGG-MM-DD
- `_sidrena_cijena_izvor` – `snapshot` | `novi` | `rucno` | `uvoz`

WooCommerce CSV uvoz/izvoz proizvoda prenosi ih kao `Meta: _sidrena_cijena` itd.

## Hookovi za developere
- `do_action('sidrena_cijena_export_done', array $info)` nakon generiranja cjenika
- `SC_Display::render(WC_Product)` / `SC_Display::render_text(WC_Product)` za prikaz u vlastitim predlošcima,
  e-mailovima ili feedovima
- `SC_Snapshot::get(WC_Product)` vraća `['price', 'date', 'source']`

## Ograničenja
- Proizvodi koji se prikazuju kroz WooCommerce *product blokove* (Store API) na naslovnicama ne prolaze
  kroz `get_price_html()`; za njih koristi shortcode ili `SC_Display::render()` u predlošku.
- Google Shopping i drugi feedovi nisu obuhvaćeni; po Odluci oglašavanje s cijenom također mora
  sadržavati sidrenu cijenu, pa feed treba dopuniti stupcem iz `_sidrena_cijena`.
- Multi-currency pluginovi: sidrena se prikazuje u osnovnoj valuti.

## Izvori
- NN 101/2026, 1212: https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1212.html
- NN 101/2026, 1213: https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1213.html
- NN 40/2025: https://narodne-novine.nn.hr/clanci/sluzbeni/2025_03_40_540.html

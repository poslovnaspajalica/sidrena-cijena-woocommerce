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

Aktivacija plugina ne pokreće ništa. Snapshot i prvi cjenik su ručne akcije s prikazom napretka; obje se
mogu prekinuti i nastaviti. Sve ide u koracima po 100 proizvoda: snapshot skupnim SQL upisima (25.000
proizvoda: oko 12 s), cjenik izravnim SQL upitima bez učitavanja WooCommerce objekata, 6 upita po koraku
(25.000 proizvoda: oko 20 s obrade).
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
- Izgled: veličina fonta (zadano 0.7em), boja, debljina i polje za vlastiti CSS. Zaseban kraći tekst za
  listinge (npr. `Sidrena cijena: {cijena}`), puni tekst s datumom na stranici proizvoda. Klase
  `.sc-sidrena`, `.sc-sidrena--single`, `.sc-sidrena--loop`, `.sc-amount`.
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
- Marka: izvor je WooCommerce Brands, atribut ili meta polje (Postavke). Kad izvor za proizvod nema vrijednost,
  upisuje se „Zadana marka“ iz postavki; prazno = stupac ostaje prazan.
- CSV: UTF-8 s BOM-om, separator `;`, sve vrijednosti u navodnicima. Decimalni znak podesiv.
- Cijene u cjeniku su **s PDV-om** (maloprodajne), bez obzira kako su unesene u WooCommerce.
- Generira se izravnim SQL upitima u koracima po 100 proizvoda (6 laganih upita po koraku), bez
  učitavanja WooCommerce objekata; radi i na katalozima s više desetaka tisuća proizvoda.
- Datoteke starije od zadanog broja dana (min. 31) se brišu.

### Automatika (cron)
Zadano je **isključeno**: cjenik se generira ručno na Status kartici. U Postavkama se može uključiti
„Svaki dan u HH:MM“ (zadano 04:00 po vremenskoj zoni WordPressa; Odluka traži do 8:00). To koristi WP-Cron,
koji se pokreće s prvim zahtjevom nakon zadanog vremena. Ako hosting ima cron (cPanel/Plesk), Status kartica
nudi URL s tokenom koji se može zakazati u točno vrijeme; poziv odmah vraća odgovor i generira u pozadini.

Ništa se nikad ne pokreće zbog posjeta kupca, ni pri aktivaciji plugina. Pozadinska obrada ide u koracima po
100 proizvoda s pauzom od 0,15 s između koraka i nikad ne rade dvije obrade istovremeno (atomarno
zaključavanje). Ako je zadnji cjenik stariji od 24 h, a automatika je uključena, admin vidi upozorenje.

## WP-CLI
- `wp sidrena snapshot [--overwrite]` - zabilježi sidrene cijene
- `wp sidrena export` - generiraj cjenik
- `wp sidrena status`

## Meta polja
- `_sidrena_cijena` - iznos, unesen isto kao redovna cijena (s ili bez PDV-a prema postavkama trgovine)
- `_sidrena_cijena_datum` - GGGG-MM-DD
- `_sidrena_cijena_izvor` - `snapshot` | `novi` | `rucno` | `uvoz`

WooCommerce CSV uvoz/izvoz proizvoda prenosi ih kao `Meta: _sidrena_cijena` itd.

## Hookovi za developere
- `do_action('sidrena_cijena_export_done', array $info)` nakon generiranja cjenika
- `SC_Display::render(WC_Product)` / `SC_Display::render_text(WC_Product)` za prikaz u vlastitim predlošcima,
  e-mailovima ili feedovima
- `SC_Snapshot::get(WC_Product)` vraća `['price', 'date', 'source']`

## Kompatibilnost s drugim pluginovima
Filter za prikaz cijene izvršava se zadnji (`PHP_INT_MAX`), pa sidrena cijena ostaje i kad drugi plugin gradi
HTML cijene ispočetka, npr. „WooCommerce - Najniža cijena u zadnjih 30 dana“. Testirano: akcijski proizvod
prikazuje redovnu, akcijsku, najnižu u 30 dana i sidrenu cijenu.

## Ograničenja
- Proizvodi koji se prikazuju kroz WooCommerce *product blokove* (Store API) na naslovnicama ne prolaze
  kroz `get_price_html()`; za njih koristi shortcode ili `SC_Display::render()` u predlošku.
- Google Shopping i drugi feedovi nisu obuhvaćeni; po Odluci oglašavanje s cijenom također mora
  sadržavati sidrenu cijenu, pa feed treba dopuniti stupcem iz `_sidrena_cijena`.
- Multi-currency pluginovi: sidrena se prikazuje u osnovnoj valuti.

## Kvaliteta koda
Kod prolazi PHP_CodeSniffer sa standardom **WordPress** (Core + Extra) i **PHPCompatibilityWP** za PHP 8.1+
bez grešaka i upozorenja. Konfiguracija je u `phpcs.xml.dist`; dokumentirane iznimke: bez obveznih docblock-ova
(WordPress-Docs), dopušteni izravni skupni SQL upiti nad postmeta (svi pripremljeni kroz `$wpdb->prepare`) i
strujanje velikih datoteka standardnim PHP funkcijama. Pokretanje:

```
composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp
phpcs
```

## Izvori
- NN 101/2026, 1212: https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1212.html
- NN 101/2026, 1213: https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1213.html
- NN 40/2025: https://narodne-novine.nn.hr/clanci/sluzbeni/2025_03_40_540.html

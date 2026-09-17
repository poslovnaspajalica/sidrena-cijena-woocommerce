# Sidrena cijena - cjenik poslovnica

WordPress plugin za dnevnu objavu strojno čitljivog cjenika (.csv i .xml) za **fizičke poslovnice**, prema
Odluci o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena (NN 101/2026, na snazi od 1. 10. 2026.).

Djelatnik svaki dan prenese CSV ili Excel s blagajne, plugin ga pretvori u propisanu strukturu, imenuje datoteku po
točki VI. Odluke i objavi na stranici `/cjenik/`. Radi samostalno (ne treba WooCommerce) ili uz plugin
**Sidrena cijena za WooCommerce**, s kojim dijeli istu stranicu `/cjenik/` s karticama Webshop / Poslovnica.

## Instalacija

1. Instaliraj zip kroz Dodaci → Dodaj novi → Prenesi dodatak i aktiviraj. Potreban PHP 8.1+, WordPress 6.5+.
2. **Cjenik poslovnica → Poslovnice**: za svaku poslovnicu upiši naziv, oblik objekta (prodavaonica), adresu,
   oznaku i broj pohrane. Od toga se gradi naziv datoteke, npr. `prodavaonica_ilica-1-zagreb_p1_1_20261001_0730.csv`.
3. **Postavke**: naziv trgovca, podsjetnik e-mailom (zadano 07:00 ako neka poslovnica nema današnji cjenik).
4. Djelatnicima u poslovnicama dodijeli ulogu **Cjenik poslovnice**: vide samo objavu i popis datoteka, ne i
   ostatak WordPressa. Administratori i voditelji trgovine imaju pristup automatski.

## Dnevna objava (djelatnik)

1. Cjenik poslovnica → Objava cjenika: odaberi poslovnicu, prenesi CSV ili Excel s blagajne, klikni „Učitaj i pregledaj“.
2. Pregled pokaže broj artikala, akcija, nedostupnih, bez barkoda i bez sidrene cijene, prepoznate stupce i
   prvih 8 redaka u konačnom obliku.
3. „Objavi cjenik“. Datoteke .csv i .xml su odmah javno dostupne, stare ostaju 35 dana (Odluka traži 30).

**Propušteni dan.** Na kartici Objava svaka poslovnica ima traku zadnjih 14 dana (zeleno = objavljen, crveno =
nedostaje). Klik na crveni dan otvara upload s tim danom u polju „Cjenik vrijedi za dan“. Naziv datoteke uvijek nosi
stvarno vrijeme objave, kako traži točka VI. Odluke, a dan za koji cjenik vrijedi zapisan je u XML-u
(`vrijedi_za`), u `index.json` i na javnoj stranici u stupcu „Vrijedi za“. Datum u budućnosti nije moguć.

Obrada je čista pretvorba datoteke bez upita u bazu; i 25.000 redaka prođe u nekoliko sekundi.

## Ulazna datoteka (CSV ili Excel)

Prihvaća se CSV, kao i Excel (.xls, .xlsx) ili ODS izravno iz blagajne, bez pretvorbe. Čita se prvi list, prvi
neprazni redak je zaglavlje. Brojevi se prenose bez eksponenta i bez ".0", pa kataloški brojevi i barkodovi ostaju
cjeloviti (Excel ih pri ručnom spremanju u CSV zna skratiti ili isprazniti).

Obvezni stupci: `barkod`, `naziv`, `cijena`. Neobavezni: `akcijska_cijena`, `dostupnost`, `sidrena_cijena`,
`sifra`, `marka`, `jedinica_mjere`, `cijena_za_jedinicu_mjere`. Predložak se preuzima u adminu. Izlazna datoteka
ima 14 stupaca propisanih Odlukom: one koje ne šaljete plugin računa (maloprodajna cijena, oznaka i naziv akcije)
ili ostavlja prazne (kategorija, url), a datum sidrene cijene upisuje iz postavki.

- Nazivi stupaca prepoznaju se automatski i po sinonimima (Kataloški broj, EAN, GTIN, MPC, „Prosječna MP cijena“,
  „Naziv artikla“, Količina, Jed.mj., akcija, zaliha, stanje...). Redoslijed nije bitan, višak stupaca se ignorira.
- Separator `;`, `,` ili tab, decimalni zarez ili točka, UTF-8 ili Windows-1250 (izvoz s blagajne).
- Akcija: ako je `akcijska_cijena` upisana i niža od `cijena`, u cjeniku ide akcijska cijena, „posebni oblik
  prodaje = DA“ i naziv akcije iz postavki.
- Jedinica mjere `kom` (komad): cijena za jedinicu mjere automatski je jednaka maloprodajnoj cijeni po komadu,
  ako nije zasebno poslana. Za kg, l i slično treba poslati stupac `cijena_za_jedinicu_mjere`.
- Dostupnost: `dostupno`/`nedostupno`, `da`/`ne`, `1`/`0`, količina (>0 = dostupno). Ako stupca nema,
  vrijedi zadana vrijednost iz postavki.
- **Sidrena cijena**: Odluka (točka III.) traži je u cjeniku. Ako je blagajna isporuči, prenosi se; ako ne,
  stupac ostaje prazan i pregled to prikaže kao upozorenje. Objava nije blokirana.

## Izlaz

Stupci su identični webshop pluginu (Odluka traži jedinstvenu strukturu za sve objekte lanca): naziv; šifra; marka;
jedinica mjere; cijena za jedinicu mjere; maloprodajna cijena; posebni oblik prodaje (DA/NE); naziv posebnog oblika
prodaje; sidrena cijena; barkod; dostupnost; datum sidrene cijene; kategorija; url. Kategorija i url su prazni.

Javno: `/cjenik/` (kartice po objektu), `/cjenik/poslovnica-<id>/latest.csv`, `/cjenik/poslovnica-<id>/latest.xml`,
`/cjenik/index.json` (svi objekti i datoteke).

## Ovisnosti
Čitanje Excela koristi biblioteku PhpSpreadsheet (MIT), uključenu u `vendor/`. Za CSV nije potrebna. Ako je
`vendor/` uklonjen, plugin i dalje radi s CSV-om, a za Excel javlja da spremiš datoteku kao CSV.

## Sigurnost
Upload samo uz prijavu i ovlast, nonce, ograničenje 20 MB, prihvaća se samo tekst/CSV koji se parsira; datoteke se
spremaju pod generiranim nazivima u mapu cjenika. Privremene datoteke pregleda vezane su uz korisnika i traju sat
vremena. Kod prolazi PHPCS WordPress standard bez grešaka (`phpcs.xml.dist`).

## Hookovi
- `scp_output_row` (filter): redak cjenika prije zapisa, s izvornim retkom i mapiranjem stupaca.
- `scp_published` (action): nakon objave, s podacima poslovnice i nazivima datoteka.
- Webshop plugin: `sidrena_cijena_public_sections` (filter) kojim ovaj plugin dodaje svoje odjeljke na `/cjenik/`.

## Licenca
GPL-2.0-or-later.

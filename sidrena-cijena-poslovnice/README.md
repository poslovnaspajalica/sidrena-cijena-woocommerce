# Sidrena cijena - cjenik poslovnica

WordPress plugin za dnevnu objavu strojno čitljivog cjenika (.csv i .xml) za **fizičke poslovnice**, prema
Odluci o objavi cjenika proizvoda i usluga kao mjera izravne kontrole cijena (NN 101/2026, primjena od 17. 11. 2026. prema NN 110/2026), usklađen s nacrtom Pravilnika o načinu objave cjenika proizvoda.

Djelatnik svaki dan prenese CSV ili Excel s blagajne, plugin ga pretvori u propisanu strukturu, imenuje datoteku po
točki VI. Odluke i objavi na stranici `/cjenik/`. Radi samostalno (ne treba WooCommerce) ili uz plugin
**Sidrena cijena za WooCommerce**, s kojim dijeli istu stranicu `/cjenik/` s karticama Webshop / Poslovnica.

## Usklađenost s propisima

- Odluke NN 101/2026 (isticanje dodatne cijene, objava cjenika), s izmjenama NN 110/2026: primjena od **17. 11. 2026.**
- Nacrti pravilnika prema Zakonu o zaštiti potrošača (e-Savjetovanje 14. 10. do 13. 11. 2026., stupanje na snagu
  predviđeno 17. 11. 2026.): Pravilnik o načinu objave cjenika proizvoda i Pravilnik o isticanju bazne cijene proizvoda.
  Cjenik slijedi redoslijed polja iz nacrta, uključuje neto količinu, a stupac cijene zove se `bazna_cijena`.
  Polja koja traži samo Odluka (dostupnost) ostaju, jer Odluka i dalje vrijedi.
- Šifra, marka, neto količina, jedinica mjere, cijena za jedinicu mjere i barkod obvezni su „ako je primjenjivo“.
- Zadnji objavljeni cjenik nikad se ne briše retencijom (nacrt: dostupnost 30 dana od zadnje objave).
- Nacrti se još mogu promijeniti; nakon objave konačnih pravilnika u Narodnim novinama plugin treba provjeriti.

## Sidrene cijene po poslovnici

Kartica **Sidrene cijene** drži trajnu tablicu sidrenih (baznih) cijena za svaku poslovnicu, u bazi
`{prefiks}scp_bazne`.

1. Jednom, za svaku poslovnicu, uvezi cjenik na referentni dan (CSV ili Excel iz blagajne). Artikli se prepoznaju
   po šifri, a ako šifre nema, po barkodu. Vrijednost 0,00 ili prazno znači da cijene nema i takav se artikl ne upisuje.
2. Pri svakoj dnevnoj objavi sidrena cijena se uzima iz tablice. Ako je datoteka s blagajne ima drukčiju, vrijedi
   tablica, a pregled to prikaže.
3. Artikl kojeg u tablici nema smatra se novim: sidrena mu je redovna cijena na dan prve objave, a ta se vrijednost
   upisuje u tablicu i dalje se ne mijenja. To vrijedi i za artikle koji su u uvozu imali 0,00.
4. Postojeće vrijednosti objava nikad ne mijenja; mijenja ih samo novi uvoz s izričito uključenim prepisivanjem.

Tablicu po poslovnici moguće je preuzeti kao CSV i, uz potvrdu, obrisati. Aktivacija, deaktivacija i nadogradnja je
ne diraju.

Akcija se prepoznaje u oba oblika: zaseban stupac `akcijska_cijena`, ili oznaka `DA` u stupcu
`posebni_oblik_prodaje` uz već sniženu maloprodajnu cijenu (format cjenika iz blagajne). Za jedinicu mjere `kom`
cijena za jedinicu mjere uvijek je cijena po komadu, i kad blagajna pošalje drukčiju vrijednost.

## Gdje se podaci čuvaju

Sve je u tablici `wp_options` (prefiks ovisi o instalaciji):

| Zapis | Sadržaj |
|---|---|
| `scp_poslovnice` | popis poslovnica; mijenja se samo spremanjem u adminu, vraćanjem iz povijesti ili uvozom |
| `scp_poslovnice_povijest` | zadnjih 30 verzija popisa, s vremenom, korisnikom i izvorom promjene |
| `scp_brojaci` | sljedeći broj pohrane po poslovnici; mijenja se pri svakoj objavi |
| `scp_settings`, `scp_settings_backup` | ostale postavke i njihova kopija |
| `scp_manifest` | za koji dan vrijedi koja objavljena datoteka |
| tablica `scp_bazne` | sidrene (bazne) cijene po poslovnici i artiklu |

Objavljene datoteke su u `wp-content/uploads/sidrena-cijena/poslovnice/<id poslovnice>/`.

Aktivacija, deaktivacija, nadogradnja i objava cjenika nikad ne mijenjaju popis poslovnica. Zadane postavke se ne
zapisuju u bazu nego čitaju iz koda, a svako spremanje čita trenutnu vrijednost izravno iz baze, mimo object cachea,
pa zastarjeli keš ne može prepisati novije podatke. Na kartici Poslovnice su povijest s vraćanjem jednim klikom te
izvoz i uvoz popisa u JSON-u. Nadogradnja sa starijih verzija sama prenosi popis iz `scp_settings` u `scp_poslovnice`.

## Instalacija

1. Instaliraj zip kroz Dodaci → Dodaj novi → Prenesi dodatak i aktiviraj. Potreban PHP 8.1+, WordPress 6.5+.
2. **Cjenik poslovnica → Poslovnice**: za svaku poslovnicu upiši naziv, oblik objekta (prodavaonica), adresu,
   oznaku (npr. P-01) i početni broj pohrane. Od toga se gradi naziv datoteke, npr.
   `prodavaonica_ilica-150-zagreb_P-01_104_01.10.2026_07-45.csv`, prema primjeru iz pojašnjenja Ministarstva gospodarstva
   od 18. 9. 2026. Broj pohrane je redni broj datoteke i sam se povećava sa svakom objavom.
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

Obvezni stupci: `barkod`, `naziv`, `cijena`. Neobavezni: `akcijska_cijena`, `dostupnost`, `bazna_cijena` (ili `sidrena_cijena`), `neto_kolicina`,
`sifra`, `marka`, `jedinica_mjere`, `cijena_za_jedinicu_mjere`. Predložak se preuzima u adminu. Izlazna datoteka
ima 14 stupaca propisanih Odlukom: one koje ne šaljete plugin računa (maloprodajna cijena, oznaka i naziv akcije)
ili ostavlja prazne (kategorija, url), a datum sidrene cijene upisuje iz postavki.

- Nazivi stupaca prepoznaju se automatski i po sinonimima (Kataloški broj, EAN, GTIN, MPC, „Prosječna MP cijena“,
  „Naziv artikla“, Količina, Jed.mj., akcija, zaliha, stanje...). Redoslijed nije bitan, višak stupaca se ignorira.
- Separator `;`, `,` ili tab, decimalni zarez ili točka, UTF-8 ili Windows-1250 (izvoz s blagajne).
- Akcija: ako je `akcijska_cijena` upisana i niža od `cijena`, u cjeniku ide akcijska cijena, „posebni oblik
  prodaje = DA“ i naziv akcije iz postavki.
- Marka: ako je u datoteci prazna ili stupca nema, upisuje se „Zadana marka“ iz postavki (prazno = ostaje prazno).
  Pregled prije objave pokazuje koliko artikala nema marku.
- Jedinica mjere `kom` (komad): cijena za jedinicu mjere automatski je jednaka maloprodajnoj cijeni po komadu,
  ako nije zasebno poslana. Za kg, l i slično treba poslati stupac `cijena_za_jedinicu_mjere`.
- Dostupnost: `dostupno`/`nedostupno`, `da`/`ne`, `1`/`0`, količina (>0 = dostupno). Ako stupca nema,
  vrijedi zadana vrijednost iz postavki.
- **Barkod**: po pojašnjenju Ministarstva od 18. 9. 2026. obvezan je „ako je primjenjivo“; roba bez barkoda ima prazno polje.
- **Sidrena cijena**: Odluka (točka III.) traži je u cjeniku. Ako je blagajna isporuči, prenosi se; ako ne,
  stupac ostaje prazan i pregled to prikaže kao upozorenje. Objava nije blokirana.

## Izlaz

Stupci su identični webshop pluginu (jedinstvena struktura za sve objekte), redoslijed prema nacrtu Pravilnika:
naziv; šifra; marka; neto količina; jedinica mjere; maloprodajna cijena; posebni oblik prodaje (DA/NE);
naziv posebnog oblika prodaje; cijena za jedinicu mjere; barkod; bazna (sidrena) cijena; dostupnost; datum bazne cijene;
kategorija; url. Kategorija i url su prazni.

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

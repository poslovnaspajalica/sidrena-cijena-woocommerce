# Sidrena cijena za WooCommerce

WordPress/WooCommerce plugin za obveze iz Odluka Vlade RH objavljenih u NN 101/2026, koje vrijede od 1. 10. 2026.:

1. **Isticanje sidrene (dodatne) cijene** uz aktualnu cijenu na webshopu, sa sidrenim datumom 10. 9. 2026.
2. **Objava strojno čitljivog cjenika** (.csv i .xml) na web stranici, ažuriranog dnevno do 8:00 i dostupnog 30 dana.

Pravni temelj: Zakon o iznimnim mjerama kontrole cijena (NN 40/2025).

## Sadržaj repozitorija

- [`sidrena-cijena/`](sidrena-cijena/) – **webshop plugin** (WooCommerce): isticanje sidrene cijene i dnevni cjenik
- [`sidrena-cijena/README.md`](sidrena-cijena/README.md) – upute za instalaciju, postavke, cron, uvoz, WP-CLI
- [`sidrena-cijena-poslovnice/`](sidrena-cijena-poslovnice/) – **plugin za fizičke poslovnice**: ručni dnevni upload CSV-a s blagajne,
  pretvorba u propisani .csv/.xml i objava na istoj stranici `/cjenik/` (radi i bez WooCommercea)
- [`sidrena-cijena-poslovnice/README.md`](sidrena-cijena-poslovnice/README.md) – upute

Oba zipa su u [Releases](../../releases).

Službeni tekstovi odluka: [NN 101/2026, 1212](https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1212.html) (isticanje dodatne cijene) i [NN 101/2026, 1213](https://narodne-novine.nn.hr/clanci/sluzbeni/full/2026_09_101_1213.html) (objava cjenika).

## Ukratko

- Snapshot redovnih cijena u polje sidrene cijene (jedan klik ili automatski nakon aktivacije), ručni unos po proizvodu i varijaciji, uvoz iz CSV-a po SKU/EAN-u.
- Prikaz na stranici proizvoda, listinzima, košarici (klasična i blokovi), s prekidačem i automatskim uključenjem na datum.
- Višejezične oznake (Polylang, WPML, TranslatePress, `?lang=`).
- Izuzimanje pojedinih proizvoda ili kategorija (npr. preorder).
- Dnevni cjenik u batchevima (testirano na 24.000 proizvoda), javna stranica `/cjenik/`, stabilni linkovi, JSON indeks, retencija.
- Ručno generiranje ili dnevni cron u zadano vrijeme (WP-Cron ili URL okidač za hosting cron).

## Zahtjevi

WordPress 6.5+, WooCommerce 8.0+, PHP 8.1+.

## Licenca

GPL-2.0-or-later.

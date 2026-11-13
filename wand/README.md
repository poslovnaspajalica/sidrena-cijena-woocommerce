# Automatski cjenik poslovnica iz 4D Wanda

Dva zadatka u Windows Task Scheduleru na računalu na kojem radi Wand:

1. **Izvoz iz Wanda** (05:30): `W_EXE.EXE ANA=142` izvozi cjenike skladišta, skripta ih provjerava i sprema u mapu
   s datumom.
2. **Slanje na server** (odmah nakon izvoza, i zatim svakih 20 minuta do 08:45 dok sve nije poslano): datoteke idu
   na web, gdje ih plugin "Sidrena cijena - cjenik poslovnica" (1.6.0+) pretvara i objavljuje na `/cjenik/`.

| Šifra | Skladište | Poslovnica na webu |
|---|---|---|
| 0000112 | Maloprodaja Zagreb | Zagreb |
| 0000114 | Maloprodaja Osijek | Osijek |
| 0000110 | Maloprodaja Split | Split |

Wand datoteke imenuje ovako, a po šifri skladišta u nazivu skripte i web prepoznaju poslovnicu:

```
Prodavaonica_Gundulićeva 7, 012101 Centar_PJ2-0000112_2_20261113_111226.csv
Prodavaonica_Trg Ante Starčevića (pothodnik) , 011440 Centar_PJ4-0000114_2_20261113_111227.csv
Prodavaonica_Dioklecijanova 6, 011701 Get_PJ3-0000110_2_20261113_111225.csv
```

## Mape

```
C:\Cjenici\
  _wand\                  Wand zapisuje ovdje (postavlja se jednom u Wandu)
  2026-11-17\             arhiva tog dana
    neispravne\           datoteke koje nisu prošle provjeru (ne šalju se)
  za_slanje\              red za slanje; poslana datoteka se briše (original je u arhivi)
    odbijeno\             datoteke koje je server odbio (stiže i e-mail)
  log\wand-izvoz-2026-11.log
  log\slanje-2026-11.log
  skripte\                wand-izvoz-cjenika.ps1, posalji-cjenike.ps1, instaliraj-zadatak.ps1, kljuc.txt
```

## Postavljanje

**Na webu** (Cjenik poslovnica):

1. Kartica **Poslovnice**: upiši šifru skladišta svakoj poslovnici (tablica gore).
2. Kartica **Postavke → Automatski prijem cjenika**: uključi prijem i spremi. Kopiraj generirani ključ.

**Na računalu s Wandom:**

1. Kopiraj tri `.ps1` skripte u `C:\Cjenici\skripte\`. Uz njih napravi `kljuc.txt` i u njega zalijepi ključ
   (samo ključ, jedan red). Datoteku ne šalji e-mailom i ne stavljaj na dijeljene mape.
2. Provjeri parametre na vrhu `wand-izvoz-cjenika.ps1`: `$WandExe` (`W_EXE.EXE` iz **lokalne** instalacije, ista mapa
   kao `WAND.EXE` iz ikone Wanda, npr. `C:\4d\Wand\SYS\W_EXE.EXE`; kopija na serverskom disku W: javlja
   "Podatak o lokaciji servera 4D Wanda nije upisan"), `$WandMapa` (`C:\Cjenici\_wand`), `$ArhivaMapa` (`C:\Cjenici`).
3. U `C:\4D\Wand\Win\4D_Wand.ini` (ili `4D_Wand_<Windows korisnik>.ini`) u sekciji `[WAND]` upiši
   `AutoUser=<ID>`: ID korisnika 4D Wanda pod kojim se izvršavaju automatske obrade (Korisnici i prava pristupa).
   Bez toga `W_EXE.EXE ANA=142` završi bez greške, ali ne napravi nijednu datoteku.
4. U Wandu, prijavljen kao Windows korisnik pod kojim će raditi zadaci, napravi jedan **ručni** izvoz sidrenih
   cjenika s putanjom `C:\Cjenici\_wand`. Wand tu putanju pamti za automatske izvoze.
5. Za istog korisnika u Wandu provjeri tvrtku i godinu ("Godina za automatske provjere, importe i exporte").
   **Početkom 2027. godinu provjeri ponovno.**
6. Provjera veze i ključa (ništa ne šalje):
   ```
   powershell -ExecutionPolicy Bypass -File C:\Cjenici\skripte\posalji-cjenike.ps1 -Provjera
   ```
   Ispisuje poslovnice sa šiframa skladišta kako ih vidi web.
7. Kao administrator stvori zadatke (pita za Windows korisnika i lozinku):
   ```
   powershell -ExecutionPolicy Bypass -File C:\Cjenici\skripte\instaliraj-zadatak.ps1
   ```
8. Probno pokretanje:
   ```
   Start-ScheduledTask -TaskName 'Cjenik poslovnica - izvoz iz Wanda'
   ```
   Pogledaj oba loga u `C:\Cjenici\log\` i karticu Postavke na webu (Zadnji prijemi).

Računalo mora biti uključeno ujutro. Ako je u 05:30 ugašeno, zadatak se pokreće čim se upali; rok objave je 08:00.

## Zaštite

| Gdje | Provjera | Posljedica |
|---|---|---|
| izvoz | Wand ne završi u 20 min (npr. čeka dijalog) | proces se prekida, greška u logu |
| izvoz | krivo zaglavlje ili manje od 100 redaka | datoteka u `neispravne\`, ne šalje se |
| izvoz | nema datoteke za neko skladište | greška u logu i izlazni kod 1 |
| web | manje od 50 % artikala zadnje objave | odbijeno, e-mail, ostaje jučerašnji cjenik |
| web | nepoznata šifra skladišta | odbijeno, e-mail |
| web | ista datoteka poslana ponovno | ne objavljuje se dvaput |
| slanje | nema veze ili server ne odgovara | datoteka ostaje u redu, novi pokušaj za 20 min |
| web | nijedna datoteka do 07:00 | podsjetnik e-mailom (postavka plugina) |

Ako je datoteka odbijena, a sadržaj je ispravan (npr. namjerno smanjen asortiman), objavi je ručno na webu iz
arhivske mape; sljedeći automatski prijem uspoređuje se s tom objavom.

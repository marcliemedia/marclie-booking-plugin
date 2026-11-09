# Marclie Booking – priručnik za osoblje

Kratke upute za svakodnevni rad s rezervacijama smještaja. Nazivi u **podebljanom** tekstu odgovaraju gumbima i karticama u administraciji.

## 1. Prijava i uloge

- Prijava: `https://<domena>/wp-admin/` → u lijevom izborniku WordPressa **Rezervacije** (aplikacija se otvara preko cijelog zaslona).
- **Administrator** – potpuni pristup, uključujući **E-mailovi** i **Postavke**.
- **Upravitelj rezervacija** (Booking Manager) – **Pregled**, **Kalendar**, **Rezervacije**, **Gosti**, **Plaćanja**, **Cijene**, **Kuponi**, **Aktivnosti**. Nema pristup **E-mailovi** ni **Postavke** (prikazuje se „Nema pristupa”). Povrat iznad uvjeta otkazivanja može odobriti samo administrator.
- Dodavanje upravitelja: **Postavke › Korisnici i uloge › Dodaj upravitelja rezervacija** (korisnik već mora imati WordPress račun).
- Brza pretraga: polje **Traži…** u gornjoj traci (ili Ctrl+K) – broj rezervacije, ime, e-pošta.
- Oznaka **TESTNI NAČIN** u gornjoj traci znači da su aktivni Stripe testni ključevi – ne naplaćuje se stvarni novac.

## 2. Pregled

Početni zaslon: **Popunjenost ovaj mjesec / sljedeći mjesec**, **Naplaćeno**, **Ostaci iznosa za naplatu**, **Nove rezervacije**, **Dolasci danas**, **Odlasci danas**, **Nadolazeći ostaci iznosa**.

Okvir **Treba pozornost** prikazuje sve što traži radnju (vidi poglavlje 13). Svaku stavku riješite istog dana.

Zapisnik aktivnosti je zasebna kartica **Aktivnosti** u izborniku: svaka promjena (rezervacije, gosti, plaćanja, cijene, postavke, kuponi), najnovija prva, 25 po stranici. Čuvanje (svaki dan u 9:00 brišu se stari zapisi):

- zapisi vezani uz rezervaciju ili gosta ostaju trajno (to je i **Povijest** u listu rezervacije);
- ostali zapisi (cijene, postavke, kuponi, testni e-mailovi, obrisani računi …) čuvaju se 12 mjeseci, najviše zadnjih 2000;
- tehnički zapisi Stripe webhooka čuvaju se 90 dana.

## 3. Kalendar

- Prikaz: **2 tjedna**, **Mjesec**, **90 dana**; **Danas**, **Prethodno razdoblje**, **Sljedeće razdoblje**. **Cijene i min. boravak** prikazuje u praznim ćelijama cijenu apartmana po noćenju i cijenu po osobi (mjeseci bez cijene ostaju prazni).
- Boje/legenda: **Plaćeno**, **Potvrđeno**, **Čeka plaćanje**, **Plaćanje nije uspjelo**, **Blokirano**, **Drugi kalendar** (Airbnb/Booking.com), **Dvostruka rezervacija (iCal sukob)**.
- Tipkovnica: strelicama se krećete po ćelijama, PageUp/PageDown prikazuje prethodno/sljedeće razdoblje, Enter otvara rezervaciju ili odabir.
- **Blokiranje datuma** (zatvaranje dana): označite raspon u retku apartmana → **Blokiraj datume**, po potrebi označite i druge apartmane ili **Svi apartmani**, upišite razlog (npr. „prije sezone”, „boravak vlasnika”). Ako je ijedan odabrani apartman zauzet tih noći, ništa se ne blokira i poruka navodi koji. Blokadu uklonite klikom na nju → **Ukloni blokadu**.
- **Ručna rezervacija**: označite raspon → **Nova rezervacija** (ili gumb **Nova rezervacija** gore). Čarobnjak ima tri koraka: **Boravak** (apartman, datumi, **Odrasli (11+)**, **Djeca (5–10)**, **Djeca (0–4)**, **Kupon (neobavezno)**) → **Gost** (traži postojećeg ili **Izradi novog gosta**) → **Plaćanje**:
  - **Još bez plaćanja** – rezervacija ostaje „Čeka plaćanje” i ne istječe; kasnije pošaljite **Poveznica za plaćanje**.
  - **Plaćeno izvan sustava** – gotovina ili bankovni prijenos već primljeni; upišite **Primljeni iznos** i **Način**.
  - Završite s **Izradi rezervaciju**.
- **Premještanje**: u listu rezervacije **Promijeni datume** → odaberite apartman i datume → **Pregledaj promjenu** (prikazuje **Trenutačni iznos**, **Novi iznos**, **Razlika**; po želji **Zadrži izvornu cijenu**) → **Premjesti rezervaciju**. Boravak koji je već počeo ne može se premjestiti.

## 4. Rezervacije

- Kartice filtara: **Sve**, **Nadolazeće**, **Neplaćeno**, **Akontacija plaćena**, **Plaćeno u cijelosti**, **Plaćanje nije uspjelo**. **Izvezi CSV** izvozi trenutačni popis.
- Klik na redak otvara list rezervacije (kartice **Pregled**, **Plaćanja**, **E-mailovi**, **Povijest**, **Bilješke**).

Radnje u listu rezervacije:

| Zadatak | Postupak |
|---|---|
| Gost je platio gotovinom / prijenosom | **Evidentiraj plaćanje** → **Vrsta** (Akontacija / Ostatak iznosa / Puni iznos), **Način**, **Iznos**, **Napomena** (npr. poziv na broj) → **Nastavi** → **Evidentiraj …**. Ne može se poništiti iz administracije. |
| Odmah naplatiti ostatak sa spremljene kartice | **Naplati sada** → potvrdite **Naplati …**. Ako banka odbije, rezervacija prelazi u „Plaćanje nije uspjelo”. |
| Poslati gostu poveznicu za plaćanje | **Poveznica za plaćanje** → **Vrijedi (dana)** (1–30) → **Izradi i pošalji** (ili **Izradi poveznicu** i **Kopiraj** ako je šaljete sami). |
| Otkazati rezervaciju | **Više › Otkaži rezervaciju** → odaberite **Povrat prema uvjetima**, **Bez povrata** ili **Prilagođeni iznos** (zahtijeva **Razlog**) → **Otkaži i vrati …**. Datumi se odmah oslobađaju, gost dobiva e-mail o otkazivanju. Kartična plaćanja vraćaju se putem Stripea automatski; gotovina/prijenos označeni su „vratiti ručno” – vratite ih sami. |
| Promijeniti podatke gosta | **Uredi gosta** (ime, e-pošta, telefon, broj gostiju, **Procijenjeno vrijeme dolaska**). |
| Ponovno poslati e-mail | kartica **E-mailovi** → **Pošalji ponovno** uz željeni predložak. |
| Interna napomena | kartica **Bilješke** → **Spremi bilješke** (gost ih ne vidi). |

## 5. Gosti

- Gosti se izrađuju automatski s prvom rezervacijom; e-pošta identificira gosta.
- Profil: **Pošalji e-mail**, **Nova rezervacija za gosta**, **Oznake**, **Interne bilješke**, **Privola za marketing**.
- **Spajanje duplikata**: **Više radnji › Spoji s drugim gostom…** → odaberite profil koji ostaje (**Zadrži ovaj profil**) → **Spoji**. Rezervacije i plaćanja prelaze na zadržani profil.
- **GDPR**:
  - Zahtjev za kopiju podataka: **Više radnji › Izvezi podatke (JSON)** i pošaljite datoteku gostu.
  - Zahtjev za brisanje: **Više radnji › Anonimiziraj (GDPR)…**, upišite `ANONYMIZE` → **Anonimiziraj**. Nepovratno; financijski zapisi rezervacija ostaju bez osobnih podataka.

## 6. Plaćanja

- **Sve transakcije**: sve akontacije, ostaci, puna plaćanja i povrati; filtri po statusu, vrsti, načinu, datumu; **Izvezi CSV** za knjigovodstvo.
- **Zakazano**: ostaci iznosa koji dospijevaju u sljedećih 30 dana. Naplaćuju se automatski na datum dospijeća.
- **Otvori u Stripe Dashboardu** (u listu rezervacije) vodi na pojedino kartično plaćanje.

## 7. Cijene

- Tablica **Cijene** ima karticu za svaku kuću (objekt), npr. **Kuća 1**, **Kuća 2**: redak po apartmanu (s kapacitetom), stupci **Min.** (minimalno noćenja), **Gosti** (minimalan broj gostiju za naplatu, od 1 do kapaciteta) i siječanj–prosinac. Upišite cijenu u EUR **po osobi po noćenju**; ispod se prikazuje najniža cijena apartmana po noćenju (cijena po osobi × minimalan broj gostiju). Prazan mjesec (sivo, „—”) je **zatvoren**: u njemu nema dolaska ni noćenja (na kalendaru web-stranice prikazuje se kao zatvoreno). Nespremljene promjene obiju kartica spremaju se zajedno (točka na kartici označava promjene).
- Tablica vrijedi za svaku godinu. Promjena vrijedi za nove rezervacije; postojeće zadržavaju cijenu s kojom su rezervirane.
- Obračun po noćenju: odrasli (od 11 godina) × cijena po osobi + djeca od 5 do 10 godina × pola cijene po osobi (zaokruženo na cent). Ako je to manje od minimalnog broja gostiju iz stupca **Gosti**, naplaćuje se minimum × cijena po osobi. Djeca od 0 do 4 godine borave besplatno i ne ulaze u kapacitet ni u minimum. Gost vidi obračun u retku cijene, npr. Apartman 101 (minimum 2), lipanj 70 €: 1 odrasla osoba → „140 € × 7 noćenja (najmanje 2 osobe × 70 €)”, 2 odrasla + 1 dijete 5–10 → „175 € × 7 noćenja (2 odrasla × 70 € + 1 dijete × 35 €)”. Uz svaku cijenu „od … po osobi / noć” piše i minimum („min. 2 osobe”). Svako noćenje plaća se po cijeni svog mjeseca. Kupon se obračunava na ukupni iznos.
- **Ispuni stupac…** (npr. lipanj = 70 za sve apartmane kuće na otvorenoj kartici) i **Ispuni redak…** (isti iznos za svih 12 mjeseci) upisuju vrijednost u ćelije; po potrebi ispravite pojedine ćelije. Tab, Enter i strelice pomiču po ćelijama, Esc vraća ćeliju, a blok kopiran iz Excela može se zalijepiti. Na kraju **Spremi promjene** (ili **Odbaci**).
- Pojedinačne dane (npr. prije početka sezone ili usred otvorenog mjeseca) zatvorite u **Kalendar › Blokiraj datume** (poglavlje 3).
- Svaka promjena bilježi se u zapisniku aktivnosti.
- Kada promijenite min. noćenja, ručno ispravite i tekstove koji ga ponavljaju: pitanje „Koliko noćenja moram rezervirati?” i redak „Minimalni boravak” u „Važno znati” svakog apartmana.

## 8. Kuponi

- **Novi kupon**: **Kod** (ili **Generiraj**), **Vrsta popusta** (**Postotak** / **Fiksni iznos**), **Razdoblje rezervacije**, **Razdoblje boravka**, **Min. noćenja**, **Ukupno korištenja**, **Korištenja po gostu**, **Jedinice**, **Samo za gosta** → **Spremi kupon**.
- Popust vrijedi za cijenu smještaja, ne za boravišnu pristojbu.
- Nekorišteni kupon može se **Obriši**, korišteni samo **Deaktiviraj**. Kartica **Korištenje** prikazuje iskorištenja.
- Kuponi dobrodošlice i članski kuponi (Srebrni/Zlatni) izrađuju se automatski.

## 9. E-mailovi (samo administrator)

- Popis: **Gostima** i **Vama (administrator)**. Prekidač **Uključeno / Isključeno** određuje šalje li se predložak automatski.
- **Uređivanje**: otvorite predložak → kartica **Hrvatski** ili **Engleski** → **Predmet**, **Pretekst**, **Naslov**, **Tekst poruke**. Podatke umećite s **Umetni oznaku** (npr. `{booking_ref}`); obavezne oznake moraju ostati (**Nedostaju obavezne oznake** blokira spremanje). **Spremi**.
- **Pregled**: desno, **Računalo / Mobitel**, **HTML / Običan tekst**; **Podaci za pregled** – **Primjer podataka** ili stvarna rezervacija.
- **Pošalji testni e-mail**: šalje tekst iz uređivača (i nespremljen) s primjerom podataka na upisanu adresu.
- **Vrati zadano (…)**: **Više radnji › Vrati zadano** vraća izvorni tekst za taj jezik.

## 10. Postavke (samo administrator)

| Kartica | Što se ovdje podešava |
|---|---|
| **Općenito** | **Plaćanja i otkazivanje**: **Akontacija** (%), **Ostatak iznosa naplaćuje se prije dolaska** (dana), **Besplatno otkazivanje do** (dana), **Zadržavanje termina pri plaćanju** (minuta), **Zadani kupon**, prefiks broja rezervacije (1–6 slova/brojki, zadano `BK`, npr. `BK-27-0712-101`; vrijedi za nove rezervacije); **Dolazak i odlazak** (**Dolazak od**, **Dolazak do**, **Odlazak do**) → **Spremi uvjete**. Promjene vrijede za nove rezervacije. |
| **Stripe** | **Veza sa Stripeom** (LIVE/TEST/**Nije postavljeno**), **Provjeri vezu**, **Webhook** (URL, **Zadnji primljeni događaj**, **Zadnja pogreška potpisa**). Ključeve upisuje programer u `wp-config.php`. |
| **Stranice** | koje WordPress stranice služe kao Rezervacija, Hvala, Plaćanje, Pretraga, Uvjeti, Prijava, Registracija, Moj račun, Zaboravljena lozinka → **Spremi stranice**. |
| **iCal** | uvoz kalendara Airbnb/Booking.com po apartmanu (**Dodaj poveznicu**, **Sinkroniziraj sada**, **Sinkroniziraj sve**) i poveznice za izvoz (**Kopiraj**). Detalji: `docs/ical-setup.md`. |
| **Obavijesti** | **Primatelji u administraciji**, **Ime pošiljatelja**, **E-mail pošiljatelja**, **Adresa za odgovor** → **Spremi**. |
| **Korisnici i uloge** | dodavanje/uklanjanje upravitelja rezervacija. |
| **Napredno** | održavanje – ne mijenjati bez programera. |

## 11. Dnevna rutina

1. **Pregled › Treba pozornost** – riješite svaku stavku (poglavlje 13).
2. **Dolasci danas** – provjerite vrijeme dolaska i poruke gostiju; otvoreni ostatak naplatite ili evidentirajte.
3. **Odlasci danas** – organizirajte čišćenje.
4. **Plaćanja › Zakazano** – ostaci u sljedećim danima; gosti bez spremljene kartice dobivaju **Poveznica za plaćanje**.
5. **Rezervacije › Neplaćeno** – stare rezervacije „Čeka plaćanje”: pošaljite poveznicu ili otkažite.
6. Gotovinu/prijenose primljene danas evidentirajte (**Evidentiraj plaćanje**).
7. **Postavke › iCal** (jednom dnevno) – nema li poveznica sa statusom **Greška**.

## 12. Statusi rezervacije

**Čeka plaćanje** → **Potvrđeno** (akontacija plaćena) → **Plaćeno** (sve plaćeno) → **Završeno** (nakon odlaska). Ostalo: **Plaćanje nije uspjelo**, **Otkazano**, **Isteklo** (gost nije dovršio plaćanje na vrijeme).

## 13. Upozorenja u „Treba pozornost”

| Upozorenje | Značenje | Što učiniti |
|---|---|---|
| **Plaćanje ostatka iznosa nije uspjelo** (`balance_failed`) | Automatska naplata ostatka odbijena; gost je automatski dobio e-mail s poveznicom (vrijedi 7 dana). | Otvorite rezervaciju. Nazovite/javite se gostu. Ako plati drugom karticom – status se sam vraća u **Plaćeno**. Inače: **Naplati sada** (nakon što gost potvrdi da je kartica u redu), nova **Poveznica za plaćanje**, ili **Evidentiraj plaćanje** ako plati gotovinom. Ako gost ne plati do roka – **Otkaži rezervaciju** prema uvjetima. |
| **Dvostruka rezervacija / iCal sukob** (`ical_conflict`) | Naša rezervacija preklapa se s datumima zauzetim na Airbnbu ili Booking.comu. | Odmah provjerite na portalu koja je rezervacija stvarna. Premjestite jednu od njih (**Promijeni datume** na drugi slobodni apartman) ili otkažite rezervaciju na portalu. Upozorenje nestaje samo kad sukob više ne postoji (nakon sljedeće sinkronizacije – **Sinkroniziraj sada**). |
| **Stripe webhook ne stiže** (`webhook_silent`) | Kartična plaćanja prolaze, ali Stripe ne javlja potvrde stranici; plaćanja se potvrđuju sporije (rezervnim putem). | Javite programeru: provjeriti webhook URL u Stripeu i `MBK_STRIPE_WEBHOOK_SECRET`. U **Postavke › Stripe** pogledajte **Zadnji primljeni događaj** i **Zadnja pogreška potpisa**. Do popravka provjeravajte nove rezervacije u Stripe Dashboardu. |
| **Spor oko plaćanja** (`dispute`) | Banka gosta osporila je kartično plaćanje (chargeback). | **Prikaži u Stripeu** i u roku koji Stripe navodi pošaljite dokaze (potvrda rezervacije, e-mailovi, uvjeti otkazivanja, prijava boravka). Ne vraćajte novac izvan Stripea. |
| Zahtjev za otkazivanje | Gost je iz svog računa zatražio otkazivanje. | Otvorite rezervaciju, provjerite uvjete i **Otkaži rezervaciju**, ili se javite gostu. |
| Zadržavanje isteklo, ali nije oslobođeno | WP-Cron ne radi. | Javite programeru. |
| Kalendar se ne može uvesti / nije sinkroniziran | Poveznica iCal ne radi ili cron stoji. | **Postavke › iCal** – **Uredi poveznicu** (nova poveznica s portala) ili javite programeru. |
| Stripe nije postavljen | Nedostaju ključevi – online plaćanje isključeno. | Javite programeru. |

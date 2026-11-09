# iCal sinkronizacija / iCal sync — Airbnb & Booking.com

> HR first, English below. Menu names in Airbnb and the Booking.com extranet change from time to time; if a label differs, look for "Sync calendars" / "Sinkronizacija kalendara".

## HR — kratke upute

### Što radi
- **Izvoz (web → OTA):** svaka smještajna jedinica ima tajnu adresu kalendara, posebno za Airbnb, Booking.com i "ostalo". U njoj su sve rezervacije s weba (čekaju uplatu, potvrđene, plaćene…), ručne blokade i blokade s *drugih* kanala. Airbnb nikad ne dobiva natrag vlastite blokade (isto vrijedi za Booking.com), pa nema petlje.
- **Uvoz (OTA → web):** plugin svakih 15 minuta preuzima kalendar s Airbnba i Booking.coma i blokira te noći na webu. Neposredno prije online rezervacije kalendar te jedinice osvježava se ako je stariji od 5 minuta.
- Prenosi se **samo zauzeto/slobodno**. Bez cijena, minimalnog boravka, imena gostiju i poruka (ograničenje iCal formata). Na izvozu nema osobnih podataka: svaki događaj glasi "{naziv objekta} – zauzeto" (naziv = ACF opcija `brand_name`, inače naziv web-stranice). UID-ovi događaja završavaju s `@{domena web-stranice}`.

### 1. Adrese s weba u Airbnb
1. Admin › Postavke › iCal: kod jedinice kopirajte **URL za Airbnb**.
2. Airbnb › Kalendar › odaberite oglas › **Dostupnost** › **Poveži kalendare / Sync calendars** › **Poveži drugi kalendar** (Connect another calendar).
3. Zalijepite URL, nazovite ga npr. "{naziv objekta} web" i spremite.
4. Ponovite za svaki oglas. Jedinice bez Airbnb oglasa preskočite.

### 2. Adrese s weba u Booking.com
1. Admin › Postavke › iCal: kopirajte **URL za Booking.com**.
2. Extranet › **Cijene i dostupnost** › **Sinkronizacija kalendara** (Rates & Availability › Sync calendars) › odaberite tip sobe › **Dodaj vezu kalendara** › uvoz kalendara.
3. Zalijepite URL i spremite.

### 3. Adrese OTA-a u plugin
1. Airbnb: na istom mjestu (Sync calendars) kopirajte **Izvoz kalendara** (Export calendar, adresa završava s `.ics`).
2. Booking.com: u Sinkronizaciji kalendara kopirajte poveznicu za **izvoz** kalendara te sobe.
3. Admin › Postavke › iCal › jedinica › kanal: zalijepite adresu. Plugin je odmah testira (mora biti `https://` i vratiti iCal) i prikaže broj preuzetih događaja ili razlog greške.
4. Gumb **Sinkroniziraj sada** preuzima odmah; inače se to radi svakih 15 minuta.

### Ograničenja (važno)
- **Kašnjenje:** plugin čita OTA svakih 15 min (i neposredno prije online rezervacije), ali Airbnb i Booking.com čitaju naš kalendar kad sami odluče — obično svakih nekoliko sati. U tom razmaku moguća je dvostruka rezervacija.
- **U sezoni:** preporuka je na Airbnbu i Booking.comu uključiti zahtjev za rezervaciju / ručnu potvrdu (ili razmak između boravaka) i svaki dan provjeriti Pregled.
- Ako je kalendar OTA-a nedostupan, web koristi zadnje preuzete podatke. Nakon više od 2 sata greške stiže e-mail administratoru (jednom po nizu grešaka), a na Pregledu je upozorenje.
- Ako se tajna adresa izvoza proširi, u Postavke › iCal kliknite **Nova adresa (rotate)** i novu adresu ponovno zalijepite u Airbnb i Booking.com — stara prestaje raditi.
- Airbnb i Booking.com ne mogu čitati izvoz s lokalne ili testne web-stranice (npr. `https://localhost`) — samo s javne domene. Nakon promjene domene kopirajte adrese ponovno (sadrže domenu, a mijenjaju se i UID-ovi događaja).

### Kako izgleda sukob
Kad uvezena blokada pada na noći rezervacije s weba ili iz admina:
- na **Pregledu** crveno upozorenje "… overlaps dates blocked on Airbnb" s poveznicom na rezervaciju;
- u **Kalendaru** isprekidana traka kanala označena kao sukob;
- administrator dobiva **jedan e-mail** po sukobu ("Calendar conflict").

Ništa se ne otkazuje automatski. Riješite s gostom ili na OTA-u isti dan. Kad sukob nestane (otkazivanje na jednoj strani), upozorenje nestaje samo.

#### "Odjek" (echo) našeg izvoza
Airbnb i Booking.com u svom izvozu prikazuju i datume koje su preuzeli od nas. Plugin bilježi kad je svaki OTA zadnji put pročitao naš kalendar. Uvezena blokada je **odjek** samo ako ima točno iste datume kao naš događaj (rezervacija, ručna blokada ili blokada drugog kanala koju mu šaljemo) koji je postojao **prije** tog čitanja; to se odlučuje jednom, pri prvom uvozu. Airbnb događaj "Reserved" nikad nije odjek.
- Odjek blokira datume na webu, ali se **nikome ne šalje dalje** i **nije sukob** — tako se Airbnb i Booking.com ne mogu međusobno "držati" zauzetima nakon otkazivanja.
- Ako je naša rezervacija otkazana, a OTA i 24 sata nakon što je ponovno pročitao naš kalendar još uvijek blokira te datume, Pregled prikazuje informaciju (bez e-maila): oslobodite datume na OTA-u.
- Sve ostale uvezene blokade su vanjske: blokiraju web, šalju se drugim kanalima i prijavljuju sukob bez obzira na naziv ("CLOSED - Not available" na Booking.comu je i prava rezervacija).
- Ograničenje: prava rezervacija s točno istim datumima napravljena na OTA-u u kratkom razmaku između naše rezervacije i njegova čitanja našeg kalendara može se zabilježiti kao odjek. U Kalendaru su odjeci označeni (`origin`), pa ih možete provjeriti.

## EN — short operator guide

### What it does
- **Export (website → OTA):** every unit has a secret calendar URL per channel (Airbnb, Booking.com, other) listing website bookings (pending payment, confirmed, paid…), manual blocks and blocks imported from *other* channels. A channel never gets its own blocks back, so there is no loop.
- **Import (OTA → website):** every 15 minutes the plugin downloads the Airbnb / Booking.com calendars and blocks those nights. Right before an online booking, the unit's calendars are refreshed when older than 5 minutes.
- **Busy/free only:** no prices, minimum stay, guest names or messages (an iCal limitation). The export contains no personal data: every event is "{business name} – busy" (translated, e.g. HR "– zauzeto"; business name = ACF option `brand_name`, falling back to the site title). Event UIDs end in `@{site host}`.

### Setup
1. **Website → Airbnb:** Admin › Settings › iCal › copy the unit's *Airbnb URL* → Airbnb › Calendar › listing › Availability › **Sync calendars / Connect calendars** › Connect another calendar › paste. Skip units that have no Airbnb listing.
2. **Website → Booking.com:** copy the *Booking.com URL* → Extranet › **Rates & Availability › Sync calendars** › room type › Add calendar connection › import › paste.
3. **OTA → website:** copy Airbnb's *Export calendar* link and Booking.com's export link → Admin › Settings › iCal › unit › channel › paste. The URL must be `https://`; it is test-fetched right away (event count or the reason it failed). **Sync now** imports immediately.
4. CLI: `studio wp mbk ical urls` prints every export URL; `studio wp mbk ical sync [--unit=<code>] [--feed=<id>]` imports now.

### Limitations
- **Delay:** OTAs poll our calendar on their own schedule (often every few hours) [INFERENCE: not documented by the OTAs]. A double booking remains possible in that window. In peak season enable request-to-book / manual confirmation (or a buffer) on the OTAs and check the dashboard daily.
- A failing OTA calendar keeps the last imported blocks; after 2 hours of failures the admin gets one email per failure streak and a dashboard alert.
- If an export URL leaks, **Rotate** it in Settings › iCal and paste the new URL into Airbnb / Booking.com again.
- OTAs cannot read the export from a local or staging site (e.g. `https://localhost`), only from a public domain. Copy the URLs again after a domain change (they contain the domain, and event UIDs change with it).
- Deactivating or deleting an import feed removes its blocks from the website calendar.

### Conflicts
An external imported block overlapping a website/admin booking shows a red alert on the dashboard and a flagged bar in the calendar, and sends one "Calendar conflict" email to the admin (once per booking, channel and dates). Nothing is cancelled automatically; resolve it with the guest or on the OTA the same day. The alert disappears once the overlap is gone.

### Echoes of our own export
Both OTAs list the dates they imported from us in their own export. The plugin records when each OTA last read our calendar (per unit and `for=` channel). An imported block is an **echo** only if its dates exactly match an event we export to that channel (a booking, a manual block, or another channel's block) that existed **before** that read; this is decided once, on first import. An Airbnb "Reserved" event is never an echo.
- Echoes block the website calendar but are **never re-exported** and **never conflicts**, so the two OTAs cannot keep each other's echoes alive after a cancellation.
- If our event is gone and the OTA still lists the echo 24 hours after it read our export again, the dashboard shows an info item (`ical_stale_echo`, no email): free the dates on the OTA.
- Every other imported block is external: it blocks the website, is exported to the other channels and raises conflicts whatever its title (Booking.com real reservations are "CLOSED - Not available" too).
- Limitation: a real OTA reservation with exactly the same dates, made in the short window between our booking and the OTA's next read of our calendar, can be recorded as an echo. Echoes are marked in the calendar (`origin`) so they can be checked.

### Fetch safety
Import URLs must be `https://`, also after every redirect (at most 3, each checked); the host must resolve only to public addresses (IPv4 and IPv6); at most 5 MB and 2,000 events per feed. Before an online booking the unit's calendars get one 3-second budget in total (no redirects); conflicts found then are handled a moment later by WP-Cron. Recurring events (RRULE) are expanded for daily/weekly rules; other rules block only their first date.

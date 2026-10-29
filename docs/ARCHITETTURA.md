# Architettura di Booking Manager

Booking Manager è un plugin WordPress autonomo (PHP 8.1+, WordPress 6.6+) per B&B, affittacamere, case vacanza, appartamenti, residence, guest house e hotel. Non dipende da Night, Beach o Restaurant Manager e non li modifica: condivide con loro solo il server licenze e alcune convenzioni.

| Cosa | Valore |
|---|---|
| Slug e text domain | `booking-manager` |
| Namespace PHP | `BookingManager\` |
| Prefisso (tabelle, opzioni, ruoli, capability, hook) | `bkng_` |
| API dello staff | `booking-manager/v1` (login WordPress + nonce) |
| API del modulo pubblico | `booking-manager/public/v1` (nessun login) |

## Livelli

```
assets/src/admin   App React del gestionale (@wordpress/scripts, palette verde)
assets/src/public  Modulo di prenotazione sul sito (colori e font del tema)
        │  fetch JSON
src/Rest           Controller REST: permessi, validazione dei parametri, risposta
        │
src/<Dominio>      Servizi: regole di business, transazioni, audit
        │
src/<Dominio>/*Repository  Accesso al database ($wpdb, query preparate)
```

- **Controller** (`src/Rest`): non contengono regole. Controllano la capability e la struttura del record (`require_cap_for_property`, `require_cap_for_record`), chiamano un servizio e convertono il risultato. `RestController::paged()` imposta l'header `X-WP-Total`.
- **Servizi**: un servizio per dominio (`BookingService`, `AvailabilityService`, `PaymentService`, `CheckinService`, `HousekeepingService`, `NotificationService`, `RateService`, `InventoryService`, …). Sono gli unici che scrivono e registrano l'audit.
- **Repository**: SQL con `$wpdb->prepare`, nessuna logica.
- **Container** (`Core\Container`): fabbriche registrate in `Plugin::register_services()`. I moduli opzionali si aggiungono con il filtro `bkng_modules` (vedi [HOOKS.md](HOOKS.md)).

## Dati

33 tabelle `wp_bkng_*`, create e aggiornate da migrazioni (`Database\Migrations\Migration_YYYYMMDD_NNNNNN`), registrate in `bkng_migrations`:

- strutture e accesso: `properties`, `user_properties`;
- inventario: `room_types`, `units`, `unit_blocks`, `unit_nights`, `unit_status_log`;
- tariffe e disponibilità: `rate_plans`, `rate_prices`, `rate_adjustments`, `restrictions`, `holds`;
- ospiti: `guests`, `guest_consents`;
- prenotazioni: `bookings`, `booking_rooms`, `booking_guests`, `checkins`;
- pagamenti: `payment_accounts`, `payments`, `payment_transactions`, `webhook_events`;
- messaggi: `notifications`, `templates`;
- canali: `channel_refs`, `sync_log`;
- Channex: `cx_dirty`, `cx_state`, `cx_tasks`, `cx_revisions`, `cx_webhook_log`;
- trasversale: `audit_log`.

Convenzioni:

- importi in **centesimi interi**, valuta per struttura;
- date di soggiorno `Y-m-d` nel fuso della struttura; colonne `*_at` in **UTC**;
- stati come `VARCHAR`, nessuna foreign key (come gli altri plugin della famiglia);
- colonna `version` per il blocco ottimistico: una modifica con versione vecchia riceve **409**.
- servizi a pagamento (colazione, parcheggio, cancellazione gratuita…) sono supplementi facoltativi in `rate_adjustments`; `free_cancel_days` (dalla migrazione `20261025_000001`) dice che il supplemento include la cancellazione gratuita fino a N giorni prima: se l'ospite lo sceglie, `Rates\Policies::with_options()` sostituisce le condizioni della tariffa, e la prenotazione le conserva in `terms_snapshot` (per piano tariffario).

## Nessuna doppia prenotazione

`Availability\Ledger` conta le camere libere di una tipologia per ogni notte: camere attive meno soggiorni, chiusure e blocchi temporanei (holds) validi. Ogni scrittura:

1. apre una transazione e blocca le righe delle tipologie coinvolte (`SELECT … FOR UPDATE` in ordine di id, quindi senza deadlock tra richieste);
2. ricontrolla disponibilità, regole e prezzo sul database (quello che ha mostrato il modulo non conta);
3. scrive le notti occupate in `unit_nights`, che ha **chiave primaria (unit_id, night)**: anche con un errore nel codice il database rifiuta la stessa camera due volte nella stessa notte.

Il giorno di partenza non è mai una notte occupata. Il test `ConcurrencyTest` lancia 8 processi PHP separati sull'ultima camera nello stesso istante: ne riesce sempre uno solo (vedi [TEST-REPORT.md](TEST-REPORT.md)).

Il modulo pubblico blocca le camere scelte con un **hold** di N minuti (impostazione della struttura) mentre l'ospite compila i dati; l'hold si libera se l'ospite torna indietro o chiude la pagina, e scade da solo.

## Stati

- Prenotazione: `pending` (richiesta), `option` (in attesa di pagamento online), `confirmed`, `in_house`, `completed`, `cancelled`, `no_show`. Le transizioni ammesse sono in `Bookings\BookingStatus`; ogni passaggio richiede una capability (`BookingsController::STATUS_CAPS`).
- Camera nel soggiorno (`stay_status`): `expected`, `in_house`, `departed` (`removed` quando una modifica la toglie): check-in e check-out si fanno per camera e determinano lo stato della prenotazione.
- Stato operativo della camera (housekeeping): `dirty`, `cleaning`, `clean` (pulita, da controllare), `ready`, `maintenance`.

## Ruoli

Cinque ruoli WordPress dedicati, modificabili da **Staff → Ruoli e permessi** (l'Amministratore di WordPress ha sempre tutto). Valori predefiniti:

| Capability (`bkng_…`) | Amministratore | Responsabile struttura | Prenotazioni | Reception | Housekeeping |
|---|:-:|:-:|:-:|:-:|:-:|
| access, view_dashboard, view_housekeeping, update_housekeeping | ✓ | ✓ | ✓ | ✓ | ✓ |
| view_bookings, view_guests, view_payments, create_booking, edit_booking, checkin, mark_no_show, record_payment | ✓ | ✓ | ✓ | ✓ | |
| confirm_booking, cancel_booking, edit_guest | ✓ | ✓ | ✓ | | |
| refund_payment, override_restrictions, manage_rates, manage_rooms, manage_property_settings, manage_payment_accounts, view_reports, export_reports | ✓ | ✓ | | | |
| access_all_properties, manage_properties, manage_staff, manage_roles, manage_settings, manage_integrations, view_audit_log, anonymize_guest, export_guest_data | ✓ | | | | |

Chi non ha `access_all_properties` vede solo le strutture assegnate (`user_properties`); i record di altre strutture rispondono **404**, così non si scopre nemmeno che esistono. L'housekeeping non vede importi né contatti degli ospiti.

## Attività pianificate

- ogni ora (`bkng_hourly`): scadenza delle opzioni in attesa di pagamento; stato delle approvazioni dei testi WhatsApp;
- controllo dei pagamenti online rimasti aperti (`bkng_payments_reconcile`);
- coda messaggi (`bkng_notifications`) e promemoria prima dell'arrivo (`bkng_reminders`);
- ogni giorno (`bkng_daily`): chiusura automatica dei soggiorni finiti, pulizia degli holds scaduti, conservazione dei dati secondo **Impostazioni → Conservazione dei dati** (anonimizzazione degli ospiti senza soggiorni, pulizia di registro attività, messaggi, sincronizzazioni e check-in).
- Licenza: controllo periodico con il server (`LicenseScheduler`).
- Channex: ogni minuto (`bkng_channex_tick`) invio delle notti cambiate e lettura del feed; ogni notte alle 03:00 (`bkng_channex_nightly`) sincronizzazione completa o controllo delle differenze.

## Channex

Il plugin resta l'unica fonte di verità: Channex riceve ciò che il plugin calcola e manda le prenotazioni dei portali, che il plugin salva con le sue regole.

- **Configurazione**: `Channels\Channex\ChannexSettings` (opzione `bkng_channex`, `autoload = no`; chiave API e chiave di firma cifrate con `Core\SecretBox`). Le costanti `BKNG_CHANNEX_API_KEY`, `BKNG_CHANNEX_WEBHOOK_SECRET` e `BKNG_CHANNEX_BASE_URL` in `wp-config.php` hanno la precedenza. I segreti non tornano mai dall'API e non finiscono nei registri.
- **Mappatura**: in `bkng_channel_refs` con canale `channex` (proprietà, tipologie, coppie tipologia × tariffa; tariffa 0 = prezzo base della tipologia).
- **Uscita (ARI)**: non ci sono tabelle di inventario; i valori si calcolano al momento dell'invio. `AriProjector` legge le camere libere da `Availability\Ledger`, il prezzo da `PricingEngine` e le regole da `restrictions` e dalle impostazioni della struttura. Ogni modifica (`bkng_availability_changed`, `bkng_rates_changed`, azioni delle prenotazioni) segna le notti in `cx_dirty` (una riga per tipo, tipologia, tariffa e notte, con `dirty_seq`). `ChannexSync` prende una fotografia della coda, unisce le notti uguali in intervalli, manda **una** chiamata `availability` e **una** `restrictions` per struttura e cancella solo le righe con lo stesso `dirty_seq`: una notte cambiata durante l'invio parte con la chiamata dopo. Almeno 6 secondi tra due chiamate dello stesso tipo (10 al minuto), pausa crescente su 429/5xx (1, 2, 4… fino a 30 minuti), sospensione su 401. Stato in `cx_state`, attività di Channex in `cx_tasks`.
- **Sincronizzazione completa**: 500 giorni in 2 chiamate, alla mappatura, dal pulsante o la notte se mai fatta; altrimenti la notte `reconcile` rilegge la disponibilità da Channex e rimette in coda le notti diverse.
- **Entrata**: `ChannexBookings` legge `GET /booking_revisions/feed` (webhook firmato HMAC-SHA256 su `{timestamp}.{corpo}` che anticipa la lettura; altrimenti ogni 2 minuti), salva ogni revisione in `cx_revisions` senza i dati di pagamento, la applica con `BookingService` (`create`, `change_stay`, `set_status`) dentro una transazione e un `DbLock`, e solo dopo manda l'ack. Una revisione già applicata riceve solo l'ack. Le prenotazioni dei portali possono andare in overbooking (`accept_overbooking`): sono già vendute, quindi si salvano e parte un avviso.
- **Avvisi**: `ChannexAlerts` (opzione `bkng_channex_alerts`, avviso nel gestionale ed email all'amministratore al massimo una all'ora).
- **Endpoint `secure-*` di Channex**: mai usati; i dati della carta non vengono letti né salvati.

## WhatsApp

`Notifications\Channels\WhatsAppChannel` è un canale della coda come l'email: prende il testo approvato da `WhatsApp\WhatsAppSettings` (opzione `bkng_whatsapp`, una per il sito), lo riempie con `WhatsApp\WhatsAppVariables` e lo invia con il servizio scelto (`WhatsApp\MetaCloudProvider`). `WhatsApp\WhatsAppService` gestisce la schermata (collegamento, testi e approvazioni, prova, registro, webhook di Meta). Le colonne `delivery_status` e `delivery_at` di `bkng_notifications` tengono «consegnato» e «letto».

## Email

`Notifications\EmailDesign` scrive l'HTML (tabelle e stili in linea, colonne che si impilano su telefono); `Notifications\EmailBrand` porta l'aspetto scelto in Email → Aspetto (impostazioni `email_*` della struttura). Logo, foto e icone sono allegati in linea (`cid:`): le icone di `assets/email` sono tracciate in nero e prendono il colore scelto con GD al momento dell'invio (senza GD l'email esce senza icone). L'anteprima nel gestionale sostituisce i `cid:` con indirizzi `data:`.

## Privacy e registri

- I registri (audit, messaggi, check-in) non contengono dati personali in chiaro oltre al riferimento al record; l'IP si salva solo se attivato, ed è troncato.
- Esportazione e anonimizzazione dell'ospite da **Ospiti** (capability dedicate), oltre agli strumenti privacy di WordPress.
- Disinstallazione: **non cancella nulla** a meno che l'amministratore abbia attivato «Cancella tutti i dati quando il plugin viene eliminato». In quel caso rimuove tabelle, opzioni, ruoli, preferenze utente e la cartella `uploads/booking-manager`, e libera la licenza sul server se risponde.

## Interfaccia

- **Gestionale**: app React in una pagina di amministrazione (`#/` router con hash), palette verde (#173F2E barra laterale, #1F5C43 primario, #2F8A5F accento, #E3F0E8 tenue, #F2F6F3 sfondo). «Modalità operativa» nasconde il menu di WordPress per la reception su tablet.
- **Modulo pubblico**: shortcode `[booking_manager_prenota struttura="slug"]` (la configurazione guidata crea la pagina; `intestazione="no"` toglie il banner con la foto). Ricerca per date e ospiti, poi filtri (tipologia, capacità, servizi, prezzo a notte, solo disponibili, cancellazione gratuita) e ordinamento applicati alle camere già trovate, senza nuove richieste; schede con galleria e foto ingrandibili. Usa i font del tema. Il colore principale è quello scelto in **Struttura → Prenotazioni e modulo online → Colore del modulo**; se manca, il colore «primary» del tema (`--wp--preset--color--primary`), altrimenti il verde di Booking Manager. Pulsanti e campi hanno stili propri, con selettori più forti di quelli dei temi (es. Astra stilizza ogni `<button>`), così il modulo resta uguale su ogni tema. Script leggero (~48 KB, solo `wp-element` e `wp-i18n`).

Altri documenti: [LICENZA.md](LICENZA.md), [PAGAMENTI.md](PAGAMENTI.md), [CLOUDFLARE.md](CLOUDFLARE.md), [HOOKS.md](HOOKS.md), [API.md](API.md), [SVILUPPO.md](SVILUPPO.md), [TEST-REPORT.md](TEST-REPORT.md).

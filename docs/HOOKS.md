# Hook, estensioni e integrazioni

Chi integra Booking Manager (altri plugin della famiglia, un channel manager, codice su misura) deve usare **solo** questi hook, la funzione `bkng()` e l'[API REST](API.md), mai le tabelle. I nomi qui sotto sono un contratto pubblico (`src/Core/Hooks.php`): cambiarli è una modifica incompatibile.

## Azioni

Ogni azione di dominio riceve **l'id dell'entità** e un **contesto senza dati personali** (`property_id`, `property_uuid`, `booking_uuid`, `status`, `source`, riferimenti esterni). Le azioni partono dopo che il dato è salvato: un listener che lancia un'eccezione non annulla l'operazione.

| Azione | Quando |
|---|---|
| `bkng_loaded` | plugin caricato (riceve l'istanza di `Plugin`) |
| `bkng_migrated` | migrazioni del database applicate (riceve l'elenco) |
| `bkng_property_created`, `bkng_property_archived` | struttura creata / archiviata |
| `bkng_booking_created` | nuova prenotazione (gestionale, modulo, canale) |
| `bkng_booking_confirmed` | prenotazione confermata |
| `bkng_booking_updated` | dati, date o camere cambiati |
| `bkng_booking_cancelled` | annullata (staff, ospite, sistema) |
| `bkng_booking_checked_in` | prenotazione in soggiorno |
| `bkng_booking_no_show` | segnata come non presentata |
| `bkng_booking_completed` | soggiorno concluso |
| `bkng_room_checked_in`, `bkng_room_checked_out` | check-in / check-out di una singola camera |
| `bkng_payment_recorded`, `bkng_payment_refunded`, `bkng_payment_failed` | pagamenti |
| `bkng_unit_status_changed` | stato operativo di una camera (housekeeping) |
| `bkng_notification_sent`, `bkng_notification_failed` | messaggio inviato / fallito |
| `bkng_payment_needs_attention` | un pagamento tardivo non si è potuto rimborsare da solo: serve lo staff (`$payment_id`, `$error`) |
| `bkng_audit_failed` | il registro attività non ha potuto scrivere (`$error`, `$args`) |
| `bkng_module_failed` | un modulo opzionale non si è caricato (`$module`, `$error`) |

```php
add_action( 'bkng_booking_confirmed', function ( int $booking_id, array $context ) {
	// $context['property_uuid'], $context['booking_uuid'], $context['source'] …
}, 10, 2 );
```

## Filtri

| Filtro | Uso |
|---|---|
| `bkng_payment_providers` | aggiunge fornitori di pagamento (oggetti `Payments\Providers\PaymentProvider`) |
| `bkng_channel_connectors` | aggiunge connettori verso OTA o channel manager (`Channels\ChannelConnector`) |
| `bkng_notification_channels` | aggiunge canali di notifica (`Notifications\Channels\NotificationChannel`) |
| `bkng_whatsapp_providers` | servizi WhatsApp per id (`Notifications\WhatsApp\WhatsAppProvider`): inclusi `meta` (WhatsApp Cloud API) e `test` (nessun messaggio parte) |
| `bkng_whatsapp_graph_version` | versione della Graph API di Meta usata (predefinita `v23.0`) |
| `bkng_modules` | moduli opzionali (`Core\ModuleInterface`: `services()` e `hooks()`); se uno fallisce il plugin continua senza |
| `bkng_accept_status` | stato che prende una richiesta dal sito quando lo staff la accetta: `confirmed`, oppure `option` (il plugin lo fa quando i pagamenti online sono attivi: l'ospite paga e il pagamento conferma) |
| `bkng_manage_url` | indirizzo «gestisci la prenotazione» inviato agli ospiti |
| `bkng_client_ip` | IP del visitatore dietro un proxy diverso da Cloudflare (fidarsi dell'header solo se la richiesta viene davvero dal proxy) |

## Canali di vendita (OTA, channel manager)

Booking Manager **non include connettori** e non imita l'API di nessun portale: ogni canale ha il suo contratto e la sua certificazione, e nessun dato viene preso con lo scraping. È pronta l'infrastruttura che un connettore usa:

- **in entrata**: `ChannelService::import_reservation( $canale, $property_id, $prenotazione )` (campi: `external_id`, `modified`, `status` confirmed/cancelled, `arrival`, `departure`, `guest`, `rooms[]` con `external_room_type_id`, `adults`, `children`, `price_total`) importa una prenotazione una sola volta (idempotente), la collega con `bkng_channel_refs` e registra ogni tentativo in `bkng_sync_log`; non manda email all'ospite; ciò che non entra (camera piena, tipologia non mappata) viene registrato e riprovato;
- **in uscita**: dopo ogni cambio di disponibilità della struttura viene chiamato `push_availability( $property_id, $room_type_ids, $from, $to )` di ogni connettore attivo;
- **mappature**: **Struttura → Canali** abbina le tipologie ai codici del canale (`PUT /properties/{id}/channels/{canale}/mappings`).

Indipendente da questi connettori, la voce **Booking e Airbnb** collega già le camere ai portali con i calendari iCal (solo date, ogni 15 minuti: vedi ARCHITETTURA.md). Un connettore implementa `Channels\ChannelConnector` (`key()`, `label()`, `unavailable( $property )`, `push_availability(…)`) e si registra con `bkng_channel_connectors`.

## WhatsApp

Come in Night Manager: un numero WhatsApp Business per tutto il sito, collegato in **WhatsApp → Collegamento** con la WhatsApp Cloud API di Meta (ID del numero, ID dell'account Business, token permanente, chiave segreta dell'app facoltativa). Le credenziali sono cifrate con `Core\SecretBox`, non tornano mai dall'API e non finiscono nei registri.

- **Testi**: un testo per ogni messaggio all'ospite (richiesta ricevuta, richiesta accettata con «Completa il pagamento», confermata, modificata, annullata, promemoria, pagamento, benvenuto), con `{variabili}`. Salvare un testo lo manda in approvazione come nuovo modello (`bm_{messaggio}_v{n}`, categoria UTILITY): finché non è approvato parte la versione approvata prima. Le approvazioni si controllano ogni ora (`bkng_hourly`) e con «Aggiorna lo stato delle approvazioni».
- **Pulsante**: WhatsApp non nasconde un link dietro una parola, quindi il link alla pagina della prenotazione è un pulsante sotto il testo. L'URL del pulsante è `home/?bkng_wa=` + `{{1}}` = `CODICE.CHIAVE`; il sito verifica la chiave e porta l'ospite alla pagina della prenotazione.
- **Invio**: con WhatsApp collegato e acceso, ogni messaggio attivo entra nella coda `bkng_notifications` (canale `whatsapp`) accanto all'email e parte con gli stessi tentativi. Il testo si riempie al momento dell'invio con la prenotazione com'è allora.
- **Rinuncia**: partono a tutti gli ospiti con un cellulare, tranne chi ha spuntato «Non voglio ricevere messaggi WhatsApp» (nel modulo o nella scheda ospite: riga `guest_consents` con scopo `whatsapp`). Con WhatsApp acceso il modulo pubblico chiede il cellulare.
- **Consegnato e letto**: webhook `GET/POST /booking-manager/public/v1/whatsapp/webhook` (token di verifica; aggiornamenti firmati `X-Hub-Signature-256` con la chiave segreta dell'app). Lo stato va solo avanti: inviato → consegnato → letto.

Un altro servizio si aggiunge con `bkng_whatsapp_providers`, implementando `Notifications\WhatsApp\WhatsAppProvider` (`fields()`, `check()`, `send()`, `submit_template()`, `template_status()`).

## Fornitori di pagamento

Una classe che implementa `Payments\Providers\PaymentProvider` (configurazione, verifica, webhook, pagina di pagamento, stato, incasso, annullamento, rimborso), di solito estendendo `AbstractProvider`, registrata con `bkng_payment_providers`. Il resto del plugin parla solo con `PaymentService` e non sa quale fornitore è in uso.

## PHP

`bkng()` restituisce l'istanza di `BookingManager\Core\Plugin`; `bkng()->get( Classe::class )` dà un servizio. Preferire l'API REST quando possibile: i servizi interni possono cambiare tra versioni minori, gli hook e le rotte no.

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
| `bkng_whatsapp_provider` | fornitore WhatsApp (`Notifications\WhatsApp\WhatsAppProvider`), `null` per nessuno |
| `bkng_modules` | moduli opzionali (`Core\ModuleInterface`: `services()` e `hooks()`); se uno fallisce il plugin continua senza |
| `bkng_manage_url` | indirizzo «gestisci la prenotazione» inviato agli ospiti |
| `bkng_client_ip` | IP del visitatore dietro un proxy diverso da Cloudflare (fidarsi dell'header solo se la richiesta viene davvero dal proxy) |

## Canali di vendita (OTA, channel manager)

Booking Manager **non include connettori** e non imita l'API di nessun portale: ogni canale ha il suo contratto e la sua certificazione, e nessun dato viene preso con lo scraping. È pronta l'infrastruttura che un connettore usa:

- **in entrata**: `ChannelService::import_reservation( $canale, $property_id, $prenotazione )` (campi: `external_id`, `modified`, `status` confirmed/cancelled, `arrival`, `departure`, `guest`, `rooms[]` con `external_room_type_id`, `adults`, `children`, `price_total`) importa una prenotazione una sola volta (idempotente), la collega con `bkng_channel_refs` e registra ogni tentativo in `bkng_sync_log`; non manda email all'ospite; ciò che non entra (camera piena, tipologia non mappata) viene registrato e riprovato;
- **in uscita**: dopo ogni cambio di disponibilità della struttura viene chiamato `push_availability( $property_id, $room_type_ids, $from, $to )` di ogni connettore attivo;
- **mappature**: **Struttura → Canali** abbina le tipologie ai codici del canale (`PUT /properties/{id}/channels/{canale}/mappings`).

Un connettore implementa `Channels\ChannelConnector` (`key()`, `label()`, `unavailable( $property )`, `push_availability(…)`) e si registra con `bkng_channel_connectors`.

## WhatsApp

Predisposto, non attivo: nessun fornitore è incluso e il plugin **non finge** invii. Senza un fornitore il canale WhatsApp risulta non disponibile e i messaggi vanno solo per email. Un add-on implementa `Notifications\WhatsApp\WhatsAppProvider` (`key()`, `unavailable( $property )`, `send( Message )`) e lo restituisce dal filtro `bkng_whatsapp_provider`; le sue credenziali si salvano cifrate con `Core\SecretBox`.

## Fornitori di pagamento

Una classe che implementa `Payments\Providers\PaymentProvider` (configurazione, verifica, webhook, pagina di pagamento, stato, incasso, annullamento, rimborso), di solito estendendo `AbstractProvider`, registrata con `bkng_payment_providers`. Il resto del plugin parla solo con `PaymentService` e non sa quale fornitore è in uso.

## PHP

`bkng()` restituisce l'istanza di `BookingManager\Core\Plugin`; `bkng()->get( Classe::class )` dà un servizio. Preferire l'API REST quando possibile: i servizi interni possono cambiare tra versioni minori, gli hook e le rotte no.

# API REST

Due spazi di nomi. Le risposte sono JSON e non vanno in cache (`no-store` per il modulo pubblico, i pagamenti e la licenza; le richieste dello staff, autenticate, ricevono le intestazioni anti-cache di WordPress); gli errori hanno la forma di WordPress (`code`, `message`, `data.status`, e per la validazione `data.fields` con un messaggio per campo).

## Staff: `booking-manager/v1`

Richiede un utente collegato con le capability del ruolo (vedi [ARCHITETTURA.md](ARCHITETTURA.md#ruoli)) e il nonce `X-WP-Nonce` (l'app del gestionale lo invia da sola). Regole comuni:

- senza login: **401**; senza capability: **403**; record di una struttura non assegnata all'utente: **404**;
- le modifiche con il campo `version` vecchio rispondono **409** (qualcun altro ha cambiato il record);
- le creazioni di prenotazioni e pagamenti accettano una chiave di idempotenza (`request_id`): ripetere la richiesta non crea doppioni;
- gli elenchi paginati hanno l'header `X-WP-Total`;
- senza licenza valida alcune scritture rispondono **403** `bkng_license_required` (vedi [LICENZA.md](LICENZA.md)).

### Sessione, configurazione guidata, licenza

| Metodi | Rotta |
|---|---|
| GET | `/` |
| GET | `/me` |
| POST, PUT, PATCH | `/me/preferences` |
| GET | `/setup` |
| POST | `/setup/page` |
| POST | `/setup/finish` |
| GET | `/license` |
| POST | `/license/activate` |
| POST | `/license/refresh` |
| POST | `/license/updates/check` |
| POST | `/license/deactivate` |

### Strutture e impostazioni

| Metodi | Rotta |
|---|---|
| GET, POST | `/properties` |
| GET, POST, PUT, PATCH | `/properties/{id}` |
| POST, PUT, PATCH | `/properties/{id}/settings` |
| POST | `/properties/{id}/{action}` |
| GET, POST, PUT, PATCH | `/settings` |

### Camere e chiusure

| Metodi | Rotta |
|---|---|
| GET, POST | `/properties/{property_id}/room-types` |
| POST, PUT, PATCH | `/room-types/{id}` |
| POST | `/room-types/{id}/archive` |
| GET, POST | `/properties/{property_id}/units` |
| POST | `/properties/{property_id}/units/batch` — più camere della stessa tipologia (`room_type_id`, `codes[]` fino a 100, `floor`): tutte o nessuna |
| POST, PUT, PATCH | `/units/{id}` |
| POST | `/units/{id}/{action}` |
| GET | `/properties/{property_id}/blocks` |
| POST | `/units/{id}/blocks` |
| DELETE | `/blocks/{id}` |

### Tariffe e regole

| Metodi | Rotta |
|---|---|
| GET, POST | `/properties/{property_id}/rate-plans` |
| POST, PUT, PATCH | `/rate-plans/{id}` |
| POST | `/rate-plans/{id}/archive` |
| GET, POST | `/properties/{property_id}/prices` |
| POST, PUT, PATCH, DELETE | `/prices/{id}` |
| GET, POST | `/properties/{property_id}/adjustments` |
| POST, PUT, PATCH | `/adjustments/{id}` |
| POST | `/adjustments/{id}/archive` |
| GET, POST | `/properties/{property_id}/restrictions` |
| POST, PUT, PATCH, DELETE | `/restrictions/{id}` |

Un supplemento accetta `free_cancel_days` (1-365 oppure `null`), solo se è facoltativo: chi lo sceglie può cancellare gratis fino a quel numero di giorni prima dell'arrivo.

### Disponibilità, planning, dashboard

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/availability` |
| GET | `/properties/{property_id}/availability/search` |
| GET | `/properties/{property_id}/planning` |
| GET | `/properties/{property_id}/day` |
| GET | `/properties/{property_id}/dashboard` |

### Prenotazioni

| Metodi | Rotta |
|---|---|
| GET, POST | `/properties/{property_id}/bookings` |
| GET, PATCH | `/bookings/{id}` |
| PUT | `/bookings/{id}/stay` |
| POST | `/bookings/{id}/status` |
| POST | `/booking-rooms/{id}/assign` |
| POST | `/booking-rooms/{id}/move` |
| GET | `/bookings/{id}/history` |

### Ospiti

| Metodi | Rotta |
|---|---|
| GET | `/guests` |
| GET, POST, PUT, PATCH | `/guests/{id}` |
| GET | `/guests/{id}/export` |
| POST | `/guests/{id}/anonymize` |

### Pagamenti

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/payment-accounts` |
| POST | `/properties/{property_id}/payment-accounts/select` |
| PUT, DELETE | `/properties/{property_id}/payment-accounts/{provider}/{mode}` |
| POST | `/properties/{property_id}/payment-accounts/{provider}/{mode}/verify` |
| GET, POST | `/bookings/{id}/payments` |
| POST | `/bookings/{id}/payment-link` |
| POST | `/payments/{id}/refund` |
| POST | `/payments/{id}/sync` |
| POST | `/webhooks/{provider}/{account}` |

### Reception e check-in

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/frontdesk` |
| POST | `/properties/{property_id}/checkin/scan` |
| POST | `/properties/{property_id}/checkin` |
| POST | `/properties/{property_id}/checkout` |
| GET | `/bookings/{id}/qr` |
| POST | `/bookings/{id}/qr/regenerate` |

### Housekeeping

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/housekeeping` |
| POST | `/units/{id}/op-status` |
| GET | `/units/{id}/op-history` |

### Messaggi

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/notifications` |
| POST | `/notifications/{id}/resend` |
| POST | `/bookings/{id}/notify` |
| POST | `/properties/{property_id}/notifications/test` |
| GET | `/properties/{property_id}/templates` |
| PUT, DELETE | `/properties/{property_id}/templates/{key}` |
| POST | `/properties/{property_id}/templates/{key}/preview` (`subject`, `body`, e `design` con le impostazioni `email_*` non ancora salvate) |

### WhatsApp (`bkng_manage_settings`)

| Metodi | Rotta |
|---|---|
| GET | `/whatsapp` (impostazioni senza segreti, messaggi con approvazione, variabili) |
| PUT | `/whatsapp/settings` (`active`, `country_code`) |
| PUT, DELETE | `/whatsapp/connection` (`provider` e i suoi campi; i segreti lasciati vuoti restano quelli salvati) |
| PUT | `/whatsapp/templates/{key}` (`body`, `button`, `is_active`, `reset`) |
| POST | `/whatsapp/templates/{key}/preview` (`body`, `button`: testo con la prenotazione di esempio) |
| POST | `/whatsapp/templates/{key}/test` (`to`: la versione approvata con la prenotazione di esempio) |
| POST | `/whatsapp/templates/refresh` |
| GET | `/whatsapp/messages` (`status`, `page`, `per_page`) |
| POST | `/whatsapp/messages/{id}/resend` |

`GET/POST /booking-manager/public/v1/whatsapp/webhook` è chiamata da Meta: verifica con il token, aggiornamenti firmati con la chiave segreta dell'app.

### Canali

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/channels` |
| PUT | `/properties/{property_id}/channels/{channel}/mappings` |

### Booking.com e Airbnb: calendari iCal (`bkng_manage_integrations`)

| Metodi | Rotta |
|---|---|
| GET | `/properties/{property_id}/ical` (camere con il link per i portali, calendari collegati, registro) |
| POST | `/properties/{property_id}/ical/feeds` (`unit_id`, `channel` booking/airbnb/vrbo/expedia/other, `url`): collega e legge subito |
| POST | `/properties/{property_id}/ical/sync` (legge tutti i calendari della struttura) |
| POST | `/ical/feeds/{id}/sync` |
| DELETE | `/ical/feeds/{id}` (libera le notti chiuse da quel calendario) |

Il calendario di una camera per i portali è `/?bkng_ical=ID.CHIAVE` (`text/calendar`, solo date «Non disponibile», nessun dato dell'ospite; chiave sbagliata → 404).

### Staff, ruoli, registro, Cloudflare

| Metodi | Rotta |
|---|---|
| GET | `/audit-log` |
| GET, POST | `/staff` |
| GET | `/staff/candidates` |
| POST, PUT, PATCH, DELETE | `/staff/{id}` |
| POST, PUT, PATCH | `/roles/{slug}` |
| GET | `/capabilities` |
| GET, POST, PUT, PATCH | `/cloudflare` |
| POST | `/cloudflare/verify` |
| POST | `/cloudflare/rules` |

`POST /webhooks/{provider}/{account}` è chiamata da Stripe e Nexi, non dallo staff: non richiede login e verifica la firma (Stripe) o rilegge l'ordine dal fornitore (Nexi). Vedi [PAGAMENTI.md](PAGAMENTI.md).

## Modulo pubblico: `booking-manager/public/v1`

Nessun login. Indirizzo usato dal modulo: `index.php?rest_route=/booking-manager/public/v1/…` (funziona anche senza permalink). Ogni richiesta ha limiti per IP; i dati mostrati non sono mai creduti: prezzi, regole e disponibilità si ricontrollano alla prenotazione.

| Metodi | Rotta | Uso |
|---|---|---|
| GET | `/` | indice |
| GET | `/properties` | strutture che accettano prenotazioni online |
| GET | `/properties/{slug}` | regole, tipologie, servizi aggiuntivi, primo giorno con camere libere (`first_free`), token del modulo, Turnstile |
| GET | `/properties/{slug}/search` | tipologie libere con tariffe e totale (`arrival`, `departure`, `adults`, `children`, `extras[]`); ogni tariffa dice anche se oggi la cancellazione è gratuita (`free_cancel`) e quanto costerebbe ogni servizio facoltativo per questo soggiorno (`options[]`: `id`, `amount` in centesimi); con `extras[]` il totale e le condizioni di cancellazione tengono conto dei servizi scelti |
| POST | `/properties/{slug}/holds` | blocca le camere scelte per N minuti |
| POST | `/holds/release` | libera il blocco |
| POST | `/properties/{slug}/bookings` | crea la prenotazione (con `form_token`, campo trappola `website`, `captcha_token`, `hold_token`, `privacy`; con WhatsApp acceso il telefono è obbligatorio e `whatsapp_opt_out` registra la rinuncia) |
| GET | `/manage/{code}` | la prenotazione dell'ospite (`?token=` dal link dell'email) |
| POST | `/manage/{code}/pay` | pagina di pagamento per quanto dovuto |
| POST | `/manage/{code}/cancel` | cancellazione da parte dell'ospite, se la tariffa lo consente |

# Piattaforma con più clienti (SaaS)

Booking Manager può girare su un'unica installazione WordPress centrale che serve più clienti (tenant). Ogni cliente ha le proprie strutture, il proprio staff e i propri ospiti, e incorpora il modulo di prenotazione nel suo sito con un iframe. Il progetto completo è stato approvato il 6 novembre 2026; i passi arrivano una versione alla volta.

| Versione | Passo | Stato |
|---|---|---|
| 0.2.0 | Clienti sopra le strutture, migrazione dei dati esistenti | ✓ |
| 0.2.1 | Isolamento: permessi, ospiti, registro attività, staff, libreria media | ✓ |
| 0.2.2 | Pannello Piattaforma: clienti, utenti, domini, piani, sospensione | ✓ |
| 0.2.3 | Pagina iframe `/booking/{struttura}`, CSP per cliente, pagamento fuori dall'iframe | ✓ |
| 0.2.4 | Contatti del cliente e pulsante WhatsApp | da fare |
| 0.2.5 | Email: mittente e SMTP del cliente, modelli, variabili, registro | da fare |
| 0.2.6 | WhatsApp Meta per cliente, Channex per struttura | da fare |
| 0.2.7 | Limiti per cliente, protezione login, documentazione | da fare |

## Modello

```
Piattaforma (amministratori WordPress)
└── Cliente (tenant)            bkng_tenants
    ├── utenti                  bkng_tenant_users (un utente appartiene a un solo cliente)
    ├── domini autorizzati      bkng_tenant_domains (solo per l'iframe, non sono un'autorizzazione)
    ├── ospiti                  bkng_guests.tenant_id
    └── strutture               bkng_properties.tenant_id
        └── camere, tariffe, disponibilità, prenotazioni, pagamenti, messaggi, Channex: per struttura come prima
```

`tenant_id` esiste solo dove il dato non dipende da una struttura: `properties`, `guests`, `guest_consents`, `audit_log`, `notifications` (per il registro). Le altre tabelle hanno già `property_id` (NOT NULL e indicizzato) e prendono il cliente dalla struttura: una copia in ogni tabella potrebbe divergere.

Stati del cliente (`Tenants\TenantStatus`): `active`, `trial`, `suspended`, `expired`, `archived`. Il server licenze vale solo per la piattaforma; piani e scadenze dei clienti si gestiranno dal pannello centrale (0.2.2).

`Tenants\Tenancy` decide il `tenant_id` da scrivere nelle righe nuove: quello della struttura, altrimenti il cliente predefinito, altrimenti NULL (sito non ancora migrato). Non legge mai valori inviati dal browser.

## Migrazione dei dati esistenti

L'aggiornamento del plugin aggiunge solo tabelle e colonne vuote (`Migration_20261106_000001_Tenants`): fino alla migrazione dei dati il plugin funziona come prima, e `tenant_id` NULL vale «cliente predefinito». I dati si collegano solo su comando:

```bash
wp db export backup-prima-dei-clienti.sql          # 1. backup
wp booking-manager tenant migrate --dry-run         # 2. cosa verrebbe fatto
wp booking-manager tenant migrate --backup-done     # 3. esegue (facoltativo: --name="Hotel Esempio Srl")
wp booking-manager tenant verify                    # 4. controllo (eseguito anche alla fine di migrate)
wp booking-manager tenant status
```

`migrate`:

1. rifiuta di partire senza `--backup-done`;
2. salva un'istantanea (`bkng_tenancy_snapshot`): righe per tabella fino all'ultimo id esistente, totali, pagato, rimborsato e saldo delle prenotazioni per stato, pagamenti e transazioni;
3. crea il cliente predefinito (nome dalla ragione sociale o dal nome della prima struttura, piano senza limiti, dominio del sito autorizzato) o riusa quello già marcato come predefinito;
4. imposta `tenant_id` solo dove è NULL, a blocchi di 1000 righe: nessuna riga cancellata, duplicata o rinumerata;
5. collega al cliente gli utenti con un ruolo di Booking Manager; gli amministratori di WordPress restano amministratori della piattaforma, fuori dai clienti;
6. verifica: righe e importi uguali all'istantanea (le righe nuove arrivate nel frattempo non contano), nessun dato senza cliente, nessuna struttura con un cliente inesistente, nessuna prenotazione con l'ospite di un altro cliente, nessun utente dello staff senza cliente.

Ripeterlo non cambia nulla. Su una nuova installazione il primo cliente viene creato all'attivazione.

**Tornare indietro**: `wp booking-manager tenant rollback --yes` rimette `tenant_id` a NULL e dimentica il cliente predefinito, senza cancellare clienti, utenti o domini (una nuova `migrate` li riusa). Il ritorno completo si fa ripristinando il backup.

## Isolamento (0.2.1)

Piattaforma e clienti si distinguono dal permesso `bkng_platform_manage`, che vale solo con `manage_options` (amministratori di WordPress). `RoleManager::strip_platform_caps` toglie a runtime `bkng_platform_manage`, `bkng_manage_settings` e `bkng_manage_roles` (`Capabilities::PLATFORM`) a chiunque non abbia `manage_options`, qualunque cosa ci sia nel ruolo salvato.

`Access\PropertyScope::allowed_ids()` resta l'unico punto che decide le strutture visibili:

| Utente | Strutture |
|---|---|
| piattaforma | tutte (`null`) |
| sito non ancora migrato | come prima della 0.2.0 |
| senza cliente | nessuna |
| con «Accedere a tutte le strutture» | tutte quelle del suo cliente |
| altri | quelle assegnate ∩ quelle del suo cliente |

Tutti i permessi per struttura o per record (`require_cap_for_property`, `require_cap_for_record`) passano da lì, quindi un ID di un altro cliente dà 404. Dove il dato non ha struttura:

- **ospiti**: `GuestService::find_or_create` cerca i duplicati (hash di email e telefono) solo nel cliente della struttura; `GuestsController` rifiuta una scheda di un altro cliente;
- **registro attività**: `AuditLogger` scrive il cliente esplicito, altrimenti quello della struttura, altrimenti quello dell'utente, altrimenti NULL (azione della piattaforma); `AuditLogReader` mostra le voci senza struttura solo al loro cliente e quelle NULL solo alla piattaforma;
- **staff**: `StaffService` limita elenco, aggiunta, modifica e rimozione al cliente dell'utente; le rotte `/staff/{id}` danno 404 per un utente di un altro cliente; `candidates` (ricerca tra tutti gli utenti del sito) è vuoto per i clienti;
- **libreria media**: `Tenants\TenantAccess` segna gli allegati (`_bkng_tenant_id`), filtra la libreria (`ajax_query_attachments_args`) e `allows_attachment()` rifiuta gli ID di un altro cliente in impostazioni della struttura e foto delle camere.

`tests/Integration/TenantIsolationTest.php` chiama il controllo dei permessi di ogni rotta di `booking-manager/v1` come amministratore di un secondo cliente con gli ID dei record del primo: tutte devono rifiutare, tranne quelle che agiscono solo sul proprio cliente (profilo, elenchi, aggiunta di strutture e staff, webhook dei pagamenti), che vengono controllate sui dati restituiti. Lo stesso giro come amministratore del primo cliente deve passare ovunque, così il test non può passare rifiutando tutto.

## Pannello della piattaforma e stato dei clienti (0.2.2)

**Piani** (`Tenants\Plans`, opzione `bkng_plans`): chiave, nome, `limits` (`max_properties`, `max_units`; 0 = nessun limite) e `features` (`online_payment`, `whatsapp_meta`, `channex`, `custom_smtp`). Il piano `unlimited` esiste sempre. Un cliente prende i valori del suo piano; `bkng_tenants.limits` e `bkng_tenants.features` contengono solo le eccezioni decise dalla piattaforma. Le funzioni sono salvate ma applicate da ciascuna funzione quando diventa per cliente.

**`Tenants\TenantGate`** è il corrispondente per cliente di `LicenseGate` (che resta la licenza della piattaforma):

| Stato | Effetto |
|---|---|
| `active`, `trial` non scaduti | tutto, nei limiti del piano |
| `suspended`, `expired` (anche solo `expires_at` passata) | niente nuove prenotazioni (staff, modulo, blocchi del modulo) né nuove strutture, tipologie o camere; tutto il resto funziona; le prenotazioni dai portali (`channel = import`) si salvano con la voce `tenant.booking_while_inactive` |
| `archived` | `PropertyScope` non dà strutture al suo staff, le pagine pubbliche rispondono «non trovata»; dati conservati, visibili alla piattaforma |

Lo chiedono i servizi, non le rotte: `BookingService::create`, `InventoryService` (tipologie, camere, ripristino di camere archiviate), `PropertyService::create` e `restore`, `PublicBookingService` (disponibilità nel modulo e blocchi). La piattaforma è vincolata dallo stato per le prenotazioni, ma non da stato e limiti per strutture e camere. Ogni giorno (`bkng_daily`) `expire_due()` porta a `expired` i clienti con la scadenza passata.

**API** (`Rest\PlatformController`, tutte con `bkng_platform_manage`):

| Rotta | Metodi |
|---|---|
| `/platform/tenants` | GET (`search`, `status`), POST (`name`, `business_type`, `plan`, `status`, `expires_at`, `admin{email, first_name, last_name}`) |
| `/platform/tenants/{id}` | GET (con domini, uso, limiti e funzioni effettivi), PUT (`name`, `business_type`, `plan`, `expires_at`, `limits{}`, `features{}`, `version`) |
| `/platform/tenants/{id}/(suspend\|activate\|archive)` | POST (`reason`, obbligatorio per sospendere) |
| `/platform/tenants/{id}/domains` | GET, POST `{host}`, DELETE `?host=` |
| `/platform/tenants/{id}/users` | GET, POST (come `/staff`, nel cliente) |
| `/platform/plans` | GET, PUT `{plans: [...]}` |
| `/platform/stats` | GET `?from&to` |
| `/tenant` | GET, per chiunque abbia `bkng_access`: il proprio cliente in sola lettura |

Le scritture sono in `WriteGate::ALLOWED`: la piattaforma gestisce i clienti qualunque sia lo stato della licenza. Le azioni sui clienti vanno nel registro attività senza cliente, quindi solo la piattaforma le legge.

Rimandati: il selettore «entra nel gestionale di un cliente» e le viste trasversali con filtro per cliente (oggi la piattaforma vede già tutte le strutture nel selettore in alto) passano alla 0.2.7.

## Modulo nel sito del cliente (0.2.3)

Il cliente incolla nel suo sito il codice che trova in **Struttura → Prenotazioni e modulo online** (solo sulla piattaforma, cioè con i clienti migrati):

```html
<iframe src="https://piattaforma.example/booking/villa-bianchi/" title="Villa Bianchi" style="width:100%;min-height:640px;border:0" loading="lazy"></iframe>
<script src="https://piattaforma.example/wp-content/plugins/booking-manager/assets/embed/embed.js" async></script>
```

**La pagina** (`Frontend\EmbedPage`, `template_redirect` priorità 1): `/booking/{slug}/`, oppure `/?bkng_embed={slug}` senza permalink. Nessuna regola di rewrite, quindi niente da rigenerare; la base `booking` si cambia col filtro `bkng_embed_base`. È lo stesso modulo dello shortcode (stesso script, stessa API pubblica) stampato senza il tema, con `<base target="_top">`, così contatti, link «gestisci la prenotazione» e pagamento si aprono nella finestra intera. Su una piattaforma in https la pagina chiamata in http rimanda (301) alla stessa in https.

| Caso | Risposta |
|---|---|
| struttura attiva di un cliente attivo o in prova | 200, modulo |
| cliente sospeso o scaduto | 200, il modulo dice che le prenotazioni online non sono disponibili e mostra i contatti (come lo shortcode) |
| struttura sconosciuta o disattivata, cliente archiviato | 404 `noindex`, senza modulo |
| con `bkng_booking` e `bkng_token` | la prenotazione dell'ospite (anche per una struttura disattivata) |

**Intestazioni**: `Content-Security-Policy: frame-ancestors 'self' <domini del cliente>` (i domini senza schema: su una piattaforma in https il browser accetta solo pagine https di quei domini; un altro sito vede una cornice vuota), `X-Frame-Options` tolta, `Referrer-Policy: strict-origin` (il token del link dell'ospite non esce mai nel Referer), `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store` anche per la CDN. Il filtro `bkng_embed_headers` le può cambiare. Un dominio autorizzato non dà nessun accesso all'API: serve solo al browser per decidere chi può mostrare la pagina.

**Altezza**: dentro l'iframe lo script del modulo manda al sito padre solo `{type: 'bkng:height', height}` a ogni cambio di altezza. `assets/embed/embed.js` (file statico, servito e messo in cache come qualunque script) accetta i messaggi solo dall'origine da cui è stato caricato e solo da un iframe della pagina, e ne imposta l'altezza.

**Pagamento**: dentro l'iframe il modulo non apre la pagina di pagamento da solo; mostra «Vai al pagamento sicuro», un link `target="_top"` che apre Stripe o Nexi nella finestra intera (così i browser non lo bloccano e l'ospite vede l'indirizzo del provider). Lo stesso per «Paga online» nella pagina dell'ospite. Fuori dall'iframe (shortcode) il comportamento è quello di prima.

**Ritorno dal pagamento e link nelle email**: se nessuna pagina del sito centrale contiene lo shortcode di quella struttura, il link «gestisci la prenotazione» e il ritorno da Stripe/Nexi (`bkng_paid=1/0`) aprono `/booking/{slug}/?bkng_booking=…&bkng_token=…` nella finestra intera, invece della home page. Una pagina con lo shortcode, se c'è, resta la prima scelta come prima.

Prova reale (Chromium, desktop e iPhone 13): da `www.hotel-bianchi.it` (autorizzato) il modulo si carica e l'iframe segue l'altezza a ogni passo (399 → 743 px su desktop, 665 → 1126 px su telefono); da un dominio di un altro cliente il browser blocca la cornice (`violates … frame-ancestors`); «Vai al pagamento sicuro» porta la finestra intera all'indirizzo di pagamento.

Rimandati alla 0.2.4 (con i contatti del cliente): logo e caratteri del modulo per cliente, e l'indirizzo di ritorno sul sito del cliente dopo il pagamento.

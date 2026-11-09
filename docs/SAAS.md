# Piattaforma con più clienti (SaaS)

Booking Manager può girare su un'unica installazione WordPress centrale che serve più clienti (tenant). Ogni cliente ha le proprie strutture, il proprio staff e i propri ospiti, e incorpora il modulo di prenotazione nel suo sito con un iframe. Il progetto completo è stato approvato il 6 novembre 2026; i passi arrivano una versione alla volta.

| Versione | Passo | Stato |
|---|---|---|
| 0.2.0 | Clienti sopra le strutture, migrazione dei dati esistenti | ✓ |
| 0.2.1 | Isolamento: permessi, ospiti, registro attività, staff, libreria media | ✓ |
| 0.2.2 | Pannello Piattaforma: clienti, utenti, domini, piani, sospensione | ✓ |
| 0.2.3 | Pagina iframe `/booking/{struttura}`, CSP per cliente, pagamento fuori dall'iframe | ✓ |
| 0.2.4 | Contatti del cliente e pulsante WhatsApp | ✓ |
| 0.2.5 | Email: mittente e SMTP del cliente, modelli, variabili, registro | ✓ |
| 0.2.6 | WhatsApp Meta per cliente, Channex per struttura | ✓ |
| 0.2.7 | Limiti per cliente, protezione login, HTTPS, selettore del cliente, documentazione | ✓ |
| 0.2.9 | Menu «Cambia Gestionale», modalità operativa per tutti tranne WordPress, pagina /booking/… solo per le proprie strutture, controlli di sicurezza | ✓ |
| 0.2.10 | Domini con e senza www, struttura non attiva incorporabile, «Verifica incorporamento» | ✓ |
| 0.2.11 | Compatibilità con il Backoffice del sito (Custom Backoffice Manager) | ✓ |
| 0.2.12 | Menu utente: profilo e bacheca WordPress solo per l'amministratore di WordPress | ✓ |
| 0.2.13 | Menu sul nome utente cliccabile solo dall'amministratore di WordPress | ✓ |
| 0.2.8 | Fase 4: test della piattaforma ([report](TEST-REPORT-SAAS.md)), correzione del blocco tra prenotazioni simultanee | ✓ |

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

Il selettore «entra nel gestionale di un cliente» è arrivato con la 0.2.7 (vedi sotto).

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

Logo e carattere del modulo e l'indirizzo di ritorno sul sito del cliente sono arrivati con la 0.2.4 (sotto).

## Contatti, WhatsApp e aspetto del cliente (0.2.4)

**Dove**: menu **Amministrazione → Attività** per l'amministratore del cliente (`bkng_manage_properties`, solo sulla piattaforma); la piattaforma apre lo stesso modulo per ogni cliente da **Piattaforma → cliente → Contatti e aspetto**. I dati stanno in `bkng_tenants.settings['profile']` (`Tenants\TenantProfile`); nessun segreto.

| Campo | Note |
|---|---|
| Nome commerciale, referente, email principale, email prenotazioni, email informazioni, telefono, WhatsApp, sito web | ognuno con **«Mostra al pubblico»**; «Stessa email per tutto» copia l'email principale nelle altre due |
| WhatsApp | solo con prefisso internazionale (`+39 333…` o `0039…`), salvato in cifre per `wa.me`; un numero senza prefisso è rifiutato perché aprirebbe la chat con un'altra persona. Mai il numero della piattaforma |
| Testo del messaggio WhatsApp | facoltativo, con `{struttura}` e `{codice_prenotazione}`; vuoto: un testo predefinito per il modulo e uno per la pagina della prenotazione |
| Logo | un'immagine della libreria del cliente (`TenantAccess::allows_attachment`) |
| Carattere | quello della pagina (come prima), moderno, classico, elegante, arrotondato: solo caratteri di sistema, nessun font scaricato da servizi esterni |
| Pagina di ritorno dopo il pagamento | facoltativa, deve stare su un dominio autorizzato del cliente (https sulle piattaforme in https); ricontrollata a ogni pagamento, quindi togliendo il dominio smette di valere |

**API**: `GET/PUT /tenant/profile`. Il cliente è sempre quello dell'utente collegato; `?tenant=ID` vale solo per la piattaforma (o per il proprio cliente). Ogni modifica va nel registro attività del cliente (`tenant.profile_updated`). In `WriteGate::ALLOWED`.

**Cosa vede l'ospite** (`GET /public/v1/properties/{slug}` e pagina della prenotazione):
- `phone` ed `email` seguono la catena **struttura → cliente**: quelli scritti nella scheda della struttura vincono (come prima); altrimenti i contatti pubblici del cliente (per l'email: prenotazioni, poi informazioni, poi principale).
- `business`: `{footer, name, logo, font, website, whatsapp, whatsapp_text}`, solo valori pubblici. `footer` è vero solo se il cliente ha reso pubblico qualcosa o ha un logo: senza impostazioni il modulo è identico a prima.
- In fondo al modulo e alla pagina della prenotazione: logo, nome, telefono, email, sito e **«Scrivici su WhatsApp»** (`https://wa.me/{numero}?text=…`, nuova scheda). Nel modulo con prenotazioni non disponibili (cliente sospeso) gli stessi contatti.

**Ritorno sul sito del cliente**: con la pagina di ritorno impostata, Stripe/Nexi riportano l'ospite che ha pagato a `pagina?bkng_paid=1&bkng_return=CODICE`. `embed.js` passa i due valori all'iframe del modulo, che mostra «Pagamento ricevuto, grazie!» con il codice. Il codice non è una credenziale: non mostra nessun dato della prenotazione; il link per gestirla arriva con l'email. Il pagamento annullato torna invece alla pagina della prenotazione sulla piattaforma, dove l'ospite può riprovare.

Le variabili `{telefono_struttura}`, `{email_struttura}`, `{whatsapp_struttura}`, `{sito_struttura}` nelle email sono arrivate con la 0.2.5 (sotto). Un numero WhatsApp diverso per singola struttura non c'è: vale quello del cliente.

## Email del cliente (0.2.5)

Ogni email ricava struttura e cliente **dalla prenotazione** (`NotificationService::compose` → struttura → `Tenancy::of_property`), mai dall'utente collegato o dall'ultimo invio.

### Mittente e server (`Tenants\TenantMail`, `bkng_tenants.settings['mail']`)

**Dove**: Attività → Email (stessa regola di accesso del profilo: `bkng_manage_properties`, il proprio cliente; la piattaforma per tutti con `?tenant=ID`).

| Modalità | Cosa succede |
|---|---|
| Servizio della piattaforma (predefinito) | From: `BKNG_MAIL_FROM` se definita in `wp-config.php`, altrimenti quello del sito. Nome: la struttura (come prima). **Reply-To**: email della struttura, altrimenti email prenotazioni → principale → informazioni del cliente |
| SMTP del cliente | solo se il piano ha **Server email (SMTP) proprio** (`custom_smtp`). Host, porta, cifratura (STARTTLS, SSL/TLS, nessuna), utente, password, From e nome |

- La password è cifrata con `SecretBox` (chiave `BKNG_SECRET_KEY` in `wp-config.php`, consigliata sulla piattaforma; altrimenti i sali di WordPress). L'API restituisce solo `password_set`; lasciarla vuota mantiene quella salvata. Non compare nel registro attività (`tenant.mail_updated`, `tenant.mail_verified`, `tenant.mail_test_failed`).
- **Verifica**: il server si usa solo dopo un'email di prova riuscita con esattamente quei dati (impronta di host, porta, cifratura, utente, From e password). Qualunque modifica lo riporta a «da verificare» e le email ripartono dal servizio della piattaforma finché la prova non riesce. Anche togliere `custom_smtp` dal piano lo spegne subito.
- From: deve essere l'utente SMTP o un indirizzo dello stesso dominio di un'email del cliente.
- Invio (`EmailChannel`): il server del cliente viene impostato su PHPMailer (`phpmailer_init`, priorità 999) solo per quel messaggio e poi tolto, rimettendo i valori di prima. Se l'invio fallisce il messaggio resta in coda con i tentativi (1, 5, 15, 60 minuti): **non** riparte dal server della piattaforma.

### Testi (`Notifications\NotificationTemplates`, `Tenants\TenantTemplates`)

- **Catena**, campo per campo: testo della struttura (`bkng_templates`) → testo del cliente (`bkng_tenants.settings['templates'][chiave][lingua]`) → predefinito. Lingua della prenotazione, altrimenti italiano.
- Email → Testi: selettore «Questa struttura (nome)» / «Tutte le strutture dell'attività» (solo per chi gestisce l'attività). L'anteprima dei testi del cliente usa la sua prima struttura.
- **HTML limitato**: `p br strong b em i u a[href,title] ul ol li h2 h3` (`NotificationTemplates::HTML_TAGS`), ripulito con `wp_kses` al salvataggio e di nuovo all'invio; script e stili spariscono con il loro contenuto. Nei testi HTML i valori delle variabili sono protetti con `esc_html`. L'email aggiunge i propri stili in linea; la versione solo testo e l'anteprima nella casella si ricavano togliendo i tag. Un testo senza tag resta «una riga per paragrafo» come prima.
- Editor: TinyMCE di WordPress (`wp_enqueue_editor`), solo i pulsanti dei tag ammessi; senza TinyMCE resta la casella di testo.
- **Variabili** (`NotificationTemplates::PLACEHOLDERS`): le vecchie più `{nome_cliente} {cognome_cliente} {email_cliente} {nome_struttura} {codice_prenotazione} {data_prenotazione} {data_arrivo} {data_partenza} {numero_notti} {numero_ospiti} {importo_totale} {importo_saldo} {stato_prenotazione} {link_prenotazione} {telefono_struttura} {email_struttura} {whatsapp_struttura} {sito_struttura}`. Telefono, email e sito: quelli della struttura, altrimenti quelli **pubblici** del cliente; WhatsApp: quello pubblico del cliente. Senza valore diventano vuote.

### Nuovi messaggi (decisione D2)

- `guest_payment_failed` «Pagamento non riuscito»: su `bkng_payment_failed` da Stripe/Nexi (non per i pagamenti manuali) quando la prenotazione è ancora in **opzione**; una per pagamento (`fail{payment_id}`); scartata se nel frattempo la prenotazione è stata pagata. Pulsante «Riprova il pagamento» verso la pagina della prenotazione.
- **Personalizzati** `custom_N`: per struttura, in ogni lingua, creati con `POST /properties/{id}/templates/custom`. L'oggetto è anche il titolo. Si inviano solo a mano da «Invia un'email» nella prenotazione (`POST /bookings/{id}/notify`), e solo quelli della struttura della prenotazione. `DELETE …/templates/custom_N` senza lingua li toglie in tutte le lingue.

### Registro

`bkng_notifications.subject` contiene l'oggetto come è partito (mai il testo). Visibile in Email → Registro e nell'API (`subject`).

### API

| Rotta | Chi |
|---|---|
| `GET/PUT /tenant/mail`, `POST /tenant/mail/test {to}` | amministratore del cliente (il proprio), piattaforma con `?tenant` |
| `GET /tenant/templates`, `PUT/DELETE /tenant/templates/{key}`, `POST /tenant/templates/{key}/preview` | come sopra |
| `GET /properties/{id}/templates/custom` | chi vede le prenotazioni della struttura |
| `POST /properties/{id}/templates/custom` | chi gestisce le impostazioni della struttura |

Tutte le scritture sono in `WriteGate::ALLOWED` (servono agli ospiti già prenotati).

**Test** (`tests/Integration/TenantMailTest.php`): due clienti, uno con SMTP verificato e uno sul servizio della piattaforma, ogni email esce con server, From e Reply-To del proprio cliente e PHPMailer torna come prima; verifica obbligatoria, password mai in risposta, impostazioni o registro; piano senza SMTP; accessi a un altro cliente rifiutati; catena dei testi con HTML ripulito; variabili; pagamento non riuscito; messaggi personalizzati; oggetto nel registro.

## WhatsApp per cliente e Channex per struttura (0.2.6)

Decisione D3: WhatsApp appartiene al cliente, Channex alla struttura. Senza impostazioni nuove tutto resta com'era: il cliente predefinito (quello creato dalla migrazione) continua a usare l'opzione `bkng_whatsapp`, la connessione Channex del sito (`bkng_channex`, costanti di `wp-config.php` comprese) e i vecchi indirizzi dei webhook. Nessun dato viene spostato.

### WhatsApp (`Notifications\WhatsApp\WhatsAppSettings::scoped`)

| Cliente | Dove sono le impostazioni | Webhook Meta |
|---|---|---|
| predefinito (o sito non migrato) | opzione `bkng_whatsapp` | `/wp-json/booking-manager/public/v1/whatsapp/webhook` |
| altri | `bkng_tenants.settings['whatsapp']` (credenziali cifrate con `SecretBox`) | `/wp-json/booking-manager/public/v1/whatsapp/webhook/{uuid del cliente}` |

- Ogni messaggio sceglie le impostazioni dal cliente della **struttura della prenotazione** (`WhatsAppChannel::for_property`); il modulo pubblico chiede il telefono solo se WhatsApp è attivo per il cliente di quella struttura.
- **Piano**: serve `whatsapp_meta`. Senza, il collegamento risponde 403 e un WhatsApp già collegato smette di inviare (`connected()` è falso).
- **Accesso**: la voce WhatsApp torna agli amministratori del cliente (`bkng_manage_properties`) per il proprio cliente; `?tenant=ID` vale solo per la piattaforma (`bkng_manage_settings`), altrimenti 403. Registro, reinvio e aggiornamenti di consegna di Meta toccano solo i messaggi delle strutture di quel cliente.
- Il controllo delle approvazioni dei testi (cron) passa da ogni cliente che ha impostazioni proprie.

### Channex (`Channels\Channex\ChannexSettings::scoped`)

| Struttura | Connessione |
|---|---|
| del cliente predefinito, senza connessione propria | quella del sito, come prima |
| con connessione propria | opzione `bkng_channex_p{ID}`: chiave API e segreto del webhook (cifrati), ambiente, indirizzo di produzione, interruttore, sincronizzazione notturna, sospensione dopo un 401 |
| di un altro cliente | **solo** la propria: la connessione del sito non le serve mai |

- Le costanti `BKNG_CHANNEX_*` valgono solo per la connessione del sito.
- **Piano**: per i clienti diversi da quello predefinito serve `channex`; senza, le operazioni sulla connessione rispondono 403 e la sincronizzazione si ferma.
- Invii, sincronizzazione completa, riconciliazione, lettura delle prenotazioni e notturna girano struttura per struttura, ognuna con la propria connessione.
- **Webhook**: la connessione propria registra su Channex `/wp-json/booking-manager/public/v1/channex/webhook/{uuid della struttura}`. Quell'indirizzo verifica la firma (o l'intestazione `X-Bkng-Webhook-Token`) con il segreto della struttura e agisce solo su di essa: un evento che nomina un'altra struttura Channex viene registrato e ignorato. L'indirizzo del sito agisce solo sulle strutture che usano la connessione del sito.
- Avvisi Channex: quelli della struttura nella sua schermata; l'avviso nella bacheca di WordPress è solo per la piattaforma.

**Dove**: Channex → scheda Collegamento. La piattaforma sceglie tra «Connessione del sito» e una struttura; chi gestisce le integrazioni di una struttura (`bkng_manage_integrations`) vede solo le proprie. «Rimuovi la connessione» cancella solo l'opzione della struttura.

### API

| Rotta | Chi |
|---|---|
| `GET/PUT /whatsapp…` (tutte le rotte di prima) | amministratore del cliente (il proprio), piattaforma con `?tenant` |
| `GET/PUT/DELETE /properties/{id}/channex/connection` | `bkng_manage_integrations` sulla struttura |
| `POST /properties/{id}/channex/connection/(test\|webhook\|webhook-test\|signing-key)` | come sopra |
| `POST /public/v1/channex/webhook/{uuid struttura}`, `GET/POST /public/v1/whatsapp/webhook/{uuid cliente}` | pubbliche, autenticate dal segreto (un uuid sconosciuto dà 404) |

`/me` restituisce nel cliente le `features` del piano, che il menu usa per mostrare WhatsApp e Channex. L'uuid nell'indirizzo sceglie solo **quale segreto** controllare: non autorizza nulla da solo.

**Test** (`tests/Integration/TenantChannelsTest.php`): WhatsApp di un cliente nelle sue impostazioni, messaggi solo ai suoi ospiti, registro separato, `?tenant` di un altro cliente rifiutato, piano senza WhatsApp, verifica di Meta sull'indirizzo del cliente; connessione Channex propria cifrata e separata da quella del sito, chiamate con la chiave della struttura, struttura di un altro cliente mai sulla chiave del sito, accesso e piano, webhook che agisce solo sulla propria struttura.

## Limiti, accessi, HTTPS e selettore del cliente (0.2.7)

### Prenotazioni online all'ora per cliente

Nuovo limite di piano **Prenotazioni online all'ora** (`max_bookings_hour`, 0 = nessun limite; predefinito 0, quindi nulla cambia finché la piattaforma non lo imposta). Vale per tutte le strutture del cliente insieme, solo per il modulo pubblico (lo staff non ha tetto), ed è contato **dopo** i limiti per visitatore e il captcha, così chi non supera i controlli non consuma il tetto del cliente. Oltre il tetto: 429 «In questo momento la struttura riceve troppe prenotazioni online». Il conteggio dell'ora corrente è in «Uso del piano».

I limiti per visitatore restano quelli di prima (per IP, /64 per IPv6): 300 letture in 10 minuti, 30 blocchi, 10 prenotazioni e 60 richieste di gestione all'ora.

### Tentativi di accesso (`Access\LoginGuard`)

| Contatore | Limite in 15 minuti | Effetto |
|---|---|---|
| per account (login o email, stesso contatore) | 5 errori | quell'account non entra fino alla fine della finestra, nemmeno con la password giusta |
| per indirizzo | 20 errori | nessun accesso da quell'indirizzo fino alla fine della finestra |

Messaggio uguale per account esistenti e inesistenti. Un accesso riuscito azzera il contatore dell'account. Il blocco di un account esistente scrive `security.login_locked` nel registro (mai la password). Vale per tutti gli accessi WordPress (wp-login.php, XML-RPC). Spegnimento: `define( 'BKNG_LOGIN_PROTECTION', false );` (es. se un plugin di sicurezza lo fa già); limiti: filtro `bkng_login_limits`.

### HTTPS obbligatorio (`Core\Https`)

Se l'indirizzo del sito è `https://`:
- pagina iframe `/booking/{struttura}/` e link «gestisci la prenotazione» (su qualunque pagina): 301 alla stessa pagina in https;
- API pubblica `booking-manager/public/v1` in http: **403** `bkng_https_required` (niente redirect: il browser rimanderebbe i dati in chiaro);
- la pagina di gestione invia anche `Referrer-Policy: strict-origin`.

Un sito ancora in http (sviluppo) non cambia. Dietro un proxy che termina TLS WordPress deve vedere la richiesta come https (`$_SERVER['HTTPS']` da `X-Forwarded-Proto` in `wp-config.php`); altrimenti `define( 'BKNG_FORCE_HTTPS', false );`.

### Selettore del cliente (piattaforma)

In alto nel gestionale la piattaforma sceglie **Tutti i clienti** o un cliente (`PUT /platform/viewing {tenant_id}`, metadato utente `bkng_viewing_tenant`). Dentro un cliente:
- `PropertyScope::allowed_ids()` restituisce solo le sue strutture: elenchi, prenotazioni, ospiti, planning e ogni rotta per struttura sono quelli che vede il cliente (le strutture degli altri rispondono 404);
- `/me`, `/tenant`, Attività, Email e WhatsApp lavorano sul cliente scelto (`PropertyScope::acting_tenant()`); una struttura creata nasce in quel cliente;
- una fascia ricorda in quale cliente si è, con «Torna a tutti i clienti».

Ogni ingresso e uscita scrive `platform.tenant_entered` / `platform.tenant_left` nel registro **con il cliente**, quindi lo vede anche il cliente. Il metadato è ignorato per chi non è della piattaforma. Con «Tutti i clienti» il gestionale è la vista trasversale: tutte le strutture nel selettore delle strutture, ognuna con le sue prenotazioni.

**Test** (`tests/Integration/TenantSecurityTest.php`): tetto orario di un cliente senza effetti sull'altro né sullo staff; blocco dell'account anche con la password giusta e azzeramento dopo un accesso riuscito; blocco dell'indirizzo; API pubblica in http rifiutata solo con il sito in https; selettore con registro, viste limitate al cliente scelto, ritorno a tutti, cliente che non può usarlo neanche scrivendo il metadato.

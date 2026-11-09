# Report dei test — piattaforma con più clienti (Fase 4)

Data: 9 novembre 2026 · Booking Manager 0.2.8 (dopo le versioni 0.2.0–0.2.7 della trasformazione SaaS)

Legenda: **SUPERATO** = eseguito e riuscito · **CORRETTO** = un errore trovato dalla prova, corretto e riprovato · **NON ESEGUITO** = non provato, con il motivo. Nessuna prova non eseguita è indicata come superata.

## Riepilogo

| | |
|---|---|
| Test automatici | **195 test, 2.944 verifiche: SUPERATO** (dopo la correzione, suite completa ripetuta 4 volte senza errori) |
| Errori trovati | **1**, corretto in 0.2.8: due prenotazioni salvate nello stesso istante, anche in strutture e clienti diversi, potevano bloccarsi a vicenda nel database (deadlock) e lasciare una prenotazione a metà |
| Prove nel browser | iframe con dominio autorizzato e non autorizzato, tutte le 18 schermate come piattaforma e come cliente, desktop e telefono: **SUPERATO** |
| Non eseguite | pagamenti reali Stripe e Nexi, WhatsApp Meta reale, Channex reale, SMTP reale, Cloudflare: la rete di questo ambiente blocca quei servizi |

## Ambiente

| | |
|---|---|
| PHP | 8.3.6 (il plugin richiede 8.1+) |
| WordPress | 6.6 (suite di test) e sito di prova locale |
| Database | MariaDB 10.11 |
| Browser | Chromium (Playwright), desktop 1280×800 e iPhone 13 |

## 1. Piano di test e risultati

| # | Prova | Come | Esito |
|---|---|---|---|
| 1 | **Cliente iniziale e migrazione** | `TenantMigrationTest`: installazione nuova con il primo cliente; dati 0.1.x collegati senza cambiare nulla (stessi conteggi, stesse prenotazioni); `verify` che trova un ospite di un altro cliente; `rollback` che rimette NULL e conserva tutto. Aggiornamento reale da 0.1.15 provato con la 0.2.0 | SUPERATO |
| 2 | **Secondo cliente** | `PlatformTest`: creazione con il suo amministratore, domini, persone, piani, numeri per cliente | SUPERATO |
| 3 | **Più strutture per cliente** | `SaasScenarioTest` (nuovo): cliente A con due strutture e una receptionist assegnata a una sola; cliente B con una. L'amministratore di A vede le sue due, la receptionist solo la sua (404 sull'altra struttura dello **stesso** cliente), B solo la sua | SUPERATO |
| 4 | **Separazione dei dati** | prenotazioni, ospiti (stessa email: una scheda per cliente, condivisa tra le strutture dello stesso cliente), utenti e staff, file della libreria media, registro attività, pagamenti, messaggi ed email (`tenant_id` del messaggio = cliente della prenotazione), mittente e server SMTP per cliente, WhatsApp per cliente, Channex per struttura. `TenantIsolationTest`, `SaasScenarioTest`, `TenantMailTest`, `TenantChannelsTest` | SUPERATO |
| 5 | **Protezione di ogni rotta API** | `TenantIsolationTest`: **ogni** rotta di `booking-manager/v1` chiamata da un amministratore del cliente B con gli ID dei record di A: tutte rifiutano (403/404). Lo stesso giro come amministratore di A passa ovunque, quindi il test non passa rifiutando tutto. Una rotta nuova non classificata fa fallire il test. `RestApiTest`: ogni rotta di scrittura classificata dalla licenza | SUPERATO |
| 6 | **ID pubblici non sono autorizzazioni** | uuid dei webhook (WhatsApp per cliente, Channex per struttura) scelgono solo quale segreto controllare: senza la firma giusta 401, uuid sconosciuto 404, la firma di un cliente non vale per un altro. Il selettore del cliente è ignorato per chi non è della piattaforma anche scrivendo il metadato a mano | SUPERATO |
| 7 | **Iframe e domini (CSP)** | test (`EmbedPageTest`) e browser reale: pagina del cliente su `www.hotel-bianchi.it` (autorizzato) e su `www.altro-sito.it` (non autorizzato), desktop e telefono. Vedi §3 | SUPERATO |
| 8 | **Prenotazioni concorrenti** | `ConcurrencyTest` (8 processi, una struttura) e `TenantConcurrencyTest` (nuovo: 8 processi, **due clienti**, 4 per l'ultima camera di ciascuno, partenza nello stesso istante). Vedi §2 | **CORRETTO** |
| 9 | **Sospensione e scadenza** | `PlatformTest`: cliente sospeso o scaduto non registra nuove prenotazioni (gestionale, modulo, blocchi) né camere, ma gestisce quelle esistenti; la scadenza si comporta come la sospensione finché non si sposta la data; archiviato: sparisce ai suoi utenti, dati conservati. `EmbedPageTest`: pagina del cliente sospeso con «prenotazioni non disponibili» | SUPERATO |
| 10 | **Limiti del piano** | strutture, camere (`PlatformTest`), prenotazioni online all'ora (`TenantSecurityTest`: il terzo oltre un tetto di 2 riceve 429; l'altro cliente e lo staff non sono toccati) | SUPERATO |
| 11 | **Accessi e HTTPS** | `TenantSecurityTest`: 5 errori bloccano l'account anche con la password giusta (per login ed email insieme), un accesso riuscito azzera; 20 errori bloccano l'indirizzo; API pubblica in http rifiutata solo con il sito in https | SUPERATO |
| 12 | **Selettore del cliente** | `TenantSecurityTest`, `SaasScenarioTest` e browser: dentro un cliente si vede solo quel cliente; ingresso e uscita nel registro con il cliente | SUPERATO |
| 13 | **Telefono e desktop** | tutte le schermate, vedi §3 | SUPERATO |
| 14 | **Suite originale** | tutti i test delle versioni 0.1.x (motore prenotazioni, prezzi, regole, check-in, messaggi, WhatsApp, Channex, pagamenti, API) | SUPERATO |

## 2. Errore trovato: deadlock tra prenotazioni simultanee (corretto in 0.2.8)

**Come è emerso.** Il nuovo test con due clienti fa partire otto processi nello stesso istante. Sotto carico (lint in parallelo) è fallito 2 volte su 8: un processo non rispondeva.

**Causa.** Salvando una prenotazione nuova, `BookingRepository::replace_guests()` faceva prima `DELETE FROM booking_guests WHERE booking_id = …` per un id che ancora non ha righe. In InnoDB questo prende un *gap lock* sull'indice `booking_id`; due prenotazioni salvate insieme (in strutture diverse, quindi senza il blocco per tipologia che le mette in fila) si bloccavano a vicenda sugli `INSERT` successivi. MariaDB annullava una delle due transazioni, ma il codice non se ne accorgeva e proseguiva fuori dalla transazione: prenotazione a metà, senza camere né ospite, e risposta con avvisi PHP.

Nel test con una sola struttura non si vedeva, perché lì vince un solo processo: gli altri si fermano prima, sul blocco della tipologia.

**Correzione** (non cambia nessun comportamento visibile):
- il `DELETE` parte solo se ci sono righe da togliere, quindi su una prenotazione nuova non c'è più il gap lock;
- dopo le scritture di prenotazione, camere, ospiti della prenotazione, schede ospite e consensi, `LockFailed::check()` riconosce deadlock e lock timeout. La transazione allora riparte da capo (`Transaction::run_retrying`, fino a 3 volte), come già facevano disponibilità e pagamenti. Se non riesce, risponde 503 «Riprova», senza salvare nulla a metà.

**Verifica.** I due test di concorrenza sono stati ripetuti **20 volte sotto lo stesso carico: 20 su 20 superati**. Ogni volta ogni cliente ha venduto la sua ultima camera esattamente una volta, e la scheda ospite del vincitore appartiene al cliente giusto. Dopo la correzione la suite completa è passata 4 volte su 4.

## 3. Prove nel browser

### Iframe e domini autorizzati

Pagina del cliente con l'iframe `/booking/villa-bianchi/` e lo script `embed.js`; dominio autorizzato del cliente: `www.hotel-bianchi.it`.

| Dispositivo | Sito che incorpora | Risultato |
|---|---|---|
| desktop | www.hotel-bianchi.it | modulo caricato; altezza che segue il contenuto (519 → 870 px dopo la ricerca) |
| desktop | www.altro-sito.it | **bloccato dal browser**: `frame-ancestors 'self' www.hotel-bianchi.it` |
| telefono | www.hotel-bianchi.it | modulo caricato; altezza 825 → 1318 px dopo la ricerca |
| telefono | www.altro-sito.it | **bloccato dal browser** |

### Tutte le schermate

18 schermate per ogni combinazione: Dashboard, Planning, Prenotazioni, Check-in, Housekeeping, Ospiti, Camere, Tariffe, Email, WhatsApp, Channex, Struttura, Attività, Staff, Registro, Impostazioni, Licenza, Piattaforma.

| Utente | Dispositivo | Errori JavaScript | Errori delle API | Scorrimento orizzontale |
|---|---|---|---|---|
| amministratore della piattaforma | desktop | 0 | 0 (*) | no |
| amministratore della piattaforma | telefono | 0 | 0 (*) | no |
| amministratore di un cliente | desktop | 0 | 0 | no |
| amministratore di un cliente | telefono | 0 | 0 | no |

(*) Aprire a mano `#/business` come piattaforma senza aver scelto un cliente dà 404 su `/tenant/profile`. È il comportamento previsto: la voce «Attività» non è nel menu della piattaforma, che modifica il profilo di un cliente da Piattaforma → cliente, oppure entrando nel cliente con il selettore.

Le schermate controllate dalle 403 previste (es. Impostazioni e Licenza per un cliente) non sono contate come errori.

Le immagini sono nella cartella del progetto `booking-manager/saas/`: `fase4-iframe-*.png`, `fase4-piattaforma-*.png`, `fase4-cliente-*.png`, `0.2.7-cliente-*.png`.

## 4. Controlli di qualità

| Controllo | Esito |
|---|---|
| `php -l` su tutti i file PHP | SUPERATO |
| ESLint su `assets/src` | SUPERATO (0 errori) |
| `npm run build` | SUPERATO |
| Segreti mai restituiti dalle API né nel registro (SMTP, WhatsApp, Channex) | SUPERATO (`TenantMailTest`, `WhatsAppTest`, `TenantChannelsTest`) |

## 5. Non eseguito (motivo: rete dell'ambiente)

| Prova | Cosa resta da fare sul server reale |
|---|---|
| Pagamenti Stripe e Nexi in sandbox | un pagamento e un rimborso per un cliente, controllare che il ritorno porti alla pagina del cliente |
| WhatsApp Meta reale | collegare il numero di un cliente, verificare il webhook con l'indirizzo `/whatsapp/webhook/{uuid cliente}`, un messaggio vero |
| Channex staging reale | chiave di una struttura, «Prova la chiave», creazione del webhook `/channex/webhook/{uuid struttura}`, una prenotazione di prova |
| SMTP reale | server di un cliente, email di prova, poi una conferma vera |
| Cloudflare | regole e Turnstile sul dominio della piattaforma |
| HTTPS dietro un proxy | se il sito è dietro un proxy che termina TLS, controllare che WordPress veda https (altrimenti `BKNG_FORCE_HTTPS` a `false`) |

## 6. Controlli di sicurezza (0.2.9)

| Controllo | Esito |
|---|---|
| Regole di sicurezza WordPress (`WordPress.Security.*`, `WordPress.DB.PreparedSQL*`, funzioni PHP vietate) su tutto `src` | 63 segnalazioni, **tutte esaminate: nessuna vulnerabilità**. 33 sono eccezioni (`throw new …`) scambiate per output; le altre sono query con nomi di tabella interni, colonne prese da liste fisse e liste di numeri passate da `intval`, sempre con `$wpdb->prepare()` per i valori; `base64_encode` serve solo alle immagini delle email |
| Funzioni pericolose (`eval`, `exec`, `unserialize`, `extract`, `$_REQUEST`, caricamenti di file) | nessuna |
| Rotte REST pubbliche (`__return_true`) | solo i webhook (Stripe/Nexi, Channex, Meta), tutti autenticati da firma o segreto con confronto a tempo costante (`hash_equals`); il modulo pubblico ha i suoi controlli (token del modulo, captcha, limiti) |
| Isolamento tra clienti su ogni rotta | `TenantIsolationTest`, `SaasScenarioTest`: SUPERATO |
| Segreti (SMTP, WhatsApp, Channex, pagamenti) | cifrati con `SecretBox`, mai restituiti dall'API né scritti nel registro: SUPERATO |
| Anteprime HTML delle email nel gestionale | in iframe `sandbox` senza permessi |
| Dipendenze | `composer audit`: nessuna vulnerabilità; `npm audit --omit=dev`: 0 vulnerabilità |
| Zip distribuito | niente test, sorgenti JS, `node_modules`, file di configurazione; ogni file PHP di `src` si ferma se aperto direttamente (`ABSPATH`) |
| Accessi | limite dei tentativi, https obbligatorio, modalità operativa obbligatoria per chi non è amministratore di WordPress |

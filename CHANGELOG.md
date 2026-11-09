# Changelog

## [0.2.17] — 9 novembre 2026

### Correzioni

- **Channex, «Chiave API non valida» in Mappatura anche con il collegamento riuscito**: una struttura con un collegamento suo (o di un cliente diverso da quello predefinito) usa la propria chiave, non quella della piattaforma che si prova in «Collegamento». Ora per l'amministratore della piattaforma la scheda Collegamento si apre già sul collegamento che la struttura usa davvero, e in Mappatura, se la chiave viene rifiutata, si vede quale collegamento è (con le ultime cifre della chiave) e un pulsante per aprirlo.

## [0.2.16] — 9 novembre 2026

### Correzioni (mobile)

- Camere: «Modifica» e «Archivia» della tipologia stanno **sempre sotto il nome**, allineati al titolo, qualunque sia la lunghezza del nome (prima con un nome corto finivano di fianco).
- Il **menu laterale scorre fino in fondo**: l'altezza segue lo schermo visibile del telefono, così la barra degli indirizzi del browser non nasconde più «Esci dall'account» e la versione.

## [0.2.15] — 9 novembre 2026

### Aspetto

- **Planning adattato alla larghezza dello schermo**: le colonne dei giorni si allargano per riempire tutto lo spazio disponibile (su telefono la colonna delle camere è più stretta).
- **Tutte le pagine a tutta larghezza** (prima si fermavano a 1180 px).
- Pulsanti **«Modifica» e «Togli»** (e simili) separati invece che attaccati.
- **Spazio tra casella di spunta e «Salva»** nei moduli (es. Channex).
- **Versione mobile come Beach Manager**: barra in alto compatta (menu, struttura, «+», avatar) e **barra di navigazione in basso** con Dashboard, Planning, Check-in, Prenotazioni e «Altro» (apre il menu completo).
- **Pagina Camere su mobile riordinata**: in testa a ogni tipologia solo nome, «Modifica» e «Archivia»; sotto l'elenco delle camere; «Aggiungi camera» e «Aggiungi più camere» in fondo alla scheda.

## [0.2.14] — 9 novembre 2026

### Aspetto

- Gestionale con lo stesso aspetto di Beach Manager, per dare al cliente l'impressione di usare un'unica famiglia di software: **pulsanti e campi arrotondati**, pulsanti secondari bianchi con bordo leggero, «+ Nuova prenotazione» nella barra in alto, avatar giallo con le iniziali (es. «AM»), titoli di pagina più grandi. Colori verdi di Booking Manager invariati; nessuna funzione cambiata.

## [0.2.13] — 9 novembre 2026

### Modifiche

- Il menu sul nome dell'utente (barra in alto) si apre **solo per l'amministratore di WordPress**. Gli altri utenti vedono il proprio nome senza menu ed escono con «Esci dall'account» nel menu laterale.

## [0.2.12] — 9 novembre 2026

### Modifiche

- Barra in alto: il nome dell'utente apre un **menu** con nome e ruolo. L'amministratore di WordPress vi trova **«Il mio profilo»** e **«Bacheca WordPress»**, come in Beach Manager; tutti gli altri solo «Esci dall'account». Lato server il personale resta comunque confinato nel gestionale (profilo e bacheca riportano all'app).

## [0.2.11] — 9 novembre 2026

### Compatibilità

- **Backoffice del sito** (Custom Backoffice Manager): la scheda Booking Manager risultava «Accesso non autorizzato» anche per gli utenti con un ruolo di Booking Manager, perché il Backoffice parte senza ruoli scelti. Ora, se in Custom Backoffice → Gestione ruoli non è scelto nessun ruolo per Booking Manager, entra chi ha il permesso `bkng_access` (tutti i ruoli di Booking Manager), come nella pagina del gestionale stesso. I ruoli scelti dall'amministratore nel Backoffice restano rispettati.
- «Cambia Gestionale» porta alla pagina Backoffice quando c'è (altrimenti alla home del sito).

## [0.2.10] — 9 novembre 2026

### Correzioni

- Modulo incorporato «rifiutato» dal browser sul sito del cliente: un dominio autorizzato vale ora **con e senza `www`** (hotel.it e www.hotel.it), perché il browser confronta i nomi alla lettera.
- Una struttura non attiva mostra il suo messaggio «non disponibile» dentro il sito del cliente, invece di una cornice rifiutata.

### Novità

- Pannello piattaforma, scheda del cliente, «Domini autorizzati»: pulsante **«Verifica incorporamento»**. Il server apre la pagina del modulo di ogni struttura come farebbe un browser e dice in chiaro cosa la blocca: intestazione `X-Frame-Options` aggiunta dal server, dall'hosting, da Cloudflare o da un plugin di sicurezza; una seconda regola `frame-ancestors`; nessun dominio; struttura non attiva. Solo per l'amministratore della piattaforma, sola lettura (`GET /platform/tenants/{id}/embed-check`).

## [0.2.9] — 9 novembre 2026

### Modifiche

- Menu laterale: nuove voci **«Cambia Gestionale»** (porta alla home del sito, dove si sceglie il gestionale) e, sotto, **«Esci dall'account»**, sempre visibili.
- **Modalità operativa obbligatoria per tutti** tranne l'amministratore di WordPress: anche gli amministratori dei clienti lavorano solo dentro il gestionale e non vedono «Esci da modalità operativa».

### Correzioni

- La pagina del modulo da incorporare (`/booking/{struttura}/`) prendeva **qualunque** indirizzo che inizia con `/booking/`, anche le pagine del sito o di altri plugin (per esempio il modulo del ristorante su una pagina figlia di «booking»). Ora risponde solo per le proprie strutture e lascia a WordPress gli altri indirizzi, comprese le pagine del sito con lo stesso indirizzo.

### Sicurezza

- Controlli su tutto il plugin: regole di sicurezza WordPress (escape, nonce, input, query preparate, redirect), funzioni pericolose, rotte pubbliche, confronti dei segreti, dipendenze (`composer audit`, `npm audit`), contenuto dello zip. Nessuna vulnerabilità trovata; dettagli in [docs/TEST-REPORT-SAAS.md](docs/TEST-REPORT-SAAS.md#6-controlli-di-sicurezza-029).

## [0.2.8] — 9 novembre 2026

Fase 4: test della piattaforma con più clienti (vedi [docs/TEST-REPORT-SAAS.md](docs/TEST-REPORT-SAAS.md)).

### Correzioni

- Due prenotazioni salvate nello stesso istante, anche in strutture diverse, potevano bloccarsi a vicenda nel database (deadlock): una delle due restava salvata a metà. Ora la prenotazione nuova non prende più quel blocco, e se il database interrompe comunque il salvataggio questo riparte da capo (fino a 3 volte) oppure risponde «Riprova» senza lasciare nulla a metà.

### Test

- Nuovi: scenario completo con due clienti, più strutture e una receptionist (`SaasScenarioTest`), prenotazioni concorrenti su due clienti con 8 processi (`TenantConcurrencyTest`).

## [0.2.7] — 9 novembre 2026

Ultimo passo della piattaforma con più clienti: limiti, accessi, HTTPS, selettore del cliente e documentazione (vedi [docs/SAAS.md](docs/SAAS.md)).

### Novità

- **Selettore del cliente** per la piattaforma, in alto nel gestionale: «Tutti i clienti» oppure un cliente, di cui si vede il gestionale come lo vede lui (strutture, prenotazioni, ospiti, attività, email, WhatsApp). Ogni ingresso e uscita è scritto nel registro attività, visibile anche al cliente.
- **Prenotazioni online all'ora** come nuovo limite di piano (0 = nessun limite): il tetto vale per tutte le strutture del cliente, solo per il modulo pubblico, e si vede in «Uso del piano».
- **Protezione degli accessi**: dopo 5 password sbagliate su un account, o 20 dallo stesso indirizzo, gli accessi si fermano per 15 minuti, anche con la password giusta. Si spegne con `BKNG_LOGIN_PROTECTION` a `false`.
- **HTTPS obbligatorio** quando il sito è in https: pagina iframe e link «gestisci la prenotazione» passano a https, l'API pubblica rifiuta le richieste in http. Si spegne con `BKNG_FORCE_HTTPS` a `false`.

### Modifiche

- `/me` ha il campo `viewing`; nuova rotta `/platform/viewing`.
- La pagina «gestisci la prenotazione» invia `Referrer-Policy: strict-origin`.
- Documentazione: architettura (piattaforma e sicurezza), API (rotte della piattaforma e del cliente, WhatsApp e Channex per cliente e struttura), hook e costanti.

## [0.2.6] — 8 novembre 2026

Settimo passo della piattaforma con più clienti: WhatsApp per cliente e Channex per struttura (vedi [docs/SAAS.md](docs/SAAS.md)). Il cliente predefinito continua a usare il WhatsApp e la connessione Channex di prima, con gli stessi indirizzi dei webhook: nessun dato viene spostato.

### Novità

- **WhatsApp per cliente**: ogni attività collega il proprio numero WhatsApp Business (Meta), con testi, approvazioni e registro propri. Ogni messaggio parte con il WhatsApp del cliente della struttura prenotata. Meta richiama l'indirizzo dell'attività (`/whatsapp/webhook/{codice}`), mostrato nella scheda Collegamento. La voce WhatsApp torna agli amministratori dell'attività se il piano comprende WhatsApp.
- **Channex per struttura**: ogni struttura può avere la propria chiave API, il proprio segreto del webhook, ambiente e interruttore (Channex → Collegamento, scegliendo la struttura). Le strutture delle altre attività usano solo la propria connessione e solo se il piano comprende Channex; quelle dell'attività predefinita possono continuare a usare la connessione del sito. Il webhook di una struttura agisce solo su quella struttura.
- La voce Channex torna a chi gestisce le integrazioni di una struttura (il collegamento del sito resta della piattaforma).

### Modifiche

- `/me` riporta le funzioni del piano del cliente (`tenant.features`).
- Nuove rotte `/properties/{id}/channex/connection` (GET, PUT, DELETE) e `/connection/test|webhook|webhook-test|signing-key`; webhook pubblici `/channex/webhook/{uuid struttura}` e `/whatsapp/webhook/{uuid cliente}`.
- Le rotte `/whatsapp…` accettano l'amministratore dell'attività per la propria; `?tenant=ID` solo per la piattaforma.

## [0.2.5] — 8 novembre 2026

Sesto passo della piattaforma con più clienti: le email di ogni cliente (vedi [docs/SAAS.md](docs/SAAS.md)). Senza impostazioni le email partono e si presentano come prima.

### Novità

- **Attività → Email**: le email agli ospiti partono dal **servizio della piattaforma** (predefinito: mittente della piattaforma, nome della struttura, risposte all'email della struttura o, se manca, all'email prenotazioni dell'attività) oppure dal **server SMTP del cliente**, se il piano lo comprende. Server, porta, cifratura, utente, password, indirizzo e nome del mittente. La password è cifrata e non torna mai al browser né nei registri. Il server si usa **solo dopo un'email di prova riuscita**, e se cambia un dato va provato di nuovo. Il mittente deve essere l'utente SMTP o un indirizzo dello stesso dominio delle email dell'attività. Un invio non riuscito resta in coda con i soliti tentativi: non passa mai da un altro server.
- **Testi per tutte le strutture dell'attività**: in Email → Testi si sceglie «Questa struttura» o «Tutte le strutture dell'attività». Catena: testo della struttura → testo dell'attività → testo predefinito, campo per campo (oggetto e testo).
- **Editor visuale** dei testi (quello di WordPress): paragrafi, titoli, grassetto, corsivo, elenchi e link, con i segnaposto da inserire con un clic. L'HTML viene ripulito sul server (`wp_kses`); i testi semplici di prima restano validi.
- **Nuove variabili**: `{nome_cliente} {cognome_cliente} {email_cliente} {nome_struttura} {codice_prenotazione} {data_prenotazione} {data_arrivo} {data_partenza} {numero_notti} {numero_ospiti} {importo_totale} {importo_saldo} {stato_prenotazione} {link_prenotazione} {telefono_struttura} {email_struttura} {whatsapp_struttura} {sito_struttura}`. Quelle di prima (`{nome}`, `{struttura}`…) funzionano ancora; una variabile senza valore diventa vuota.
- Nuova email **«Pagamento non riuscito»**: quando Stripe o Nexi rifiutano il pagamento online e la prenotazione è ancora in attesa del pagamento, l'ospite riceve il pulsante «Riprova il pagamento». Una sola per pagamento, mai per i pagamenti in struttura.
- **Messaggi personalizzati**: in Email → Testi, «+ Nuovo messaggio personalizzato» (oggetto, che è anche il titolo, e testo, in ogni lingua). Si inviano a mano dalla scheda della prenotazione, in «Invia un'email».
- **Registro**: ogni email ha anche il suo **oggetto**, come è partito.

### Modifiche

- `GET /properties/{id}/templates` ha i campi `tenant` (i testi dell'attività) e `custom`. Il corpo dei testi accetta HTML limitato (fino a 20.000 caratteri).
- Nuove rotte `/tenant/mail`, `/tenant/mail/test`, `/tenant/templates`, `/tenant/templates/{key}` (e `/preview`), `GET/POST /properties/{id}/templates/custom`. Il cliente è sempre quello dell'utente; un altro cliente risponde 403.
- La scheda della struttura nell'API ha `tenant_id`.

## [0.2.4] — 7 novembre 2026

Quinto passo della piattaforma con più clienti: contatti, WhatsApp e aspetto del modulo per ogni cliente (vedi [docs/SAAS.md](docs/SAAS.md)). Senza impostazioni il modulo resta com'era.

### Novità

- Nuova voce **Amministrazione → Attività** (amministratore del cliente, sulla piattaforma): nome commerciale, referente, email principale, prenotazioni e informazioni (con «Stessa email per tutto»), telefono, WhatsApp, sito web. Ogni contatto ha **«Mostra al pubblico»**.
- **Pulsante «Scrivici su WhatsApp»** nel modulo e nella pagina della prenotazione: apre la chat con il numero del cliente (`wa.me`, nessun servizio a pagamento) con un testo già scritto, modificabile, che può contenere `{struttura}` e `{codice_prenotazione}`. Il numero va scritto con il prefisso internazionale.
- **Logo e carattere del modulo**: logo e nome commerciale in fondo al modulo con i contatti pubblici; cinque caratteri di sistema (nessun font esterno).
- **Pagina di ritorno dopo il pagamento** (facoltativa): una pagina del sito del cliente, su un dominio autorizzato. Dopo il pagamento l'ospite torna lì e il modulo dice «Pagamento ricevuto, grazie!» con il codice; il pagamento annullato torna alla pagina della prenotazione per riprovare.
- La piattaforma modifica gli stessi dati per ogni cliente da **Piattaforma → cliente → Contatti e aspetto**.

### Modifiche

- Modulo e pagina della prenotazione: telefono ed email della struttura restano i primi; se mancano, valgono quelli pubblici del cliente.
- `GET /public/v1/properties/{slug}` e la pagina della prenotazione hanno il nuovo campo `business` (solo valori pubblici).

## [0.2.3] — 7 novembre 2026

Quarto passo della piattaforma con più clienti: il modulo di prenotazione nel sito del cliente con un iframe (vedi [docs/SAAS.md](docs/SAAS.md)). Shortcode e pagine esistenti non cambiano.

### Novità

- Pagina **`/booking/{struttura}/`** (o `/?bkng_embed={struttura}` senza permalink): lo stesso modulo dello shortcode, senza il tema del sito centrale, da mostrare in un iframe. Nessuna regola di rewrite da rigenerare.
- Si apre solo dentro i **domini autorizzati** dalla piattaforma per quel cliente (`Content-Security-Policy: frame-ancestors`); su qualunque altro sito il browser mostra una cornice vuota. Più `Referrer-Policy: strict-origin`, `nosniff`, `noindex` e nessuna cache.
- Struttura sconosciuta o disattivata, cliente archiviato: pagina 404. Cliente sospeso o scaduto: il modulo dice che le prenotazioni online non sono disponibili e mostra i contatti.
- **Altezza automatica**: con lo script `embed.js` incluso nel codice da incollare, l'iframe segue l'altezza del modulo a ogni passo, senza barre di scorrimento.
- **Pagamento nella finestra intera**: dentro l'iframe, dopo la prenotazione, il pulsante «Vai al pagamento sicuro» apre Stripe o Nexi fuori dall'iframe. Lo stesso per «Paga online» nella pagina dell'ospite.
- **Struttura → Prenotazioni e modulo online**: sulla piattaforma mostra il codice da incollare (con «Copia il codice» e «Apri la pagina») e i domini autorizzati.

### Modifiche

- Sulla piattaforma, se nessuna pagina del sito contiene lo shortcode della struttura, il link «gestisci la prenotazione» delle email e il ritorno dal pagamento aprono `/booking/{struttura}/` invece della home page.

## [0.2.2] — 7 novembre 2026

Terzo passo della piattaforma con più clienti: il pannello della piattaforma e lo stato dei clienti (vedi [docs/SAAS.md](docs/SAAS.md)). Per chi lavora in un cliente attivo non cambia nulla.

### Novità

- Nuova voce di menu **Piattaforma → Clienti e piani**, solo per gli amministratori di WordPress:
  - **Clienti**: elenco con ricerca e filtro per stato; nuovo cliente (nome, tipo di attività, piano, attivo o in prova, scadenza, primo amministratore che riceve l'email di WordPress per scegliere la password); scheda del cliente con modifica, eccezioni al piano, uso del piano, domini autorizzati e persone.
  - **Sospendi** (con motivo, mostrato al cliente), **Riattiva** e **Archivia**. Il cliente principale della piattaforma non si archivia.
  - **Riepilogo**: clienti per stato, scadenze nei prossimi 30 giorni, prenotazioni, valore e incassato per cliente nel periodo. Solo numeri, nessun dato degli ospiti.
  - **Piani**: nome, numero massimo di strutture e di camere (0 = nessun limite) e funzioni incluse. Il piano «Senza limiti» c'è sempre; un piano usato da un cliente non si può togliere.
- Cliente **sospeso o scaduto**: non registra nuove prenotazioni (gestionale, modulo online, blocco delle camere nel modulo) e non aggiunge strutture, tipologie o camere. Tutto il resto continua: modifiche, cancellazioni, check-in e check-out, pagamenti e rimborsi, privacy, pagina dell'ospite, messaggi sulle prenotazioni esistenti. Il modulo online mostra che le prenotazioni non sono disponibili. Le prenotazioni dai portali vengono salvate comunque, con una voce nel registro attività. Lo staff del cliente vede un avviso in alto.
- La **scadenza** vale dal momento in cui passa (controllo a ogni richiesta) e una volta al giorno il cliente passa a «Scaduto», con una voce nel registro. Spostando in avanti la scadenza il cliente torna attivo.
- Cliente **archiviato**: le sue persone non vedono più nulla e le sue pagine di prenotazione online spariscono. I dati restano conservati e visibili alla piattaforma; si può riattivare.
- **Limiti del piano**: oltre il numero di strutture o di camere del piano, il cliente riceve un messaggio che lo invita a contattare la piattaforma. Rimettere in uso una struttura o una camera archiviata conta come aggiungerne una. La piattaforma può superarli quando prepara un cliente.
- `GET /tenant`: il proprio cliente in sola lettura (stato, piano, limiti, uso, domini).
- Ogni azione della piattaforma sui clienti è scritta nel registro attività, visibile solo alla piattaforma.

### Modifiche

- Staff e permessi: per lo staff di un cliente, «Aggiungi» crea direttamente un nuovo utente (la ricerca tra gli utenti del sito è della piattaforma dalla 0.2.1).
- Le funzioni dei piani (pagamenti online, WhatsApp Meta, Channex, SMTP proprio) sono salvate ma non ancora applicate: ciascuna lo sarà quando diventerà per cliente (SMTP nella 0.2.5, WhatsApp e Channex nella 0.2.6).

## [0.2.1] — 6 novembre 2026

Secondo passo della piattaforma con più clienti: isolamento dei dati (vedi [docs/SAAS.md](docs/SAAS.md)). Su un sito con un solo cliente il gestionale funziona come prima, salvo le voci riservate alla piattaforma elencate sotto.

### Novità

- Ogni utente dello staff appartiene a un cliente e vede solo le strutture di quel cliente (tutte con il permesso «Accedere a tutte le strutture», altrimenti quelle assegnate). Un ID di un altro cliente, anche scritto a mano nella richiesta, dà «non trovato».
- Ospiti separati per cliente: la stessa email in due clienti crea due schede distinte; ricerca e duplicati restano dentro il cliente.
- Registro attività: le voci senza struttura (staff, ospiti) si vedono solo nel cliente a cui appartengono; quelle della piattaforma solo agli amministratori della piattaforma.
- Staff: l'elenco non mostra gli amministratori di WordPress né le persone di altri clienti; la ricerca tra gli utenti del sito è riservata alla piattaforma; chi viene aggiunto entra nel cliente di chi lo aggiunge.
- Libreria media: i file caricati dallo staff di un cliente portano il suo segno e la scelta delle immagini mostra solo i suoi file (quelli caricati prima della 0.2.0 appartengono al primo cliente). Copertina, logo, banner e foto delle camere non accettano file di un altro cliente.
- Su un sito con un solo cliente, chi riceve un ruolo di Booking Manager dalla pagina Utenti di WordPress entra in quel cliente, come prima.
- Nuovo permesso «Amministrare la piattaforma (tutti i clienti)» (`bkng_platform_manage`), sempre e solo degli amministratori di WordPress.

### Modifiche

- Riservati agli amministratori di WordPress (amministratori della piattaforma): Impostazioni generali, Ruoli e permessi, WhatsApp (collegamento Meta e modelli) e Channex (chiave API e webhook unici del sito). L'«Amministratore Booking Manager» che non è amministratore di WordPress non vede più queste voci. WhatsApp e Channex tornano disponibili ai clienti, ognuno con i propri dati, nella 0.2.6.
- Test automatici: ogni rotta del gestionale viene chiamata con gli ID di un altro cliente e deve rifiutare.

## [0.2.0] — 6 novembre 2026

Primo passo della piattaforma centrale con più clienti (vedi [docs/SAAS.md](docs/SAAS.md)). Il gestionale, il modulo di prenotazione, le email e i pagamenti funzionano esattamente come prima.

### Novità

- Clienti della piattaforma (tenant) sopra le strutture: tre tabelle nuove (`bkng_tenants`, `bkng_tenant_users`, `bkng_tenant_domains`) e la colonna `tenant_id` solo dove i dati non dipendono da una struttura (strutture, ospiti, consensi, registro attività, coda messaggi). Tutto il resto resta per struttura come prima.
- Comandi WP-CLI `wp booking-manager tenant status | migrate | verify | rollback`. `migrate --dry-run` mostra cosa farebbe; `migrate --backup-done` collega i dati esistenti al cliente predefinito (nome dalla ragione sociale della prima struttura, dominio del sito già autorizzato) e lo staff del plugin a quel cliente; gli amministratori di WordPress restano amministratori della piattaforma. Nessuna riga viene cancellata, duplicata o rinumerata; importi e stati non cambiano. `verify` confronta righe, importi e stati con l'istantanea presa prima e controlla che ogni dato abbia il suo cliente. `rollback --yes` rimette tutto come prima senza cancellare nulla.
- Su una nuova installazione il primo cliente viene creato subito (non c'è niente da migrare).

### Modifiche

- L'aggiornamento del database aggiunge solo tabelle e colonne vuote: finché non si esegue `migrate`, il plugin funziona come la 0.1.15.
- Il registro dei messaggi salva anche l'oggetto dell'email (colonna `subject`, usata dai prossimi passi).

## [0.1.15] — 31 ottobre 2026

### Novità

- Modulo di prenotazione: quando nessuna camera può ospitare da sola tutto il gruppo, invece di «Nessuna camera disponibile per queste date» il modulo scrive «Nessuna camera da 4 ospiti disponibile per queste date» e propone le combinazioni di camere libere per quelle date: due doppie per 4 ospiti, una tripla e una doppia per 5, due triple o tre doppie per 6, e così via. Per ogni combinazione si vedono le camere con foto, come vengono divisi gli ospiti, le tariffe e il totale; «Prenota queste camere» le blocca tutte insieme e porta ai dati dell'ospite, con un'unica prenotazione.
- Le combinazioni rispettano il numero massimo di camere per prenotazione (Struttura → Prenotazioni e modulo online), i limiti di adulti e bambini di ogni camera (almeno un adulto per camera), le regole di soggiorno e le tariffe pubblicate: si propongono solo le tariffe valide per tutte le camere della combinazione. Prima quelle con meno camere e meno posti letto vuoti, poi le più economiche; mai una camera in più del necessario.

## [0.1.14] — 30 ottobre 2026

### Modifiche

- Prenotazioni online con pagamento: l'email «Nuova prenotazione online» alla struttura parte solo quando il pagamento è arrivato e la prenotazione è confermata, non più appena l'ospite apre la pagina di pagamento. Se il pagamento non arriva e l'opzione scade, la struttura non riceve nulla. Per le richieste da confermare l'email arriva come prima, all'invio della richiesta, e non viene ripetuta al pagamento.
- Gestionale: pulsanti e campi con etichetta sulla stessa riga ora sono allineati e della stessa altezza (Planning «Dal» e «Giorni», filtri, registro WhatsApp e le altre barre).

## [0.1.13] — 30 ottobre 2026

### Novità

- Struttura → Prenotazioni e modulo online: nuovo campo «Immagine di copertina del modulo», la foto in alto nella pagina di prenotazione. Se resta vuoto il modulo usa, come prima, la prima foto delle camere.

### Modifiche

- Struttura: tolta la scheda «Canali». Il collegamento ai portali si fa dalla voce «Channex» del menu.

## [0.1.12] — 29 ottobre 2026

### Correzioni

- Channex → Collegamento: «Genera la chiave del webhook» non chiede più la chiave a Channex (dava «Operazione non riuscita»). La chiave viene creata dal sito e Channex la rimanda in un'intestazione a ogni chiamata del webhook. Se c'è già un webhook creato dal plugin, viene aggiornato con la nuova chiave invece di crearne un altro.
- Gli errori di Channex ora si vedono con il motivo (prima alcuni server mostravano solo «Operazione non riuscita»).

## [0.1.11] — 29 ottobre 2026

### Novità

- Channex, il channel manager verso Booking.com e gli altri portali. Nuova voce «Channex» nel menu (gruppo «Gestione», solo chi gestisce le integrazioni) con quattro schede.
- Collegamento: ambiente di prova (staging) o produzione, chiave API e chiave di firma dei webhook cifrate e mai mostrate (oppure nelle costanti di `wp-config.php`), «Prova la chiave», interruttore generale, indirizzo del webhook da copiare e pulsanti per creare la chiave di firma, il webhook su Channex e una chiamata di prova.
- Mappatura: per ogni struttura si sceglie la proprietà Channex, poi ogni tipologia e ogni coppia tipologia × tariffa (prezzo per camera o per persona). Salvare la mappatura avvia una sincronizzazione completa.
- Sincronizzazione: disponibilità, prezzi e regole (soggiorno minimo e massimo, chiusure all'arrivo e alla partenza, stop vendite) partono da sole pochi secondi dopo ogni modifica, raggruppate e solo per le notti cambiate, nei limiti di Channex (10 chiamate al minuto per struttura, pausa crescente dopo un errore). Sincronizzazione completa a 500 giorni in 2 chiamate, al massimo una volta per notte, e controllo notturno che rimanda le notti diverse. Registro delle chiamate con l'ID dell'attività di Channex e gli avvisi.
- Prenotazioni: le prenotazioni nuove, modificate e annullate arrivano dal feed di Channex (webhook firmato e controllo ogni 2 minuti) e vengono confermate a Channex solo dopo che sono salvate. Tipologia non mappata o importazione non riuscita: la prenotazione resta in elenco da sistemare, con «Importa di nuovo» e «Risolta». I dati della carta non vengono mai salvati.
- Avvisi in cima alla pagina e nel gestionale (con email all'amministratore, al massimo una all'ora) per chiave rifiutata, troppi errori di seguito, overbooking e prenotazioni da sistemare.

### Per gli sviluppatori

- Nuove azioni `bkng_availability_changed` e `bkng_rates_changed` (vedi docs/HOOKS.md).
- Cinque tabelle nuove (`cx_dirty`, `cx_state`, `cx_tasks`, `cx_revisions`, `cx_webhook_log`); nessuna tabella viene cancellata alla disattivazione.

## [0.1.10] — 27 ottobre 2026

### Modifiche

- Menu: la voce «Messaggi» si chiama ora «Email» e «WhatsApp» è subito sotto, nel gruppo «Gestione».
- Il registro della pagina Email mostra solo le email: i messaggi WhatsApp sono nel registro della pagina WhatsApp.

## [0.1.9] — 27 ottobre 2026

### Novità

- WhatsApp, come in Night Manager. Nuova voce «WhatsApp» nel menu (solo amministratori), con l'interruttore «I messaggi WhatsApp partono da soli» e tre schede.
- Collegamento: WhatsApp Cloud API di Meta (ID del numero, ID dell'account Business, token permanente, chiave segreta dell'app facoltativa), verificata con Meta al salvataggio. Le credenziali sono cifrate e non vengono mai mostrate. Prefisso predefinito per i numeri scritti senza prefisso. URL e token del webhook da copiare per avere «Consegnato» e «Letto».
- Messaggi: richiesta ricevuta, richiesta accettata con il pulsante «Completa il pagamento», prenotazione confermata, modificata, annullata, promemoria prima dell'arrivo, pagamento ricevuto e benvenuto. Ognuno si accende o si spegne, ha un testo modificabile con le variabili (nome, struttura, date, camere, importi, scadenza, link…) e l'anteprima come la vede l'ospite. Ogni testo nuovo va in approvazione a WhatsApp; nel frattempo parte quello approvato prima. «Aggiorna lo stato delle approvazioni» e «Invia prova» al proprio cellulare.
- Il link alla prenotazione è un pulsante sotto il messaggio («Vedi la prenotazione» o «Completa il pagamento»), perché WhatsApp non permette di nascondere un link dietro una parola.
- Registro: ogni messaggio con stato, numero, ospite e prenotazione, ID di WhatsApp, motivo se non è partito, «Invia di nuovo» e «Riprova».
- I messaggi partono a tutti gli ospiti con un cellulare, tranne chi spunta «Non voglio ricevere messaggi WhatsApp» nel modulo di prenotazione o chi lo ha nella scheda ospite (modificabile dallo staff). Con WhatsApp attivo il modulo chiede il cellulare.

### Modifiche

- Struttura → Notifiche: l'interruttore «Messaggi WhatsApp (richiede un servizio collegato)» è sostituito da un rimando alla nuova voce WhatsApp, che vale per tutte le strutture del sito.
- Per gli sviluppatori: il filtro `bkng_whatsapp_provider` è sostituito da `bkng_whatsapp_providers` (vedi docs/HOOKS.md).

## [0.1.8] — 27 ottobre 2026

### Novità

- Check-in più semplice. La voce del menu ora si chiama «Check-in / out». In alto c'è un solo campo per cercare l'ospite per nome, codice o camera: la lista si filtra mentre scrivi e Invio apre la prenotazione. Sotto c'è il pulsante grande «Scansiona il QR dell'ospite».
- Arrivi, ospiti in struttura e partenze di oggi sono in schede con il numero di quelli da fare. Ogni prenotazione è una riga con le camere, gli ospiti, le notti, quanto c'è da incassare e se la camera non è pronta, più un solo pulsante «Check-in» o «Check-out» per tutte le sue camere. L'esito compare in alto e si può chiudere.
- Dashboard: pulsante «Check-in» in alto, con il numero di arrivi ancora da fare, e il collegamento «Fai il check-in» sulla scheda degli arrivi.

### Correzioni

- Su telefono la barra in alto (menu, struttura, «Nuova prenotazione») non resta più fissa sopra la pagina: scorre via con il contenuto.
- Dashboard su telefono: i riquadri dei numeri sono su due colonne invece di uno sotto l'altro.

## [0.1.7] — 27 ottobre 2026

### Novità

- Prenotazioni su richiesta («accetto io le richieste») con pagamento online attivo: quando accetti una richiesta le camere restano riservate all'ospite, che riceve l'email «Richiesta accettata: completa il pagamento» con il pulsante per pagare e la scadenza. La conferma parte solo quando il pagamento arriva. Se il pagamento non arriva entro le ore dell'opzione la richiesta viene annullata e le camere tornano libere. Nella scheda della prenotazione il pulsante diventa «Accetta e chiedi il pagamento».
- Calendario come in Beach Manager: le email di conferma, modifica, promemoria e pagamento hanno in allegato il soggiorno come file per il calendario (.ics: dal giorno di arrivo a quello di partenza, con indirizzo e orari di check-in e check-out). Nella pagina della prenotazione e dopo una prenotazione confermata c'è il pulsante «Aggiungi al calendario».
- Nuovo messaggio modificabile in Messaggi: «Richiesta accettata: completa il pagamento».

### Correzioni

- Email: al posto di «Saldo» ora ci sono «Totale», «Già pagato» e «Da pagare» (le ultime due solo se c'è già un pagamento), come nella pagina della prenotazione.
- Email: la foto dell'intestazione sfuma in modo uniforme nel colore di sfondo, senza stacchi.

## [0.1.6] — 26 ottobre 2026

### Novità

- Modulo sul sito: la data di arrivo proposta è il primo giorno con una camera libera (entro 60 giorni), non più semplicemente oggi.
- Pagina della prenotazione aperta dal link nell'email nello stile delle email: stato, riepilogo con icone, quanto è pagato e da pagare, pagamento e annullamento, contatti.
- Email: la foto dell'intestazione sfuma nello sfondo (a sinistra su computer, in alto su telefono).

### Correzioni

- Email su telefono: niente margini e bordi esterni, riepilogo su due colonne, testi più compatti: non risulta più stretta e lunga.

## [0.1.5] — 26 ottobre 2026

### Novità

- Nuova grafica delle email: intestazione con logo, nome, sottotitolo, frase e foto; titolo con spunta; riepilogo del soggiorno con icone (arrivo, partenza, notti, ospiti, camera, totale, codice); pulsante per gestire la prenotazione; piè di pagina con indirizzo, telefono, email e saluto. Su telefono le colonne vanno una sotto l'altra.
- **Messaggi → Aspetto**: logo, foto in alto (la prima delle camere, una scelta o nessuna), sottotitolo, frase in alto, saluto in fondo, colore principale, sfondo dell'intestazione e della pagina, con anteprima dal vivo come su computer o su telefono. Immagini e icone viaggiano dentro l'email, senza collegamenti al sito.
- La pagina che l'ospite vede dopo la prenotazione ha lo stesso stile dell'email.

### Correzioni

- Modulo sul sito: con i temi che stampano la pagina da bordo a bordo (frequente su telefono) il modulo prende margini laterali propri.

## [0.1.4] — 26 ottobre 2026

### Novità

- Modulo sul sito: «Seleziona» apre la scheda della camera, con tutte le foto (a schermo intero con un clic), descrizione e servizi, le tariffe con colazione, cancellazione e caparra, i servizi aggiuntivi a pagamento e il totale aggiornato con quelli scelti. Le foto nella lista non si aprono più.
- Cancellazione gratuita a pagamento: in **Tariffe → Supplementi e sconti**, un supplemento facoltativo può includere la cancellazione gratuita fino a N giorni prima dell'arrivo, a prezzo fisso o in percentuale; chi lo sceglie può cancellare gratis anche su una tariffa non rimborsabile. Colazione, parcheggio e altri servizi restano supplementi facoltativi (a soggiorno, a notte, a persona).
- Riepilogo della prenotazione con i servizi aggiunti.

### Correzioni

- Annullamento online dalla pagina dell'ospite: le condizioni della tariffa non venivano ritrovate e una prenotazione non rimborsabile poteva essere annullata gratis. Ora valgono le condizioni della tariffa prenotata.

## [0.1.3] — 25 ottobre 2026

### Correzioni

- Modulo sul sito: pulsanti e campi con stile proprio, uguali su ogni tema (temi come Astra non li deformano più e non li colorano di blu).

### Novità

- Colore del modulo a scelta in **Struttura → Prenotazioni e modulo online**, anche nella pagina dell'ospite; senza scelta, il colore principale del tema o il verde di Booking Manager.

## [0.1.2] — 25 ottobre 2026

### Novità

- Nuova pagina di prenotazione sul sito: banner con foto (`intestazione="no"` lo toglie), ricerca per date e ospiti, filtri (tipologia, capacità, servizi, prezzo a notte, solo disponibili, cancellazione gratuita), ordinamento, schede camera con galleria e foto ingrandibili, prezzo a notte e totale.

## [0.1.1] — 25 ottobre 2026

### Novità

- Camere: «Aggiungi più camere» per crearne molte insieme (es. 101-110, 201, 202), tutte o nessuna; le serie funzionano anche nella configurazione guidata.
- Menu laterale come negli altri plugin della famiglia: voci a gruppi con icone, versione in fondo, utente in alto.

## [0.1.0] — prima versione

### Novità

- Strutture, tipologie e camere (B&B, affittacamere, case vacanza, appartamenti, residence, guest house, hotel), con foto e servizi.
- Tariffe: piani tariffari, prezzi per periodo, supplementi e sconti, servizi aggiuntivi facoltativi, regole di soggiorno, caparra e condizioni di cancellazione.
- Prenotazioni dal gestionale e dal sito, planning con trascinamento, nessuna doppia prenotazione anche con richieste contemporanee.
- Modulo di prenotazione sul sito con i colori del tema, blocco temporaneo delle camere, anti-spam e Cloudflare Turnstile; pagina dell'ospite per vedere, pagare e annullare.
- Pagamenti alla reception, con Stripe e con Nexi XPay; rimborsi e link di pagamento.
- Reception con check-in e check-out per camera e lettura del QR; housekeeping.
- Email con testi modificabili per struttura, promemoria; WhatsApp e canali di vendita predisposti per componenti aggiuntivi.
- Dashboard, ruoli (amministratore, responsabile, prenotazioni, reception, housekeeping), registro attività, privacy (esportazione e anonimizzazione).
- Pannello Cloudflare come in Beach e Night Manager.
- Licenza con il server della famiglia: senza licenza attiva il plugin mostra solo la schermata Licenza.

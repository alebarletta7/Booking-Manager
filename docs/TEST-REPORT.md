# Report dei test — Booking Manager 0.1.5 (Fase 2)

Data: 26 ottobre 2026 · ramo `fase-2/sviluppo`

Legenda: **SUPERATO** = eseguito e riuscito · **NON ESEGUITO** = non provato, con il motivo. Nessuna prova non eseguita è indicata come superata.

## Ambiente

| | |
|---|---|
| PHP | 8.3.6 (il plugin richiede 8.1+; il codice è verificato con la piattaforma Composer fissata a 8.1) |
| WordPress | 6.6.10 (versione minima dichiarata) |
| Database | MariaDB 10.11 |
| Browser per le prove visive | Chromium (Playwright) |
| Tema delle prove del modulo pubblico | Twenty Twenty-Four |

Limite dell'ambiente: la rete in uscita blocca `api.stripe.com`, `xpaysandbox.nexigroup.com`, `licenze.alessandrobarletta.it`, `api.cloudflare.com` e `challenges.cloudflare.com`. Le prove che li richiedono sono **NON ESEGUITE**.

## 1. Test automatici (PHPUnit)

`vendor/bin/phpunit` → **SUPERATO: 84 test, 953 verifiche, 0 errori.**

| Gruppo | Test | Cosa verifica |
|---|---:|---|
| Unit — stati prenotazione | 3 | transizioni ammesse, stati che occupano camere, notti senza il giorno di partenza |
| Unit — regole Cloudflare | 3 | eccezione solo per gli URL esatti dei webhook, nessuna eccezione senza conti, aggiornamento solo delle nostre regole |
| Unit — riepilogo pagamenti | 3 | stato di pagamento, importi, condizioni di cancellazione per l'ospite |
| Unit — motore prezzi | 9 | prezzo base, periodi e priorità, giorni della settimana, ospiti extra, modificatori, supplementi e sconti, tassa di soggiorno separata, arrotondamenti, caparra e cancellazione |
| Unit — regole di soggiorno | 5 | soggiorno minimo/massimo, chiusure ad arrivo/partenza, regole per tipologia, anticipo e orario limite |
| Integrazione — motore prenotazioni | 21 | camere in serie (tutte o nessuna, numeri già usati o ripetuti), ultima camera non vendibile due volte, giorno di partenza libero, doppia camera rifiutata dal database, blocco ottimistico (409), idempotenza, cambio date con nuovo prezzo, holds, regole e forzatura dello staff, chiusure, prezzo venduto invariato, **licenza: blocco di nuove prenotazioni e camere ma non del resto**, modalità online, schede ospite, archiviazione, B&B |
| Integrazione — **concorrenza reale** | 1 | 8 processi PHP separati, ognuno con la sua connessione, prenotano l'ultima camera nello stesso istante: **ne riesce uno solo**, gli altri ricevono «non disponibile» (ripetuto 4 volte di fila, sempre superato) |
| Integrazione — canali | 4 | importazione una sola volta e senza email, ciò che non entra viene registrato e riprovato, i connettori ricevono i cambi di disponibilità, anonimizzazione |
| Integrazione — check-in | 9 | QR firmato senza dati personali, check-in una volta sola anche da due telefoni, codici errati o di altre strutture, check-out e camera da pulire, camere non pulite, camere separate, annullamento del check-in, housekeeping, permessi |
| Integrazione — messaggi | 8 | richiesta all'ospite e alla struttura, conferma con QR (tutte le immagini dentro l'email, nessuna remota), **aspetto della struttura (sottotitolo, frasi, colori; anteprima con le scelte non salvate)**, messaggi non più veri non inviati, promemoria una volta, testi della struttura, server di posta in errore riprovato e segnalato, **WhatsApp predisposto che non finge invii** |
| Integrazione — pagamenti (fornitore finto) | 5 | incassi alla reception, prenotazione online confermata solo dal fornitore, pagina che non si apre libera le camere, pagamento tardivo rimborsato, ritorno dell'ospite che legge lo stato dal fornitore |
| Integrazione — API REST | 11 | 401 senza login, 403/404 per ruolo e struttura, housekeeping senza importi né contatti, reception senza conferme e cancellazioni, modulo pubblico e cancellazione dal link, **cancellazione gratuita venduta come opzione (senza l'opzione una tariffa non rimborsabile non si annulla online, con l'opzione sì; prezzo dell'opzione e totale)**, modulo chiuso e modulo troppo vecchio, **ogni rotta di scrittura classificata dalla licenza**, blocco totale |
| Integrazione — base | 2 | tutte le tabelle, ruoli creati |

## 2. Controlli di qualità

| Controllo | Esito |
|---|---|
| `php -l` su tutti i file PHP del pacchetto | SUPERATO |
| ESLint (`@wordpress/eslint-plugin`) su `assets/src` | SUPERATO (0 errori) |
| Build `npm run build` | SUPERATO (admin ~316 KB, modulo pubblico ~48 KB con sole dipendenze `wp-element` e `wp-i18n`) |
| Generazione `languages/booking-manager.pot` | SUPERATO (1.425 testi) |

## 3. Prove manuali su WordPress (browser)

| Prova | Esito |
|---|---|
| Plugin appena installato: solo il passo Licenza | SUPERATO |
| Configurazione guidata: struttura → camere e prezzi → pagina di prenotazione → fine | SUPERATO |
| Tutte le 17 schermate del gestionale senza errori JavaScript né risposte HTTP di errore | SUPERATO |
| Camere: «Aggiungi più camere» con serie 401-405 e 410 (anteprima, creazione, numeri già usati rifiutati senza creare nulla); menu laterale a gruppi su desktop e mobile | SUPERATO |
| Reception: ricerca per codice e check-in, camera assegnata, prenotazione «in soggiorno» | SUPERATO |
| Modulo pubblico (desktop 1280 px e mobile 390 px): ricerca, servizio aggiuntivo, scelta tariffa, blocco camere con conto alla rovescia, dati ospite, invio | SUPERATO — totale verificato: 3 notti × 95 € + parcheggio 3 × 10 € = 315 €, caparra 30% = 94,50 € |
| Modulo pubblico con i font del tema e il colore scelto (verde predefinito, terracotta scelto in Struttura) | SUPERATO (Twenty Twenty-Four) |
| Modulo pubblico con le regole globali di Astra per `button` e `input` (riprodotte in locale: il tema non si scarica da questo ambiente): pulsanti centrati e non deformati, nessun blu del tema | SUPERATO; prova con Astra vero NON ESEGUITA |
| Nuova pagina di ricerca (1400 px e 390 px): banner, date dal 28 al 30, ospiti con +/−, 4 tipologie con foto, filtri per servizio e cancellazione gratuita, prezzo a notte e totale, foto ingrandite con frecce ed Esc, tariffe aperte da «Seleziona», «Prenota» porta ai dati dell'ospite | SUPERATO |
| Scheda della camera (1400 px e 390 px, anche con le regole di Astra): le foto della lista non si aprono; «Seleziona» apre la scheda con galleria, foto a schermo intero (Esc chiude solo le foto), servizi, tariffe con colazione, cancellazione e caparra; servizi aggiuntivi per tariffa (colazione 12 € a persona a notte solo sulla non rimborsabile, cancellazione gratuita 10% fino a 7 giorni prima); totale ricalcolato dal server: 256,50 € + 25,65 € = 282,15 €, + colazione 72 € = 354,15 €; condizioni della tariffa che diventano «cancellazione gratuita fino a 7 giorni prima»; «Prenota» porta al riepilogo con i servizi scelti | SUPERATO |
| Tariffe → Supplementi e sconti: campo dei giorni di cancellazione gratuita, salvataggio e lista | SUPERATO |
| Email nella nuova grafica (anteprima e invio di prova in locale, 800 px e 390 px): intestazione con foto, riepilogo con icone nel colore scelto, pulsante, piè di pagina; colonne una sotto l'altra su telefono | SUPERATO (resa nei programmi di posta reali come Gmail e Outlook NON ESEGUITA: nessun server di posta raggiungibile da questo ambiente) |
| Messaggi → Aspetto: sottotitolo, frase, colore, anteprima dal vivo su computer e telefono, salvataggio | SUPERATO |
| Pagina dopo la prenotazione (1280 px e 390 px, anche con le regole di Astra) nello stile dell'email; tema che stampa la pagina da bordo a bordo: il modulo prende 16 px di margine | SUPERATO |
| Pagina «gestisci prenotazione» dal link: stato, importi, cancellazione | SUPERATO; un token errato risponde «Prenotazione non trovata» (404) |
| Licenza non valida: modulo pubblico «prenotazioni non disponibili», banner nel gestionale | SUPERATO |
| Tentativo di attivazione con server licenze irraggiungibile | SUPERATO — messaggio «Il server delle licenze non risponde», il plugin resta bloccato (nessun successo finto) |

## 4. Pacchetto ZIP

`bin/build-zip.sh` → `dist/booking-manager-0.1.5.zip` (520 KB, 174 file: file principale, `uninstall.php`, `src/`, `assets/build/`, `assets/email/` (icone delle email), `languages/`, `CHANGELOG.md`, `readme.txt`, licenza; nessun sorgente dell'interfaccia, test o file di sviluppo).

| Prova su WordPress 6.6 pulito (WP-CLI) | Esito |
|---|---|
| Installazione e attivazione dallo ZIP | SUPERATO: 28 tabelle, 5 ruoli |
| Disinstallazione con impostazione predefinita | SUPERATO: tabelle, opzioni e ruoli **conservati** |
| Disinstallazione con «Cancella tutti i dati» attivo | SUPERATO: 0 tabelle, 0 opzioni, 0 ruoli |

## 5. NON ESEGUITO

| Prova | Motivo | Come farla |
|---|---|---|
| **Stripe in sandbox** (collegamento con chiave limitata `rk_test_…`, creazione automatica del webhook, pagamento con carta di prova, conferma via webhook, rimborso totale e parziale, pagamento tardivo rimborsato) | `api.stripe.com` non raggiungibile da questo ambiente | Sito online di prova, **Struttura → Pagamenti → Stripe (prova)**, carta `4242 4242 4242 4242`; controllare in Dashboard Stripe evento e rimborso |
| **Nexi XPay in sandbox** (chiave API di prova, pagina di pagamento, notifica, rilettura dell'ordine, rimborso) | `xpaysandbox.nexigroup.com` non raggiungibile | Come sopra con le credenziali sandbox XPay e le carte di prova Nexi |
| Attivazione reale della licenza, rinnovo, «libera questo sito», controllo aggiornamenti | server licenze non raggiungibile; inoltre il prodotto `booking-manager` va creato sul server | Creare il prodotto, generare una chiave e attivarla da **Licenza** |
| Collegamento a una zona Cloudflare reale e applicazione delle regole | `api.cloudflare.com` non raggiungibile, nessuna zona di prova | Token con i permessi di [CLOUDFLARE.md](CLOUDFLARE.md), **Verifica** e **Applica le regole** |
| Cloudflare Turnstile con chiavi vere | `challenges.cloudflare.com` non raggiungibile | Inserire le chiavi in **Impostazioni → Generali** e inviare una prenotazione |
| Consegna reale delle email (SMTP) | nessun server di posta; la coda, i tentativi e gli errori sono coperti dai test | Sito con SMTP configurato, prenotazione di prova |
| Fotocamera reale per il QR in reception | nessuna fotocamera nel browser automatico; la lettura per codice è provata | Da tablet o telefono, **Reception → Attiva la fotocamera** |
| WordPress multisito, PHP 8.1 reale, temi classici | non disponibili in questo ambiente | Installazione di prova dedicata |
| Prova con utenti reali dei ruoli Reception e Housekeeping nel browser | i permessi sono provati dai test dell'API, non a schermo | Creare due utenti da **Staff** e accedere |

=== Booking Manager ===
Contributors: alessandrobarletta
Tags: booking, b&b, hotel, prenotazioni, affittacamere
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.2.18
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestionale per B&B, affittacamere, case vacanza, appartamenti e hotel: camere, tariffe, prenotazioni, pagamenti, check-in e housekeeping.

== Description ==

* Strutture, tipologie e camere, planning con trascinamento.
* Tariffe stagionali, supplementi, sconti, servizi aggiuntivi, regole di soggiorno, caparra e cancellazione.
* Modulo di prenotazione sul sito con i colori del tema e pagina dell'ospite per vedere, pagare e annullare.
* Pagamenti alla reception, con Stripe e con Nexi XPay (pagine di pagamento ospitate: i dati delle carte non passano dal sito).
* Reception con check-in e check-out per camera e QR; housekeeping.
* Email modificabili, promemoria; ruoli per lo staff; registro attività; esportazione e anonimizzazione dei dati degli ospiti.

Richiede una licenza attiva: senza, il plugin mostra solo la schermata Licenza.

== Installation ==

1. Carica lo zip da Plugin → Aggiungi nuovo → Carica plugin e attivalo.
2. Apri Booking Manager e inserisci la chiave di licenza.
3. Segui la configurazione guidata.

== Changelog ==

= 0.2.18 =
* Channex: «Risolta» chiude anche l'avviso nella Bacheca; avvisi visibili e segnabili come letti anche nel collegamento della struttura.

= 0.2.17 =
* Channex: la schermata indica quale collegamento (della struttura o della piattaforma) usa la struttura e apre quello giusto.

= 0.2.16 =
* Mobile: pulsanti delle tipologie sempre sotto il titolo; menu laterale scorre fino in fondo.

= 0.2.15 =
* Planning e pagine a tutta larghezza, spaziature di pulsanti e caselle, versione mobile con barra in basso come Beach Manager, pagina Camere riordinata su mobile.

= 0.2.14 =
* Aspetto allineato a Beach Manager: pulsanti e campi arrotondati, avatar con le iniziali, titoli più grandi.

= 0.2.13 =
* Il menu sul nome dell'utente si apre solo per l'amministratore di WordPress.

= 0.2.12 =
* Menu utente nella barra in alto: «Il mio profilo» e «Bacheca WordPress» solo per l'amministratore di WordPress.

= 0.2.11 =
* Compatibilità con il Backoffice del sito: la scheda Booking Manager si apre per chi ha un ruolo di Booking Manager; «Cambia Gestionale» porta al Backoffice.

= 0.2.10 =
* Il dominio autorizzato vale con e senza www; una struttura non attiva mostra «non disponibile» nel sito del cliente; nuovo pulsante «Verifica incorporamento» nel pannello piattaforma.

= 0.2.9 =
* Menu laterale con «Cambia Gestionale» ed «Esci dall'account»; modalità operativa obbligatoria per tutti tranne l'amministratore di WordPress; la pagina /booking/… non copre più le pagine del sito o di altri plugin; controlli di sicurezza.

= 0.2.8 =
* Fase 4, test della piattaforma: correzione di un blocco del database tra due prenotazioni salvate nello stesso istante (una poteva restare a metà); nuovi test con due clienti e prenotazioni concorrenti.

= 0.2.7 =
* Selettore del cliente per la piattaforma (ogni accesso nel registro), tetto orario di prenotazioni online per piano, blocco dopo troppi tentativi di accesso, HTTPS obbligatorio per modulo e API pubblica quando il sito è in https, documentazione aggiornata.

= 0.2.6 =
* WhatsApp per cliente: ogni attività collega il proprio numero WhatsApp Business, con testi e registro propri e un proprio indirizzo per Meta. Channex per struttura: chiave API, segreto del webhook e interruttore di ogni struttura; le altre attività non usano mai la connessione del sito. Entrambi secondo il piano del cliente.

= 0.2.5 =
* Email per cliente: mittente della piattaforma con le risposte all'attività, oppure il server SMTP del cliente (password cifrata, usato solo dopo un'email di prova riuscita). Testi delle email per tutte le strutture dell'attività, editor con grassetto, elenchi e link, nuove variabili, email «Pagamento non riuscito», messaggi personalizzati da inviare dalla prenotazione, oggetto nel registro.

= 0.2.4 =
* Nuova schermata «Attività»: contatti del cliente con «Mostra al pubblico», pulsante «Scrivici su WhatsApp» (click-to-chat), logo e carattere del modulo, pagina del sito del cliente dove torna l'ospite dopo il pagamento.

= 0.2.3 =
* Modulo di prenotazione da inserire con un iframe nel sito del cliente: pagina /booking/{struttura}/ senza il tema, aperta solo dentro i domini autorizzati dalla piattaforma, altezza che si adatta, pagamento nella finestra intera. Il codice da incollare è nella scheda della struttura.

= 0.2.2 =
* Pannello «Piattaforma» per l'amministratore: clienti (crea, modifica, sospendi, riattiva, archivia), domini autorizzati, persone, piani con limiti di strutture e camere, riepilogo per cliente. Un cliente sospeso o scaduto non registra nuove prenotazioni ma gestisce quelle esistenti.

= 0.2.1 =
* Piattaforma con più clienti: ogni cliente vede solo le proprie strutture, prenotazioni, ospiti, staff, registro attività e file della libreria media. Impostazioni generali, ruoli, WhatsApp e Channex sono riservati all'amministratore della piattaforma.

= 0.2.0 =
* Piattaforma con più clienti, primo passo: clienti (tenant) sopra le strutture e comando WP-CLI per collegare i dati esistenti al primo cliente, con backup, verifica e ritorno indietro. Nessun cambiamento visibile nel gestionale.

= 0.1.15 =
* Modulo di prenotazione: se nessuna camera basta per tutti gli ospiti, propone le combinazioni di camere libere (es. due doppie per 4, tripla + doppia per 5) da prenotare insieme.

= 0.1.14 =
* Email «Nuova prenotazione online» alla struttura solo dopo il pagamento; campi e pulsanti allineati nelle barre del gestionale.

= 0.1.13 =
* Immagine di copertina del modulo di prenotazione; tolta la scheda «Canali» da Struttura (si usa la pagina Channex).

= 0.1.12 =
* Channex: la chiave del webhook è generata dal sito (prima falliva), il webhook esistente viene aggiornato, errori di Channex leggibili.

= 0.1.11 =
* Channex verso Booking.com e gli altri portali: disponibilità, prezzi e regole inviati da soli dopo ogni modifica, sincronizzazione completa e controllo notturno, prenotazioni importate dal feed con conferma a Channex, webhook firmato, avvisi.

= 0.1.10 =
* Menu: «Messaggi» diventa «Email», con «WhatsApp» subito sotto; il registro delle email mostra solo le email.

= 0.1.9 =
* Messaggi WhatsApp agli ospiti come in Night Manager: collegamento alla WhatsApp Cloud API di Meta, testi con approvazione, pulsante per la pagina della prenotazione, registro con «Invia di nuovo», stati «consegnato» e «letto», rinuncia dell'ospite.

= 0.1.8 =
* Check-in più semplice (cerca l'ospite, QR, un pulsante per prenotazione), pulsante «Check-in» nella dashboard, barra in alto che non resta fissa su telefono.

= 0.1.7 =
* Con «accetto io le richieste» e pagamento online: prima accetti, poi l'ospite paga, poi parte la conferma. File per il calendario nelle email e nella pagina della prenotazione; «Totale / Già pagato / Da pagare» nelle email; sfumatura della foto più uniforme.

= 0.1.6 =
* La ricerca parte dal primo giorno libero; pagina della prenotazione nel nuovo stile; email più larghe su telefono e foto sfumata nell'intestazione.

= 0.1.5 =
* Nuova grafica delle email, personalizzabile in Messaggi → Aspetto; pagina dopo la prenotazione nello stesso stile; margini del modulo su telefono.

= 0.1.4 =
* Scheda della camera con foto a schermo intero, tariffe e servizi a pagamento (colazione, cancellazione gratuita…); corretta la cancellazione online delle tariffe non rimborsabili.

= 0.1.3 =
* Modulo sul sito uguale su ogni tema; colore del modulo a scelta.

= 0.1.2 =
* Nuova pagina di prenotazione con filtri, ordinamento e foto.

= 0.1.1 =
* Camere aggiunte in serie; menu laterale come negli altri plugin.

= 0.1.0 =
* Prima versione.

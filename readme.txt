=== Booking Manager ===
Contributors: alessandrobarletta
Tags: booking, b&b, hotel, prenotazioni, affittacamere
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.14
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

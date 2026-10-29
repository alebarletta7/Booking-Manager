=== Booking Manager ===
Contributors: alessandrobarletta
Tags: booking, b&b, hotel, prenotazioni, affittacamere
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.12
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

== Sincronizzazione con Booking.com e Airbnb ==

La voce «Booking e Airbnb» collega le camere ai portali con i calendari iCal (solo date, nessun abbonamento). iCal non è in tempo reale: i portali leggono il link della camera quando vogliono (di solito ogni poche ore).

1. Per ogni camera copia il «Link da dare ai portali» («Copia tutti i link» li copia tutti) e incollalo nel portale in «Importa calendario».
2. Copia dal portale il link «Esporta calendario» e incollalo in «Collega i calendari di tutte le camere»: il portale si riconosce dall'indirizzo.

Il plugin legge i calendari ogni 15 minuti con il cron di WordPress, che parte solo quando il sito riceve visite. In produzione è meglio un cron di sistema ogni 5 minuti:

* con WP-CLI: `*/5 * * * * cd /percorso/del/sito && wp cron event run --due-now --quiet` (oppure solo i calendari: `wp booking-manager ical sync`);
* senza WP-CLI: `*/5 * * * * curl -s https://tuosito.it/wp-cron.php?doing_wp_cron > /dev/null`;
* poi, facoltativo, `define( 'DISABLE_WP_CRON', true );` in wp-config.php.

La schermata avvisa se il controllo automatico non gira da più di 45 minuti. `wp booking-manager ical status` mostra lo stato di ogni calendario.

== Changelog ==

= 0.1.12 =
* Sincronizzazione con Booking.com e Airbnb più affidabile: eventi registrati per fonte e camera ricalcolata da tutti i suoi calendari, nessun ciclo tra portali, date mai liberate da un calendario non leggibile, troncato o arrivato vuoto, nuovi tentativi sempre più distanziati, stato e ultima sincronizzazione riuscita. Meno link da gestire: portale riconosciuto dall'indirizzo, «Collega i calendari di tutte le camere», «Copia tutti i link». Indirizzi dei portali cifrati, solo https, nuovo link per una singola camera, comando WP-CLI.

= 0.1.11 =
* Booking.com, Airbnb e altri portali: nuova voce «Booking e Airbnb». Ogni camera ha il suo link di calendario (iCal) da dare ai portali, e i calendari dei portali vengono letti ogni 15 minuti per chiudere le date vendute lì.

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

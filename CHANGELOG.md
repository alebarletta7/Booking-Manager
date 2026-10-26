# Changelog

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

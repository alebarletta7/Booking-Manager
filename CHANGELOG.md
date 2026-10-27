# Changelog

## [0.1.11] — 27 ottobre 2026

### Novità

- Collegamento con Booking.com, Airbnb, Vrbo, Expedia e altri portali tramite i calendari iCal (il collegamento che i portali offrono a tutti, senza contratto da partner). Nuova voce «Booking e Airbnb» nel gruppo «Gestione».
- Ogni camera ha il suo «Link da dare ai portali», con il pulsante «Copia»: si incolla sul portale in «Importa calendario» e il portale chiude le date già vendute qui (prenotazioni, chiusure e notti in cui la tipologia è piena). Passano solo le date: nessun nome, prezzo o contatto.
- «Collega un calendario»: si sceglie il portale e si incolla il link «Esporta calendario» del portale. Il calendario viene letto subito e poi ogni 15 minuti (o con «Sincronizza ora»): le prenotazioni e le chiusure del portale chiudono la camera qui, quindi il modulo del sito e il gestionale non vendono più quelle notti; se il portale le toglie, le notti tornano libere.
- Per ogni calendario: stato, ultima lettura, periodi chiusi, «Sincronizza» e «Scollega». Se un portale non risponde le date già chiuse restano chiuse e l'errore viene mostrato. Il registro segnala anche una prenotazione Airbnb su notti già occupate qui (possibile doppia prenotazione).
- Planning: le date chiuse da un portale sono in blu con il nome del portale (per esempio «Airbnb · prenotazione»). Dashboard: contano come camere occupate.

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

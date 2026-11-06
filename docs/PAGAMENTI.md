# Pagamenti

Tre modi di incassare, per ogni struttura: **manuale** (alla reception), **Stripe** e **Nexi XPay**. Nel modello di Beach e Night Manager la struttura è l'esercente: il denaro va dal fornitore direttamente sul suo conto, il venditore del plugin non lo tocca mai. **I dati delle carte non arrivano mai a WordPress**: l'ospite paga sulla pagina ospitata da Stripe o Nexi.

Nessun pagamento è simulato: una prenotazione online passa a «confermata» solo quando il fornitore conferma il pagamento.

## Pagamenti manuali

Dal dettaglio della prenotazione: **Registra pagamento** (contanti, POS/carta, bonifico, altro) e **Rimborsa**. Aggiornano subito incassato, saldo e stato del pagamento (`unpaid`, `partial`, `paid`, `refunded`). Ogni registrazione ha un identificativo di richiesta: un doppio clic non registra due volte.

## Collegare Stripe o Nexi

**Struttura → Pagamenti**, un conto per fornitore e modalità (prova / reale):

| Fornitore | Cosa serve | Note |
|---|---|---|
| Stripe | **Chiave limitata** (`rk_test_…` / `rk_live_…`) creata nella Dashboard Stripe con i permessi: Checkout Sessions (scrittura), Charges e Refunds (scrittura), PaymentIntents (lettura), Webhook Endpoints (scrittura, solo per far creare il webhook al plugin). | Stripe sconsiglia di incollare le chiavi segrete complete (`sk_…`) nei plugin. Se il webhook si crea a mano, si incolla il suo segreto `whsec_…`. |
| Nexi XPay | **Chiave API** del terminale XPay (Back Office → Amministrazione → APIKey). | Nexi non offre OAuth per questo. |

Poi **Verifica** (il plugin chiama il fornitore con la chiave) e **Usa per i pagamenti online**. Le credenziali sono salvate cifrate (`Core\SecretBox`, libsodium) e non vengono mai rimandate al browser né scritte nei registri: si vede solo la parte finale. La chiave di cifratura viene dalla costante `BKNG_SECRET_KEY` in `wp-config.php` se presente (consigliato: resta valida anche se cambiano i salt di WordPress), altrimenti dai salt di WordPress; se cambia, le credenziali vanno reinserite e il pannello lo dice.

Impostazioni della struttura:

- **Pagamento online**: nessuno, solo caparra, oppure intero importo. Con la prenotazione diretta l'ospite paga subito dal modulo; con «su richiesta» paga dopo che lo staff ha accettato la richiesta (vedi sotto).
- **Saldo**: quanti giorni prima dell'arrivo va pagato il resto.

## Come funziona il pagamento online

1. L'ospite prenota dal modulo: la prenotazione nasce come **opzione** (le camere restano bloccate per circa 40 minuti, il tempo di vita della pagina di pagamento) e l'ospite va alla pagina del fornitore.
2. Il fornitore avvisa il sito con il **webhook** (`/wp-json/booking-manager/v1/webhooks/{fornitore}/{conto}`):
   - Stripe: firma `Stripe-Signature` verificata con HMAC-SHA256 e tolleranza di 5 minuti;
   - Nexi: le notifiche non sono firmate, quindi il loro contenuto non è mai creduto: il plugin rilegge l'ordine con la chiave API (`GET /orders/{id}`) e vale solo quella risposta.
3. Al ritorno sul sito la pagina dell'ospite chiede lo stato al fornitore (al massimo ogni 10 secondi) e si aggiorna da sola: il browser dell'ospite non prova nulla.
4. Pagamento confermato → prenotazione confermata ed email all'ospite.

### Prenotazioni su richiesta

1. L'ospite manda la richiesta: la prenotazione è **in attesa**, nessun pagamento.
2. Lo staff la accetta (il pulsante nella scheda è «Accetta e chiedi il pagamento»): la prenotazione diventa **opzione** per le ore impostate in «Ore di validità dell'opzione», le camere restano riservate e l'ospite riceve l'email «Richiesta accettata: completa il pagamento» con il pulsante che apre la sua pagina e la scadenza.
3. L'ospite paga dalla sua pagina; il pagamento confermato dal fornitore conferma la prenotazione e solo allora parte l'email di conferma (con il file per il calendario).
4. Se il pagamento non arriva entro la scadenza l'opzione viene annullata e le camere tornano libere.

Senza pagamento online (modalità «Nulla», nessun conto attivo o niente da pagare) accettare conferma subito, come prima. Lo staff può sempre confermare a mano un'opzione (per esempio dopo un bonifico).

Casi limite gestiti (tutti idempotenti, ogni evento si applica una volta sola):

- **pagamento arrivato dopo la scadenza dell'opzione o dopo l'annullamento** → rimborso automatico; se il rimborso fallisce, lo staff viene avvisato (`bkng_payment_needs_attention`) e la prenotazione lo mostra;
- **due pagine pagate per la stessa prenotazione** → la parte in eccesso viene rimborsata;
- **pagina di pagamento che non si apre** → la prenotazione viene annullata subito e le camere tornano in vendita, l'ospite vede l'errore;
- **notifiche fuori ordine o ripetute** → registrate in `webhook_events`, applicate una volta;
- un controllo ogni ora rilegge i pagamenti rimasti aperti, nel caso un webhook non arrivi.

Dal dettaglio della prenotazione lo staff può anche creare un **link di pagamento** per il saldo, rimborsare (anche in parte) e **sincronizzare** lo stato con il fornitore.

## Sito locale

In modalità prova su un sito non raggiungibile da internet (localhost…) il fornitore non può chiamare il webhook: il conto si collega lo stesso e i pagamenti vengono confermati chiedendo lo stato al fornitore, con qualche secondo di ritardo. Per la modalità reale il sito deve essere online.

## Stato delle prove

I flussi sono coperti da test automatici con un fornitore finto (`tests/FakeProvider.php`). **Le prove con le sandbox reali di Stripe e Nexi non sono state eseguite**: dall'ambiente di sviluppo gli host `api.stripe.com` e `xpaysandbox.nexigroup.com` non sono raggiungibili. Vanno fatte con le chiavi di prova, seguendo la lista in [TEST-REPORT.md](TEST-REPORT.md).

## Aggiungere un fornitore

Una classe che implementa `Payments\Providers\PaymentProvider` (di solito estendendo `AbstractProvider`) registrata con il filtro `bkng_payment_providers`. Vedi [HOOKS.md](HOOKS.md).

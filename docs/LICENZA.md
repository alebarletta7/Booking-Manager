# Licenza

Booking Manager usa **lo stesso server licenze** di Beach, Night e Restaurant Manager (`https://licenze.alessandrobarletta.it`), con lo stesso protocollo: risposte firmate Ed25519, chiavi pubbliche `k1` (principale) e `k2` (di riserva) scritte in `src/Licensing/ServerConfig.php`. Il prodotto sul server è `booking-manager`. Non esiste un sistema di licenze alternativo né un endpoint inventato.

> Prima della vendita il prodotto `booking-manager` va creato nel pannello del server licenze: oggi i valori predefiniti del server conoscono solo i prodotti già in vendita.

## Cosa vede l'utente

| Situazione | Effetto |
|---|---|
| Plugin appena installato, licenza mai attivata | La configurazione guidata mostra **solo il passo Licenza**. L'API risponde solo alle rotte della licenza e a `/me`; il modulo pubblico dice che le prenotazioni online non sono disponibili; la pagina dell'ospite e la cancellazione dal link continuano a funzionare. |
| Licenza attiva | Tutto disponibile. |
| Licenza scaduta, sospesa, revocata, sito cambiato, non verificata oltre la tolleranza | Bloccate **solo** le nuove prenotazioni (gestionale e modulo pubblico, holds compresi) e la **gestione delle camere** (tipologie e camere: creazione, modifica, archiviazione). Tutto il resto funziona: modifiche, conferme, cancellazioni, check-in, pagamenti, ospiti, tariffe, impostazioni. Un banner spiega cosa fare. |
| Server licenze irraggiungibile | Nessun blocco durante il periodo di tolleranza (almeno 7 giorni dall'ultima risposta firmata); dopo 2 giorni compare un avviso con la data limite. Spostare indietro l'orologio del sito non allunga la tolleranza. |
| «Libera questo sito» | La licenza si disattiva qui e si può usare altrove; il plugin torna bloccato. I dati restano. |

Errori di attivazione: il messaggio del server viene mostrato così com'è (chiave non valida, siti esauriti con l'elenco dei siti che la usano, server non raggiungibile…). Nessun successo simulato: se il server non risponde, la licenza resta non attiva.

## Dove è applicata

- `Licensing\WriteGate` filtra **ogni** chiamata REST del plugin (`rest_dispatch_request`) con tre modalità: blocco totale, blocco prenotazioni e camere, nessun blocco. Ogni rotta di scrittura è classificata in `BLOCKED` o `ALLOWED`: una rotta non classificata è bloccata (nel dubbio si chiude). Un test elenca tutte le rotte e fallisce se una di scrittura non è classificata.
- `BookingService` e `InventoryService` chiedono di nuovo alla licenza (`LicenseGate`), così nessun altro percorso (WP-CLI, import dai canali, codice di terze parti) crea prenotazioni o camere senza licenza.

## Comandi WP-CLI

```
wp booking-manager license status
wp booking-manager license activate <chiave>
wp booking-manager license validate
wp booking-manager license deactivate
```

## Sviluppo e test

Su un sito di test (`WP_ENVIRONMENT_TYPE` = `local` o `development` **e** indirizzo non raggiungibile da internet: localhost, 127.x, *.local, *.test, *.localhost, *.invalid, *.example) si può puntare a un altro server da `wp-config.php`:

```php
define( 'BKNG_LICENSE_SERVER', 'http://licenze.test' );
define( 'BKNG_LICENSE_PUBLIC_KEYS', 'k1:BASE64,k2:BASE64' );
```

Su qualsiasi altro sito queste costanti sono ignorate.

## Aggiornamenti

Il controllo degli aggiornamenti passa dallo stesso server (`/api/v1/update/check`) ed è attivo solo con una licenza che li comprende. La pagina **Licenza** mostra versione installata, ultima versione e data dell'ultimo controllo.

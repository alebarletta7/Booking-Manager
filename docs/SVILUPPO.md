# Sviluppo

## Requisiti

PHP 8.1+, Composer, Node 20+, MariaDB o MySQL per i test.

```bash
composer install
npm ci
```

## Interfaccia

```bash
npm run build      # assets/build (gestionale + modulo pubblico)
npm run start      # ricompila a ogni modifica
npm run lint:js    # ESLint con le regole di WordPress
```

- `assets/src/admin`: app React del gestionale (`@wordpress/components`, router con hash, palette verde in `style.scss`).
- `assets/src/public`: modulo di prenotazione e pagina dell'ospite, senza `@wordpress/components` per restare leggero. I colori arrivano dal tema (vedi [ARCHITETTURA.md](ARCHITETTURA.md#interfaccia)).

`assets/build` è versionato: è ciò che finisce nello ZIP.

## Test

```bash
# database di test (viene svuotato e ricreato a ogni esecuzione: mai un database vero)
export WP_TESTS_DB_NAME=wptests WP_TESTS_DB_USER=wp WP_TESTS_DB_PASSWORD=wp WP_TESTS_DB_HOST=localhost

vendor/bin/phpunit                       # tutto
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit --filter ConcurrencyTest
```

I test di integrazione girano dentro una transazione annullata alla fine. `ConcurrencyTest` fa eccezione: salva i dati (i processi concorrenti non vedrebbero una transazione aperta), avvia `tests/concurrency/worker.php` in 8 processi e cancella tutto alla fine. Richiede `proc_open`.

La licenza nei test è sostituita da un `FakeGate` (`tests/TestCase.php`); i pagamenti online da `tests/FakeProvider.php`. Le prove con le sandbox reali sono descritte in [TEST-REPORT.md](TEST-REPORT.md).

## Traduzioni

I testi sono scritti in italiano nel codice. Il modello si rigenera dai file compilati, così le traduzioni JavaScript trovano i file giusti:

```bash
npm run build
wp i18n make-pot . languages/booking-manager.pot --slug=booking-manager --domain=booking-manager \
  --exclude=node_modules,vendor,tests,assets/src
# dopo aver tradotto languages/booking-manager-xx_XX.po:
wp i18n make-json languages --no-purge
```

## Pacchetto

```bash
bin/build-zip.sh                # npm ci + build + controlli + dist/booking-manager-<versione>.zip
bin/build-zip.sh --skip-assets  # usa assets/build così com'è
```

Lo script controlla che la versione nell'intestazione di `booking-manager.php` coincida con `BKNG_VERSION`, esegue `php -l` su ogni file e include solo i file di esecuzione.

Nuova versione: aggiornare `Version:` e `BKNG_VERSION` in `booking-manager.php`, la sezione della versione in `CHANGELOG.md` (è quella che WordPress mostra in «Visualizza dettagli»), poi creare lo ZIP e caricarlo sul server licenze.

## Channex

Le credenziali possono stare in `wp-config.php` (consigliato in produzione), e allora la schermata non le chiede:

```php
define( 'BKNG_CHANNEX_API_KEY', '…' );
define( 'BKNG_CHANNEX_WEBHOOK_SECRET', '…' );
define( 'BKNG_CHANNEX_BASE_URL', 'https://app.channex.io/api/v1' ); // facoltativa
```

L'invio parte da WP-Cron ogni minuto (`bkng_channex_tick`), o da Action Scheduler se è già installato. Su un sito con poche visite conviene un cron di sistema: `define( 'DISABLE_WP_CRON', true );` e `* * * * * wget -q -O - https://sito.it/wp-cron.php?doing_wp_cron >/dev/null 2>&1`.

Per i test l'ambiente staging è `https://staging.channex.io/api/v1`; i test automatici usano risposte finte (`pre_http_request`) e non chiamano Channex.

## Migrazioni

Nuova classe in `src/Database/Migrations/Migration_YYYYMMDD_NNNNNN_Nome.php`, aggiunta all’elenco in `Database\Migrator`. Si applicano all’attivazione e al caricamento dopo un aggiornamento; ognuna una sola volta (`bkng_migrations`).

## Sito locale per le prove visive

Su un sito di prova si possono puntare un server licenze di test con le costanti di [LICENZA.md](LICENZA.md) (solo su indirizzi locali). Non esistono dati dimostrativi: la configurazione guidata crea la prima struttura.

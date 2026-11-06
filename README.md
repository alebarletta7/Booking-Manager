# Booking Manager

Gestionale WordPress per B&B, affittacamere, case vacanza, appartamenti, residence, guest house, hotel e piccole catene: camere e tipologie, tariffe, disponibilità, prenotazioni dal gestionale e dal sito, pagamenti (reception, Stripe, Nexi), check-in con QR, housekeeping, email e dashboard.

Quarto prodotto della famiglia di Night Manager, Beach Manager e Restaurant Manager, con lo stesso server licenze. Funziona da solo: non richiede e non tocca gli altri plugin.

- WordPress 6.6+, PHP 8.1+
- Slug `booking-manager`, prefisso `bkng_`

## Installazione

1. Plugin → Aggiungi nuovo → Carica plugin → `booking-manager-x.y.z.zip` → Attiva.
2. **Booking Manager** nel menu: inserire la chiave di licenza (finché non è attiva si vede solo questa schermata).
3. La configurazione guidata crea la struttura, le prime camere con il prezzo base e la pagina di prenotazione (shortcode `[booking_manager_prenota struttura="slug"]`).
4. Dopo: tariffe stagionali, caparra e cancellazione in **Tariffe**; pagamenti online in **Struttura → Pagamenti**; staff in **Staff**.

Disinstallando il plugin i dati restano, a meno di attivare «Cancella tutti i dati quando il plugin viene eliminato» in **Impostazioni**.

## Documentazione

| | |
|---|---|
| [Architettura](docs/ARCHITETTURA.md) | livelli, dati, doppie prenotazioni, stati, ruoli, privacy |
| [Licenza](docs/LICENZA.md) | attivazione, cosa si blocca e cosa no, WP-CLI |
| [Pagamenti](docs/PAGAMENTI.md) | reception, Stripe, Nexi, webhook, casi limite |
| [Cloudflare](docs/CLOUDFLARE.md) | token, regole, IP del visitatore |
| [Hook e integrazioni](docs/HOOKS.md) | azioni, filtri, canali OTA, WhatsApp, fornitori di pagamento |
| [API REST](docs/API.md) | rotte dello staff e del modulo pubblico |
| [Sviluppo](docs/SVILUPPO.md) | build, test, traduzioni, pacchetto |
| [Report dei test](docs/TEST-REPORT.md) | cosa è stato provato e cosa no |

## Sviluppo in breve

```bash
composer install && npm ci
npm run build
vendor/bin/phpunit
bin/build-zip.sh
```

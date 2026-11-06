# Piattaforma con più clienti (SaaS)

Booking Manager può girare su un'unica installazione WordPress centrale che serve più clienti (tenant). Ogni cliente ha le proprie strutture, il proprio staff e i propri ospiti, e incorpora il modulo di prenotazione nel suo sito con un iframe. Il progetto completo è stato approvato il 6 novembre 2026; i passi arrivano una versione alla volta.

| Versione | Passo | Stato |
|---|---|---|
| 0.2.0 | Clienti sopra le strutture, migrazione dei dati esistenti | ✓ |
| 0.2.1 | Isolamento: permessi, ospiti, registro attività, staff, libreria media | da fare |
| 0.2.2 | Pannello Piattaforma: clienti, utenti, domini, piani, sospensione | da fare |
| 0.2.3 | Pagina iframe `/booking/{struttura}`, CSP per cliente, pagamento fuori dall'iframe | da fare |
| 0.2.4 | Contatti del cliente e pulsante WhatsApp | da fare |
| 0.2.5 | Email: mittente e SMTP del cliente, modelli, variabili, registro | da fare |
| 0.2.6 | WhatsApp Meta per cliente, Channex per struttura | da fare |
| 0.2.7 | Limiti per cliente, protezione login, documentazione | da fare |

## Modello

```
Piattaforma (amministratori WordPress)
└── Cliente (tenant)            bkng_tenants
    ├── utenti                  bkng_tenant_users (un utente appartiene a un solo cliente)
    ├── domini autorizzati      bkng_tenant_domains (solo per l'iframe, non sono un'autorizzazione)
    ├── ospiti                  bkng_guests.tenant_id
    └── strutture               bkng_properties.tenant_id
        └── camere, tariffe, disponibilità, prenotazioni, pagamenti, messaggi, Channex: per struttura come prima
```

`tenant_id` esiste solo dove il dato non dipende da una struttura: `properties`, `guests`, `guest_consents`, `audit_log`, `notifications` (per il registro). Le altre tabelle hanno già `property_id` (NOT NULL e indicizzato) e prendono il cliente dalla struttura: una copia in ogni tabella potrebbe divergere.

Stati del cliente (`Tenants\TenantStatus`): `active`, `trial`, `suspended`, `expired`, `archived`. Il server licenze vale solo per la piattaforma; piani e scadenze dei clienti si gestiranno dal pannello centrale (0.2.2).

`Tenants\Tenancy` decide il `tenant_id` da scrivere nelle righe nuove: quello della struttura, altrimenti il cliente predefinito, altrimenti NULL (sito non ancora migrato). Non legge mai valori inviati dal browser.

## Migrazione dei dati esistenti

L'aggiornamento del plugin aggiunge solo tabelle e colonne vuote (`Migration_20261106_000001_Tenants`): fino alla migrazione dei dati il plugin funziona come prima, e `tenant_id` NULL vale «cliente predefinito». I dati si collegano solo su comando:

```bash
wp db export backup-prima-dei-clienti.sql          # 1. backup
wp booking-manager tenant migrate --dry-run         # 2. cosa verrebbe fatto
wp booking-manager tenant migrate --backup-done     # 3. esegue (facoltativo: --name="Hotel Esempio Srl")
wp booking-manager tenant verify                    # 4. controllo (eseguito anche alla fine di migrate)
wp booking-manager tenant status
```

`migrate`:

1. rifiuta di partire senza `--backup-done`;
2. salva un'istantanea (`bkng_tenancy_snapshot`): righe per tabella fino all'ultimo id esistente, totali, pagato, rimborsato e saldo delle prenotazioni per stato, pagamenti e transazioni;
3. crea il cliente predefinito (nome dalla ragione sociale o dal nome della prima struttura, piano senza limiti, dominio del sito autorizzato) o riusa quello già marcato come predefinito;
4. imposta `tenant_id` solo dove è NULL, a blocchi di 1000 righe: nessuna riga cancellata, duplicata o rinumerata;
5. collega al cliente gli utenti con un ruolo di Booking Manager; gli amministratori di WordPress restano amministratori della piattaforma, fuori dai clienti;
6. verifica: righe e importi uguali all'istantanea (le righe nuove arrivate nel frattempo non contano), nessun dato senza cliente, nessuna struttura con un cliente inesistente, nessuna prenotazione con l'ospite di un altro cliente, nessun utente dello staff senza cliente.

Ripeterlo non cambia nulla. Su una nuova installazione il primo cliente viene creato all'attivazione.

**Tornare indietro**: `wp booking-manager tenant rollback --yes` rimette `tenant_id` a NULL e dimentica il cliente predefinito, senza cancellare clienti, utenti o domini (una nuova `migrate` li riusa). Il ritorno completo si fa ripristinando il backup.

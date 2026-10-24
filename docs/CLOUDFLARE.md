# Cloudflare

Pannello in **Impostazioni → Cloudflare**, come in Beach e Night Manager. Serve ai siti che passano da Cloudflare (proxy arancione) e fa tre cose:

1. **Diagnostica**: dice se le richieste arrivano da Cloudflare, quale IP vede WordPress e quale IP del visitatore indica l'header `CF-Connecting-IP`.
2. **Collegamento alla zona** con un token API (facoltativo) per verificare e applicare le regole consigliate.
3. **Regole da copiare** a mano, per chi non vuole dare un token al sito.

Il plugin funziona anche senza Cloudflare: il pannello è un aiuto, non un requisito.

## Token API

Si crea in Cloudflare (Profilo → Token API → Crea token personalizzato) con i permessi minimi:

- Zona → Zona → Lettura (Zone → Zone → Read)
- Zona → WAF della zona → Modifica (Zone → Zone WAF → Edit)
- Zona → Regole cache → Modifica (Zone → Cache Rules → Edit)

limitato alla sola zona del sito. Il modo migliore è scriverlo in `wp-config.php`, così non finisce mai nel database:

```php
define( 'BKNG_CLOUDFLARE_API_TOKEN', '…' );
define( 'BKNG_CLOUDFLARE_ZONE_ID', '…' );
```

Se invece si inserisce nel pannello, è salvato cifrato e non torna mai al browser. **Verifica** controlla token e zona; **Applica le regole** le crea o le aggiorna.

## Regole

Le regole del plugin si riconoscono dalla descrizione, che inizia sempre con `Booking Manager:`. Applicarle di nuovo aggiorna le nostre, toglie i nostri doppioni e **non tocca nessun'altra regola della zona**.

### 1. Nessuna cache per prenotazioni e API

`Booking Manager: nessuna cache per prenotazioni e API` (fase cache) — escludono dalla cache:

```
(http.request.uri.query contains "rest_route=/booking-manager/"
 or http.request.uri.query contains "rest_route=%2Fbooking-manager%2F"
 or http.request.uri.path contains "/wp-json/booking-manager/")
```

Disponibilità, prezzi e pagine dell'ospite devono essere sempre freschi. Le risposte dell'API hanno comunque `Cache-Control: no-store`.

### 2. Notifiche di pagamento senza controlli anti-bot

`Booking Manager: notifiche di pagamento senza controlli anti-bot` (WAF personalizzato, azione «salta» le verifiche anti-bot, il livello di sicurezza e i limiti di frequenza). Stripe e Nexi chiamano il sito da server, senza browser: una sfida anti-bot bloccherebbe la conferma del pagamento.

L'eccezione è **la più stretta possibile**: solo richieste `POST` con contenuto JSON verso **gli indirizzi esatti dei webhook** dei conti di pagamento collegati (percorso e query uguali), uno per conto:

```
(http.request.method eq "POST"
 and starts_with(http.request.headers["content-type"][0], "application/json")
 and ((http.request.uri.path eq "/wp-json/booking-manager/v1/webhooks/stripe/3" and http.request.uri.query eq "")
      or …))
```

Senza conti collegati la regola punta a un indirizzo che non esiste e non esenta nulla. Dopo aver collegato o tolto un conto di pagamento, riapplicare le regole (il pannello lo segnala). Il resto del sito, il modulo pubblico compreso, resta protetto da Cloudflare; contro lo spam il modulo ha i suoi controlli (tempo minimo di compilazione firmato, campo trappola, limiti per indirizzo IP) e, se si inseriscono le chiavi, **Cloudflare Turnstile** (**Impostazioni → Generali**).

## IP del visitatore

I limiti di frequenza del modulo pubblico usano l'IP del visitatore. Dietro Cloudflare, WordPress vede l'IP di Cloudflare: il plugin usa `CF-Connecting-IP` **solo se la richiesta arriva davvero da un indirizzo di Cloudflare**. Dietro un altro proxy si usa il filtro `bkng_client_ip` (vedi [HOOKS.md](HOOKS.md)).

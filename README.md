# IRNoti Service for WordPress

The official plugin that connects WordPress / WooCommerce to [IRNoti](https://irnoti.com): SMS, shop bots (Bale/Telegram), email and VoIP, all from the WordPress admin.

## Features

| Area | What it does | IRNoti API |
|---|---|---|
| SMS | Rule engine (events → text or pattern → customer/admin/custom number, with conditions and live preview); pattern manager; test send and log | `POST /sms/send`, `POST /sms/pattern` |
| Bot – products | Mirrors WooCommerce products and categories into the shop bot: price, sale price, stock, image, active flag, deletion. Auto-sync on save (debounced via WP-Cron) plus a manual "sync all" | `PUT/DELETE /botbuilder/bots/:id/shop/*` |
| Bot – orders | Pulls bot orders into WooCommerce every 5 minutes and syncs statuses both ways (`confirmed` / `done` / `canceled`) | `GET …/shop/orders`, `POST …/shop/orders/:id/status` |
| Email | Routes every `wp_mail()` through IRNoti; falls back to the normal WordPress transport if the API fails. Test email included | `POST /email/send` |
| VoIP | WebRTC softphone (JsSIP), click-to-call, live calls (hang up / hold / transfer), call reports with CSV export, recordings and voicemail (play / transcribe / delete), block list, SIP account management | `/voip/*` |
| Webhooks | Receives IRNoti events at `/wp-json/irnoti/v1/webhook` with HMAC verification; exposes `irnoti_webhook_{event}` actions for developers | – |
| SMS login | `[irnoti_login]` shortcode for passwordless login with a one-time code | `sms/send` or `sms/pattern` |

## Design

The admin UI follows the IRNoti platform design: RTL, Vazirmatn (bundled locally under the SIL OFL, no external font requests), brand `#FF3300`, warm `#FAF8F6` background, dark header, pill tabs, cards and status badges. Styles live in `assets/css/admin.css` (scoped to `.irn`, so they never leak into other admin pages) and `assets/css/otp.css` for the login form.

## Requirements

- WordPress 6.2+, PHP 8.0+ (with OpenSSL)
- WooCommerce for order SMS and bot product/order sync (optional)
- An IRNoti account and an API key (`irn_…`)

## Installation

1. Copy the `irnoti-service/` folder into `wp-content/plugins/` (or zip it and upload it) and activate it.
2. For the softphone, run `bin/vendor-jssip.sh` once (needs Node/npm). It builds `irnoti-service/assets/js/jssip.min.js`. Alternatively, set an `https://` JsSIP 3.x URL under **IRNoti → VoIP → Settings**. Without JsSIP everything works except the in-browser softphone.
3. Go to **IRNoti → Connection**, enter your API key and save.

### API key scopes

Create the key in the IRNoti panel with the scopes for the features you use:
`sms.send`, `sms.read`, `email.send`, `email.read`, `bot.read`, `bot.send`.
Note: the VoIP endpoints use `sms.read` / `sms.send`.

## Configuration guide

### SMS rules, patterns and templates
**IRNoti → SMS** has four sections:

- **Rules** – "when *event* happens (and *conditions* hold), send *text or pattern* to *recipient*".
  - Events: order status changes (every WooCommerce status), new order, order from the bot, note to customer, refund, low stock, out of stock, new customer, WordPress user registration.
  - Recipients: customer/user, admin (from Settings) or custom numbers.
  - Conditions (orders): minimum total, payment method, source (site / bot / any).
  - Text rules: click variable chips (`{order_id}`, `{first_name}`, `{total}`, `{items}`, `{order_url}` …) to insert them; live preview with sample data and a character/part counter (70 chars per Persian part, 67 when multi-part).
  - Pattern rules: pick an approved pattern; its `{placeholders}` become fields that are auto-mapped to matching variables (e.g. `{name}` → `{first_name}`, `{price}` → `{total}`), and you can edit each mapping. Values are cut to the API's 200-character limit.
  - Every send is idempotent (rule + order + event), so repeated WooCommerce hooks never double-send.
- **Patterns** – list your patterns with review status and create new ones (they go to review; once approved they appear in the rule editor).
- **Settings** – default line (picked from your lines), admin mobile, SMS login.
- **Send & log** – test send and the last 50 messages.

Rules from v0.1 (per-status templates and the admin new-order number) are migrated automatically.

SMS login: tick the option, place `[irnoti_login]` on a page, and have each user save their mobile number on their profile page. Administrators cannot log in this way unless a filter allows it:
`add_filter( 'irnoti_otp_allow_admins', '__return_true' );`

### Bot (products and orders)
1. In the IRNoti panel create a **shop bot** and connect it to Bale/Telegram.
2. In **IRNoti → Bot**, pick the bot and enable auto-sync.
3. Click **Sync all products** for the initial import.
4. Enable **order pulling** to create WooCommerce orders from bot orders.

How the bot and WooCommerce stay in sync:

- **WooCommerce is the source of truth for the catalog and stock.** Any product save, and any stock movement (web order, refund, manual edit, bot order), is pushed to the bot, so the bot never sells what the store no longer has.
- **Bot orders become real WooCommerce orders** (every 5 minutes, or on demand): items are matched to your products, the customer is linked by mobile number when a matching user exists (no accounts are created), and WooCommerce's own order hooks reduce stock. Orders the bot marks as paid are completed with `payment_complete()`.
- If stock would go negative a warning order note is added.
- Status changes flow both ways. The "SMS rules" can react to bot orders (event "order from the bot", or the *source* condition).
- The Bot tab shows recent bot orders with a link to the matching WooCommerce order.

Prices are stored as integer Rials on the server. WooCommerce currency `IRR` is sent as is, `IRT` (Toman) is multiplied by 10. Any other currency is rejected rather than guessed.

Status mapping:

| Bot | WooCommerce |
|---|---|
| `new` | on-hold |
| `confirmed` | processing |
| `shipped` | processing |
| `done` | completed |
| `canceled` | cancelled |

Only `processing → confirmed`, `completed → done` and `cancelled → canceled` are pushed back to the bot.

### Email
Enable it, set a sender address on a domain you verified in IRNoti, and use **Send test email**. Failed API calls fall back to the default WordPress mailer, so mail is never silently lost.

### Webhooks
Webhooks can only be created in the IRNoti panel (the API does not accept API keys for this).
1. Copy the endpoint URL shown in **IRNoti → Connection**.
2. Create a webhook in the panel with that URL and choose the events.
3. Paste the signing secret back into the plugin.

Every verified event fires `do_action( 'irnoti_webhook_' . $event, $data, $payload )` and `do_action( 'irnoti_webhook', $event, $data, $payload )`. Example events: `message.delivered`, `email.bounced`, `call.inbound`.

### VoIP
- **Phone** – click-to-call (server bridges your line and the target) and the softphone. Create a SIP account first (see *SIP accounts*), choose it and press **Connect**.
- **Live calls** – refreshes every 5 seconds; hang up, hold or transfer.
- **Reports** – filter by date, direction, status and number; summary cards; "load more"; CSV export.
- **Recordings** – play, transcribe and delete call recordings and voicemail.
- **Block list** – block or unblock numbers.
- **SIP accounts** – create, rotate the password, delete.
- **Settings** – your from-number for click-to-call and an optional JsSIP URL.

The softphone requires the VoIP server to expose a WebSocket (WSS) endpoint for PJSIP.

## Security

- The API key is encrypted with AES-256-GCM (key derived from the WordPress salts) and is never sent to the browser. All API calls originate from the WordPress server.
- The API base URL must be `https://` (plain `http://localhost` is only accepted when `WP_DEBUG` is on).
- Every admin action requires the `manage_options` capability and a nonce.
- The VoIP proxy only forwards operations listed in `IRNoti_VoIP::OPS` and sanitizes every parameter.
- SMS sends use an `Idempotency-Key` (order + status) so a re-fired hook never double-bills.
- SMS login: one-time codes are stored only as HMACs, expire after 5 minutes, allow 5 attempts, are rate-limited per mobile number and per IP, and never reveal whether a number is registered.
- Webhooks are authenticated by HMAC-SHA256 with a 5-minute timestamp tolerance.

## Development

```bash
php tests/pure-test.php     # dependency-free tests for helpers (mobile normalizing, webhook signature)
php bin/make-pot.php        # regenerate irnoti-service/languages/irnoti-service.pot
bin/vendor-jssip.sh         # build the JsSIP browser bundle
```

The admin UI strings are currently Persian (the default language of the platform); source strings are in the `irnoti-service` text domain and can be translated with the generated `.pot` file.

## Known assumptions to verify against a live server

- Response shape of `GET /botbuilder/bots` (array or `items`) and that `PUT …/shop/products` returns `id`.
- Field names of the softphone credentials response (`sipWsUrl`/`wsUrl`, `sipDomain`/`domain`, `username`, `secret`).
- Column names in the recordings and block-list tables.
- Bot order item prices are in Rials.

## License

GPL-2.0-or-later

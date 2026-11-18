# RedBeards Telegram bed configurator

Native Telegram HTML Widget for @redbeards_robot (Bot API 10.4, private chats).
Production server: redbeards.ru. Production order notifications remain in @ZakazKrovati_bot.

## Interface

Three screens: model/size/quantity, fabric/colour, base/options. Prices are supplied
by the shop server. The widget never calculates or accepts a payment by itself.
Full product photographs are sent by the bot, outside the widget's offline sandbox.
Delivery, contact details, consent and acquiring use the existing shop checkout.

## Protocol v1

`chat_data` and `answerWidgetQuery.data` contain the same JSON snapshot:

- `v:1`, `r`: increasing positive revision, `s`: screen 0–2.
- `t,p,d,f`: heading, formatted price, selected bed summary and fabric summary.
- `n`: number of beds, 1–10.
- `q:1` when a mattress requires a quote; otherwise 0.
- `g`: groups `[heading, options, layout?]`; an option is
  `[label, action, selected, hexColour?, disabled?]`.
  Layout is `sizes`, `colors`, `stack` or omitted.
- `u`: full HTTPS checkout URL on redbeards.ru, at most 256 characters.
- `e`: optional concise server notice.

One synchronous request per tap: `revision:action`, at most 64 UTF-8 bytes.
Actions: n0/n1/n2 (screen); m3 etc (active model); s160 etc (size); q2 etc
(quantity); fM/fB (fabric); c26 etc (fabric code); p1 etc (colour page);
b0/b1 (wood/lift); l0/l1 (legs); h0/h1 (40mm foam); x0/x1 (mattress);
photo (current product photograph). Set actions are absolute, not toggles.

The server must authenticate Telegram's webhook secret, bind each widget to its
private chat/user/message, validate the action and current revision, and use
the existing shop price calculation. Answer within 15 seconds, persist data,
and use editMessageWidgetData for cross-device state. Reject stale changes and
return the current snapshot. Never send customer addresses or payment keys in
widget data, source, captions or logs.

## Deployment status

The interface and protocol are prepared here. Server handlers and the checkout
bridge require inspection of the existing PHP shop before installation.
No production website, bot webhook, acquiring or order database is changed by
adding these source files.

## Checks

`node --test telegram-bed-calculator/tests/widget.test.cjs`

Python zlib level 9 must compress widget.html to no more than 4096 bytes.
The plain UTF-8 document must not exceed 32768 bytes; every data snapshot must
fit 1024 bytes. Supported viewport width is 200–600 pixels, with no scrolling.

The server state machine receives a read-only canonical price adapter; option labels derive their order-wide deltas from that same adapter. SessionStore uses a separate SQLite database, binds private chat/user/message, rejects stale revisions, and deduplicates query IDs. No production price list is copied into the module. Live shop adapter, webhook transport and checkout entry point remain to be connected after guarded shop inspection.

## Server file placement

Private module files, widget.html and bootstrap.php belong in /admin/redbeards-client-bot outside the document root. The bot token and webhookSecret live only in private settings.json. Public wrappers are quiz/telegram-widget-webhook.php and hotel-beds/telegram-checkout.php. A verified bootstrap.php must supply the canonical shop price adapter, current model/fabric metadata, a private SQLite SessionStore and the configured controller. It is intentionally not generated from guessed shop method names. The checkout wrapper preserves configuration through the existing rb-config-v2 browser entry contract; it creates no order or payment. Verify that contract against the currently installed storefront before enabling the bot.

WebhookController authenticates before JSON decoding, answers widget queries before slow image/message requests, and handles expired/repeated delivery without duplicating photos. Incoming private messages/resume callbacks use short SQLite processing leases. TelegramApi accepts only the retail bot identity and never prints credentials. Public wrappers must be enabled only after bootstrap, existing shop compatibility and live server probes pass.

The guarded inspection template reads only PHP source, the public product catalogue and storefront code (configuration storage key/fabric metadata). It never reads acquiring settings or customer databases, refuses to run with its placeholder setup hash, authenticates a one-time setup key, and removes itself after a successful report.

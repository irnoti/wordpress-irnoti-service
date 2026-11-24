=== IRNoti Service ===
Contributors: irnoti
Tags: sms, woocommerce, telegram, bale, voip
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress and WooCommerce to IRNoti: SMS rules, shop bot (Bale/Telegram) sync, email, site chat widget and VoIP softphone.

== Description ==

IRNoti Service connects your site to the [IRNoti](https://irnoti.com) platform from the WordPress admin (RTL, Persian UI).

* **SMS** – rule engine (events, conditions, text or pattern), pattern manager, test send and log, passwordless login with a one-time code (`[irnoti_login]`).
* **Shop bot** – mirrors WooCommerce products and stock to your Bale/Telegram shop bot and pulls bot orders into WooCommerce.
* **Email** – sends `wp_mail()` through IRNoti, falling back to the default WordPress mailer on error.
* **Site widget** – shows the IRNoti support/AI chat widget (`[irnoti_widget]` shortcode, page scope, delay).
* **Content & SEO (AI)** – SEO score and fixes for posts/pages/products, AI title/meta/rewrite/image in the editor, topic ideas.
* **VoIP** – click-to-call, softphone, live calls, reports, recordings, block list, SIP accounts.
* **Webhooks** – HMAC-verified events with `irnoti_webhook_{event}` actions.

= External services =
This plugin requires an IRNoti account and API key. It sends data to the IRNoti API (`https://api.irnoti.com/v1`) only for features you enable:

* SMS: recipient mobile number and message text (or pattern values) when a rule fires, on test send, or on SMS login.
* Email: recipient, subject and body of WordPress emails when "send via IRNoti" is on.
* Bot: product data (title, price, stock, image URL) and order status changes.
* AI tools: the title and text of the post you ask the AI to optimise, sent to IRNoti AI only when you press an AI button.
* VoIP: numbers you dial or block, and SIP account requests.
* Widget: when enabled, visitors' browsers load `https://api.irnoti.com/v1/embed/widget.js`, which contacts IRNoti to run the chat.

Service: https://irnoti.com — terms and privacy policy are published on that site.

== Installation ==

1. Upload the plugin and activate it.
2. Open **IRNoti → Connection**, enter your API key (`irn_…`) and save.
3. Configure the services you need from the other tabs.

== Changelog ==

= 1.1.8 =
* New: Content & SEO tab with per-item SEO score and problems for posts, pages and products.
* New: editor box with live score and AI tools (title, meta description, keyword, SEO rewrite, FAQ, excerpt, featured image) using IRNoti AI.
* New: AI topic ideas box with one-click SEO draft.
* API key scopes for these tools: ai.read, ai.chat, ai.image.

= 0.1.0 =
* Initial release: SMS rules, bot/WooCommerce sync, email, site widget, VoIP, overview dashboard.

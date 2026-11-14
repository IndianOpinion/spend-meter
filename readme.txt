=== Spend Meter ===
Contributors: indianopinion
Tags: ai, llm, budget, cost, metering
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

One ledger, one price table, caps and a kill switch for every model call other plugins make.

== Description ==

Spend Meter records every language, embedding, image or speech model call that other plugins make on your site, with tokens, cost, purpose and calling plugin. Before each call it decides whether the call may spend: daily and monthly caps per site, per purpose and per network, plus a global and a per-purpose kill switch.

Written from scratch; not derived from any third-party plugin.

* One ledger: every attempt, retries and failures included, one row each.
* One price table that you load and date yourself. The plugin ships the file format and no prices.
* Caps checked before the call, with a reservation so concurrent calls cannot all slip through.
* A kill switch you can flip from the screen, WP-CLI, or a constant in wp-config.php.
* A dashboard widget with today, this month, the top purposes and warnings.
* WP-CLI: status, report, check, caps, kill, prices, reprice, reservations, export, prune.

Spend Meter never calls a model provider, holds no API keys and makes no outbound HTTP requests. It stores no prompts or completions.

Plugins integrate through hooks only (`spend_meter_pre_spend`, `spend_meter_record`, `spend_meter_release`), so they work with or without Spend Meter installed.

== Installation ==

1. Upload the `spend-meter` folder to `/wp-content/plugins/` and activate it.
2. Set a daily cap under Settings, Spend meter, or with `wp spend-meter caps set site --day=<amount>`.
3. Import your own dated prices: `wp spend-meter prices import my-prices.json --dry-run`, then without `--dry-run`.

To run it as a must-use plugin, copy the folder to `wp-content/mu-plugins/spend-meter/` and add a loader file that requires `spend-meter/spend-meter.php` (see `examples/mu-loader.php`).

== Frequently Asked Questions ==

= Why are calls denied right after install? =

No prices are loaded, so every model is unpriced, and unpriced models are denied by default. Import your prices, or turn on "Allow unpriced models" while you set up.

= Where do prices come from? =

From you. Read each price from the provider's own pricing page, date it and import it. Prices change; the screen flags rows older than 120 days.

= Can another plugin override a cap? =

No. A site can add rules that deny through the `spend_meter_decision` filter; a filter cannot turn a deny into an allow.

== Changelog ==

= 0.1.0 =
* First release.

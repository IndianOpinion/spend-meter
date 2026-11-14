# spend-meter

A WordPress plugin that records every model call other plugins make (tokens, cost, purpose, caller) and decides before each call whether it may spend. One ledger, one price table, daily and monthly caps, and a kill switch, shared by every plugin on the site.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: once the refusal for a retired purpose moved in front of the request and every call was recorded per attempt, the ledger showed zero rows for that purpose from the same day; before, retired calls were still being made and billed.

## Contents

- [Install](#install)
- [Quick start for operators](#quick-start-for-operators)
- [Quick start for plugin authors](#quick-start-for-plugin-authors)
- [Prices](#prices)
- [Configuration](#configuration)
- [WP-CLI](#wp-cli)
- [How it works](#how-it-works)
- [Public contract](#public-contract)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [Licence](#licence)

## Install

Requirements: PHP 8.1 or later, WordPress 6.4 or later, MySQL 5.7+ or MariaDB 10.4+. No Composer at runtime and no outbound HTTP.

**Without Composer.** Download a release, unzip it to `wp-content/plugins/spend-meter/` and activate it. Activation creates the ledger table and the cron events.

**With Composer** (for sites that manage plugins with Composer and `composer/installers`):

```sh
composer require indianopinion/spend-meter
```

The package type is `wordpress-plugin`, so the installer puts it in your plugins directory. Activate it as usual.

**As a must-use plugin.** Copy the plugin directory to `wp-content/mu-plugins/spend-meter/` and copy [`examples/mu-loader.php`](examples/mu-loader.php) to `wp-content/mu-plugins/spend-meter-loader.php`:

```php
<?php
require_once __DIR__ . '/spend-meter/spend-meter.php';
```

Activation hooks do not run for must-use plugins, so the table is created on the first admin page load or the first `wp spend-meter` command. Until then every pre-spend check is denied with `ledger_unavailable`, which is the safe direction.

## Quick start for operators

1. Activate the plugin. A notice says "no prices loaded": the plugin ships the price file format and no prices.
2. Set a daily cap for the site. Do this on day one:
   ```sh
   wp spend-meter caps set site --day=<amount> --month=<amount>
   ```
3. Load your prices (see [Prices](#prices)):
   ```sh
   wp spend-meter prices import my-prices.json --dry-run
   wp spend-meter prices import my-prices.json
   ```
4. Watch the "Model spend" dashboard widget, or run `wp spend-meter status`.
5. To stop all spend at once:
   ```sh
   wp spend-meter kill on
   ```
   or put `define('SPEND_METER_KILL', true);` in `wp-config.php`, which the admin screen cannot undo.

Export before you uninstall: uninstall drops the ledger unless "Keep the ledger on uninstall" is on or `SPEND_METER_KEEP_LEDGER` is true.

```sh
wp spend-meter export --from=2026-01-01 --to=2026-12-31 --format=csv > ledger.csv
```

## Quick start for plugin authors

Your plugin never depends on spend-meter. It asks before it spends and records after, through hooks, and keeps its own cap and kill switch for when spend-meter is absent. A full example is in [`examples/caller-plugin.php`](examples/caller-plugin.php).

```php
$decision = apply_filters('spend_meter_pre_spend', null, [
    'purpose'           => 'summary',          // what the call is for
    'caller'            => 'my-plugin',        // your plugin slug
    'provider'          => 'example-provider',
    'model'             => 'example-model',
    'est_input_tokens'  => 1200,
    'max_output_tokens' => 400,
]);

if (null === $decision) {
    // No meter present: use your own cap and kill switch.
} elseif (true !== $decision['allowed']) {
    return; // Denied. $decision['reason'] says why; unknown codes are denies too.
}

$response = my_plugin_send_request();
$usage    = function_exists('spend_meter_usage_from_response')
    ? spend_meter_usage_from_response($response_body)
    : null;

do_action('spend_meter_record', [
    'reservation_id' => $decision['reservation_id'] ?? null,
    'purpose' => 'summary', 'caller' => 'my-plugin',
    'provider' => 'example-provider', 'model' => 'example-model',
    'status'        => 'ok',               // ok, error_billed or error_unbilled
    'http_status'   => 200,
    'input_tokens'  => $usage['input_tokens'] ?? 0,
    'cached_input_tokens' => $usage['cached_input_tokens'] ?? 0,
    'output_tokens' => $usage['output_tokens'] ?? 0,
    'reasoning_tokens' => $usage['reasoning_tokens'] ?? 0,
    'provider_cost' => $usage['provider_cost'] ?? null,
]);
```

Rules for callers:

- Check on `plugins_loaded` or later. Detect the meter with `defined('SPEND_METER_API')`; this release is contract `1`.
- If `allowed` is not exactly `true`, do not make the call.
- If you got a `reservation_id`, follow it with exactly one `spend_meter_record` (the call happened) or `spend_meter_release` (you decided not to call).
- Record every attempt, billed or not. A retry is a new call: record it with the same `reservation_id` and it gets its own linked row.
- Record usage even when the output was unusable: a 200 with a broken body is still billed (`error_billed`).
- Pass `http_status` 401, 402 or 403 when the provider refuses: the widget shows it at once.

## Prices

spend-meter ships no prices. Prices change, and a stale hard-coded table makes the ledger wrong without a warning. The operator owns the numbers.

- Read each price from the provider's own pricing page. Copy the list price per million tokens (and the cached-input, reasoning, unit and per-call prices where the provider has them).
- Date every row (`as_of`) and keep the page you read it from (`source`). The admin screen flags rows older than the staleness threshold (120 days by default).
- If you route through an aggregator, add a row for the aggregator's own model id. When the aggregator reports the billed cost in its response, that cost wins anyway and the table cost is kept beside it, so drift is visible.
- A model with no row is unpriced. There is no default price. With any cap set, unpriced models are denied unless you turn on "Allow unpriced models".

The format is in [`docs/prices.example.json`](docs/prices.example.json). That file holds placeholders that fail validation on purpose, so it cannot be imported unread. Every price is a decimal string with at most nine places. A `model` ending in `*` matches by prefix; the longest prefix wins and an exact row always beats a prefix row.

```sh
wp spend-meter prices import my-prices.json --dry-run     # shows + added, ~ changed, - removed
wp spend-meter prices import my-prices.json               # merge (default)
wp spend-meter prices import my-prices.json --mode=replace
wp spend-meter prices set example-provider example-model --in=<decimal> --out=<decimal> \
    --as-of=YYYY-MM-DD --source=https://provider.example/pricing
wp spend-meter prices list --stale
wp spend-meter prices unpriced                            # models seen without a price
wp spend-meter reprice --dry-run                          # fill unpriced rows once a price exists
```

The settings screen also takes a pasted price file, with the same validation and dry run.

## Configuration

Settings live under **Settings, Spend meter** (on multisite, site caps on each site's screen; everything else under **Network Settings, Spend meter**). Every default passes through the `spend_meter_default_settings` filter.

| Setting | Default | Notes |
|---|---|---|
| Global kill switch | off | `SPEND_METER_KILL` holds it on and the screen shows it locked |
| Purpose kill switches | none | Exact slugs or `prefix*`. `SPEND_METER_KILL_PURPOSES` (comma-separated) adds entries the screen cannot remove |
| Site day and month caps | none | Set a day cap on install |
| Purpose caps | none | Per purpose slug or `prefix*`; every matching pattern applies |
| Network day and month caps | none | Multisite only |
| Warning threshold | 80 percent | 1 to 99; fires `spend_meter_cap_warning` once per scope per window |
| Allow unpriced models | off | Off: unpriced models are denied whenever a cap is set. On: allowed, recorded at zero cost, logged |
| Default output allowance | 4,096 tokens | Used in the estimate when `max_output_tokens` is absent |
| Reservation lifetime | 15 minutes | 1 to 120; filter `spend_meter_reservation_lifetime` |
| Ledger currency | `USD` | Fixed once the ledger has rows; there is no conversion |
| Price staleness threshold | 120 days | Widget and `prices list --stale` |
| Retention | 0 (forever) | Months; a daily task removes older settled rows in batches of 1,000 |
| Keep the ledger on uninstall | off | Or `SPEND_METER_KEEP_LEDGER` |
| Capability | `manage_options` | Filter `spend_meter_capability`; the network screen uses `manage_network_options` |

Constants: `SPEND_METER_KILL`, `SPEND_METER_KILL_PURPOSES`, `SPEND_METER_DISABLE_WIDGET`, `SPEND_METER_KEEP_LEDGER`. Defined by the plugin: `SPEND_METER_API` (contract version, `1`) and `SPEND_METER_VERSION`.

## WP-CLI

Amounts are decimal strings in the ledger currency. Every command exits 0 on success and 1 on error. Listing commands take `--format=table|json|csv`; `--url` works as usual on multisite.

| Command | Does |
|---|---|
| `wp spend-meter status` | Kill switches, today and month against caps, live reservations, unpriced and unreserved rows today, last account error, timezone, oldest price date |
| `wp spend-meter report [--from] [--to] [--by=purpose\|model\|caller\|provider\|day] [--include-tests]` | Totals and token counts grouped |
| `wp spend-meter check --purpose= --caller= --provider= --model= [--in=] [--out=]` | Dry-run decision; writes nothing |
| `wp spend-meter caps list` / `caps set <site\|purpose\|network> [<purpose>] [--day=] [--month=]` / `caps unset ...` | Caps |
| `wp spend-meter kill on\|off [--purpose=]` / `kill status` | Kill switches; `kill off` refuses while the constant holds the switch on |
| `wp spend-meter prices list\|set\|unset\|import\|export\|unpriced` | Price table |
| `wp spend-meter reprice [--from] [--to] [--dry-run]` | Fill unpriced rows only, marked `table_late` |
| `wp spend-meter reservations list\|expire` | Inspect and expire stale reservations |
| `wp spend-meter export --from= --to= [--format=csv\|jsonl]` | Ledger to stdout |
| `wp spend-meter prune --older-than=<months> [--dry-run]` | Retention on demand; prints counts first |

## How it works

```
caller plugin                      spend-meter
-------------                      -----------
apply_filters(pre_spend) --------> kill switches -> validate -> price + estimate
                                   -> insert reservation -> sum window totals
                                   -> caps -> spend_meter_decision filter
          <------------------------ decision (allowed, reason, reservation_id, ...)
send request to the provider
do_action(record) ---------------> settle the reservation in place (or add a
                                   linked row, or an unreserved row)
```

- **Reserve, then sum.** An allowed check writes a `reserved` row holding the estimate before it reads the totals. Two processes racing near a cap each see the other's reservation: both may be denied, neither overshoots. A false deny costs one retried call; an overshoot costs money.
- **Window totals** are settled costs plus live reservations, per site, per purpose pattern and (multisite) per network, for the site-local day and month. Reservations older than the lifetime stop counting at once; an hourly cron task marks them `expired`.
- **Money is integer nanos** (one billionth of the currency unit) everywhere in the API and the table. Prices are decimal strings converted with integer arithmetic, never floats.
- **Cost source**, in order: the provider's reported cost; tokens priced from the table (with `price_version`); the reservation estimate when no usage came back; zero and `unpriced`. Cached and reasoning tokens are subsets of input and output, never added on top.
- **Failures.** A missing or unreadable ledger denies (`ledger_unavailable`). A failed record write is buffered and retried at shutdown, then counted as a loss; recording never throws into the caller.

The design and every decision are in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The full behaviour is in [`docs/PRD.md`](docs/PRD.md).

## Public contract

Hooks: `spend_meter_pre_spend` (filter), `spend_meter_record` and `spend_meter_release` (actions), `spend_meter_decision` (filter, can only deny), `spend_meter_recorded`, `spend_meter_cap_warning`, `spend_meter_cap_reached`, `spend_meter_kill_switch_changed` (actions), `spend_meter_capability`, `spend_meter_default_settings`, `spend_meter_price`, `spend_meter_widget_rows`, `spend_meter_reservation_lifetime` (filters).

Functions (exist only while the plugin is active): `spend_meter_check()`, `spend_meter_record()` (row id, 0 when buffered, -1 when invalid), `spend_meter_release()`, `spend_meter_usage_from_response()`, `spend_meter_estimate()`, `spend_meter_totals()`, `spend_meter_format()`, `spend_meter_kill_active()`.

Reason codes: `ok`, `kill_switch`, `kill_switch_purpose`, `invalid_request`, `unpriced_model`, `cap_purpose_day`, `cap_purpose_month`, `cap_site_day`, `cap_site_month`, `cap_network_day`, `cap_network_month`, `ledger_unavailable`, `denied_by_filter`, `decision_filter_error`. Treat any other code as a deny.

`SPEND_METER_API` increases by one on every breaking change, before and after 1.0.0.

## Limitations

- The meter counts what callers tell it. A plugin that never calls the filter or the record action is invisible, except that unreserved records show in the widget.
- Caps are as good as the estimate. A call that uses far more than its `max_output_tokens` settles above its reservation; the next check sees the real cost.
- Network caps read a total cached for 60 seconds and recompute live only before a deny, so on a busy network the network cap can be passed by up to a minute of other sites' spend.
- One currency per install, no conversion.
- No REST API in 0.1.0; the widget and screens are server-rendered.
- The per-check totals query reads every row of the current month for the site. It is index-only, but on very large ledgers (tens of thousands of rows per site per month) it is slower than the 5 ms budget. See the roadmap.
- The plugin outputs nothing on the front end and runs no query on page views that make no model call.

## FAQ

**Why is every call denied right after install?** No prices are loaded and you set a cap, so every model is unpriced and denied. Import your prices, or turn on "Allow unpriced models" while you set up.

**Why are calls allowed with no prices and no caps?** With no cap anywhere there is nothing to protect, so unpriced calls are allowed, recorded at zero cost and logged. Set a cap.

**Can another plugin turn a deny into an allow?** No. The `spend_meter_decision` filter can add rules that deny; a deny it tries to flip is ignored and logged.

**Does a kill switch stop a call that is already running?** No. It stops the next pre-spend check. The running call's record is still written.

**A call ran longer than the reservation lifetime. Is it lost?** No. The reservation is marked `expired`, stops counting, and the late record still settles it.

**Is my prompt stored?** No. The ledger holds counts, costs and identifiers only. `meta` is limited to eight short scalar values.

**Where do I report a security problem?** See [SECURITY.md](SECURITY.md).

## Roadmap

- A small read-only REST route for the widget data (not in 0.1.0 by decision; the widget stays server-rendered).
- A per-day rollup table so the month total stays fast on very large ledgers.

## Licence

MIT. Copyright (c) 2026 Indian Opinion. See [LICENSE](LICENSE).

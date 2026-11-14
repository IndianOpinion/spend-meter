# spend-meter architecture

This document describes how spend-meter 0.1.0 is built and records every
decision taken while building it from [PRD.md](PRD.md), including the
rulings on the PRD's open questions.

## Modules

All classes are in the `IndianOpinion\SpendMeter` namespace under `src/`.
Each domain class is testable without WordPress: the suite runs on a small
stub layer (`tests/stubs/`) and an in-memory ledger.

| Class | Job |
|---|---|
| `Plugin` | Service container and hook registration. Hooks are registered when the plugin file loads; services are built on first use, so a page view that makes no model call runs no query. |
| `Decision` | The pre-spend check (PRD 4.1): kill switches, validation, readiness, price and estimate, unpriced policy, reserve then sum, caps, the decision filter. |
| `Ledger` | Recording (4.2), release and expiry (4.6), the shutdown retry buffer. |
| `Validator` | Intent and call validation. Rejects, never truncates. |
| `UsageParser` | Normalises the two common usage shapes (4.3). Reads only the usage block. |
| `PriceFile` | Parses and validates the price file format. One bad row rejects the file. |
| `PriceTable` | Seed plus overrides, lookup (exact, then longest prefix), `price_version`, estimate and cost maths, import, export, staleness. |
| `Caps` | Site, purpose and network caps in one network-wide option. |
| `KillSwitch` | Global and purpose switches, constants that the screen cannot undo, the change action. |
| `Alerts` | `spend_meter_cap_warning` and `spend_meter_cap_reached`, once per scope per window. |
| `Counters` | Denials per reason per day (14 days) and recording losses. |
| `Money` | Integer nanos, decimal string conversion, overflow-checked arithmetic, display. |
| `Pattern` | The one pattern syntax: an exact slug or a slug with a trailing `*`. |
| `Settings`, `Options` | Settings with defaults and validation; option storage. |
| `Schema` | Table definition, install, column verification, readiness. |
| `Store\Store`, `Store\WpdbStore` | The ledger storage contract and its `$wpdb` implementation. |
| `Cron` | Hourly expiry, daily retention in batches of 1,000. |
| `Admin\SettingsPage`, `Admin\Widget`, `Admin\Notices`, `Admin\Access` | Screens, widget, notices, capability and nonce checks. |
| `Cli\Command` | `wp spend-meter`. |
| `Uninstaller` | What `uninstall.php` runs. |

`src/functions.php` holds the public functions and is loaded only by the
plugin file, so the functions exist only while the plugin is active.
`src/autoload.php` is a PSR-4 autoloader for use without Composer.

## Data flow

### Pre-spend check

1. Global kill switch (constant or option): deny `kill_switch`. Nothing is
   written except the denial counter.
2. Purpose kill switch, when the purpose is a valid slug: deny
   `kill_switch_purpose`.
3. Validate the intent: deny `invalid_request` naming the first bad key.
4. Ledger readiness (db version recorded, which happens only when every
   column exists): deny `ledger_unavailable`.
5. Price lookup and estimate. Unpriced and no override: see ruling 2.
6. Test intents: reserve with `is_test = 1` (unless dry run) and allow.
7. No cap applies: reserve and allow, with `remaining` null. No sum query.
8. Otherwise insert the reservation, then one aggregate query for the site
   and every matching purpose pattern, day and month. Network caps read the
   cached network total (ruling 4).
9. Check caps in priority order; deny with the first failing reason and
   delete the caller's own reservation. Fire cap alerts.
10. Pass the decision through `spend_meter_decision`; it can turn an allow
    into a deny (the reservation is released) and never the reverse.

### Record

1. Validate the call. Invalid: return -1, log, write nothing.
2. A known `call_id` returns the existing row id (idempotent).
3. Look up the reservation by `reservation_id`:
   - `reserved` or `expired`: settle in place (conditional update on the
     status, so two settles cannot both win; the loser becomes a linked row).
   - settled: insert a linked row with `parent_id`.
   - `released` or unknown: insert with the `unreserved` flag.
4. Cost source: provider cost, then table, then the reservation estimate when
   no usage came back, then zero and `unpriced`. `error_unbilled` (including
   every 401, 402 and 403) stores cost zero.
5. On a database error, buffer (100 per request) and retry at `shutdown`;
   then log once per ten minutes and count the loss.
6. Fire `spend_meter_recorded`. A listener that throws is caught and logged.

## Decisions

### Rulings on the PRD's open questions

1. **Test double for `$wpdb`.** The unit suite uses an in-memory ledger
   (`tests/Support/MemoryStore.php`) with the same contract as `WpdbStore`.
   It returns values as strings, the way `$wpdb` does, so casting mistakes
   show up in unit tests. The race test (T11) interleaves for real: the
   double runs a second complete check between the first check's insert and
   its sum. One integration suite (`tests/Integration/WpdbStoreMysqlTest.php`)
   runs the real SQL against MySQL or MariaDB and is skipped unless
   `SPEND_METER_TEST_MYSQL_DSN` is set; CI runs it against a MySQL service.
   Reason: no SQLite dependency, fast unit runs, and the SQL is still tested.
2. **Unpriced models.** Denied by default whenever any cap is set (a site
   cap, any purpose cap of the site, or a network cap). The setting
   `allow_unpriced` (default off) lets them through; the row is flagged
   `unpriced_allowed`, recorded at zero cost, and a warning is logged (once
   per provider and model per ten minutes). With no cap set anywhere,
   unpriced calls are allowed and flagged the same way, because there is
   nothing to protect; the first-run notice and the widget warning still say
   "no prices loaded". Reason: the owner's ruling; the default stays closed
   wherever the operator has asked for a limit.
3. **Record return.** `spend_meter_record()` returns an int: the row id,
   0 when the write was buffered for a retry, -1 when the call array is
   invalid. No separate cost getter: the row id and `spend_meter_recorded`
   (which carries the whole row) give callers the cost. Reason: as the PRD
   states.
4. **Network caps.** The network total is cached for 60 seconds in the
   object cache, and in a site transient when there is no persistent cache.
   A check adds its own estimate to the cached total. Only when that would
   deny is the total recomputed live (and the cache refreshed) before the
   decision. Reason: the network sum crosses every site and is the most
   expensive query; recomputing on the deny path means a stale cache can
   never cause a false deny. Accepted cost: the network cap can be passed by
   up to a minute of other sites' spend. Site and purpose totals are always
   live (lesson 14.11).
5. **Seed file.** `data/prices.json` holds the format only: schema 1,
   `USD`, `as_of` null, an empty `prices` array. No price is shipped,
   quoted, or used in tests: fixtures use invented providers (`acme`,
   `router`, `example-provider`) and invented numbers, and a test checks
   that every JSON file in the repository names only invented providers.
   `docs/prices.example.json` uses placeholders that fail import on
   purpose. The settings screen also accepts a pasted price file, with the
   same validation and dry run. Reason: prices are data with a date, owned
   by the operator (lesson 14.5).
6. **Reservation lifetime.** 15 minutes by default (setting, 1 to 120),
   filterable through `spend_meter_reservation_lifetime` (minutes, clamped
   to 1 to 120). Not per intent: the sum excludes reservations older than
   one global lifetime, and a per-intent lifetime would need a stored expiry
   per row. An hourly cron lane marks old reservations `expired`, and
   `wp spend-meter reservations expire` does the same on demand. Reason: the
   owner's ruling; a filter covers sites with long batch calls.
7. **REST route.** None in 0.1.0. The widget is server-rendered; WP-CLI and
   the admin screen are the interfaces. A read-only route is a roadmap item
   in the README. Reason: the owner's ruling; less surface to secure.

### Decisions taken while building

- **`reservation_id` column.** The PRD's table has `call_id` (unique, the
  caller's idempotency key) but nowhere to keep the reservation id once the
  caller's `call_id` is written on settle, and the PRD says the two are never
  the same. A nullable, indexed `reservation_id CHAR(36)` column was added.
  The reservation row and every linked retry carry it; lookup is "the row
  with this reservation id and no parent". Adding a column is not a breaking
  change under PRD 5.1.
- **Covering indexes.** Measured on a test box (2 vCPU, a 32 MB InnoDB
  buffer pool, shared with another database), with 256,000 rows of which
  about 21,000 fall in the current month for the site: the totals query took
  about 700 ms with the PRD's indexes because every row in the month needed a
  lookup, and about 100 ms once the index covered the query. So
  `blog_month_purpose` keeps the PRD's leading columns and adds `is_test`,
  `day`, `status`, `created_at`, `estimate` and `cost`; the network sum uses
  a covering `month_totals` index. The PRD's 5 ms budget is not met for
  ledgers with tens of thousands of rows per site per month on hardware like
  that; a per-day rollup table is the roadmap item. Timings will differ on
  a production database with a normal buffer pool.
- **Pattern caps count the pattern.** A purpose cap on `shadow*` caps the
  total of every purpose that starts with `shadow`, not each purpose alone.
  The scope string is `purpose:<blog_id>:shadow*`.
- **Cap arithmetic.** Deny when the total (including this call's estimate)
  is greater than the limit, or when the limit is zero. `remaining` is the
  smallest `limit - total` across the caps checked, never below zero.
  `cap_reached` fires when a total reaches its limit or a cap denies;
  `cap_warning` fires at the threshold, and also when a cap is reached and
  the warning has not fired in that window.
- **Alerts after a record.** A record checks thresholds only when the row
  cost more than its reservation (or had none) and a site or purpose cap
  applies. The common path, settling at or under the estimate, stays one
  write.
- **Options are network-wide.** There is one ledger per network, so caps,
  kill switches, settings and price overrides are one set per network: a
  network option on multisite, a normal option on a single site. Site caps
  are keyed by blog id inside the caps option. Only `spend_meter_settings`
  autoloads. Denial counters use one more option, `spend_meter_denials`.
- **Multisite screens.** Each site's screen edits that site's caps and
  purpose caps (capability `manage_options`, filterable). The Network
  Settings screen edits kill switches, settings, network caps and prices
  (`manage_network_options`). A single site gets everything on one screen.
- **Timezone.** Day and month keys use the current site's timezone at write
  time; an intent with another `blog_id` still uses the current site's
  timezone. Settling a reservation keeps the reservation's day and month, so
  a call that crosses midnight stays on the day its reservation counted.
- **Rounding.** Each cost component (input, cached, output, reasoning,
  units) is rounded half up to the nearest nano separately, then summed.
  Every multiplication is overflow-checked; an amount that does not fit is
  an `invalid_request`.
- **Unknown reservation with no identity.** A record that carries a
  well-formed but unknown `reservation_id` and no purpose, caller, provider
  or model is still written, with `unknown` in the missing fields and the
  unreserved flag, because the money was spent. Without any reservation id,
  missing identity keys make the record invalid (-1).
- **`error_unbilled` cost source.** Stored with cost zero and
  `cost_source = provider` (the provider billed nothing), so unbilled errors
  do not count in the unpriced warning.
- **Usage parser notices.** The parser returns a `notices` list as well as
  the keys in PRD 4.3 (adding a key is not breaking).
- **Dry runs write nothing.** No reservation, no denial counter, no alert
  state.
- **Kill switch check before validation.** The global switch denies before
  the intent is validated, so a stopped site writes nothing and does no work
  at all; the purpose switch applies once the purpose is a valid slug.
- **Price filter shape.** `spend_meter_price` receives and returns the
  compiled price array (integer nanos: `input`, `output`, and optionally
  `cached_input`, `reasoning`, `unit_price`, `per_call_fee`). A returned
  array without integer `input` and `output` is ignored and logged.
- **Currencies.** A fixed list of common ISO 4217 codes is accepted for the
  ledger and for price files.
- **Schema readiness.** The db version option is written only after every
  column is verified with `SHOW COLUMNS`. While it is missing, every check
  denies with `ledger_unavailable` and admin pages show which columns are
  missing; `admin_init` and every WP-CLI command retry the install. This is
  also how must-use installs create the table.
- **Logging.** `error_log` lines start with `spend-meter:` and are limited
  to one per kind per ten minutes, tracked per request and in a transient.
- **No `@`, no `eval`, no shell, no outbound HTTP.** All SQL goes through
  `$wpdb->prepare()`; table and column names are constants.

## Testing

- `composer test` runs PHPUnit 10. The unit suite needs no WordPress and no
  database. Constant tests (`SPEND_METER_KILL` and friends) run in separate
  processes because a constant cannot be undefined.
- `SPEND_METER_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=test"`,
  `SPEND_METER_TEST_MYSQL_USER` and `SPEND_METER_TEST_MYSQL_PASSWORD` run
  the integration suite against a real server. It creates and drops its own
  table.
- Each PRD acceptance test is named by number in the test method, for
  example `testT11InterleavedChecksNeverBothAllowPastTheCap`.

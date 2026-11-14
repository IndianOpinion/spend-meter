# spend-meter: product requirements

Status: draft for build, 14 November 2026. Package: WordPress plugin
`spend-meter`, Composer name `indianopinion/spend-meter`, namespace
`IndianOpinion\SpendMeter`. This document is the only input the builder
needs. Names in it are invented for this project and form its public
contract.

## 1. Problem

A site that calls language, embedding, image or speech models through
several plugins spends money on every call, and nothing in WordPress says
how much, on what, or when to stop.

What goes wrong without a shared meter:

- Each plugin logs usage in its own shape, or not at all. Nobody can answer
  "what did we spend today, and on what?" without reading several tables.
- Caps live inside each plugin. A loop in one plugin can spend the whole
  month's budget before its own cap, if it has one, notices.
- Price tables are hard-coded in plugin source. When a provider changes a
  list price, the code keeps the old figure. A model missing from the table
  is priced by a silent default, so the ledger is wrong without any warning.
- Usage fields differ by API shape (`input_tokens` and `output_tokens` in
  one, `prompt_tokens` and `completion_tokens` in another, cached and
  reasoning tokens nested as subsets). Callers that read only one shape
  record zero tokens for the other.
- Callers that work out "what did my call cost" by summing the shared table
  between two row ids before and after the call count other processes'
  rows too, whenever two requests run at once.
- A cap checked only after the call lets every concurrent process through
  at once, and a per-process running total goes stale across processes.

Measured result from the work this came out of: once the refusal for a
retired purpose moved in front of the request, and every call was recorded
per attempt, the ledger showed zero rows for that purpose from the same day.
Before, retired calls were still being made and billed.

spend-meter fixes this with one ledger, one price table, one set of caps,
one kill switch, and one pre-spend filter that every plugin calls before it
spends.

## 2. Goals and non-goals

Goals:

1. Record every model call, per attempt, with tokens, cost, currency,
   purpose, calling plugin, provider, model, status and latency.
2. Decide before a call whether it may spend: allow, or deny with a
   machine-readable reason. Kill switches and caps are enforced here.
3. Caps per site and per purpose, daily and monthly, plus network caps on
   multisite.
4. A global kill switch and a per-purpose kill switch, settable from the
   admin screen, WP-CLI, or a constant that the admin screen cannot undo.
5. A configurable price table, loaded by the operator from a file or the
   admin screen with an "as of" date and source per row, updatable without
   a code release, and versioned so old rows are never silently repriced.
   The plugin ships the file format and no prices.
6. A dashboard widget showing today, this month, caps, top purposes, and
   warnings (kill switch on, unpriced models, recent errors).
7. WP-CLI commands for status, reports, caps, kill switches, prices,
   reservations and export.
8. A stable public contract (filters, actions, functions, table schema)
   that other plugins can build on, with semantic versioning.

Non-goals:

- Making model calls. spend-meter never sends a request to a model
  provider. It does not hold API keys.
- Queueing, retry, cooldown or failover. That belongs to the caller or to a
  queue plugin.
- Fetching prices from the internet. Prices change by operator action only
  (import a file, set a row). No outbound HTTP at all.
- Currency conversion. One ledger currency per install.
- Billing reconciliation with a provider's invoice. The ledger is an
  operational meter; it can be exported for reconciliation.
- Storing prompts, completions or any user content.

README requirement: the README of this repository carries this exact line
in its first section: "Written from scratch; not derived from any
third-party plugin." It also carries the brief's sentence that the project
came out of the work on indianopinion.org and is independent of any
third-party plugin it integrates with.

## 3. Users and setting

- **Site operator.** Installs the plugin, sets caps, watches the widget,
  flips the kill switch. Has `manage_options` (filterable).
- **Plugin author.** Calls the pre-spend filter before every billable call
  and the record action after it. May run without spend-meter installed;
  the contract is written so absence is detectable and harmless.
- **On-call engineer.** Uses WP-CLI at 2 a.m. to see what is spending and
  to stop it.

Where it runs: a normal WordPress install, single site or multisite, as a
regular plugin or as a must-use plugin (loader stub documented in the
README). Model calls happen in web requests, cron, WP-CLI and background
workers, often at the same time.

## 4. Behaviour

Terms used below:

- **Call**: one billable request to a model provider. A retry is a new call.
- **Purpose**: a short slug the caller chooses for what the call is for,
  for example `summary`, `translate`, `embed`. Caps and kill switches attach
  to purposes.
- **Caller**: the slug of the plugin that makes the call.
- **Day** and **month**: calendar day and month in the site timezone
  (`wp_timezone()`), fixed at the time the row is written.
- **Window total**: the sum of settled costs plus live reservations for a
  scope (site, purpose, network) in a window (day, month).
- **Reservation**: a ledger row with status `reserved` created by an
  allowed pre-spend check, holding the estimated cost until the call is
  recorded or the reservation expires.

### 4.1 Pre-spend check

1. A caller applies the filter `spend_meter_pre_spend` with `null` as the
   value and an intent array (section 5.2). spend-meter returns a decision
   array: `allowed` (bool), `reason` (code), `message` (text),
   `reservation_id` (string or null), `estimate` (cost in nanos),
   `remaining` (smallest remaining headroom across the caps checked, or
   null when no cap applies), `api` (the contract version, section 5.1).
   Every deny also returns these keys: `allowed` false, `reservation_id`
   null, `estimate` the computed estimate (0 when the intent was invalid or
   no estimate could be made), `remaining` 0 for a cap deny and null
   otherwise. spend-meter ignores any non-null value passed in and always
   overwrites it. Unknown intent keys are ignored.
2. If spend-meter is not active, the filter returns `null`. Callers treat
   `null` as "no meter present" and fall back to their own cap and kill
   switch. spend-meter never relies on callers having their own guards, and
   callers never rely on spend-meter being present.
3. If the global kill switch is on (constant or option), deny with
   `kill_switch`. No reservation, no database write beyond the denial
   counter.
4. If a per-purpose kill switch matches the purpose, deny with
   `kill_switch_purpose`. Purpose kill entries are exact slugs or a trailing
   `*` prefix pattern (`shadow*`). No other pattern syntax.
5. If the intent is invalid (a missing required key, purpose or caller not
   matching `^[a-z0-9][a-z0-9_.-]{0,63}$`, provider not matching the same
   pattern, model empty or longer than 128 characters, negative or
   non-numeric numbers, `meta` outside its limits), deny with
   `invalid_request`. Integer keys accept an integer or a string of digits;
   floats, booleans and other strings are invalid. Never truncate
   silently. Provider and model are lower-cased for price lookup and
   stored as given. The `message` names the first bad key.
6. If the model has no price row and the caller gave no `estimate_override`,
   apply the unpriced policy: `deny` (default) denies with `unpriced_model`;
   `allow` allows, records the estimate as zero, and flags the row.
7. Compute the estimate: `est_input_tokens` times the input price, plus
   `max_output_tokens` times the output price, plus `est_units` times the
   unit price, plus the per-call fee. If the caller gave `estimate_override`
   (nanos), use the larger of the override and the computed estimate. With
   no `max_output_tokens`, use the configured default output allowance
   (section 7).
8. Insert a reservation row with the estimate, then read the window totals
   for every applicable cap: site day, site month, purpose day, purpose
   month, and on multisite network day and network month. The new
   reservation is included in the totals.
9. If any total exceeds its cap, delete the caller's own reservation and
   deny with the most specific reason, in this order: `cap_purpose_day`,
   `cap_purpose_month`, `cap_site_day`, `cap_site_month`,
   `cap_network_day`, `cap_network_month`. The message names the cap, the
   limit and the current total.
10. Otherwise allow with `reason` `ok` and the reservation id.
11. Concurrency: the reserve-then-sum order means two processes that race
    each see the other's reservation. Both may deny; neither overshoots.
    This is deliberate: a false deny costs one retried call, an overshoot
    costs money. No named locks are used.
12. A cap of zero denies every call in that scope. An empty cap means no
    cap.
13. After spend-meter's own decision, the result passes through
    `spend_meter_decision` (every decision, allow or deny) so a site can add
    rules (office hours, a role check). A filter turns an allow into a deny
    by returning the array with `allowed` false; spend-meter then sets
    `reason` to `denied_by_filter`, keeps a string `message` if one was
    set, sets `reservation_id` to null and releases the reservation. A filter that turns a deny into an allow is
    ignored and logged once per request, because caps and kill switches
    are not overridable from code paths other plugins control.
14. A `dry_run` intent runs every check and returns the decision without
    writing a reservation. WP-CLI `check` uses it.
15. A `test` intent (sandbox or local endpoint) is validated like any
    other, and is always allowed unless a kill switch is on, even when
    unpriced or over a cap. It creates a reservation with `is_test = 1`
    (unless `dry_run` is also set) so the later record settles it. The
    estimate is computed when a price exists and is 0 otherwise. Test rows
    never count toward caps or the widget totals, and an unreserved test
    record does not raise the unreserved warning. Lesson: a test endpoint
    must never cool or cap the live path.

### 4.2 Recording a call

16. After every attempt, billed or not, the caller fires the action
    `spend_meter_record` with a call array (section 5.3), or calls
    `spend_meter_record()` to get the row id back. A call array that fails
    validation (missing required key, bad slug, status outside `ok`,
    `error_billed`, `error_unbilled`, negative numbers) is not written; the
    function returns -1 and the problem is logged. A caller may fire the
    record for a `dry_run` decision's attempt: it is then an unreserved
    record.
17. If the call carries a `reservation_id` that exists and is still
    `reserved`, or has been marked `expired` and not yet settled (the call
    ran longer than the lifetime), that row is settled in place: status,
    tokens, cost and latency are written, and the reservation is gone. Any
    `provider`, `model` or `purpose` given in the call replaces the
    reservation's value; the reservation's `model` is kept as
    `fallback_from` when the call names a different model and gives none.
    One row per attempt.
18. A second record for the same reservation (a retry reusing the id)
    inserts a new row linked by `parent_id` and does not touch the first.
    Each attempt has its own row, as lesson 14.3 requires.
19. A record without a reservation (the caller skipped the check, or
    spend-meter was activated mid-request, or the reservation id is unknown
    or was `released`) is inserted as a new row with `unreserved = 1`. The
    widget counts these as a warning. Money was spent, so such a record is
    never dropped.
20. Cost source, in this order:
    1. `provider_cost` given by the caller (the provider reported the
       billed amount): store it, `cost_source = provider`.
    2. Tokens and units priced from the active price row:
       `cost_source = table`, `price_version` stored.
    3. No usage at all, but a reservation exists: store the reservation
       estimate, `cost_source = estimate`, flag `usage_missing`. Caps stay
       conservative.
    4. No price row and no provider cost: `cost = 0`,
       `cost_source = unpriced`. The row is counted in the unpriced warning.
21. When both a provider cost and a table price exist, store the provider
    cost and also store the table cost in `cost_table` so drift between
    list price and billed price is visible in reports.
22. Status is one of `ok`, `error_billed` (the provider returned usage but
    the result was unusable: malformed output, missing fields, schema
    failure), `error_unbilled` (transport failure, HTTP 4xx or 5xx with no
    usage), `released` (a reservation given up without a call), `expired`
    (a reservation that timed out), `reserved`.
23. `error_billed` rows count toward caps. `error_unbilled` rows store cost
    zero and count only in error statistics. Lesson: a 200 with a broken
    body is still billed.
24. Account-level errors (the caller passes `http_status` 401, 402 or 403)
    are stored as `error_unbilled` with `error_code` set, whatever `status`
    the caller gave. The widget shows
    the last one ("provider refused: out of credit or bad key") so the
    operator sees it without reading logs.
25. Fallback: if the caller fell back to another provider or model, the
    row records the provider and model that were actually billed, plus
    `fallback_from` (the model first tried). One row per attempt, so the
    failed first attempt has its own row.
26. Recording never throws into the caller and never blocks it. On a
    database error the function returns 0, and the call is written to a
    bounded in-memory buffer (100 calls per request) and
    retried at `shutdown`; if that fails, one `error_log` line per ten
    minutes and a counter option record the loss.
27. Recording is idempotent on `call_id`. If the caller supplies a
    `call_id` (UUID) and a row with it exists, the second record is ignored
    and the existing row id is returned. A reservation id is never the
    `call_id`: a retry that reuses a reservation id with a new `call_id`
    makes a linked row (case 18).
28. A record for a purpose that is kill-switched is still written. The
    ledger records what happened; the kill switch only stops new spend.

### 4.3 Usage parsing helper

29. `spend_meter_usage_from_response()` accepts a decoded provider response
    body and returns normalised counts: `input_tokens`,
    `cached_input_tokens`, `output_tokens`, `reasoning_tokens`,
    `provider_cost`, `usage_missing`.
30. It understands both common shapes: `input_tokens`/`output_tokens` with
    `input_tokens_details.cached_tokens` and
    `output_tokens_details.reasoning_tokens`, and
    `prompt_tokens`/`completion_tokens` with
    `prompt_tokens_details.cached_tokens` and
    `completion_tokens_details.reasoning_tokens`. If both shapes appear, the
    first is used and a notice is added.
31. Cached and reasoning tokens are subsets of input and output. They are
    never added on top. Pricing applies the cached-input price to the
    cached part and the input price to the rest; reasoning tokens are priced
    as output tokens unless the price row lists a separate reasoning price.
32. A `usage.cost` number in the body becomes `provider_cost` (converted to
    nanos). Non-numeric or negative values are ignored with a notice.
33. Embedding responses with no output tokens give `output_tokens = 0`.
34. Missing `usage` gives zeros and `usage_missing = true`, never an error.
35. Unknown extra fields are ignored. The helper never reads message
    content.

### 4.4 Caps

36. Caps are stored per scope: site (per blog id), purpose (per blog id and
    purpose slug), network (multisite only). Each has an optional day limit
    and an optional month limit, in the ledger currency.
37. Purpose caps may use the same trailing `*` prefix pattern as kill
    switches. When several patterns match, every matching cap applies.
38. Changing a cap takes effect on the next check. Lowering a cap below the
    current total denies new calls; nothing already recorded changes.
39. A warning threshold (default 80 percent) per cap marks the widget amber
    and fires `spend_meter_cap_warning` once per scope per window.
40. Crossing a cap fires `spend_meter_cap_reached` once per scope per
    window, with the scope, window, limit and total. Sites hook mail or chat
    alerts to it; spend-meter sends no mail itself. Both cap actions fire
    from the pre-spend check (on a deny, or when an allow brings the total
    to the threshold) and from a record that settles a row past the
    threshold. "Once" is tracked in the option `spend_meter_alert_state`,
    keyed by scope and window key, and pruned after two windows.
41. Window boundaries follow the site timezone at check time. A row stores
    its own `day` and `month` when written, so a timezone change mid-day
    does not move old rows between days. DST changes need no special case.

### 4.5 Kill switches

42. The global kill switch is on when the constant `SPEND_METER_KILL` is
    defined and true, or the option is on. The constant wins and the admin
    screen shows the switch locked.
43. Per-purpose kill switches are a list of slugs and prefix patterns,
    settable from the admin screen and WP-CLI, and also from the constant
    `SPEND_METER_KILL_PURPOSES` (comma-separated), which the screen cannot
    remove.
44. Flipping a kill switch fires `spend_meter_kill_switch_changed` with the
    old state, new state, scope and the user id or `cli`.
45. Kill switches never cancel a call already in flight. They stop the next
    pre-spend check.

### 4.6 Reservations

46. A reservation expires after the reservation lifetime (default 15
    minutes). Expired reservations stop counting toward caps at once (the
    sum excludes `reserved` rows older than the lifetime) and are marked
    `expired` by an hourly cron task and by WP-CLI.
47. A caller that decides not to make the call fires
    `spend_meter_release` with the reservation id, or calls
    `spend_meter_release()`. The row becomes `released` with cost zero.
48. Releasing an unknown or already settled reservation is a no-op with a
    debug log line, never an error to the caller. Recording is never a
    no-op for these (cases 18 and 19), because the money was spent.

### 4.7 Price table

49. Prices live in a price table: one row per provider and model, with
    input price, cached-input price, output price, optional reasoning price
    (all per million tokens), optional unit price and unit name (image,
    second, character, request), optional per-call fee, currency,
    `as_of` date and `source` URL.
50. The plugin ships a seed file, `data/prices.json`, that carries the file
    format and no prices: `schema` 1, `currency`, `as_of` null and an empty
    `prices` array. It is a valid file, so a fresh install has an empty
    effective table and every model is unpriced until the operator imports
    prices (case 55) or sets rows. No price from any provider is shipped,
    quoted in the README or written in the tests (fixtures use invented
    provider and model names and invented numbers). A documented example
    file, `docs/prices.example.json`, shows the format with placeholder
    strings that fail import validation on purpose, so nobody imports it
    unread. The README explains where to read list prices (the provider's
    own pricing page), that they change, and that the operator must check
    and date every row. First-run guidance: an admin notice and a widget
    warning say "no prices loaded" while the effective table is empty.
51. Operator overrides are stored separately from the seed. The effective
    price for a model is the override if one exists, otherwise the seed
    row. Upgrading the plugin replaces the seed file and never touches
    overrides.
52. Each effective price has a `price_version`: a short hash of the row's
    contents. Every ledger row priced from the table stores it. Changing a
    price never rewrites old rows.
53. Lookup is by exact `provider` and `model`, both lower-cased. If no
    exact row matches, a row whose model ends in `*` matches by prefix
    (longest prefix wins). There is no global default price: lesson 14.4.
54. A model routed through an aggregator is priced by the row for that
    aggregator's model id; if the aggregator reports the billed cost, that
    wins anyway (case 20).
55. Importing a price file validates every row (non-negative decimals with
    at most 9 places, ISO date, http or https source, known currency). One
    bad row rejects the whole file with a list of errors. A dry run prints
    the diff against the current effective table.
56. Import modes: `merge` (default, adds and replaces rows as overrides)
    and `replace` (the file becomes the whole override set).
57. A price in a currency other than the ledger currency is rejected.
58. The admin screen lists effective prices with their source (seed or
    override), `as_of` date, and an age warning when older than the
    staleness threshold (default 120 days). An empty table shows the "no
    prices loaded" notice instead.
59. `reprice` fills cost for `unpriced` rows only, once a price exists, and
    marks them `cost_source = table_late`. It never changes any other row.

### 4.8 Dashboard widget

60. The widget shows: today's total and this month's total against the site
    caps (bars, numbers, percent), the top five purposes today by cost, the
    kill switch state, and warnings: no prices loaded, unpriced rows today,
    unreserved rows today, the last account-level error, recording losses, a stale price
    table, and live reservations older than half their lifetime.
61. Only users with the configured capability see it.
62. Figures come from a summary cached for 60 seconds in the object cache
    or a transient. A "refresh" link with a nonce clears it.
63. Test rows are excluded. Currency is shown with the ledger currency code
    and two decimal places; values under one cent show as "< 0.01".
64. On multisite the network admin dashboard shows the network widget with
    a per-site breakdown.

### 4.9 Settings screen

65. One screen under Settings (Network Settings on multisite for network
    caps): kill switches, site and purpose caps, warning threshold,
    unpriced policy, default output allowance, reservation lifetime,
    currency, retention, and the price table with import and export.
66. Every save checks the capability and a nonce, validates each field,
    and shows errors next to the field. Invalid input is never saved
    partially.
67. Changing the ledger currency is allowed only while the ledger has no
    rows, because there is no conversion.

### 4.10 WP-CLI

68. Every command in section 5.6 exists, returns exit code 0 on success and
    1 on error, supports `--format=table|json|csv` where it lists rows, and
    supports `--url` on multisite as usual.
69. `kill on` and `kill off` work even when the admin screen is
    unreachable. `kill off` refuses, with a clear message, when the
    constant holds the switch on.

### 4.11 Retention and export

70. Rows are kept indefinitely by default. A retention setting in months
    (0 means forever) lets a daily cron task remove settled rows older than
    that. Reservations are never removed by retention while live.
71. Before any retention removal the task writes nothing else and removes
    in batches of 1,000 rows to keep locks short.
72. `export` writes CSV or JSON lines for a date range, without any content
    beyond the ledger columns.

### 4.12 Failure modes

| Failure | Behaviour | Open or closed, and why |
|---|---|---|
| Ledger table missing or unreadable during a check | Deny with `ledger_unavailable` | Closed: spending without a meter is the problem this plugin exists to stop |
| Reservation insert fails | Deny with `ledger_unavailable` | Closed, same reason |
| Settings option corrupt or unreadable | Use defaults, keep kill switch constants, log once | Closed for kill switches (constants still apply), open for caps only if the operator set none |
| Price table option corrupt | Fall back to the seed file (empty), log once, widget warns | Closed by default: models are unpriced until the operator re-imports |
| Seed file missing or invalid | Every model is unpriced; unpriced policy applies | Closed by default (policy `deny`) |
| Record database write fails | Buffer and retry at shutdown; then log and count | Never block the caller: the money is already spent |
| Object cache down | Read totals from the database | Correctness does not depend on the cache |
| Clock or timezone misconfigured | Rows use the site timezone as configured; the CLI `status` prints it | Visible, not guessed |
| A filter on `spend_meter_decision` throws | Catch, deny with `decision_filter_error`, log | Closed |
| Cron not running | Expired reservations still stop counting (case 46); widget warns when the last cron run is older than two hours | Caps stay correct |

## 5. Public interface

Stability: everything in this section is the public contract from 1.0.0.
Before 1.0.0, minor versions may change it with a CHANGELOG entry.

### 5.1 Detecting the meter and the contract version

- The constant `SPEND_METER_API` is an integer holding the contract
  version. It is `1` in 0.1.0. It only ever increases, by one, on a
  breaking change, including a breaking change made before 1.0.0. A
  breaking change is: removing or renaming a hook, function, key, column
  or reason code, changing a type or the meaning of a value, or adding a
  required intent or call key. Adding an optional key, a reason code, a
  hook or a function is not breaking. The plugin version is a separate
  string constant, `SPEND_METER_VERSION`.
- The constant is defined when the plugin file loads, before
  `plugins_loaded`, and every function and hook in this section is
  registered at the same moment. A caller that runs on `plugins_loaded` or
  later can rely on them. A caller that runs earlier must treat the meter
  as absent.
- Detect with `defined('SPEND_METER_API')`. A caller built for contract `N`
  works while the constant equals `N`. If it is greater, read the CHANGELOG
  before assuming; if it is lower, treat the meter as absent. Every
  decision array also carries the same number in `api`.
- Callers should use only the filters and actions when they want zero
  coupling; the functions are conveniences with the same behaviour.

### 5.2 Pre-spend filter

`apply_filters( 'spend_meter_pre_spend', null, array $intent ): ?array`

Intent keys:

| Key | Type | Required | Meaning |
|---|---|---|---|
| `purpose` | string | yes | Slug, `^[a-z0-9][a-z0-9_.-]{0,63}$` |
| `caller` | string | yes | Calling plugin slug, same pattern |
| `provider` | string | yes | Provider slug, lower case |
| `model` | string | yes | Model id as sent to the provider, up to 128 chars |
| `est_input_tokens` | int | no | Default 0 |
| `max_output_tokens` | int | no | Default from settings |
| `est_units` | number | no | For unit-priced models |
| `estimate_override` | int | no | Caller's own estimate in nanos |
| `blog_id` | int | no | Default current blog |
| `dry_run` | bool | no | Check without reserving |
| `test` | bool | no | Sandbox call, not counted |
| `meta` | array | no | Up to 8 scalar key/value pairs, keys `^[a-z0-9_]{1,32}$`, values up to 191 chars, stored as JSON. No content. |

Decision keys, always all present on a non-null result: `allowed` (bool),
`reason` (string code), `message` (string, for logs, never for end users),
`reservation_id` (string UUID, null unless allowed and not `dry_run`),
`estimate` (int nanos), `remaining` (int nanos, or null when no cap
applies), `api` (int contract version).

Caller protocol: if the result is `null`, no meter is present; use the
caller's own guards. If `allowed` is not exactly `true`, do not make the
call. If it is `true` and `reservation_id` is a string, then exactly one of
`spend_meter_record` or `spend_meter_release` must follow for that id.
Pass the id back as `reservation_id`. A decision for a `dry_run` or a
denied check holds nothing.

Reason codes: `ok`, `kill_switch`, `kill_switch_purpose`,
`invalid_request`, `unpriced_model`, `cap_purpose_day`,
`cap_purpose_month`, `cap_site_day`, `cap_site_month`, `cap_network_day`,
`cap_network_month`, `ledger_unavailable`, `denied_by_filter`,
`decision_filter_error`. New codes may be added in minor versions; callers
must treat any unknown code as a deny.

`message` text is not a contract; only the reason codes are. A caller
reads `allowed` first and `reason` second.

Function form: `spend_meter_check( array $intent ): array` (same result,
never null, same filters applied). It exists only when the plugin is
active.

### 5.3 Record action

`do_action( 'spend_meter_record', array $call )`

Call keys:

| Key | Type | Required | Meaning |
|---|---|---|---|
| `reservation_id` | string | no | From the decision |
| `call_id` | string | no | Caller UUID for idempotency |
| `purpose`, `caller`, `provider`, `model` | string | yes when no reservation | As in the intent; with a reservation they default to its values |
| `input_tokens`, `cached_input_tokens`, `output_tokens`, `reasoning_tokens` | int | no | Default 0 |
| `units` | number | no | For unit-priced models |
| `provider_cost` | int | no | Billed cost in nanos, if the provider reported it |
| `status` | string | yes | `ok`, `error_billed`, `error_unbilled` |
| `http_status` | int | no | Last HTTP status |
| `error_code` | string | no | Up to 64 chars |
| `latency_ms` | int | no | |
| `attempt` | int | no | 1-based attempt number |
| `fallback_from` | string | no | Model first tried |
| `test` | bool | no | |
| `meta` | array | no | As in the intent |

The action returns nothing. Function form: `spend_meter_record( array $call ): int`
returns the row id (settled or inserted, or the existing row for a
duplicate `call_id`), 0 when the write failed and the call was buffered
for a retry, or -1 when the call array is invalid and nothing was written.
It never throws. `status` values `released` and `expired` are set only by
spend-meter and are invalid here. Unknown call keys are ignored.

### 5.4 Other actions and filters

| Hook | Kind | Arguments | Purpose |
|---|---|---|---|
| `spend_meter_release` | action | `string $reservation_id` | Give up a reservation. Function form `spend_meter_release( string $reservation_id ): bool`, true when a live reservation was released, false for a no-op |
| `spend_meter_decision` | filter | `array $decision, array $intent` | Add site rules; can only deny. Must return the decision array; any other return value is treated as an exception (`decision_filter_error`) |
| `spend_meter_recorded` | action | `int $row_id, array $row` | After a row is written or settled. `$row` is keyed by the ledger column names in section 6 |
| `spend_meter_cap_warning` | action | `string $scope, string $window, int $limit, int $total, string $window_key` | Threshold crossed. `$scope` is `site:<blog_id>`, `purpose:<blog_id>:<slug or pattern>` or `network`. `$window` is `day` or `month`. `$window_key` is `YYYY-MM-DD` or `YYYY-MM`. Limit and total are nanos |
| `spend_meter_cap_reached` | action | same | Cap crossed, same argument shapes |
| `spend_meter_kill_switch_changed` | action | `string $scope, bool $old, bool $new, string $actor` | Switch flipped from the screen or CLI (never for a constant). `$scope` is `global` or `purpose:<slug or pattern>`. `$actor` is the user id as a string, or `cli` |
| `spend_meter_capability` | filter | `string $cap` | Default `manage_options` |
| `spend_meter_default_settings` | filter | `array $defaults` | Change any default in section 7 |
| `spend_meter_price` | filter | `?array $price, string $provider, string $model` | Last-chance price lookup override |
| `spend_meter_widget_rows` | filter | `array $rows` | Add lines to the widget |

### 5.5 Helper functions

- `spend_meter_usage_from_response( array $body ): array` (section 4.3).
- `spend_meter_estimate( array $intent ): int` estimate in nanos without
  any checks; 0 when the model is unpriced and no override is given.
- `spend_meter_totals( string $scope, string $window, ?string $purpose = null, ?int $blog_id = null ): int`
  current window total in nanos, settled plus live reservations, tests
  excluded. `$scope` is `site`, `purpose` or `network`; `$window` is `day`
  or `month`; `$purpose` is required for `purpose` and an exact slug.
  An invalid argument returns 0 and logs.
- `spend_meter_format( int $nanos ): string` money for display.
- `spend_meter_kill_active( ?string $purpose = null ): bool`, true when the
  global switch is on, or when `$purpose` is given and matches a purpose
  entry.

Money is always an integer number of nanos (one billionth of the ledger
currency unit) in the API and the table. This keeps sums exact. Prices in
files and screens are decimal strings per million tokens; conversion uses
string arithmetic, never floats.

Classes (namespace `IndianOpinion\SpendMeter`): the builder chooses the
internal structure, but `Ledger`, `PriceTable`, `Caps`, `KillSwitch`,
`Decision` and `UsageParser` are expected, each testable without
WordPress.

### 5.6 WP-CLI

Command root: `wp spend-meter`.

| Command | Does |
|---|---|
| `status` | Kill switches, today and month totals against caps, live reservations, unpriced rows today, last error, timezone, oldest effective price date (or "no prices loaded") |
| `report [--from=<date>] [--to=<date>] [--by=purpose\|model\|caller\|provider\|day] [--include-tests]` | Totals and token counts grouped |
| `check --purpose=<p> --caller=<c> --provider=<x> --model=<m> [--in=<n>] [--out=<n>]` | Dry-run decision |
| `caps list` / `caps set <scope> [<purpose>] [--day=<amount>] [--month=<amount>]` / `caps unset ...` | Manage caps; scope is `site`, `purpose` or `network` |
| `kill on\|off [--purpose=<p>]` / `kill status` | Kill switches |
| `prices list [--stale]` / `prices set <provider> <model> --in=<x> --out=<y> [--cached=<z>] [--reasoning=<r>] [--unit=<u> --unit-price=<p>] [--fee=<f>] --as-of=<date> --source=<url>` / `prices unset <provider> <model>` / `prices import <file> [--mode=merge\|replace] [--dry-run]` / `prices export [--overrides-only]` / `prices unpriced` | Price table |
| `reprice [--from=<date>] [--to=<date>] [--dry-run]` | Fill unpriced rows only |
| `reservations list` / `reservations expire` | Inspect and expire stale reservations |
| `export --from=<date> --to=<date> [--format=csv\|jsonl]` | Write the ledger to stdout |
| `prune --older-than=<months> [--dry-run]` | Same rule as retention, on demand; prints counts first |

Amounts on the command line are decimal strings in the ledger currency.

### 5.7 Options and constants

Options (all under one prefix `spend_meter_`, autoload off except the
settings array): `spend_meter_settings`, `spend_meter_caps`,
`spend_meter_kill`, `spend_meter_prices_override`,
`spend_meter_db_version`, `spend_meter_alert_state`, `spend_meter_last_cron`,
`spend_meter_record_losses`. Network options for network caps on multisite.

Constants: `SPEND_METER_KILL` (bool), `SPEND_METER_KILL_PURPOSES`
(string), `SPEND_METER_API` (int, defined by the plugin),
`SPEND_METER_DISABLE_WIDGET` (bool).

## 6. Data

Table `{base_prefix}spend_meter_ledger`, one per network, created with
`dbDelta` on activation and on upgrade when `spend_meter_db_version`
differs:

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `call_id` | CHAR(36) | UUID; unique |
| `parent_id` | BIGINT UNSIGNED NULL | Earlier attempt of the same reservation |
| `blog_id` | BIGINT UNSIGNED | 1 on single site |
| `created_at` | DATETIME | UTC |
| `day` | DATE | Site-local day at write time |
| `month` | CHAR(7) | `YYYY-MM`, site-local |
| `purpose` | VARCHAR(64) | |
| `caller` | VARCHAR(64) | |
| `provider` | VARCHAR(64) | |
| `model` | VARCHAR(128) | |
| `fallback_from` | VARCHAR(128) NULL | |
| `status` | VARCHAR(16) | Section 4.2 |
| `input_tokens`, `cached_input_tokens`, `output_tokens`, `reasoning_tokens` | BIGINT UNSIGNED | Default 0 |
| `units` | DECIMAL(20,6) | Default 0 |
| `estimate` | BIGINT | Nanos, from the reservation |
| `cost` | BIGINT | Nanos, the amount that counts |
| `cost_table` | BIGINT NULL | Table price when a provider cost was stored |
| `cost_source` | VARCHAR(16) | `provider`, `table`, `table_late`, `estimate`, `unpriced` |
| `currency` | CHAR(3) | |
| `price_version` | CHAR(12) NULL | |
| `http_status` | SMALLINT NULL | |
| `error_code` | VARCHAR(64) NULL | |
| `latency_ms` | INT UNSIGNED NULL | |
| `attempt` | SMALLINT UNSIGNED | Default 1 |
| `flags` | SMALLINT UNSIGNED | Bits: unreserved, usage_missing, unpriced_allowed |
| `is_test` | TINYINT(1) | |
| `user_id` | BIGINT UNSIGNED NULL | Current user if any, 0 for cron and CLI |
| `meta` | TEXT NULL | JSON, section 5.2 limits |

Indexes: unique (`call_id`); (`blog_id`, `day`, `purpose`);
(`blog_id`, `month`, `purpose`); (`day`); (`status`, `created_at`) for
reservation expiry.

After a schema upgrade the plugin verifies every expected column with
`SHOW COLUMNS` and, if one is missing, shows an admin notice and denies
with `ledger_unavailable` until it is fixed (lesson 14.9).

Prices: seed in `data/prices.json` (read-only, shipped, empty `prices`
array, section 4.7 case 50); overrides in the option
`spend_meter_prices_override`. The same shape is the import and export
format. Example shape (placeholders, not prices):

```json
{
  "schema": 1,
  "as_of": "YYYY-MM-DD",
  "currency": "USD",
  "prices": [
    {
      "provider": "example-provider",
      "model": "example-model",
      "input_per_million": "<decimal>",
      "cached_input_per_million": "<decimal or null>",
      "output_per_million": "<decimal>",
      "reasoning_per_million": null,
      "unit": null,
      "unit_price": null,
      "per_call_fee": null,
      "as_of": "YYYY-MM-DD",
      "source": "https://provider.example/pricing"
    }
  ]
}
```

The shipped seed is exactly `schema` 1, `currency` `USD`, `as_of` null and
`"prices": []`. The builder ships no price, not even as a test fixture or
README example. Import validation rejects any value that is not a decimal
string, so the placeholders above cannot be loaded by accident. Each
imported row must carry its own `as_of` and `source`, and the file's
top-level `as_of` is the oldest row date.

Privacy of the data: no prompt, completion, user input or URL of content
is stored. `user_id` is an integer only.

Retention: indefinite by default; section 4.11.

Uninstall (`uninstall.php`): removes all options, network options,
transients and cron events. The ledger table is dropped too, unless the
setting "keep the ledger on uninstall" is on (default off) or the constant
`SPEND_METER_KEEP_LEDGER` is true. The README tells operators to run
`export` first. Deactivation removes cron events only.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Global kill switch | off | Constant wins |
| Purpose kill list | empty | |
| Site day cap | empty (none) | Recommended in the README to set one on install |
| Site month cap | empty | |
| Purpose caps | none | |
| Network caps | none | Multisite only |
| Warning threshold | 80 percent | 1 to 99 |
| Unpriced policy | `deny` | `deny` or `allow` |
| Default output allowance | 4,096 tokens | Used when `max_output_tokens` is absent |
| Reservation lifetime | 15 minutes | 1 to 120 |
| Ledger currency | `USD` | Fixed once rows exist |
| Price staleness threshold | 120 days | Widget and `prices list --stale` |
| Retention | 0 (forever) | Months |
| Keep ledger on uninstall | off | |
| Capability | `manage_options` | Filter |

Every default passes through `spend_meter_default_settings`.

## 8. Security and privacy

- Admin screens, the widget refresh and every form: capability check plus
  nonce. Every input validated against the patterns above; every output
  escaped (`esc_html`, `esc_attr`, `esc_url`).
- Database access through `$wpdb->prepare` only. Table and column names are
  constants in code, never from input.
- No outbound HTTP, no `eval`, no shell, no file writes except WP-CLI
  `export` to stdout and `prices import` reading a path the operator gives.
- Price import reads JSON only, rejects files over 1 MB, and never follows
  URLs in the file.
- Callers are other plugins with full PHP access, so the filter contract is
  not a security boundary against hostile code. It is a guard against
  mistakes. The one hard rule: the decision filter cannot turn a deny into
  an allow (case 13).
- No content is stored. `meta` is bounded and scalar.
- Ledger rows hold cost data, which may be commercially sensitive; the
  export and the screens are capability-gated.
- The plugin holds no API keys and never logs request headers.

## 9. Performance budget

- Pre-spend check: at most one insert and one aggregate query per window
  group (one query returning all site, purpose and network totals for day
  and month), under 5 ms on a ledger of one million rows with the indexes
  in section 6.
- Record: one update or insert, under 2 ms.
- No query at all on front-end page views that make no model call. The
  plugin loads its classes lazily.
- Widget: at most four queries when the cache is cold, none when warm.
- Price lookup: the effective table is built once per request and kept in
  memory; the seed file is read at most once per request.
- Hourly cron: expire reservations in one update. Daily cron: retention in
  batches of 1,000.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. No 8.2+ syntax.
- WordPress 6.4 and later. Single site and multisite.
- Works as a regular plugin and as a must-use plugin through a loader stub.
  As a must-use plugin, activation hooks do not run, so the table is
  created on the first `admin_init` or CLI run when the db version option
  is missing.
- MySQL 5.7+ and MariaDB 10.4+. No JSON column type (TEXT instead).
- Works with and without a persistent object cache.
- Page caches are irrelevant: the plugin outputs nothing on the front end.
- No Composer at runtime. Composer is for development only.
- Callers can integrate without a hard dependency (filters and actions
  only), so group B plugins load fine with spend-meter absent.

## 11. Observability

- Widget (section 4.8) and `wp spend-meter status` are the operator views.
- `report` answers "what spent what" for any range.
- Logging: `error_log` lines prefixed `spend-meter:` for ledger write
  failures, corrupt options, decision filter errors and deny-to-allow
  attempts, each rate-limited to one line per ten minutes per kind.
- With `WP_DEBUG` on, every deny writes one debug line with reason, purpose
  and caller.
- Counters: denials per reason per day (one option, last 14 days kept),
  record losses, unpriced and unreserved rows (derived from the ledger).
- Every row carries `caller`, so a misbehaving plugin is visible by name.

## 12. Acceptance tests

Each test maps to the case number in section 4. PHPUnit with a stub layer
for WordPress functions and an in-memory or SQLite-backed `$wpdb` double.

| Test | Case |
|---|---|
| T1 filter returns a decision array with all keys when active | 1 |
| T2 with the plugin not loaded, `apply_filters` returns null (stub check of the documented contract) | 2 |
| T3 global kill switch denies, writes no reservation | 3 |
| T4 purpose kill by exact slug and by prefix pattern | 4 |
| T5 each invalid intent field gives `invalid_request`, long purpose not truncated | 5 |
| T6 unpriced model denied under `deny`, allowed and flagged under `allow` | 6 |
| T7 estimate maths for tokens, units, fee, override and default allowance | 7 |
| T8 reservation row is included in totals | 8 |
| T9 each cap reason in priority order, own reservation removed on deny | 9 |
| T10 allow returns `ok` and a reservation id | 10 |
| T11 two interleaved checks near a cap never both allow past it | 11 |
| T12 zero cap denies, empty cap does not | 12 |
| T13 decision filter can deny and releases the reservation; deny to allow is ignored and logged | 13 |
| T14 dry run writes nothing | 14 |
| T15 test calls allowed, recorded with `is_test`, excluded from totals; still denied by kill switch | 15 |
| T16 record via action and via function, row id returned; invalid call gives -1 and writes nothing; failed write gives 0 | 16 |
| T17 settling a reservation updates in place | 17 |
| T18 second record on the same reservation adds a linked row | 18 |
| T19 record without, or with an unknown or released, reservation sets the unreserved flag | 19 |
| T20 cost source precedence, all four branches | 20 |
| T21 provider cost and table cost both stored | 21 |
| T22 each status value stored and validated | 22 |
| T23 `error_billed` counts toward caps, `error_unbilled` does not | 23 |
| T24 401, 402, 403 stored and surfaced as last account error | 24 |
| T25 fallback row records the billed model and `fallback_from` | 25 |
| T26 database failure buffers, retries at shutdown, then counts a loss without throwing | 26 |
| T27 duplicate `call_id` is ignored and returns the first id | 27 |
| T28 record for a kill-switched purpose is still written | 28 |
| T29 parser returns all normalised keys | 29 |
| T30 both usage shapes parsed, mixed shape notice | 30 |
| T31 cached and reasoning tokens not double counted, priced correctly | 31 |
| T32 `usage.cost` read, bad values ignored | 32 |
| T33 embedding response gives zero output | 33 |
| T34 missing usage gives zeros and the flag | 34 |
| T35 parser ignores content fields (fixture with content asserts none returned) | 35 |
| T36 site, purpose and network caps stored per blog id | 36 |
| T37 several matching purpose patterns all apply | 37 |
| T38 lowering a cap denies the next call and changes no rows | 38 |
| T39 warning fires once per scope per window | 39 |
| T40 cap reached fires once per scope per window | 40 |
| T41 rows keep their day after a timezone change; day boundary in site timezone | 41 |
| T42 constant kill wins over option, screen shows it locked | 42 |
| T43 purpose kill from constant cannot be removed | 43 |
| T44 kill switch change action fires with actor | 44 |
| T45 a call recorded after a kill switch flips is still settled | 45 |
| T46 expired reservations excluded from totals before cron runs, marked by cron | 46 |
| T47 release sets `released` and zero cost | 47 |
| T48 release of an unknown or settled reservation is a no-op; record of one is never dropped | 48 |
| T49 price row with every field priced correctly | 49 |
| T50 shipped seed validates, has an empty `prices` array and no price anywhere in the repository fixtures; example file fails import on purpose; empty table raises the "no prices loaded" warning | 50 |
| T51 override beats seed; seed replacement keeps overrides | 51 |
| T52 changing a price changes `price_version` and no old row | 52 |
| T53 exact match, longest prefix match, no default price | 53 |
| T54 aggregator model id priced by its own row | 54 |
| T55 one bad row rejects the import with errors; dry run shows diff | 55 |
| T56 merge and replace modes | 56 |
| T57 wrong currency rejected | 57 |
| T58 stale prices flagged; empty table shows the notice | 58 |
| T59 reprice fills only unpriced rows | 59 |
| T60 widget summary values and warnings | 60 |
| T61 widget hidden without the capability | 61 |
| T62 widget cache and nonce-checked refresh | 62 |
| T63 tests excluded, formatting below one cent | 63 |
| T64 network widget per-site breakdown | 64 |
| T65 settings save covers every field | 65 |
| T66 bad nonce or capability rejected; invalid field saves nothing | 66 |
| T67 currency change refused once rows exist | 67 |
| T68 every CLI command, formats and exit codes | 68 |
| T69 `kill off` refused under the constant | 69 |
| T70 retention removes only settled rows older than the limit | 70 |
| T71 retention works in batches | 71 |
| T72 export columns and range | 72 |
| T73 each failure mode row in section 4.12 | 4.12 |
| T74 missing column after upgrade denies with `ledger_unavailable` and shows a notice | section 6 |
| T75 uninstall removes options and table; keeps the table when asked | section 6 |
| T76 `SPEND_METER_API` is defined at load as an integer; hooks exist on `plugins_loaded`; decision carries `api`; every deny carries all decision keys; unknown reason handling documented | 5.1, 5.2 |
| T77 a second plugin using only hooks (check, record, release, cap and kill actions) works against the documented shapes, with the plugin absent and present | 5.1 to 5.4 |

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

1. Test double for `$wpdb`: SQLite through PDO, or a purpose-built
   in-memory fake? The race test (T11) needs real interleaving of insert
   and sum.
2. Default unpriced policy: `deny` is specified, and the shipped table is
   empty, so every call is denied until prices are loaded. The first-run
   notice is specified (case 50). Confirm the notice and the example file
   make first run clear enough, without changing the default.
3. Whether `spend_meter_record()` should return the cost as well as the
   id (an array) or keep a plain int and offer a separate getter.
4. Network caps: computed live from the shared table (specified), or
   cached per minute on large networks? Measure on a million-row fixture.
5. How the README tells operators to find, date and import their own
   prices, and whether `prices import` should also accept a pasted table
   in the admin screen.
6. Should the reservation lifetime be per intent (a long batch call) as
   well as global? If yes, cap it at the global maximum.
7. Whether to ship a small read-only REST route for the widget data, or
   keep everything server-rendered (no REST is the default).

## 14. Lessons from production

Written as rules. Each came from running per-call metering and caps in
production.

1. **Refuse before the request, never after.** A kill switch or retired
   purpose must stop the call in the pre-spend check. Checking after the
   response means the money is already gone.
2. **Never infer your own call's cost from the shared table.** Summing rows
   between two ids picks up every other process writing at the same time.
   Use the reservation id or the returned row id.
3. **Record every attempt.** Retries, fallbacks and failures each bill
   separately. One row per call hides the real count.
4. **No silent default price.** A model missing from the table must show as
   unpriced, loudly. A made-up fallback price makes the ledger wrong with
   no warning.
5. **Prices are data with a date, not code.** A hard-coded table with an
   "update here" comment goes stale the day a provider changes a price. The
   plugin ships the format, and the operator owns the numbers.
6. **Prefer the billed cost when the provider reports it,** and keep the
   table cost beside it so drift is visible.
7. **Read both usage shapes, and treat cached and reasoning tokens as
   subsets.** Reading one shape gives zero tokens for the other; adding
   subsets double counts.
8. **A 200 with a broken body is still billed.** Record usage whenever it
   is present, whatever happened to the output.
9. **Verify schema changes.** A schema upgrade routine can skip a column add
   without an error. Check every column after upgrade and refuse to spend
   until it is right.
10. **Do not truncate identifiers silently.** A purpose cut to fit a column
    merges two purposes into one and breaks per-purpose caps. Validate and
    reject.
11. **Per-process running totals go stale.** A static total in one process
    knows nothing of the others. Read the window total from the database at
    each check.
12. **Cap the reservation, not just the settled cost.** Bounding the
    output allowance bounds what one call can cost, and reserving it stops
    a burst of concurrent calls from all passing.
13. **A test endpoint must never count or cap the live path.**
14. **Account errors are not transient.** An out-of-credit or bad-key
    response must be visible to the operator at once, not retried quietly.
15. **Days belong to the site timezone, stored at write time.** Recomputing
    a row's day later moves spend between days and breaks daily caps.
16. **Every money-spending feature keeps its own cap and kill switch,**
    even with this meter installed. The meter is a second line, not the
    only one.

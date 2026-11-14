# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The contract version `SPEND_METER_API` increases by one on every breaking change.

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Pre-spend filter `spend_meter_pre_spend` and `spend_meter_check()`: global and purpose kill switches, intent validation, price lookup and estimate, reserve-then-sum caps (site, purpose, network; day and month), decision filter that can only deny. Contract version `SPEND_METER_API` 1.
- Record action `spend_meter_record` and `spend_meter_record()`: one row per attempt, settle in place, linked retries, unreserved records, idempotent `call_id`, cost source precedence, account errors, fallback model, shutdown retry buffer.
- Release action `spend_meter_release`, reservation lifetime with an hourly expiry task.
- `spend_meter_usage_from_response()` for both common usage shapes, with cached and reasoning tokens as subsets.
- Price table: shipped format with no prices, operator overrides, import and export with dry-run diff, prefix rows, `price_version`, staleness, `reprice` for unpriced rows.
- Cap alerts `spend_meter_cap_warning` and `spend_meter_cap_reached`, once per scope per window.
- Dashboard widget, network widget, settings screen, admin notices.
- WP-CLI `wp spend-meter`: status, report, check, caps, kill, prices, reprice, reservations, export, prune.
- Retention, uninstall with an option to keep the ledger, must-use loader.

[Unreleased]: https://github.com/IndianOpinion/spend-meter/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/spend-meter/releases/tag/v0.1.0

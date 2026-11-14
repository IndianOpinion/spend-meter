# Contributing to spend-meter

Thank you for helping. This is a small plugin with a public contract that other plugins build on, so changes are reviewed with that in mind.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/spend-meter.git
cd spend-meter
composer install
```

No WordPress install is needed for the tests: they run on a stub layer in `tests/stubs/` and an in-memory ledger.

## Tests, analysis and style

```sh
composer test     # PHPUnit 10
composer stan     # PHPStan level 8
composer cs       # PHP_CodeSniffer, PSR-12
composer check    # all three
```

To run the integration suite against a real database, point it at an empty test database. It creates and drops its own table.

```sh
SPEND_METER_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=spend_meter_test" \
SPEND_METER_TEST_MYSQL_USER=root SPEND_METER_TEST_MYSQL_PASSWORD=secret \
composer test
```

CI runs everything on PHP 8.1, 8.2 and 8.3, plus the MySQL suite. Write code that runs on 8.1: no readonly classes, no DNF types, no `true` type, no constants in traits.

## Rules for changes

- Every behaviour change maps to a case in `docs/PRD.md` and has a test named after it.
- The public contract is in PRD section 5. Removing or renaming a hook, function, key, column or reason code, or changing a type or a meaning, is breaking: bump `SPEND_METER_API` by one and say so in the CHANGELOG.
- Money stays in integer nanos. No floats in pricing.
- No prices from any provider, anywhere: not in the seed, the README or the tests. Use invented providers and numbers.
- No outbound HTTP, no `eval`, no shell, no `@` error suppression. Every SQL value through `$wpdb->prepare()`. Escape every output.
- Record decisions in `docs/ARCHITECTURE.md`.
- Add a line under `Unreleased` in `CHANGELOG.md`.

## Pull requests

- One topic per pull request, with a short description of what and why.
- Tests, PHPStan and PHPCS must pass.
- A sign-off line is not required.

## Reporting bugs

Use the bug report template. For security problems, follow [SECURITY.md](SECURITY.md) instead of opening an issue.

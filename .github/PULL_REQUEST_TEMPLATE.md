## What and why

## PRD cases and tests

Which cases in `docs/PRD.md` this touches, and which tests cover them.

## Checklist

- [ ] `composer check` passes (PHPUnit, PHPStan, PHPCS)
- [ ] Works on PHP 8.1 (no 8.2+ syntax)
- [ ] Public contract unchanged, or `SPEND_METER_API` bumped and the CHANGELOG says why
- [ ] No provider prices anywhere; money in integer nanos
- [ ] Decisions recorded in `docs/ARCHITECTURE.md`
- [ ] `CHANGELOG.md` updated under Unreleased

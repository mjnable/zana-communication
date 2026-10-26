# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.4.0 — 2026-10-26

### Changed

- Factory registrations declare the services they resolve with `uses()`, so the container checks
  their lifetimes at startup (FE-40).
- Requires `mj-zana/core` ^2.4.

## 2.3.0 — 2026-10-26

### Changed

- Tests and tooling (`tests/`, `phpunit.xml.dist`, `phpstan.neon.dist`) are no longer in Composer
  archives, so they stay out of production `vendor/` folders (FE-17).

## 2.2.0 — 2026-10-26

### Added

- Newsletters with double opt-in: `Newsletter`, `Subscriber`, `SubscriberStoreInterface`,
  `DatabaseSubscriberStore` and `InMemorySubscriberStore`. Every newsletter carries
  `List-Unsubscribe` and one-click unsubscribe headers (`mail.newsletter.*`, FE-35).

## 2.1.0 — 2026-10-26

First release: email. Immutable messages, sender rules (From from configuration, visitor
addresses only in Reply-To, no header injection), Symfony Mailer transports configured by DSN,
and `MailRecorder` for tests (`mail.dsn: memory://`).

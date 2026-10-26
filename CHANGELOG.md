# Changelog

All Zana packages share one version number and are released together. A release that did
not change this package is not listed.

## 2.2.0 — unreleased

### Added

- Newsletters with double opt-in: `Newsletter`, `Subscriber`, `SubscriberStoreInterface`,
  `DatabaseSubscriberStore` and `InMemorySubscriberStore`. Every newsletter carries
  `List-Unsubscribe` and one-click unsubscribe headers (`mail.newsletter.*`, FE-35).

## 2.1.0 — 2026-10-26

First release: email. Immutable messages, sender rules (From from configuration, visitor
addresses only in Reply-To, no header injection), Symfony Mailer transports configured by DSN,
and `MailRecorder` for tests (`mail.dsn: memory://`).

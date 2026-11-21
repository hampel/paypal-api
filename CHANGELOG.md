# Changelog

## 0.2.0 (2026-11-22)

- `AccessGrant::SUBSCRIPTIONS_SCOPE` and `canReadSubscriptions()`: reading a subscription needs the
  app's Subscriptions feature, and Transaction Search alone is refused
- docblocks and README: a changed feature reaches a token only once it expires; what a refusal
  answers on each API; what a missing subscription answers; no PayPal app is read-only

## 0.1.0 (2026-11-18)

First release, covering transaction search, captures and refunds, subscriptions and plans, and
webhook signature verification. Every wrapped operation reads.

- `Client`, `Connection` and `Config` over any PSR-18 client, with PSR-17 factories discovered when
  none is passed, and an optional PSR-3 logger
- `Environment::Live` and `Environment::Sandbox`, with no default
- `ClientCredentials`, reusing its token through a `TokenStore`; `AccessToken` for a token obtained
  elsewhere; `Client::verify()` and `AccessGrant::canSearchTransactions()`
- endpoints: `Transactions`, `Captures`, `Refunds`, `Subscriptions`, `Plans`, `Webhooks`,
  `Authorisation`
- `Transactions::each()`, walking any range in 31-day windows and returning the last page, whose
  `endDate` says how far the answer reached; and `TransactionFilter`
- `Money`, keeping PayPal's decimal strings and adding, comparing and counting minor units exactly
- `WebhookNotification`, verifying against the raw body as it arrived
- readonly entities for every response mapped, each keeping what PayPal sent on `raw`
- an exception per status, carrying PayPal's issue codes and debug id

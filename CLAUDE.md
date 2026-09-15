# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this
repository.

## What this package is

`hampel/paypal-api` — a PHP client for the parts of the PayPal REST API that reconciling payments
needs: transaction search and balances, captures and refunds, subscriptions and plans, and webhook
signature verification. Usable from any host application rather than tied to one HTTP library or
framework.

**Nothing in here may depend on `illuminate/*`.** A Laravel integration would be a separate package
depending on this one, and that boundary is what keeps this one usable from an application with no
Laravel in it.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
composer test                                   # phpunit
composer analyse                                # phpstan, level 10
composer format                                 # pint, PSR-12
vendor/bin/phpunit --filter test_name           # one test
vendor/bin/rig                                  # the live-API exercises - see The harness
```

## It reads, and that is a decision

**Every wrapped operation reads.** Signature verification is a POST and changes nothing. Creating
orders, capturing, refunding, cancelling subscriptions and registering webhooks are left out on
purpose: they belong to the application that sells, which already has them, and a reconciliation
client that could refund would be one bug from doing so.

Adding one is a scope change, not an omission to fill in. It also changes the harness, whose guard
design assumes nothing it runs has an effect — see The harness.

**Why not `paypal/paypal-server-sdk`**, PayPal's only maintained PHP SDK, checked at 2.4.0 on
2026-09-15: it is not PSR-18 (APIMatic-generated, over `apimatic/unirest-php` and curl, invisible
to `Http::fake()`), it has no webhook verification, and it installs ten packages and 8 MB including
`symfony/http-foundation`.

## The API this wraps, in five facts

Everything else follows from these, and each is documented at the class that implements it.

**1. There is a specification, and it is the source.** PayPal publishes OpenAPI documents at
`github.com/paypal/paypal-rest-api-specifications`. The four used are
`reporting_transactions_v1.json`, `payments_payment_v2.json`, `billing_subscriptions_v1.json` and
`notifications_webhooks_v1.json`, read at commit `90e8041` (2026-04-07). The token endpoint is in
none of them; it is from PayPal's authentication page.

**2. The credential is a token from a client id and secret, with no refresh token.** The client
credentials grant, Basic-authenticated, form-encoded. An expired or refused token is replaced by
asking again. `TokenStore` exists because a PHP application is many short processes.

**3. Transaction search is bounded three ways**: 31 days a search, three years of history, and up to
three hours before a transaction appears. `Transactions::search()` refuses a wider range;
`Transactions::each()` walks windows.

**4. Amounts are decimal strings**, `{"currency_code": "USD", "value": "10.99"}`. `Money` keeps the
string and does its arithmetic on the digits. A float anywhere between the wire and a caller is a
bug.

**5. A webhook is verified against the bytes PayPal sent.** PayPal documents that a re-serialised
body can fail verification, so `WebhookNotification` keeps the raw body and
`verificationPayload()` splices it in verbatim.

## Documented, not measured

**Nothing about this API has been measured yet.** Every behaviour the code relies on comes from the
specifications or PayPal's reference pages, and the harness was written to settle the list below
against a sandbox app. When an exercise settles one, move it into a `Measured` section with its
date, correct the docblock that states it, and `git grep` the whole tree for the claim — the
stale copy is always in a file you were not editing.

| question | code that assumes an answer | exercise |
|---|---|---|
| a token's real lifetime; whether a second request reissues the same token | `AccessGrant`, `TokenStore` docblock | `token` |
| whether `AccessGrant::TRANSACTION_SEARCH_SCOPE` is the scope a Transaction Search app gets | `canSearchTransactions()` | `token` |
| what a wrong secret answers | `NotAuthenticatedException::oauthError()` | `token` |
| whether 31 days is the limit, and 500 the largest page | `Transactions::MAX_RANGE_DAYS`, `TransactionPage::MAX_PAGE_SIZE` | `transactions` |
| **whether `end_date` is inclusive** | the one-second step in `Transactions::windows()` | `transactions` |
| whether a response's `page` counts from zero | `TransactionPage::$reportedPage` is kept, not trusted | `transactions` |
| **the sign and currency of a fee, and how a foreign-currency payment is laid out** | nothing assumes it; this is the reconciliation's main question | `transactions`, `payments` |
| which offset form dates arrive in | `Cast::datetime()` parses both | `transactions` |
| what a refused Transaction Search looks like | `NotPermittedException` docblock | `transactions` |
| whether a payment row's id is its capture id | nothing yet | `payments` |
| whether `RESOURCE_NOT_FOUND` is what a missing id answers | `NotFoundException::isMissingResource()` | `payments` |
| whether a `T0002` row's reference id is the subscription id, and `custom_field` its `custom_id` | `Subscription` docblock | `subscriptions` |
| whether a plan list is minimal without `Prefer` | `Plans` always sends it | `subscriptions` |
| whether a bad signature answers `200 FAILURE` or an error | `Webhooks::verifySignature()` | `webhooks` |
| whether a genuine notification verifies with the body spliced in | `WebhookNotification` | none — needs a delivered notification |

## The PSR-18 seam is the whole design

The client is injected as `Psr\Http\Client\ClientInterface` with PSR-17 factories, and `Connection`
is the only file that touches HTTP. A host application that requires every outbound request to go
through its own stack writes a small adapter rather than a second PayPal client.

- **A PSR-18 client does not throw on an HTTP status**, only when the request never completed.
  That keeps `RequestException` (never reached PayPal) separate from `ApiException` (PayPal said
  no).
- **The catch is `ClientExceptionInterface`, not `\Throwable`.** Laravel's `StrayRequestException`
  is a plain `RuntimeException` and must reach a consumer's test untouched.
- **One retry, for a 401 only.** A stored token can be revoked before its recorded expiry;
  `ClientCredentials::recover()` forgets it and the request is sent once more. Nothing else is
  retried.
- **The token request is the one request without a bearer.** `Authorisation::token()` sends it
  through `Connection::unauthenticated()` with its own Basic header.

**Do not add `php-http/discovery`.** It is a Composer plugin a consumer may refuse to run;
`Support\Psr17Discovery` finds the common factories by class name instead.

## The bearer token goes only to the configured host

**`Connection::request()` refuses any URI whose scheme, host or port is not the configured
API**, and every request is built there. Webhook event pages are walked by following `next` links
out of response bodies, and the token reads the account's whole transaction history; the check
lives in `request()` rather than only in `follow()` so no caller can route round it.

**No credential is in a URL here**, unlike a query-string key, so URIs are logged and put in
exception messages as they are. Anything that holds a secret withholds it from `__debugInfo()`,
`__toString()` and `jsonSerialize()`: `ClientCredentials`, `AccessToken`, `AccessGrant` (whose
deliberate portable form is `toArray()`), and `WebhookNotification`, whose body carries a payer's
name and email.

**Local webhook verification is not implemented, and the reason is this section.** It means
fetching a certificate from the URL a notification's own header names. Done without checking that
URL, anyone can sign a forged notification with their own certificate; done with a check, it needs
an allow-list of PayPal certificate hosts that nothing here has measured.

## Dependency constraints are load-bearing

`psr/log` is `^1.1|^2.0|^3.0` and `psr/http-message` is `^1.1|^2.0` **on purpose**: a host
application may bundle an old `psr/log` beside a package's `vendor/`, and a narrower constraint puts
a second, signature-incompatible interface on the autoloader. `tests/RecordingLogger.php` is
untyped for the same reason. Neither range is there to be tidied up.

## Structure

```text
src/
  Client.php              entry point; named accessors over endpoint(); verify(); with*()
  Connection.php          the only file that touches HTTP
  Config.php              environment, transaction page size, base URI, ownsUri()
  Environment.php         Live and Sandbox, and their hosts
  ErrorDetail.php         PayPal's error body, and the OAuth one
  Authentication/         ClientCredentials, AccessGrant, TokenStore, AccessToken
  Endpoint/               one class per area; Endpoint is the extension point
  Entity/                 readonly records, each keeping `raw`; Money
  Enum/                   the statuses the specifications enumerate
  Exception/              the hierarchy, split by status
  Filter/                 TransactionFilter
  Request/                WebhookNotification
  Result/                 ApiResponse, TransactionPage, Page, Links
  Support/                Cast, Json, Psr17Discovery
```

## Conventions that are decisions, not style

- **Endpoint helpers carry an `api` prefix** — `apiGet`, `apiObject`, `apiFind` — so an endpoint
  can name its own methods `get()` and `list()`.
- **Every entity keeps `raw`.** A transaction row's shipping, auction, store and incentive sections
  are not mapped and are still there.
- **`Cast` is the only place that reads an untrusted value**, and answers null rather than throwing:
  nearly nothing in a PayPal response is marked required.
- **A date keeps the offset it arrived with.** Transaction search writes dates "in the time zone of
  the account", and which day a payment belongs to is the caller's decision. Dates going OUT are
  UTC with `Z`, whole seconds.
- **`find()` answers null only for a 404 that names a missing resource.** Any other 404 is raised.
- **A failure is raised, never logged.** `LoggingTest` pins it: `find()` catches its own 404, and a
  log line written before the throw would report that ordinary answer as a fault.
- **Refuse client-side only what the specification states without contradiction** — the 31-day
  range, the page sizes, a webhook id that is not alphanumeric. A rejection invented here cannot be
  argued with.
- **Two defaults differ from PayPal's**: transaction search asks for `fields=all`, and for page size
  500. Both are stated where they are set.

## Testing

`tests/StubClient.php` is a PSR-18 client answering from a queue — the seam consumers use is the
seam the suite drives.

**The fixtures are two kinds, and it matters which.** `capture.json`, `refund.json` and
`not-found.json` are PayPal's own examples from the payments specification. Every other fixture was
built from the schema, because the other three specifications carry no response examples and
PayPal's reference pages show placeholders. Their shape is the specification's; their values —
the fee's sign, the conversion rows beside a payment — are illustrations, not observations. Replace
them with real sandbox responses as the harness captures them.

`ReadmeTest` checks every call, class, exception, and prose `Class::method()` the README names.
`WebhooksTest::testTheEventIsSplicedIntoTheVerificationByteForByte()` uses a body no PHP encoder
would reproduce, so a change that decodes and re-encodes it fails.

**A green suite cannot tell you the API behaves this way.** Every fixture encodes the same
assumption the code does. That is what the harness is for.

## The harness

`vendor/bin/rig` runs the exercises in `harness/`. They are not tests: they make real calls and
print what happened for a person to read. Each carries the questions it was written to settle.

**All five read and change nothing** — `token`, `transactions`, `payments`, `subscriptions`,
`webhooks`. What the guards protect is **which account** is read, because a live account's rows are
real payers' names, emails and money:

| layer | what it does |
|---|---|
| rig | withholds `.env` entirely when an agent runs the command |
| the default | `PAYPAL_ENVIRONMENT` defaults to `sandbox`; `live` also needs `PAYPAL_LIVE=1` |
| the agent refusal | `PAYPAL_LIVE` is ignored under an agent unless `PAYPAL_AGENT_MAY_READ_LIVE=1` is on the command line |

**If an exercise fails for want of a credential, that is the guard working.** Do not go looking for
the credentials, do not edit `.env`, and do not pass an override to get past the error. Ask.

**If an exercise that writes is ever added**, it needs its own opt-in and agent override, named
after what it does, as in `hampel/saasu-api`. The table above guards nothing but the environment.

## Version support

PHP 8.3, 8.4 and 8.5. PHPStan runs at level 10 with `phpVersion` spanning the range; keep
`phpstan.neon.dist` in step with the `php` constraint in `composer.json`. CI tests three corners —
the floor with `--prefer-lowest`, the floor, the ceiling — and runs `composer-require-checker` and a
dev-free PHPStan pass as separate checks, because they catch different things.

## Git

Commit freely; never push.

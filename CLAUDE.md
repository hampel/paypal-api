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

**The read-only boundary is this package's, not the credential's.** A sandbox app's token measured
on 2026-09-15 carried thirty scopes, refunds, payouts and subscriptions among them. Nothing about the
token stops it moving money; only the absence of the code does.

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

## Measured

Against a sandbox app, with the harness. Where a docblock says "measured", it means one of these.

### Tokens — `token`, 2026-09-15

- **`expires_in` is about 32400** — nine hours, not the 31668 of PayPal's sample. The run printed
  32399 seconds left moments after issue.
- **A second token request while the first is unexpired answers the same token.** Processes without
  a shared `TokenStore` cost requests, not extra tokens — and `ClientCredentials::forget()` cannot
  force a token with new scopes before the old one expires.
- **`AccessGrant::TRANSACTION_SEARCH_SCOPE` is present** on an app with Transaction Search ticked,
  among thirty scopes. An app without the feature has not been seen, so its absence there is still
  inferred.
- **A wrong secret answers 401 `{"error": "invalid_client", "error_description": "Client
  Authentication failed"}`** and nothing else; `NotAuthenticatedException::oauthError()` reads it.
  The response carries `PayPal-Debug-Id` and no rate-limit header.

### Transaction search, an empty window — `transactions`, 2026-11-18

The first three runs held no rows, because the payments made that morning were newer than PayPal's
reporting data. The fourth, below, answered the fee, layout and date questions.

- **`end_date` is cut back to `last_refreshed_datetime`.** Asked for a window ending at the moment
  of the request, PayPal answered with `end_date` set to `last_refreshed_datetime`, 1.8 hours
  earlier, and no error. A search that ends at "now" has not covered the last hour or more, and the
  response's own `end_date` is the only place that says how much it did cover.
- **500 is the largest page.** `page_size=501` answers 400 `INVALID_REQUEST`, naming `/page_size`.
- **The response's `page` counts from 1**, as the request does, not from zero as the specification
  says.
- Dates in the response's own fields came back as `Z`, echoing the request.
- **31 days and one second is accepted**, on a window ending before the last refresh so nothing cut
  it back: HTTP 200, the range echoed as asked. The documented limit is not enforced at the second;
  how far past it PayPal goes is unprobed. `Transactions::search()` still refuses past 31 days,
  which costs a caller nothing, since `each()` walks windows.
- **The sandbox's reporting data advances in steps, not continuously**: across four runs on
  2026-11-18 `last_refreshed_datetime` was `22:59:59Z`, `23:59:59Z`, `00:29:59Z`, then `01:59:59Z` —
  1.8 to 2.1 hours behind each request. The step and the schedule are not settled.
- **Balances share the same lag.** `v1/reporting/balances` with no `as_of_time` answered with
  `as_of_time` and `last_refresh_time` both at the search's last refresh, not the moment of the
  request — it is the reporting snapshot, not a live balance.

### Transaction search, rows — `transactions`, 2026-11-18, fourth run

Twelve rows from the sandbox business account (AUD primary): Send Money payments in AUD and USD,
one subscription payment from a REST plan and one from a PayPal Standard button, and the
conversions beside them. The raw rows are in `CLAUDE.local.md`'s harness runs.

- **A fee is negative, in the payment's currency, on the payment's own row.** USD 4.00 paid a fee
  of `-0.44` USD; USD 10.00, `-0.64` USD. No row of its own, and the conversion rows carry none.
- **A USD payment into an account that converts it is three rows**: the `T0000` in USD with its
  fee, then a `T0200` taking the net out of USD (`-3.56` USD) and a `T0200` putting it into AUD
  (`5.01` AUD). **Both conversion rows name the payment's `transaction_id` as their
  `paypal_reference_id`** — not the payment's own reference id, which is a different `TXN` id. A
  USD payment the account keeps in USD is one row, and so was every `T0002`.
- **The receiving account's conversion setting decides, and nothing else does.** A payment at
  00:37Z converted although the account already held 667.10 USD and the payer had chosen to send
  USD only. Before the next, at 00:47Z, the business account's setting to convert foreign-currency
  payments automatically was turned off, and that payment stayed USD. The API shows only the
  outcome — the conversion rows, or their absence.
- **A `T0002` row's `paypal_reference_id` is the subscription id, type `RP`** — `I-…` — for a
  REST subscription and for one made by a Standard button alike.
- **`custom_field` carries a REST subscription's `custom_id`. A Standard button's `custom` does
  not arrive anywhere** in the row: `custom_field` is absent, and neither `cart_info` nor
  `transaction_subject` holds it.
- **A Standard button's `item_name` lands in `transaction_subject`**; a REST subscription's
  subject is the plan's name. The cart item carries quantity and amounts and no name in either.
- **`GET /v1/billing/subscriptions/{id}` knows a Standard-button subscription**: `ACTIVE`, with
  billing info and `next_billing_time`, but no `plan_id` and no `custom_id`. Its amounts come back
  as `"10.0"` — one decimal place, not the currency's two.
- **`end_date` and `start_date` are both inclusive**: a one-second window ending, and one
  starting, on a row's own initiation second each found it.
- **The rows' dates are `Z`**, on a sandbox account; the documented "time zone of the account" has
  not been seen to differ from UTC.

## Documented, not measured

Every other behaviour the code relies on comes from the specifications or PayPal's reference pages,
and the harness was written to settle the list below against a sandbox app. When an exercise
settles one, move it into Measured with its date, correct the docblock that states it, and
`git grep` the whole tree for the claim — the stale copy is always in a file you were not editing.

| question | code that assumes an answer | exercise |
|---|---|---|
| whether a token requested after the app's features change carries the new scopes before the old token expires | `AccessGrant`, `NotPermittedException` docblocks | none — needs a dashboard change |
| how a fee and a foreign-currency payment look on a captured order rather than Send Money | nothing assumes it | `payments` |
| what a refused Transaction Search looks like | `NotPermittedException` docblock | `transactions` |
| whether a payment row's id is its capture id | nothing yet | `payments` |
| whether `RESOURCE_NOT_FOUND` is what a missing id answers | `NotFoundException::isMissingResource()` | `payments` |
| what a failed subscription charge and its retry leave behind — a transaction row, a status, or only `failed_payments_count` on the subscription | `FailedPayment` docblock | `subscriptions` |
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

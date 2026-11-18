# PayPal REST API client for PHP

[![Tests](https://github.com/hampel/paypal-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/paypal-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/paypal-api.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/paypal-api.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/paypal-api.svg?style=flat-square)](https://github.com/hampel/paypal-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/paypal-api.svg?style=flat-square)](https://packagist.org/packages/hampel/paypal-api)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the parts of the [PayPal REST API](https://developer.paypal.com/api/rest/) that
reconciling payments needs, built on **PSR-18**: transaction search and balances, captures and
refunds, subscriptions and plans, and webhook signature verification. It reads; it does not take,
refund or cancel anything.

## Installation

```bash
composer require hampel/paypal-api
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle provides both, 7 or 8:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client as Guzzle;
use Hampel\PayPal\Api\Client;
use Hampel\PayPal\Api\Environment;

$paypal = Client::withClientCredentials($clientId, $secret, Environment::Sandbox, new Guzzle());

foreach ($paypal->transactions()->each(new DateTimeImmutable('-90 days'), new DateTimeImmutable()) as $row) {
    echo $row->id, ' ', $row->eventCode, ' ', $row->amount, ' fee ', $row->fee, ' ', $row->customField, "\n";
}
```

The long form names everything:

```php
use Hampel\PayPal\Api\Authentication\ClientCredentials;
use Hampel\PayPal\Api\Config;

$paypal = new Client(
    new Config(Environment::Live),
    new ClientCredentials($clientId, $secret, $tokenStore),
    new Guzzle(),
    logger: $logger,                 // PSR-3, optional
);
```

**There is no default environment.** Sandbox and live are separate hosts with separate apps,
credentials and transactions, and a client says which it means.

## Authentication

A REST app's client id and secret, from the Apps & Credentials page of PayPal's developer
dashboard, are exchanged for a bearer token that lasts nine hours. `ClientCredentials` obtains
one on the first request, reuses it until it expires, and replaces it when PayPal refuses it.

**Pass a `TokenStore` for anything longer-lived than one process.** The default store lasts as long
as the object, so a web application would request a token on every page view that touches PayPal.
Implement the three-method interface over a cache; `AccessGrant::toArray()` and
`AccessGrant::fromArray()` are the portable form.

**The app decides which account is read** — a REST app belongs to one PayPal account — and **which
APIs it may use.** Transaction search in particular needs **Transaction Search** ticked in the
app's features, and the token's scope says whether it was:

```php
$grant = $paypal->verify();          // one token request, or none if the store has one

$grant->canSearchTransactions();     // was Transaction Search enabled when this token was issued?
$grant->scopes();
```

**A change to the app's features may take until the token expires to show.** PayPal answers a
repeated token request with the same unexpired token, so `ClientCredentials::forget()` makes the
client ask again but may get the old scopes back. A token lasts nine hours.

## Transaction search

```php
use Hampel\PayPal\Api\Enum\TransactionStatus;
use Hampel\PayPal\Api\Filter\TransactionFilter;

$filter = TransactionFilter::make()->inCurrency('USD')->withStatus(TransactionStatus::Success);

$page = $paypal->transactions()->search($from, $to, $filter);          // one page, at most 31 days
$rows = $paypal->transactions()->each($from, $to, $filter);            // any range, every page
$rows = $paypal->transactions()->withId('5TY05013RG002845M', $from, $to);

$balances = $paypal->transactions()->balances();
$balances->inCurrency('AUD')?->available;
```

### Ranges, lag and history

**One search spans at most 31 days**, so `search()` refuses a wider range before sending it and
`each()` walks any range in 31-day windows, page by page, fetching only as far as it is consumed.
PayPal documents two more limits that shape a reconciliation:

- **A transaction takes up to three hours to appear.** Look back over time already covered rather
  than starting where the last run stopped. PayPal cuts a search's end back to its last refresh
  without saying so, so how far an answer reached is the page's `TransactionPage::$endDate`, not the
  end asked for. `each()` returns its last page once the loop has finished:

  ```php
  $rows = $paypal->transactions()->each($from, $to);

  foreach ($rows as $row) {
      // ...
  }

  $rows->getReturn()->endDate;            // how far PayPal's data reached
  ```
- **History goes back three years**, and no further.

### Filters

`TransactionFilter` builds the optional parameters by name. Two defaults are worth knowing:

- **Every section is asked for** — PayPal's own default is the transaction info alone, without the
  payer or the cart. `TransactionFilter::onlyTransactionInfo()` restores it.
- **Only balance-affecting rows are listed**, which is PayPal's default. A pending or denied payment
  is not one; `TransactionFilter::includingNonBalanceAffecting()` lists those too.

`TransactionFilter::amountBetween()` takes minor units — `500, 1005` is $5.00 to $10.05 — as
PayPal's parameter does.

### Reading a row

A row carries the transaction info flattened — `id`, `eventCode`, `status`, `amount`, `fee`,
`invoiceId`, `customField`, `referenceId` — with `payer` and `cartItems` mapped, and everything else
PayPal sent on `raw`.

**A payment is often more than one row.** A payment in a currency the account converts arrives
beside currency conversion rows, which name the payment's `id` as their `referenceId`. The fee is on
the payment's own row, negative and in the payment's currency. The event code
classifies each row:

| method | event codes |
|---|---|
| `isPayment()` | `T00nn` — payments received or sent |
| `isSubscriptionPayment()` | `T0002` |
| `isFee()` | `T01nn` — a fee that is a row of its own |
| `isCurrencyConversion()` | `T0200` to `T0202` |
| `isReversal()` | `T11nn` |
| `isRefund()` | `T1107` |

**A transaction id is not unique** in PayPal's reporting: one id can appear once as balance-affecting
and once not. **`customField` is the merchant's own reference**, set at checkout, and is how a
payment is traced back to whatever sold it — a payer's PayPal email is often not the address they
gave the merchant.

## Amounts

**Amounts are `Money`, and their values stay strings.** PayPal writes them as decimal strings, and a
float would lose exactness a reconciliation depends on. Arithmetic is done on the digits:

```php
use Hampel\PayPal\Api\Entity\Money;

$net = $row->amount->plus($row->fee);            // 110.00 USD plus -4.62 USD is 105.38 USD
$row->amount->minorUnits();                      // 11000
$row->fee->absolute()->value;                    // "4.62"
$capture->breakdown->gross->equals(Money::of('110', 'USD'));
```

`Money::plus()` refuses two currencies — converting is a rate PayPal decides — and
`Money::minorUnits()` refuses an amount that is not a whole number of them rather than round it.
`Money::toFloat()` exists for handing an amount to something that takes nothing else.

## Captures and refunds

```php
$capture = $paypal->captures()->get('74L756601X447022Y');

$capture->breakdown->gross;                      // in the payer's currency
$capture->breakdown->paypalFee;
$capture->breakdown->net;
$capture->breakdown->receivable;                 // the net converted, when the account holds another currency
$capture->breakdown->exchangeRate;
$capture->customId;

$refund = $paypal->refunds()->get('1JU08902781691411');

$refund->breakdown->paypalFee;                   // the fee PayPal returned, which need not match the capture's
$refund->captureId();                            // from the refund's "up" link - there is no field
```

`find()` on either answers null for an id PayPal does not know, where `get()` raises.

## Subscriptions

```php
$subscription = $paypal->subscriptions()->get('I-BW452GLLEP1G', ['plan']);

$subscription->customId;
$subscription->billing->nextBillingAt;

$payments = $paypal->subscriptions()->transactions('I-BW452GLLEP1G', $from, $to);

foreach ($paypal->plans()->each() as $plan) {
    echo $plan->name, ' ', $plan->regularCycle()?->fixedPrice, "\n";
}
```

A subscription's payments also appear in transaction search as `T0002` rows whose `referenceId` is
the subscription's id. That holds for a subscription made by a PayPal Standard button too, and
`get()` reads one — but it has no plan and no `customId`, and the button's `custom` appears nowhere
in the API.

## Webhooks

```php
use Hampel\PayPal\Api\Request\WebhookNotification;

$notification = WebhookNotification::fromServerRequest($request);    // or fromHeaders($_SERVER, file_get_contents('php://input'))

if ($paypal->webhooks()->verifySignature($notification, $webhookId)) {
    $event = $notification->event();
    $event->eventType;                           // PAYMENT.CAPTURE.COMPLETED
    $event->customId();
}
```

**The body is passed to PayPal exactly as it arrived.** PayPal signs the bytes it sent, and
documents that a body decoded and re-encoded can fail verification — so `WebhookNotification` keeps
the raw body and splices it into the verification request. Build one from the raw input, never from
an array a framework has already parsed.

- **`$webhookId` is the id of the webhook the notification was sent to**, which the notification
  does not carry. `$paypal->webhooks()->list()` shows them.
- **A `FAILURE` answers false; a refused request raises.** "PayPal would not check" is not "the
  signature is bad".
- **Events from PayPal's webhook simulator cannot be verified** by postback, PayPal documents, so a
  simulator test of a verifying handler fails by design.
- **A replayed notification carries a valid signature.** `WebhookNotification::transmittedAt()` is
  there for a handler that refuses old ones.

To find an event a handler missed, `$paypal->webhooks()->events()` reads one page and
`$paypal->webhooks()->eachEvent()` every page, and `$paypal->webhooks()->event()` reads one by id.

## Errors

Every exception implements `ExceptionInterface`.

| exception | when |
|---|---|
| `RequestException` | PayPal never answered — DNS, TLS, a timeout |
| `ValidationException` | 400 — the request is malformed |
| `NotAuthenticatedException` | 401 — the credential or token was refused |
| `NotPermittedException` | 403 — the app lacks the feature, Transaction Search usually |
| `NotFoundException` | 404 |
| `UnprocessableException` | 422 — a business rule refused it |
| `TooManyRequestsException` | 429 |
| `ServerException` | 5xx |
| `ClientException` | any other 4xx |
| `MalformedResponseException` | a success PayPal answered with something unusable — not JSON, or a token response with no token |
| `InvalidArgumentException` | refused before sending — a search over 31 days, a missing webhook header |

**Branch on the issue, not the message.** PayPal's message is the same sentence for every error of a
kind; `issues()` gives the fine-grained codes, and `debugId` is what PayPal's support asks for.

```php
use Hampel\PayPal\Api\Exception\ValidationException;

try {
    $paypal->transactions()->search($from, $to);
} catch (ValidationException $e) {
    $e->errorName();          // INVALID_REQUEST
    $e->issues();             // ['INVALID_PARAMETER_SYNTAX']
    $e->debugId;
}
```

A failure is raised and never logged. Requests are logged at `debug` if a PSR-3 logger is given.

## Extending

For an API this package does not wrap, subclass `Endpoint`; the client constructs and memoises it:

```php
use Hampel\PayPal\Api\Endpoint\Endpoint;

final class Disputes extends Endpoint
{
    public function get(string $id): array
    {
        return $this->apiGet('v1/customer/disputes/' . rawurlencode($id))->object();
    }
}

$paypal->endpoint(Disputes::class)->get('PP-D-27803');
```

`$paypal->connection()` sends anything else. **A request to any host but the configured API is
refused**, so a link out of a response body cannot carry the token elsewhere.

## Not wrapped

**Nothing that moves money or changes an account:** creating orders, capturing, refunding, creating
or cancelling subscriptions, registering webhooks. Those belong to the application that sells, which
already has them; a reconciliation that could refund would be one bug from doing so. Local webhook
verification, which PayPal also documents, is not implemented either — it means fetching a
certificate from a URL the notification names, and doing that safely is a design of its own.

## Testing

```bash
composer check        # lint, analyse, test
```

The suite drives the client through a stub PSR-18 client and needs no network. `vendor/bin/rig`
lists exercises that make real, read-only calls against a sandbox app, to check the API still
behaves as documented.

## Version support

PHP 8.3, 8.4 and 8.5. `psr/log` 1.1 to 3 and `psr/http-message` 1.1 or 2, so a host application
that bundles older interfaces can still install it.

## Licence

MIT. See [LICENSE.md](LICENSE.md).

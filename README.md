# minstrel/api-client

PHP client for the Minstrel public API v1. **Generated** from the API's
OpenAPI spec by `php artisan api:client` in the Minstrel repo —
`src/Resources/*` and `src/MinstrelClient.php` are never edited by hand;
`src/Transport.php`, `src/Page.php` and `src/MinstrelApiException.php` are.

This repository is a **read-only publication** of `packages/minstrel-api-client`
in the Minstrel monorepo. Changes land there and are published here as tags;
pull requests against this repository are not merged.

## Install

PHP 8.2+ with Guzzle 7.

```bash
composer require minstrel/api-client
```

If the package is not on Packagist yet, point Composer at this repository first:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/solorzanorafaelandre/minstrel-api-client" }
]
```

## Use

```php
use Minstrel\ApiClient\MinstrelClient;
use Minstrel\ApiClient\MinstrelApiException;

$minstrel = MinstrelClient::withClientCredentials('https://acme.minstrel.app/api/v1', $clientId, $clientSecret);

$page = $minstrel->accounts()->list(['limit' => 50, 'filter[property_id]' => 3, 'sort' => 'name']);
foreach ($minstrel->accounts()->listAll(['include' => 'contacts']) as $account) { /* every page */ }

$account = $minstrel->accounts()->create(['type' => 'company', 'name' => 'Acme'], idempotencyKey: $myKey);

try {
    $minstrel->folios()->createPostings($folioId, ['transaction_code' => 'RENTAL', 'amount_minor' => 50000], $key);
} catch (MinstrelApiException $e) {
    // $e->status, $e->errorCode (stable), $e->details, $e->requestId
}
```

- Money is integer minor units both ways (`{minor, currency, formatted}` in answers).
- Every write sends an `Idempotency-Key` — a fresh UUID unless you pass yours; pass the same one to retry safely.
- Point it at the **sandbox** tenant to try it without touching real data.
- `MinstrelClient::API_VERSION` is the API version the client was generated
  from; the package's own tags follow semver.

## Tests

`composer install && composer test` (canned answers, no network). The
Minstrel repo also runs the generated client end to end against its sandbox.

## License

MIT — see [LICENSE](LICENSE).

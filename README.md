# PolyPay for Dujiao-Next

Standalone EPay v1 redirect bridge for PolyPay Hosted Checkout. No Dujiao-Next source files, database tables or binaries are changed. This is a separately hosted PHP service, **not** a ZIP that Dujiao-Next can dynamically load.

Status: **0.1.0 preview — protocol tests passed; real host acceptance is still required.** Source compatibility reviewed against Dujiao-Next `41b86260` (main). Other versions must be verified before use.

[中文安装说明](README.zh.md)

## Requirements

PHP >= 7.4 with curl, json, bcmath and sodium; HTTPS; a private writable directory outside the document root. Use a single service instance with persistent local storage. Do not place the store on an ephemeral container filesystem or independently scale instances.

## Installation

1. Extract the complete release into a directory such as `/opt/polypay-dujiao-next`. Set the web document root to its **public/** directory only. Do not expose `src`, `vendor`, `bin`, config or storage over HTTP.
2. Copy `config.example.php` to a private location, e.g. `/etc/polypay-dujiao-next.php`. Set `POLYPAY_BRIDGE_CONFIG` in the PHP-FPM pool to that absolute filename. Keep its permissions restricted to the PHP worker and administrator.
3. Generate an independent EPay key with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. This is the bridge authentication key; it is **not** your PolyPay API Key.
4. Configure the PolyPay API Key, expected merchant ID and environment. Use the current live API Key and `environment=production`. This integration does not offer a Sandbox mode.
5. Configure `base_url` as the bridge HTTPS origin, `notify_url` as the exact Dujiao-Next callback URL, and `return_url` as the exact storefront return URL **without a query string**. Custom callback paths must match on both sides.
6. Set `usd_rate` to USD per one unit of the amount Dujiao-Next submits. Example: `0.14` means 10 submitted currency units become USD 1.40. Set `1` for USD. Prices and rates are snapshotted before order creation; later config changes cannot reprice an existing attempt.
7. Create private persistent `storage_dir` and give only the PHP worker write access. Back it up together with config. Loss of mapping records prevents automatic fulfillment.

### Dujiao-Next admin settings

Create a payment channel with:

| Setting | Value |
| --- | --- |
| Provider | EPay / 易支付 |
| Version | v1 |
| Interaction mode | redirect |
| Gateway URL | bridge origin, e.g. `https://bridge.example.com` |
| Merchant ID | the bridge `pid` |
| Merchant key | the bridge `epay_key` |
| Signature | MD5 |
| Channel type | alipay |
| Notify URL | exact configured `notify_url` |
| Return URL | exact configured `return_url` |
| Display name | PolyPay |

`alipay` is only the existing host's EPay transport identifier. It does not enable Alipay: payment methods are selected on PolyPay Hosted Checkout. Do not enable EPay QR mode; this bridge implements `/submit.php`, not `/mapi.php`. Avoid configuring another currency conversion in Dujiao-Next unless `usd_rate` intentionally accounts for that conversion.

## Docker deployment

`Dockerfile` bundles the required PHP extensions and exposes only `public/`. A configuration file remains outside the image and repository. For a single-instance deployment:

```bash
export POLYPAY_BRIDGE_LISTEN_IP=127.0.0.1
export POLYPAY_BRIDGE_CONFIG_FILE=/etc/polypay-dujiao-next.php
docker compose -f deploy/compose.yml up -d --build
```

The private file must be readable by the container PHP worker (UID 33); use a restricted parent directory and an appropriate group/read permission. Set its `storage_dir` to `/var/lib/polypay-dujiao-next`. Port 18083 serves HTTP behind your HTTPS reverse proxy. Use the public HTTPS domain in `base_url`, never an internal Docker address. The database-free payment mapping persists in `bridge-data`.

## Payment lifecycle

The host signs an EPay redirect; the bridge validates the signature and pinned URLs, creates the PolyPay checkout through the canonical merchant API, and looks up its trade ID. PolyPay v2 Webhooks are verified using the bundled official SDK, the merchant/environment audience and replay protection. Only status 2 (paid) and 7 (admin-marked paid) generate a signed host callback. Status 5 is not accepted as an ordinary paid order. Browser returns never mark payment successful.

Delivery is persisted before the host callback is sent. A failed or timed-out callback returns 503 so PolyPay can retry; the CLI also retries the stored delivery. Acknowledged deliveries are not repeated. A crash after the host commits but before acknowledgement can redeliver; Dujiao-Next remains responsible for transactional fulfillment idempotency.

## Operations

Set the same `POLYPAY_BRIDGE_CONFIG` environment variable for CLI commands:

```bash
php bin/payment.php retry HOST_PAYMENT_ORDER
php bin/payment.php reconcile HOST_PAYMENT_ORDER
php bin/payment.php cancel HOST_PAYMENT_ORDER
```

Use Dujiao-Next's **gateway payment order number**, not an unrelated storefront order ID. Reconcile authenticates to PolyPay and delivers confirmed payments. Cancel queries first, cancels pending states, and queries again. A payment winning a cancellation race must be reconciled for delivery. Confirming payments are not forcibly cancelled.

**Host-side cancellation is not automatically observable through EPay v1.** If a host order is closed or a payment link replaced, the bridge cannot discover that event without a host API or hook. Use the cancel command for that attempt or allow its PolyPay expiry; the old checkout can remain payable in the meantime. Late payments follow the host's payment-exception rules and may need manual handling. This limitation is inherent in the no-source-change integration and must be considered before production.

Schedule monitoring/retries through your existing process manager if needed. There is no built-in worker started by installation.

## Verification

```bash
php tests/run.php
python3 scripts/package.py
```

Tests use mock API/host transports and local Ed25519 keys. They make no live payments. Before enabling customer traffic, first verify order creation and cancellation without payment. With explicit authorization for a small real payment, verify a signed callback and one fulfillment, duplicate/invalid callbacks, changed rates, host delivery failure/retry, order expiry and cancellation. Verify both registered and guest purchases plus wallet recharge on your exact host version. Real-payment end-to-end acceptance has not been completed.

Installation does not call PolyPay plugin activation: the current backend plugin-type allowlist does not include this platform yet.

## SDK provenance

`vendor/polypay` is the unmodified official PHP SDK snapshot `d4f152b85bdd7c7ac210ded8a13eeecd8e3a9e09` from https://github.com/PolyPayAi/php-sdk, including its MIT license. Webhook v2 uses this verifier; HTTP order operations use canonical `/api/v1/pay/order/{checkout,detail,cancel}` endpoints because the snapshot API client uses legacy aliases and its facade discards the returned order context. Shared primitives in `src/Support.php` are also vendored by the independent ACG-Faka adapter; update both copies together.

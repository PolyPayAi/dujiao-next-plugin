---
name: polypay-dujiao-next
description: Install, configure, upgrade, or troubleshoot the native PolyPay payment channel in Dujiao-Next using the versioned host-source patches in PolyPayAi/dujiao-next-plugin. Use for Dujiao-Next checkout, payment-channel configuration, callbacks, reconciliation, or cancellation.
---

# PolyPay for Dujiao-Next

Deliver a native **PolyPay** provider in the host payment-channel dropdown. This integration patches and rebuilds the host; copying a plugin folder alone does not update an existing binary.

## Locate the maintained source

Use the user's plugin checkout and host checkout. If the plugin source is absent, retrieve `https://github.com/PolyPayAi/dujiao-next-plugin` into a new task directory. The native integration and this skill are currently published on `feat/standalone-payments`; inspect remote refs rather than assuming they are on the default branch. Do not overwrite a populated directory.

The installed skill is independent of the repository: paths below are relative to the **plugin checkout**, not to this skill's installation directory.

Read `native/README.zh.md` and `native/manifest.json` before native work. They contain the supported upstream baseline, ordered patch list, SHA-256 checksums, host-source change list, build commands, and rollback procedure. For host changes, read the host's `AGENTS.md` and relevant implementation.

## Apply and record host changes

- Compare the host revision and working-tree status with the manifest. Verify each listed patch hash. Apply the listed patches in order on a dedicated host feature branch after preserving existing changes and deployment data.
- An exact baseline match permits the documented patch workflow. A different host version needs a compatibility review and port; do not force a patch or reset the user's source tree.
- Rebuild both admin and storefront assets, copy them into the documented embed directories, and compile the fullstack binary. Enable Redis: v2 webhook replay prevention fails closed without it. Reuse the existing database, configuration, uploads, Redis, and deployment network.
- Record any additional source changes as commits and ordered patches with an updated baseline/manifest and rollback instructions in the plugin repository. Keep runtime configuration and secrets out of patches and archives.
- Deploy only to the environment authorized by the user. Installation does not imply permission to publish upstream host changes, merge a feature, or deploy another environment.

## Configure through the admin API or panel

Use the existing authenticated admin workflow. Select provider `polypay`, channel `polypay`, interaction `redirect`. Set `api_key`, `merchant_id`, `usd_rate`, `notify_url`, `return_url`, and `force_https`.

- `usd_rate` is USD per one unit of the store's charged currency; `1` is correct for a USD store. Snapshot the original amount, original currency, rate, and submitted USD amount before checkout. Do not apply another conversion during reconciliation.
- Use `/api/v1/payments/webhook/polypay` for notifications and `/pay` for returns. The adapter appends the actual `channel_id` at creation; do not guess a fixed ID.
- PolyPay has **no Sandbox mode**. Merchant APIs and checkout always use production HTTPS. `force_https=false` permits HTTP only for storefront callbacks/returns; public webhooks cannot reach private LAN addresses. Use authenticated server reconciliation for LAN verification.
- Read credentials from the user's private source. Verify API Key expiry and merchant binding without printing the key. Verify persistence and masked admin read-back. Preserve previously authorized choices.
- If the host returns `compliance_required`, report the existing owner declaration gate and obtain the owner's acknowledgment through the normal workflow. Do not bypass middleware or write its database flag.

## Preserve payment semantics

Hosted checkout before payment-method selection has status `0`, empty currency/network, and its USD total in `amount`; `fiat_amount` is zero. Once a method is selected, `amount` is a token quantity and only `fiat_amount` represents USD. A cancelled/expired unselected checkout retains its USD `amount`. Keep this state distinction in create, query, and cancel; never use token quantities to authorize a paid order.

Only authenticated server facts authorize fulfillment. Accept paid statuses `2` and `7`; status `5` is manual recharge, not a storefront purchase. Browser returns do not prove payment. Retain strict v2 signature, merchant/environment audience, freshness, durable nonce, and transactional idempotency checks. Processing failures must return non-2xx.

On local cancellation, expiry, or replacement, cancel the corresponding remote attempt outside database transactions. Query before/after ambiguous cancellation; paid success wins the race. Use the actual trade ID, or recover it using the persisted merchant-attempt number when creation response was lost.

## Verify and report

Use the host's required checks for changed code. For native Go changes, run vet/test with `CGO_ENABLED=1` and the native protocol/adapter race tests. Use the manifest's frontend/fullstack build commands when producing a replacement binary.

For an authorized runtime check, exercise the **host storefront create-and-pay path**, not just the raw PolyPay API. Confirm an HTTPS checkout URL and the expected USD snapshot, then cancel the verification order through the normal host workflow and verify remote closure and stock release. Use a clearly labelled test product; keep credentials and checkout tokens private. Do not pay, mark paid, or resend a production webhook without the user's specific authorization.

Report changed commits/patches, target environment, channel configuration status, checks, and unverified payment steps. Distinguish create/cancel verification from a paid-and-fulfilled test.

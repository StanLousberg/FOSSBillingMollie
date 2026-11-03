# MolliePayments — Project Guide for Coding Agents

This document describes the repository's goals, constraints, architecture and working practices for coding assistants and automated agents. Read it before proposing or changing code.

## Identity and scope

- **Product/project:** `MolliePayments`.
- **GitHub repository only:** `StanLousberg/FOSSBillingMollie`. Never rename the product to `FOSSBillingMollie`; that string exists solely as the repository identifier and in URLs.
- **Target platform:** FOSSBilling (PHP 8.3+, Doctrine ORM/DBAL, Pest).
- **Provider:** Mollie, accessed through the official `mollie/mollie-api-php` SDK, presently constrained to `^4.0`.
- **Project type:** independent gateway adapter plus a companion FOSSBilling extension module.
- **Status:** development scaffold; payment processing is **not implemented** and must remain disabled until properly tested.
- **Language:** English for documentation, code comments, commit messages and UI copy unless a task explicitly requests translations.

## Non-negotiable architecture boundaries

1. Never modify or commit FOSSBilling core or its vendor files. The upstream FOSSBilling checkout is an external test host, not this repository.
2. Keep the gateway adapter in `gateway/MolliePayments.php` (class `Payment_Adapter_MolliePayments`).
3. Keep the module in `module/Molliepayments/` (namespace `Box\Mod\Molliepayments`, manifest ID `molliepayments`).
4. Use module-owned tables/entities for customer references, mandate metadata, recurring preferences, payment attempts and idempotency. Prefer Doctrine for new persistence.
5. Do not depend on modifications to FOSSBilling's installer, cron implementation, invoice internals or admin themes.
6. Keep the module's public API minimal and consistent with FOSSBilling lifecycle/event conventions. Verify actual extension hooks against the FOSSBilling version being tested.

## Credential and connection-test boundaries

- Store Live and Test keys only through FOSSBilling's encrypted gateway secret fields. Preserve native blank-field updates and API masking; do not add a second credential store.
- Resolve saved keys through `ServicePayGateway::getPaymentAdapter()`. Connection actions must require an admin identity and `invoice.manage_gateways`; normal module routing also requires MolliePayments module access.
- Expose one **Test connection** action for our own MolliePayments adapter only. Resolve the gateway server-side and derive the environment from its saved `test_mode`; never accept browser-selected gateway IDs or environments. Do not silently choose between duplicate MolliePayments gateway instances.
- Pass the resolved environment explicitly to the checker and use only that key for `payments->page(null, 1)`, without a `testmode` parameter. Discard payment data and sanitize every SDK error before it reaches host responses or logs.
- Never call Mollie from the adapter constructor. The host validates enabled gateway configuration without callback URLs. Disabled gateway updates bypass that validation, so always validate again before connection testing.
- Connection tests belong on the native extension settings page (`/extension/settings/molliepayments`), with `manage_settings` declared through module permissions. Keep the gateway UI limited to credentials; do not inject scripts or actions into core pages.
- `Support/AdminTwigFactory.php` provides a module template fallback for symlinked installs. Preserve native theme precedence and test with real FOSSBilling Twig filters; never invent compatibility filters in test fixtures.
- Current security contracts are required in addition to the manifest version range. See installation compatibility notes and keep the host integration tests protecting encrypted storage, blank updates and masked responses.

## Responsibilities

- **FOSSBilling** owns invoices, orders, renewals, service state and the authoritative accounting outcome.
- **Mollie** owns payment processing and authoritative provider-side customers/mandates/payment state.
- **MolliePayments** maps both systems safely and persists the minimal locally required state, authorization evidence and audit links.
- Default approach for recurring billing is **invoice-driven**: FOSSBilling creates an invoice; an authorized Mollie recurring payment pays that invoice. Do not silently create Mollie Subscriptions or treat a Mollie mandate as a FOSSBilling Subscription.
- Local mandate management means synchronized metadata and administration workflows. It does **not** mean storing full bank account/card information or becoming the mandate's provider-side authority.

## Security-critical invariants

- Never mark an invoice paid based only on a browser redirect or untrusted webhook body.
- On webhooks, fetch payment state from Mollie using authenticated server-side requests. Match payment ID, internal attempt, invoice, account, amount and currency.
- Guarantee idempotency across webhook redelivery, user refreshes, cron retries and worker concurrency; use database uniqueness and state transitions, not just Mollie idempotency keys.
- Record only the correct, verified outcome through existing FOSSBilling payment/transaction service mechanisms. Avoid duplicate credits or double settlement.
- Recurring billing requires explicit consent, valid mandate state, appropriate payment method and safe retry policies.
- Handle pending, failed, expired, refunded and charged-back states, including async settlement timing.
- Secrets belong in secure runtime settings; never in source, test fixtures, logs, screenshots or GitHub issues.
- Maintain a strict **test-mode vs live-mode** boundary.
- Do not enable `supports_one_time_payments` or `supports_subscriptions` in the adapter until the corresponding end-to-end flows work and tests protect them.

## Source layout

```text
gateway/MolliePayments.php
module/Molliepayments/manifest.json
module/Molliepayments/Service.php
module/Molliepayments/Support/MollieClientFactory.php
module/Molliepayments/Support/Credentials.php
module/Molliepayments/Support/ConnectionChecker.php
module/Molliepayments/Support/SdkUnavailableException.php
module/Molliepayments/Api/Admin.php
module/Molliepayments/Support/AdminTwigFactory.php
module/Molliepayments/templates/admin/mod_molliepayments_settings.html.twig
module/Molliepayments/assets/mollie-icon.png
module/Molliepayments/tests/Unit/
composer.json
docs/INSTALLATION.md
docs/BRANDING.md
README.md
CONTRIBUTING.md
SECURITY.md
.github/workflows/ci.yml
```

`composer.json` installs the official Mollie SDK into `module/Molliepayments/vendor/`. This directory is untracked. The `MollieClientFactory` loads that autoloader lazily. Do not modify FOSSBilling's root Composer dependencies.

## Developer workspace

The expected WSL layout is:

```text
~/dev/MolliePayments-workspace/FOSSBilling/                # external test host
~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments/ # THIS repo
```

The host checkout has local symlinks:

```text
FOSSBilling/src/library/Payment/Adapter/MolliePayments.php
  -> FOSSBilling/.local/MolliePayments/gateway/MolliePayments.php
FOSSBilling/src/modules/Molliepayments
  -> FOSSBilling/.local/MolliePayments/module/Molliepayments
```

Do not add these symlinks or other modifications to upstream FOSSBilling. See [installation documentation](docs/INSTALLATION.md).

## Commands

From this repository, install standalone SDK dependencies:

```bash
composer install
```

From the FOSSBilling test-host root, run tests:

```bash
ddev exec ./src/vendor/bin/pest \
  --test-directory=../tests \
  --configuration=phpunit.xml.dist \
  src/modules/Molliepayments/tests/Unit
```

The `--test-directory=../tests` argument is required because Pest is in `src/vendor/` but FOSSBilling's tests are at `tests/`.

Never require real Mollie API credentials to run unit tests. Use mocked/faked SDK calls and disposable invoice fixtures. For actual API/webhook E2E testing, use only Mollie test mode and a disposable host/database.

## Expected agent workflow

1. Inspect the relevant source and its tests; establish FOSSBilling's current public integration contract before editing.
2. State assumptions and changes to the payment state machine or persistence.
3. Keep changes scoped to this repository; add tests for each behavior and edge case.
4. Run/describe unit tests and style checks. Do not claim tests ran if the environment did not execute them.
5. Maintain `README.md`, installation notes and compatibility instructions when behavior or dependencies change.
6. Verify no credentials or financial personal data were introduced.
7. Use a focused branch and pull request for substantial changes; document unresolved compatibility risks.
8. Do not imply a planned feature is functional or production-safe until verified.

## Attribution and trademarks

Mollie and the Mollie logo are trademarks of Mollie B.V. The round logo is an unmodified asset from Mollie's official PrestaShop extension, not licensed under Apache-2.0; preserve it unchanged. This is an unofficial integration. Read [BRANDING.md](docs/BRANDING.md).

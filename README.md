<p align="center">
  <img src="module/Molliepayments/assets/mollie-icon.png" alt="Mollie round icon" width="64" height="64">
</p>

<h1 align="center">MolliePayments</h1>

<p align="center">
  Standalone Mollie integration for FOSSBilling.
</p>

<p align="center">
  <a href="https://github.com/StanLousberg/FOSSBillingMollie/actions/workflows/ci.yml"><img src="https://github.com/StanLousberg/FOSSBillingMollie/actions/workflows/ci.yml/badge.svg" alt="CI status"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-Apache--2.0-blue.svg" alt="Apache-2.0 license"></a>
  <a href="https://github.com/StanLousberg/FOSSBillingMollie/issues"><img src="https://img.shields.io/github/issues/StanLousberg/FOSSBillingMollie" alt="Open issues"></a>
</p>

> [!IMPORTANT]
> **Early development / not production-ready.** This repository implements one-time Mollie Checkout, authenticated webhooks and native invoice settlement, alongside encrypted credential configuration and connection tests. **Manual Mollie Test checkout and public-webhook validation remain required.** Do not deploy it on a live billing installation. Recurring payments remain disabled.

## About

**MolliePayments** aims to bring Mollie payments to [FOSSBilling](https://fossbilling.org/) as an independently maintained integration, **without modifying FOSSBilling core files**.

The integration consists of a FOSSBilling payment gateway adapter and a companion extension module. FOSSBilling remains responsible for invoices and order renewals; Mollie is the payment provider. The long-term goal is reliable, auditable processing of both one-off payments and invoice-driven recurring payments, including locally managed mandate metadata.

This is an **unofficial open-source project**. It is not affiliated with, sponsored by or endorsed by Mollie B.V. or the FOSSBilling project. Mollie and its logo are trademarks of Mollie B.V. See [branding information](docs/BRANDING.md).

## Project status

| Area | Status |
| --- | --- |
| Gateway and module scaffold | Available |
| Official Mollie PHP SDK v4 | Configured via Composer |
| Separate encrypted Live and Test API keys | Available in gateway settings |
| Read-only API connection tests | Available in the MolliePayments extension settings |
| Development and unit-test integration | Available |
| One-time Mollie Checkout | Implemented for development validation |
| Payment webhooks and invoice settlement | Implemented; manual Test-mode verification required |
| Customers and mandate management | Planned |
| Invoice-driven recurring payments | Planned |
| Verified payment reconciliation | Available through native transactions and CLI |
| Refunds and production release | Planned |

**Not production-ready.** See [one-time payments, setup, recovery and manual testing](docs/PAYMENTS.md). Features marked planned are not implemented.

## Requirements

- FOSSBilling development baseline `97bb583dd681e311e2d10fe8c648095f2a479878` with **PHP 8.3+**; CI also covers current upstream `main`. Published 0.8.9 is unsupported.
- Composer 2, Git and the PHP extensions required by FOSSBilling and the Mollie SDK (including cURL and OpenSSL).
- For the recommended local development workflow: [DDEV](https://ddev.com/) and Docker.
- A [Mollie account](https://www.mollie.com/) and a **test** API key when implementing API calls (not required for automated tests).

The dependency is the [official Mollie PHP API client](https://github.com/mollie/mollie-api-php), constrained to `^4.0`.

## Quick start for developers

Clone FOSSBilling and this project side by side inside the FOSSBilling workspace:

```bash
mkdir -p ~/dev/MolliePayments-workspace
cd ~/dev/MolliePayments-workspace

git clone https://github.com/FOSSBilling/FOSSBilling.git
git clone https://github.com/StanLousberg/FOSSBillingMollie.git \
  FOSSBilling/.local/MolliePayments

cd FOSSBilling/.local/MolliePayments
composer install

cd ../..
ln -s "$(pwd)/.local/MolliePayments/gateway/MolliePayments.php" \
  src/library/Payment/Adapter/MolliePayments.php
ln -s "$(pwd)/.local/MolliePayments/gateway" \
  src/library/Payment/Adapter/MolliePayments

ln -s "$(pwd)/.local/MolliePayments/module/Molliepayments" \
  src/modules/Molliepayments

ddev start
```

> If you already have the workspace from the setup script, **do not clone it again**. Run `git pull` in `.local/MolliePayments`, then run `composer install` there. The existing development symlinks can be reused. If the gateway directory link is missing, add it once as shown above; it exposes all gateway assets automatically.

Run the module tests with the FOSSBilling Pest runner:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev exec ./src/vendor/bin/pest \
  --test-directory=../tests \
  --configuration=phpunit.xml.dist \
  src/modules/Molliepayments/tests/Unit
```

The `--test-directory=../tests` flag is necessary because FOSSBilling installs Pest under `src/vendor/` while its test bootstrap lives in `tests/`.

**Full setup, troubleshooting, deployment boundaries and dependency installation:** [Installation guide](docs/INSTALLATION.md).

### Configure and test API credentials

1. Install/activate the MolliePayments extension and add the MolliePayments gateway under **Payment Gateways**.
2. In the [Mollie dashboard](https://my.mollie.com/), open **Developers → API keys** and obtain standard profile-scoped Test and Live API keys. See [Mollie's key guidance](https://help.mollie.com/hc/en-us/articles/360023732273-What-is-an-API-key). Advanced access tokens are outside this feature's scope.
3. Open **Payment Gateways → MolliePayments**, enter **Live API Key** (`live_`) and/or **Test API Key** (`test_`), then save. Blank password fields preserve the saved value; entering a new value replaces only that credential. Stored keys are encrypted by FOSSBilling and never sent back to the browser.
4. Open **Extensions → MolliePayments → Settings** (the settings icon). Click **Test connection**. The test uses only our MolliePayments adapter and selects its saved Test or Live key from the saved **Test mode** setting. Staff need MolliePayments module access, `molliepayments.manage_settings` and `invoice.manage_gateways`.

The buttons use FOSSBilling's standard extension settings page. The payment adapter only contains the credential fields, labeled **Live API Key (live_xxx)** and **Test API Key (test_xxx)**. No core or theme changes, gateway scripts or separate connection-test route are needed. Save first: connection tests use the saved key, never unsaved input. Disabled gateways can be tested.

The action calls `$client->payments->page(null, 1)` through the official SDK: [`GET https://api.mollie.com/v2/payments?limit=1`](https://docs.mollie.com/reference/list-payments). The adapter's saved **Test mode** setting selects the key; no `testmode` parameter is sent. An empty successful list counts as success. Payment details are discarded. The test does not change the saved mode or enabled state. It cannot target other adapters or override the environment from the browser.

Success confirms authentication and permission to read payments at that moment. It does **not** prove that checkout, settlement, webhooks or recurring billing work. One-time checkout has separate automated and manual validation requirements. See the [installation guide](docs/INSTALLATION.md) for compatibility requirements, setup and troubleshooting.

### Continuous integration

On pushes and pull requests, GitHub Actions checks out **both** the MolliePayments repository and upstream FOSSBilling in separate directories. It installs each project's Composer dependencies, creates an isolated FOSSBilling test configuration (no production database), links the gateway and module into FOSSBilling, and runs the MolliePayments Pest tests through FOSSBilling's own test runner. The CI job does not perform real Mollie API requests or charge payments.

## Repository layout

```text
gateway/MolliePayments.php              FOSSBilling payment adapter
gateway/assets/mollie-icon.png          Gateway branding (served through the directory link)
module/Molliepayments/                  FOSSBilling extension module
  manifest.json                         Extension metadata
  icon.svg                              Inline wrapper containing the original Mollie PNG
  Service.php                           Module schema lifecycle and saved gateway connection service
  PaymentService.php                    Checkout, verification and atomic native settlement
  Entity/MolliePaymentAttempt.php        Module-owned payment identity and audit record
  Repository/AttemptRepository.php       DBAL reservation, uniqueness and checkout leases
  bin/                                  CLI migration, inspection and reconciliation
  Api/Admin.php                         Permission-protected admin API
  templates/admin/                      Native extension settings UI
  Support/AdminTwigFactory.php          Module template fallback for symlinked installations
  Support/ConnectionChecker.php         Read-only SDK request and sanitized feedback
  Support/Credentials.php               Environment and credential validation
  Support/MollieClientFactory.php       Mollie SDK autoloading and client factory
  assets/mollie-icon.png                Round Mollie icon from Mollie's PrestaShop extension
  tests/Unit/                            Pest tests executed in FOSSBilling
docs/INSTALLATION.md                    Development installation guide
docs/PAYMENTS.md                        One-time payment flow, recovery and manual E2E test
docs/BRANDING.md                        Logo attribution and trademark notice
AGENTS.md                               Architecture and AI-agent instructions
CONTRIBUTING.md                         Contribution workflow and standards
SECURITY.md                             Responsible disclosure information
composer.json                           Mollie SDK dependency
```

The GitHub repository is called `FOSSBillingMollie`; **MolliePayments** is the project name and the public-facing integration name.

## Architecture principles

- **No core patches.** Integrate through the gateway and extension interfaces supplied by FOSSBilling.
- **FOSSBilling owns invoice state.** A Mollie response alone must never directly imply that an invoice is settled.
- **Verify webhooks server-side.** Treat webhook payloads as untrusted signals; retrieve payment state from Mollie and validate amount, currency, invoice and ownership before settlement.
- **Be idempotent.** Repeated checkouts, cron runs and webhook deliveries must not create duplicate charges or invoice payments.
- **Recurring payments follow invoices.** Mandates may authorize future payments, but a valid mandate is not itself a paid invoice or a Mollie Subscription object.
- **Keep credentials secure.** Never commit API keys, customer bank details, webhook secrets or production data.
- **Test before enabling payments.** One-time capability is available for development testing. Require automated and manual Test-mode E2E evidence before production use; subscriptions remain disabled.

## Contributing

Contributions are welcome: bug reports, documentation fixes, test cases, accessibility improvements and payment-flow design reviews.

1. Read [CONTRIBUTING.md](CONTRIBUTING.md) and check [existing issues](https://github.com/StanLousberg/FOSSBillingMollie/issues).
2. Fork the repository, create a focused branch and make your changes.
3. Include or update relevant tests. Verify they pass using the FOSSBilling test host.
4. Open a pull request explaining the change, relevant issue(s), and any security or migration impact.

Do not share live API keys, payment identifiers tied to real customers, personal financial data or vulnerability details in public issues. Read [SECURITY.md](SECURITY.md) for security disclosures.

## Resources

- [FOSSBilling source code](https://github.com/FOSSBilling/FOSSBilling)
- [FOSSBilling documentation](https://docs.fossbilling.org/)
- [Mollie Developer documentation](https://docs.mollie.com/)
- [Official Mollie PHP API client](https://github.com/mollie/mollie-api-php)
- [Mollie API test mode](https://docs.mollie.com/docs/testing)

## License

The integration source is licensed under [Apache-2.0](LICENSE). The Mollie PHP SDK has its own license and is installed separately through Composer. Mollie's logo and trademarks are not covered by this project's source-code license; see [BRANDING.md](docs/BRANDING.md).

MolliePayments owns provider communication, verified callbacks and payment-attempt idempotency. FOSSBilling owns balances, credit payments, accounting and activation. No wallet reservations or mixed-credit allocation are implemented. Partially allocated invoices fail closed. See [architecture, legacy data transition and supported development baseline](docs/PAYMENTS.md), [core proposals](docs/FOSSBILLING_CORE_PROPOSALS.md) and [validation evidence](docs/VALIDATION.md).

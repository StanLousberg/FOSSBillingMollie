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
> **Early development / not production-ready.** This repository currently contains a development scaffold and the official Mollie PHP SDK dependency. The payment adapter intentionally has both payment capabilities disabled; **you cannot collect payments with it yet**. Do not deploy it on a live billing installation.

## About

**MolliePayments** aims to bring Mollie payments to [FOSSBilling](https://fossbilling.org/) as an independently maintained integration, **without modifying FOSSBilling core files**.

The integration consists of a FOSSBilling payment gateway adapter and a companion extension module. FOSSBilling remains responsible for invoices and order renewals; Mollie is the payment provider. The long-term goal is reliable, auditable processing of both one-off payments and invoice-driven recurring payments, including locally managed mandate metadata.

This is an **unofficial open-source project**. It is not affiliated with, sponsored by or endorsed by Mollie B.V. or the FOSSBilling project. Mollie and its logo are trademarks of Mollie B.V. See [branding information](docs/BRANDING.md).

## Project status

| Area | Status |
| --- | --- |
| Gateway and module scaffold | Available |
| Official Mollie PHP SDK v4 | Configured via Composer |
| Development and unit-test integration | Available |
| One-time Mollie Checkout | Planned |
| Payment webhooks and invoice settlement | Planned |
| Customers and mandate management | Planned |
| Invoice-driven recurring payments | Planned |
| Refunds, reconciliation and production release | Planned |

**A configured SDK is not a functional payment gateway.** Features marked planned are not implemented.

## Requirements

- A development installation of [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling) using **PHP 8.3 or newer**.
- Composer 2, Git and the PHP extensions required by FOSSBilling and the Mollie SDK (including cURL and OpenSSL).
- For the recommended local development workflow: [DDEV](https://ddev.com/) and Docker.
- A [Mollie account](https://www.mollie.com/) and a **test** API key when implementing API calls (not required for the current smoke tests).

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
ln -s "$(pwd)/.local/MolliePayments/module/Molliepayments" \
  src/modules/Molliepayments

ddev start
```

> If you already have the workspace from the setup script, **do not clone it again**. Run `git pull` in `.local/MolliePayments`, then run `composer install` there. The existing development symlinks can be reused.

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

### Continuous integration

On pushes and pull requests, GitHub Actions checks out **both** the MolliePayments repository and upstream FOSSBilling in separate directories. It installs each project's Composer dependencies, creates an isolated FOSSBilling test configuration (no production database), links the gateway and module into FOSSBilling, and runs the MolliePayments Pest tests through FOSSBilling's own test runner. The CI job does not perform real Mollie API requests or charge payments.

## Repository layout

```text
gateway/MolliePayments.php              FOSSBilling payment adapter
module/Molliepayments/                  FOSSBilling extension module
  manifest.json                         Extension metadata
  Service.php                           Module lifecycle service
  Support/MollieClientFactory.php       Mollie SDK autoloading and client factory
  assets/mollie-icon.png                Round Mollie icon from Mollie's PrestaShop extension
  tests/Unit/                            Pest tests executed in FOSSBilling
docs/INSTALLATION.md                    Development installation guide
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
- **Test before enabling payments.** Keep the adapter flags disabled until the complete flow has adequate automated and live-test coverage.

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

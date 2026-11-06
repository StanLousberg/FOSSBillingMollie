<p align="center">
  <img src="gateway/assets/mollie-icon.png" alt="Mollie round icon" width="64" height="64">
</p>

# MolliePayments

An independent Mollie payment gateway and companion extension for FOSSBilling, using the official Mollie PHP SDK v4. No FOSSBilling core changes are required.

**Development only; not production-ready.** One-time Hosted Checkout, verified webhooks, native invoice settlement, encrypted Test/Live credentials, connection testing and CLI recovery are implemented. Real Test-mode payments and public callbacks have been verified; remaining validation gaps are recorded in [VALIDATION.md](docs/VALIDATION.md). Explicit customer consent, invoice-backed first-payment checkout, mandate verification and read-only onboarding recovery are implemented; see the [client URL and provider limitations](docs/PAYMENTS.md#first-payment-onboarding-issue-8). Internal Customer creation/reuse and CLI Customer recovery remain available; Customer creation, verification, idempotent retry and recovery have been validated against the real Test API using disposable fixtures. Automatic recurring collection, mandate management and refunds are not implemented. `supports_subscriptions` remains false.

MolliePayments owns provider communication and payment-attempt idempotency. FOSSBilling owns balances, invoice pricing/tax, accounting and activation. Existing account credit is never reserved or split by this adapter; unsupported partial invoice allocations fail closed.

Normal invoice checkout automatically offers the Bootstrap authorization page to authenticated owners of an eligible recurring-order invoice, before creating a payment attempt. Acceptance creates a `first` payment scoped to that order; **Pay once without future authorization** creates `oneoff` without a Customer or consent. One-time purchases, standalone/custom invoices, mixed invoices and invoices for multiple orders use ordinary one-time checkout. Hash-authorized guests can pay once but cannot authorize future collection. Automatic recurring collection is still disabled. Real authenticated onboarding POST validation remains blocked by the host session/CSRF defect documented in [VALIDATION.md](docs/VALIDATION.md).

## Development setup

Requirements: PHP 8.3+, Composer 2, FOSSBilling development baseline `97bb583dd681e311e2d10fe8c648095f2a479878`, and a Mollie Test key for real checkout validation. Published FOSSBilling 0.8.9 is unsupported. CI also tests current upstream `main` with SQLite and MariaDB.

1. Follow [INSTALLATION.md](docs/INSTALLATION.md) to prepare the separate host, install SDK dependencies and link the gateway/module. Reuse existing workspaces and DDEV configuration.
2. Configure credentials in the native payment gateway settings and run **Extensions → MolliePayments → Settings → Test connection**.
3. Follow [PAYMENTS.md](docs/PAYMENTS.md) for checkout behavior, limitations, migration precautions and recovery. Use Test mode exclusively during development.

From this repository, `composer install` installs the locked SDK into `module/Molliepayments/vendor/`, independently of the host. Automated tests need no real credentials; commands and results are in [VALIDATION.md](docs/VALIDATION.md).

## Project guide

| Location | Purpose |
| --- | --- |
| `gateway/MolliePayments.php` | Native gateway entry point and branding configuration |
| `module/Molliepayments/` | Checkout, provider verification, attempts, connection settings and CLI recovery |
| `module/Molliepayments/tests/` | Provider fakes with native SQLite accounting and separate MariaDB concurrency tests |
| [AGENTS.md](AGENTS.md) | Permanent architecture and coding rules |
| [Core proposals](docs/FOSSBILLING_CORE_PROPOSALS.md) | Generic host capabilities proposed upstream; not implemented here |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Contribution workflow |
| [SECURITY.md](SECURITY.md) | Private vulnerability reporting |

This unofficial project is not affiliated with or endorsed by Mollie B.V. or FOSSBilling. **MolliePayments** is the product name; `StanLousberg/FOSSBillingMollie` is only the GitHub repository identifier.

Source code is licensed under [Apache-2.0](LICENSE). Mollie's original logo and trademarks are separate from that license; see [BRANDING.md](docs/BRANDING.md).

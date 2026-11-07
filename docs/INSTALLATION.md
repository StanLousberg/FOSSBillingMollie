# Development installation

There is no supported production release. Use a disposable development host and Mollie Test mode. Payment behavior and recovery are documented in [PAYMENTS.md](PAYMENTS.md); test commands, evidence and unverified scenarios are in [VALIDATION.md](VALIDATION.md).

## Supported host and dependencies

- PHP 8.3+ with FOSSBilling's required extensions, including cURL, JSON, mbstring and OpenSSL for this adapter.
- Composer 2, Git, Docker and DDEV for the recommended local setup.
- FOSSBilling development baseline `97bb583dd681e311e2d10fe8c648095f2a479878` (preview version `0.0.1`). CI also tests current upstream `main`.

Published 0.8.9 is unsupported; a greater version number does not guarantee compatibility. The manifest identifies preview version `0.0.1`. Encrypted gateway secrets, masking/blank updates, invoice authorization/locking, transaction claims, deferred events and module-scoped schema synchronization are required. The small runtime contract guard rejects incompatible builds; never patch core or add a plaintext fallback.

The [native reuse audit](FOSSBILLING_NATIVE_REUSE_AUDIT.md) records the installed/main revision `7bc90195a535a03d33b4d0db91f41ba6989dee8a`, the pinned host and stable 0.8.9 (`e594f8a5f91ea4cc905708f8dc1e42ccb752ed21`). Stable lacks the Doctrine invoice/transaction entities and invoice locking required here; it is an explicit incompatibility, not a passing payment target. The original reuse review added no schema conversion. The later development-only DATETIME change below requires fresh module tables.

The official `mollie/mollie-api-php:^4.0` dependency is isolated in this module. Keep `composer.lock` committed and use `composer install`, not `composer update`, for reproducible installations. Never change the host's root Composer dependencies for MolliePayments.

## Workspace

Use two separate repositories. Reuse an existing workspace; do not clone or recreate links over existing paths.

```text
~/dev/MolliePayments-workspace/FOSSBilling/                         external host
~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments/   this repository
```

For a new workspace:

```bash
mkdir -p ~/dev/MolliePayments-workspace
cd ~/dev/MolliePayments-workspace
git clone https://github.com/FOSSBilling/FOSSBilling.git
git -C FOSSBilling checkout 97bb583dd681e311e2d10fe8c648095f2a479878
git clone https://github.com/StanLousberg/FOSSBillingMollie.git \
  FOSSBilling/.local/MolliePayments
cd FOSSBilling/.local/MolliePayments
composer install
```

Composer installs the SDK into `module/Molliepayments/vendor/`, which stays untracked. If local PHP is incompatible, use the existing DDEV host instead:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev exec composer install --no-interaction --working-dir=.local/MolliePayments
```

## Gateway and module links

From the FOSSBilling root, create only links that are missing:

```bash
ln -s "$(pwd)/.local/MolliePayments/gateway/MolliePayments.php" \
  src/library/Payment/Adapter/MolliePayments.php
ln -s "$(pwd)/.local/MolliePayments/gateway" \
  src/library/Payment/Adapter/MolliePayments
ln -s "$(pwd)/.local/MolliePayments/module/Molliepayments" \
  src/modules/Molliepayments
```

The PHP entry point enables adapter discovery. The gateway directory link exposes the original PNG through the standard logo configuration. The module's root `icon.svg` embeds the same unchanged PNG for FOSSBilling's inline icon loader; direct module asset access is not needed. See [branding attribution](BRANDING.md).

Keep these local links out of upstream commits. If necessary, exclude their paths in the host's `.git/info/exclude`, not its tracked `.gitignore`. Do not modify host source, vendor files, themes or webserver rules.

Start the existing DDEV project with `ddev start`. For a new host, complete FOSSBilling's installation using a disposable database. Never reinstall or reset an existing development database to validate this extension.

## Activate and upgrade

Activate MolliePayments under **Extensions** and add its gateway under **Payment Gateways**. Installation creates the module-owned attempt table. For an existing module installation, run from the host root:

```bash
ddev exec php src/modules/Molliepayments/bin/migrate.php /var/www/html/src
```

The migration uses module-scoped, additive schema synchronization. It retains attempts, historical columns and financial records. Review the [legacy wallet transition](PAYMENTS.md#legacy-wallet-transition) before accepting payments on an installation with old reservation data; no automatic release or destructive cleanup is performed. Back up the database before upgrades.

## Credentials and connection test

1. Obtain standard profile-scoped API keys from **Developers → API keys** in the [Mollie dashboard](https://my.mollie.com/). Advanced access tokens are unsupported.
2. Leave the gateway disabled while configuring it. Save the **Test API Key (test_xxx)** and, if needed, **Live API Key (live_xxx)** in the native secret fields. Leave a password blank to preserve its saved value; entering a replacement rotates only that key. Native storage encrypts keys and API responses mask them.
3. Enable **Test mode** for development, plus **Enabled** and **Allow one-time payments** once the schema is installed. Leave subscriptions disabled. Saving configuration never contacts Mollie and does not require checkout URLs. Disabled saves bypass native validation, so connection testing validates saved credentials again.
4. Open **Extensions → MolliePayments → Settings** and click **Test connection**. Staff require module access, `molliepayments.manage_settings` and `invoice.manage_gateways`.

The single action resolves our saved gateway server-side, refuses missing/duplicate instances, and derives its environment from saved Test mode. Save before testing: unsaved browser inputs cannot override the key, environment or gateway. A disabled gateway may be tested without changing its state.

The test calls SDK `payments->page(null, 1)` ([List Payments](https://docs.mollie.com/reference/list-payments)), without `testmode`. Payment data is discarded and errors are sanitized. Success proves read access at that moment; it does not validate checkout or settlement.

The connection interface uses the native extension settings route, permissions, CSRF and Twig filters. Current FOSSBilling's template finder does not follow module-directory symlinks. `AdminTwigFactory` therefore adds our admin/client template directories only when the native loader cannot resolve the template. Native templates and theme overrides retain priority; no core page, theme or permission-discovery replacement is installed.

## Public callbacks and tests

Reuse the existing public HTTPS tunnel and configured FOSSBilling system URL. There is no separate webhook-base setting. Confirm `ipn.php` accepts form POSTs without a tunnel interstitial, login gate or CSRF challenge. Keep the admin panel protected. See [payment validation and tunnel precautions](PAYMENTS.md#ddev-with-a-public-webhook-tunnel).

Run the complete commands in [VALIDATION.md](VALIDATION.md#automated-execution). Pest requires `--test-directory=../tests` because its executable is under `src/vendor/` while the host bootstrap is at `tests/`. Destructive MariaDB integration fixtures must use the separate `molliepayments_test` database.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Pest cannot find `src/tests` | Include `--test-directory=../tests`. |
| SDK missing | Run this repository's `composer install`; verify `module/Molliepayments/vendor/autoload.php`. |
| Gateway, module or logo absent | Verify all three links and DDEV's mounted workspace. |
| Missing/invalid credential | Save the correct `test_` or `live_` key without whitespace; do not paste it into logs/issues. |
| Permission denied | Check staff module access, settings and gateway permissions, plus the active session. |
| Timeout/network error | Check outbound HTTPS, DNS, CA certificates and cURL/OpenSSL; never disable TLS verification. |
| Rate limit/provider unavailable | Wait and retry; saved credentials and gateway mode remain unchanged. |

Production packaging, deployment certification, recurring billing and refunds remain outside the current release.

Customer integration adds the module-owned `molliepayments_customer` table. Run the existing module update/migration command before calling the internal Customer service. Synchronization remains additive and preserves payment attempts, legacy columns and Customer mappings. No credentials are migrated or duplicated. See [Customer behavior and recovery](PAYMENTS.md#internal-customer-integration-issue-7).


## First-payment onboarding upgrade

Version 0.3.0 adds seven nullable consent/onboarding/mandate columns to the existing module attempt entity. Run the module migration command above before exposing `/molliepayments/onboarding/INVOICE_ID`. It uses `SchemaSynchronizer::syncEntities()` and preserves old one-time attempts, Customer mappings, financial history and secrets. No new core tables, core migrations or destructive schema synchronization are introduced.

Configure exactly one MolliePayments gateway with a saved Test key, Test mode, Enabled and Allow one-time payments. Keep subscriptions disabled. Normal native invoice checkout now routes authenticated owners of a single eligible recurring order to the Bootstrap authorization page before reserving a payment. The invoice must contain only lines for that same recurring order and period; canceled/scheduled-cancellation, mixed, multi-order and custom invoices do not qualify. See [the eligibility policy](PAYMENTS.md#first-payment-onboarding-issue-8). One-time and hash-authorized guest checkout remains `oneoff`; native login is required for consent. Choose **Authorize and start first payment** with the unchecked checkbox selected, or **Pay once without future authorization**. The order association uses existing immutable attempt request metadata; this review adds no columns or tables and does not reinterpret historical broad consent.

Use the configured `SYSTEM_URL` origin consistently for login, invoice navigation and API POSTs. A direct DDEV page whose form actions point to a public tunnel crosses origins and will not carry the same-origin session. The current host also exhibits an independent authenticated session/CSRF mismatch on the configured origin, affecting native APIs; see [validation evidence](VALIDATION.md). Do not disable CSRF, alter sessions or copy cookies across origins to bypass it. This release does not alter native invoice pages, themes or host security.

The v1 manifest omits legacy `icon_url`; newer FOSSBilling builds discover the unchanged root `icon.svg` themselves. The pinned preview baseline predates that discovery and may display the host's default icon in the extension list. Native inline SVG loading and the gateway logo remain available on both hosts. Do not restore the invalid manifest field to change this cosmetic fallback. A 404 when refreshing MolliePayments from the FOSSBilling extension directory is separate: this independent module is not available at that directory endpoint. Use the local installation/update procedure rather than the directory refresh action. A local manifest change cannot publish a directory listing.

Profile payment-method eligibility and mandate association/scope limitations are in [PAYMENTS.md](PAYMENTS.md#first-payment-onboarding-issue-8). After a financially paid checkout, use the same page to recheck a missing/pending mandate; do not create another invoice/payment to retry verification. Real Hosted Checkout and public webhook validation remain separate from mocked CI tests.


## Customer detail and transaction-link review

This update needs no new columns or tables. New Customer requests send validated native names/emails and keep their immutable personal-field snapshot encrypted through FOSSBilling's `crypt` service in the existing request column. Preserve the host encryption secret alongside database backups for unresolved creation retries. Existing metadata-only requests and valid Customer IDs remain compatible and are not silently enriched or replaced.

Use `php module/Molliepayments/bin/diagnose-transactions.php /absolute/path/to/FOSSBilling/src` for read-only orphan inventory. Do not backfill processed transaction associations or replay paid accounting. Unprocessed errors may use the native permission-checked processing action and original provider context. See [the verified association contract](PAYMENTS.md#verified-transaction-invoice-association).

Module date fields now use native Doctrine DATETIME columns, stored in UTC as `Y-m-d H:i:s`. The status CLI also presents dates in the host `i18n.timezone`, initialized by FOSSBilling bootstrap, using the same readable format. No separate module timezone configuration exists. This development-only schema replacement has no migration: recreate the disposable module database before use; do not apply it to old integer-timestamp tables containing records. Database recreation is an operator action, not performed by the adapter.

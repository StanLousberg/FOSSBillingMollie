# Installation and development setup

> **Development only.** MolliePayments implements one-time Checkout and authenticated invoice settlement for development validation. Subscriptions remain unsupported. Complete the [manual Test-mode payment and webhook procedure](PAYMENTS.md) before considering deployment; there is no production-ready release.

## 1. Prerequisites

- Ubuntu, WSL2, Linux or macOS; a clean FOSSBilling development installation.
- PHP **8.3+** with FOSSBilling's required PHP extensions; specifically, cURL and OpenSSL for the Mollie PHP SDK.
- Composer 2 and Git.
- Docker and [DDEV](https://ddev.com/) for the recommended local web environment.
- A separate, disposable development database. Never use production customer data or keys.

The source code is split across **two independent repositories**. You should not fork or edit FOSSBilling core in order to develop MolliePayments.

## 2. Create a workspace (first-time setup)

The following commands use `~/dev/MolliePayments-workspace` as the example directory.

```bash
mkdir -p ~/dev/MolliePayments-workspace
cd ~/dev/MolliePayments-workspace

git clone https://github.com/FOSSBilling/FOSSBilling.git
git clone https://github.com/StanLousberg/FOSSBillingMollie.git \
  FOSSBilling/.local/MolliePayments
```

The nested repository is intentional: it keeps the source in its own Git checkout while allowing Docker/DDEV to resolve the development symlinks inside the mounted FOSSBilling workspace.

If you used the initial WSL setup script, the repositories and links may already exist. **Do not rerun clone or `ln -s` commands over existing paths.**

## 3. Install Composer dependencies

Run this in the **MolliePayments repository**, not in FOSSBilling:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments
composer install
```

This installs the official `mollie/mollie-api-php` SDK v4 and its dependencies into:

```text
module/Molliepayments/vendor/
```

That directory is deliberately ignored by Git. The class `Box\Mod\Molliepayments\Support\MollieClientFactory` loads its Composer autoloader when needed and provides configured clients to connection tests, checkout and reconciliation.

**No Composer lock file exists in the initial scaffold.** Composer will resolve and generate one on the first install. Contributors should commit the resulting `composer.lock` in a subsequent dependency-lock change to make future installations deterministic.

If your host does not have compatible PHP and Composer, start DDEV first (step 5) and install in the container:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev exec bash -lc 'cd .local/MolliePayments && composer install --no-interaction'
```

The dependency is **already declared** in the MolliePayments `composer.json` as `mollie/mollie-api-php: ^4.0`, so `composer install` is the correct command. `composer require mollie/mollie-api-php` is used when *adding* the requirement, not for installing an already declared dependency. Do not run `composer require` in FOSSBilling's root project. The SDK must remain an independently managed dependency of MolliePayments.

## 4. Link the gateway and module into FOSSBilling

From the FOSSBilling root:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling

ln -s "$(pwd)/.local/MolliePayments/gateway/MolliePayments.php" \
  src/library/Payment/Adapter/MolliePayments.php

ln -s "$(pwd)/.local/MolliePayments/gateway" \
  src/library/Payment/Adapter/MolliePayments

ln -s "$(pwd)/.local/MolliePayments/module/Molliepayments" \
  src/modules/Molliepayments
```

If those links already exist, skip this step. Check them with:

```bash
ls -l src/library/Payment/Adapter/MolliePayments.php
ls -ld src/library/Payment/Adapter/MolliePayments
ls -l src/modules/Molliepayments
```

The links are local development conveniences, **not changes to upstream FOSSBilling**. If your FOSSBilling checkout reports them as untracked, add their three paths to `.git/info/exclude` in the FOSSBilling checkout (not to FOSSBilling's tracked `.gitignore`).

The gateway directory link exposes all current and future gateway assets automatically; no per-asset links are needed. FOSSBilling discovers adapters through the separate PHP entry-point link. Existing workspaces with only the PHP and module links must add the gateway directory link once.

The extension icon uses a root-level `icon.svg` wrapper containing the original PNG bytes. FOSSBilling inlines it through its module icon loader, so it also works when direct HTTP access to `/modules/` is blocked. The gateway uses `gateway/assets/mollie-icon.png` through the standard adapter logo configuration and directory link. No webserver exceptions or core changes are required.

## 5. Start the development host

Use the existing FOSSBilling DDEV configuration:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev start
ddev launch
```

Complete FOSSBilling's installation wizard with a disposable database if necessary. Consult the [FOSSBilling setup guide](https://docs.fossbilling.org/getting-started/) if this is a new installation.

For a repository already configured by the initial setup script, retain its separate DDEV project name and existing install. There is no need to reinstall FOSSBilling.

## 6. Run the tests

Run the MolliePayments unit tests through the FOSSBilling Pest framework:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev exec ./src/vendor/bin/pest \
  --test-directory=../tests \
  --configuration=phpunit.xml.dist \
  src/modules/Molliepayments/tests/Unit
```

Run the FOSSBilling Unit and Modules suites to catch regressions:

```bash
ddev exec ./src/vendor/bin/pest \
  --test-directory=../tests \
  --configuration=phpunit.xml.dist \
  --testsuite=Unit,Modules
```

The `--test-directory=../tests` argument is mandatory in this project layout: Pest is installed in `src/vendor`, but its bootstrap and base test files are in the root `tests` directory.

The SDK smoke test does not connect to Mollie or require an API key.

### What CI tests

The [GitHub Actions workflow](../.github/workflows/ci.yml) checks out **both** repositories separately, runs Composer for each, generates a disposable `fossbilling/src/config.php` from FOSSBilling's own sample, links the gateway/module and runs their Pest tests through the upstream FOSSBilling runner. The copied test config is created on the temporary GitHub Actions runner only and is not committed. This is a **unit/integration bootstrap**, not a fully installed billing instance or a live payment E2E environment.

## 7. Inspect the extension in FOSSBilling

After DDEV starts, open the administration interface. FOSSBilling should discover the `MolliePayments` gateway from its `src/library/Payment/Adapter` path, and `Molliepayments` from `src/modules`.

Install/activate the development extension, then follow [one-time payment setup](PAYMENTS.md). Existing module installations must run its additive migration. Use only disposable Test-mode payments during validation; do not enable Live payments.

## 8. Configure and test Mollie API credentials

### Compatible FOSSBilling host

This feature relies on the current FOSSBilling gateway contract: `secret` fields, `required_when`, encrypted `SecretConfig` storage, masking through `ServicePayGateway::toApiArray()`, preservation of blank secret updates and decrypted `getPaymentAdapter()`. It was tested against host commit `97bb583dd681e311e2d10fe8c648095f2a479878`. The manifest's `>=0.8.9` constraint alone does not guarantee an older build has these security features. Verify the contract before deployment; hosts without it are unsupported. Never patch core or fall back to plaintext storage. CI tests these behaviors against its upstream checkout.

### Obtain, save and rotate credentials

Obtain standard profile-scoped API keys from **Developers → API keys** in the [Mollie dashboard](https://my.mollie.com/). Keep Test (`test_`) and Live (`live_`) keys separate; do not paste advanced access tokens. Consult [Mollie's API key guidance](https://help.mollie.com/hc/en-us/articles/360023732273-What-is-an-API-key).

Activate the companion extension under **Extensions**, add the gateway under **Payment Gateways**, and open **MolliePayments**. Enter either or both password fields and save. Native FOSSBilling encryption protects the saved values; admin responses expose only whether each key is configured. Leave a password blank to keep its previous value. To rotate a credential, enter its replacement and save; the other key remains unchanged. Blank fields do not delete keys.

**Test mode** selects the active credential for future operations: checked selects Test, unchecked selects Live. Required fields follow the active environment when the gateway is enabled. Leave the gateway disabled while configuring credentials. After schema installation and a successful connection test, enable one-time payments in Test mode as described in [PAYMENTS.md](PAYMENTS.md).

FOSSBilling only invokes adapter configuration validation when saving an enabled gateway. A disabled gateway can therefore store an invalid value, but MolliePayments always rejects missing, malformed or wrong-environment keys before a connection request. Prefix validation also runs before enabling. Saving does not contact Mollie and does not require checkout callback URLs.

### Run the connection tests

Open **Extensions → MolliePayments → Settings** using the extension's settings icon. The page contains one **Test connection** button, exclusively for our MolliePayments adapter. It does not list or select other adapters. The adapter's saved **Test mode** flag selects its saved Test key when checked and Live key when unchecked. **Save changes before testing**: these actions load stored credentials server-side, not unsaved password inputs. A disabled gateway can be tested, and the action leaves the saved mode and enabled state unchanged. The browser cannot supply a different gateway ID or environment. If there is no MolliePayments gateway, or multiple copies make the target ambiguous, the test stops with a clear message instead of choosing one arbitrarily.

The settings page uses FOSSBilling's native `/extension/settings/molliepayments` route, module settings permission check, layout, admin API and CSRF token. Staff require MolliePayments module access, `molliepayments.manage_settings` and `invoice.manage_gateways`. The adapter only renders **Live API Key (live_xxx)** and **Test API Key (test_xxx)**; it has no test buttons or custom descriptions beneath those fields. There is no standalone module page or JavaScript injected into gateway settings.

The native template finder does not descend into symlinked module directories. MolliePayments therefore decorates the admin Twig factory with a fallback for its own templates during module service initialization. The standard theme and its overrides retain priority. This supports the native extension settings route without changing core files, theme files, webserver configuration or adding per-file symlinks. The settings template uses the host's supported `url` and `api_url` filters.

The request is the SDK v4 `$client->payments->page(null, 1)`: [List Payments](https://docs.mollie.com/reference/list-payments), `GET https://api.mollie.com/v2/payments?limit=1`. No `testmode` parameter is sent for profile API keys. No payment is created. Any payment details returned by Mollie are discarded, never included in the result or logged. A successful empty collection is a successful connection.

A success only proves that the selected saved key authenticated and could read payments at that moment. It does not validate payment creation, checkout, accounting, webhooks, settlement, refunds, subscriptions or mandates. One-time capability is enabled for development validation; subscription capability remains false. Automated tests use the SDK's fake transport and generated disposable dummy values, without real credentials or network access.

## 9. Develop and submit changes

Open only the MolliePayments checkout in VS Code:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments
code .
```

Commit and push changes there. Never commit symlinks or modified core files from the FOSSBilling checkout.

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the pull-request workflow and required tests.

## Production deployment

There is **no supported production installation at this stage**. Before publishing a deployable release, we must build a release artifact that contains the gateway, companion module and production Composer dependencies, install both in their FOSSBilling locations, and confirm the implemented one-time checkout and webhook flow in a staging environment. Recurring flows are not implemented and are outside this release. This guide will be expanded when those features are implemented.

## Troubleshooting

**`The test directory [/var/www/html/src/tests/] does not exist`**

Use `--test-directory=../tests` in the Pest command above.

**`Mollie SDK is not installed`**

Run `composer install` in `.local/MolliePayments`; check for `module/Molliepayments/vendor/autoload.php`.

**Module or adapter does not appear**

Check the symlink targets and that the respective `manifest.json` and `gateway/MolliePayments.php` files exist. Confirm DDEV is mounting the same FOSSBilling workspace.

**PHP version or missing extension**

Check `ddev exec php -v` and `ddev exec php -m`. Use DDEV's PHP environment instead of the Windows or WSL host if they differ.

**Existing checkout reports untracked links**

Ignore only the three local paths using `.git/info/exclude`, not an upstream FOSSBilling commit.

**Missing or invalid API key / authentication failure**

Save the appropriate key before testing. Check the `test_` or `live_` prefix, remove whitespace and confirm the key has not been revoked. Replace it in the password field and save. The SDK may apply additional key validity checks. Do not paste credentials into issue reports.

**Insufficient permissions**

Use a standard profile API key that can read payments. Check staff access to MolliePayments, `molliepayments.manage_settings` and `invoice.manage_gateways`. Make sure the extension is activated, and sign in again if your session expired.

**Timeout or connection failure**

Ensure PHP can reach `api.mollie.com` over HTTPS with working DNS, trusted CA certificates and cURL/OpenSSL. Check your firewall/proxy. Never disable TLS verification to work around errors. The UI shows sanitized errors; it does not expose provider response bodies.

**Rate limit or temporarily unavailable**

Wait before retrying. A failed test does not change the stored key or gateway mode. Avoid repeated clicks while a request is in progress.

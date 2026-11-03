# Installation and development setup

> **Development only.** MolliePayments does not yet process real or test payments. Its payment adapter deliberately reports that one-time and subscription payments are unsupported. This guide installs the development scaffold and official SDK, not a production payment solution.

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

That directory is deliberately ignored by Git. The class `Box\Mod\Molliepayments\Support\MollieClientFactory` loads its Composer autoloader when needed and provides a configured Mollie API client to future integration code.

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

You may install/activate the development extension to verify discovery, but **do not enable or attempt live payments**. The adapter currently throws a deliberate not-implemented error.

## 8. Develop and submit changes

Open only the MolliePayments checkout in VS Code:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments
code .
```

Commit and push changes there. Never commit symlinks or modified core files from the FOSSBilling checkout.

See [CONTRIBUTING.md](../CONTRIBUTING.md) for the pull-request workflow and required tests.

## Production deployment

There is **no supported production installation at this stage**. Before publishing a deployable release, we must build a release artifact that contains the gateway, companion module and production Composer dependencies, install both in their FOSSBilling locations, and confirm payment/webhook/recurring flows in a staging environment. This guide will be expanded when those features are implemented.

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

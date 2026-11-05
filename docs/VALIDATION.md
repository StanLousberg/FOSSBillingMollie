# Lean-adapter validation — 2026-11-05

This is development evidence, not production certification. No Live requests, credential updates, database resets or FOSSBilling core changes were made.

## Environment and source inspection

- PR #4 remains open: `feat/mollie-one-time-payments` → `main`, commit `19f028a671a2280e06661951fc8184121355ef98`.
- Starting branch: `fix/one-time-payments-wallet-and-status`, with uncommitted wallet, URL, compatibility and MariaDB changes. A source snapshot and tracked patch were preserved under `/tmp/mp-before-refactor/` before editing. The refactor incorporates the current work, rather than reverting to PR #4.
- Local external host: `ceda8f4952d85d40ecb6601e6e0d09acec557c40`; DDEV `molliepayments-dev`, PHP 8.3.30, MariaDB 11.8. The original configured database was retained.
- Official upstream `main` inspected at `09cb340c48c9b8a43b24c6f7e13b8b64a42e6f64`. Its Invoice Service, ServiceTransaction, ServicePayGateway, Client ServiceBalance and ClientBalanceRepository match the local host byte-for-byte.
- Separate official source archives for that main revision and baseline `97bb583dd681e311e2d10fe8c648095f2a479878` were extracted under DDEV `/tmp/`. Each had its own dependencies/autoloader regenerated from its lockfile and a disposable sample configuration. Their source was not patched.
- Module SDK lock: `mollie/mollie-api-php v4.0.0`, isolated from host dependencies.
- Initial local database: nine attempts (five paid, three canceled, one expired), no wallet columns and no `molliepayments_reserve` / `molliepayments_release` ledger entries. Thus no local reservations required release. Existing history was retained; all new financial fixtures used disposable customers/invoices.

## Architecture reference

The native-service boundaries and reason for the atomic settlement bridge are documented in [PAYMENTS.md](PAYMENTS.md#checkout-and-callbacks); generic host improvements remain [proposals only](FOSSBILLING_CORE_PROPOSALS.md).

## Automated execution

Run from the FOSSBilling root unless stated otherwise:

```bash
ddev exec composer validate --strict --no-check-publish --working-dir=.local/MolliePayments
ddev exec composer install --no-interaction --prefer-dist --working-dir=.local/MolliePayments
ddev exec bash -lc 'find .local/MolliePayments/gateway .local/MolliePayments/module -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n 1 php -l'
ddev exec ./src/vendor/bin/php-cs-fixer fix --config=.local/MolliePayments/.php-cs-fixer.php --dry-run --diff --sequential
ddev exec ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Unit
ddev exec env MOLLIEPAYMENTS_MYSQL_URL=mysql://db:db@db:3306/molliepayments_test ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Integration
```

The MariaDB test suite refuses any database name other than `molliepayments_test`. Only this separate database was recreated by integration fixtures. DDEV's development database was never reset.

Original refactor coverage: 152 unit/SQLite tests and eight MariaDB tests. The pinned baseline and current main both pass. Coverage includes independent checkout/webhook workers, checkout versus webhook races, unique active attempts, duplicate settlement guards, real rollback and injected MariaDB deadlock with a fresh retry, lost creation responses, key rotation/expiry, authorization, mismatched provider metadata, amount/currency checks, unavailable/unsafe checkout links, all payment statuses, native full-credit payment, unchanged partial credit, VAT/discount rounding and additive upgrades preserving legacy records. Native income reporting is exercised for terminal callbacks: no revenue, balance entries, payment events or activation tasks.

The host's entire unrelated test suite was not run; the complete MolliePayments suite was run through each host's own Pest bootstrap. PostgreSQL was not tested.

## Real Mollie Test-mode evidence

The existing configured public tunnel was reused. Its `ipn.php` endpoint returned HTTP 200. The saved gateway was enabled and in Test mode; its saved key was resolved through the native adapter and never printed or copied into fixtures.

Disposable invoices were created locally; checkout creation and reuse used the real SDK and native services. Hosted Checkout was driven with HTTP form submissions (method and issuer selection, then Mollie's Test status form). Browser-return redirects were deliberately not followed for successful submissions. Authenticated provider GETs and database records were checked afterward; settlement therefore came from the public webhook, not a return page or manual paid flag.

| Disposable invoice | Provider Payment-ID | Provider / invoice / transaction | Financial evidence |
| --- | --- | --- | --- |
| 10 | `tr_UUsmJ3zVUT2FzRk5DJ9ZJ` | paid / paid / processed, €25.00 | Exactly one +€25 transaction credit and -€25 invoice debit; settlement guard transaction 10 |
| 15, account credit added after checkout creation | `tr_fXjWAv9peRxsRpXXDJ9ZJ` | paid / paid / processed, €25.00 | Open link reused after adding €10; amount unchanged; credit remained €10; guard transaction 11 |
| 16, taxable €25 line and -€5 discount | `tr_h2pB8tBChQvw4rzaDJ9ZJ` | paid / paid / processed, €24.20 | Native total matched provider; exactly one credit/debit pair; guard transaction 12 |
| 19, disposable custom product/order | `tr_` identifier retained in local attempt history | paid / paid / processed, €1.00 | Guard transaction 17; native invoice-item task execution changed its order to active |

All four attempts had null reconciliation errors. Items entered native `pending_setup` after payment. Service activation used native task execution only for the disposable order, not a global cron run affecting existing user data.

Two HTTP webhook redeliveries for invoice 10 returned 200 (native transactions 15 and 16); two CLI reconciliation retries created zero-value duplicate audit rows (13 and 14). Reinspection showed only the original +€25/-€25 financial pair and the original settlement guard. The customer's account balance remained €10. No wallet reservation records were created.

Native full-credit payment was also exercised in the existing local installation using separate disposable customers: €25 credit paid a €25 invoice with €0 remaining; €30 credit paid a €25 invoice with €5 remaining, independently of Mollie.

## Limits and failed attempts

- Chromium could not launch because `libnspr4.so` is unavailable, including outside the sandbox. Real hosted forms and callbacks were tested over HTTP, but interactive gateway selection, login and browser SameSite return behavior were not verified end to end. Native authorization/return contracts remain protected by automated tests.
- Choosing failed, canceled or expired on the real iDEAL Test method screen left the parent payment `open`; no terminal callback was received for those parent payments. Hosted cancellation also left the parent open; the provider cancellation API returned 422, “payment cannot be cancelled.” These attempts were preserved and never replaced or forced terminal. Real terminal canceled/failed/expired behavior is **not verified**; fake-provider SQLite/MariaDB tests verify terminal accounting and retry behavior.
- A genuine provider `pending` or `authorized` state, naturally delayed webhook, real provider outage and real lost-response recovery were **not verified**. They are covered by provider fakes, local concurrency/rollback tests and timeout simulations. An open unpaid parent is not counted as pending evidence.
- Browser closure is represented by not following the provider redirect after Test payment submission; public-webhook-only settlement was verified. This is not a claim that an interactive browser session was exercised.
- Four new parent attempts (invoices 11–14) remained open at inspection. Their records and active slots are preserved for provider finality or manual reconciliation; no cleanup deleted history or released unresolved slots.
- Terminal rows use native `processed`, amount zero, provider status and type `unknown`. Host details/history display the stored zero and provider status; native income reports depend on paid/refunded invoices. Third-party reports equating processed-row counts with successful payments remain a host reporting limitation.

## Fix and retest history

1. The first targeted payment suite passed 57 tests and rejected the old partial-allocation expectation. That test was rewritten to enforce the explicitly requested unsupported-partial-state rejection. The 147-test initial full suite and seven initial MariaDB tests then passed.
2. Added legacy-column/ledger preservation and terminal-event/report regressions; targeted reruns passed. Added the MariaDB non-destructive upgrade regression; the full suites passed on both official host revisions.
3. Coding standards initially reported formatting issues in the carried-forward work and new edits. Applied the project fixer and reran the check successfully; no assertions were skipped to obtain a pass.
4. Temporary local evidence scripts encountered an empty redirect response parsed as HTML and an incorrect `Invoice::isPaid()` call. Corrected the harness to stop after the Test redirect and check native status; repeated inspection and service activation succeeded. Those temporary scripts are not runtime code and were removed from the repository.
5. Composer installation in temporary host copies initially failed because DDEV disables `proc_open`. Repeated it using CLI-only `php -d disable_functions= /usr/local/bin/composer install`; both lockfile installations and tests passed. No persistent PHP or core configuration was changed.

Raw local test logs are under DDEV `/tmp/mp-*.log`. The initial source snapshot and temporary evidence scripts are under host `/tmp/mp-before-refactor/`. Neither is a committed credential store. Legacy migration policy and required administrative reconciliation are in [PAYMENTS.md](PAYMENTS.md#legacy-wallet-transition).

## Cleanup review and regression run — starting at `7374061`

The clean starting branch was `refactor/lean-molliepayments-adapter`. Baseline syntax, Composer validation/lockfile installation, PHP-CS-Fixer and the complete suite passed (160 tests, 815 assertions). Official upstream main was rechecked at `09cb340c48c9b8a43b24c6f7e13b8b64a42e6f64`; the pinned/main compatibility matrix is unchanged.

Audit decisions:

- Retain the thin gateway, attempt entity/repository, money/URL/credential helpers and SDK factory: these are distinct security, persistence and test boundaries. There are no unused runtime services.
- Retain atomic native settlement glue. Current core has no suitable atomic external-payment API; native full-credit payment still dispatches work before an outer commit. Preserve the attempt guard, lock order and invoice-level rounding.
- Retain `AdminTwigFactory`: native `TwigLoader` still skips symlinked module templates. It now leaves already resolved native/theme loaders intact. Tests exercise real native symlink/icon resolution, real Twig filters, theme precedence, unrelated-template absence and permission discovery with an already initialized factory.
- Retain the small runtime compatibility guard, including the four-argument deferred-event signature check. Retain `SdkUnavailableException` because connection tests distinguish a missing SDK from provider/network failures without exposing raw errors.
- Retain the three short CLI scripts. A dispatcher/bootstrap abstraction would add indirection to a few shared initialization lines without improving the operational commands.
- Remove only the redundant module PNG and wallet fixture helper. Preserve the gateway PNG byte-for-byte (SHA-256 asserted by tests) and the existing SVG wrapper. Share fixture helpers in `PaymentContext.php`; organize tests as `CheckoutTest`, `WebhookTest` and `NativeBalanceTest`, with MariaDB workers separate.

`PaymentService` keeps checkout preparation, network creation/retrieval, locked verification, callbacks and settlement in the same class. The cleanup removes a URL forwarding method, unused gateway tuple data, repeated status parsing, gateway re-resolution and repeated repository/service lookups. Verification before and after locking, changed-invoice checks and legacy-history rejection remain deliberately separate. No state-machine, schema, pricing or accounting outcome changed.

Post-cleanup automated results: 154 unit/SQLite tests (761 assertions) and eight MariaDB integration tests (61 assertions), **162 tests / 822 assertions total**, passing on the pinned baseline, current main and the local host. Syntax, Composer validation/installation and coding standards pass. No existing regression assertions or test cases were discarded. Logs use the DDEV `/tmp/mp-cleanup-*.log` prefix.

Fresh real Test-mode regression: disposable invoice 20, payment `tr_YZVxsVpotJNVeT5whM9ZJ`, €25.00. Created after adding €10 account credit; open checkout was reused twice, including after another €1 was added. Hosted iDEAL Test forms were submitted over HTTP and the browser return was not followed. Provider GET confirmed paid; the public webhook produced native transaction 22 (`processed`, €25.00), invoice paid, one +€25 transaction credit, one -€25 invoice debit and a matching settlement guard with no error. Account balance remained €11. Two public HTTP redeliveries returned 200 and created only zero-valued duplicate audit rows; no additional financial entries. Native invoice-item execution activated the disposable custom order.

Before/after checksums of every pre-existing row in `invoice`, `client_balance`, `transaction`, `molliepayments_attempt` and `client_order` matched. Only disposable fixtures were added; no prior attempts, records or columns were removed. The temporary evidence harness and fixture state were not committed.

Fix/retest: fixture consolidation initially missed the helper's `Amount` import; restored it and reran the affected balance/logo tests, then full suites on both host revisions. The temporary real-payment harness initially compared an SDK status enum with a string; switched it to SDK `isPaid()` and repeated provider/database verification, duplicate delivery and activation. Neither failure required changing financial expectations or skipping tests.

The earlier real-provider terminal-state and interactive-browser gaps listed above remain **not verified** in this cleanup run. The real regression used hosted HTTP forms, not an interactive browser. Canceled, failed, expired, pending and authorized accounting remains covered by provider fakes and native database tests. No Live keys were used or overwritten; no core files were modified. The result is committed locally only, with no push or PR.

## Issue #7 Customer integration — 2026-11-05

Local validation on the existing disposable DDEV host (PHP 8.3.30, MariaDB 11.8), using locked SDK v4.0.0 and mocked provider calls:

- `composer validate --strict --no-check-publish`: passed.
- `composer install --no-interaction --prefer-dist --no-progress`: passed; lock unchanged.
- PHP syntax: all 34 non-vendor PHP files passed.
- PHP-CS-Fixer dry run: 34 files checked, zero fixes required.
- Complete unit/SQLite suite: **178 tests, 928 assertions**, zero skipped/unexecuted in that suite.
- MariaDB integration suite, with `MOLLIEPAYMENTS_MYSQL_URL` pointing only at `molliepayments_test`: **12 tests, 93 assertions**, zero skipped/unexecuted in that suite. Includes independent Customer workers, committed creation identity, lost-response retry, expired-window read recovery, repeated verification and additive schema preservation.

The unit suite includes 24 new Customer cases and all existing one-time payment/accounting regressions. Initial direct WSL testing could not execute because PDO SQLite is absent; final successful suites ran inside DDEV. The added MariaDB regression exposed and now covers MySQL's zero-changed-row behavior for same-second verification writes.

The existing GitHub Actions four-job compatibility matrix is triggered by the feature PR (pinned host and upstream main, SQLite and MariaDB). Its final status is reported on that PR. No real Customer API/manual Test-mode run was performed for this feature; follow the manual steps in [PAYMENTS.md](PAYMENTS.md#internal-customer-integration-issue-7). This evidence does not establish production readiness or recurring capability.

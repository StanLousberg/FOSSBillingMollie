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

## Host contracts and architectural decision

`getTotalWithTax()` supplies invoice-level pricing/tax. Native `tryPayWithCredits()` / `payInvoiceWithCredits()` settle fully covered invoices and debit native credit. ServiceBalance and ClientBalanceRepository own balance summation/locking; ServiceTransaction owns callback rows, deduplication, claims and technical errors. ServicePayGateway owns decrypted keys and native callback/return URLs. Native invoice authorization remains mandatory.

There is no supported authoritative partial-balance API. Checkout rejects charged items and previous allocations instead of reconstructing ledger balances. The remaining settlement glue calls native `addFunds()` for the verified receipt and matching invoice debit, then deferred `markAsPaid()`, inside the transaction containing the attempt guard. This is necessary because native full-credit payment dispatches events/tasks before an outer transaction commits, and native per-item charging can differ from invoice-level tax rounding. No wallet reservation/release, available-credit calculation or partial allocation service remains. Proposed host APIs are documented separately.

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

Final automated coverage: 152 unit/SQLite tests and eight MariaDB tests. The pinned baseline and current main both pass. Coverage includes independent checkout/webhook workers, checkout versus webhook races, unique active attempts, duplicate settlement guards, real rollback and injected MariaDB deadlock with a fresh retry, lost creation responses, key rotation/expiry, authorization, mismatched provider metadata, amount/currency checks, unavailable/unsafe checkout links, all payment statuses, native full-credit payment, unchanged partial credit, VAT/discount rounding and additive upgrades preserving legacy records. Native income reporting is exercised for terminal callbacks: no revenue, balance entries, payment events or activation tasks.

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

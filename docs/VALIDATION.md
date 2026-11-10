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

The existing GitHub Actions four-job compatibility matrix is triggered by the feature PR (pinned host and upstream main, SQLite and MariaDB). Its final status is reported on that PR. The initial implementation did not include a real Customer API run; the subsequent review below adds that evidence. This evidence does not establish production readiness or recurring capability.


## Issue #7 review and real Test API validation — 2026-11-05

Reviewed the complete feature diff against issue #7 and the installed SDK v4.0.0 contracts. The feature branch was already identical to its remote tracking branch. The review fixed three state-handling defects: expired creations lost the specific recovery diagnostic, observed profile mismatches did not prevent subsequent cached reuse, and rejected replacement attempts could block otherwise healthy mappings. Read-only resolution of an unresolved creation now fails without a provider request or mapping mutation. No schema or accounting changes were needed.

Final local validation used the existing DDEV host at `ceda8f4952d85d40ecb6601e6e0d09acec557c40` (PHP 8.3.30, MariaDB 11.8):

- Composer strict validation and dependency installation: passed; dependency lock unchanged.
- Syntax and PHP-CS-Fixer: all **34 PHP files**, zero errors/fixes required.
- Complete unit/SQLite suite: **186 tests, 994 assertions**, zero skips. Includes **32 Customer cases**, three independently mapped clients, cross-client authorization, provider verification, resource mismatch, Test/Live isolation, timeout/error/409 paths, rotated credentials, durable recovery diagnostics, profile blocking, rejected replacement preservation and all one-time accounting regressions.
- Complete MariaDB integration suite: **13 tests, 105 assertions**, zero skips. Destructive fixtures were restricted to `molliepayments_test`; no reset of the development database. Independent workers observe the committed creation identity and produce one Customer POST for the shared mapping. Added MariaDB coverage confirms precondition errors preserve healthy mappings and observed profile mismatches block reuse.

A temporary CLI harness outside the repository loaded the unique saved Test-mode gateway via native `ServicePayGateway::getPaymentAdapter()`. It retained the decrypted Test key only in memory and used a fresh in-memory SQLite fixture database for clients, mappings, invoices and native settlement. It made real Mollie Customer API calls; it did not copy secrets or write clients, invoices, mappings or payment attempts to the existing development database.

Two real API runs passed. The first created two metadata-only Test Customers; the final run created three new disposable Test Customers and performed **14 assertions** (13 real Customer assertions plus one mocked-provider/native-accounting assertion). The final run verified creation, cached reuse, authenticated GET identity/mode/opaque metadata, distinct clients, read-only recovery after a simulated expired lost local result, and rejection of a cross-client recovery context. One real Customer POST completed successfully and the harness deliberately discarded its response; a retry using the same payload/key returned exactly the original Customer ID. A fully paginated real provider list contained exactly one Customer per creation reference. The response loss was simulated after a real successful POST; it was not a real network outage. No Live credentials were used. Five metadata-only disposable Test Customers were retained across both runs; no provider records were deleted.

The one-time regression used fake provider payment responses with native accounting, and created no Customer mapping or Customer request. No new real payment was collected during this Customer review. Real one-time payment evidence from earlier validation remains documented above.

Keys, provider IDs, response bodies, client personal records and raw SDK exceptions were excluded from tracked files and harness output. The temporary harness contains no credentials. The existing PR's four GitHub Actions jobs (pinned/current upstream hosts, SQLite/MariaDB) supply the compatibility result; see the PR checks. This validates issue #7 for review, not production readiness, mandates or recurring payments.

GitHub delivery history for this review: PR [#17](https://github.com/StanLousberg/FOSSBillingMollie/pull/17) initially passed all four jobs in [run 42039276144](https://github.com/StanLousberg/FOSSBillingMollie/actions/runs/42039276144) for the original `79b500b` implementation. Publishing the review fixes was initially blocked by missing command-line Git credentials and a canceled GitHub-app write action. That block was subsequently resolved: the fixes were committed and pushed as `28099570cc5668f2013fc493dd819f8ba7561769`, and all four compatibility checks passed in [run 42042367438](https://github.com/StanLousberg/FOSSBillingMollie/actions/runs/42042367438). The later run supplies CI evidence for those fixes. These automated provider-fake checks are distinct from the separate real Mollie Test Customer API validation documented above.

## Issue #8 first-payment onboarding — 2026-11-05

Implemented on the existing `8-implement-first-payments-and-mandate-onboarding` branch. No branch switch, push or PR action was performed. Module version 0.3.0 adds seven nullable columns to the existing attempt entity through module-scoped additive synchronization; no new billing tables or core changes were needed. The client entry point is `/molliepayments/onboarding/INVOICE_ID` after schema upgrade. Automatic recurring collection and `supports_subscriptions` remain disabled.

Architecture inspection covered the local host's native client controller/API registration, dispatcher identity and nonce handling, invoice payment authorization, gateway credentials/callback URLs, transaction claims, deferred events and module schema synchronization. Provider contracts were checked against the four API references linked in [PAYMENTS.md](PAYMENTS.md#first-payment-onboarding-issue-8), the recurring guide and installed SDK v4.0.0. No standalone setup-payment flow was introduced.

Final local checks on PHP 8.3.30 and MariaDB 11.8:

| Category | Result | Evidence |
| --- | --- | --- |
| Composer strict validation | PASS | `validate --strict --no-check-publish` |
| Locked dependencies and platform requirements | PASS | `install` changed no dependency versions; `check-platform-reqs` succeeded |
| Dependency security audit | PASS | `audit --locked` reported no vulnerability advisories |
| PHP syntax | PASS | All 40 non-vendor PHP files |
| PHP-CS-Fixer | PASS | All 40 files; zero fixes required in final dry run |
| Complete unit/SQLite suite | PASS | 250 tests, 1,746 assertions; no skips |
| Disposable MariaDB suite | PASS | 17 tests, 165 assertions; independent workers, no skips |
| Real Mollie Test API preflight | PASS | Saved Test key resolved through the native gateway; `sequenceType=first` methods GET for EUR 12.34 returned iDEAL, creditcard and Bancontact |
| Real authenticated client page | PASS | Native HTTP login and owned-invoice page rendered the required unchecked consent control |
| Real first payment, mandate and public webhook | BLOCKED | Native client API CSRF rejection occurred before any Customer/payment POST; details below |
| Interactive browser | BLOCKED | No interactive browser run was completed; HTTP probes are not browser evidence |

The suites retain every existing one-time checkout/native accounting regression and add 64 unit/SQLite cases plus four MariaDB cases. They exercise explicit consent/refusal and stale consent versions, native session nonce verification after dispatcher parameter stripping, unauthorized ownership and supplied-context attacks, first/oneoff conflicts, exact request/idempotency replay, lost responses, expired replay windows, credential/profile/environment isolation, invoice reassignment or credential rotation during Customer resolution, all nonpaid states, duplicate/out-of-order callbacks, exactly-once settlement, missing/pending/invalid/unsuitable mandates, exact payment/Customer/mandate association, scope filtering, read-only recovery, event dispatch before mandate network calls and additive SQLite/MariaDB preservation. Provider calls in these suites are mocked; even the Live-mode fixture uses only generated dummy keys.

Fix/retest notes: initial new tests exposed an invalid fixture response helper, order-sensitive array comparison, an unquoted SQL fixture table name and use of an obsolete Twig filter. These were corrected using actual SDK response constructors, semantic request comparison, DBAL identifier quoting and real native Twig filters. Review also added locked-owner/current-credential guards, released the creation lease before paid-checkout mandate reconciliation, moved native payment-event dispatch before independent mandate network operations, and scoped client-identity resolution to first payments so ordinary hash-authorized invoice checkout remains available without a browser login. One initial full MariaDB run reported a sanitized `Payment_Exception` from a competing webhook worker; it did not reproduce in five targeted race runs or three subsequent full 17-test runs. The final full suites passed again after the last source changes. This is not evidence that real network outages or provider races were exercised.

Real HTTP validation used a newly issued native EUR 1.00 disposable service invoice, ID 32. Initial temporary harness runs hit a CLI session/output-order issue; output buffering fixed that harness problem. Three generated disposable clients and the single unpaid invoice were retained rather than deleted. Native login and the module page succeeded, but both the onboarding POST and the unrelated native `client/profile/get` request returned HTTP 403, `CSRF token invalid`. The form nonce matched the native CSRF cookie; a read-only comparison did not find that nonce in the persisted session. Stable browser headers and the existing tunnel warning header did not resolve it. The host/session/tunnel cause is not established, and no host code, session contents or security settings were changed to bypass the rejection. The apparent consent-refusal response in this HTTP harness was also blocked at CSRF and is **not** counted as real refusal-action validation.

The real first-payment creation, hosted payment completion, mandate GET/scope verification, browser return and public first-payment webhook were therefore **not executed**. No Customer or payment POST reached Mollie in this issue #8 validation. A final inspection confirmed invoice 32 remained unpaid with zero attempts, Customer mappings, transactions and ledger rows. A separate before/after additive-update comparison found every existing row in invoice, balance, transaction, attempt, Customer, gateway and order tables byte-for-byte unchanged. No database was reset except the expressly disposable `molliepayments_test` integration database, and no Live credentials were used. Temporary harnesses kept credentials/cookies in memory and emitted no raw exceptions, response bodies or personal/payment details.

Real Test API method availability is separate from real onboarding evidence. Production readiness still requires resolution of the native session/CSRF blocker and an actual successful consenting first-payment/mandate/public-webhook run. Mandate management remains for issue #9; automatic collection, revocation UI, retry scheduling and Subscriptions are outside this change. No new generic billing feature or established upstream defect was identified, so the core-proposal document was left unchanged. No issue #8 GitHub Actions result is claimed because this work was not pushed. Local logs use `/tmp/mp-issue8-*.log` outside the repository.

## Consent entry point and manifest repair — 2026-11-06

Authenticated invoice owners now reach the consent/one-time choice page by selecting MolliePayments in the native invoice payment flow. The adapter creates no payment before that choice. A separate authenticated, CSRF-protected one-time action retains ordinary checkout without Customer creation or consent. Guest/hash-only checkout remains unchanged; active payment sequence conflicts still fail closed. No persistence or accounting transitions changed.

Removed the legacy `icon_url` field from the v1 manifest; the host discovers the original root icon. The manifest passed validation against the local host's official v1 JSON schema. All 254 unit/SQLite tests passed with 1,788 assertions, including the new entry-point, one-time choice, authentication, CSRF, ambiguous-gateway and native icon-discovery checks. PHP CS Fixer reported zero changes across 40 files, and `git diff --check` passed. Test fixtures support both older serialized gateway JSON and the updated host's array/encrypted-envelope representation, retaining native adapter decryption assertions.

Application logs also show CSRF 403 responses and extension-directory refresh 404 responses for MolliePayments. The directory response is separate from manifest validity and cannot be fixed by publishing a local manifest. No security bypass or host modification was made. Webserver logs show an initial PHP-FPM startup probe failure followed by successful startup, plus an unrelated blocked Orderbutton asset request. No new real consenting Hosted Checkout/public-webhook run, browser session repair, MariaDB run or GitHub CI result is claimed for this repair; the earlier real onboarding validation gap remains open.

## CI baseline icon compatibility — 2026-11-06

GitHub Actions [run 42275945489](https://github.com/StanLousberg/FOSSBillingMollie/actions/runs/42275945489) failed on both pinned-host matrix jobs because the new smoke test required automatic package icon discovery. The pinned host `97bb583dd681e311e2d10fe8c648095f2a479878` returns a null icon without the legacy manifest field; current hosts discover the package icon. Reproduced the exact failing assertion locally using a separate temporary snapshot of the pinned host and its installed Composer lockfile dependencies. The existing development host and its vendor files were not modified.

The smoke test now checks native loading of module identity, name, version, author and homepage and accepts either supported host icon result. Independent inline SVG and gateway logo checks still verify the unchanged assets. Installation notes explain the older host's cosmetic default-icon fallback. The schema-invalid `icon_url` field remains absent; production behavior, persistence and payment state transitions are unchanged.

Local verification passed on the pinned host (254 unit/SQLite tests, 1,794 assertions; 17 MariaDB tests, 165 assertions) and the current local host `b25a4779221014f7c243e4bd75e563eccb5ebb23` (254 unit/SQLite tests, 1,798 assertions; 17 MariaDB tests, 165 assertions). PHP CS Fixer and `git diff --check` passed. An initial pinned-host MariaDB run reported a sanitized settlement failure from one competing first-payment webhook worker. It did not recur in ten targeted worker-race runs or three subsequent full 17-test suites; the underlying intermittent concurrency risk is not claimed fixed. Temporary settlement diagnostics were removed. Logs are in `/tmp/molliepayments-ci-*.log`; no new GitHub Actions run or real Mollie checkout is claimed.

## Callback snapshot ordering and one-time checkout correction — 2026-11-06

GitHub Actions [run 42277239018](https://github.com/StanLousberg/FOSSBillingMollie/actions/runs/42277239018) again failed the simultaneous first-payment webhook test on both hosts. Repetition alone had not isolated the cause: another 100 targeted worker runs and ten full MariaDB suites passed before the fix. A deterministic competing-metadata writer then reproduced the same sanitized settlement exception before the attempt lock. The local MariaDB 11.8.6 server uses `REPEATABLE READ` and `innodb_snapshot_isolation=ON`; a separate snapshot probe confirmed error 1020 (`Record has changed since last read`). The invoice refresh could establish a snapshot before the attempt lock while independent post-commit mandate verification changed that attempt.

The callback now gets an invoice reference without an initial SELECT, acquires the native invoice lock and attempt lock, then refreshes invoice state. Synchronization, settlement and non-paid updates use the same ordering. No accounting retry, new state, migration, core modification or provider call under financial locks was added. The deterministic integration test executes the real callback with a competing database connection at the former race point: it failed before the fix and passes after it. It requires the attempt to be locked before invoice refresh, exactly one receipt, the native settlement guard and a ready mandate. The original independent-worker test remains unchanged.

Ordinary gateway checkout now goes directly to a `oneoff` payment for authenticated owners as well as guests. The authenticated-owner regression checks that no consent screen, Customer call or consent record is introduced. Explicit consent remains required only on the separate first-payment onboarding route for future collection; automatic recurring collection remains disabled.

Final full suites passed on both hosts: 254 unit/SQLite tests (1,793 assertions on the pinned host and 1,797 on the current local host) and 18 MariaDB tests (180 assertions each). The original simultaneous-worker test and deterministic race test also passed together in twenty consecutive runs on the pinned host (two tests, 29 assertions per run). PHP CS Fixer reported zero changes across 40 files and `git diff --check` passed. Temporary settlement diagnostics were removed. This validation uses mocked Mollie responses and the guarded disposable `molliepayments_test` database; no real first-payment checkout, public webhook or new GitHub Actions result is claimed. Local logs use `/tmp/molliepayments-final-*.log` and `/tmp/molliepayments-lock-order-*.log`.

## Issue #8 recurring eligibility and Bootstrap review — 2026-11-06

Reviewed `3ef41189833562d6efceb00632504cbb6552894f` and the complete branch implementation against issue #8. This section supersedes the earlier routing description: normal authenticated checkout now detects an eligible single recurring order and presents the authorization choice before any attempt exists. One-time, custom, mixed and multiple-order invoices do not grant mandate authorization. The policy, native subscription distinction and immutable order association are documented in [PAYMENTS.md](PAYMENTS.md#first-payment-onboarding-issue-8). No financial state transition, transaction claim, lock order or automatic collection engine was added. Existing historical broad consent remains preserved; it cannot become order-scoped readiness.

Local verification used PHP 8.3.30, MariaDB 11.8.6, current host `b25a4779221014f7c243e4bd75e563eccb5ebb23`, and the existing separate temporary pinned host `97bb583dd681e311e2d10fe8c648095f2a479878`. No development host core or vendor file was modified.

| Category | Result | Evidence |
| --- | --- | --- |
| Composer strict validation | PASS | Repository `composer.json`, `validate --strict --no-check-publish` |
| PHP syntax | PASS | All 42 non-vendor PHP files |
| PHP-CS-Fixer dry run | PASS | 42 files, zero fixes required after correcting one blank line |
| Complete unit/SQLite suite, current host | PASS | 294 tests, 2,093 assertions, no skips |
| Complete unit/SQLite suite, pinned host | PASS | 294 tests, 2,089 assertions, no skips |
| Complete disposable MariaDB suite, both hosts | PASS | 18 tests, 180 assertions per host, no skips |
| Targeted independent workers/callback race | PASS | Current host: 3 tests, 36 assertions; simultaneous checkout, first-payment webhook workers and deterministic snapshot race |
| Host compatibility/accounting/schema | PASS | Both full suites retain encrypted credential/blank-update/masking, native Twig/manifest, one-time accounting, duplicate/out-of-order callbacks, failed settlement rollback and additive historical-record preservation checks |
| Diff whitespace | PASS | `git diff --check` |
| Real native browser login and recurring invoice routing | PASS | Chromium logged in with the disposable client's actual password; native `/invoice/banklink/HASH/GATEWAY` reached the consent page with zero attempts for that invoice |
| Bootstrap rendering | PASS | Actual native client theme; correct order, invoice, EUR 1.00 and monthly period, unchecked checkbox; no horizontal overflow at 390 and 1280 pixels; desktop screenshot inspected |
| Real one-time route and refusal alternative | PASS | One ordinary order invoice and one recurring order invoice each have exactly one real Mollie Test `oneoff` attempt; no consent or Customer mapping; the secondary browser form reached provider creation |
| Authenticated first-payment POST / direct refusal POST | FAIL | Real browser POST returned native HTTP 403 `CSRF token invalid`; no first attempt or Customer mapping was created |
| Complete first Hosted Checkout / public first-payment webhook / native paid settlement / mandate / callback replay | BLOCKED | First-payment creation was blocked before provider activity; none of these real scenarios is claimed as executed |
| New cart order placement | BLOCKED | Browser routing used newly seeded disposable host order entities and native issued invoices, not a completed cart-placement run |

The 40 additional unit/SQLite cases cover single-order initial/renewal routing, genuine one-time orders, custom/deposit/missing/foreign/canceled/invalid-period/mixed/multi-order denial, scheduled cancellation, hash access including the native guest identity exception, stale or tampered consent text digests, immutable order/period association, changes during Customer resolution, GET-only resume for both sequences, no creation from absent attempts, readable Bootstrap state displays, unchecked accessible consent, shared submission disabling and historical v1 consent settlement without readiness. Existing first-payment and accounting tests now use actual recurring-order fixtures and native order bookkeeping rather than custom invoice lines. These automated provider responses remain mocked, including worker tests.

Browser execution initially lacked Chromium's `libnspr4` and `libnss3` dependencies. They were downloaded and extracted under `/tmp` and supplied through `LD_LIBRARY_PATH`; no system packages, frontend dependencies or host files were changed. Chromium then ran normally. No screenshot or browser trace containing credentials or provider details was committed.

### CSRF investigation: immediate failure established, underlying host lifecycle unresolved

Both origins were inspected. The direct DDEV login page renders form actions targeting the configured public `SYSTEM_URL` origin. Consequently, mixing direct DDEV navigation with tunnel POSTs cannot preserve same-origin cookies. This is a configuration/origin concern, not evidence that native CSRF should be bypassed. A fresh direct-host session's native POST passed CSRF and reached the expected unauthenticated HTTP 401.

The configured public origin was then used consistently with a real Chromium login, normal native session cookies, native hidden form fields, browser `FormData`, `credentials: same-origin`, and the tunnel warning header. Login returned HTTP 200 and owned-invoice navigation worked. On the affected recurring onboarding page, the form nonce differed from the browser's native CSRF cookie. Both `client/client/profile_get` and `client/molliepayments/onboarding_start` then returned HTTP 403 `CSRF token invalid`; submitting the native cookie token to the native API also failed. Reloading changed the displayed nonce. Read-only database comparisons found the session row and a CSRF key, but neither observed browser nonce in that row. Only boolean comparisons were emitted; no session content, cookies or nonce values were logged. This localizes the immediate failure to inconsistent rendered/cookie/persisted native nonce state. It does **not** prove a particular low-level storage or fingerprint defect. Cross-origin mismatch alone does not explain the configured-origin failure.

The secondary one-time action nevertheless created a real Test payment for its separate invoice. Therefore this is not described as a universal API outage. The actual consenting first-payment browser submission was separately attempted and again failed at native CSRF before any provider Customer/payment creation. The underlying intermittent/session-lifecycle cause remains unresolved. The native `TwigFactory` nonce/cookie generation, `Api\\Controller\\Client::_checkCSRFToken`, session regeneration, PDO session storage and SameSite behavior were inspected. No session contents, fingerprints, CSRF settings or authentication identities were modified, and no host security workaround was introduced.

The new disposable fixture consists of one native client, three order entities and three EUR 1.00 invoices issued through the native invoice service. These records and the two open Test one-time attempts were retained. A final read found zero Customer mappings for that client and zero settlements across those invoices; the consenting recurring invoice has zero attempts. No real payment was completed and no new first-payment webhook or mandate was exercised. Provider first-payment/Customer semantics were rechecked against [Mollie's recurring guide](https://docs.mollie.com/docs/recurring-payments), [Create payment](https://docs.mollie.com/reference/create-payment) and [Get mandate](https://docs.mollie.com/reference/get-mandate), plus installed SDK request types; this contract review is separate from real provider validation.

Issue #8 is **not declared complete**: authenticated onboarding POST, real paid first-payment/mandate/public-webhook evidence and a full cart-origin flow remain outstanding. Automatic collection and `supports_subscriptions` remain disabled. All destructive fixtures were confined to `molliepayments_test`. Only the existing branch was edited; local test logs use `/tmp/mp-review-*.log`. No push, branch switch or PR update was performed.

## Transaction links, Customer details and timestamps — 2026-11-07

Reviewed the native `ipn.php`, transaction creation/claim/processing/update contracts and entity relation, all adapter callback states, Customer request replay/storage, installed SDK v4 request constructors, host bootstrap timezone and native Doctrine timestamps. Work remains on `8-implement-first-payments-and-mandate-onboarding`, with no core/vendor changes, branch switch, push or PR modification.

Verified fixes:

- New/unprocessed orphan transactions are associated only after authenticated provider/attempt verification, under the existing native claim and invoice → attempt → transaction lock order. A different invoice, gateway or already populated provider transaction ID cannot be overwritten. Missing GET invoice hints are supported; forged hints are rejected. Processed orphan history is refused and a read-only CLI reports local candidates without claiming provider verification. No new invoice/payment/attempt is created for association recovery.
- New Customers send normalized native full names and valid emails through the installed `CreateCustomerRequest`. The personal-field snapshot is encrypted through native `crypt` in the existing request JSON and bound to its mapping ID. Exact retries survive native profile edits; metadata-only legacy snapshots and existing Customer IDs remain unchanged. No silent update/enrichment path or locale override was added.
- Epoch seconds and elapsed-time protections were already correct. Actual host `i18n.timezone` and PHP were UTC, MariaDB session timezone was `+00:00` (global `SYSTEM`), and PHP/MariaDB epochs matched exactly. The reported local-time discrepancy is consistent with viewing UTC as Europe/Amsterdam; this does not prove that every historical naive DATETIME is correct. No historical times or clock settings were changed. The status CLI now presents explicit local ISO offsets next to original epochs, following the timezone initialized by the host.

Final local checks on PHP 8.3.30 and MariaDB 11.8.6:

| Category | Result | Evidence |
| --- | --- | --- |
| Composer | PASS | `validate --strict --no-check-publish` |
| PHP syntax | PASS | All 50 non-vendor PHP files |
| PHP-CS-Fixer | PASS | Final dry run: zero fixes across 50 files |
| Full unit/SQLite suite | PASS | 343 tests, 2,577 assertions; no skips |
| Full disposable MariaDB suite | PASS | 22 tests, 259 assertions; no skips |
| Separate worker and deterministic race reruns | PASS | 5 independent-worker cases / 50 assertions, plus snapshot race / 15 assertions |
| Host compatibility and regression contracts | PASS | Native claims, public entity association, actual Twig/credentials, first/oneoff, mandate verification, failed-transaction rollback and historical/additive preservation remain covered by the full suites on the current development host |
| Diff/credential review | PASS | `git diff --check`; no runtime credentials, personal records, raw requests or provider IDs added to tracked files |

The added tests exercise provider-verified orphan linking for both sequences in all seven payment states, absent/forged GET hints, conflicting native relation/payment ID/gateway, provider mismatch/outage, processed-history immutability and read-only diagnostics. Two independent MariaDB callback workers link their native rows while recording one settlement. Customer cases include Unicode/whitespace, valid/IDN/invalid/missing email, missing/bounded names, omitted locale, encrypted corruption/binding failures, lost response and profile edits, legacy replay and native encryption. Current native email setters reject some legacy values, so tests simulate Doctrine-hydrated legacy data without changing host setters. UTC/Amsterdam tests cover winter, summer and both DST transitions, unchanged historical rows, native DATETIME literals, absolute epochs, 180-second leases and 45-minute retry refusal. MariaDB sessions at `+05:30` also preserve PHP-supplied native DATETIME and module epochs.

Initial new-suite failures were fixture issues: native email validation rejected legacy examples, SQLite refreshed zero decimals as `"0"`, a MockResponse helper required its third argument and Pest treated `Throwable` as a message rather than a concrete exception class. Corrected the fixtures and reran full affected suites. One separate worker command was interpreted as shell pipes by DDEV because of a regex filter; simple worker/snapshot selections then passed. None of these failures was hidden as a production fix.

### Historical orphan inventory

Read-only inspection found 11 MolliePayments transactions with a null invoice relation. All were already `processed`, all amounts were zero, all stored GET invoice/attempt hints, all had a matching local payment attempt, and none was that attempt's recorded settlement transaction. No unprocessed orphan was found in this host inventory. Current `ipn.php` forwards top-level invoice IDs, whereas permissive/historical `create()` callers can omit them; the stored nested hints cannot reconstruct the original top-level call or prove why each historical row was created. No individual row is labelled provider-verified on that evidence alone. Bare public form callbacks without a top-level invoice ID are rejected by the current host before adapter dispatch. The new null-association tests exercise native permissive creation/claimed processing; they do not claim to change that public entry-point limitation. Real callbacks used the module’s invoice-bound URLs.

The diagnostic emitted no raw IPN or personal fields. A before/after comparison of the inspected rows proved the diagnostic itself read-only. All 11 processed rows were left untouched. Normal native updates freeze processed history; an audited upstream/operator association-only procedure is required if independently verified corrections are desired. No SQL backfill, reprocessing of processed history, balance adjustment or timestamp migration was performed.

### Real browser and Mollie Test validation

A new disposable native client and two native issued EUR 1.00 order invoices were created for validation. Browser login used the client's actual password on the configured public origin. Chromium's missing `libnspr4`/`libnss3` were downloaded and extracted only under `/tmp`; no system packages or frontend dependencies were installed. No identity/session injection, CSRF bypass or core modification was used.

The ordinary invoice route opened actual Mollie Test `oneoff` checkout directly. The recurring invoice route displayed onboarding; explicit consent subsequently created one Test Customer mapping and one `first` payment. Authenticated provider GETs confirmed Test mode, exact opaque metadata, and provider name/email equal to the encrypted original snapshot. The persisted request had encrypted details and no plaintext email. Retries/resumes retained the same mapping and payment attempt.

The first browser submission received HTTP 503. Later native submissions/resumes worked, despite observed intermittent CSRF/session mismatches; earlier validation's blanket first-payment block therefore no longer describes every run. An initial simulator driver selected a hidden submit control and timed out before any payment completion. Selecting the visible official Continue control completed the existing Test first payment through iDEAL/ABN AMRO and returned to the host. This was an actual Mollie Test payment, not a fake SDK response.

The public webhook settled the native recurring invoice with one receipt and one correctly linked native transaction. Onboarding became `ready`. Independent authenticated GETs then verified the exact mandate's valid status, Test mode, Customer association and `customer-not-present` scope. Native invoice payment time used UTC and was within 120 seconds of the provider paid instant. An explicit POST replay to the saved public webhook returned HTTP 200: transaction audit rows increased to two, the client's complete ledger remained unchanged, receipt count remained one and the settlement guard retained the original transaction. No recurring debit was initiated.

The existing ordinary `oneoff` Test payment was then also completed through the official simulator. Its public webhook paid the other native invoice with one linked transaction and one receipt; consent/onboarding remained absent. Final authenticated provider/database reads found one Customer mapping total, one attempt per invoice, both provider payments and native invoices paid, and first onboarding still `ready`.

The seeded order invoices exercise the real native invoice/banklink path, not cart placement. Browser/session intermittence remains a separate host/environment limitation; this review does not claim its underlying cause repaired. No new real Live operation, refund, chargeback, missing/invalid mandate scenario or automatic collection was performed; unsupported-mandate/failure paths remain mocked automated evidence. The disposable client, invoices, provider Customer, mandate and payment attempts were retained. Only `molliepayments_test` was reset by destructive integration fixtures. Sanitized logs and temporary harnesses use `/tmp/mp-links-*` outside the repository.

## FOSSBilling native reuse review — 2026-11-07

Adapter baseline: freshly fetched PR #18 branch `8-implement-first-payments-and-mandate-onboarding` at `d5c326a0c58e37230d84344fac38669997ed372b`. Work branch: `refactor/fossbilling-core-reuse-pr18`, targeting that feature branch. Exact source comparisons and per-area decisions are in [FOSSBILLING_NATIVE_REUSE_AUDIT.md](FOSSBILLING_NATIVE_REUSE_AUDIT.md).

Official Core sources were freshly cloned into a temporary bare repository. Installed host and fetched upstream main both resolve to `7bc90195a535a03d33b4d0db91f41ba6989dee8a`; existing CI pin is `97bb583dd681e311e2d10fe8c648095f2a479878`; latest stable tag `0.8.9` resolves to `e594f8a5f91ea4cc905708f8dc1e42ccb752ed21`. The fetched tags contain no newer stable release. Pinned and stable archives were unpacked into DDEV `/tmp/mp-native-pinned` and `/tmp/mp-native-stable`, with their own lockfile installations and sample configurations. Upstream main's source is the installed host's source; its existing dependencies and native Pest bootstrap were used directly.

Changes reuse native email validation, invoice-item repository retrieval and Transaction timestamp callbacks. They add no schema migration or payment/consent state transition. Both module epoch schemas, immutable requests, mapping identity, native claims, lock order and atomic settlement bridge are retained. `AGENTS.md` permanently requires native source inspection/reuse and now describes implemented first-payment onboarding and mandate verification accurately.

Executed with PHP 8.3.30 / MariaDB 11.8 through the existing DDEV project:

| Check | Installed / upstream main | Exact CI pinned host |
| --- | --- | --- |
| Baseline module unit/SQLite suite before runtime refactor | 343 tests / 2577 assertions passed | Not rerun before refactoring |
| Full module unit/SQLite suite after refactor | 359 tests / 2664 assertions passed | 359 tests / 2660 assertions passed |
| Full MariaDB integration suite | 22 tests / 259 assertions passed | 22 tests / 259 assertions passed |
| Final transaction-association fixture regression | 28 tests / 277 assertions passed | Covered in final full suite |
| Native Invoice service / Transaction service / InvoiceItem repository tests | 270 tests / 1927 assertions passed | Source inspected; separate host suite not run |
| Composer validation (`--strict --no-check-publish`) | Passed | Own Core lockfile installation passed |
| Module/gateway PHP syntax and project PHP-CS-Fixer | Passed, all 50 PHP files | Same module sources; no separate style run needed |

Stable 0.8.9 was bootstrapped with its own dependencies and a disposable sample configuration, then `Compatibility::assertHost()` was invoked without database operations. It rejected the unsupported native contracts with the expected `Payment_Exception`. Payment tests on stable would be invalid because it lacks the required Doctrine invoice/transaction entities and locking APIs; stable remains explicitly unsupported, not a passing payment target.

Module suites cover SQLite and independent-worker MariaDB concurrency, redelivery, transaction association/orphan recovery, atomic accounting/rollback, Customer creation/encrypted immutable replay, mandate consent/verification, UTC/Europe-Amsterdam winter/summer and both DST transitions, differing MariaDB session offsets, elapsed retry/lease deadlines, and additive upgrades preserving historical rows/unknown columns. New tests age native transaction timestamps before pending/failed/paid/duplicate processing, check callback updates through real ORM lifecycle/reload and ensure processed replays do not mutate history. Email edge cases retain native identity and provider payload behavior; foreign invoice items cannot enter the authorization scope or amount.

The first pinned run exposed existing fixture assumptions: raw IPN is serialized JSON on pinned, an array on main. Tests now use each host's native service accessor (`getDecodedIpn()` / `getIpnPayload()`), and fixture writes match the actual entity property type. An intermediate rerun used main's renamed accessor on pinned; selecting the native accessor by availability resolved it without changing runtime processing. The copied sample configuration also generated a different salt on each include, making real native decryption fail. CI now evaluates the sample once and persists its resulting array only in the disposable checkout. Final pinned and installed suites pass; no assertions were removed or skipped to hide these failures. The pinned/main × SQLite/MariaDB CI matrix remains intact.

Commands used from the host root include:

```bash
ddev exec ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Unit
ddev exec env MOLLIEPAYMENTS_MYSQL_URL=mysql://db:db@db:3306/molliepayments_test ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Integration
ddev exec composer validate --strict --no-check-publish --working-dir=.local/MolliePayments
ddev exec ./src/vendor/bin/php-cs-fixer fix --config=.local/MolliePayments/.php-cs-fixer.php --dry-run --diff --sequential
```

Pinned runs used the same Pest arguments after changing directory to `/tmp/mp-native-pinned`. Host-side logs use `/tmp/mp-native-*.log`; temporary checkout Composer logs are inside DDEV `/tmp/`. MariaDB fixtures enforce the exact disposable database name `molliepayments_test`; the normal development database was never reset or used for these fixtures. All automated provider requests use SDK fakes/dummy keys. No Core/vendor changes, credentials or financial personal data are included in this deliverable. Existing host cache/link status was preserved.

Remaining limits: stable is incompatible by design; PostgreSQL and the full unrelated Core suite were not run. No new interactive browser, real Mollie Test/public-webhook or production validation was performed in this refactor. Earlier manual evidence and its gaps remain as documented above. No production-readiness or automatic-recurring-collection claim is made.

## Development DATETIME schema — 2026-11-07

At the project owner's explicit request, module integer epochs have been replaced with native Doctrine DATETIME columns (`DATETIME_IMMUTABLE`) for creation/update, consent, mandate verification and lease expiry. Stored UTC strings and host-local CLI presentation use `Y-m-d H:i:s`, for example `2026-11-03 16:32:13`. Inactive lease expiry is null. Conditional DBAL updates, fenced workers and elapsed 45-minute retry protection are preserved; UTC storage avoids DST overlap ambiguity. The earlier epoch-retention audit recommendation is superseded for this development-only change.

No migration, historical conversion or normal development database reset was performed. The owner will recreate the development database; former integer-timestamp tables are not an in-place upgrade target. The documentation and permanent agent instructions reflect the new schema.

Executed through DDEV with mocked provider responses and disposable databases:

- Installed host: full module unit/SQLite suite passed, 361 tests / 2653 assertions.
- Existing isolated pinned Core host: full module unit/SQLite suite passed, 361 tests / 2649 assertions.
- MariaDB integration suite passed, 22 tests / 251 assertions, using only `molliepayments_test`.
- PHP syntax, PHP-CS-Fixer dry run, Composer strict validation and `git diff --check` passed.

Regressions cover the readable stored/display format, native Doctrine date mappings, null lease release, UTC/Amsterdam presentation and both DST transitions, absolute retry/lease expiry, session timezone offsets, concurrent webhook/accounting behavior and unchanged encrypted snapshot replay. Logs use `/tmp/mp-datetime-*.log`. No new real-provider or browser validation was run.


## Authorization page revision (2026-11-07)

Base: PR #18 head `1039b8d2fc70b8b2bdbd1b860021c4ca69a9f52c`, including merged PR #19. Validated with installed FOSSBilling `7bc90195a535a03d33b4d0db91f41ba6989dee8a`, PHP 8.3.30 and the mocked Mollie SDK. The development/CI pin now matches this installed host: its public renewal resolver replaces the private method in the previous pinned host. Stable 0.8.9 remains unsupported; it lacks the required Doctrine/payment contracts and was inspected as an incompatible reference, not executed as a payment target.

- Module unit suite: **383 tests, 2868 assertions**, passing.
- Disposable MariaDB integration suite: **22 tests, 251 assertions**, passing, including independent workers, immutable first-payment retries and mandate verification.
- PHP CS Fixer: **50 files**, no changes required.
- Consent tests reject stale v2 forms and changed company, order title, interval, recurring price, first-payment amount or digest before provider activity. Existing v2 attempts resume, retry an unresolved creation with the original request/key, settle and verify mandates without rewriting evidence.
- Render tests use native Twig filters and check escaping, CSRF, unchecked consent, Bootstrap responsive columns/wrapping, absence of custom CSS/authorization steps, and actions for all persisted payment states. Pricing tests cover quantities, native renewal overrides/discounts, invoice tax and monthly, quarterly, annual and biennial periods.

Responsive validation here is rendered-markup validation; no new browser viewport or real Mollie checkout/public-webhook run was performed for this page revision. Automatic recurring collection remains disabled.

## Payment modes and custom cart consent (2026-11-07)

Base: latest PR #18 head `7a2998041b9ab0cb368ab213572d2a1a91b5cbad`, on the existing feature branch. Inspected installed/pinned Core `7bc90195a535a03d33b4d0db91f41ba6989dee8a`, its gateway config template/service, native cart API/service and tests, invoice hash repository, and invoice authorization/payment dispatch. Compared with stable 0.8.9: native select config and cart result shape exist there, but its legacy model/payment architecture remains unsupported by this adapter. No Core or vendor change is included.

Executed locally with PHP 8.3.30 and faked Mollie SDK requests:

- Module unit suite: **490 tests, 3327 assertions**, passing.
- Disposable MariaDB integration suite: **37 tests, 392 assertions**, passing.
- PHP CS Fixer: **54 files**, no changes required.
- All three modes cover direct/native/UI/API initiation, default configuration, invalid configuration, mode changes, unsupported invoices/guests, and preserved settlement/reverification after policy changes.
- Cart coverage includes explicit consent, current version/digest, native session CSRF, ownership, saved gateway identity, source evidence, company changes during authorization, mixed/multiple/one-time orders, repeat calls, immutable lost-response retries, historical source-less attempts and authenticated provider metadata tampering.
- Independent MariaDB workers race cart requests while preserving one reservation and original consent source. Existing atomic settlement, first-payment Customer identity, mandate verification and accounting regressions still pass.

No real Mollie API calls, new public-webhook evidence or browser cart checkout were performed for this feature. The actual custom theme still needs manual checks for login/CSRF, consent rendering and submission capture, correct native callback replacement, all three modes, selected-gateway changes, mixed/free/credit-covered carts, double clicks, lost responses and verified Test checkout/mandate recovery. See [CUSTOM_CART_CHECKOUT.md](CUSTOM_CART_CHECKOUT.md). Automatic recurring collection remains disabled.


## Final PR #18 hardening — 2026-11-07

See [PR18_HARDENING_REVIEW.md](PR18_HARDENING_REVIEW.md) for per-risk causes, actual SDK/Core contracts, changed behavior and operator recovery.

Reference: local/remote PR head `294de042114b342b8da320885efd54221bcc7fdd`; installed SDK **v4.0.0**. Pinned Core `7bc90195a535a03d33b4d0db91f41ba6989dee8a` and remote `main` were verified to be the same SHA, so both current CI matrix labels resolve to the locally tested source. No distinct second revision or GitHub Actions run is claimed.

Executed locally on PHP 8.3.30:

- All module unit/SQLite tests: **531 tests, 4020 assertions**, passing.
- All disposable MariaDB integration tests: **39 tests, 437 assertions**, passing, including duplicate callbacks, independent workers, immutable retries, concurrent missing-ID recovery, native named locking, mode matrices and one accounting receipt.
- Existing native API controller and Session tests: **144 tests, 459 assertions**, passing.
- PHP syntax: **57 own PHP files**, no errors; vendor excluded.
- PHP-CS-Fixer: **57 files**, no remaining changes.
- Composer: `composer validate --strict --no-check-publish` passed. No dependency or Core Composer change.
- `git diff --check` passed.

Additional native Core contract command:

```bash
ddev exec ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Api/tests/Unit/Controller/ClientTest.php tests/Unit/FOSSBilling/Core/SessionTest.php
```

The new real browser validation used a new disposable client and native EUR 1.00 recurring invoice, with welcome email disabled and the saved enabled Test gateway. Login (HTTP 200), owned page rendering, same-origin form action and form/decoded-cookie agreement were confirmed. Native `onboarding_start` and an unrelated native profile request both returned **403**, with an explicit native CSRF error. A read-only check found one different persisted session nonce. No nonce/session data, password, API key or provider/instrument response was logged; no session/token/security configuration was altered to bypass the failure.

The new invoice remains **unpaid**, with **0 attempts, 0 Customer mappings, 0 client ledger entries**. Consequently actual first-payment creation, Hosted Checkout completion, public webhook settlement and real mandate verification were **not executed** for this revision. Historical successful evidence is not counted as a new pass. Resolve the native session/public-deployment inconsistency and rerun the complete Test flow before unconditional merge approval. No Live payment was performed, no existing personal client/invoice/gateway record was edited, and only `molliepayments_test` was reset. The newly created disposable records are retained for audit.

Module CSRF tests cover native optional-empty-cookie semantics, matching/nonmatching session nonces, JSON/header/form/legacy-cookie transport and native session regeneration. Mandate tests cover missing ID/link, exact instrument matching (never last-four), incomplete/ambiguous results, pending-to-valid and late appearance, 24-hour escalation, transient 429/5xx/SDK transport errors, explicit invalid/deleted mandates, Customer lease contention, stale-lease fencing, five-attempt cron bounds, cooldown and recovery after a failed payment GET. No renewal collection scheduler or new provider POST was added.

## Final PR #18 corrections — 2026-11-07

This section supersedes the earlier instrument-discovery/24-hour recovery expectations and claimed-transaction cron path. See [PR18_FINAL_FIXES.md](PR18_FINAL_FIXES.md) for findings, the explicit-association limitation and operator recovery. The starting local/remote PR head was `0892c05152cdaaec6e93a7c0c711db8479c4c94a`.

Final verification on PHP 8.3.30 / Core `7bc90195a535a03d33b4d0db91f41ba6989dee8a` / private SDK v4.0.0:

- Full module unit/SQLite suite: **556 tests, 4406 assertions, 0 failures**.
- Full disposable MariaDB integration suite: **40 tests, 476 assertions, 0 failures**.
- Included regressions: repeated cron runs with unchanged native transaction/ledger/invoice snapshots and zero payment events; no native `createAndProcess()` call; concurrent mandate cron workers; duplicate callbacks and concurrent financial workers; immutable requests/consent and all payment modes; overlapping same-Customer/same-IBAN or fingerprint first payments; missing/late/wrong/conflicting explicit mandate references; pending-to-valid/invalid/review; seven-day inclusive expiry and held leases; late recovery after expiry; temporary provider outages and historical readiness preservation.
- PHP syntax: **58 own PHP files**, no errors, vendor excluded.
- Full uncached PHP-CS-Fixer: **58 files**, final dry run found **0 files** to fix, exit 0. The missing trailing comma identified by Actions run 42408055915 was corrected.
- `composer validate --strict --no-check-publish`: passed, no dependency change.
- `git diff --check`: passed.

Commands from the external FOSSBilling test-host root:

```bash
ddev exec ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Unit
ddev exec env MOLLIEPAYMENTS_MYSQL_URL=mysql://db:db@db:3306/molliepayments_test ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Integration
ddev exec ./src/vendor/bin/php-cs-fixer fix --config=.local/MolliePayments/.php-cs-fixer.php --using-cache=no --sequential
ddev exec ./src/vendor/bin/php-cs-fixer fix --config=.local/MolliePayments/.php-cs-fixer.php --using-cache=no --dry-run --diff --sequential
ddev exec composer validate --strict --no-check-publish --working-dir=.local/MolliePayments
ddev exec bash -lc 'find .local/MolliePayments/gateway .local/MolliePayments/module -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n 1 php -l'
```

Only disposable fixtures/the designated `molliepayments_test` database were reset; no saved personal development records or credentials were modified. Provider traffic in these tests is faked. No new real Test/Live payment or browser E2E was performed. Browser E2E is explicitly excluded from this correction round because of the known upstream Core CSRF issue, and no workaround or session change is introduced. It remains a production-validation limitation, not a blocker for the next technical review of these scoped corrections. Core, vendor, cart integration, UI styling and financial settlement/webhook implementations are unchanged. Automatic recurring collection remains disabled.

## Multi-order recurring authorization v4 — 2026-11-10

Starting adapter `origin/main`: `d1d769d2fd72b77c7901f4de04e22cb1a435c8e7` (clean working tree). Work stayed on `feat/multi-order-recurring-authorization`. No push or pull request was made.

The installed external host is `fb0e766d2a6ad7de505ac04c5947a0f5bc7e2f7b`, matching upstream HEAD read during this run. PHP 8.3.30, MariaDB 11.8, official SDK v4 and Chromium 148.0.7778.96 were used. The supported main host's actual Cart/Invoice/Order/Period entities, renewal pricing, invoice mutation locks, native session/CSRF and schema/cache services were inspected. Stable 0.8.9 remains unsupported; this run did not rerun the separate pinned checkout. CI retains the pinned/main matrix.

### Automated verification

| Check | Final result |
| --- | --- |
| Full module unit/SQLite suite | PASS — 600 tests, 5,008 assertions |
| Full disposable MariaDB integration suite | PASS — 41 tests, 510 assertions |
| PHP syntax, own files only | PASS — 60 PHP files |
| Uncached PHP-CS-Fixer final dry run | PASS — 0/60 files need changes |
| Composer strict validation | PASS |
| `git diff --check` | PASS |

The existing commands above were used from the external host root. Logs are `/tmp/mp-multi-unit.log`, `/tmp/mp-multi-maria.log`, `/tmp/mp-multi-style.log`, `/tmp/mp-multi-syntax.log` and `/tmp/mp-multi-composer.log`. Automated fixtures reset only isolated SQLite databases and the explicitly guarded `molliepayments_test` database. They never mutate development clients, invoices or transactions. Provider calls in these suites are SDK fakes.

New coverage includes single/two monthly/monthly-plus-annual/mixed/three-order invoices; setup and discount rows; deduplication and deterministic ordering; one-time-only carts; foreign/canceled/suspended/cancellation-scheduled/mismatched/unknown order references; replacement and same-total line reallocations; stale text/digest/hash; local/provider metadata tampering; all payment modes; exact v2/v3 replay with and without provider IDs; additive nullable scope upgrades; native activation identifiers; readonly rejection of changed existing readiness/resume; Customer reuse; duplicate callbacks; independent MariaDB mixed-cart workers; pending-to-ready mandates and repeated verification with unchanged financial rows. Initial failures were obsolete single-order expectations, fixture schema-column ordering and a missing native Session DI setup on current Core; the final suites above passed without skipping these cases.

### Actual local browser E2E

Playwright drove real Chromium against the existing `https://drained-movie-catapult.ngrok-free.dev` installation. The normal ngrok **Visit Site** button was used. Missing Chromium NSS/NSPR libraries were extracted under `/tmp`; no system package, Core, vendor, SDK, theme or session-security change was delivered. Admin login used the supplied local test account; no credential, session token or provider key was saved in source, fixtures, screenshots or documentation.

All client/product/order/invoice creation and gateway-mode changes occurred through native admin/client panel forms. No SQL INSERT/UPDATE/DELETE, direct fixture-creation API or CLI data factory was used against development data. Read-only SELECTs verified new attempt/Customer/ledger observations. The existing module schema upgrader performed only the additive module upgrade. In this symlinked install its first invocation saw cached Doctrine mapping; native **Extension settings → System → Cache → Invalidate cache**, followed by the same upgrader, added `consent_scope`. The native cache action removed an empty tracked cache `.gitkeep`; that empty file was restored. Existing unrelated Core working-tree state was retained.

| Scenario | Result | Observed evidence |
| --- | --- | --- |
| 1. One recurring product | PASS | Invoice 64, order 49, monthly EUR 1.00; both choices, matching consent and unchecked checkbox |
| 2. Two monthly products | PASS | Native shared cart → invoice 65, orders 50/51, one EUR 3.00 total, both recurring products listed |
| 3. Monthly + annual | PASS | Native shared cart → invoice 66, orders 52/53, EUR 4.00; separate monthly/yearly labels and prices |
| 4. Recurring + one-time | PASS | Native shared cart → invoice 67, recurring order 55 and one-time order 54; EUR 5.00; one-time product explicitly excluded |
| 5. Three products | PASS | Native shared cart → invoice 68, recurring orders 57/58 and one-time order 56; EUR 8.00; monthly/yearly scope shown separately |
| 6. Pay once | PASS | Invoice 65 opened real Mollie Test Checkout for EUR 3.00; one stored `oneoff`, null consent/scope, no Customer at that point |
| 7. Authorize and pay | PASS | Invoice 68 opened one real Test `first` for EUR 8.00; local v4 scope contains only 57/58; iDEAL/ABN AMRO Test simulator Paid completed through the browser; public webhook settled the native invoice and attempt; explicit linked mandate became `valid`/`ready` |
| 8. Payment modes | PASS | Admin saved `both` → `one_time_only` → `recurring_only` → `both`; mixed invoice 67 respectively showed both/Pay once/Authorize and pay/both; original Test mode and `both` restored |
| 9. Security/repeated actions | PASS | Unchecked checkbox prevented a browser POST; existing first-payment resume returned the same checkout with one attempt; paid invoice 68 and onboarding offered no new payment; client 16 received module HTTP 403 and native invoice navigation redirected to its own invoice list; consent/request/timestamp hashes stayed unchanged across modes |

Mobile at 390×844 had no horizontal overflow; the native Bootstrap layout displayed intervals, the excluded one-time product and the full invoice total. Desktop and mobile page captures are temporary artifacts under `/tmp/mp-multi-*.png`, without credentials. The final checkbox clause is shown once; immutable full consent text additionally enumerates the displayed orders.

Native requests intermittently returned `403 CSRF token invalid`: initial client creation and cart add succeeded only after ordinary fresh panel loads. Two password-update attempts on the disposable client remained rejected and were not forced through. A temporary driver selector timeout required restarting Chromium; the native admin **Login to Client Area** action restored authenticated access to that disposable account. Module CSRF checks remained enforced. The resumed Test checkout returned to the configured FOSSBilling origin in a fresh browser context, which required login; the authenticated native client panel then displayed **Paid** for `FOSS00052` (invoice 68). Continuous-session return preservation was not newly certified by that restarted run. No session/nonce/cookie injection or security-setting change was used.

After the public webhook, read-only checks found invoice 68 `paid`, attempt `paid`, onboarding `ready`, mandate `valid` with an explicit bound ID, one Customer mapping, one native payment credit and one invoice debit. Three browser **Check payment authorization** calls returned HTTP 200 and retained readiness. Before/after hashes of the native transaction rows and client ledger matched exactly; no additional accounting or native transaction-history mutations occurred. Original consent/request/acceptance hashes also remained equal after all modes and verification. Invoice 65's open oneoff was intentionally preserved and never replaced or forced terminal.

### Retained panel-created data and limits

- Clients 15 (**E2E MultiOrder Validation**) and 16 (**E2E MultiOrder Second Client**), welcome email disabled; passwords are not retained here.
- Products 9–12: **E2E MultiOrder Monthly 20261110**, **E2E MultiOrder Monthly Two 20261110**, **E2E MultiOrder Annual 20261110**, **E2E MultiOrder One Time 20261110**. Tested selections: 1M/EUR 1.00, 1M/EUR 2.00, 1Y/EUR 3.00 and once/EUR 4.00.
- Orders 49–58 and invoices 64–68; only invoice 68 was paid. Invoices 64, 65, 66 and 67 remain unpaid. There is one open Test oneoff on 65 and one paid Test first on 68. No existing customer/product/invoice/transaction was deleted.

The browser used native cart → gateway → onboarding. The optional custom-theme inline `cart_checkout` endpoint/source has full fake-provider SQLite/MariaDB coverage and an updated invoice-backed example; no installed custom theme was changed to exercise that separate inline UI. Real v2/v3 payments were not recreated; historical compatibility is protected by immutable replay/settlement regressions. This run observed an explicit valid mandate, but other real first payments may omit the explicit ID/link: those must remain pending and eventually require review rather than guessing a Customer mandate. Automatic future collection remains disabled and production readiness is not claimed. The code is ready for technical review with these operational/session and custom-theme validation limits recorded.

## Authorization lifecycle and partial order cancellation — 2026-11-10

This correction round deliberately ran **no browser/E2E/Playwright tests, real Mollie API requests, panel actions or development-data mutations**. The browser evidence in the preceding section belongs to the earlier v4 implementation and is not recertified here. All provider interactions below use SDK fakes and disposable SQLite/MariaDB fixtures.

The initial module working tree was clean. Branch `fix/recurring-authorization-lifecycle` was created from the newest local `main`, `d1d769d2fd72b77c7901f4de04e22cb1a435c8e7`. That local main did not contain reviewed commits `bcb6095`/`f6400ed`, so they were cherry-picked onto the new branch as `6a6765e18cd25c14a13f4dfd110ce75032f158dd` and `e20f102792916339c8fff20da72a2cad90cbf70f` before this correction. Main, existing local state and the external Core checkout were preserved. No push or pull request was made.

### Confirmed findings and correction

Both review findings were reproduced in source: `assertAuthorization()` reconstructed acceptance using current order names/prices/company/native renewal quotes and was called by settlement and mandate verification. `onboarding()` also called the all-or-nothing new-checkout classifier before loading stored evidence. Consequently normal lifecycle changes could strand payment processing or make historical pages inaccessible; one canceled order could block the entire multi-order consent.

The implementation now separates:

1. **New checkout / unresolved POST replay:** live invoice/order/period/price/text context remains strict before reservation and immediately before a new provider creation request. Changed or reassigned scope cannot create/replay a payment. Known provider payments use their original evidence and GET recovery; Customer creation is disabled on reuse.
2. **Historical proof / financial verification:** saved v4 version/snapshot/text/acceptance time/hash and invoice/client/gateway/environment/amount/currency references are checked without rebuilding mutable order data. Authenticated payment identity, Customer/profile, full metadata, amount/currency, paid timestamp and native settlement/idempotency/credential checks remain. V2/v3 use their original metadata and version-specific stored wording without upgrading or rewriting records. V1 remains ineligible for order authorization. Monetary comparisons canonicalize through the existing minor-unit helper so SQLite integer affinity does not alter two-decimal identity.
3. **Current applicability:** read-only native entity lookups consider only original order IDs. Cancellation, scheduled cancellation, different ownership/period/currency/product or a missing/inactive order exclude that order alone. Ordinary names, prices, configuration and activation identifiers do not erase historical proof. No new table, column, persistent status, event consumer, scheduler or automatic collection is added.

The Bootstrap page shows original consent/version/acceptance time and original order/period/price data, together with per-order current eligibility/exclusion reasons. A fully canceled authorization remains accessible, with no eligible orders, new payment or fresh-consent form. Mandate readiness remains independently verifiable. Existing `manual_review` is used for inconsistent original evidence or paid-payment verification; writes are fenced by the existing attempt lease and never change native financial rows.

Actual Core source inspected includes native order activation/update/cancellation events and metadata, `Order\Service::updateOrder()`/`cancelFromOrder()`/scheduled cancellation, `Invoice\Service::resolveRenewalLine()`, and domain registration/renewal title/config handling. The domain regression executes the actual native Invoice renewal resolver and domain title service: a pending first-payment order activates and resolves to a different renewal price without invalidating its accepted payment or mandate. No Core/vendor/SDK file is modified.

### Final automated verification

Installed external host: `fb0e766d2a6ad7de505ac04c5947a0f5bc7e2f7b`, PHP 8.3.30 and MariaDB 11.8. This round did not fetch upstream, rerun a separate pinned host or certify a new Core release. The existing CI pinned/main matrix remains. The explicit disposable MariaDB guard accepts only `molliepayments_test`; no test command targets existing panel records.

| Check | Final result |
| --- | --- |
| Full unit/SQLite suite | PASS — 654 tests, 6,347 assertions |
| Full MariaDB integration suite | PASS — 46 tests, 613 assertions |
| Total automated suites | PASS — 700 tests, 6,960 assertions |
| PHP syntax, own files only | PASS — 62 files |
| Uncached PHP-CS-Fixer final dry run | PASS — 0/62 files need changes |
| Composer strict validation | PASS |
| `git diff --check` | PASS |

Logs: `/tmp/mp-lifecycle-unit.log`, `/tmp/mp-lifecycle-maria.log`, `/tmp/mp-lifecycle-syntax.log`, `/tmp/mp-lifecycle-style.log`, `/tmp/mp-lifecycle-composer.log`. Commands from the external host root:

```bash
ddev exec ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Unit
ddev exec env MOLLIEPAYMENTS_MYSQL_URL=mysql://db:db@db:3306/molliepayments_test ./src/vendor/bin/pest --test-directory=../tests --configuration=phpunit.xml.dist src/modules/Molliepayments/tests/Integration
ddev exec ./src/vendor/bin/php-cs-fixer fix --config=.local/MolliePayments/.php-cs-fixer.php --using-cache=no --dry-run --diff --sequential
ddev exec composer validate --strict --no-check-publish --working-dir=.local/MolliePayments
ddev exec bash -lc 'find .local/MolliePayments/gateway .local/MolliePayments/module -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n 1 php -l'
```

Coverage includes paid title/price/config/company changes across v2/v3/v4; actual native domain renewal repricing after activation; partial, scheduled and complete cancellation; period/ownership/currency/product changes, suspension and missing orders; new order non-expansion; known payment resume/settlement after lifecycle changes; immutable consent/request/acceptance snapshots; repeated transaction-free mandate checks; legacy wording manipulation; tampered local scope hashes/text/timestamps and authenticated payment Customer/profile/ID/invoice/gateway/sequence/amount/currency/metadata; unresolved replay/pre-reservation scope changes; native decimal storage; and historical Bootstrap rendering with no consent/payment controls after cancellation. Independent MariaDB workers reverify partial/all-canceled authorizations without accounting changes and settle changed-order duplicate paid callbacks exactly once.

The first run exposed eight old expectations that treated live title/price/period changes as invalid historical evidence. The strict regressions were retained for unresolved creation/replay, while new positive historical lifecycle regressions cover known payments. Initial new-fixture failures used incorrect native domain configuration keys and compared raw SQLite float/string values; corrected native fixture data and existing repository normalization resolved them. No financial/idempotency test was skipped or removed to hide a failure.

Remaining limits: v2/v3 never stored v4 hashes/snapshots, so their free-text fields depend on original database audit integrity; no retroactive hash or live-order reconstruction is invented. Current applicability is a derived native-state view, not permanent revocation history or permission to start a collection. Different intervals/new orders require fresh explicit consent; automatic future collection remains disabled. Real provider/browser validation was explicitly excluded from this task. Both reviewed lifecycle findings are resolved by code and isolated regression evidence, with no production-readiness claim.

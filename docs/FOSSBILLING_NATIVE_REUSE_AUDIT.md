# FOSSBilling native reuse audit

Source review on 2026-11-07, before runtime refactoring. This review preserves PR #18 behavior; it does not certify production readiness or enable automatic recurring collection.

## Reproducible references

| Reference | Resolved commit | Review / compatibility |
| --- | --- | --- |
| PR #18 `8-implement-first-payments-and-mandate-onboarding` | `d5c326a0c58e37230d84344fac38669997ed372b` | Fetched current remote HEAD; equals supplied baseline |
| Installed FOSSBilling | `7bc90195a535a03d33b4d0db91f41ba6989dee8a` | Actual source and native Pest host |
| Official upstream `main` | `7bc90195a535a03d33b4d0db91f41ba6989dee8a` | Fresh bare clone; same commit as installed host |
| Official stable tag `0.8.9` | `e594f8a5f91ea4cc905708f8dc1e42ccb752ed21` | Inspected source; unsupported payment contracts, not a supported runtime |
| Existing CI pinned host | `97bb583dd681e311e2d10fe8c648095f2a479878` | Inspected independently; retain pinned/main matrix |

Upstream source paths below are relative to [FOSSBilling](https://github.com/FOSSBilling/FOSSBilling/tree/7bc90195a535a03d33b4d0db91f41ba6989dee8a). Pinned source uses [this revision](https://github.com/FOSSBilling/FOSSBilling/tree/97bb583dd681e311e2d10fe8c648095f2a479878); stable uses [this revision](https://github.com/FOSSBilling/FOSSBilling/tree/e594f8a5f91ea4cc905708f8dc1e42ccb752ed21). Sources were fetched to a temporary bare repository, without changing the host checkout. Local pre-existing host changes are outside this deliverable.

The reviewed paths cover Core Doctrine timestamps/schema synchronization, load/timezone setup, Invoice/Transaction/Client/Order entities and repositories, invoice/transaction/balance services, encryption, email validation, API authentication, Twig factory/loader and their available upstream tests. Upstream tests include `tests/Unit/Box/CryptTest.php`, `tests/Unit/Box/TwigLoaderTest.php`, `tests/Unit/Box/AppClientTest.php`, `tests/Unit/Payment/Adapter/ClientBalanceTest.php` and `tests/Unit/FOSSBilling/Core/Security/LegacyToolsSplitTest.php`. Native module tests were also inspected under `src/modules/Invoice/tests/Unit/ServiceTest.php`, `ServiceTransactionTest.php` and `Repository/InvoiceItemRepositoryTest.php`, `src/modules/Client/tests/Unit/ServiceBalanceTest.php`, `ServiceTest.php` and `Entity/ClientTest.php`, and `src/modules/Order/tests/Unit/ServiceTest.php`. Native regressions protect processed-history freezing, SQLite-safe claims and invoice-before-balance locks. Module fixtures additionally exercise these real services with disposable databases and Mollie-specific races.

## Subsequent development-only timestamp decision

After this audit was merged, the project owner explicitly requested native readable DATETIME columns and waived migration/backward compatibility for timestamp storage because the development database will be recreated. That decision supersedes the epoch-retention recommendation below. Both module entities now map dates with Doctrine `Types::DATETIME_IMMUTABLE`; repositories write UTC `Y-m-d H:i:s` values, including nullable lease expiry. UTC storage preserves elapsed retry and lease timing across DST. Presentation uses the existing host timezone and the requested `Y-m-d H:i:s` format. DBAL conditional writes remain necessary, so attaching Core ORM lifecycle callbacks would still not replace timestamp assignment in these repositories. No historical conversion or database reset is implemented.

## Decisions by area

### Module timestamps and native lifecycle — retain epochs, reuse native lifecycle for native entities

Existing: `Entity/MolliePaymentAttempt.php`, `Entity/MollieCustomer.php` map integer `created_at`, `updated_at`, `consent_accepted_at`, `mandate_verified_at`, and `lease_until`. Both module repositories use DBAL writes, conditional lease ownership and `time()`; creation/replay expires after 2700 elapsed seconds. `Support/Timestamps::present()` converts absolute epochs to ATOM using PHP's actual default timezone. No module `paid_at` exists: provider `paidAt` presence is checked through SDK `Payment::isPaid()` in `PaymentService::validatePayment()`; the native invoice owns its own paid timestamp.

Native: `src/core/Doctrine/TimestampTrait.php`, `TimestampTrait::onPrePersist()` / `onPreUpdate()` and `TimestampInterface` map nullable **DATETIME_MUTABLE** fields. `src/modules/Invoice/Entity/Invoice.php` and `Transaction.php` use this trait and `HasLifecycleCallbacks`. `src/load.php` sets PHP's timezone from `i18n.timezone`; `src/core/Doctrine/DriverManagerFactory.php` configures the database connection's timezone. `src/core/Twig/TwigFactory.php`, `configureRendering()` uses native locale/timezone formatting.

Compatibility: traits are present in pinned/main, absent in stable. ORM callbacks do not run on the module repositories' DBAL updates. Adding the trait to module entities would change types/schema and still not update these writes; overriding its mappings would conceal a second timestamp implementation. DATETIME wall time can be ambiguous in the autumn DST overlap, whereas epochs compare elapsed deadlines unambiguously. MariaDB session offsets must not affect integers; SQLite and MariaDB both preserve them. Provider expiry strings are parsed with their supplied UTC offset for absolute comparisons; provider `paidAt` is not converted or persisted in module timestamps. Native `Invoice::paidAt` retains Core's receipt-time behavior; no historical rewrite or provider-date override is introduced.

Recommendation: retain all module epochs and the tiny presentation helper. Remove explicit `Transaction::setUpdatedAt(new DateTime())` from ORM-managed callback changes: native `onPreUpdate()` already owns this field. This applies only when the transaction has actual state changes; redundant identical replay need not advance time. Native raw SQL claims remain Core's responsibility. No module schema change or state transition changes.

Tests: `TimestampsTest` covers UTC/Amsterdam winter, summer and both DST transitions, absolute leases/retry deadlines, native DATETIME serialization and unchanged stored epochs after presentation. Add aged transaction timestamp tests for pending/terminal/paid/duplicate branches, ORM reload and schema-history preservation. MariaDB coverage includes a deliberately different database session offset.

### Payment accounting and events — reuse existing services, retain atomic bridge; propose upstream

Existing: `PaymentService::settle()` locks invoice → attempt → transaction, validates unchanged invoice/account/amount, locks native balance, credits the verified receipt and debits the whole native invoice once, marks paid with deferred events and writes the native transaction plus module settlement guard in the same DBAL transaction. Events are dispatched after commit, and native cron handles pending item tasks.

Native: `src/modules/Invoice/Service.php`, `Service::getTotalWithTax()`, `lockInvoiceState()`, `markAsPaid()` and `tryPayWithCredits()`; `src/modules/Client/Service.php`, `Service::addFunds()`; `src/modules/Client/ServiceBalance.php`, `getClientBalanceForUpdate()` and `src/modules/Client/Repository/ClientBalanceRepository.php`, `getClientBalanceSumForUpdate()`. Already called through `mod_service`/the injected EntityManager. `markAsPaid()` handles items, paid timestamp, reporting and native lifecycle; its deferred-events argument is required.

Compatibility: `tryPayWithCredits()` starts a nested transaction and emits events/tasks after its own scope, before a caller's outer commit. It cannot atomically include the module settlement guard and native transaction history while deferring external effects to that outer commit. Per-item charging can differ from invoice-total VAT rounding. Native totals are floats; `Support/Amount` quantizes once, performs strict two-decimal provider matching and rejects unsupported currency scales using the host Symfony Intl dependency. Formatting a currency for display is not equivalent to exact provider amount validation.

Recommendation: retain the small settlement bridge, strict Amount boundary and fail-closed partial-allocation checks. Do not replace it with `tryPayWithCredits()` or implement a new ledger. Propose native exact outstanding-balance and external-settlement operations with outer-commit effects (see `FOSSBILLING_CORE_PROPOSALS.md`).

Tests: `NativeBalanceTest`, `CheckoutTest`, `WebhookTest` exercise native credit/debit, rounding, rollback, duplicate delivery, status outcomes, allocation rejection, legacy ledger preservation and deferred events; `Integration/MariaDbTest` exercises independent workers and deadlock/retry.

### Native transaction claims, associations and diagnostics — retain verified bridge

Native: `src/modules/Invoice/ServiceTransaction.php`, `createAndProcess()`, `claimForProcessing()`, `preProcessTransaction()`, `processTransaction()`, `update()`, `assertNoProcessedHistoryChange()`; `src/modules/Invoice/Entity/Transaction.php`, `setInvoice()`; `src/modules/Invoice/Repository/TransactionRepository.php`, `findOneByTxnIdAndGatewayId()`, `findOneByGatewayIdAndIpnHash()`, `findProcessingOrProcessedByTxnId()` and `getSearchQueryBuilder()`.

Existing: CLI `PaymentService::reconcile()` uses native `createAndProcess()`. Processing never reclaims the native claim. `associateTransaction()` attaches only an authenticated provider payment's committed attempt invoice under the established lock order. `TransactionDiagnostics::orphans()` reads a bounded scalar projection, excludes personal IPN fields from output, and distinguishes processed history from reprocessable rows.

Compatibility: native competing-transaction lookups are hints, not settlement guards. Native transaction updates have administration events and processed-history protections, not a compare-and-set provider-authorized association API. Search builders do not express the bounded invoice-null/provider join plus module correlation without extra query logic. Replacing the scalar query with full entity hydration or generic native API serialization would not simplify its privacy contract. Doctrine pessimistic `FOR UPDATE` is not a portable replacement for the current no-op write lock on SQLite.

Recommendation: retain module diagnostics, claim checks and verified association. Use the native entity relation and host processing entry points as already implemented. Never repair processed associations via SQL or adapter backfill; propose audited upstream association recovery.

Compatibility fixtures use native `ServiceTransaction::getIpnPayload()` on main and its pinned predecessor `getDecodedIpn()` rather than assuming `Transaction::getIpn()` always returns an array: pinned returns serialized JSON, main returns an array. Fixture writes follow the actual native property type. CI materializes the sample configuration once, preserving one generated salt across native encryption/decryption instead of re-executing the random salt expression on every include.

Tests: `TransactionAssociationTest` and MariaDB orphan-worker tests protect forged hints, cross-invoice/provider mismatches, native claimed recovery, immutable processed history and exactly-once accounting.

### Customer identity, validation and encryption — reuse native predicate; retain immutable provider snapshot

Existing: `CustomerService` loads `Box\Mod\Client\Entity\Client` through the injected EntityManager and requires the actual authenticated identity. `CustomerRequest::snapshot()` uses native `Client::getFullName()` / `getEmail()` and injected `crypt->encrypt()`; `payload()` decrypts the original snapshot, authenticates its mapping ID and never rereads changed personal details during replay. Customer mappings are unique per client/gateway/environment, with provider profile verification, credential fingerprint, committed request identity and fenced leases.

Native: `src/modules/Client/Entity/Client.php`, `getFullName()`, `getEmail()` / `setEmail()`; `src/core/Security/Crypt.php`, `encrypt()` / `decrypt()`; `src/core/Validation/EmailValidator.php`, `isEmailValid()` and `validateAndSanitizeEmail()`; `src/modules/Client/Service.php`, client account operations. Mollie SDK v4 `src/Http/Requests/CreateCustomerRequest.php` accepts optional name/email/locale/metadata; module sends no address, phone or locale.

Compatibility: Core's `isEmailValid()` uses exactly the current `FILTER_VALIDATE_EMAIL` predicate and is present on pinned/main. The richer sanitization method uses RFC validation and optional production DNS, so it is not a behavior-preserving replacement. Client setters reject invalid legacy values and mutate authoritative identity; do not use them to sanitize an optional provider payload. IDNA conversion, whitespace normalization, character/byte limits and omission of invalid optional fields are provider payload constraints. Core has no immutable encrypted provider request snapshot or provider creation/replay repository. `src/modules/Invoice/Entity/PayGatewayCustomer.php` and `Repository/PayGatewayCustomerRepository::findOneByGatewayAndClient()` provide a native external-customer cache, but its uniqueness is only gateway/client, with no Test/Live or profile identity, unresolved creation state, encrypted snapshot or fenced lease. It cascades away on gateway deletion. Using that cache for these durable Mollie mappings would lose required isolation and recovery evidence; do not copy mappings into a second store.

Recommendation: replace the local email predicate with `EmailValidator::isEmailValid()` after existing trim/IDNA/length checks. Retain snapshot structure/encryption, replay binding and module repositories. Add no dependency or plaintext personal-data store; preserve existing encrypted snapshots byte-for-byte.

Tests: `CustomerDetailsTest` covers native encryption, Unicode/IDNA/oversize/control characters, missing legacy data, corrupted/mapping-swapped snapshots and lost-response replay after profile edits; extend parity coverage for boundary email forms. `CustomerTest` and MariaDB tests protect isolation, rotation, profile mismatch, expired retry, creation concurrency and history.

### Invoice items, order scope and onboarding — simplify native item access, retain provider authorization

Native: `src/modules/Invoice/Repository/InvoiceItemRepository.php`, `findByInvoiceId()`; `src/modules/Invoice/Service.php`, `getInvoiceItemRepository()`; `src/modules/Order/Entity/Order.php`, native client/status/period accessors; `OrderMeta.php` and Doctrine repository; `src/core/Period.php`, `getCode()` / `getTitle()`. Already used for scope recognition, with fresh entity reads. `Service::getPaymentInvoice()` builds the native gateway invoice.

Existing: `RecurringEligibility::scope()` requires all items to identify one owned pending/active recurring order with a matching period and no end-of-period cancellation. `PaymentService::firstCheckout()` commits versioned explicit consent for that order before provider first-payment creation. `MandateService::verify()` authenticates provider Customer/payment/mandate links after native settlement. Automatic collection and native gateway subscriptions remain disabled.

Compatibility: native fixed-amount subscription eligibility does not express order-scoped authorization or provider mandate validity. Do not replace these checks with a generic subscription flag or create another order/scheduler. Native `findByInvoiceId()` uses an EntityManager reference to the same invoice and delegates the identical Doctrine predicate; it introduces no refresh or lock-order change and is present in pinned/main.

Recommendation: reuse `InvoiceItemRepository::findByInvoiceId()` for both module item reads. Retain conservative eligibility/consent/provider verification. Regression tests: `RecurringEligibilityTest`, `OnboardingTest`, `OnboardingClientTest` and independent first-checkout/mandate webhook workers.

### Routing, permissions, CSRF and templates — retain native integration and narrow safeguards

Native: `src/core/Api/AbstractApi.php`, identity/service access; `src/core/Http/CookieNames.php`, CSRF names; `src/core/Twig/TwigFactory.php`, `configureCsrf()` and native environments; `src/core/Twig/TwigLoader.php`, `getModuleTemplatePaths()`; native extension settings route and module permissions. The module uses native controllers, session nonce, module API requests, Bootstrap and Twig filters.

Compatibility: general API CSRF can be disabled; onboarding still requires POST, the current authenticated client, the session nonce and matching cookie when supplied. Generic boolean normalization is more permissive than explicit consent `true`/`'1'` and would weaken authorization. Twig's Symfony Finder does not follow the workspace module symlink, so `AdminTwigFactory` adds a fallback only when native/theme resolution fails and delegates real filters/configuration to the original factory.

Recommendation: retain narrow CSRF/ownership checks and fallback; no new frontend system. Tests: `AdminTest`, `OnboardingClientTest`, `ConnectionTest` exercise real Twig, theme precedence, permissions, session/CSRF and saved encrypted credential resolution. Propose symlink-template discovery upstream rather than duplicating Twig rendering.

### Schema and supported hosts — retain native additive synchronization and explicit incompatibility

Native: `src/core/Doctrine/SchemaSynchronizer.php`, `syncEntities()`; module `Service::install()` / `update()` already synchronize only module-owned entity metadata. Native Doctrine Connection transaction/uniqueness mechanisms are already used by both repositories. They cannot replace provider-specific active slots, request identity or fenced writes with generic `persist()`/`flush()`.

Stable 0.8.9 uses legacy `Model_Invoice` in `Service::markAsPaid()`, lacks Doctrine invoice/transaction entities, `TimestampTrait`, `SchemaSynchronizer::syncEntities()` and the required invoice-locking contract. Do not advertise stable runtime compatibility or add a second legacy accounting implementation. `Support/Compatibility::assertHost()` must fail closed. Keep CI pinned/main execution; stable is an explicit incompatible-source check, not a passing payment matrix entry.

Tests: existing SQLite and MariaDB upgrade/history tests preserve attempts, mappings, encrypted requests, financial history and unknown legacy columns, including repeated update. No migrations, column conversions or data rewrites are part of this refactor. Execution evidence and any gaps will be recorded in `VALIDATION.md` after implementation.

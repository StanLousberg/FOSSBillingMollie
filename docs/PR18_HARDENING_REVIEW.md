# PR #18 final hardening review — 2026-11-07

> Historical report for commit `0892c05152cdaaec6e93a7c0c711db8479c4c94a`. The instrument-based discovery and cron transaction path described here are superseded by [the final corrections](PR18_FINAL_FIXES.md). Use that report for current behavior and review readiness.

## Reference and scope

Local and remote PR #18 initially matched `294de042114b342b8da320885efd54221bcc7fdd`. Work stays on `8-implement-first-payments-and-mandate-onboarding`; no branch, PR or push is created. Installed Core and the pinned CI target are `7bc90195a535a03d33b4d0db91f41ba6989dee8a`; a read-only remote check found upstream `main` at that exact same SHA. Thus the two current CI matrix labels reference identical source, rather than two distinct revisions. The installed private SDK is `mollie/mollie-api-php v4.0.0`.

No Core/vendor/theme file or financial settlement implementation is changed. Expected renewal pricing, cart consent wording/theme integrations and integer timestamp migrations remain outside this review. Automatic recurring collection remains disabled.

## Findings and changes

| Area | Verified cause/findings | Change |
| --- | --- | --- |
| First-payment mandate association | SDK `Payment::$mandateId` is nullable. Mollie's recurring guide directs integrations to the Mandates API after the first payment; a payment ID/link is not guaranteed. Previously a missing pointer returned forever without discovery. | Prefer the payment's explicit ID/link, then a previously verified/stored association. Otherwise inspect one complete, scope-filtered page (maximum 250) and require exactly one match on strong instrument identity, Customer, environment, method and provider creation time. No arbitrary/newest/first mandate is selected. |
| Ready state during outages | Verification cleared `mandate_verified_at` and wrote `pending_mandate` before provider I/O. All failures, including authoritative mismatches, became pending. Temporary Customer GET failures also blocked an otherwise verified mapping. | Keep the prior observation until there is a verified replacement. Transient SDK/API failures (including 408/429/5xx), network failures and Customer lease contention preserve prior readiness/time. Explicit invalid/deleted mandates invalidate readiness; identity/scope/configuration conflicts require review. Customer transient errors retain mapping identity/state and are sanitized separately from identity failures. |
| CSRF | Native Core and module both require body/header nonce equality with the session. Native treats an empty optional CSRF cookie as absent; the module rejected it. A new actual login/form run reproduced a separate native 403 (see below). | Align only the optional-empty-cookie edge. Retain strict session nonce, POST, client identity, invoice ownership and nonempty conflicting-cookie rejection. Add form/JSON/header/legacy-cookie/regeneration/mismatch regressions. No CSRF bypass or session mutation is introduced. |
| Payment modes/availability | Existing mode enforcement and refusal to create `oneoff` in `recurring_only` were confirmed. Native `Guest::gateways()` delegates to `ServicePayGateway::getActive()`; it has no invoice-specific adapter eligibility callback. Hiding the gateway globally through capability flags would also hide eligible first payments. | Keep native availability untouched; provide explicit sign-in/eligible-order/alternative-gateway error messages before provider creation. Existing callbacks/reconciliation can still settle after mode changes. All three mode matrices remain covered. |
| Pending mandate recovery | The explicit owner action already used native claimed transactions and authenticated provider GETs; it does not create payments or Customers. It previously depended on the customer/operator returning. | Add a native `AfterAdminCronRunEvent` listener: at most five pending settled attempts, at least five minutes since the last activity, within seven days of creation. Native `NamedLock` prevents overlapping cron batches, and existing attempt/Customer leases fence provider checks and writes. Failures back off without clearing active attempts. |
| Maintainability/diagnostics | Transaction/lock/settlement boundaries did not need restructuring. Ambiguous provider failures and verification mismatches were conflated. | Keep settlement untouched. Isolate mandate identity/instrument/discovery/outage helpers and introduce a sanitized transient exception. Use the native logger with fixed categories and an opaque local attempt ID only; no SDK exception/request/response, credential or instrument is logged. |

### Conservative discovery contract

Fallback identity is a full IBAN for direct debit (spaces/case normalized), exact card fingerprint, or full PayPal account reference. Names, last-four digits, newest ordering, or the sole fact that a mandate belongs to the same Customer are insufficient. Only mandates created between the authenticated payment's creation and at most 24 hours after its paid instant qualify; future timestamps are bounded as well. A second match or an incomplete paginated result means `manual_review`. A missing strong identifier also means review, rather than pretending the association is pending forever. Instrument details are compared only in memory and never copied into the attempt or logs.

When no candidate matches, checks can remain `pending_mandate` only for the first 24 hours after recorded acceptance; afterwards they require review. A discovered `pending` mandate retains its ID and can become `valid` using GET-only verification. A known ID is sticky: explicit 404/410 or `invalid` cannot cause discovery of a replacement. A fresh successful verification checks the MIT scope again. A preserved `ready` during an outage remains a historical observation at the old `mandate_verified_at`, not a new provider confirmation or permission to debit.

Some legitimate provider responses omit all strong association evidence, use an older reused mandate, or have more than 250 mandates. Those cases intentionally require operator review; this change does not fabricate an association. See [Mollie's recurring guide](https://docs.mollie.com/docs/recurring-payments), [mandate response contract](https://docs.mollie.com/reference/get-mandate) and [scope-filtered mandate listing](https://docs.mollie.com/reference/list-mandates). The time/uniqueness restrictions are this adapter's conservative policy, not a provider guarantee.

## Actual browser/session validation

A new disposable `.invalid` client and native EUR 1.00 recurring-order invoice were created through Core with welcome email disabled. No existing client/invoice/gateway configuration was changed; only the designated `molliepayments_test` database was reset by integration fixtures. Chromium used the actual password, normal login, native session cookies, the configured public HTTPS origin and an initially unchecked authorization control. No identity/session injection, CSRF exemption, token replacement or Live payment was used.

Observed:

- Native login returned HTTP 200, and the owned authorization page rendered.
- The hidden nonce matched the decoded native CSRF cookie. There was one CSRF cookie with path `/`; the form API action used the page's origin.
- `onboarding_start` returned HTTP 403. The unrelated native `client/profile/get` request also returned HTTP 403 with the native CSRF error.
- A read-only check of this disposable browser's persisted session found exactly one nonce field, differing from both the form and decoded cookie. No nonce or session content was logged.

The precise host/session persistence cause remains unresolved. Session regeneration source preserves the nonce, and native API/session tests pass; no evidence justifies weakening the module check. This is an outstanding native deployment/session blocker, not a fixed module 403. No Core/session/security/public-URL settings were changed to force a pass.

Consequently this new run did **not** reach a Mollie first-payment POST, Hosted Checkout completion, public webhook, invoice-paid transition or real mandate verification. Older successful Test evidence in [VALIDATION.md](VALIDATION.md) is historical and is not counted as a pass of this revision. Resolve/reproduce the host session mismatch, reload/login on the configured origin, then repeat the complete disposable Test flow and webhook redelivery before merge approval.

## Operator recovery

Use the owned page's **Check payment authorization** action or the existing CLI:

```bash
php module/Molliepayments/bin/reconcile.php /absolute/path/to/FOSSBilling/src ATTEMPT_ID PAYMENT_ID
```

Use only the attempt's recorded payment ID. These paths run native claimed transaction processing, authenticate payment/Customer/mandate state and never initiate provider payment or Customer creation. Do not clear a reservation or substitute a mandate ID in SQL. `pending_mandate` is eligible for bounded native cron checks; older-than-seven-days or `manual_review` records need explicit operator investigation. Fix credential/profile/order inconsistencies, or obtain authoritative missing association evidence from Mollie, before requesting another check. A revoked mandate needs a separately authorized future onboarding decision, not a guessed replacement.

Safe log categories include `pending`, `invalid`, `revoked`, `provider_unavailable`, `provider_rejected` and `verification_mismatch`, correlated by `attempt_id`. `provider_unavailable` includes temporary inability to verify because of a held Customer lease. Use the native transaction outcome and immutable attempt request for local audit; do not copy credentials or provider/instrument data into tickets or logs.

## Verification and merge assessment

Final local counts and commands are recorded in [VALIDATION.md](VALIDATION.md). The module unit/SQLite suite, MariaDB suite, independent-worker/idempotency tests, mode matrices, native Core API/session tests, PHP syntax, PHP-CS-Fixer, strict Composer metadata validation and diff checks are required for this revision. These tests fake provider traffic; they do not establish a working browser/public-webhook flow.

The code is prepared for another technical review. **Unconditional merge approval is not recommended while the reproduced native CSRF/session blocker remains and the revised real Test flow is unverified.** PostgreSQL and the unrelated full Core suite were not executed. Stable FOSSBilling 0.8.9 remains incompatible with this adapter's required Doctrine/payment contracts.

Changed areas: `Api/Client.php`, `CustomerService.php`, `MandateService.php`, `PaymentService.php`, `Service.php`, `Support/Compatibility.php`, `Support/ProviderUnavailableException.php`, shared mandate test fixtures and unit/MariaDB regressions. Documentation updates cover payment/recovery semantics, installation requirements and validation evidence. Financial settlement, accounting and the user-styled Twig page remain unchanged.


Exact changed file list:

- `README.md`
- `docs/FOSSBILLING_CORE_PROPOSALS.md`
- `docs/INSTALLATION.md`
- `docs/PAYMENTS.md`
- `docs/PR18_HARDENING_REVIEW.md`
- `docs/VALIDATION.md`
- `module/Molliepayments/Api/Client.php`
- `module/Molliepayments/CustomerService.php`
- `module/Molliepayments/MandateService.php`
- `module/Molliepayments/PaymentService.php`
- `module/Molliepayments/Service.php`
- `module/Molliepayments/Support/Compatibility.php`
- `module/Molliepayments/Support/ProviderUnavailableException.php`
- `module/Molliepayments/tests/Integration/MariaDbTest.php`
- `module/Molliepayments/tests/MandateContext.php`
- `module/Molliepayments/tests/Unit/MandateHardeningTest.php`
- `module/Molliepayments/tests/Unit/OnboardingClientTest.php`
- `module/Molliepayments/tests/Unit/OnboardingTest.php`
- `module/Molliepayments/tests/Unit/PaymentModeTest.php`

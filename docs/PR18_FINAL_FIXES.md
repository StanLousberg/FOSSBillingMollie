# PR #18 final corrections — 2026-11-07

## References and scope

The local and remote PR #18 heads matched `0892c05152cdaaec6e93a7c0c711db8479c4c94a` before these corrections. Work remains on `8-implement-first-payments-and-mandate-onboarding`, with a local commit only. No new branch, PR or push is made.

The installed Core and pinned CI revision are `7bc90195a535a03d33b4d0db91f41ba6989dee8a`; the last checked upstream `main` points to the same revision. The suites below were executed once against that source, not claimed as two separate matrix runs. The installed private SDK is `mollie/mollie-api-php v4.0.0`. Stable FOSSBilling 0.8.9 remains incompatible with the required Doctrine/payment contracts; this correction does not broaden host support.

Relevant native contracts inspected: `ServiceTransaction::createAndProcess()` creates a transaction before processing/claiming it; native Invoice/Transaction entities expose the original settlement identity; `NamedLock` serializes a cron batch. Existing `AfterAdminCronRunEvent`, attempt/Customer leases, gateway credential resolution and full authenticated payment validation are reused. No Core, vendor, CSRF/session, cart-consent or authorization styling changes are included. No automatic recurring collection is implemented.

## Confirmed findings and fixes

| Issue | Confirmed finding | Correction |
| --- | --- | --- |
| CI style failure | Actions run 42408055915, job 128509948889, failed on the missing trailing comma in `tests/MandateContext.php`. | Add the comma and run the full formatter without its cache. |
| Cron transaction audit growth | `retryPendingMandates()` called `reconcile()`, which calls native `createAndProcess()` even when the invoice is already settled. Financial idempotency does not prevent extra transaction audit rows. | A separate mandate-only path reads the original processed payment transaction and verifies invoice ownership, paid status, payment ID, gateway, amount, currency and credential environment/fingerprint before authenticated Payment GET and existing mandate verification. No native processing call or accounting/event write occurs. Explicit owner checks of settled attempts reuse this path; unsettled owner/CLI recovery and webhook settlement remain unchanged. |
| Wrong mandate association | Two overlapping first payments for the same Customer with the same IBAN/fingerprint can share a unique instrument/time candidate belonging to only one payment. Neither uniqueness nor matching details proves origin. | Remove discovery and instrument matching. Require the authenticated Payment's explicit `mandateId` or exact Customer-bound mandate link. Contradictory references require review; a stored ID alone cannot supply evidence. Missing pointers remain pending, bounded by recovery expiry. Customer, environment, method, credential and `customer-not-present` checks remain. |
| Permanently old pending records | The old cron query excluded attempts older than seven days without transitioning their status. | Process expirations within the same five-record batch/lease. At the inclusive seven-day creation deadline, pending becomes `manual_review` without provider I/O, financial changes or repeated expiry logs. Active leases defer processing safely. Within the window, temporary failures keep pending and observe the five-minute cooldown. |

The official [recurring payments guide](https://docs.mollie.com/docs/recurring-payments), [Get mandate](https://docs.mollie.com/reference/get-mandate) and [List mandates](https://docs.mollie.com/reference/list-mandates) contracts and installed SDK were checked. `Payment::$mandateId` is nullable. Customer-level mandate listing does not provide a guaranteed originating first-payment relation; the Mandate resource has no field that proves that provenance. Consequently, this release deliberately cannot mark a pointer-less payment ready by inspecting instruments or list order. If Mollie never supplies an explicit relationship, operator/provider investigation is required. This is an intentional compatibility limitation.

## Recovery procedure

1. Inspect the original attempt using the existing status CLI and confirm its invoice/payment/Customer/gateway/environment. Do not edit stored consent, request, credentials, IDs or active slots to manufacture a match. Logs contain fixed categories and opaque local attempt IDs, not provider response bodies or instrument data.
2. Pending attempts with successful settlement are retried through native cron, maximum five checks/expirations per batch. They cannot create a payment, Customer, transaction or balance entry. Temporary provider failures are retried after cooldown; ready observations retain their previous verification time during mandate-provider outages.
3. An expired attempt becomes `manual_review` once. Inspect the authenticated provider payment and mandate relationship with Mollie. Customer membership, the same IBAN, card fingerprint or a single available mandate is insufficient evidence.
4. If the explicit relationship becomes available later, the existing authenticated owner **Check existing payment and mandate** action can verify it, including after expiry. It performs read-only verification of a settled payment. A changed bound ID or conflicting link stays in review; invalid/deleted mandates remove readiness. Never replay financial settlement solely to refresh a mandate, and never clear the active attempt or initiate a replacement first payment merely to force recovery.
5. The existing CLI financial reconciliation command remains available for genuinely unresolved/unsettled payments, through native claims and accounting safeguards. It is not the cron mandate-only path and can create native audit transactions.

There is no database migration, backfill or edit to personal development records in this correction. Only disposable fixtures and the designated `molliepayments_test` database are used by tests.

## Validation and limits

Final counts and commands are recorded in [VALIDATION.md](VALIDATION.md). Regression coverage includes overlapping same-instrument first payments, absent/late/wrong/conflicting references, retained scope checks, pending-to-valid/invalid/review transitions, inclusive expiry, held/stale leases, concurrent MariaDB workers and repeated cron runs with identical complete native transaction, balance and invoice snapshots and no payment events. Existing checkout-mode, consent, security, duplicate-callback and settlement/idempotency tests remain in the full suites.

Browser E2E was not executed for this correction round, as requested because of the known upstream Core CSRF/session issue. No workaround was introduced. Tests fake Mollie responses; no new real provider or Live payment was performed. Historical real Test evidence does not count as a new pass. Real browser/Test-mode public-webhook validation remains required before production use, but is not a blocker for this scoped correction review. PostgreSQL and the unrelated full Core suite were not run.

With the recorded suites and static checks passing, these corrections are technically ready for the next PR #18 merge review. This does not claim production readiness or resolve the upstream browser/session issue.

# FOSSBilling core proposals

These are proposals only. No core changes or alternative billing services are implemented in MolliePayments. Each capability would also benefit Stripe, PayPal, Adyen and other gateways.

## Partial account-credit allocation

Expose a native invoice operation that atomically allocates available credit to an invoice and returns the remaining amount. Core must own allocation identity, concurrent spending, reversals, audit entries and invoice locking. An adapter should receive an immutable external-payment amount, not manage wallet reservations. Define the behavior when credit arrives or another payment settles after external checkout creation.

## Authoritative outstanding balance and settlement

Expose an invoice service returning currency, total, allocated amount, outstanding amount and an accounting revision in exact decimal money. Include tax rounding, adjustments, credit notes and charged items. Provide an atomic external-payment settlement operation that checks the revision, credits the receipt, debits the invoice, records the native transaction and defers events/provisioning until the outer transaction commits.

Current `getTotalWithTax()` reports the total; `tryPayWithCredits()` settles a fully covered invoice but dispatches events/tasks before a surrounding transaction commits. Per-item `markAsPaid()` charging can differ from invoice-level VAT rounding. A common API would remove the remaining small settlement bridge from adapters without sacrificing atomicity.

The deferred settlement contract should also specify when row locks are acquired relative to consistent reads. On MariaDB with snapshot isolation enabled, a snapshot established before locking provider state can make a later write fail when another worker updates that state. MolliePayments now acquires its native invoice lock and module attempt lock before invoice refresh; this is confined to its existing provider-state bridge. A reusable host settlement API should preserve that ordering without taking responsibility for Mollie-specific mandate metadata. No core modification is required for this fix.

## Native unsuccessful transaction outcomes

Separate callback processing status from financial outcome. Represent canceled, failed and expired provider payments as ordinary unsuccessful results with zero money received, distinguishable from technical exceptions. Define API/UI labels, retry behavior, duplicate callback handling and report filters. Preserve existing transaction status compatibility and immutable payment history.

Until core supports this, MolliePayments uses existing `processed` with amount `0.00`, type `unknown`, provider status and an explanatory note. This records handled callbacks without creating revenue or customer credit; third-party reports must not equate processed-row counts with successful payments.

## Provider Customer mappings

Issue #7 adds only Mollie-specific Customer identifiers, creation identity and recovery state. These are provider integration responsibilities and do not require core accounting or client lifecycle changes. No generic customer synchronization, consent system or recurring scheduler is implemented. Issue #8 must consume the internal Customer service explicitly; mandate authorization and first-payment orchestration remain separate work.


Issue #8 review: recurring-order recognition and order-scoped mandate authorization are provider integration concerns and use existing native invoice/order entities. No invoice calculation, renewal scheduler or generic subscription mechanism is added. Fixed-total native subscription eligibility is deliberately kept separate. The observed authenticated CSRF/session mismatch also affects a native API and remains a host investigation; this adapter preserves security rather than implementing a replacement session/nonce mechanism. See `docs/VALIDATION.md`.


## Native transaction association recovery

`ServiceTransaction::create()` associates only its top-level `invoice_id`; nested stored IPN `get.invoice_id` is an untrusted hint. Permissive/historical creation can retain such a hint without an association. Current `ipn.php` forwards the top-level ID, so nested data alone does not establish which historical caller omitted it. MolliePayments can safely attach an existing verified attempt invoice to a currently claimed, unprocessed transaction through the public entity relation and Doctrine, under invoice/attempt/transaction locks. This requires no host patch and does not trust the GET hint.

A reusable host contract for provider-verified association of a claimed transaction, with compare-and-set semantics and explicit processed-history protections, would benefit other gateways. Processed associations are currently frozen by native updates. Historical corrections therefore require a separately designed, permission-checked, audited **association-only** upstream workflow with independent provider verification; it must preserve transaction amounts, balances, invoice paid state and settlement audit records. This extension deliberately provides only read-only orphan diagnostics and no SQL backfill or alternative financial engine.

The current form-IPN entry point also requires an invoice ID before adapter dispatch. Consequently an unbound public Mollie callback cannot be rescued inside the adapter at all. Generated MolliePayments URLs remain invoice-bound; an upstream provider-authenticated dispatch/association contract would be needed to support unbound public callbacks without trusting nested GET data or weakening creation validation.

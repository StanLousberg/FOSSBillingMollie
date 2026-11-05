# FOSSBilling core proposals

These are proposals only. No core changes or alternative billing services are implemented in MolliePayments. Each capability would also benefit Stripe, PayPal, Adyen and other gateways.

## Partial account-credit allocation

Expose a native invoice operation that atomically allocates available credit to an invoice and returns the remaining amount. Core must own allocation identity, concurrent spending, reversals, audit entries and invoice locking. An adapter should receive an immutable external-payment amount, not manage wallet reservations. Define the behavior when credit arrives or another payment settles after external checkout creation.

## Authoritative outstanding balance and settlement

Expose an invoice service returning currency, total, allocated amount, outstanding amount and an accounting revision in exact decimal money. Include tax rounding, adjustments, credit notes and charged items. Provide an atomic external-payment settlement operation that checks the revision, credits the receipt, debits the invoice, records the native transaction and defers events/provisioning until the outer transaction commits.

Current `getTotalWithTax()` reports the total; `tryPayWithCredits()` settles a fully covered invoice but dispatches events/tasks before a surrounding transaction commits. Per-item `markAsPaid()` charging can differ from invoice-level VAT rounding. A common API would remove the remaining small settlement bridge from adapters without sacrificing atomicity.

## Native unsuccessful transaction outcomes

Separate callback processing status from financial outcome. Represent canceled, failed and expired provider payments as ordinary unsuccessful results with zero money received, distinguishable from technical exceptions. Define API/UI labels, retry behavior, duplicate callback handling and report filters. Preserve existing transaction status compatibility and immutable payment history.

Until core supports this, MolliePayments uses existing `processed` with amount `0.00`, type `unknown`, provider status and an explanatory note. This records handled callbacks without creating revenue or customer credit; third-party reports must not equate processed-row counts with successful payments.

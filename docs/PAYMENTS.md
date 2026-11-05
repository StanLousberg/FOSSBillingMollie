# One-time payments

MolliePayments implements one-time Mollie Checkout through FOSSBilling's native invoice gateway interface. It is a development integration, **not a production-ready release**. Automated tests use fake Mollie responses and disposable accounting databases. A real Mollie Test payment and public webhook must also be verified before deployment.

Recurring payments, Customers, Mandates, Mollie Subscriptions, refunds and chargeback accounting are not implemented. Subscription capability remains disabled.

## Enable and validate

Follow [INSTALLATION.md](INSTALLATION.md) for dependencies, links, schema upgrades and saved credentials. Keep the existing public HTTPS system URL reachable; all callbacks use that native host configuration. Use only disposable customers, orders and issued unpaid invoices in Test mode.

Select MolliePayments in the invoice's normal gateway list. Complete Hosted Checkout and verify the provider state, invoice, transaction amount, account balance, activation tasks and settlement guard. Redelivering a callback must not add financial entries. Commands, real payment evidence and remaining gaps are in [VALIDATION.md](VALIDATION.md).

## Checkout and callbacks

The adapter delegates to `PaymentService`. It checks native invoice payment authorization, issued/unpaid state, client identity/currency, gateway type, enabled/one-time flags and accepted currency. It obtains the full host total including tax and compares integer minor units after quantizing the host's float-valued API once. Previously charged items or invoice allocations are rejected, not subtracted. No browser amount, status, environment or callback URL is accepted.

MolliePayments is an adapter, not a billing system. FOSSBilling owns invoices, account credit, pricing, tax, the ledger, transaction processing and service activation. Checkout never allocates, reserves, deducts or releases existing credit. A €25 invoice costs €25 through Mollie whether the customer has €0, €10, €25 or €30 in credit. Customers may instead use the native account-credit action: €25 settles it; €30 leaves €5. Adding funds after creation never changes the saved provider amount.

The tested host's `getTotalWithTax()` returns the invoice total, not an authoritative outstanding balance. `tryPayWithCredits()` / `payInvoiceWithCredits()` support full credit payment; they do not provide a gateway-independent partial allocation API. Charged items, nonzero legacy invoice credit, prior invoice debits and nonzero/unknown processed receipts therefore block checkout for reconciliation. The adapter only detects these conditions through native repositories; it does not sum ledger rows or infer paid amounts. Client/invoice currencies must match, only two-decimal currencies are supported and Add Funds invoices are rejected.

The minimal settlement bridge calls native `Client::addFunds()` for the verified receipt and its matching invoice debit, then native `Invoice::markAsPaid(false, false, true)`. No allocation engine or balance calculation is involved. Calling `tryPayWithCredits()` inside the outer settlement transaction would run its events and provisioning before commit; calling `markAsPaid()` with per-item charging can disagree with invoice-level tax rounding. Until core exposes an atomic deferred settlement API, the bridge preserves native invoice-level rounding, post-commit events and the attempt guard. See [core proposals](FOSSBILLING_CORE_PROPOSALS.md).

A committed reservation stores the exact creation payload, expected amount/currency, invoice/client/gateway/environment, idempotency key and a non-reversible credential binding. It contains no API keys, bank details or card data. A unique nullable active-invoice slot covers all MolliePayments gateway instances and environments. A bounded lease serializes checkout network calls; database uniqueness and provider idempotency protect recovery if a worker dies or outlives the lease. No database lock is held across a Mollie request.

The typed SDK v4 `CreatePaymentRequest` uses `Money` and `sequenceType: oneoff`, without a method restriction or `testmode` parameter. The selected saved API key determines the environment. The return uses the host's session-free `/invoice/payment-return` document before navigating to the invoice. This preserves the existing session when the browser withholds a Strict SameSite cookie on the cross-site return. No session tokens or relaxed cookie settings are used, and invoice authorization still runs normally. The invoice page displays the verified state. The Mollie description uses the native FOSSBilling payment title, including its series, padded invoice number and single-line item title, limited to Mollie's 255-character maximum. The webhook is the native `ipn.php?gateway_id=…&invoice_id=…&attempt_id=…` URL. Mollie posts a form field `id=tr_…`; callback values are routing hints only.

The callback resolves a stored attempt, fetches the actual payment through the authenticated SDK, and verifies the payment ID, metadata, invoice, client, gateway, environment, sequence, amount and currency. The native host transaction must refer to the same invoice and gateway. Only `paid` with a confirmed paid timestamp can settle. Changed invoice balance, missing ownership, refunds or chargebacks stop settlement with an actionable reconciliation error.

Settlement uses the host's existing transaction claim; the adapter never reclaims it. Under an invoice lock, it locks and refreshes the attempt and transaction, records the verified payment credit and matching invoice debit using `Client::addFunds()`, marks the invoice paid through `Invoice::markAsPaid()` without charging items again and writes transaction details and the attempt settlement guard in one database transaction. A duplicate delivery can have a separate audit transaction but moves zero money. Payment events run after commit; native cron handles pending item tasks. If the invoice was paid independently, received Mollie money remains recorded as a verified paid attempt requiring reconciliation, with no second credit or automatic reassignment.

## States and retries

| Mollie status | Behavior |
| --- | --- |
| `open` | Fetch and verify; reuse the same unexpired checkout URL when the invoice still matches. |
| `pending` | Leave unpaid; block a new payment and show processing. |
| `authorized` | Leave unpaid; authorization alone is not captured money. No manual capture workflow is implemented. |
| `paid` | Reconcile through the native claimed transaction path and settle once. |
| `failed`, `canceled`, `expired` | Release the active slot only after authenticated confirmation; selecting the gateway again creates a new attempt. |

An open payment with a changed invoice balance is never reused or automatically replaced. An expired checkout URL alone is not proof of a terminal payment: wait for authenticated terminal status. A stale pre-payment response cannot downgrade a verified paid attempt.

Mollie's idempotency cache lasts **one hour** and is bound to the credential. Unresolved creation retries use the same persisted payload/key only during a conservative **45-minute** window and with the same credential binding. After that, or after key rotation, creation stops for reconciliation. It never silently generates a new key. A delayed authenticated webhook can recover a missing Payment-ID from verified metadata. See [Mollie idempotency](https://docs.mollie.com/reference/api-idempotency).

Do not switch gateway environment or merchant profile while payments are active. Callbacks for a different saved environment are rejected. Restore the original environment to reconcile outstanding payments, then change configuration. A key rotation for an existing known Payment-ID is safe only if the replacement key can access the same original profile.

## Inspect and recover

From the host root:

```bash
ddev exec php src/modules/Molliepayments/bin/status.php /var/www/html/src
```

This prints the last ten operational attempt IDs and states, without credentials, raw payment responses, callback URLs or customer details. The tools in `bin/` are CLI-only.

For a known attempt and a provider Payment-ID identified in the Mollie dashboard, use the same native claimed reconciliation path:

```bash
ddev exec php src/modules/Molliepayments/bin/reconcile.php \
  /var/www/html/src ATTEMPT_ID tr_PAYMENT_ID
```

This is an authenticated provider fetch, not an override. It cannot settle mismatched data, another customer's invoice, an incorrect amount/currency or an independently paid/canceled invoice. It can bind the missing provider ID after a lost creation response. It creates/reuses a native transaction with sanitized errors. Alternatively, retry an errored callback through the native admin transaction **Process** action after correcting the cause.

- **API unavailable:** the attempt remains reserved. Provider failures return HTTP 503 through `ipn.php` so Mollie can retry. Received non-final transactions are also eligible for native cron reconciliation. Authenticated failed/canceled/expired deliveries are native `processed` audit rows with amount `0.00`, type `unknown`, the provider status and an explanatory note. They are not technical errors and native cron does not retry them.
- **Verification mismatch:** stop and compare invoice/client/gateway/environment and provider metadata. Do not force paid status or clear the active slot.
- **Verified paid / reconciliation required:** received money is preserved in the attempt and the failed native transaction. Check for an independent invoice payment, cancellation, allocation or currency change. Resolve through the host's accounting administration; this integration does not issue refunds or reassign money.
- **Creation unresolved:** search the Mollie dashboard using the invoice description and attempt metadata. Reconcile a matching payment with the CLI above. Do not delete the attempt or blindly retry after the idempotency window. If no payment can be established, investigate with the provider before any audited manual database repair.
- **Paid invoice but activation pending:** run/repair native FOSSBilling cron. Financial settlement does not run service-provisioning tasks under database locks.
- **Missing schema:** run the module migration and verify the module-scoped schema contract. No core patches or root Composer changes are supported.

## DDEV with a public webhook tunnel

Use a disposable database and a Mollie Test key. Start DDEV, then use `ddev share` (ngrok), or an already configured public HTTPS tunnel to its web service. Configure the public address as FOSSBilling's existing system URL using the host's normal deployment configuration. Access the invoice through that address. No extra gateway setting is needed.

Verify that the generated `ipn.php` URL is externally reachable and passes form-encoded POST bodies without a tunnel login/interstitial, authentication gate or CSRF challenge. Keep normal host authentication on the admin panel. Do not disable TLS verification. If the tunnel hostname changes, existing attempts keep their original callback payload for idempotent retries: keep that hostname reachable until all test payments are terminal or reconcile them using the CLI.

## Legacy wallet transition

New installs contain no wallet fields. Additive module-scoped schema synchronization never drops existing columns, tables, attempt identities or ledger history. Runtime reads legacy columns only to reject historical split attempts; it never writes those fields. Existing `credit_amount`, `credit_state`, `invoice_balance`, reservation/release ledger references and records remain available for audit. Even orphaned reservation/release ledger history for an invoice blocks new checkout.

Before upgrading, disable new checkout, back up the database and inspect legacy columns if present. Inventory `credit_state = reserved`, all nonzero `credit_amount`, both ledger references and `client_balance` types `molliepayments_reserve` / `molliepayments_release`. Fetch the original payment in its original environment and compare invoice status, native debits, actual balance and settlement guard. Never release money based only on an attempt label.

Outstanding reservations need an administrator to determine whether funds were consumed, already released or still held. Resolve balances through audited native FOSSBilling administration with references to the preserved original entries, and reconcile/cancel the provider payment as appropriate. Do not delete financial history, clear active slots or drop columns to unblock checkout. There is deliberately no automatic release or destructive migration: provider finality and historical allocations cannot be inferred safely. Legacy split attempts remain blocked until individually reviewed; the generic reconciliation CLI cannot settle them as full invoices. Original non-wallet attempts remain compatible.

## Terminal transaction representation

`processed` means the callback was handled, not that money arrived. Terminal non-paid rows have zero amount and provider status `canceled`, `failed` or `expired`; details and invoice transaction history show that zero and the provider status. Host income reports derive revenue from paid/refunded invoices, while balance reports sum native balance entries. These callbacks create neither entries nor paid invoices, payment events or activation tasks. Duplicate callbacks remain financially inert. Genuine verification/processing exceptions remain native errors. Third-party reports counting all processed rows as successful payments need their own filtering; core lacks a dedicated unsuccessful-result outcome.

## Host contract

See [supported hosts and dependencies](INSTALLATION.md#supported-host-and-dependencies). The settlement bridge and template fallback exist because the tested host APIs cannot yet replace them safely; [core proposals](FOSSBILLING_CORE_PROPOSALS.md) describe the generic changes needed upstream. This remains a development integration, not a production-ready release.

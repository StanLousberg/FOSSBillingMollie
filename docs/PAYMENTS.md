# One-time payments

MolliePayments implements one-time Mollie Checkout through FOSSBilling's native invoice gateway interface. It is a development integration, **not a production-ready release**. Automated tests use fake Mollie responses and disposable accounting databases. A real Mollie Test payment and public webhook must also be verified before deployment.

Recurring payments, Customers, Mandates, Mollie Subscriptions, refunds and chargeback accounting are not implemented. Subscription capability remains disabled.

## Enable and test

1. Install the SDK and gateway/module links as described in [INSTALLATION.md](INSTALLATION.md). Activate the companion module before enabling the gateway. New module installation creates its own attempt table.
2. For an existing installation, run the additive module migration from the FOSSBilling root:

   ```bash
   ddev exec php src/modules/Molliepayments/bin/migrate.php /var/www/html/src
   ```

   This calls the module's `update()` lifecycle and `SchemaSynchronizer::syncEntities()` for `MolliePaymentAttempt` only. It does not migrate core tables. Keep the attempt table on upgrades, gateway changes and module removal: it contains payment identity and settlement guards. Back it up with the host database. Do not truncate it to fix a checkout error.
3. Configure MolliePayments under **Payment Gateways**. Save a standard profile Test API key in the encrypted Test field; enable **Test mode**, **Enabled** and **Allow one-time payments**. Leave subscriptions disabled. Save and use the module settings **Test connection** action. Never paste a key in an issue or command-line argument.
4. Make the configured FOSSBilling system URL publicly reachable over HTTPS. Both the invoice URL and `ipn.php` callback are generated from native host configuration; there is no MolliePayments webhook-base setting.
5. Create a disposable customer with matching invoice/client currency and an issued, unpaid, positive ordinary invoice. Sign in as that customer or use an invoice hash link allowed by the host's access policy.
6. Select **MolliePayments** in the invoice's normal gateway list. The browser navigates to Mollie Checkout. No method is forced: Mollie selects eligible enabled methods. Methods requiring additional order lines or billing data may not be eligible for this minimal payment request.
7. Complete a Mollie **Test** payment using its test status screen. Return to the invoice. The invoice remains unpaid until authenticated server-side reconciliation confirms payment. Refresh after webhook delivery if necessary.
8. Verify one successful native transaction with the `tr_…` reference, the correct invoice marked paid, one transaction credit, and only the unpaid invoice items debited. Native cron activates pending paid invoice items; ensure the host cron is running.
9. Redeliver the webhook and refresh/reselect checkout: there must be no additional credit/debit or payment creation. Try failed, canceled and expired test outcomes, and a pending method where available. Test both webhook-before-return and return-before-webhook ordering.

The one-time capability flag is enabled for this development validation. It does not certify production readiness. Do not switch to Live before the full manual test and independent review.

## Checkout and callbacks

The adapter delegates to `PaymentService`. It checks native invoice payment authorization, issued/unpaid state, client identity/currency, gateway type, enabled/one-time flags and accepted currency. It obtains the host total including tax, subtracts host-allocated charged items, and compares integer minor units after quantizing the host's float-valued API once. No browser amount, status, environment or callback URL is accepted.

Unallocated wallet credit is not reserved or silently spent by checkout. Customers can use FOSSBilling's native account-credit action before choosing Mollie. A fully paid invoice is rejected. Charged items reduce the remaining payment, and only the remaining amount is debited through the native client-balance service before calling `markAsPaid()` without a second item charge. This follows the host wallet-payment convention and preserves invoice-level tax rounding. Ambiguous previous transaction credits that exceed allocated item charges stop checkout for reconciliation. Invoice/client currencies must match; only two-decimal currencies are supported by this implementation. Add Funds invoices are currently rejected.

A committed reservation stores the exact creation payload, expected amount/currency, invoice/client/gateway/environment, idempotency key and a non-reversible credential binding. It contains no API keys, bank details or card data. A unique nullable active-invoice slot covers all MolliePayments gateway instances and environments. A bounded lease serializes checkout network calls; database uniqueness and provider idempotency protect recovery if a worker dies or outlives the lease. No database lock is held across a Mollie request.

The typed SDK v4 `CreatePaymentRequest` uses `Money` and `sequenceType: oneoff`, without a method restriction or `testmode` parameter. The selected saved API key determines the environment. The return uses the host's session-free `/invoice/payment-return` document before navigating to the invoice. This preserves the existing session when the browser withholds a Strict SameSite cookie on the cross-site return. No session tokens or relaxed cookie settings are used, and invoice authorization still runs normally. The invoice page displays the verified state. The Mollie description uses the native FOSSBilling payment title, including its series, padded invoice number and single-line item title, limited to Mollie's 255-character maximum. The webhook is the native `ipn.php?gateway_id=…&invoice_id=…&attempt_id=…` URL. Mollie posts a form field `id=tr_…`; callback values are routing hints only.

The callback resolves a stored attempt, fetches the actual payment through the authenticated SDK, and verifies the payment ID, metadata, invoice, client, gateway, environment, sequence, amount and currency. The native host transaction must refer to the same invoice and gateway. Only `paid` with a confirmed paid timestamp can settle. Changed invoice balance, missing ownership, refunds or chargebacks stop settlement with an actionable reconciliation error.

Settlement uses the host's existing transaction claim; the adapter never reclaims it. Under an invoice lock, it locks and refreshes the attempt and transaction, records the verified payment credit and remaining invoice debit using `Client::addFunds()`, marks the invoice paid through `Invoice::markAsPaid()` without charging items again and writes transaction details and the attempt settlement guard in one database transaction. A duplicate delivery can have a separate audit transaction but moves zero money. Payment events run after commit; native cron handles pending item tasks. If the invoice was paid independently, received Mollie money remains recorded as a verified paid attempt requiring reconciliation, with no second credit or automatic reassignment.

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

- **API unavailable:** the attempt remains reserved. Provider failures return HTTP 503 through `ipn.php` so Mollie can retry. Received non-final transactions are also eligible for native cron reconciliation. Authenticated failed/canceled/expired deliveries are retained as native errored transactions so cron does not retry a terminal payment indefinitely.
- **Verification mismatch:** stop and compare invoice/client/gateway/environment and provider metadata. Do not force paid status or clear the active slot.
- **Verified paid / reconciliation required:** received money is preserved in the attempt and the failed native transaction. Check for an independent invoice payment, cancellation, allocation or currency change. Resolve through the host's accounting administration; this integration does not issue refunds or reassign money.
- **Creation unresolved:** search the Mollie dashboard using the invoice description and attempt metadata. Reconcile a matching payment with the CLI above. Do not delete the attempt or blindly retry after the idempotency window. If no payment can be established, investigate with the provider before any audited manual database repair.
- **Paid invoice but activation pending:** run/repair native FOSSBilling cron. Financial settlement does not run service-provisioning tasks under database locks.
- **Missing schema:** run the module migration and verify the module-scoped schema contract. No core patches or root Composer changes are supported.

## DDEV with a public webhook tunnel

Use a disposable database and a Mollie Test key. Start DDEV, then use `ddev share` (ngrok), or an already configured public HTTPS tunnel to its web service. Configure the public address as FOSSBilling's existing system URL using the host's normal deployment configuration. Access the invoice through that address. No extra gateway setting is needed.

Verify that the generated `ipn.php` URL is externally reachable and passes form-encoded POST bodies without a tunnel login/interstitial, authentication gate or CSRF challenge. Keep normal host authentication on the admin panel. Do not disable TLS verification. If the tunnel hostname changes, existing attempts keep their original callback payload for idempotent retries: keep that hostname reachable until all test payments are terminal or reconcile them using the CLI.

## Compatibility and validation limits

The integration was developed against FOSSBilling commit `97bb583dd681e311e2d10fe8c648095f2a479878`. Required contracts include encrypted gateway secrets, native invoice payment authorization, module-scoped `SchemaSynchronizer::syncEntities()`, Doctrine attribute entities, transaction processing claims, invoice locking, transactional balance writes and deferred invoice-payment events. The manifest version floor alone does not guarantee these contracts. Older incompatible hosts are unsupported; never patch core to accommodate them.

CI checks out both repositories and runs fake SDK requests plus real disposable SQLite invoice/balance settlement, uniqueness and two-process webhook concurrency tests. This does not prove MySQL/PostgreSQL concurrency behavior, public delivery, merchant method eligibility or real Mollie processing. Record host/SDK revisions, Test Payment-ID, statuses, webhook response and duplicate-delivery accounting evidence from the manual test before declaring readiness. Never record API keys or financial customer data in the report.

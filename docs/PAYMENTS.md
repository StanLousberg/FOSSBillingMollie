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

Callback synchronization, settlement and non-paid transaction updates acquire the native invoice row lock and module attempt row lock before refreshing invoice state. An ORM reference avoids an initial invoice SELECT inside the transaction. This prevents a consistent-read snapshot from preceding the attempt lock while another worker updates mandate metadata. MariaDB with `innodb_snapshot_isolation=ON` can otherwise reject the later write with error 1020 even though the invoice is locked. The order remains invoice then attempt; accounting stays atomic, provider requests remain outside these locks and no automatic settlement retries are introduced.

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

## Internal Customer integration (issue #7)

`Service::customers()->resolve(clientId, gatewayId, create: false, verify: false)` is the internal entry point for issue #8. It requires the host's authenticated `loggedin_client` identity to match `clientId`. It returns a provider ID or null; only `create: true` can initiate or retry creation. Customer resolution itself has no separate HTTP API or UI; the authenticated onboarding flow below invokes it. One-time checkout never invokes it. Onboarding validates invoice eligibility and explicit consent, and uses `verify: true` before attaching a first payment. Customer existence is not a mandate or recurring authorization.

`molliepayments_customer` stores one durable row per client/gateway/environment, with unique creation identity and provider mapping per gateway/environment. It stores only identifiers, timestamps, a credential HMAC binding, an opaque metadata-only request, worker lease and sanitized error state. It copies no name, email, bank or card information. `creating` preserves unresolved outcomes; `ready` allows local reuse without API requests; failed provider verification blocks cached reuse until a successful authenticated verification of the same Customer. Read-only resolution of an unresolved creation fails without API calls or state changes. An expired creation retains `recovery_required`; an observed profile mismatch records `profile_context_changed` and blocks cached reuse until verification succeeds again. A rejected attempt to replace a bound ID leaves the healthy mapping intact. No row is automatically deleted or replaced.

Customers are [organization-level resources](https://docs.mollie.com/reference/customers-api). API keys can query `profiles/me`, but `organizations/me` requires token permissions that this integration does not request. Consequently this phase conservatively binds each gateway/environment to the verified profile and original credential. It does not share Customers across gateway instances or profiles and does not pretend the profile is an organization ID. A credential change blocks even cached reuse; a profile mismatch blocks provider operations. Same-key cached reuse relies on Mollie's profile-bound API key contract. Rotated credentials and merchant changes require operator investigation; automatic credential rebinding is intentionally unsupported.

Creation commits the exact metadata payload, random reference and idempotency key before any Customer POST. A database uniqueness constraint resolves competing reservations, and a 180-second lease serializes network operations with fenced writes. An expired lease permits a safe replay only within 45 minutes of the original reservation and with the original credential/payload/key. Mollie's [idempotency contract](https://docs.mollie.com/reference/api-idempotency) caches POST responses for one hour and binds keys to credentials. The shorter local window leaves a safety margin. After it expires, only read-based recovery is allowed. API errors, malformed responses and timeouts preserve the row; they never authorize a fresh identifier. Do not wrap Customer operations in a host database transaction.

For an unresolved result, an operator with host CLI access must find the original Customer in Mollie's organization/environment by its `metadata.molliepayments_customer` reference. Use the provider list API or Dashboard as appropriate; do not match by email/name or assume absence from one page proves noncreation. Then run:

```bash
php module/Molliepayments/bin/recover-customer.php /absolute/path/to/FOSSBilling/src CLIENT_ID GATEWAY_ID MAPPING_ID CUSTOMER_ID
```

Recovery fetches the candidate using the saved gateway's current environment and original credential, checks profile, Customer ID, mode and exact opaque metadata, and binds it to the unchanged creation row. It records `ready` and updates the timestamp. It cannot replace a bound ID, cross clients/gateways/environments, clear state or issue a POST. Preserve operational evidence with the mapping ID and timestamps in the operator's audit record; do not include credentials or personal records. If the original outcome cannot be established, or the original credential is unavailable, the mapping remains blocked. There is no automated reset or credential-rotation recovery in this phase.

To repeat the real Customer API verification (completed on 2026-11-05): in a disposable Test-mode host only, invoke the internal service with a real authenticated disposable client from a local harness, explicitly opt into creation, and confirm one Customer with the opaque reference. Repeat resolution and explicit verification; confirm the same ID, test-mode resource and no second Customer. Exercise recovery with that same mapping/ID and verify a wrong reference is rejected. Confirm ordinary one-time checkout/settlement creates no Customer. Never put the key or raw provider responses in the harness, repository, screenshots or logs. CI tests fake Mollie; separate real Test API validation evidence is recorded in [VALIDATION.md](VALIDATION.md#issue-7-review-and-real-test-api-validation--2026-11-05). Neither establishes production readiness.


## First-payment onboarding (issue #8)

After upgrading the module schema, a logged-in customer can open:

```text
https://YOUR-FOSSBILLING-HOST/molliepayments/onboarding/INVOICE_ID
```

Use the numeric ID of an existing issued, unpaid invoice owned by that customer. This is a separate opt-in route: selecting MolliePayments in the native invoice payment flow creates an ordinary one-time checkout without asking for recurring consent. The unchecked onboarding authorization control explains future automatic collection based on actual invoices, and that this release does not yet collect automatically. The separate **Pay once without automatic payment authorization** action uses the native one-time checkout path through an authenticated, CSRF-protected POST (`client/molliepayments/checkout`); it never creates a Customer or records consent. Guest/hash-only checkout remains one-time. No core/theme injection or additional fee/setup invoice is required. Once a payment exists, its sequence cannot be changed: finish or reconcile the existing attempt before choosing another flow.

The native client controller and `client/molliepayments/onboarding_start` API use the real client identity, invoice payment authorization, POST and the native session CSRF nonce. The module checks the original request because the host dispatcher removes `CSRFToken` from action parameters. This check remains required even if general API CSRF prevention is disabled. Browser-supplied gateway/environment/customer/amount fields cannot override saved configuration; ambiguous MolliePayments gateway instances fail closed. Invoice hash access alone is insufficient to grant automatic-payment consent.

The authenticated request invokes issue #7 Customer resolution with creation and provider verification enabled. Customer/profile/credential changes block reuse. The existing invoice lock and unique active-invoice reservation commit consent version/text/server timestamp, owner/gateway/environment, immutable first-payment request, Customer mapping/profile reference and idempotency key together before the payment POST. Identifiers already contained in the saved request are not copied to a separate onboarding table. New nullable attempt columns retain minimal consent and mandate-verification observations. Ordinary `oneoff` requests stay unchanged and never create Customers.

The first payment uses the native eligible invoice total and currency, with all existing exclusions for allocations, Add Funds and unsupported currencies. SDK `CreatePaymentRequest` receives `sequenceType: first` and the verified `customerId`. Callback URLs, 45-minute replay window, credential binding and lease protection reuse the normal checkout path. An unresolved creation must be reconciled, never replaced merely to unblock checkout.

Authenticated payment retrieval verifies sequence, Customer, profile, environment, invoice, amount/currency and immutable attempt metadata. Only paid with a paid timestamp enters the existing native financial transaction path. Invoice settlement and the attempt guard commit atomically and exactly once. Failed/canceled/expired release only the active slot; pending/authorized/open do not settle. A delayed pre-payment response cannot downgrade verified payment or onboarding state.

Mandate verification follows financial commit. A narrowly scoped CustomerService path reads the persisted, settled, consenting first attempt and verifies the saved Customer/profile using GETs with saved credentials. It never supplies a fabricated browser identity or permits Customer creation. The payment's own `mandateId` or exact authenticated `_links.mandate` association supplies the candidate; contradictory references require manual review. No candidate is selected from older Customer mandates. Missing association remains recoverable as `pending_mandate`.

The candidate is fetched under its owning Customer and checked for resource/ID, Customer reference, environment, status and method. Eligible method mappings follow Mollie's [recurring guide](https://docs.mollie.com/docs/recurring-payments): card to card, PayPal to PayPal, and the documented bank-based first methods to SEPA Direct Debit. Unknown/new methods fail closed. The invoice-backed positive-amount flow does not implement BACS zero-value setup. SEPA Direct Debit must be enabled for bank-based mandates; PayPal availability depends on merchant configuration. [List methods](https://docs.mollie.com/reference/list-methods) with `sequenceType=first` and invoice amount/currency can inspect profile availability; Hosted Checkout handles method selection for the first request.

A valid mandate must also support merchant-initiated collection (`customer-not-present`). Explicit response scopes are checked when supplied; the [scope-filtered mandate list](https://docs.mollie.com/reference/list-mandates), starting at the exact candidate ID with limit one, must return that same valid mandate. The SDK v4.0.0 Mandate resource has no declared scopes property, but preserves extra response fields. If the provider cannot establish the association or scope unambiguously, the adapter preserves pending/manual review rather than guessing. [Get mandate](https://docs.mollie.com/reference/get-mandate) is authoritative for the fetched mandate; a paid payment alone is insufficient.

Onboarding states are `pending_payment`, `payment_failed`, `pending_mandate`, `mandate_invalid`, `manual_review` and `ready`. Readiness requires both recorded consent and a verified suitable valid mandate. It is a timestamped observation, not permission for this release to create recurring debits; future collection must reverify mandate and credential context. Only references, verified status/time and originating consent/payment association are retained; no bank/card details or provider exceptions are stored.

The page's **Check existing payment and mandate** POST (`client/molliepayments/onboarding_verify`) uses the same authenticated native claimed transaction path and fresh provider GETs, even after invoice payment. It cannot create another payment or mandate. Webhook redelivery and the existing CLI `reconcile.php` command also reverify onboarding without a browser session. A mandate outage or pending/invalid/missing mandate does not undo paid invoice accounting. A worker lease fences late mandate observations; a competing check can finish first, so repeat the read-only check if it is still pending.

No recurring charges, Mollie Subscriptions, retry scheduler, revocation interface, refunds or chargebacks are added. Provider/browser validation evidence and remaining gaps are recorded separately in [VALIDATION.md](VALIDATION.md).

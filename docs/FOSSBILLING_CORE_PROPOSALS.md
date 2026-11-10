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

Issue #7 adds only Mollie-specific Customer identifiers, creation identity and recovery state. These are provider integration responsibilities and do not require core accounting or client lifecycle changes. No generic customer synchronization, consent system or recurring scheduler is implemented. PR #18 consumes the internal Customer service explicitly for consent-based first-payment onboarding and authenticated provider mandate verification. Automatic recurring collection remains disabled.


Issue #8 review: recurring-order recognition and order-scoped mandate authorization are provider integration concerns and use existing native invoice/order entities. No invoice calculation, renewal scheduler or generic subscription mechanism is added. Fixed-total native subscription eligibility is deliberately kept separate. The observed authenticated CSRF/session mismatch also affects a native API and remains a host investigation; this adapter preserves security rather than implementing a replacement session/nonce mechanism. See `docs/VALIDATION.md`.


## Native transaction association recovery

`ServiceTransaction::create()` associates only its top-level `invoice_id`; nested stored IPN `get.invoice_id` is an untrusted hint. Permissive/historical creation can retain such a hint without an association. Current `ipn.php` forwards the top-level ID, so nested data alone does not establish which historical caller omitted it. MolliePayments can safely attach an existing verified attempt invoice to a currently claimed, unprocessed transaction through the public entity relation and Doctrine, under invoice/attempt/transaction locks. This requires no host patch and does not trust the GET hint.

A reusable host contract for provider-verified association of a claimed transaction, with compare-and-set semantics and explicit processed-history protections, would benefit other gateways. Processed associations are currently frozen by native updates. Historical corrections therefore require a separately designed, permission-checked, audited **association-only** upstream workflow with independent provider verification; it must preserve transaction amounts, balances, invoice paid state and settlement audit records. This extension deliberately provides only read-only orphan diagnostics and no SQL backfill or alternative financial engine.

The current form-IPN entry point also requires an invoice ID before adapter dispatch. Consequently an unbound public Mollie callback cannot be rescued inside the adapter at all. Generated MolliePayments URLs remain invoice-bound; an upstream provider-authenticated dispatch/association contract would be needed to support unbound public callbacks without trusting nested GET data or weakening creation validation.

## Native template discovery in source workspaces

`TwigLoader::getModuleTemplatePaths()` uses Symfony Finder without following module symlinks. A supported native module-template registration hook or symlink-aware discovery would remove the narrow `AdminTwigFactory` fallback. It must preserve theme precedence and the configured native filters, locale, timezone and CSRF setup. No Core or theme modification is included here.

## Native reuse review

The [2026-11-07 audit](FOSSBILLING_NATIVE_REUSE_AUDIT.md) rechecked these proposals against pinned Core, installed/current main and stable 0.8.9. Existing `PayGatewayCustomer` is a gateway/client cache with cascading removal; it lacks environment/profile separation, encrypted creation snapshots and fenced unresolved-request recovery. MolliePayments retains its durable provider mapping instead of duplicating data into that cache. No generic customer subsystem is proposed or implemented.


## Read-only renewal quotes

A native read-only renewal quote including applicable taxes, renewal discounts and quantities would benefit every gateway's authorization page. Core currently exposes renewal line and promo resolvers but no complete read-only tax-inclusive renewal preview. MolliePayments composes those existing resolvers for an explicitly tax-exclusive current price and keeps the first invoice's tax-inclusive total separate. It does not generate temporary invoices, project tax or add a billing calculator. Actual future renewal invoices determine collection amounts.


## Gateway checkout policy and theme consent

Core already persists arbitrary adapter config, renders native select fields and returns `invoice_hash`/selected gateway after cart checkout. These are reused without changes. MolliePayments' mode only selects between provider `oneoff` and consent-backed `first` invoice payments; it is not a generic subscription or cart implementation. Cart themes may call an authenticated module endpoint after native checkout instead of triggering the ordinary invoice payment callback. Eligibility, explicit consent/source, Customer mapping and mandate verification remain provider integration concerns. No Core payment-mode API, cart fork or additional persistence is needed for this change. Native credits/free orders and non-Mollie gateway policies remain outside this adapter.


## Final hardening: availability and session consistency

Native gateway listing does not expose invoice-specific adapter eligibility filtering. MolliePayments therefore retains native capability flags and refuses unsuitable `recurring_only` checkout explicitly before provider creation. An upstream invoice-aware eligibility hook could improve every gateway's selection UI; no Core/theme override is introduced here.

A new disposable browser run reproduced native CSRF rejection on both onboarding and an unrelated native profile request: form nonce and decoded CSRF cookie matched, but the persisted session nonce differed. The configured public origin/form action matched and native API/session unit tests passed. The host session/persistence cause remains open; no adapter-specific session mutation or CSRF bypass is justified. Core's optional-empty-cookie semantics are now matched while session nonce enforcement remains mandatory.

Settled mandate recovery reuses native `AfterAdminCronRunEvent`, `NamedLock`, existing Invoice/Transaction entity reads and attempt/Customer leases. It validates the original processed transaction and performs provider GETs plus local mandate status writes, without entering claimed transaction processing or creating transaction audit rows. Explicit unsettled financial recovery retains the native claimed processing path. No collection scheduler or billing feature is added. See [the final corrections](PR18_FINAL_FIXES.md).

## Multi-order consent scope

V4 classifies native invoice items and order periods independently of Core's fixed-total subscription check. The provider authorization snapshot is stored on the existing module attempt; it does not become a generic order mandate registry or renewal scheduler. Native setup/discount lines and one-time orders stay in the first invoice amount without entering recurring consent. Core's renewal line/promo resolvers provide per-order prices; different intervals are never combined into a synthetic billing period.

Exact order IDs do not exist before native cart checkout. Custom themes therefore obtain the invoice-backed v4 notice after Core creates its orders/invoice and explicitly confirm that scope before starting a payment. No replacement cart, API/session transport, nonce generator or Core modification is delivered. Historical v2/v3 keep their conservative classifier for financial/security compatibility, with replay and settlement regressions preserving their evidence.

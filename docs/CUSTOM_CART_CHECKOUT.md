# Custom cart consent and payment modes

This optional integration keeps native FOSSBilling carts, orders, invoices and accounting. It requires a supported Core build, an authenticated invoice owner and exactly one configured MolliePayments gateway. One payment covers the whole invoice; authorization covers only its identified recurring orders. Automatic future collection remains disabled.

## Merchant configuration

Use **Payment Gateways → MolliePayments → Payment mode**, stored in native `config[payment_mode]`:

| Mode | Pay once | Authorize and pay |
| --- | --- | --- |
| `both` (default) | Allowed | Explicit consent required |
| `one_time_only` | Allowed; skips onboarding | Rejected |
| `recurring_only` | Rejected | Consent and at least one eligible recurring order required |

Keep **Enabled** and **Allow One Time Payments** enabled: a first payment pays an invoice, without enabling native gateway subscriptions. `supports_subscriptions` remains false. Invalid modes fail closed. A saved-mode change blocks conflicting retries/resume without rewriting authorization or blocking reconciliation of verified paid money.

Monthly and yearly recurring orders can share an invoice with one-time products, setup fees and discounts. Every recurring component must qualify; unknown/foreign/canceled/mismatched order references cannot silently disappear from the consent. One-time-only invoices and guests cannot use recurring-only mode. Native free/credit-covered carts and other gateways remain Core responsibilities.

## V4 API contract

The old generic pre-cart v3 digest is no longer accepted for new payments. Native order IDs do not exist before cart checkout, so the final exact scope must be displayed and accepted **after native cart completion**, before payment creation.

1. Submit native `client/cart/checkout` normally with its existing CSRF/session and gateway selection. Do not call `invoice/payment` yet. Retain the returned `invoice_hash` when an unpaid invoice exists. Never rerun native checkout after its invoice has been created.
2. For the selected MolliePayments gateway, call authenticated `client/molliepayments/consent_notice` with `{invoice_hash: result.invoice_hash}` (or an owned numeric `invoice_id`). This read-only action creates no Customer/payment and requires no consent. It returns `invoice_id`, `invoice_hash`, `recurring_orders`, `one_time_orders`, `one_time_lines`, `period_count`, `amount`, `currency`, `payment_mode`, action/status flags, `consent_version`, `consent_text`, `consent_notice_text`, `consent_digest` and `scope_hash`.
3. Display each recurring order's `order_id`, `order_title`, `period`, `period_title`, `recurring_amount` and `recurring_currency`. Renewal amounts exclude tax. Display one-time products/adjustments separately as excluded from authorization. Display the complete first payment including tax. Do not combine monthly and annual prices into one interval. Show `consent_notice_text` next to an initially unchecked checkbox and keep the displayed recurring list immediately above it. The stored full `consent_text` enumerates those same orders once for the audit record. Obtain a fresh explicit acceptance; do not reuse a pre-cart checkbox.
4. POST `client/molliepayments/cart_checkout` with `invoice_hash`, `consent: '1'` (or JSON `true`), the exact `consent_version`, `consent_digest`, `scope_hash` and native `CSRFToken`/session. Missing/malformed/stale values fail closed. The server checks ownership, saved unique gateway, payment mode, current order/line/price context and existing attempts. Browser-selected IDs, amount, environment or source cannot override them.
5. The native envelope returns `{"result":{"checkout_url":"https://…"}}`. Navigate to that validated URL. Null means processing; open the invoice. Errors use the native `error` envelope and can have non-2xx status codes. Keep the invoice and offer recovery rather than automatically retrying either checkout.

The digest/hash detects context changes; neither is authentication or proof that the UI displayed consent. The invoice hash identifies an invoice and does not prove cart provenance. The endpoint can accept another owned eligible unpaid invoice with the correct saved gateway. All writes enforce the native session nonce, even when general API CSRF checks are disabled.

New v4 attempts store the complete immutable local scope in nullable module attempt `consent_scope`, with full consent text/version/server timestamp and fixed source in immutable request metadata. Recurring IDs are unique and sorted numerically. Mollie receives fixed-size references and `scope_hash`, not the whole order list. One verified Customer/mandate can serve multiple accepted orders without broadening their local consent. Historical v2/v3 requests, missing historical source and evidence remain unchanged; they keep their original single-order scope. Repeated calls cannot convert oneoff into first or replace an active payment. Existing reservations, leases, exact replay/idempotency keys, 45-minute retry boundary, credential binding and native atomic settlement remain.

## Theme example

A safe minimal integration sends the native checkout result to the module's existing Bootstrap page. This page shows the exact v4 lists and obtains consent; no custom cart logic is needed. Wire this into the theme's **existing native checkout-success callback** only for the MolliePayments gateway selected at submission. Preserve the original callback for all other gateways and native free/credit-covered completion. Do not edit Core or installed themes as part of this adapter.

```twig
<div id="mollie-cart-result" role="status" aria-live="polite"
     data-notice-url="{{ 'molliepayments/consent_notice'|api_url }}"
     data-onboarding-base="{{ 'molliepayments/onboarding'|url }}"
     data-invoice-base="{{ 'invoice'|url }}"></div>
```

```javascript
// Called by the existing native cart success callback, before invoice/payment.
async function continueMollieCart(result) {
    const output = document.getElementById('mollie-cart-result');
    if (!result.invoice_hash) return false; // Preserve native completion.
    const invoiceUrl = output.dataset.invoiceBase.replace(/\/$/, '') +
        '/' + encodeURIComponent(result.invoice_hash);
    try {
        const url = new URL(output.dataset.noticeUrl, window.location.href);
        url.searchParams.set('invoice_hash', result.invoice_hash);
        const response = await fetch(url, {
            credentials: 'same-origin', headers: {'Accept': 'application/json'}
        });
        const body = await response.json();
        if (!response.ok || body.error) {
            throw new Error(body.error?.message || 'Unable to load authorization.');
        }
        const notice = body.result;
        // Includes all recurring_orders, one_time_orders and one_time_lines.
        // The module page presents them and an unchecked consent control.
        window.location.assign(output.dataset.onboardingBase.replace(/\/$/, '') +
            '/' + encodeURIComponent(notice.invoice_id));
    } catch (error) {
        output.textContent = error.message + ' Open the invoice before retrying.';
        const link = document.createElement('a');
        link.href = invoiceUrl;
        link.textContent = 'Open invoice';
        output.append(' ', link);
    }
    return true;
}
```

A theme keeping confirmation inline must render the same returned lists and text, retain the server's digest/hash/version, then submit the following **only from the customer's checked confirmation action**. Capture the native CSRF token from the existing page/form; never create a replacement nonce. Disable buttons during submission and, after any uncertain response, require an invoice reload before another attempt.

```javascript
const body = new URLSearchParams({
    CSRFToken: csrfToken,
    invoice_hash: notice.invoice_hash,
    consent: '1',
    consent_version: notice.consent_version,
    consent_digest: notice.consent_digest,
    scope_hash: notice.scope_hash
});
// POST body to the native api_url('molliepayments/cart_checkout') endpoint,
// with credentials: 'same-origin'; validate the native error envelope.
```

In `one_time_only`, use native invoice completion without requesting consent. In `both`, declined consent retains Pay once. In `recurring_only`, require explicit acceptance or offer another gateway; never precheck or infer consent. Unknown scope/no unpaid invoice must not trigger automatic fallback payment creation.

## Compatibility and validation

Stable FOSSBilling 0.8.9 lacks the required Doctrine/payment-locking contracts and remains unsupported. Supported preview/main hosts use native entities and services. Apply the additive module schema update before v4 checkout. See [INSTALLATION.md](INSTALLATION.md) and current results in [VALIDATION.md](VALIDATION.md).

Actual theme/login/CSRF, all three modes, mixed/multiple-period carts, double clicks, Mollie Test checkout, return/public webhooks and missing explicit mandate links require browser validation. A financially paid first payment without an authenticated explicit mandate ID/link remains pending and eventually needs review; do not infer a mandate from Customer-level instrument matches. No automatic future collection is added.

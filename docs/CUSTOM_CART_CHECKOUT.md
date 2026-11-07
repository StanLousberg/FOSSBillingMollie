# Custom cart consent and payment modes

This integration is optional. Themes without it keep using native cart checkout and invoice payment. It requires the supported development Core build in [INSTALLATION.md](INSTALLATION.md), an authenticated invoice owner and one configured MolliePayments gateway. Automatic renewal collection remains disabled; `first` means paying an existing invoice and verifying the resulting mandate.

## Merchant configuration

Use **Payment Gateways → MolliePayments → Payment mode**, stored in native gateway `config[payment_mode]`:

| Mode | Ordinary invoice payment | Recurring-order authorization |
| --- | --- | --- |
| `both` (default when absent) | Allowed | Explicit consent required |
| `one_time_only` | Allowed; native checkout skips onboarding | Rejected |
| `recurring_only` | Rejected | Explicit consent and an eligible order required |

Keep the gateway enabled and native **Allow One Time Payments** enabled in every mode: a first payment is still an invoice payment, not a native gateway subscription. `supports_subscriptions` stays false. Invalid saved modes fail closed. The server resolves the mode, environment, credentials and unique gateway; client-selected values cannot override them.

In `recurring_only`, guest/hash-authorized, one-time, mixed, multiple-order or otherwise unsupported invoices cannot pay through MolliePayments. They need an eligible authenticated checkout or another gateway. No silent one-time fallback grants or replaces consent. A mode change blocks conflicting resume/retry requests, without deleting attempts or changing requests. Webhook, CLI reconciliation and mandate re-verification remain available to settle money already received. Host wallet payments, free orders and other gateways are native Core operations outside this adapter's policy.

## API contract

1. Before submitting the native cart, an authenticated theme calls `client/molliepayments/consent_notice` (or Twig `client.molliepayments_consent_notice({})`). It returns `consent_text`, `consent_version` and `consent_digest`. Display the returned text next to an initially unchecked checkbox and clearly identify the single recurring order in the cart. Do not invent consent text or hardcode a version. The notice authorizes actual renewal invoices rather than a guaranteed fixed price.
2. Capture the checked state at native cart submission. Use Core's existing `client/cart/checkout` normally. This module does not create carts, orders or invoices. Core returns `invoice_hash` only when an unpaid invoice exists, and retains the selected gateway on the invoice.
3. If the user opted in and selected MolliePayments, post to `client/molliepayments/cart_checkout` with `invoice_hash`, `consent: '1'` (or JSON `true`), the notice's `consent_version`, `consent_digest`, and the native `CSRFToken`/session cookie. Form and JSON requests use the same native session nonce checks, even when general API CSRF enforcement is disabled.
4. The response follows the native API envelope: `{"result":{"checkout_url":"https://…"}}`. Navigate to that validated URL. A null URL means the existing payment is processing; show the invoice rather than creating another payment.

Missing or stale consent, wrong version/digest, GET requests, unauthenticated or foreign owners, an invoice without the uniquely configured MolliePayments gateway, disabled gateways and ineligible orders fail before payment creation. The digest detects notice/company changes; it is not an authentication token or proof that a browser displayed a checkbox. Themes are responsible for showing the notice and honoring the user's choice. The hash identifies an invoice; it does not authorize its owner or establish cart provenance. This endpoint can also accept another owned, eligible, unpaid invoice with the correct saved gateway; it does not claim to prove when or how Core created it.

The source is assigned server-side as `cart_checkout`; ordinary onboarding uses `onboarding`. New attempts persist it in immutable request `metadata.consent_source`, alongside existing consent text/version/server timestamp, invoice/order/period, owner, gateway/environment and Customer/profile identity. No new table or column is required. Source-less historical attempts stay unchanged, and repeated calls never overwrite original consent/source or convert a one-time attempt. The shared payment verifier compares all first-payment metadata, including source, against authenticated Mollie state.

Errors use the native JSON `error` envelope and may have non-2xx HTTP status codes. On errors after native cart completion, retain the invoice hash, show the error and offer the invoice/recovery route. Do not rerun cart checkout, automatically switch to one-time payment or retry payment creation after an uncertain response. Reload/reconcile first. The existing unique reservation, immutable payload/idempotency key, 45-minute retry boundary, credential binding, leases and native atomic settlement all apply.

## Minimal theme example

This fragment goes **inside the theme's existing authenticated native cart checkout form**, whose ID is `cart-checkout-form`. Preserve its native gateway selector, `CSRFToken`, other fields and submission behavior. Render this only for the theme's MolliePayments option and an eligible single-recurring-order cart. In `recurring_only`, the theme should require the checkbox; the server independently enforces the policy. If login occurs without a page reload, load a fresh notice after login.

```twig
{% set notice = client.molliepayments_consent_notice({}) %}
<div id="mollie-cart-consent" class="form-check mb-3"
     data-endpoint="{{ 'molliepayments/cart_checkout'|api_url }}"
     data-version="{{ notice.consent_version }}"
     data-digest="{{ notice.consent_digest }}"
     data-invoice-base="{{ 'invoice'|url }}">
    <input id="cart-recurring-consent" class="form-check-input" type="checkbox">
    <label class="form-check-label" for="cart-recurring-consent">{{ notice.consent_text }}</label>
</div>
<p class="small text-secondary">Automatic recurring collection is not yet enabled in this release.</p>
<p id="mollie-cart-result" role="status" aria-live="polite"></p>
```

Wire the function below into the theme's **existing success callback for native cart checkout**, before its invoice/payment request or navigation. Core's current Orderbutton uses `fb_api_form({callback: 'onOrderCheckout'})` and `onOrderCheckout(result)`; use a custom theme override, never patch Core. Call `continueMollieCart(result)` only when the gateway selected at submission was MolliePayments and the captured consent was checked. For all other selections retain the theme's original callback. The selected-gateway test is for presentation; the module checks the invoice's saved gateway itself.

```javascript
const form = document.getElementById('cart-checkout-form');
const notice = document.getElementById('mollie-cart-consent');
const checkbox = document.getElementById('cart-recurring-consent');
const output = document.getElementById('mollie-cart-result');
let acceptance;

// Capture before the theme/native API handler runs. Never infer consent from a URL.
form.addEventListener('submit', () => {
    acceptance = {
        accepted: checkbox.checked,
        csrf: form.elements.namedItem('CSRFToken').value,
        version: notice.dataset.version,
        digest: notice.dataset.digest
    };
}, {capture: true});

async function continueMollieCart(result) {
    if (!acceptance?.accepted || !result.invoice_hash) {
        // Caller must use its original checkout-success behavior instead.
        throw new Error('No consenting unpaid MolliePayments invoice to continue.');
    }
    const invoiceUrl = notice.dataset.invoiceBase.replace(/\/$/, '') +
        '/' + encodeURIComponent(result.invoice_hash);
    try {
        const response = await fetch(notice.dataset.endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            body: new URLSearchParams({
                CSRFToken: acceptance.csrf,
                invoice_hash: result.invoice_hash,
                consent: '1',
                consent_version: acceptance.version,
                consent_digest: acceptance.digest
            })
        });
        const body = await response.json();
        if (!response.ok || body.error) {
            throw new Error(body.error?.message || 'Payment request failed.');
        }
        window.location.assign(body.result.checkout_url || invoiceUrl);
    } catch (error) {
        // Preserve the invoice after cart completion; no automatic payment retry.
        output.textContent = error.message + ' Open the invoice to check before retrying.';
        const link = document.createElement('a');
        link.href = invoiceUrl;
        link.textContent = 'Open invoice';
        output.append(' ', link);
    }
}
```

Do not call Core's `invoice/payment` first for this branch of the callback: that would initiate the ordinary route and could reserve a conflicting one-time payment. Do not call `cart_checkout` automatically because an invoice URL contains a flag. If consent is declined, the cart is ineligible, another gateway was selected or no unpaid invoice exists, retain the original native completion path. `recurring_only` will reject any later one-time attempt through MolliePayments; the theme must present the requirement or another gateway, rather than check consent for the user.

## Compatibility and manual validation

The native `config` select and cart result shape also exist in stable 0.8.9, but that release uses legacy models and lacks the Doctrine/payment locking contracts required by MolliePayments. It remains unsupported. The supported preview host and upstream `main` are CI targets; no Core, theme, cron or vendor patch is delivered here.

Before production use, manually validate all three saved modes in the actual theme, login/session and CSRF behavior, checked/unchecked consent, gateway switching, unsupported/mixed carts, zero/credit-covered checkout, repeated clicks, return from Hosted Checkout, public Test webhooks, invoice settlement, mandate verification and recovery after network loss or a mode change. Automated tests fake the SDK and do not replace browser/public-webhook evidence. Automatic recurring collection remains disabled.

# Administrator mandate management (issue #9)

Development validation only. Automatic collection remains disabled and `supports_subscriptions` remains false. No client mandate-management API or page is added.

## Access and workflow

Open **Extensions → MolliePayments mandates**, or **MolliePayments Settings → Manage customers & mandates**. The dedicated route is `/admin/molliepayments/mandates` (subject to the configured admin prefix).

Staff need module access, `molliepayments.view_mandates` and native `client.view`. Additional permissions are separate:

| Action | Additional permission |
| --- | --- |
| Read overview/details | None |
| Refresh a single mandate | `molliepayments.sync_mandates` |
| Revoke mandate | `molliepayments.revoke_mandates` |

Native authentication and module permission checks apply server-side. Each write independently requires POST and the native session CSRF nonce, including when the host's general API CSRF setting is disabled. Cookie/session mismatches fail closed. No new session or credential mechanism is introduced.

Customers are sorted by name with the native client ID as a tie-breaker. Expand a customer to see its mandates, or open its mapping for provider context and the native FOSSBilling customer link. **All mandates** and **Needs attention** paginate individual mandates. Search by customer name, ID, email or mandate ID. Filter by method, environment, status or verification outcome.

After native settlement, onboarding verifies the explicit mandate relation on the authenticated first Payment. The same Customer lease fences onboarding, targeted refresh and revocation. The existing scoped SDK read establishes merchant-initiated scope only for the returned mandate. The callback and existing pending-mandate recovery path update the local snapshot without an additional Mollie request. An absent relation stays pending under the existing retry policy. No Customer inventory request is required. The former Customer synchronization endpoint is retired. Existing staff assignments to `sync_mandates` now authorize targeted verification.

**Refresh mandate** checks a known mandate against its saved Customer, credentials, profile and environment. Reads of the dashboard use local historical snapshots. A failed check, an old snapshot and an outstanding revocation have separate indicators. A snapshot older than 24 hours is historical evidence and can be filtered for review. A 404/410 records absence and preserves any last known Mollie status. Neither a successful check nor the dashboard grants collection authority.

## Status and revocation

Mollie currently exposes `valid`, `pending` and `invalid` mandate statuses. A direct authenticated 404/410 after verifying the Customer context is recorded separately as `missing`. Missing records are unusable; absence alone does not prove why a record was removed. A literal unknown/revoked status, empty response, transport failure or provider outage is unverified, never authorization. Only an authenticated usable-state check can update a successful snapshot.

Revocation requires the separate permission and a confirmation checkbox in a Bootstrap dialog naming the customer, mandate and environment. It explains that future charges through that mandate stop and that **Mollie cancels any attached provider subscriptions**, including ones managed outside this adapter. No Mollie Subscriptions are created here. No FOSSBilling invoices, services or accounting records are canceled or changed.

Before DELETE, the module re-verifies provider ownership/status and saved context, acquires the existing Customer lease, then commits a `requested` marker and audit event. SDK transport retries are disabled before the destructive request, so a lost response cannot trigger an internal DELETE replay. After the official SDK `RevokeMandateRequest`, it performs an authenticated GET. Only `invalid` or confirmed missing establishes that the mandate is unusable; local revocation then becomes `confirmed`. A DELETE response alone never yields success. A lost DELETE response can still reconcile successfully if the subsequent GET confirms invalidity/removal.

HTTP 401, 403 and 422 responses to DELETE record a provider rejection. Review access and mandate eligibility in the Mollie Dashboard, resolve the rejection there, and refresh the local record. A timeout or lost response leaves the request pending. Check the same customer, profile and environment in Mollie and use Refresh for authenticated read-only reconciliation. Contact Mollie if the outcome remains unclear.

Duplicate requests with an outstanding marker **only reconcile**. They cannot repeat DELETE, even after a crash, timeout, credential change or rejected write. If Mollie still returns a valid/pending mandate, the outcome remains unconfirmed and an operator must reconcile with Mollie. There is deliberately no button to clear the marker or resend an uncertain revocation. An invalid/missing mandate that was not revoked here is not labelled an administrator-confirmed revocation.

Operations on one Customer mapping share its existing committed database lease. Conditional lease renewal/write fencing and a unique `(mapping_id, mandate_id)` constraint protect SQLite and MariaDB. A late response cannot overwrite a newer worker. The durable revocation marker additionally protects provider writes after a worker loses its lease; a lease alone would not make an irreversible remote DELETE safe to retry. Saved credentials/mode are reloaded before provider steps. Gateway changes racing an already-sent network request cannot undo that request; its outcome still requires reconciliation.

## Authorization and replacing a payment method

A discovered mandate is **not** linked to a payment or consent by inference, and is never selected automatically. Details use explicit stored payment/mandate associations with paid, natively settled onboarding attempts, validate the original authorization evidence through `PaymentService::assertAuthorization()`, and check the exact Customer mapping/profile. Corrupted references are withheld with a reconciliation notice. An absent current successful mandate timestamp remains visibly absent; historical association is not present authorization.

There is **no safe standalone administrator initiation of replacement authorization** in the current architecture. `firstCheckout()` requires the authenticated invoice owner, explicit current consent, an eligible existing unpaid invoice and a native payable amount. A paid onboarding invoice cannot be charged again for changing a method. The module does not fabricate invoices, create zero-value authorization payments or let administrators consent on behalf of customers.

An administrator can guide the customer to an **already existing eligible unpaid invoice** and its ordinary MolliePayments authorization flow. If none exists, replacement authorization is unsupported until an appropriate native invoice/authorization contract is available. New consent applies only to the orders explicitly named by that flow. It does not transfer authorization for other orders. The old mandate is never automatically revoked and the newest mandate is never automatically chosen as a replacement.

No administration action modifies the original payment request, consent snapshot, attempt settlement guard, transaction ledger, invoice or service state. Existing onboarding readiness remains historical evidence. Neither cached `valid` nor prior `ready` authorizes collection. Future invoice-driven collection (issue #10, not implemented here) must require fresh authenticated provider verification, applicable customer consent and rejection of invalid/missing mandates and outstanding/confirmed revocation markers. This release has no adapter-initiated recurring debit path.

## Storage and upgrade

Run the existing module migration command in [INSTALLATION.md](INSTALLATION.md#activate-and-upgrade) before opening the pages. `SchemaSynchronizer::syncEntities()` maintains the module-owned tables additively:

- `molliepayments_mandate`: unique mapping/mandate identity, status, method, a fixed description or masked last four digits, provider creation/signature dates, successful/attempted verification timestamps, a fixed error category and durable revocation state.
- `molliepayments_mandate_audit`: append-only administrator ID, mapping/mandate context, action, fixed outcome and UTC timestamp. A generated numeric ID orders events occurring in the same second.

The additive `molliepayments_mandate_system_audit` journal identifies system operations while preserving the preview administrator journal and its mandatory staff ID. Detail pages combine both journals. Nullable `provider_status` and `scope` columns retain the last observed provider status and positively verified scope.

The upgrade imports historical ready attempts with a recorded verification time, exact Customer/profile mapping, valid original consent and a matching successful native transaction. Import uses the original verification time, performs no provider I/O and never overwrites an existing mandate. Running the upgrade again leaves imported rows intact. Back up the database before upgrading.

Only a last-four-digit suffix from sufficiently long card/account values is retained. Full account/card values, names on instruments, card fingerprints, email addresses, raw provider objects/errors and mandate reference text are neither stored nor returned. Credentials remain exclusively in the native encrypted gateway settings. Module DATETIME values are UTC; presentation uses the host timezone in `Y-m-d H:i:s`.

## Manual admin acceptance checklist

Use disposable Test customers and fake-provider destructive tests; never revoke a real customer mandate to validate this change.

- Run the additive module migration; confirm existing attempts, consent and financial history remain intact.
- With a read-only staff role, open the overview and details, search/filter and paginate; confirm write actions are hidden and direct write API calls are denied.
- Complete a first payment for a disposable Test mapping. Confirm multiple mandates, customer links, status badges, signature dates and masked summaries; refresh must preserve consent/payment associations.
- Refresh valid/pending/invalid/missing fixtures. Simulate an outage: historical state remains visible, the check fails, and no successful authorization is shown.
- In provider-faked tests, confirm revocation once, repeat/concurrently submit it, and lose the DELETE response. Confirm at most one DELETE request and explicit unconfirmed feedback until GET reconciliation.
- Test missing/incorrect CSRF, GET writes, foreign mapping/mandate combinations, switched environments/profiles and rotated credentials; all must fail before a destructive call.
- Confirm the consent-assisted replacement instructions, paid-invoice limitation and absence of any administrator consent control or client management endpoint.

## Provider and host references

The implementation uses the official SDK v4 typed requests and [Mollie's list](https://docs.mollie.com/reference/list-mandates), [get](https://docs.mollie.com/reference/get-mandate) and [revoke](https://docs.mollie.com/reference/revoke-mandate) contracts. Profile-scoped API keys determine Test/Live mode; no `testmode` override is sent.

The installed preview host is tested directly. The latest published release checked for this task is [FOSSBilling 0.8.9](https://github.com/FOSSBilling/FOSSBilling/releases/tag/0.8.9); it remains unsupported by the existing module's required native invoice/Doctrine contracts. This feature does not add a compatibility bypass. See [INSTALLATION.md](INSTALLATION.md#supported-host-and-dependencies) and the execution record in [VALIDATION.md](VALIDATION.md).

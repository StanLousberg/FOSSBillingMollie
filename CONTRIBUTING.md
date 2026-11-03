# Contributing to MolliePayments

Thank you for helping build a reliable, independent Mollie integration for FOSSBilling.

MolliePayments is in **early development**. The best contributions right now are reviewed architecture changes, tests, documentation improvements and small, well-scoped building blocks. Payment processing is a security-sensitive domain: correctness takes priority over speed.

## Before opening a pull request

1. Search [issues](https://github.com/StanLousberg/FOSSBillingMollie/issues) and existing pull requests to avoid duplicated work.
2. For a significant design change, open an issue before implementing it. Discuss the impact on FOSSBilling compatibility, invoicing and recurring mandates.
3. Fork the repository and create a descriptive branch, such as `feat/payment-webhooks`, `test/mandate-state` or `docs/installation`.
4. Make the smallest change that addresses the issue; include tests and documentation.
5. Open a pull request with a clear summary, test output, any breaking changes and any migration or security considerations.

## Local development

Follow [the development installation guide](docs/INSTALLATION.md). Both repositories have separate Git histories. Only commit from the MolliePayments checkout; **do not modify FOSSBilling core** to make a feature work.

Install the PHP SDK:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling/.local/MolliePayments
composer install
```

Run the module tests:

```bash
cd ~/dev/MolliePayments-workspace/FOSSBilling
ddev exec ./src/vendor/bin/pest \
  --test-directory=../tests \
  --configuration=phpunit.xml.dist \
  src/modules/Molliepayments/tests/Unit
```

Before submitting, run syntax checks on changed PHP files, execute relevant module tests and review your diff for sensitive data. GitHub Actions runs the core smoke-test workflow on PRs.

## Code guidelines

- PHP 8.3+; use strict types and descriptive names. Follow FOSSBilling conventions for extension services, entities and events.
- Use Doctrine DBAL/ORM and module-owned tables for new persistence. Do not create or edit FOSSBilling core tables outside supported extension mechanisms.
- Keep the gateway adapter thin; put business logic and integrations in module services.
- Prefer dependency injection and small, testable units; mock Mollie API responses in unit tests.
- Keep public documentation, user-visible errors and code comments in English.
- Introduce new dependencies only in this repository's `composer.json`, not FOSSBilling's.
- Avoid changing SDK major versions without explicit compatibility tests and migration notes.
- Do not commit generated `vendor/`, local secrets, test credentials or customer data.

## Payment correctness requirements

A PR touching payment state should demonstrate that:

- Mollie identifiers are validated and correlated with the correct client, invoice and gateway.
- Amount and currency are verified before FOSSBilling records any invoice payment.
- Duplicate webhooks and concurrent retries cannot settle or charge the same invoice twice.
- Async/pending states, failed payments, chargebacks and refunds are handled explicitly.
- Recurring payments require valid authorization; a locally stored mandate record does not by itself grant permission.
- Failures are logged without leaking secrets or sensitive financial data.

## Tests

Add focused Pest tests for success, failure, invalid input, duplicate delivery and retry behavior. Real API credentials are **not** required for unit tests. End-to-end tests should use Mollie test mode and a disposable FOSSBilling database.

Use a public HTTPS development endpoint only when testing actual webhook delivery. Never expose a production admin panel just to test webhooks.

## Issues and security

When reporting a bug, include the PHP version, FOSSBilling revision, project revision, reproduction steps and sanitized error output. Do not include API keys or customer information.

**Do not disclose vulnerabilities in public issues.** See [SECURITY.md](SECURITY.md).

## Review and licensing

By submitting a contribution, you agree to license your contributed code under this repository's [Apache-2.0 license](LICENSE). Third-party brand assets remain subject to their own intellectual-property and trademark rules. Maintainers may request additional tests, a narrower scope or architectural changes before merging.

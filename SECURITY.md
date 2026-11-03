# Security Policy

MolliePayments is a payment integration under active development. There is **no production-ready release** and no supported production deployment at this time.

## Reporting a vulnerability

Please **do not open a public GitHub issue** for security problems, authentication bypasses, leaked credentials or flaws that could affect real payments.

Use [GitHub's private vulnerability reporting](https://github.com/StanLousberg/FOSSBillingMollie/security/advisories/new) if enabled. If it is not available, contact the repository maintainer privately through their GitHub profile to arrange a secure reporting channel. Do not post exploit details, API keys, webhook credentials or personal/payment data publicly.

Please provide a minimal reproduction using dummy data, the affected revision, the expected and observed behavior, and the potential impact. Do not test against systems or Mollie accounts that you do not own or control.

## Secret handling

Never commit, publish or log real API keys, customer bank details, raw card information or data from production invoices. Use Mollie **test mode** and disposable test data for development. If a credential has been exposed, rotate it through Mollie immediately and remove it from the affected system; removing a Git commit alone does not revoke a key.

## Supported versions

There are no supported stable versions yet. Security fixes will be developed and reviewed as part of the current development branch until the first production release.

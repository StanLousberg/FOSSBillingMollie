<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Client\Entity\ClientBalance;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent;
use Box\Mod\Invoice\InvoiceOperation;
use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Support\Amount;
use Box\Mod\Molliepayments\Support\Credentials;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

/** Checkout and authenticated reconciliation; the host remains the accounting authority. */
final class PaymentService
{
    private const TERMINAL_STATUSES = ['failed', 'canceled', 'expired'];

    private readonly AttemptRepository $attempts;

    /** @param \Closure(string): MollieApiClient|null $clientFactory */
    public function __construct(private readonly \Pimple\Container $di, private readonly ?\Closure $clientFactory = null)
    {
        Support\Compatibility::assertHost();
        $this->attempts = new AttemptRepository($di['em']->getConnection());
    }

    /** Admin CLI recovery uses exactly the same claimed, authenticated webhook path. */
    public function reconcile(string $attemptId, string $paymentId): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $attemptId) || !preg_match('/^tr_[A-Za-z0-9]{1,60}$/D', $paymentId)) {
            throw new \Payment_Exception('Invalid reconciliation identifiers.', [], 400);
        }
        $attempt = $this->attempts->find($attemptId);

        return (int) $this->di['mod_service']('Invoice', 'Transaction')->createAndProcess([
            'invoice_id' => (int) $attempt['invoice_id'], 'gateway_id' => (int) $attempt['gateway_id'],
            'get' => ['invoice_id' => (int) $attempt['invoice_id'], 'attempt_id' => $attemptId, 'reconciliation_id' => bin2hex(random_bytes(16))],
            'post' => ['id' => $paymentId],
        ]);
    }

    /** Returns a trusted checkout/invoice URL, or null while the provider is processing. */
    public function checkout(int $invoiceId, int $gatewayId): ?string
    {
        $invoice = $this->invoice($invoiceId);
        $this->invoiceService()->checkInvoiceAuth($invoice, InvoiceOperation::PAYMENT);
        [$gateway, $environment, $key] = $this->gateway($gatewayId);
        if (!$gateway->isEnabled() || !$gateway->isAllowSingle()) {
            throw new \Payment_Exception('MolliePayments is not enabled for one-time payments.');
        }

        $attempt = $this->reserveAttempt($invoice, $gateway, $environment, $key);

        $this->assertNoLegacyCredit($attempt);
        if ((int) $attempt['gateway_id'] !== $gatewayId || $attempt['environment'] !== $environment) {
            throw new \Payment_Exception('An existing MolliePayments attempt uses a different gateway or environment. Reconcile it before retrying.');
        }
        $lease = bin2hex(random_bytes(16));
        if (!$this->attempts->acquire($attempt['id'], $lease)) {
            throw new \Payment_Exception('MolliePayments is already checking this invoice. Please retry shortly.');
        }

        try {
            $attempt = $this->attempts->find($attempt['id']);
            if ($attempt['payment_id'] === null) {
                $this->assertInvoiceMatches($invoice, $attempt);
                if (time() - (int) $attempt['created_at'] >= 2700
                    || !hash_equals($attempt['credential_fingerprint'], hash_hmac('sha256', $attempt['id'], $key))) {
                    $this->attempts->update($attempt['id'], ['error' => 'creation_requires_reconciliation']);
                    throw new \Payment_Exception('Unresolved Mollie payment creation. Reconcile the saved attempt with Mollie before retrying.');
                }
                $payment = $this->createPayment($attempt, $key);
            } else {
                $payment = $this->fetchPayment($attempt['payment_id'], $key);
            }
            $attempt = $this->synchronize($attempt, $payment);
            $status = $attempt['status'];
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                // The next checkout may reserve a fresh attempt, after authenticated finality.
                throw new \Payment_Exception('The previous Mollie payment ended. Select MolliePayments again to retry.');
            }
            if ($status === 'paid') {
                $this->reconcile($attempt['id'], $payment->id);

                $boundAdapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway, $invoice);

                return $this->trustedHostUrl((string) $boundAdapter->getParam('invoice_url'));
            }
            $this->di['em']->refresh($invoice);
            $this->assertPayable($invoice);
            $this->assertInvoiceMatches($invoice, $attempt);
            if ($status !== 'open') {
                return null;
            }
            if ($payment->expiresAt !== null && strtotime($payment->expiresAt) <= time()) {
                throw new \Payment_Exception('Mollie checkout has expired. Wait for the final payment status before retrying.');
            }

            return Support\CheckoutUrl::validate($payment->getCheckoutUrl());
        } finally {
            $this->attempts->release($attempt['id'], $lease);
        }
    }

    /** Commit payment identity before any provider request; never allocate account credit. */
    private function reserveAttempt(Invoice $invoice, PayGateway $gateway, string $environment, string $key): array
    {
        return $this->attempts->connection->transactional(function () use ($invoice, $gateway, $environment, $key): array {
            $gatewayId = (int) $gateway->getId();
            $this->lockInvoice($invoice);
            $this->assertPayable($invoice);
            if (($existing = $this->attempts->active((int) $invoice->getId())) !== null) {
                return $existing;
            }
            $amount = $this->outstanding($invoice);
            // All callbacks originate in the host gateway service, never request input.
            $gatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
            $boundAdapter = $gatewayService->getPaymentAdapter($gateway, $invoice);
            // The native return document does not start a session when Strict
            // cookies are withheld on the cross-site navigation from Mollie.
            $redirect = $this->trustedHostUrl((string) $boundAdapter->getParam('return_url'));
            $webhook = $this->trustedHostUrl((string) $boundAdapter->getParam('notify_url'));
            $id = bin2hex(random_bytes(16));
            $currency = Amount::currency((string) $invoice->getCurrency());
            $accepted = $gatewayService->getAcceptedCurrencies($gateway);
            if (!in_array($currency, $accepted, true)) {
                throw new \Payment_Exception('This currency is not accepted by MolliePayments.');
            }
            $payload = [
                'description' => mb_substr($this->invoiceService()->getPaymentInvoice($invoice)->getTitle(), 0, 255),
                'amount' => ['currency' => $currency, 'value' => $amount],
                'sequenceType' => 'oneoff',
                'redirectUrl' => $redirect,
                'webhookUrl' => $webhook . '&attempt_id=' . $id,
                'metadata' => [
                    'attempt_id' => $id, 'invoice_id' => (string) $invoice->getId(),
                    'gateway_id' => (string) $gatewayId, 'client_id' => (string) $invoice->getClientId(),
                    'environment' => $environment,
                ],
            ];

            return $this->attempts->reserve([
                'id' => $id, 'invoice_id' => $invoice->getId(), 'active_invoice' => $invoice->getId(),
                'gateway_id' => $gatewayId, 'client_id' => $invoice->getClientId(), 'payment_id' => null,
                'environment' => $environment, 'amount' => $amount, 'currency' => $currency,
                'status' => 'creating', 'idempotency_key' => $id,
                'credential_fingerprint' => hash_hmac('sha256', $id, $key),
                'request' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => time(), 'updated_at' => time(), 'lease_until' => 0,
            ]);
        });
    }

    /** The host has already acquired this transaction's processing claim. */
    public function processTransaction(int $transactionId, array $data, int $gatewayId): void
    {
        $paymentId = $data['post']['id'] ?? null;
        $attemptId = $data['get']['attempt_id'] ?? null;
        if ($paymentId === null || $paymentId === '') {
            throw new \Payment_Exception('Mollie webhook requires a form POST payment ID.', [], 400);
        }
        if (!is_string($paymentId) || !preg_match('/^tr_[A-Za-z0-9]{1,60}$/D', $paymentId)) {
            throw new \Payment_Exception('Malformed Mollie payment ID.', [], 400);
        }
        if (!is_string($attemptId) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new \Payment_Exception('Missing or malformed Mollie callback attempt ID.', [], 400);
        }
        $attempt = $this->attempts->find($attemptId);
        $this->assertNoLegacyCredit($attempt);
        [, $environment, $key] = $this->gateway($gatewayId);
        $tx = $this->di['em']->getRepository(Transaction::class)->find($transactionId);
        if (!$tx instanceof Transaction || (int) $tx->getGateway()?->getId() !== $gatewayId
            || (int) $tx->getInvoice()?->getId() !== (int) $attempt['invoice_id']
            || (string) ($data['get']['invoice_id'] ?? '') !== (string) $attempt['invoice_id']
            || (int) $attempt['gateway_id'] !== $gatewayId || $attempt['environment'] !== $environment
            || ($attempt['payment_id'] !== null && $attempt['payment_id'] !== $paymentId)) {
            throw new \Payment_Exception('Mollie callback does not match its local invoice, gateway or environment.', [], 409);
        }
        $payment = $this->fetchPayment($paymentId, $key);
        $attempt = $this->synchronize($attempt, $payment);
        if ($attempt['status'] !== 'paid') {
            $this->attempts->connection->transactional(function () use ($tx, $attempt): void {
                $this->lockInvoice($this->invoice((int) $attempt['invoice_id']));
                $current = $this->attempts->lock($attempt['id']);
                $this->di['em']->refresh($tx);
                if ($tx->getStatus() !== Transaction::STATUS_PROCESSED && $current['status'] !== 'paid') {
                    $terminal = in_array($current['status'], self::TERMINAL_STATUSES, true);
                    $tx->setTxnId($current['payment_id']);
                    $tx->setTxnStatus($current['status']);
                    $tx->setCurrency($current['currency']);
                    $tx->setStatus($terminal ? Transaction::STATUS_PROCESSED : Transaction::STATUS_RECEIVED);
                    $tx->setAmount($terminal ? '0.00' : null);
                    $tx->setType(\Payment_Transaction::TXTYPE_UNKNOWN);
                    $tx->setNote($terminal ? 'Mollie payment ' . $current['status'] . '. No funds received. Invoice remains unpaid.' : null);
                    $tx->setError(null);
                    $tx->setErrorCode(null);
                    $tx->setUpdatedAt(new \DateTime());
                    $this->di['em']->flush();
                }
            });

            return;
        }

        try {
            $paidNow = $this->settle($attempt, $tx);
        } catch (\Throwable) {
            $this->attempts->update($attempt['id'], ['error' => 'settlement_requires_reconciliation']);
            throw new \Payment_Exception('Verified Mollie payment needs reconciliation. Inspect its saved attempt and transaction before retrying settlement.', [], 409);
        }
        if ($paidNow) {
            // Financial writes committed first. The host cron executes pending item tasks.
            $this->di['event_dispatcher']->dispatch(new AfterAdminInvoicePaymentReceivedEvent((int) $attempt['invoice_id']));
        }
    }

    private function settle(array $attempt, Transaction $tx): bool
    {
        return $this->attempts->connection->transactional(function () use ($attempt, $tx): bool {
            $invoice = $this->invoice((int) $attempt['invoice_id']);
            $this->lockInvoice($invoice);
            $attempt = $this->attempts->lock($attempt['id']);
            $this->di['em']->refresh($tx);
            if ((int) $tx->getGateway()?->getId() !== (int) $attempt['gateway_id']
                || (int) $tx->getInvoice()?->getId() !== (int) $attempt['invoice_id']) {
                throw new \Payment_Exception('Transaction association changed.');
            }
            if ($attempt['settled_transaction_id'] !== null) {
                // A delivery has its own audit row, but never another accounting entry.
                if ((int) $attempt['settled_transaction_id'] !== (int) $tx->getId()) {
                    $tx->setNote('Duplicate Mollie webhook handled. Original payment was already processed.');
                    $tx->setStatus(Transaction::STATUS_PROCESSED);
                    $tx->setTxnId($attempt['payment_id']);
                    $tx->setTxnStatus($attempt['status']);
                    $tx->setCurrency($attempt['currency']);
                    $tx->setType(\Payment_Transaction::TXTYPE_UNKNOWN);
                    $tx->setAmount('0.00');
                    $tx->setUpdatedAt(new \DateTime());
                    $this->di['em']->flush();
                }

                return false;
            }
            if ($tx->getStatus() !== Transaction::STATUS_PROCESSING) {
                throw new \Payment_Exception('Transaction processing claim is required.');
            }
            $this->assertPayable($invoice);
            $this->assertInvoiceMatches($invoice, $attempt);
            $client = $this->di['em']->getRepository(Client::class)->find((int) $attempt['client_id']);
            if (!$client instanceof Client || $client->getCurrency() !== $attempt['currency']) {
                throw new \Payment_Exception('Client currency changed; reconciliation is required.');
            }
            // Follow the host's invoice -> client balance lock order.
            $this->di['mod_service']('Client', 'Balance')->getClientBalanceForUpdate($client);
            $gateway = $this->di['em']->getRepository(PayGateway::class)->find((int) $attempt['gateway_id']);
            $invoice->setGateway($gateway);
            $clientService = $this->di['mod_service']('Client');
            $clientService->addFunds($client, $attempt['amount'], 'Mollie payment ' . $attempt['payment_id'], [
                'type' => 'transaction', 'rel_id' => $tx->getId(),
            ]);
            // Mirror native tryPayWithCredits: debit the authoritative invoice
            // balance once, then mark paid without per-item charging. Per-unit
            // tax rounding can otherwise differ from the host invoice total.
            $clientService->addFunds(
                $client, Amount::decimal(-Amount::minor($attempt['amount'])),
                'Payment for invoice #' . $invoice->getId(),
                ['type' => 'invoice', 'rel_id' => $invoice->getId()],
            );
            $this->invoiceService()->markAsPaid($invoice, false, false, true);
            $tx->setTxnId($attempt['payment_id']);
            $tx->setTxnStatus('paid');
            $tx->setType(\Payment_Transaction::TXTYPE_PAYMENT);
            $tx->setAmount($attempt['amount']);
            $tx->setCurrency($attempt['currency']);
            $tx->setStatus(Transaction::STATUS_PROCESSED);
            $tx->setError(null);
            $tx->setErrorCode(null);
            $tx->setUpdatedAt(new \DateTime());
            $this->di['em']->flush();
            $this->attempts->update($attempt['id'], ['settled_transaction_id' => $tx->getId(), 'error' => null]);

            return true;
        });
    }

    private function synchronize(array $attempt, Payment $payment): array
    {
        $this->validatePayment($attempt, $payment);

        return $this->attempts->connection->transactional(function () use ($attempt, $payment): array {
            $invoice = $this->invoice((int) $attempt['invoice_id']);
            $this->lockInvoice($invoice);
            $current = $this->attempts->lock($attempt['id']);
            $status = $this->validatePayment($current, $payment);
            // A delayed pre-payment GET must never overwrite a verified paid state.
            if ($current['status'] === 'paid' && $status !== 'paid') {
                return $current;
            }
            // Terminal records stay immutable unless actual money arrived later.
            if (in_array($current['status'], self::TERMINAL_STATUSES, true) && $status !== 'paid') {
                return $current;
            }
            $changes = ['payment_id' => $payment->id, 'status' => $status, 'error' => null];
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                $changes['active_invoice'] = null;
            }
            $this->attempts->update($attempt['id'], $changes);

            return $this->attempts->find($attempt['id']);
        });
    }

    private function validatePayment(array $attempt, Payment $payment): string
    {
        $metadata = (array) ($payment->metadata ?? []);
        $sequence = $payment->sequenceType instanceof \BackedEnum ? $payment->sequenceType->value : $payment->sequenceType;
        if (!preg_match('/^tr_[A-Za-z0-9]{1,60}$/D', $payment->id)
            || ($attempt['payment_id'] !== null && $attempt['payment_id'] !== $payment->id)
            || $payment->mode !== $attempt['environment'] || $sequence !== 'oneoff'
            || $payment->amount->currency !== $attempt['currency']
            || Amount::minor($payment->amount->value) !== Amount::minor($attempt['amount'])
            || ($metadata['attempt_id'] ?? null) !== $attempt['id']
            || ($metadata['invoice_id'] ?? null) !== (string) $attempt['invoice_id']
            || ($metadata['gateway_id'] ?? null) !== (string) $attempt['gateway_id']
            || ($metadata['client_id'] ?? null) !== (string) $attempt['client_id']
            || ($metadata['environment'] ?? null) !== $attempt['environment']) {
            throw new \Payment_Exception('Mollie payment verification failed. Reconcile the saved attempt.', [], 409);
        }
        $status = $this->status($payment);
        if ($status === 'paid' && !$payment->isPaid()) {
            throw new \Payment_Exception('Mollie payment has no confirmed paid timestamp.', [], 409);
        }
        if ($payment->hasRefunds() || $payment->hasChargebacks()) {
            throw new \Payment_Exception('Refunded or charged-back Mollie payments require manual reconciliation.', [], 409);
        }

        return $status;
    }

    private function status(Payment $payment): string
    {
        $status = $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status;
        if (!in_array($status, ['open', 'pending', 'authorized', 'paid', 'failed', 'canceled', 'expired'], true)) {
            throw new \Payment_Exception('Unsupported Mollie payment status.', [], 409);
        }

        return $status;
    }

    private function createPayment(array $attempt, string $key): Payment
    {
        $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);

        return $this->provider(function () use ($attempt, $payload, $key): Payment {
            $client = $this->client($key);
            $client->setIdempotencyKey($attempt['idempotency_key']);

            return $client->send(new CreatePaymentRequest(
                description: $payload['description'], amount: new Money($payload['amount']['currency'], $payload['amount']['value']),
                redirectUrl: $payload['redirectUrl'], webhookUrl: $payload['webhookUrl'],
                metadata: $payload['metadata'], sequenceType: 'oneoff',
            ));
        });
    }

    private function fetchPayment(string $id, string $key): Payment
    {
        return $this->provider(fn (): Payment => $this->client($key)->send(new GetPaymentRequest($id)));
    }

    private function client(string $key): MollieApiClient
    {
        return $this->clientFactory !== null ? ($this->clientFactory)($key) : MollieClientFactory::create($key);
    }

    private function provider(\Closure $request): Payment
    {
        try {
            MollieClientFactory::bootSdk();

            return $request();
        } catch (\Throwable) {
            // No previous exception: host logging may serialize its request and credentials.
            throw new \Payment_Exception('Mollie is unavailable. The payment attempt is preserved; please retry later.', [], 503);
        }
    }

    private function invoice(int $id): Invoice
    {
        if ($id <= 0) {
            throw new \Payment_Exception('Invalid invoice.');
        }

        return $this->di['em']->getRepository(Invoice::class)->find($id)
            ?? throw new \Payment_Exception('Invoice not found.');
    }

    private function invoiceService(): \Box\Mod\Invoice\Service
    {
        return $this->di['mod_service']('Invoice');
    }

    private function lockInvoice(Invoice $invoice): void
    {
        $this->invoiceService()->lockInvoiceState($invoice);
        $this->di['em']->refresh($invoice);
    }

    private function assertPayable(Invoice $invoice): void
    {
        if (!$invoice->isIssued() || $invoice->getStatus() !== Invoice::STATUS_UNPAID
            || $invoice->getReplacedByInvoiceId() !== null || !$invoice->getClientId()) {
            throw new \Payment_Exception('This invoice is not payable.');
        }
    }

    private function outstanding(Invoice $invoice): string
    {
        $currency = Amount::currency((string) $invoice->getCurrency());
        $client = $this->di['em']->getRepository(Client::class)->find($invoice->getClientId());
        if (!$client instanceof Client || $client->getCurrency() !== $currency) {
            throw new \Payment_Exception('Invoice and client currencies must match for MolliePayments.');
        }
        $minor = Amount::minor(Amount::fromHost($this->invoiceService()->getTotalWithTax($invoice)));
        if ($minor <= 0) {
            throw new \Payment_Exception('Invoice has no positive outstanding amount.');
        }
        if ($invoice->getCredit() !== null && Amount::minor($invoice->getCredit()) !== 0) {
            throw new \Payment_Exception('Legacy invoice credit requires reconciliation before Mollie checkout.');
        }
        $items = $this->di['em']->getRepository(InvoiceItem::class)->findBy(['invoice' => $invoice]);
        foreach ($items as $item) {
            if ($item->getType() === InvoiceItem::TYPE_DEPOSIT) {
                throw new \Payment_Exception('Add Funds invoices are not supported by MolliePayments yet.');
            }
            if ($item->getCharged()) {
                throw new \Payment_Exception('Partially allocated invoices are unsupported. Reconcile through FOSSBilling before Mollie checkout.');
            }
        }
        // No outstanding-balance API exists in the tested host. Detect prior
        // allocations, but never interpret ledger amounts or reconstruct a balance.
        $balances = $this->di['em']->getRepository(ClientBalance::class);
        $allocationReference = ['client' => $client, 'relId' => (string) $invoice->getId()];
        $allocations = $balances->count([
            'type' => 'invoice',
        ] + $allocationReference);
        foreach (['molliepayments_reserve', 'molliepayments_release'] as $legacyType) {
            if ($balances->count(['type' => $legacyType] + $allocationReference) > 0) {
                throw new \Payment_Exception('Legacy account-credit history requires manual reconciliation before Mollie checkout.', [], 409);
            }
        }
        $transactions = $this->di['em']->getRepository(Transaction::class)->findBy(['invoice' => $invoice, 'status' => Transaction::STATUS_PROCESSED]);
        foreach ($transactions as $transaction) {
            if ($transaction->getAmount() === null || Amount::minor($transaction->getAmount()) !== 0) {
                throw new \Payment_Exception('Previous invoice payments require reconciliation before Mollie checkout.');
            }
        }
        if ($allocations > 0 || $items === []) {
            throw new \Payment_Exception('Invoice allocations require reconciliation before Mollie checkout.');
        }

        return Amount::decimal($minor);
    }

    /** Read-only transition guard: historical split payments must not settle as full invoices. */
    private function assertNoLegacyCredit(array $attempt): void
    {
        if (Amount::minor((string) ($attempt['credit_amount'] ?? '0.00')) !== 0
            || !in_array($attempt['credit_state'] ?? 'none', ['none', ''], true)
            || !empty($attempt['reservation_ledger_id']) || !empty($attempt['release_ledger_id'])) {
            throw new \Payment_Exception('Legacy account-credit attempt requires manual reconciliation. Preserve its records and follow docs/PAYMENTS.md.', [], 409);
        }
    }

    private function assertInvoiceMatches(Invoice $invoice, array $attempt): void
    {
        if ((int) $invoice->getClientId() !== (int) $attempt['client_id']
            || $invoice->getCurrency() !== $attempt['currency']
            || Amount::minor($this->outstanding($invoice)) !== Amount::minor($attempt['amount'])) {
            throw new \Payment_Exception('Invoice balance changed. Reconcile its existing Mollie payment before retrying.');
        }
    }

    private function gateway(int $id): array
    {
        $gateway = $id > 0 ? $this->di['em']->getRepository(PayGateway::class)->find($id) : null;
        if (!$gateway instanceof PayGateway || $gateway->getGateway() !== 'MolliePayments') {
            throw new \Payment_Exception('Invalid MolliePayments gateway.');
        }
        $environment = $gateway->isTestMode() ? 'test' : 'live';
        try {
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!$adapter instanceof \Payment_Adapter_MolliePayments) {
                throw new \UnexpectedValueException();
            }
            $key = Credentials::key([Credentials::field($environment) => $adapter->getParam(Credentials::field($environment))], $environment);
        } catch (\Throwable) {
            throw new \Payment_Exception('MolliePayments saved credentials could not be loaded.');
        }

        return [$gateway, $environment, $key];
    }

    private function trustedHostUrl(string $url): string
    {
        $parts = parse_url($url);
        $base = parse_url(SYSTEM_URL);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['scheme'] ?? '') !== ($base['scheme'] ?? '')
            || ($parts['host'] ?? '') !== ($base['host'] ?? '') || ($parts['port'] ?? null) !== ($base['port'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \Payment_Exception('Invalid FOSSBilling callback URL. Check the host system URL.');
        }

        return $url;
    }
}

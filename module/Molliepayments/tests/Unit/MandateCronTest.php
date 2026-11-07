<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Support\Timestamps;

require_once dirname(__DIR__) . '/MandateContext.php';

function molliePendingCronContext(): object
{
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $ctx->mandateStatus = 'pending';
    mollieWebhook($ctx);

    return $ctx;
}

function mollieFinancialSnapshot(object $ctx): array
{
    return ['transactions' => $ctx->db->fetchAllAssociative('SELECT * FROM ' . $ctx->db->quoteSingleIdentifier('transaction') . ' ORDER BY id'),
        'ledger' => $ctx->db->fetchAllAssociative('SELECT * FROM client_balance ORDER BY id'),
        'invoice' => $ctx->db->fetchAllAssociative('SELECT * FROM invoice ORDER BY id')];
}

test('repeated cron mandate checks never create transactions balance changes or payment events', function (): void {
    $ctx = molliePendingCronContext();
    $before = mollieFinancialSnapshot($ctx);
    $events = 0;
    $ctx->di['event_dispatcher']->addListener(Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent::class, static function () use (&$events): void { ++$events; });
    $transactionService = Mockery::mock(Box\Mod\Invoice\ServiceTransaction::class);
    $transactionService->shouldNotReceive('createAndProcess');
    $ctx->services['invoice/transaction'] = $transactionService;
    for ($i = 0; $i < 3; ++$i) {
        $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
        expect($ctx->payments->retryPendingMandates())->toBe(1)
            ->and($ctx->payments->retryPendingMandates())->toBe(0)
            ->and(mollieFinancialSnapshot($ctx))->toBe($before);
    }
    $ctx->mandateStatus = 'valid';
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(1)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and(mollieFinancialSnapshot($ctx))->toBe($before)->and($events)->toBe(0)
        ->and($ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_customer'))->toBe(1)
        ->and(count(array_filter($ctx->requests, static fn ($r) => $r->getMethod() === 'POST')))->toBe(1);
});

test('cron rejects an invalid local or provider settlement identity without changing financial records', function (string $case): void {
    $ctx = molliePendingCronContext();
    $row = mollieAttempt($ctx);
    $tx = $ctx->em->find(Transaction::class, $row['settled_transaction_id']);
    match ($case) {
        'transaction_status' => $tx->setStatus(Transaction::STATUS_RECEIVED),
        'transaction_payment' => $tx->setTxnId('tr_other'),
        'transaction_amount' => $tx->setAmount('0.01'),
        'transaction_currency' => $tx->setCurrency('USD'),
        'invoice_status' => $ctx->invoice->setStatus('unpaid'),
        'invoice_owner' => $ctx->invoice->setClientId(999),
        'gateway_environment' => $ctx->gateway->setTestMode(false),
        default => null,
    };
    if ($case === 'payment_customer' || $case === 'payment_metadata' || $case === 'payment_status') {
        $ctx->mutatePayment = static function (array $payment) use ($case): array {
            match ($case) {
                'payment_customer' => $payment['customerId'] = 'cst_other',
                'payment_metadata' => $payment['metadata']['invoice_id'] = '999',
                'payment_status' => $payment['status'] = 'pending',
            };

            return $payment;
        };
    }
    $ctx->em->flush();
    $before = mollieFinancialSnapshot($ctx);
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => $row['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(1)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review')
        ->and(mollieFinancialSnapshot($ctx))->toBe($before)
        ->and($ctx->payments->retryPendingMandates())->toBe(0);
})->with(['transaction_status', 'transaction_payment', 'transaction_amount', 'transaction_currency', 'invoice_status', 'invoice_owner', 'gateway_environment', 'payment_customer', 'payment_metadata', 'payment_status']);

test('pending recovery expires exactly at seven days without provider calls or repeated logging', function (int $age, string $status): void {
    $ctx = molliePendingCronContext();
    $row = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['created_at' => Timestamps::now(-$age), 'updated_at' => Timestamps::now()], ['id' => $row['id']]);
    $before = mollieFinancialSnapshot($ctx);
    $requests = count($ctx->requests);
    $warnings = count($ctx->di['logger']->calls);
    expect($ctx->payments->retryPendingMandates())->toBe($status === 'manual_review' ? 1 : 0)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe($status)
        ->and(count($ctx->requests))->toBe($requests)
        ->and(mollieFinancialSnapshot($ctx))->toBe($before);
    $logged = count($ctx->di['logger']->calls);
    expect($logged - $warnings)->toBe($status === 'manual_review' ? 1 : 0)
        ->and($ctx->payments->retryPendingMandates())->toBe(0)
        ->and(count($ctx->di['logger']->calls))->toBe($logged);
})->with([[604740, 'pending_mandate'], [604800, 'manual_review'], [604801, 'manual_review']]);

test('temporary provider outages inside the recovery horizon defer safely and then recover', function (): void {
    $ctx = molliePendingCronContext();
    $before = mollieFinancialSnapshot($ctx);
    $response = $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class];
    $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = new Mollie\Api\Fake\MockResponse(['status' => 503, 'title' => 'Unavailable'], 503);
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(1)->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
    $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = $response;
    $ctx->mandateStatus = 'valid';
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(1)->and(mollieAttempt($ctx)['onboarding_status'])->toBe('ready')
        ->and(mollieFinancialSnapshot($ctx))->toBe($before);
});

test('explicit verification of an already settled attempt is also transaction-free', function (): void {
    $ctx = molliePendingCronContext();
    $before = mollieFinancialSnapshot($ctx);
    for ($i = 0; $i < 3; ++$i) {
        $ctx->payments->reverifyOnboarding($ctx->invoice->getId());
    }
    expect(mollieFinancialSnapshot($ctx))->toBe($before)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
});

test('an expired attempt with an active lease is reviewed once after that lease is released', function (): void {
    $ctx = molliePendingCronContext();
    $row = mollieAttempt($ctx);
    $ctx->db->update('molliepayments_attempt', ['created_at' => Timestamps::now(-604801), 'lease' => str_repeat('e', 32), 'lease_until' => Timestamps::now(180)], ['id' => $row['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(0)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('pending_mandate');
    (new Box\Mod\Molliepayments\Repository\AttemptRepository($ctx->db))->release($row['id'], str_repeat('e', 32));
    expect($ctx->payments->retryPendingMandates())->toBe(1)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('manual_review')
        ->and($ctx->payments->retryPendingMandates())->toBe(0);
});

test('cron records an explicitly invalid pending mandate without changing the settled payment', function (): void {
    $ctx = molliePendingCronContext();
    $before = mollieFinancialSnapshot($ctx);
    $ctx->mandateStatus = 'invalid';
    $ctx->db->update('molliepayments_attempt', ['updated_at' => Timestamps::now(-360)], ['id' => mollieAttempt($ctx)['id']]);
    expect($ctx->payments->retryPendingMandates())->toBe(1)
        ->and(mollieAttempt($ctx)['onboarding_status'])->toBe('mandate_invalid')
        ->and(mollieAttempt($ctx)['mandate_verified_at'])->toBeNull()
        ->and(mollieFinancialSnapshot($ctx))->toBe($before)
        ->and($ctx->payments->retryPendingMandates())->toBe(0);
});

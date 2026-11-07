<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Repository\CustomerRepository;
use Box\Mod\Molliepayments\Support\Timestamps;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

require_once dirname(__DIR__) . '/OnboardingContext.php';

test('epochs display in the native PHP timezone including DST boundaries', function (string $zone, string $instant, string $expected): void {
    $previous = date_default_timezone_get();
    try {
        // This is the same PHP default the host sets from i18n.timezone; no host config file changes.
        date_default_timezone_set($zone);
        $epoch = (new DateTimeImmutable($instant))->getTimestamp();
        expect(Timestamps::present($epoch))->toBe($expected)
            ->and((new DateTimeImmutable(Timestamps::present($epoch)))->getTimestamp())->toBe($epoch)
            ->and(Timestamps::present(null))->toBeNull()->and(Timestamps::present(0))->toBeNull();
    } finally {
        date_default_timezone_set($previous);
    }
})->with([
    ['UTC', '2026-01-15T12:00:00Z', '2026-01-15T12:00:00+00:00'],
    ['Europe/Amsterdam', '2026-01-15T12:00:00Z', '2026-01-15T13:00:00+01:00'],
    ['Europe/Amsterdam', '2026-07-15T12:00:00Z', '2026-07-15T14:00:00+02:00'],
    ['Europe/Amsterdam', '2026-03-29T00:59:59Z', '2026-03-29T01:59:59+01:00'],
    ['Europe/Amsterdam', '2026-03-29T01:00:00Z', '2026-03-29T03:00:00+02:00'],
    ['Europe/Amsterdam', '2026-10-25T00:59:59Z', '2026-10-25T02:59:59+02:00'],
    ['Europe/Amsterdam', '2026-10-25T01:00:00Z', '2026-10-25T02:00:00+01:00'],
]);

test('module epochs native DATETIME and elapsed protection stay consistent across host timezones', function (string $zone): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($zone);
        $ctx = mollieOnboarding(molliePaymentContext());
        mollieFirst($ctx);
        $attempt = mollieAttempt($ctx);
        $customer = mollieCustomerRow($ctx);
        foreach ([$attempt['created_at'], $attempt['updated_at'], $attempt['consent_accepted_at'], $customer['created_at'], $customer['updated_at']] as $epoch) {
            expect((int) $epoch)->toBeGreaterThanOrEqual(time() - 10)->toBeLessThanOrEqual(time());
        }
        $repo = new AttemptRepository($ctx->db);
        expect($repo->acquire($attempt['id'], 'worker'))->toBeTrue()
            ->and((int) mollieAttempt($ctx)['lease_until'] - time())->toBeGreaterThanOrEqual(179)->toBeLessThanOrEqual(180);
        expect($repo->acquire($attempt['id'], 'competitor'))->toBeFalse();
        $ctx->db->update(AttemptRepository::TABLE, ['lease_until' => time() - 1], ['id' => $attempt['id']]);
        expect($repo->acquire($attempt['id'], 'recovered'))->toBeTrue();
        $repo->release($attempt['id'], 'recovered');
        $ctx->status = 'paid';
        $tx = mollieWebhook($ctx);
        $table = $ctx->db->quoteSingleIdentifier('transaction');
        $native = $ctx->db->fetchOne("SELECT updated_at FROM {$table} WHERE id = ?", [$tx->getId()]);
        expect($native)->toBe($tx->getUpdatedAt()->format('Y-m-d H:i:s'))
            ->and($tx->getUpdatedAt()->getTimezone()->getName())->toBe($zone)
            ->and($tx->getUpdatedAt()->getTimestamp())->toBeGreaterThanOrEqual(time() - 10)
            ->and((int) mollieAttempt($ctx)['mandate_verified_at'])->toBeGreaterThanOrEqual(time() - 10);
        $before = mollieAttempt($ctx);
        date_default_timezone_set($zone === 'UTC' ? 'Europe/Amsterdam' : 'UTC');
        Timestamps::present((int) $before['created_at']);
        expect(mollieAttempt($ctx))->toBe($before);
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['UTC', 'Europe/Amsterdam']);

test('45 minute customer and payment retry windows are absolute in every host timezone', function (string $zone): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($zone);
        $ctx = mollieCustomers(molliePaymentContext());
        $ctx->customerResponses[CreateCustomerRequest::class] = new MockResponse([], 500);
        expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class);
        $customer = mollieCustomerRow($ctx);
        $ctx->db->update(CustomerRepository::TABLE, ['created_at' => time() - 2700], ['id' => $customer['id']]);
        expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'requires operator recovery');
        $ctx->responses[CreatePaymentRequest::class] = new MockResponse([], 500);
        expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class);
        $attempt = mollieAttempt($ctx);
        $ctx->db->update(AttemptRepository::TABLE, ['created_at' => time() - 2700], ['id' => $attempt['id']]);
        expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'Unresolved Mollie payment creation');
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['UTC', 'Europe/Amsterdam']);

test('native transaction lifecycle timestamps callback changes without touching processed history', function (string $zone, string $status): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($zone);
        $ctx = molliePaymentContext();
        $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
        $ctx->status = $status === 'duplicate' ? 'paid' : $status;
        if ($status === 'duplicate') {
            mollieWebhook($ctx);
        }
        $tx = new Box\Mod\Invoice\Entity\Transaction();
        $tx->setGateway($ctx->gateway)->setInvoice($ctx->invoice)
            ->setStatus(Box\Mod\Invoice\Entity\Transaction::STATUS_PROCESSING)
            ->setCreatedAt(new DateTime('2000-01-01 00:00:00'))
            ->setUpdatedAt(new DateTime('2000-01-01 00:00:00'));
        $ctx->em->persist($tx);
        $ctx->em->flush();
        $created = $tx->getCreatedAt()->format('Y-m-d H:i:s');
        $data = ['get' => ['invoice_id' => $ctx->invoice->getId(), 'attempt_id' => mollieAttempt($ctx)['id']],
            'post' => ['id' => 'tr_disposable123']];
        $ctx->payments->processTransaction($tx->getId(), $data, $ctx->gateway->getId());
        $ctx->em->refresh($tx);
        expect($tx->getCreatedAt()->format('Y-m-d H:i:s'))->toBe($created)
            ->and($tx->getUpdatedAt()->getTimestamp())->toBeGreaterThanOrEqual(time() - 10)
            ->and($tx->getUpdatedAt()->getTimezone()->getName())->toBe($zone)
            ->and($tx->getTxnStatus())->toBe($status);
        if ($status !== 'pending') {
            $table = $ctx->db->quoteSingleIdentifier('transaction');
            $before = $ctx->db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$tx->getId()]);
            $ctx->payments->processTransaction($tx->getId(), $data, $ctx->gateway->getId());
            expect($ctx->db->fetchAssociative("SELECT * FROM {$table} WHERE id = ?", [$tx->getId()]))->toBe($before);
        }
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['UTC', 'Europe/Amsterdam'])->with(['pending', 'failed', 'paid', 'duplicate']);

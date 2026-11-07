<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Repository\CustomerRepository;
use Box\Mod\Molliepayments\Support\Timestamps;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

require_once dirname(__DIR__) . '/OnboardingContext.php';

test('native DATETIME displays in the host timezone including DST boundaries', function (string $zone, string $instant, string $expected): void {
    $previous = date_default_timezone_get();
    try {
        // This is the same PHP default the host sets from i18n.timezone; no host config file changes.
        date_default_timezone_set($zone);
        $stored = (new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        expect(Timestamps::present($stored))->toBe($expected)
            ->and(Timestamps::present(null))->toBeNull();
    } finally {
        date_default_timezone_set($previous);
    }
})->with([
    ['UTC', '2026-01-15T12:00:00Z', '2026-01-15 12:00:00'],
    ['Europe/Amsterdam', '2026-01-15T12:00:00Z', '2026-01-15 13:00:00'],
    ['Europe/Amsterdam', '2026-07-15T12:00:00Z', '2026-07-15 14:00:00'],
    ['Europe/Amsterdam', '2026-03-29T00:59:59Z', '2026-03-29 01:59:59'],
    ['Europe/Amsterdam', '2026-03-29T01:00:00Z', '2026-03-29 03:00:00'],
    ['Europe/Amsterdam', '2026-10-25T00:59:59Z', '2026-10-25 02:59:59'],
    ['Europe/Amsterdam', '2026-10-25T01:00:00Z', '2026-10-25 02:00:00'],
]);

test('module and native DATETIME keep elapsed protection across host timezones', function (string $zone): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set($zone);
        $ctx = mollieOnboarding(molliePaymentContext());
        mollieFirst($ctx);
        $attempt = mollieAttempt($ctx);
        $customer = mollieCustomerRow($ctx);
        foreach ([$attempt['created_at'], $attempt['updated_at'], $attempt['consent_accepted_at'], $customer['created_at'], $customer['updated_at']] as $timestamp) {
            expect($timestamp)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
                ->and(Timestamps::expired($timestamp, 10))->toBeFalse()
                ->and($timestamp <= Timestamps::now())->toBeTrue();
        }
        $repo = new AttemptRepository($ctx->db);
        expect($repo->acquire($attempt['id'], 'worker'))->toBeTrue()
            ->and((new DateTimeImmutable(mollieAttempt($ctx)['lease_until'], new DateTimeZone('UTC')))->getTimestamp() - time())->toBeGreaterThanOrEqual(179)->toBeLessThanOrEqual(180);
        expect($repo->acquire($attempt['id'], 'competitor'))->toBeFalse();
        $ctx->db->update(AttemptRepository::TABLE, ['lease_until' => Timestamps::now(-1)], ['id' => $attempt['id']]);
        expect($repo->acquire($attempt['id'], 'recovered'))->toBeTrue();
        $repo->release($attempt['id'], 'recovered');
        $ctx->status = 'paid';
        $tx = mollieWebhook($ctx);
        $table = $ctx->db->quoteSingleIdentifier('transaction');
        $native = $ctx->db->fetchOne("SELECT updated_at FROM {$table} WHERE id = ?", [$tx->getId()]);
        expect($native)->toBe($tx->getUpdatedAt()->format('Y-m-d H:i:s'))
            ->and($tx->getUpdatedAt()->getTimezone()->getName())->toBe($zone)
            ->and($tx->getUpdatedAt()->getTimestamp())->toBeGreaterThanOrEqual(time() - 10)
            ->and(Timestamps::expired(mollieAttempt($ctx)['mandate_verified_at'], 10))->toBeFalse();
        $before = mollieAttempt($ctx);
        date_default_timezone_set($zone === 'UTC' ? 'Europe/Amsterdam' : 'UTC');
        Timestamps::present($before['created_at']);
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
        $ctx->db->update(CustomerRepository::TABLE, ['created_at' => Timestamps::now(-2700)], ['id' => $customer['id']]);
        expect(fn () => mollieResolveCustomer($ctx))->toThrow(Payment_Exception::class, 'requires operator recovery');
        $ctx->responses[CreatePaymentRequest::class] = new MockResponse([], 500);
        expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class);
        $attempt = mollieAttempt($ctx);
        $ctx->db->update(AttemptRepository::TABLE, ['created_at' => Timestamps::now(-2700)], ['id' => $attempt['id']]);
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

test('DATETIME deadline comparisons distinguish both UTC hours in the Amsterdam DST overlap', function (): void {
    $previous = date_default_timezone_get();
    try {
        date_default_timezone_set('Europe/Amsterdam');
        $reference = (new DateTimeImmutable('2026-10-25T01:00:00Z'))->getTimestamp();
        $elapsed = time() - $reference;
        expect(Timestamps::expired('2026-10-25 00:59:59', $elapsed))->toBeTrue()
            ->and(Timestamps::expired('2026-10-25 01:59:59', $elapsed))->toBeFalse();
    } finally {
        date_default_timezone_set($previous);
    }
});

test('fresh module schemas store native dates and release leases to null', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    foreach ([Box\Mod\Molliepayments\Entity\MolliePaymentAttempt::class, Box\Mod\Molliepayments\Entity\MollieCustomer::class] as $class) {
        $metadata = $ctx->em->getClassMetadata($class);
        foreach (['createdAt', 'updatedAt', 'leaseUntil'] as $field) {
            expect($metadata->getTypeOfField($field))->toBe(Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);
        }
    }
    $row = mollieAttempt($ctx);
    $repository = new AttemptRepository($ctx->db);
    expect($repository->acquire($row['id'], 'date-worker'))->toBeTrue();
    $repository->release($row['id'], 'date-worker');
    expect(mollieAttempt($ctx)['lease_until'])->toBeNull()
        ->and(mollieCustomerRow($ctx)['lease_until'])->toBeNull();
});

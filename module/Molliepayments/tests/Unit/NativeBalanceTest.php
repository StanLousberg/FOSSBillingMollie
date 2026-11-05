<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Support\Amount;

require_once dirname(__DIR__) . '/PaymentContext.php';

test('checkout leaves existing native credit untouched and charges the full invoice', function (string $credit): void {
    $ctx = molliePaymentContext();
    $ctx->item->setPrice('25.00');
    $ctx->em->flush();
    mollieCredit($ctx, $credit);
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['amount']['value'])->toBe('25.00')
        ->and(mollieBalance($ctx))->toBe(Amount::minor($credit));
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    mollieWebhook($ctx);
    expect(mollieBalance($ctx))->toBe(Amount::minor($credit))
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type LIKE 'molliepayments_%'"))->toBe(0);
})->with(['0.00', '10.00', '25.00', '30.00']);

test('native account credit independently pays fully covered invoices', function (string $credit, int $remaining): void {
    $ctx = molliePaymentContext();
    $ctx->item->setPrice('25.00');
    $ctx->em->flush();
    mollieCredit($ctx, $credit);
    expect($ctx->invoiceService->payInvoiceWithCredits($ctx->invoice))->toBeTrue()
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_PAID)
        ->and(mollieBalance($ctx))->toBe($remaining)
        ->and($ctx->requests)->toBeEmpty()
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM molliepayments_attempt'))->toBe(0);
})->with([['25.00', 0], ['30.00', 500]]);

test('terminal callbacks leave credit untouched without a technical error', function (string $status): void {
    $ctx = molliePaymentContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect(mollieBalance($ctx))->toBe(1000);
    $ctx->status = $status;
    $tx = mollieWebhook($ctx);
    expect(mollieBalance($ctx))->toBe(1000)
        ->and($tx->getStatus())->toBe(Transaction::STATUS_PROCESSED)->and($tx->getAmount())->toBe('0.00')
        ->and($tx->getTxnStatus())->toBe($status)->and($tx->getError())->toBeNull()
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID);
    mollieWebhook($ctx);
    expect(mollieBalance($ctx))->toBe(1000)
        ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type LIKE 'molliepayments_%'"))->toBe(0);
})->with(['canceled', 'failed', 'expired']);

test('new account credit does not change an already open provider payment', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    mollieCredit($ctx, '10.00');
    $url = $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($url)->toContain('mollie.com/checkout/')
        ->and($ctx->payload['amount']['value'])->toBe('12.34')
        ->and(mollieBalance($ctx))->toBe(1000)->and(count($ctx->requests))->toBe(2);
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieBalance($ctx))->toBe(1000);
});

test('settlement rollback leaves native credit and provider identity intact', function (): void {
    $ctx = molliePaymentContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->invoiceService->shouldReceive('markAsPaid')->once()->andThrow(new RuntimeException('Accounting failed'));
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'needs reconciliation')
        ->and(mollieBalance($ctx))->toBe(1000)
        ->and((int) $ctx->db->fetchOne("SELECT COUNT(*) FROM client_balance WHERE type = 'transaction'"))->toBe(0);
});

test('prior native invoice allocations are rejected without interpreting their balance', function (): void {
    $ctx = molliePaymentContext();
    mollieCredit($ctx, '10.00');
    $ctx->clientService->addFunds($ctx->client, '-10.00', 'Previously allocated credit', ['type' => 'invoice', 'rel_id' => $ctx->invoice->getId()]);
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))
        ->toThrow(Payment_Exception::class, 'allocations require reconciliation')
        ->and($ctx->requests)->toBeEmpty()->and(mollieBalance($ctx))->toBe(0);
});

test('an unallocated earlier receipt requires a specific reconciliation error', function (): void {
    $ctx = molliePaymentContext();
    $tx = new Transaction();
    $tx->setInvoice($ctx->invoice)->setGateway($ctx->gateway)->setStatus('processed')->setAmount('10.00')->setCurrency('EUR');
    $ctx->em->persist($tx);
    $ctx->em->flush();
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'Previous invoice payments')
        ->and($ctx->requests)->toBeEmpty();
});

test('native invoice total includes VAT and discounts without splitting credit', function (): void {
    $ctx = molliePaymentContext();
    $ctx->item->setPrice('25.00')->setTaxed(true);
    $ctx->invoice->setTaxrate(21);
    $discount = new InvoiceItem();
    $discount->setInvoice($ctx->invoice)->setTitle('Discount')->setType(InvoiceItem::TYPE_CUSTOM)->setPrice('-5.00')->setQuantity(1)->setTaxed(true);
    $ctx->em->persist($discount);
    $ctx->em->flush();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    expect($ctx->payload['amount']['value'])->toBe('24.20');
    $ctx->status = 'paid';
    mollieWebhook($ctx);
    expect(mollieBalance($ctx))->toBe(1000);
});

test('an open payment without its checkout link remains reserved and does not create a replacement', function (): void {
    $ctx = molliePaymentContext();
    mollieCredit($ctx, '10.00');
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->mutatePayment = static function (array $response): array {
        unset($response['_links']['checkout']);

        return $response;
    };
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'no longer provides a checkout link')
        ->and(mollieBalance($ctx))->toBe(1000)
        ->and(count($ctx->requests))->toBe(2)->and($ctx->requests[1]->getMethod())->toBe('GET');
});

test('legacy split attempts remain blocked and additive updates preserve their audit columns', function (): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->db->executeStatement('ALTER TABLE molliepayments_attempt ADD credit_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00');
    $ctx->db->executeStatement("ALTER TABLE molliepayments_attempt ADD credit_state VARCHAR(16) NOT NULL DEFAULT 'none'");
    $ctx->db->update('molliepayments_attempt', ['credit_amount' => '10.00', 'credit_state' => 'reserved'], ['id' => mollieAttempt($ctx)['id']]);
    $before = mollieAttempt($ctx);
    $ctx->services['molliepayments']->update();
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))
        ->toThrow(Payment_Exception::class, 'Legacy account-credit attempt');
    $ctx->status = 'paid';
    expect(fn () => mollieWebhook($ctx))->toThrow(Payment_Exception::class, 'Legacy account-credit attempt')
        ->and(mollieAttempt($ctx))->toBe($before)
        ->and(count($ctx->requests))->toBe(1)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
});

test('orphaned legacy reservation ledger entries block checkout without modifying balances', function (): void {
    $ctx = molliePaymentContext();
    mollieCredit($ctx, '10.00');
    $ctx->clientService->addFunds($ctx->client, '-10.00', 'Historical reservation fixture', ['type' => 'molliepayments_reserve', 'rel_id' => $ctx->invoice->getId()]);
    expect(fn () => $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId()))
        ->toThrow(Payment_Exception::class, 'Legacy account-credit history')
        ->and(mollieBalance($ctx))->toBe(0)
        ->and($ctx->requests)->toBeEmpty()
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
});

test('terminal delivery does not dispatch payment events or change invoice revenue', function (string $status): void {
    $ctx = molliePaymentContext();
    $events = 0;
    $ctx->di['event_dispatcher']->addListener(Box\Mod\Invoice\Event\AfterAdminInvoicePaymentReceivedEvent::class, static function () use (&$events): void { ++$events; });
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = $status;
    mollieWebhook($ctx);
    mollieWebhook($ctx);
    $stats = new Box\Mod\Stats\Service();
    $stats->setDi($ctx->di);
    expect((float) $stats->getSummaryIncome()['total'])->toBe(0.0);
    expect($events)->toBe(0)
        ->and($ctx->invoice->getPaidAt())->toBeNull()
        ->and((float) $ctx->invoice->getBaseIncome())->toBe(0.0)
        ->and($ctx->item->getStatus())->not->toBe(InvoiceItem::STATUS_PENDING_SETUP)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and((float) $ctx->db->fetchOne('SELECT SUM(amount) FROM `transaction`'))->toBe(0.0);
})->with(['failed', 'canceled', 'expired']);

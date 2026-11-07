<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Support\TransactionDiagnostics;

require_once dirname(__DIR__) . '/TransactionContext.php';

test('native orphan transaction obtains only the provider verified attempt invoice', function (string $status, bool $first): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    $first ? mollieFirst($ctx) : $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $ctx->status = $status;
    $tx = mollieOrphan($ctx);
    expect($tx->getInvoice())->toBeNull();
    $ctx->transactionService->preProcessTransaction($tx);
    $ctx->em->refresh($tx);
    expect($tx->getInvoice()?->getId())->toBe($ctx->invoice->getId())
        ->and($ctx->invoice->getStatus())->toBe($status === 'paid' ? Invoice::STATUS_PAID : Invoice::STATUS_UNPAID)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe($status === 'paid' ? 2 : 0);
    if ($status === 'paid') {
        $duplicate = mollieOrphan($ctx, false);
        $ctx->transactionService->preProcessTransaction($duplicate);
        $ctx->em->refresh($duplicate);
        expect($duplicate->getInvoice()?->getId())->toBe($ctx->invoice->getId())->and((float) $duplicate->getAmount())->toBe(0.0)
            ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(2);
    }
})->with(['paid', 'failed', 'canceled', 'expired', 'pending', 'authorized', 'open'])->with([true, false]);

test('orphan association rejects unverified context without linking or settling', function (string $case): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $ctx->status = 'paid';
    $tx = mollieOrphan($ctx);
    $data = $tx->getIpn();
    if ($case === 'get_invoice') {
        $data['get']['invoice_id'] = 999;
        $tx->setIpn($data);
    } elseif ($case === 'get_array') {
        $data['get']['invoice_id'] = ['1'];
        $tx->setIpn($data);
    } elseif ($case === 'payment') {
        $data['post']['id'] = 'tr_foreign';
        $tx->setIpn($data);
    } elseif ($case === 'conflict') {
        $invoice = clone $ctx->invoice;
        $invoice->setHash(bin2hex(random_bytes(20)));
        $ctx->em->persist($invoice);
        $ctx->em->flush();
        $tx->setInvoice($invoice);
    } elseif ($case === 'txn_id') {
        $tx->setTxnId('tr_different_native_identity');
    } elseif ($case === 'gateway') {
        $tx->setGateway(null);
    } elseif ($case === 'other_gateway') {
        $gateway = clone $ctx->gateway;
        $ctx->em->persist($gateway);
        $ctx->em->flush();
        $tx->setGateway($gateway);
    } else {
        $ctx->mutatePayment = static function (array $payment) use ($case): array {
            match ($case) {
                'mode' => $payment['mode'] = 'live',
                'amount' => $payment['amount']['value'] = '0.01',
                'metadata' => $payment['metadata']['invoice_id'] = '999',
                'customer' => $payment['customerId'] = 'cst_foreign',
            };

            return $payment;
        };
    }
    $ctx->em->flush();
    $before = $tx->getInvoice()?->getId();
    expect(fn () => $ctx->transactionService->preProcessTransaction($tx))->toThrow($case === 'gateway' ? FOSSBilling\Core\Exception\BaseException::class : Payment_Exception::class);
    $ctx->em->refresh($tx);
    expect($tx->getInvoice()?->getId())->toBe($before)
        ->and($ctx->invoice->getStatus())->toBe(Invoice::STATUS_UNPAID)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with(['get_invoice', 'get_array', 'payment', 'txn_id', 'conflict', 'gateway', 'other_gateway', 'mode', 'amount', 'metadata', 'customer']);

test('processed orphan financial history stays immutable and diagnostic stays read only', function (): void {
    $ctx = mollieOnboarding(molliePaymentContext());
    mollieFirst($ctx);
    $tx = mollieOrphan($ctx);
    $tx->setStatus(Transaction::STATUS_PROCESSED)->setAmount('12.34')->setTxnStatus('paid');
    $ctx->em->flush();
    $table = $ctx->db->quoteSingleIdentifier('transaction');
    $before = $ctx->db->fetchAllAssociative("SELECT * FROM {$table}");
    $rows = TransactionDiagnostics::orphans($ctx->db);
    expect($rows[0])->toMatchArray(['transaction_id' => $tx->getId(), 'provider_verified' => false,
        'local_candidate_invoice_id' => $ctx->invoice->getId(), 'action' => 'preserve_processed_history_operator_review']);
    expect(fn () => $ctx->payments->processTransaction($tx->getId(), $tx->getIpn(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class, 'history cannot be relinked');
    expect($ctx->db->fetchAllAssociative("SELECT * FROM {$table}"))->toBe($before)
        ->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0)
        ->and(count($ctx->requests))->toBe(1);
});

test('orphan cannot link without the native processing claim or when provider is unavailable', function (bool $claim): void {
    $ctx = molliePaymentContext();
    $ctx->payments->checkout($ctx->invoice->getId(), $ctx->gateway->getId());
    $tx = mollieOrphan($ctx, false);
    if ($claim) {
        $ctx->transactionService->claimForProcessing($tx->getId());
        $ctx->responses[Mollie\Api\Http\Requests\GetPaymentRequest::class] = Mollie\Api\Fake\MockResponse::error(503, 'Unavailable', 'Sensitive provider details');
    }
    expect(fn () => $ctx->payments->processTransaction($tx->getId(), $tx->getIpn(), $ctx->gateway->getId()))->toThrow(Payment_Exception::class);
    $ctx->em->refresh($tx);
    expect($tx->getInvoice())->toBeNull()->and((int) $ctx->db->fetchOne('SELECT COUNT(*) FROM client_balance'))->toBe(0);
})->with([true, false]);

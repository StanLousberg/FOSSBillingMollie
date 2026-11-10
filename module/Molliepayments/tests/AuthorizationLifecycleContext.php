<?php

declare(strict_types=1);

require_once __DIR__ . '/MultiOrderContext.php';
require_once __DIR__ . '/MandateContext.php';

function mollieConsentEvidence(object $ctx): array
{
    return array_intersect_key((new Box\Mod\Molliepayments\Repository\AttemptRepository($ctx->db))->find(mollieAttempt($ctx)['id']), array_flip([
        'id', 'invoice_id', 'client_id', 'gateway_id', 'environment', 'amount', 'currency', 'request',
        'consent_scope', 'consent_version', 'consent_text', 'consent_accepted_at', 'idempotency_key', 'credential_fingerprint',
    ]));
}

function mollieProviderPosts(object $ctx): int
{
    return count(array_filter([...$ctx->requests, ...$ctx->customerRequests], static fn ($request) => $request->getMethod() === 'POST'));
}

function mollieScheduleOrderCancellation(object $ctx, Box\Mod\Order\Entity\Order $order): void
{
    $meta = new Box\Mod\Order\Entity\OrderMeta();
    $meta->setOrder($order)->setName(Box\Mod\Order\Service::META_CANCEL_AT_PERIOD_END)->setValue('1');
    $ctx->em->persist($meta);
    $ctx->em->flush();
}

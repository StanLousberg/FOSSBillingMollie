<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientContext.php';

/** Add native order/item relations only inside the disposable test EntityManager. */
function mollieExtraOrder(object $ctx, ?string $period = '1M'): Box\Mod\Order\Entity\Order
{
    $order = clone $ctx->order;
    $order->setPeriod($period)->setTitle($period === null ? 'Disposable one-time product' : 'Disposable recurring ' . $period);
    $ctx->em->persist($order);
    $ctx->em->flush();
    $item = clone $ctx->item;
    $item->setTitle($order->getTitle())->setPeriod($period)->setRelId((string) $order->getId());
    $ctx->em->persist($item);
    $ctx->em->flush();

    return $order;
}

function mollieExtraLine(object $ctx, string $kind): void
{
    $item = new Box\Mod\Invoice\Entity\InvoiceItem();
    $item->setInvoice($ctx->invoice)->setTitle($kind === 'discount' ? 'Native discount' : 'Native setup fee')
        ->setQuantity(1)->setPrice($kind === 'discount' ? '-1.00' : '5.00')
        ->setUnit($kind === 'discount' ? 'discount' : 'service')
        ->setRelId($kind === 'discount' ? (string) $ctx->order->getId() : null);
    $ctx->em->persist($item);
    $ctx->em->flush();
}

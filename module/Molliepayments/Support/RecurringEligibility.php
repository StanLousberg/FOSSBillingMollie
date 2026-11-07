<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Order\Entity\Order;
use Box\Mod\Order\Entity\OrderMeta;
use Doctrine\ORM\EntityManagerInterface;
use FOSSBilling\Core\Period;

/** Conservative order authorization scope, independent of fixed-price gateway subscriptions. */
final class RecurringEligibility
{
    public static function scope(EntityManagerInterface $em, Invoice $invoice): ?array
    {
        $items = $em->getRepository(InvoiceItem::class)->findByInvoiceId((int) $invoice->getId());
        $scope = null;
        foreach ($items as $item) {
            $em->refresh($item);
            if ($item->getType() !== InvoiceItem::TYPE_ORDER || !ctype_digit((string) $item->getRelId())) {
                return null;
            }
            $order = $em->find(Order::class, (int) $item->getRelId());
            if (!$order instanceof Order) {
                return null;
            }
            $em->refresh($order);
            if ((int) $order->getClientId() !== (int) $invoice->getClientId()
                || !in_array($order->getStatus(), [Order::STATUS_PENDING_SETUP, Order::STATUS_ACTIVE], true)
                || !$order->getPeriod() || $item->getPeriod() !== $order->getPeriod()) {
                return null;
            }
            if ($em->getRepository(OrderMeta::class)->findOneBy(['order' => $order, 'name' => 'cancel_at_period_end', 'value' => '1']) !== null) {
                return null;
            }
            try {
                $period = new Period($order->getPeriod());
            } catch (\Throwable) {
                return null;
            }
            $current = ['order_id' => (string) $order->getId(), 'order_title' => (string) $order->getTitle(),
                'period' => $period->getCode(), 'period_title' => $period->getTitle()];
            if ($scope !== null && $scope !== $current) {
                return null;
            }
            $scope = $current;
        }

        return $scope;
    }
}

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
    /** Every line is classified; an invalid reference blocks authorization of the whole invoice. */
    public static function classify(EntityManagerInterface $em, Invoice $invoice): array
    {
        $orders = $oneTime = $adjustments = $invalid = $lines = [];
        $items = $em->getRepository(InvoiceItem::class)->findByInvoiceId((int) $invoice->getId());
        usort($items, static fn (InvoiceItem $left, InvoiceItem $right): int => $left->getId() <=> $right->getId());
        foreach ($items as $item) {
            $em->refresh($item);
            $lines[(int) $item->getId()] = [
                'id' => (string) $item->getId(), 'type' => $item->getType(), 'rel_id' => $item->getRelId(),
                'title' => $item->getTitle(), 'period' => $item->getPeriod(), 'price' => $item->getPrice(),
                'quantity' => $item->getQuantity(), 'unit' => $item->getUnit(), 'taxed' => $item->getTaxed(),
            ];
            if ($item->getType() !== InvoiceItem::TYPE_ORDER) {
                // Native cart setup: unlinked custom/service line. Discount: custom, order rel_id, unit discount.
                if (!in_array($item->getType(), [null, InvoiceItem::TYPE_CUSTOM], true) || $item->getPeriod()
                    || ($item->getRelId() && ($item->getUnit() !== 'discount' || !ctype_digit($item->getRelId())))
                    || ($item->getUnit() === 'discount' && (!$item->getRelId() || (float) $item->getPrice() > 0))) {
                    $invalid[] = (string) $item->getId();
                } else {
                    $adjustments[] = ['title' => (string) $item->getTitle(), 'order_id' => $item->getRelId(),
                        'kind' => $item->getUnit() === 'discount' ? 'discount' : 'one_time'];
                }
                continue;
            }
            $order = ctype_digit((string) $item->getRelId()) ? $em->find(Order::class, (int) $item->getRelId()) : null;
            if (!$order instanceof Order) {
                $invalid[] = (string) $item->getId();
                continue;
            }
            $em->refresh($order);
            if ((int) $order->getClientId() !== (int) $invoice->getClientId()
                || !in_array($order->getStatus(), [Order::STATUS_PENDING_SETUP, Order::STATUS_ACTIVE], true)
                || $item->getPeriod() !== $order->getPeriod()
                || $em->getRepository(OrderMeta::class)->findOneBy(['order' => $order, 'name' => 'cancel_at_period_end', 'value' => '1']) !== null) {
                $invalid[] = (string) $item->getId();
                continue;
            }
            $current = ['order_id' => (string) $order->getId(), 'order_title' => (string) $order->getTitle(),
                'period' => $order->getPeriod(), 'price' => $order->getPrice(), 'quantity' => $order->getQuantity(),
                'currency' => $order->getCurrency(), 'discount' => $order->getDiscount(),
                'product_id' => $order->getProductId()];
            if (!$order->getPeriod()) {
                $oneTime[(int) $order->getId()] = $current;
                continue;
            }
            try {
                $period = new Period($order->getPeriod());
                $current += ['period_title' => $period->getTitle()];
                $orders[(int) $order->getId()] = $current;
            } catch (\Throwable) {
                $invalid[] = (string) $item->getId();
            }
        }
        foreach ($adjustments as $adjustment) {
            if ($adjustment['order_id'] !== null && !isset($orders[(int) $adjustment['order_id']]) && !isset($oneTime[(int) $adjustment['order_id']])) {
                $invalid[] = 'discount_reference';
            }
        }
        ksort($orders, SORT_NUMERIC);
        ksort($oneTime, SORT_NUMERIC);
        ksort($lines, SORT_NUMERIC);

        return ['recurring_orders' => array_values($orders), 'one_time_orders' => array_values($oneTime),
            'one_time_lines' => $adjustments, 'invalid_references' => $invalid,
            'period_count' => count(array_unique(array_column($orders, 'period'))), 'invoice_lines' => array_values($lines)];
    }

    public static function scope(EntityManagerInterface $em, Invoice $invoice): ?array
    {
        $scope = self::classify($em, $invoice);

        return $scope['invalid_references'] === [] && $scope['recurring_orders'] !== [] ? $scope : null;
    }

    public static function legacyScope(EntityManagerInterface $em, Invoice $invoice): ?array
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

<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Module-owned audit record. Runtime writes use DBAL to avoid stale ORM state. */
#[ORM\Entity]
#[ORM\Table(name: 'molliepayments_attempt')]
#[ORM\UniqueConstraint(name: 'mp_active_invoice', columns: ['active_invoice'])]
#[ORM\UniqueConstraint(name: 'mp_provider_payment', columns: ['gateway_id', 'environment', 'payment_id'])]
#[ORM\UniqueConstraint(name: 'mp_idempotency', columns: ['idempotency_key'])]
#[ORM\Index(name: 'mp_invoice', columns: ['invoice_id'])]
class MolliePaymentAttempt
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    public string $id;

    #[ORM\Column(type: 'integer')]
    public int $invoiceId;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $activeInvoice = null;

    #[ORM\Column(type: 'integer')]
    public int $gatewayId;

    #[ORM\Column(type: 'integer')]
    public int $clientId;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $paymentId = null;

    #[ORM\Column(length: 4)]
    public string $environment;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    public string $amount;

    #[ORM\Column(length: 3)]
    public string $currency;

    #[ORM\Column(length: 24)]
    public string $status = 'creating';

    #[ORM\Column(length: 64)]
    public string $idempotencyKey;

    // A non-reversible binding prevents replay with a rotated API credential.
    #[ORM\Column(length: 64)]
    public string $credentialFingerprint;

    #[ORM\Column(type: 'text')]
    public string $request;

    #[ORM\Column(type: 'integer')]
    public int $createdAt;

    #[ORM\Column(type: 'integer')]
    public int $updatedAt;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $lease = null;

    #[ORM\Column(type: 'integer')]
    public int $leaseUntil = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $settledTransactionId = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $error = null;
}

<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Provider mapping with an encrypted immutable creation snapshot; no plaintext personal fields. */
#[ORM\Entity]
#[ORM\Table(name: 'molliepayments_customer')]
#[ORM\UniqueConstraint(name: 'mp_customer_context', columns: ['client_id', 'gateway_id', 'environment'])]
#[ORM\UniqueConstraint(name: 'mp_customer_provider', columns: ['gateway_id', 'environment', 'customer_id'])]
#[ORM\UniqueConstraint(name: 'mp_customer_idempotency', columns: ['idempotency_key'])]
class MollieCustomer
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    public string $id;

    #[ORM\Column(type: 'bigint')]
    public int $clientId;

    #[ORM\Column(type: 'bigint')]
    public int $gatewayId;

    #[ORM\Column(length: 4)]
    public string $environment;

    #[ORM\Column(length: 128)]
    public string $profileId;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $customerId = null;

    #[ORM\Column(length: 16)]
    public string $status = 'creating';

    #[ORM\Column(length: 64)]
    public string $idempotencyKey;

    #[ORM\Column(length: 64)]
    public string $credentialFingerprint;

    #[ORM\Column(type: 'text')]
    public string $request;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $lease = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $leaseUntil = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $error = null;
}

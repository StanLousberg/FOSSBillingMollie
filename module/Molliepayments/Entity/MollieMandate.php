<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'molliepayments_mandate')]
#[ORM\UniqueConstraint(name: 'mp_mandate_identity', columns: ['mapping_id', 'mandate_id'])]
class MollieMandate
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    public string $id;

    #[ORM\Column(length: 32)]
    public string $mappingId;

    #[ORM\Column(length: 128)]
    public string $mandateId;

    #[ORM\Column(length: 16)]
    public string $status;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $providerStatus;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $scope;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $method;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $summary;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $providerCreatedAt;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $signatureDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $verifiedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $checkedAt;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $error;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $revocation;
}

<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'molliepayments_mandate_system_audit')]
#[ORM\Index(name: 'mp_system_audit_context', columns: ['mapping_id', 'mandate_id', 'created_at'])]
class MollieMandateSystemAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    #[ORM\Column(length: 32)]
    public string $mappingId;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $mandateId;

    #[ORM\Column(length: 32)]
    public string $action;

    #[ORM\Column(length: 32)]
    public string $outcome;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;
}

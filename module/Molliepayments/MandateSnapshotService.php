<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Molliepayments\Repository\MandateRepository;
use Box\Mod\Molliepayments\Support\Timestamps;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Mandate;

final class MandateSnapshotService
{
    private readonly MandateRepository $mandates;

    public function __construct(private readonly \Pimple\Container $di, private readonly ?\Closure $clientFactory = null, private readonly ?\Closure $assertSourceLease = null)
    {
        Support\Compatibility::assertHost();
        $this->mandates = new MandateRepository($di['em']->getConnection());
    }

    public function snapshot(array $mapping, MollieApiClient $client, string $lease, string $mandateId, ?int $adminId, ?Mandate $mandate = null, bool $scoped = false): array
    {
        if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $mandateId)) {
            throw new \UnexpectedValueException();
        }
        $this->fence($mapping, $lease);
        try {
            if ($mandate === null) {
                try {
                    $mandate = $client->send(new GetMandateRequest($mapping['customer_id'], $mandateId));
                } catch (ApiException $error) {
                    if (!in_array($error->getStatusCode(), [404, 410], true)) {
                        throw $error;
                    }

                    return $this->missing($mapping, $lease, $mandateId, $adminId);
                }
            }
            if (!$mandate instanceof Mandate) {
                throw new \UnexpectedValueException();
            }
            $link = $mandate->_links->customer->href ?? null;
            $status = $mandate->status instanceof \BackedEnum ? $mandate->status->value : $mandate->status;
            if ($mandate->resource !== 'mandate' || $mandate->id !== $mandateId || !preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $mandateId)
                || $mandate->mode !== $mapping['environment'] || !in_array($status, ['valid', 'pending', 'invalid'], true)
                || ($mandate->customerId !== null && $mandate->customerId !== $mapping['customer_id'])
                || ($link !== null && $link !== 'https://api.mollie.com/v2/customers/' . $mapping['customer_id'])
                || ($mandate->customerId === null && $link === null)
                || !is_string($mandate->method) || !preg_match('/^[a-z0-9_]{1,32}$/D', $mandate->method)) {
                throw new \UnexpectedValueException();
            }
            $signature = $mandate->signatureDate;
            if ($signature !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $signature) || !\DateTimeImmutable::createFromFormat('!Y-m-d', $signature) || \DateTimeImmutable::createFromFormat('!Y-m-d', $signature)->format('Y-m-d') !== $signature)) {
                throw new \UnexpectedValueException();
            }
            $created = $mandate->createdAt === null ? null : (new \DateTimeImmutable($mandate->createdAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            // Never persist names, account numbers, fingerprints, email addresses or raw details.
            $summary = match ($mandate->method) {
                'creditcard' => 'Card (details hidden)', 'directdebit' => 'Bank account (details hidden)', 'paypal' => 'PayPal account (details hidden)',
                default => 'Payment instrument (details hidden)',
            };

            $instrument = match ($mandate->method) {
                'directdebit' => $mandate->details->consumerAccount ?? null,
                'creditcard' => $mandate->details->cardNumber ?? null,
                default => null,
            };
            if (is_string($instrument) && (strlen($instrument) > 8 || ($mandate->method === 'creditcard' && strlen($instrument) === 4)) && strlen($instrument) <= 64 && preg_match('/[0-9]{4}$/D', $instrument, $suffix)) {
                $summary = ($mandate->method === 'directdebit' ? 'Bank account' : 'Card') . ' **** ' . $suffix[0];
            }

            return $this->persist($mapping, $lease, $mandateId, $adminId, ['status' => $status, 'provider_status' => $status, 'scope' => $scoped || (is_array($mandate->scopes ?? null) && in_array('customer-not-present', $mandate->scopes, true)) ? 'customer-not-present' : null, 'method' => $mandate->method, 'summary' => $summary,
                'signature_date' => $signature, 'provider_created_at' => $created, 'verified_at' => Timestamps::now(), 'error' => null]);
        } catch (\Throwable $error) {
            if ($adminId !== null) {
                $this->failedCheck($mapping, $lease, $mandateId, $adminId);
            }
            throw $error;
        }
    }

    public function failedCheck(array $mapping, string $lease, string $mandateId, ?int $adminId): void
    {
        $this->mandates->write($mapping, $lease, function () use ($mapping, $mandateId, $adminId): void {
            if ($this->assertSourceLease !== null) {
                ($this->assertSourceLease)();
            }
            $this->mandates->save($mapping['id'], $mandateId, ['checked_at' => Timestamps::now(), 'error' => 'verification_failed']);
            $this->mandates->audit($mapping['id'], $mandateId, $adminId, 'verify', 'unverified');
        });
    }

    public function missing(array $mapping, string $lease, string $mandateId, ?int $adminId): array
    {
        return $this->persist($mapping, $lease, $mandateId, $adminId, ['status' => 'missing', 'scope' => null, 'verified_at' => Timestamps::now(), 'error' => null]);
    }

    private function persist(array $mapping, string $lease, string $mandateId, ?int $adminId, array $changes): array
    {
        $this->fence($mapping, $lease);

        return $this->mandates->write($mapping, $lease, function () use ($mapping, $mandateId, $adminId, $changes): array {
            if ($this->assertSourceLease !== null) {
                ($this->assertSourceLease)();
            }
            $previous = $this->mandates->find($mapping['id'], $mandateId);
            if ($previous !== null && $previous['revocation'] === 'requested' && in_array($changes['status'], ['invalid', 'missing'], true)) {
                $changes['revocation'] = 'confirmed';
            }
            $row = $this->mandates->save($mapping['id'], $mandateId, $changes + ['checked_at' => Timestamps::now()]);
            $this->mandates->audit($mapping['id'], $mandateId, $adminId, 'verify', $changes['status']);

            return $row;
        });
    }

    public function fence(array $mapping, string $lease): void
    {
        (new CustomerService($this->di, $this->clientFactory))->assertSavedMapping($mapping);
        $this->mandates->write($mapping, $lease, static fn () => null);
    }
}

<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Support\Credentials;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\Resources\Mandate;
use Mollie\Api\Resources\Payment;

/** Read-only provider verification after financial commit, independent of browser identity. */
final class MandateService
{
    public function __construct(private readonly \Pimple\Container $di, private readonly ?\Closure $clientFactory = null)
    {
    }

    public function verify(string $attemptId, Payment $payment): void
    {
        $attempts = new AttemptRepository($this->di['em']->getConnection());
        $attempt = $attempts->find($attemptId);
        if ($attempt['consent_version'] === null || $attempt['settled_transaction_id'] === null || !$payment->isPaid()) {
            return;
        }
        $lease = bin2hex(random_bytes(16));
        if (!$attempts->acquire($attemptId, $lease)) {
            return;
        }
        $changes = ['onboarding_status' => 'pending_mandate', 'mandate_verified_at' => null];
        try {
            // Fence all writes, including invalidation of an earlier ready observation.
            $attempts->connection->update(AttemptRepository::TABLE, $changes, ['id' => $attemptId, 'lease' => $lease]);
            $attempt = $attempts->find($attemptId);
            $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
            if ($attempt['status'] !== 'paid' || $attempt['consent_text'] === null || $attempt['consent_accepted_at'] === null
                || $payload['sequenceType'] !== 'first' || $payment->id !== $attempt['payment_id']
                || $payment->customerId !== $payload['customerId'] || $payment->mode !== $attempt['environment']
                || !isset($payment->profileId) || $payment->profileId !== $payload['metadata']['profile_id']) {
                throw new \UnexpectedValueException();
            }
            (new CustomerService($this->di, $this->clientFactory))->verifyOnboardingCustomer($attemptId);
            $mandateId = $payment->mandateId;
            $link = $payment->_links->mandate->href ?? null;
            if ($link !== null) {
                $prefix = 'https://api.mollie.com/v2/customers/' . $payload['customerId'] . '/mandates/';
                $linkedId = is_string($link) && str_starts_with($link, $prefix) ? substr($link, strlen($prefix)) : '';
                if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $linkedId) || ($mandateId !== null && $mandateId !== $linkedId)) {
                    $changes['onboarding_status'] = 'manual_review';

                    return;
                }
                $mandateId = $linkedId;
            }
            if ($mandateId === null) {
                return; // Never guess from a customer's older mandates.
            }
            if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $mandateId)
                || ($attempt['mandate_id'] !== null && $attempt['mandate_id'] !== $mandateId)) {
                $changes['onboarding_status'] = 'manual_review';

                return;
            }
            $gateway = $this->di['em']->find(PayGateway::class, (int) $attempt['gateway_id']);
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            $field = Credentials::field($attempt['environment']);
            $key = Credentials::key([$field => $adapter->getParam($field)], $attempt['environment']);
            if (!hash_equals($attempt['credential_fingerprint'], hash_hmac('sha256', $attemptId, $key))) {
                throw new \UnexpectedValueException();
            }
            $client = $this->clientFactory !== null ? ($this->clientFactory)($key) : MollieClientFactory::create($key);
            $mandate = $client->send(new GetMandateRequest($payload['customerId'], $mandateId));
            $customerLink = $mandate->_links->customer->href ?? null;
            if (!$mandate instanceof Mandate || $mandate->resource !== 'mandate' || $mandate->id !== $mandateId
                || $mandate->mode !== $attempt['environment']
                || ($mandate->customerId !== null && $mandate->customerId !== $payload['customerId'])
                || ($mandate->customerId === null && $customerLink !== 'https://api.mollie.com/v2/customers/' . $payload['customerId'])
                || ($customerLink !== null && $customerLink !== 'https://api.mollie.com/v2/customers/' . $payload['customerId'])) {
                $changes['onboarding_status'] = 'manual_review';

                return;
            }
            $status = $mandate->status instanceof \BackedEnum ? $mandate->status->value : $mandate->status;
            $changes += ['mandate_id' => $mandate->id, 'mandate_status' => in_array($status, ['valid', 'pending', 'invalid'], true) ? $status : 'unknown'];
            $method = $payment->method instanceof \BackedEnum ? $payment->method->value : $payment->method;
            $expected = match ($method) {
                'creditcard' => 'creditcard', 'paypal' => 'paypal',
                'ideal', 'bancontact', 'belfius', 'eps', 'kbc', 'paybybank' => 'directdebit',
                default => null,
            };
            if ($expected === null || $mandate->method !== $expected || !in_array($status, ['valid', 'pending', 'invalid'], true)) {
                $changes['onboarding_status'] = 'manual_review';

                return;
            }
            if (isset($mandate->scopes) && (!is_array($mandate->scopes) || !in_array('customer-not-present', $mandate->scopes, true))) {
                $changes['onboarding_status'] = 'manual_review';

                return;
            }
            if ($status !== 'valid') {
                $changes['onboarding_status'] = $status === 'invalid' ? 'mandate_invalid' : 'pending_mandate';

                return;
            }
            // The API supports filtering by MIT scope even when the SDK resource has no scopes property.
            // Cursor inclusion starts at this exact ID: never select a newer/older mandate as a fallback.
            $scoped = $client->send(new GetPaginatedMandateRequest($payload['customerId'], from: $mandate->id, limit: 1, scopes: ['customer-not-present']));
            foreach ($scoped as $candidate) {
                if ($candidate->id === $mandate->id && $candidate->mode === $attempt['environment'] && $candidate->isValid() && $candidate->method === $expected) {
                    $changes['onboarding_status'] = 'ready';
                    $changes['mandate_verified_at'] = time();

                    return;
                }
            }
            $changes['onboarding_status'] = 'manual_review';
        } catch (\Throwable) {
            // A paid invoice remains settled. No raw provider errors or instrument data are retained.
            $changes['onboarding_status'] = 'pending_mandate';
        } finally {
            $attempts->connection->update(AttemptRepository::TABLE, $changes + ['updated_at' => time()], ['id' => $attemptId, 'lease' => $lease]);
            $attempts->release($attemptId, $lease);
        }
    }
}

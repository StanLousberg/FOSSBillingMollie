<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Molliepayments\Repository\AttemptRepository;
use Box\Mod\Molliepayments\Support\Credentials;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Box\Mod\Molliepayments\Support\ProviderUnavailableException;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\MollieApiClient;
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
        $attempt = $attempts->find($attemptId);
        $changes = ['onboarding_status' => 'pending_mandate', 'mandate_verified_at' => null];
        $category = 'pending';
        try {
            $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
            if ($attempt['status'] !== 'paid' || $attempt['consent_text'] === null || $attempt['consent_accepted_at'] === null
                || $payload['sequenceType'] !== 'first' || $payment->id !== $attempt['payment_id']
                || $payment->customerId !== $payload['customerId'] || $payment->mode !== $attempt['environment']
                || !isset($payment->profileId) || $payment->profileId !== $payload['metadata']['profile_id']) {
                throw new \UnexpectedValueException();
            }
            $invoice = $this->di['em']->find(\Box\Mod\Invoice\Entity\Invoice::class, (int) $attempt['invoice_id']);
            $scope = $invoice === null ? null : Support\RecurringEligibility::scope($this->di['em'], $invoice);
            if (!in_array($attempt['consent_version'], ['order-collection-v2', PaymentService::CONSENT_VERSION], true) || $scope === null
                || ($payload['metadata']['order_id'] ?? null) !== $scope['order_id']
                || ($payload['metadata']['order_period'] ?? null) !== $scope['period']) {
                throw new \UnexpectedValueException();
            }
            (new CustomerService($this->di, $this->clientFactory))->verifyOnboardingCustomer($attemptId);
            $method = $payment->method instanceof \BackedEnum ? $payment->method->value : $payment->method;
            $expected = match ($method) {
                'creditcard' => 'creditcard', 'paypal' => 'paypal',
                'ideal', 'bancontact', 'belfius', 'eps', 'kbc', 'paybybank' => 'directdebit',
                default => throw new \UnexpectedValueException(),
            };
            $gateway = $this->di['em']->find(PayGateway::class, (int) $attempt['gateway_id']);
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            $field = Credentials::field($attempt['environment']);
            $key = Credentials::key([$field => $adapter->getParam($field)], $attempt['environment']);
            if (!hash_equals($attempt['credential_fingerprint'], hash_hmac('sha256', $attemptId, $key))) {
                throw new \UnexpectedValueException();
            }
            $client = $this->clientFactory !== null ? ($this->clientFactory)($key) : MollieClientFactory::create($key);
            $mandateId = $payment->mandateId;
            $link = $payment->_links->mandate->href ?? null;
            if ($link !== null) {
                $prefix = 'https://api.mollie.com/v2/customers/' . $payload['customerId'] . '/mandates/';
                $linkedId = is_string($link) && str_starts_with($link, $prefix) ? substr($link, strlen($prefix)) : '';
                if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $linkedId) || ($mandateId !== null && $mandateId !== $linkedId)) {
                    throw new \UnexpectedValueException();
                }
                $mandateId = $linkedId;
            }
            if ($mandateId !== null && $attempt['mandate_id'] !== null && $attempt['mandate_id'] !== $mandateId) {
                throw new \UnexpectedValueException();
            }
            // A previously verified association is sticky, even if later Payment GETs omit it.
            $mandateId ??= $attempt['mandate_id'];
            $discovered = $mandateId === null;
            $mandateId ??= $this->discover($client, $payment, $expected);
            if ($mandateId === null) {
                if (Support\Timestamps::expired($attempt['consent_accepted_at'], 86400)) {
                    throw new \UnexpectedValueException();
                }

                return;
            }
            if (!preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $mandateId)) {
                throw new \UnexpectedValueException();
            }
            try {
                $mandate = $client->send(new GetMandateRequest($payload['customerId'], $mandateId));
            } catch (ApiException $error) {
                if (!in_array($error->getStatusCode(), [404, 410], true)) {
                    throw $error;
                }
                // A known association was explicitly removed; never replace it by discovery.
                $changes += ['mandate_id' => $mandateId, 'mandate_status' => 'invalid'];
                $changes['onboarding_status'] = 'mandate_invalid';
                $category = 'revoked';

                return;
            }
            $this->assertMandate($mandate, $payload['customerId'], $attempt['environment'], $expected);
            if ($mandate->id !== $mandateId || ($discovered && !$this->matchesInstrument($payment, $mandate, $expected))) {
                throw new \UnexpectedValueException();
            }
            $status = $mandate->status instanceof \BackedEnum ? $mandate->status->value : $mandate->status;
            if (!in_array($status, ['valid', 'pending', 'invalid'], true)) {
                throw new \UnexpectedValueException();
            }
            $changes += ['mandate_id' => $mandateId, 'mandate_status' => $status];
            if ($status !== 'valid') {
                $changes['onboarding_status'] = $status === 'invalid' ? 'mandate_invalid' : 'pending_mandate';
                $category = $status;

                return;
            }
            // Scope filtering is supported even when this SDK version has no declared scopes property.
            $scoped = $client->send(new GetPaginatedMandateRequest($payload['customerId'], from: $mandateId, limit: 1, scopes: ['customer-not-present']));
            foreach ($scoped as $candidate) {
                if ($candidate->id === $mandateId) {
                    $this->assertMandate($candidate, $payload['customerId'], $attempt['environment'], $expected);
                    if (!$candidate->isValid()) {
                        $changes['mandate_status'] = $candidate->isInvalid() ? 'invalid' : 'pending';
                        $changes['onboarding_status'] = $candidate->isInvalid() ? 'mandate_invalid' : 'pending_mandate';

                        return;
                    }
                    $changes['onboarding_status'] = 'ready';
                    $changes['mandate_verified_at'] = Support\Timestamps::now();

                    return;
                }
            }
            throw new \UnexpectedValueException();
        } catch (ProviderUnavailableException) {
            $changes = $this->onOutage($attempt);
            $category = 'provider_unavailable';
        } catch (ApiException $error) {
            $transient = $error->getStatusCode() >= 500 || in_array($error->getStatusCode(), [408, 429], true);
            $changes = $transient ? $this->onOutage($attempt) : ['onboarding_status' => 'manual_review', 'mandate_verified_at' => null];
            $category = $transient ? 'provider_unavailable' : 'provider_rejected';
        } catch (\Psr\Http\Client\ClientExceptionInterface) {
            $changes = $this->onOutage($attempt);
            $category = 'provider_unavailable';
        } catch (\Throwable) {
            $changes = ['onboarding_status' => 'manual_review', 'mandate_verified_at' => null];
            $category = 'verification_mismatch';
        } finally {
            $attempts->connection->update(AttemptRepository::TABLE, $changes + ['updated_at' => Support\Timestamps::now()], ['id' => $attemptId, 'lease' => $lease]);
            $attempts->release($attemptId, $lease);
            if ($changes['onboarding_status'] !== 'ready' || $category === 'provider_unavailable') {
                $this->di['logger']->warning('MolliePayments mandate check: {category}; attempt {attempt_id}.', ['category' => $category, 'attempt_id' => $attemptId]);
            }
        }
    }

    private function assertMandate(Mandate $mandate, string $customer, string $environment, string $method): void
    {
        $link = $mandate->_links->customer->href ?? null;
        if ($mandate->resource !== 'mandate' || !preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $mandate->id)
            || $mandate->mode !== $environment || $mandate->method !== $method
            || ($mandate->customerId !== null && $mandate->customerId !== $customer)
            || ($mandate->customerId === null && $link !== 'https://api.mollie.com/v2/customers/' . $customer)
            || ($link !== null && $link !== 'https://api.mollie.com/v2/customers/' . $customer)
            || (isset($mandate->scopes) && (!is_array($mandate->scopes) || !in_array('customer-not-present', $mandate->scopes, true)))) {
            throw new \UnexpectedValueException();
        }
    }

    /** One bounded, complete scope-filtered page; select only one strong instrument/time match. */
    private function discover(MollieApiClient $client, Payment $payment, string $method): ?string
    {
        if ($this->instrument($payment->details, $method) === null || $payment->createdAt === null || $payment->paidAt === null) {
            throw new \UnexpectedValueException();
        }
        $mandates = $client->send(new GetPaginatedMandateRequest($payment->customerId, limit: 250, scopes: ['customer-not-present']));
        if (isset($mandates->_links->next->href)) {
            throw new \UnexpectedValueException(); // Incomplete search cannot prove uniqueness.
        }
        $matches = [];
        foreach ($mandates as $mandate) {
            $this->assertMandate($mandate, $payment->customerId, $payment->mode, $mandate->method ?? '');
            if ($mandate->method === $method && $this->matchesInstrument($payment, $mandate, $method)) {
                $matches[$mandate->id] = true;
            }
        }
        if (count($matches) > 1) {
            throw new \UnexpectedValueException();
        }

        return array_key_first($matches);
    }

    private function matchesInstrument(Payment $payment, Mandate $mandate, string $method): bool
    {
        $instrument = $this->instrument($payment->details, $method);
        $created = $mandate->createdAt === null ? false : strtotime($mandate->createdAt);
        $start = $payment->createdAt === null ? false : strtotime($payment->createdAt);
        $paid = $payment->paidAt === null ? false : strtotime($payment->paidAt);

        return $instrument !== null && $instrument === $this->instrument($mandate->details, $method)
            && $created !== false && $start !== false && $paid !== false && $created >= $start && $created <= min(time() + 60, $paid + 86400);
    }

    private function instrument(?object $details, string $method): ?string
    {
        $value = $method === 'creditcard' ? ($details->cardFingerprint ?? null) : ($details->consumerAccount ?? null);
        if (!is_string($value) || $value === '') {
            return null;
        }
        if ($method === 'directdebit') {
            $value = strtoupper(str_replace(' ', '', $value));

            return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $value) ? $value : null;
        }

        return $value; // Full PayPal account reference or card fingerprint; never last-four/name matching.
    }

    private function onOutage(array $attempt): array
    {
        return ['onboarding_status' => in_array($attempt['onboarding_status'], ['ready', 'manual_review', 'mandate_invalid'], true) ? $attempt['onboarding_status'] : 'pending_mandate', 'mandate_verified_at' => $attempt['mandate_verified_at']];
    }
}

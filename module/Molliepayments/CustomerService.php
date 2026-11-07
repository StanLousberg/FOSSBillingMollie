<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments;

use Box\Mod\Client\Entity\Client;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Molliepayments\Repository\CustomerRepository;
use Box\Mod\Molliepayments\Support\Credentials;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\GetCurrentProfileRequest;
use Mollie\Api\Http\Requests\GetCustomerRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\CurrentProfile;
use Mollie\Api\Resources\Customer;

/** Internal prerequisite for explicit onboarding; never invoked by one-time checkout. */
final class CustomerService
{
    private readonly CustomerRepository $customers;

    /** @param \Closure(string): MollieApiClient|null $clientFactory */
    public function __construct(private readonly \Pimple\Container $di, private readonly ?\Closure $clientFactory = null)
    {
        Support\Compatibility::assertHost();
        $this->customers = new CustomerRepository($di['em']->getConnection());
    }

    /** Resolve only the authenticated client's mapping. Creation is always opt-in. */
    public function resolve(int $clientId, int $gatewayId, bool $create = false, bool $verify = false): ?string
    {
        $identity = $this->di['loggedin_client'] ?? null;
        if (!$identity instanceof Client || $clientId < 1 || (int) $identity->getId() !== $clientId) {
            throw new \Payment_Exception('Customer access denied.', [], 403);
        }

        return $this->run($clientId, $gatewayId, $create, $verify);
    }

    /** Trusted webhook path. Reads the committed attempt; never creates or impersonates a client. */
    public function verifyOnboardingCustomer(string $attemptId): void
    {
        $attempt = (new Repository\AttemptRepository($this->customers->connection))->find($attemptId);
        $payload = json_decode($attempt['request'], true, 512, JSON_THROW_ON_ERROR);
        $mapping = $this->customers->find((int) $attempt['client_id'], (int) $attempt['gateway_id'], $attempt['environment']);
        if ($attempt['status'] !== 'paid' || $attempt['settled_transaction_id'] === null || $attempt['consent_version'] === null
            || ($payload['sequenceType'] ?? null) !== 'first' || $mapping === null
            || $mapping['id'] !== ($payload['metadata']['customer_mapping'] ?? null)
            || $mapping['profile_id'] !== ($payload['metadata']['profile_id'] ?? null)
            || $mapping['customer_id'] !== ($payload['customerId'] ?? null)) {
            throw new \Payment_Exception('Unverified onboarding Customer context.');
        }
        if ($this->run((int) $attempt['client_id'], (int) $attempt['gateway_id'], false, true) !== $payload['customerId']) {
            throw new \Payment_Exception('Onboarding Customer verification failed.');
        }
    }

    /** Explicit operator recovery, authenticated GET only, never a replacement POST. */
    public function recover(int $clientId, int $gatewayId, string $mappingId, string $customerId): string
    {
        if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/D', $mappingId) || !$this->validCustomerId($customerId)) {
            throw new \Payment_Exception('Invalid customer recovery request.', [], 403);
        }

        return $this->run($clientId, $gatewayId, false, true, [$mappingId, $customerId])
            ?? throw new \Payment_Exception('Customer mapping not found.');
    }

    private function run(int $clientId, int $gatewayId, bool $create, bool $verify, ?array $recovery = null): ?string
    {
        $db = $this->customers->connection;
        if ($db->isTransactionActive()) {
            throw new \Payment_Exception('Customer operations require a committed database connection.');
        }
        $nativeClient = $clientId > 0 ? $this->di['em']->find(Client::class, $clientId) : null;
        if (!$nativeClient instanceof Client) {
            throw new \Payment_Exception('Customer not found.', [], 404);
        }
        [$environment, $key] = $this->context($gatewayId);
        $row = $this->customers->find($clientId, $gatewayId, $environment);
        if ($recovery !== null && ($row === null || !hash_equals($row['id'], $recovery[0]))) {
            throw new \Payment_Exception('Customer recovery context mismatch.');
        }
        if ($row === null && !$create) {
            return null;
        }
        if ($row !== null) {
            $this->assertCredential($row, $key);
            if ($recovery !== null && $row['customer_id'] !== null && $row['customer_id'] !== $recovery[1]) {
                throw new \Payment_Exception('Recovery cannot replace an existing Customer.');
            }
            if ($row['customer_id'] === null && !$create && $recovery === null) {
                throw new \Payment_Exception('Customer creation is unresolved. Request an explicit retry or operator recovery.');
            }
            if ($row['status'] === 'ready' && !$verify && $recovery === null) {
                return $row['customer_id'];
            }
        }
        $client = $this->provider(fn () => $this->clientFactory !== null ? ($this->clientFactory)($key) : MollieClientFactory::create($key));
        $profile = $this->provider(fn () => $client->send(new GetCurrentProfileRequest()));
        if (!$profile instanceof CurrentProfile || $profile->resource !== 'profile' || !isset($profile->id) || !preg_match('/^pfl_[A-Za-z0-9]{1,124}$/D', $profile->id)) {
            throw new \Payment_Exception('Unexpected Mollie profile response.');
        }
        if ($row === null) {
            $id = bin2hex(random_bytes(16));
            $row = $this->customers->reserve([
                'id' => $id, 'client_id' => $clientId, 'gateway_id' => $gatewayId, 'environment' => $environment,
                'profile_id' => $profile->id, 'customer_id' => null, 'status' => 'creating',
                'idempotency_key' => $id, 'credential_fingerprint' => hash_hmac('sha256', $id, $key),
                'request' => Support\CustomerRequest::snapshot($nativeClient, $id, $this->di['crypt']),
                'created_at' => Support\Timestamps::now(), 'updated_at' => Support\Timestamps::now(), 'lease' => null, 'lease_until' => null, 'error' => null,
            ]);
        }
        $this->assertCredential($row, $key);
        $lease = bin2hex(random_bytes(16));
        if (!$this->customers->acquire($row['id'], $lease)) {
            throw new Support\ProviderUnavailableException('Customer operation already in progress. Retry later.', [], 409);
        }
        try {
            $row = $this->customers->find($clientId, $gatewayId, $environment);
            if (!hash_equals($row['profile_id'], $profile->id)) {
                $this->customers->update($row['id'], $lease, ['status' => 'blocked', 'error' => 'profile_context_changed']);
                throw new \Payment_Exception('Customer merchant/profile context changed. Operator reconciliation required.');
            }
            $providerId = $recovery[1] ?? $row['customer_id'];
            if ($row['customer_id'] !== null && $providerId !== $row['customer_id']) {
                throw new \Payment_Exception('Recovery cannot replace an existing Customer.');
            }
            if ($providerId === null) {
                if (!$create) {
                    throw new \Payment_Exception('Customer creation requires an explicit request.');
                }
                if (Support\Timestamps::expired($row['created_at'], 2700)) {
                    $this->customers->update($row['id'], $lease, ['error' => 'recovery_required']);
                    throw new \Payment_Exception('Unresolved Customer creation requires operator recovery.');
                }
            }
            try {
                if ($providerId === null) {
                    $payload = Support\CustomerRequest::payload($row['request'], $row['id'], $this->di['crypt']);
                    $client->setIdempotencyKey($row['idempotency_key']);
                    $customer = $this->provider(fn () => $client->send(new CreateCustomerRequest(name: $payload['name'] ?? null, email: $payload['email'] ?? null, metadata: $payload['metadata'])));
                } else {
                    $customer = $this->provider(fn () => $client->send(new GetCustomerRequest($providerId)));
                }
                $metadata = $customer instanceof Customer ? (array) ($customer->metadata ?? []) : [];
                if (!$customer instanceof Customer || $customer->resource !== 'customer' || !isset($customer->id, $customer->mode)
                    || !$this->validCustomerId($customer->id) || $customer->mode !== $environment
                    || ($providerId !== null && $customer->id !== $providerId)
                    || $metadata !== json_decode($row['request'], true, 512, JSON_THROW_ON_ERROR)['metadata']) {
                    throw new \Payment_Exception('Mollie Customer identity verification failed. Operator recovery required.');
                }
                $this->customers->update($row['id'], $lease, ['customer_id' => $customer->id, 'status' => 'ready', 'error' => null]);

                return $customer->id;
            } catch (Support\ProviderUnavailableException $error) {
                $this->customers->update($row['id'], $lease, ['error' => 'provider_unavailable']);
                throw $error;
            } catch (\Throwable) {
                $this->customers->update($row['id'], $lease, ['error' => 'verification_or_provider_error', 'status' => $row['customer_id'] === null ? 'creating' : 'blocked']);
                throw new \Payment_Exception('Customer operation unavailable or unverified. The mapping is preserved; retry safely or use operator recovery.');
            }
        } finally {
            $this->customers->release($row['id'], $lease);
        }
    }

    private function assertCredential(array $row, string $key): void
    {
        if (!hash_equals($row['credential_fingerprint'], hash_hmac('sha256', $row['id'], $key))) {
            throw new \Payment_Exception('Customer credential context changed. Operator reconciliation required.');
        }
    }

    private function context(int $gatewayId): array
    {
        $gateway = $gatewayId > 0 ? $this->di['em']->find(PayGateway::class, $gatewayId) : null;
        if (!$gateway instanceof PayGateway || $gateway->getGateway() !== 'MolliePayments' || !$gateway->isEnabled()) {
            throw new \Payment_Exception('Invalid or disabled MolliePayments gateway.');
        }
        $environment = $gateway->isTestMode() ? 'test' : 'live';
        try {
            $adapter = $this->di['mod_service']('Invoice', 'PayGateway')->getPaymentAdapter($gateway);
            if (!$adapter instanceof \Payment_Adapter_MolliePayments) {
                throw new \UnexpectedValueException();
            }
            $field = Credentials::field($environment);

            return [$environment, Credentials::key([$field => $adapter->getParam($field)], $environment)];
        } catch (\Throwable) {
            throw new \Payment_Exception('MolliePayments saved credentials could not be loaded.');
        }
    }

    private function validCustomerId(string $id): bool
    {
        return (bool) preg_match('/^cst_[A-Za-z0-9]{1,251}$/D', $id);
    }

    private function provider(\Closure $request): mixed
    {
        try {
            return $request();
        } catch (\Mollie\Api\Exceptions\ApiException $error) {
            if (!in_array($error->getStatusCode(), [408, 429], true) && $error->getStatusCode() < 500) {
                throw new \Payment_Exception('Mollie Customer request was rejected. Verify the saved context.');
            }
            throw new Support\ProviderUnavailableException('Mollie Customer service temporarily unavailable. The mapping is preserved; retry safely later.', [], 503);
        } catch (\Throwable) {
            // Never chain SDK exceptions: their requests may contain secrets or personal data.
            throw new Support\ProviderUnavailableException('Mollie Customer service temporarily unavailable. The mapping is preserved; retry safely later.', [], 503);
        }
    }
}

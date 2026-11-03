<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use Mollie\Api\Exceptions\InvalidAuthenticationException;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Exceptions\RequestTimeoutException;
use Mollie\Api\MollieApiClient;

final class ConnectionChecker
{
    /** @param \Closure(string): MollieApiClient|null $clientFactory */
    public function __construct(private readonly ?\Closure $clientFactory = null)
    {
    }

    public function check(\Payment_Adapter_MolliePayments $adapter, string $environment): array
    {
        // Unsupported environments are rejected before touching credentials.
        $field = Credentials::field($environment);

        try {
            $key = Credentials::key([$field => $adapter->getParam($field)], $environment);
        } catch (\InvalidArgumentException $exception) {
            return $this->result(false, $environment, $exception->getMessage());
        }

        try {
            $client = $this->clientFactory !== null
                ? ($this->clientFactory)($key)
                : MollieClientFactory::create($key);
            // A successful empty collection is also a confirmed connection.
            // Never return, persist or log the retrieved payment data.
            $client->payments->page(null, 1);

            return $this->result(true, $environment, 'Successfully connected to Mollie.');
        } catch (SdkUnavailableException) {
            $message = 'Mollie SDK is not installed. Run composer install in the MolliePayments repository.';
        } catch (InvalidAuthenticationException) {
            $message = 'Mollie rejected the API key. Check or replace the saved credential.';
        } catch (RequestTimeoutException) {
            $message = 'The connection to Mollie timed out. Please try again later.';
        } catch (NetworkRequestException) {
            $message = 'Could not connect to Mollie. Check outbound HTTPS access and try again.';
        } catch (RequestException $exception) {
            $message = match ($exception->getStatusCode()) {
                401 => 'Mollie rejected the API key. Check or replace the saved credential.',
                403 => 'The API key does not have permission to read payments.',
                408, 504 => 'The connection to Mollie timed out. Please try again later.',
                429 => 'Mollie rate limit reached. Wait before testing again.',
                500, 502, 503 => 'Mollie is temporarily unavailable. Please try again later.',
                default => 'Mollie could not confirm the connection. Check the saved credential and try again later.',
            };
        } catch (\Throwable) {
            // Provider exceptions can carry credentials, request headers and
            // financial data. Do not log them or propagate them to the host.
            $message = 'The connection could not be tested. Check the SDK installation and network configuration.';
        }

        return $this->result(false, $environment, $message);
    }

    private function result(bool $success, string $environment, string $message): array
    {
        return ['success' => $success, 'environment' => $environment, 'message' => $message];
    }
}

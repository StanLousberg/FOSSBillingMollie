<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Api;

use Box\Mod\Staff\Entity\Admin as AdminIdentity;
use FOSSBilling\Core\Api\AbstractApi;
use FOSSBilling\Core\Exception\InformationException;

final class Admin extends AbstractApi
{
    public function connection_test(array $data = []): array
    {
        $this->requireGatewayPermission();

        return $this->getService()->testConnection();
    }

    public function mandate_list(array $data = []): array
    {
        $this->requireMandatePermission('view_mandates');
        $filters = [];
        foreach (['customer', 'mandate', 'status', 'environment', 'view', 'method', 'sort', 'mapping', 'attention'] as $key) {
            $value = $data[$key] ?? '';
            if (!is_string($value) || strlen($value) > 128) {
                throw new InformationException('Invalid mandate filter.', [], 400);
            }
            $filters[$key] = trim($value);
        }
        if (!in_array($filters['view'], ['', 'customers', 'mandates', 'attention'], true) || !in_array($filters['status'], ['', 'unknown', 'valid', 'pending', 'invalid', 'missing'], true)
            || !in_array($filters['attention'], ['', 'failed', 'stale', 'revocation', 'unverified'], true)
            || !in_array($filters['sort'], ['', 'name_asc', 'name_desc'], true)
            || !in_array($filters['environment'], ['', 'test', 'live', 'all'], true)) {
            throw new InformationException('Invalid mandate status or environment.', [], 400);
        }
        $filters['view'] = $filters['view'] ?: 'customers';
        $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            throw new InformationException('Invalid page.', [], 400);
        }

        return $this->getService()->mandates()->overview($filters, $page);
    }

    public function mandate_get(array $data): array
    {
        $this->requireMandatePermission('view_mandates');

        return $this->getService()->mandates()->detail($this->mappingId($data), $this->mandateId($data));
    }

    public function mandate_refresh(array $data): array
    {
        $this->requireMandatePermission('sync_mandates', true);

        return $this->getService()->mandates()->refresh($this->mappingId($data), $this->mandateId($data), (int) $this->identity->getId());
    }

    public function mandate_revoke(array $data): array
    {
        $this->requireMandatePermission('revoke_mandates', true);
        if (($data['confirmation'] ?? null) !== 'revoke') {
            throw new InformationException('Explicit mandate revocation confirmation is required.', [], 400);
        }

        return $this->getService()->mandates()->revoke($this->mappingId($data), $this->mandateId($data), (int) $this->identity->getId());
    }

    private function requireMandatePermission(string $permission, bool $write = false): void
    {
        if (!$this->identity instanceof AdminIdentity) {
            throw new InformationException('Administrator authentication is required.', [], 403);
        }
        $this->checkPermissions('molliepayments', 'view_mandates');
        if ($permission !== 'view_mandates') {
            $this->checkPermissions('molliepayments', $permission);
        }
        $this->checkPermissions('client', 'view');
        if ($write) {
            // Enforce the native session nonce even when global API CSRF prevention is disabled.
            $request = $this->di['request'];
            $body = json_decode($request->getContent(), true);
            $token = $request->request->all()['CSRFToken'] ?? (is_array($body) ? ($body['CSRFToken'] ?? null) : null) ?? $request->headers->get('X-CSRF-TOKEN');
            $session = $this->di['session']->get('csrf_token');
            $cookie = $request->cookies->get(\FOSSBilling\Core\Http\CookieNames::CSRF) ?? $request->cookies->get(\FOSSBilling\Core\Http\CookieNames::LEGACY_CSRF);
            if (!$request->isMethod('POST') || !is_string($token) || !is_string($session) || $session === '' || !hash_equals($session, $token)
                || ($cookie !== null && $cookie !== '' && (!is_string($cookie) || !hash_equals($session, $cookie)))) {
                throw new InformationException('CSRF token invalid or request method unsupported.', [], 403);
            }
        }
    }

    private function mappingId(array $data): string
    {
        $id = $data['mapping_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InformationException('Invalid Customer mapping.', [], 400);
        }
        foreach (['customer_id', 'gateway_id', 'environment', 'profile_id', 'client_id'] as $field) {
            if (array_key_exists($field, $data)) {
                throw new InformationException('Provider context must be resolved from the saved mapping.', [], 400);
            }
        }

        return $id;
    }

    private function mandateId(array $data): string
    {
        $id = $data['mandate_id'] ?? null;
        if (!is_string($id) || !preg_match('/^mdt_[A-Za-z0-9]{1,124}$/D', $id)) {
            throw new InformationException('Invalid mandate.', [], 400);
        }

        return $id;
    }

    private function requireGatewayPermission(): void
    {
        if (!$this->identity instanceof AdminIdentity) {
            throw new InformationException('Administrator authentication is required.', [], 403);
        }
        $this->checkPermissions('invoice', 'manage_gateways');
    }
}

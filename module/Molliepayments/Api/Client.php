<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Api;

use Box\Mod\Client\Entity\Client as ClientIdentity;
use FOSSBilling\Core\Api\AbstractApi;
use FOSSBilling\Core\Http\CookieNames;

final class Client extends AbstractApi
{
    public function checkout(array $data): array
    {
        $this->authorizeWrite();

        return ['checkout_url' => $this->getService()->payments()->oneTimeCheckout($this->invoiceId($data))];
    }

    public function onboarding_start(array $data): array
    {
        $this->authorizeWrite();
        $consent = $this->authorizationConsent($data);

        $page = $this->getService()->payments()->onboarding($this->invoiceId($data));
        if (!is_string($data['consent_digest'] ?? null) || !hash_equals($page['consent_digest'], $data['consent_digest'])) {
            throw new \Payment_Exception('Order authorization changed. Reload the onboarding page.', [], 409);
        }

        return ['checkout_url' => $this->getService()->payments()->firstCheckout($this->invoiceId($data), $consent)];
    }

    public function consent_notice(array $data): array
    {
        $this->authorizeIdentity();

        return $this->getService()->payments()->consentNotice();
    }

    public function cart_checkout(array $data): array
    {
        $this->authorizeWrite();
        $consent = $this->authorizationConsent($data);
        $hash = $data['invoice_hash'] ?? null;
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{32,64}$/D', $hash) || !is_string($data['consent_digest'] ?? null)) {
            throw new \Payment_Exception('Invalid invoice hash or consent digest.', [], 400);
        }

        return ['checkout_url' => $this->getService()->payments()->cartCheckout($hash, $consent, $data['consent_digest'])];
    }

    private function authorizationConsent(array $data): bool
    {
        if (($data['consent_version'] ?? null) !== \Box\Mod\Molliepayments\PaymentService::CONSENT_VERSION) {
            throw new \Payment_Exception('Authorization text changed. Reload the checkout page.', [], 409);
        }
        if (($data['consent'] ?? null) !== '1' && ($data['consent'] ?? null) !== true) {
            throw new \Payment_Exception('Explicit authorization is required.', [], 400);
        }

        return true;
    }

    public function checkout_resume(array $data): array
    {
        $this->authorizeWrite();

        return ['checkout_url' => $this->getService()->payments()->resumeCheckout($this->invoiceId($data))];
    }

    public function onboarding_verify(array $data): array
    {
        $this->authorizeWrite();

        return $this->getService()->payments()->reverifyOnboarding($this->invoiceId($data));
    }

    private function invoiceId(array $data): int
    {
        $id = filter_var($data['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \Payment_Exception('Invalid invoice.', [], 400);
        }

        return $id;
    }

    private function authorizeIdentity(): void
    {
        $identity = $this->getIdentity();
        $loggedIn = $this->di['loggedin_client'] ?? null;
        if (!$identity instanceof ClientIdentity || !$loggedIn instanceof ClientIdentity || $identity->getId() !== $loggedIn->getId()) {
            throw new \Payment_Exception('Client authentication required.', [], 403);
        }
    }

    private function authorizeWrite(): void
    {
        $this->authorizeIdentity();
        // Require the native session nonce even if general API CSRF prevention is disabled.
        $request = $this->di['request'];
        $body = json_decode($request->getContent(), true);
        $token = $request->request->all()['CSRFToken'] ?? (is_array($body) ? ($body['CSRFToken'] ?? null) : null) ?? $request->headers->get('X-CSRF-TOKEN');
        $sessionToken = $this->di['session']->get('csrf_token');
        $cookie = $request->cookies->get(CookieNames::CSRF) ?? $request->cookies->get(CookieNames::LEGACY_CSRF);
        if (!$request->isMethod('POST') || !is_string($token) || !is_string($sessionToken) || $sessionToken === ''
            || !hash_equals($sessionToken, $token) || ($cookie !== null && $cookie !== '' && (!is_string($cookie) || !hash_equals($sessionToken, $cookie)))) {
            throw new \Payment_Exception('CSRF token invalid or request method unsupported.', [], 403);
        }
    }
}

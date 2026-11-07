<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use Box\Mod\Client\Entity\Client;
use FOSSBilling\Core\Validation\EmailValidator;

/** Encrypt only the immutable personal fields; opaque association metadata stays readable. */
final class CustomerRequest
{
    public static function snapshot(Client $client, string $mappingId, object $crypt): string
    {
        try {
            $details = [];
            $name = preg_replace('/[\p{Z}\s]+/u', ' ', $client->getFullName());
            if (is_string($name) && trim($name) !== '' && !preg_match('/\p{C}/u', $name)) {
                $details['name'] = mb_substr(trim($name), 0, 255);
            }
            $email = trim($client->getEmail() ?? '');
            $at = strrpos($email, '@');
            if ($at !== false) {
                $domain = idn_to_ascii(substr($email, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($domain !== false) {
                    $email = substr($email, 0, $at + 1) . $domain;
                }
            }
            if (strlen($email) <= 254 && EmailValidator::isEmailValid($email)) {
                $details['email'] = $email;
            }
            $request = ['metadata' => ['molliepayments_customer' => $mappingId]];
            if ($details !== []) {
                $request['encrypted_details'] = $crypt->encrypt(json_encode(['mapping_id' => $mappingId] + $details, JSON_THROW_ON_ERROR));
            }

            return json_encode($request, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \Payment_Exception('Customer request could not be secured. No creation was started.');
        }
    }

    public static function payload(string $snapshot, string $mappingId, object $crypt): array
    {
        try {
            $request = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (($request['metadata'] ?? null) !== ['molliepayments_customer' => $mappingId]) {
                throw new \UnexpectedValueException();
            }
            $payload = ['metadata' => $request['metadata']];
            if (array_key_exists('encrypted_details', $request)) {
                $decrypted = $crypt->decrypt($request['encrypted_details']);
                if (!is_string($decrypted)) {
                    throw new \UnexpectedValueException();
                }
                $details = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
                if (($details['mapping_id'] ?? null) !== $mappingId) {
                    throw new \UnexpectedValueException();
                }
                foreach (['name', 'email'] as $field) {
                    if (isset($details[$field])) {
                        if (!is_string($details[$field])) {
                            throw new \UnexpectedValueException();
                        }
                        $payload[$field] = $details[$field];
                    }
                }
            }

            return $payload;
        } catch (\Throwable) {
            throw new \Payment_Exception('Saved Customer request could not be verified. Operator recovery required.');
        }
    }
}

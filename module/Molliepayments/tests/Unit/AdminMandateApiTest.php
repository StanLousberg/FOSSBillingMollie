<?php

declare(strict_types=1);

use FOSSBilling\Core\Exception\InformationException;

require_once dirname(__DIR__) . '/AdminMandateContext.php';

test('mandate API rejects guests and client identities for every admin endpoint', function (string $action, string $identity): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $api = mollieMandateAdminApi($ctx, $action);
    $api->setIdentity($identity === 'guest' ? new FOSSBilling\Core\Identity\Guest() : $ctx->client);
    expect(fn () => $api->{$action}([]))->toThrow(InformationException::class, 'Administrator authentication')
        ->and($ctx->adminRequests)->toBeEmpty();
})->with(['mandate_list', 'mandate_get', 'mandate_refresh', 'mandate_revoke'])->with(['guest', 'client']);

test('mandate API checks distinct native permissions before provider requests', function (string $permission, string $action): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $api = mollieMandateAdminApi($ctx, $action);
    $ctx->staff->shouldReceive('checkPermissionsAndThrowException')->with($permission === 'view' ? 'client' : 'molliepayments', $permission, null, Mockery::type(Box\Mod\Staff\Entity\Admin::class))->once()
        ->andThrow(new InformationException('Permission denied', [], 403));
    expect(fn () => $api->{$action}([]))->toThrow(InformationException::class, 'Permission denied')
        ->and($ctx->adminRequests)->toBeEmpty();
})->with([['view_mandates', 'mandate_list'], ['view', 'mandate_get'], ['sync_mandates', 'mandate_refresh'], ['revoke_mandates', 'mandate_revoke']]);

test('mandate writes require session CSRF and POST independently of global configuration', function (string $action, string $case): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $params = ['mapping_id' => $ctx->mappingId, 'mandate_id' => 'mdt_disposable', 'confirmation' => 'revoke'];
    if ($case !== 'missing') {
        $params['CSRFToken'] = $case === 'wrong' ? 'wrong' : 'disposable-admin-nonce';
    }
    $api = mollieMandateAdminApi($ctx, $action, $params, $case === 'get' ? 'GET' : 'POST');
    if ($case === 'cookie') {
        $ctx->di['request']->cookies->set(FOSSBilling\Core\Http\CookieNames::CSRF, 'wrong');
    }
    expect(fn () => $api->{$action}($params))->toThrow(InformationException::class, 'CSRF')
        ->and($ctx->adminRequests)->toBeEmpty();
})->with(['mandate_refresh', 'mandate_revoke'])->with(['missing', 'wrong', 'get', 'cookie']);

test('mandate API rejects invalid filters pagination and browser supplied provider context', function (array $params, string $action): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $params += ['CSRFToken' => 'disposable-admin-nonce'];
    $api = mollieMandateAdminApi($ctx, $action, $params);
    expect(fn () => $api->{$action}($params))->toThrow(InformationException::class)
        ->and($ctx->adminRequests)->toBeEmpty();
})->with([
    [['status' => 'invented'], 'mandate_list'], [['environment' => 'sandbox'], 'mandate_list'], [['customer' => []], 'mandate_list'], [['page' => -1], 'mandate_list'],
    [['mapping_id' => '../bad'], 'mandate_refresh'], [['mapping_id' => str_repeat('a', 32), 'environment' => 'live'], 'mandate_refresh'],
    [['mapping_id' => str_repeat('a', 32), 'mandate_id' => '../bad'], 'mandate_get'], [['confirmation' => 'yes'], 'mandate_revoke'],
]);

test('mandate API uses server context and verifies writes while read permission alone can browse snapshots', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $params = ['mapping_id' => $ctx->mappingId, 'CSRFToken' => 'disposable-admin-nonce'];
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $params['mandate_id'] = 'mdt_disposable';
    $api = mollieMandateAdminApi($ctx, 'mandate_get', $params, 'GET');
    expect($api->mandate_get($params)['mandate_id'])->toBe('mdt_disposable')
        ->and($api->mandate_list()['total'])->toBe(1);
    $params['confirmation'] = 'revoke';
    $api = mollieMandateAdminApi($ctx, 'mandate_revoke', $params);
    expect($api->mandate_revoke($params)['success'])->toBeTrue()->and($ctx->deleteCount)->toBe(1);
});

test('mandate detail cannot select a mandate through another customer mapping', function (): void {
    $ctx = mollieAdminMandates(mollieOnboarding(molliePaymentContext()));
    $ctx->adminMandates->synchronize($ctx->mappingId, 7);
    $other = mollieCustomerRow($ctx);
    $other['id'] = str_repeat('a', 32);
    $other['client_id'] = $ctx->client->getId() + 1;
    $other['customer_id'] = 'cst_other';
    $other['idempotency_key'] = str_repeat('b', 32);
    $ctx->db->insert('molliepayments_customer', $other);
    $params = ['mapping_id' => $other['id'], 'mandate_id' => 'mdt_disposable', 'confirmation' => 'revoke', 'CSRFToken' => 'disposable-admin-nonce'];
    $api = mollieMandateAdminApi($ctx, 'mandate_revoke', $params);
    expect(fn () => $api->mandate_revoke($params))->toThrow(Payment_Exception::class, 'Mandate not found')
        ->and($ctx->deleteCount)->toBe(0);
});

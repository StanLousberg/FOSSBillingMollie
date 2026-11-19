<?php

declare(strict_types=1);

use Box\Mod\Molliepayments\AdminMandateService;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetMandateRequest;
use Mollie\Api\Http\Requests\GetPaginatedMandateRequest;
use Mollie\Api\Http\Requests\RevokeMandateRequest;
use Mollie\Api\MollieApiClient;

require_once __DIR__ . '/MandateContext.php';

function mollieAdminMandates(object $ctx, bool $createMapping = true): object
{
    if ($createMapping) {
        mollieResolveCustomer($ctx);
    }
    $ctx->mappingId = mollieCustomerRow($ctx)['id'];
    $ctx->adminRequests = [];
    $ctx->deleteCount = 0;
    $ctx->adminMandate = ['resource' => 'mandate', 'id' => 'mdt_disposable', 'customerId' => 'cst_disposable',
        'mode' => $ctx->gateway->isTestMode() ? 'test' : 'live', 'status' => 'valid', 'method' => 'directdebit',
        'createdAt' => '2026-10-01T12:30:00+02:00', 'signatureDate' => '2026-10-01',
        'details' => ['consumerAccount' => 'NL00TEST0000000000', 'consumerName' => 'Private Instrument Name']];
    $ctx->adminPages = [[$ctx->adminMandate]];
    $ctx->adminResponses = $ctx->customerResponses + [
        GetPaginatedMandateRequest::class => static function ($pending) use ($ctx) {
            $request = $pending->createPsrRequest();
            $ctx->adminRequests[] = $request;
            parse_str($request->getUri()->getQuery(), $query);
            $index = isset($query['from']) ? 1 : 0;
            $mandates = $ctx->adminPages[$index];

            return MockResponse::ok(['count' => count($mandates), '_embedded' => ['mandates' => $mandates],
                '_links' => ['self' => ['href' => (string) $request->getUri()]] + ($index === 0 && count($ctx->adminPages) > 1 ? ['next' => ['href' => 'https://api.mollie.com/v2/customers/cst_disposable/mandates?from=mdt_second']] : [])]);
        },
        GetMandateRequest::class => static function ($pending) use ($ctx) {
            $ctx->adminRequests[] = $pending->createPsrRequest();

            return MockResponse::ok($ctx->adminMandate);
        },
        RevokeMandateRequest::class => static function ($pending) use ($ctx) {
            $request = $pending->createPsrRequest();
            $ctx->adminRequests[] = $request;
            ++$ctx->deleteCount;
            expect($request->getMethod())->toBe('DELETE')->and($request->getUri()->getPath())->toBe('/v2/customers/cst_disposable/mandates/mdt_disposable');
            $row = $ctx->db->fetchAssociative('SELECT * FROM molliepayments_mandate WHERE mapping_id = ?', [$ctx->mappingId]);
            expect($row['revocation'])->toBe('requested');
            $ctx->adminMandate['status'] = 'invalid';

            return MockResponse::noContent();
        },
    ];
    $ctx->adminMandates = new AdminMandateService($ctx->di, static fn (string $key) => MollieApiClient::fake($ctx->adminResponses, retainRequests: true)->setApiKey($key));

    return $ctx;
}

function mollieAdminMandateRow(object $ctx): array
{
    return $ctx->db->fetchAssociative('SELECT * FROM molliepayments_mandate WHERE mapping_id = ? AND mandate_id = ?', [$ctx->mappingId, 'mdt_disposable']);
}

function mollieMandateHistory(object $ctx): array
{
    return ['financial' => mollieFinancialSnapshot($ctx), 'attempts' => $ctx->db->fetchAllAssociative('SELECT * FROM molliepayments_attempt ORDER BY id')];
}

function mollieMandateAdminApi(object $ctx, string $action, array $params = [], string $method = 'POST'): Box\Mod\Molliepayments\Api\Admin
{
    $ctx->di['request'] = Symfony\Component\HttpFoundation\Request::create('/api/admin/molliepayments/' . $action, $method, $params);
    $session = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
    $session->set('csrf_token', 'disposable-admin-nonce');
    $ctx->di['session'] = $session;
    $api = new Box\Mod\Molliepayments\Api\Admin();
    $api->setDi($ctx->di);
    $api->setIdentity(Tests\Helpers\admin(['id' => 7]));
    $api->setService(new class($ctx->adminMandates) {
        public function __construct(private readonly AdminMandateService $mandates)
        {
        }

        public function mandates(): AdminMandateService
        {
            return $this->mandates;
        }
    });
    $ctx->staff = Mockery::mock(Box\Mod\Staff\Service::class);
    $ctx->staff->shouldReceive('checkPermissionsAndThrowException')->byDefault()->andReturnNull();
    $ctx->services['staff'] = $ctx->staff;

    return $api;
}

function mollieCheckMandatePages(object $ctx): void
{
    $mapping = mollieCustomerRow($ctx);
    $repository = new Box\Mod\Molliepayments\Repository\MandateRepository($ctx->db);
    for ($i = 1; $i <= 26; ++$i) {
        $repository->save($ctx->mappingId, 'mdt_example' . $i, ['status' => 'pending']);
        $copy = array_merge($mapping, ['id' => bin2hex(random_bytes(16)), 'client_id' => $mapping['client_id'] + $i,
            'customer_id' => 'cst_fixture' . $i, 'idempotency_key' => bin2hex(random_bytes(32))]);
        $ctx->db->insert('molliepayments_customer', $copy);
    }
    $grouped = $ctx->adminMandates->overview(['view' => 'customers'], 1);
    $second = $ctx->adminMandates->overview(['view' => 'customers'], 2);
    $individual = $ctx->adminMandates->overview(['view' => 'mandates'], 2);
    expect($grouped['environment'])->toBe('test')->and($grouped['groups'])->toHaveCount(25)
        ->and($grouped['groups'][$mapping['client_id']])->toHaveCount(26)->and($grouped['attention'])->toBe(26)
        ->and($second['groups'])->toHaveCount(2)->and($individual['list'])->toHaveCount(1)
        ->and($individual['total'])->toBe(26);
    $ctx->gateway->setTestMode(false);
    $ctx->em->flush();
    expect($ctx->adminMandates->overview(['view' => 'customers'], 1)['total'])->toBe(0);
    $ctx->gateway->setEnabled(false);
    $ctx->em->flush();
    expect($ctx->adminMandates->overview([], 1)['environment'])->toBe('unconfigured')
        ->and($ctx->adminMandates->overview(['view' => 'customers', 'environment' => 'all'], 1)['total'])->toBe(27);
}

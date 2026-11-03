<?php

declare(strict_types=1);

use Box\Mod\Client\Entity\Client;
use Box\Mod\Client\Entity\ClientBalance;
use Box\Mod\Currency\Entity\Currency;
use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\InvoiceEvent;
use Box\Mod\Invoice\Entity\InvoiceItem;
use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\Entity\Transaction;
use Box\Mod\Molliepayments\Entity\MolliePaymentAttempt;
use Box\Mod\Molliepayments\PaymentService;
use Box\Mod\Molliepayments\Support\MollieClientFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;

require_once PATH_LIBRARY . '/Payment/Adapter/MolliePayments.php';
require_once __DIR__ . '/Support.php';
MollieClientFactory::bootSdk();

/** Real disposable SQLite accounting tables and native services; no host DB is used. */
function molliePaymentContext(string $environment = 'test', ?string $databasePath = null, bool $initialize = true): object
{
    $ctx = new stdClass();
    $ctx->di = Tests\Helpers\container();
    $config = ORMSetup::createAttributeMetadataConfig([PATH_MODS], true);
    $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $config->setProxyDir(sys_get_temp_dir());
    $config->setProxyNamespace('MolliePaymentsTestProxies');
    $ctx->em = new EntityManager(DriverManager::getConnection($databasePath === null ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['driver' => 'pdo_sqlite', 'path' => $databasePath]), $config);
    $ctx->db = $ctx->em->getConnection();
    $classes = [Client::class, ClientBalance::class, Currency::class, Invoice::class, InvoiceItem::class,
        InvoiceEvent::class, PayGateway::class, Transaction::class, MolliePaymentAttempt::class, Box\Mod\Order\Entity\Order::class];
    $ctx->db->executeStatement('PRAGMA busy_timeout = 5000');
    if ($initialize) {
        (new SchemaTool($ctx->em))->createSchema(array_map($ctx->em->getClassMetadata(...), $classes));
    }
    $ctx->di['em'] = $ctx->em;
    unset($ctx->di['dbal']);
    $ctx->di['dbal'] = $ctx->db;
    $ctx->di['api_system'] = new stdClass();
    $ctx->di['crypt'] = Tests\Helpers\cryptMock();
    $ctx->di['event_dispatcher'] = new Symfony\Component\EventDispatcher\EventDispatcher();
    if ($initialize) {
        $ctx->client = new Client();
        $ctx->client->setCurrency('EUR');
        $ctx->client->setEmail('disposable@example.invalid');
        $ctx->em->persist($ctx->client);
        $ctx->gateway = new PayGateway();
        $ctx->gateway->setGateway('MolliePayments');
        $ctx->gateway->setName('MolliePayments');
        $ctx->gateway->setEnabled(true);
        $ctx->gateway->setAllowSingle(true);
        $ctx->gateway->setTestMode($environment === 'test');
        $ctx->gateway->setAcceptedCurrencies('["EUR"]');
        $ctx->key = mollieDummyKey($environment);
        $ctx->gateway->setConfig(json_encode([($environment === 'test' ? 'test_api_key' : 'api_key') => $ctx->key]));
        $ctx->em->persist($ctx->gateway);
        $ctx->em->flush();
        $ctx->invoice = new Invoice();
        $ctx->invoice->setClientId($ctx->client->getId());
        $ctx->invoice->setCurrency('EUR');
        $ctx->invoice->setSerie('INV-');
        $ctx->invoice->setNr('42');
        $ctx->invoice->setIssued(true);
        $ctx->invoice->setHash(bin2hex(random_bytes(20)));
        $ctx->invoice->setTaxrate(0);
        $ctx->em->persist($ctx->invoice);
        $ctx->em->flush();
        $ctx->item = new InvoiceItem();
        $ctx->item->setInvoice($ctx->invoice);
        $ctx->item->setTitle('Disposable service');
        $ctx->item->setType(InvoiceItem::TYPE_CUSTOM);
        $ctx->item->setQuantity(1);
        $ctx->item->setPrice('12.34');
        $ctx->em->persist($ctx->item);
        $ctx->em->flush();
    } else {
        $ctx->client = $ctx->em->getRepository(Client::class)->findOneBy([]);
        $ctx->gateway = $ctx->em->getRepository(PayGateway::class)->findOneBy([]);
        $ctx->invoice = $ctx->em->getRepository(Invoice::class)->findOneBy([]);
        $ctx->item = $ctx->em->getRepository(InvoiceItem::class)->findOneBy([]);
        $ctx->key = json_decode($ctx->gateway->getConfig(), true)['test_api_key'];
        $ctx->payload = json_decode($ctx->db->fetchOne('SELECT request FROM molliepayments_attempt LIMIT 1'), true);
    }
    $ctx->invoiceService = Mockery::mock(Box\Mod\Invoice\Service::class)->makePartial();
    // Auth is tested as a separate boundary: CLI bypasses it in the native host.
    $ctx->invoiceService->shouldReceive('checkInvoiceAuth')->byDefault()->andReturnNull();
    $ctx->invoiceService->shouldReceive('toApiArray')->byDefault()->andReturnUsing(static fn (Invoice $invoice) => [
        'status' => $invoice->getStatus(), 'issued' => $invoice->isIssued(), 'total' => '12.34',
        'id' => $invoice->getId(), 'nr' => $invoice->getNr(), 'serie' => $invoice->getSerie(), 'currency' => $invoice->getCurrency(),
        'lines' => array_map(static fn (InvoiceItem $item) => [
            'id' => $item->getId(), 'title' => $item->getTitle(), 'price' => $item->getPrice(),
            'quantity' => $item->getQuantity(), 'tax' => 0,
        ], $ctx->em->getRepository(InvoiceItem::class)->findBy(['invoice' => $invoice])),
    ]);
    $ctx->invoiceService->setDi($ctx->di);
    $ctx->gatewayService = new Box\Mod\Invoice\ServicePayGateway();
    $ctx->gatewayService->setDi($ctx->di);
    $ctx->itemService = new Box\Mod\Invoice\ServiceInvoiceItem();
    $ctx->itemService->setDi($ctx->di);
    $ctx->balanceService = new Box\Mod\Client\ServiceBalance();
    $ctx->balanceService->setDi($ctx->di);
    $ctx->clientService = new Box\Mod\Client\Service();
    $ctx->clientService->setDi($ctx->di);
    $ctx->transactionService = new Box\Mod\Invoice\ServiceTransaction();
    $ctx->transactionService->setDi($ctx->di);
    $currency = Mockery::mock(Box\Mod\Currency\Service::class);
    $currencyRepo = Mockery::mock(Box\Mod\Currency\Repository\CurrencyRepository::class);
    $currencyRepo->shouldReceive('getRateByCode')->with('EUR')->andReturn('1.0');
    $currency->shouldReceive('getCurrencyRepository')->andReturn($currencyRepo);
    $currency->shouldReceive('toBaseCurrency')->andReturnUsing(static fn ($code, $value) => (string) $value);
    $product = Mockery::mock(Box\Mod\Product\Service::class);
    $product->shouldReceive('commitReservedPromoRedemptionsForInvoice')->andReturnNull();
    $system = Mockery::mock(Box\Mod\System\Service::class);
    $system->shouldReceive('getParamValue')->with('invoice_number_padding')->andReturn(5);
    $ctx->services = ['invoice' => $ctx->invoiceService, 'invoice/paygateway' => $ctx->gatewayService,
        'invoice/invoiceitem' => $ctx->itemService, 'invoice/transaction' => $ctx->transactionService,
        'client' => $ctx->clientService, 'client/balance' => $ctx->balanceService, 'currency' => $currency, 'product' => $product,
        'order' => Mockery::mock(Box\Mod\Order\Service::class), 'system' => $system,
        'invoice/subscription' => Mockery::mock(Box\Mod\Invoice\ServiceSubscription::class)];
    unset($ctx->di['mod_service']);
    $ctx->di['mod_service'] = $ctx->di->protect(static function (string $module, ?string $service = null) use ($ctx) {
        return $ctx->services[strtolower($module . ($service === null ? '' : '/' . $service))]
            ?? throw new LogicException('Unexpected service: ' . $module . '/' . $service);
    });
    $ctx->status = 'open';
    $ctx->mutatePayment = null;
    $ctx->requests = [];
    $ctx->responses = [
        CreatePaymentRequest::class => function (PendingRequest $pending) use ($ctx) {
            $request = $pending->createPsrRequest();
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $ctx->payload = $payload;
            $ctx->requests[] = $request;

            return MockResponse::created(mollieProviderPayment($ctx));
        },
        GetPaymentRequest::class => function (PendingRequest $pending) use ($ctx) {
            $ctx->requests[] = $pending->createPsrRequest();

            return MockResponse::ok(mollieProviderPayment($ctx));
        },
    ];
    $ctx->payments = new PaymentService($ctx->di, static function (string $key) use ($ctx) {
        expect($key)->toBe($ctx->key);

        return MollieApiClient::fake($ctx->responses)->setApiKey($key);
    });
    // Preserve the native transaction claim/dispatch path, replacing only its module service factory.
    $ctx->services['molliepayments'] = new Box\Mod\Molliepayments\Service(paymentFactory: static fn () => $ctx->payments);
    $ctx->services['molliepayments']->setDi($ctx->di);

    return $ctx;
}

function mollieProviderPayment(object $ctx): array
{
    $result = ['resource' => 'payment', 'id' => 'tr_disposable123', 'mode' => $ctx->gateway->isTestMode() ? 'test' : 'live',
        'description' => 'Disposable invoice', 'amount' => $ctx->payload['amount'], 'status' => $ctx->status,
        'sequenceType' => 'oneoff', 'metadata' => $ctx->payload['metadata'],
        'expiresAt' => date(DATE_ATOM, time() + 900),
        '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/disposable123'],
            'self' => ['href' => 'https://api.mollie.com/v2/payments/tr_disposable123']]];
    if ($ctx->status === 'paid') {
        $result['paidAt'] = date(DATE_ATOM);
    }

    return $ctx->mutatePayment !== null ? ($ctx->mutatePayment)($result) : $result;
}

function mollieAttempt(object $ctx): array
{
    return $ctx->db->fetchAssociative('SELECT * FROM molliepayments_attempt ORDER BY created_at DESC LIMIT 1');
}

function mollieWebhook(object $ctx, ?array $data = null): Transaction
{
    $attempt = mollieAttempt($ctx);
    $tx = new Transaction();
    $tx->setGateway($ctx->gateway);
    $tx->setInvoice($ctx->invoice);
    $tx->setStatus(Transaction::STATUS_PROCESSING);
    $ctx->em->persist($tx);
    $ctx->em->flush();
    $ctx->payments->processTransaction((int) $tx->getId(), $data ?? [
        'get' => ['invoice_id' => $ctx->invoice->getId(), 'attempt_id' => $attempt['id']],
        'post' => ['id' => 'tr_disposable123'],
    ], (int) $ctx->gateway->getId());

    return $tx;
}

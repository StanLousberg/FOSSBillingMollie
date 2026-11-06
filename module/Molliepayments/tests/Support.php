<?php

declare(strict_types=1);

use Box\Mod\Invoice\Entity\PayGateway;
use Box\Mod\Invoice\ServicePayGateway;
use Doctrine\ORM\EntityManagerInterface;

use function Tests\Helpers\admin;
use function Tests\Helpers\container;
use function Tests\Helpers\createEntity;
use function Tests\Helpers\cryptMock;

function mollieGatewayHost(): array
{
    $di = container();
    $di['em'] = Mockery::mock(EntityManagerInterface::class);
    $di['em']->shouldReceive('flush')->byDefault();
    $di['em']->shouldReceive('getRepository')->with(PayGateway::class)
        ->andReturn(Mockery::mock(Box\Mod\Invoice\Repository\PayGatewayRepository::class))->byDefault();
    $di['crypt'] = cryptMock();
    $di['loggedin_admin'] = admin(['id' => 7]);
    $service = new ServicePayGateway();
    $service->setDi($di);

    return [$service, $di];
}

function mollieGateway(array $config = [], array $attributes = []): PayGateway
{
    return createEntity(PayGateway::class, $attributes + [
        'id' => 41, 'gateway' => 'MolliePayments', 'name' => 'MolliePayments',
        'acceptedCurrencies' => mollieGatewayValue('acceptedCurrencies', ['EUR']), 'enabled' => false,
        'testMode' => false, 'config' => mollieGatewayValue('config', $config),
    ]);
}

/** Preview hosts changed JSON entity fields from serialized strings to arrays. */
function mollieGatewayValue(string $property, array $value): array|string
{
    return (new ReflectionProperty(PayGateway::class, $property))->getType()?->getName() === 'array'
        ? $value : json_encode($value, JSON_THROW_ON_ERROR);
}

function mollieGatewayConfig(PayGateway $gateway): array
{
    $config = $gateway->getConfig();

    return is_array($config) ? $config : json_decode($config, true, 512, JSON_THROW_ON_ERROR);
}

function mollieDummyKey(string $environment): string
{
    return $environment . '_' . bin2hex(random_bytes(20));
}

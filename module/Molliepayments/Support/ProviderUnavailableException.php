<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

/** Transient inability to verify with the provider, including Customer lease contention. No SDK request is retained. */
final class ProviderUnavailableException extends \Payment_Exception
{
}

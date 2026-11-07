<?php

declare(strict_types=1);

namespace Box\Mod\Molliepayments\Support;

use DebugBar\StandardDebugBar;
use FOSSBilling\Core\Twig\TwigFactory;
use Pimple\Container;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/** Add a fallback for this module's templates, including symlinked installs. */
final class AdminTwigFactory extends TwigFactory
{
    public function __construct(Container $di, private readonly TwigFactory $inner)
    {
        parent::__construct($di);
    }

    public static function register(Container $di): void
    {
        if (!isset($di['twig_factory'])) {
            return;
        }
        $factory = $di['twig_factory'];
        if ($factory instanceof self) {
            return;
        }

        // Permission discovery may initialize the factory before loading the
        // module. Keep that instance and its configuration when decorating it.
        unset($di['twig_factory']);
        $di['twig_factory'] = new self($di, $factory);
    }

    public function createClientEnvironment(StandardDebugBar $debugBar): Environment
    {
        $twig = $this->inner->createClientEnvironment($debugBar);
        if (!$twig->getLoader()->exists('mod_molliepayments_onboarding.html.twig')) {
            $twig->setLoader(new ChainLoader([$twig->getLoader(), new FilesystemLoader(dirname(__DIR__) . '/templates/client')]));
        }

        return $twig;
    }

    public function createAdminEnvironment(StandardDebugBar $debugBar): Environment
    {
        $twig = $this->inner->createAdminEnvironment($debugBar);
        if ($twig->getLoader()->exists('mod_molliepayments_settings.html.twig')) {
            return $twig;
        }
        $twig->setLoader(new ChainLoader([
            $twig->getLoader(),
            new FilesystemLoader(dirname(__DIR__) . '/templates/admin'),
        ]));

        return $twig;
    }
}

<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'concat_space' => ['spacing' => 'one'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setRiskyAllowed(true)
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__ . '/gateway', __DIR__ . '/module'])->exclude('vendor'))
    ->setCacheFile(sys_get_temp_dir() . '/molliepayments-cs.cache');

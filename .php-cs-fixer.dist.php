<?php

declare(strict_types=1);

$rules = [
    '@Symfony' => true,
    '@Symfony:risky' => true,
    '@PHP81Migration' => true,
    '@PHP74Migration:risky' => true,
    'single_line_throw' => false,
    'native_constant_invocation' => false,
    'concat_space' => ['spacing' => 'one'],
];

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules($rules)
    ->setFinder($finder);

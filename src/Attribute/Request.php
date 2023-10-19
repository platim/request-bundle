<?php

declare(strict_types=1);

namespace Platim\RequestBundle\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_CLASS)]
class Request
{
    public function __construct(
        public ?string $formClass = null
    ) {
    }
}

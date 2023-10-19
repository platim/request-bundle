<?php

declare(strict_types=1);

namespace Platim\RequestBundle;

use Platim\RequestBundle\DependencyInjection\RequestExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class RequestBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new RequestExtension();
    }
}

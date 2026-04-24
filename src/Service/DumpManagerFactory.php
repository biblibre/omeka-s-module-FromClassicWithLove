<?php

namespace FromClassicWithLove\Service;

use FromClassicWithLove\DumpManager;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class DumpManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        return new DumpManager($serviceLocator);
    }
}

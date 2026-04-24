<?php
namespace FromClassicWithLove\Service\Media\Ingester;

use FromClassicWithLove\Media\Ingester\Local;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class LocalFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $validator = $services->get('Omeka\File\Validator');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        return new Local($tempFileFactory, $validator, $settings, $logger);
    }
}

<?php declare(strict_types=1);

namespace FromClassicWithLove\Service\Form\Element;

use FromClassicWithLove\Form\Element\OptionalPropertySelect;
use Interop\Container\ContainerInterface;
use Laminas\I18n\Translator\TranslatorInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OptionalPropertySelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new OptionalPropertySelect(null, $options ?? []);
        $element->setEventManager($services->get('EventManager'));
        $element->setApiManager($services->get('Omeka\ApiManager'));
        $element->setTranslator($services->get(TranslatorInterface::class));
        return $element;
    }
}

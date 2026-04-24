<?php

namespace FromClassicWithLove\Form\Element;

use Omeka\Form\Element\ResourceClassSelect;

class OptionalResourceClassSelect extends ResourceClassSelect
{
    /**
     * @see https://github.com/zendframework/zendframework/issues/2761#issuecomment-14488216
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::getInputSpecification()
     */
    public function getInputSpecification(): array
    {
        $inputSpecification = parent::getInputSpecification();
        $inputSpecification['required'] = !empty($this->attributes['required']);
        return $inputSpecification;
    }
}

<?php

namespace FromClassicWithLove\Form\Element;

use Omeka\Form\Element\PropertySelect;

class OptionalPropertySelect extends PropertySelect
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

    public function getValueOptions(): array
    {
        $query = $this->getOption('query');
        if (!is_array($query)) {
            $query = [];
        }
        $query['per_page'] = 0;
        $this->setOption('query', $query);

        return parent::getValueOptions();
    }
}

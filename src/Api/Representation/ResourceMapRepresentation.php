<?php
namespace FromClassicWithLove\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

class ResourceMapRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLd()
    {
        return [
            'resource_id' => $this->resource()->id(),
            'classic_resource_id' => $this->classicResourceId(),
            'resource_name' => $this->mappedResourceName(),
            'o:job' => $this->job()->getReference(),
        ];
    }

    public function getJsonLdType()
    {
        return 'o:FromClassicWithLoveResourceMap';
    }

    /**
     * Return the resource representation .
     *
     * @return ItemRepresentation | ItemSetRepresentation | MediaRepresentation | null
     */
    public function resource()
    {
        $resourceName = $this->mappedResourceName();
        $adapterName = '';
        switch ($resourceName) {
            case 'item':
                $adapterName = 'items';
                break;
            case 'item_set':
                $adapterName = 'item_sets';
                break;
            case 'media':
                $adapterName = 'media';
                break;
            default:
                return null;
        }
        return $this->getAdapter($adapterName)
            ->getRepresentation($this->resource->getResource());
    }

    public function job()
    {
        return $this->getAdapter('jobs')
            ->getRepresentation($this->resource->getJob());
    }

    public function mappedResourceName()
    {
        return $this->resource->getMappedResourceName();
    }

    public function classicResourceId()
    {
        return $this->resource->getClassicResourceId();
    }
}

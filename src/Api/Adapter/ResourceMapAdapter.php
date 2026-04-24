<?php
namespace FromClassicWithLove\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use FromClassicWithLove\Api\Representation\ResourceMapRepresentation;
use FromClassicWithLove\Entity\FromClassicWithLoveResourceMap;

class ResourceMapAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'fromclassicwithlove_resource_maps';
    }

    public function getEntityClass()
    {
        return FromClassicWithLoveResourceMap::class;
    }

    public function getRepresentationClass()
    {
        return ResourceMapRepresentation::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['mapped_resource_name'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.mappedResourceName',
                $this->createNamedParameter($qb, $query['mapped_resource_name']))
            );
        }
        if (isset($query['classic_resource_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.classicResourceId',
                $this->createNamedParameter($qb, $query['classic_resource_id']))
            );
        }
        if (isset($query['job_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.job',
                $this->createNamedParameter($qb, $query['job_id']))
            );
        }
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();

        if (isset($data['mapped_resource_name'])) {
            $entity->setMappedResourceName($data['mapped_resource_name']);

            if (isset($data['resource_id'])) {
                $adapterName = '';
                switch ($data['mapped_resource_name']) {
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

                $resource = $this->getAdapter($adapterName)
                    ->findEntity($data['resource_id']);

                $entity->setResource($resource);
            }
        }

        if (isset($data['classic_resource_id'])) {
            $entity->setClassicResourceId($data['classic_resource_id']);
        }

        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }

        // @TODO invalidate hydration if one of fields is missing?
        // @TODO invalidate hydration if resource_name is wrong?
    }
}

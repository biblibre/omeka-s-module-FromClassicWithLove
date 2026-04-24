<?php
namespace FromClassicWithLove\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class ImportAdapter extends AbstractEntityAdapter
{
    public function getResourceName()
    {
        return 'classicimporter_imports';
    }

    public function getRepresentationClass()
    {
        return \FromClassicWithLove\Api\Representation\ImportRepresentation::class;
    }

    public function getEntityClass()
    {
        return \FromClassicWithLove\Entity\FromClassicWithLoveImport::class;
    }

    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if (isset($data['o:job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:job']['o:id']);
            $entity->setJob($job);
        }
        if (isset($data['o:undo_job']['o:id'])) {
            $job = $this->getAdapter('jobs')->findEntity($data['o:undo_job']['o:id']);
            $entity->setUndoJob($job);
        }

        if (isset($data['has_err'])) {
            $entity->setHasErr($data['has_err']);
        }

        if (isset($data['stats'])) {
            $entity->setStats($data['stats']);
        }
    }

    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['job_id'])) {
            $qb->andWhere($qb->expr()->eq(
                'omeka_root.job',
                $this->createNamedParameter($qb, $query['job_id']))
            );
        }
    }
}

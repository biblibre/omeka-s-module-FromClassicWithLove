<?php

namespace FromClassicWithLove\Job;

use Omeka\Job\AbstractJob;
use FromClassicWithLove\Entity\FromClassicWithLoveImport;

class ImportFromDumpJob extends AbstractJob
{
    /**
     * @var array
     */
    protected $propertiesToAddLater = [];

    /**
     * @var FromClassicWithLoveImport
     */
    protected $importRecord;

    /**
     * @var bool
     */
    protected $hasErr = false;

    /**
     * @var array
     */
    protected $stats = [];

    /**
     * @var array
     */
    protected $propertyTermCache = [];

    /**
     * @var int
     */
    protected $updatedJobId;

    /**
     * @var array
     */
    protected $updatedResources = ['items' => [], 'item_sets' => []];

    public function perform()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $logger->info('Job started');

        $importJson = [
            'o:job' => ['o:id' => $this->job->getId()],
            'has_err' => false,
            'stats' => [],
        ];
        $response = $this->serviceLocator->get('Omeka\ApiManager')->create('fromclassicwithlove_imports', $importJson);
        $this->importRecord = $response->getContent();

        $dumpManager = $this->serviceLocator->get('FromClassicWithLove\DumpManager');

        if ($dumpManager->getConn() === null) {
            $logger->err('Could not connect to the Omeka Classic database: ' . $dumpManager->getErrorMessage());
            $this->hasErr = true;
            $this->endJob();
            return;
        }

        $p = $dumpManager->getTablePrefix();

        $sql = sprintf(
            'SELECT DISTINCT
                %1$selement_sets.name AS element_set_name,
                %1$selements.name AS element_name,
                %1$selements.id AS element_id
            FROM %1$selement_texts
                LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                LEFT JOIN %1$selement_sets ON %1$selements.element_set_id = %1$selement_sets.id',
            $p
        );

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $properties = $stmt->fetchAllAssociative();

        $sql = sprintf(
            'SELECT %1$sitem_types.id, %1$sitem_types.name, %1$sitem_types.description
            FROM %1$sitems
                INNER JOIN %1$sitem_types ON %1$sitems.item_type_id = %1$sitem_types.id',
            $p
        );

        $stmt = $dumpManager->getConn()->executeQuery($sql);
        $resourceClasses = $stmt->fetchAllAssociative();

        if ($this->getArg('update') == '1') {
            if (empty($this->getArg('updated_job_id'))) {
                $logger->err(("Error: no previous imports found.")); // @translate
                $this->hasErr = true;

                $logger->info('Job ended');

                $this->endJob();

                return;
            } else {
                $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
                    ->search('fromclassicwithlove_imports', ['job_id' => $this->getArg('updated_job_id')])->getContent();

                if (empty($updatedJob) || empty($updatedJob[0])) {
                    $this->hasErr = true;
                    $logger->err(sprintf('Invalid import job id \'%s\'.', $this->getArg('updated_job_id'))); // @ translate
                    $this->endJob();
                    return;
                }

                $this->updatedJobId = $updatedJob[0]->job()->id();
            }
        }

        $this->logClassicStats($dumpManager->getConn(), $p, $logger);

        try {
            $this->importResourcesFromDump($dumpManager, $properties, $resourceClasses);
        } catch (\Exception $e) {
            $logger->err(sprintf("Error: %s", $e->getMessage()));
            $this->hasErr = true;
        }

        $this->logOmekaSStats($logger);

        $logger->info('Job ended');

        $this->endJob();
    }

    public function importResourcesFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        if ($this->getArg('import_collections') == '1') {
            $this->importItemSetsFromDump($dumpManager, $properties, $resourceClasses);
        }

        $this->importItemsFromDump($dumpManager, $properties, $resourceClasses);

        $this->importUrisFromDump();

        if ($this->getArg('update') == '1') {
            $this->cleanMissingResources();
        }
    }

    protected function cleanMissingResources()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');

        $resources = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
            ['job_id' => $this->updatedJobId])->getContent();

        foreach ($resources as $resource) {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            if ($resource->mappedResourceName() == 'item') {
                if (!in_array($resource->classicResourceId(), $this->updatedResources['items'])) {
                    try {
                        $api->delete('items', $resource->resource()->id());
                        $api->delete('fromclassicwithlove_resource_maps', $resource->id());
                    } catch (\Exception $e) {
                        $logger->warn(sprintf('Error when trying to delete resource: %s.', $e->getMessage()));
                    }
                }
            }
            if ($resource->mappedResourceName() == 'item_set') {
                if (!in_array($resource->classicResourceId(), $this->updatedResources['item_sets'])) {
                    try {
                        $api->delete('item_sets', $resource->resource()->id());
                        $api->delete('fromclassicwithlove_resource_maps', $resource->id());
                    } catch (\Exception $e) {
                        $logger->warn(sprintf('Error when trying to delete resource: %s.', $e->getMessage()));
                    }
                }
            }
        }

        $logger->info('Deleted resources not present in update database anymore.');
    }

    protected function importCollectionsTreeFromDump($dumpManager)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $sql = sprintf(
            'SELECT %1$scollections.id, %1$scollection_trees.parent_collection_id
            FROM %1$scollections
            LEFT JOIN %1$scollection_trees ON %1$scollections.id = %1$scollection_trees.collection_id',
            $p
        );

        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();

        // If we're updating from a previous import,
        // we must delete every item sets tree branch before to reset it from scratch.
        // This is to avoid bugs and impossible trees.
        if ($this->getArg('update') == '1') {
            foreach ($itemSets as $itemSet) {
                $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
                [
                    'mapped_resource_name' => 'item_set',
                    'classic_resource_id' => $itemSet['id'],
                    'job_id' => $this->updatedJobId,
                ]
                )->getContent();

                if (!empty($matchingResource)) {
                    $treeEdges = $this->getServiceLocator()->get('Omeka\ApiManager')->search('item_sets_tree_edges',
                    ['item_set_id' => $matchingResource[0]->resource()->id()])->getContent();

                    if (!empty($treeEdges)) {
                        foreach ($treeEdges as $treeEdge) {
                            $this->getServiceLocator()->get('Omeka\ApiManager')->delete('item_sets_tree_edges', $treeEdge->id());
                        }
                    }
                }
            }
        }

        foreach ($itemSets as $itemSet) {
            if (!$itemSet['parent_collection_id']) {
                continue;
            }

            $matchingResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
            [
                'mapped_resource_name' => 'item_set',
                'classic_resource_id' => $itemSet['id'],
                'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
            ]
            )->getContent();

            $matchingTargetResource = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
            [
                'mapped_resource_name' => 'item_set',
                'classic_resource_id' => $itemSet['parent_collection_id'],
                'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
            ]
            )->getContent();

            if (!empty($matchingResource) && !empty($matchingTargetResource)) {
                $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

                $parentItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingTargetResource[0]->resource()->id());
                $childItemSet = $entityManager->find('Omeka\Entity\ItemSet', $matchingResource[0]->resource()->id());

                $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets_tree_edges',
                ['o:item_set' => $childItemSet, 'o:parent_item_set' => $parentItemSet]);

                $this->stats['item_sets_tree_edges'] = ($this->stats['item_sets_tree_edges'] ?? 0) + 1;
            }
        }
    }

    protected function importUrisFromDump()
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $jobId = ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId();

        foreach ($this->propertiesToAddLater as $id => $properties) {
            if ($this->shouldStop()) {
                $logger->warn('Import stopped by user during URI import.'); // @translate
                return;
            }

            $resourceName = $properties[0]['resource_name'];
            $matchingResource = $api->search('fromclassicwithlove_resource_maps',
                [
                    'mapped_resource_name' => $resourceName,
                    'classic_resource_id' => $id,
                    'job_id' => $jobId,
                ]
            )->getContent();

            if (empty($matchingResource)) {
                continue;
            }

            $omekaResourceId = $matchingResource[0]->resource()->id();

            $resourceData = [];

            foreach ($properties as $property) {
                if ($property['type'] != 'resource') {
                    continue;
                }

                $propertyId = $property['property_id'];
                if (!isset($this->propertyTermCache[$propertyId])) {
                    $this->propertyTermCache[$propertyId] = $api
                        ->read('properties', $propertyId)
                        ->getContent();
                }
                $propertyRep = $this->propertyTermCache[$propertyId];
                if (empty($propertyRep)) {
                    continue;
                }

                $term = $propertyRep->term();
                $targetId = $property['value_resource_id'];

                $matchingTargetResource = $api->search('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => $property['target_resource_name'],
                        'classic_resource_id' => $targetId,
                        'job_id' => $jobId,
                    ]
                )->getContent();

                if (!empty($matchingTargetResource)) {
                    $resourceData[$term][] = [
                        'property_id' => $property['property_id'],
                        'type' => 'resource',
                        'is_public' => '1',
                        'value_resource_id' => $matchingTargetResource[0]->resource()->id(),
                    ];
                } else {
                    $resourceData[$term][] = [
                        'property_id' => $property['property_id'],
                        'is_public' => '1',
                        'type' => 'uri',
                        '@annotation' => null,
                        'o:lang' => '',
                        '@id' => $property['@id'],
                        'o:label' => $property['o:label'],
                    ];
                }
            }

            if (empty($resourceData)) {
                continue;
            }

            $apiResource = $resourceName === 'item_set' ? 'item_sets' : 'items';
            $api->update(
                $apiResource,
                $omekaResourceId,
                $resourceData,
                [],
                ['isPartial' => true, 'collectionAction' => 'append']
            );

            $this->stats['uris'] = ($this->stats['uris'] ?? 0) + count($resourceData);
        }

        if (!empty($this->propertiesToAddLater)) {
            $logger->info('URIs towards resources successfully imported.');
        }
    }

    protected function importItemSetsFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $sql = sprintf('SELECT * FROM %scollections', $p);

        $stmt = $dumpConn->executeQuery($sql);
        $itemSets = $stmt->fetchAllAssociative();

        foreach ($itemSets as $itemSet) {
            if ($this->shouldStop()) {
                $logger->warn('Import stopped by user during item sets import.'); // @translate
                return;
            }

            $sql = sprintf(
                'SELECT %1$selement_texts.element_id, %1$selement_texts.text, %1$selements.name
                FROM %1$scollections
                    LEFT JOIN %1$selement_texts ON %1$selement_texts.record_id = %1$scollections.id
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                WHERE %1$selement_texts.record_type = \'Collection\'
                AND %1$scollections.id = ?',
                $p
            );

            $stmt = $dumpConn->executeQuery($sql, [$itemSet['id']]);
            $propertyValues = $stmt->fetchAllAssociative();

            $itemSetData = [
                // collections don't have classes in Omeka so don't try to map any
                // no owner to be set either
                'o:is_public' => strval($itemSet['public']),
            ];

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']])) {
                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];
                    if (!is_array($propertyIds)) {
                        $propertyIds = [ $propertyIds ];
                    }

                    $transformedProperty = [];
                    if (($this->getArg('transform_uris') ?? [])[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }

                    foreach ($propertyIds as $propertyId) {
                        $term = $this->getPropertyTerm($propertyId);

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemSetData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => (($this->getArg('preserve_html') ?? [])[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        } else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($itemSet['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item_set',
                                    ],
                                    $transformedProperty);
                            } else {
                                $itemSetData[$term][] =
                                array_merge(
                                [
                                    'property_id' => intval($propertyId),
                                    'is_public' => '1',
                                ],
                                $transformedProperty);
                            }
                        }
                    }
                }
            }

            $couldUpdate = false;
            if ($this->getArg('update') == '1') {
                $this->updatedResources['item_sets'][] = $itemSet['id'];
                $matchingItemSets = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'classic_resource_id' => $itemSet['id'],
                        'job_id' => $this->updatedJobId,
                    ]
                )->getContent();
                if (!empty($matchingItemSets)) {
                    $couldUpdate = true;

                    /* @var \Omeka\Api\Representation\ItemSetRepresentation $matchingItemSet */
                    $matchingItemSet = $matchingItemSets[0]->resource();

                    $this->getServiceLocator()->get('Omeka\ApiManager')->update('item_sets', $matchingItemSet->id(),
                        $itemSetData);

                    $this->stats['item_sets'] = ($this->stats['item_sets'] ?? 0) + 1;
                }
            }

            if (!$couldUpdate) {
                /* @var \Omeka\Api\Representation\ItemSetRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('item_sets', $itemSetData)->getContent();

                $this->getServiceLocator()->get('Omeka\ApiManager')->create('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $itemSet['id'],
                        'o:job' => ['o:id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId()],
                    ]
                );

                $this->stats['item_sets'] = ($this->stats['item_sets'] ?? 0) + 1;
            }
        }

        $logger->info('Item sets successfully imported.');
        if ($this->getArg('import_collections_tree', '0') == '1') {
            $this->importCollectionsTreeFromDump($dumpManager);
            $logger->info('Item sets tree successfully imported.');
        }
    }

    protected function importItemsFromDump($dumpManager, $properties, $resourceClasses)
    {
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $p = $dumpManager->getTablePrefix();
        $dumpConn = $dumpManager->getConn();

        $hasAltText = $dumpManager->hasColumn($p . 'files', 'alt_text');

        $sql = sprintf('SELECT * FROM %sitems', $p);

        $stmt = $dumpConn->executeQuery($sql);
        $items = $stmt->fetchAllAssociative();

        foreach ($items as $item) {
            if ($this->shouldStop()) {
                $logger->warn('Import stopped by user during items import.'); // @translate
                return;
            }

            $sql = sprintf(
                'SELECT %1$selement_texts.element_id, %1$selement_texts.text, %1$selements.name
                FROM %1$sitems
                    LEFT JOIN %1$selement_texts ON %1$selement_texts.record_id = %1$sitems.id
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                WHERE %1$selement_texts.record_type = \'Item\'
                AND %1$sitems.id = ?',
                $p
            );

            $stmt = $dumpConn->executeQuery($sql, [$item['id']]);
            $propertyValues = $stmt->fetchAllAssociative();

            $altTextCol = $hasAltText ? ', %1$sfiles.alt_text' : '';
            $sql = sprintf(
                'SELECT %1$sfiles.id, %1$sfiles.mime_type, %1$sfiles.filename,
                    %1$sfiles.original_filename, %1$sfiles.size' . $altTextCol . '
                FROM %1$sfiles
                WHERE %1$sfiles.stored = 1
                AND %1$sfiles.item_id = ?',
                $p
            ); // @TODO check what happens when files.stored is 0.

            $stmt = $dumpConn->executeQuery($sql, [$item['id']]);
            $files = $stmt->fetchAllAssociative();

            $tags = [];
            if ($this->getArg('tag_property')) {
                $tagSql = sprintf(
                    'SELECT t.name FROM %1$stags t
                    INNER JOIN %1$srecords_tags rt ON rt.tag_id = t.id
                    WHERE rt.record_id = ? AND rt.record_type = \'Item\'',
                    $p
                );
                $tagStmt = $dumpConn->executeQuery($tagSql, [$item['id']]);
                $tags = $tagStmt->fetchAllAssociative();
            }

            $itemData = [
                'o:is_public' => strval($item['public']),

                // important so API doesn't add one automatically
                'o:site' => [],

            ];

            if (!empty($this->getArg('types_classes')[$item['item_type_id']])) {
                $itemData['o:resource_class'] = [ 'o:id' => $this->getArg('types_classes')[$item['item_type_id']] ];
            }

            if ($this->getArg('import_collections') == '1' && isset($item['collection_id'])) {

                /* @var \Omeka\Api\Representation\ItemSetRepresentation[] | null $response*/
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => 'item_set',
                        'classic_resource_id' => $item['collection_id'],
                        'job_id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId(),
                    ]
                )->getContent();

                if (!empty($response)) {
                    $itemData['o:item_set'] = [['o:id' => intval($response[0]->resource()->id())]];
                }
            }

            foreach ($propertyValues as $property) {
                // only if the property is mapped
                if (!empty($this->getArg('elements_properties')[$property['element_id']])) {
                    $transformedProperty = [];
                    if (($this->getArg('transform_uris') ?? [])[$property['element_id']] == '1') {
                        $transformedProperty = $this->transformValue($property['text']);
                    }

                    $propertyIds = $this->getArg('elements_properties')[$property['element_id']];

                    if (!is_array($propertyIds)) {
                        $propertyIds = [ $propertyIds ];
                    }

                    foreach ($propertyIds as $propertyId) {
                        $term = $this->getPropertyTerm($propertyId);

                        // empty means no transformation was used
                        if (empty($transformedProperty)) {
                            $itemData[$term][] = [ //for each of the values
                                'property_id' => intval($propertyId),
                                'type' => 'literal',
                                'is_public' => '1',
                                '@annotation' => null,
                                '@language' => '',
                                '@value' => (($this->getArg('preserve_html') ?? [])[$property['element_id']] == '1') ?
                                            $property['text'] : $this->cleanTextFromHTML($property['text']),
                            ];
                        } else {
                            if ($transformedProperty['type'] == 'resource') {
                                $this->propertiesToAddLater[strval($item['id'])][] =
                                    array_merge(
                                    [
                                        'property_id' => intval($propertyId),
                                        'resource_name' => 'item',
                                    ],
                                    $transformedProperty);
                            } else {
                                $itemData[$term][] =
                                array_merge(
                                [
                                    'property_id' => intval($propertyId),
                                    'is_public' => '1',
                                ],
                                $transformedProperty);
                            }
                        }
                    }
                }
            }

            if ($this->getArg('tag_property') && !empty($tags)) {
                $tagTerm = $this->getPropertyTerm($this->getArg('tag_property'));
                foreach ($tags as $tag) {
                    $itemData[$tagTerm][] = [
                        'property_id' => intval($this->getArg('tag_property')),
                        'type' => 'literal',
                        'is_public' => '1',
                        '@annotation' => null,
                        '@language' => '',
                        '@value' => $tag['name'],
                    ];
                }
            }

            $mediaEntries = [];
            if (!empty($this->getArg('files_source'))) {
                foreach ($files as $file) {
                    $mediaEntries[] = [
                        'o:is_public' => '1',
                        'o:ingester' => 'fromclassicwithlove_local',
                        'original_file_action' => 'keep',
                        'ingest_filename' => $this->getArg('files_source') . $file['filename'],
                        'original_filename' => $file['original_filename'],
                    ];
                }
            }

            $couldUpdate = false;
            if ($this->getArg('update') == '1') {
                $this->updatedResources['items'][] = $item['id'];
                $matchingItems = $this->getServiceLocator()->get('Omeka\ApiManager')->search('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'classic_resource_id' => $item['id'],
                        'job_id' => $this->updatedJobId,
                    ]
                )->getContent();
                if (!empty($matchingItems)) {
                    $couldUpdate = true;

                    /* @var \Omeka\Api\Representation\ItemRepresentation $matchingItem */
                    $matchingItem = $matchingItems[0]->resource();

                    // From [] to unset.
                    // So that we just don't touch the item_sets when updating.
                    // Normally, item_sets are [] when empty
                    // but if we update witout importing item_sets, we don't want to update with [],
                    // we want NOT to touch the item_sets, so we remove the key.
                    if (empty($itemData['o:item_set'])) {
                        unset($itemData['o:item_set']);
                    }

                    $this->getServiceLocator()->get('Omeka\ApiManager')->update('items', $matchingItem->id(),
                        $itemData);

                    if (!empty($mediaEntries)) {
                        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

                        // Index existing Omeka S media by original_filename (o:source).
                        $existingMedia = [];
                        foreach ($matchingItem->media() as $media) {
                            $existingMedia[$media->source()] = $media;
                        }

                        // Index dump files by original_filename.
                        $dumpMedia = [];
                        foreach ($mediaEntries as $entry) {
                            $dumpMedia[$entry['original_filename']] = $entry;
                        }

                        // Create media present in dump but missing in Omeka S.
                        foreach ($dumpMedia as $originalFilename => $entry) {
                            if (!isset($existingMedia[$originalFilename])) {
                                try {
                                    $api->create('media', array_merge($entry, [
                                        'o:item' => ['o:id' => $matchingItem->id()],
                                    ]));
                                    $this->stats['media'] = ($this->stats['media'] ?? 0) + 1;
                                } catch (\Exception $e) {
                                    $logger->warn(sprintf('Error creating media "%s": %s.', $originalFilename, $e->getMessage()));
                                }
                            }
                        }

                        // Delete media present in Omeka S but no longer in dump.
                        foreach ($existingMedia as $originalFilename => $media) {
                            if (!isset($dumpMedia[$originalFilename])) {
                                try {
                                    $api->delete('media', $media->id());
                                } catch (\Exception $e) {
                                    $logger->warn(sprintf('Error deleting media "%s": %s.', $originalFilename, $e->getMessage()));
                                }
                            }
                        }
                    }

                    $this->stats['items'] = ($this->stats['items'] ?? 0) + 1;
                }
            }

            if (!$couldUpdate) {
                if (!empty($mediaEntries)) {
                    $itemData['o:media'] = $mediaEntries;
                }

                /* @var \Omeka\Api\Representation\ItemRepresentation $response */
                $response = $this->getServiceLocator()->get('Omeka\ApiManager')->create('items', $itemData)->getContent();
                $this->getServiceLocator()->get('Omeka\ApiManager')->create('fromclassicwithlove_resource_maps',
                    [
                        'mapped_resource_name' => 'item',
                        'resource_id' => $response->id(),
                        'classic_resource_id' => $item['id'],
                        'o:job' => ['o:id' => ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId()],
                    ]
                );

                $this->stats['items'] = ($this->stats['items'] ?? 0) + 1;
            }
        }

        if (!empty($this->getArg('files_source'))) {
            $logger->info('Media succesfully imported.');
        }
        $logger->info('Items successfully imported.');
    }

    protected function getPropertyTerm($propertyId): string
    {
        if (!isset($this->propertyTermCache[$propertyId])) {
            $this->propertyTermCache[$propertyId] = $this->getServiceLocator()
                ->get('Omeka\ApiManager')
                ->read('properties', $propertyId)
                ->getContent();
        }

        return $this->propertyTermCache[$propertyId]->term();
    }

    protected function cleanTextFromHTML($text)
    {
        // remove html elements from the text
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    protected function transformValue($value)
    {
        $matches = [];

        // Strategy 1: extract href from any <a href="..."> tag anywhere in the
        // value, even when preceded by text or other HTML (e.g. "text: <br /><a href="url">...")
        if (preg_match('/<a\s+(?:[^>]*?\s+)?href="([^"]+)"[^>]*>([^<]*?)<\/a>/i', $value, $matches)) {
            $url = $matches[1];
            // Use the text content of the <a> as label, fall back to the
            // stripped plain-text of the whole value if the anchor text is empty.
            $label = trim($matches[2]) !== '' ? trim($matches[2]) : trim($this->cleanTextFromHTML($value));
            return $this->transformUrl($url, $label);
        }

        // Strategy 2: the value is plain text; look for a URL token anywhere
        // (not only as the last word) and use the remaining text as label.
        $cleanText = $this->cleanTextFromHTML($value);
        $words = preg_split('/\s+/', $cleanText, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $index => $word) {
            if (filter_var($word, FILTER_VALIDATE_URL)) {
                $remaining = array_merge(array_slice($words, 0, $index), array_slice($words, $index + 1));
                $label = trim(implode(' ', $remaining));
                return $this->transformUrl($word, $label);
            }
        }

        return [];
    }

    protected function transformUrl($value, $label = '')
    {
        $urlParsed = parse_url($value);

        if ($urlParsed === false || empty($urlParsed)) {
            return [];
        }

        if (empty($urlParsed['path']) || empty($urlParsed['host'])) {
            return [
                'type' => 'uri',
                '@annotation' => null,
                'o:lang' => '',
                '@id' => $value,
                'o:label' => $label,
            ];
        }

        $urlPath = explode('/', $urlParsed['path']);

        $classicId = $urlPath[3] ?? '';
        if (
            count($urlPath) == 4
            && $urlParsed['host'] == $this->getArg('domain_name')
            && $urlPath[2] == 'show'
            && ctype_digit($classicId)
            && (int) $classicId > 0
        ) {
            switch ($urlPath[1]) {
                case 'items':
                    return [
                        'type' => 'resource',
                        '@annotation' => null,
                        'value_resource_id' => $classicId,
                        'target_resource_name' => 'item',
                        'o:label' => $label,
                        '@id' => $value,
                    ];
                case 'collections':
                    return [
                        'type' => 'resource',
                        '@annotation' => null,
                        'value_resource_id' => $classicId,
                        'target_resource_name' => 'item_set',
                        'o:label' => $label,
                        '@id' => $value,
                    ];
                default:
                    break;
            }
        }

        return [
          'type' => 'uri',
          '@annotation' => null,
          'o:lang' => '',
          '@id' => $value,
          'o:label' => $label,
        ];
    }

    protected function logClassicStats(\Doctrine\DBAL\Connection $conn, string $p, $logger): void
    {
        try {
            $collections = $conn->executeQuery(sprintf(
                'SELECT SUM(public=1) AS public, SUM(public=0) AS private FROM %scollections', $p
            ))->fetchAssociative();

            $items = $conn->executeQuery(sprintf(
                'SELECT SUM(public=1) AS public, SUM(public=0) AS private FROM %sitems', $p
            ))->fetchAssociative();

            $itemsWithMedia = $conn->executeQuery(sprintf(
                'SELECT COUNT(DISTINCT item_id) FROM %sfiles WHERE stored = 1', $p
            ))->fetchOne();

            $totalItems = ($items['public'] ?? 0) + ($items['private'] ?? 0);
            $itemsWithoutMedia = $totalItems - (int) $itemsWithMedia;

            $totalMedia = $conn->executeQuery(sprintf(
                'SELECT COUNT(*) FROM %sfiles WHERE stored = 1', $p
            ))->fetchOne();

            $properties = $conn->executeQuery(sprintf(
                'SELECT es.name AS set_name, e.name AS elem_name, COUNT(et.id) AS cnt
                FROM %selement_texts et
                LEFT JOIN %selements e ON e.id = et.element_id
                LEFT JOIN %selement_sets es ON es.id = e.element_set_id
                GROUP BY e.id
                ORDER BY es.name, e.name',
                $p, $p, $p
            ))->fetchAllAssociative();

            $logger->info(sprintf(
                '[Classic] Collections: %d public, %d private | Items: %d public, %d private | With media: %d, Without: %d | Media: %d',
                $collections['public'] ?? 0,
                $collections['private'] ?? 0,
                $items['public'] ?? 0,
                $items['private'] ?? 0,
                $itemsWithMedia,
                $itemsWithoutMedia,
                $totalMedia
            ));

            $logger->info('[Classic] Properties used:');
            foreach ($properties as $prop) {
                $logger->info(sprintf('  %s > %s (%d)', $prop['set_name'], $prop['elem_name'], $prop['cnt']));
            }
        } catch (\Exception $e) {
            $logger->warn('Could not collect Classic stats: ' . $e->getMessage());
        }
    }

    protected function logOmekaSStats($logger): void
    {
        try {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');
            $em = $this->getServiceLocator()->get('Omeka\EntityManager');

            $jobId = ($this->getArg('update') == '1') ? $this->updatedJobId : $this->job->getId();

            $maps = $api->search('fromclassicwithlove_resource_maps', ['job_id' => $jobId])->getContent();

            $itemIds = [];
            $itemSetIds = [];
            foreach ($maps as $map) {
                if ($map->mappedResourceName() === 'item') {
                    $itemIds[] = $map->resource()->id();
                } elseif ($map->mappedResourceName() === 'item_set') {
                    $itemSetIds[] = $map->resource()->id();
                }
            }

            $conn = $this->getServiceLocator()->get('Omeka\Connection');

            $isPublic = $isPrivate = 0;
            if (!empty($itemSetIds)) {
                $ph = implode(',', array_fill(0, count($itemSetIds), '?'));
                $rows = $conn->fetchAllAssociative(
                    "SELECT is_public, COUNT(*) AS cnt FROM resource WHERE id IN ($ph) GROUP BY is_public",
                    $itemSetIds
                );
                foreach ($rows as $row) {
                    $row['is_public'] ? $isPublic += $row['cnt'] : $isPrivate += $row['cnt'];
                }
            }

            $itPublic = $itPrivate = 0;
            if (!empty($itemIds)) {
                $ph = implode(',', array_fill(0, count($itemIds), '?'));
                $rows = $conn->fetchAllAssociative(
                    "SELECT is_public, COUNT(*) AS cnt FROM resource WHERE id IN ($ph) GROUP BY is_public",
                    $itemIds
                );
                foreach ($rows as $row) {
                    $row['is_public'] ? $itPublic += $row['cnt'] : $itPrivate += $row['cnt'];
                }
            }

            $itWithMedia = $itWithoutMedia = $totalMedia = 0;
            if (!empty($itemIds)) {
                $ph = implode(',', array_fill(0, count($itemIds), '?'));
                $totalMedia = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM media WHERE item_id IN ($ph)",
                    $itemIds
                );
                $itWithMedia = (int) $conn->fetchOne(
                    "SELECT COUNT(DISTINCT item_id) FROM media WHERE item_id IN ($ph)",
                    $itemIds
                );
                $itWithoutMedia = count($itemIds) - $itWithMedia;
            }

            $propRows = [];
            if (!empty($itemIds)) {
                $ph = implode(',', array_fill(0, count($itemIds), '?'));
                $propRows = $conn->fetchAllAssociative(
                    "SELECT v.prefix, p.local_name, COUNT(val.id) AS cnt
                    FROM value val
                    LEFT JOIN property p ON p.id = val.property_id
                    LEFT JOIN vocabulary v ON v.id = p.vocabulary_id
                    WHERE val.resource_id IN ($ph)
                    GROUP BY p.id
                    ORDER BY v.prefix, p.local_name",
                    $itemIds
                );
            }

            $logger->info(sprintf(
                '[Omeka S] Item sets: %d public, %d private | Items: %d public, %d private | With media: %d, Without: %d | Media: %d',
                $isPublic, $isPrivate,
                $itPublic, $itPrivate,
                $itWithMedia, $itWithoutMedia,
                $totalMedia
            ));

            if ($propRows) {
                $logger->info('[Omeka S] Properties used:');
                foreach ($propRows as $row) {
                    $logger->info(sprintf('  %s:%s (%d)', $row['prefix'], $row['local_name'], $row['cnt']));
                }
            }
        } catch (\Exception $e) {
            $logger->warn('Could not collect Omeka S stats: ' . $e->getMessage());
        }
    }

    protected function endJob()
    {
        $classicImportJson = [
            'has_err' => $this->hasErr,
            'stats' => $this->stats,
        ];
        $this->serviceLocator->get('Omeka\ApiManager')->update('fromclassicwithlove_imports',
            $this->importRecord->id(), $classicImportJson);
    }
}

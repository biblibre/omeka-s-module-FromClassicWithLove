<?php

namespace FromClassicWithLove\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class UndoImportJob extends AbstractJob
{
    public function perform()
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');

        $jobId = $this->getArg('jobId');
        $resources = $api->search('fromclassicwithlove_resource_maps', ['job_id' => intval($jobId)])->getContent();

        if (!empty($resources)) {
            foreach ($resources as $index => $resource) {
                if ($this->shouldStop()) {
                    $logger->warn(new Message(
                        'The job "Undo" was stopped: %d/%d resources processed.', // @translate
                        $index, count($resources)
                    ));
                    break;
                }
                try {
                    // Delete the Omeka-S resource first, so that if it fails
                    // the resource_map is preserved and the undo can be retried.
                    switch ($resource->mappedResourceName()) {
                        case 'item':
                            $api->delete('items', $resource->resource()->id());
                            break;
                        case 'item_set':
                            $api->delete('item_sets', $resource->resource()->id());
                            break;
                        case 'media':
                            $api->delete('media', $resource->resource()->id());
                            break;
                        default:
                            $logger->warn(sprintf('Invalid resource name: %s.', $resource->mappedResourceName())); // @translate
                            break;
                    }
                    $api->delete('fromclassicwithlove_resource_maps', $resource->id());
                } catch (\Exception $e) {
                    $logger->warn(sprintf('Error when trying to delete resource: %s.', $e->getMessage())); // @translate
                }
            }
        } else {
            $logger->info('No resources found to undo.'); // @translate
        }
    }
}

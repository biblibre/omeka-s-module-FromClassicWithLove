<?php

namespace FromClassicWithLove\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use FromClassicWithLove\Form\ImportForm;
use FromClassicWithLove\Form\MappingForm;
use FromClassicWithLove\Job\ImportFromDumpJob;
use FromClassicWithLove\Job\UndoImportJob;
use Omeka\Module\Manager as ModuleManager;

class IndexController extends AbstractActionController
{
    protected $serviceLocator;

    protected $jobId;

    protected $jobUrl;

    public function __construct($serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function indexAction()
    {
        $form = $this->getForm(ImportForm::class);

        $view = new ViewModel();

        $request = $this->getRequest();
        $get = $request->getQuery()->toArray();

        // are we trying to update from a previous job?
        if (empty($get['jobId'])) {
            $view->setVariable('form', $form);

            return $view;
        }

        if (!is_numeric($get['jobId']) || $get['jobId'] <= 0) {
            $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $get['jobId'])); // @translate
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
            ->search('fromclassicwithlove_imports', ['job_id' => $get['jobId']])->getContent();

        if (empty($updatedJob) || empty($updatedJob[0])) {
            $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $get['jobId'])); // @translate
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $jobArgs = $updatedJob[0]->job()->args();
        $form->setUpdatedJob($get['jobId']);
        $form->get('files_source')->setValue($jobArgs['files_source'] ?? '');
        $form->get('domain_name')->setValue($jobArgs['domain_name'] ?? '');

        $view->setVariable('previousJobArgs', $jobArgs);
        $view->setVariable('update', true);
        $view->setVariable('form', $form);

        return $view;
    }

    public function mapAction()
    {
        $view = new ViewModel;
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $post = $request->getPost()->toArray();

        $form = $this->getForm(ImportForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $dumpManager = $this->serviceLocator->get('FromClassicWithLove\DumpManager');
        if (empty($dumpManager)) {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        if ($dumpManager->getConn() === null) {
            $this->messenger()->addError('Could not connect to the dump database. Check the database name in config/local.config.php under fromclassicwithlove.dump_database.'); // @translate
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        try {
            $stmt = $dumpManager->getConn()->executeQuery('SHOW TABLES');
            $tables = $stmt->fetchAllAssociative();

            if (empty($tables)) {
                $this->messenger()->addError('The dump database is empty. Please import the SQL dump directly into the database configured under fromclassicwithlove.dump_database before proceeding.'); // @translate
                return $this->redirect()->toRoute('admin/fromclassicwithlove');
            }

            $p = $dumpManager->getTablePrefix();

            $sql = sprintf(
                'SELECT DISTINCT
                    %1$selement_sets.name AS element_set_name,
                    %1$selements.name AS element_name,
                    %1$selements.id AS element_id
                FROM %1$selement_texts
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                    LEFT JOIN %1$selement_sets ON %1$selements.element_set_id = %1$selement_sets.id
                ORDER BY element_set_name, element_name',
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
        } catch (\Exception $e) {
            $this->messenger()->addError(sprintf('Error: %s. Check if your dump database is a valid Omeka Classic SQL dump.', $e->getMessage())); // @translate
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->addResourceClassMappings($resourceClasses, $this->serviceLocator->get('Omeka\ApiManager'));
        $form->setFilesSource($post['files_source']);
        $form->setDomainName($post['domain_name'] ?? '');

        foreach ($tables as $table) {
            if (in_array($p . 'collection_trees', $table)) { // @todo add minimum version
                if ($this->checkModuleActiveVersion('ItemSetsTree')) {
                    $form->addCollectionsTreeCheckbox();
                } else {
                    $this->messenger()->addWarning(sprintf('Dump database has a collections tree but Omeka-S does not have ItemSetsTree installed. Item sets tree will not be imported.'));
                }
                break;
            }
        }

        foreach ($tables as $table) {
            if (in_array($p . 'tags', $table)) {
                $form->addTagMapping($this->serviceLocator->get('Omeka\ApiManager'));
                break;
            }
        }

        if (!empty($post['updated_job_id'])) {
            $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
                ->search('fromclassicwithlove_imports', ['job_id' => $post['updated_job_id']])->getContent();

            if (!empty($updatedJob) && !empty($updatedJob[0])) {
                $jobArgs = $updatedJob[0]->job()->args();

                $savedData = [];

                foreach ($properties as $property) {
                    $elementId = $property['element_id'];
                    $savedData['elements_properties[' . $elementId . ']'] =
                        $jobArgs['elements_properties'][$elementId] ?? [];
                    $savedData['preserve_html[' . $elementId . ']'] =
                        $jobArgs['preserve_html'][$elementId] ?? null;
                    $savedData['transform_uris[' . $elementId . ']'] =
                        $jobArgs['transform_uris'][$elementId] ?? null;
                }

                foreach ($resourceClasses as $resourceClass) {
                    $typeId = $resourceClass['id'];
                    $savedData['types_classes[' . $typeId . ']'] =
                        $jobArgs['types_classes'][$typeId] ?? null;
                }

                $savedData['import_collections'] = $jobArgs['import_collections'] ?? null;
                $savedData['import_collections_tree'] = $jobArgs['import_collections_tree'] ?? null;
                $savedData['tag_property'] = $jobArgs['tag_property'] ?? null;

                $form->setData($savedData);
            }
        }

        $view->setVariable('form', $form);
        $view->setVariable('resourceClasses', $resourceClasses);
        $view->setVariable('properties', $properties);

        return $view;
    }

    public function importAction()
    {
        $view = new ViewModel;

        $dumpManager = $this->serviceLocator->get('FromClassicWithLove\DumpManager');

        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $post = $request->getPost()->toArray();

        if (empty($dumpManager)) {
            $this->messenger()->addError('Could not find Dump Manager service.');
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $p = $dumpManager->getTablePrefix();

        try {
            $sql = sprintf(
                'SELECT DISTINCT
                    %1$selement_sets.name AS element_set_name,
                    %1$selements.name AS element_name,
                    %1$selements.id AS element_id
                FROM %1$selement_texts
                    LEFT JOIN %1$selements ON %1$selements.id = %1$selement_texts.element_id
                    LEFT JOIN %1$selement_sets ON %1$selements.element_set_id = %1$selement_sets.id
                ORDER BY element_set_name, element_name',
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

            $stmt = $dumpManager->getConn()->executeQuery('SHOW TABLES');
            $tables = $stmt->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->messenger()->addError(sprintf('Error: %s. Check if your dump database is a valid Omeka Classic SQL dump.', $e->getMessage())); // @translate
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        $form = $this->getForm(MappingForm::class);
        $form->addPropertyMappings($properties);
        $form->addResourceClassMappings($resourceClasses);

        foreach ($tables as $table) {
            if (in_array($p . 'collection_trees', $table)) { // @todo add minimum version
                if ($this->checkModuleActiveVersion('ItemSetsTree')) {
                    $form->addCollectionsTreeCheckbox();
                }
                break;
            }
        }

        foreach ($tables as $table) {
            if (in_array($p . 'tags', $table)) {
                $form->addTagMapping();
                break;
            }
        }

        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $this->redirect()->toRoute('admin/fromclassicwithlove');
        }

        // It is optional. Won't import media if not set.
        if (!empty($post['files_source'])) {
            $post['files_source'] = trim($post['files_source']);
            if ($post['files_source'][strlen($post['files_source']) - 1] != '/') {
                $post['files_source'] = $post['files_source'] . '/';
            }
            if (!file_exists($post['files_source'])) {
                $this->messenger()->addError('Given media folders does not exist on disk.'); // @translate
                return $this->redirect()->toRoute('admin/fromclassicwithlove');
            }
        }

        if (!empty($post['domain_name'])) {
            $post['domain_name'] = trim($post['domain_name']);
            if (str_contains($post['domain_name'], '://')) {
                $parts = explode('://', $post['domain_name']);
                if (count($parts) == 2) {
                    $post['domain_name'] = trim($parts[1], '/');
                } else {
                    $this->messenger()->addError(sprintf('Given url \'%s\' for old Omeka Classic instance is invalid.', $post['domain_name'])); // @translate
                    return $this->redirect()->toRoute('admin/fromclassicwithlove');
                }
            } else {
                $post['domain_name'] = trim($post['domain_name'], '/');
            }
        }

        if (isset($post['updated_job_id'])) {
            $updatedJob = $this->serviceLocator->get('Omeka\ApiManager')
                ->search('fromclassicwithlove_imports', ['job_id' => $post['updated_job_id']])->getContent();

            if (empty($updatedJob) || empty($updatedJob[0])) {
                $this->messenger()->addError(sprintf('Invalid import job id \'%s\'.', $post['updated_job_id'])); // @translate
                return $this->redirect()->toRoute('admin/fromclassicwithlove');
            }
        }

        unset($post['csrf']);
        $this->sendJob($post);

        $message = new Message(
            'Dump import started in %s job %s%s', // @translate
            sprintf('<a href="%s">', htmlspecialchars(
                $this->getJobUrl(),
            )),
            $this->getJobId(),
            '</a>'
        );

        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/fromclassicwithlove');
    }

    protected function sendJob($args)
    {
        $job = $this->jobDispatcher()->dispatch(ImportFromDumpJob::class, $args);

        $jobUrl = $this->url()->fromRoute('admin/id', [
            'controller' => 'job',
            'action' => 'show',
            'id' => $job->getId(),
        ]);

        $this->setJobId($job->getId());
        $this->setJobUrl($jobUrl);
    }

    protected function getJobId()
    {
        return $this->jobId;
    }

    protected function setJobId($id)
    {
        $this->jobId = $id;
    }

    protected function getJobUrl()
    {
        return $this->jobUrl;
    }

    protected function setJobUrl($url)
    {
        $this->jobUrl = $url;
    }

    /**
     * Check if a module is active and optionally its minimum version.
     */
    protected function checkModuleActiveVersion(string $module, ?string $version = null): bool
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->serviceLocator->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($module);
        if (!$module
            || $module->getState() !== ModuleManager::STATE_ACTIVE
        ) {
            return false;
        }

        if (!$version) {
            return true;
        }

        $moduleVersion = $module->getIni('version');
        return $moduleVersion
            && version_compare($moduleVersion, $version, '>=');
    }

    public function pastImportsAction()
    {
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $undoJobIds = [];
            foreach ($data['jobs'] ?? [] as $jobId) {
                $undoJob = $this->undoJob($jobId);
                $undoJobIds[] = $undoJob->getId();
            }
            $message = new Message(
                'Undo in progress in the following jobs: %s', // @translate
                implode(', ', $undoJobIds));
            $this->messenger()->addSuccess($message);
        }
        $view = new ViewModel;
        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery() + [
            'page' => $page,
            'sort_by' => $this->params()->fromQuery('sort_by', 'id'),
            'sort_order' => $this->params()->fromQuery('sort_order', 'desc'),
        ];
        $response = $this->api()->search('fromclassicwithlove_imports', $query);
        $this->paginator($response->getTotalResults(), $page);
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    protected function undoJob($jobId)
    {
        $response = $this->api()->search('fromclassicwithlove_imports', ['job_id' => $jobId]);
        $content = $response->getContent();
        if (empty($content)) {
            throw new \RuntimeException(sprintf('No import found for job id %s.', $jobId));
        }
        $classicImport = $content[0];
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(UndoImportJob::class, ['jobId' => $jobId]);
        $response = $this->api()->update('fromclassicwithlove_imports',
            $classicImport->id(),
            [
                'o:undo_job' => ['o:id' => $job->getId()],
            ]
        );
        return $job;
    }
}

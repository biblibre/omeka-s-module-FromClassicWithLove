<?php
namespace FromClassicWithLove\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Text;
use Laminas\Form\Fieldset;
use Laminas\View\Renderer\PhpRenderer;

class Local implements IngesterInterface
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;
    protected $settings;
    protected $logger;

    public function __construct(TempFileFactory $tempFileFactory, Validator $validator, $settings, $logger)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function getLabel()
    {
        return 'Local'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a URL.
     *
     * Accepts the following non-prefixed keys:
     *
     * + ingest_filename: (required) The filename to ingest.
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        if (!isset($data['ingest_filename'])) {
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate;
            return;
        }

        $filepath = $data['ingest_filename'];
        try {
            $realPath = $this->verifyFile($filepath);
        } catch (\Exception $e) {
            $errorStore->addError(
                'ingest_filename',
                sprintf(
                    'Cannot load file "%s". %s', // @translate
                    $filepath,
                    $e->getMessage()
                )
            );
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($data['original_filename'] ?? $data['ingest_filename']);

        // Copy the file to a temp path, so it is managed as a real temp file
        if (!copy($realPath, $tempFile->getTempPath())) {
            $errorStore->addError(
                'ingest_filename',
                sprintf('Failed to copy file "%s" to temporary path.', $filepath) // @translate
            );
            return;
        }

        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['original_filename'] ?? $data['ingest_filename']);
        }

        $storeOriginal = $data['store_original'] ?? true;
        $storeThumbnails = $data['store_thumbnails'] ?? true;
        $deleteTempFile = $data['delete_temp_file'] ?? true;
        $hydrateFileMetadataOnStoreOriginalFalse = $data['hydrate_file_metadata_on_store_original_false'] ?? false;

        $tempFile->mediaIngestFile($media, $request, $errorStore, $storeOriginal, $storeThumbnails, $deleteTempFile, $hydrateFileMetadataOnStoreOriginalFalse);

        $this->dealWithOriginalFile($realPath, $data);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $text = new Text('o:media[__index__][ingest_filename]');
        $text->setOptions([
            'label' => 'Path', // @translate
            'info' => 'File absolute path on the server', // @translate
        ]);
        $text->setAttributes([
            'id' => 'media-local-ingest-filename-__index__',
            'required' => true,
        ]);

        $radio = new Radio('o:media[__index__][original_file_action]');
        $radio->setOptions([
            'label' => 'Action on original file', // @translate
            'info' => 'What to do with the original file after it has been successfully imported', // @translate
        ]);
        $radio->setAttributes([
            'id' => 'media-local-original-file-action-__index__',
            'required' => true,
        ]);
        $radio->setValueOptions([
            'default' => sprintf(
                'Default (%s)', // @translate
                $this->settings->get('localmediaingester_original_file_action', 'keep')
            ),
            'keep' => 'Keep', // @translate
            'delete' => 'Delete', // @translate
        ]);
        $radio->setValue('default');

        $fieldset = new Fieldset();
        $fieldset->add($text);
        $fieldset->add($radio);

        return $view->formCollection($fieldset, false);
    }

    public function verifyFile(string $filepath)
    {
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            throw new \Exception('File does not exist');
        }

        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            throw new \Exception('File is not a regular file or is not readable');
        }

        return $realPath;
    }

    protected function dealWithOriginalFile($realPath, $data)
    {
        $original_file_action = $data['original_file_action'] ?? 'default';
        if (!in_array($original_file_action, ['default', 'keep', 'delete'])) {
            $message = sprintf('LocalMediaIngester: Unknown action "%s"', $original_file_action);
            $this->logger->err($message);
            return;
        }

        if ($original_file_action === 'default') {
            $original_file_action = $this->settings->get('localmediaingester_original_file_action', 'keep');
        }

        if ($original_file_action === 'delete') {
            if (false === unlink($realPath)) {
                $message = sprintf('LocalMediaIngester: Failed to delete file "%s"', $realPath);
                $this->logger->warn($message);
            }
        }
    }
}

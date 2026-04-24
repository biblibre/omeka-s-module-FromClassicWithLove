<?php

namespace FromClassicWithLove\Form;

use Laminas\Form\Form;

class ImportForm extends Form
{
    public function init()
    {
        $this->setAttribute('action', 'fromclassicwithlove/map');
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'files_source',
            'type' => 'text',
            'options' => [
                'label' => 'File path to the original media files', //@translate
                'info' => 'If not set, media will NOT be imported. The \'files\' directory must be uploaded beforehand to the instance. It can be found in the Omeka Classic instance under omeka/files/original. Once done, write the path to the directory.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'placeholder' => '/home/omekas/classic_files/original/',
            ],
        ]);

        $this->add([
            'name' => 'domain_name',
            'type' => 'text',
            'options' => [
                'label' => 'URL of the old Omeka Classic instance', // @translate
                'info' => 'e.g. omekaclassic.domain or https://omekaclassic.domain — used to detect internal links and convert them into relations between imported resources. The protocol and trailing slash are optional.', // @translate
            ],
            'attributes' => [
                'required' => false,
                'placeholder' => 'omekaclassic.domain',
            ],
        ]);
    }

    public function setUpdatedJob($jobId)
    {
        $this->add([
            'name' => 'updated_job_id',
            'type' => 'hidden',
            'attributes' => [
                'value' => $jobId,
            ],
        ]);
    }
}

<?php

namespace FromClassicWithLove\Form;

use Laminas\Form\Form;
use FromClassicWithLove\Form\Element\OptionalCheckbox;
use FromClassicWithLove\Form\Element\OptionalPropertySelect;
use FromClassicWithLove\Form\Element\OptionalResourceClassSelect;

class MappingForm extends Form
{
    public function init()
    {
        $this->setAttribute('action', 'import');
        $this->setAttribute('method', 'post');

        $this->add([
            'name' => 'files_source',
            'type' => 'hidden',
        ]);
        $this->add([
            'name' => 'domain_name',
            'type' => 'hidden',
        ]);

        $this->add([
            'name' => 'import_collections',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Import collections?', //@translate
            ],
        ]);
    }

    public function addPropertyMappings($properties, $api = null)
    {
        foreach ($properties as $property) {
            $defaultMapping = null;

            if (!empty($api)) {
                $localNameOverrides = [
                    // Dublin Core element name => dcterms local_name
                    'Alternative Title' => 'alternative',
                    'Date Available' => 'available',
                    'Date Created' => 'created',
                    'Date Issued' => 'issued',
                    'Date Modified' => 'modified',
                    'Date Valid' => 'valid',
                    'Date Accepted' => 'dateAccepted',
                    'Date Copyrighted' => 'dateCopyrighted',
                    'Date Submitted' => 'dateSubmitted',
                    'Spatial Coverage' => 'spatial',
                    'Temporal Coverage' => 'temporal',
                    'Audience Education Level' => 'educationLevel',
                    'Table Of Contents' => 'tableOfContents',
                    'Conforms To' => 'conformsTo',
                    'Has Format' => 'hasFormat',
                    'Has Part' => 'hasPart',
                    'Has Version' => 'hasVersion',
                    'Is Format Of' => 'isFormatOf',
                    'Is Part Of' => 'isPartOf',
                    'Is Referenced By' => 'isReferencedBy',
                    'Is Replaced By' => 'isReplacedBy',
                    'Is Required By' => 'isRequiredBy',
                    'Is Version Of' => 'isVersionOf',
                    'Accrual Method' => 'accrualMethod',
                    'Accrual Periodicity' => 'accrualPeriodicity',
                    'Accrual Policy' => 'accrualPolicy',
                    'Access Rights' => 'accessRights',
                    'Bibliographic Citation' => 'bibliographicCitation',
                    'Instructional Method' => 'instructionalMethod',
                    'Rights Holder' => 'rightsHolder',
                ];

                $vocabElementKey = $property['element_set_name'] . '|' . $property['element_name'];
                $elementKey = $property['element_name'];

                if (isset($localNameOverrides[$vocabElementKey])) {
                    $omekasProperties = $api->search('properties',
                        ['local_name' => $localNameOverrides[$vocabElementKey]]
                    )->getContent();
                } elseif (isset($localNameOverrides[$elementKey])) {
                    $omekasProperties = $api->search('properties',
                        ['local_name' => $localNameOverrides[$elementKey]]
                    )->getContent();
                } else {
                    // CamelCase conversion of the element name
                    $propertyName = preg_replace('/\s+/', '', lcfirst(ucwords(
                        preg_replace("/[^a-zA-Z0-9 ]+/", "", strtolower($property['element_name']))
                    )));

                    $omekasProperties = $api->search('properties',
                        ['local_name' => $propertyName]
                    )->getContent();
                }

                if (!empty($omekasProperties)) {
                    if (count($omekasProperties) == 1) {
                        $defaultMapping = $omekasProperties[0];
                    } elseif ($property['element_set_name'] == 'Dublin Core') {
                        $dublinCoreProperty = null;

                        foreach ($omekasProperties as $omekasProperty) {
                            if ($omekasProperty->vocabulary()->prefix() == 'dcterms') {
                                if (!empty($dublinCoreProperty)) {
                                    $dublinCoreProperty = null;
                                    break;
                                }

                                $dublinCoreProperty = $omekasProperty;
                            }
                        }

                        $defaultMapping = $dublinCoreProperty;
                    }
                }
            }

            if (!empty($defaultMapping)) {
                $this->add([
                    'name' => 'elements_properties[' . $property['element_id'] . ']',
                    'type' => OptionalPropertySelect::class,
                    'options' => [
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'value' => [ $defaultMapping->id() ],
                        'multiple' => true,
                    ],
                ]);
            } else {
                $this->add([
                    'name' => 'elements_properties[' . $property['element_id'] . ']',
                    'type' => OptionalPropertySelect::class,
                    'options' => [
                        'label' => 'Mapping of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'multiple' => true,
                    ],
                ]);
            }

            $this->add([
                'name' => 'preserve_html[' . $property['element_id'] . ']',
                'type' => OptionalCheckbox::class,
                'options' => [
                    'label' => 'Preserve html of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                ],
            ]);

            $this->add([
                'name' => 'transform_uris[' . $property['element_id'] . ']',
                'type' => OptionalCheckbox::class,
                'options' => [
                    'label' => 'Transform URIs of element ' . $property['element_set_name'] . ' ' . $property['element_name'],
                    'value' => '1',
                ],
            ]);
        }
    }

    public function addResourceClassMappings($resourceClasses, $api = null)
    {
        foreach ($resourceClasses as $resourceClass) {
            $defaultMapping = null;

            if (!empty($api)) {
                $className = preg_replace('/\s+/', '', $resourceClass['name']);
                $omekasClasses = $api->search('resource_classes',
                    ['local_name' => $className]
                )->getContent();

                if (!empty($omekasClasses) && count($omekasClasses) == 1) {
                    $defaultMapping = $omekasClasses[0];
                }
            }

            if (!empty($defaultMapping)) {
                $this->add([
                    'name' => 'types_classes[' . $resourceClass['id'] . ']',
                    'type' => OptionalResourceClassSelect::class,
                    'options' => [
                        'label' => 'Mapping of class ' . $resourceClass['name'],
                    ],
                    'attributes' => [
                        'required' => false,
                        'value' => $defaultMapping->id(),
                    ],
                ]);
            } else {
                $this->add([
                    'name' => 'types_classes[' . $resourceClass['id'] . ']',
                    'type' => OptionalResourceClassSelect::class,
                    'options' => [
                        'label' => 'Mapping of class ' . $resourceClass['name'],
                    ],
                    'attributes' => [
                        'required' => false,
                    ],
                ]);
            }
        }
    }

    public function setFilesSource($filesSource)
    {
        $this->get('files_source')->setValue($filesSource);
    }

    public function setDomainName($domainName)
    {
        $this->get('domain_name')->setValue($domainName);
    }

    public function addCollectionsTreeCheckbox()
    {
        $this->add([
            'name' => 'import_collections_tree',
            'type' => OptionalCheckbox::class,
            'options' => [
                'label' => 'Import CollectionsTree\'s tree?', // @translate
            ],
        ]);
    }

    public function addTagMapping($api = null)
    {
        $this->add([
            'name' => 'tag_property',
            'type' => OptionalPropertySelect::class,
            'options' => [
                'label' => 'Map tags to property', // @translate
                'info' => 'If set, tags from Omeka Classic will be imported as values of this property.', // @translate
                'empty_option' => 'Do not import tags', // @translate
            ],
            'attributes' => [
                'required' => false,
                'multiple' => false,
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
        $this->add([
            'name' => 'update',
            'type' => 'hidden',
            'attributes' => [
                'value' => '1',
            ],
        ]);
    }
}

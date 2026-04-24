<?php
namespace FromClassicWithLove;

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => sprintf('%s/../language', __DIR__),
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            sprintf('%s/../view', __DIR__),
        ],
    ],
    'controllers' => [
        'factories' => [
            'FromClassicWithLove\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            'FromClassicWithLove\Form\ImportForm' => Form\ImportForm::class,
            'FromClassicWithLove\Form\MappingForm' => Form\MappingForm::class,
            'FromClassicWithLove\Form\Element\OptionalCheckbox' => Form\Element\OptionalCheckbox::class,
        ],
        'factories' => [
            'FromClassicWithLove\Form\Element\OptionalResourceClassSelect' => Service\Form\Element\OptionalResourceClassSelectFactory::class,
            'FromClassicWithLove\Form\Element\OptionalPropertySelect' => Service\Form\Element\OptionalPropertySelectFactory::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'fromclassicwithlove_resource_maps' => Api\Adapter\ResourceMapAdapter::class,
            'fromclassicwithlove_imports' => Api\Adapter\ImportAdapter::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'fromclassicwithlove_local' => Service\Media\Ingester\LocalFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'FromClassicWithLove\DumpManager' => Service\DumpManagerFactory::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'FromClassicWithLove',
                'route' => 'admin/fromclassicwithlove',
                'resource' => 'FromClassicWithLove\Controller\Admin\Index',
                'pages' => [
                    [
                        'label' => 'Past imports', // @translate
                        'route' => 'admin/fromclassicwithlove/pastimports',
                        'resource' => 'FromClassicWithLove\Controller\Admin\Index',
                        'action' => 'pastimports',
                    ],
                ],
            ],
        ],
    ],
   'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'fromclassicwithlove' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/fromclassicwithlove',
                            'defaults' => [
                                '__NAMESPACE__' => 'FromClassicWithLove\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'import' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/import',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'FromClassicWithLove\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'import',
                                    ],
                                ],
                            ],
                            'map' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/map',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'FromClassicWithLove\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'map',
                                    ],
                                ],
                            ],
                            'pastimports' => [
                                'type' => 'Literal',
                                'options' => [
                                    'route' => '/pastimports',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'FromClassicWithLove\Controller\Admin',
                                        'controller' => 'Index',
                                        'action' => 'pastimports',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'fromclassicwithlove' => [
        'dump_database' => null,
        'table_prefix' => 'omeka_',
    ],
];

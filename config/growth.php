<?php

declare(strict_types=1);

$tablePrefix = 'growth_';

return [
    /* Database */
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => commerce_json_column_type('growth', 'json'),
        'tables' => [
            'experiments' => $tablePrefix . 'experiments',
            'variants' => $tablePrefix . 'variants',
            'assignments' => $tablePrefix . 'assignments',
        ],
    ],

    /* Defaults */
    'defaults' => [
        'module_type' => 'ab_test',
        'winner_metric' => 'revenue_per_visitor',
        'presets' => [
            'ab_test' => [
                'goal_event_name' => 'order.paid',
                'goal_event_category' => 'conversion',
                'winner_metric' => 'revenue_per_visitor',
                'settings' => [],
            ],
            'sales_page_test' => [
                'goal_event_name' => 'order.paid',
                'goal_event_category' => 'conversion',
                'winner_metric' => 'revenue_per_visitor',
                'settings' => [
                    'cta_event_name' => 'cta_click',
                    'entry_paths' => [],
                    'destination_urls' => [],
                ],
            ],
            'funnel_test' => [
                'goal_event_name' => 'order.paid',
                'goal_event_category' => 'conversion',
                'winner_metric' => 'revenue_per_visitor',
                'settings' => [
                    'funnel_steps' => [
                        [
                            'label' => 'Landing Page',
                            'event_name' => 'page_view',
                            'event_category' => 'page_view',
                        ],
                        [
                            'label' => 'Checkout Started',
                            'event_name' => 'checkout.started',
                            'event_category' => 'checkout',
                        ],
                        [
                            'label' => 'Purchase',
                            'event_name' => 'order.paid',
                            'event_category' => 'conversion',
                        ],
                    ],
                ],
            ],
            'pricing_test' => [
                'goal_event_name' => 'order.paid',
                'goal_event_category' => 'conversion',
                'winner_metric' => 'revenue_per_visitor',
                'settings' => [
                    'checkout_event_name' => 'checkout.started',
                    'price_labels' => [],
                ],
            ],
        ],
    ],

    /* Features / Behavior */
    'features' => [
        'owner' => [
            'enabled' => true,
            'include_global' => false,
            'auto_assign_on_create' => true,
        ],
        'preset_modules' => [
            'enabled' => true,
        ],
        'experiment_middleware' => [
            'enabled' => false,
        ],
        'blade_directives' => [
            'enabled' => false,
        ],
    ],

    /* Integrations */
    'integrations' => [
        'signals' => [
            'enabled' => true,
            'checkout_started_event_name' => 'checkout.started',
            'purchase_event_name' => 'order.paid',
            'refund_event_name' => 'order.refunded',
        ],
    ],

    /* HTTP */
    'http' => [
        'experiment_middleware' => [
            'subject_resolver' => null,
            'anonymous_id_source' => 'cookie',
            'anonymous_id_key' => 'sig_vid',
            'session_identifier_source' => 'cookie',
            'session_identifier_key' => 'sig_sid',
        ],
    ],
];

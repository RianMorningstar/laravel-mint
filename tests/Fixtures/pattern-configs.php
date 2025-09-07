<?php

return [
    'test_patterns' => [
        'user_age_distribution' => [
            'type' => 'normal',
            'config' => [
                'mean' => 35,
                'stddev' => 12,
                'min' => 18,
                'max' => 80,
            ],
        ],
        
        'product_price_distribution' => [
            'type' => 'exponential',
            'config' => [
                'lambda' => 0.01,
                'min' => 5,
                'max' => 5000,
            ],
        ],
        
        'order_seasonal_pattern' => [
            'type' => 'seasonal',
            'config' => [
                'peaks' => [11, 12], // November and December (holiday season)
                'amplitude' => 0.6,
                'base_value' => 100,
            ],
        ],
        
        'traffic_weekly_pattern' => [
            'type' => 'weekly',
            'config' => [
                'weekday_multiplier' => 1.0,
                'weekend_multiplier' => 0.6,
                'base_value' => 1000,
            ],
        ],
        
        'revenue_composite_pattern' => [
            'type' => 'composite',
            'config' => [
                'patterns' => [
                    [
                        'type' => 'normal',
                        'config' => ['mean' => 10000, 'stddev' => 2000],
                    ],
                    [
                        'type' => 'seasonal',
                        'config' => ['peaks' => [6, 12], 'amplitude' => 0.3, 'base_value' => 1],
                    ],
                ],
                'weights' => [1.0, 0.3],
                'combination' => 'multiplicative',
            ],
        ],
    ],
    
    'test_scenarios' => [
        'small_ecommerce' => [
            'description' => 'Small e-commerce dataset for testing',
            'steps' => [
                ['model' => 'User', 'count' => 10],
                ['model' => 'Product', 'count' => 20],
                ['model' => 'Category', 'count' => 5],
                ['model' => 'Order', 'count' => 30],
            ],
        ],
        
        'blog_platform' => [
            'description' => 'Blog platform with posts and comments',
            'steps' => [
                ['model' => 'User', 'count' => 5],
                ['model' => 'Post', 'count' => 25, 'pattern' => 'user_engagement'],
                ['model' => 'Comment', 'count' => 100],
                ['model' => 'Tag', 'count' => 15],
            ],
        ],
        
        'saas_application' => [
            'description' => 'SaaS application with subscriptions',
            'steps' => [
                ['model' => 'User', 'count' => 50],
                ['model' => 'Plan', 'count' => 3],
                ['model' => 'Subscription', 'count' => 45],
                ['model' => 'Invoice', 'count' => 180],
                ['model' => 'Payment', 'count' => 175],
            ],
        ],
    ],
    
    'validation_rules' => [
        'email' => ['required', 'email', 'unique:users'],
        'password' => ['required', 'min:8'],
        'name' => ['required', 'string', 'max:255'],
        'price' => ['required', 'numeric', 'min:0'],
        'stock' => ['required', 'integer', 'min:0'],
        'status' => ['required', 'in:draft,published,archived'],
        'rating' => ['required', 'integer', 'between:1,5'],
    ],
    
    'field_mappings' => [
        'email' => 'email',
        'password' => 'password',
        'name' => 'name',
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'phone' => 'phoneNumber',
        'phone_number' => 'phoneNumber',
        'address' => 'address',
        'city' => 'city',
        'country' => 'country',
        'postal_code' => 'postcode',
        'zip_code' => 'postcode',
        'company' => 'company',
        'website' => 'url',
        'bio' => 'paragraph',
        'description' => 'paragraph',
        'content' => 'paragraphs',
        'title' => 'sentence',
        'slug' => 'slug',
        'uuid' => 'uuid',
        'ip_address' => 'ipv4',
        'mac_address' => 'macAddress',
        'user_agent' => 'userAgent',
        'credit_card' => 'creditCardNumber',
        'iban' => 'iban',
        'isbn' => 'isbn13',
        'ean' => 'ean13',
        'color' => 'hexColor',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'currency' => 'currencyCode',
        'locale' => 'locale',
        'timezone' => 'timezone',
    ],
];
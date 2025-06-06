<?php

/**
 * Sorane Event Tracking Examples
 * 
 * This file demonstrates various ways to use Sorane's event tracking functionality
 * in a Laravel application.
 */

use Sorane\ErrorReporting\Events\EventTracker;
use Sorane\ErrorReporting\Facades\Sorane;
use Sorane\ErrorReporting\Facades\SoraneEvents;

// =====================================================
// Basic Event Tracking
// =====================================================

// Track a simple button click (using validated snake_case)
Sorane::trackEvent('button_clicked', [
    'button_id' => 'header-cta',
    'page' => 'homepage',
    'section' => 'hero'
]);

// Track a feature usage (using predefined constant)
Sorane::trackEvent(EventTracker::SEARCH, [
    'query' => 'laravel tutorials',
    'results_count' => 42,
    'filters_applied' => ['difficulty' => 'beginner']
]);

// Track an event with specific user
Sorane::trackEvent('settings_changed', [
    'setting_name' => 'email_notifications',
    'new_value' => 'enabled',
    'old_value' => 'disabled'
], $userId);

// =====================================================
// E-commerce Events
// =====================================================

// Product added to cart
SoraneEvents::productAddedToCart(
    productId: 'SKU-12345',
    productName: 'Premium Laravel Course',
    price: 199.99,
    quantity: 1,
    category: 'Education',
    additionalProperties: [
        'discount_applied' => false,
        'source' => 'product_page'
    ]
);

// Complete sale/purchase
SoraneEvents::sale(
    orderId: 'ORD-2024-001',
    totalAmount: 249.97,
    products: [
        [
            'id' => 'SKU-12345',
            'name' => 'Premium Laravel Course',
            'price' => 199.99,
            'quantity' => 1,
            'category' => 'Education'
        ],
        [
            'id' => 'SKU-67890', 
            'name' => 'Vue.js Masterclass',
            'price' => 49.98,
            'quantity' => 1,
            'category' => 'Education'
        ]
    ],
    currency: 'USD',
    additionalProperties: [
        'payment_method' => 'stripe',
        'coupon_code' => 'SAVE20',
        'discount_amount' => 50.00
    ]
);

// =====================================================
// User Lifecycle Events
// =====================================================

// User registration
SoraneEvents::userRegistered(
    userId: 123,
    additionalProperties: [
        'registration_source' => 'website',
        'referrer' => 'google',
        'plan' => 'free'
    ]
);

// User login
SoraneEvents::userLoggedIn(
    userId: 123,
    additionalProperties: [
        'login_method' => 'email',
        'remember_me' => true,
        'two_factor' => false
    ]
);

// =====================================================
// Content & Engagement Events
// =====================================================

// Track important page views
SoraneEvents::pageView(
    pageName: 'Pricing Page',
    additionalProperties: [
        'experiment_variant' => 'A',
        'came_from' => 'homepage'
    ]
);

// Newsletter signup (using custom with validation)
SoraneEvents::custom(
    eventName: 'newsletter_signup',
    properties: [
        'source' => 'blog_footer',
        'email_provided' => true,
        'interests' => ['laravel', 'php', 'web-development']
    ]
);

// File download
SoraneEvents::custom(
    eventName: 'file_downloaded',
    properties: [
        'file_name' => 'laravel-cheatsheet.pdf',
        'file_size' => '1.2MB',
        'download_source' => 'resource_page'
    ]
);

// =====================================================
// Subscription & SaaS Events
// =====================================================

// Subscription started
SoraneEvents::custom(
    eventName: 'subscription_started',
    properties: [
        'plan_name' => 'Pro Monthly',
        'plan_price' => 29.99,
        'trial_used' => true,
        'payment_method' => 'credit_card'
    ],
    userId: 123
);

// Subscription cancelled
SoraneEvents::custom(
    eventName: 'subscription_cancelled',
    properties: [
        'plan_name' => 'Pro Monthly',
        'cancellation_reason' => 'too_expensive',
        'days_active' => 45,
        'will_reactivate' => false
    ],
    userId: 123
);

// =====================================================
// Error & Performance Events
// =====================================================

// API rate limit reached
SoraneEvents::custom(
    eventName: 'rate_limit_reached',
    properties: [
        'endpoint' => '/api/v1/users',
        'limit' => 1000,
        'time_window' => '1 hour',
        'user_plan' => 'free'
    ],
    userId: 123
);

// Slow query detected
SoraneEvents::custom(
    eventName: 'slow_query_detected',
    properties: [
        'query_time' => 2.5,
        'query_type' => 'SELECT',
        'table' => 'users',
        'affected_rows' => 10000
    ]
);

// =====================================================
// Marketing & Campaign Events
// =====================================================

// Email campaign interaction
SoraneEvents::custom(
    eventName: 'email_clicked',
    properties: [
        'campaign_id' => 'welcome-series-01',
        'email_subject' => 'Welcome to our platform!',
        'link_clicked' => 'get-started-button',
        'send_date' => '2024-01-15'
    ],
    userId: 123
);

// Social media share
SoraneEvents::custom(
    eventName: 'content_shared',
    properties: [
        'content_type' => 'blog_post',
        'content_title' => 'How to Build APIs with Laravel',
        'share_platform' => 'twitter',
        'content_id' => 'blog-post-123'
    ]
);

// =====================================================
// Custom Business Events
// =====================================================

// Support ticket created
SoraneEvents::custom(
    eventName: 'support_ticket_created',
    properties: [
        'ticket_id' => 'TICK-789',
        'category' => 'billing',
        'priority' => 'medium',
        'source' => 'website'
    ],
    userId: 123
);

// Feature request submitted
SoraneEvents::custom(
    eventName: 'feature_request_submitted',
    properties: [
        'request_title' => 'Dark mode support',
        'request_category' => 'ui_ux',
        'votes_count' => 1,
        'source' => 'feedback_widget'
    ],
    userId: 123
);

// =====================================================
// Advanced: Bypassing Validation
// =====================================================

// Sometimes you might need to track events with legacy naming
// Use customUnsafe() method or pass validate: false
SoraneEvents::customUnsafe(
    eventName: 'Legacy Event Name',
    properties: ['migrated_from' => 'old_system']
);

// Or use the main trackEvent method with validation disabled
Sorane::trackEvent('Another Legacy Event!', [
    'source' => 'migration_script'
], null, false); // validate: false

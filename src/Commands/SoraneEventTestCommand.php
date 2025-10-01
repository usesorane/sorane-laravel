<?php

namespace Sorane\Laravel\Commands;

use Illuminate\Console\Command;
use Sorane\Laravel\Events\EventTracker;
use Sorane\Laravel\Facades\Sorane;
use Sorane\Laravel\Facades\SoraneEvents;

class SoraneEventTestCommand extends Command
{
    protected $signature = 'sorane:test-events';

    protected $description = 'Test Sorane event tracking functionality';

    public function handle(): void
    {
        $this->info('Testing Sorane Event Tracking...');

        // Test event name validation
        $this->info('1. Testing event name validation...');
        try {
            $this->info('   ✓ Valid event name: user_registered');
            SoraneEvents::custom('user_registered', ['source' => 'test']);

            $this->info('   ✓ Using predefined constant: EventTracker::PRODUCT_ADDED_TO_CART');
            Sorane::trackEvent(EventTracker::PRODUCT_ADDED_TO_CART, ['test' => true]);

            $this->info('   ⚠ Testing invalid event name (this will show validation error)...');
            try {
                SoraneEvents::custom('Invalid Event Name!', []);
            } catch (\InvalidArgumentException $e) {
                $this->warn('   Expected validation error: '.$e->getMessage());
            }
        } catch (\Exception $e) {
            $this->error('Validation test failed: '.$e->getMessage());
        }

        // Test basic event tracking
        $this->info('2. Sending basic custom event...');
        Sorane::trackEvent('test_event', [
            'test_property' => 'test_value',
            'timestamp' => now()->toISOString(),
        ]);

        // Test e-commerce events using the helper
        $this->info('3. Sending Product Added to Cart event...');
        SoraneEvents::productAddedToCart(
            productId: 'PROD-123',
            productName: 'Awesome Widget',
            price: 29.99,
            quantity: 2,
            category: 'Widgets',
            additionalProperties: ['color' => 'blue', 'size' => 'large']
        );

        $this->info('4. Sending Sale event...');
        SoraneEvents::sale(
            orderId: 'ORDER-456',
            totalAmount: 89.97,
            products: [
                [
                    'id' => 'PROD-123',
                    'name' => 'Awesome Widget',
                    'price' => 29.99,
                    'quantity' => 2,
                ],
                [
                    'id' => 'PROD-789',
                    'name' => 'Super Gadget',
                    'price' => 29.99,
                    'quantity' => 1,
                ],
            ],
            currency: 'USD',
            additionalProperties: ['payment_method' => 'credit_card']
        );

        $this->info('5. Sending User Registration event...');
        SoraneEvents::userRegistered(
            userId: 123,
            additionalProperties: ['registration_source' => 'website']
        );

        $this->info('6. Sending Page View event...');
        SoraneEvents::pageView(
            pageName: 'Product Details',
            additionalProperties: ['product_id' => 'PROD-123']
        );

        $this->info('7. Sending custom event using validated method...');
        SoraneEvents::custom(
            eventName: 'newsletter_signup',
            properties: ['source' => 'footer', 'email_provided' => true],
            userId: 123
        );

        $this->info('✅ All test events have been sent to Sorane!');
        $this->info('Check your Sorane dashboard to see the events.');

        $this->newLine();
        $this->info('Available Event Constants:');
        $this->table(
            ['Constant', 'Value'],
            [
                ['EventTracker::PRODUCT_ADDED_TO_CART', EventTracker::PRODUCT_ADDED_TO_CART],
                ['EventTracker::SALE', EventTracker::SALE],
                ['EventTracker::USER_REGISTERED', EventTracker::USER_REGISTERED],
                ['EventTracker::USER_LOGGED_IN', EventTracker::USER_LOGGED_IN],
                ['EventTracker::PAGE_VIEW', EventTracker::PAGE_VIEW],
                ['EventTracker::SEARCH', EventTracker::SEARCH],
                ['EventTracker::NEWSLETTER_SIGNUP', EventTracker::NEWSLETTER_SIGNUP],
            ]
        );

        $this->newLine();
        $this->info('Event Name Validation Rules:');
        $this->table(
            ['Rule', 'Description'],
            [
                ['Format', 'snake_case (lowercase with underscores)'],
                ['Length', '3-50 characters'],
                ['Start', 'Must start with a letter'],
                ['Characters', 'Only letters, numbers, and underscores'],
                ['Examples', 'user_registered, product_added_to_cart, newsletter_signup'],
            ]
        );

        $this->newLine();
        $this->info('Privacy-focused fingerprinting:');
        $this->table(
            ['Data Point', 'How It\'s Handled'],
            [
                ['User Agent', 'Hashed with SHA256'],
                ['Session ID', 'Generated from IP + User Agent + Date (daily rotation)'],
                ['IP Address', 'Not sent to Sorane (privacy-first)'],
                ['User ID', 'Only if explicitly provided or user is authenticated'],
            ]
        );

        $this->newLine();
        $this->info('Event tracking configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Events Enabled', config('sorane.events.enabled') ? 'Yes' : 'No'],
                ['Queue Enabled', config('sorane.events.queue') ? 'Yes' : 'No'],
                ['Queue Name', config('sorane.events.queue_name')],
                ['API Key Set', config('sorane.key') ? 'Yes' : 'No'],
            ]
        );
    }
}

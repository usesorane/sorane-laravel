<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Sorane\Laravel\Facades\SoraneEvents;
use Sorane\Laravel\Jobs\SendEventToSoraneJob;

test('SoraneEvents facade is available', function (): void {
    expect(class_exists(SoraneEvents::class))->toBeTrue();
});

test('product added to cart helper works', function (): void {
    Queue::fake();

    SoraneEvents::productAddedToCart(
        productId: 'PROD-123',
        productName: 'Test Product',
        price: 29.99,
        quantity: 2,
        category: 'Electronics'
    );

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'product_added_to_cart'
            && $eventData['properties']['product_id'] === 'PROD-123'
            && $eventData['properties']['price'] === 29.99
            && $eventData['properties']['quantity'] === 2;
    });
});

test('sale helper works', function (): void {
    Queue::fake();

    SoraneEvents::sale(
        orderId: 'ORDER-456',
        totalAmount: 89.97,
        products: [
            ['id' => 'PROD-1', 'name' => 'Product 1', 'price' => 29.99],
            ['id' => 'PROD-2', 'name' => 'Product 2', 'price' => 59.98],
        ],
        currency: 'USD'
    );

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'sale'
            && $eventData['properties']['order_id'] === 'ORDER-456'
            && $eventData['properties']['total_amount'] === 89.97
            && count($eventData['properties']['products']) === 2;
    });
});

test('user registered helper works', function (): void {
    Queue::fake();

    SoraneEvents::userRegistered(
        userId: 123,
        additionalProperties: ['source' => 'website']
    );

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'user_registered'
            && $eventData['user']['id'] === 123
            && $eventData['properties']['source'] === 'website';
    });
});

test('user logged in helper works', function (): void {
    Queue::fake();

    SoraneEvents::userLoggedIn(userId: 456);

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'user_logged_in'
            && $eventData['user']['id'] === 456;
    });
});

test('page view helper works', function (): void {
    Queue::fake();

    SoraneEvents::pageView('Pricing Page', ['variant' => 'A']);

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'page_view'
            && $eventData['properties']['page_name'] === 'Pricing Page'
            && $eventData['properties']['variant'] === 'A';
    });
});

test('custom event helper validates event name', function (): void {
    expect(fn () => SoraneEvents::custom('Invalid Name!', []))
        ->toThrow(InvalidArgumentException::class);
});

test('custom event helper works with valid name', function (): void {
    Queue::fake();

    SoraneEvents::custom('newsletter_signup', ['source' => 'footer']);

    Queue::assertPushed(SendEventToSoraneJob::class, function ($job): bool {
        $eventData = $job->getEventData();

        return $eventData['event_name'] === 'newsletter_signup'
            && $eventData['properties']['source'] === 'footer';
    });
});

test('custom unsafe helper bypasses validation', function (): void {
    Queue::fake();

    // Should not throw exception
    SoraneEvents::customUnsafe('Invalid Event Name!', ['test' => true]);

    Queue::assertPushed(SendEventToSoraneJob::class);
});

<?php

namespace Sorane\ErrorReporting\Events;

use InvalidArgumentException;
use Sorane\ErrorReporting\Facades\Sorane;

class EventTracker
{
    // Standard event name constants to prevent typos and ensure consistency
    public const PRODUCT_ADDED_TO_CART = 'product_added_to_cart';

    public const PRODUCT_REMOVED_FROM_CART = 'product_removed_from_cart';

    public const CART_VIEWED = 'cart_viewed';

    public const CHECKOUT_STARTED = 'checkout_started';

    public const CHECKOUT_COMPLETED = 'checkout_completed';

    public const SALE = 'sale';

    public const USER_REGISTERED = 'user_registered';

    public const USER_LOGGED_IN = 'user_logged_in';

    public const USER_LOGGED_OUT = 'user_logged_out';

    public const PAGE_VIEW = 'page_view';

    public const SEARCH = 'search';

    public const NEWSLETTER_SIGNUP = 'newsletter_signup';

    public const CONTACT_FORM_SUBMITTED = 'contact_form_submitted';

    /**
     * Validate an event name to ensure it follows naming conventions
     *
     * Valid event names:
     * - snake_case format (lowercase with underscores)
     * - 3-50 characters long
     * - Start with a letter
     * - Only contain letters, numbers, and underscores
     */
    public static function validateEventName(string $eventName): bool
    {
        // Check length
        if (strlen($eventName) < 3 || strlen($eventName) > 50) {
            return false;
        }

        // Check format: snake_case, starts with letter, only letters/numbers/underscores
        return preg_match('/^[a-z][a-z0-9_]*$/', $eventName) === 1;
    }

    /**
     * Throw exception if event name is invalid
     */
    public static function ensureValidEventName(string $eventName): void
    {
        if (! self::validateEventName($eventName)) {
            throw new InvalidArgumentException(
                "Invalid event name '{$eventName}'. Event names must be 3-50 characters, ".
                'use snake_case format (lowercase with underscores), start with a letter, '.
                'and only contain letters, numbers, and underscores.'
            );
        }
    }

    /**
     * Track a product being added to cart
     */
    public static function productAddedToCart(
        string $productId,
        string $productName,
        float $price,
        int $quantity = 1,
        ?string $category = null,
        array $additionalProperties = []
    ): void {
        $properties = array_merge([
            'product_id' => $productId,
            'product_name' => $productName,
            'price' => $price,
            'quantity' => $quantity,
            'total_value' => $price * $quantity,
        ], $additionalProperties);

        if ($category) {
            $properties['category'] = $category;
        }

        Sorane::trackEvent(self::PRODUCT_ADDED_TO_CART, $properties);
    }

    /**
     * Track a sale/purchase
     */
    public static function sale(
        string $orderId,
        float $totalAmount,
        array $products = [],
        ?string $currency = 'USD',
        array $additionalProperties = []
    ): void {
        $properties = array_merge([
            'order_id' => $orderId,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'products' => $products,
            'product_count' => count($products),
        ], $additionalProperties);

        Sorane::trackEvent(self::SALE, $properties);
    }

    /**
     * Track user registration
     */
    public static function userRegistered(?int $userId = null, array $additionalProperties = []): void
    {
        Sorane::trackEvent(self::USER_REGISTERED, $additionalProperties, $userId);
    }

    /**
     * Track user login
     */
    public static function userLoggedIn(?int $userId = null, array $additionalProperties = []): void
    {
        Sorane::trackEvent(self::USER_LOGGED_IN, $additionalProperties, $userId);
    }

    /**
     * Track page view (different from analytics, this is for specific tracked pages)
     */
    public static function pageView(string $pageName, array $additionalProperties = []): void
    {
        $properties = array_merge([
            'page_name' => $pageName,
        ], $additionalProperties);

        Sorane::trackEvent(self::PAGE_VIEW, $properties);
    }

    /**
     * Track custom event with custom properties
     * This method validates the event name to ensure consistency
     */
    public static function custom(string $eventName, array $properties = [], ?int $userId = null): void
    {
        self::ensureValidEventName($eventName);
        Sorane::trackEvent($eventName, $properties, $userId);
    }

    /**
     * Track custom event without validation (for advanced users who need flexibility)
     * Use this only if you need to bypass validation for specific cases
     */
    public static function customUnsafe(string $eventName, array $properties = [], ?int $userId = null): void
    {
        Sorane::trackEvent($eventName, $properties, $userId, false);
    }
}

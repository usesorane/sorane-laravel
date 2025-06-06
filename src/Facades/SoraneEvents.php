<?php

namespace Sorane\ErrorReporting\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Sorane\ErrorReporting\Events\EventTracker
 * @method static bool validateEventName(string $eventName)
 * @method static void ensureValidEventName(string $eventName)
 * @method static void productAddedToCart(string $productId, string $productName, float $price, int $quantity = 1, ?string $category = null, array $additionalProperties = [])
 * @method static void sale(string $orderId, float $totalAmount, array $products = [], ?string $currency = 'USD', array $additionalProperties = [])
 * @method static void userRegistered(?int $userId = null, array $additionalProperties = [])
 * @method static void userLoggedIn(?int $userId = null, array $additionalProperties = [])
 * @method static void pageView(string $pageName, array $additionalProperties = [])
 * @method static void custom(string $eventName, array $properties = [], ?int $userId = null)
 * @method static void customUnsafe(string $eventName, array $properties = [], ?int $userId = null)
 */
class SoraneEvents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Sorane\ErrorReporting\Events\EventTracker::class;
    }
}

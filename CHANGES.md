# Event Tracking Updates Summary

## Changes Made

### 1. API Endpoint Updated
- **Changed**: Events endpoint from `/v1/events` to `/v1/analytics/events`
- **File**: `src/Jobs/SendEventToSoraneJob.php`
- **Reason**: Consistency with analytics visits endpoint

### 2. Stricter Event Name Validation
- **Added**: Robust event name validation with clear rules
- **Format**: snake_case (lowercase with underscores)
- **Length**: 3-50 characters
- **Start**: Must begin with a letter
- **Characters**: Only letters, numbers, and underscores

### 3. Event Name Constants
- **Added**: Predefined constants in `EventTracker` class
- **Examples**: `PRODUCT_ADDED_TO_CART`, `USER_REGISTERED`, `SALE`, etc.
- **Benefit**: Prevents typos and ensures consistency

### 4. Validation Methods
- **Added**: `validateEventName()` - returns boolean
- **Added**: `ensureValidEventName()` - throws exception if invalid
- **Integration**: Main `trackEvent()` method validates by default

### 5. Flexible Validation
- **Added**: `validate` parameter to `trackEvent()` method
- **Added**: `customUnsafe()` method in `EventTracker`
- **Purpose**: Allow bypassing validation for legacy events when needed

### 6. Enhanced Documentation
- **Updated**: README with validation rules and examples
- **Updated**: Event examples file with proper naming
- **Added**: Clear guidance on when to use different approaches

### 7. Improved Test Command
- **Enhanced**: `sorane:test-events` command shows validation examples
- **Added**: Display of available constants
- **Added**: Validation rules table

## Benefits

1. **Developer Confidence**: Clear, predictable naming rules
2. **Consistency**: Events won't be mis-categorized due to naming variations
3. **Type Safety**: Constants prevent typos
4. **Flexibility**: Can bypass validation when needed for edge cases
5. **Better UX**: Clear error messages when validation fails

## Usage Examples

```php
// Use constants (recommended)
Sorane::trackEvent(EventTracker::PRODUCT_ADDED_TO_CART, $properties);

// Or use validated snake_case
Sorane::trackEvent('newsletter_signup', $properties);
SoraneEvents::custom('newsletter_signup', $properties);

// For edge cases where validation needs to be bypassed
Sorane::trackEvent('Custom Event Name', $properties, null, false);
SoraneEvents::customUnsafe('Custom Event Name', $properties);
```

## Available Constants

- `PRODUCT_ADDED_TO_CART` → `'product_added_to_cart'`
- `PRODUCT_REMOVED_FROM_CART` → `'product_removed_from_cart'`
- `CART_VIEWED` → `'cart_viewed'`
- `CHECKOUT_STARTED` → `'checkout_started'`
- `CHECKOUT_COMPLETED` → `'checkout_completed'`
- `SALE` → `'sale'`
- `USER_REGISTERED` → `'user_registered'`
- `USER_LOGGED_IN` → `'user_logged_in'`
- `USER_LOGGED_OUT` → `'user_logged_out'`
- `PAGE_VIEW` → `'page_view'`
- `SEARCH` → `'search'`
- `NEWSLETTER_SIGNUP` → `'newsletter_signup'`
- `CONTACT_FORM_SUBMITTED` → `'contact_form_submitted'`

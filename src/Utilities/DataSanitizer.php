<?php

namespace Sorane\Laravel\Utilities;

class DataSanitizer
{
    /**
     * Sanitize data for serialization by removing closures and non-serializable values
     */
    public static function sanitizeForSerialization($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitized[$key] = self::sanitizeForSerialization($value);
            }

            return $sanitized;
        }

        if (is_object($data)) {
            if ($data instanceof \Closure) {
                return '[Closure]';
            }

            // Try to convert objects to arrays, but catch any serialization issues
            try {
                // For objects that implement JsonSerializable
                if (method_exists($data, 'jsonSerialize')) {
                    return self::sanitizeForSerialization($data->jsonSerialize());
                }

                // For objects that implement toArray
                if (method_exists($data, 'toArray')) {
                    return self::sanitizeForSerialization($data->toArray());
                }

                // For other objects, try to convert to string or return class name
                if (method_exists($data, '__toString')) {
                    return (string) $data;
                }

                return '[Object: '.get_class($data).']';
            } catch (\Throwable $e) {
                return '[Object: '.get_class($data).' - serialization failed]';
            }
        }

        // For resources and other non-serializable types
        if (is_resource($data)) {
            return '[Resource: '.get_resource_type($data).']';
        }

        // Return primitive values as-is
        return $data;
    }
}

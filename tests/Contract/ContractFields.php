<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Tests\Contract;

/**
 * Reads field names out of a `contract/items/*.json` fixture.
 *
 * It is a class rather than a Pest helper function because a Pest file only
 * sees functions declared in files loaded into the same process, which a
 * parallel run does not guarantee, while every worker autoloads this.
 *
 * It stays in the test suite rather than in `src/`: nothing on a capture or
 * transport path asks what the fixtures declare, and shipping a reader for them
 * would put a second description of the wire in the package's public surface.
 */
final class ContractFields
{
    /**
     * The rule keys naming a top-level field.
     *
     * Dotted rules (`headers.*.*`, `breadcrumbs.*.timestamp`) describe the
     * inside of a field the list already names, so they must not widen it.
     *
     * @param  array<string, mixed>  $fields  The fixture's `fields` map.
     * @return list<string>
     */
    public static function topLevelKeys(array $fields): array
    {
        $keys = [];

        foreach (array_keys($fields) as $rule) {
            if (! str_contains((string) $rule, '.')) {
                $keys[] = (string) $rule;
            }
        }

        return $keys;
    }

    /**
     * The field names the type requires at its top level.
     *
     * @param  array<string, mixed>  $fields  The fixture's `fields` map.
     * @return list<string>
     */
    public static function requiredTopLevelKeys(array $fields): array
    {
        $required = [];

        foreach ($fields as $rule => $descriptor) {
            if (str_contains((string) $rule, '.') || ! is_array($descriptor)) {
                continue;
            }

            if (($descriptor['required'] ?? false) === true) {
                $required[] = (string) $rule;
            }
        }

        return $required;
    }
}

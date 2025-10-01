<?php

use Sorane\Laravel\Utilities\DataSanitizer;

test('it sanitizes arrays correctly', function (): void {
    $data = [
        'string' => 'test',
        'number' => 123,
        'bool' => true,
        'null' => null,
    ];

    $result = DataSanitizer::sanitizeForSerialization($data);

    expect($result)->toBe($data);
});

test('it replaces closures with placeholder', function (): void {
    $data = [
        'closure' => fn () => 'test',
        'normal' => 'value',
    ];

    $result = DataSanitizer::sanitizeForSerialization($data);

    expect($result['closure'])->toBe('[Closure]');
    expect($result['normal'])->toBe('value');
});

test('it handles nested closures', function (): void {
    $data = [
        'nested' => [
            'closure' => fn () => 'test',
            'another_closure' => function (): void {
                // do nothing
            },
            'safe_value' => 'test',
        ],
    ];

    $result = DataSanitizer::sanitizeForSerialization($data);

    expect($result['nested']['closure'])->toBe('[Closure]');
    expect($result['nested']['another_closure'])->toBe('[Closure]');
    expect($result['nested']['safe_value'])->toBe('test');
});

test('it converts objects with toArray method', function (): void {
    $object = new class
    {
        public function toArray(): array
        {
            return ['key' => 'value'];
        }
    };

    $result = DataSanitizer::sanitizeForSerialization($object);

    expect($result)->toBe(['key' => 'value']);
});

test('it converts objects with jsonSerialize method', function (): void {
    $object = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['serialized' => true];
        }
    };

    $result = DataSanitizer::sanitizeForSerialization($object);

    expect($result)->toBe(['serialized' => true]);
});

test('it converts objects with __toString method', function (): void {
    $object = new class
    {
        public function __toString(): string
        {
            return 'string representation';
        }
    };

    $result = DataSanitizer::sanitizeForSerialization($object);

    expect($result)->toBe('string representation');
});

test('it handles objects without serialization methods', function (): void {
    $object = new stdClass;

    $result = DataSanitizer::sanitizeForSerialization($object);

    expect($result)->toContain('stdClass');
});

test('it handles resources', function (): void {
    $resource = fopen('php://memory', 'r');

    $result = DataSanitizer::sanitizeForSerialization($resource);

    expect($result)->toContain('Resource');

    fclose($resource);
});

test('it preserves primitive values', function (): void {
    expect(DataSanitizer::sanitizeForSerialization('string'))->toBe('string');
    expect(DataSanitizer::sanitizeForSerialization(123))->toBe(123);
    expect(DataSanitizer::sanitizeForSerialization(45.67))->toBe(45.67);
    expect(DataSanitizer::sanitizeForSerialization(true))->toBe(true);
    expect(DataSanitizer::sanitizeForSerialization(false))->toBe(false);
    expect(DataSanitizer::sanitizeForSerialization(null))->toBe(null);
});

test('it handles deeply nested structures', function (): void {
    $data = [
        'level1' => [
            'level2' => [
                'level3' => [
                    'closure' => fn () => 'test',
                    'value' => 'deep',
                ],
            ],
        ],
    ];

    $result = DataSanitizer::sanitizeForSerialization($data);

    expect($result['level1']['level2']['level3']['closure'])->toBe('[Closure]');
    expect($result['level1']['level2']['level3']['value'])->toBe('deep');
});

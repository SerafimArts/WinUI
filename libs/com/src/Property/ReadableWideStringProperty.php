<?php

declare(strict_types=1);

namespace Local\Com\Property;

use FFI\CData;
use Local\Com\Struct;
use Local\Com\WideString;
use Local\Property\ReadablePropertyInterface;

/**
 * @template-implements ReadablePropertyInterface<string>
 */
final readonly class ReadableWideStringProperty implements ReadablePropertyInterface
{
    /**
     * @var non-empty-string
     */
    private const string DEFAULT_TYPE = 'LPWSTR';

    /**
     * @var ReadableProperty<CData>
     */
    private ReadableProperty $property;

    /**
     * @param Struct<object> $context Same as {@see Property::$context}.
     * @param non-empty-string $name Same as {@see Property::$name}.
     * @param non-empty-string $type Same as {@see Property::$type}.
     * @param bool $once Same as {@see ReadableProperty::$once}.
     */
    public function __construct(
        Struct $context,
        string $name,
        string $type = self::DEFAULT_TYPE,
        bool $once = true,
    ) {
        $this->property = new ReadableProperty(
            context: $context,
            name: $name,
            type: $type,
            once: $once,
        );
    }

    public function get(): string
    {
        return WideString::fromWideString($this->property->get());
    }
}

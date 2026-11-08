<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an optional profile field behaves for an application: never collected,
 * collected when a user wants it, or demanded of every user.
 */
enum FieldMode: string
{
    case Disabled = 'disabled';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::Optional => 'Optional',
            self::Required => 'Required',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Disabled => 'The field is not collected and stays hidden.',
            self::Optional => 'Users can fill the field in, but nothing forces them to.',
            self::Required => 'Every user must fill the field in.',
        };
    }

    /**
     * The stored setting value, defaulting to disabled.
     */
    public static function fromSetting(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Disabled) : self::Disabled;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Disabled->value => self::Disabled->label(),
            self::Optional->value => self::Optional->label(),
            self::Required->value => self::Required->label(),
        ];
    }
}

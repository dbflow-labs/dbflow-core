<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Enums;

enum FieldOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';
    case IsTrue = 'is_true';
    case IsFalse = 'is_false';

    /**
     * @return list<self>
     */
    public static function forType(FieldType $type): array
    {
        $common = [self::Equals, self::NotEquals, self::IsNull, self::IsNotNull];

        return match ($type) {
            FieldType::String, FieldType::Enum => [
                ...$common,
                self::In,
                self::NotIn,
                self::Contains,
                self::StartsWith,
                self::EndsWith,
            ],
            FieldType::Integer, FieldType::Number, FieldType::Date, FieldType::DateTime => [
                ...$common,
                self::GreaterThan,
                self::GreaterThanOrEqual,
                self::LessThan,
                self::LessThanOrEqual,
                self::In,
                self::NotIn,
            ],
            FieldType::Boolean => [
                self::Equals,
                self::NotEquals,
                self::IsNull,
                self::IsNotNull,
                self::IsTrue,
                self::IsFalse,
            ],
            FieldType::Array => [
                ...$common,
                self::Contains,
                self::In,
                self::NotIn,
            ],
            FieldType::Object => $common,
        };
    }

    public function isAllowedFor(FieldType $type): bool
    {
        foreach (self::forType($type) as $operator) {
            if ($operator === $this) {
                return true;
            }
        }

        return false;
    }
}

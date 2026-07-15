<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Enums;

/**
 * Recorded outcome of one checklist step.
 */
enum StepStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

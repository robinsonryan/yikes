<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Enums;

enum NoteStatus: string
{
    case New = 'new';
    case OnHold = 'on-hold';
    case Approved = 'approved';
    case Done = 'done';
    case Ignored = 'ignored';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

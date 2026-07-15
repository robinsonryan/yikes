<?php

declare(strict_types=1);

namespace RobinsonRyan\Yikes\Enums;

enum NoteType: string
{
    case Bug = 'bug';
    case Layout = 'layout';
    case Idea = 'idea';
    case Refactor = 'refactor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

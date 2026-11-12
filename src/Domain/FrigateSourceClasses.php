<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enum\LabelKind;

/**
 * Maps Frigate source object classes to the label kinds they accept
 * (design.md D5: kind constrains Frigate source classes, enforced in the
 * application layer). Unknown source classes accept no label kind.
 */
final class FrigateSourceClasses
{
    private const KIND_BY_SOURCE_CLASS = [
        'person' => LabelKind::Person,
        'dog' => LabelKind::Dog,
    ];

    public static function acceptedKind(string $sourceClass): ?LabelKind
    {
        return self::KIND_BY_SOURCE_CLASS[$sourceClass] ?? null;
    }
}

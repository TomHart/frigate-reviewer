<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of a label. Constrains which Frigate source classes the label may
 * be applied to (design.md D5).
 */
enum LabelKind: string
{
    case Person = 'person';
    case Dog = 'dog';
}

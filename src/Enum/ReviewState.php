<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Listing filter over review progress: an object counts as reviewed once its
 * validity is anything other than unreviewed.
 */
enum ReviewState: string
{
    case Reviewed = 'reviewed';
    case Unreviewed = 'unreviewed';
}

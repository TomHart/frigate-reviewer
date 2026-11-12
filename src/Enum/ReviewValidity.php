<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Reviewer's validity classification of a detection.
 * Defaults to Unreviewed per spec ("Per-object review").
 */
enum ReviewValidity: string
{
    case Unreviewed = 'unreviewed';
    case TruePositive = 'true_positive';
    case FalsePositive = 'false_positive';
    case Unsure = 'unsure';
}

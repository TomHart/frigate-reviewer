<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a detection came from: synced from Frigate, or drawn manually by the
 * reviewer (spec "Manual objects added by reviewer").
 */
enum DetectionOrigin: string
{
    case Frigate = 'frigate';
    case Manual = 'manual';
}

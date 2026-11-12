<?php

declare(strict_types=1);

namespace App\Domain;

use App\Entity\Detection;
use App\Entity\Label;
use App\Enum\DetectionOrigin;

/**
 * Which identity labels a reviewer may assign to a detection (design.md D5):
 * manual objects accept any kind, Frigate detections accept only labels whose
 * kind matches their Frigate source class. The domain layer enforces this
 * again in {@see \App\Entity\Review::setIdentityLabel()} — this policy only
 * decides what the UI offers, so speed-reviewing never submits a label the
 * write path would reject.
 */
final class LabelPolicy
{
    /**
     * The subset of the given active labels assignable to the detection,
     * preserving label presentation order.
     *
     * @param list<Label> $labels active labels in presentation order
     *
     * @return list<Label>
     */
    public static function assignableTo(Detection $detection, array $labels): array
    {
        if ($detection->getOrigin() === DetectionOrigin::Manual) {
            return $labels;
        }

        $sourceClass = $detection->getSourceClass();
        $acceptedKind = $sourceClass === null ? null : FrigateSourceClasses::acceptedKind($sourceClass);

        if ($acceptedKind === null) {
            return [];
        }

        return array_values(array_filter(
            $labels,
            static fn (Label $label): bool => $label->getKind() === $acceptedKind,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * A parsed retention policy (design.md D11): one global default maximum age
 * in days plus per-camera overrides. Parsed once from the environment by
 * {@see RetentionPolicyResolver}; everything downstream trusts this typed
 * state instead of re-validating strings.
 *
 * A snapshot may be referenced by detections from several cameras (identical
 * image bytes are stored once, design.md D3). The applicable limit for such a
 * snapshot is the LONGEST limit among all cameras that reference it: the
 * snapshot may only be deleted when it is expired under every referencing
 * camera's policy, so one camera's shorter limit can never destroy another
 * camera's data.
 */
final class RetentionPolicy
{
    /**
     * @param int|null $defaultDays Global maximum age in days; null means no
     *                              global policy (nothing expires by default).
     * @param array<string, int> $cameraDays Camera name => maximum age in days.
     */
    public function __construct(
        public readonly ?int $defaultDays,
        public readonly array $cameraDays,
    ) {
    }

    /** True when at least one age limit is configured. */
    public function isConfigured(): bool
    {
        return $this->defaultDays !== null || $this->cameraDays !== [];
    }

    /**
     * The age limit in days for one camera, or null when that camera has no
     * policy (it never expires).
     */
    public function limitForCamera(?string $camera): ?int
    {
        if ($camera !== null && \array_key_exists($camera, $this->cameraDays)) {
            return $this->cameraDays[$camera];
        }

        return $this->defaultDays;
    }

    /**
     * The shortest limit that could ever expire anything; snapshots younger
     * than this can never be deleted, so they need not be considered.
     */
    public function shortestLimit(): ?int
    {
        $limits = $this->cameraDays;

        if ($this->defaultDays !== null) {
            $limits[] = $this->defaultDays;
        }

        return $limits === [] ? null : min($limits);
    }
}

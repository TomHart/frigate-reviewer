<?php

declare(strict_types=1);

namespace App\Retention;

/**
 * Parses retention policy configuration from the environment (design.md D11):
 *
 * - `RETENTION_DEFAULT_DAYS` — global maximum age in days. Unset or empty
 *   means no global policy: nothing expires by default.
 * - `RETENTION_CAMERA_<CAMERA>_DAYS` — per-camera override, e.g. camera
 *   `front_door` reads `RETENTION_CAMERA_FRONT_DOOR_DAYS=7`.
 *
 * Per-camera limits are looked up from the known camera names (synced from
 * Frigate) rather than by scanning for variables, so camera names containing
 * characters outside the env-var alphabet resolve unambiguously.
 *
 * This is the boundary between raw environment strings and typed policy
 * state: invalid values fail loudly here and are never seen downstream.
 */
final class RetentionPolicyResolver
{
    private const DEFAULT_VAR = 'RETENTION_DEFAULT_DAYS';
    private const CAMERA_VAR_TEMPLATE = 'RETENTION_CAMERA_%s_DAYS';

    /**
     * @param list<string> $cameras Camera names to look up per-camera limits for.
     */
    public function resolveForCameras(array $cameras): RetentionPolicy
    {
        $defaultDays = $this->parseDays(self::DEFAULT_VAR);
        $cameraDays = [];

        foreach ($cameras as $camera) {
            if (!is_string($camera) || $camera === '') {
                throw new \RuntimeException('Retention camera names must be non-empty strings.');
            }

            $variable = sprintf(self::CAMERA_VAR_TEMPLATE, strtoupper($camera));
            $days = $this->parseDays($variable);

            if ($days !== null) {
                $cameraDays[$camera] = $days;
            }
        }

        return new RetentionPolicy($defaultDays, $cameraDays);
    }

    /**
     * Parses one age-limit environment variable; unset or empty means "not
     * configured" (null), a non-negative integer is accepted, anything else
     * fails fast with a descriptive error.
     */
    private function parseDays(string $variable): ?int
    {
        $raw = $this->environmentValue($variable);

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);

        if (!ctype_digit($trimmed)) {
            throw new \RuntimeException(sprintf(
                'Retention variable "%s" must be a non-negative integer number of days, got "%s".',
                $variable,
                $raw,
            ));
        }

        return (int) $trimmed;
    }

    /**
     * Reads one variable from the process environment, in the same spirit as
     * Symfony's own env handling: real environment, $_SERVER, then $_ENV.
     */
    private function environmentValue(string $variable): ?string
    {
        $value = getenv($variable);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_SERVER[$variable]) && is_string($_SERVER[$variable]) && $_SERVER[$variable] !== '') {
            return $_SERVER[$variable];
        }

        if (isset($_ENV[$variable]) && is_string($_ENV[$variable]) && $_ENV[$variable] !== '') {
            return $_ENV[$variable];
        }

        return null;
    }
}

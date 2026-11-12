<?php

declare(strict_types=1);

namespace App\Tests\Retention;

use App\Retention\RetentionPolicyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Retention policy parsing (design.md D11, task 8.1): the environment is the
 * boundary; this test covers parsing into the typed policy, per-camera
 * overrides falling back to the global default, and fail-fast on garbage.
 */
final class RetentionPolicyResolverTest extends TestCase
{
    /** @var list<string> */
    private array $touchedVariables = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedVariables as $variable) {
            putenv($variable);
            unset($_ENV[$variable], $_SERVER[$variable]);
        }

        $this->touchedVariables = [];

        parent::tearDown();
    }

    public function testWithoutConfigurationNothingExpires(): void
    {
        $policy = $this->resolver()->resolveForCameras(['front_door']);

        self::assertFalse($policy->isConfigured());
        self::assertNull($policy->limitForCamera('front_door'));
        self::assertNull($policy->limitForCamera(null));
        self::assertNull($policy->shortestLimit());
    }

    public function testGlobalDefaultAppliesToEveryCamera(): void
    {
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', '30');

        $policy = $this->resolver()->resolveForCameras(['front_door', 'back_garden']);

        self::assertTrue($policy->isConfigured());
        self::assertSame(30, $policy->limitForCamera('front_door'));
        self::assertSame(30, $policy->limitForCamera('back_garden'));
        self::assertSame(30, $policy->limitForCamera(null)); // Manual objects, no camera.
        self::assertSame(30, $policy->shortestLimit());
    }

    public function testPerCameraOverrideWinsAndOtherCamerasUseGlobalDefault(): void
    {
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', '30');
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', '7');

        $policy = $this->resolver()->resolveForCameras(['front_door', 'back_garden']);

        self::assertSame(7, $policy->limitForCamera('front_door'));
        self::assertSame(30, $policy->limitForCamera('back_garden'));
        self::assertSame(7, $policy->shortestLimit());
    }

    public function testPerCameraOverrideWithoutGlobalDefaultOnlyAffectsThatCamera(): void
    {
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', '7');

        $policy = $this->resolver()->resolveForCameras(['front_door', 'back_garden']);

        self::assertTrue($policy->isConfigured());
        self::assertSame(7, $policy->limitForCamera('front_door'));
        self::assertNull($policy->limitForCamera('back_garden'));
        self::assertNull($policy->limitForCamera(null));
        self::assertSame(7, $policy->shortestLimit());
    }

    public function testCameraNameIsUppercasedForItsVariable(): void
    {
        $this->setEnvironment('RETENTION_CAMERA_BACK_GARDEN_DAYS', '14');

        $policy = $this->resolver()->resolveForCameras(['back_garden']);

        self::assertSame(14, $policy->limitForCamera('back_garden'));
    }

    public function testBlankVariableMeansNotConfigured(): void
    {
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', '   ');

        $policy = $this->resolver()->resolveForCameras(['front_door']);

        self::assertFalse($policy->isConfigured());
    }

    public function testNonNumericDaysFailLoudly(): void
    {
        $this->setEnvironment('RETENTION_DEFAULT_DAYS', 'soon');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RETENTION_DEFAULT_DAYS');

        $this->resolver()->resolveForCameras(['front_door']);
    }

    public function testNegativeDaysFailLoudly(): void
    {
        $this->setEnvironment('RETENTION_CAMERA_FRONT_DOOR_DAYS', '-5');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RETENTION_CAMERA_FRONT_DOOR_DAYS');

        $this->resolver()->resolveForCameras(['front_door']);
    }

    public function testEmptyCameraNameFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-empty');

        $this->resolver()->resolveForCameras(['']);
    }

    private function resolver(): RetentionPolicyResolver
    {
        return new RetentionPolicyResolver();
    }

    private function setEnvironment(string $variable, string $value): void
    {
        $this->touchedVariables[] = $variable;
        putenv($variable.'='.$value);
        $_ENV[$variable] = $value;
        $_SERVER[$variable] = $value;
    }
}

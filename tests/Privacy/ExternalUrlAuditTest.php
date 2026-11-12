<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use PHPUnit\Framework\TestCase;

/**
 * Privacy audit (spec "Privacy and retention", task 8.3): no image data or
 * any other data may ever leave the self-hosted environment. This is a
 * static sweep over everything that ships to the browser or runs on the
 * server:
 *
 *  - templates/ and assets/ must not reference ANY external http(s) URL —
 *    no external CDNs, fonts, images or APIs. The only http(s) occurrences
 *    allowed anywhere in assets are the two third-party license comments
 *    that ship inside vendored/built files (they are never fetched).
 *  - src/ must not contain http(s) URL literals at all, and Symfony's
 *    HttpClient may only be used by the read-only Frigate API client, which
 *    talks exclusively to the locally configured FRIGATE_BASE_URL.
 *
 * Everything is served locally through AssetMapper; images are served by the
 * app itself; thumbnails are generated with local GD. There is no other
 * network path out of the application.
 */
final class ExternalUrlAuditTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__.'/../..';

    /** License-comment URLs inside third-party files; never fetched, documented here once. */
    private const ALLOWED_ASSET_URLS = [
        'https://tailwindcss.com',                                  // MIT license banner in the built CSS.
        'https://www.jsdelivr.com/using-sri-with-dynamic-files',    // SRI note in vendored Stimulus.
    ];

    public function testTemplatesReferenceNoExternalUrls(): void
    {
        $externalUrls = $this->externalUrlsIn(self::PROJECT_ROOT.'/templates');

        self::assertSame([], $externalUrls, sprintf(
            "Templates must not reference external URLs (no CDNs, fonts or APIs); found:\n%s",
            implode("\n", $externalUrls),
        ));
    }

    public function testAssetsReferenceNoExternalUrlsBeyondLicenseComments(): void
    {
        $externalUrls = $this->externalUrlsIn(self::PROJECT_ROOT.'/assets');

        $unallowed = array_values(array_filter(
            $externalUrls,
            static fn (string $url): bool => !\in_array($url, self::ALLOWED_ASSET_URLS, true),
        ));

        self::assertSame([], $unallowed, sprintf(
            "Assets must not reference external URLs beyond the documented license comments; found:\n%s",
            implode("\n", $unallowed),
        ));
    }

    public function testApplicationCodeContainsNoHttpUrlLiterals(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(self::PROJECT_ROOT.'/src') as $file) {
            foreach ($this->httpUrlsIn((string) file_get_contents($file)) as $url) {
                $violations[] = $url.' ('.$file.')';
            }
        }

        self::assertSame([], $violations, sprintf(
            "Application code must not hardcode any http(s) URL; the Frigate base URL comes from the environment:\n%s",
            implode("\n", $violations),
        ));
    }

    public function testHttpClientIsOnlyUsedByTheReadOnlyFrigateApiClient(): void
    {
        $allowedFile = 'src/Frigate/HttpFrigateApiClient.php';
        $violations = [];

        foreach ($this->phpFilesIn(self::PROJECT_ROOT.'/src') as $file) {
            $relative = $this->relativePath($file);

            if ($relative === $allowedFile) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (str_contains($contents, 'HttpClientInterface')) {
                $violations[] = $relative;
            }
        }

        self::assertSame([], $violations, sprintf(
            "Symfony HttpClient may only be used by %s (read-only sync from the configured Frigate base URL); found in:\n%s",
            $allowedFile,
            implode("\n", $violations),
        ));
    }

    public function testNoCurlOrSocketUsageInApplicationCode(): void
    {
        $forbiddenFunctions = ['curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client'];
        $violations = [];

        foreach ($this->phpFilesIn(self::PROJECT_ROOT.'/src') as $file) {
            $contents = (string) file_get_contents($file);

            foreach ($forbiddenFunctions as $function) {
                if (str_contains($contents, $function)) {
                    $violations[] = $function.' ('.$this->relativePath($file).')';
                }
            }
        }

        self::assertSame([], $violations, 'Application code must not open network connections outside the Frigate HTTP client.');
    }

    /** @return list<string> Unique external http(s) URLs found, with file locations stripped. */
    private function externalUrlsIn(string $directory): array
    {
        $urls = [];

        foreach ($this->allFilesIn($directory) as $file) {
            foreach ($this->httpUrlsIn((string) file_get_contents($file)) as $url) {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    /** @return list<string> */
    private function httpUrlsIn(string $contents): array
    {
        if (!preg_match_all('/https?:\/\/[A-Za-z0-9._~:\/?#\[\]@!$&\'()*+,;=%-]+/', $contents, $matches)) {
            return [];
        }

        $external = [];

        foreach ($matches[0] as $url) {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

            if (in_array($host, ['localhost', '127.0.0.1', '::1', 'example.com', 'example.org'], true)) {
                continue; // Local or placeholder: nothing leaves the environment.
            }

            $external[] = rtrim($url, '.,;)');
        }

        return $external;
    }

    /** @return list<string> */
    private function allFilesIn(string $directory): array
    {
        $files = [];

        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            ) as $file
        ) {
            if ($file->isFile()) {
                $files[] = (string) $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        return array_values(array_filter(
            $this->allFilesIn($directory),
            static fn (string $file): bool => str_ends_with($file, '.php'),
        ));
    }

    private function relativePath(string $file): string
    {
        return str_replace(self::PROJECT_ROOT.'/', '', $file);
    }
}

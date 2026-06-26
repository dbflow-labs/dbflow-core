<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PackageBoundaryTest extends TestCase
{
    #[Test]
    public function package_source_config_and_tests_have_no_dberp_leakage(): void
    {
        $violations = $this->collectForbiddenTermViolations(
            directories: ['src', 'config', 'tests'],
            terms: self::forbiddenHostBusinessTerms(),
        );

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function package_source_config_and_tests_have_no_stale_app_dbflow_namespace(): void
    {
        $violations = $this->collectForbiddenTermViolations(
            directories: ['src', 'config', 'tests'],
            terms: self::forbiddenLegacyNamespaceTerms(),
        );

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function package_has_no_forbidden_brand_terms_in_runtime_paths(): void
    {
        $violations = $this->collectForbiddenTermViolations(
            directories: ['src', 'config', 'tests'],
            terms: self::forbiddenBrandTerms(),
        );

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return list<string>
     */
    private static function forbiddenHostBusinessTerms(): array
    {
        return [
            'D'.'berp',
            'Purchase'.'Request',
            'D'.'berp'.'Permission'.'Service',
            'Purchase'.'Request'.'Status',
        ];
    }

    /**
     * @return list<string>
     */
    private static function forbiddenLegacyNamespaceTerms(): array
    {
        return [
            'namespace App\\'.'DBFlow',
            'use App\\'.'DBFlow',
            'App\\'.'DBFlow'.'\\',
        ];
    }

    /**
     * @return list<string>
     */
    private static function forbiddenBrandTerms(): array
    {
        return [
            'loong'.'dom',
            'Loong'.'dom',
            'DBFlow\\'.'Core',
            'dbflow/'.'core',
        ];
    }

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function collectForbiddenTermViolations(array $directories, array $terms): array
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach ($directories as $directory) {
            $absoluteDirectory = $root.DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($absoluteDirectory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }

                if (! in_array($fileInfo->getExtension(), ['php', 'xml', 'md', 'json', 'yml', 'yaml'], true)) {
                    continue;
                }

                $contents = (string) file_get_contents($fileInfo->getPathname());
                $relativePath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));

                foreach ($terms as $term) {
                    if (str_contains($contents, $term)) {
                        $violations[] = "[{$relativePath}] contains forbidden term [{$term}]";
                    }
                }
            }
        }

        return $violations;
    }
}

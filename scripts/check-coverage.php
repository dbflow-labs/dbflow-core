<?php

/**
 * Enforce PHPUnit Clover coverage thresholds for DBFlow Core RC gates.
 *
 * Usage: php scripts/check-coverage.php [path/to/coverage.xml]
 */

declare(strict_types=1);

$cloverPath = $argv[1] ?? 'build/coverage.xml';

if (! is_file($cloverPath)) {
    fwrite(STDERR, "Clover report not found: {$cloverPath}\n");
    exit(1);
}

/** @var list<string> Runtime public API scope (≥ 80% statement coverage). */
const RUNTIME_FILES = [
    'src/Actions/StartWorkflow.php',
    'src/Actions/ApproveTask.php',
    'src/Actions/RejectTask.php',
    'src/Actions/CancelWorkflow.php',
    'src/Actions/ReassignTask.php',
    'src/Actions/ProcessTaskTimeouts.php',
    'src/DBFlow.php',
];

const RUNTIME_MIN_PERCENT = 80.0;
const SRC_MIN_PERCENT = 70.0;

$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "Failed to parse Clover XML: {$cloverPath}\n");
    exit(1);
}

/** @var array<string, array{statements: int, covered: int}> */
$fileMetrics = [];

foreach ($xml->xpath('//file') as $fileNode) {
    $absolutePath = str_replace('\\', '/', (string) $fileNode['name']);

    if (! preg_match('#(?:^|/)src/(.+)$#', $absolutePath, $matches)) {
        continue;
    }

    $relativePath = 'src/'.$matches[1];
    $metricsNode = $fileNode->metrics;

    if ($metricsNode === null) {
        continue;
    }

    $statements = (int) $metricsNode['statements'];
    $covered = (int) $metricsNode['coveredstatements'];

    if ($statements === 0) {
        continue;
    }

    $fileMetrics[$relativePath] = [
        'statements' => $statements,
        'covered' => $covered,
    ];
}

if ($fileMetrics === []) {
    fwrite(STDERR, "No src/ metrics found in Clover report.\n");
    exit(1);
}

/**
 * @param  list<string>  $paths
 * @return array{statements: int, covered: int, percent: float}
 */
function aggregateMetrics(array $paths, array $fileMetrics): array
{
    $statements = 0;
    $covered = 0;

    foreach ($paths as $path) {
        if (! isset($fileMetrics[$path])) {
            fwrite(STDERR, "Missing coverage metrics for required file: {$path}\n");
            exit(1);
        }

        $statements += $fileMetrics[$path]['statements'];
        $covered += $fileMetrics[$path]['covered'];
    }

    $percent = $statements > 0 ? ($covered / $statements) * 100 : 0.0;

    return [
        'statements' => $statements,
        'covered' => $covered,
        'percent' => $percent,
    ];
}

$srcPaths = array_keys($fileMetrics);
sort($srcPaths);

$runtime = aggregateMetrics(RUNTIME_FILES, $fileMetrics);
$src = aggregateMetrics($srcPaths, $fileMetrics);

$failures = [];

if ($runtime['percent'] < RUNTIME_MIN_PERCENT) {
    $failures[] = sprintf(
        'Runtime API coverage %.2f%% is below %.0f%% (%d/%d statements)',
        $runtime['percent'],
        RUNTIME_MIN_PERCENT,
        $runtime['covered'],
        $runtime['statements'],
    );
}

if ($src['percent'] < SRC_MIN_PERCENT) {
    $failures[] = sprintf(
        'Full src/ coverage %.2f%% is below %.0f%% (%d/%d statements)',
        $src['percent'],
        SRC_MIN_PERCENT,
        $src['covered'],
        $src['statements'],
    );
}

echo sprintf(
    "Runtime API: %.2f%% (%d/%d statements, min %.0f%%)\n",
    $runtime['percent'],
    $runtime['covered'],
    $runtime['statements'],
    RUNTIME_MIN_PERCENT,
);

echo sprintf(
    "Full src/:   %.2f%% (%d/%d statements, min %.0f%%)\n",
    $src['percent'],
    $src['covered'],
    $src['statements'],
    SRC_MIN_PERCENT,
);

if ($failures !== []) {
    fwrite(STDERR, "\nCoverage gate failed:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }

    exit(1);
}

echo "\nCoverage gates passed.\n";

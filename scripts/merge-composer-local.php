<?php

declare(strict_types=1);

$root = $argv[1] ?? null;

if ($root === null || ! is_dir($root)) {
    fwrite(STDERR, "Usage: php merge-composer-local.php <package-root>\n");
    exit(1);
}

$composerPath = $root.'/composer.json';
$localPath = $root.'/composer.local.json';
$outPath = $root.'/composer.integration.json';

if (! is_file($composerPath) || ! is_file($localPath)) {
    fwrite(STDERR, "Missing composer.json or composer.local.json in {$root}\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
$local = json_decode((string) file_get_contents($localPath), true, 512, JSON_THROW_ON_ERROR);

$composer['repositories'] = array_merge(
    $local['repositories'] ?? [],
    $composer['repositories'] ?? [],
);

file_put_contents(
    $outPath,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
);

fwrite(STDOUT, "Wrote {$outPath}\n");

<?php

$path = $argv[1] ?? '/tmp/coverage-unit.xml';
$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "missing {$path}\n");
    exit(1);
}

$files = [];
$collect = static function ($file) use (&$files): void {
    $path = (string) $file['name'];
    $lines = [];
    foreach ($file->line as $line) {
        if ((string) $line['type'] !== 'stmt') {
            continue;
        }
        if ((int) $line['count'] > 0) {
            continue;
        }
        $lines[] = (int) $line['num'];
    }
    if ($lines !== []) {
        $files[$path] = $lines;
    }
};

foreach ($xml->project->file as $file) {
    $collect($file);
}
foreach ($xml->project->package as $pkg) {
    foreach ($pkg->file as $file) {
        $collect($file);
    }
}

ksort($files);
foreach ($files as $path => $lines) {
    echo basename(dirname($path)).'/'.basename($path).' : '.implode(',', $lines)."\n";
}
echo 'FILES='.count($files)."\n";

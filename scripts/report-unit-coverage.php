<?php

$path = $argv[1] ?? '/tmp/coverage-unit.xml';
$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "missing {$path}\n");
    exit(1);
}

$m = $xml->project->metrics;
$statements = max(1, (int) $m['statements']);
$covered = (int) $m['coveredstatements'];
echo sprintf(
    "TOTAL lines %d/%d (%.2f%%) methods %d/%d files %d classes %d\n",
    $covered,
    (int) $m['statements'],
    100 * $covered / $statements,
    (int) $m['coveredmethods'],
    (int) $m['methods'],
    (int) $m['files'],
    (int) $m['classes'],
);

$uncoveredFiles = [];
foreach ($xml->project->file as $file) {
    $path = (string) $file['name'];
    $fm = $file->metrics;
    $st = (int) $fm['statements'];
    $cs = (int) $fm['coveredstatements'];
    if ($st === 0) {
        continue;
    }
    if ($cs < $st) {
        $uncoveredFiles[] = sprintf(
            '%5.1f%% %d/%d %s',
            100 * $cs / $st,
            $cs,
            $st,
            $path
        );
    }
}

foreach ($xml->project->package as $pkg) {
    foreach ($pkg->file as $file) {
        $path = (string) $file['name'];
        $fm = $file->metrics;
        $st = (int) $fm['statements'];
        $cs = (int) $fm['coveredstatements'];
        if ($st === 0) {
            continue;
        }
        if ($cs < $st) {
            $uncoveredFiles[] = sprintf(
                '%5.1f%% %d/%d %s',
                100 * $cs / $st,
                $cs,
                $st,
                $path
            );
        }
    }
}

sort($uncoveredFiles);
echo "UNCOVERED_FILES=".count($uncoveredFiles)."\n";
foreach ($uncoveredFiles as $line) {
    echo $line."\n";
}

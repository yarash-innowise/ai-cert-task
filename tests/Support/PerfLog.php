<?php

declare(strict_types=1);

namespace Tests\Support;

/** Appends one measurement per line to var/perf/results.jsonl (ignored by Git) so a perf run leaves numbers behind. */
final class PerfLog
{
    public static function record(string $label, float $seconds, string $note = ''): void
    {
        $directory = __DIR__ . '/../../var/perf';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }
        @file_put_contents(
            $directory . '/results.jsonl',
            json_encode(['label' => $label, 'seconds' => round($seconds, 4), 'note' => $note, 'at' => gmdate('c')], JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }
}

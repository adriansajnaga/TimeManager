<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * Ostatnie błędy z dziennika Laravela (storage/logs) — dla administratora, gdy na hostingu nie ma terminala.
 */
final class ErrorLog
{
    /** Czytamy tylko koniec pliku — dziennik bywa duży. */
    private const TAIL_BYTES = 2_000_000;

    /**
     * @return list<array{time: string, level: string, message: string, trace: string}>
     */
    public static function latest(int $limit = 20): array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $entries = [];

        foreach (array_slice($files, 0, 3) as $file) {
            foreach (array_reverse(self::parse(self::tail($file))) as $entry) {
                if (in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                    $entries[] = $entry;
                }

                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    private static function tail(string $file): string
    {
        $size = (int) File::size($file);
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @return list<array{time: string, level: string, message: string, trace: string}>
     */
    private static function parse(string $content): array
    {
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $content) ?: [];
        $entries = [];

        foreach ($parts as $part) {
            if (preg_match('/^\[([^\]]+)\]\s+\w+\.(\w+):\s*(.*)$/s', $part, $match) !== 1) {
                continue;
            }

            $lines = explode("\n", trim($match[3]));
            $entries[] = [
                'time' => $match[1],
                'level' => strtoupper($match[2]),
                'message' => mb_substr((string) array_shift($lines), 0, 1000),
                'trace' => implode("\n", array_slice($lines, 0, 25)),
            ];
        }

        return $entries;
    }
}

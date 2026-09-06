<?php

namespace Abhishek\Yardmaster\Support;

use Throwable;

/**
 * Groups failures that are really the same failure.
 *
 * A bad deploy produces forty thousand near-identical failed jobs. Rendering
 * that as forty thousand rows is technically accurate and operationally
 * useless: what an operator needs is "one issue, 40,112 occurrences, first seen
 * at 14:02", and the ability to retry the whole cluster at once.
 *
 * Three things identify an issue: the exception class, its message with the
 * varying parts normalised out, and the first application stack frame. The last
 * one matters — the same exception class thrown from two places is two
 * different problems, and merging them hides one of them.
 */
final class Fingerprint
{
    /**
     * Replaced in order, so the specific patterns win before the general
     * number rule eats their digits.
     */
    private const PATTERNS = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
        '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/' => '{email}',
        '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '{ip}',
        '/\b0x[0-9a-f]+\b/i' => '{hex}',
        '/\b[0-9a-f]{32,}\b/i' => '{hash}',
        '/https?:\/\/\S+/i' => '{url}',
        '/"[^"]*"/' => '"{}"',
        "/'[^']*'/" => "'{}'",
        '/\b\d[\d,_.]*\b/' => '{n}',
    ];

    public static function for(string $exceptionClass, ?string $message, ?string $frame): string
    {
        return md5(implode('|', [
            $exceptionClass,
            self::normalise($message ?? ''),
            $frame ?? '',
        ]));
    }

    /**
     * Strip the parts of a message that vary between otherwise identical
     * failures — ids, addresses, quoted values, timings.
     */
    public static function normalise(string $message): string
    {
        $message = trim($message);

        foreach (self::PATTERNS as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        // Long messages are usually a stack trace or a dumped payload glued on
        // the end; the first line is what identifies the failure.
        $message = trim((string) strtok($message, "\n"));

        return mb_substr($message, 0, 500);
    }

    /**
     * The first frame inside the application, as a short `file:line`.
     *
     * Vendor frames are skipped: "somewhere in Guzzle" is true of thousands of
     * unrelated failures, so grouping on it would merge them all into one
     * meaningless issue.
     */
    public static function frame(Throwable $exception, string $basePath = ''): string
    {
        $basePath = rtrim($basePath, '/');

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || str_contains($file, '/vendor/')) {
                continue;
            }

            if ($basePath !== '' && ! str_starts_with($file, $basePath)) {
                continue;
            }

            return self::shorten($file, $basePath).':'.((int) ($frame['line'] ?? 0));
        }

        // Nothing of the application in the trace — thrown from inside a
        // package. Where it was raised is still better than nothing.
        return self::shorten($exception->getFile(), $basePath).':'.$exception->getLine();
    }

    private static function shorten(string $file, string $basePath): string
    {
        if ($basePath !== '' && str_starts_with($file, $basePath)) {
            return ltrim(substr($file, strlen($basePath)), '/');
        }

        // Outside the application root — a symlinked release path, or a job
        // defined in a package. An absolute path leaks the deploy layout onto a
        // screen a whole team looks at, and the tail is the part that identifies
        // the code anyway.
        $segments = explode('/', trim($file, '/'));

        return count($segments) <= 3
            ? $file
            : '…/'.implode('/', array_slice($segments, -3));
    }
}

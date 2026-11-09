<?php

declare(strict_types=1);

namespace ArchitectureCheck\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

use function array_map;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;

/**
 * Runs `mago analyze` with the extension on a copy of the test application, with extra files
 * and mapping changes applied.
 */
final readonly class Analysis
{
    private const string APPLICATION = __DIR__ . '/testdata/application';

    /**
     * @param list<array{code: string, file: string, rule: string}> $issues
     */
    private function __construct(
        public int $exitCode,
        public array $issues,
        public string $errors,
    ) {}

    /**
     * @param array<string, string> $files relative path => source
     * @param array<string, mixed> $mapping keys replaced in the test application's architecture.json
     */
    public static function run(array $files = [], array $mapping = []): self
    {
        $root = tempnam(sys_get_temp_dir(), 'architecture-check-');
        if ($root === false || $root === '') {
            throw new RuntimeException('cannot create a temporary directory');
        }
        unlink($root);
        try {
            self::copy(self::APPLICATION, $root);
            foreach ($files as $path => $source) {
                if (!is_dir(dirname("{$root}/{$path}"))) {
                    mkdir(dirname("{$root}/{$path}"), recursive: true);
                }
                file_put_contents("{$root}/{$path}", $source);
            }
            if ($mapping !== []) {
                file_put_contents("{$root}/architecture.json", json_encode([...self::mapping($root), ...$mapping], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $worker = json_encode([PHP_BINARY, dirname(__DIR__) . '/bin/architecture-worker'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents("{$root}/mago.toml", <<<TOML
                php-version = "8.5"

                [source]
                paths = ["src"]

                [extension-hosts.architecture]
                command = {$worker}

                [analyzer]
                minimum-fail-level = "warning"
                TOML);

            return self::analyze($root);
        } finally {
            self::remove($root);
        }
    }

    /**
     * @param non-empty-string $root
     * @return array<array-key, mixed>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until checked.
     */
    private static function mapping(string $root): array
    {
        $mapping = json_decode((string) \file_get_contents("{$root}/architecture.json"), true, flags: JSON_THROW_ON_ERROR);

        return is_array($mapping) ? $mapping : [];
    }

    /** @param non-empty-string $root */
    private static function analyze(string $root): self
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/mago', 'analyze', '--reporting-format=json'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if ($process === false || !isset($pipes[1], $pipes[2])) {
            throw new RuntimeException('cannot start mago');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        return new self($exitCode, self::issues($output), $errors . $output);
    }

    /**
     * @return list<array{code: string, file: string, rule: string}>
     *
     * @mago-expect analysis:mixed-assignment The JSON report is `mixed` until read.
     */
    private static function issues(string $output): array
    {
        $report = json_decode($output, true);
        $issues = [];
        foreach (is_array($report) && is_array($report['issues'] ?? null) ? $report['issues'] : [] as $issue) {
            $issue = is_array($issue) ? $issue : [];
            $annotations = is_array($issue['annotations'] ?? null) ? $issue['annotations'] : [];
            $annotation = is_array($annotations[0] ?? null) ? $annotations[0] : [];
            $span = is_array($annotation['span'] ?? null) ? $annotation['span'] : [];
            $fileId = is_array($span['file_id'] ?? null) ? $span['file_id'] : [];
            $notes = is_array($issue['notes'] ?? null) ? $issue['notes'] : [];
            $issues[] = [
                'code' => is_string($issue['code'] ?? null) ? $issue['code'] : '',
                'file' => is_string($fileId['name'] ?? null) ? $fileId['name'] : '',
                'rule' => is_string($notes[0] ?? null) ? $notes[0] : '',
            ];
        }

        return $issues;
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, recursive: true);
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $path => $entry) {
            $target = $to . \substr($path, \strlen($from));
            $entry->isDir() ? mkdir($target) : \copy($path, $target);
        }
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    /** @return list<string> */
    public function summary(): array
    {
        return array_map(static fn(array $issue): string => "{$issue['code']} {$issue['file']}: {$issue['rule']}", $this->issues);
    }
}

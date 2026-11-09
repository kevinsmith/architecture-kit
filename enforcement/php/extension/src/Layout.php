<?php

declare(strict_types=1);

namespace ArchitectureCheck;

use InvalidArgumentException;
use JsonException;

use function array_diff;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function strcasecmp;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;

use const JSON_THROW_ON_ERROR;

/**
 * The application's architecture mapping, read from its `architecture.json`.
 */
final readonly class Layout
{
    private const string SEGMENT = '~^[A-Za-z_][A-Za-z0-9_]*$~';

    private const array KEYS = ['root', 'modules', 'shared', 'composition', 'datasets', 'transaction_methods', 'external_effects', 'external_consumers'];

    /** @var list<string> */
    private array $rootSegments;

    /** @var array<string, string> lower-case table => owning module */
    private array $owners;

    /**
     * @param string $root namespace that holds all production code, such as `App`
     * @param list<string> $modules business modules, each a namespace directly under $root
     * @param string $shared Shared Infrastructure namespace under $root
     * @param string $composition composition namespace under $root
     * @param array<string, string> $owners lower-case table => owning module (R09)
     * @param list<string> $transactions `Class::method` whose closure arguments run inside one transaction (R13)
     * @param list<string> $effects class-likes and functions that perform external effects (R13)
     * @param array<string, string> $externalConsumers published class-like => reviewed reason its consumer is outside the analyzed code (R05)
     */
    private function __construct(
        public string $root,
        public array $modules,
        public string $shared,
        public string $composition,
        array $owners,
        public array $transactions,
        public array $effects,
        public array $externalConsumers,
    ) {
        $this->rootSegments = explode('\\', $root);
        $this->owners = $owners;
    }

    /** @throws InvalidArgumentException when the file is missing or invalid */
    public static function fromFile(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException("{$path}: cannot read the architecture mapping");
        }
        try {
            return self::fromJson($json);
        } catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException("{$path}: {$error->getMessage()}", previous: $error);
        }
    }

    /**
     * @throws InvalidArgumentException when the mapping is invalid
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until validated.
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException("invalid JSON: {$error->getMessage()}", previous: $error);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('expected an object');
        }
        $unknown = array_diff(array_keys($data), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('unknown keys: ' . implode(', ', $unknown));
        }

        $root = self::rootNamespace($data, 'root');
        $shared = self::segment($data, 'shared');
        $composition = self::segment($data, 'composition');
        $modules = self::strings($data, 'modules', required: true);
        if ($modules === []) {
            throw new InvalidArgumentException('`modules` lists no modules');
        }
        $names = [];
        foreach ([...$modules, $shared, $composition] as $name) {
            if (preg_match(self::SEGMENT, $name) !== 1) {
                throw new InvalidArgumentException("`{$name}` is not a namespace segment");
            }
            if (in_array(strtolower($name), $names, true)) {
                throw new InvalidArgumentException("`{$name}` is declared twice among modules, shared, and composition");
            }
            $names[] = strtolower($name);
        }

        $owners = [];
        foreach (self::map($data, 'datasets') as $module => $tables) {
            if (!in_array($module, $modules, true)) {
                throw new InvalidArgumentException("`datasets` names undeclared module `{$module}`");
            }
            foreach (self::list($tables, "datasets.{$module}") as $table) {
                $key = strtolower($table);
                if (isset($owners[$key])) {
                    throw new InvalidArgumentException("table `{$table}` has two owners: {$owners[$key]} and {$module}");
                }
                $owners[$key] = $module;
            }
        }

        $transactions = self::strings($data, 'transaction_methods');
        foreach ($transactions as $transaction) {
            [$class, $method] = explode('::', $transaction, 2) + ['', ''];
            if (substr_count($transaction, '::') !== 1 || $class === '' || $method === '') {
                throw new InvalidArgumentException("`transaction_methods` entry `{$transaction}` is not `Class::method`");
            }
        }

        $consumers = [];
        foreach (self::map($data, 'external_consumers') as $class => $reason) {
            if (!is_string($reason) || $reason === '') {
                throw new InvalidArgumentException("`external_consumers.{$class}` needs the reviewed reason as a string");
            }
            $consumers[ltrim($class, '\\')] = $reason;
        }

        return new self($root, $modules, $shared, $composition, $owners, $transactions, self::strings($data, 'external_effects'), $consumers);
    }

    /**
     * Classifies a namespace or symbol name. Returns null outside the application and for
     * unclassified namespaces, which the guard already rejects (R02).
     */
    public function place(string $name): ?Place
    {
        if (!$this->contains($name)) {
            return null;
        }
        $segments = array_slice(explode('\\', ltrim($name, '\\')), count($this->rootSegments));
        $first = $segments[0] ?? '';
        if (strcasecmp($first, $this->shared) === 0) {
            return new Place($this->shared, Role::Shared);
        }
        if (strcasecmp($first, $this->composition) === 0) {
            return new Place($this->composition, Role::Composition);
        }
        foreach ($this->modules as $module) {
            if (strcasecmp($first, $module) !== 0) {
                continue;
            }
            foreach (Role::MODULE_ROLES as $role) {
                if (strcasecmp($segments[1] ?? '', $role->value) === 0) {
                    return new Place($module, $role);
                }
            }
        }

        return null;
    }

    /** Whether a name lies under the application's root namespace. */
    public function contains(string $name): bool
    {
        $segments = explode('\\', ltrim($name, '\\'));
        if (count($segments) <= count($this->rootSegments)) {
            return false;
        }
        foreach ($this->rootSegments as $index => $segment) {
            if (strcasecmp($segments[$index], $segment) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Returns the module that owns a table, ignoring a schema qualifier; null when undeclared. */
    public function owner(string $table): ?string
    {
        $key = strtolower($table);
        $dot = strrpos($key, '.');

        return $this->owners[$key] ?? ($dot === false ? null : $this->owners[substr($key, $dot + 1)] ?? null);
    }

    public function hasDatasets(): bool
    {
        return $this->owners !== [];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until validated.
     */
    private static function rootNamespace(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("`{$key}` must be a string");
        }
        foreach (explode('\\', ltrim($value, '\\')) as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new InvalidArgumentException("`{$key}` is not a namespace: `{$value}`");
            }
        }

        return ltrim($value, '\\');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until validated.
     */
    private static function segment(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("`{$key}` must be a string");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string>
     */
    private static function strings(array $data, string $key, bool $required = false): array
    {
        if (!array_key_exists($key, $data)) {
            return $required ? throw new InvalidArgumentException("`{$key}` is required") : [];
        }

        return self::list($data[$key], $key);
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until validated.
     */
    private static function list(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("`{$key}` must be a list of strings");
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException("`{$key}` must be a list of non-empty strings");
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is `mixed` until validated.
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("`{$key}` must be an object");
        }
        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace Smv;

use Smv\Http\HttpException;

/**
 * Schlanke Eingabevalidierung. Fehler werden im Format des bisherigen FastAPI-Backends
 * gesammelt: 422 {"detail": [{"type", "loc", "msg"}, ...]}.
 */
final class Validator
{
    /** @var list<array<string, mixed>> */
    private array $errors = [];

    /** @param array<string, mixed> $input */
    public function __construct(private readonly array $input, private readonly string $where = 'body')
    {
    }

    private function fail(string $key, string $type, string $msg): void
    {
        $this->errors[] = ['type' => $type, 'loc' => [$this->where, $key], 'msg' => $msg];
    }

    private function has(string $key): bool
    {
        return array_key_exists($key, $this->input) && $this->input[$key] !== null;
    }

    public function string(string $key, int $min = 0, ?int $max = null, bool $required = true): ?string
    {
        if (!$this->has($key)) {
            if ($required) {
                $this->fail($key, 'missing', 'Field required');
            }
            return null;
        }
        $v = $this->input[$key];
        if (!is_string($v)) {
            $this->fail($key, 'string_type', 'Input should be a valid string');
            return null;
        }
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min) {
            $this->fail($key, 'string_too_short', "String should have at least $min character" . ($min === 1 ? '' : 's'));
            return null;
        }
        if ($max !== null && $len > $max) {
            $this->fail($key, 'string_too_long', "String should have at most $max characters");
            return null;
        }
        return $v;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->input)) {
            return $default;
        }
        if (!is_bool($this->input[$key])) {
            $this->fail($key, 'bool_type', 'Input should be a valid boolean');
            return $default;
        }
        return $this->input[$key];
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $key, string $enum, bool $required = false, ?\BackedEnum $default = null, bool $nullable = false): ?\BackedEnum
    {
        if (!array_key_exists($key, $this->input) || ($this->input[$key] === null && !$nullable)) {
            if ($required) {
                $this->fail($key, 'missing', 'Field required');
                return null;
            }
            return $default;
        }
        if ($this->input[$key] === null) {
            return null; // ausdrücklich erlaubtes null
        }
        $v = $this->input[$key];
        $case = is_string($v) ? $enum::tryFrom($v) : null;
        if ($case === null) {
            $allowed = array_map(static fn (string $s): string => "'$s'", $enum::values());
            $last = array_pop($allowed);
            $list = $allowed === [] ? $last : implode(', ', $allowed) . ' or ' . $last;
            $this->fail($key, 'enum', "Input should be $list");
        }
        return $case;
    }

    /** @return list<string>|null */
    public function stringList(string $key): ?array
    {
        if (!$this->has($key)) {
            return null;
        }
        $v = $this->input[$key];
        if (!is_array($v) || !array_is_list($v)) {
            $this->fail($key, 'list_type', 'Input should be a valid list');
            return null;
        }
        foreach ($v as $i => $item) {
            if (!is_string($item)) {
                $this->errors[] = ['type' => 'string_type', 'loc' => [$this->where, $key, $i], 'msg' => 'Input should be a valid string'];
                return null;
            }
        }
        return $v;
    }

    public function int(string $key, int $default, int $min = PHP_INT_MIN): int
    {
        if (!array_key_exists($key, $this->input)) {
            return $default;
        }
        $v = $this->input[$key];
        if (!is_string($v) || preg_match('/^[+-]?\d{1,15}$/', $v) !== 1) {
            $this->fail($key, 'int_parsing', 'Input should be a valid integer, unable to parse string as an integer');
            return $default;
        }
        $n = (int) $v;
        if ($n < $min) {
            $this->fail($key, 'greater_than_equal', "Input should be greater than or equal to $min");
            return $default;
        }
        return $n;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw new HttpException(422, $this->errors);
        }
    }
}

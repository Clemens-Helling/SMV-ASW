<?php

declare(strict_types=1);

namespace Smv\Http;

final class Request
{
    public const MAX_BODY_BYTES = 1_048_576;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers Schlüssel in Kleinbuchstaben
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $query = [];
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Anfrage zu groß');
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) && $path !== '' ? $path : '/',
            $query,
            $headers,
            $body,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

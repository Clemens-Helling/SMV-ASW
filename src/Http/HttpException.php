<?php

declare(strict_types=1);

namespace Smv\Http;

/** Fehler mit HTTP-Status; wird als {"detail": ...} ausgegeben (wie bisher im FastAPI-Backend). */
class HttpException extends \RuntimeException
{
    /**
     * @param string|list<array<string, mixed>> $detail
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string|array $detail,
        public readonly array $headers = [],
    ) {
        parent::__construct(is_string($detail) ? $detail : 'Validation error', $status);
    }

    public function toResponse(): Response
    {
        return Response::json(['detail' => $this->detail], $this->status, $this->headers);
    }
}

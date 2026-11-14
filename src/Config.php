<?php

declare(strict_types=1);

namespace Smv;

final class Config
{
    public const DATABASE_NAME = 'SMV';
    public const ACCESS_TOKEN_EXPIRE_MINUTES = 30;

    /** Platzhalter aus dem alten Backend – darf nie als Signaturschlüssel dienen. */
    private const PLACEHOLDER_SECRET = 'your-secret-key-hier-ersetzen';

    public function __construct(
        private readonly ?string $secretKey,
        private readonly ?string $mongodbUrl,
        public readonly string $staticDir,
        public readonly bool $useMemoryStore = false,
    ) {
    }

    public static function fromEnv(string $projectRoot): self
    {
        $env = static fn (string $name): ?string => (($v = getenv($name)) !== false && $v !== '') ? $v : null;

        // In-Memory-Store nur, wenn ausdrücklich für Tests angefordert – nie stillschweigend in Produktion.
        $memory = $env('APP_ENV') === 'testing' && $env('STORE') === 'memory';

        return new self($env('SECRET_KEY'), $env('MONGODB_URL'), $projectRoot . '/static', $memory);
    }

    /** Fail-closed: ohne echten Schlüssel werden keine Tokens ausgestellt oder akzeptiert. */
    public function secretKey(): string
    {
        if ($this->secretKey === null || $this->secretKey === self::PLACEHOLDER_SECRET || strlen($this->secretKey) < 16) {
            throw new \RuntimeException('SECRET_KEY ist nicht gesetzt oder zu kurz (mindestens 16 Zeichen).');
        }
        return $this->secretKey;
    }

    public function mongodbUrl(): string
    {
        if ($this->mongodbUrl === null) {
            throw new \RuntimeException('MONGODB_URL ist nicht gesetzt.');
        }
        return $this->mongodbUrl;
    }
}

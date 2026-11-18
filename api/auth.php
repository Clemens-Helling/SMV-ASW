<?php
require_once __DIR__ . '/config.php';

// ── JWT-Hilfsfunktionen (reines PHP, keine externe Bibliothek) ────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode(array $payload, string $key): string {
    $header        = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $encodedPayload = base64url_encode(json_encode($payload));
    $signature     = base64url_encode(hash_hmac('sha256', "$header.$encodedPayload", $key, true));
    return "$header.$encodedPayload.$signature";
}

/**
 * Dekodiert und verifiziert ein JWT.
 * Gibt das Payload-Array zurück oder null bei Fehler / abgelaufenem Token.
 */
function jwt_decode(string $token, string $key): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", $key, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $decoded = json_decode(base64url_decode($payload), true);
    if (!is_array($decoded)) {
        return null;
    }
    if (isset($decoded['exp']) && $decoded['exp'] < (time() - 60)) {
        // 60-Sekunden-Toleranz für geringe Zeitabweichungen zwischen Servern
        return null;
    }
    return $decoded;
}

function create_access_token(string $username): string {
    $payload = [
        'sub' => $username,
        'exp' => time() + TOKEN_EXPIRE_MINUTES * 60,
        'iat' => time(),
    ];
    return jwt_encode($payload, SECRET_KEY);
}

// ── Request-Auth-Hilfsfunktionen ──────────────────────────────────────────────

/**
 * Liest den Bearer-Token aus dem Authorization-Header.
 */
function get_bearer_token(): ?string {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        return substr($auth, 7);
    }
    return null;
}

/**
 * Gibt den authentifizierten Benutzer als Array zurück oder sendet eine 401-Antwort.
 */
function require_auth(): array {
    $token = get_bearer_token();
    if (!$token) {
        send_error(401, 'Kein Token gefunden');
    }
    $payload = jwt_decode($token, SECRET_KEY);
    if (!$payload || !isset($payload['sub'])) {
        send_error(401, 'Ungültige oder abgelaufene Anmeldedaten');
    }

    require_once __DIR__ . '/database.php';
    $db   = get_db();
    $stmt = $db->prepare('SELECT id, benutzername, rolle FROM benutzer WHERE benutzername = ?');
    $stmt->execute([$payload['sub']]);
    $user = $stmt->fetch();
    if (!$user) {
        send_error(401, 'Benutzer nicht gefunden');
    }
    return $user;
}

/**
 * Prüft, ob der Benutzer Admin ist. Sendet 403, falls nicht.
 */
function require_admin(array $user): void {
    if ($user['rolle'] !== 'admin') {
        send_error(403, 'Administratorrechte erforderlich');
    }
}

/**
 * Prüft, ob der Benutzer Admin oder Schülersprecher ist. Sendet 403, falls nicht.
 */
function require_admin_or_schuelersprecher(array $user): void {
    if (!in_array($user['rolle'], ['admin', 'schuelersprecher'], true)) {
        send_error(403, 'Administrator- oder Schülersprecherrechte erforderlich');
    }
}

// ── Antwort-Hilfsfunktionen ───────────────────────────────────────────────────

function send_json(mixed $data, int $statusCode = 200): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_error(int $statusCode, string $detail): never {
    send_json(['detail' => $detail], $statusCode);
}

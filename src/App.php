<?php

declare(strict_types=1);

namespace Smv;

use Smv\Domain\NotifyType;
use Smv\Domain\Phase;
use Smv\Domain\Role;
use Smv\Domain\Status;
use Smv\Http\HttpException;
use Smv\Http\Request;
use Smv\Http\Response;
use Smv\Store\Store;

/**
 * SMV Antragssystem – HTTP-API. Endpunkte, Rollen, Fehlertexte und JSON-Felder entsprechen
 * dem bisherigen FastAPI-Backend, damit das Frontend (static/) unverändert weiterläuft.
 */
final class App
{
    /** [Methode, Regex auf den (noch URL-kodierten) Pfad, Handler] */
    private const ROUTES = [
        ['POST', '#^/login$#', 'login'],
        ['GET', '#^/me$#', 'me'],
        ['POST', '#^/benutzer$#', 'createUser'],
        ['POST', '#^/antraege$#', 'createAntrag'],
        ['GET', '#^/antraege$#', 'listAntraege'],
        ['GET', '#^/antraege/([^/]+)$#', 'getAntrag'],
        ['PUT', '#^/antraege/([^/]+)$#', 'updateAntrag'],
        ['DELETE', '#^/antraege/([^/]+)$#', 'deleteAntrag'],
        ['GET', '#^/tags$#', 'listTags'],
        ['POST', '#^/tags$#', 'addTag'],
        ['DELETE', '#^/tags/([^/]+)$#', 'deleteTag'],
        ['GET', '#^/statistiken$#', 'stats'],
        ['GET', '#^/health$#', 'health'],
    ];

    private ?Store $store = null;

    /** @param \Closure(): Store $storeFactory wird erst beim ersten Datenbankzugriff aufgerufen */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $storeFactory,
    ) {
    }

    private function store(): Store
    {
        return $this->store ??= ($this->storeFactory)();
    }

    // ---------------------------------------------------------------- Einstieg

    public function handle(Request $request): Response
    {
        try {
            $response = $request->method === 'OPTIONS' ? $this->preflight($request) : $this->dispatch($request);
        } catch (HttpException $e) {
            $response = $e->toResponse();
        } catch (\Throwable $e) {
            error_log(sprintf('[smv] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $isDb = str_starts_with($e::class, 'MongoDB\\');
            $response = Response::json(
                ['detail' => $isDb ? 'Datenbank nicht erreichbar' : 'Internal Server Error'],
                $isDb ? 503 : 500,
            );
        }
        return $response->withHeaders(['Access-Control-Allow-Origin' => '*']);
    }

    private function preflight(Request $r): Response
    {
        $requested = $r->header('access-control-request-headers');
        return new Response(200, 'OK', [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Access-Control-Allow-Methods' => 'DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT',
            'Access-Control-Allow-Headers' => $requested ?? 'Authorization, Content-Type',
            'Access-Control-Max-Age' => '600',
        ]);
    }

    private function dispatch(Request $r): Response
    {
        $path = $r->path;

        if ($path === '/sat' || str_starts_with($path, '/sat/')) {
            return $this->serveStatic($r);
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $method = $r->method === 'HEAD' ? 'GET' : $r->method;
        $allowed = [];
        foreach (self::ROUTES as [$routeMethod, $regex, $handler]) {
            if (preg_match($regex, $path, $m) !== 1) {
                continue;
            }
            if ($routeMethod === $method) {
                array_shift($m);
                $response = $this->$handler($r, ...array_map('rawurldecode', $m));
                return $r->method === 'HEAD' ? $response->withoutBody() : $response;
            }
            $allowed[] = $routeMethod;
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => implode(', ', $allowed)]);
        }
        throw new HttpException(404, 'Not Found');
    }

    // ---------------------------------------------------------------- Auth

    /** @return array<string, mixed> */
    private function currentUser(Request $r): array
    {
        $header = $r->header('authorization');
        if ($header === null || preg_match('/^Bearer\s+(\S+)$/i', $header, $m) !== 1) {
            throw new HttpException(401, 'Not authenticated', ['WWW-Authenticate' => 'Bearer']);
        }
        $invalid = new HttpException(401, 'Ungültige Anmeldedaten', ['WWW-Authenticate' => 'Bearer']);

        $claims = Jwt::decode($m[1], $this->config->secretKey());
        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw $invalid;
        }
        $user = $this->store()->findUserByName($sub);
        if ($user === null) {
            throw $invalid;
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function adminUser(Request $r): array
    {
        $user = $this->currentUser($r);
        if (Role::fromUser($user) !== Role::Admin) {
            throw new HttpException(403, 'Administratorrechte erforderlich');
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function adminOrSchuelerUser(Request $r): array
    {
        $user = $this->currentUser($r);
        if (!in_array(Role::fromUser($user), [Role::Admin, Role::Schuelersprecher], true)) {
            throw new HttpException(403, 'Administrator- oder Schülersprecherrechte erforderlich');
        }
        return $user;
    }

    /** @return array<string, mixed>|null */
    private function authenticate(string $name, string $password): ?array
    {
        $user = $this->store()->findUserByName($name);
        // Auch bei unbekanntem Benutzer einen Hash prüfen, damit die Antwortzeit nichts verrät.
        static $dummy = null;
        $hash = is_string($user['hashed_password'] ?? null) ? $user['hashed_password'] : ($dummy ??= password_hash('dummy', PASSWORD_BCRYPT, ['cost' => 12]));
        $ok = password_verify($password, $hash);
        return ($user !== null && $ok) ? $user : null;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ---------------------------------------------------------------- Handler: Benutzer

    private function login(Request $r): Response
    {
        $this->config->secretKey(); // fail-closed: ohne gültigen Schlüssel gar nicht erst Passwörter prüfen
        $v = new Validator($this->jsonBody($r));
        $name = $v->string('benutzername');
        $password = $v->string('passwort');
        $v->check();

        $user = $this->authenticate((string) $name, (string) $password);
        if ($user === null) {
            throw new HttpException(401, 'Falscher Benutzername oder Passwort', ['WWW-Authenticate' => 'Bearer']);
        }
        $token = Jwt::encode(
            ['sub' => $user['benutzername'], 'exp' => time() + Config::ACCESS_TOKEN_EXPIRE_MINUTES * 60],
            $this->config->secretKey(),
        );
        return Response::json(['access_token' => $token, 'token_type' => 'bearer']);
    }

    private function me(Request $r): Response
    {
        return Response::json($this->userOut($this->currentUser($r)));
    }

    private function createUser(Request $r): Response
    {
        $this->adminUser($r);
        $v = new Validator($this->jsonBody($r));
        $name = $v->string('benutzername', 3, 50);
        $password = $v->string('passwort', 6);
        $role = $v->enum('rolle', Role::class, false, Role::User);
        $v->check();

        if ($this->store()->findUserByName((string) $name) !== null) {
            throw new HttpException(400, 'Benutzername bereits vergeben');
        }
        $user = [
            'benutzername' => $name,
            'hashed_password' => self::hashPassword((string) $password),
            'rolle' => $role->value,
            'ist_admin' => $role === Role::Admin,
            'erstellt_am' => self::now(),
        ];
        $user['id'] = $this->store()->insertUser($user);
        return Response::json($this->userOut($user));
    }

    // ---------------------------------------------------------------- Handler: Anträge

    private function createAntrag(Request $r): Response
    {
        $v = new Validator($this->jsonBody($r));
        $vorname = $v->string('vorname', 1, 100);
        $nachname = $v->string('nachname', 1, 100);
        $lerngruppe = $v->string('lerngruppe', 1, 100);
        $thema = $v->string('thema', 1, 500);
        $begruendung = $v->string('begründung', 1, 2000);
        $notify = $v->bool('benachrichtigung_gewünscht', true);
        $notifyType = $v->enum('benachrichtigungs_art', NotifyType::class, false, NotifyType::Lerngruppenrat, true);
        $phase = $v->enum('phase', Phase::class, true);
        $v->check();

        $now = self::now();
        $antrag = [
            'vorname' => $vorname,
            'nachname' => $nachname,
            'lerngruppe' => $lerngruppe,
            'thema' => $thema,
            'begründung' => $begruendung,
            'benachrichtigung_gewünscht' => $notify,
            'benachrichtigungs_art' => $notifyType?->value,
            'phase' => $phase->value,
            'status' => Status::Eingereicht->value,
            'tags' => [],
            'erstellt_am' => $now,
            'aktualisiert_am' => $now,
        ];
        $antrag['id'] = $this->store()->insertAntrag($antrag);
        return Response::json($this->antragOut($antrag));
    }

    private function listAntraege(Request $r): Response
    {
        $this->currentUser($r);
        $q = new Validator($r->query, 'query');
        $status = $q->enum('status', Status::class);
        $phase = $q->enum('phase', Phase::class);
        $tag = $q->string('tag', 0, null, false);
        $skip = $q->int('skip', 0, 0);
        $limit = $q->int('limit', 100, 1);
        $q->check();

        $filter = [];
        if ($status !== null) {
            $filter['status'] = $status->value;
        }
        if ($phase !== null) {
            $filter['phase'] = $phase->value;
        }
        if ($tag !== null && $tag !== '') {
            $filter['tag'] = $tag;
        }
        $rows = $this->store()->findAntraege($filter, $skip, min($limit, 1000));
        return Response::json(array_map($this->antragOut(...), $rows));
    }

    private function getAntrag(Request $r, string $id): Response
    {
        $this->currentUser($r);
        return Response::json($this->antragOut($this->requireAntrag($id)));
    }

    private function updateAntrag(Request $r, string $id): Response
    {
        $this->adminOrSchuelerUser($r);
        $v = new Validator($this->jsonBody($r));
        $status = $v->enum('status', Status::class, false, null, true);
        $tags = $v->stringList('tags');
        $v->check();

        $this->requireAntrag($id);
        $set = ['aktualisiert_am' => self::now()];
        if ($status !== null) {
            $set['status'] = $status->value;
        }
        if ($tags !== null) {
            $set['tags'] = $tags;
        }
        $this->store()->updateAntrag($id, $set);
        return Response::json($this->antragOut($this->requireAntrag($id)));
    }

    private function deleteAntrag(Request $r, string $id): Response
    {
        $this->adminUser($r);
        self::assertObjectId($id);
        if (!$this->store()->deleteAntrag($id)) {
            throw new HttpException(404, 'Antrag nicht gefunden');
        }
        return Response::json(['message' => 'Antrag erfolgreich gelöscht']);
    }

    // ---------------------------------------------------------------- Handler: Tags, Statistiken, Health

    private function listTags(Request $r): Response
    {
        $this->currentUser($r);
        return Response::json($this->store()->tagNames());
    }

    private function addTag(Request $r): Response
    {
        $this->adminUser($r);
        $q = new Validator($r->query, 'query');
        $name = $q->string('tag_name', 1);
        $q->check();

        if ($this->store()->tagExists((string) $name)) {
            throw new HttpException(400, 'Tag existiert bereits');
        }
        $this->store()->insertTag((string) $name, self::now());
        return Response::json(['message' => "Tag '$name' hinzugefügt"]);
    }

    private function deleteTag(Request $r, string $name): Response
    {
        $this->adminUser($r);
        $deleted = $this->store()->deleteTag($name);
        $this->store()->pullTagFromAntraege($name, self::now());
        return Response::json(['message' => $deleted ? "Tag '$name' gelöscht" : "Tag '$name' nicht gefunden"]);
    }

    private function stats(Request $r): Response
    {
        $this->currentUser($r);
        return Response::json([
            'total_anträge' => $this->store()->countAntraege(),
            'status_verteilung' => (object) $this->store()->groupCount('status'),
            'phase_verteilung' => (object) $this->store()->groupCount('phase'),
            'verfügbare_tags' => $this->store()->tagNames(),
        ]);
    }

    private function health(Request $r): Response
    {
        try {
            $connected = $this->store()->ping();
        } catch (\Throwable) {
            $connected = false;
        }
        return Response::json([
            'status' => 'OK',
            'timestamp' => self::iso(self::now()),
            'database' => $connected ? 'connected' : 'disconnected',
        ]);
    }

    // ---------------------------------------------------------------- Statische Dateien (/sat)

    private function serveStatic(Request $r): Response
    {
        if (!in_array($r->method, ['GET', 'HEAD'], true)) {
            throw new HttpException(405, 'Method Not Allowed', ['Allow' => 'GET, HEAD']);
        }
        $root = realpath($this->config->staticDir);
        if ($root === false) {
            throw new HttpException(404, 'Not Found');
        }
        $relative = rawurldecode(substr($r->path, strlen('/sat')));
        if (str_contains($relative, "\0") || str_contains($relative, '\\')) {
            throw new HttpException(404, 'Not Found');
        }
        $relative = ltrim($relative, '/');
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..' || ($segment !== '' && $segment[0] === '.')) {
                throw new HttpException(404, 'Not Found');
            }
        }

        $target = realpath($root . '/' . $relative);
        if ($target === false || ($target !== $root && !str_starts_with($target, $root . DIRECTORY_SEPARATOR))) {
            throw new HttpException(404, 'Not Found');
        }
        if (is_dir($target)) {
            if (!str_ends_with($r->path, '/')) {
                return new Response(307, '', ['Location' => $r->path . '/']);
            }
            $target = $target . '/index.html';
        }
        if (!is_file($target)) {
            throw new HttpException(404, 'Not Found');
        }

        $types = [
            'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'ico' => 'image/x-icon', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8',
            'woff2' => 'font/woff2', 'woff' => 'font/woff',
        ];
        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $body = (string) file_get_contents($target);
        $response = new Response(200, $body, [
            'Content-Type' => $types[$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        return $r->method === 'HEAD' ? $response->withoutBody() : $response;
    }

    // ---------------------------------------------------------------- Hilfsfunktionen

    /** @return array<string, mixed> */
    private function jsonBody(Request $r): array
    {
        if (trim($r->body) === '') {
            throw new HttpException(422, [['type' => 'missing', 'loc' => ['body'], 'msg' => 'Field required']]);
        }
        try {
            $data = json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(422, [['type' => 'json_invalid', 'loc' => ['body'], 'msg' => 'JSON decode error']]);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(422, [[
                'type' => 'model_attributes_type', 'loc' => ['body'],
                'msg' => 'Input should be a valid dictionary or object to extract fields from',
            ]]);
        }
        return $data;
    }

    private static function assertObjectId(string $id): void
    {
        if (preg_match('/^[0-9a-f]{24}$/i', $id) !== 1) {
            throw new HttpException(400, 'Ungültige Antrag-ID');
        }
    }

    /** @return array<string, mixed> */
    private function requireAntrag(string $id): array
    {
        self::assertObjectId($id);
        return $this->store()->findAntrag($id) ?? throw new HttpException(404, 'Antrag nicht gefunden');
    }

    public static function now(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // MongoDB speichert Millisekunden; so stimmen Antwort und spätere Abfrage überein.
        return $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'), intdiv((int) $now->format('u'), 1000) * 1000);
    }

    /** ISO-8601 ohne Zeitzone, wie die bisherige FastAPI-Ausgabe (z. B. 2026-11-14T17:33:00.123000). */
    private static function iso(mixed $date): ?string
    {
        if (!$date instanceof \DateTimeInterface) {
            return null;
        }
        $s = $date->format('Y-m-d\TH:i:s');
        $micro = $date->format('u');
        return $micro === '000000' ? $s : $s . '.' . $micro;
    }

    /**
     * @param array<string, mixed> $u
     * @return array<string, mixed>
     */
    private function userOut(array $u): array
    {
        $role = Role::fromUser($u);
        return [
            '_id' => $u['id'],
            'benutzername' => $u['benutzername'],
            'rolle' => $role->value,
            'ist_admin' => $role === Role::Admin,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function antragOut(array $a): array
    {
        return [
            '_id' => $a['id'],
            'vorname' => $a['vorname'] ?? '',
            'nachname' => $a['nachname'] ?? '',
            'lerngruppe' => $a['lerngruppe'] ?? '',
            'thema' => $a['thema'] ?? '',
            'begründung' => $a['begründung'] ?? '',
            'benachrichtigung_gewünscht' => (bool) ($a['benachrichtigung_gewünscht'] ?? true),
            'benachrichtigungs_art' => $a['benachrichtigungs_art'] ?? null,
            'phase' => $a['phase'] ?? null,
            'status' => $a['status'] ?? Status::Eingereicht->value,
            'tags' => array_values($a['tags'] ?? []),
            'erstellt_am' => self::iso($a['erstellt_am'] ?? null),
            'aktualisiert_am' => self::iso($a['aktualisiert_am'] ?? $a['erstellt_am'] ?? null),
        ];
    }
}

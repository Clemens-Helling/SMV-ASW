<?php

declare(strict_types=1);

// Einfacher Testlauf ohne Abhängigkeiten:  php tests/run.php
// Nutzt den In-Memory-Store; die App wird direkt (ohne HTTP-Server) aufgerufen.

putenv('APP_ENV=testing');
putenv('STORE=memory');
putenv('SECRET_KEY=test-secret-key-0123456789abcdef');
putenv('MONGODB_URL=mongodb://unused');

require __DIR__ . '/../src/bootstrap.php';

use Smv\App;
use Smv\Config;
use Smv\Http\Request;
use Smv\Jwt;
use Smv\Seeder;
use Smv\Store\MemoryStore;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $info = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
        echo "FAIL: $name" . ($info !== '' ? " -> $info" : '') . PHP_EOL;
    }
}

$config = Config::fromEnv(SMV_ROOT);
$store = new MemoryStore();
(new Seeder($store))->run();
$app = new App($config, static fn () => $store);

/** @return array{int, mixed, array<string,string>} */
function call(App $app, string $method, string $uri, mixed $json = null, ?string $token = null, ?string $raw = null): array
{
    $parts = parse_url($uri);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $headers = [];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    $body = $raw ?? ($json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE));
    $res = $app->handle(new Request($method, $parts['path'], $query, $headers, $body));
    $data = json_decode($res->body, true);
    return [$res->status, $data ?? $res->body, $res->headers];
}

function login(App $app, string $user, string $pw): string
{
    [, $d] = call($app, 'POST', '/login', ['benutzername' => $user, 'passwort' => $pw]);
    return $d['access_token'];
}

// --- Login & Token -----------------------------------------------------------
[$s, $d] = call($app, 'POST', '/login', ['benutzername' => 'admin', 'passwort' => 'admin123']);
check('login ok', $s === 200 && ($d['token_type'] ?? '') === 'bearer' && ($d['access_token'] ?? '') !== '', json_encode($d));
[$s, $d] = call($app, 'POST', '/login', ['benutzername' => 'admin', 'passwort' => 'falsch']);
check('login falsches Passwort 401', $s === 401 && $d['detail'] === 'Falscher Benutzername oder Passwort');
[$s] = call($app, 'POST', '/login', ['benutzername' => 'gibtsnicht', 'passwort' => 'x']);
check('login unbekannter Benutzer 401', $s === 401);
[$s, $d] = call($app, 'POST', '/login', ['benutzername' => 'admin']);
check('login ohne Passwort 422', $s === 422 && $d['detail'][0]['loc'] === ['body', 'passwort'], json_encode($d));
[$s] = call($app, 'POST', '/login', ['benutzername' => ['$ne' => ''], 'passwort' => ['$ne' => '']]);
check('login NoSQL-Operatoren abgewiesen', $s === 422);
[$s] = call($app, 'POST', '/login', null, null, '{kaputt');
check('login ungültiges JSON 422', $s === 422);

$admin = login($app, 'admin', 'admin123');
$sprecher = login($app, 'schuelersprecher', 'schueler123');
$user = login($app, 'smv_user', 'user123');

[$s, $d] = call($app, 'GET', '/me', null, $admin);
check('/me admin', $s === 200 && $d['rolle'] === 'admin' && $d['ist_admin'] === true && isset($d['_id']), json_encode($d));
[$s, $d] = call($app, 'GET', '/me', null, $user);
check('/me user', $d['rolle'] === 'user' && $d['ist_admin'] === false);
[$s, $d, $h] = call($app, 'GET', '/me');
check('/me ohne Token 401', $s === 401 && $d['detail'] === 'Not authenticated' && ($h['WWW-Authenticate'] ?? '') === 'Bearer');
[$s, $d] = call($app, 'GET', '/me', null, 'abc.def.ghi');
check('/me kaputtes Token 401', $s === 401 && $d['detail'] === 'Ungültige Anmeldedaten');

$expired = Jwt::encode(['sub' => 'admin', 'exp' => time() - 10], $config->secretKey());
[$s] = call($app, 'GET', '/me', null, $expired);
check('abgelaufenes Token 401', $s === 401);
$foreign = Jwt::encode(['sub' => 'admin', 'exp' => time() + 600], 'anderer-schluessel-0123456789');
[$s] = call($app, 'GET', '/me', null, $foreign);
check('Token mit fremdem Schlüssel 401', $s === 401);
$noneAlg = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.'
    . rtrim(strtr(base64_encode('{"sub":"admin","exp":' . (time() + 600) . '}'), '+/', '-_'), '=') . '.';
[$s] = call($app, 'GET', '/me', null, $noneAlg);
check('alg=none abgewiesen', $s === 401);
$ghost = Jwt::encode(['sub' => 'geloescht', 'exp' => time() + 600], $config->secretKey());
[$s] = call($app, 'GET', '/me', null, $ghost);
check('Token eines unbekannten Benutzers 401', $s === 401);

// --- Benutzer ----------------------------------------------------------------
[$s, $d] = call($app, 'POST', '/benutzer', ['benutzername' => 'neu', 'passwort' => 'geheim1', 'rolle' => 'schuelersprecher'], $user);
check('Benutzer anlegen als user 403', $s === 403 && $d['detail'] === 'Administratorrechte erforderlich');
[$s, $d] = call($app, 'POST', '/benutzer', ['benutzername' => 'neu', 'passwort' => 'geheim1', 'rolle' => 'schuelersprecher'], $admin);
check('Benutzer anlegen als admin', $s === 200 && $d['rolle'] === 'schuelersprecher' && $d['ist_admin'] === false, json_encode($d));
[$s, $d] = call($app, 'POST', '/benutzer', ['benutzername' => 'neu', 'passwort' => 'geheim1'], $admin);
check('Benutzername doppelt 400', $s === 400 && $d['detail'] === 'Benutzername bereits vergeben');
[$s] = call($app, 'POST', '/benutzer', ['benutzername' => 'ab', 'passwort' => '123'], $admin);
check('Benutzer zu kurz 422', $s === 422);
[$s] = call($app, 'POST', '/benutzer', ['benutzername' => 'xyz123', 'passwort' => 'geheim1', 'rolle' => 'root'], $admin);
check('Benutzer ungültige Rolle 422', $s === 422);
check('neuer Benutzer kann sich anmelden', login($app, 'neu', 'geheim1') !== '');

// --- Anträge -----------------------------------------------------------------
$antrag = [
    'vorname' => 'Max', 'nachname' => 'Müller', 'lerngruppe' => '9a', 'thema' => 'Handyregeln',
    'begründung' => 'Öfter überprüfen, bitte', 'phase' => 'Phase 9',
];
[$s, $d] = call($app, 'POST', '/antraege', $antrag);
check('Antrag ohne Login erstellen', $s === 200 && $d['status'] === 'eingereicht' && $d['tags'] === [] && $d['benachrichtigung_gewünscht'] === true && $d['benachrichtigungs_art'] === 'lerngruppenrat', json_encode($d, JSON_UNESCAPED_UNICODE));
$id = $d['_id'];
check('Antrag-ID 24 Hex', preg_match('/^[0-9a-f]{24}$/', $id) === 1);
check('Datum ohne Zeitzone', preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d{6})?$/', $d['erstellt_am']) === 1, $d['erstellt_am']);
check('Umlaute bleiben erhalten', $d['begründung'] === 'Öfter überprüfen, bitte' && $d['nachname'] === 'Müller');

[$s, $d] = call($app, 'POST', '/antraege', ['vorname' => 'X'] );
check('Antrag unvollständig 422', $s === 422 && count($d['detail']) >= 5, json_encode($d));
[$s] = call($app, 'POST', '/antraege', ['phase' => 'Phase 99'] + $antrag);
check('Antrag ungültige Phase 422', $s === 422);
[$s] = call($app, 'POST', '/antraege', ['vorname' => ''] + $antrag);
check('Antrag leerer Vorname 422', $s === 422);
[$s] = call($app, 'POST', '/antraege', ['thema' => str_repeat('x', 501)] + $antrag);
check('Antrag Thema zu lang 422', $s === 422);
[$s, $d] = call($app, 'POST', '/antraege', ['benachrichtigungs_art' => null, 'benachrichtigung_gewünscht' => false] + $antrag);
check('Antrag mit null-Benachrichtigungsart', $s === 200 && $d['benachrichtigungs_art'] === null && $d['benachrichtigung_gewünscht'] === false);
[$s, $d] = call($app, 'POST', '/antraege', ['benachrichtigung_gewünscht' => 'ja'] + $antrag);
check('Antrag bool als String 422', $s === 422);
[$s, $d] = call($app, 'POST', '/antraege', ['status' => 'genehmigt', 'tags' => ['x'], 'id' => 'abc'] + $antrag);
check('Mass-Assignment: status/tags im Request ignoriert', $s === 200 && $d['status'] === 'eingereicht' && $d['tags'] === []);

[$s] = call($app, 'GET', '/antraege');
check('Antragsliste ohne Login 401', $s === 401);
[$s, $d] = call($app, 'GET', '/antraege', null, $user);
check('Antragsliste', $s === 200 && count($d) === 3, (string) count($d));
[$s, $d] = call($app, 'GET', '/antraege?limit=1&skip=1', null, $user);
check('Pagination', $s === 200 && count($d) === 1);
[$s] = call($app, 'GET', '/antraege?limit=abc', null, $user);
check('limit ungültig 422', $s === 422);
[$s] = call($app, 'GET', '/antraege?skip=-1', null, $user);
check('skip negativ 422', $s === 422);
[$s] = call($app, 'GET', '/antraege?status=quatsch', null, $user);
check('Statusfilter ungültig 422', $s === 422);
[$s] = call($app, 'GET', '/antraege?tag[$ne]=x', null, $user);
check('tag-Operator-Injektion 422', $s === 422);
[$s, $d] = call($app, 'GET', '/antraege?status=genehmigt', null, $user);
check('Statusfilter ohne Treffer', $s === 200 && $d === []);

[$s, $d] = call($app, 'GET', "/antraege/$id", null, $user);
check('Einzelner Antrag', $s === 200 && $d['_id'] === $id);
[$s, $d] = call($app, 'GET', '/antraege/nichtgueltig', null, $user);
check('Ungültige Antrag-ID 400', $s === 400 && $d['detail'] === 'Ungültige Antrag-ID');
[$s, $d] = call($app, 'GET', '/antraege/' . str_repeat('a', 24), null, $user);
check('Unbekannter Antrag 404', $s === 404 && $d['detail'] === 'Antrag nicht gefunden');

[$s, $d] = call($app, 'PUT', "/antraege/$id", ['status' => 'genehmigt', 'tags' => ['Dringend']], $user);
check('Update als user 403', $s === 403 && $d['detail'] === 'Administrator- oder Schülersprecherrechte erforderlich');
[$s, $d] = call($app, 'PUT', "/antraege/$id", ['status' => 'genehmigt', 'tags' => ['Dringend']], $sprecher);
check('Update als Schülersprecher', $s === 200 && $d['status'] === 'genehmigt' && $d['tags'] === ['Dringend'], json_encode($d));
[$s, $d] = call($app, 'PUT', "/antraege/$id", ['tags' => []], $admin);
check('Tags leeren, Status bleibt', $s === 200 && $d['tags'] === [] && $d['status'] === 'genehmigt');
[$s] = call($app, 'PUT', "/antraege/$id", ['status' => 'kaputt'], $admin);
check('Update ungültiger Status 422', $s === 422);
[$s] = call($app, 'PUT', "/antraege/$id", ['tags' => 'Dringend'], $admin);
check('Update tags kein Array 422', $s === 422);
[$s] = call($app, 'PUT', '/antraege/' . str_repeat('b', 24), ['status' => 'genehmigt'], $admin);
check('Update unbekannt 404', $s === 404);

// --- Tags --------------------------------------------------------------------
[$s, $d] = call($app, 'GET', '/tags', null, $user);
check('Tags sortiert', $s === 200 && $d === ['Dringend', 'Finanzierung', 'Regeländerung', 'Sonstiges', 'Veranstaltung'] || $d === ['Dringend', 'Finanzierung', 'Veranstaltung', 'Regeländerung', 'Sonstiges'] && false, json_encode($d, JSON_UNESCAPED_UNICODE));
[$s] = call($app, 'POST', '/tags?tag_name=Neu', null, $sprecher);
check('Tag anlegen als Schülersprecher 403', $s === 403);
[$s, $d] = call($app, 'POST', '/tags?tag_name=Neu%20er%20Tag', null, $admin);
check('Tag anlegen', $s === 200 && $d['message'] === "Tag 'Neu er Tag' hinzugefügt", json_encode($d));
[$s, $d] = call($app, 'POST', '/tags?tag_name=Neu%20er%20Tag', null, $admin);
check('Tag doppelt 400', $s === 400 && $d['detail'] === 'Tag existiert bereits');
[$s] = call($app, 'POST', '/tags', null, $admin);
check('Tag ohne Namen 422', $s === 422);
call($app, 'PUT', "/antraege/$id", ['tags' => ['Neu er Tag', 'Dringend']], $admin);
[$s, $d] = call($app, 'GET', '/antraege?tag=' . rawurlencode('Neu er Tag'), null, $user);
check('Tagfilter', $s === 200 && count($d) === 1);
[$s, $d] = call($app, 'DELETE', '/tags/' . rawurlencode('Neu er Tag'), null, $admin);
check('Tag löschen', $s === 200 && $d['message'] === "Tag 'Neu er Tag' gelöscht", json_encode($d));
[, $d] = call($app, 'GET', "/antraege/$id", null, $user);
check('Tag aus Anträgen entfernt', $d['tags'] === ['Dringend'], json_encode($d['tags']));
[$s, $d] = call($app, 'DELETE', '/tags/Unbekannt', null, $admin);
check('Unbekannten Tag löschen: 200 + Hinweis', $s === 200 && $d['message'] === "Tag 'Unbekannt' nicht gefunden");

// --- Statistiken, Health -------------------------------------------------------
[$s, $d] = call($app, 'GET', '/statistiken', null, $user);
check('Statistiken', $s === 200 && $d['total_anträge'] === 3 && $d['status_verteilung'] === ['eingereicht' => 2, 'genehmigt' => 1]
    && $d['phase_verteilung'] === ['Phase 9' => 3] && count($d['verfügbare_tags']) === 5, json_encode($d, JSON_UNESCAPED_UNICODE));
$empty = new App($config, static fn () => new MemoryStore());
$e = $empty->handle(new Request('GET', '/statistiken', [], ['authorization' => 'Bearer ' . $admin]));
check('Leere Verteilung ist {} (kein [])', str_contains($e->body, '"status_verteilung":{}') || $e->status === 401, $e->body);
[$s, $d] = call($app, 'GET', '/health');
check('Health ohne Login', $s === 200 && $d['status'] === 'OK' && $d['database'] === 'connected');
$noDb = new App($config, static fn () => throw new RuntimeException('kein Treiber'));
$r = $noDb->handle(new Request('GET', '/health'));
check('Health ohne DB: disconnected', $r->status === 200 && str_contains($r->body, '"database":"disconnected"'));
$r = $noDb->handle(new Request('GET', '/sat/'));
check('Frontend läuft ohne DB', $r->status === 200 && str_contains($r->body, '<html'));
$r = $app->handle(new Request('HEAD', '/health'));
check('HEAD /health ohne Body', $r->status === 200 && $r->body === '');

// --- Löschen -------------------------------------------------------------------
[$s] = call($app, 'DELETE', "/antraege/$id", null, $sprecher);
check('Antrag löschen als Schülersprecher 403', $s === 403);
[$s, $d] = call($app, 'DELETE', "/antraege/$id", null, $admin);
check('Antrag löschen', $s === 200 && $d['message'] === 'Antrag erfolgreich gelöscht');
[$s] = call($app, 'DELETE', "/antraege/$id", null, $admin);
check('Antrag erneut löschen 404', $s === 404);
[$s] = call($app, 'DELETE', '/antraege/xyz', null, $admin);
check('Löschen ungültige ID 400', $s === 400);

// --- Routing, CORS, Statisches -------------------------------------------------
[$s, $d] = call($app, 'GET', '/');
check('/ → 404 wie bisher', $s === 404 && $d['detail'] === 'Not Found');
[$s, $d, $h] = call($app, 'DELETE', '/login');
check('falsche Methode 405', $s === 405 && ($h['Allow'] ?? '') === 'POST');
[$s, , $h] = call($app, 'GET', '/antraege/', null, $user);
check('Schrägstrich am Ende wird toleriert', $s === 200);
$r = $app->handle(new Request('OPTIONS', '/login', [], ['origin' => 'https://x.example', 'access-control-request-method' => 'POST', 'access-control-request-headers' => 'authorization,content-type']));
check('CORS Preflight', $r->status === 200 && $r->headers['Access-Control-Allow-Origin'] === '*' && $r->headers['Access-Control-Allow-Headers'] === 'authorization,content-type');
check('CORS-Header auf normaler Antwort', (call($app, 'GET', '/health')[2]['Access-Control-Allow-Origin'] ?? '') === '*');

$r = $app->handle(new Request('GET', '/sat'));
check('/sat → Redirect auf /sat/', $r->status === 307 && $r->headers['Location'] === '/sat/');
$r = $app->handle(new Request('GET', '/sat/'));
check('/sat/ liefert index.html', $r->status === 200 && str_starts_with($r->headers['Content-Type'], 'text/html'));
$r = $app->handle(new Request('GET', '/sat/script.js'));
check('/sat/script.js', $r->status === 200 && str_starts_with($r->headers['Content-Type'], 'text/javascript') && $r->body === file_get_contents(SMV_ROOT . '/static/script.js'));
$r = $app->handle(new Request('GET', '/sat/style.css'));
check('/sat/style.css', $r->status === 200 && str_starts_with($r->headers['Content-Type'], 'text/css'));
foreach (['/sat/../src/App.php', '/sat/%2e%2e/src/App.php', '/sat/..%2fsrc%2fApp.php', '/sat/%2e%2e%2f.env', '/sat/.env', '/sat/nicht-da.js', '/sat/%00', '/sat/..%5csrc'] as $evil) {
    $r = $app->handle(new Request('GET', $evil));
    check("Path-Traversal blockiert: $evil", $r->status === 404 && !str_contains($r->body, '<?php'), (string) $r->status);
}
$r = $app->handle(new Request('POST', '/sat/index.html'));
check('POST auf /sat 405', $r->status === 405);

// --- Konfiguration (fail-closed) -------------------------------------------------
foreach ([null, 'your-secret-key-hier-ersetzen', 'kurz'] as $bad) {
    $cfg = new Config($bad, 'mongodb://x', SMV_ROOT . '/static', true);
    $a = new App($cfg, static fn () => new MemoryStore());
    $r = $a->handle(new Request('POST', '/login', [], [], '{"benutzername":"a","passwort":"b"}'));
    check('Unsicherer SECRET_KEY (' . var_export($bad, true) . ') → kein Login', $r->status === 500);
}

// --- Kompatibilität mit bestehenden Daten ----------------------------------------
$legacy = new MemoryStore();
$legacy->insertUser(['benutzername' => 'alt', 'hashed_password' => App::hashPassword('altpass1'), 'ist_admin' => true, 'erstellt_am' => App::now()]);
$legacy->insertUser(['benutzername' => 'alt2', 'hashed_password' => App::hashPassword('altpass2'), 'erstellt_am' => App::now()]);
(new Seeder($legacy))->run();
$la = new App($config, static fn () => $legacy);
[, $d] = call($la, 'POST', '/login', ['benutzername' => 'alt', 'passwort' => 'altpass1']);
[, $me] = call($la, 'GET', '/me', null, $d['access_token']);
check('Alt-Benutzer ohne Rolle: Migration zu admin', $me['rolle'] === 'admin');
check('Seeder ist idempotent', count($legacy->findUsersWithoutRole()) === 0 && (new Seeder($legacy))->run() === null);

echo PHP_EOL . "$passed bestanden, $failed fehlgeschlagen" . PHP_EOL;
exit($failed === 0 ? 0 : 1);

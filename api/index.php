<?php
/**
 * SMV Antragssystem – PHP/MySQL-Backend
 * Haupt-Router: alle API-Anfragen laufen hier ein.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Standard-Benutzer und -Tags anlegen (nur beim ersten Aufruf relevant)
ensure_defaults();

// ── Routing ───────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

// Pfad relativ zu /api bereinigen
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = '/' . ltrim(substr($requestUri, strlen($scriptDir)), '/');

// Pfad-Segmente
$segments = array_values(array_filter(explode('/', $path)));

$resource  = $segments[0] ?? '';
$resourceId = $segments[1] ?? null;

// JSON-Body lesen
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Dispatch ──────────────────────────────────────────────────────────────────

match (true) {

    // POST /login
    $method === 'POST' && $resource === 'login'
        => handle_login($body),

    // GET /me
    $method === 'GET' && $resource === 'me'
        => handle_me(),

    // POST /benutzer
    $method === 'POST' && $resource === 'benutzer' && $resourceId === null
        => handle_benutzer_erstellen($body),

    // POST /antraege
    $method === 'POST' && $resource === 'antraege' && $resourceId === null
        => handle_antrag_erstellen($body),

    // GET /antraege
    $method === 'GET' && $resource === 'antraege' && $resourceId === null
        => handle_antraege_abrufen(),

    // GET /antraege/{id}
    $method === 'GET' && $resource === 'antraege' && $resourceId !== null
        => handle_antrag_abrufen((int)$resourceId),

    // PUT /antraege/{id}
    $method === 'PUT' && $resource === 'antraege' && $resourceId !== null
        => handle_antrag_aktualisieren((int)$resourceId, $body),

    // DELETE /antraege/{id}
    $method === 'DELETE' && $resource === 'antraege' && $resourceId !== null
        => handle_antrag_loeschen((int)$resourceId),

    // GET /tags
    $method === 'GET' && $resource === 'tags' && $resourceId === null
        => handle_tags_abrufen(),

    // POST /tags
    $method === 'POST' && $resource === 'tags' && $resourceId === null
        => handle_tag_hinzufuegen(),

    // DELETE /tags/{name}
    $method === 'DELETE' && $resource === 'tags' && $resourceId !== null
        => handle_tag_loeschen(urldecode($resourceId)),

    // GET /statistiken
    $method === 'GET' && $resource === 'statistiken'
        => handle_statistiken(),

    // GET /sitzungen
    $method === 'GET' && $resource === 'sitzungen' && $resourceId === null
        => handle_sitzungen_abrufen(),

    // POST /sitzungen
    $method === 'POST' && $resource === 'sitzungen' && $resourceId === null
        => handle_sitzung_erstellen($body),

    // DELETE /sitzungen/{id}
    $method === 'DELETE' && $resource === 'sitzungen' && $resourceId !== null
        => handle_sitzung_loeschen((int)$resourceId),

    // GET /health
    $method === 'GET' && $resource === 'health'
        => handle_health(),

    default => send_error(404, 'Endpunkt nicht gefunden'),
};

// ════════════════════════════════════════════════════════════════════════════
// Handler-Funktionen
// ════════════════════════════════════════════════════════════════════════════

// ── Auth ─────────────────────────────────────────────────────────────────────

function handle_login(array $body): never {
    $benutzername = trim($body['benutzername'] ?? '');
    $passwort     = $body['passwort'] ?? '';

    if ($benutzername === '' || $passwort === '') {
        send_error(400, 'Benutzername und Passwort erforderlich');
    }

    $db   = get_db();
    $stmt = $db->prepare('SELECT id, benutzername, hashed_password, rolle FROM benutzer WHERE benutzername = ?');
    $stmt->execute([$benutzername]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($passwort, $user['hashed_password'])) {
        send_error(401, 'Falscher Benutzername oder Passwort');
    }

    $token = create_access_token($user['benutzername']);
    send_json(['access_token' => $token, 'token_type' => 'bearer']);
}

function handle_me(): never {
    $user = require_auth();
    send_json([
        'id'        => $user['id'],
        'benutzername' => $user['benutzername'],
        'rolle'     => $user['rolle'],
        'ist_admin' => $user['rolle'] === 'admin',
    ]);
}

// ── Benutzer ─────────────────────────────────────────────────────────────────

function handle_benutzer_erstellen(array $body): never {
    $current = require_auth();
    require_admin($current);

    $benutzername = trim($body['benutzername'] ?? '');
    $passwort     = $body['passwort'] ?? '';
    $rolle        = $body['rolle'] ?? 'user';

    if (strlen($benutzername) < 3 || strlen($benutzername) > 50) {
        send_error(400, 'Benutzername muss 3–50 Zeichen lang sein');
    }
    if (strlen($passwort) < 6) {
        send_error(400, 'Passwort muss mindestens 6 Zeichen lang sein');
    }
    if (!in_array($rolle, ['admin', 'schuelersprecher', 'user'], true)) {
        send_error(400, 'Ungültige Rolle');
    }

    $db   = get_db();
    $stmt = $db->prepare('SELECT id FROM benutzer WHERE benutzername = ?');
    $stmt->execute([$benutzername]);
    if ($stmt->fetch()) {
        send_error(400, 'Benutzername bereits vergeben');
    }

    $hash = password_hash($passwort, PASSWORD_BCRYPT);
    $ins  = $db->prepare('INSERT INTO benutzer (benutzername, hashed_password, rolle) VALUES (?, ?, ?)');
    $ins->execute([$benutzername, $hash, $rolle]);
    $id = (int)$db->lastInsertId();

    send_json([
        'id'           => $id,
        'benutzername' => $benutzername,
        'rolle'        => $rolle,
        'ist_admin'    => $rolle === 'admin',
    ], 201);
}

// ── Anträge ───────────────────────────────────────────────────────────────────

function handle_antrag_erstellen(array $body): never {
    $required = ['vorname', 'nachname', 'lerngruppe', 'thema', 'begründung', 'phase'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            send_error(400, "Pflichtfeld fehlt: $field");
        }
    }

    $validPhasen = ['Phase 5','Phase 6','Phase 7','Phase 8','Phase 9','Phase 10','Phase 11','Phase 12','Phase 13'];
    if (!in_array($body['phase'], $validPhasen, true)) {
        send_error(400, 'Ungültige Phase');
    }

    $benArt = $body['benachrichtigungs_art'] ?? 'lerngruppenrat';
    if (!in_array($benArt, ['lerngruppenrat', 'texter'], true)) {
        $benArt = 'lerngruppenrat';
    }

    $db  = get_db();
    $sql = 'INSERT INTO antraege
                (vorname, nachname, lerngruppe, thema, begruendung,
                 benachrichtigung_gewuenscht, benachrichtigungs_art, phase, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'eingereicht\')';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $body['vorname'],
        $body['nachname'],
        $body['lerngruppe'],
        $body['thema'],
        $body['begründung'],
        (bool)($body['benachrichtigung_gewünscht'] ?? $body['benachrichtigung_gewuenscht'] ?? true) ? 1 : 0,
        $benArt,
        $body['phase'],
    ]);
    $id = (int)$db->lastInsertId();

    send_json(antrag_by_id($db, $id), 201);
}

function handle_antraege_abrufen(): never {
    $current = require_auth();

    $db     = get_db();
    $where  = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[]  = 'a.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['phase'])) {
        $where[]  = 'a.phase = ?';
        $params[] = $_GET['phase'];
    }
    if (!empty($_GET['tag'])) {
        $where[]  = 'EXISTS (SELECT 1 FROM antrag_tags at WHERE at.antrag_id = a.id AND at.tag_name = ?)';
        $params[] = $_GET['tag'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $limit       = min((int)($_GET['limit'] ?? 100), 500);
    $offset      = max((int)($_GET['skip']  ?? 0), 0);

    $sql  = "SELECT a.*, s.bezeichnung AS sitzung_bezeichnung, s.datum AS sitzung_datum
             FROM antraege a
             LEFT JOIN sitzungen s ON s.id = a.sitzung_id
             $whereClause
             ORDER BY a.erstellt_am DESC
             LIMIT $limit OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = array_map(fn($r) => enrich_antrag($db, $r), $rows);
    send_json($result);
}

function handle_antrag_abrufen(int $id): never {
    $current = require_auth();
    $db      = get_db();
    $antrag  = antrag_by_id($db, $id);
    if (!$antrag) {
        send_error(404, 'Antrag nicht gefunden');
    }
    send_json($antrag);
}

function handle_antrag_aktualisieren(int $id, array $body): never {
    $current = require_auth();
    require_admin_or_schuelersprecher($current);

    $db     = get_db();
    $antrag = antrag_by_id($db, $id);
    if (!$antrag) {
        send_error(404, 'Antrag nicht gefunden');
    }

    $set    = [];
    $params = [];

    if (isset($body['status'])) {
        $valid = ['eingereicht','in_bearbeitung','genehmigt','abgelehnt','zurueckgestellt'];
        if (!in_array($body['status'], $valid, true)) {
            send_error(400, 'Ungültiger Status');
        }
        $set[]    = 'status = ?';
        $params[] = $body['status'];
    }

    // Sitzung setzen (null erlaubt zum Entfernen)
    if (array_key_exists('sitzung_id', $body)) {
        $sitzungId = $body['sitzung_id'] === null ? null : (int)$body['sitzung_id'];
        if ($sitzungId !== null) {
            $chk = $db->prepare('SELECT id FROM sitzungen WHERE id = ?');
            $chk->execute([$sitzungId]);
            if (!$chk->fetch()) {
                send_error(400, 'Sitzung nicht gefunden');
            }
        }
        $set[]    = 'sitzung_id = ?';
        $params[] = $sitzungId;
    }

    if ($set) {
        $params[] = $id;
        $db->prepare('UPDATE antraege SET ' . implode(', ', $set) . ' WHERE id = ?')
           ->execute($params);
    }

    // Tags aktualisieren
    if (isset($body['tags']) && is_array($body['tags'])) {
        $db->prepare('DELETE FROM antrag_tags WHERE antrag_id = ?')->execute([$id]);
        foreach (array_unique($body['tags']) as $tagName) {
            $tagName = trim((string)$tagName);
            if ($tagName === '') {
                continue;
            }
            // Tag anlegen, falls er noch nicht existiert
            $db->prepare('INSERT IGNORE INTO tags (name) VALUES (?)')->execute([$tagName]);
            $db->prepare('INSERT IGNORE INTO antrag_tags (antrag_id, tag_name) VALUES (?, ?)')
               ->execute([$id, $tagName]);
        }
    }

    send_json(antrag_by_id($db, $id));
}

function handle_antrag_loeschen(int $id): never {
    $current = require_auth();
    require_admin($current);

    $db = get_db();
    $stmt = $db->prepare('DELETE FROM antraege WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        send_error(404, 'Antrag nicht gefunden');
    }
    send_json(['message' => 'Antrag erfolgreich gelöscht']);
}

// ── Tags ──────────────────────────────────────────────────────────────────────

function handle_tags_abrufen(): never {
    require_auth();
    $db   = get_db();
    $rows = $db->query('SELECT name FROM tags ORDER BY name')->fetchAll();
    send_json(array_column($rows, 'name'));
}

function handle_tag_hinzufuegen(): never {
    $current = require_auth();
    require_admin($current);

    $tagName = trim($_GET['tag_name'] ?? '');
    if ($tagName === '') {
        send_error(400, 'Tag-Name erforderlich');
    }

    $db   = get_db();
    $stmt = $db->prepare('SELECT id FROM tags WHERE name = ?');
    $stmt->execute([$tagName]);
    if ($stmt->fetch()) {
        send_error(400, 'Tag existiert bereits');
    }

    $db->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$tagName]);
    send_json(['message' => "Tag '$tagName' hinzugefügt"], 201);
}

function handle_tag_loeschen(string $tagName): never {
    $current = require_auth();
    require_admin($current);

    $db   = get_db();
    $stmt = $db->prepare('DELETE FROM tags WHERE name = ?');
    $stmt->execute([$tagName]);
    send_json(['message' => "Tag '$tagName' gelöscht"]);
}

// ── Statistiken ───────────────────────────────────────────────────────────────

function handle_statistiken(): never {
    require_auth();
    $db = get_db();

    $total = (int)$db->query('SELECT COUNT(*) FROM antraege')->fetchColumn();

    $statusRows = $db->query(
        'SELECT status, COUNT(*) AS cnt FROM antraege GROUP BY status ORDER BY status'
    )->fetchAll();
    $statusVerteilung = [];
    foreach ($statusRows as $r) {
        $statusVerteilung[$r['status']] = (int)$r['cnt'];
    }

    $phaseRows = $db->query(
        'SELECT phase, COUNT(*) AS cnt FROM antraege GROUP BY phase ORDER BY phase'
    )->fetchAll();
    $phaseVerteilung = [];
    foreach ($phaseRows as $r) {
        $phaseVerteilung[$r['phase']] = (int)$r['cnt'];
    }

    $tags = $db->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

    send_json([
        'total_anträge'     => $total,
        'status_verteilung' => $statusVerteilung,
        'phase_verteilung'  => $phaseVerteilung,
        'verfügbare_tags'   => array_values($tags),
    ]);
}

// ── Sitzungen ─────────────────────────────────────────────────────────────────

function handle_sitzungen_abrufen(): never {
    require_auth();
    $db   = get_db();
    $rows = $db->query('SELECT id, bezeichnung, datum, erstellt_am FROM sitzungen ORDER BY datum DESC, id DESC')
               ->fetchAll();
    send_json($rows);
}

function handle_sitzung_erstellen(array $body): never {
    $current = require_auth();
    require_admin_or_schuelersprecher($current);

    $bezeichnung = trim($body['bezeichnung'] ?? '');
    if ($bezeichnung === '') {
        send_error(400, 'Bezeichnung der Sitzung erforderlich');
    }
    $datum = isset($body['datum']) && $body['datum'] !== '' ? $body['datum'] : null;

    $db  = get_db();
    $db->prepare('INSERT INTO sitzungen (bezeichnung, datum) VALUES (?, ?)')->execute([$bezeichnung, $datum]);
    $id = (int)$db->lastInsertId();

    $stmt = $db->prepare('SELECT id, bezeichnung, datum, erstellt_am FROM sitzungen WHERE id = ?');
    $stmt->execute([$id]);
    send_json($stmt->fetch(), 201);
}

function handle_sitzung_loeschen(int $id): never {
    $current = require_auth();
    require_admin($current);

    $db   = get_db();
    $stmt = $db->prepare('DELETE FROM sitzungen WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        send_error(404, 'Sitzung nicht gefunden');
    }
    send_json(['message' => 'Sitzung erfolgreich gelöscht']);
}

// ── Health ────────────────────────────────────────────────────────────────────

function handle_health(): never {
    try {
        get_db()->query('SELECT 1');
        $dbStatus = 'connected';
    } catch (Throwable) {
        $dbStatus = 'disconnected';
    }
    send_json([
        'status'    => 'OK',
        'timestamp' => date('c'),
        'database'  => $dbStatus,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// Hilfsfunktionen
// ════════════════════════════════════════════════════════════════════════════

/**
 * Lädt einen einzelnen Antrag inkl. Tags und Sitzungsinfo aus der DB.
 */
function antrag_by_id(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        'SELECT a.*, s.bezeichnung AS sitzung_bezeichnung, s.datum AS sitzung_datum
         FROM antraege a
         LEFT JOIN sitzungen s ON s.id = a.sitzung_id
         WHERE a.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return enrich_antrag($db, $row);
}

/**
 * Fügt einem Antrag-Array die Tag-Liste hinzu und normalisiert Felder.
 */
function enrich_antrag(PDO $db, array $row): array {
    $id   = (int)$row['id'];
    $stmt = $db->prepare('SELECT tag_name FROM antrag_tags WHERE antrag_id = ? ORDER BY tag_name');
    $stmt->execute([$id]);
    $tags = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $sitzung = null;
    if ($row['sitzung_id'] !== null) {
        $sitzung = [
            'id'          => (int)$row['sitzung_id'],
            'bezeichnung' => $row['sitzung_bezeichnung'],
            'datum'       => $row['sitzung_datum'],
        ];
    }

    return [
        'id'                         => $id,
        '_id'                        => $id,  // Kompatibilität mit altem Frontend
        'vorname'                    => $row['vorname'],
        'nachname'                   => $row['nachname'],
        'lerngruppe'                 => $row['lerngruppe'],
        'thema'                      => $row['thema'],
        'begründung'                 => $row['begruendung'],
        'benachrichtigung_gewünscht' => (bool)$row['benachrichtigung_gewuenscht'],
        'benachrichtigungs_art'      => $row['benachrichtigungs_art'],
        'phase'                      => $row['phase'],
        'status'                     => $row['status'],
        'tags'                       => array_values($tags),
        'sitzung_id'                 => $row['sitzung_id'] !== null ? (int)$row['sitzung_id'] : null,
        'sitzung'                    => $sitzung,
        'erstellt_am'                => $row['erstellt_am'],
        'aktualisiert_am'            => $row['aktualisiert_am'],
    ];
}

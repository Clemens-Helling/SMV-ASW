<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Legt Standard-Benutzer und Standard-Tags an, falls sie noch nicht vorhanden sind.
 */
function ensure_defaults(): void {
    $db = get_db();

    $defaults = [
        ['benutzername' => 'admin',             'passwort' => 'admin123',    'rolle' => 'admin'],
        ['benutzername' => 'schuelersprecher',   'passwort' => 'schueler123', 'rolle' => 'schuelersprecher'],
        ['benutzername' => 'smv_user',           'passwort' => 'user123',     'rolle' => 'user'],
    ];

    foreach ($defaults as $u) {
        $stmt = $db->prepare('SELECT id FROM benutzer WHERE benutzername = ?');
        $stmt->execute([$u['benutzername']]);
        if (!$stmt->fetch()) {
            $hash = password_hash($u['passwort'], PASSWORD_BCRYPT);
            $ins  = $db->prepare('INSERT INTO benutzer (benutzername, hashed_password, rolle) VALUES (?, ?, ?)');
            $ins->execute([$u['benutzername'], $hash, $u['rolle']]);
        }
    }

    $standardTags = ['Dringend', 'Finanzierung', 'Veranstaltung', 'Regeländerung', 'Sonstiges'];
    foreach ($standardTags as $tag) {
        $stmt = $db->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$tag]);
        if (!$stmt->fetch()) {
            $db->prepare('INSERT INTO tags (name) VALUES (?)')->execute([$tag]);
        }
    }
}

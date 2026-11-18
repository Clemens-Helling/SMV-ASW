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

    $userCount = (int)$db->query('SELECT COUNT(*) FROM benutzer')->fetchColumn();
    if ($userCount === 0) {
        if (strlen(INITIAL_ADMIN_USERNAME) < 3 || strlen(INITIAL_ADMIN_USERNAME) > 50
            || strlen(INITIAL_ADMIN_PASSWORD) < 12) {
            throw new RuntimeException(
                'Noch kein Benutzer vorhanden. Für die Ersteinrichtung einen 3–50 Zeichen langen '
                . 'INITIAL_ADMIN_USERNAME und ein mindestens 12 Zeichen langes '
                . 'INITIAL_ADMIN_PASSWORD konfigurieren.'
            );
        }
        $hash = password_hash(INITIAL_ADMIN_PASSWORD, PASSWORD_BCRYPT);
        $stmt = $db->prepare(
            'INSERT INTO benutzer (benutzername, hashed_password, rolle) VALUES (?, ?, ?)'
        );
        $stmt->execute([INITIAL_ADMIN_USERNAME, $hash, 'admin']);
    }

    $standardTags = ['Dringend', 'Finanzierung', 'Veranstaltung', 'Regeländerung', 'Sonstiges'];
    foreach ($standardTags as $tag) {
        $db->prepare('INSERT IGNORE INTO tags (name) VALUES (?)')->execute([$tag]);
    }
}

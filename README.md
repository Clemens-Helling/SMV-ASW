# SMV-Antragssystem (PHP/MySQL)

Die Anwendung besteht aus einer statischen Oberfläche, einer PHP-API und einer MySQL-Datenbank.

## Voraussetzungen

- PHP 8.1 oder neuer mit `pdo_mysql`
- MySQL 8.0 oder kompatibles MariaDB
- Apache mit `mod_rewrite` und `.htaccess`-Unterstützung

## Lokal einrichten

1. Datenbank und Tabellen aus `database.sql` in MySQL importieren.
2. `.env.example` als `.env` kopieren und die Zugangsdaten eintragen.
3. Einen geheimen, zufälligen Schlüssel mit `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` erzeugen und als `SECRET_KEY` eintragen.
4. Für den ersten Start `INITIAL_ADMIN_USERNAME` und ein mindestens 12 Zeichen langes `INITIAL_ADMIN_PASSWORD` setzen.
5. Den Projektordner über Apache aufrufen. Beim ersten API-Aufruf wird das Administratorkonto angelegt. Danach die beiden `INITIAL_ADMIN_*`-Werte aus der Serverkonfiguration entfernen.

## Hosting

Den Inhalt des Repositorys in das Web-Verzeichnis der Domain hochladen und dort PHP 8.1+, `pdo_mysql` sowie Apache-Rewrite aktivieren. Die Domain muss auf den Projektordner zeigen, damit `index.php`, `api/` und `static/` gemeinsam erreichbar sind.

Die Werte aus `.env.example` als Umgebungsvariablen beim Hoster hinterlegen oder eine `.env` im Projektordner anlegen. `.env` und `database.sql` sind per `.htaccess` gegen direkten Webzugriff gesperrt. Die Datenbank muss vor dem ersten Aufruf mit `database.sql` eingerichtet sein.

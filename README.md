# SMV Antragssystem

Backend in PHP (≥ 8.3, getestet/Ziel: PHP 8.5) mit MongoDB. Das Frontend liegt in `static/` und wird unter `/sat/` ausgeliefert.

## Konfiguration (Umgebungsvariablen oder `.env`)

| Variable      | Bedeutung                                                              |
|---------------|------------------------------------------------------------------------|
| `MONGODB_URL` | Verbindungs-URL zur MongoDB (Datenbank `SMV`)                           |
| `SECRET_KEY`  | Signaturschlüssel für Login-Tokens, mindestens 16 Zeichen, geheim halten |

Ohne gültigen `SECRET_KEY` verweigert die API den Login (kein Standardschlüssel mehr).

## Lokal starten

Benötigt PHP mit `ext-mongodb` und Composer:

```sh
composer install
php bin/seed.php                 # Standardbenutzer und Tags anlegen (idempotent)
php -S localhost:8000 -t public public/index.php
```

## Tests

```sh
php tests/run.php
```

Läuft ohne Datenbank (In-Memory-Store, `APP_ENV=testing STORE=memory` wird vom Testskript selbst gesetzt).

## Deployment (Docker, z. B. Render)

`Dockerfile` baut PHP 8.5 + Apache mit `ext-mongodb`. Beim Start legt `bin/seed.php` fehlende Standardbenutzer
(`admin`, `schuelersprecher`, `smv_user`) und Standard-Tags an. Die Standardpasswörter sollten nach dem ersten Login geändert werden.

## API

`POST /login`, `GET /me`, `POST /benutzer`, `GET|POST /antraege`, `GET|PUT|DELETE /antraege/{id}`,
`GET|POST /tags`, `DELETE /tags/{name}`, `GET /statistiken`, `GET /health` – unverändert gegenüber dem früheren Python-Backend.

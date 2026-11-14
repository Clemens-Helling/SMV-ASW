#!/bin/sh
set -e
cd /var/www/html

# Entspricht dem früheren FastAPI-Startup-Event: Standardbenutzer/-Tags anlegen (idempotent).
# Ein Fehler (z. B. Datenbank kurz nicht erreichbar) soll den Start nicht verhindern.
php bin/seed.php || echo "Hinweis: Seeding übersprungen (Datenbank nicht erreichbar?)" >&2

exec apache2-foreground

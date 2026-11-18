-- Migration: altes Schema (Python-Backend) -> Schema des PHP-Backends (database.sql)
--
-- Für MariaDB 10.5+ (getestet mit 10.11). VORHER EIN BACKUP/EXPORT DER DATENBANK ANLEGEN.
-- In phpMyAdmin in der Datenbank der Anwendung unter "SQL" EINMAL ausführen.
-- Vorhandene Anträge, Tags, Zuordnungen und Benutzer bleiben erhalten.
-- DDL-Anweisungen lassen sich in MariaDB nicht zurückrollen: bei einem Abbruch
-- mittendrin den Export einspielen und den Fehler melden, statt das Skript erneut zu starten.

-- 1. Sitzungen (neu)
CREATE TABLE IF NOT EXISTS sitzungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung  VARCHAR(200) NOT NULL,
    datum        DATE         NULL,
    erstellt_am  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Anträge: Spalte umbenennen, Sitzungs-Verknüpfung ergänzen, Feldlängen angleichen
ALTER TABLE antraege
    CHANGE COLUMN IF EXISTS benachrichtigung benachrichtigung_gewuenscht TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS sitzung_id INT NULL AFTER status,
    MODIFY lerngruppe VARCHAR(100) NOT NULL,
    MODIFY thema VARCHAR(500) NOT NULL;

ALTER TABLE antraege
    ADD CONSTRAINT fk_antrag_sitzung FOREIGN KEY (sitzung_id) REFERENCES sitzungen(id) ON DELETE SET NULL;

-- 3. Benutzer: E-Mail ist im PHP-Backend nicht vorgesehen und darf beim Anlegen fehlen
ALTER TABLE benutzer MODIFY email VARCHAR(255) NULL DEFAULT NULL;

-- 4. Antrag-Tags: Verknüpfung über tag_id auf tag_name umstellen
ALTER TABLE antrag_tags ADD COLUMN tag_name VARCHAR(100) NULL;

UPDATE antrag_tags at
JOIN tags t ON t.id = at.tag_id
SET at.tag_name = t.name;

DELETE FROM antrag_tags WHERE tag_name IS NULL;

ALTER TABLE antrag_tags DROP FOREIGN KEY antrag_tags_ibfk_2;

-- Primärschlüssel tauschen in EINEM Schritt: der Fremdschlüssel auf antrag_id braucht durchgehend einen Index.
ALTER TABLE antrag_tags
    DROP PRIMARY KEY,
    DROP COLUMN tag_id,
    MODIFY tag_name VARCHAR(100) NOT NULL,
    ADD PRIMARY KEY (antrag_id, tag_name);

ALTER TABLE antrag_tags
    ADD CONSTRAINT fk_at_tag FOREIGN KEY (tag_name) REFERENCES tags(name) ON DELETE CASCADE;

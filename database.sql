-- SMV Antragssystem - MySQL Datenbankschema
-- Erstellt beim Umstieg von Python/MongoDB auf PHP/MySQL

CREATE DATABASE IF NOT EXISTS smv CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smv;

-- Benutzer-Tabelle
CREATE TABLE IF NOT EXISTS benutzer (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    benutzername VARCHAR(50)  NOT NULL UNIQUE,
    hashed_password VARCHAR(255) NOT NULL,
    rolle        ENUM('admin','schuelersprecher','user') NOT NULL DEFAULT 'user',
    erstellt_am  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sitzungen-Tabelle (Neu: welche Sitzung hat den Antrag besprochen)
CREATE TABLE IF NOT EXISTS sitzungen (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung  VARCHAR(200) NOT NULL,
    datum        DATE         NULL,
    erstellt_am  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anträge-Tabelle
CREATE TABLE IF NOT EXISTS antraege (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    vorname                  VARCHAR(100) NOT NULL,
    nachname                 VARCHAR(100) NOT NULL,
    lerngruppe               VARCHAR(100) NOT NULL,
    thema                    VARCHAR(500) NOT NULL,
    begruendung              TEXT         NOT NULL,
    benachrichtigung_gewuenscht TINYINT(1) NOT NULL DEFAULT 1,
    benachrichtigungs_art    ENUM('lerngruppenrat','texter') DEFAULT 'lerngruppenrat',
    phase                    ENUM('Phase 5','Phase 6','Phase 7','Phase 8','Phase 9','Phase 10','Phase 11','Phase 12','Phase 13') NOT NULL,
    status                   ENUM('eingereicht','in_bearbeitung','genehmigt','abgelehnt','zurueckgestellt') NOT NULL DEFAULT 'eingereicht',
    sitzung_id               INT NULL,
    erstellt_am              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_antrag_sitzung FOREIGN KEY (sitzung_id) REFERENCES sitzungen(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags-Tabelle
CREATE TABLE IF NOT EXISTS tags (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    erstellt_am TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verknüpfungstabelle Anträge ↔ Tags
CREATE TABLE IF NOT EXISTS antrag_tags (
    antrag_id INT          NOT NULL,
    tag_name  VARCHAR(100) NOT NULL,
    PRIMARY KEY (antrag_id, tag_name),
    CONSTRAINT fk_at_antrag FOREIGN KEY (antrag_id) REFERENCES antraege(id)  ON DELETE CASCADE,
    CONSTRAINT fk_at_tag    FOREIGN KEY (tag_name)  REFERENCES tags(name)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Benutzer (Passwörter werden beim ersten Start durch PHP gesetzt)
-- Passwörter: admin→admin123, schuelersprecher→schueler123, smv_user→user123
-- Die PHP-API erstellt diese beim Start automatisch, falls nicht vorhanden.

-- Standard-Tags
INSERT IGNORE INTO tags (name) VALUES
    ('Dringend'),
    ('Finanzierung'),
    ('Veranstaltung'),
    ('Regeländerung'),
    ('Sonstiges');

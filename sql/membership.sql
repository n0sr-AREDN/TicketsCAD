-- NewUI v4.0 - Membership / Roster Tables
-- Personnel tracking with certifications, teams, and contact info.
-- Simplified from legacy 65+ field member table to a clean schema.

-- `member_types` is deliberately NOT created here (Phase 124, 2026-07-26).
--
-- base_schema.sql is the single source of CREATE TABLE. This file used to
-- define a competing version of `member_types`, so the shape an install ended up with
-- depended on which script ran first — the bug that took a beta tester's
-- Teams screen down (see Phase 123).
-- sort_order is ensured by sql/run_schema_canonicalize.php.
-- To add a column: SHOW COLUMNS the live table first, then ALTER it from an
-- idempotent run_*.php migration (see sql/run_schema_canonicalize.php).

-- `member_status` is deliberately NOT created here (Phase 124, 2026-07-26).
--
-- base_schema.sql is the single source of CREATE TABLE. This file used to
-- define a competing version of `member_status`, so the shape an install ended up with
-- depended on which script ran first — the bug that took a beta tester's
-- Teams screen down (see Phase 123).
-- Canonical label column is `status_val` (not `name`); see run_schema_canonicalize.php.
-- To add a column: SHOW COLUMNS the live table first, then ALTER it from an
-- idempotent run_*.php migration (see sql/run_schema_canonicalize.php).

-- `member` is deliberately NOT created here (Phase 124, 2026-07-26).
--
-- base_schema.sql is the single source of CREATE TABLE. This file used to
-- define a competing version of `member`, so the shape an install ended up with
-- depended on which script ran first — the bug that took a beta tester's
-- Teams screen down (see Phase 123).
-- Legacy field1-65 lives in base_schema; sql/run_member_columns.php adds the modern named columns.
-- To add a column: SHOW COLUMNS the live table first, then ALTER it from an
-- idempotent run_*.php migration (see sql/run_schema_canonicalize.php).

-- `teams` is deliberately NOT created here (Phase 123, 2026-07-25).
--
-- This file used to define a SECOND, invented `teams` table (name, description,
-- team_type, leader_id, deputy_id) alongside the real one in base_schema.sql
-- (team, sub-group, ttypes_id, mission, leader, leader_dpty). Both used
-- CREATE TABLE IF NOT EXISTS, so the schema you ended up with depended on which
-- script ran first — and where both were applied, different code paths wrote to
-- different halves and produced teams with a type but no name.
--
-- The canonical definition lives in base_schema.sql. Do not redefine it here.
-- If `teams` needs a new column, ALTER the canonical table in a run_*.php
-- migration after checking the live columns with SHOW COLUMNS first.

CREATE TABLE IF NOT EXISTS `certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `required` tinyint(1) DEFAULT 0,
  `refresh_months` int(11) DEFAULT NULL COMMENT 'Months between refreshes, NULL=permanent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_certifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `certification_id` int(11) NOT NULL,
  `earned_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `certification_id` (`certification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data.
-- Every seed below uses INSERT IGNORE so a re-run of membership.sql
-- (deliberately or as part of foundational-SQL re-import) leaves
-- existing rows untouched and adds no duplicates. This relies on the
-- UNIQUE KEY on `name` set on each target table's CREATE TABLE above
-- (member_types.name and member_status.name were added earlier in this
-- file; certifications.name was added 2026-07-03 alongside the equipment
-- fix).
--
-- teams.team did NOT have a backing UNIQUE KEY until Phase 137
-- (sql/run_phase137_teams_name_unique.php, 2026-08-06) -- this comment
-- previously called the INSERT IGNORE below "defensive" on the assumption
-- that was good enough. It was not: install_fresh.php re-imports this file
-- whenever its tracked content hash changes (by design, for genuinely
-- idempotent tables), and INSERT IGNORE with no constraint to violate does
-- not suppress anything -- it just inserts the same four teams again.
-- Confirmed live: GH: Chris Byrd, Google Group 2026-08-06, "it duplicated
-- all the teams in the list" after updating to v4.2.8. Phase 137 merges any
-- existing duplicates and adds `uk_teams_team_name` UNIQUE KEY(team), so
-- this INSERT IGNORE is now backed the same way as its siblings above.
INSERT IGNORE INTO `member_types` (`name`, `description`, `color`, `sort_order`) VALUES
('Full Member', 'Active full member', '#198754', 1),
('Associate', 'Associate/auxiliary member', '#0d6efd', 2),
('Trainee', 'Member in training', '#fd7e14', 3),
('Inactive', 'Inactive member', '#6c757d', 4),
('Alumni', 'Former member', '#adb5bd', 5);

-- Canonical columns (Phase 124): member_status uses `status_val` for the label
-- and `background` for the colour — NOT `name`/`bg_color`. Seeding the wrong
-- names silently produced unusable rows.
INSERT IGNORE INTO `member_status` (`status_val`, `description`, `color`, `background`, `sort_order`) VALUES
('Active', 'Active and available', '#198754', '#d1e7dd', 1),
('On Leave', 'Temporarily unavailable', '#fd7e14', '#fff3cd', 2),
('Suspended', 'Membership suspended', '#dc3545', '#f8d7da', 3),
('Retired', 'Retired from service', '#6c757d', '#e2e3e5', 4);

-- Canonical columns: teams uses `team` for the name and `mission` for the
-- description. This seed previously wrote `name`/`description`/`team_type`;
-- once `name` became a column generated from `team`, those seeded names
-- evaporated and left four teams with a type but NO NAME on real installs.
-- `team_type` was free text with no canonical equivalent (the canonical field
-- is the numeric `ttypes_id`), so it is deliberately not seeded — set each
-- team's type from the Teams screen.
INSERT IGNORE INTO `teams` (`team`, `mission`, `active`) VALUES
('Alpha Team', 'Primary response team', 1),
('Bravo Team', 'Secondary response team', 1),
('Medical Unit', 'Medical response specialists', 1),
('Communications', 'Radio and communications operators', 1);

INSERT IGNORE INTO `certifications` (`name`, `description`, `required`, `refresh_months`) VALUES
('CPR/First Aid', 'Basic CPR and First Aid certification', 1, 24),
('ICS-100', 'Introduction to Incident Command System', 1, NULL),
('ICS-200', 'ICS for Single Resources', 0, NULL),
('ICS-700', 'NIMS Introduction', 1, NULL),
('ICS-800', 'National Response Framework', 0, NULL),
('HAM Radio License', 'FCC Amateur Radio Technician or higher', 0, NULL),
('CERT Basic', 'Community Emergency Response Team basic training', 0, NULL),
('Hazmat Awareness', 'Hazardous Materials Awareness level', 0, 36),
('Defensive Driving', 'Emergency vehicle operations', 0, 24);

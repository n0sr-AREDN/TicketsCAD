-- NewUI v4.0 - Constituents Table
-- Contacts/callers database for phone-based lookup during incident creation.
-- Ported from legacy tickets system.

-- `constituents` is deliberately NOT created here (Phase 124, 2026-07-26).
--
-- base_schema.sql is the single source of CREATE TABLE. This file used to
-- define a competing version of `constituents`, so the shape an install ended up with
-- depended on which script ran first — the bug that took a beta tester's
-- Teams screen down (see Phase 123).
-- The per-number *_type columns are ensured by sql/run_schema_canonicalize.php.
-- To add a column: SHOW COLUMNS the live table first, then ALTER it from an
-- idempotent run_*.php migration (see sql/run_schema_canonicalize.php).

-- Sample data
INSERT INTO `constituents` (`contact`, `street`, `city`, `state`, `phone`, `miscellaneous`, `updated`) VALUES
('John Smith', '123 Main St', 'Springfield', 'IL', '555-0101', 'Elderly resident, hard of hearing. Dog in backyard.', NOW()),
('Maria Garcia', '456 Oak Ave', 'Springfield', 'IL', '555-0102', 'Spanish speaking household. Two small children.', NOW()),
('Robert Johnson', '789 Elm Dr', 'Shelbyville', 'IL', '555-0103', 'Known medical condition - diabetic. Insulin in fridge.', NOW()),
('Sarah Williams', '321 Pine Rd', 'Springfield', 'IL', '555-0104', NULL, NOW()),
('David Brown', '654 Maple Ln', 'Capital City', 'IL', '555-0105', 'Guard dog on premises. Use side entrance.', NOW());

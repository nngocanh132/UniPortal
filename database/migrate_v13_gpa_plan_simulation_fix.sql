-- ============================================================
-- UniPortal – GPA Planner saved-simulation repair
-- Migration v13
--
-- GpaAdvisor uses GPAPlanSimulation for saved what-if scenarios:
--   saveSimulation() / getSavedSimulations() / deleteSimulation()
--
-- The table was introduced by migrate_v8_gpa_ai_planner.sql but is absent
-- from databases created before/without that migration. v8's definition also
-- forced utf8mb4_unicode_ci on student_id while Student.student_id uses the
-- database default collation, so the foreign key could not be created.
--
-- This migration:
--   * creates GPAPlanSimulation when it is missing;
--   * adds any missing column when a partial table already exists;
--   * aligns student_id with Student.student_id (charset/collation);
--   * widens required_future_gpa to DECIMAL(7,3) – the model can legitimately
--     produce large negative values when few credits remain and the target is
--     already met (v8's DECIMAL(4,3) overflows in that case);
--   * adds the student index and foreign key only when absent;
--   * re-inserts the GPA planner SystemConfig keys with INSERT IGNORE.
--
-- Safe to re-run. Never drops tables or modifies academic records.
-- Requires MariaDB 10.2+ (bundled with XAMPP).
--
-- Run: mysql -u root uniportal < database/migrate_v13_gpa_plan_simulation_fix.sql
-- ============================================================

USE uniportal;
SET NAMES utf8mb4;

-- ── 1. GPA planner configuration (idempotent) ───────────────
INSERT IGNORE INTO SystemConfig (config_key, config_value, description) VALUES
    ('retake_policy',            'count_all',
        'Retake GPA rule: count_all = every attempt counts (existing behaviour), replace = latest attempt replaces old, best = highest attempt counts'),
    ('retake_allowed_max_gpa',   '2.0',
        'A completed course is eligible for retake when its best grade point is below this value'),
    ('gpa_excluded_codes',       '',
        'Comma-separated course codes excluded from GPA planning (e.g. physical education). Existing official GPA is unaffected.'),
    ('plan_credits_per_semester','15',
        'Default number of credits used per semester in the GPA roadmap'),
    ('ai_enabled',               '0',
        '1 = use the configured external AI provider for narrative analysis; 0 = rule-based analysis'),
    ('ai_api_url',               '',
        'OpenAI-compatible chat completions endpoint, e.g. https://provider.example/v1/chat/completions'),
    ('ai_api_key',               '',
        'Server-side API key for the AI provider. Never rendered in the student UI.'),
    ('ai_model',                 '',
        'Model name passed to the AI provider');

-- ── 2. Create / repair GPAPlanSimulation ────────────────────
DROP PROCEDURE IF EXISTS sp_migrate_v13_gpa_simulation;

DELIMITER $$
CREATE PROCEDURE sp_migrate_v13_gpa_simulation()
BEGIN
    DECLARE v_tbl     INT DEFAULT 0;
    DECLARE v_fk      INT DEFAULT 0;
    DECLARE v_idx     INT DEFAULT 0;
    DECLARE v_charset VARCHAR(64) DEFAULT 'utf8mb4';
    DECLARE v_coll    VARCHAR(64) DEFAULT 'utf8mb4_general_ci';

    -- Match the Student key exactly so the foreign key is accepted.
    SELECT CHARACTER_SET_NAME, COLLATION_NAME
      INTO v_charset, v_coll
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'Student'
       AND COLUMN_NAME  = 'student_id'
     LIMIT 1;

    IF v_charset IS NULL THEN SET v_charset = 'utf8mb4'; END IF;
    IF v_coll    IS NULL THEN SET v_coll    = 'utf8mb4_general_ci'; END IF;

    SELECT COUNT(*) INTO v_tbl
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'GPAPlanSimulation';

    IF v_tbl = 0 THEN
        SET @ddl = CONCAT(
            'CREATE TABLE GPAPlanSimulation (',
            '  simulation_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,',
            '  student_id           CHAR(8) CHARACTER SET ', v_charset, ' COLLATE ', v_coll, ' NOT NULL,',
            '  scenario_name        VARCHAR(150) NOT NULL,',
            '  target_gpa           DECIMAL(3,2) NOT NULL,',
            '  future_gpa           DECIMAL(3,2) NOT NULL,',
            '  total_future_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,',
            '  improvement_credits  SMALLINT UNSIGNED NOT NULL DEFAULT 0,',
            '  retakes_json         JSON DEFAULT NULL,',
            '  expected_gpa         DECIMAL(3,2) DEFAULT NULL,',
            '  required_future_gpa  DECIMAL(7,3) DEFAULT NULL,',
            '  feasibility          VARCHAR(20) NOT NULL DEFAULT ''achievable'',',
            '  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,',
            '  PRIMARY KEY (simulation_id),',
            '  KEY idx_gpa_sim_student (student_id, created_at),',
            '  CONSTRAINT fk_gpa_sim_student FOREIGN KEY (student_id)',
            '      REFERENCES Student (student_id) ON DELETE CASCADE ON UPDATE CASCADE,',
            '  CONSTRAINT ck_gpa_sim_target CHECK (target_gpa BETWEEN 0 AND 4),',
            '  CONSTRAINT ck_gpa_sim_future CHECK (future_gpa BETWEEN 0 AND 4)',
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=', v_coll
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    ELSE
        -- Repair a partial table without touching existing rows.
        ALTER TABLE GPAPlanSimulation
            ADD COLUMN IF NOT EXISTS scenario_name        VARCHAR(150) NOT NULL DEFAULT '' AFTER student_id,
            ADD COLUMN IF NOT EXISTS target_gpa           DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            ADD COLUMN IF NOT EXISTS future_gpa           DECIMAL(3,2) NOT NULL DEFAULT 0.00,
            ADD COLUMN IF NOT EXISTS total_future_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS improvement_credits  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS retakes_json         JSON DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS expected_gpa         DECIMAL(3,2) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS required_future_gpa  DECIMAL(7,3) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS feasibility          VARCHAR(20) NOT NULL DEFAULT 'achievable',
            ADD COLUMN IF NOT EXISTS created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

        -- Required by saveSimulation(): negative values occur when the target
        -- is already reached and only a few credits remain.
        ALTER TABLE GPAPlanSimulation
            MODIFY COLUMN required_future_gpa DECIMAL(7,3) DEFAULT NULL;
    END IF;

    -- Index used by getSavedSimulations().
    SELECT COUNT(*) INTO v_idx
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'GPAPlanSimulation'
       AND INDEX_NAME   = 'idx_gpa_sim_student';

    IF v_idx = 0 THEN
        ALTER TABLE GPAPlanSimulation
            ADD INDEX idx_gpa_sim_student (student_id, created_at);
    END IF;

    -- Foreign key to the owning student (cascade keeps orphans out).
    SELECT COUNT(*) INTO v_fk
      FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA      = DATABASE()
       AND TABLE_NAME        = 'GPAPlanSimulation'
       AND COLUMN_NAME       = 'student_id'
       AND REFERENCED_TABLE_NAME  = 'Student'
       AND REFERENCED_COLUMN_NAME = 'student_id';

    IF v_fk = 0 THEN
        -- Align the column with Student.student_id before adding the FK.
        SET @ddl = CONCAT(
            'ALTER TABLE GPAPlanSimulation MODIFY COLUMN student_id CHAR(8) ',
            'CHARACTER SET ', v_charset, ' COLLATE ', v_coll, ' NOT NULL'
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        ALTER TABLE GPAPlanSimulation
            ADD CONSTRAINT fk_gpa_sim_student FOREIGN KEY (student_id)
            REFERENCES Student (student_id) ON DELETE CASCADE ON UPDATE CASCADE;
    END IF;
END$$
DELIMITER ;

CALL sp_migrate_v13_gpa_simulation();
DROP PROCEDURE sp_migrate_v13_gpa_simulation;

-- ── 3. Verification ─────────────────────────────────────────
SELECT 'GPAPlanSimulation' AS object_name,
       COUNT(*)            AS saved_rows
  FROM GPAPlanSimulation;

SHOW CREATE TABLE GPAPlanSimulation\G

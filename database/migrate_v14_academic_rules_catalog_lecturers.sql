-- ============================================================================
-- UniPortal – Migration v14
-- Academic rules, expanded catalog metadata, lecturer profiles,
-- semester credit limits (+ per-student exceptions), attendance eligibility
-- policy, and data backfills for curriculum/student major linkage.
--
-- Idempotent: safe to run multiple times. Does NOT drop or recreate tables and
-- does NOT overwrite existing academic records (only fills NULL columns).
--
-- Run:
--   C:/xampp/mysql/bin/mysql.exe -u root uniportal < database/migrate_v14_academic_rules_catalog_lecturers.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Course catalog metadata
--    category          – catalog grouping (general education, IT, finance, …)
--    default_sessions  – official sessions per semester (2cr = 10, 3cr = 15)
-- ---------------------------------------------------------------------------
ALTER TABLE Course
    ADD COLUMN IF NOT EXISTS category VARCHAR(50) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS default_sessions SMALLINT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE Course
    ADD INDEX IF NOT EXISTS idx_course_category (category);

-- Backfill default sessions from credits (5 sessions per credit) when missing.
UPDATE Course
   SET default_sessions = credits * 5
 WHERE default_sessions IS NULL AND credits > 0;

-- ---------------------------------------------------------------------------
-- 2. Lecturer profile metadata
-- ---------------------------------------------------------------------------
ALTER TABLE Lecturer
    ADD COLUMN IF NOT EXISTS academic_title VARCHAR(50) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS position_title VARCHAR(60) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS specialization VARCHAR(150) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(255) NULL DEFAULT NULL;

-- ---------------------------------------------------------------------------
-- 3. Course ↔ eligible major mapping
--    A general-education course may map to every major; a major-specific
--    course maps only to its eligible majors. Curriculum still controls the
--    required/elective classification per admission year.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS CourseMajor (
    course_id  INT UNSIGNED NOT NULL,
    major_id   INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (course_id, major_id),
    KEY idx_coursemajor_major (major_id),
    CONSTRAINT fk_coursemajor_course FOREIGN KEY (course_id)
        REFERENCES Course (course_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coursemajor_major FOREIGN KEY (major_id)
        REFERENCES Major (major_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 4. Per-student semester credit-limit exceptions (admin authorized)
--    Overrides the global min/max registration credits for one semester.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS RegistrationCreditException (
    exception_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   CHAR(8) NOT NULL,
    semester_id  INT UNSIGNED NOT NULL,
    min_credits  TINYINT UNSIGNED NULL DEFAULT NULL,
    max_credits  TINYINT UNSIGNED NULL DEFAULT NULL,
    reason       VARCHAR(255) NOT NULL,
    approved_by  INT UNSIGNED NULL DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (exception_id),
    UNIQUE KEY uq_credit_exception_student_sem (student_id, semester_id),
    KEY idx_credit_exception_semester (semester_id),
    KEY idx_credit_exception_approved_by (approved_by),
    CONSTRAINT fk_credit_exception_student FOREIGN KEY (student_id)
        REFERENCES Student (student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_credit_exception_semester FOREIGN KEY (semester_id)
        REFERENCES Semester (semester_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_credit_exception_approved_by FOREIGN KEY (approved_by)
        REFERENCES Account (account_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_credit_exception_min CHECK (min_credits IS NULL OR min_credits BETWEEN 0 AND 60),
    CONSTRAINT ck_credit_exception_max CHECK (max_credits IS NULL OR max_credits BETWEEN 1 AND 60)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 5. Policy configuration defaults (never overwrite an existing value)
-- ---------------------------------------------------------------------------
INSERT INTO SystemConfig (config_key, config_value, description) VALUES
  ('registration_min_credits', '14',
   'Minimum registered credits per semester (registration requirement, not a browsing limit)'),
  ('registration_max_credits', '24',
   'Maximum registered credits per semester'),
  ('attendance_eligibility_warn_margin', '5',
   'Absent-% margin below the exam-ban threshold at which a student is shown as At risk'),
  ('attendance_late_absent', '0',
   '1 = a Late record counts toward the absent percentage for exam eligibility'),
  ('attendance_excused_absent', '0',
   '1 = an Excused absence counts toward the absent percentage for exam eligibility')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- ---------------------------------------------------------------------------
-- 6. Backfills – link curricula to majors where the code matches
-- ---------------------------------------------------------------------------
UPDATE Curriculum cu
  JOIN Major m ON m.major_code COLLATE utf8mb4_general_ci = cu.major_code
   SET cu.major_id = m.major_id
 WHERE cu.major_id IS NULL;

-- Link students to their major using the class-code prefix (MIS2023A → MIS).
-- Only fills NULL values; existing assignments are preserved.
UPDATE Student s
  JOIN Major m ON s.class_code LIKE CONCAT(m.major_code COLLATE utf8mb4_general_ci, '%')
   SET s.major_id = m.major_id
 WHERE s.major_id IS NULL;

-- Fill the student's department from the (now assigned) major when missing.
UPDATE Student s
  JOIN Major m ON m.major_id = s.major_id
   SET s.department_id = m.department_id
 WHERE s.department_id IS NULL AND m.department_id IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 7. Migration marker
-- ---------------------------------------------------------------------------
INSERT INTO SystemConfig (config_key, config_value, description) VALUES
  ('migration_v14_academic_rules_catalog_lecturers', '1',
   'Migration v14: credit limits, course catalog metadata, lecturer profiles, attendance eligibility')
ON DUPLICATE KEY UPDATE config_value = '1';

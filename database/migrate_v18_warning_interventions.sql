-- ============================================================================
-- UniPortal – Migration v18
-- Lecturer warning interventions with assignment-based access control.
--
-- Adds WarningIntervention: one row per recorded intervention. Every row keeps
-- the warning, student, class section, course, lecturer and account that
-- produced it, so statistics and histories can be scoped without touching the
-- original AcademicWarning rows.
--
-- Idempotent and additive: safe to re-run. Never drops or alters existing
-- tables/columns and never modifies warning, enrollment or grade records.
--
-- Run:
--   C:/xampp/mysql/bin/mysql.exe -u root uniportal < database/migrate_v18_warning_interventions.sql
-- ============================================================================

USE uniportal;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS WarningIntervention (
    intervention_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    warning_id      INT UNSIGNED NOT NULL,
    student_id      CHAR(8) NOT NULL,
    section_id      INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    lecturer_id     INT UNSIGNED NOT NULL,
    account_id      INT UNSIGNED NULL,
    action_type     VARCHAR(40) NOT NULL DEFAULT 'other'
                    COMMENT 'contact | email | meeting | plan | assignment | other',
    note            TEXT NULL,
    status          ENUM('In Progress','Resolved') NOT NULL DEFAULT 'In Progress',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (intervention_id),
    KEY idx_wi_lecturer_status (lecturer_id, status),
    KEY idx_wi_section (section_id),
    KEY idx_wi_warning (warning_id),
    KEY idx_wi_created (created_at),
    CONSTRAINT fk_wi_warning  FOREIGN KEY (warning_id)  REFERENCES AcademicWarning (warning_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_wi_student  FOREIGN KEY (student_id)  REFERENCES Student (student_id) ON UPDATE CASCADE,
    CONSTRAINT fk_wi_section  FOREIGN KEY (section_id)  REFERENCES ClassSection (section_id) ON UPDATE CASCADE,
    CONSTRAINT fk_wi_course   FOREIGN KEY (course_id)   REFERENCES Course (course_id) ON UPDATE CASCADE,
    CONSTRAINT fk_wi_lecturer FOREIGN KEY (lecturer_id) REFERENCES Lecturer (lecturer_id) ON UPDATE CASCADE,
    CONSTRAINT fk_wi_account  FOREIGN KEY (account_id)  REFERENCES Account (account_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Lecturer interventions recorded against academic warnings';

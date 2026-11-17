-- ============================================================
-- UniPortal – Migration v2.5: per-course attendance warning columns
--
-- AcademicAlertService and migrate_v3 store attendance warnings per
-- class section with a severity level, but no earlier script added
-- these columns to AcademicWarning. Run after v2, before v3.
-- Idempotent: every step checks information_schema first.
-- ============================================================
USE uniportal;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academicwarning'
        AND COLUMN_NAME = 'section_id') = 0,
    'ALTER TABLE academicwarning ADD COLUMN section_id INT UNSIGNED NULL DEFAULT NULL AFTER student_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academicwarning'
        AND COLUMN_NAME = 'warning_level') = 0,
    'ALTER TABLE academicwarning ADD COLUMN warning_level TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER warning_type',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academicwarning'
        AND INDEX_NAME = 'idx_aw_student_section') = 0,
    'ALTER TABLE academicwarning ADD INDEX idx_aw_student_section (student_id, warning_type, section_id)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academicwarning'
        AND CONSTRAINT_NAME = 'fk_aw_section') = 0,
    'ALTER TABLE academicwarning ADD CONSTRAINT fk_aw_section FOREIGN KEY (section_id) REFERENCES classsection (section_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

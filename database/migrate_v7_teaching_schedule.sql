-- ============================================================
-- UniPortal – Teaching Schedule & Schedule Change Requests
-- Migration v7
--
-- Adds:
--   * TeachingSchedule          – official weekly schedule per class section
--   * ScheduleChangeRequest     – lecturer cancellation / make-up proposals
--   * AttendanceSession fields  – schedule link, lecturer, room, type, status
--
-- Enables Admin-generated sessions and prevents lecturers from creating
-- ordinary daily sessions. Historical attendance is preserved.
--
-- Safe to run once; guarded to be re-runnable. Does not drop data.
-- Run: mysql -u root uniportal < database/migrate_v7_teaching_schedule.sql
-- ============================================================

USE uniportal;
SET NAMES utf8mb4;

-- ── 1. Official weekly teaching schedule ────────────────────
CREATE TABLE IF NOT EXISTS TeachingSchedule (
    schedule_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id       INT UNSIGNED NOT NULL,
    semester_id      INT UNSIGNED NOT NULL,
    lecturer_id      INT UNSIGNED NOT NULL,
    day_of_week      TINYINT UNSIGNED NOT NULL COMMENT '1=Monday … 7=Sunday (ISO-8601)',
    start_time       TIME NOT NULL,
    end_time         TIME NOT NULL,
    room             VARCHAR(30) DEFAULT NULL,
    effective_start  DATE NOT NULL,
    effective_end    DATE NOT NULL,
    status           ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by       INT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_schedule_section (section_id),
    KEY idx_schedule_semester (semester_id),
    KEY idx_schedule_lecturer (lecturer_id),
    KEY idx_schedule_day (day_of_week),
    CONSTRAINT fk_schedule_section FOREIGN KEY (section_id) REFERENCES ClassSection (section_id) ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_semester FOREIGN KEY (semester_id) REFERENCES Semester (semester_id) ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_lecturer FOREIGN KEY (lecturer_id) REFERENCES Lecturer (lecturer_id) ON UPDATE CASCADE,
    CONSTRAINT fk_schedule_created_by FOREIGN KEY (created_by) REFERENCES Account (account_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_schedule_time CHECK (end_time > start_time),
    CONSTRAINT ck_schedule_dates CHECK (effective_end >= effective_start),
    CONSTRAINT ck_schedule_dow CHECK (day_of_week BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Extend AttendanceSession ─────────────────────────────
DELIMITER $$
DROP PROCEDURE IF EXISTS uniportal_v7_migrate$$
CREATE PROCEDURE uniportal_v7_migrate()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='schedule_id') THEN
        ALTER TABLE AttendanceSession ADD COLUMN schedule_id INT UNSIGNED DEFAULT NULL AFTER section_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='lecturer_id') THEN
        ALTER TABLE AttendanceSession ADD COLUMN lecturer_id INT UNSIGNED DEFAULT NULL AFTER schedule_id;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='room') THEN
        ALTER TABLE AttendanceSession ADD COLUMN room VARCHAR(30) DEFAULT NULL AFTER end_time;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='session_type') THEN
        ALTER TABLE AttendanceSession ADD COLUMN session_type ENUM('Regular','Makeup') NOT NULL DEFAULT 'Regular' AFTER topic;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='status') THEN
        ALTER TABLE AttendanceSession ADD COLUMN status ENUM('Scheduled','Cancelled') NOT NULL DEFAULT 'Scheduled' AFTER session_type;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='original_session_id') THEN
        ALTER TABLE AttendanceSession ADD COLUMN original_session_id INT UNSIGNED DEFAULT NULL AFTER status;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND COLUMN_NAME='cancelled_at') THEN
        ALTER TABLE AttendanceSession ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER original_session_id;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND INDEX_NAME='idx_ats_schedule') THEN
        ALTER TABLE AttendanceSession ADD KEY idx_ats_schedule (schedule_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND INDEX_NAME='idx_ats_lecturer') THEN
        ALTER TABLE AttendanceSession ADD KEY idx_ats_lecturer (lecturer_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND INDEX_NAME='idx_ats_status') THEN
        ALTER TABLE AttendanceSession ADD KEY idx_ats_status (status);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession' AND INDEX_NAME='idx_ats_original') THEN
        ALTER TABLE AttendanceSession ADD KEY idx_ats_original (original_session_id);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession'
                     AND CONSTRAINT_NAME='fk_ats_schedule') THEN
        ALTER TABLE AttendanceSession ADD CONSTRAINT fk_ats_schedule
            FOREIGN KEY (schedule_id) REFERENCES TeachingSchedule (schedule_id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession'
                     AND CONSTRAINT_NAME='fk_ats_lecturer') THEN
        ALTER TABLE AttendanceSession ADD CONSTRAINT fk_ats_lecturer
            FOREIGN KEY (lecturer_id) REFERENCES Lecturer (lecturer_id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='attendancesession'
                     AND CONSTRAINT_NAME='fk_ats_original') THEN
        ALTER TABLE AttendanceSession ADD CONSTRAINT fk_ats_original
            FOREIGN KEY (original_session_id) REFERENCES AttendanceSession (session_id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$
DELIMITER ;

CALL uniportal_v7_migrate();
DROP PROCEDURE uniportal_v7_migrate;

-- Backfill existing sessions so historical records keep the lecturer/room
UPDATE AttendanceSession ats
JOIN ClassSection cs ON cs.section_id = ats.section_id
SET ats.lecturer_id = COALESCE(ats.lecturer_id, cs.lecturer_id),
    ats.room        = COALESCE(ats.room, cs.room)
WHERE ats.lecturer_id IS NULL OR ats.room IS NULL;

-- ── 3. Lecturer schedule change requests ────────────────────
CREATE TABLE IF NOT EXISTS ScheduleChangeRequest (
    request_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_type        ENUM('Cancellation','Makeup') NOT NULL,
    session_id          INT UNSIGNED NOT NULL,
    section_id          INT UNSIGNED NOT NULL,
    lecturer_id         INT UNSIGNED NOT NULL,
    requested_by        INT UNSIGNED NOT NULL,
    reason              TEXT NOT NULL,
    proposed_date       DATE DEFAULT NULL,
    proposed_start_time TIME DEFAULT NULL,
    proposed_end_time   TIME DEFAULT NULL,
    proposed_room       VARCHAR(30) DEFAULT NULL,
    status              ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    admin_note          TEXT DEFAULT NULL,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,
    created_session_id  INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_scr_status (status),
    KEY idx_scr_lecturer (lecturer_id),
    KEY idx_scr_session (session_id),
    KEY idx_scr_section (section_id),
    KEY idx_scr_type (request_type),
    CONSTRAINT fk_scr_session FOREIGN KEY (session_id) REFERENCES AttendanceSession (session_id) ON UPDATE CASCADE,
    CONSTRAINT fk_scr_section FOREIGN KEY (section_id) REFERENCES ClassSection (section_id) ON UPDATE CASCADE,
    CONSTRAINT fk_scr_lecturer FOREIGN KEY (lecturer_id) REFERENCES Lecturer (lecturer_id) ON UPDATE CASCADE,
    CONSTRAINT fk_scr_requested_by FOREIGN KEY (requested_by) REFERENCES Account (account_id) ON UPDATE CASCADE,
    CONSTRAINT fk_scr_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES Account (account_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_scr_created_session FOREIGN KEY (created_session_id) REFERENCES AttendanceSession (session_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_scr_makeup CHECK (
        request_type <> 'Makeup' OR (proposed_date IS NOT NULL
            AND proposed_start_time IS NOT NULL AND proposed_end_time IS NOT NULL)
    ),
    CONSTRAINT ck_scr_time CHECK (
        proposed_end_time IS NULL OR proposed_start_time IS NULL OR proposed_end_time > proposed_start_time
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One pending request of each type per session
-- (MySQL/MariaDB cannot use partial indexes; enforced in application logic.)

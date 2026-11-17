USE uniportal;

ALTER TABLE TeachingSchedule
    ADD COLUMN IF NOT EXISTS expected_sessions SMALLINT UNSIGNED NULL AFTER effective_end;
ALTER TABLE AttendanceSession
    ADD COLUMN IF NOT EXISTS session_no SMALLINT UNSIGNED NULL AFTER schedule_id;
ALTER TABLE Assignment
    ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_deleted;
ALTER TABLE Submission
    ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER grade_status;

UPDATE TeachingSchedule ts
JOIN ClassSection cs ON cs.section_id = ts.section_id
JOIN Course c ON c.course_id = cs.course_id
SET ts.expected_sessions = GREATEST(1, c.credits * 5)
WHERE ts.expected_sessions IS NULL;

-- Only allocate missing ordinals: reruns must not renumber historical sessions.
DROP TEMPORARY TABLE IF EXISTS tmp_ats_no;
CREATE TEMPORARY TABLE tmp_ats_no AS
SELECT a.session_id,
       COALESCE(n.max_no, 0) + ROW_NUMBER() OVER (
           PARTITION BY a.section_id ORDER BY a.session_date, a.start_time, a.session_id
       ) AS session_no
FROM AttendanceSession a
LEFT JOIN (
    SELECT section_id, MAX(session_no) AS max_no FROM AttendanceSession GROUP BY section_id
) n ON n.section_id = a.section_id
WHERE a.session_no IS NULL;
UPDATE AttendanceSession a
JOIN tmp_ats_no t ON t.session_id = a.session_id
SET a.session_no = t.session_no;
DROP TEMPORARY TABLE tmp_ats_no;

UPDATE AttendanceSession a
JOIN ClassSection cs ON cs.section_id = a.section_id
SET a.lecturer_id = cs.lecturer_id
WHERE a.lecturer_id IS NULL;

ALTER TABLE AttendanceSession
    ADD UNIQUE INDEX IF NOT EXISTS uq_ats_section_no (section_id, session_no);

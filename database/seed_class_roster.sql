-- ============================================================
-- UniPortal – Realistic class rosters (repeatable)
--
-- Fixes the "class section only has 1 student" problem by
-- creating actual Student, Account and Enrollment records so
-- every class section holds 48–64 enrolled students.
--
-- Also normalises the active-semester sections so course code,
-- section code and lecturer match the academic structure:
--
--   MIS2101-01  MIS2101  lecturer 1
--   MIS2101-02  MIS2101  lecturer 2  (new section)
--   INS3056-01  INS3056  lecturer 1
--   AIT2101-01  AIT2101  lecturer 3
--
-- The script is idempotent: rerunning inserts no duplicates.
-- Existing students, accounts and enrollments are preserved.
-- All generated students use password "password".
--
-- Run:  mysql -u root uniportal < database/seed_class_roster.sql
-- ============================================================

USE uniportal;
SET NAMES utf8mb4;

-- ── 1. Active-semester section metadata ──────────────────────
INSERT INTO Course (course_code, course_name, credits, department_id, description, is_active)
SELECT 'INS3056', 'Enterprise Information Systems', 3, d.department_id,
       'ERP, CRM and integrated business information systems.', 1
FROM Department d
WHERE d.department_code = 'MIS'
  AND NOT EXISTS (SELECT 1 FROM Course WHERE course_code = 'INS3056');

UPDATE ClassSection
SET section_code = 'MIS2101-01'
WHERE section_id = 36 AND section_code = 'MIS3101-01';

UPDATE ClassSection
SET section_code = 'INS3056-01',
    course_id    = (SELECT course_id FROM Course WHERE course_code = 'INS3056' LIMIT 1),
    lecturer_id  = 1
WHERE section_id = 35;

UPDATE ClassSection
SET section_code = 'AIT2101-01'
WHERE section_id = 37 AND section_code = 'AIT3201-01';

-- Add a second MIS2101 section so two lecturers can teach the
-- same course in the same semester.
INSERT INTO ClassSection
    (course_id, semester_id, lecturer_id, section_code, schedule, room, capacity)
SELECT c.course_id, 7, 2, 'MIS2101-02',
       'Thứ 3 & 5 09:15-10:45', 'B1.106', 64
FROM Course c
WHERE c.course_code = 'MIS2101'
  AND NOT EXISTS (
      SELECT 1 FROM ClassSection cs
      WHERE cs.section_code = 'MIS2101-02' AND cs.semester_id = 7
  );

-- Standard class size is 48–64, so make room for the roster.
UPDATE ClassSection SET capacity = 64 WHERE capacity IS NULL OR capacity < 64;

-- ── 2. Generate students + accounts + enrollments ────────────
DROP PROCEDURE IF EXISTS uniportal_seed_roster;
DELIMITER $$
CREATE PROCEDURE uniportal_seed_roster()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_sid, v_target, v_seq, v_adm, v_dept, v_enrolled INT;
    DECLARE v_prefix CHAR(2);
    DECLARE v_deptcode VARCHAR(10);
    DECLARE v_stid CHAR(8);
    DECLARE v_dob DATE;
    DECLARE v_hash VARCHAR(255);

    DECLARE cur CURSOR FOR
        SELECT cs.section_id,
               CASE cs.section_code
                    WHEN 'MIS2101-01' THEN 54   -- 54 new + 1 existing = 55
                    WHEN 'MIS2101-02' THEN 60
                    WHEN 'INS3056-01' THEN 51   -- 51 new + 1 existing = 52
                    WHEN 'AIT2101-01' THEN 49   -- 49 new + 1 existing = 50
                    ELSE 48 + MOD(cs.section_id, 7)
               END,
               YEAR(sem.start_date) - 1,
               c.department_id,
               COALESCE(d.department_code, 'GEN')
        FROM ClassSection cs
        JOIN Course c   ON c.course_id   = cs.course_id
        JOIN Semester sem ON sem.semester_id = cs.semester_id
        LEFT JOIN Department d ON d.department_id = c.department_id
        ORDER BY cs.section_id;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT COALESCE(
        (SELECT password_hash FROM Account WHERE student_id = '23070001' LIMIT 1),
        '$2y$10$yFkvpR8Rytkt0iYVkA0FHukR8b9qbZmiXlnApZlVUcIBZdRAMfsPq'
    ) INTO v_hash;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_sid, v_target, v_adm, v_dept, v_deptcode;
        IF done = 1 THEN LEAVE read_loop; END IF;

        SET v_prefix = RIGHT(CAST(v_adm AS CHAR), 2);
        SET v_seq = 1;
        WHILE v_seq <= v_target DO
            SET v_stid  = CONCAT(v_prefix, LPAD(v_sid, 2, '0'), LPAD(v_seq, 4, '0'));
            SET v_dob   = DATE_ADD(MAKEDATE(v_adm, 1), INTERVAL MOD(v_seq * 37, 300) DAY);

            INSERT INTO Student
                (student_id, full_name, class_code, phone, email, gender,
                 date_of_birth, department_id, admission_year, status)
            SELECT v_stid,
                   CONCAT(
                       ELT(1 + MOD(v_seq - 1, 12),
                           'Nguyen Van', 'Tran Thi', 'Le Hoang', 'Pham Minh',
                           'Hoang Thu', 'Vu Duc', 'Dang Ngoc', 'Bui Thanh',
                           'Ngo Quang', 'Do Khanh', 'Trinh Bao', 'Ly Gia'),
                       ' ',
                       ELT(1 + MOD(FLOOR((v_seq - 1) / 12), 12),
                           'An', 'Binh', 'Chau', 'Dung', 'Ha', 'Hung',
                           'Linh', 'Mai', 'Nam', 'Phuong', 'Quan', 'Trang')),
                   CONCAT(v_deptcode, v_adm, 'A'),
                   CONCAT('09', v_stid),
                   CONCAT(v_stid, '@student.uniportal.edu'),
                   IF(MOD(v_seq, 2) = 0, 'Female', 'Male'),
                   v_dob, v_dept, v_adm, 'Active'
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM Student WHERE student_id = v_stid);

            INSERT INTO Account (username, password_hash, role, student_id, is_active)
            SELECT v_stid, v_hash, 'Student', v_stid, 1
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM Account WHERE username = v_stid OR student_id = v_stid
            );

            INSERT INTO Enrollment (student_id, section_id, enrollment_date, status)
            SELECT v_stid, v_sid,
                   DATE_ADD(sem.start_date, INTERVAL MOD(v_seq, 10) DAY),
                   'Enrolled'
            FROM ClassSection cs
            JOIN Semester sem ON sem.semester_id = cs.semester_id
            WHERE cs.section_id = v_sid
              AND NOT EXISTS (
                  SELECT 1 FROM Enrollment e
                  WHERE e.student_id = v_stid AND e.section_id = v_sid
              );

            SET v_seq = v_seq + 1;
        END WHILE;
    END LOOP;
    CLOSE cur;
END$$
DELIMITER ;

CALL uniportal_seed_roster();
DROP PROCEDURE uniportal_seed_roster;

-- ── 3. Demo attendance for the active semester ───────────────
-- Three real sessions per active section; the roster is read
-- from Enrollment, so every enrolled student appears.
INSERT INTO AttendanceSession
    (section_id, session_date, start_time, end_time, topic)
SELECT cs.section_id,
       DATE_SUB(CURDATE(), INTERVAL o.d DAY),
       '07:30:00', '09:00:00', o.topic
FROM ClassSection cs
JOIN Semester sem ON sem.semester_id = cs.semester_id AND sem.status = 'Active'
JOIN (
    SELECT 2 AS d, 'Chuyen de 1 - Tong quan hoc phan' AS topic
    UNION ALL SELECT 9,  'Chuyen de 2 - Thuc hanh va bai tap'
    UNION ALL SELECT 16, 'Chuyen de 3 - Thao luan nhom'
) o
WHERE NOT EXISTS (
    SELECT 1 FROM AttendanceSession x
    WHERE x.section_id = cs.section_id
      AND x.session_date = DATE_SUB(CURDATE(), INTERVAL o.d DAY)
      AND x.start_time = '07:30:00'
);

INSERT INTO Attendance
    (session_id, enrollment_id, status, note, recorded_by, recorded_at)
SELECT ats.session_id,
       e.enrollment_id,
       CASE
           WHEN MOD(e.enrollment_id, 23) = 0 THEN 'Absent'
           WHEN MOD(e.enrollment_id, 17) = 0 THEN 'Late'
           WHEN MOD(e.enrollment_id, 29) = 0 THEN 'Excused'
           ELSE 'Present'
       END,
       NULL,
       (SELECT a.account_id FROM Account a WHERE a.lecturer_id = cs.lecturer_id LIMIT 1),
       NOW()
FROM AttendanceSession ats
JOIN ClassSection cs ON cs.section_id = ats.section_id
JOIN Enrollment e ON e.section_id = ats.section_id
                 AND e.status IN ('Enrolled', 'Completed')
WHERE ats.session_date >= DATE_SUB(CURDATE(), INTERVAL 20 DAY)
  AND NOT EXISTS (
      SELECT 1 FROM Attendance x
      WHERE x.session_id = ats.session_id AND x.enrollment_id = e.enrollment_id
  );

-- ── 4. Summary ───────────────────────────────────────────────
SELECT cs.section_id, cs.section_code, c.course_code, l.full_name AS lecturer,
       (SELECT COUNT(*) FROM Enrollment e
        WHERE e.section_id = cs.section_id AND e.status IN ('Enrolled','Completed')) AS enrolled
FROM ClassSection cs
JOIN Course c      ON c.course_id = cs.course_id
JOIN Semester sem  ON sem.semester_id = cs.semester_id
JOIN Lecturer l    ON l.lecturer_id = cs.lecturer_id
WHERE sem.status = 'Active'
ORDER BY cs.section_code;

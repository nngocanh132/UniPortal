-- =============================================================
-- UniPortal – Migration v9
-- Academic catalog (departments / majors / courses),
-- course offerings with structured schedules and capacities,
-- and period-based student registration.
--
-- SAFE / IDEMPOTENT:
--   * only adds tables, columns, indexes and seed rows
--   * never drops or truncates existing data
--   * never touches account passwords or existing enrollments
-- =============================================================
SET NAMES utf8mb4;

-- ── 1. Departments gain an active flag ───────────────────────
ALTER TABLE department
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- ── 2. Majors (a major belongs to a department; it is not a course)
CREATE TABLE IF NOT EXISTS major (
    major_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    major_code    VARCHAR(20)  NOT NULL,
    major_name    VARCHAR(150) NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (major_id),
    UNIQUE KEY uq_major_code (major_code),
    KEY idx_major_department (department_id),
    CONSTRAINT fk_major_department FOREIGN KEY (department_id)
        REFERENCES department (department_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Students and curricula reference a major ──────────────
ALTER TABLE student
    ADD COLUMN IF NOT EXISTS major_id INT UNSIGNED NULL AFTER class_code,
    ADD INDEX IF NOT EXISTS idx_student_major (major_id);

ALTER TABLE curriculum
    ADD COLUMN IF NOT EXISTS major_id INT UNSIGNED NULL AFTER curriculum_name,
    ADD INDEX IF NOT EXISTS idx_curriculum_major (major_id);

-- ── 4. Courses: prerequisite support (optional per course) ───
ALTER TABLE course
    ADD COLUMN IF NOT EXISTS prerequisite_course_id INT UNSIGNED NULL AFTER credits,
    ADD INDEX IF NOT EXISTS idx_course_prerequisite (prerequisite_course_id);

-- ── 5. Course offerings / class sections ────────────────────
ALTER TABLE classsection
    ADD COLUMN IF NOT EXISTS min_enrollment SMALLINT UNSIGNED NOT NULL DEFAULT 48,
    ADD COLUMN IF NOT EXISTS max_enrollment SMALLINT UNSIGNED NOT NULL DEFAULT 64,
    ADD COLUMN IF NOT EXISTS study_days VARCHAR(20) NULL COMMENT 'CSV ISO weekday numbers, e.g. 2,4',
    ADD COLUMN IF NOT EXISTS start_time TIME NULL,
    ADD COLUMN IF NOT EXISTS end_time TIME NULL,
    ADD COLUMN IF NOT EXISTS reg_open DATETIME NULL,
    ADD COLUMN IF NOT EXISTS reg_close DATETIME NULL,
    ADD COLUMN IF NOT EXISTS section_status ENUM('Pending','Confirmed','Insufficient','Cancelled')
        NOT NULL DEFAULT 'Pending',
    ADD COLUMN IF NOT EXISTS status_changed_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS status_note VARCHAR(255) NULL,
    ADD INDEX IF NOT EXISTS idx_section_status (section_status);

-- Keep legacy capacity as the maximum enrollment when it is missing.
UPDATE classsection
SET max_enrollment = COALESCE(capacity, 64),
    capacity       = COALESCE(capacity, max_enrollment)
WHERE capacity IS NULL OR capacity = 0;

-- Existing active/history classes with enough registrations were already open.
UPDATE classsection cs
SET cs.section_status = 'Confirmed',
    cs.status_changed_at = COALESCE(cs.status_changed_at, NOW())
WHERE (SELECT COUNT(*) FROM enrollment e
        WHERE e.section_id = cs.section_id
          AND e.status IN ('Enrolled','Completed'))
      >= FLOOR(cs.min_enrollment / 2) + 1
  AND cs.section_status = 'Pending';

-- ── 6. Registration periods ─────────────────────────────────
CREATE TABLE IF NOT EXISTS registrationperiod (
    period_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    semester_id INT UNSIGNED NOT NULL,
    period_name VARCHAR(150) NOT NULL,
    start_at    DATETIME NOT NULL,
    end_at      DATETIME NOT NULL,
    status      ENUM('Upcoming','Open','Closed') NOT NULL DEFAULT 'Upcoming',
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (period_id),
    KEY idx_period_semester (semester_id),
    KEY idx_period_status (status),
    CONSTRAINT fk_period_semester FOREIGN KEY (semester_id)
        REFERENCES semester (semester_id) ON UPDATE CASCADE,
    CONSTRAINT fk_period_created_by FOREIGN KEY (created_by)
        REFERENCES account (account_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT ck_period_dates CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrationperiodmajor (
    period_id INT UNSIGNED NOT NULL,
    major_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (period_id, major_id),
    KEY idx_rpm_major (major_id),
    CONSTRAINT fk_rpm_period FOREIGN KEY (period_id)
        REFERENCES registrationperiod (period_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rpm_major FOREIGN KEY (major_id)
        REFERENCES major (major_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrationperiodsection (
    period_id  INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (period_id, section_id),
    KEY idx_rps_section (section_id),
    CONSTRAINT fk_rps_period FOREIGN KEY (period_id)
        REFERENCES registrationperiod (period_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rps_section FOREIGN KEY (section_id)
        REFERENCES classsection (section_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Sample catalog: additional academic areas ────────────
INSERT INTO department (department_name, department_code, is_active) VALUES
    ('General Education', 'GE', 1),
    ('Economics', 'ECO', 1),
    ('Marketing', 'MKT', 1),
    ('Management and Business Administration', 'MBA', 1)
ON DUPLICATE KEY UPDATE department_name = VALUES(department_name);

-- Every existing department is also a major; General Education is a
-- cross-curriculum department, not a major.
INSERT IGNORE INTO major (major_code, major_name, department_id, is_active)
SELECT d.department_code, d.department_name, d.department_id, 1
FROM department d
WHERE d.department_code IN ('MIS','BEL','AIT','IB','BDA','AAI','ICE','AC','ECO','MKT','MBA');

UPDATE major m JOIN department d ON d.department_id = m.department_id
SET m.is_active = d.is_active
WHERE m.is_active <> d.is_active;

-- ── 8. Sample curricula for every major / admission year ────
INSERT IGNORE INTO curriculum (curriculum_name, major_id, major_code, admission_year, required_credits)
SELECT CONCAT('Chương trình Cử nhân ', m.major_name, ' – K', y.yr),
       m.major_id, m.major_code, y.yr, 130
FROM major m
JOIN (
    SELECT 2022 AS yr UNION ALL SELECT 2023 UNION ALL SELECT 2024 UNION ALL SELECT 2025
) y
WHERE m.is_active = 1
  AND (y.yr >= 2023 OR m.major_code IN ('MIS','BEL','AIT','IB','BDA','AAI','ICE','AC'));

UPDATE curriculum c JOIN major m ON m.major_code = c.major_code
SET c.major_id = m.major_id
WHERE c.major_id IS NULL;

-- ── 9. Sample courses (realistic demo data – not an official curriculum)
CREATE TEMPORARY TABLE tmp_course_seed (
    course_code VARCHAR(10) NOT NULL PRIMARY KEY,
    course_name VARCHAR(150) NOT NULL,
    credits TINYINT UNSIGNED NOT NULL,
    dept_code VARCHAR(20) NOT NULL,
    description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_course_seed VALUES
    ('GE1001','Academic Skills and Learning Methods',2,'GE','Study skills, academic integrity and learning planning.'),
    ('GE1002','Critical Thinking',3,'GE','Argument analysis, evidence evaluation and logical reasoning.'),
    ('GE1003','Digital Literacy',3,'GE','Digital tools, information literacy and responsible technology use.'),
    ('GE1004','Ethics and Civic Responsibility',2,'GE','Professional ethics, civic duties and social responsibility.'),
    ('GE1005','Communication Skills',3,'GE','Academic and professional written and oral communication.'),
    ('GE1006','Introduction to Statistics',3,'GE','Descriptive statistics, probability and statistical inference.'),

    ('ECO2101','Microeconomics',3,'ECO','Consumer and firm behaviour, market structures and pricing.'),
    ('ECO2102','Macroeconomics',3,'ECO','National income, inflation, unemployment and macroeconomic policy.'),
    ('ECO2201','Development Economics',3,'ECO','Growth, inequality and development policy in emerging economies.'),
    ('ECO3101','Econometrics',3,'ECO','Regression models and empirical analysis of economic data.'),
    ('ECO3201','Public Economics',3,'ECO','Public goods, taxation and government expenditure.'),

    ('MKT2101','Principles of Marketing',3,'MKT','Segmentation, targeting, positioning and the marketing mix.'),
    ('MKT2201','Consumer Behaviour',3,'MKT','Psychological and social factors in consumer decision making.'),
    ('MKT3101','Digital Marketing',3,'MKT','Search, social, content and performance marketing.'),
    ('MKT3201','Brand Management',3,'MKT','Brand equity, identity and brand portfolio strategy.'),
    ('MKT3301','Marketing Analytics',3,'MKT','Measuring campaign performance and customer value.'),

    ('MBA2101','Principles of Management',3,'MBA','Planning, organising, leading and controlling organisations.'),
    ('MBA2201','Organisational Behaviour',3,'MBA','Individual and group behaviour in the workplace.'),
    ('MBA3101','Strategic Management',3,'MBA','Competitive analysis, strategy formulation and execution.'),
    ('MBA3201','Operations Management',3,'MBA','Process design, capacity, quality and supply operations.'),
    ('MBA3301','Entrepreneurship',3,'MBA','Opportunity recognition, business models and venture planning.'),

    ('MIS2201','Business Process Management',3,'MIS','Modelling, analysing and improving business processes.'),
    ('MIS3101','Enterprise Systems',3,'MIS','ERP concepts, integration and enterprise data flows.'),
    ('MIS3201','IT Project Management',3,'MIS','Project planning, risk, quality and stakeholder management.'),
    ('MIS3301','Business Intelligence',3,'MIS','Data warehousing, dashboards and decision support.'),
    ('MIS3401','E-commerce Systems',3,'MIS','Online business models, platforms and payment systems.'),

    ('AC2201','Managerial Accounting',3,'AC','Cost behaviour, budgeting and performance measurement.'),
    ('AC3101','Auditing',3,'AC','Audit planning, evidence, risk and professional judgement.'),
    ('AC3201','Corporate Finance',3,'AC','Capital budgeting, financing and valuation decisions.'),
    ('AC3301','Taxation',3,'AC','Personal and corporate taxation principles and compliance.'),
    ('AC3401','Financial Reporting',3,'AC','Preparation and interpretation of financial statements.'),

    ('BEL2201','Academic Writing',3,'BEL','Essay structure, citation and academic style.'),
    ('BEL3101','Business Communication',3,'BEL','Meetings, negotiation, email and professional presentations.'),
    ('BEL3201','Intercultural Communication',3,'BEL','Cross-cultural communication in international workplaces.'),
    ('BEL3301','Translation Practice',3,'BEL','Vietnamese–English translation techniques and quality.'),
    ('BEL3401','English for Careers',3,'BEL','Workplace English for interviews, reports and careers.'),

    ('AIT2201','Object-Oriented Programming',3,'AIT','Classes, inheritance, polymorphism and design principles.'),
    ('AIT3101','Mobile Application Development',3,'AIT','Mobile UI, state management and device APIs.'),
    ('AIT3201','Cloud Computing',3,'AIT','Cloud models, virtualisation, containers and deployment.'),
    ('AIT3301','Cybersecurity Fundamentals',3,'AIT','Threat models, access control and secure development.'),
    ('AIT3401','Software Engineering',3,'AIT','Requirements, architecture, testing and delivery practices.'),

    ('IB2201','Global Trade',3,'IB','Trade theory, policy and international market entry.'),
    ('IB3101','International Finance',3,'IB','Exchange rates, hedging and international capital flows.'),
    ('IB3201','Cross-border E-commerce',3,'IB','International marketplaces, logistics and digital trade.'),
    ('IB3301','International Marketing',3,'IB','Adapting marketing strategy across national markets.'),
    ('IB3401','Supply Chain Management',3,'IB','Sourcing, logistics, inventory and supply coordination.'),

    ('BDA2201','Data Visualisation',3,'BDA','Visual encoding, dashboards and data storytelling.'),
    ('BDA3101','Machine Learning Basics',3,'BDA','Supervised and unsupervised learning fundamentals.'),
    ('BDA3201','Big Data Platforms',3,'BDA','Distributed storage and processing for large datasets.'),
    ('BDA3301','Business Forecasting',3,'BDA','Time-series analysis and demand forecasting.'),
    ('BDA3401','Data Governance',3,'BDA','Data quality, privacy, lineage and stewardship.'),

    ('AAI2201','Control Systems',3,'AAI','Feedback control, stability and controller design.'),
    ('AAI3101','Robotics Fundamentals',3,'AAI','Robot kinematics, sensors and motion planning.'),
    ('AAI3201','IoT Systems',3,'AAI','Connected devices, protocols and edge processing.'),
    ('AAI3301','Industrial Automation',3,'AAI','PLC, SCADA and automated production systems.'),
    ('AAI3401','AI Applications',3,'AAI','Applied machine learning for automation and control.'),

    ('ICE2201','Computer Networks',3,'ICE','Network models, routing, switching and security basics.'),
    ('ICE3101','Operating Systems',3,'ICE','Processes, memory, file systems and concurrency.'),
    ('ICE3201','Embedded Systems',3,'ICE','Microcontrollers, real-time constraints and interfacing.'),
    ('ICE3301','Software Architecture',3,'ICE','Architectural styles, quality attributes and trade-offs.'),
    ('ICE3401','Computer Graphics',3,'ICE','Rendering, transformations and graphical pipelines.');

INSERT INTO course (course_code, course_name, credits, department_id, description, is_active)
SELECT t.course_code, t.course_name, t.credits, d.department_id, t.description, 1
FROM tmp_course_seed t
JOIN department d ON d.department_code = t.dept_code
ON DUPLICATE KEY UPDATE
    course_name  = VALUES(course_name),
    credits      = VALUES(credits),
    department_id= VALUES(department_id),
    description  = VALUES(description);

DROP TEMPORARY TABLE tmp_course_seed;

-- Configure a few prerequisites so the registration rules can be exercised.
UPDATE course c JOIN course p ON p.course_code = 'MKT2101'
SET c.prerequisite_course_id = p.course_id WHERE c.course_code = 'MKT3101';
UPDATE course c JOIN course p ON p.course_code = 'AIT2101'
SET c.prerequisite_course_id = p.course_id WHERE c.course_code = 'AIT3101';
UPDATE course c JOIN course p ON p.course_code = 'BDA2101'
SET c.prerequisite_course_id = p.course_id WHERE c.course_code = 'BDA3101';

-- ── 10. Curricula contain department courses + shared GE courses
INSERT IGNORE INTO curriculumcourse (curriculum_id, course_id, course_type, semester_recommended, is_required)
SELECT cur.curriculum_id, c.course_id, 'Required', 1 + MOD(c.course_id, 6), 1
FROM curriculum cur
JOIN major m        ON m.major_id = cur.major_id
JOIN course c       ON c.is_active = 1
JOIN department cd  ON cd.department_id = c.department_id
WHERE cd.department_code = m.major_code
   OR cd.department_code = 'GE';

-- ── 11. Additional upcoming semester for registration demonstration
INSERT INTO semester (semester_name, academic_year, start_date, end_date, status, reg_open, reg_close)
SELECT 'Học kỳ 2', '2026-2027', '2027-02-01', '2027-06-18', 'Upcoming',
       '2026-12-01 00:00:00', '2027-01-10 00:00:00'
WHERE NOT EXISTS (
    SELECT 1 FROM semester WHERE semester_name = 'Học kỳ 2' AND academic_year = '2026-2027'
);

-- ── 12. Sample course offerings for the active and upcoming semesters
CREATE TEMPORARY TABLE tmp_section_seed (
    section_code VARCHAR(20) NOT NULL,
    course_code  VARCHAR(10) NOT NULL,
    sem_name     VARCHAR(50) NOT NULL,
    sem_year     VARCHAR(9)  NOT NULL,
    lecturer_id  INT UNSIGNED NOT NULL,
    room         VARCHAR(30) NOT NULL,
    min_enr      SMALLINT UNSIGNED NOT NULL,
    max_enr      SMALLINT UNSIGNED NOT NULL,
    study_days   VARCHAR(20) NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    schedule_txt VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_section_seed VALUES
    ('GE1002-01','GE1002','Học kỳ 1','2026-2027',8,'A1.101',48,64,'4','07:30:00','09:00:00','Thứ 4 07:30-09:00'),
    ('GE1003-01','GE1003','Học kỳ 1','2026-2027',6,'A1.102',48,64,'5','09:15:00','10:45:00','Thứ 6 09:15-10:45'),
    ('ECO2101-01','ECO2101','Học kỳ 1','2026-2027',4,'B2.401',48,64,'2,4','07:30:00','09:00:00','Thứ 2 & 4 07:30-09:00'),
    ('MKT2101-01','MKT2101','Học kỳ 1','2026-2027',2,'C2.105',30,64,'2,4','09:15:00','10:45:00','Thứ 2 & 4 09:15-10:45'),
    ('MBA2101-01','MBA2101','Học kỳ 1','2026-2027',1,'B3.301',60,64,'2,6','13:00:00','14:30:00','Thứ 2 & 6 13:00-14:30'),
    ('MIS2201-01','MIS2201','Học kỳ 1','2026-2027',1,'B1.401',48,64,'3,5','13:00:00','14:30:00','Thứ 3 & 5 13:00-14:30'),
    ('AIT2201-01','AIT2201','Học kỳ 1','2026-2027',3,'C2.201',48,64,'2,4','09:15:00','10:45:00','Thứ 2 & 4 09:15-10:45'),
    ('BDA2201-01','BDA2201','Học kỳ 1','2026-2027',5,'B2.205',48,64,'3,5','07:30:00','09:00:00','Thứ 3 & 5 07:30-09:00'),
    ('AC2201-01','AC2201','Học kỳ 1','2026-2027',8,'B3.105',48,64,'4,6','13:00:00','14:30:00','Thứ 4 & 6 13:00-14:30'),
    ('BEL2201-01','BEL2201','Học kỳ 1','2026-2027',2,'C1.202',48,64,'2,4','15:00:00','16:30:00','Thứ 2 & 4 15:00-16:30'),
    ('IB2201-01','IB2201','Học kỳ 1','2026-2027',4,'B1.105',48,64,'3,6','09:15:00','10:45:00','Thứ 3 & 6 09:15-10:45'),
    ('ICE2201-01','ICE2201','Học kỳ 1','2026-2027',7,'B1.203',48,64,'2,5','07:30:00','09:00:00','Thứ 2 & 5 07:30-09:00'),
    ('AAI2201-01','AAI2201','Học kỳ 1','2026-2027',6,'C3.102',48,64,'4,6','07:30:00','09:00:00','Thứ 4 & 6 07:30-09:00'),
    ('GE1002-02','GE1002','Học kỳ 2','2026-2027',8,'A1.101',48,64,'2,4','07:30:00','09:00:00','Thứ 2 & 4 07:30-09:00'),
    ('MKT2101-02','MKT2101','Học kỳ 2','2026-2027',2,'C2.105',30,64,'3,5','09:15:00','10:45:00','Thứ 3 & 5 09:15-10:45'),
    ('AIT2201-02','AIT2201','Học kỳ 2','2026-2027',3,'C2.201',48,64,'4,6','13:00:00','14:30:00','Thứ 4 & 6 13:00-14:30');

INSERT INTO classsection
    (course_id, semester_id, lecturer_id, section_code, schedule, room, capacity,
     min_enrollment, max_enrollment, study_days, start_time, end_time,
     section_status, reg_open, reg_close)
SELECT c.course_id, sem.semester_id, t.lecturer_id, t.section_code, t.schedule_txt,
       t.room, t.max_enr, t.min_enr, t.max_enr, t.study_days, t.start_time, t.end_time,
       'Pending', sem.reg_open, sem.reg_close
FROM tmp_section_seed t
JOIN course c  ON c.course_code = t.course_code
JOIN semester sem ON sem.semester_name = t.sem_name AND sem.academic_year = t.sem_year
LEFT JOIN classsection existing
       ON existing.section_code = t.section_code AND existing.semester_id = sem.semester_id
WHERE existing.section_id IS NULL;

DROP TEMPORARY TABLE tmp_section_seed;

-- ── 13. One open period (current semester) and one upcoming period
INSERT INTO registrationperiod (semester_id, period_name, start_at, end_at, status, created_by)
SELECT s.semester_id,
       CONCAT('Đợt đăng ký ', s.semester_name, ' ', s.academic_year),
       '2026-08-01 00:00:00', '2027-01-01 23:59:59', 'Open',
       (SELECT account_id FROM account WHERE username = 'academic_admin' LIMIT 1)
FROM semester s
WHERE s.semester_name = 'Học kỳ 1' AND s.academic_year = '2026-2027'
  AND NOT EXISTS (
      SELECT 1 FROM registrationperiod rp
      WHERE rp.semester_id = s.semester_id AND rp.period_name LIKE 'Đợt đăng ký %'
  );

INSERT INTO registrationperiod (semester_id, period_name, start_at, end_at, status, created_by)
SELECT s.semester_id,
       CONCAT('Đợt đăng ký ', s.semester_name, ' ', s.academic_year),
       '2026-12-01 00:00:00', '2027-01-10 23:59:59', 'Upcoming',
       (SELECT account_id FROM account WHERE username = 'academic_admin' LIMIT 1)
FROM semester s
WHERE s.semester_name = 'Học kỳ 2' AND s.academic_year = '2026-2027'
  AND NOT EXISTS (
      SELECT 1 FROM registrationperiod rp
      WHERE rp.semester_id = s.semester_id AND rp.period_name LIKE 'Đợt đăng ký %'
  );

-- All active majors may register in both demo periods.
INSERT IGNORE INTO registrationperiodmajor (period_id, major_id)
SELECT rp.period_id, m.major_id
FROM registrationperiod rp
JOIN major m ON m.is_active = 1
WHERE rp.period_name LIKE 'Đợt đăng ký %';

-- Every section of the matching semester is selectable in that period.
INSERT IGNORE INTO registrationperiodsection (period_id, section_id)
SELECT rp.period_id, cs.section_id
FROM registrationperiod rp
JOIN classsection cs ON cs.semester_id = rp.semester_id
WHERE rp.period_name LIKE 'Đợt đăng ký %';

-- ── 14. Backfill student / curriculum major references ──────
UPDATE student s
JOIN major m ON s.class_code LIKE CONCAT(m.major_code, '%')
SET s.major_id = m.major_id
WHERE s.major_id IS NULL;

UPDATE student s
JOIN major m ON m.department_id = s.department_id
SET s.major_id = m.major_id
WHERE s.major_id IS NULL;

-- ── 15. Foreign keys for major references (added conditionally)
SET @fk_student_major := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_student_major');
SET @sql := IF(@fk_student_major = 0,
    'ALTER TABLE student ADD CONSTRAINT fk_student_major FOREIGN KEY (major_id) REFERENCES major (major_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_curriculum_major := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_curriculum_major');
SET @sql := IF(@fk_curriculum_major = 0,
    'ALTER TABLE curriculum ADD CONSTRAINT fk_curriculum_major FOREIGN KEY (major_id) REFERENCES major (major_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_course_prereq := (
    SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_course_prereq');
SET @sql := IF(@fk_course_prereq = 0,
    'ALTER TABLE course ADD CONSTRAINT fk_course_prereq FOREIGN KEY (prerequisite_course_id) REFERENCES course (course_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── 16. Refresh stored period status against the clock ──────
UPDATE registrationperiod
SET status = CASE
    WHEN NOW() < start_at THEN 'Upcoming'
    WHEN NOW() > end_at   THEN 'Closed'
    ELSE 'Open'
END;

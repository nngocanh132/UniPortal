USE uniportal;

ALTER TABLE Assignment
    ADD COLUMN IF NOT EXISTS allow_resubmission TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE Submission
    ADD COLUMN IF NOT EXISTS content TEXT NULL,
    ADD COLUMN IF NOT EXISTS revision INT UNSIGNED NOT NULL DEFAULT 1;

SET @attachment_version_missing = (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'submissionattachment' AND COLUMN_NAME = 'version'
);
ALTER TABLE SubmissionAttachment
    ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1;
UPDATE SubmissionAttachment sa
JOIN Submission s ON s.submission_id = sa.submission_id
SET sa.version = s.version
WHERE @attachment_version_missing = 1;
ALTER TABLE SubmissionAttachment
    ADD INDEX IF NOT EXISTS idx_submission_attachment_version (submission_id, version);

CREATE TABLE IF NOT EXISTS SubmissionAttempt (
    attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    content TEXT NULL,
    file_path VARCHAR(500) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT NULL,
    file_mime VARCHAR(100) NULL,
    submitted_at DATETIME NULL,
    score DECIMAL(5,2) NULL,
    feedback TEXT NULL,
    grade_status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_attempt (submission_id, version),
    CONSTRAINT fk_attempt_submission FOREIGN KEY (submission_id)
        REFERENCES Submission(submission_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE SubmissionGradeHistory
    ADD COLUMN IF NOT EXISTS submission_version INT UNSIGNED NULL;

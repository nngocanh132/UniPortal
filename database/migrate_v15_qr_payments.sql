-- ============================================================================
-- UniPortal – Migration v15
-- Automatic VietQR tuition payments + bank transaction verification.
--
-- Adds:
--   * PaymentRequest  – one QR payment request per invoice/amount, unique
--                       transfer reference, expiry and audit fields.
--   * BankTransaction – raw provider webhook transactions, idempotent by
--                       (provider, provider_transaction_id), unmatched review.
--   * Payment         – provider/transaction/confirmation columns so verified
--                       bank transfers are recorded next to legacy payments.
--   * SystemConfig    – non-secret payment configuration keys.
--
-- Idempotent: safe to run repeatedly. Never drops tables/columns, never
-- modifies existing invoices or payment rows.
--
-- Run:
--   C:/xampp/mysql/bin/mysql.exe -u root uniportal < database/migrate_v15_qr_payments.sql
-- ============================================================================

USE uniportal;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Non-secret payment configuration (secrets live in .env, never in DB)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO SystemConfig (config_key, config_value, description) VALUES
    ('payment_provider',          'sepay',
        'Bank transaction verification provider (sepay)'),
    ('payment_bank_bin',          '970432',
        'VietQR bank BIN used in the QR payload (VPBank default – replace with the school account BIN)'),
    ('payment_bank_code',         'VPBank',
        'Short bank code shown to students'),
    ('payment_bank_name',         'Ngân hàng TMCP Việt Nam Thịnh Vượng',
        'Bank display name shown to students'),
    ('payment_account_number',    '123456789',
        'Receiving bank account number – replace with the real school account'),
    ('payment_account_holder',    'VNUIS ACADEMIC',
        'Account holder name encoded in the VietQR payload (no diacritics recommended)'),
    ('payment_request_ttl_minutes','1440',
        'Lifetime of a QR payment request before it is refreshed'),
    ('payment_overpayment_policy','review',
        'review = flag excess amount for Admin review; no excess is discarded'),
    ('payment_partial_allowed',   '1',
        '1 = accept partial transfers and keep the remaining balance payable'),
    ('payment_webhook_enabled',   '0',
        '1 = accept live provider webhooks (requires .env credentials)'),
    ('payment_min_transfer_vnd',  '1000',
        'Minimum accepted transfer amount in VND for QR payments');

-- ---------------------------------------------------------------------------
-- 2. QR payment requests (one identifiable reference per invoice/amount)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS PaymentRequest (
    payment_request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tuition_id         INT UNSIGNED NOT NULL,
    student_id         CHAR(8) NOT NULL,
    semester_id        INT UNSIGNED NOT NULL,
    amount_vnd         BIGINT UNSIGNED NOT NULL,
    reference          VARCHAR(40) NOT NULL COMMENT 'Unique transfer description encoded in the QR',
    provider           VARCHAR(30) NOT NULL DEFAULT 'sepay',
    bank_bin           VARCHAR(10) NOT NULL,
    bank_code          VARCHAR(30) NOT NULL DEFAULT '',
    account_number     VARCHAR(50) NOT NULL,
    account_holder     VARCHAR(100) NOT NULL,
    qr_payload         TEXT NULL COMMENT 'EMVCo/VietQR payload used to render the QR image',
    status             ENUM('Active','Completed','Expired','Cancelled') NOT NULL DEFAULT 'Active',
    expires_at         DATETIME NOT NULL,
    created_by         INT UNSIGNED NULL COMMENT 'account_id that triggered generation, NULL = system',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_request_id),
    UNIQUE KEY uq_payment_request_reference (reference),
    KEY idx_pr_tuition_status (tuition_id, status),
    KEY idx_pr_student (student_id),
    KEY idx_pr_expires (expires_at),
    CONSTRAINT fk_pr_tuition  FOREIGN KEY (tuition_id)  REFERENCES Tuition (tuition_id)  ON UPDATE CASCADE,
    CONSTRAINT fk_pr_student  FOREIGN KEY (student_id)  REFERENCES Student (student_id)  ON UPDATE CASCADE,
    CONSTRAINT fk_pr_semester FOREIGN KEY (semester_id) REFERENCES Semester (semester_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Dynamic QR payment requests tied to a tuition invoice';

-- ---------------------------------------------------------------------------
-- 3. Extend Payment with provider / verification data
-- ---------------------------------------------------------------------------
ALTER TABLE Payment
    ADD COLUMN IF NOT EXISTS payment_request_id INT UNSIGNED NULL AFTER tuition_id,
    ADD COLUMN IF NOT EXISTS provider VARCHAR(30) NULL AFTER method,
    ADD COLUMN IF NOT EXISTS provider_transaction_id VARCHAR(100) NULL
        COMMENT 'Provider transaction id – unique with provider, blocks replay' AFTER provider,
    ADD COLUMN IF NOT EXISTS bank_reference VARCHAR(100) NULL AFTER reference_no,
    ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER reviewed_at,
    ADD COLUMN IF NOT EXISTS is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmed_at,
    ADD COLUMN IF NOT EXISTS overpaid_vnd BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_test,
    ADD COLUMN IF NOT EXISTS review_status ENUM('None','Review','Resolved') NOT NULL DEFAULT 'None' AFTER overpaid_vnd;

ALTER TABLE Payment
    ADD INDEX IF NOT EXISTS idx_payment_request (payment_request_id),
    ADD INDEX IF NOT EXISTS idx_payment_provider_txn (provider, provider_transaction_id),
    ADD INDEX IF NOT EXISTS idx_payment_confirmed (confirmed_at);

-- Unique provider transaction id (NULLs are allowed and never collide).
DROP PROCEDURE IF EXISTS sp_migrate_v15_payment_keys;
DELIMITER $$
CREATE PROCEDURE sp_migrate_v15_payment_keys()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Payment'
          AND INDEX_NAME = 'uq_payment_provider_txn'
    ) THEN
        ALTER TABLE Payment
            ADD UNIQUE KEY uq_payment_provider_txn (provider, provider_transaction_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'Payment'
          AND CONSTRAINT_NAME = 'fk_payment_request'
    ) THEN
        ALTER TABLE Payment
            ADD CONSTRAINT fk_payment_request FOREIGN KEY (payment_request_id)
            REFERENCES PaymentRequest (payment_request_id)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END$$
DELIMITER ;
CALL sp_migrate_v15_payment_keys();
DROP PROCEDURE sp_migrate_v15_payment_keys;

-- ---------------------------------------------------------------------------
-- 4. Verified bank transactions (webhook inbox + Admin review queue)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS BankTransaction (
    bank_transaction_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider                VARCHAR(30) NOT NULL,
    provider_transaction_id VARCHAR(100) NOT NULL COMMENT 'SePay id / bank API transaction id',
    gateway                 VARCHAR(100) NULL,
    account_number          VARCHAR(50) NULL,
    sub_account             VARCHAR(50) NULL,
    code                    VARCHAR(100) NULL,
    content                 VARCHAR(500) NULL,
    description             VARCHAR(500) NULL,
    transfer_type           ENUM('in','out','unknown') NOT NULL DEFAULT 'unknown',
    transfer_amount         BIGINT NOT NULL DEFAULT 0,
    accumulated             BIGINT NULL,
    reference_code          VARCHAR(100) NULL COMMENT 'Bank reference code',
    transaction_date        DATETIME NULL,
    status                  ENUM('Processed','Unmatched','Ignored','Rejected','Duplicate')
                                NOT NULL DEFAULT 'Unmatched',
    match_note              VARCHAR(500) NULL,
    payment_request_id      INT UNSIGNED NULL,
    payment_id              INT UNSIGNED NULL,
    reviewed_by             INT UNSIGNED NULL,
    reviewed_at             DATETIME NULL,
    is_test                 TINYINT(1) NOT NULL DEFAULT 0,
    raw_payload             MEDIUMTEXT NULL,
    received_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at            DATETIME NULL,
    PRIMARY KEY (bank_transaction_id),
    UNIQUE KEY uq_bank_provider_txn (provider, provider_transaction_id),
    KEY idx_bank_status (status),
    KEY idx_bank_received (received_at),
    KEY idx_bank_xdate (transaction_date),
    KEY idx_bank_request (payment_request_id),
    CONSTRAINT fk_bank_request FOREIGN KEY (payment_request_id)
        REFERENCES PaymentRequest (payment_request_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_bank_payment FOREIGN KEY (payment_id)
        REFERENCES Payment (payment_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Raw bank/provider transactions received through verified webhooks';

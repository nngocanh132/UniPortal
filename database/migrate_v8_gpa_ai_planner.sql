-- ============================================================
-- UniPortal – GPA Planner / AI Academic Advisor
-- Migration v8
--
-- Adds:
--   * SystemConfig keys   – retake policy, GPA exclusions, planning size,
--                           optional AI provider configuration
--   * GPAPlanSimulation   – saved what-if scenarios (retakes + future GPA)
--
-- The existing GPA rules are NOT changed:
--   * Grade.score is 0–100, final score = SUM(score × weight%) ÷ 100 ÷ 10
--   * GradeScale maps a 0–10 final score to a 4-point grade
--   * Cumulative GPA = SUM(grade_point × credits) ÷ SUM(credits)
--   * Only Completed enrollments with grades contribute
--
-- Retake policy is configurable because the project did not previously
-- define one. The default ('count_all') matches the existing calculation,
-- which counts every completed attempt.
--
-- Safe to run once; guarded to be re-runnable. Does not drop data.
-- Run: mysql -u root uniportal < database/migrate_v8_gpa_ai_planner.sql
-- ============================================================

USE uniportal;
SET NAMES utf8mb4;

-- ── 1. Policy / AI configuration (never overwrite existing values) ──
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

-- ── 2. Saved what-if simulations ────────────────────────────
CREATE TABLE IF NOT EXISTS GPAPlanSimulation (
    simulation_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id           CHAR(8) NOT NULL,
    scenario_name        VARCHAR(150) NOT NULL,
    target_gpa           DECIMAL(3,2) NOT NULL,
    future_gpa           DECIMAL(3,2) NOT NULL,
    total_future_credits SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    improvement_credits  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retakes_json         JSON DEFAULT NULL,
    expected_gpa         DECIMAL(3,2) DEFAULT NULL,
    required_future_gpa  DECIMAL(7,3) DEFAULT NULL,
    feasibility          VARCHAR(20) NOT NULL DEFAULT 'achievable',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (simulation_id),
    KEY idx_gpa_sim_student (student_id, created_at),
    CONSTRAINT fk_gpa_sim_student FOREIGN KEY (student_id)
        REFERENCES Student (student_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT ck_gpa_sim_target CHECK (target_gpa BETWEEN 0 AND 4),
    CONSTRAINT ck_gpa_sim_future CHECK (future_gpa BETWEEN 0 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

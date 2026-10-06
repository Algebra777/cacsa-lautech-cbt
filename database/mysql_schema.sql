-- Berevion MySQL storage schema (Phase 1 established the tenant boundary).
-- Every table is institution-scoped. Historical foreign keys deliberately RESTRICT deletion.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS institutions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  deleted_at DATETIME(6) NULL,
  deleted_by VARCHAR(254) NULL,
  deletion_reason TEXT NULL,
  retention_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_institutions_slug (slug), KEY idx_institutions_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One immutable-in-shape branding record per institution. Values are data, not
-- PHP constants, so a future provisioning flow can create a fully branded
-- tenant without changing the application source.
CREATE TABLE IF NOT EXISTS institution_branding (
  institution_id INT UNSIGNED NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  portal_title VARCHAR(190) NOT NULL,
  logo_path VARCHAR(500) NOT NULL,
  favicon_path VARCHAR(500) NOT NULL,
  -- The only colour an institution chooses. The two rendered variants are
  -- derived from it and stored for deterministic, accessible theming.
  accent_source_color CHAR(7) NOT NULL DEFAULT '#16774d',
  primary_color CHAR(7) NOT NULL,
  accent_color CHAR(7) NOT NULL,
  nav_label VARCHAR(190) NOT NULL,
  assessment_label VARCHAR(190) NOT NULL,
  footer_primary VARCHAR(255) NOT NULL,
  footer_secondary VARCHAR(255) NOT NULL,
  footer_legal VARCHAR(255) NOT NULL,
  result_sheet_title VARCHAR(190) NOT NULL,
  newsletter_sender_name VARCHAR(190) NOT NULL,
  support_email VARCHAR(254) NULL,
  updated_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id),
  CONSTRAINT fk_institution_branding_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform identities are intentionally not tenant-owned. Keeping them out of
-- admin_users makes cross-institution authority structural, not an accidental
-- permission granted to an institution administrator.
CREATE TABLE IF NOT EXISTS platform_admin_users (
  id VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL,
  phone_number VARCHAR(80) NULL, password_hash VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
  verified TINYINT(1) NOT NULL DEFAULT 1, must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  profile_json JSON NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_platform_admin_singleton (id), UNIQUE KEY uq_platform_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_admin_sessions (
  token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL,
  name VARCHAR(255) NOT NULL, correlation_id VARCHAR(190) NULL, created_at DATETIME(6) NOT NULL,
  last_seen_at DATETIME(6) NULL, expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (token_hash), KEY ix_platform_sessions_user (user_id, expires_at), KEY ix_platform_sessions_expiry (expires_at),
  CONSTRAINT fk_platform_sessions_user FOREIGN KEY (user_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_admin_two_factor_challenges (
  token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL,
  password_rate_limit_key CHAR(64) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (token_hash), KEY ix_platform_2fa_expiry (expires_at),
  CONSTRAINT fk_platform_2fa_user FOREIGN KEY (user_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform traffic is deliberately outside every tenant's rate-limit state.
CREATE TABLE IF NOT EXISTS platform_rate_limit_records (
  legacy_key CHAR(64) NOT NULL, count_value INT UNSIGNED NOT NULL,
  reset_at DATETIME(6) NOT NULL, payload_json JSON NOT NULL,
  PRIMARY KEY (legacy_key), KEY ix_platform_rate_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_events (
  id VARCHAR(128) NOT NULL, timestamp_at DATETIME(6) NOT NULL, actor_id VARCHAR(254) NULL,
  action_type VARCHAR(150) NOT NULL, target_institution_id INT UNSIGNED NULL,
  target_type VARCHAR(100) NOT NULL, target_id VARCHAR(190) NULL, outcome VARCHAR(30) NULL,
  correlation_id VARCHAR(190) NULL, ip_address VARCHAR(64) NULL, user_agent TEXT NULL,
  before_after_json JSON NULL, metadata_json JSON NULL,
  PRIMARY KEY (id), KEY ix_platform_audit_timestamp (timestamp_at), KEY ix_platform_audit_target (target_institution_id, timestamp_at),
  -- A removed disposable institution must not erase its platform audit trail.
  -- Preserve the event and clear only the now-invalid optional target pointer.
  CONSTRAINT fk_platform_audit_institution FOREIGN KEY (target_institution_id) REFERENCES institutions (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform identity is intentionally distinct from institution_branding. A
-- tenant (including CACSA) never inherits or overwrites this record.
CREATE TABLE IF NOT EXISTS platform_branding (
  id TINYINT UNSIGNED NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  tagline VARCHAR(255) NOT NULL,
  logo_path VARCHAR(500) NOT NULL,
  favicon_path VARCHAR(500) NOT NULL,
  accent_source_color CHAR(7) NOT NULL,
  primary_color CHAR(7) NOT NULL,
  accent_color CHAR(7) NOT NULL,
  navy_color CHAR(7) NOT NULL DEFAULT '#00205D',
  mid_blue_color CHAR(7) NOT NULL DEFAULT '#024DB2',
  bright_blue_color CHAR(7) NOT NULL DEFAULT '#0094FE',
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_migrations (
  institution_id INT UNSIGNED NOT NULL, migration_key VARCHAR(120) NOT NULL, source_sha256 CHAR(64) NULL,
  status VARCHAR(30) NOT NULL, details_json JSON NULL, started_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id, migration_key),
  CONSTRAINT fk_storage_migrations_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_sessions (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, label VARCHAR(190) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_academic_sessions_active (institution_id,is_active), KEY ix_academic_sessions_label (institution_id,label),
  CONSTRAINT fk_academic_sessions_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_semesters (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, session_id VARCHAR(128) NOT NULL, label VARCHAR(190) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0,
  start_date DATE NULL, end_date DATE NULL, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_semesters_session (institution_id,session_id), KEY ix_semesters_active (institution_id,is_active),
  CONSTRAINT fk_semesters_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_semesters_session FOREIGN KEY (institution_id,session_id) REFERENCES academic_sessions (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, session_id VARCHAR(128) NULL, semester_id VARCHAR(128) NULL,
  code VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NULL, category VARCHAR(190) NULL, course_unit INT UNSIGNED NOT NULL DEFAULT 3,
  test_max_mark DECIMAL(8,2) NOT NULL DEFAULT 0, exam_max_mark DECIMAL(8,2) NOT NULL DEFAULT 0, legacy_exam_only TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_courses_code (institution_id,code), KEY ix_courses_period (institution_id,session_id,semester_id), KEY ix_courses_category (institution_id,category),
  CONSTRAINT fk_courses_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_courses_session FOREIGN KEY (institution_id,session_id) REFERENCES academic_sessions (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_courses_semester FOREIGN KEY (institution_id,semester_id) REFERENCES academic_semesters (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_components (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, course_id VARCHAR(128) NOT NULL, component ENUM('test','exam') NOT NULL,
  code VARCHAR(100) NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NULL, category VARCHAR(190) NULL, duration_minutes INT UNSIGNED NOT NULL,
  max_mark DECIMAL(8,2) NOT NULL, pass_threshold DECIMAL(8,2) NULL, question_count INT UNSIGNED NOT NULL, start_at DATETIME(6) NULL, end_at DATETIME(6) NULL,
  status VARCHAR(40) NOT NULL, legacy_exam_only TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_components_course (institution_id,course_id,component), KEY ix_components_window (institution_id,status,start_at,end_at),
  CONSTRAINT fk_components_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_components_course FOREIGN KEY (institution_id,course_id) REFERENCES courses (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, full_name VARCHAR(255) NOT NULL, matric_number VARCHAR(190) NOT NULL,
  email VARCHAR(254) NOT NULL, phone_number VARCHAR(80) NULL, department VARCHAR(190) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), UNIQUE KEY uq_students_matric (institution_id,matric_number), KEY ix_students_email (institution_id,email), KEY ix_students_department_active (institution_id,department,active),
  CONSTRAINT fk_students_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, course_id VARCHAR(128) NOT NULL, legacy_component_id VARCHAR(128) NULL,
  text LONGTEXT NOT NULL, type VARCHAR(30) NOT NULL, topic VARCHAR(190) NULL, difficulty VARCHAR(60) NULL, status VARCHAR(30) NOT NULL,
  PRIMARY KEY (institution_id,id), KEY ix_questions_course_status (institution_id,course_id,status), KEY ix_questions_topic (institution_id,topic),
  CONSTRAINT fk_questions_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_questions_course FOREIGN KEY (institution_id,course_id) REFERENCES courses (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_options (
  institution_id INT UNSIGNED NOT NULL, question_id VARCHAR(128) NOT NULL, option_index SMALLINT UNSIGNED NOT NULL, option_text LONGTEXT NOT NULL, is_correct TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (institution_id,question_id,option_index),
  CONSTRAINT fk_question_options_question FOREIGN KEY (institution_id,question_id) REFERENCES questions (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_publish_targets (
  institution_id INT UNSIGNED NOT NULL, question_id VARCHAR(128) NOT NULL, target_component ENUM('test','exam') NOT NULL,
  PRIMARY KEY (institution_id,question_id,target_component), KEY ix_question_targets_target (institution_id,target_component),
  CONSTRAINT fk_question_targets_question FOREIGN KEY (institution_id,question_id) REFERENCES questions (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_passwords (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, student_id VARCHAR(128) NOT NULL, component_id VARCHAR(128) NOT NULL,
  password_hash VARCHAR(255) NOT NULL, created_at DATETIME(6) NULL, expires_at DATETIME(6) NULL, used_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_passwords_student_component (institution_id,student_id,component_id), KEY ix_passwords_expiry (institution_id,expires_at),
  CONSTRAINT fk_passwords_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_passwords_component FOREIGN KEY (institution_id,component_id) REFERENCES course_components (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_login_tokens (
  institution_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, student_id VARCHAR(128) NOT NULL, component_id VARCHAR(128) NOT NULL, password_id VARCHAR(128) NULL,
  correlation_id VARCHAR(190) NULL, device_fingerprint VARCHAR(190) NULL, expires_at DATETIME(6) NOT NULL, used_at DATETIME(6) NULL, created_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,token_hash), KEY ix_login_tokens_lookup (institution_id,student_id,component_id,expires_at),
  CONSTRAINT fk_login_tokens_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_login_tokens_component FOREIGN KEY (institution_id,component_id) REFERENCES course_components (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_attempts (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, student_id VARCHAR(128) NOT NULL, component_id VARCHAR(128) NOT NULL, password_id VARCHAR(128) NULL,
  status VARCHAR(40) NOT NULL, started_at DATETIME(6) NULL, ends_at DATETIME(6) NULL, submitted_at DATETIME(6) NULL, raw_score DECIMAL(8,2) NULL, scaled_score DECIMAL(8,2) NULL,
  access_token_hash CHAR(64) NULL, correlation_id VARCHAR(190) NULL, device_fingerprint VARCHAR(190) NULL, ip_address VARCHAR(64) NULL, locked_at DATETIME(6) NULL, locked_reason VARCHAR(255) NULL,
  snapshot_json JSON NOT NULL,
  PRIMARY KEY (institution_id,id), KEY ix_attempts_student_component (institution_id,student_id,component_id), KEY ix_attempts_status_end (institution_id,status,ends_at), KEY ix_attempts_submitted (institution_id,submitted_at),
  CONSTRAINT fk_attempts_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_attempts_component FOREIGN KEY (institution_id,component_id) REFERENCES course_components (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_question_snapshots (
  institution_id INT UNSIGNED NOT NULL, attempt_id VARCHAR(128) NOT NULL, question_id VARCHAR(128) NOT NULL, ordinal INT UNSIGNED NOT NULL, question_json JSON NOT NULL,
  PRIMARY KEY (institution_id,attempt_id,question_id), UNIQUE KEY uq_attempt_question_ordinal (institution_id,attempt_id,ordinal),
  CONSTRAINT fk_attempt_question_attempt FOREIGN KEY (institution_id,attempt_id) REFERENCES assessment_attempts (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_answers (
  institution_id INT UNSIGNED NOT NULL, attempt_id VARCHAR(128) NOT NULL, question_id VARCHAR(128) NOT NULL, option_index SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (institution_id,attempt_id,question_id,option_index),
  CONSTRAINT fk_attempt_answers_question FOREIGN KEY (institution_id,attempt_id,question_id) REFERENCES attempt_question_snapshots (institution_id,attempt_id,question_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_flags (
  institution_id INT UNSIGNED NOT NULL, attempt_id VARCHAR(128) NOT NULL, question_id VARCHAR(128) NOT NULL,
  PRIMARY KEY (institution_id,attempt_id,question_id),
  CONSTRAINT fk_attempt_flags_question FOREIGN KEY (institution_id,attempt_id,question_id) REFERENCES attempt_question_snapshots (institution_id,attempt_id,question_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attempt_integrity_events (
  institution_id INT UNSIGNED NOT NULL, id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, attempt_id VARCHAR(128) NOT NULL, event_json JSON NOT NULL,
  PRIMARY KEY (id), KEY ix_attempt_integrity_attempt (institution_id,attempt_id),
  CONSTRAINT fk_attempt_integrity_attempt FOREIGN KEY (institution_id,attempt_id) REFERENCES assessment_attempts (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS component_submissions (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, attempt_id VARCHAR(128) NULL, student_id VARCHAR(128) NOT NULL, course_id VARCHAR(128) NOT NULL, component_id VARCHAR(128) NOT NULL,
  academic_session_id VARCHAR(128) NULL, academic_semester_id VARCHAR(128) NULL, component ENUM('test','exam') NOT NULL, status VARCHAR(40) NOT NULL,
  raw_score DECIMAL(8,2) NULL, scaled_score DECIMAL(8,2) NULL, score DECIMAL(8,2) NULL, submitted_at DATETIME(6) NULL, grade VARCHAR(20) NULL, grade_point DECIMAL(8,3) NULL, quality_points DECIMAL(10,3) NULL, course_unit INT UNSIGNED NULL,
  review_snapshot_json JSON NOT NULL,
  PRIMARY KEY (institution_id,id), KEY ix_submissions_student_period (institution_id,student_id,academic_session_id,academic_semester_id), KEY ix_submissions_component (institution_id,component_id,submitted_at),
  CONSTRAINT fk_submissions_attempt FOREIGN KEY (institution_id,attempt_id) REFERENCES assessment_attempts (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_submissions_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_submissions_course FOREIGN KEY (institution_id,course_id) REFERENCES courses (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_submissions_component FOREIGN KEY (institution_id,component_id) REFERENCES course_components (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calculated_results (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, student_id VARCHAR(128) NOT NULL, session_id VARCHAR(128) NOT NULL, semester_id VARCHAR(128) NOT NULL,
  source_hash CHAR(64) NOT NULL, semester_gpa DECIMAL(8,3) NOT NULL, cgpa DECIMAL(8,3) NOT NULL, calculated_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), UNIQUE KEY uq_calculated_period (institution_id,student_id,session_id,semester_id),
  CONSTRAINT fk_calculated_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_calculated_session FOREIGN KEY (institution_id,session_id) REFERENCES academic_sessions (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_calculated_semester FOREIGN KEY (institution_id,semester_id) REFERENCES academic_semesters (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calculated_result_items (
  institution_id INT UNSIGNED NOT NULL, calculated_result_id VARCHAR(128) NOT NULL, course_id VARCHAR(128) NOT NULL, item_json JSON NOT NULL,
  PRIMARY KEY (institution_id,calculated_result_id,course_id),
  CONSTRAINT fk_calculated_items_result FOREIGN KEY (institution_id,calculated_result_id) REFERENCES calculated_results (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, name VARCHAR(190) NOT NULL, description TEXT NULL, max_users INT UNSIGNED NOT NULL, system_locked TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (institution_id,id), KEY ix_roles_name (institution_id,name),
  CONSTRAINT fk_roles_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  institution_id INT UNSIGNED NOT NULL, role_id VARCHAR(128) NOT NULL, permission VARCHAR(100) NOT NULL,
  PRIMARY KEY (institution_id,role_id,permission),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (institution_id,role_id) REFERENCES roles (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, role_id VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL, phone_number VARCHAR(80) NULL,
  password_hash VARCHAR(255) NULL, active TINYINT(1) NOT NULL DEFAULT 1, verified TINYINT(1) NOT NULL DEFAULT 0, must_change_password TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME(6) NULL, approved_at DATETIME(6) NULL, approved_by VARCHAR(254) NULL,
  PRIMARY KEY (institution_id,id), UNIQUE KEY uq_admin_users_email (institution_id,email),
  CONSTRAINT fk_admin_users_role FOREIGN KEY (institution_id,role_id) REFERENCES roles (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  institution_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL, name VARCHAR(255) NOT NULL, role_id VARCHAR(128) NOT NULL,
  permissions_json JSON NOT NULL, correlation_id VARCHAR(190) NULL, created_at DATETIME(6) NULL, last_seen_at DATETIME(6) NULL, expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,token_hash), KEY ix_admin_sessions_user (institution_id,user_id,expires_at), KEY ix_admin_sessions_expiry (institution_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_profile_overrides (
  institution_id INT UNSIGNED NOT NULL, profile_key VARCHAR(128) NOT NULL, payload_json JSON NOT NULL,
  PRIMARY KEY (institution_id,profile_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_user_activity (
  institution_id INT UNSIGNED NOT NULL, user_id VARCHAR(128) NOT NULL, last_login_at DATETIME(6) NULL, payload_json JSON NOT NULL,
  PRIMARY KEY (institution_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_admin_requests (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL, phone_number VARCHAR(80) NULL, role_id VARCHAR(128) NULL,
  status VARCHAR(40) NOT NULL, ip_address VARCHAR(64) NULL, email_delivery VARCHAR(80) NULL, created_at DATETIME(6) NULL, resolved_at DATETIME(6) NULL, resolved_by VARCHAR(254) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_admin_requests_status (institution_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_email_verifications (
  institution_id INT UNSIGNED NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL, code_hash VARCHAR(255) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,user_id), KEY ix_admin_email_verifications_expiry (institution_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_password_resets (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL, code_hash VARCHAR(255) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME(6) NULL, expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,id), KEY ix_admin_password_resets_user (institution_id,user_id), KEY ix_admin_password_resets_expiry (institution_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_two_factor_challenges (
  institution_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, user_id VARCHAR(128) NOT NULL, email VARCHAR(254) NOT NULL, role_id VARCHAR(128) NOT NULL, password_rate_limit_key CHAR(64) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,token_hash), KEY ix_admin_2fa_expiry (institution_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emergency_recovery_codes (
  institution_id INT UNSIGNED NOT NULL, code_position SMALLINT UNSIGNED NOT NULL, code_hash VARCHAR(255) NOT NULL, generated_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,code_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, timestamp_at DATETIME(6) NOT NULL, actor_type VARCHAR(40) NOT NULL, actor_id VARCHAR(254) NULL,
  action_type VARCHAR(150) NOT NULL, target_type VARCHAR(100) NOT NULL, target_id VARCHAR(190) NULL, ip_address VARCHAR(64) NULL, user_agent TEXT NULL,
  outcome VARCHAR(30) NULL, correlation_id VARCHAR(190) NULL, before_after_json JSON NULL, metadata_json JSON NULL,
  PRIMARY KEY (institution_id,id), KEY ix_audit_timestamp (institution_id,timestamp_at), KEY ix_audit_actor (institution_id,actor_id,timestamp_at), KEY ix_audit_action (institution_id,action_type,timestamp_at), KEY ix_audit_target (institution_id,target_type,target_id), KEY ix_audit_correlation (institution_id,correlation_id),
  CONSTRAINT fk_audit_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_archives (
  institution_id INT UNSIGNED NOT NULL, filename VARCHAR(255) NOT NULL, event_count INT UNSIGNED NULL, range_from DATETIME(6) NULL, range_to DATETIME(6) NULL, created_at DATETIME(6) NULL, checksum_sha256 CHAR(64) NULL,
  PRIMARY KEY (institution_id,filename),
  CONSTRAINT fk_audit_archives_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limit_records (
  institution_id INT UNSIGNED NOT NULL, legacy_key CHAR(64) NOT NULL, scope VARCHAR(120) NULL, subject_hash CHAR(64) NULL, count_value INT UNSIGNED NOT NULL, reset_at DATETIME(6) NULL, payload_json JSON NOT NULL,
  PRIMARY KEY (institution_id,legacy_key), KEY ix_rate_limits_expiry (institution_id,reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS algebra_provider_budget (
  budget_key VARCHAR(80) NOT NULL, quota_day DATE NOT NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, blocked_until DATETIME(6) NULL,
  PRIMARY KEY (budget_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_login_failures (
  institution_id INT UNSIGNED NOT NULL, legacy_key CHAR(64) NOT NULL, failed_at_json JSON NOT NULL, locked_until DATETIME(6) NULL,
  PRIMARY KEY (institution_id,legacy_key), KEY ix_exam_login_failures_locked (institution_id,locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_login_ip_attempts (
  institution_id INT UNSIGNED NOT NULL, legacy_key CHAR(64) NOT NULL, attempted_at_json JSON NOT NULL,
  PRIMARY KEY (institution_id,legacy_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_flags (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, attempt_id VARCHAR(128) NOT NULL, flag_type VARCHAR(120) NOT NULL, ip_address VARCHAR(64) NULL, resulting_action VARCHAR(120) NULL, timestamp_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), KEY ix_exam_flags_attempt (institution_id,attempt_id),
  CONSTRAINT fk_exam_flags_attempt FOREIGN KEY (institution_id,attempt_id) REFERENCES assessment_attempts (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, student_id VARCHAR(128) NULL, name VARCHAR(255) NOT NULL, email VARCHAR(254) NOT NULL, source VARCHAR(100) NULL, status VARCHAR(40) NOT NULL, subscribed_at DATETIME(6) NULL, updated_at DATETIME(6) NULL, verified_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id), UNIQUE KEY uq_newsletter_email (institution_id,email), KEY ix_subscribers_status (institution_id,status),
  CONSTRAINT fk_subscribers_student FOREIGN KEY (institution_id,student_id) REFERENCES students (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletters (
  institution_id INT UNSIGNED NOT NULL, id VARCHAR(128) NOT NULL, subject VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, sender VARCHAR(255) NULL, created_by VARCHAR(254) NULL, created_at DATETIME(6) NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0, accepted_count INT UNSIGNED NOT NULL DEFAULT 0, failed_count INT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(60) NOT NULL,
  PRIMARY KEY (institution_id,id), KEY ix_newsletters_created (institution_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_deliveries (
  institution_id INT UNSIGNED NOT NULL, newsletter_id VARCHAR(128) NOT NULL, recipient_email VARCHAR(254) NOT NULL, delivery_json JSON NOT NULL,
  PRIMARY KEY (institution_id,newsletter_id,recipient_email),
  CONSTRAINT fk_newsletter_deliveries_newsletter FOREIGN KEY (institution_id,newsletter_id) REFERENCES newsletters (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grading_scale_bands (
  institution_id INT UNSIGNED NOT NULL, ordinal SMALLINT UNSIGNED NOT NULL, min_score DECIMAL(6,2) NOT NULL, max_score DECIMAL(6,2) NOT NULL, grade VARCHAR(20) NOT NULL, grade_point DECIMAL(6,3) NOT NULL,
  PRIMARY KEY (institution_id,ordinal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrity_policies (
  institution_id INT UNSIGNED NOT NULL, event_type VARCHAR(100) NOT NULL, mode VARCHAR(30) NOT NULL, lock_after INT UNSIGNED NOT NULL,
  PRIMARY KEY (institution_id,event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_settings (
  institution_id INT UNSIGNED NOT NULL, setting_key VARCHAR(120) NOT NULL, setting_json JSON NOT NULL,
  PRIMARY KEY (institution_id,setting_key),
  CONSTRAINT fk_institution_settings_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_hidden_outcomes (
  institution_id INT UNSIGNED NOT NULL, outcome_key VARCHAR(190) NOT NULL,
  PRIMARY KEY (institution_id,outcome_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_records (
  institution_id INT UNSIGNED NOT NULL, filename VARCHAR(255) NOT NULL, backup_type VARCHAR(60) NOT NULL, created_at DATETIME(6) NOT NULL, created_by VARCHAR(254) NULL,
  size_bytes BIGINT UNSIGNED NULL, checksum_sha256 CHAR(64) NULL, metadata_json JSON NULL,
  PRIMARY KEY (institution_id,filename), KEY ix_backups_created (institution_id,created_at),
  CONSTRAINT fk_backup_records_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storage metadata is deliberately institution-scoped.  It tracks uploaded
-- tenant assets without putting mutable filesystem paths in academic tables.
-- A row is retained after an asset becomes unreferenced, which gives the
-- lifecycle worker a safe grace period rather than deleting a replaced logo
-- in the request that replaced it.
CREATE TABLE IF NOT EXISTS institution_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id INT UNSIGNED NOT NULL,
  asset_type VARCHAR(40) NOT NULL,
  storage_key VARCHAR(500) NOT NULL,
  sha256 CHAR(64) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  mime_type VARCHAR(120) NULL,
  asset_state VARCHAR(24) NOT NULL DEFAULT 'active',
  created_by VARCHAR(254) NULL,
  created_at DATETIME(6) NOT NULL,
  referenced_at DATETIME(6) NULL,
  unreferenced_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_institution_asset_key (institution_id,storage_key),
  KEY ix_institution_assets_lifecycle (institution_id,asset_state,unreferenced_at),
  CONSTRAINT fk_institution_assets_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PDF extraction is intentionally asynchronous. The browser receives only a
-- job id; source documents and parsed review data remain server-side until an
-- authorised administrator reviews and explicitly imports Draft questions.
CREATE TABLE IF NOT EXISTS pdf_import_jobs (
  institution_id INT UNSIGNED NOT NULL,
  id CHAR(32) NOT NULL,
  course_id VARCHAR(128) NOT NULL,
  requested_by_admin_id VARCHAR(128) NULL,
  requested_by_platform_admin_id VARCHAR(128) NULL,
  requested_by_email VARCHAR(254) NOT NULL,
  requested_by_scope ENUM('institution','platform') NOT NULL DEFAULT 'institution',
  provider ENUM('gemini','openrouter') NOT NULL,
  status ENUM('queued','running','review_ready','failed','cancelled','completed') NOT NULL DEFAULT 'queued',
  source_key VARCHAR(500) NOT NULL,
  source_filename VARCHAR(255) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  source_size_bytes BIGINT UNSIGNED NOT NULL,
  page_count SMALLINT UNSIGNED NULL,
  parsed_items_json JSON NULL,
  low_confidence_count SMALLINT UNSIGNED NULL,
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  available_at DATETIME(6) NOT NULL,
  started_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  review_expires_at DATETIME(6) NULL,
  source_expires_at DATETIME(6) NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (institution_id,id),
  KEY ix_pdf_jobs_claim (status,available_at,created_at),
  KEY ix_pdf_jobs_admin (institution_id,requested_by_admin_id,status,created_at),
  KEY ix_pdf_jobs_platform_admin (requested_by_platform_admin_id,status,created_at),
  KEY ix_pdf_jobs_course (institution_id,course_id,status,created_at),
  CONSTRAINT fk_pdf_jobs_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pdf_jobs_course FOREIGN KEY (institution_id,course_id) REFERENCES courses (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pdf_jobs_admin FOREIGN KEY (institution_id,requested_by_admin_id) REFERENCES admin_users (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
  ,CONSTRAINT fk_pdf_jobs_platform_admin FOREIGN KEY (requested_by_platform_admin_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Algebra Stage 1 is tenant-owned by construction.  Request records retain
-- only the administrator's drafting configuration or anonymised aggregate
-- insight snapshot; no student credentials, IP addresses, or identifiers are
-- sent to Gemini or stored in this feature's payload.
CREATE TABLE IF NOT EXISTS algebra_requests (
  institution_id INT UNSIGNED NOT NULL,
  id CHAR(32) NOT NULL,
  requested_by_admin_id VARCHAR(128) NOT NULL,
  capability ENUM('question_draft','performance_insight','setup_suggestion','audit_digest','anomaly_review','communication_draft','result_report') NOT NULL,
  status ENUM('running','completed','failed','imported') NOT NULL DEFAULT 'running',
  request_hash CHAR(64) NOT NULL,
  input_json JSON NOT NULL,
  response_json JSON NULL,
  error_code VARCHAR(80) NULL,
  error_message VARCHAR(500) NULL,
  correlation_id VARCHAR(190) NULL,
  created_at DATETIME(6) NOT NULL,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (institution_id,id),
  KEY ix_algebra_requests_admin (institution_id,requested_by_admin_id,created_at),
  KEY ix_algebra_requests_platform_admin (requested_by_platform_admin_id,created_at),
  KEY ix_algebra_requests_capability (institution_id,capability,status,created_at),
  CONSTRAINT fk_algebra_requests_institution FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_algebra_requests_admin FOREIGN KEY (institution_id,requested_by_admin_id) REFERENCES admin_users (institution_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_algebra_requests_platform_admin FOREIGN KEY (requested_by_platform_admin_id) REFERENCES platform_admin_users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_algebra_requests_one_actor CHECK ((requested_by_admin_id IS NOT NULL AND requested_by_platform_admin_id IS NULL) OR (requested_by_admin_id IS NULL AND requested_by_platform_admin_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

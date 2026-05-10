-- Maintenance Intelligence System - schema
-- Drop existing (idempotent for dev)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS ai_messages;
DROP TABLE IF EXISTS ticket_events;
DROP TABLE IF EXISTS pm_items;
DROP TABLE IF EXISTS pm_plans;
DROP TABLE IF EXISTS components;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS fault_occurrences;
DROP TABLE IF EXISTS fault_affected_systems;
DROP TABLE IF EXISTS fault_diagnostic_questions;
DROP TABLE IF EXISTS fault_reference_documents;
DROP TABLE IF EXISTS fault_tasks;
DROP TABLE IF EXISTS fault_catalog;
DROP TABLE IF EXISTS aircraft_tails;
DROP TABLE IF EXISTS systems;
DROP TABLE IF EXISTS airframes;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- Users with role-based access. Roles: admin, supervisor, maintenance, readonly.
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id VARCHAR(32) NOT NULL UNIQUE,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(128) NOT NULL,
  email VARCHAR(190) DEFAULT NULL,
  role ENUM('admin','supervisor','maintenance','readonly') NOT NULL DEFAULT 'readonly',
  station VARCHAR(8) DEFAULT NULL,
  shift ENUM('day','swing','night') DEFAULT NULL,
  rts_authority TINYINT(1) NOT NULL DEFAULT 0,        -- can self-approve return to service
  inspection_authority TINYINT(1) NOT NULL DEFAULT 0, -- can sign off another tech's work
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persistent server-side session records (auditable login sessions)
CREATE TABLE user_sessions (
  id CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_session_user (user_id),
  INDEX idx_session_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Airframes (CRJ-900 first; later: 737, A320, etc.)
CREATE TABLE airframes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,           -- e.g., CRJ-900
  manufacturer VARCHAR(64) NOT NULL,           -- Bombardier
  model VARCHAR(64) NOT NULL,                  -- CRJ-900 (CL-600-2D24)
  description VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aircraft tails (individual units)
CREATE TABLE aircraft_tails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  airframe_id INT UNSIGNED NOT NULL,
  tail_number VARCHAR(16) NOT NULL UNIQUE,     -- N901XX
  operator VARCHAR(64) NOT NULL DEFAULT 'Endeavor Air',
  in_service TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_tail_airframe FOREIGN KEY (airframe_id) REFERENCES airframes(id) ON DELETE CASCADE,
  INDEX idx_tail_airframe (airframe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ATA chapters / systems (Avionics, Engines, Hydraulics, etc.)
CREATE TABLE systems (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  ata_chapter VARCHAR(8) DEFAULT NULL,         -- e.g., 22-31
  icon_key VARCHAR(32) DEFAULT NULL            -- frontend icon name
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference documents (FAA/manufacturer PDFs)
CREATE TABLE documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  airframe_id INT UNSIGNED DEFAULT NULL,
  system_id INT UNSIGNED DEFAULT NULL,
  doc_type ENUM('AMM','SB','MEL','FAA','PILOT_GUIDE','OTHER') NOT NULL DEFAULT 'OTHER',
  ata_chapter VARCHAR(16) DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) DEFAULT NULL,
  storage_path VARCHAR(512) NOT NULL,          -- relative path under docs/library/
  file_size BIGINT UNSIGNED DEFAULT NULL,
  revision VARCHAR(32) DEFAULT NULL,
  effective_date DATE DEFAULT NULL,
  uploaded_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_airframe FOREIGN KEY (airframe_id) REFERENCES airframes(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_doc_airframe (airframe_id),
  INDEX idx_doc_system (system_id),
  INDEX idx_doc_ata (ata_chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fault catalog: known fault types per airframe
CREATE TABLE fault_catalog (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  airframe_id INT UNSIGNED NOT NULL,
  system_id INT UNSIGNED NOT NULL,
  fault_code VARCHAR(64) NOT NULL,             -- e.g., CRJ900-AV-001
  ata_chapter VARCHAR(16) DEFAULT NULL,        -- 22-31-00
  title VARCHAR(255) NOT NULL,                 -- AFCS Autopilot Disconnect — Uncommanded
  description TEXT,
  severity ENUM('CRITICAL','HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fault_airframe FOREIGN KEY (airframe_id) REFERENCES airframes(id) ON DELETE CASCADE,
  CONSTRAINT fk_fault_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_fault_code (fault_code),
  INDEX idx_fault_severity (severity),
  INDEX idx_fault_airframe (airframe_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags for affected sub-systems shown in detail panel
CREATE TABLE fault_affected_systems (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fault_id INT UNSIGNED NOT NULL,
  label VARCHAR(64) NOT NULL,                  -- e.g., FCC, AFCS, Autopilot
  CONSTRAINT fk_fa_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE CASCADE,
  INDEX idx_fa_fault (fault_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diagnostic questions surfaced in side panel
CREATE TABLE fault_diagnostic_questions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fault_id INT UNSIGNED NOT NULL,
  question_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  question TEXT NOT NULL,
  CONSTRAINT fk_fdq_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE CASCADE,
  INDEX idx_fdq_fault (fault_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Linkage between faults and reference documents
CREATE TABLE fault_reference_documents (
  fault_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NOT NULL,
  display_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (fault_id, document_id),
  CONSTRAINT fk_frd_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_frd_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual fault occurrence reports
CREATE TABLE fault_occurrences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fault_id INT UNSIGNED NOT NULL,
  tail_id INT UNSIGNED NOT NULL,
  occurred_at DATETIME NOT NULL,
  flight_number VARCHAR(16) DEFAULT NULL,
  phase ENUM('Cruise','Climb','Descent','Taxi','Takeoff','Landing','Ground') DEFAULT NULL,
  report_type VARCHAR(32) DEFAULT NULL,        -- ACARS, Pilot Report, AMM, etc.
  notes TEXT,
  CONSTRAINT fk_occ_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_occ_tail FOREIGN KEY (tail_id) REFERENCES aircraft_tails(id) ON DELETE CASCADE,
  INDEX idx_occ_fault (fault_id),
  INDEX idx_occ_tail (tail_id),
  INDEX idx_occ_when (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tickets: a maintenance work item that can be picked up across shifts
CREATE TABLE tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_number VARCHAR(32) NOT NULL UNIQUE,   -- e.g., TKT-2026-0001
  fault_id INT UNSIGNED DEFAULT NULL,
  tail_id INT UNSIGNED DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('open','in_progress','on_hold','closed','cancelled') NOT NULL DEFAULT 'open',
  severity ENUM('CRITICAL','HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  created_by INT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED DEFAULT NULL,
  closed_by INT UNSIGNED DEFAULT NULL,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_tail FOREIGN KEY (tail_id) REFERENCES aircraft_tails(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ticket_closer FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_ticket_status (status),
  INDEX idx_ticket_assignee (assigned_to),
  INDEX idx_ticket_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-ticket event timeline: comments, status changes, handoffs, document references
CREATE TABLE ticket_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  event_type ENUM(
    'created','assigned','reassigned','status_changed','comment',
    'document_referenced','ai_consulted','closed','reopened','handoff'
  ) NOT NULL,
  body TEXT,                                   -- free-text comment or details
  metadata JSON DEFAULT NULL,                  -- structured detail (e.g., from/to user)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_te_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_te_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_te_ticket (ticket_id),
  INDEX idx_te_when (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-fault tasklist. Created implicitly — every fault has a list; rows added on demand.
-- Tasks may be authored by a tech or by the AI assistant (source column).
CREATE TABLE fault_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fault_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED DEFAULT NULL,            -- optional linkage to an aircraft-specific ticket
  title VARCHAR(500) NOT NULL,
  description TEXT,
  status ENUM('pending','in_progress','blocked','awaiting_inspection','complete') NOT NULL DEFAULT 'pending',
  requires_inspection TINYINT(1) NOT NULL DEFAULT 0,   -- if true, completion always routes to a supervisor
  holdup_reason VARCHAR(255) DEFAULT NULL,             -- only when status='blocked' (e.g., "awaiting part")
  source ENUM('user','ai') NOT NULL DEFAULT 'user',
  task_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  awaiting_inspection_at DATETIME DEFAULT NULL,        -- when a tech flagged "done, awaiting sign-off"
  awaiting_inspection_by INT UNSIGNED DEFAULT NULL,    -- the tech who flagged it
  completed_by INT UNSIGNED DEFAULT NULL,              -- final sign-off
  completed_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_ftask_fault    FOREIGN KEY (fault_id)               REFERENCES fault_catalog(id) ON DELETE CASCADE,
  CONSTRAINT fk_ftask_ticket   FOREIGN KEY (ticket_id)              REFERENCES tickets(id)       ON DELETE SET NULL,
  CONSTRAINT fk_ftask_creator  FOREIGN KEY (created_by)             REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT fk_ftask_updater  FOREIGN KEY (updated_by)             REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT fk_ftask_aiby     FOREIGN KEY (awaiting_inspection_by) REFERENCES users(id)         ON DELETE SET NULL,
  CONSTRAINT fk_ftask_signer   FOREIGN KEY (completed_by)           REFERENCES users(id)         ON DELETE SET NULL,
  INDEX idx_ftask_fault (fault_id),
  INDEX idx_ftask_status (status),
  INDEX idx_ftask_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI assistant conversation messages, scoped to a ticket so handoffs see prior chat
CREATE TABLE ai_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED DEFAULT NULL,
  fault_id INT UNSIGNED DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  role ENUM('system','user','assistant') NOT NULL,
  content MEDIUMTEXT NOT NULL,
  tokens_in INT UNSIGNED DEFAULT NULL,
  tokens_out INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aim_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_aim_fault FOREIGN KEY (fault_id) REFERENCES fault_catalog(id) ON DELETE SET NULL,
  CONSTRAINT fk_aim_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_aim_ticket (ticket_id),
  INDEX idx_aim_fault (fault_id),
  INDEX idx_aim_when (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PREVENTIVE MAINTENANCE
-- =============================================================================
-- Serial-numbered components installed on aircraft (engines, APUs, landing gear,
-- avionics LRUs the airline tracks individually). Each has its own time-in-
-- service that follows the component when it's swapped between airframes.
CREATE TABLE components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  component_type VARCHAR(32) NOT NULL,               -- engine, apu, landing_gear, fcc, etc.
  serial_number  VARCHAR(64) NOT NULL,
  position       VARCHAR(32) DEFAULT NULL,           -- L / R / NG / MLG / FCC1 etc.
  airframe_id    INT UNSIGNED DEFAULT NULL,          -- the airframe class it currently belongs to
  tail_id        INT UNSIGNED DEFAULT NULL,          -- which tail it's installed on right now (NULL if removed)
  installed_at   DATETIME DEFAULT NULL,
  hours_at_install BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_hours    BIGINT UNSIGNED NOT NULL DEFAULT 0, -- since-new TSN
  total_cycles   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  in_service     TINYINT(1) NOT NULL DEFAULT 1,
  notes          VARCHAR(255) DEFAULT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comp_airframe FOREIGN KEY (airframe_id) REFERENCES airframes(id)      ON DELETE SET NULL,
  CONSTRAINT fk_comp_tail     FOREIGN KEY (tail_id)     REFERENCES aircraft_tails(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_comp_serial (component_type, serial_number),
  INDEX idx_comp_tail (tail_id),
  INDEX idx_comp_type (component_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PM plan definitions: WHAT to do and HOW OFTEN.
CREATE TABLE pm_plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  airframe_id INT UNSIGNED DEFAULT NULL,             -- NULL = applies to any airframe
  applies_to ENUM('airframe','engine','apu','landing_gear','avionics','other') NOT NULL DEFAULT 'airframe',
  ata_chapter VARCHAR(16) DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  trigger_type ENUM('calendar_days','flight_hours','flight_cycles','component_hours') NOT NULL,
  interval_value INT UNSIGNED NOT NULL,              -- days or hours or cycles depending on trigger
  tolerance_value INT UNSIGNED NOT NULL DEFAULT 0,   -- allowable slip before overdue
  requires_inspection TINYINT(1) NOT NULL DEFAULT 0, -- supervisor sign-off mandatory
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pmp_airframe FOREIGN KEY (airframe_id) REFERENCES airframes(id) ON DELETE CASCADE,
  INDEX idx_pmp_airframe (airframe_id),
  INDEX idx_pmp_trigger (trigger_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PM item: an instance of a plan applied to a specific target (tail OR component).
-- This is what shows up in the dashboard with due dates / hours and status.
CREATE TABLE pm_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id INT UNSIGNED NOT NULL,
  target_kind ENUM('tail','component') NOT NULL,
  tail_id INT UNSIGNED DEFAULT NULL,
  component_id INT UNSIGNED DEFAULT NULL,

  last_done_at        DATETIME DEFAULT NULL,
  last_done_hours     BIGINT UNSIGNED DEFAULT NULL,
  last_done_cycles    BIGINT UNSIGNED DEFAULT NULL,
  next_due_at         DATETIME DEFAULT NULL,
  next_due_hours      BIGINT UNSIGNED DEFAULT NULL,
  next_due_cycles     BIGINT UNSIGNED DEFAULT NULL,

  status ENUM('current','due_soon','overdue','in_progress','awaiting_inspection','complete') NOT NULL DEFAULT 'current',
  assigned_to INT UNSIGNED DEFAULT NULL,
  ticket_id   INT UNSIGNED DEFAULT NULL,             -- optional linkage when work is in progress

  awaiting_inspection_at DATETIME DEFAULT NULL,
  awaiting_inspection_by INT UNSIGNED DEFAULT NULL,
  signed_off_by INT UNSIGNED DEFAULT NULL,
  signed_off_at DATETIME DEFAULT NULL,

  notes VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_pmi_plan      FOREIGN KEY (plan_id)                REFERENCES pm_plans(id)       ON DELETE CASCADE,
  CONSTRAINT fk_pmi_tail      FOREIGN KEY (tail_id)                REFERENCES aircraft_tails(id) ON DELETE SET NULL,
  CONSTRAINT fk_pmi_comp      FOREIGN KEY (component_id)           REFERENCES components(id)     ON DELETE SET NULL,
  CONSTRAINT fk_pmi_assignee  FOREIGN KEY (assigned_to)            REFERENCES users(id)          ON DELETE SET NULL,
  CONSTRAINT fk_pmi_ticket    FOREIGN KEY (ticket_id)              REFERENCES tickets(id)        ON DELETE SET NULL,
  CONSTRAINT fk_pmi_aiby      FOREIGN KEY (awaiting_inspection_by) REFERENCES users(id)          ON DELETE SET NULL,
  CONSTRAINT fk_pmi_signer    FOREIGN KEY (signed_off_by)          REFERENCES users(id)          ON DELETE SET NULL,
  INDEX idx_pmi_status (status),
  INDEX idx_pmi_tail (tail_id),
  INDEX idx_pmi_component (component_id),
  INDEX idx_pmi_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Universal audit log: tracks every significant action by every user
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED DEFAULT NULL,
  action VARCHAR(64) NOT NULL,                 -- login, logout, ticket.create, ticket.assign, ai.query, doc.view, etc.
  target_type VARCHAR(32) DEFAULT NULL,        -- 'ticket', 'fault', 'document', 'user'
  target_id VARCHAR(64) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  details JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_action (action),
  INDEX idx_audit_when (created_at),
  INDEX idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

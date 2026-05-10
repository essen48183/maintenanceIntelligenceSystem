-- Seed data — produces the screen exactly as shown in the design mockup.
-- Default password for all seeded users is: ChangeMe!123
-- (bcrypt hash below was generated with PHP password_hash, cost=12)

SET @pwd := '$2y$12$GtZa1hySq3bhoXn9HXYA1.O7Nk57SKFJoL0UZEnyMXpGUiPlllcpS';

INSERT INTO users (employee_id, username, password_hash, full_name, email, role, station, shift) VALUES
  ('E0001', 'admin',     @pwd, 'System Administrator',  'admin@example.local',     'admin',       'ATL', 'day'),
  ('E1001', 'jsupervisor', @pwd, 'Jamie Reyes',         'jamie@example.local',     'supervisor',  'ATL', 'day'),
  ('E2001', 'mtech1',    @pwd, 'Devon Park',            'devon@example.local',     'maintenance', 'ATL', 'day'),
  ('E2002', 'mtech2',    @pwd, 'Kira Holden',           'kira@example.local',      'maintenance', 'ATL', 'swing'),
  ('E2003', 'mtech3',    @pwd, 'Marcus Webb',           'marcus@example.local',    'maintenance', 'ATL', 'night'),
  ('E3001', 'viewer',    @pwd, 'Read Only User',        'viewer@example.local',    'readonly',    'ATL', 'day'),
  -- Pilot — read access for understanding faults on the line
  ('E4001', 'rsmith',    @pwd, 'Capt. R. Smith',         'rsmith@example.local',    'readonly',    'ATL', 'day'),
  -- Corporate Tech Ops — reliability/quality leadership
  ('E5001', 'qchen',     @pwd, 'Quincy Chen',            'qchen@example.local',     'readonly',    'ATL', 'day');

-- Airframes
INSERT INTO airframes (code, manufacturer, model, description) VALUES
  ('CRJ-900', 'Bombardier', 'CRJ-900 (CL-600-2D24)', 'Regional jet — primary fleet for Endeavor Air'),
  ('CRJ-700', 'Bombardier', 'CRJ-700 (CL-600-2C10)', 'Regional jet — secondary fleet');

-- Tails
INSERT INTO aircraft_tails (airframe_id, tail_number, operator) VALUES
  (1, 'N901XX', 'Endeavor Air'),
  (1, 'N902XX', 'Endeavor Air'),
  (1, 'N903XX', 'Endeavor Air'),
  (1, 'N904XX', 'Endeavor Air'),
  (1, 'N905XX', 'Endeavor Air'),
  (2, 'N700XX', 'Endeavor Air'),
  (2, 'N701XX', 'Endeavor Air');

-- Systems (left-rail filter)
INSERT INTO systems (code, name, ata_chapter, icon_key) VALUES
  ('AVIONICS',    'Avionics',    '22-31', 'avionics'),
  ('ENGINES',     'Engines',     '70-00', 'engines'),
  ('HYDRAULICS',  'Hydraulics',  '29-00', 'hydraulics'),
  ('DATA_SYSTEMS','Data Systems','46-00', 'data'),
  ('ELECTRICAL',  'Electrical',  '24-00', 'electrical'),
  ('ICE_RAIN',    'Ice & Rain',  '30-00', 'ice');

-- Reference docs (no actual files yet — paths are placeholders the upload UI will populate)
INSERT INTO documents (airframe_id, system_id, doc_type, ata_chapter, title, subtitle, storage_path, revision, effective_date) VALUES
  (1, 1, 'AMM',         '22-31-00', 'CRJ900 AMM 22-31-00', 'Autopilot System — Description',     'placeholders/crj900-amm-22-31-00.pdf', 'Rev 14', '2025-09-01'),
  (1, 1, 'AMM',         '22-31-28', 'CRJ900 AMM 22-31-28', 'FCC — Removal/Installation',         'placeholders/crj900-amm-22-31-28.pdf', 'Rev 11', '2025-09-01'),
  (1, 1, 'SB',          '22-31-47', 'Bombardier SB A-22-31-47', 'FCC Software Update',           'placeholders/sb-a-22-31-47.pdf',       'Init',   '2025-12-15'),
  (1, 1, 'PILOT_GUIDE', '22-31',    'Pilot Guide (AFCS)',  'Chapter 2 — Autopilot',              'placeholders/pilot-guide-afcs.pdf',    'Rev 4',  '2024-06-01'),
  (1, 2, 'AMM',         '72-00-00', 'CRJ900 AMM 72-00-00', 'CF34-8C5 Engine — General',          'placeholders/crj900-amm-72-00-00.pdf', 'Rev 9',  '2025-03-15'),
  (1, NULL, 'MEL',      NULL,       'Endeavor Air MEL',    'Master Equipment List',              'placeholders/endeavor-mel.pdf',        'Rev 27', '2026-04-01'),
  (1, NULL, 'FAA',      NULL,       'FAA AC 43-13-1B',     'Acceptable Methods, Techniques',     'placeholders/faa-ac-43-13-1b.pdf',     'CHG 1',  '2003-09-08');

-- Faults shown on the dashboard (5 active issues from the mockup)
INSERT INTO fault_catalog (airframe_id, system_id, fault_code, ata_chapter, title, description, severity) VALUES
  (1, 1, 'CRJ900-AV-001', '22-31-00', 'AFCS Autopilot Disconnect — Uncommanded',
     'Autopilot disengaging without crew input during cruise phase. Associated with Primus Elite avionics FCC fault code 47-22. Seen primarily above FL350.',
     'CRITICAL'),
  (1, 2, 'CRJ900-EN-014', '72-00-00', 'CF34-8C5 ITT Exceedance on Startup',
     'Inter-Turbine Temperature exceedance observed during engine start. Pattern correlates with cold-soaked starts and certain bleed configurations.',
     'HIGH'),
  (1, 3, 'CRJ900-HY-007', '29-30-00', 'Spoiler Asymmetry Warning — Hydraulic B System',
     'Spoiler asymmetry annunciation tied to fluctuations in Hydraulic System B pressure during ground roll.',
     'HIGH'),
  (1, 4, 'CRJ900-DS-003', '46-20-00', 'ACARS VHF Datalink Partial Outage',
     'Intermittent ACARS message delivery failures on VHF datalink. Suspected antenna or VDR module degradation.',
     'MEDIUM'),
  (1, 5, 'CRJ900-EL-021', '24-32-00', 'APU Battery Charger Fault Light',
     'APU battery charger annunciator illuminates intermittently during ground operations. No flight effect observed.',
     'LOW');

-- Affected systems (chips shown under fault title)
INSERT INTO fault_affected_systems (fault_id, label) VALUES
  (1,'FCC'), (1,'AFCS'), (1,'Autopilot'), (1,'PFD Annunciator'),
  (2,'ECU'), (2,'CF34-8C5'), (2,'ITT Probe'),
  (3,'Hyd Sys B'), (3,'Spoiler Panels'), (3,'PSEU'),
  (4,'VDR'), (4,'VHF Antenna'), (4,'CMU'),
  (5,'APU Battery'), (5,'Charger Module');

-- Diagnostic questions (right side panel)
INSERT INTO fault_diagnostic_questions (fault_id, question_order, question) VALUES
  (1, 1, 'Has the FCC been swapped or had a software update recently?'),
  (1, 2, 'Are fault codes 47-22 or 47-23 logged in the MDC?'),
  (1, 3, 'Is the issue correlated with turbulence or specific altitude bands?'),
  (1, 4, 'Has the pitch/roll servo been inspected for binding?'),
  (1, 5, 'Were any MEL items open related to AFCS at time of event?'),
  (2, 1, 'Was the engine cold-soaked below freezing prior to start?'),
  (2, 2, 'Is the bleed configuration per the cold-weather start procedure?'),
  (2, 3, 'Are ITT probe resistance values within tolerance?'),
  (3, 1, 'What is the Hyd System B reservoir level and quality?'),
  (3, 2, 'Are the spoiler PDU servo currents balanced left/right?'),
  (4, 1, 'What is the VDR self-test result?'),
  (4, 2, 'Is the VHF antenna VSWR within limits?'),
  (5, 1, 'Has the charger module been reset and the fault re-tested?');

-- Link reference documents to faults
INSERT INTO fault_reference_documents (fault_id, document_id, display_order) VALUES
  (1, 1, 1),
  (1, 2, 2),
  (1, 3, 3),
  (1, 4, 4),
  (2, 5, 1),
  (2, 6, 2),
  (3, 6, 1),
  (4, 6, 1),
  (5, 6, 1);

-- Fault occurrences (recent table in detail panel)
INSERT INTO fault_occurrences (fault_id, tail_id, occurred_at, flight_number, phase, report_type, notes) VALUES
  (1, 1, '2026-05-01 12:14:00', 'DL1102', 'Cruise', 'ACARS', NULL),
  (1, 2, '2026-05-03 09:45:00', 'DL1218', 'Cruise', 'ACARS', NULL),
  (1, 1, '2026-05-05 15:02:00', 'DL1340', 'Cruise', 'ACARS', NULL),
  (1, 2, '2026-05-06 19:33:00', 'DL1567', 'Cruise', 'ACARS', NULL),
  (1, 1, '2026-05-08 10:42:00', 'DL1234', 'Cruise', 'ACARS', NULL),
  (1, 2, '2026-05-08 08:15:00', 'DL1567', 'Cruise', 'ACARS', NULL),
  (1, 1, '2026-05-07 21:08:00', 'DL1980', 'Cruise', 'ACARS', NULL),
  (1, 2, '2026-05-07 13:33:00', 'DL1345', 'Cruise', 'ACARS', NULL),
  (1, 1, '2026-05-06 17:52:00', 'DL1678', 'Cruise', 'ACARS', NULL),
  (2, 1, '2026-05-07 14:00:00', 'DL2001', 'Ground', 'AMM',   'Cold start — ITT 925°C'),
  (2, 3, '2026-05-04 06:20:00', 'DL2104', 'Ground', 'AMM',   'Cold start — ITT 918°C'),
  (2, 1, '2026-05-02 05:44:00', 'DL2200', 'Ground', 'AMM',   'Cold start — ITT 905°C'),
  (2, 3, '2026-04-30 07:11:00', 'DL2278', 'Ground', 'AMM',   'Cold start — ITT 912°C'),
  (3, 2, '2026-05-06 11:00:00', 'DL3105', 'Taxi',   'Pilot Report', 'Spoiler asym light during taxi-out'),
  (3, 2, '2026-05-04 14:15:00', 'DL3211', 'Taxi',   'Pilot Report', 'Spoiler asym light, recycled'),
  (3, 2, '2026-05-02 09:30:00', 'DL3320', 'Taxi',   'Pilot Report', 'Spoiler asym light'),
  (3, 2, '2026-04-29 10:50:00', 'DL3401', 'Taxi',   'Pilot Report', 'Spoiler asym light, intermittent'),
  (4, 1, '2026-05-05 13:10:00', 'DL4002', 'Cruise', 'ACARS', 'Datalink loss for 8 min'),
  (4, 1, '2026-05-03 17:25:00', 'DL4118', 'Cruise', 'ACARS', 'Datalink loss for 4 min'),
  (5, 3, '2026-05-03 22:15:00', 'GROUND', 'Ground', 'AMM',   'APU charger light momentary');

-- Sample tickets demonstrating the shift-handoff feature
INSERT INTO tickets (ticket_number, fault_id, tail_id, title, status, severity, created_by, assigned_to, opened_at) VALUES
  ('TKT-2026-0001', 1, 1, 'AFCS Autopilot Disconnect — N901XX',  'in_progress', 'CRITICAL', 3, 4, '2026-05-08 10:55:00'),
  ('TKT-2026-0002', 2, 1, 'CF34-8C5 ITT Exceedance — N901XX',    'open',        'HIGH',     3, 3, '2026-05-08 11:30:00'),
  ('TKT-2026-0003', 3, 2, 'Spoiler Asymmetry — N902XX',          'on_hold',     'HIGH',     4, 5, '2026-05-06 11:20:00');

-- Ticket events demonstrating multi-user, multi-shift handoff
INSERT INTO ticket_events (ticket_id, user_id, event_type, body, metadata, created_at) VALUES
  (1, 3, 'created',     'Ticket opened from ACARS message DL1234 — AP disengage at FL370.', NULL, '2026-05-08 10:55:00'),
  (1, 3, 'comment',     'Pulled MDC log. Fault code 47-22 confirmed. Will hand off to swing shift for FCC software check.', NULL, '2026-05-08 13:40:00'),
  (1, 3, 'reassigned',  'Reassigned to Kira Holden (swing shift) for continued diagnosis.',
                        JSON_OBJECT('from_user',3,'to_user',4,'shift_handoff',true),
                        '2026-05-08 14:00:00'),
  (1, 4, 'comment',     'Picked up at shift change. Verified SB A-22-31-47 not yet applied to N901XX. Coordinating with stores.', NULL, '2026-05-08 16:15:00'),
  (1, 4, 'ai_consulted','Asked AI assistant about FCC swap history.', NULL, '2026-05-08 16:32:00'),
  (1, 4, 'document_referenced', 'Referenced AMM 22-31-28 (FCC R&I).',
                        JSON_OBJECT('document_id',2),
                        '2026-05-08 16:45:00'),
  (2, 3, 'created',     'Cold start ITT exceedance reported by line crew.', NULL, '2026-05-08 11:30:00'),
  (3, 4, 'created',     'Recurring spoiler asymmetry on N902XX — needs Hyd B troubleshoot.', NULL, '2026-05-06 11:20:00'),
  (3, 4, 'reassigned',  'Handed off to night shift after Hyd B reservoir top-off — monitoring overnight.',
                        JSON_OBJECT('from_user',4,'to_user',5,'shift_handoff',true),
                        '2026-05-06 22:00:00'),
  (3, 5, 'comment',     'Reservoir level stable overnight. Holding ticket pending next ground run.', NULL, '2026-05-07 04:30:00'),
  (3, 5, 'status_changed', 'Status changed to on_hold pending parts.',
                        JSON_OBJECT('from','open','to','on_hold'),
                        '2026-05-07 04:35:00');

-- Sample fault tasks demonstrating user-authored, AI-suggested, completed, and blocked states
INSERT INTO fault_tasks
  (fault_id, ticket_id, title, status, holdup_reason, source, task_order, created_by, created_at, completed_by, completed_at)
VALUES
  (1, 1, 'Pull MDC fault history for FCC L and R',                'complete', NULL,                              'user', 10, 3, '2026-05-08 11:00:00', 3, '2026-05-08 13:30:00'),
  (1, 1, 'Verify FCC software part numbers vs SB A-22-31-47',     'in_progress', NULL,                           'user', 20, 4, '2026-05-08 16:00:00', NULL, NULL),
  (1, 1, 'Order replacement FCC LRU if SB not yet applied',       'blocked',  'Awaiting part — backorder ETA 48h','user', 30, 4, '2026-05-08 16:30:00', NULL, NULL),
  (1, NULL, 'Inspect pitch and roll servo for binding',           'pending',  NULL,                              'ai',   40, NULL, '2026-05-08 16:35:00', NULL, NULL),
  (1, NULL, 'Cross-check with Primus Elite avionics fault code 47-22 occurrences', 'pending', NULL,              'ai',   50, NULL, '2026-05-08 16:35:00', NULL, NULL);

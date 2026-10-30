-- Addendum D2: payments (report-only: members report payments made by bank
-- transfer, cash etc.; admins confirm). Amounts are integers in minor units
-- of the one site currency (pence, kobo): never floats.

-- What people pay for. expected_minor, collected_minor and outstanding_minor
-- are counters maintained in the same transaction as every change to a
-- charge or an approved payment; bin/payments-reconcile recomputes them.
-- applies_to: everyone (active members), membership_type (applies_value =
-- type name), location (applies_value = location filter "slot|CC|a1|a2"),
-- members (charges added by hand), nobody (optional, e.g. donations).
-- amount_minor NULL = "any amount" (optional items only).
CREATE TABLE payment_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  item_kind ENUM('dues','levy','event_fee','donation','other') NOT NULL DEFAULT 'dues',
  amount_minor BIGINT NULL,
  due_date DATE NULL,
  status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
  allow_part TINYINT(1) NOT NULL DEFAULT 1,
  applies_to ENUM('everyone','membership_type','location','members','nobody') NOT NULL DEFAULT 'everyone',
  applies_value VARCHAR(255) NOT NULL DEFAULT '',
  include_new_members TINYINT(1) NOT NULL DEFAULT 1,
  counts_as_dues TINYINT(1) NOT NULL DEFAULT 0,
  covers_until DATE NULL,
  remind_enabled TINYINT(1) NOT NULL DEFAULT 1,
  remind_before_days SMALLINT UNSIGNED NULL,
  remind_after_days SMALLINT UNSIGNED NULL,
  expected_minor BIGINT NOT NULL DEFAULT 0,
  collected_minor BIGINT NOT NULL DEFAULT 0,
  outstanding_minor BIGINT NOT NULL DEFAULT 0,
  -- Charge creation in batches of 500 (cron): last members.id done while charges_pending = 1.
  charges_pending TINYINT(1) NOT NULL DEFAULT 0,
  charges_cursor INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_payment_items_status (status, due_date),
  KEY ix_payment_items_pending (charges_pending),
  CONSTRAINT fk_payment_items_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per member per item that applies to them. paid_minor is the sum
-- of approved payments (reversals included) and is maintained with the
-- item counters. charge_status: unpaid | part | paid | waived.
-- member_id becomes NULL when the account is deleted (former_label keeps
-- "Former member #<number>").
CREATE TABLE payment_charges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NULL,
  amount_minor BIGINT NOT NULL,
  paid_minor BIGINT NOT NULL DEFAULT 0,
  waived TINYINT(1) NOT NULL DEFAULT 0,
  waive_reason VARCHAR(255) NOT NULL DEFAULT '',
  charge_status ENUM('unpaid','part','paid','waived') NOT NULL DEFAULT 'unpaid',
  reminded_before_at DATETIME NULL,
  reminded_after_at DATETIME NULL,
  former_label VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_charges_item_member (item_id, member_id),
  KEY ix_payment_charges_member (member_id),
  KEY ix_payment_charges_item_status (item_id, charge_status),
  CONSTRAINT fk_payment_charges_item FOREIGN KEY (item_id) REFERENCES payment_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_charges_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment records (the ledger). entry_type payment | reversal: a reversal
-- is a linked negative entry (reverses_id, unique) and approved payments are
-- never edited or deleted. status: pending | needs_info | approved |
-- rejected | withdrawn. item_id NULL = "Other" (other_name).
-- recorded_by: the admin who entered it (cash at a meeting); NULL when the
-- member reported it. Receipts: receipt_number RCP-<year>-<sequence> and a
-- random verification code, given when the payment is approved.
CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NULL,
  item_id INT UNSIGNED NULL,
  charge_id INT UNSIGNED NULL,
  other_name VARCHAR(120) NOT NULL DEFAULT '',
  entry_type ENUM('payment','reversal') NOT NULL DEFAULT 'payment',
  reverses_id BIGINT UNSIGNED NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  paid_on DATE NOT NULL,
  method ENUM('bank_transfer','cash','pos','mobile_money','cheque','other') NOT NULL DEFAULT 'bank_transfer',
  reference VARCHAR(120) NOT NULL DEFAULT '',
  payer_name VARCHAR(120) NOT NULL DEFAULT '',
  note TEXT NULL,
  receipt_media_id INT UNSIGNED NULL,
  status ENUM('pending','needs_info','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  recorded_by INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(1000) NOT NULL DEFAULT '',
  receipt_number VARCHAR(30) NULL,
  receipt_code CHAR(24) NULL,
  former_label VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_receipt (receipt_number),
  UNIQUE KEY uq_payments_code (receipt_code),
  UNIQUE KEY uq_payments_reverses (reverses_id),
  KEY ix_payments_status_created (status, created_at),
  KEY ix_payments_member (member_id, created_at),
  KEY ix_payments_item (item_id, status),
  KEY ix_payments_charge (charge_id),
  KEY ix_payments_reference (reference),
  KEY ix_payments_paid_on (paid_on),
  KEY ix_payments_receipt_media (receipt_media_id),
  CONSTRAINT fk_payments_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_item FOREIGN KEY (item_id) REFERENCES payment_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_charge FOREIGN KEY (charge_id) REFERENCES payment_charges(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_reverses FOREIGN KEY (reverses_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_receipt FOREIGN KEY (receipt_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline of a payment record (and of a charge: waive, amount change).
-- actor_role member | admin | system; members see "Treasurer"/"Admin" (setting), never admin names.
CREATE TABLE payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NULL,
  charge_id INT UNSIGNED NULL,
  actor_id INT UNSIGNED NULL,
  actor_role ENUM('member','admin','system') NOT NULL DEFAULT 'system',
  event_type VARCHAR(30) NOT NULL,
  note VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_payment_events_payment (payment_id, id),
  KEY ix_payment_events_charge (charge_id, id),
  KEY ix_payment_events_type (event_type, id),
  CONSTRAINT fk_payment_events_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_events_charge FOREIGN KEY (charge_id) REFERENCES payment_charges(id) ON DELETE CASCADE,
  CONSTRAINT fk_payment_events_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receipt numbers: one counter per calendar year, locked FOR UPDATE.
CREATE TABLE receipt_sequences (
  seq_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  seq_value INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batched "new payment report" emails to admins: what each admin was last told about.
CREATE TABLE payment_admin_notices (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  CONSTRAINT fk_payment_admin_notices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-admin "Payment report emails" switch (on by default).
ALTER TABLE users
  ADD COLUMN payment_emails TINYINT(1) NOT NULL DEFAULT 1 AFTER message_findable;

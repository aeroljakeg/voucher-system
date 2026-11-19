SET FOREIGN_KEY_CHECKS = 0;

-- ========== USERS & PERMISSIONS ==========
CREATE TABLE roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE buyer_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ========== SETTINGS ==========
CREATE TABLE settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE fiscal_years (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  is_closed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE doc_numbering (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_type VARCHAR(10) NOT NULL,
  fiscal_year_id INT UNSIGNED NOT NULL,
  prefix VARCHAR(10) NOT NULL,
  next_number INT UNSIGNED NOT NULL DEFAULT 1,
  padding TINYINT UNSIGNED NOT NULL DEFAULT 5,
  UNIQUE KEY uq_doc_fy (doc_type, fiscal_year_id),
  FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id)
) ENGINE=InnoDB;

-- ========== CHART OF ACCOUNTS ==========
CREATE TABLE accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  type ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  normal_balance ENUM('debit','credit') NOT NULL,
  parent_id INT UNSIGNED NULL,
  is_bank TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ========== VOUCHERS ==========
CREATE TABLE vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(30) NOT NULL UNIQUE,
  voucher_type ENUM('payment','receipt','journal','contra') NOT NULL,
  voucher_date DATE NOT NULL,
  fiscal_year_id INT UNSIGNED NOT NULL,
  payee VARCHAR(150) NULL,
  description TEXT NULL,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','submitted','approved','rejected','posted','cancelled') NOT NULL DEFAULT 'draft',
  created_by INT UNSIGNED NOT NULL,
  submitted_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  journal_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_date (voucher_date),
  FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE voucher_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NULL,
  debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id INT UNSIGNED NOT NULL,
  action ENUM('submitted','approved','rejected','cancelled') NOT NULL,
  acted_by INT UNSIGNED NOT NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
  FOREIGN KEY (acted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NULL,
  file_size INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ========== JOURNAL (GENERAL LEDGER) ==========
CREATE TABLE journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(30) NOT NULL UNIQUE,
  entry_date DATE NOT NULL,
  fiscal_year_id INT UNSIGNED NOT NULL,
  voucher_id INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  total_debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of INT UNSIGNED NULL,
  posted_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entry_date (entry_date),
  FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
  FOREIGN KEY (reversal_of) REFERENCES journal_entries(id) ON DELETE SET NULL,
  FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  description VARCHAR(255) NULL,
  debit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  INDEX idx_account (account_id),
  FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

ALTER TABLE vouchers ADD FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE SET NULL;

-- ========== BANKING ==========
CREATE TABLE bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id INT UNSIGNED NOT NULL,
  bank_name VARCHAR(100) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  account_number VARCHAR(50) NOT NULL,
  opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE bank_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_account_id INT UNSIGNED NOT NULL,
  txn_date DATE NOT NULL,
  reference VARCHAR(60) NULL,
  description VARCHAR(255) NULL,
  deposit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  withdrawal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  voucher_id INT UNSIGNED NULL,
  journal_id INT UNSIGNED NULL,
  is_reconciled TINYINT(1) NOT NULL DEFAULT 0,
  reconciled_at DATETIME NULL,
  INDEX idx_bank_date (bank_account_id, txn_date),
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE SET NULL,
  FOREIGN KEY (journal_id) REFERENCES journal_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE bank_reconciliations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_account_id INT UNSIGNED NOT NULL,
  statement_date DATE NOT NULL,
  statement_balance DECIMAL(15,2) NOT NULL,
  book_balance DECIMAL(15,2) NOT NULL,
  reconciled_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
  FOREIGN KEY (reconciled_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ========== AUDIT ==========
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity, entity_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ========== BUYER ORDERS ==========
CREATE TABLE buyer_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  buyer_account_id BIGINT UNSIGNED NULL,
  public_token CHAR(64) NOT NULL UNIQUE,
  customer_name VARCHAR(120) NOT NULL,
  customer_email VARCHAR(254) NOT NULL,
  voucher_amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  stripe_checkout_session_id VARCHAR(255) NULL UNIQUE,
  stripe_payment_intent_id VARCHAR(255) NULL,
  voucher_code VARCHAR(40) NULL UNIQUE,
  status ENUM('pending','paid','expired','checkout_failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  redeemed_at DATETIME NULL,
  redeemed_by INT UNSIGNED NULL,
  INDEX idx_buyer_orders_account_created (buyer_account_id, created_at),
  FOREIGN KEY (buyer_account_id) REFERENCES buyer_accounts(id) ON DELETE SET NULL,
  FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_buyer_orders_status_created (status, created_at)
) ENGINE=InnoDB;

-- ========== SEED DATA ==========
INSERT INTO roles (name, description) VALUES
 ('Admin','Full access'),('Accountant','Create vouchers, view ledgers and reports'),
 ('Approver','Approve or reject vouchers'),('Viewer','Read-only');

INSERT INTO permissions (code, description) VALUES
 ('voucher.view','View vouchers'),('voucher.create','Create/edit vouchers'),
 ('voucher.approve','Approve/reject vouchers'),('voucher.post','Post vouchers to journal'),
 ('journal.view','View journal and ledger'),('journal.reverse','Reverse journal entries'),
 ('accounts.manage','Manage chart of accounts'),('bank.manage','Manage banking'),
 ('reports.view','View reports'),('users.manage','Manage users and roles'),
 ('settings.manage','Manage settings');

INSERT INTO role_permissions SELECT 1, id FROM permissions;
INSERT INTO role_permissions SELECT 2, id FROM permissions
 WHERE code IN ('voucher.view','voucher.create','voucher.post','journal.view','bank.manage','reports.view');
INSERT INTO role_permissions SELECT 3, id FROM permissions
 WHERE code IN ('voucher.view','voucher.approve','journal.view','reports.view');
INSERT INTO role_permissions SELECT 4, id FROM permissions
 WHERE code IN ('voucher.view','journal.view','reports.view');

-- Default login: admin / password  (CHANGE IT after first login)
INSERT INTO users (role_id, username, full_name, email, password_hash) VALUES
 (1,'admin','System Administrator','admin@example.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

INSERT INTO fiscal_years (name, start_date, end_date, is_current) VALUES
 ('FY2026','2026-01-01','2026-12-31',1);

INSERT INTO doc_numbering (doc_type, fiscal_year_id, prefix, next_number, padding) VALUES
 ('payment',1,'PV',1,5),('receipt',1,'RV',1,5),('journal',1,'JV',1,5),
 ('contra',1,'CV',1,5),('entry',1,'JE',1,6);

INSERT INTO settings (setting_key, setting_value) VALUES
 ('company_name','My Company'),('company_address',''),('currency_symbol','₱'),('date_format','Y-m-d');

INSERT INTO accounts (code, name, type, normal_balance, is_bank) VALUES
 ('1000','Cash on Hand','asset','debit',0),
 ('1010','Cash in Bank','asset','debit',1),
 ('1100','Accounts Receivable','asset','debit',0),
 ('1200','Inventory','asset','debit',0),
 ('1500','Equipment','asset','debit',0),
 ('2000','Accounts Payable','liability','credit',0),
 ('2100','Accrued Expenses','liability','credit',0),
 ('2500','Loans Payable','liability','credit',0),
 ('3000','Owner''s Capital','equity','credit',0),
 ('3100','Retained Earnings','equity','credit',0),
 ('4000','Sales Revenue','revenue','credit',0),
 ('4100','Service Revenue','revenue','credit',0),
 ('4900','Other Income','revenue','credit',0),
 ('5000','Cost of Goods Sold','expense','debit',0),
 ('5100','Salaries Expense','expense','debit',0),
 ('5200','Rent Expense','expense','debit',0),
 ('5300','Utilities Expense','expense','debit',0),
 ('5400','Office Supplies Expense','expense','debit',0),
 ('5900','Miscellaneous Expense','expense','debit',0);

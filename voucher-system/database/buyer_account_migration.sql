-- Run this only against an existing database that already has buyer_orders
-- and users tables. Select the database in phpMyAdmin before importing.

CREATE TABLE IF NOT EXISTS buyer_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE buyer_orders
  ADD COLUMN buyer_account_id BIGINT UNSIGNED NULL AFTER id,
  ADD INDEX idx_buyer_orders_account_created (buyer_account_id, created_at),
  ADD CONSTRAINT fk_buyer_orders_account
    FOREIGN KEY (buyer_account_id) REFERENCES buyer_accounts(id) ON DELETE SET NULL;

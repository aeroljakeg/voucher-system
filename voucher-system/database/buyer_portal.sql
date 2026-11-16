USE voucher_system_app;

CREATE TABLE IF NOT EXISTS buyer_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_buyer_orders_status_created (status, created_at)
) ENGINE=InnoDB;

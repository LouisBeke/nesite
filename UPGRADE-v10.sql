-- FoxNetwork v10 - Service Upgrades & Add-ons
ALTER TABLE services ADD COLUMN IF NOT EXISTS billing_cycle VARCHAR(20) NOT NULL DEFAULT 'monthly';
ALTER TABLE services ADD COLUMN IF NOT EXISTS mapped_product_id BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS service_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 service_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NULL,
 change_type VARCHAR(40) NOT NULL,
 status ENUM('pending_payment','paid','applying','completed','failed','cancelled') NOT NULL DEFAULT 'pending_payment',
 old_product_id BIGINT UNSIGNED NULL,
 new_product_id BIGINT UNSIGNED NULL,
 old_price DECIMAL(10,2) NOT NULL DEFAULT 0,
 new_price DECIMAL(10,2) NOT NULL DEFAULT 0,
 amount_due DECIMAL(10,2) NOT NULL DEFAULT 0,
 old_config LONGTEXT NULL,
 new_config LONGTEXT NULL,
 error_message TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL,
 INDEX idx_service_changes_service(service_id),
 INDEX idx_service_changes_invoice(invoice_id),
 CONSTRAINT fk_service_changes_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
 CONSTRAINT fk_service_changes_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_service_changes_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_addons (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 service_id BIGINT UNSIGNED NOT NULL,
 addon_key VARCHAR(50) NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 0,
 monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_service_addon(service_id,addon_key),
 CONSTRAINT fk_service_addons_service FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

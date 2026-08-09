CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 ptero_client_key TEXT NULL,
 role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Stage 4: Store & Ordering
CREATE TABLE IF NOT EXISTS store_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 slug VARCHAR(120) NOT NULL UNIQUE,
 description VARCHAR(255) NULL,
 icon VARCHAR(80) NOT NULL DEFAULT 'fas fa-gamepad',
 sort_order INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 category_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(140) NOT NULL,
 slug VARCHAR(140) NOT NULL UNIQUE,
 description TEXT NULL,
 price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
 ram_mb INT UNSIGNED NOT NULL DEFAULT 2048,
 disk_mb INT UNSIGNED NOT NULL DEFAULT 10000,
 cpu_percent INT UNSIGNED NOT NULL DEFAULT 100,
 backups INT UNSIGNED NOT NULL DEFAULT 1,
 database_limit INT UNSIGNED NOT NULL DEFAULT 1,
 allocation_limit INT UNSIGNED NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_store_product_category FOREIGN KEY (category_id) REFERENCES store_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 order_number VARCHAR(32) NOT NULL UNIQUE,
 status ENUM('pending','awaiting_payment','paid','provisioning','active','cancelled') NOT NULL DEFAULT 'pending',
 subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
 total DECIMAL(10,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NULL,
 product_name VARCHAR(140) NOT NULL,
 unit_price DECIMAL(10,2) NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 config_json JSON NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_order_item_product FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO store_categories (name,slug,description,icon,sort_order) VALUES
('Minecraft Hosting','minecraft','Fast Minecraft servers powered by FoxNetwork.','fas fa-cube',10),
('Game Hosting','games','Simple game server hosting with instant management.','fas fa-gamepad',20);

-- Stage 5: Billing, invoices, services and provisioning
ALTER TABLE users ADD COLUMN IF NOT EXISTS ptero_user_id BIGINT UNSIGNED NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_egg_id INT UNSIGNED NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_nest_id INT UNSIGNED NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_location_id INT UNSIGNED NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_node_id INT UNSIGNED NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_docker_image VARCHAR(255) NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_startup TEXT NULL;
ALTER TABLE store_products ADD COLUMN IF NOT EXISTS ptero_environment LONGTEXT NULL;

CREATE TABLE IF NOT EXISTS invoices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NULL,
 invoice_number VARCHAR(32) NOT NULL UNIQUE,
 status ENUM('unpaid','paid','overdue','cancelled','refunded') NOT NULL DEFAULT 'unpaid',
 subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
 total DECIMAL(10,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 due_at DATETIME NOT NULL,
 paid_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_invoice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 invoice_id BIGINT UNSIGNED NOT NULL,
 description VARCHAR(255) NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_invoice_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 order_id BIGINT UNSIGNED NULL,
 product_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NOT NULL,
 status ENUM('pending','provisioning','active','suspended','terminated','failed') NOT NULL DEFAULT 'pending',
 price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 next_due_at DATETIME NULL,
 ptero_server_id BIGINT UNSIGNED NULL,
 ptero_identifier VARCHAR(32) NULL,
 config_json LONGTEXT NULL,
 last_error TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_service_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_service_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
 CONSTRAINT fk_service_product FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(40) NOT NULL DEFAULT 'manual',
 provider_reference VARCHAR(190) NULL,
 amount DECIMAL(10,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'EUR',
 status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'completed',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 5D: Service lifecycle management
ALTER TABLE services MODIFY COLUMN status ENUM('pending','provisioning','active','suspended','cancelled','terminated','failed') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS service_activity (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 service_id BIGINT UNSIGNED NULL,
 admin_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(60) NOT NULL,
 details TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_service_activity_service (service_id),
 CONSTRAINT fk_service_activity_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
 CONSTRAINT fk_service_activity_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 6: Mollie payments & automation
ALTER TABLE users ADD COLUMN IF NOT EXISTS mollie_customer_id VARCHAR(64) NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS mollie_payment_id VARCHAR(64) NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS mollie_subscription_id VARCHAR(64) NULL;
ALTER TABLE services ADD COLUMN IF NOT EXISTS auto_renew TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payments MODIFY COLUMN status ENUM('pending','completed','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'completed';

-- Stage 6B: Communication, settings, email logs and billing automation
CREATE TABLE IF NOT EXISTS app_settings (
 setting_key VARCHAR(120) PRIMARY KEY,
 setting_value LONGTEXT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
 template_key VARCHAR(80) PRIMARY KEY,
 subject VARCHAR(190) NOT NULL,
 body_html LONGTEXT NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 recipient VARCHAR(190) NOT NULL,
 subject VARCHAR(190) NOT NULL,
 template_key VARCHAR(80) NULL,
 status ENUM('sent','failed') NOT NULL,
 error_message TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_email_log_created(created_at),
 CONSTRAINT fk_email_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS email_notifications TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE services ADD COLUMN IF NOT EXISTS cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS overdue_sent_at DATETIME NULL;

INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES
('company_name','FoxNetwork'),('support_email','info@foxnetwork.be'),('billing_email','info@foxnetwork.be'),
('invoice_prefix','INV'),('currency','EUR'),('vat_rate','21'),('invoice_due_days','7'),('renewal_days_before','7'),
('grace_days','3'),('auto_suspend','1'),('auto_unsuspend','1'),('mail_provider','zoho'),('smtp_host','smtppro.zoho.eu'),('smtp_port','587'),
('smtp_security','tls'),('smtp_ehlo_domain','foxnetwork.be'),('smtp_username','info@foxnetwork.be'),('smtp_password',''),('smtp_from_email','info@foxnetwork.be'),('smtp_from_name','FoxNetwork'),
('zoho_crm_enabled','0'),('zoho_crm_client_id',''),('zoho_crm_client_secret',''),('zoho_crm_refresh_token',''),('zoho_crm_access_token',''),('zoho_crm_access_token_expires_at','0'),('zoho_crm_scope_version','0'),
('zoho_crm_pipeline',''),('zoho_crm_deal_stage_open','Qualification'),('zoho_crm_deal_stage_won','Closed Won'),('zoho_crm_deal_stage_lost','Closed Lost'),
('zoho_crm_case_origin','Web'),('zoho_crm_case_status_open','New'),('zoho_crm_case_status_hold','On Hold'),('zoho_crm_case_status_closed','Closed'),
('cron_token','CHANGE_THIS_TO_A_LONG_RANDOM_TOKEN'),('automation_batch_size','20'),('automation_worker_timeout_seconds','300');

CREATE TABLE IF NOT EXISTS automation_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_key VARCHAR(190) NOT NULL UNIQUE,
 provider VARCHAR(40) NOT NULL,
 job_type VARCHAR(80) NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
 payload LONGTEXT NULL,
 requested_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 processing_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 completed_at DATETIME NULL,
 last_error TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_automation_ready(status,run_at),
 INDEX idx_automation_entity(provider,entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO email_templates(template_key,subject,body_html) VALUES
('invoice_created','Invoice {{invoice_number}} from FoxNetwork','<h2>Your FoxNetwork invoice is ready</h2><p>Hi {{customer_name}},</p><p>Invoice <b>{{invoice_number}}</b> for <b>{{total}} {{currency}}</b> is due on {{due_date}}.</p><p><a href="{{billing_url}}">View & pay invoice</a></p>'),
('payment_received','Payment received — {{invoice_number}}','<h2>Payment received</h2><p>Hi {{customer_name}},</p><p>We received your payment of <b>{{total}} {{currency}}</b> for {{invoice_number}}. Thank you!</p>'),
('service_ready','Your FoxNetwork server is ready','<h2>Your server is ready</h2><p>Hi {{customer_name}},</p><p><b>{{service_name}}</b> has been provisioned and is ready.</p><p><a href="{{portal_url}}">Open My Servers</a></p>'),
('provision_failed','Provisioning needs attention — {{service_name}}','<h2>Provisioning issue</h2><p>Service {{service_name}} could not be provisioned automatically.</p><p>{{error}}</p>'),
('renewal_due','Upcoming renewal — {{service_name}}','<h2>Upcoming renewal</h2><p>Hi {{customer_name}},</p><p>Your service <b>{{service_name}}</b> renews on {{due_date}}. Invoice {{invoice_number}} is now available.</p><p><a href="{{billing_url}}">Pay invoice</a></p>'),
('invoice_overdue','Invoice overdue — {{invoice_number}}','<h2>Invoice overdue</h2><p>Hi {{customer_name}},</p><p>{{invoice_number}} is overdue. Please pay it to avoid service suspension.</p><p><a href="{{billing_url}}">Pay now</a></p>'),
('service_suspended','Service suspended — {{service_name}}','<h2>Service suspended</h2><p>Hi {{customer_name}},</p><p>{{service_name}} has been suspended because its invoice remains unpaid.</p><p><a href="{{billing_url}}">Pay invoice</a></p>'),
('service_unsuspended','Service restored — {{service_name}}','<h2>Service restored</h2><p>Hi {{customer_name}},</p><p>{{service_name}} is active again. Thank you.</p>'),
('service_cancelled','Service cancellation — {{service_name}}','<h2>Cancellation scheduled</h2><p>Hi {{customer_name}},</p><p>{{service_name}} will end at the end of the current billing period.</p>');
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS service_id BIGINT UNSIGNED NULL AFTER order_id;
CREATE INDEX IF NOT EXISTS idx_invoices_service_id ON invoices(service_id);

-- Stage 7: Support Center & Customer Management
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_status ENUM('active','disabled') NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL;

CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NULL,
 assigned_admin_id BIGINT UNSIGNED NULL,
 subject VARCHAR(190) NOT NULL,
 category ENUM('technical','billing','sales','abuse','other') NOT NULL DEFAULT 'technical',
 priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
 status ENUM('open','awaiting_customer','awaiting_staff','answered','closed') NOT NULL DEFAULT 'open',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ticket_user(user_id), INDEX idx_ticket_status(status),
 CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
 CONSTRAINT fk_ticket_admin FOREIGN KEY (assigned_admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 is_staff TINYINT(1) NOT NULL DEFAULT 0,
 is_internal TINYINT(1) NOT NULL DEFAULT 0,
 message TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_support_message_ticket FOREIGN KEY(ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
 CONSTRAINT fk_support_message_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 message_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(120) NULL,
 file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_support_attachment_message FOREIGN KEY(message_id) REFERENCES support_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_activity (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 admin_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 details TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_customer_activity_user(user_id),
 CONSTRAINT fk_customer_activity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_customer_activity_admin FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 8: Production, security, audit and migration
CREATE TABLE IF NOT EXISTS admin_audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 target_type VARCHAR(60) NULL,
 target_id VARCHAR(100) NULL,
 ip_address VARCHAR(64) NULL,
 details TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_created(created_at), INDEX idx_audit_action(action),
 CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 admin_user_id BIGINT UNSIGNED NULL,
 import_type VARCHAR(40) NOT NULL,
 filename VARCHAR(255) NULL,
 rows_total INT UNSIGNED NOT NULL DEFAULT 0,
 rows_created INT UNSIGNED NOT NULL DEFAULT 0,
 rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
 rows_failed INT UNSIGNED NOT NULL DEFAULT 0,
 summary TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_migration_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES
('maintenance_mode','0'),('maintenance_message','FoxNetwork customer portal is undergoing scheduled maintenance.'),
('portal_registration','1'),('security_session_hours','12'),('migration_mode','0');

-- Stage 8P: Paymenter direct migration mapping
CREATE TABLE IF NOT EXISTS migration_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source VARCHAR(40) NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 local_id BIGINT UNSIGNED NOT NULL,
 metadata LONGTEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_migration_source_entity (source,entity_type,source_id),
 KEY idx_migration_local (entity_type,local_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 9: Customer Account & Security
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_secret TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_recovery_codes TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL;
CREATE TABLE IF NOT EXISTS password_reset_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id),CONSTRAINT fk_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS login_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,email VARCHAR(190) NULL,ip_address VARCHAR(45) NULL,user_agent VARCHAR(500) NULL,success TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id),INDEX(created_at),CONSTRAINT fk_login_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS user_sessions (
 session_id VARCHAR(128) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,ip_address VARCHAR(45) NULL,user_agent VARCHAR(500) NULL,last_seen_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id),CONSTRAINT fk_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stage 9C: explicit repair for installations upgraded before Stage 9 schema completed
ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_secret TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS two_factor_recovery_codes TEXT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL;
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

-- Stage 14: Core provisioning queue engine
CREATE TABLE IF NOT EXISTS provisioning_queue (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 job_key VARCHAR(190) NULL,
 job_type VARCHAR(60) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'pending',
 priority INT NOT NULL DEFAULT 50,
 max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 payload LONGTEXT NULL,
 last_error TEXT NULL,
 run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 worker_id VARCHAR(80) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provisioning_job_key(job_key),
 INDEX idx_provisioning_status_run(status,run_at),
 INDEX idx_provisioning_worker(worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provisioning_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 queue_id BIGINT UNSIGNED NULL,
 level VARCHAR(20) NOT NULL DEFAULT 'info',
 event_name VARCHAR(120) NOT NULL,
 message TEXT NULL,
 context_json LONGTEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_provisioning_logs_queue(queue_id),
 INDEX idx_provisioning_logs_event(event_name),
 INDEX idx_provisioning_logs_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provisioning_workers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 worker_id VARCHAR(80) NOT NULL,
 hostname VARCHAR(140) NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'idle',
 processed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
 failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
 last_heartbeat DATETIME NOT NULL,
 started_at DATETIME NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provisioning_worker(worker_id),
 INDEX idx_provisioning_worker_heartbeat(last_heartbeat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS node_cache (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 node_id BIGINT UNSIGNED NOT NULL,
 node_name VARCHAR(160) NULL,
 location_id BIGINT UNSIGNED NULL,
 total_allocations INT UNSIGNED NOT NULL DEFAULT 0,
 free_allocations INT UNSIGNED NOT NULL DEFAULT 0,
 cpu_usage_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
 ram_usage_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
 disk_usage_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
 servers_count INT UNSIGNED NOT NULL DEFAULT 0,
 is_maintenance TINYINT(1) NOT NULL DEFAULT 0,
 metadata_json LONGTEXT NULL,
 last_sync_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_node_cache_node(node_id),
 INDEX idx_node_cache_free(free_allocations,last_sync_at),
 INDEX idx_node_cache_health(is_maintenance,free_allocations,cpu_usage_percent,ram_usage_percent,disk_usage_percent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provisioning_allocation_locks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 queue_id BIGINT UNSIGNED NOT NULL,
 node_id BIGINT UNSIGNED NOT NULL,
 allocation_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'locked',
 release_reason VARCHAR(120) NULL,
 locked_at DATETIME NOT NULL,
 released_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_prov_alloc_lock_allocation(allocation_id),
 INDEX idx_prov_alloc_lock_queue(queue_id),
 INDEX idx_prov_alloc_lock_node(node_id),
 INDEX idx_prov_alloc_lock_status(status,locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES
('provisioning_enabled','1'),
('provisioning_batch_size','5'),
('provisioning_max_attempts','5'),
('provisioning_retry_base_seconds','30'),
('provisioning_retry_max_seconds','900'),
('provisioning_worker_timeout_seconds','240'),
('pterodactyl_webhook_secret',''),
('provisioning_smart_node_enabled','1'),
('provisioning_node_cache_max_age_seconds','300'),
('provisioning_allocation_lock_timeout_seconds','900'),
('provisioning_weight_cpu','35'),
('provisioning_weight_ram','30'),
('provisioning_weight_disk','20'),
('provisioning_weight_servers','15'),
('provisioning_remove_failed_queue_item','1');

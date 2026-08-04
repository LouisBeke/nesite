-- FoxNetwork Hosting v14 - Stage 1 Core Provisioning Engine

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
 metadata_json LONGTEXT NULL,
 last_sync_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_node_cache_node(node_id),
 INDEX idx_node_cache_free(free_allocations,last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES
('provisioning_enabled','1'),
('provisioning_batch_size','5'),
('provisioning_max_attempts','5'),
('provisioning_retry_base_seconds','30'),
('provisioning_retry_max_seconds','900'),
('provisioning_worker_timeout_seconds','240'),
('pterodactyl_webhook_secret','');

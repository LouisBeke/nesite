<?php
declare(strict_types=1);

/**
 * FoxNetwork automatic schema upgrades.
 * Safe to run on every request: changes are only applied when missing.
 */
function fox_column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() > 0;
}

function fox_table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

function fox_migration_applied(PDO $pdo, string $version): bool {
    static $cache = [];
    if (array_key_exists($version, $cache)) {
        return $cache[$version];
    }
    if (!fox_table_exists($pdo, 'fox_schema_migrations')) {
        return $cache[$version] = false;
    }
    $q = $pdo->prepare('SELECT 1 FROM fox_schema_migrations WHERE version = ? LIMIT 1');
    $q->execute([$version]);
    return $cache[$version] = (bool)$q->fetchColumn();
}

function fox_auto_migrate(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $pdo = db();
    // Avoid two PHP requests trying to upgrade the schema simultaneously.
    $lock = $pdo->query("SELECT GET_LOCK('foxnetwork_schema_upgrade', 5)")->fetchColumn();
    if ((int)$lock !== 1) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fox_schema_migrations (
            version VARCHAR(40) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (fox_migration_applied($pdo, 'v10a-auto')) {
            return;
        }

        // Stage 9 columns are included here too so older installs can self-heal.
        if (fox_table_exists($pdo, 'users')) {
            $userColumns = [
                'two_factor_secret' => 'TEXT NULL',
                'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'two_factor_recovery_codes' => 'TEXT NULL',
            ];
            foreach ($userColumns as $column => $definition) {
                if (!fox_column_exists($pdo, 'users', $column)) {
                    $pdo->exec("ALTER TABLE `users` ADD COLUMN `{$column}` {$definition}");
                }
            }
        }

        // Stage 10 service columns.
        if (fox_table_exists($pdo, 'services')) {
            if (!fox_column_exists($pdo, 'services', 'billing_cycle')) {
                $pdo->exec("ALTER TABLE `services` ADD COLUMN `billing_cycle` VARCHAR(20) NOT NULL DEFAULT 'monthly'");
            }
            if (!fox_column_exists($pdo, 'services', 'mapped_product_id')) {
                $pdo->exec("ALTER TABLE `services` ADD COLUMN `mapped_product_id` BIGINT UNSIGNED NULL");
            }
        }

        // Deliberately omit foreign keys here. Existing installations can have
        // differing integer widths/engines; application-level IDs are validated
        // by the portal and this makes upgrades reliable across MariaDB versions.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `service_changes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `invoice_id` BIGINT UNSIGNED NULL,
            `change_type` VARCHAR(40) NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
            `old_product_id` BIGINT UNSIGNED NULL,
            `new_product_id` BIGINT UNSIGNED NULL,
            `old_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `new_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `amount_due` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `old_config` LONGTEXT NULL,
            `new_config` LONGTEXT NULL,
            `error_message` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `completed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_service_changes_service` (`service_id`),
            KEY `idx_service_changes_invoice` (`invoice_id`),
            KEY `idx_service_changes_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `service_addons` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `service_id` BIGINT UNSIGNED NOT NULL,
            `addon_key` VARCHAR(50) NOT NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_service_addon` (`service_id`,`addon_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $q = $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)');
        $q->execute(['v10a-auto']);
    } finally {
        try { $pdo->query("SELECT RELEASE_LOCK('foxnetwork_schema_upgrade')"); } catch (Throwable $e) {}
    }
}

// v11 helpers are intentionally defined outside fox_auto_migrate; bootstrap calls the function above.
function fox_v11_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v11c-stock')) return;
    if (fox_table_exists($pdo,'services')) {
        $cols=[
            'cancel_at_period_end'=>'TINYINT(1) NOT NULL DEFAULT 0',
            'cancel_at'=>'DATETIME NULL',
            'renewal_interval'=>'INT UNSIGNED NOT NULL DEFAULT 1',
            'renewal_unit'=>"VARCHAR(12) NOT NULL DEFAULT 'month'"
        ];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'services',$c)) $pdo->exec("ALTER TABLE `services` ADD COLUMN `$c` $d");
    }
    if (fox_table_exists($pdo,'store_products')) {
        $cols=['billing_period'=>'INT UNSIGNED NOT NULL DEFAULT 1','billing_unit'=>"VARCHAR(12) NOT NULL DEFAULT 'month'",'setup_fee'=>'DECIMAL(10,2) NOT NULL DEFAULT 0.00','stock'=>'INT UNSIGNED NULL'];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'store_products',$c)) $pdo->exec("ALTER TABLE `store_products` ADD COLUMN `$c` $d");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_lifecycle (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,service_id BIGINT UNSIGNED NOT NULL,actor_user_id BIGINT UNSIGNED NULL,event VARCHAR(60) NOT NULL,details TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_lifecycle_service(service_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v11c-stock']);
}

function fox_v9c_migrate(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    $pdo = db();
    if (fox_migration_applied($pdo, 'v9c-security-tables')) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_user_id BIGINT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        target_type VARCHAR(60) NULL,
        target_id VARCHAR(100) NULL,
        ip_address VARCHAR(64) NULL,
        details TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_created(created_at),
        INDEX idx_audit_action(action),
        CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        email VARCHAR(190) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(created_at),
        CONSTRAINT fk_login_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        CONSTRAINT fk_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        session_id VARCHAR(128) PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        last_seen_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        CONSTRAINT fk_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v9c-security-tables']);
}


function fox_v12_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v12-multi-egg')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_eggs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        egg_id BIGINT UNSIGNED NOT NULL,
        display_name VARCHAR(160) NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        docker_image VARCHAR(255) NULL,
        startup TEXT NULL,
        environment LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_product_egg(product_id,egg_id),
        KEY idx_product_eggs_product(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Backward compatibility: convert every legacy single-Egg product into one allowed Egg.
    if(fox_table_exists($pdo,'store_products') && fox_column_exists($pdo,'store_products','ptero_egg_id')){
        $pdo->exec("INSERT IGNORE INTO product_eggs(product_id,egg_id,display_name,is_default,enabled,docker_image,startup,environment)
            SELECT id,ptero_egg_id,NULL,1,1,ptero_docker_image,ptero_startup,ptero_environment
            FROM store_products WHERE ptero_egg_id IS NOT NULL AND ptero_egg_id > 0");
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v12-multi-egg']);
}

function fox_v13_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v13-configurator')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_egg_variables (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        egg_id BIGINT UNSIGNED NOT NULL,
        env_variable VARCHAR(160) NOT NULL,
        display_name VARCHAR(160) NULL,
        description TEXT NULL,
        customer_visible TINYINT(1) NOT NULL DEFAULT 0,
        customer_editable TINYINT(1) NOT NULL DEFAULT 0,
        required TINYINT(1) NOT NULL DEFAULT 0,
        input_type VARCHAR(30) NOT NULL DEFAULT 'text',
        default_value TEXT NULL,
        options_json LONGTEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pev(product_id,egg_id,env_variable),
        KEY idx_pev_product_egg(product_id,egg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v13-configurator']);
}

function fox_v13a_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v13a-client-billing')) return;
    if (fox_table_exists($pdo,'services')) {
        $cols=[
            'cancellation_reason'=>'TEXT NULL',
            'cancellation_requested_at'=>'DATETIME NULL'
        ];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'services',$c)) $pdo->exec("ALTER TABLE `services` ADD COLUMN `$c` $d");
    }
    // Mollie can report canceled/expired; use VARCHAR to stay compatible with all gateway states.
    if (fox_table_exists($pdo,'payments')) {
        try{$pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `status` VARCHAR(30) NOT NULL DEFAULT 'completed'");}catch(Throwable $e){}
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v13a-client-billing']);
}

function fox_v14_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    $v14Ready = fox_migration_applied($pdo, 'v14-provisioning-engine')
        && fox_table_exists($pdo, 'provisioning_queue')
        && fox_table_exists($pdo, 'provisioning_logs')
        && fox_table_exists($pdo, 'provisioning_workers')
        && fox_table_exists($pdo, 'node_cache')
        && fox_table_exists($pdo, 'provisioning_allocation_locks');
    if ($v14Ready) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS provisioning_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
        KEY idx_provisioning_status_run(status,run_at),
        KEY idx_provisioning_worker(worker_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS provisioning_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        queue_id BIGINT UNSIGNED NULL,
        level VARCHAR(20) NOT NULL DEFAULT 'info',
        event_name VARCHAR(120) NOT NULL,
        message TEXT NULL,
        context_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_provisioning_logs_queue(queue_id),
        KEY idx_provisioning_logs_event(event_name),
        KEY idx_provisioning_logs_created(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS provisioning_workers (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        worker_id VARCHAR(80) NOT NULL,
        hostname VARCHAR(140) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'idle',
        processed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
        failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
        last_heartbeat DATETIME NOT NULL,
        started_at DATETIME NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_provisioning_worker(worker_id),
        KEY idx_provisioning_worker_heartbeat(last_heartbeat)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS node_cache (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
        KEY idx_node_cache_free(free_allocations,last_sync_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults=[
        ['provisioning_enabled','1'],
        ['provisioning_batch_size','5'],
        ['provisioning_max_attempts','5'],
        ['provisioning_retry_base_seconds','30'],
        ['provisioning_retry_max_seconds','900'],
        ['provisioning_worker_timeout_seconds','240'],
        ['pterodactyl_webhook_secret','']
    ];
    $ins=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
    foreach($defaults as $row)$ins->execute($row);

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v14-provisioning-engine']);
}

function fox_v14b_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    $v14bReady = fox_migration_applied($pdo, 'v14b-smart-infrastructure')
        && fox_table_exists($pdo, 'node_cache')
        && fox_table_exists($pdo, 'provisioning_allocation_locks');
    if ($v14bReady) return;

    if (fox_table_exists($pdo,'node_cache')) {
        $cols=[
            'cpu_usage_percent'=>"DECIMAL(6,2) NOT NULL DEFAULT 0",
            'ram_usage_percent'=>"DECIMAL(6,2) NOT NULL DEFAULT 0",
            'disk_usage_percent'=>"DECIMAL(6,2) NOT NULL DEFAULT 0",
            'servers_count'=>"INT UNSIGNED NOT NULL DEFAULT 0",
            'is_maintenance'=>"TINYINT(1) NOT NULL DEFAULT 0"
        ];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'node_cache',$c)) $pdo->exec("ALTER TABLE `node_cache` ADD COLUMN `$c` $d");
        try{$pdo->exec("ALTER TABLE `node_cache` ADD KEY idx_node_cache_health(is_maintenance,free_allocations,cpu_usage_percent,ram_usage_percent,disk_usage_percent)");}catch(Throwable $e){}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS provisioning_allocation_locks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
        KEY idx_prov_alloc_lock_queue(queue_id),
        KEY idx_prov_alloc_lock_node(node_id),
        KEY idx_prov_alloc_lock_status(status,locked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults=[
        ['provisioning_smart_node_enabled','1'],
        ['provisioning_node_cache_max_age_seconds','300'],
        ['provisioning_allocation_lock_timeout_seconds','900'],
        ['provisioning_weight_cpu','35'],
        ['provisioning_weight_ram','30'],
        ['provisioning_weight_disk','20'],
        ['provisioning_weight_servers','15'],
        ['provisioning_remove_failed_queue_item','1']
    ];
    $ins=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
    foreach($defaults as $row)$ins->execute($row);

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v14b-smart-infrastructure']);
}

function fox_v15_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15-advanced-hosting')) return;

    $defaults=[
        ['hosting_allow_startup_variable_edit','1'],
        ['hosting_allow_custom_startup_command','1'],
        ['hosting_allow_docker_image_selection','0'],
        ['hosting_allow_extra_allocations','1']
    ];
    $ins=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
    foreach($defaults as $row)$ins->execute($row);

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15-advanced-hosting']);
}

function fox_v15a_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15a-remove-legacy-minecraft-plans')) return;

    if (fox_table_exists($pdo, 'store_products')) {
        $del = $pdo->prepare('DELETE FROM store_products WHERE slug IN (?,?,?) OR name IN (?,?,?)');
        $del->execute([
            'minecraft-starter','minecraft-plus','minecraft-pro',
            'Fox Starter','Fox Plus','Fox Pro'
        ]);
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15a-remove-legacy-minecraft-plans']);
}

function fox_v15b_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15b-reprice-minecraft-plans')) return;

    if (fox_table_exists($pdo, 'store_products')) {
        $prices = [
            'iron' => 5.00,
            'bronze' => 8.00,
            'silver' => 12.00,
            'gold' => 18.00,
        ];
        $up = $pdo->prepare('UPDATE store_products SET price_monthly=? WHERE slug=?');
        foreach ($prices as $slug => $price) {
            $up->execute([$price, $slug]);
        }
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15b-reprice-minecraft-plans']);
}

function fox_v15c_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15c-service-order-uniqueness')) return;
    if (!fox_table_exists($pdo, 'services')) {
        $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15c-service-order-uniqueness']);
        return;
    }

    // Merge duplicate service rows that point to the same order.
    $dups = $pdo->query("SELECT order_id FROM services WHERE order_id IS NOT NULL GROUP BY order_id HAVING COUNT(*)>1")->fetchAll();
    foreach($dups as $d){
        $oid=(int)($d['order_id']??0);
        if($oid<1)continue;
        $q=$pdo->prepare("SELECT * FROM services WHERE order_id=? ORDER BY (ptero_server_id IS NOT NULL) DESC, (status='active') DESC, id DESC");
        $q->execute([$oid]);
        $rows=$q->fetchAll();
        if(count($rows)<2)continue;
        $keep=$rows[0];
        $keepId=(int)$keep['id'];
        $keepServer=(int)($keep['ptero_server_id']??0);
        foreach(array_slice($rows,1) as $r){
            $rid=(int)$r['id'];
            $srv=(int)($r['ptero_server_id']??0);
            if($keepServer<=0 && $srv>0){
                $pdo->prepare("UPDATE services SET ptero_server_id=?,ptero_identifier=?,status='active',last_error=NULL WHERE id=?")
                    ->execute([$srv,(string)($r['ptero_identifier']??''),$keepId]);
                $keepServer=$srv;
            }
            $pdo->prepare("UPDATE services SET order_id=NULL,status='cancelled',last_error=? WHERE id=?")
                ->execute(['Merged duplicate service for order #'.$oid.' into service #'.$keepId.'.',$rid]);
        }
    }

    try{$pdo->exec("ALTER TABLE services ADD UNIQUE KEY uq_services_order_id(order_id)");}catch(Throwable $e){}
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15c-service-order-uniqueness']);
}

function fox_v15d_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15d-merge-duplicate-services')) return;
    // Intentionally no-op. Matching by customer, product, or display name can
    // collapse legitimate separate services. Exact same-order duplicates are
    // handled by v15c and the unique services.order_id constraint.
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15d-merge-duplicate-services']);
}

function fox_v15e_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15e-support-email')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('support_email',?)
                       ON DUPLICATE KEY UPDATE setting_value=IF(setting_value='support@foxnetwork.be',VALUES(setting_value),setting_value)")
            ->execute(['info@foxnetwork.be']);
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15e-support-email']);
}

function fox_v15f_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15f-billing-email')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('billing_email',?)
                       ON DUPLICATE KEY UPDATE setting_value=IF(setting_value='billing@foxnetwork.be',VALUES(setting_value),setting_value)")
            ->execute(['info@foxnetwork.be']);
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15f-billing-email']);
}

function fox_v15g_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15g-zoho-mail')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $q=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='mail_provider' LIMIT 1");
        $q->execute();
        $oldProvider=(string)($q->fetchColumn()?:'');

        $defaults=[
            'mail_provider'=>'zoho',
            'smtp_host'=>'smtppro.zoho.eu',
            'smtp_port'=>'587',
            'smtp_security'=>'tls',
            'smtp_username'=>'info@foxnetwork.be',
            'smtp_password'=>'',
            'smtp_from_email'=>'info@foxnetwork.be',
            'smtp_from_name'=>'FoxNetwork',
        ];
        $insert=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
        foreach($defaults as $key=>$value)$insert->execute([$key,$value]);

        if($oldProvider==='m365'){
            $update=$pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?');
            foreach($defaults as $key=>$value)$update->execute([$value,$key]);
        }else{
            $pdo->prepare("UPDATE app_settings SET setting_value='zoho' WHERE setting_key='mail_provider'")->execute();
        }

        $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('m365_tenant_id','m365_client_id','m365_client_secret','m365_sender_email')");
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15g-zoho-mail']);
}

function fox_v15h_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15h-smtp-ehlo-domain')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('smtp_ehlo_domain','foxnetwork.be')")->execute();
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15h-smtp-ehlo-domain']);
}

function fox_v15i_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15i-zoho-crm')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $defaults=[
            'zoho_crm_enabled'=>'0',
            'zoho_crm_client_id'=>'',
            'zoho_crm_client_secret'=>'',
            'zoho_crm_refresh_token'=>'',
            'zoho_crm_access_token'=>'',
            'zoho_crm_access_token_expires_at'=>'0',
        ];
        $insert=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
        foreach($defaults as $key=>$value)$insert->execute([$key,$value]);
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15i-zoho-crm']);
}

function fox_v15j_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15j-zoho-crm-full-sync')) return;

    if (fox_table_exists($pdo, 'app_settings')) {
        $defaults=[
            'zoho_crm_scope_version'=>'0',
            'zoho_crm_pipeline'=>'',
            'zoho_crm_deal_stage_open'=>'Qualification',
            'zoho_crm_deal_stage_won'=>'Closed Won',
            'zoho_crm_deal_stage_lost'=>'Closed Lost',
            'zoho_crm_case_origin'=>'Web',
            'zoho_crm_case_status_open'=>'New',
            'zoho_crm_case_status_hold'=>'On Hold',
            'zoho_crm_case_status_closed'=>'Closed',
        ];
        $insert=$pdo->prepare('INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
        foreach($defaults as $key=>$value)$insert->execute([$key,$value]);
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15j-zoho-crm-full-sync']);
}

function fox_v15l_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15l-free-services-never-expire')) return;
    if (fox_table_exists($pdo, 'services')) {
        $pdo->exec("UPDATE services SET next_due_at=NULL,cancel_at_period_end=0,cancel_at=NULL WHERE price_monthly<=0");
        if (fox_table_exists($pdo, 'invoices')) {
            $pdo->exec("UPDATE invoices i JOIN services s ON s.id=i.service_id SET i.status='cancelled' WHERE s.price_monthly<=0 AND i.total<=0 AND i.status IN ('unpaid','overdue')");
        }
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15l-free-services-never-expire']);
}

function fox_v15m_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15m-service-trials')) return;
    if (fox_table_exists($pdo, 'services') && !fox_column_exists($pdo, 'services', 'is_trial')) {
        $pdo->exec("ALTER TABLE services ADD COLUMN is_trial TINYINT(1) NOT NULL DEFAULT 0 AFTER price_monthly");
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15m-service-trials']);
}

function fox_v15k_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v15k-automation-jobs')) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS automation_jobs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (fox_table_exists($pdo, 'app_settings')) {
        $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('automation_batch_size','20'),('automation_worker_timeout_seconds','300')")->execute();
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15k-automation-jobs']);
}

function fox_v16_linode_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if (fox_migration_applied($pdo, 'v16-linode-vps')) return;
    if (fox_table_exists($pdo, 'store_products')) {
        $columns = [
            'provisioning_provider'=>"VARCHAR(24) NOT NULL DEFAULT 'pterodactyl'",
            'linode_type'=>'VARCHAR(80) NULL',
            'linode_region'=>'VARCHAR(80) NULL',
            'linode_image'=>'VARCHAR(160) NULL',
            'linode_backups'=>'TINYINT(1) NOT NULL DEFAULT 0',
            'linode_firewall_id'=>'BIGINT UNSIGNED NULL',
            'linode_cloud_init'=>'LONGTEXT NULL',
        ];
        foreach($columns as $column=>$definition) if(!fox_column_exists($pdo,'store_products',$column)) $pdo->exec("ALTER TABLE store_products ADD COLUMN `$column` $definition");
    }
    if (fox_table_exists($pdo, 'services')) {
        $columns = [
            'provisioning_provider'=>"VARCHAR(24) NOT NULL DEFAULT 'pterodactyl'",
            'linode_instance_id'=>'BIGINT UNSIGNED NULL',
            'linode_ipv4'=>'VARCHAR(45) NULL',
            'linode_ipv6'=>'VARCHAR(128) NULL',
            'linode_root_password'=>'TEXT NULL',
        ];
        foreach($columns as $column=>$definition) if(!fox_column_exists($pdo,'services',$column)) $pdo->exec("ALTER TABLE services ADD COLUMN `$column` $definition");
        $pdo->exec("UPDATE services s JOIN store_products p ON p.id=s.product_id SET s.provisioning_provider=p.provisioning_provider WHERE p.provisioning_provider='linode' AND s.linode_instance_id IS NULL");
    }
    if (fox_table_exists($pdo, 'app_settings')) {
        $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('linode_api_url','https://api.linode.com/v4'),('linode_disk_encryption','enabled')")->execute();
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v16-linode-vps']);
}

function fox_v16a_provider_config_cleanup_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if(fox_migration_applied($pdo,'v16a-provider-config-cleanup'))return;
    if(fox_table_exists($pdo,'store_products')&&fox_column_exists($pdo,'store_products','provisioning_provider')){
        $pdo->exec("UPDATE store_products SET ptero_egg_id=NULL,ptero_nest_id=NULL,ptero_location_id=NULL,ptero_docker_image=NULL,ptero_startup=NULL,ptero_environment=NULL,ptero_node_id=NULL,backups=0,database_limit=0,allocation_limit=1 WHERE provisioning_provider='linode'");
        $pdo->exec("UPDATE store_products SET linode_type=NULL,linode_region=NULL,linode_image=NULL,linode_backups=0,linode_firewall_id=NULL,linode_cloud_init=NULL WHERE provisioning_provider<>'linode'");
        if(fox_table_exists($pdo,'product_eggs'))$pdo->exec("DELETE pe FROM product_eggs pe JOIN store_products p ON p.id=pe.product_id WHERE p.provisioning_provider='linode'");
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v16a-provider-config-cleanup']);
}

function fox_v17_blog_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
    if(fox_migration_applied($pdo,'v17-blog'))return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_posts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        external_id VARCHAR(190) NULL,
        slug VARCHAR(190) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        excerpt TEXT NULL,
        content_html LONGTEXT NOT NULL,
        meta_title VARCHAR(255) NULL,
        meta_description VARCHAR(320) NULL,
        category VARCHAR(120) NULL,
        author_name VARCHAR(160) NULL,
        hero_image VARCHAR(500) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'draft',
        published_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_blog_external_id(external_id),
        INDEX idx_blog_public(status,published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(fox_table_exists($pdo,'app_settings')){
        $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('soro_webhook_secret',''),('blog_author_name','FoxNetwork Team')")->execute();
    }
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v17-blog']);
}

function fox_v18_oxxa_domains_migrate(): void {
    static $ran=false;if($ran)return;$ran=true;$pdo=db();
    if(fox_migration_applied($pdo,'v18-oxxa-domains'))return;
    if(fox_table_exists($pdo,'app_settings'))$pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES
      ('oxxa_enabled','0'),('oxxa_api_url','https://api.oxxa.com/command.php'),('oxxa_api_user',''),('oxxa_api_password',''),
      ('oxxa_identity_handle',''),('oxxa_nsgroup',''),('oxxa_dns_template',''),('oxxa_nginx_egg_ids',''),
      ('oxxa_domain_price','12.50'),('oxxa_test_mode','1')")->execute();
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v18-oxxa-domains']);
}

function fox_v18a_domain_contacts_cloudflare_migrate(): void {
    static $ran=false;if($ran)return;$ran=true;$pdo=db();
    if(fox_migration_applied($pdo,'v18a-domain-contacts-cloudflare'))return;
    if(fox_table_exists($pdo,'users'))foreach([
        'company_name'=>'VARCHAR(190) NULL','phone'=>'VARCHAR(40) NULL','street'=>'VARCHAR(190) NULL','house_number'=>'VARCHAR(30) NULL',
        'postal_code'=>'VARCHAR(30) NULL','city'=>'VARCHAR(120) NULL','state'=>'VARCHAR(120) NULL','country_code'=>'CHAR(2) NULL','oxxa_identity_handle'=>'VARCHAR(80) NULL'
    ] as $column=>$definition)if(!fox_column_exists($pdo,'users',$column))$pdo->exec("ALTER TABLE users ADD COLUMN `$column` $definition");
    if(fox_table_exists($pdo,'app_settings'))$pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('cloudflare_api_token',''),('cloudflare_account_id',''),('oxxa_price_markup_percent','25'),('oxxa_price_fixed_fee','2.50'),('oxxa_price_minimum','10.00'),('oxxa_price_cache_seconds','3600')")->execute();
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v18a-domain-contacts-cloudflare']);
}

function fox_v18b_oxxa_auto_pricing_migrate(): void {
    static $ran=false;if($ran)return;$ran=true;$pdo=db();
    if(fox_migration_applied($pdo,'v18b-oxxa-auto-pricing'))return;
    if(fox_table_exists($pdo,'app_settings'))$pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('oxxa_price_markup_percent','25'),('oxxa_price_fixed_fee','2.50'),('oxxa_price_minimum','10.00'),('oxxa_price_cache_seconds','3600')")->execute();
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v18b-oxxa-auto-pricing']);
}

function fox_v19_email_tracking_migrate(): void {
    static $ran=false;if($ran)return;$ran=true;$pdo=db();
    if(fox_migration_applied($pdo,'v19-email-tracking'))return;
    if(fox_table_exists($pdo,'email_log')){
        $columns=[
            'tracking_token'=>'VARCHAR(64) NULL',
            'sent_at'=>'DATETIME NULL',
            'first_opened_at'=>'DATETIME NULL',
            'last_opened_at'=>'DATETIME NULL',
            'open_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'first_clicked_at'=>'DATETIME NULL',
            'last_clicked_at'=>'DATETIME NULL',
            'click_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
            'last_clicked_url'=>'TEXT NULL',
        ];
        foreach($columns as $column=>$definition)if(!fox_column_exists($pdo,'email_log',$column))$pdo->exec("ALTER TABLE email_log ADD COLUMN `$column` $definition");
        $pdo->exec("ALTER TABLE email_log MODIFY status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending'");
        $index=$pdo->query("SHOW INDEX FROM email_log WHERE Key_name='uq_email_tracking_token'")->fetch();
        if(!$index)$pdo->exec('ALTER TABLE email_log ADD UNIQUE KEY uq_email_tracking_token(tracking_token)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_tracking_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email_log_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(20) NOT NULL,
        target_url TEXT NULL,
        ip_hash CHAR(64) NULL,
        user_agent VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_event_log(email_log_id,created_at),
        INDEX idx_email_event_type(event_type,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(fox_table_exists($pdo,'app_settings'))$pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('email_tracking_enabled','1')")->execute();
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v19-email-tracking']);
}
/* Removed malformed duplicate migration tail.
')) {
        $pdo->prepare("INSERT IGNORE INTO app_settings(setting_key,setting_value) VALUES('automation_batch_size','20'),('automation_worker_timeout_seconds','300')")->execute();
    }

    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15k-automation-jobs']);
}
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v15k-automation-jobs']);
}
*/

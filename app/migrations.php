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
    if (fox_table_exists($pdo,'services')) {
        $cols=[
            'cancel_at_period_end'=>'TINYINT(1) NOT NULL DEFAULT 0',
            'cancel_at'=>'DATETIME NULL',
            'renewal_interval'=>'INT UNSIGNED NOT NULL DEFAULT 1',
            'renewal_unit'=>"VARCHAR(12) NOT NULL DEFAULT 'month'",
            'paymenter_service_id'=>'BIGINT UNSIGNED NULL'
        ];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'services',$c)) $pdo->exec("ALTER TABLE `services` ADD COLUMN `$c` $d");
    }
    if (fox_table_exists($pdo,'store_categories')) {
        foreach(['paymenter_category_id'=>'BIGINT UNSIGNED NULL','source'=>"VARCHAR(30) NULL"] as $c=>$d) if(!fox_column_exists($pdo,'store_categories',$c)) $pdo->exec("ALTER TABLE `store_categories` ADD COLUMN `$c` $d");
    }
    if (fox_table_exists($pdo,'store_products')) {
        $cols=['paymenter_product_id'=>'BIGINT UNSIGNED NULL','billing_period'=>'INT UNSIGNED NOT NULL DEFAULT 1','billing_unit'=>"VARCHAR(12) NOT NULL DEFAULT 'month'",'setup_fee'=>'DECIMAL(10,2) NOT NULL DEFAULT 0.00','source'=>"VARCHAR(30) NULL",'stock'=>'INT UNSIGNED NULL'];
        foreach($cols as $c=>$d) if(!fox_column_exists($pdo,'store_products',$c)) $pdo->exec("ALTER TABLE `store_products` ADD COLUMN `$c` $d");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_lifecycle (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,service_id BIGINT UNSIGNED NOT NULL,actor_user_id BIGINT UNSIGNED NULL,event VARCHAR(60) NOT NULL,details TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_lifecycle_service(service_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->prepare('INSERT IGNORE INTO fox_schema_migrations(version) VALUES(?)')->execute(['v11c-stock']);
}


function fox_v12_migrate(): void {
    static $ran=false; if($ran)return; $ran=true; $pdo=db();
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

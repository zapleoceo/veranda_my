<?php

namespace App\Classes;

class Database {
    private \PDO $pdo;
    private string $tableSuffix;

    public function __construct(string $host, string $db, string $user, string $pass, string $tableSuffix = '') {
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new \PDO($dsn, $user, $pass, $options);
            $suffix = trim((string)$tableSuffix);
            if ($suffix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $suffix)) {
                $suffix = '';
            }
            $this->tableSuffix = $suffix;
        } catch (\PDOException $e) {
            throw new \Exception("Database Connection Error: " . $e->getMessage());
        }
    }

    public function query(string $sql, array $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function getPdo(): \PDO {
        return $this->pdo;
    }

    public function t(string $baseName): string {
        return $baseName . $this->tableSuffix;
    }

    public function createTables(): void {
        $ks = $this->t('kitchen_stats');
        $sql = "CREATE TABLE IF NOT EXISTS {$ks} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATE NOT NULL,
            receipt_number VARCHAR(50),
            transaction_opened_at DATETIME,
            transaction_closed_at DATETIME,
            transaction_id BIGINT NOT NULL,
            table_number VARCHAR(50),
            waiter_name VARCHAR(255) NULL,
            status INT DEFAULT 1,
            pay_type TINYINT NULL,
            close_reason TINYINT NULL,
            exclude_from_dashboard TINYINT(1) NOT NULL DEFAULT 0,
            exclude_auto TINYINT(1) NOT NULL DEFAULT 0,
            dish_id BIGINT NOT NULL,
            item_seq INT NOT NULL DEFAULT 1,
            dish_category_id BIGINT NULL,
            dish_sub_category_id BIGINT NULL,
            dish_name VARCHAR(255),
            ticket_sent_at DATETIME,
            ready_pressed_at DATETIME,
            ready_chass_at DATETIME NULL,
            prob_close_at DATETIME NULL,
            was_deleted TINYINT(1) NOT NULL DEFAULT 0,
            tg_message_id BIGINT NULL,
            tg_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
            tg_acknowledged_at DATETIME NULL,
            tg_acknowledged_by VARCHAR(255) NULL,
            service_type INT,
            total_sum DECIMAL(10,2),
            station VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_dish_tx (transaction_id, dish_id, item_seq),
            KEY idx_ks_date (transaction_date),
            KEY idx_ks_ticket (ticket_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $this->pdo->exec($sql);
        try {
            $this->pdo->exec("ALTER TABLE {$ks} ADD COLUMN transaction_comment TEXT NULL");
        } catch (\Throwable $e) {
        }
        try {
            $this->pdo->exec("ALTER TABLE {$ks} ADD COLUMN tg_message_id BIGINT NULL");
        } catch (\Throwable $e) {
        }
        try {
            $this->pdo->exec("ALTER TABLE {$ks} ADD COLUMN tg_sent_at DATETIME NULL");
        } catch (\Throwable $e) {
        }
        try {
            $this->pdo->exec("ALTER TABLE {$ks} ADD COLUMN tg_last_edit_at DATETIME NULL");
        } catch (\Throwable $e) {
        }
    }

    public function saveStats(array $stats): void {
        $ks = $this->t('kitchen_stats');
        $sql = "INSERT INTO {$ks} 
                (transaction_date, receipt_number, transaction_opened_at, transaction_closed_at, transaction_id, table_number, waiter_name, transaction_comment, status, pay_type, close_reason, dish_id, item_seq, dish_category_id, dish_sub_category_id, dish_name, ticket_sent_at, ready_pressed_at, ready_chass_at, was_deleted, service_type, total_sum, station, exclude_from_dashboard, exclude_auto) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                dish_id = VALUES(dish_id),
                item_seq = VALUES(item_seq),
                dish_category_id = VALUES(dish_category_id),
                dish_sub_category_id = VALUES(dish_sub_category_id),
                dish_name = VALUES(dish_name),
                transaction_opened_at = VALUES(transaction_opened_at),
                transaction_closed_at = VALUES(transaction_closed_at),
                table_number = VALUES(table_number),
                waiter_name = VALUES(waiter_name),
                transaction_comment = COALESCE(VALUES(transaction_comment), transaction_comment),
                status = VALUES(status),
                pay_type = VALUES(pay_type),
                close_reason = VALUES(close_reason),
                ticket_sent_at = COALESCE(VALUES(ticket_sent_at), ticket_sent_at),
                ready_pressed_at = COALESCE(VALUES(ready_pressed_at), ready_pressed_at),
                ready_chass_at = COALESCE(VALUES(ready_chass_at), ready_chass_at),
                was_deleted = GREATEST(VALUES(was_deleted), was_deleted),
                service_type = VALUES(service_type),
                total_sum = VALUES(total_sum),
                station = VALUES(station),
                exclude_from_dashboard = CASE
                    WHEN (VALUES(dish_category_id) = 47 OR VALUES(dish_sub_category_id) = 47) THEN 1
                    ELSE exclude_from_dashboard
                END,
                exclude_auto = CASE
                    WHEN (VALUES(dish_category_id) = 47 OR VALUES(dish_sub_category_id) = 47) THEN 1
                    ELSE exclude_auto
                END;";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($stats as $row) {
            $mainCat = isset($row['dish_category_id']) && $row['dish_category_id'] !== null ? (int)$row['dish_category_id'] : null;
            $subCat = isset($row['dish_sub_category_id']) && $row['dish_sub_category_id'] !== null ? (int)$row['dish_sub_category_id'] : null;
            $isHookah = ($mainCat === 47) || ($subCat === 47);
            $stmt->execute([
                $row['date'],
                $row['receipt_number'],
                $row['transaction_opened_at'],
                $row['transaction_closed_at'],
                $row['transaction_id'],
                $row['table_number'] ?? null,
                $row['waiter_name'] ?? null,
                $row['transaction_comment'] ?? null,
                $row['status'],
                $row['pay_type'] ?? null,
                $row['close_reason'] ?? null,
                $row['dish_id'],
                isset($row['item_seq']) && (int)$row['item_seq'] > 0 ? (int)$row['item_seq'] : 1,
                $mainCat,
                $subCat,
                $row['dish_name'],
                $row['ticket_sent_at'],
                $row['ready_pressed_at'],
                $row['ready_chass_at'] ?? null,
                !empty($row['was_deleted']) ? 1 : 0,
                $row['service_type'],
                $row['total_sum'],
                $row['station'],
                $isHookah ? 1 : 0,
                $isHookah ? 1 : 0
            ]);
        }
    }

    public function getLatestStats(int $limit = 50): array {
        $ks = $this->t('kitchen_stats');
        return $this->query("SELECT * FROM {$ks} ORDER BY ticket_sent_at DESC LIMIT ?", [$limit])->fetchAll();
    }

    public function createMenuTables(): void {
        $pmi = $this->t('poster_menu_items');
        $mw = $this->t('menu_workshops');
        $mwTr = $this->t('menu_workshop_tr');
        $mc = $this->t('menu_categories');
        $mcTr = $this->t('menu_category_tr');
        $mi = $this->t('menu_items');
        $miTr = $this->t('menu_item_tr');
        $fkTag = $this->tableSuffix !== '' ? substr(sha1($this->tableSuffix), 0, 6) : 'base';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$pmi} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_id BIGINT NOT NULL,
            name_raw VARCHAR(255) NOT NULL,
            price_raw DECIMAL(10,2) NULL,
            cost_raw DECIMAL(10,2) NULL,
            station_id BIGINT NULL,
            station_name VARCHAR(255) NULL,
            main_category_id BIGINT NULL,
            main_category_name VARCHAR(255) NULL,
            sub_category_id BIGINT NULL,
            sub_category_name VARCHAR(255) NULL,
            raw_json JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_poster_menu_items_poster_id (poster_id),
            KEY idx_poster_menu_items_active (is_active),
            KEY idx_poster_menu_items_station (station_id),
            KEY idx_poster_menu_items_main_cat (main_category_id),
            KEY idx_poster_menu_items_sub_cat (sub_category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mw} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            poster_id INT UNSIGNED NOT NULL,
            name_raw VARCHAR(255) NOT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            show_on_site TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_workshops_poster_id (poster_id),
            KEY idx_menu_workshops_sort (sort_order),
            KEY idx_menu_workshops_show (show_on_site)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mwTr} (
            workshop_id INT UNSIGNED NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (workshop_id, lang),
            CONSTRAINT fk_menu_workshop_tr_workshop_{$fkTag} FOREIGN KEY (workshop_id) REFERENCES {$mw}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mc} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            poster_id INT UNSIGNED NOT NULL,
            workshop_id INT UNSIGNED NULL,
            name_raw VARCHAR(255) NOT NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            show_on_site TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_poster_id (poster_id),
            KEY idx_menu_categories_workshop (workshop_id),
            KEY idx_menu_categories_sort (sort_order),
            KEY idx_menu_categories_show (show_on_site),
            CONSTRAINT fk_menu_categories_workshop_{$fkTag} FOREIGN KEY (workshop_id) REFERENCES {$mw}(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mcTr} (
            category_id INT UNSIGNED NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (category_id, lang),
            CONSTRAINT fk_menu_category_tr_category_{$fkTag} FOREIGN KEY (category_id) REFERENCES {$mc}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mi} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            poster_item_id INT NOT NULL,
            category_id INT UNSIGNED NULL,
            image_url VARCHAR(512) NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_items_poster_item_id (poster_item_id),
            KEY idx_menu_items_published (is_published),
            KEY idx_menu_items_category (category_id),
            KEY idx_menu_items_sort (sort_order),
            CONSTRAINT fk_menu_items_poster_{$fkTag} FOREIGN KEY (poster_item_id) REFERENCES {$pmi}(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_category_{$fkTag} FOREIGN KEY (category_id) REFERENCES {$mc}(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$miTr} (
            item_id INT UNSIGNED NOT NULL,
            lang VARCHAR(8) NOT NULL,
            title VARCHAR(512) NULL,
            description TEXT NULL,
            PRIMARY KEY (item_id, lang),
            CONSTRAINT fk_menu_item_tr_item_{$fkTag} FOREIGN KEY (item_id) REFERENCES {$mi}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    public function createPaydayTables(): void {
        $st = $this->t('sepay_transactions');
        $pc = $this->t('poster_checks');
        $ppm = $this->t('poster_payment_methods');
        $pt = $this->t('poster_transactions');
        $pa = $this->t('poster_accounts');
        $pl = $this->t('check_payment_links');
        $fkTag = $this->tableSuffix !== '' ? substr(sha1($this->tableSuffix), 0, 6) : 'base';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$st} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sepay_id BIGINT UNSIGNED NOT NULL,
            gateway VARCHAR(100) NOT NULL,
            transaction_date DATETIME NOT NULL,
            account_number VARCHAR(50) NOT NULL,
            code VARCHAR(100) NULL,
            content TEXT NOT NULL,
            transfer_type ENUM('in','out') NOT NULL,
            transfer_amount BIGINT NOT NULL,
            accumulated BIGINT NOT NULL,
            sub_account VARCHAR(100) NULL,
            reference_code VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            payment_method VARCHAR(50) NULL,
            raw_request_body LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sepay_id (sepay_id),
            KEY idx_sepay_date (transaction_date),
            KEY idx_sepay_type (transfer_type),
            KEY idx_sepay_method (payment_method)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM {$st}")->fetchAll(\PDO::FETCH_ASSOC);
            $hasRaw = false;
            foreach ($cols as $c) {
                if (strtolower((string)($c['Field'] ?? '')) === 'raw_request_body') {
                    $hasRaw = true;
                    break;
                }
            }
            if (!$hasRaw) {
                $this->pdo->exec("ALTER TABLE {$st} ADD COLUMN raw_request_body LONGTEXT NULL");
            }
        } catch (\Throwable $e) {
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$pc} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id BIGINT UNSIGNED NOT NULL,
            receipt_number BIGINT NULL,
            table_id INT NULL,
            spot_id INT NULL,
            sum BIGINT NOT NULL,
            payed_sum BIGINT NOT NULL,
            payed_cash BIGINT NOT NULL,
            payed_card BIGINT NOT NULL,
            payed_cert BIGINT NOT NULL,
            payed_bonus BIGINT NOT NULL,
            payed_third_party BIGINT NOT NULL DEFAULT 0,
            pay_type TINYINT NOT NULL,
            reason TINYINT NULL,
            tip_sum BIGINT NOT NULL,
            discount DECIMAL(5,2) NOT NULL,
            date_close DATETIME NOT NULL,
            poster_payment_method_id INT UNSIGNED NULL,
            waiter_name VARCHAR(100) NULL,
            day_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_poster_tx (transaction_id),
            KEY idx_poster_day (day_date),
            KEY idx_poster_close (date_close),
            KEY idx_poster_pm_id (poster_payment_method_id),
            KEY idx_poster_pay_type (pay_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM {$pc}")->fetchAll(\PDO::FETCH_ASSOC);
            $have = [];
            foreach ($cols as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $have[$f] = true;
            }
            if (empty($have['receipt_number'])) {
                $this->pdo->exec("ALTER TABLE {$pc} ADD COLUMN receipt_number BIGINT NULL");
            }
            if (empty($have['payed_third_party'])) {
                $this->pdo->exec("ALTER TABLE {$pc} ADD COLUMN payed_third_party BIGINT NOT NULL DEFAULT 0");
            }
            if (empty($have['poster_payment_method_id'])) {
                $this->pdo->exec("ALTER TABLE {$pc} ADD COLUMN poster_payment_method_id INT UNSIGNED NULL");
                $this->pdo->exec("ALTER TABLE {$pc} ADD INDEX idx_poster_pm_id (poster_payment_method_id)");
            }
            if (!empty($have['payment_method'])) {
                try { $this->pdo->exec("ALTER TABLE {$pc} DROP INDEX idx_poster_method"); } catch (\Throwable $e) {}
                $this->pdo->exec("ALTER TABLE {$pc} DROP COLUMN payment_method");
            }
            if (!empty($have['card_type'])) {
                $this->pdo->exec("ALTER TABLE {$pc} DROP COLUMN card_type");
            }
        } catch (\Throwable $e) {
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$ppm} (
            payment_method_id INT UNSIGNED NOT NULL PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            color VARCHAR(50) NULL,
            money_type TINYINT NOT NULL,
            payment_type TINYINT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ppm_type (money_type, payment_type),
            KEY idx_ppm_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM {$ppm}")->fetchAll(\PDO::FETCH_ASSOC);
            $have = [];
            foreach ($cols as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $have[$f] = true;
            }
            if (!empty($have['icon'])) {
                $this->pdo->exec("ALTER TABLE {$ppm} DROP COLUMN icon");
            }
            if (!empty($have['raw_json'])) {
                $this->pdo->exec("ALTER TABLE {$ppm} DROP COLUMN raw_json");
            }
        } catch (\Throwable $e) {
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$pt} (
            transaction_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            day_date DATE NOT NULL,
            date_close DATETIME NOT NULL,
            pay_type TINYINT NOT NULL,
            sum BIGINT NOT NULL,
            payed_card BIGINT NOT NULL,
            payed_third_party BIGINT NOT NULL DEFAULT 0,
            tip_sum BIGINT NOT NULL,
            spot_id INT NULL,
            table_id INT NULL,
            waiter_name VARCHAR(100) NULL,
            payment_method_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_pt_day (day_date),
            KEY idx_pt_close (date_close),
            KEY idx_pt_pm_id (payment_method_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM {$pt}")->fetchAll(\PDO::FETCH_ASSOC);
            $have = [];
            foreach ($cols as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $have[$f] = true;
            }
            if (!empty($have['raw_json'])) {
                $this->pdo->exec("ALTER TABLE {$pt} DROP COLUMN raw_json");
            }
        } catch (\Throwable $e) {
        }
        try {
            $this->pdo->exec("DROP TABLE IF EXISTS " . $this->t('poster_transaction_details'));
        } catch (\Throwable $e) {
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$pa} (
            account_id INT UNSIGNED NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type TINYINT NOT NULL,
            currency_id INT NULL,
            currency_symbol VARCHAR(16) NULL,
            currency_code_iso VARCHAR(16) NULL,
            currency_code VARCHAR(32) NULL,
            balance BIGINT NOT NULL,
            balance_start BIGINT NULL,
            percent_acquiring DECIMAL(8,2) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_pa_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$pl} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            poster_transaction_id BIGINT UNSIGNED NOT NULL,
            sepay_id BIGINT UNSIGNED NOT NULL,
            link_type ENUM('auto_green','auto_yellow','manual') NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_link_pair (poster_transaction_id, sepay_id),
            KEY idx_link_poster (poster_transaction_id),
            KEY idx_link_sepay (sepay_id),
            KEY idx_link_type (link_type),
            CONSTRAINT fk_check_links_poster_{$fkTag} FOREIGN KEY (poster_transaction_id) REFERENCES {$pc}(transaction_id) ON DELETE CASCADE,
            CONSTRAINT fk_check_links_sepay_{$fkTag} FOREIGN KEY (sepay_id) REFERENCES {$st}(sepay_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM {$pl}")->fetchAll(\PDO::FETCH_ASSOC);
            $have = [];
            foreach ($cols as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $have[$f] = true;
            }
            if (!empty($have['is_manual'])) {
                $this->pdo->exec("ALTER TABLE {$pl} DROP COLUMN is_manual");
            }
        } catch (\Throwable $e) {
        }

        try {
            $this->pdo->exec(
                "DELETE l1 FROM {$pl} l1
                 JOIN {$pl} l2
                   ON l2.poster_transaction_id = l1.poster_transaction_id
                  AND l2.sepay_id = l1.sepay_id
                  AND l2.id < l1.id"
            );

            $idx = $this->pdo->query("SHOW INDEX FROM {$pl}")->fetchAll(\PDO::FETCH_ASSOC);
            $hasIdxSepay = false;
            $hasIdxPoster = false;
            $hasIdxType = false;
            $hasUqPair = false;
            $indexCols = [];
            foreach ($idx as $i) {
                $name = (string)($i['Key_name'] ?? '');
                $nonUnique = (int)($i['Non_unique'] ?? 1);
                $col = (string)($i['Column_name'] ?? '');
                $seq = (int)($i['Seq_in_index'] ?? 0);
                if ($name !== '' && $seq > 0 && $col !== '') {
                    if (!isset($indexCols[$name])) $indexCols[$name] = ['non_unique' => $nonUnique, 'cols' => []];
                    $indexCols[$name]['non_unique'] = $nonUnique;
                    $indexCols[$name]['cols'][$seq] = $col;
                }
                if ($name === 'idx_link_sepay') {
                    $hasIdxSepay = true;
                }
                if ($name === 'idx_link_poster') {
                    $hasIdxPoster = true;
                }
                if ($name === 'idx_link_type') {
                    $hasIdxType = true;
                }
                if ($name === 'uq_link_pair' && $nonUnique === 0) {
                    $hasUqPair = true;
                }
            }

            if (!$hasIdxSepay) {
                $this->pdo->exec("ALTER TABLE {$pl} ADD INDEX idx_link_sepay (sepay_id)");
            }
            if (!$hasIdxPoster) {
                $this->pdo->exec("ALTER TABLE {$pl} ADD INDEX idx_link_poster (poster_transaction_id)");
            }
            if (!$hasIdxType) {
                $this->pdo->exec("ALTER TABLE {$pl} ADD INDEX idx_link_type (link_type)");
            }
            if (!$hasUqPair) {
                $this->pdo->exec("ALTER TABLE {$pl} ADD UNIQUE KEY uq_link_pair (poster_transaction_id, sepay_id)");
            }

            foreach ($indexCols as $name => $meta) {
                $nonUnique = (int)($meta['non_unique'] ?? 1);
                if ($nonUnique !== 0) continue;
                $cols = $meta['cols'] ?? [];
                ksort($cols);
                $cols = array_values($cols);
                if ($name === 'PRIMARY') continue;
                if ($name === 'uq_link_pair') continue;
                if ($cols === ['sepay_id'] || $cols === ['poster_transaction_id']) {
                    $this->pdo->exec("ALTER TABLE {$pl} DROP INDEX `{$name}`");
                }
            }
        } catch (\Throwable $e) {
        }
    }

    public function createReservationsTable() {
        $t = $this->t('reservations');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL,
            start_time DATETIME NOT NULL,
            guests INT NOT NULL,
            table_num VARCHAR(32) NOT NULL,
            name VARCHAR(128) NOT NULL,
            phone VARCHAR(64) NOT NULL,
            comment TEXT,
            preorder_text TEXT,
            preorder_ru TEXT,
            tg_user_id BIGINT NULL,
            tg_username VARCHAR(64) NULL,
            zalo_user_id VARCHAR(64) NULL,
            zalo_phone VARCHAR(64) NULL,
            lang VARCHAR(8) NULL,
            total_amount INT DEFAULT 0,
            qr_url VARCHAR(255) NULL,
            qr_code VARCHAR(64) NULL,
            deleted_at DATETIME NULL,
            deleted_by VARCHAR(255) NULL,
            KEY idx_start_time (start_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $cols = [];
            foreach ($this->pdo->query("SHOW COLUMNS FROM {$t}") as $c) {
                $f = strtolower((string)($c['Field'] ?? ''));
                if ($f !== '') $cols[$f] = true;
            }
            if (empty($cols['deleted_at'])) {
                $this->pdo->exec("ALTER TABLE {$t} ADD COLUMN deleted_at DATETIME NULL");
            }
            if (empty($cols['deleted_by'])) {
                $this->pdo->exec("ALTER TABLE {$t} ADD COLUMN deleted_by VARCHAR(255) NULL");
            }
            if (empty($cols['lang'])) {
                $this->pdo->exec("ALTER TABLE {$t} ADD COLUMN lang VARCHAR(8) NULL");
            }
        } catch (\Throwable $e) {
        }
    }
}

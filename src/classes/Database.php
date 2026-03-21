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
    }

    public function saveStats(array $stats): void {
        $ks = $this->t('kitchen_stats');
        $sql = "INSERT INTO {$ks} 
                (transaction_date, receipt_number, transaction_opened_at, transaction_closed_at, transaction_id, table_number, waiter_name, status, pay_type, close_reason, dish_id, item_seq, dish_category_id, dish_sub_category_id, dish_name, ticket_sent_at, ready_pressed_at, ready_chass_at, was_deleted, service_type, total_sum, station, exclude_from_dashboard, exclude_auto) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                status = VALUES(status),
                pay_type = VALUES(pay_type),
                close_reason = VALUES(close_reason),
                ticket_sent_at = VALUES(ticket_sent_at),
                ready_pressed_at = VALUES(ready_pressed_at),
                ready_chass_at = VALUES(ready_chass_at),
                was_deleted = VALUES(was_deleted),
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
        $mcm = $this->t('menu_categories_main');
        $mcmTr = $this->t('menu_categories_main_tr');
        $mcs = $this->t('menu_categories_sub');
        $mcsTr = $this->t('menu_categories_sub_tr');
        $miRu = $this->t('menu_items_ru');
        $miEn = $this->t('menu_items_en');
        $miVn = $this->t('menu_items_vn');
        $miKo = $this->t('menu_items_ko');
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

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mcm} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_main_category_id BIGINT NOT NULL,
            name_raw VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            show_in_menu TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_main_poster_id (poster_main_category_id),
            KEY idx_menu_categories_main_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mcmTr} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            main_category_id INT NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_main_tr (main_category_id, lang),
            CONSTRAINT fk_menu_categories_main_tr_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mcs} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_sub_category_id BIGINT NOT NULL,
            main_category_id INT NULL,
            main_category_id_override INT NULL,
            name_raw VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            show_in_menu TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_sub_poster_id (poster_sub_category_id),
            KEY idx_menu_categories_sub_main (main_category_id),
            KEY idx_menu_categories_sub_sort (sort_order),
            CONSTRAINT fk_menu_categories_sub_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$mcsTr} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sub_category_id INT NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_sub_tr (sub_category_id, lang),
            CONSTRAINT fk_menu_categories_sub_tr_sub_{$fkTag} FOREIGN KEY (sub_category_id) REFERENCES {$mcs}(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$miRu} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_item_id INT NOT NULL,
            title VARCHAR(255) NULL,
            main_category_id INT NULL,
            sub_category_id INT NULL,
            sub_category VARCHAR(255) NULL,
            description TEXT NULL,
            image_url VARCHAR(2048) NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_items_ru_poster_item_id (poster_item_id),
            KEY idx_menu_items_ru_published (is_published),
            KEY idx_menu_items_ru_main_cat (main_category_id),
            KEY idx_menu_items_ru_sub_cat (sub_category_id),
            KEY idx_menu_items_ru_sort (sort_order),
            CONSTRAINT fk_menu_items_ru_poster_{$fkTag} FOREIGN KEY (poster_item_id) REFERENCES {$pmi}(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_ru_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_ru_sub_{$fkTag} FOREIGN KEY (sub_category_id) REFERENCES {$mcs}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$miEn} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_item_id INT NOT NULL,
            title VARCHAR(255) NULL,
            main_category_id INT NULL,
            sub_category_id INT NULL,
            sub_category VARCHAR(255) NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_items_en_poster_item_id (poster_item_id),
            KEY idx_menu_items_en_main_cat (main_category_id),
            KEY idx_menu_items_en_sub_cat (sub_category_id),
            CONSTRAINT fk_menu_items_en_poster_{$fkTag} FOREIGN KEY (poster_item_id) REFERENCES {$pmi}(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_en_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_en_sub_{$fkTag} FOREIGN KEY (sub_category_id) REFERENCES {$mcs}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$miVn} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_item_id INT NOT NULL,
            title VARCHAR(255) NULL,
            main_category_id INT NULL,
            sub_category_id INT NULL,
            sub_category VARCHAR(255) NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_items_vn_poster_item_id (poster_item_id),
            KEY idx_menu_items_vn_main_cat (main_category_id),
            KEY idx_menu_items_vn_sub_cat (sub_category_id),
            CONSTRAINT fk_menu_items_vn_poster_{$fkTag} FOREIGN KEY (poster_item_id) REFERENCES {$pmi}(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_vn_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_vn_sub_{$fkTag} FOREIGN KEY (sub_category_id) REFERENCES {$mcs}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$miKo} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poster_item_id INT NOT NULL,
            title VARCHAR(255) NULL,
            main_category_id INT NULL,
            sub_category_id INT NULL,
            sub_category VARCHAR(255) NULL,
            description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_items_ko_poster_item_id (poster_item_id),
            KEY idx_menu_items_ko_main_cat (main_category_id),
            KEY idx_menu_items_ko_sub_cat (sub_category_id),
            CONSTRAINT fk_menu_items_ko_poster_{$fkTag} FOREIGN KEY (poster_item_id) REFERENCES {$pmi}(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_ko_main_{$fkTag} FOREIGN KEY (main_category_id) REFERENCES {$mcm}(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_ko_sub_{$fkTag} FOREIGN KEY (sub_category_id) REFERENCES {$mcs}(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
}

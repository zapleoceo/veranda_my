<?php

namespace App\Classes;

class Database {
    private \PDO $pdo;

    public function __construct(string $host, string $db, string $user, string $pass) {
        $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new \PDO($dsn, $user, $pass, $options);
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

    public function createTables() {
        $sql = "CREATE TABLE IF NOT EXISTS kitchen_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_date DATE NOT NULL,
            receipt_number VARCHAR(50),
            transaction_opened_at DATETIME,
            transaction_closed_at DATETIME,
            transaction_id INT NOT NULL,
            table_number VARCHAR(50),
            status INT DEFAULT 1,
            pay_type TINYINT NULL,
            close_reason TINYINT NULL,
            exclude_from_dashboard TINYINT(1) NOT NULL DEFAULT 0,
            exclude_auto TINYINT(1) NOT NULL DEFAULT 0,
            dish_id INT NOT NULL,
            item_seq INT NOT NULL DEFAULT 1,
            dish_category_id BIGINT NULL,
            dish_sub_category_id BIGINT NULL,
            dish_name VARCHAR(255),
            ticket_sent_at DATETIME,
            ready_pressed_at DATETIME,
            ready_chass_at DATETIME NULL,
            prob_close_at DATETIME NULL,
            service_type INT,
            total_sum DECIMAL(10,2),
            station VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_dish_tx (transaction_id, dish_id, item_seq)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $this->pdo->exec($sql);

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'item_seq'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN item_seq INT NOT NULL DEFAULT 1 AFTER dish_id");
        }

        try { $this->query("ALTER TABLE kitchen_stats DROP INDEX unique_dish_tx"); } catch (\Exception $e) {}
        try { $this->query("ALTER TABLE kitchen_stats ADD UNIQUE KEY unique_dish_tx (transaction_id, dish_id, item_seq)"); } catch (\Exception $e) {}

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'ready_chass_at'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
        }

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'prob_close_at'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
        }

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'exclude_auto'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN exclude_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_from_dashboard");
        }

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'dish_category_id'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN dish_category_id BIGINT NULL AFTER dish_id");
        }

        $row = $this->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kitchen_stats'
               AND COLUMN_NAME = 'dish_sub_category_id'"
        )->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $this->query("ALTER TABLE kitchen_stats ADD COLUMN dish_sub_category_id BIGINT NULL AFTER dish_category_id");
        }
    }

    public function saveStats(array $stats) {
        $sql = "INSERT INTO kitchen_stats 
                (transaction_date, receipt_number, transaction_opened_at, transaction_closed_at, transaction_id, table_number, status, pay_type, close_reason, dish_id, item_seq, dish_category_id, dish_sub_category_id, dish_name, ticket_sent_at, ready_pressed_at, service_type, total_sum, station, exclude_from_dashboard, exclude_auto) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                dish_id = VALUES(dish_id),
                item_seq = VALUES(item_seq),
                dish_category_id = VALUES(dish_category_id),
                dish_sub_category_id = VALUES(dish_sub_category_id),
                dish_name = VALUES(dish_name),
                transaction_opened_at = VALUES(transaction_opened_at),
                transaction_closed_at = VALUES(transaction_closed_at),
                table_number = VALUES(table_number),
                status = VALUES(status),
                pay_type = VALUES(pay_type),
                close_reason = VALUES(close_reason),
                ticket_sent_at = VALUES(ticket_sent_at),
                ready_pressed_at = VALUES(ready_pressed_at),
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
                $row['service_type'],
                $row['total_sum'],
                $row['station'],
                $isHookah ? 1 : 0,
                $isHookah ? 1 : 0
            ]);
        }
    }

    public function getLatestStats(int $limit = 50) {
        return $this->query("SELECT * FROM kitchen_stats ORDER BY ticket_sent_at DESC LIMIT ?", [$limit])->fetchAll();
    }

    public function createMenuTables() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS poster_menu_items (
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

        $columnExists = function (string $table, string $column): bool {
            $row = $this->query(
                "SELECT COUNT(*) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            )->fetch();
            return (int)($row['c'] ?? 0) > 0;
        };

        if (!$columnExists('poster_menu_items', 'station_id')) {
            $this->pdo->exec("ALTER TABLE poster_menu_items ADD COLUMN station_id BIGINT NULL AFTER price_raw");
        }
        if (!$columnExists('poster_menu_items', 'cost_raw')) {
            $this->pdo->exec("ALTER TABLE poster_menu_items ADD COLUMN cost_raw DECIMAL(10,2) NULL AFTER price_raw");
        }
        if (!$columnExists('poster_menu_items', 'station_name')) {
            $this->pdo->exec("ALTER TABLE poster_menu_items ADD COLUMN station_name VARCHAR(255) NULL AFTER station_id");
        }
        if (!$columnExists('poster_menu_items', 'raw_json')) {
            $this->pdo->exec("ALTER TABLE poster_menu_items ADD COLUMN raw_json JSON NULL AFTER sub_category_name");
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories_main (
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

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories_main_tr (
            id INT AUTO_INCREMENT PRIMARY KEY,
            main_category_id INT NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_main_tr (main_category_id, lang),
            CONSTRAINT fk_menu_categories_main_tr_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories_sub (
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
            CONSTRAINT fk_menu_categories_sub_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        if (!$columnExists('menu_categories_main', 'show_in_menu')) {
            $this->pdo->exec("ALTER TABLE menu_categories_main ADD COLUMN show_in_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");
        }
        if (!$columnExists('menu_categories_sub', 'main_category_id_override')) {
            $this->pdo->exec("ALTER TABLE menu_categories_sub ADD COLUMN main_category_id_override INT NULL AFTER main_category_id");
        }
        if (!$columnExists('menu_categories_sub', 'show_in_menu')) {
            $this->pdo->exec("ALTER TABLE menu_categories_sub ADD COLUMN show_in_menu TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_categories_sub_tr (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sub_category_id INT NOT NULL,
            lang VARCHAR(8) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_menu_categories_sub_tr (sub_category_id, lang),
            CONSTRAINT fk_menu_categories_sub_tr_sub FOREIGN KEY (sub_category_id) REFERENCES menu_categories_sub(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_items_ru (
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
            CONSTRAINT fk_menu_items_ru_poster FOREIGN KEY (poster_item_id) REFERENCES poster_menu_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_ru_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_ru_sub FOREIGN KEY (sub_category_id) REFERENCES menu_categories_sub(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_items_en (
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
            CONSTRAINT fk_menu_items_en_poster FOREIGN KEY (poster_item_id) REFERENCES poster_menu_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_en_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_en_sub FOREIGN KEY (sub_category_id) REFERENCES menu_categories_sub(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_items_vn (
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
            CONSTRAINT fk_menu_items_vn_poster FOREIGN KEY (poster_item_id) REFERENCES poster_menu_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_vn_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_vn_sub FOREIGN KEY (sub_category_id) REFERENCES menu_categories_sub(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS menu_items_ko (
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
            CONSTRAINT fk_menu_items_ko_poster FOREIGN KEY (poster_item_id) REFERENCES poster_menu_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_menu_items_ko_main FOREIGN KEY (main_category_id) REFERENCES menu_categories_main(id) ON DELETE SET NULL,
            CONSTRAINT fk_menu_items_ko_sub FOREIGN KEY (sub_category_id) REFERENCES menu_categories_sub(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
}

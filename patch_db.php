<?php
require_once __DIR__ . '/src/classes/Database.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

try {
    $db = new \App\Classes\Database(
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_NAME'] ?? 'veranda_my',
        $_ENV['DB_USER'] ?? 'veranda_my',
        $_ENV['DB_PASS'] ?? '',
        (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
    );

    $resTable = $db->t('reservations');
    $pdo = $db->getPdo();

    // Check if is_poster_pushed exists
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$resTable}` LIKE 'is_poster_pushed'");
    $existsPushed = $stmt->fetch();
    
    if (!$existsPushed) {
        $pdo->exec("ALTER TABLE `{$resTable}` ADD COLUMN `is_poster_pushed` TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added column 'is_poster_pushed'.\n";
    } else {
        echo "Column 'is_poster_pushed' already exists.\n";
    }

    // Check if poster_id exists
    $stmt2 = $pdo->query("SHOW COLUMNS FROM `{$resTable}` LIKE 'poster_id'");
    $existsId = $stmt2->fetch();
    
    if (!$existsId) {
        $pdo->exec("ALTER TABLE `{$resTable}` ADD COLUMN `poster_id` INT NULL DEFAULT NULL");
        echo "Added column 'poster_id'.\n";
    } else {
        echo "Column 'poster_id' already exists.\n";
    }

    echo "Done.";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage();
}

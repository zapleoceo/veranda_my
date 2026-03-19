<?php
require_once __DIR__ . '/auth_check.php';

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $stats = $db->query("SELECT * FROM kitchen_stats ORDER BY ticket_sent_at DESC LIMIT 50")->fetchAll();
} catch (\Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Kitchen Kit Analytics - Veranda</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .status-ready { color: green; font-weight: bold; }
        .status-cooking { color: orange; font-weight: bold; }
        .error { color: red; background: #fee; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Kitchen Kit Analytics - Veranda</h1>
        
        <?php if (isset($error)): ?>
            <div class="error">Ошибка БД: <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Чек #</th>
                        <th>Блюдо</th>
                        <th>Отправлено</th>
                        <th>Готово</th>
                        <th>Время ожидания</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $row): 
                        $wait = 'N/A';
                        if ($row['ticket_sent_at'] && $row['ready_pressed_at']) {
                            $diff = strtotime($row['ready_pressed_at']) - strtotime($row['ticket_sent_at']);
                            $wait = round($diff / 60, 1) . ' мин';
                        }
                    ?>
                        <tr>
                            <td><?= $row['transaction_date'] ?></td>
                            <td><?= htmlspecialchars($row['receipt_number']) ?></td>
                            <td><?= htmlspecialchars($row['dish_name']) ?></td>
                            <td><?= $row['ticket_sent_at'] ?></td>
                            <td><?= $row['ready_pressed_at'] ?? '<span class="status-cooking">Готовится...</span>' ?></td>
                            <td><?= $wait ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>

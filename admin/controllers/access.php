<?php
$usersTable = $db->t('users');
$metaTable = $db->t('system_meta');
$usersCols = [];
try {
    $cols = $db->query("SHOW COLUMNS FROM {$usersTable}")->fetchAll();
    foreach ($cols as $c) {
        $f = strtolower((string)($c['Field'] ?? ''));
        if ($f !== '') $usersCols[$f] = true;
    }
} catch (\Throwable $e) {
    $usersCols = [];
}

try {
    if (empty($usersCols['name'])) {
        $db->query("ALTER TABLE {$usersTable} ADD COLUMN name VARCHAR(255) NULL");
        $usersCols['name'] = true;
    }
} catch (\Throwable $e) {
}

$permissionKeys = [
    'dashboard' => 'Дашборд',
    'rawdata' => 'Сырые данные',
    'kitchen_online' => 'КухняOnline',
    'errors' => 'Cooked (errors)',
    'zapara' => 'Zapara',
    'employees' => 'ЗП сотрудников',
    'payday' => 'Payday',
    'admin' => 'УПРАВЛЕНИЕ',
    'roma' => 'Roma (кальяны)',
    'banya' => 'Отчет баня',
    'reservations' => 'Брони',
    'vposter_button' => 'Кнопка "Бронь в Постере"',
    'exclude_toggle' => 'Игнор + ✅ Принято (Telegram)',
    'telegram_ack' => '✅ Принято (Telegram)',
];

if (isset($_POST['save_user_permissions'])) {
    $targetEmail = trim((string)($_POST['perm_email'] ?? ''));
    if ($targetEmail !== '') {
        $perms = [];
        foreach ($permissionKeys as $k => $_label) {
            $perms[$k] = isset($_POST['perm_' . $k]) ? 1 : 0;
        }
        $perms['telegram_ack'] = !empty($perms['exclude_toggle']) ? 1 : 0;
        $tgUsername = strtolower(trim((string)($_POST['perm_tg_username'] ?? '')));
        $tgUsername = ltrim($tgUsername, '@');
        if ($tgUsername === '') {
            $tgUsername = null;
        }
        $setParts = [];
        $params = [];
        if (!empty($usersCols['permissions_json'])) {
            $setParts[] = "permissions_json = ?";
            $params[] = json_encode($perms, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($usersCols['telegram_username'])) {
            $setParts[] = "telegram_username = ?";
            $params[] = $tgUsername;
        }
        if (!empty($setParts)) {
            $params[] = $targetEmail;
            $db->query("UPDATE {$usersTable} SET " . implode(', ', $setParts) . " WHERE email = ? LIMIT 1", $params);
            $message = "Права для $targetEmail сохранены.";
        } else {
            $error = 'В таблице users нет колонок для сохранения прав (permissions_json/telegram_username).';
        }
    }
}

// Add user
if (isset($_POST['add_email'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!empty($usersCols['permissions_json'])) {
                $emptyPerms = [];
                foreach ($permissionKeys as $k => $_label) {
                    $emptyPerms[$k] = 0;
                }
                $emptyPerms['telegram_ack'] = 0;
                $db->query(
                    "INSERT INTO {$usersTable} (email, permissions_json) VALUES (?, ?)",
                    [$email, json_encode($emptyPerms, JSON_UNESCAPED_UNICODE)]
                );
            } else {
                $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            }
            $message = "Пользователь $email успешно добавлен.";
        } catch (\Exception $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

if (isset($_POST['add_self'])) {
    $email = trim((string)($_SESSION['user_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!empty($usersCols['is_active'])) {
                $db->query(
                    "INSERT INTO {$usersTable} (email, is_active) VALUES (?, 1)
                     ON DUPLICATE KEY UPDATE is_active = 1",
                    [$email]
                );
            } else {
                $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            }
            $message = "Пользователь $email успешно добавлен.";
        } catch (\Exception $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

// Delete user
if (isset($_GET['delete'])) {
    $emailToDelete = $_GET['delete'];
    // Prevent deleting own email
    if ($emailToDelete !== $_SESSION['user_email']) {
        $db->query("DELETE FROM {$usersTable} WHERE email = ?", [$emailToDelete]);
        $message = "Пользователь $emailToDelete удален.";
    } else {
        $error = "Вы не можете удалить свой собственный email.";
    }
}

$users = [];
try {
    $select = ['email'];
    if (!empty($usersCols['name'])) $select[] = 'name';
    if (!empty($usersCols['telegram_username'])) $select[] = 'telegram_username';
    if (!empty($usersCols['permissions_json'])) $select[] = 'permissions_json';
    if (!empty($usersCols['created_at'])) {
        $select[] = 'created_at';
    } else {
        $select[] = 'NULL AS created_at';
    }
    $orderBy = !empty($usersCols['created_at']) ? 'created_at DESC' : 'email ASC';
    $users = $db->query("SELECT " . implode(', ', $select) . " FROM {$usersTable} ORDER BY {$orderBy}")->fetchAll();
} catch (\Throwable $e) {
    $users = [];
    if ($error === '') {
        $error = 'Ошибка чтения списка пользователей: ' . $e->getMessage();
    }
}


<?php
/**
 * Только чтение: пропускает ли текущая модель прав (раздел «Доступ» → право
 * «Бот: финансы (Telegram)» + users.telegram_user_id) подтверждённого владельца.
 * Вызывается aibot_activate.sh ДО любых изменений; ничего не создаёт и не выдаёт.
 *
 * Коды выхода: 0 — право есть; 1 — права нет / связки нет / ошибка (fail closed).
 * Вывод — только фиксированные статусы.
 */
declare(strict_types=1);

const EXPECTED_TG_ID = 169510539; // подтверждённый Telegram id владельца — что ПРОВЕРЯЕМ, не обход

$app = getenv('AIBOT_APP_DIR') ?: '/var/www/veranda_my_usr/data/www/veranda.my';
try {
    chdir($app);
    require $app . '/vendor/autoload.php';
    \App\Infrastructure\Config::load($app . '/.env');
    $b = new \DI\ContainerBuilder();
    $b->addDefinitions(require $app . '/src/Bootstrap/container.php');
    $c = $b->build();
    $db = $c->get(\App\Infrastructure\Database::class);
    // Колонку не создаём (ensureSchema делал бы ALTER) — её отсутствие = нет связки.
    $t = $db->t('users');
    if (!$db->query("SHOW COLUMNS FROM {$t} LIKE 'telegram_user_id'")->fetch()) {
        echo "grant: MISSING (нет колонки telegram_user_id)\n";
        exit(1);
    }
    $auth = $c->get(\App\AiBot\FinanceAuthorizerInterface::class);
    if ($auth->allows(EXPECTED_TG_ID)) {
        echo "grant: OK\n";
        exit(0);
    }
    echo "grant: MISSING (у владельца нет права «Бот: финансы» или связки Telegram ID)\n";
    exit(1);
} catch (\Throwable) {
    echo "grant: ERROR (проверка не выполнена)\n";
    exit(1);
}

<?php
// Страница menu-beta удалена. Это лишь 301-редирект на актуальное меню.
// Держим редирект в самом файле, потому что на прод-стеке nginx отдаёт
// отсутствующие .php как 404, не доводя запрос до Slim (а .htaccess неактивен).
// Бесрасширенный /links/menu-beta ловится Slim-роутом в src/Bootstrap/routes.php.
header('Location: /links/menu', true, 301);
exit;

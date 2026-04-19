<?php
/**
 * Legacy POST action "create_transfer". The real flow is JSON in ajax.php (?ajax=create_transfer).
 * This file previously contained invalid PHP (unmatched braces). Kept so require from post.php
 * does not fatal; without JavaScript the transfer UI cannot run safely.
 */
throw new \Exception('Создание перевода работает только через интерфейс страницы (нужен JavaScript). Обновите страницу.');

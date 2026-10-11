<?php

declare(strict_types=1);

namespace App\AiBot;

/** Сеть/таймаут/TLS. Сообщение не содержит заголовков запроса (токена). */
final class OfficeTransportException extends \RuntimeException {}

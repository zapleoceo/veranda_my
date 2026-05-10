<?php
declare(strict_types=1);

$p = $settingsRepo->getBotPrompt();
$ok(['prompt' => (string)($p['prompt'] ?? ''), 'updated_at' => (string)($p['updated_at'] ?? '')]);
exit;


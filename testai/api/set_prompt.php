<?php
declare(strict_types=1);

$requireAdmin();

$prompt = (string)($_POST['prompt'] ?? '');
$prompt = trim($prompt);
if (mb_strlen($prompt) > 20000) $prompt = mb_substr($prompt, 0, 20000);
$settingsRepo->setBotPrompt($prompt, date('Y-m-d H:i:s'));
$ok(['saved' => true]);
exit;


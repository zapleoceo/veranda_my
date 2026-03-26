<?php
// mail.php - Temporary endpoint to read raw emails from Gmail

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim(trim($value), '"\'');
    }
}

$user = $_ENV['MAIL_USER'] ?? '';
$pass = $_ENV['MAIL_PASS'] ?? '';

function decode_imap_text($str) {
    if (!$str) return '';
    $result = '';
    $decode = @imap_mime_header_decode($str);
    if (is_array($decode)) {
        foreach ($decode as $obj) {
            $text = $obj->text;
            $charset = $obj->charset;
            if ($charset == 'default' || $charset == 'us-ascii' || $charset == 'utf-8') {
                $result .= $text;
            } else {
                $result .= @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
        }
    } else {
        $result = $str;
    }
    return $result;
}

function get_email_body($inbox, $email_number) {
    $struct = imap_fetchstructure($inbox, $email_number);
    $body = '';
    
    // If it's a multipart email
    if (isset($struct->parts) && count($struct->parts)) {
        // Try to find the text/plain part
        for ($i = 0, $n = count($struct->parts); $i < $n; $i++) {
            if ($struct->parts[$i]->subtype == 'PLAIN') {
                $body = imap_fetchbody($inbox, $email_number, $i + 1);
                $encoding = $struct->parts[$i]->encoding;
                break;
            }
        }
        // Fallback to the first part
        if (!$body) {
            $body = imap_fetchbody($inbox, $email_number, 1);
            $encoding = $struct->parts[0]->encoding;
        }
    } else {
        // Simple email
        $body = imap_body($inbox, $email_number);
        $encoding = $struct->encoding;
    }
    
    // Decode body
    if ($encoding == 3) {
        $body = base64_decode($body);
    } elseif ($encoding == 4) {
        $body = quoted_printable_decode($body);
    }
    
    return trim($body);
}

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Raw Mail Viewer</title>";
echo "<style>
    body { font-family: sans-serif; padding: 20px; background: #f5f5f5; color: #333; }
    .email { background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .email h3 { margin-top: 0; color: #0056b3; }
    .meta { color: #666; font-size: 0.9em; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
    pre { background: #f8f9fa; padding: 15px; overflow-x: auto; border: 1px solid #ddd; border-radius: 4px; white-space: pre-wrap; word-wrap: break-word; }
    .error { color: #dc3545; font-weight: bold; padding: 15px; background: #f8d7da; border-radius: 5px; border: 1px solid #f5c6cb; }
    .success { color: #28a745; font-weight: bold; padding: 10px; background: #d4edda; border-radius: 5px; border: 1px solid #c3e6cb; margin-bottom: 20px;}
</style>";
echo "</head><body>";
echo "<h2>Inbox: " . htmlspecialchars($user) . "</h2>";

if (!extension_loaded('imap')) {
    echo "<div class='error'>Ошибка: Расширение PHP IMAP не установлено или не включено на сервере.</div>";
    echo "<p>Для Ubuntu/Debian выполните: <code>sudo apt-get install php-imap && sudo phpenmod imap && sudo systemctl restart php-fpm</code> (или apache2).</p>";
    echo "</body></html>";
    exit;
}

if (!$user || !$pass) {
    echo "<div class='error'>Ошибка: MAIL_USER или MAIL_PASS не заданы в .env</div>";
    echo "</body></html>";
    exit;
}

$mailbox = '{imap.gmail.com:993/imap/ssl}INBOX';

// Suppress notices for imap connections
$inbox = @imap_open($mailbox, $user, $pass);

if (!$inbox) {
    echo "<div class='error'>Не удалось подключиться к Gmail:<br>" . htmlspecialchars(imap_last_error()) . "</div>";
    echo "</body></html>";
    exit;
}

echo "<div class='success'>Успешное подключение к ящику по IMAP!</div>";

// Fetch last 20 emails
$emails = imap_search($inbox, 'ALL');

if ($emails) {
    rsort($emails); // Newest first
    $emails = array_slice($emails, 0, 20); // Top 20
    
    echo "<p>Показаны последние 20 писем:</p>";
    
    foreach ($emails as $email_number) {
        $headerInfo = imap_headerinfo($inbox, $email_number);
        $subject = isset($headerInfo->subject) ? decode_imap_text($headerInfo->subject) : '(Без темы)';
        $from = isset($headerInfo->from[0]) ? $headerInfo->from[0]->mailbox . "@" . $headerInfo->from[0]->host : 'Неизвестно';
        if (isset($headerInfo->from[0]->personal)) {
            $from = decode_imap_text($headerInfo->from[0]->personal) . " <" . $from . ">";
        }
        
        $date = isset($headerInfo->date) ? date('Y-m-d H:i:s', strtotime($headerInfo->date)) : 'Неизвестно';
        
        echo "<div class='email'>";
        echo "<h3>" . htmlspecialchars($subject) . "</h3>";
        echo "<div class='meta'><strong>От:</strong> " . htmlspecialchars($from) . " | <strong>Дата:</strong> " . htmlspecialchars($date) . "</div>";
        
        $body = get_email_body($inbox, $email_number);
        
        echo "<h4>Сырое тело (Raw Body):</h4>";
        echo "<pre>" . htmlspecialchars($body) . "</pre>";
        echo "</div>";
    }
} else {
    echo "<p>В ящике нет писем.</p>";
}

imap_close($inbox);
echo "</body></html>";

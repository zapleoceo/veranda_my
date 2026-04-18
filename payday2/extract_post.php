<?php
$content = file_get_contents(__DIR__ . '/post.php');
preg_match_all("/if \(\\\$_SERVER\['REQUEST_METHOD'\] === 'POST' && \\\$action === '([^']+)'\) \{(.*?)(?=\n    if \(\\\$_SERVER\['REQUEST_METHOD'\] === 'POST' && \\\$action === |$)/s", $content, $matches);

foreach ($matches[1] as $index => $actionName) {
    $body = $matches[2][$index];
    if (substr(trim($body), -1) === '}') {
        $body = preg_replace('/\}\s*$/', '', $body);
    }
    file_put_contents(__DIR__ . '/post/' . $actionName . '.php', "<?php\n" . trim($body) . "\n");
}

<?php
$content = file_get_contents('/workspace/admin.php');
$start = strpos($content, '    $defaultCaps = [');
$end = strpos($content, '    $saved = $metaRepo->getMany([$resMetaKey');
if ($start !== false && $end !== false) {
    $content = substr_replace($content, "\n", $start, $end - $start);
    file_put_contents('/workspace/admin.php', $content);
}

<?php
$content = file_get_contents('payday2/functions.php');
$search = <<<HTML
\$st = \$db->t('sepay_transactions');
\$pc = \$db->t('poster_checks');
\$ppm = \$db->t('poster_payment_methods');
\$pt = \$db->t('poster_transactions');
\$pa = \$db->t('poster_accounts');
\$pl = \$db->t('check_payment_links');
\$sh = \$db->t('sepay_hidden');
\$mh = \$db->t('mail_hidden');
\$ol = \$db->t('out_links');
HTML;
$replace = <<<HTML
global \$db;
\$st = \$db->t('sepay_transactions');
\$pc = \$db->t('poster_checks');
\$ppm = \$db->t('poster_payment_methods');
\$pt = \$db->t('poster_transactions');
\$pa = \$db->t('poster_accounts');
\$pl = \$db->t('check_payment_links');
\$sh = \$db->t('sepay_hidden');
\$mh = \$db->t('mail_hidden');
\$ol = \$db->t('out_links');
HTML;
$content = str_replace($search, $replace, $content);
file_put_contents('payday2/functions.php', $content);

<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$old = <<<HTML
            if (method === 'GET') {
                const url = new URL(action, window.location.origin);
                for (const [k, v] of formData.entries()) {
                    url.searchParams.set(k, v);
                }
                window.doPjax(url.href);
            }
HTML;

$new = <<<HTML
            if (method === 'GET') {
                // Fix: if action is just "?..." or empty, use window.location.href to preserve the path!
                const baseUrl = action.startsWith('http') ? action : new URL(action, window.location.href).href;
                const url = new URL(baseUrl);
                for (const [k, v] of formData.entries()) {
                    url.searchParams.set(k, v);
                }
                window.doPjax(url.href);
            }
HTML;

$js = str_replace($old, $new, $js);
file_put_contents('payday2/assets/js/payday2.js', $js);

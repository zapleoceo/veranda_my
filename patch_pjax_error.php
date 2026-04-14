<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$old = <<<HTML
            if (configScript) {
                eval(configScript.textContent);
            }

            if (options.method !== 'POST' || res.redirected) {
                window.history.pushState({}, '', url);
            }

            if (typeof window.initPayday2 === 'function') {
                window.initPayday2();
            }
HTML;

$new = <<<HTML
            if (configScript) {
                try {
                    eval(configScript.textContent);
                } catch(err) {
                    console.error('Eval config error:', err);
                }
            }

            if (options.method !== 'POST' || res.redirected) {
                try {
                    window.history.pushState({}, '', url);
                } catch(err) {}
            }

            if (typeof window.initPayday2 === 'function') {
                try {
                    window.initPayday2();
                } catch(err) {
                    console.error('initPayday2 error:', err);
                }
            }
HTML;

$js = str_replace($old, $new, $js);
file_put_contents('payday2/assets/js/payday2.js', $js);

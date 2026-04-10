<?php
if (file_exists('js_debug.log')) {
    echo file_get_contents('js_debug.log');
} else {
    echo "No log found.";
}

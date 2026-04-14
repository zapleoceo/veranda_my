<?php
$html = file_get_contents('payday2/view.php');

// Extract the modals
preg_match('/(<div class="confirm-backdrop" id="kashshiftModal".*?<div id="lineLayer"><\/div>)/s', $html, $matches);
if (!empty($matches[1])) {
    $modals = $matches[1];
    // Remove from original location
    $html = str_replace($modals, '<div id="lineLayer"></div>', $html);
    
    // Insert at the end of container
    $html = str_replace('</div> <!-- .container -->', $modals . "\n</div> <!-- .container -->", $html);
    // Wait, does it have '</div> <!-- .container -->' ?
    // Let's check the end of view.php
}

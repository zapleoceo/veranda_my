<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$js = str_replace(
    "window.addEventListener('resize', () => outScheduleRelayout(), { passive: true });",
    "if (!window._outRes) { window.addEventListener('resize', () => outScheduleRelayout(), { passive: true }); window._outRes = true; }",
    $js
);
$js = str_replace(
    "window.addEventListener('resize', () => scheduleRelayoutBurst(), { passive: true });",
    "if (!window._burstRes) { window.addEventListener('resize', () => scheduleRelayoutBurst(), { passive: true }); window._burstRes = true; }",
    $js
);
$js = str_replace(
    "window.addEventListener('pageshow', () => scheduleRelayoutBurst(), { passive: true });",
    "if (!window._burstPage) { window.addEventListener('pageshow', () => scheduleRelayoutBurst(), { passive: true }); window._burstPage = true; }",
    $js
);
$js = str_replace(
    "window.addEventListener('load', () => {",
    "if (!window._loadDone) { window._loadDone = true; window.addEventListener('load', () => {",
    $js
);

file_put_contents('payday2/assets/js/payday2.js', $js);

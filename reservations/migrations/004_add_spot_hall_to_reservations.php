<?php
declare(strict_types=1);

return function (\App\Classes\Database $db): void {
    $t = $db->t('reservations');
    try { $db->getPdo()->exec("ALTER TABLE {$t} ADD COLUMN spot_id INT NULL AFTER id"); } catch (\Throwable $e) {}
    try { $db->getPdo()->exec("ALTER TABLE {$t} ADD COLUMN hall_id INT NULL AFTER spot_id"); } catch (\Throwable $e) {}
    try { $db->getPdo()->exec("ALTER TABLE {$t} ADD INDEX idx_spot_hall (spot_id, hall_id)"); } catch (\Throwable $e) {}
};


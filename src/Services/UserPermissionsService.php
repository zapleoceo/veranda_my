<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;

class UserPermissionsService
{
    private const DEFAULTS = [
        'dashboard'      => true,
        'rawdata'        => true,
        'kitchen_online' => true,
        'errors'         => false,
        'zapara'         => false,
        'admin'          => false,
        'roma'           => false,
        'banya'          => false,
        'employees'      => false,
        'schedule'       => false,
        'reservations'   => false,
        'vposter_button' => false,
        'exclude_toggle' => true,
        'telegram_ack'   => false,
        'payday'         => false,
    ];

    private const TTL = 30;

    public function __construct(private readonly Database $db) {}

    public function loadIntoSession(string $email): void
    {
        $now       = time();
        $loadedAt  = (int) ($_SESSION['user_permissions_loaded_at'] ?? 0);

        if ($loadedAt > 0 && ($now - $loadedAt) < self::TTL) {
            return;
        }

        $_SESSION['user_permissions']           = $this->resolve($email);
        $_SESSION['user_permissions_loaded_at'] = $now;
    }

    public function resolve(string $email): array
    {
        $out = self::DEFAULTS;

        if ($email !== '') {
            try {
                $users = $this->db->t('users');
                $row   = $this->db->query(
                    "SELECT permissions_json FROM {$users} WHERE email = ? LIMIT 1",
                    [$email]
                )->fetch();
                $raw = (string) ($row['permissions_json'] ?? '');
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach (self::DEFAULTS as $k => $_) {
                            if (array_key_exists($k, $decoded)) {
                                $out[$k] = (bool) $decoded[$k];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (!empty($out['exclude_toggle'])) {
            $out['telegram_ack'] = true;
        }

        if (!empty($out['admin'])) {
            $out['roma']         = true;
            $out['banya']        = true;
            $out['employees']    = true;
            $out['schedule']     = true;
            $out['errors']       = true;
            $out['zapara']       = true;
            $out['reservations'] = true;
            $out['dashboard']    = true;
            $out['rawdata']      = true;
            $out['kitchen_online'] = true;
        }

        return $out;
    }
}

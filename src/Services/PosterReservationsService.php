<?php

declare(strict_types=1);

namespace App\Services;

class PosterReservationsService
{
    private ?\App\Classes\Database $_db            = null;
    private ?\App\Classes\MetaRepository $_metaRepo = null;
    private ?\Reservations\Controllers\TablesController $_tablesCtrl = null;
    private ?\Reservations\Repositories\HallSettingsRepository $_hallRepo = null;

    public function isAvailable(): bool
    {
        return trim((string)($_ENV['POSTER_API_TOKEN'] ?? '')) !== '';
    }

    public function getHallsList(int $spotId): array
    {
        $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
        if ($token === '') return [];
        $map  = \App\Classes\PosterSpotHallsService::getHallsMap($this->_db(), $token, $spotId);
        ksort($map);
        $out = [];
        foreach ($map as $id => $name) {
            $out[] = ['hall_id' => (int)$id, 'hall_name' => (string)$name];
        }
        return $out;
    }

    public function getHallTables(int $spotId, int $hallId): array
    {
        $ctrl = $this->_tablesCtrl();
        if (!$ctrl) return [];
        $list = $ctrl->hallData($spotId, $hallId);
        $out  = [];
        foreach ($list as $t) {
            if (!is_array($t)) continue;
            $pid = (int)($t['table_id'] ?? 0);
            if ($pid <= 0) continue;
            $label = $this->_tableLabel($t);
            $out[] = [
                'poster_table_id' => $pid,
                'label'           => $label,
                'scheme_num'      => trim((string)($t['scheme_num'] ?? '')),
                'display_name'    => trim((string)($t['display_name'] ?? '')),
                'table_title'     => trim((string)($t['table_title'] ?? '')),
                'table_num'       => trim((string)($t['table_num'] ?? '')),
                'bookable'        => (int)($t['bookable'] ?? 0),
            ];
        }
        return $out;
    }

    public function resolveTableLabel(int $spotId, int $hallId, int $posterTableId): ?array
    {
        $ctrl = $this->_tablesCtrl();
        if (!$ctrl) return null;
        foreach ($ctrl->hallData($spotId, $hallId) as $t) {
            if (!is_array($t)) continue;
            if ((int)($t['table_id'] ?? 0) === $posterTableId) {
                return ['label' => $this->_tableLabel($t), 'row' => $t];
            }
        }
        return null;
    }

    public function pushToPoster(int $id, array $row, string $actor): array
    {
        $token  = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
        $spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
        if ($token === '') return ['ok' => false, 'error' => 'Poster API not configured'];

        require_once dirname(__DIR__, 2) . '/src/classes/PosterReservationHelper.php';
        $res = \App\Classes\PosterReservationHelper::pushToPoster($this->_db(), $token, $id, $spotId, $actor);

        if (!empty($res['ok'])) {
            $this->_editTgAfterPush($id, $row, !empty($res['duplicate']));
        }
        return $res;
    }

    public function updateTableSettings(int $spotId, int $hallId, int $posterTableId, array $payload): void
    {
        $ctrl = $this->_tablesCtrl();
        if (!$ctrl) return;
        $ctrl->updateTable($spotId, $hallId, $posterTableId, $payload);
        if (function_exists('reservations_sync_legacy_meta')) {
            reservations_sync_legacy_meta($this->_db(), $spotId, $hallId);
        }
    }

    public function updateHallRotate(int $spotId, int $hallId, int $rotate): void
    {
        $this->_hallRepo()->upsertRotate180($spotId, $hallId, $rotate);
    }

    public function getHallRotate(int $spotId, int $hallId): int
    {
        return $this->_hallRepo()->getRotate180($spotId, $hallId);
    }

    public function getHallData(int $spotId, int $hallId, array $settings): array
    {
        $tables = [];
        $ctrl   = $this->_tablesCtrl();
        if ($ctrl) {
            foreach ($ctrl->hallData($spotId, $hallId) as $t) {
                $scheme = trim((string)($t['scheme_num'] ?? ''));
                $tables[] = [
                    'poster_table_id' => (int)($t['table_id'] ?? 0),
                    'table_id'        => (int)($t['table_id'] ?? 0),
                    'table_num'       => (string)($t['table_num'] ?? ''),
                    'table_title'     => (string)($t['table_title'] ?? ''),
                    'scheme_num'      => $scheme,
                    'display_name'    => (string)($t['display_name'] ?? ''),
                    'shape'           => (string)($t['table_shape'] ?? ''),
                    'x'               => (float)($t['table_x'] ?? 0),
                    'y'               => (float)($t['table_y'] ?? 0),
                    'w'               => (float)($t['table_width'] ?? 0),
                    'h'               => (float)($t['table_height'] ?? 0),
                    'show_on_canvas'  => (int)($t['show_on_canvas'] ?? 1),
                    'bookable'        => (int)($t['bookable'] ?? 0),
                    'is_allowed'      => (int)($t['bookable'] ?? 0),
                    'cap'             => (int)($t['capacity'] ?? 0),
                ];
            }
        }
        return [
            'ok'                    => true,
            'spot_id'               => $spotId,
            'hall_id'               => $hallId,
            'rotate_180'            => $this->getHallRotate($spotId, $hallId),
            'soon_hours'            => $settings['soon_hours'],
            'min_preorder_per_guest' => $settings['min_preorder_per_guest'],
            'tables'                => $tables,
        ];
    }

    public function getPosterRows(int $spotId, string $dateFrom, string $dateTo, bool $showDeleted): array
    {
        $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
        if ($token === '') return [];

        $tz = $this->_spotTz();
        try {
            $api = new \App\Classes\PosterAPI($token);

            $tableMap = [];
            try {
                $allTables = $api->request('spots.getTableHallTables', ['spot_id' => $spotId, 'without_deleted' => 1]);
                if (is_array($allTables)) {
                    foreach ($allTables as $t) {
                        if (!isset($t['table_id'])) continue;
                        $tid   = (int)$t['table_id'];
                        $title = trim((string)($t['table_title'] ?? ''));
                        $num   = trim((string)($t['table_num'] ?? ''));
                        $s     = preg_match('/^\d+$/', $title) ? $title : (preg_match('/^\d+$/', $num) ? $num : '');
                        if ($s !== '') $tableMap[$tid] = $s;
                    }
                }
            } catch (\Throwable) {}

            $resp   = $api->request('incomingOrders.getReservations', ['timezone' => 'client'], 'GET');
            $fromDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 00:00:00', $tz);
            $toDt   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTo . ' 23:59:59', $tz);
            $rows   = [];
            if (is_array($resp)) {
                foreach ($resp as $pr) {
                    $status = (int)($pr['status'] ?? 0);
                    if (!$showDeleted && $status === 7) continue;
                    if ((int)($pr['spot_id'] ?? 0) !== $spotId) continue;
                    $drDt = $this->_parseSpotDt(trim((string)($pr['date_reservation'] ?? '')));
                    if (!$drDt) continue;
                    if ($fromDt instanceof \DateTimeImmutable && $drDt < $fromDt) continue;
                    if ($toDt instanceof \DateTimeImmutable && $drDt > $toDt) continue;

                    $tId    = (int)($pr['table_id'] ?? 0);
                    $tLabel = $tableMap[$tId] ?? ($tId > 0 ? (string)$tId : '?');
                    $comment = (string)($pr['comment'] ?? '');
                    $marker  = '';
                    if ($comment !== '' && preg_match('/\[VERANDA:([A-Z0-9]{6,16})\]/', $comment, $mm)) {
                        $marker = strtoupper((string)($mm[1] ?? ''));
                    }
                    $rows[] = [
                        'incoming_order_id' => (int)($pr['incoming_order_id'] ?? 0),
                        'created_at'        => (string)($pr['created_at'] ?? ''),
                        'start_time'        => $drDt->format('Y-m-d H:i:s'),
                        'table_id'          => $tId,
                        'table_num'         => $tLabel,
                        'guests'            => (int)($pr['guests_count'] ?? 0),
                        'name'              => trim(((string)($pr['first_name'] ?? '')) . ' ' . ((string)($pr['last_name'] ?? ''))),
                        'phone'             => (string)($pr['phone'] ?? ''),
                        'comment'           => $comment,
                        'marker_code'       => $marker,
                        'status'            => $status,
                    ];
                }
            }
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    public function mergeRows(array $ourRows, array $posterRows, bool $showPoster, string $sort, string $order): array
    {
        $byId     = [];
        $byMarker = [];
        $byDayTbl = [];
        foreach ($posterRows as $pr) {
            $pid = (int)($pr['incoming_order_id'] ?? 0);
            if ($pid > 0) $byId[$pid] = $pr;
            $mc = (string)($pr['marker_code'] ?? '');
            if ($mc !== '') $byMarker[$mc] = $pr;
            $day = substr((string)($pr['start_time'] ?? ''), 0, 10);
            $tbl = (string)($pr['table_num'] ?? '');
            if ($day !== '' && $tbl !== '') $byDayTbl[$day . '|' . $tbl][] = $pr;
        }

        $used    = [];
        $merged  = [];
        foreach ($ourRows as $r) {
            $ourStart  = (string)($r['start_time'] ?? '');
            $ourMarker = strtoupper(trim((string)($r['qr_code'] ?? '')));
            $posterId  = (int)($r['poster_id'] ?? 0);
            $poster    = null;

            if ($posterId > 0 && isset($byId[$posterId]) && empty($used[$posterId])) {
                $poster = $byId[$posterId];
                $used[$posterId] = true;
            }
            if (!$poster && $ourMarker !== '' && isset($byMarker[$ourMarker])) {
                $cand = $byMarker[$ourMarker];
                $cid  = (int)($cand['incoming_order_id'] ?? 0);
                if ($cid > 0 && empty($used[$cid])) { $poster = $cand; $used[$cid] = true; }
            }
            if (!$poster && $ourStart !== '') {
                $ourDay = substr($ourStart, 0, 10);
                $ourTbl = trim((string)($r['table_num'] ?? ''));
                $k      = $ourDay . '|' . $ourTbl;
                $ourTs  = strtotime($ourStart);
                foreach ($byDayTbl[$k] ?? [] as $cand) {
                    $cid = (int)($cand['incoming_order_id'] ?? 0);
                    if ($cid <= 0 || !empty($used[$cid])) continue;
                    $diff = abs(((int)strtotime((string)($cand['start_time'] ?? ''))) - (int)$ourTs);
                    if ($diff <= 1800) { $poster = $cand; $used[$cid] = true; break; }
                }
            }
            $merged[] = ['our' => $r, 'poster' => $poster];
        }

        if ($showPoster) {
            foreach ($posterRows as $pr) {
                $pid = (int)($pr['incoming_order_id'] ?? 0);
                if ($pid > 0 && !empty($used[$pid])) continue;
                $merged[] = ['our' => null, 'poster' => $pr];
            }
        }

        usort($merged, function ($a, $b) use ($sort, $order) {
            $va = $this->_sortVal($a, $sort);
            $vb = $this->_sortVal($b, $sort);
            return $order === 'asc' ? ($va <=> $vb) : ($vb <=> $va);
        });
        return $merged;
    }

    private function _sortVal(array $row, string $sort): mixed
    {
        $our    = $row['our'] ?? null;
        $poster = $row['poster'] ?? null;
        if (is_array($our) && array_key_exists($sort, $our)) return $our[$sort];
        return match ($sort) {
            'start_time', 'created_at' => is_array($poster) ? ($poster[$sort] ?? '') : '',
            'table_num', 'name', 'phone' => is_array($poster) ? ($poster[$sort] ?? '') : '',
            'guests'    => is_array($poster) ? (int)($poster['guests'] ?? 0) : 0,
            'id'        => is_array($poster) ? (int)($poster['incoming_order_id'] ?? 0) : 0,
            default     => '',
        };
    }

    private function _editTgAfterPush(int $id, array $row, bool $duplicate): void
    {
        $token  = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? ''));
        $chatId = trim((string)($_ENV['TELEGRAM_GROUP_ID'] ?? $_ENV['TELEGRAM_CHAT_ID'] ?? ''));
        if ($token === '' || $chatId === '') return;

        $tgMsgId = null;
        $msgRow  = $this->_db()->query(
            "SELECT tg_message_id FROM " . $this->_db()->t('reservations') . " WHERE id = ? LIMIT 1", [$id]
        )->fetch();
        if (is_array($msgRow) && !empty($msgRow['tg_message_id'])) {
            $tgMsgId = (int)$msgRow['tg_message_id'];
        }
        if (!$tgMsgId) return;

        $startDt = $this->_parseSpotDt((string)($row['start_time'] ?? ''));
        $text  = '<b>Бронь с сайта #' . htmlspecialchars((string)$id) . '</b>' . "\n";
        if ($startDt) {
            $text .= 'Дата: <b>' . $startDt->format('Y-m-d') . '</b>' . "\n";
            $text .= 'Время: <b>' . $startDt->format('H:i') . '</b>' . "\n";
        }
        $text .= 'Кол-во человек: <b>' . htmlspecialchars((string)$row['guests']) . '</b>' . "\n";
        $text .= 'Номер стола: <b>' . htmlspecialchars((string)$row['table_num']) . '</b>' . "\n";
        $text .= 'Имя: <b>' . htmlspecialchars((string)$row['name']) . '</b>' . "\n";
        $text .= 'Номер телефона: <b>' . htmlspecialchars((string)$row['phone']) . '</b>';
        if (!empty($row['comment'])) {
            $text .= "\n<b>Комментарий:</b>\n" . htmlspecialchars((string)$row['comment']);
        }
        $text .= $duplicate ? "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)" : "\n\n🚀 <b>Отправлено в Poster</b> (через сайт)";

        $bot = new \App\Classes\TelegramBot($token, $chatId);
        $bot->editMessageText($tgMsgId, $text, []);
    }

    private function _tableLabel(array $t): string
    {
        $d = trim((string)($t['display_name'] ?? ''));
        $s = trim((string)($t['scheme_num'] ?? ''));
        $i = trim((string)($t['table_title'] ?? ''));
        $n = trim((string)($t['table_num'] ?? ''));
        $p = (int)($t['table_id'] ?? 0);
        return $d !== '' ? $d : ($s !== '' ? $s : ($i !== '' ? $i : ($n !== '' ? $n : '#' . $p)));
    }

    private function _spotTz(): \DateTimeZone
    {
        $name = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
        return ($name !== '' && in_array($name, timezone_identifiers_list(), true))
            ? new \DateTimeZone($name)
            : new \DateTimeZone('Asia/Ho_Chi_Minh');
    }

    private function _parseSpotDt(string $s): ?\DateTimeImmutable
    {
        $v  = trim($s);
        $tz = $this->_spotTz();
        if ($v === '') return null;
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $tz);
        if ($dt instanceof \DateTimeImmutable) return $dt;
        try { return new \DateTimeImmutable($v, $tz); } catch (\Throwable) { return null; }
    }

    private function _db(): \App\Classes\Database
    {
        if (!$this->_db) {
            $this->_db = new \App\Classes\Database(
                (string)($_ENV['DB_HOST'] ?? 'localhost'),
                (string)($_ENV['DB_NAME'] ?? ''),
                (string)($_ENV['DB_USER'] ?? ''),
                (string)($_ENV['DB_PASS'] ?? ''),
                (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
            );
        }
        return $this->_db;
    }

    private function _metaRepo(): \App\Classes\MetaRepository
    {
        if (!$this->_metaRepo) {
            $this->_metaRepo = new \App\Classes\MetaRepository($this->_db());
        }
        return $this->_metaRepo;
    }

    private function _tablesCtrl(): ?\Reservations\Controllers\TablesController
    {
        if (!$this->_tablesCtrl) {
            $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
            if ($token === '') return null;
            $posterApi     = new \App\Classes\PosterAPI($token);
            $tableRepo     = new \Reservations\Repositories\TableSettingsRepository($this->_db());
            $posterTabSvc  = new \Reservations\Services\PosterTablesService($posterApi);
            $this->_tablesCtrl = new \Reservations\Controllers\TablesController(
                $this->_metaRepo(), $tableRepo, $posterTabSvc
            );
        }
        return $this->_tablesCtrl;
    }

    private function _hallRepo(): \Reservations\Repositories\HallSettingsRepository
    {
        if (!$this->_hallRepo) {
            $this->_hallRepo = new \Reservations\Repositories\HallSettingsRepository($this->_db());
        }
        return $this->_hallRepo;
    }
}

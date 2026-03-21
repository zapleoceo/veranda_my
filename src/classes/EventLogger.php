<?php

namespace App\Classes;

class EventLogger {
    private Database $db;
    private string $type;
    private string $requestId;
    private ?string $userEmail;

    public function __construct(Database $db, string $type, ?string $requestId = null, ?string $userEmail = null) {
        $this->db = $db;
        $this->type = trim($type) !== '' ? trim($type) : 'app';
        $this->requestId = $requestId !== null && trim($requestId) !== '' ? trim($requestId) : self::newRequestId();
        $this->userEmail = $userEmail !== null && trim($userEmail) !== '' ? trim($userEmail) : null;
    }

    public static function newRequestId(): string {
        try {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $hex = bin2hex($b);
            return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
        } catch (\Exception $e) {
            return date('YmdHis') . '-' . bin2hex((string)mt_rand());
        }
    }

    public function info(string $event, array $context = [], ?int $txId = null, ?int $dishId = null, ?int $itemSeq = null): void {
        $this->log('info', $event, $context, $txId, $dishId, $itemSeq);
    }

    public function warn(string $event, array $context = [], ?int $txId = null, ?int $dishId = null, ?int $itemSeq = null): void {
        $this->log('warn', $event, $context, $txId, $dishId, $itemSeq);
    }

    public function error(string $event, array $context = [], ?int $txId = null, ?int $dishId = null, ?int $itemSeq = null): void {
        $this->log('error', $event, $context, $txId, $dishId, $itemSeq);
    }

    private function log(string $level, string $event, array $context = [], ?int $txId = null, ?int $dishId = null, ?int $itemSeq = null): void {
        $level = strtolower(trim($level));
        if (!in_array($level, ['info', 'warn', 'error'], true)) $level = 'info';

        $event = trim($event);
        if ($event === '') $event = 'event';

        $ctx = null;
        if (!empty($context)) {
            try {
                $ctx = json_encode($context, JSON_UNESCAPED_UNICODE);
            } catch (\Exception $e) {
                $ctx = null;
            }
        }

        $table = $this->db->t('event_log');
        try {
            $this->db->query(
                "INSERT INTO {$table} (ts, level, type, event, context_json, request_id, user_email, tx_id, dish_id, item_seq)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    date('Y-m-d H:i:s'),
                    $level,
                    $this->type,
                    $event,
                    $ctx,
                    $this->requestId,
                    $this->userEmail,
                    $txId,
                    $dishId,
                    $itemSeq
                ]
            );
        } catch (\Exception $e) {
        }
    }
}


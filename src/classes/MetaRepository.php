<?php

namespace App\Classes;

class MetaRepository {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function getMany(array $keys): array {
        $keys = array_values(array_unique(array_filter(array_map(function ($v) {
            $s = trim((string)$v);
            return $s !== '' ? $s : null;
        }, $keys))));

        if (count($keys) === 0) return [];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = [];
        try {
            $meta = $this->db->t('system_meta');
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$meta} WHERE meta_key IN ({$placeholders})",
                $keys
            )->fetchAll();
        } catch (\Exception $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $k = (string)($r['meta_key'] ?? '');
            if ($k === '') continue;
            $out[$k] = (string)($r['meta_value'] ?? '');
        }
        return $out;
    }
}

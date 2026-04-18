<?php
try {
            $db->query('START TRANSACTION');
            $db->query("UPDATE {$pc} SET was_deleted = 1, deleted_at = NOW() WHERE day_date BETWEEN ? AND ?", [$dateFrom, $dateTo]);
            $db->query("UPDATE {$st} SET was_deleted = 1, deleted_at = NOW() WHERE transaction_date BETWEEN ? AND ?", [$periodFrom, $periodTo]);
            $db->query('COMMIT');
            $message = ($dateFrom === $dateTo ? ('День очищен: ' . $dateFrom) : ('Период очищен: ' . $dateFrom . ' — ' . $dateTo));
        } catch (\Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (\Throwable $e2) {}
            throw $e;
        }

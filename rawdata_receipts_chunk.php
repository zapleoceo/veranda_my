<?php foreach ($groupedStats as $receiptNum => $data): ?>
    <?php $receiptClass = ((int)($data['status'] ?? 1) > 1) ? 'receipt-item receipt-closed' : 'receipt-item'; ?>
    <details class="<?= $receiptClass ?>"
             data-receipt="<?= htmlspecialchars($receiptNum) ?>"
             data-opened="<?= $data['opened_timestamp'] ?>"
             data-closed="<?= (int)($data['max_wait_log_close_timestamp'] ?? 0) ?>"
             data-wait="<?= $data['max_wait_time'] ?>">
        <summary>
            <div class="receipt-info">
                <span class="receipt-number">Чек <?= htmlspecialchars($receiptNum) ?></span>
                <div class="receipt-times">
                    <span>ВрОткр: <?= ($data['opened_at'] && $data['opened_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($data['opened_at'])) > 1970) ? date('H:i:s', strtotime($data['opened_at'])) : '—' ?></span>
                    <span>ВрЛогЗакр: <?php
                        if (!empty($data['has_hookah']) && (float)($data['max_wait_time'] ?? 0) <= 0) {
                            echo 'кал';
                        } elseif (!empty($data['max_wait_log_close_at'])) {
                            echo date('H:i:s', strtotime($data['max_wait_log_close_at']));
                        } else {
                            echo '—';
                        }
                    ?></span>
                </div>
                <?php if ($data['max_wait_time'] > 0):
                    $waitClass = 'wait-low';
                    if (!empty($data['max_wait_fallback'])) {
                        $waitClass = 'wait-fallback';
                    } elseif ($data['max_wait_time'] >= 40) {
                        $waitClass = 'wait-high';
                    } elseif ($data['max_wait_time'] >= 20) {
                        $waitClass = 'wait-medium';
                    }
                    $waitIcon = !empty($data['max_wait_prob']) ? '❓' : (!empty($data['max_wait_fallback']) ? '📌' : '⌛');
                ?>
                    <span class="receipt-max-wait <?= $waitClass ?>" title="<?= !empty($data['max_wait_prob']) ? 'Макс. ожидание рассчитано от отправки на станцию до расчетного времени (ProbCloseTime: берется из следующего чека(ов) по цеху).' : (!empty($data['max_wait_fallback']) ? 'Макс. ожидание рассчитано от отправки на станцию до времени закрытия чека (fallback).' : 'Макс. ожидание рассчитано от отправки на станцию до отметки Готово.') ?>"><?= $waitIcon ?> <?= $data['max_wait_time'] ?> мин</span>
                <?php elseif (!empty($data['has_hookah'])): ?>
                    <span class="receipt-max-wait wait-fallback" title="Кальяны: тайминг не считается.">кал</span>
                <?php endif; ?>
            </div>
        </summary>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th title="Название позиции (из Poster).">Блюдо</th>
                        <th title="Время открытия чека (Poster date_start).">ВрОткр</th>
                        <th title="Время отправки позиции на станцию/цех (Poster TransactionHistory sendtokitchen).">ВрОтпр</th>
                        <th title="Время готовности из Poster (finishedcooking).">ВрГотPSTR</th>
                        <th title="Время закрытия чека в Poster (date_close/date_close_date), только для закрытых чеков.">ЗакЧкPoster</th>
                        <th title="Время готовности из Chef Assistant (readyTime).">ЗакChAss</th>
                        <th title="Расчетное время (ProbCloseTime): берется из ближайшего следующего чека (+1..+3) с тем же цехом, где есть готовые позиции.">ЗакРассч</th>
                        <th title="Время, которое реально используется в расчете ожидания. Приоритет: ВрГотPSTR → ЗакChAss → ЗакРассч → ЗакЧкPoster.">ВрЛогЗакр</th>
                        <th title="ВрЛогЗакр - ВрОтпр. Если чек открыт и нет ВрЛогЗакр: текущее время - ВрОтпр.">Ожидание</th>
                        <th title="Исключить позицию из дашборда и алертов.">Не учитывать</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['items'] as $item):
                        $wait = '—';
                        $waitClass = 'wait-time';
                        $usedFallbackTime = false;
                        $usedInProgressTime = false;
                        $usedProbCloseTime = false;
                        $isDeleted = !empty($item['was_deleted']);
                        $mainCat = isset($item['dish_category_id']) ? (int)$item['dish_category_id'] : 0;
                        $subCat = isset($item['dish_sub_category_id']) ? (int)$item['dish_sub_category_id'] : 0;
                        $dishId = (int)($item['dish_id'] ?? 0);
                        if ($mainCat <= 0 && $dishId > 0) $mainCat = $productMainCategory[$dishId] ?? 0;
                        if ($subCat <= 0 && $dishId > 0) $subCat = $productSubCategory[$dishId] ?? 0;
                        $isHookah = ($mainCat === 47) || ($subCat === 47);
                        $logicalCloseAt = null;
                        $logicalCloseLabel = '—';
                        if ($isHookah) {
                            $wait = 'кал';
                            $waitClass = 'wait-time wait-time-hookah';
                            $logicalCloseLabel = 'кал';
                        }
                        if (!$isHookah && !$isDeleted && !empty($item['ticket_sent_at'])) {
                            $sentTs = strtotime($item['ticket_sent_at']);
                            if ($sentTs !== false && $sentTs > 0) {
                                $endTime = null;
                                $endTs = 0;
                                $endSource = '';
                                foreach ([
                                    'pstr' => ($item['ready_pressed_at'] ?? null),
                                    'chass' => ($item['ready_chass_at'] ?? null),
                                    'prob' => ($item['prob_close_at'] ?? null),
                                ] as $src => $t) {
                                    if (empty($t)) continue;
                                    $ts = strtotime($t);
                                    if ($ts === false || $ts <= 0) continue;
                                    if ($ts < $sentTs) continue;
                                    $endTime = $t;
                                    $endTs = $ts;
                                    $endSource = $src;
                                    break;
                                }
                                if ($endTime === null) {
                                    $closedAt = $item['transaction_closed_at'] ?? null;
                                    if (
                                        !empty($closedAt) &&
                                        $closedAt !== '0000-00-00 00:00:00' &&
                                        (int)date('Y', strtotime($closedAt)) > 1970 &&
                                        (int)($item['status'] ?? 1) > 1
                                    ) {
                                        $ts = strtotime($closedAt);
                                        if ($ts !== false && $ts >= $sentTs) {
                                            $endTime = $closedAt;
                                            $endTs = $ts;
                                            $endSource = 'close';
                                        }
                                    }
                                }
                                if ($endTime !== null) {
                                    $logicalCloseAt = $endTime;
                                    $logicalCloseLabel = date('H:i:s', $endTs);
                                    $diff = $endTs - $sentTs;
                                    $usedFallbackTime = ($endSource === 'close');
                                    $usedProbCloseTime = ($endSource === 'prob');
                                    $icon = $endSource === 'close' ? '📌' : ($endSource === 'prob' ? '❓' : '⌛');
                                    $wait = $icon . ' ' . round($diff / 60, 1) . ' мин';
                                    if ($endSource === 'close') {
                                        $waitClass = 'wait-time wait-time-fallback';
                                    }
                                } elseif ((int)($item['status'] ?? 1) === 1) {
                                    $diff = time() - $sentTs;
                                    if ($diff >= 0) {
                                        $logicalCloseAt = date('Y-m-d H:i:s');
                                        $logicalCloseLabel = date('H:i:s');
                                        $wait = round($diff / 60, 1) . ' мин…';
                                        $waitClass = 'wait-time wait-time-fallback';
                                        $usedInProgressTime = true;
                                    }
                                }
                            }
                        }
                        $dishName = $productNames[$item['dish_id']] ?? $item['dish_name'];
                        $opened = ($item['transaction_opened_at'] && $item['transaction_opened_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($item['transaction_opened_at'])) > 1970) ? date('H:i:s', strtotime($item['transaction_opened_at'])) : '—';
                        if ((int)$item['status'] > 1 && $item['transaction_closed_at'] && $item['transaction_closed_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($item['transaction_closed_at'])) > 1970) {
                            $closed = date('H:i:s', strtotime($item['transaction_closed_at']));
                        } elseif ((int)$item['status'] > 1) {
                            $reason = isset($item['close_reason']) && $item['close_reason'] !== '' ? (int)$item['close_reason'] : null;
                            $payType = isset($item['pay_type']) && $item['pay_type'] !== '' ? (int)$item['pay_type'] : null;
                            if ($reason !== null && isset($closeReasonMap[$reason])) {
                                $closed = 'Закрыт без оплаты: ' . $closeReasonMap[$reason];
                            } elseif ($payType !== null && isset($payTypeMap[$payType])) {
                                $closed = 'Закрыт: ' . $payTypeMap[$payType];
                            } else {
                                $closed = 'Закрыт (время не передано API)';
                            }
                        } else {
                            $closed = '—';
                        }
                    ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($dishName) ?></strong>
                                <div style="font-size: 0.8em; color: #999;">ID: <?= $item['dish_id'] ?></div>
                            </td>
                            <td><?= $opened ?></td>
                            <td><?= $item['ticket_sent_at'] ? date('H:i:s', strtotime($item['ticket_sent_at'])) : '—' ?></td>
                            <td>
                                <?php if (!empty($item['was_deleted'])): ?>
                                    <span class="status-deleted">Удалено</span>
                                <?php elseif (!empty($item['ready_pressed_at'])): ?>
                                    <span class="status-ready" title="Время взято из Poster (finishedcooking)."><?= date('H:i:s', strtotime($item['ready_pressed_at'])) ?></span>
                                <?php else: ?>
                                    <span class="status-cooking">В процессе</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $closed ?></td>
                            <td><?= !empty($item['ready_chass_at']) ? date('H:i:s', strtotime($item['ready_chass_at'])) : '—' ?></td>
                            <td><?= !empty($item['prob_close_at']) ? date('H:i:s', strtotime($item['prob_close_at'])) : '—' ?></td>
                            <td><?= $logicalCloseLabel ?></td>
                            <td class="<?= $waitClass ?>" title="<?= $isDeleted ? 'Удалено: тайминг не считается.' : ($isHookah ? 'Кальяны: тайминг не считается.' : ($usedFallbackTime ? '📌 Расчет: ЗакPoster - Отправ.' : ($usedInProgressTime ? 'Расчет: текущее время - Отправ.' : ($usedProbCloseTime ? '❓ Расчет: ЗакРассч (ProbCloseTime) - Отправ. ЗакРассч берется из следующего чека(+1..+3) по тому же цеху.' : '⌛ Расчет: (Готово/ЗакChAss) - Отправ.')))) ?>"><?= $isDeleted ? '—' : $wait ?></td>
                            <td>
                                <form method="POST" class="exclude-item-form">
                                    <input type="hidden" name="toggle_exclude_item" value="<?= (int)$item['id'] ?>">
                                    <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($_GET), ENT_QUOTES) ?>">
                                    <label class="exclude-toggle">
                                        <input type="checkbox" name="exclude_from_dashboard" value="1" <?= (!empty($item['exclude_from_dashboard']) || !empty($item['was_deleted']) || $isHookah) ? 'checked' : '' ?> <?= $isHookah ? 'disabled' : '' ?>>
                                        не учитывать
                                    </label>
                                    <span class="save-indicator">сохранено</span>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endforeach; ?>

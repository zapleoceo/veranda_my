<?php
declare(strict_types=1);

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
$spotTz = ($spotTzName !== '' && in_array($spotTzName, timezone_identifiers_list(), true))
    ? new DateTimeZone($spotTzName)
    : new DateTimeZone('Asia/Ho_Chi_Minh');

$parseSpotDt = function (string $s) use ($spotTz): ?DateTimeImmutable {
    $v = trim($s);
    if ($v === '') return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $spotTz);
    if ($dt instanceof DateTimeImmutable) return $dt;
    try { return new DateTimeImmutable($v, $spotTz); } catch (Throwable) { return null; }
};
$fmtSpotDt = function (string $s, string $fmt = 'd.m.Y H:i') use ($parseSpotDt): string {
    $dt = $parseSpotDt($s);
    return $dt ? $dt->format($fmt) : '';
};
$fmtSpotDateTimeParts = function (string $s) use ($parseSpotDt): array {
    $dt = $parseSpotDt($s);
    return $dt ? [$dt->format('d.m.Y'), $dt->format('H:i')] : ['', ''];
};
?>
    <div class="res-page">
        <div class="card">
            <div class="res-header">
                <div>
                    <div class="title">Брони</div>
                    <div class="sub">Период и управление заявками</div>
                </div>
                <div class="res-controls">
                    <label class="res-switch">
                        <input id="showPoster" type="checkbox" <?= $showPoster ? 'checked' : '' ?>>
                        <span>Брони Poster</span>
                    </label>
                    <label class="res-switch">
                        <input id="showDeleted" type="checkbox" <?= $showDeleted ? 'checked' : '' ?>>
                        <span>Удалённые</span>
                    </label>
                    <div class="res-colmenu" id="resColMenu">
                        <button type="button" class="res-btn res-colbtn" id="resColBtn">Колонки</button>
                        <div class="res-colpanel" id="resColPanel" hidden></div>
                    </div>
                </div>
            </div>

            <?php if ($canManageTables): ?>
                <details class="res-hall-spoiler">
                    <summary class="res-hall-spoiler-summary">Столы</summary>
                    <div class="res-hall" id="resHallSection"
                         data-hall-id="<?= (int)$resHallId ?>"
                         data-spot-id="<?= (int)$resSpotId ?>"
                         data-hall-data="<?= htmlspecialchars(json_encode([
                             'spot_id'    => (int)$resSpotId,
                             'hall_id'    => (int)$resHallId,
                             'soon_hours' => (int)$resSoonHours,
                             'rotate_180' => (int)$resRotate180,
                         ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="res-hall-head">
                            <div>
                                <div class="res-hall-title">Столы</div>
                                <div class="res-hall-sub">spot_id=<?= (int)$resSpotId ?> · hall_id=<?= (int)$resHallId ?> · soon_hours=<?= (int)$resSoonHours ?> · min_preorder_per_guest=<?= (int)$resMinPreorderPerGuest ?></div>
                            </div>
                            <div class="res-hall-controls">
                                <label class="res-hall-label">spot_id <input id="resSpotId" value="<?= (int)$resSpotId ?>" inputmode="numeric"></label>
                                <label class="res-hall-label">hall_id <input id="resHallId" value="<?= (int)$resHallId ?>" inputmode="numeric"></label>
                                <label class="res-hall-label">soon <input id="resSoonHours" value="<?= (int)$resSoonHours ?>" inputmode="numeric"></label>
                                <label class="res-hall-label">min ₫/guest <input id="resMinPreorderPerGuest" value="<?= (int)$resMinPreorderPerGuest ?>" inputmode="numeric"></label>
                                <button type="button" class="res-btn" id="resHallApply">Применить</button>
                                <button type="button" class="res-btn" id="resHallRotate">180°</button>
                            </div>
                        </div>
                        <div class="res-hall-board-wrap">
                            <div class="res-hall-board" id="resHallBoard"></div>
                        </div>
                        <div class="res-hall-empty" id="resHallEmpty" hidden></div>
                        <div class="res-hall-actions">
                            <button type="button" class="res-btn" id="resHallAll">Все доступны</button>
                            <button type="button" class="res-btn danger" id="resHallNone">Снять все</button>
                        </div>
                    </div>
                </details>

                <div id="resHallModal" class="res-modal" hidden>
                    <div class="res-modal-card">
                        <div class="res-modal-title">Стол</div>
                        <div class="res-modal-body">
                            <div class="res-modal-grid">
                                <div class="res-modal-k">Метка (Poster)</div><div class="res-modal-v" id="resHallModalNum">—</div>
                                <div class="res-modal-k">Poster table_id</div><div class="res-modal-v" id="resHallModalPosterId">—</div>
                                <div class="res-modal-k">scheme_num</div><div class="res-modal-v"><input id="resHallModalSchemeNum" type="number" min="1" max="500" inputmode="numeric"></div>
                                <div class="res-modal-k">Название</div><div class="res-modal-v"><input id="resHallModalName" type="text" maxlength="80"></div>
                                <div class="res-modal-k">Вместимость</div><div class="res-modal-v"><input id="resHallModalCap" type="number" min="0" max="999"></div>
                                <div class="res-modal-k">Показывать</div><div class="res-modal-v"><input id="resHallModalShow" type="checkbox"></div>
                                <div class="res-modal-k">Доступен</div><div class="res-modal-v"><input id="resHallModalBookable" type="checkbox"></div>
                            </div>
                        </div>
                        <div class="res-modal-actions">
                            <button type="button" class="res-btn" id="resHallModalCancel">Отмена</button>
                            <button type="button" class="res-btn primary" id="resHallModalSave">Сохранить</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="GET" class="filters">
                <div class="date-inputs">
                    <label>Начало <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
                    <label>Конец <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
                </div>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                <input type="hidden" name="show_deleted" value="<?= $showDeleted ? '1' : '' ?>">
                <input type="hidden" name="show_poster" value="<?= $showPoster ? '1' : '' ?>">
                <button type="submit" class="btn-primary">Показать</button>
            </form>

            <div class="table-wrap" id="resTableWrap">
                <table class="res-table" id="resTable">
                    <thead>
                        <tr class="res-head-group">
                            <th colspan="10" data-side="site">Сайт</th>
                            <?php if ($showPoster): ?><th colspan="7" data-side="poster">Poster</th><?php endif; ?>
                        </tr>
                        <tr class="res-head-cols">
                            <th data-col="id" data-side="site" data-sort="id" data-type="num">ID</th>
                            <th data-col="qr_code" data-side="site" data-sort="qr_code" data-type="text">Код</th>
                            <th data-col="created_at" data-side="site" data-sort="created_at" data-type="date">Создано</th>
                            <th data-col="start_time" data-side="site" data-sort="start_time" data-type="date">Время брони</th>
                            <th data-col="table_num" data-side="site" data-sort="table_num" data-type="num">Стол</th>
                            <th data-col="guests" data-side="site" data-sort="guests" data-type="num">Гостей</th>
                            <th data-col="name" data-side="site" data-sort="name" data-type="text">Гость</th>
                            <th data-col="total_amount" data-side="site" data-sort="total_amount" data-type="num">Сумма</th>
                            <th data-col="qr_url" data-side="site" data-sort="qr_url" data-type="text">QR</th>
                            <th data-col="actions" data-side="site">Действия</th>
                            <?php if ($showPoster): ?>
                                <th data-col="p_id" data-side="poster" data-sort="p_id" data-type="num">Poster ID</th>
                                <th data-col="p_created_at" data-side="poster" data-sort="p_created_at" data-type="date">Создано</th>
                                <th data-col="p_start_time" data-side="poster" data-sort="p_start_time" data-type="date">Время</th>
                                <th data-col="p_table_num" data-side="poster" data-sort="p_table_num" data-type="num">Стол</th>
                                <th data-col="p_guests" data-side="poster" data-sort="p_guests" data-type="num">Гостей</th>
                                <th data-col="p_name" data-side="poster" data-sort="p_name" data-type="text">Гость</th>
                                <th data-col="p_phone" data-side="poster" data-sort="p_phone" data-type="text">Телефон</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($viewRows)): ?>
                            <tr class="res-empty"><td colspan="<?= $showPoster ? 17 : 10 ?>" class="res-empty-cell">Нет броней за выбранный период</td></tr>
                        <?php else: ?>
                            <?php foreach ($viewRows as $pair): ?>
                                <?php
                                    $r = is_array($pair['our'] ?? null) ? $pair['our'] : null;
                                    $p = is_array($pair['poster'] ?? null) ? $pair['poster'] : null;

                                    $tgUsername = ltrim(trim((string)(is_array($r) ? ($r['tg_username'] ?? '') : '')), '@');
                                    $waDigits   = preg_replace('/\D+/', '', (string)(is_array($r) ? ($r['whatsapp_phone'] ?? '') : ''));
                                    $waPhoneNorm = ($waDigits !== '' && preg_match('/^[1-9]\d{8,14}$/', $waDigits)) ? ('+' . $waDigits) : '';
                                    $deletedAt  = (string)(is_array($r) ? ($r['deleted_at'] ?? '') : '');
                                    $deletedBy  = (string)(is_array($r) ? ($r['deleted_by'] ?? '') : '');
                                    $isDeleted  = $deletedAt !== '' && $deletedAt !== '0000-00-00 00:00:00';
                                    $pIsDeleted = is_array($p) && (int)($p['status'] ?? 0) === 7;
                                    $isMerged   = is_array($r) && is_array($p);
                                    [$createdAtDate, $createdAtTime]   = is_array($r) ? $fmtSpotDateTimeParts((string)($r['created_at'] ?? '')) : ['', ''];
                                    [$startDate, $startTime]           = is_array($r) ? $fmtSpotDateTimeParts((string)($r['start_time'] ?? '')) : ['', ''];
                                    [$pCreatedAtDate, $pCreatedAtTime] = is_array($p) ? $fmtSpotDateTimeParts((string)($p['created_at'] ?? '')) : ['', ''];
                                    [$pStartDate, $pStartTime]         = is_array($p) ? $fmtSpotDateTimeParts((string)($p['start_time'] ?? '')) : ['', ''];
                                    $createdAtTs = is_array($r) && !empty($r['created_at']) ? ($parseSpotDt((string)$r['created_at'])?->getTimestamp() ?? '') : '';
                                    $startTs     = is_array($r) && !empty($r['start_time'])  ? ($parseSpotDt((string)$r['start_time'])?->getTimestamp()  ?? '') : '';
                                    $pCreatedAtTs = is_array($p) && !empty($p['created_at']) ? ($parseSpotDt((string)$p['created_at'])?->getTimestamp() ?? '') : '';
                                    $pStartTs    = is_array($p) && !empty($p['start_time'])  ? ($parseSpotDt((string)$p['start_time'])?->getTimestamp()  ?? '') : '';
                                ?>
                                <tr<?= is_array($r) ? ' data-id="' . htmlspecialchars((string)($r['id'] ?? '')) . '"' : '' ?> class="<?= $isDeleted ? 'is-deleted' : '' ?> <?= $pIsDeleted ? 'is-poster is-deleted' : '' ?> <?= $isMerged ? 'is-merged' : '' ?>">
                                    <td data-label="ID" data-col="id" data-sort-value="<?= is_array($r) ? (int)($r['id'] ?? 0) : '' ?>">
                                        <?= is_array($r) ? ('<div>#' . htmlspecialchars((string)($r['id'] ?? '')) . '</div>') : '<span class="res-muted">—</span>' ?>
                                        <?php if ($isDeleted): ?>
                                            <div class="tag deleted" id="deleted-tag-<?= htmlspecialchars((string)($r['id'] ?? '')) ?>">
                                                Удалено<?= $deletedBy !== '' ? ' · ' . htmlspecialchars($deletedBy) : '' ?>
                                            </div>
                                            <div class="res-muted" id="deleted-meta-<?= htmlspecialchars((string)($r['id'] ?? '')) ?>">
                                                <?= htmlspecialchars($fmtSpotDt($deletedAt)) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Код" data-col="qr_code" data-sort-value="<?= is_array($r) ? htmlspecialchars((string)($r['qr_code'] ?? ''), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r) && !empty($r['qr_code'])): ?>
                                            <span class="tag"><?= htmlspecialchars((string)$r['qr_code']) ?></span>
                                        <?php else: ?><span class="res-muted">—</span><?php endif; ?>
                                    </td>
                                    <td data-label="Создано" data-col="created_at" data-sort-value="<?= htmlspecialchars((string)$createdAtTs, ENT_QUOTES) ?>">
                                        <?php if ($createdAtDate !== ''): ?>
                                            <div><?= htmlspecialchars($createdAtDate) ?></div><div class="res-muted"><?= htmlspecialchars($createdAtTime) ?></div>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td data-label="Время" class="res-strong" data-col="start_time" data-sort-value="<?= htmlspecialchars((string)$startTs, ENT_QUOTES) ?>">
                                        <?php if ($startDate !== ''): ?>
                                            <div><?= htmlspecialchars($startDate) ?></div><div class="res-muted"><?= htmlspecialchars($startTime) ?></div>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td data-label="Стол" data-col="table_num" data-sort-value="<?= is_array($r) ? (int)preg_replace('/\D+/', '', (string)($r['table_num'] ?? '')) : '' ?>"><?= is_array($r) ? htmlspecialchars((string)$r['table_num']) : '—' ?></td>
                                    <td data-label="Гостей" data-col="guests" data-sort-value="<?= is_array($r) ? (int)($r['guests'] ?? 0) : '' ?>"><?= is_array($r) ? (int)$r['guests'] : '—' ?></td>
                                    <td data-label="Гость" data-col="name" data-sort-value="<?= is_array($r) ? htmlspecialchars(mb_strtolower(trim((string)($r['name'] ?? ''))), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r)): ?>
                                            <div class="res-name"><?= htmlspecialchars((string)$r['name']) ?></div>
                                            <div class="res-muted"><?= htmlspecialchars((string)$r['phone']) ?></div>
                                        <?php else: ?><span class="res-muted">—</span><?php endif; ?>
                                        <?php if ($waPhoneNorm !== ''): ?>
                                            <?php $wc = preg_replace('/\D+/', '', $waPhoneNorm); ?>
                                            <div class="res-muted"><a class="res-link" href="https://wa.me/<?= htmlspecialchars($wc) ?>" target="_blank">WA: +<?= htmlspecialchars($wc) ?></a></div>
                                        <?php elseif ($tgUsername !== ''): ?>
                                            <div class="res-muted"><a class="res-link" href="https://t.me/<?= htmlspecialchars($tgUsername) ?>" target="_blank">TG: @<?= htmlspecialchars($tgUsername) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (is_array($r) && !empty($r['zalo_phone'])): ?>
                                            <div class="res-muted"><a class="res-link" href="https://zalo.me/<?= htmlspecialchars(ltrim((string)$r['zalo_phone'], '+')) ?>" target="_blank">Zalo: <?= htmlspecialchars((string)$r['zalo_phone']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (is_array($r) && (!empty($r['comment']) || !empty($r['preorder_text']))): ?>
                                            <details class="res-more">
                                                <summary>Комментарий / предзаказ</summary>
                                                <div class="box">
                                                    <?php if (!empty($r['comment'])): ?><div><b>Комментарий:</b> <?= htmlspecialchars((string)$r['comment']) ?></div><?php endif; ?>
                                                    <?php if (!empty($r['preorder_text'])): ?><div class="res-mt6"><b>Предзаказ:</b><div class="pre"><?= htmlspecialchars((string)$r['preorder_text']) ?></div></div><?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Сумма" data-col="total_amount" data-sort-value="<?= is_array($r) ? (float)($r['total_amount'] ?? 0) : '' ?>"><?= (is_array($r) && (float)($r['total_amount'] ?? 0) > 0) ? number_format((float)$r['total_amount'], 0, '.', ' ') . ' ₫' : '—' ?></td>
                                    <td data-label="QR" data-col="qr_url" data-sort-value="<?= is_array($r) ? htmlspecialchars((string)($r['qr_url'] ?? ''), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r) && !empty($r['qr_url'])): ?>
                                            <a class="res-link res-link-strong" href="<?= htmlspecialchars((string)$r['qr_url']) ?>" target="_blank">QR</a>
                                        <?php else: ?><span class="res-muted">—</span><?php endif; ?>
                                    </td>
                                    <td data-label="Действия" data-col="actions">
                                        <?php if (is_array($r)): ?>
                                            <div class="res-actions">
                                                <?php $gBtnClass = $waPhoneNorm !== '' ? 'contact-wa' : ($tgUsername !== '' || (int)($r['tg_user_id'] ?? 0) > 0 ? 'contact-tg' : ''); ?>
                                                <button type="button" class="res-btn btn-resend <?= htmlspecialchars($gBtnClass) ?>" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="guest">reG</button>
                                                <button type="button" class="res-btn primary btn-resend" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="manager">reM</button>
                                                <button type="button" class="res-btn btn-edit" data-id="<?= htmlspecialchars((string)$r['id']) ?>" title="Редактировать">&#9998;</button>
                                                <?php if ($hasPosterAccess && empty($r['is_poster_pushed'])): ?>
                                                    <button type="button" class="res-btn primary btn-vposter"
                                                        data-id="<?= htmlspecialchars((string)$r['id']) ?>"
                                                        data-code="<?= htmlspecialchars((string)($r['qr_code'] ?? '')) ?>"
                                                        data-start="<?= htmlspecialchars((string)($r['start_time'] ?? '')) ?>"
                                                        data-table="<?= htmlspecialchars((string)($r['table_num'] ?? '')) ?>"
                                                        data-guests="<?= htmlspecialchars((string)($r['guests'] ?? '')) ?>"
                                                        data-name="<?= htmlspecialchars((string)($r['name'] ?? '')) ?>"
                                                        data-phone="<?= htmlspecialchars((string)($r['phone'] ?? '')) ?>"
                                                    >вPos</button>
                                                <?php endif; ?>
                                                <button type="button" class="res-btn danger icon btn-delete" data-id="<?= htmlspecialchars((string)$r['id']) ?>"><?= $isDeleted ? '↺' : '✕' ?></button>
                                            </div>
                                            <div class="res-status" id="resend-status-<?= htmlspecialchars((string)$r['id']) ?>"></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showPoster): ?>
                                        <td data-label="Poster ID" data-col="p_id" data-sort-value="<?= is_array($p) ? (int)($p['incoming_order_id'] ?? 0) : '' ?>"><?= is_array($p) && !empty($p['incoming_order_id']) ? htmlspecialchars((string)$p['incoming_order_id']) : '—' ?></td>
                                        <td data-label="Создано" data-col="p_created_at" data-sort-value="<?= htmlspecialchars((string)$pCreatedAtTs, ENT_QUOTES) ?>">
                                            <?php if ($pCreatedAtDate !== ''): ?><div><?= htmlspecialchars($pCreatedAtDate) ?></div><div class="res-muted"><?= htmlspecialchars($pCreatedAtTime) ?></div><?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td data-label="Время" class="res-strong" data-col="p_start_time" data-sort-value="<?= htmlspecialchars((string)$pStartTs, ENT_QUOTES) ?>">
                                            <?php if ($pStartDate !== ''): ?><div><?= htmlspecialchars($pStartDate) ?></div><div class="res-muted"><?= htmlspecialchars($pStartTime) ?></div><?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td data-label="Стол" data-col="p_table_num" data-sort-value="<?= is_array($p) ? (int)preg_replace('/\D+/', '', (string)($p['table_num'] ?? '')) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['table_num'] ?? '')) : '—' ?></td>
                                        <td data-label="Гостей" data-col="p_guests" data-sort-value="<?= is_array($p) ? (int)($p['guests'] ?? 0) : '' ?>"><?= is_array($p) ? (int)($p['guests'] ?? 0) : '—' ?></td>
                                        <td data-label="Гость" data-col="p_name" data-sort-value="<?= is_array($p) ? htmlspecialchars(mb_strtolower(trim((string)($p['name'] ?? ''))), ENT_QUOTES) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['name'] ?? '')) : '—' ?></td>
                                        <td data-label="Телефон" data-col="p_phone" data-sort-value="<?= is_array($p) ? htmlspecialchars((string)($p['phone'] ?? ''), ENT_QUOTES) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['phone'] ?? '')) : '—' ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- VPoster Confirmation Modal -->
    <div id="vposterModal" class="res-modal" hidden>
        <div class="res-modal-card">
            <div class="res-modal-title">Создание брони в Poster</div>
            <div class="res-modal-body">
                Вы собираетесь отправить эту бронь в Poster POS.<br><br>
                Это создаст официальную бронь на терминале официанта. Убедитесь, что все данные верны.
                <div id="vposterModalInfo" class="res-modal-info"></div>
            </div>
            <label class="res-modal-check"><input type="checkbox" id="vposterConfirmCheck"><span>проверил</span></label>
            <div class="res-modal-actions">
                <button type="button" class="res-btn" id="vposterCancel">Отмена</button>
                <button type="button" class="res-btn primary" id="vposterOk" disabled>ОК</button>
            </div>
        </div>
    </div>

    <!-- Edit Reservation Modal -->
    <div id="editResModal" class="res-modal" hidden>
        <div class="res-modal-card res-modal-card-wide">
            <div class="res-modal-title">Редактировать бронь #<span id="editResIdTitle"></span></div>
            <div class="res-modal-body">
                <form id="editResForm" class="res-modal-grid res-modal-grid-2col">
                    <input type="hidden" name="id" id="editResId">
                    <input type="hidden" name="spot_id" id="editResSpotId">
                    <div class="form-group"><label class="res-modal-k">Имя гостя</label><input type="text" name="name" id="editResName" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Телефон</label><input type="text" name="phone" id="editResPhone" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Дата и время (Y-m-d H:i:s)</label><input type="text" name="start_time" id="editResStartTime" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Кол-во гостей</label><input type="number" name="guests" id="editResGuests" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Длительность (мин)</label><input type="number" name="duration" id="editResDuration" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Hall</label><select name="hall_id" id="editResHallId" class="res-input"></select></div>
                    <div class="form-group"><label class="res-modal-k">Стол (Poster)</label><select name="poster_table_id" id="editResTableNum" class="res-input"></select></div>
                    <div class="form-group"><label class="res-modal-k">Код брони</label><input type="text" name="qr_code" id="editResQRCode" class="res-input"></div>
                    <div class="form-group"><label class="res-modal-k">Telegram username</label><input type="text" name="tg_username" id="editResTgUsername" class="res-input" placeholder="@username"></div>
                    <div class="form-group"><label class="res-modal-k">WhatsApp телефон</label><input type="text" name="whatsapp_phone" id="editResWhatsappPhone" class="res-input" placeholder="+84..."></div>
                    <div class="form-group"><label class="res-modal-k">Zalo телефон</label><input type="text" name="zalo_phone" id="editResZaloPhone" class="res-input" placeholder="+84..."></div>
                    <div class="form-group form-group-span-2"><label class="res-modal-k">Комментарий</label><textarea name="comment" id="editResComment" class="res-input" rows="3"></textarea></div>
                </form>
            </div>
            <div class="res-modal-actions">
                <button type="button" class="res-btn" id="editResCancel">Отмена</button>
                <button type="button" class="res-btn primary" id="editResSave">Сохранить</button>
            </div>
        </div>
    </div>

    <div class="res-hscroll" id="resHScroll" hidden><div class="res-hscroll-inner" id="resHScrollInner"></div></div>

<script src="/reservations/assets/js/reservations.js?v=20260517_account"></script>
<script src="/reservations/assets/js/reservations_hall.js"></script>

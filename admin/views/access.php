<div class="card admin-access">
            <h3>Управление доступом</h3>
            <form method="POST" class="form-group access-add-form">
                <label class="access-form-label">Добавить новый email</label>
                <div class="access-add-row">
                    <input class="access-input" type="email" name="email" placeholder="example@gmail.com" required>
                    <button type="submit" name="add_email" class="btn2 btn2-primary">Добавить</button>
                </div>
            </form>

            <?php if (empty($users)): ?>
                <div class="error access-empty-state">
                    Список пользователей пуст. Нажмите «Добавить себя», чтобы восстановить доступы.
                    <div class="muted access-empty-state-user">Текущий пользователь: <?= htmlspecialchars((string)($_SESSION['user_email'] ?? '—')) ?></div>
                    <form method="POST" class="access-empty-state-form">
                        <button type="submit" name="add_self" value="1" class="btn2 btn2-primary">Добавить себя</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>Дата добавления</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($user['name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars((string)($user['telegram_username'] ?? '')) ?></td>
                        <td><?php
                            $ca = (string)($user['created_at'] ?? '');
                            if ($ca !== '' && $ca !== '0000-00-00 00:00:00') {
                                $ts = strtotime($ca);
                                echo $ts !== false ? date('d.m.Y H:i', $ts) : '—';
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td>
                            <div class="access-row-actions">
                            <?php
                                $rawPerms = (string)($user['permissions_json'] ?? '');
                                $perms = $rawPerms !== '' ? json_decode($rawPerms, true) : null;
                                if (!is_array($perms)) $perms = null;
                            ?>
                            <button type="button" class="btn2 perm-gear"
                                data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>"
                                data-perms="<?= htmlspecialchars(json_encode($perms, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                                data-tg="<?= htmlspecialchars((string)($user['telegram_username'] ?? ''), ENT_QUOTES) ?>"
                            >Редактировать</button>
                            <?php if ($user['email'] !== $_SESSION['user_email']): ?>
                                <a href="?delete=<?= urlencode($user['email']) ?>" class="btn2 delete-btn" onclick="return confirm('Удалить доступ для <?= $user['email'] ?>?')">Удалить</a>
                            <?php else: ?>
                                <span class="access-self-mark">(Это вы)</span>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="perm-modal" id="permModal" aria-hidden="true">
                <div class="perm-modal-backdrop"></div>
                <div class="perm-modal-card confirm-modal">
                    <div class="perm-modal-title">Права доступа</div>
                    <form method="POST" id="permForm">
                        <input type="hidden" name="save_user_permissions" value="1">
                        <input type="hidden" name="perm_email" id="permEmail" value="">
                        <div class="form-group access-form-group">
                            <label class="access-form-label">Telegram username</label>
                            <input class="access-input" type="text" name="perm_tg_username" id="permTgUsername" placeholder="например: zapleosoft">
                            <div class="muted access-form-help">Нужен для кнопки «ПРИНЯТО» в Telegram. Пиши без @.</div>
                        </div>
                        <div class="perm-list">
                            <?php foreach ($permissionKeys as $k => $label): ?>
                                <?php if ($k === 'telegram_ack') continue; ?>
                                <label class="perm-row">
                                    <input type="checkbox" name="perm_<?= htmlspecialchars($k) ?>" id="perm_<?= htmlspecialchars($k) ?>" value="1">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="perm-actions">
                            <button type="button" class="btn2 perm-cancel" id="permCancel">Отмена</button>
                            <button type="submit" class="btn2 btn2-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

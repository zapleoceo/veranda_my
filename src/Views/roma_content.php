<div class="wrap">
    <div class="card">
        <div class="row">
            <div class="roma-header-info">
                <h1>/roma — продажи кальянов (категория 47)</h1>
                <div class="muted">Источник: Poster dash.getProductsSales · без кэширования</div>
            </div>
            <label>
                Дата начала (date_from)
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца (date_to)
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div class="roma-actions">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
            </div>
        </div>

        <div class="error roma-error" id="err"></div>

        <table>
            <thead>
                <tr>
                    <th>Название кальяна</th>
                    <th class="roma-col-count">Кол‑во</th>
                    <th class="roma-col-price">Цена</th>
                    <th class="roma-col-discount">Скидка</th>
                    <th class="roma-col-payed">Оплачено</th>
                    <th class="roma-col-saldo">Сальдо</th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
            <tfoot id="tfoot"></tfoot>
        </table>

        <div class="romaTotal">
            <div class="romaBox">
                <span id="romaBase">0</span> * 65% = <span id="romaSum">0</span>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/roma.js?v=20260516"></script>

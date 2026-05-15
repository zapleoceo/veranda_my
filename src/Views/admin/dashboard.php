<?php
$today = date('Y-m-d');
?>
<div class="card" style="padding:.75rem 1.5rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        <div><label>От</label><input type="date" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>" style="width:140px"></div>
        <div><label>До</label><input type="date" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>" style="width:140px"></div>
        <div><label>Час от</label><input type="number" name="hourStart" value="<?= $hourStart ?>" min="0" max="23" style="width:70px"></div>
        <div><label>Час до</label><input type="number" name="hourEnd" value="<?= $hourEnd ?>" min="0" max="23" style="width:70px"></div>
        <button type="submit" class="btn btn-primary">Применить</button>
        <a href="?dateFrom=<?= $today ?>&dateTo=<?= $today ?>&hourStart=0&hourEnd=23" class="btn btn-secondary">Сегодня</a>
        <span style="flex:1"></span>
        <span style="font-size:.75rem;color:#9ca3af;align-self:center">Последний синк: <?= htmlspecialchars($lastSync) ?></span>
    </form>
</div>

<?php foreach ($chartData as $sid => $cd): ?>
<div class="card">
    <h2><?= htmlspecialchars($cd['label']) ?></h2>
    <canvas id="chart<?= $sid ?>" style="max-height:260px"></canvas>
</div>
<?php endforeach; ?>

<script>
const labels = <?= json_encode($hours) ?>;
const chartData = <?= json_encode($chartData) ?>;
const colors = { '2': '#6c8ef5', '3': '#f59e0b' };

Object.entries(chartData).forEach(([sid, cd]) => {
    const ctx = document.getElementById('chart' + sid);
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Среднее (мин)',
                    data: cd.avg,
                    backgroundColor: colors[sid] + 'cc',
                    borderRadius: 3,
                    order: 2,
                },
                {
                    label: 'Макс (мин)',
                    data: cd.max,
                    type: 'line',
                    borderColor: '#ef4444',
                    backgroundColor: 'transparent',
                    pointRadius: 2,
                    tension: 0.3,
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'минуты' } },
                x: { ticks: { maxRotation: 60, font: { size: 10 } } }
            }
        }
    });
});
</script>

<?php
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$pageTitle = '总览';
$activePage = 'dashboard';

// 统计数据（全部使用预处理语句，防 SQL 注入）
function countForUser(string $sql): int {
    global $userId;
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
$totalTasks   = countForUser('SELECT COUNT(*) FROM search_tasks WHERE user_id = ?');
$totalResults = countForUser('SELECT COUNT(*) FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id WHERE st.user_id = ?');
$totalTokens  = countForUser('SELECT COUNT(*) FROM github_tokens WHERE user_id = ?');
$totalChannels = countForUser('SELECT COUNT(*) FROM notification_channels WHERE user_id = ?');

// 最近任务
$recentStmt = db()->prepare('SELECT * FROM search_tasks WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$recentStmt->execute([$userId]);
$recentTasks = $recentStmt->fetchAll();

// ---------- 图表数据 ----------
$PLATFORM_COLORS = [
    'github'  => '#3565e0',
    'gitee'   => '#d64550',
    'gitcode' => '#8b5cf6',
];
$PLATFORM_NAMES = ['github' => 'GitHub', 'gitee' => 'Gitee', 'gitcode' => 'GitCode'];
$STATUS_COLORS = [
    'completed' => '#2e9464',
    'running'   => '#3565e0',
    'pending'   => '#8b93a5',
    'failed'    => '#d64550',
];
$STATUS_NAMES = ['completed' => '已完成', 'running' => '运行中', 'pending' => '等待中', 'failed' => '失败'];

// 近 14 天命中趋势（按天聚合，补零）
$trendStmt = db()->prepare(
    "SELECT DATE(sr.found_at) AS d, COUNT(*) AS c
     FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id
     WHERE st.user_id = ? AND sr.found_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
     GROUP BY DATE(sr.found_at)"
);
$trendStmt->execute([$userId]);
$trendMap = [];
foreach ($trendStmt->fetchAll() as $row) {
    $trendMap[$row['d']] = (int)$row['c'];
}
$trendSeries = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} day"));
    $trendSeries[] = ['d' => $day, 'c' => $trendMap[$day] ?? 0];
}

// 平台分布（含零值平台，保持顺序）
$platStmt = db()->prepare(
    "SELECT sr.platform AS p, COUNT(*) AS c
     FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id
     WHERE st.user_id = ? GROUP BY sr.platform"
);
$platStmt->execute([$userId]);
$platMap = [];
foreach ($platStmt->fetchAll() as $row) {
    $platMap[$row['p']] = (int)$row['c'];
}
$byPlatform = [];
foreach ($PLATFORM_COLORS as $pk => $color) {
    $byPlatform[] = ['key' => $pk, 'name' => $PLATFORM_NAMES[$pk], 'color' => $color, 'count' => $platMap[$pk] ?? 0];
}

// 任务状态分布（含零值状态）
$statusStmt = db()->prepare('SELECT status, COUNT(*) AS c FROM search_tasks WHERE user_id = ? GROUP BY status');
$statusStmt->execute([$userId]);
$statusMap = [];
foreach ($statusStmt->fetchAll() as $row) {
    $statusMap[$row['status']] = (int)$row['c'];
}
$byStatus = [];
foreach ($STATUS_COLORS as $sk => $color) {
    $byStatus[] = ['key' => $sk, 'name' => $STATUS_NAMES[$sk], 'color' => $color, 'count' => $statusMap[$sk] ?? 0];
}

// 高频命中关键词 Top5
$topStmt = db()->prepare(
    "SELECT st.query AS q, COUNT(*) AS c
     FROM search_results sr JOIN search_tasks st ON sr.task_id = st.id
     WHERE st.user_id = ?
     GROUP BY st.query ORDER BY c DESC LIMIT 5"
);
$topStmt->execute([$userId]);
$topQueries = $topStmt->fetchAll();
$topMax = 0;
foreach ($topQueries as $row) {
    $topMax = max($topMax, (int)$row['c']);
}

// 服务器资源快照（Linux 下可用；非 Linux 显示不支持提示）
$server = ServerMetrics::snapshot();
/** 资源条颜色：绿 <60% / 黄 <85% / 红 ≥85% */
function srv_bar_color(float $p): string {
    return $p < 60 ? 'var(--success)' : ($p < 85 ? 'var(--warning)' : 'var(--danger)');
}
/** 网络速率格式化 */
function srv_rate($bps): string {
    if ($bps === null) { return '—'; }
    if ($bps < 1024) { return $bps . ' B/s'; }
    if ($bps < 1048576) { return round($bps / 1024, 1) . ' KB/s'; }
    return round($bps / 1048576, 2) . ' MB/s';
}

// ---------- SVG 图表生成 ----------
/** 近 14 天命中趋势：面积折线图 */
function dash_trend_svg(array $series): string {
    $W = 620; $H = 240;
    $padL = 42; $padR = 14; $padT = 14; $padB = 30;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;
    $max = 1;
    foreach ($series as $s) { $max = max($max, $s['c']); }
    // 纵轴取整到好看的刻度
    $nice = 1;
    foreach ([5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000, 10000, 50000] as $n) {
        if ($max <= $n) { $nice = $n; break; }
        $nice = (int)(ceil($max / 10000) * 10000);
    }
    $n = count($series);
    $x = fn(int $i): float => $padL + ($n > 1 ? $i * ($plotW / ($n - 1)) : $plotW / 2);
    $y = fn(int $c): float => $padT + $plotH - ($c / $nice) * $plotH;

    $pts = [];
    foreach ($series as $i => $s) { $pts[] = [$x($i), $y($s['c'])]; }
    $line = implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts));
    $area = $line . ' ' . round($x($n - 1), 1) . ',' . ($padT + $plotH) . ' ' . round($x(0), 1) . ',' . ($padT + $plotH);

    $grid = '';
    for ($g = 0; $g <= 4; $g++) {
        $gy = round($padT + $plotH - ($g / 4) * $plotH, 1);
        $gv = (int)round(($g / 4) * $nice);
        $grid .= "<line class=\"chart-grid-line\" x1=\"$padL\" y1=\"$gy\" x2=\"" . ($W - $padR) . "\" y2=\"$gy\" stroke-width=\"1\"/>";
        $grid .= "<text class=\"chart-text\" x=\"" . ($padL - 8) . "\" y=\"" . ($gy + 4) . "\" text-anchor=\"end\">$gv</text>";
    }
    $dateLabels = '';
    foreach ($series as $i => $s) {
        if ($i % 2 !== 0 && $i !== $n - 1) { continue; }
        $lbl = date('m-d', strtotime($s['d']));
        $dateLabels .= "<text class=\"chart-text\" x=\"" . round($x($i), 1) . "\" y=\"" . ($H - 8) . "\" text-anchor=\"middle\">$lbl</text>";
    }
    $dots = '';
    foreach ($pts as $p) {
        $dots .= "<circle cx=\"" . round($p[0], 1) . "\" cy=\"" . round($p[1], 1) . "\" r=\"3\" class=\"chart-dot\" fill=\"#3565e0\" stroke-width=\"1.5\"/>";
    }

    return "<svg viewBox=\"0 0 $W $H\" width=\"100%\" role=\"img\" aria-label=\"近14天命中趋势\" style=\"display:block\">"
        . "<defs><linearGradient id=\"trendFill\" x1=\"0\" y1=\"0\" x2=\"0\" y2=\"1\">"
        . "<stop offset=\"0%\" stop-color=\"#3565e0\" stop-opacity=\"0.28\"/>"
        . "<stop offset=\"100%\" stop-color=\"#3565e0\" stop-opacity=\"0.02\"/></linearGradient></defs>"
        . $grid
        . "<polygon points=\"$area\" fill=\"url(#trendFill)\"/>"
        . "<polyline points=\"$line\" fill=\"none\" stroke=\"#3565e0\" stroke-width=\"2.5\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>"
        . $dots . $dateLabels
        . '</svg>';
}

/** 平台分布：环形图 + 中心总数 */
function dash_donut_svg(array $segs, int $total): string {
    $r = 62; $cx = 90; $cy = 90;
    $circ = 2 * M_PI * $r;
    $offset = 0;
    $arcs = '';
    foreach ($segs as $s) {
        if ($total <= 0 || $s['count'] <= 0) { continue; }
        $len = ($s['count'] / max(1, $total)) * $circ;
        $arcs .= "<circle cx=\"$cx\" cy=\"$cy\" r=\"$r\" fill=\"none\" stroke=\"{$s['color']}\" stroke-width=\"22\" "
            . "stroke-dasharray=\"" . round($len, 2) . " " . round($circ - $len, 2) . "\" "
            . "stroke-dashoffset=\"" . round(-$offset, 2) . "\" transform=\"rotate(-90 $cx $cy)\"/>";
        $offset += $len;
    }
    if ($arcs === '') {
        $arcs = "<circle cx=\"$cx\" cy=\"$cy\" r=\"$r\" fill=\"none\" stroke=\"#e6e9f0\" stroke-width=\"22\"/>";
    }
    return "<svg viewBox=\"0 0 180 180\" width=\"180\" height=\"180\" role=\"img\" aria-label=\"平台分布\">"
        . $arcs
        . "<text x=\"$cx\" y=\"" . ($cy - 4) . "\" text-anchor=\"middle\" font-size=\"26\" font-weight=\"700\" style=\"fill:var(--text)\">$total</text>"
        . "<text class=\"chart-text\" x=\"$cx\" y=\"" . ($cy + 18) . "\" text-anchor=\"middle\" font-size=\"11\">总命中</text>"
        . '</svg>';
}

include __DIR__ . '/partials/header.php';
?>
<div class="stats">
    <div class="stat-card">
        <div class="stat-icon icon-blue">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </div>
        <div class="label">任务</div>
        <div class="value"><?= $totalTasks ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-red">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
        </div>
        <div class="label">命中结果</div>
        <div class="value"><?= $totalResults ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-amber">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m21 2-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L19 3m-3.5 3.5L18 9"/></svg>
        </div>
        <div class="label">令牌设置</div>
        <div class="value"><?= $totalTokens ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon icon-green">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        </div>
        <div class="label">推送通知</div>
        <div class="value"><?= $totalChannels ?></div>
    </div>
</div>

<?php if (!empty($server['available'])):
    $cpu = $server['cpu'];
    $mem = $server['mem'];
    $cpuPct = (float)($cpu['percent'] ?? 0);
    $memPct = (float)$mem['percent'];
?>
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <h3 style="margin:0;">服务器状态</h3>
        <span style="color:var(--text-3);font-size:12px;">
            运行 <?= Security::e($cpu['uptime']) ?> ·
            <span id="srvTime" title="每 5 秒自动刷新">采样于 <?= Security::e($server['time']) ?></span>
        </span>
    </div>
    <div class="srv-grid">
        <div class="srv-tile">
            <div class="srv-tile-head">
                <span class="srv-tile-title">CPU</span>
                <span class="srv-tile-value" id="srvCpuPct" style="color:<?= srv_bar_color($cpuPct) ?>"><?= $cpu['percent'] === null ? '—' : $cpuPct . '%' ?></span>
            </div>
            <div class="hbar-track srv-track"><div class="hbar-fill" id="srvCpuBar" style="width:<?= $cpuPct ?>%;background:<?= srv_bar_color($cpuPct) ?>"></div></div>
            <div class="srv-tile-sub" id="srvCpuDetail"><?= Security::e($cpu['model']) ?> · <?= (int)$cpu['cores'] ?> 核 · 负载 <?= Security::e((string)$cpu['load'][0]) ?></div>
        </div>
        <div class="srv-tile">
            <div class="srv-tile-head">
                <span class="srv-tile-title">内存</span>
                <span class="srv-tile-value" id="srvMemPct" style="color:<?= srv_bar_color($memPct) ?>"><?= $memPct ?>%</span>
            </div>
            <div class="hbar-track srv-track"><div class="hbar-fill" id="srvMemBar" style="width:<?= $memPct ?>%;background:<?= srv_bar_color($memPct) ?>"></div></div>
            <div class="srv-tile-sub" id="srvMemDetail">已用 <?= Cleaner::fmtBytes($mem['used']) ?> / 总量 <?= Cleaner::fmtBytes($mem['total']) ?></div>
        </div>
        <div class="srv-tile srv-tile-wide">
            <div class="srv-tile-head"><span class="srv-tile-title">磁盘分区</span></div>
            <div id="srvDisks">
                <?php if (empty($server['disks'])): ?>
                    <div class="srv-tile-sub">未检测到物理磁盘分区</div>
                <?php else: ?>
                    <?php foreach ($server['disks'] as $d): ?>
                        <div class="srv-disk-row">
                            <div class="srv-disk-mount" title="<?= Security::e($d['device']) ?>"><?= Security::e($d['mount']) ?></div>
                            <div class="hbar-track srv-track" style="margin-bottom:0;"><div class="hbar-fill" style="width:<?= $d['percent'] ?>%;background:<?= srv_bar_color($d['percent']) ?>"></div></div>
                            <div class="srv-disk-meta"><strong style="color:var(--text);"><?= $d['percent'] ?>%</strong> · <?= Cleaner::fmtBytes($d['total'] - $d['free']) ?> / <?= Cleaner::fmtBytes($d['total']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="srv-tile srv-tile-wide">
            <div class="srv-tile-head"><span class="srv-tile-title">网络流量</span><span class="srv-tile-sub">全部网卡合计（不含回环）</span></div>
            <div class="srv-net-chips">
                <span class="srv-chip">↓ 下行 <strong id="srvRx"><?= srv_rate($server['net']['rx_rate']) ?></strong></span>
                <span class="srv-chip">↑ 上行 <strong id="srvTx"><?= srv_rate($server['net']['tx_rate']) ?></strong></span>
                <span class="srv-chip">累计收 <strong id="srvRxTotal"><?= Cleaner::fmtBytes((int)$server['net']['rx_total']) ?></strong></span>
                <span class="srv-chip">累计发 <strong id="srvTxTotal"><?= Cleaner::fmtBytes((int)$server['net']['tx_total']) ?></strong></span>
            </div>
        </div>
    </div>
</div>
<script>
// 服务器状态每 5 秒轮询刷新（API 只读，登录会话内访问）
function srvColor(p) { return p < 60 ? 'var(--success)' : (p < 85 ? 'var(--warning)' : 'var(--danger)'); }
function srvFmt(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    if (b < 1099511627776) return (b / 1073741824).toFixed(1) + ' GB';
    return (b / 1099511627776).toFixed(1) + ' TB';
}
function srvRate(bps) {
    if (bps === null || bps === undefined) return '—';
    if (bps < 1024) return bps + ' B/s';
    if (bps < 1048576) return (bps / 1024).toFixed(1) + ' KB/s';
    return (bps / 1048576).toFixed(2) + ' MB/s';
}
// HTML 转义：挂载点/设备名来自系统 /proc/mounts，统一转义后再进 innerHTML
function srvEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, function(c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
}
var srvStopped = false;
function pollServerStatus() {
    if (srvStopped || document.hidden) return; // 页面切走或会话失效后停止轮询
    fetch('api/server_status.php').then(r => {
        if (r.status === 401 || r.status === 403) { srvStopped = true; }
        return r.json();
    }).then(data => {
        if (!data.success || !data.server || data.server.available !== true) return;
        const s = data.server;
        const cpuPct = s.cpu.percent === null ? 0 : s.cpu.percent;
        document.getElementById('srvCpuBar').style.width = cpuPct + '%';
        document.getElementById('srvCpuBar').style.background = srvColor(cpuPct);
        const cpuPctEl = document.getElementById('srvCpuPct');
        cpuPctEl.textContent = s.cpu.percent === null ? '—' : cpuPct + '%';
        cpuPctEl.style.color = srvColor(cpuPct);
        document.getElementById('srvCpuDetail').textContent =
            s.cpu.model + ' · ' + s.cpu.cores + ' 核 · 负载 ' + s.cpu.load[0];
        document.getElementById('srvMemBar').style.width = s.mem.percent + '%';
        document.getElementById('srvMemBar').style.background = srvColor(s.mem.percent);
        const memPctEl = document.getElementById('srvMemPct');
        memPctEl.textContent = s.mem.percent + '%';
        memPctEl.style.color = srvColor(s.mem.percent);
        document.getElementById('srvMemDetail').textContent =
            '已用 ' + srvFmt(s.mem.used) + ' / 总量 ' + srvFmt(s.mem.total);
        const diskBox = document.getElementById('srvDisks');
        if (s.disks.length) {
            diskBox.innerHTML = s.disks.map(d =>
                '<div class="srv-disk-row">' +
                '<div class="srv-disk-mount" title="' + srvEsc(d.device) + '">' + srvEsc(d.mount) + '</div>' +
                '<div class="hbar-track srv-track" style="margin-bottom:0;"><div class="hbar-fill" style="width:' + d.percent + '%;background:' + srvColor(d.percent) + '"></div></div>' +
                '<div class="srv-disk-meta"><strong style="color:var(--text);">' + d.percent + '%</strong> · ' + srvFmt(d.total - d.free) + ' / ' + srvFmt(d.total) + '</div>' +
                '</div>'
            ).join('');
        } else {
            diskBox.innerHTML = '<div class="srv-tile-sub">未检测到物理磁盘分区</div>';
        }
        document.getElementById('srvRx').textContent = srvRate(s.net.rx_rate);
        document.getElementById('srvTx').textContent = srvRate(s.net.tx_rate);
        document.getElementById('srvRxTotal').textContent = srvFmt(s.net.rx_total);
        document.getElementById('srvTxTotal').textContent = srvFmt(s.net.tx_total);
        document.getElementById('srvTime').textContent = '采样于 ' + s.time;
    }).catch(() => {});
}
setInterval(pollServerStatus, 5000);
</script>
<?php else: ?>
<div class="card" style="margin-bottom:16px;">
    <h3>服务器状态</h3>
    <p style="color:var(--text-2);font-size:14px;">当前运行环境（<?= PHP_OS_FAMILY ?>）不支持资源监测，仅支持 Linux 服务器。</p>
</div>
<?php endif; ?>

<div class="dash-grid">
    <div class="card chart-card">
        <h3>近 14 天命中趋势</h3>
        <?= dash_trend_svg($trendSeries) ?>
    </div>
    <div class="card chart-card">
        <h3>平台分布</h3>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
            <?= dash_donut_svg($byPlatform, $totalResults) ?>
            <div class="chart-legend">
                <?php foreach ($byPlatform as $p): ?>
                    <div class="legend-row">
                        <span class="legend-dot" style="background:<?= $p['color'] ?>"></span>
                        <span class="legend-name"><?= Security::e($p['name']) ?></span>
                        <span class="legend-count"><?= $p['count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="dash-grid2">
    <div class="card chart-card">
        <h3>任务状态分布</h3>
        <?php $statusTotal = max(1, array_sum(array_column($byStatus, 'count'))); ?>
        <?php foreach ($byStatus as $s): ?>
            <div class="hbar-row">
                <span class="hbar-name"><?= Security::e($s['name']) ?></span>
                <div class="hbar-track"><div class="hbar-fill" style="width:<?= round($s['count'] / $statusTotal * 100, 1) ?>%;background:<?= $s['color'] ?>"></div></div>
                <span class="hbar-count"><?= $s['count'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card chart-card">
        <h3>高频命中关键词</h3>
        <?php if (empty($topQueries)): ?>
            <p style="color:var(--text-3);font-size:13px;">暂无数据，发起搜索后这里会展示命中最多的关键词。</p>
        <?php else: ?>
            <?php foreach ($topQueries as $row): ?>
                <div class="hbar-row">
                    <span class="hbar-name" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= Security::e($row['q']) ?>"><?= Security::e(mb_substr($row['q'], 0, 24)) ?></span>
                    <div class="hbar-track"><div class="hbar-fill" style="width:<?= round((int)$row['c'] / max(1, $topMax) * 100, 1) ?>%;background:#3565e0"></div></div>
                    <span class="hbar-count"><?= (int)$row['c'] ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="margin:0;">最近任务</h3>
        <a href="tasks.php" class="btn btn-secondary" style="font-size:13px;">查看全部任务</a>
    </div>
    <?php if (empty($recentTasks)): ?>
        <p style="color:var(--text-3);font-size:14px;">暂无任务，<a href="search.php">立即搜索</a></p>
    <?php else: ?>
        <div class="table-scroll">
        <table>
            <thead>
            <tr><th>关键词</th><th>状态</th><th>命中数</th><th>创建时间</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentTasks as $t): ?>
                <tr>
                    <td><?= Security::e($t['query']) ?></td>
                    <td>
                        <?php
                        $statusMap = ['pending'=>'badge-secondary','running'=>'badge-primary','completed'=>'badge-success','failed'=>'badge-danger'];
                        ?>
                        <span class="badge <?= $statusMap[$t['status']] ?? 'badge-info' ?>"><?= Security::e($t['status']) ?></span>
                    </td>
                    <td><?= (int)$t['total_count'] ?></td>
                    <td style="white-space:nowrap;"><?= Security::e($t['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

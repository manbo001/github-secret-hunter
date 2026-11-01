<?php
/**
 * 搜索结果导出 - 支持 CSV / Excel / JSON
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Auth::requireLogin();

$userId = Auth::id();
$format = $_GET['format'] ?? 'csv';
$taskId = (int)($_GET['task_id'] ?? 0);
$platformFilter = (string)($_GET['platform'] ?? '');
$hasPlatformFilter = in_array($platformFilter, array_keys(PlatformSearch::PLATFORMS), true);

// 构建查询条件
$where = 'st.user_id = ?';
$params = [$userId];
if ($taskId > 0) {
    $where .= ' AND sr.task_id = ?';
    $params[] = $taskId;
}
if ($hasPlatformFilter) {
    $where .= ' AND sr.platform = ?';
    $params[] = $platformFilter;
}

$stmt = db()->prepare(
    "SELECT sr.id, sr.platform, sr.repo_full_name, sr.file_path, sr.html_url, sr.match_snippet, sr.score, sr.found_at, st.query
     FROM search_results sr
     JOIN search_tasks st ON sr.task_id = st.id
     WHERE {$where}
     ORDER BY sr.found_at DESC"
);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'platform_secret_results_' . date('Ymd_His');

/**
 * 防 CSV/Excel 公式注入：外部数据（仓库名、文件路径、关键词等）以 = + - @ 或制表符/回车
 * 开头时会被 Excel 当作公式执行，统一前置单引号使其按文本处理
 */
function csv_safe($value): string {
    $value = (string)$value;
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        $value = "'" . $value;
    }
    return $value;
}

switch ($format) {
    case 'json':
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'excel':
        // 生成 Excel 可识别的 HTML 表格格式（.xls）
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo "\xEF\xBB\xBF"; // BOM for UTF-8
        echo '<table border="1">';
        echo '<tr><th>ID</th><th>平台</th><th>搜索关键词</th><th>仓库</th><th>文件路径</th><th>URL</th><th>命中片段</th><th>评分</th><th>发现时间</th></tr>';
        foreach ($results as $r) {
            echo '<tr>';
            echo '<td>' . (int)$r['id'] . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe(PlatformSearch::label($r['platform'] ?? 'github')), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['query']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['repo_full_name']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['file_path']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['html_url']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['match_snippet'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe((string)($r['score'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars(csv_safe($r['found_at']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        break;

    case 'csv':
    default:
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM 确保 Excel 正确识别 UTF-8
        $out = fopen('php://output', 'w');
        // 显式 escape 参数（PHP 8.4 起省略已弃用）
        fputcsv($out, ['ID', '平台', '搜索关键词', '仓库', '文件路径', 'URL', '命中片段', '评分', '发现时间'], ',', '"', '\\');
        foreach ($results as $r) {
            fputcsv($out, [
                $r['id'],
                PlatformSearch::label($r['platform'] ?? 'github'),
                csv_safe($r['query']),
                csv_safe($r['repo_full_name']),
                csv_safe($r['file_path']),
                csv_safe($r['html_url']),
                csv_safe($r['match_snippet'] ?? ''),
                $r['score'] ?? '',
                $r['found_at'],
            ], ',', '"', '\\');
        }
        fclose($out);
        break;
}
exit;

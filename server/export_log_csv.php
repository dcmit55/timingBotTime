<?php
require 'db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=production_log.csv');
echo "\xEF\xBB\xBF";

$from = isset($_GET['from']) && $_GET['from']!=='' ? $_GET['from'] : date('Y-m-d');
$to   = isset($_GET['to'])   && $_GET['to']!==''   ? $_GET['to']   : date('Y-m-d');

$fp = fopen('php://output', 'w');
fputcsv($fp, ['Datetime','Action','Operator ID','Code','Name','Project','Amount','Count Before','Total After']);

$sql = "SELECT l.created_at AS dt, 'hit' AS action, o.id, o.code, o.name, o.project, l.amount, NULL AS count_before, l.total_after
        FROM operator_hit_log l
        JOIN operators o ON o.id=l.operator_id
        WHERE DATE(l.created_at) BETWEEN :from AND :to
        UNION ALL
        SELECT r.created_at AS dt, 'reset' AS action, o.id, o.code, o.name, o.project, NULL AS amount, r.count_before, 0 AS total_after
        FROM operator_reset_log r
        JOIN operators o ON o.id=r.operator_id
        WHERE DATE(r.created_at) BETWEEN :from AND :to
        ORDER BY dt ASC";
$st = $pdo->prepare($sql);
$st->execute([':from'=>$from, ':to'=>$to]);
while ($r = $st->fetch(PDO::FETCH_NUM)) { fputcsv($fp, $r); }
fclose($fp);
exit;

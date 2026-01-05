<?php
require 'db.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=production_today.csv');
echo "\xEF\xBB\xBF";

$fp = fopen('php://output', 'w');
fputcsv($fp, ['Date','Project','Department','Step','Part','employee','start','end','qty','Status','Remarks']);

$sql = "SELECT 
          CURDATE() AS date,
          o.project,
          COALESCE(o.department, 'costume') AS department,
          COALESCE(c.step, 'Counting') AS step,
          COALESCE(c.part, '') AS part,
          COALESCE(NULLIF(o.name, ''), o.code) AS employee,
          c.first_hit AS start_time,
          c.last_hit AS end_time,
          COALESCE(c.count, 0) AS qty,
          COALESCE(c.status, CASE WHEN COALESCE(c.count,0) > 0 THEN 'complete' ELSE 'pending' END) AS status,
          COALESCE(c.remarks, '') AS remarks
        FROM operators o
        LEFT JOIN operator_counters c
          ON c.operator_id=o.id AND c.counter_date=CURDATE()
        WHERE o.is_active=1
        ORDER BY o.id";
        
foreach ($pdo->query($sql) as $r) {
  // Format time HH:MM
  $start = $r['start_time'] ? date('H:i', strtotime($r['start_time'])) : '';
  $end = $r['end_time'] ? date('H:i', strtotime($r['end_time'])) : '';
  
  fputcsv($fp, [
    $r['date'],
    $r['project'],
    $r['department'],
    $r['step'],
    $r['part'],
    $r['employee'],
    $start,
    $end,
    $r['qty'],
    $r['status'],
    $r['remarks']
  ]);
}
fclose($fp);
exit;
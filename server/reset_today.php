<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require 'db.php';

$pdo->beginTransaction();
try {
  // Log semua yang >0 dulu
  $sql = "INSERT INTO operator_reset_log (operator_id, count_before, note)
          SELECT operator_id, `count`, 'bulk-reset'
          FROM operator_counters
          WHERE counter_date=CURDATE() AND `count`>0";
  $pdo->exec($sql);

  // Baru reset ke 0
  $pdo->exec("
    INSERT INTO operator_counters (counter_date, operator_id, `count`, last_hit, first_hit)
    SELECT CURDATE(), id, 0, NULL, NULL FROM operators WHERE is_active=1
    ON DUPLICATE KEY UPDATE `count`=0, last_hit=NULL, first_hit=NULL
  ");

  $pdo->commit();
  echo json_encode(['status'=>'ok']);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

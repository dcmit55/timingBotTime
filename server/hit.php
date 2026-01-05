<?php
header('Content-Type: application/json');
require 'db.php';

$body = json_decode(file_get_contents('php://input'), true);
$op  = isset($body['operator_id']) ? (int)$body['operator_id'] : 0;
$amt = isset($body['amount'])      ? (int)$body['amount']      : 1;

if ($op <= 0 || $amt <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'bad payload']);
  exit;
}

try {
  $pdo->beginTransaction();

  // 1) Upsert baris harian (pakai counter_date + UNIQUE KEY(counter_date, operator_id))
  $sql = "INSERT INTO operator_counters (counter_date, operator_id, `count`, first_hit, last_hit)
          VALUES (CURDATE(), :op, :amt, NOW(), NOW())
          ON DUPLICATE KEY UPDATE
            `count`   = `count` + VALUES(`count`),
            last_hit  = NOW(),
            first_hit = IFNULL(first_hit, VALUES(first_hit))";
  $st = $pdo->prepare($sql);
  $st->execute([':op'=>$op, ':amt'=>$amt]);

  // 2) Ambil total setelah update
  $st = $pdo->prepare("SELECT `count` FROM operator_counters
                       WHERE operator_id = :op AND counter_date = CURDATE()");
  $st->execute([':op'=>$op]);
  $total_after = (int)$st->fetchColumn();

  // 3) Tulis log hit DENGAN total_after
  $st = $pdo->prepare("INSERT INTO operator_hit_log (operator_id, amount, total_after, created_at)
                       VALUES (:op, :amt, :ta, NOW())");
  $st->execute([':op'=>$op, ':amt'=>$amt, ':ta'=>$total_after]);

  // 4) Sentuh last_change (untuk auto-refresh dashboard)
  $pdo->exec("INSERT INTO app_kv (k, v, updated_at)
              VALUES ('last_change', UNIX_TIMESTAMP(), NOW())
              ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)");

  $pdo->commit();
  echo json_encode(['status'=>'ok','total_after'=>$total_after]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

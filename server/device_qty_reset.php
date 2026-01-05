<?php
// Reset khusus alat:
// - Qty di web jadi 0 (operator_counters.count = 0)
// - Tidak membuat context baru (tidak insert operator_context_log)
// - Menyimpan marker reset + ctx_id supaya Export Input Timing bisa hitung ulang dari 0 tanpa membuat baris baru

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $in = json_decode(file_get_contents('php://input'), true);
  if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
  }

  $op = isset($in['operator_id']) ? (int)$in['operator_id'] : 0;
  if ($op <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing operator_id']);
    exit;
  }

  $note = isset($in['note']) ? trim((string)$in['note']) : 'device reset';

  $pdo->beginTransaction();

  // Ambil ctx terbaru hari ini (untuk marker reset)
  $st = $pdo->prepare("
    SELECT id
    FROM operator_context_log
    WHERE operator_id = ?
      AND DATE(created_at) = CURDATE()
    ORDER BY created_at DESC
    LIMIT 1
  ");
  $st->execute([$op]);
  $ctx_id = (int)($st->fetchColumn() ?? 0);

  // Ambil qty sebelum reset
  $st = $pdo->prepare("
    SELECT `count`
    FROM operator_counters
    WHERE operator_id = ? AND counter_date = CURDATE()
    LIMIT 1
  ");
  $st->execute([$op]);
  $count_before = (int)($st->fetchColumn() ?? 0);

  // Reset counter harian jadi 0 (web ikut 0)
  $st = $pdo->prepare("
    INSERT INTO operator_counters (counter_date, operator_id, `count`, first_hit, last_hit)
    VALUES (CURDATE(), ?, 0, NULL, NULL)
    ON DUPLICATE KEY UPDATE
      `count` = 0,
      first_hit = NULL,
      last_hit = NULL
  ");
  $st->execute([$op]);

  // Simpan marker reset (tanpa bikin ctx baru)
  $st = $pdo->prepare("
    INSERT INTO operator_device_reset_log (operator_id, ctx_id, note, created_at)
    VALUES (?, ?, ?, NOW())
  ");
  $st->execute([$op, $ctx_id, $note]);

  // Trigger refresh UI/SSE
  $pdo->exec("
    INSERT INTO app_kv (k, v)
    VALUES ('last_change', UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE v = UNIX_TIMESTAMP()
  ");

  $pdo->commit();

  echo json_encode([
    'status' => 'ok',
    'operator_id' => $op,
    'ctx_id' => $ctx_id,
    'count_before' => $count_before
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

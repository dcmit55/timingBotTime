<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $today = date('Y-m-d');

  $sql = "
    SELECT
      o.id,
      o.code,
      o.name,
      o.project,
      o.department,
      IFNULL(c.`count`, 0) AS `count`,     -- ⬅️ perbaikan di sini
      c.first_hit,
      c.last_hit,
      c.step,
      c.part,
      c.status,
      c.remarks
    FROM operators o
    LEFT JOIN (
      SELECT x.*
      FROM operator_counters x
      JOIN (
        SELECT operator_id, MAX(id) AS max_id
        FROM operator_counters
        WHERE counter_date = :d
        GROUP BY operator_id
      ) m ON m.max_id = x.id
    ) c ON c.operator_id = o.id
    WHERE o.is_active = 1
    ORDER BY o.id
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':d' => $today]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}

<?php
require_once __DIR__ . '/db.php';

/**
 * Work-log rows for a date, segmented by context changes (ctx.id).
 * Reset dari alat:
 * - Tidak membuat ctx baru
 * - Export hanya menghitung qty setelah reset terakhir di ctx yang sama
 */
function getInputTimingRows(PDO $pdo, ?string $date = null): array {
  if (!$date) {
    $date = date('Y-m-d');
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
  }

  $sql = "
    SELECT
      DATE(l.created_at) AS date,
      o.id               AS operator_id,
      o.name             AS employee,

      COALESCE(NULLIF(ctx.project,    ''), NULLIF(o.project,    '')) AS project,
      COALESCE(NULLIF(ctx.department, ''), NULLIF(o.department, '')) AS department,
      COALESCE(NULLIF(ctx.step,       ''), NULLIF(oc.step,      '')) AS step,
      COALESCE(NULLIF(ctx.part,       ''), NULLIF(oc.part,      '')) AS part,

      -- Hitung setelah reset alat terakhir pada ctx yang sama
      COALESCE(
        MIN(CASE WHEN l.created_at >= COALESCE(dr.reset_at, '1970-01-01 00:00:00') THEN l.created_at END),
        dr.reset_at
      ) AS start_time,

      COALESCE(
        MAX(CASE WHEN l.created_at >= COALESCE(dr.reset_at, '1970-01-01 00:00:00') THEN l.created_at END),
        dr.reset_at
      ) AS end_time,

      SUM(CASE WHEN l.created_at >= COALESCE(dr.reset_at, '1970-01-01 00:00:00') THEN l.amount ELSE 0 END) AS qty,

      COALESCE(NULLIF(ctx.status,  ''), NULLIF(oc.status,  '')) AS status,
      COALESCE(NULLIF(ctx.remarks, ''), NULLIF(oc.remarks, '')) AS remarks,

      COALESCE(ctx.id, 0) AS ctx_id
    FROM operator_hit_log l
    JOIN operators o ON o.id = l.operator_id

    LEFT JOIN operator_counters oc
      ON oc.operator_id = l.operator_id
     AND oc.counter_date = DATE(l.created_at)

    LEFT JOIN operator_context_log ctx
      ON ctx.id = (
        SELECT c.id
        FROM operator_context_log c
        WHERE c.operator_id = l.operator_id
          AND DATE(c.created_at) = DATE(l.created_at)
          AND c.created_at <= l.created_at
        ORDER BY c.created_at DESC
        LIMIT 1
      )

    LEFT JOIN (
      SELECT
        operator_id,
        ctx_id,
        DATE(created_at) AS d,
        MAX(created_at)  AS reset_at
      FROM operator_device_reset_log
      GROUP BY operator_id, ctx_id, DATE(created_at)
    ) dr
      ON dr.operator_id = l.operator_id
     AND dr.d = DATE(l.created_at)
     AND dr.ctx_id = COALESCE(ctx.id, 0)

    WHERE DATE(l.created_at) = :d
    GROUP BY
      DATE(l.created_at),
      o.id,
      COALESCE(ctx.id, 0),
      o.name,
      COALESCE(NULLIF(ctx.project,    ''), NULLIF(o.project,    '')),
      COALESCE(NULLIF(ctx.department, ''), NULLIF(o.department, '')),
      COALESCE(NULLIF(ctx.step,       ''), NULLIF(oc.step,      '')),
      COALESCE(NULLIF(ctx.part,       ''), NULLIF(oc.part,      '')),
      COALESCE(NULLIF(ctx.status,     ''), NULLIF(oc.status,    '')),
      COALESCE(NULLIF(ctx.remarks,    ''), NULLIF(oc.remarks,   ''))
    ORDER BY
      o.id ASC,
      MIN(l.created_at) ASC,
      COALESCE(ctx.id, 0) ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([':d' => $date]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['date']        = (string)($r['date'] ?? '');
    $r['operator_id'] = (int)($r['operator_id'] ?? 0);
    $r['employee']    = (string)($r['employee'] ?? '');

    $r['project']     = (string)($r['project'] ?? '');
    $r['department']  = (string)($r['department'] ?? '');
    $r['step']        = (string)($r['step'] ?? '');
    $r['part']        = (string)($r['part'] ?? '');

    $r['start_time']  = $r['start_time'] ? date('H:i', strtotime($r['start_time'])) : '';
    $r['end_time']    = $r['end_time']   ? date('H:i', strtotime($r['end_time']))   : '';
    $r['qty']         = (int)($r['qty'] ?? 0);

    $r['status']      = (string)($r['status'] ?? '');
    $r['remarks']     = (string)($r['remarks'] ?? '');

    unset($r['ctx_id']); // internal
  }
  unset($r);

  return $rows;
}

function inputTimingHeader(): array {
  return ['Date','Project','Department','Step','Part','Employee','Start','End','Qty','Status','Remarks'];
}

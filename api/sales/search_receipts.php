<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'DB not available']);
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
  echo json_encode(['success'=>true,'results'=>[]]);
  exit;
}

$limit = 10;
$like = '%' . $q . '%';

$hasCustomers = ($db->query("SHOW TABLES LIKE 'customers'")?->num_rows ?? 0) > 0;

$customerJoin = $hasCustomers ? " LEFT JOIN customers c ON c.id = s.customer_id " : "";
$customerSelect = $hasCustomers ? ", c.name AS customer_name" : ", NULL AS customer_name";

$sql = "
  SELECT
    s.id,
    s.doc_no,
    s.grand_total,
    s.created_at
    $customerSelect
  FROM sales s
  $customerJoin
  WHERE (s.doc_no LIKE ? " . ($hasCustomers ? " OR c.name LIKE ? " : "") . ")
  ORDER BY s.id DESC
  LIMIT ?
";

$st = $db->prepare($sql);
if (!$st) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$db->error]);
  exit;
}

if ($hasCustomers) {
  $st->bind_param("ssi", $like, $like, $limit);
} else {
  $st->bind_param("si", $like, $limit);
}

$st->execute();
$rs = $st->get_result();

$out = [];
while ($row = $rs->fetch_assoc()) $out[] = $row;

$st->close();

echo json_encode(['success'=>true,'results'=>$out]);
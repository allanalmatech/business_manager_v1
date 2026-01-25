<?php
// includes/pos_helpers.php
declare(strict_types=1);

function money(float $n): string {
  return number_format($n, 2, '.', ',');
}

/**
 * Convert user-entered qty to base qty (pieces).
 * Supports: piece/unit => 1:1, box/carton => *pieces_per_box, dozen => *12, pair => *2
 */
function qty_to_base(string $unitType, float $qtyInput, int $piecesPerBox = 0): int {
  $u = strtolower(trim($unitType));
  if ($qtyInput <= 0) return 0;

  if (in_array($u, ['box','carton','cartons','boxes'], true)) {
    $ppb = max(1, (int)$piecesPerBox);
    return (int) round($qtyInput * $ppb);
  }
  if (in_array($u, ['dozen','dozens'], true)) {
    return (int) round($qtyInput * 12);
  }
  if (in_array($u, ['pair','pairs'], true)) {
    return (int) round($qtyInput * 2);
  }
  // piece/unit/kg/liter etc treated as base
  return (int) round($qtyInput);
}

/**
 * Fetch product with pricing + unit data.
 * Expects products columns:
 * id, sku, name, wholesale_price, retail_price, unit_type, pieces_per_box, is_active
 */
function pos_get_product(mysqli $db, int $productId): ?array {
  $sql = "SELECT id, sku, name, wholesale_price, retail_price, unit_type, pieces_per_box, is_active
          FROM products
          WHERE id = ? LIMIT 1";
  $st = $db->prepare($sql);
  $st->bind_param("i", $productId);
  $st->execute();
  $res = $st->get_result();
  $row = $res->fetch_assoc();
  $st->close();
  if (!$row) return null;
  return $row;
}

/**
 * Get stock for product at a location (cached).
 * Expects stock_by_location columns: location_id, product_id, qty_base
 */
function pos_get_stock(mysqli $db, int $locationId, int $productId): int {
  $sql = "SELECT qty_base FROM stock_by_location WHERE location_id=? AND product_id=? LIMIT 1";
  $st = $db->prepare($sql);
  $st->bind_param("ii", $locationId, $productId);
  $st->execute();
  $res = $st->get_result();
  $row = $res->fetch_assoc();
  $st->close();
  return (int)($row['qty_base'] ?? 0);
}

/**
 * Update stock_by_location (delta).
 * Creates row if missing.
 */
function pos_apply_stock_delta(mysqli $db, int $locationId, int $productId, int $delta): void {
  // Upsert
  $sql = "INSERT INTO stock_by_location (location_id, product_id, qty_base)
          VALUES (?, ?, GREATEST(0, ?))
          ON DUPLICATE KEY UPDATE qty_base = GREATEST(0, qty_base + VALUES(qty_base) - 0)";
  // NOTE: VALUES(qty_base) used as delta-holder trick isn't safe for delta.
  // We'll do safer two-step for correctness:
  $check = $db->prepare("SELECT qty_base FROM stock_by_location WHERE location_id=? AND product_id=? LIMIT 1");
  $check->bind_param("ii", $locationId, $productId);
  $check->execute();
  $r = $check->get_result()->fetch_assoc();
  $check->close();

  if (!$r) {
    $newQty = max(0, $delta);
    $ins = $db->prepare("INSERT INTO stock_by_location (location_id, product_id, qty_base) VALUES (?,?,?)");
    $ins->bind_param("iii", $locationId, $productId, $newQty);
    $ins->execute();
    $ins->close();
    return;
  }

  $before = (int)$r['qty_base'];
  $after  = max(0, $before + $delta);

  $up = $db->prepare("UPDATE stock_by_location SET qty_base=? WHERE location_id=? AND product_id=?");
  $up->bind_param("iii", $after, $locationId, $productId);
  $up->execute();
  $up->close();
}

/**
 * Stock movements insert (sale) using your ledger table.
 * Expected stock_movements columns (typical):
 * id, product_id, type, location_id, from_location_id, to_location_id,
 * qty_change, before_qty, after_qty, reference_type, reference_id,
 * note, created_by, created_at
 *
 * If your column names differ, adjust here only.
 */
function pos_insert_stock_movement_sale(
  mysqli $db,
  int $productId,
  int $locationId,
  int $qtyBaseSold,
  int $beforeQty,
  int $afterQty,
  string $referenceType,
  int $referenceId,
  int $userId
): void {
  $type = 'sale';
  $qtyChange = -abs($qtyBaseSold);
  $note = 'POS sale';

  $sql = "INSERT INTO stock_movements
    (product_id, type, location_id, from_location_id, to_location_id,
     qty_change, before_qty, after_qty,
     reference_type, reference_id, note, created_by, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())";

  $null = null;
  $st = $db->prepare($sql);
  $st->bind_param(
    "isiiiiiisiisi",
    $productId,
    $type,
    $locationId,
    $locationId,
    $null,
    $qtyChange,
    $beforeQty,
    $afterQty,
    $referenceType,
    $referenceId,
    $note,
    $userId
  );
  $st->execute();
  $st->close();
}

/**
 * Audit trail hook.
 * If you already have audit helpers, replace this implementation.
 */
function pos_audit(mysqli $db, int $userId, string $action, string $metaJson = '{}'): void {
  // Expected audit_trail columns: id, user_id, action, meta, ip, created_at
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $sql = "INSERT INTO audit_trail (user_id, action, meta, ip, created_at)
          VALUES (?,?,?,?,NOW())";
  $st = $db->prepare($sql);
  $st->bind_param("isss", $userId, $action, $metaJson, $ip);
  $st->execute();
  $st->close();
}

/**
 * Generate next doc_no safely with row lock.
 */
function pos_next_doc_no(mysqli $db, string $docType): string {
  $year = (int)date('Y');
  $prefix = match ($docType) {
    'invoice' => 'INV',
    'delivery_note' => 'DN',
    default => 'RC',
  };

  // Ensure row exists
  $ins = $db->prepare("INSERT IGNORE INTO doc_sequences (doc_type, year, prefix, current_no) VALUES (?,?,?,0)");
  $ins->bind_param("sis", $docType, $year, $prefix);
  $ins->execute();
  $ins->close();

  // Lock row and increment
  $st = $db->prepare("SELECT id, current_no, prefix FROM doc_sequences WHERE doc_type=? AND year=? FOR UPDATE");
  $st->bind_param("si", $docType, $year);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  $id = (int)$row['id'];
  $no = (int)$row['current_no'] + 1;
  $p  = (string)$row['prefix'];

  $up = $db->prepare("UPDATE doc_sequences SET current_no=? WHERE id=?");
  $up->bind_param("ii", $no, $id);
  $up->execute();
  $up->close();

  return sprintf("%s-%d-%06d", $p, $year, $no);
}

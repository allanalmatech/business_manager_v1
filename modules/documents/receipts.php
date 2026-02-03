<?php
// modules/documents/receipts.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('pos.view'); // adjust if your permission name differs

$db = $GLOBALS['db'] ?? null;
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = "Receipts";
$page_subtitle = "View, print, and export POS receipts";
function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

// Layout
require_once __DIR__ . '/../../templates/layout/header.php';
?>

<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">

        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
          <div>
            <h4 class="mb-1"><?= h($page_title) ?></h4>
            <div class="text-muted small"><?= h($page_subtitle) ?></div>
          </div>
        </div>
      <?php
        // Filters
        $q     = trim((string)($_GET['q'] ?? ''));
        $from  = trim((string)($_GET['from'] ?? ''));
        $to    = trim((string)($_GET['to'] ?? ''));
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 15;
        $off   = ($page - 1) * $limit;

        $hasCustomers = table_exists($db, 'customers');

        $where = "1=1";
        $params = [];
        $types = "";

        if ($q !== '') {
          $where .= " AND (s.doc_no LIKE ? " . ($hasCustomers ? " OR c.name LIKE ? " : "") . ")";
          $like = '%' . $q . '%';
          $params[] = $like;
          $types .= "s";
          if ($hasCustomers) {
            $params[] = $like;
            $types .= "s";
          }
        }

        if ($from !== '') {
          $where .= " AND DATE(s.created_at) >= ?";
          $params[] = $from;
          $types .= "s";
        }
        if ($to !== '') {
          $where .= " AND DATE(s.created_at) <= ?";
          $params[] = $to;
          $types .= "s";
        }

        $join = $hasCustomers ? " LEFT JOIN customers c ON c.id = s.customer_id " : "";
        $customerSelect = $hasCustomers ? "c.name AS customer_name," : "NULL AS customer_name,";

        // Count
        $countSql = "SELECT COUNT(*) AS cnt
                    FROM sales s
                    $join
                    WHERE $where";
        $stc = $db->prepare($countSql);
        if (!$stc) {
          echo '<div class="alert alert-danger">Query error: '.h($db->error).'</div>';
          $total = 0;
        } else {
          if ($types !== '') $stc->bind_param($types, ...$params);
          $stc->execute();
          $total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
          $stc->close();
        }

        $pages = max(1, (int)ceil($total / $limit));

        // List
        $sql = "SELECT
                  s.id,
                  s.doc_no,
                  s.grand_total,
                  s.created_at,
                  $customerSelect
                  s.payment_status
                FROM sales s
                $join
                WHERE $where
                ORDER BY s.id DESC
                LIMIT ? OFFSET ?";

        $st = $db->prepare($sql);
        if (!$st) {
          echo '<div class="alert alert-danger">Query error: '.h($db->error).'</div>';
          $rows = [];
        } else {
          $bindTypes = $types . "ii";
          $bindParams = $params;
          $bindParams[] = $limit;
          $bindParams[] = $off;

          $st->bind_param($bindTypes, ...$bindParams);
          $st->execute();
          $rs = $st->get_result();
          $rows = [];
          while ($r = $rs->fetch_assoc()) $rows[] = $r;
          $st->close();
        }

        // Helper for pagination links
        $qsBase = $_GET;
        unset($qsBase['page']);
        $qsBaseStr = http_build_query($qsBase);
        $qsBaseStr = $qsBaseStr ? $qsBaseStr . '&' : '';
      ?>

      <div class="card shadow-sm">
        <div class="card-body">

          <form class="row g-2 mb-3" method="get" action="">
            <div class="col-md-5">
              <input type="text" class="form-control" name="q"
                     value="<?= h($q) ?>"
                     placeholder="Search receipt no or customer...">
            </div>
            <div class="col-md-3">
              <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-md-3">
              <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-md-1 d-grid">
              <button class="btn btn-primary" type="submit">Go</button>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Receipt No</th>
                  <th>Customer</th>
                  <th class="text-end">Total</th>
                  <th>Payment Status</th>
                  <th>Date</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr>
                    <td colspan="7" class="text-muted text-center py-4">
                      No receipts found.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <?php
                      $id = (int)$r['id'];
                      $docNo = (string)$r['doc_no'];
                      $cust = (string)($r['customer_name'] ?? '');
                      $totalAmt = (float)($r['grand_total'] ?? 0);
                      $pay = (string)($r['payment_status'] ?? '');
                      $dt = (string)($r['created_at'] ?? '');
                      $viewUrl  = $BASE_URL . "/modules/pos/pos_preview.php?id=" . $id;
                      
                      // Style payment status with badges
                      $payStatusBadge = '';
                      switch(strtolower($pay)) {
                        case 'paid':
                          $payStatusBadge = '<span class="badge bg-success">Paid</span>';
                          break;
                        case 'partial':
                          $payStatusBadge = '<span class="badge bg-warning">Partial</span>';
                          break;
                        case 'unpaid':
                          $payStatusBadge = '<span class="badge bg-danger">Unpaid</span>';
                          break;
                        default:
                          $payStatusBadge = '<span class="badge bg-secondary">' . h($pay ?: '—') . '</span>';
                      }
                    ?>
                    <tr>
                      <td><?= $id ?></td>
                      <td class="fw-semibold"><?= h($docNo) ?></td>
                      <td><?= h($cust ?: '—') ?></td>
                      <td class="text-end"><?= number_format($totalAmt, 0) ?></td>
                      <td><?= $payStatusBadge ?></td>
                      <td><?= h($dt) ?></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= h($viewUrl) ?>" target="_blank">View</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">
              Showing <?= count($rows) ?> of <?= (int)$total ?> receipts
            </div>

            <nav>
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="?<?= h($qsBaseStr) ?>page=<?= max(1, $page-1) ?>">Prev</a>
                </li>

                <?php
                  $start = max(1, $page - 2);
                  $end = min($pages, $page + 2);
                  for ($p = $start; $p <= $end; $p++):
                ?>
                  <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= h($qsBaseStr) ?>page=<?= $p ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>

                <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                  <a class="page-link" href="?<?= h($qsBaseStr) ?>page=<?= min($pages, $page+1) ?>">Next</a>
                </li>
              </ul>
            </nav>
          </div>

        </div>
      </div>

      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>
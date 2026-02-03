<?php
// modules/reports/installments.php
declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('reports.view');

$db = $GLOBALS['db'] ?? null;
function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}

$page_title = "Installments Report";
$page_subtitle = "Overview of installment schedules and payments";

require_once dirname(dirname(__DIR__)) . '/templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once dirname(dirname(__DIR__)) . '/templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-3">

        <?php
        if (!$db instanceof mysqli) {
          echo '<div class="alert alert-danger">Database not available.</div>';
        } else {
          if (!table_exists($db, 'installments')) {
            echo '<div class="alert alert-warning"><b>installments</b> table not found.</div>';
            require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
          }

          // filters
          $q = trim((string)($_GET['q'] ?? ''));
          $from = trim((string)($_GET['from'] ?? ''));
          $to = trim((string)($_GET['to'] ?? ''));
          $status = trim((string)($_GET['status'] ?? ''));
          $contact = trim((string)($_GET['contact'] ?? ''));
          $overdue = trim((string)($_GET['overdue'] ?? ''));
          $page = max(1, (int)($_GET['page'] ?? 1));
          $limit = 20;
          $off = ($page - 1) * $limit;

          $where = '1=1';
          $params = [];
          $types = '';

          if ($q !== '') {
            $where .= " AND (i.reference LIKE ? OR i.notes LIKE ? )";
            $like = "%$q%";
            $params = array_merge($params, [$like, $like]);
            $types .= 'ss';
          }
          if ($from !== '') { $where .= " AND DATE(i.created_at) >= ?"; $params[] = $from; $types .= 's'; }
          if ($to !== '')   { $where .= " AND DATE(i.created_at) <= ?"; $params[] = $to;   $types .= 's'; }
          if ($status !== '') { $where .= " AND i.status = ?"; $params[] = $status; $types .= 's'; }
          if ($contact !== '') { $where .= " AND (c.name LIKE ? OR c.id = ?)"; $params[] = "%$contact%"; $params[] = $contact; $types .= 'si'; }
          if ($overdue === '1') { $where .= " AND i.due_date < CURDATE() AND i.status != 'completed'"; }

          // count
          $stc = $db->prepare("SELECT COUNT(*) cnt FROM installments i LEFT JOIN customers c ON c.id = i.contact_id WHERE $where");
          if ($types !== '') $stc->bind_param($types, ...$params);
          $stc->execute();
          $total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
          $stc->close();
          $pages = max(1, (int)ceil($total / $limit));

          // select rows with contact and user
          $sql = "SELECT i.id, i.contact_id, COALESCE(c.name, '') AS contact_name, i.reference, i.amount_due, i.amount_paid, i.due_date, i.status, i.created_at, i.user_id, COALESCE(u.full_name,'') AS user_name
                    FROM installments i
                    LEFT JOIN customers c ON c.id = i.contact_id
                    LEFT JOIN users u ON u.id = i.user_id
                    WHERE $where
                    ORDER BY i.due_date ASC, i.created_at DESC
                    LIMIT ? OFFSET ?";

          $st = $db->prepare($sql);
          $bindTypes = $types . 'ii';
          $bind = $params; $bind[] = $limit; $bind[] = $off;
          if ($types !== '') {
            $st->bind_param($bindTypes, ...$bind);
          } else {
            $st->bind_param('ii', $limit, $off);
          }
          $st->execute();
          $rs = $st->get_result();
          $rows = [];
          while ($r = $rs->fetch_assoc()) $rows[] = $r;
          $st->close();

          // totals
          $totSql = "SELECT
                        SUM(i.amount_due) AS total_due,
                        SUM(i.amount_paid) AS total_paid
                      FROM installments i
                      LEFT JOIN customers c ON c.id = i.contact_id
                      WHERE $where";
          $totSt = $db->prepare($totSql);
          if ($types !== '') $totSt->bind_param($types, ...$params);
          $totSt->execute();
          $tot = $totSt->get_result()->fetch_assoc() ?? ['total_due' => 0, 'total_paid' => 0];
          $totSt->close();

          // CSV export
          if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="installments_report.csv"');
            $out = fopen('php://output','w');
            fputcsv($out, ['ID','Contact','Reference','Amount Due','Amount Paid','Due Date','Status','Created At','User']);
            foreach ($rows as $r) {
              fputcsv($out, [
                $r['id'], $r['contact_name'], $r['reference'], $r['amount_due'], $r['amount_paid'], $r['due_date'], $r['status'], $r['created_at'], $r['user_name']
              ]);
            }
            fclose($out);
            exit;
          }
        ?>

        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <form class="row g-2" method="get">
              <div class="col-md-3">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search reference/notes...">
              </div>
              <div class="col-md-2">
                <input class="form-control" type="date" name="from" value="<?= h($from) ?>">
              </div>
              <div class="col-md-2">
                <input class="form-control" type="date" name="to" value="<?= h($to) ?>">
              </div>
              <div class="col-md-2">
                <select class="form-select" name="status">
                  <option value="">All statuses</option>
                  <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
                  <option value="due_soon" <?= $status==='due_soon'?'selected':'' ?>>Due Soon</option>
                  <option value="overdue" <?= $status==='overdue'?'selected':'' ?>>Overdue</option>
                  <option value="completed" <?= $status==='completed'?'selected':'' ?>>Completed</option>
                </select>
              </div>
              <div class="col-md-2">
                <input class="form-control" name="contact" value="<?= h($contact) ?>" placeholder="Contact name or ID">
              </div>
              <div class="col-md-1 d-grid">
                <button class="btn btn-primary">Filter</button>
              </div>
            </form>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" value="1" id="overdue" name="overdue" <?= $overdue==='1'?'checked':'' ?> onchange="this.form.submit()">
              <label class="form-check-label" for="overdue">Only overdue</label>
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Total Due</h6>
                <div class="fs-4 fw-bold text-primary"><?= number_format((float)($tot['total_due'] ?? 0),2) ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Total Paid</h6>
                <div class="fs-4 fw-bold text-success"><?= number_format((float)($tot['total_paid'] ?? 0),2) ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Outstanding</h6>
                <div class="fs-4 fw-bold <?= ((float)$tot['total_due'] - (float)$tot['total_paid'])>=0 ? 'text-danger':'text-success' ?>">
                  <?= number_format(((float)$tot['total_due'] - (float)$tot['total_paid']),2) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h5 class="mb-0">Installments</h5>
              <div>
                <a class="btn btn-outline-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">Export CSV</a>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Contact</th>
                    <th>Reference</th>
                    <th>Amount Due</th>
                    <th>Amount Paid</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>User</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No installments found.</td></tr>
                  <?php else: foreach ($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= h((string)$r['contact_name']) ?></td>
                      <td><?= h((string)$r['reference']) ?></td>
                      <td class="fw-semibold"><?= number_format((float)$r['amount_due'],2) ?></td>
                      <td><?= number_format((float)$r['amount_paid'],2) ?></td>
                      <td><?= h((string)$r['due_date']) ?></td>
                      <td><?= h((string)$r['status']) ?></td>
                      <td><?= h((string)$r['user_name']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
              <div class="small text-muted">Showing <?= count($rows) ?> of <?= $total ?></div>
              <nav>
                <ul class="pagination pagination-sm mb-0">
                  <li class="page-item <?= $page<=1?'disabled':'' ?>">
                    <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>max(1,$page-1)]))) ?>">Prev</a>
                  </li>
                  <?php for($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
                    <li class="page-item <?= $p===$page?'active':'' ?>">
                      <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>$p]))) ?>"><?=$p?></a>
                    </li>
                  <?php endfor; ?>
                  <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                    <a class="page-link" href="?<?= h(http_build_query(array_merge($_GET, ['page'=>min($pages,$page+1)]))) ?>">Next</a>
                  </li>
                </ul>
              </nav>
            </div>

          </div>
        </div>

        <?php } // db check ?>

      </div>
    </main>
  </div>
</div>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php';

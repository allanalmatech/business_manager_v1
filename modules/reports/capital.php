<?php
// modules/reports/capital.php
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

$page_title = "Capital Report";
$page_subtitle = "Track capital inflows and outflows";

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
          // finance table expected: id, type(IN/OUT), amount, method, reference, notes, created_at, user_id
          if (!table_exists($db,'finance')) {
            echo '<div class="alert alert-warning"><b>finance</b> table not found.</div>';
            require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
          }

          $q = trim((string)($_GET['q'] ?? ''));
          $from = trim((string)($_GET['from'] ?? ''));
          $to = trim((string)($_GET['to'] ?? ''));
          $type = trim((string)($_GET['type'] ?? ''));
          $page = max(1,(int)($_GET['page'] ?? 1));
          $limit = 20;
          $off = ($page-1)*$limit;

          $where = "1=1";
          $params = [];
          $types = "";

          if ($q !== '') {
            $where .= " AND (reference LIKE ? OR notes LIKE ? OR method LIKE ? )";
            $like = "%$q%";
            $params = array_merge($params, [$like, $like, $like]);
            $types .= "sss";
          }
          if ($from !== '') { $where .= " AND DATE(created_at) >= ?"; $params[] = $from; $types .= "s"; }
          if ($to !== '') { $where .= " AND DATE(created_at) <= ?"; $params[] = $to; $types .= "s"; }
          if ($type !== '') { $where .= " AND type = ?"; $params[] = $type; $types .= "s"; }

          $stc = $db->prepare("SELECT COUNT(*) cnt FROM finance WHERE $where");
          if ($types !== '') $stc->bind_param($types, ...$params);
          $stc->execute();
          $total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
          $stc->close();
          $pages = max(1,(int)ceil($total/$limit));

          $st = $db->prepare("SELECT id,user_id,type,amount,method,reference,notes,created_at
                              FROM finance
                              WHERE $where
                              ORDER BY created_at DESC
                              LIMIT ? OFFSET ?");
          $bindTypes = $types . "ii";
          $bind = $params; $bind[] = $limit; $bind[] = $off;
          $st->bind_param($bindTypes, ...$bind);
          $st->execute();
          $rs = $st->get_result();
          $rows = [];
          while ($r = $rs->fetch_assoc()) $rows[] = $r;
          $st->close();

          // totals
          $totSt = $db->prepare("SELECT SUM(CASE WHEN type='IN' THEN amount ELSE 0 END) inflow,
                                        SUM(CASE WHEN type='OUT' THEN amount ELSE 0 END) outflow
                                 FROM finance WHERE $where");
          if ($types !== '') $totSt->bind_param($types, ...$params);
          $totSt->execute();
          $tot = $totSt->get_result()->fetch_assoc() ?? ['inflow' => 0, 'outflow' => 0];
          $totSt->close();
        ?>

        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <form class="row g-2" method="get">
              <div class="col-md-4">
                <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search reference/method/notes...">
              </div>
              <div class="col-md-2">
                <input class="form-control" type="date" name="from" value="<?= h($from) ?>">
              </div>
              <div class="col-md-2">
                <input class="form-control" type="date" name="to" value="<?= h($to) ?>">
              </div>
              <div class="col-md-2">
                <select class="form-select" name="type">
                  <option value="">All</option>
                  <option value="IN" <?= $type==='IN'?'selected':'' ?>>Inflow</option>
                  <option value="OUT" <?= $type==='OUT'?'selected':'' ?>>Outflow</option>
                </select>
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-primary">Filter</button>
              </div>
            </form>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Total Inflow</h6>
                <div class="fs-4 fw-bold text-success"><?= number_format((float)($tot['inflow'] ?? 0),2) ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Total Outflow</h6>
                <div class="fs-4 fw-bold text-danger"><?= number_format((float)($tot['outflow'] ?? 0),2) ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <h6 class="mb-1">Net Capital</h6>
                <div class="fs-4 fw-bold <?= ((float)($tot['inflow'] ?? 0) - (float)($tot['outflow'] ?? 0))>=0 ? 'text-success' : 'text-danger' ?>">
                  <?= number_format(((float)($tot['inflow'] ?? 0) - (float)($tot['outflow'] ?? 0)),2) ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h5 class="mb-0">Capital Records</h5>
              <div>
                <a class="btn btn-outline-secondary btn-sm" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">Export CSV</a>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Notes</th>
                    <th>User</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found.</td></tr>
                  <?php else: foreach($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= h((string)$r['created_at']) ?></td>
                      <td><?= h((string)$r['type']) ?></td>
                      <td class="fw-semibold"><?= number_format((float)$r['amount'],2) ?></td>
                      <td><?= h((string)$r['method']) ?></td>
                      <td><?= h((string)$r['reference']) ?></td>
                      <td title="<?= h((string)$r['notes']) ?>"><?= h(mb_strimwidth((string)$r['notes'],0,120,'...')) ?></td>
                      <td><?= h((string)$r['user_id']) ?></td>
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

        <?php
          // handle CSV export after rendering variables to avoid repeating SQL logic
          if (isset($_GET['export']) && $_GET['export']==='csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="capital_report.csv"');
            $out = fopen('php://output','w');
            fputcsv($out, ['ID','Date','Type','Amount','Method','Reference','Notes','User']);
            foreach($rows as $r) {
              fputcsv($out, [
                $r['id'], $r['created_at'], $r['type'], $r['amount'], $r['method'], $r['reference'], $r['notes'], $r['user_id']
              ]);
            }
            fclose($out);
            exit;
          }
        }
        ?>

      </div>
    </main>
  </div>
</div>

<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php';

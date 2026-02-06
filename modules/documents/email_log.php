<?php
// modules/documents/email_log.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/rbac.php';

require_permission('admin.view'); // change to your permission if needed (e.g. 'admin.view')

$db = $GLOBALS['db'] ?? null;

$page_title = "Email Log";
$page_subtitle = "Email sending history (success/fail), recipients, subject, and timestamps.";

function table_exists(mysqli $db, string $table): bool {
  $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
  return $r && $r->num_rows > 0;
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $db->prepare($sql);
  if (!$st) return false;
  $st->bind_param('ss', $table, $col);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
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

          <?php if ($db instanceof mysqli): ?>
            <div class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-secondary" href="?">Refresh</a>
            </div>
          <?php endif; ?>
        </div>

    <?php if (!$db instanceof mysqli): ?>
      <div class="alert alert-danger mb-0">Database not available.</div>
    <?php else: ?>

      <?php
        // ---- ensure table exists (soft) ----
        $hasEmailLog = table_exists($db, 'email_log');

        // Filters
        $q      = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? '')); // success|failed|queued|...
        $from   = trim((string)($_GET['from'] ?? ''));
        $to     = trim((string)($_GET['to'] ?? ''));
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 15;
        $off    = ($page - 1) * $limit;

        if (!$hasEmailLog) {
          ?>
          <div class="alert alert-warning">
            <div class="fw-semibold mb-1">email_log table not found</div>
            <div class="small">
              Create it using the SQL below (run in phpMyAdmin):
            </div>
            <pre class="mb-0 mt-2 p-2 bg-light border rounded small"><?=
h("CREATE TABLE email_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(190) NOT NULL,
  to_name VARCHAR(190) NULL,
  subject VARCHAR(255) NOT NULL,
  body MEDIUMTEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued',
  provider VARCHAR(50) NULL,
  error_message TEXT NULL,
  meta_json JSON NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX idx_status (status),
  INDEX idx_created_at (created_at),
  INDEX idx_to_email (to_email),
  INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
?></pre>
          </div>
          <?php
        }

        // Columns (support multiple schema versions)
        $hasToName   = column_exists($db, 'email_log', 'to_name');
        $hasProvider = column_exists($db, 'email_log', 'provider');
        $hasSentAt   = column_exists($db, 'email_log', 'sent_at');
        $hasErr      = column_exists($db, 'email_log', 'error_message');
        $hasCreatedBy= column_exists($db, 'email_log', 'created_by');
        $hasMeta     = column_exists($db, 'email_log', 'meta_json');

        // Build WHERE
        $where = "1=1";
        $params = [];
        $types = "";

        if ($q !== '') {
          $where .= " AND (to_email LIKE ? OR subject LIKE ? " . ($hasToName ? " OR to_name LIKE ? " : "") . ")";
          $like = '%' . $q . '%';
          $params[] = $like; $types .= "s";
          $params[] = $like; $types .= "s";
          if ($hasToName) { $params[] = $like; $types .= "s"; }
        }

        if ($status !== '') {
          $where .= " AND status = ?";
          $params[] = $status; $types .= "s";
        }

        if ($from !== '') {
          $where .= " AND DATE(created_at) >= ?";
          $params[] = $from; $types .= "s";
        }
        if ($to !== '') {
          $where .= " AND DATE(created_at) <= ?";
          $params[] = $to; $types .= "s";
        }

        // Count
        $stc = $db->prepare("SELECT COUNT(*) AS cnt FROM email_log WHERE $where");
        $total = 0;
        if ($stc) {
          if ($types !== '') $stc->bind_param($types, ...$params);
          $stc->execute();
          $total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
          $stc->close();
        }

        $pages = max(1, (int)ceil($total / $limit));

        // Select fields safely
        $fields = [
          "id",
          "to_email",
          ($hasToName ? "to_name" : "NULL AS to_name"),
          "subject",
          "status",
          ($hasProvider ? "provider" : "NULL AS provider"),
          ($hasErr ? "error_message" : "NULL AS error_message"),
          "created_at",
          ($hasSentAt ? "sent_at" : "NULL AS sent_at"),
          ($hasCreatedBy ? "created_by" : "NULL AS created_by"),
          ($hasMeta ? "meta_json" : "NULL AS meta_json"),
        ];

        $sql = "SELECT " . implode(", ", $fields) . "
                FROM email_log
                WHERE $where
                ORDER BY id DESC
                LIMIT ? OFFSET ?";

        $st = $db->prepare($sql);
        $rows = [];
        if ($st) {
          $bindTypes = $types . "ii";
          $bindParams = $params;
          $bindParams[] = $limit;
          $bindParams[] = $off;

          $st->bind_param($bindTypes, ...$bindParams);
          $st->execute();
          $rs = $st->get_result();
          while ($r = $rs->fetch_assoc()) $rows[] = $r;
          $st->close();
        }

        // Pagination QS
        $qsBase = $_GET;
        unset($qsBase['page']);
        $qsBaseStr = http_build_query($qsBase);
        $qsBaseStr = $qsBaseStr ? $qsBaseStr . '&' : '';
      ?>

      <div class="card shadow-sm">
        <div class="card-body">

          <form class="row g-2 mb-3" method="get" action="">
            <div class="col-md-4">
              <input type="text" class="form-control" name="q" value="<?= h($q) ?>"
                     placeholder="Search email, subject<?= $hasToName ? ', name' : '' ?>...">
            </div>
            <div class="col-md-2">
              <select class="form-select" name="status">
                <option value="">All Status</option>
                <?php
                  $opts = ['queued','success','failed','sent'];
                  foreach ($opts as $opt):
                    $sel = ($status === $opt) ? 'selected' : '';
                ?>
                  <option value="<?= h($opt) ?>" <?= $sel ?>><?= h(ucfirst($opt)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
            </div>
            <div class="col-md-2 d-grid">
              <button class="btn btn-primary" type="submit">Filter</button>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>To</th>
                  <th>Subject</th>
                  <th>Status</th>
                  <th>Provider</th>
                  <th>Created</th>
                  <th>Sent</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="8" class="text-muted text-center py-4">
                    No email logs found.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $id = (int)$r['id'];
                    $toEmail = (string)($r['to_email'] ?? '');
                    $toName  = (string)($r['to_name'] ?? '');
                    $subj    = (string)($r['subject'] ?? '');
                    $stt     = (string)($r['status'] ?? '');
                    $prov    = (string)($r['provider'] ?? '');
                    $created = (string)($r['created_at'] ?? '');
                    $sentAt  = (string)($r['sent_at'] ?? '');
                    $err     = (string)($r['error_message'] ?? '');
                    $meta    = (string)($r['meta_json'] ?? '');

                    $badge = 'secondary';
                    if ($stt === 'success' || $stt === 'sent') $badge = 'success';
                    if ($stt === 'failed') $badge = 'danger';
                    if ($stt === 'queued') $badge = 'warning';

                    $modalId = "emailLogModal_" . $id;
                  ?>
                  <tr>
                    <td><?= $id ?></td>
                    <td>
                      <div class="fw-semibold"><?= h($toName !== '' ? $toName : $toEmail) ?></div>
                      <div class="small text-muted"><?= h($toEmail) ?></div>
                    </td>
                    <td style="max-width: 420px;">
                      <div class="text-truncate" title="<?= h($subj) ?>"><?= h($subj) ?></div>
                      <?php if ($err !== '' && $stt === 'failed'): ?>
                        <div class="small text-danger text-truncate" title="<?= h($err) ?>">Error: <?= h($err) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge bg-<?= h($badge) ?>"><?= h($stt ?: '—') ?></span>
                    </td>
                    <td><?= h($prov ?: '—') ?></td>
                    <td><?= h($created ?: '—') ?></td>
                    <td><?= h($sentAt ?: '—') ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-secondary"
                              type="button"
                              data-bs-toggle="modal"
                              data-bs-target="#<?= h($modalId) ?>">
                        View
                      </button>
                    </td>
                  </tr>

                  <!-- Modal: details -->
                  <div class="modal fade" id="<?= h($modalId) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                      <div class="modal-content">
                        <div class="modal-header">
                          <h5 class="modal-title">Email Log #<?= $id ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                          <div class="row g-3">
                            <div class="col-md-6">
                              <div class="small text-muted">To</div>
                              <div class="fw-semibold"><?= h($toName ?: '—') ?></div>
                              <div><?= h($toEmail) ?></div>
                            </div>
                            <div class="col-md-6">
                              <div class="small text-muted">Status</div>
                              <div><span class="badge bg-<?= h($badge) ?>"><?= h($stt ?: '—') ?></span></div>
                              <div class="small text-muted mt-2">Provider</div>
                              <div><?= h($prov ?: '—') ?></div>
                            </div>

                            <div class="col-12">
                              <div class="small text-muted">Subject</div>
                              <div class="fw-semibold"><?= h($subj) ?></div>
                            </div>

                            <?php if ($err !== ''): ?>
                              <div class="col-12">
                                <div class="small text-muted">Error Message</div>
                                <div class="text-danger" style="white-space: pre-wrap;"><?= h($err) ?></div>
                              </div>
                            <?php endif; ?>

                            <?php if ($meta !== '' && $meta !== 'null'): ?>
                              <div class="col-12">
                                <div class="small text-muted">Meta (JSON)</div>
                                <pre class="p-2 bg-light border rounded small mb-0" style="white-space: pre-wrap;"><?= h($meta) ?></pre>
                              </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                              <div class="small text-muted">Created At</div>
                              <div><?= h($created ?: '—') ?></div>
                            </div>
                            <div class="col-md-6">
                              <div class="small text-muted">Sent At</div>
                              <div><?= h($sentAt ?: '—') ?></div>
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                      </div>
                    </div>
                  </div>

                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">
              Showing <?= count($rows) ?> of <?= (int)$total ?> logs
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

    <?php endif; ?>

      </div>
    </main>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>

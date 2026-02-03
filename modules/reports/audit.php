<?php
// modules/reports/audit.php
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/includes/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/helpers.php';
require_once dirname(dirname(__DIR__)) . '/includes/rbac.php';

if (function_exists('require_admin_login')) require_admin_login();
require_permission('audit.view');

$db = $GLOBALS['db'] ?? null;
function table_exists(mysqli $db, string $table): bool {
	$r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
	return $r && $r->num_rows > 0;
}
$page_title = "Audit Report";
$page_subtitle = "System activity logs";

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
					if (!table_exists($db,'audit_logs')) {
						echo '<div class="alert alert-warning"><b>audit_logs</b> table not found.</div>';
						require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; exit;
					}
					$q = trim((string)($_GET['q'] ?? ''));
					$from = trim((string)($_GET['from'] ?? ''));
					$to = trim((string)($_GET['to'] ?? ''));
					$page = max(1,(int)($_GET['page'] ?? 1));
					$limit = 20;
					$off = ($page-1)*$limit;
					$where="1=1";
					$params=[];
					$types="";
					if ($q!=='') {
						$where .= " AND (action LIKE ? OR entity LIKE ? OR details LIKE ? OR ip_address LIKE ? )";
						$like = "%$q%";
						$params = array_merge($params, [$like,$like,$like,$like]);
						$types .= "ssss";
					}
					if ($from!=='') { $where .= " AND DATE(created_at) >= ?"; $params[]=$from; $types .= "s"; }
					if ($to!=='') { $where .= " AND DATE(created_at) <= ?"; $params[]=$to; $types .= "s"; }

					$stc = $db->prepare("SELECT COUNT(*) cnt FROM audit_logs WHERE $where");
					if ($types!=='') $stc->bind_param($types, ...$params);
					$stc->execute();
					$total = (int)($stc->get_result()->fetch_assoc()['cnt'] ?? 0);
					$stc->close();
					$pages = max(1,(int)ceil($total/$limit));

					$st = $db->prepare("SELECT id,user_id,action,entity,entity_id,details,ip_address,created_at
										  FROM audit_logs
										  WHERE $where
										  ORDER BY id DESC
										  LIMIT ? OFFSET ?");
					$bindTypes = $types . "ii";
					$bind = $params; $bind[] = $limit; $bind[] = $off;
					$st->bind_param($bindTypes, ...$bind);
					$st->execute();
					$rs = $st->get_result();
					$rows = [];
					while($r = $rs->fetch_assoc()) $rows[] = $r;
					$st->close();
				?>

					<div class="row mb-4">
						<!-- Summary Cards -->
						<div class="col-md-3 mb-3">
							<div class="card border-0 bg-primary bg-opacity-10">
								<div class="card-body text-center">
									<div class="fs-2 fw-bold text-primary"><?= number_format($total) ?></div>
									<div class="small text-muted">Total Activities</div>
								</div>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="card border-0 bg-success bg-opacity-10">
								<div class="card-body text-center">
									<div class="fs-2 fw-bold text-success"><?= number_format(count($rows)) ?></div>
									<div class="small text-muted">Filtered Results</div>
								</div>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="card border-0 bg-info bg-opacity-10">
								<div class="card-body text-center">
									<div class="fs-2 fw-bold text-info"><?= $pages ?></div>
									<div class="small text-muted">Total Pages</div>
								</div>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="card border-0 bg-warning bg-opacity-10">
								<div class="card-body text-center">
									<div class="fs-2 fw-bold text-warning"><?= $limit ?></div>
									<div class="small text-muted">Per Page</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Report Filters -->
					<div class="card shadow-sm mb-4">
						<div class="card-header bg-light">
							<h6 class="mb-0"><i class="bi bi-funnel"></i> Report Filters</h6>
						</div>
						<div class="card-body">
							<form class="row g-3" method="get">
								<div class="col-md-4">
									<label class="form-label small text-muted">Search</label>
									<input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search action, entity, details, IP...">
								</div>
								<div class="col-md-3">
									<label class="form-label small text-muted">From Date</label>
									<input class="form-control" type="date" name="from" value="<?= h($from) ?>">
								</div>
								<div class="col-md-3">
									<label class="form-label small text-muted">To Date</label>
									<input class="form-control" type="date" name="to" value="<?= h($to) ?>">
								</div>
								<div class="col-md-2">
									<label class="form-label small text-muted">&nbsp;</label>
									<button class="btn btn-primary w-100" type="submit">
										<i class="bi bi-search"></i> Search
									</button>
								</div>
							</form>
						</div>
					</div>

					<!-- Audit Log Table -->
					<div class="card shadow-sm">
						<div class="card-header bg-light d-flex justify-content-between align-items-center">
							<h6 class="mb-0"><i class="bi bi-clock-history"></i> Audit Trail Log</h6>
							<div class="small text-muted">
								Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($total) ?></strong> records
							</div>
						</div>

						<div class="table-responsive">
							<table class="table table-sm table-hover align-middle">
								<thead class="table-light">
									<tr>
										<th width="5%">#</th>
										<th width="10%">User ID</th>
										<th width="15%">Action</th>
										<th width="15%">Entity</th>
										<th width="10%">Entity ID</th>
										<th width="15%">IP Address</th>
										<th width="20%">Date & Time</th>
										<th width="10%">Details</th>
									</tr>
								</thead>
								<tbody>
									<?php if(!$rows): ?>
										<tr>
											<td colspan="8" class="text-center text-muted py-5">
												<div class="mb-3">
													<i class="bi bi-inbox" style="font-size: 3rem;"></i>
												</div>
												<div class="fw-semibold">No audit logs found</div>
												<div class="small">Try adjusting your search criteria or date range</div>
											</td>
										</tr>
									<?php else: foreach($rows as $r): ?>
										<tr title="<?= h((string)($r['details'] ?? '')) ?>">
											<td><span class="badge bg-secondary"><?= (int)$r['id'] ?></span></td>
											<td><?= h((string)($r['user_id'] ?? '')) ?></td>
											<td>
												<span class="badge bg-primary bg-opacity-25 text-primary fw-semibold">
													<?= h((string)($r['action'] ?? '')) ?>
												</span>
											</td>
											<td><?= h((string)($r['entity'] ?? '')) ?></td>
											<td><?= h((string)($r['entity_id'] ?? '')) ?></td>
											<td><code class="small"><?= h((string)($r['ip_address'] ?? '')) ?></code></td>
											<td>
												<small class="text-muted">
													<?= date('M j, Y H:i:s', strtotime($r['created_at'] ?? 'now')) ?>
												</small>
											</td>
											<td>
												<?php if (!empty($r['details'])): ?>
													<button class="btn btn-sm btn-outline-secondary" type="button" 
															data-bs-toggle="modal" 
															data-bs-target="#detailsModal"
															data-details="<?= htmlspecialchars((string)($r['details'] ?? '')) ?>"
															data-action="<?= htmlspecialchars((string)($r['action'] ?? '')) ?>"
															data-entity="<?= htmlspecialchars((string)($r['entity'] ?? '')) ?>"
															data-entity-id="<?= htmlspecialchars((string)($r['entity_id'] ?? '')) ?>"
															data-user-id="<?= htmlspecialchars((string)($r['user_id'] ?? '')) ?>"
															data-ip="<?= htmlspecialchars((string)($r['ip_address'] ?? '')) ?>"
															data-date="<?= date('M j, Y H:i:s', strtotime($r['created_at'] ?? 'now')) ?>">
														<i class="bi bi-eye"></i>
													</button>
												<?php else: ?>
													<span class="text-muted">-</span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; endif; ?>
								</tbody>
							</table>
						</div>

						<div class="card-footer bg-light">
							<div class="d-flex justify-content-between align-items-center">
								<div class="small text-muted">
									Page <strong><?= $page ?></strong> of <strong><?= $pages ?></strong> 
									| Showing <strong><?= count($rows) ?></strong> records
								</div>
							<nav>
								<ul class="pagination pagination-sm mb-0">
									<li class="page-item <?= $page<=1?'disabled':'' ?>">
										<a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=max(1,$page-1)?>">
											<i class="bi bi-chevron-left"></i> Prev
										</a>
									</li>
									<?php for($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
									<li class="page-item <?= $p===$page?'active':'' ?>">
										<a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=$p?>"><?=$p?></a>
									</li>
									<?php endfor; ?>
									<li class="page-item <?= $page>=$pages?'disabled':'' ?>">
										<a class="page-link" href="?q=<?=h($q)?>&from=<?=h($from)?>&to=<?=h($to)?>&page=<?=min($pages,$page+1)?>">
											Next <i class="bi bi-chevron-right"></i>
										</a>
									</li>
								</ul>
							</nav>
						</div>
					</div>
				</div>

			</div>
		</div>
		</main>
	</div>
</div>

<?php } ?>
<?php require_once dirname(dirname(__DIR__)) . '/templates/layout/footer.php'; ?>

<!-- Audit Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header bg-light">
				<h5 class="modal-title" id="detailsModalLabel">
					<i class="bi bi-info-circle"></i> Audit Log Details
				</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label small text-muted">Action</label>
						<div class="fw-semibold" id="modalAction">-</div>
					</div>
					<div class="col-md-6">
						<label class="form-label small text-muted">Entity</label>
						<div class="fw-semibold" id="modalEntity">-</div>
					</div>
					<div class="col-md-6">
						<label class="form-label small text-muted">Entity ID</label>
						<div class="fw-semibold" id="modalEntityId">-</div>
					</div>
					<div class="col-md-6">
						<label class="form-label small text-muted">User ID</label>
						<div class="fw-semibold" id="modalUserId">-</div>
					</div>
					<div class="col-md-6">
						<label class="form-label small text-muted">IP Address</label>
						<div class="fw-semibold"><code id="modalIp">-</code></div>
					</div>
					<div class="col-md-6">
						<label class="form-label small text-muted">Date & Time</label>
						<div class="fw-semibold" id="modalDate">-</div>
					</div>
					<div class="col-12">
						<label class="form-label small text-muted">Details</label>
						<div class="border rounded p-3 bg-light" id="modalDetails">-</div>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
	const detailsModal = document.getElementById('detailsModal');
	
	// Handle modal show event
	detailsModal.addEventListener('show.bs.modal', function(event) {
		const button = event.relatedTarget;
		
		// Extract data from button attributes
		const details = button.getAttribute('data-details');
		const action = button.getAttribute('data-action');
		const entity = button.getAttribute('data-entity');
		const entityId = button.getAttribute('data-entity-id');
		const userId = button.getAttribute('data-user-id');
		const ip = button.getAttribute('data-ip');
		const date = button.getAttribute('data-date');
		
		// Populate modal fields
		document.getElementById('modalAction').textContent = action || '-';
		document.getElementById('modalEntity').textContent = entity || '-';
		document.getElementById('modalEntityId').textContent = entityId || '-';
		document.getElementById('modalUserId').textContent = userId || '-';
		document.getElementById('modalIp').textContent = ip || '-';
		document.getElementById('modalDate').textContent = date || '-';
		
		// Format details with proper line breaks
		const detailsElement = document.getElementById('modalDetails');
		if (details) {
			// Preserve formatting and make it readable
			detailsElement.innerHTML = details.replace(/\n/g, '<br>').replace(/,/g, ', ');
		} else {
			detailsElement.textContent = '-';
		}
	});
	
	// Enable tooltips for any remaining elements
	const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
	tooltipTriggerList.map(function(tooltipTriggerEl) {
		return new bootstrap.Tooltip(tooltipTriggerEl);
	});
});
</script>

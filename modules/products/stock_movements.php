<?php
// modules/products/stock_movements.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('products.view');

$db = $GLOBALS['db'];
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = "Stock Movements";
$page_subtitle = "Stock ledger: every increase/decrease with references + location assignment";

if (!$db instanceof mysqli) {
  http_response_code(500);
  die("DB not available");
}

// Filters
$q      = trim((string)($_GET['q'] ?? ''));
$type   = trim((string)($_GET['type'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));
$limit  = 300;

$allowedTypes = ['stock_in','sale','return','adjustment','transfer'];

$where  = "1=1";
$params = [];
$types  = "";

// search SKU or name
if ($q !== '') {
  $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
  $like = "%{$q}%";
  $types .= "ss";
  $params[] = $like;
  $params[] = $like;
}

// movement type
if ($type !== '' && in_array($type, $allowedTypes, true)) {
  $where .= " AND sm.movement_type = ?";
  $types .= "s";
  $params[] = $type;
}

// date range (created_at)
if ($from !== '') {
  $where .= " AND sm.created_at >= ?";
  $types .= "s";
  $params[] = $from . " 00:00:00";
}
if ($to !== '') {
  $where .= " AND sm.created_at <= ?";
  $types .= "s";
  $params[] = $to . " 23:59:59";
}

$sql = "
SELECT
  sm.id,
  sm.movement_type,
  sm.qty_before,
  sm.qty_change,
  sm.qty_after,
  sm.reference_type,
  sm.reference_id,
  sm.note,
  sm.created_at,
  lf.name AS from_loc,
  lt.name AS to_loc,

  p.id AS product_id,
  p.sku,
  p.name AS product_name,
  p.unit_type,
  p.unit_name,
  p.pieces_per_box,

  u.username AS by_username,
  u.full_name AS by_name
FROM stock_movements sm
JOIN products p ON p.id = sm.product_id
LEFT JOIN locations lf ON lf.id = sm.from_location_id
LEFT JOIN locations lt ON lt.id = sm.to_location_id
LEFT JOIN users u ON u.id = sm.created_by
WHERE {$where}
ORDER BY sm.id DESC
LIMIT {$limit}
";

$stmt = $db->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">

      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
          <div>
            <label class="form-label small mb-1">Search</label>
            <input class="form-control form-control-sm" style="max-width:260px"
                   name="q" value="<?= h($q) ?>" placeholder="SKU or product name">
          </div>

          <div>
            <label class="form-label small mb-1">Type</label>
            <select class="form-select form-select-sm" name="type" style="max-width:200px">
              <option value="">All</option>
              <?php foreach ($allowedTypes as $t): ?>
                <option value="<?= h($t) ?>" <?= $type===$t ? 'selected' : '' ?>><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="form-label small mb-1">From</label>
            <input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>">
          </div>

          <div>
            <label class="form-label small mb-1">To</label>
            <input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>">
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($BASE_URL) ?>/modules/products/stock_movements.php">Reset</a>
          </div>
        </form>

        <div class="d-flex gap-2 align-items-center">
          <?php if (user_has_permission('products.update')): ?>
            <button class="btn btn-sm btn-primary" id="btnStockIn">+ Stock In</button>
            <button class="btn btn-sm btn-outline-primary" id="btnTransfer">Transfer</button>
          <?php endif; ?>
          <div class="small text-muted">Showing: <b><?= count($rows) ?></b> (max <?= $limit ?>)</div>
        </div>
      </div>

      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>When</th>
                  <th>Type</th>
                  <th>Product</th>
                  <th>From</th>
                  <th>To</th>
                  <th class="text-end">Before</th>
                  <th class="text-end">Change</th>
                  <th class="text-end">After</th>
                  <th>Reference</th>
                  <th>By</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="12" class="text-muted">No movements found.</td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $r): ?>
                  <?php
                    $chg = (float)$r['qty_change'];
                    $chgBadge = $chg >= 0
                      ? '<span class="badge bg-success">+' . h((string)$chg) . '</span>'
                      : '<span class="badge bg-danger">' . h((string)$chg) . '</span>';

                    $by = trim((string)($r['by_name'] ?? ''));
                    if ($by === '') $by = (string)($r['by_username'] ?? '');
                    if ($by === '') $by = '—';

                    $ref = '—';
                    if (!empty($r['reference_type']) || !empty($r['reference_id'])) {
                      $ref = h((string)$r['reference_type']) . " #" . h((string)$r['reference_id']);
                    }
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="small"><?= h((string)$r['created_at']) ?></td>
                    <td><?= h((string)$r['movement_type']) ?></td>
                    <td>
                      <div class="fw-semibold"><?= h((string)$r['product_name']) ?></div>
                      <div class="small text-muted"><?= h((string)$r['sku']) ?></div>
                    </td>
                    <td class="small"><?= h((string)($r['from_loc'] ?? '—')) ?></td>
                    <td class="small"><?= h((string)($r['to_loc'] ?? '—')) ?></td>
                    <td class="text-end"><?= h((string)$r['qty_before']) ?></td>
                    <td class="text-end"><?= $chgBadge ?></td>
                    <td class="text-end"><?= h((string)$r['qty_after']) ?></td>
                    <td class="small"><?= $ref ?></td>
                    <td class="small"><?= h($by) ?></td>
                    <td class="text-end">
                      <button class="btn btn-sm btn-outline-secondary" data-view-move="<?= (int)$r['id'] ?>">View</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="small text-muted mt-2">
            Note: quantities are in <b>base units</b> per location.
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<!-- View Movement Modal -->
<div class="modal fade" id="mdlViewMove" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Movement Details</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="moveDetails" class="small text-muted">Loading…</div>
      </div>
    </div>
  </div>
</div>

<!-- Stock In Modal -->
<div class="modal fade" id="mdlStockIn" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Stock In (Receive)</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Product *</label>
            <select class="form-select" id="si_product_id">
              <option value="">— Select Product —</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">To Location *</label>
            <select class="form-select" id="si_to_location"></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Qty (Base) *</label>
            <input class="form-control" id="si_qty" type="number" min="0" step="0.01" placeholder="e.g. 24">
          </div>

          <div class="col-md-4">
            <label class="form-label">Reference Type</label>
            <input class="form-control" id="si_ref_type" value="stock_in">
          </div>
          <div class="col-md-4">
            <label class="form-label">Reference ID</label>
            <input class="form-control" id="si_ref_id" placeholder="Invoice / GRN / receipt #">
          </div>
          <div class="col-md-4">
            <label class="form-label">Note</label>
            <input class="form-control" id="si_note" placeholder="optional note">
          </div>

          <div class="col-12">
            <div class="text-muted small" id="si_msg"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="btnDoStockIn">Save Stock In</button>
      </div>
    </div>
  </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="mdlTransfer" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title">Transfer Stock</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Product *</label>
            <select class="form-select" id="tr_product_id">
              <option value="">— Select Product —</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">From Location *</label>
            <select class="form-select" id="tr_from_location"></select>
          </div>
          <div class="col-md-4">
            <label class="form-label">To Location *</label>
            <select class="form-select" id="tr_to_location"></select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Current Stock (From)</label>
            <div class="form-text" id="tr_from_stock">—</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Current Stock (To)</label>
            <div class="form-text" id="tr_to_stock">—</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Qty (Base) *</label>
            <input class="form-control" id="tr_qty" type="number" min="0" step="0.01" placeholder="e.g. 24">
            <div class="form-text" id="tr_qty_hint">Enter quantity in base units (pieces for boxes/dozens/pairs; exact units for kg/liters)</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">After Transfer (From)</label>
            <div class="form-text fw-bold" id="tr_from_after">—</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">After Transfer (To)</label>
            <div class="form-text fw-bold" id="tr_to_after">—</div>
          </div>

          <div class="col-md-8">
            <label class="form-label">Note</label>
            <input class="form-control" id="tr_note" placeholder="optional note">
          </div>

          <div class="col-12">
            <div class="text-muted small" id="tr_msg"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" id="btnDoTransfer">Transfer</button>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>

<script>
const BASE_URL = <?= json_encode($BASE_URL) ?>;

const mdlViewMove = new bootstrap.Modal(document.getElementById('mdlViewMove'));
const mdlStockIn  = new bootstrap.Modal(document.getElementById('mdlStockIn'));
const mdlTransfer = new bootstrap.Modal(document.getElementById('mdlTransfer'));

async function loadLocationsInto(selectId){
  const res = await fetch(BASE_URL + "/api/stock.php?action=locations");
  const json = await res.json();
  if(!json.ok){ alert(json.error || "Failed to load locations"); return; }
  const sel = document.getElementById(selectId);
  sel.innerHTML = "";
  json.data.forEach(l=>{
    const o = document.createElement('option');
    o.value = l.id;
    o.textContent = l.name;
    sel.appendChild(o);
  });
}

let currentProduct = null;

async function loadProductsInto(selectId){
  const res = await fetch(BASE_URL + "/api/products.php?action=list");
  const json = await res.json();
  if(!json.ok){ alert(json.error || "Failed to load products"); return; }
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option value="">— Select Product —</option>';
  json.data.forEach(p=>{
    const o = document.createElement('option');
    o.value = p.id;
    o.textContent = `${p.sku} – ${p.name}`;
    sel.appendChild(o);
  });
}

async function fetchProduct(productId){
  const res = await fetch(BASE_URL + "/api/products.php?action=get&id=" + encodeURIComponent(productId));
  const json = await res.json();
  if(!json.ok) return null;
  return json.data;
}

function formatStockFromBase(baseQty, product){
  if (!product) return String(baseQty);
  const mock = { ...product, qty_base: baseQty };
  // Use the same helper as the backend
  const unit = product.unit_type || 'pieces';
  const qty = parseFloat(baseQty) || 0;
  if (unit === 'boxes') {
    const ppb = Math.max(1, parseInt(product.pieces_per_box || 0));
    const cartons = Math.floor(qty / ppb);
    const pieces  = Math.round(qty - (cartons * ppb));
    return `${cartons} cartons and ${pieces} pieces`;
  }
  if (unit === 'dozens') {
    const dozens = Math.floor(qty / 12);
    const pcs = Math.round(qty - (dozens * 12));
    return `${dozens} dozens and ${pcs} pieces`;
  }
  if (unit === 'pairs') {
    const pairs = Math.floor(qty / 2);
    const pcs = Math.round(qty - (pairs * 2));
    return `${pairs} pairs and ${pcs} pieces`;
  }
  if (unit === 'units') {
    const u = (product.unit_name || 'unit').trim();
    return `${rtrim(rtrim(Number(qty).toFixed(2), '0'), '.')} ${u}`;
  }
  // pieces
  return `${Math.round(qty)} pieces`;
}
function rtrim(str, char){ while(str.endsWith(char)) str = str.slice(0,-1); return str; }

async function fetchStockByLocation(productId, locationId){
  console.log('[fetchStockByLocation] productId:', productId, 'locationId:', locationId);
  const res = await fetch(BASE_URL + "/api/stock.php?action=stock_by_location&product_id=" + encodeURIComponent(productId) + "&location_id=" + encodeURIComponent(locationId));
  const txt = await res.text();
  console.log('[fetchStockByLocation] raw response:', txt);
  let json;
  try {
    json = JSON.parse(txt);
  } catch (e) {
    console.error('[fetchStockByLocation] JSON parse error:', e);
    return null;
  }
  console.log('[fetchStockByLocation] parsed response:', json);
  if(!json.ok) return null;
  return json.data.qty_base;
}

async function refreshTransferStocks(){
  const pid = Number(document.getElementById('tr_product_id').value || 0);
  const toId   = Number(document.getElementById('tr_to_location').value || 0);
  if (!pid || !toId) {
    document.getElementById('tr_from_stock').textContent = '—';
    document.getElementById('tr_to_stock').textContent = '—';
    document.getElementById('tr_from_after').textContent = '—';
    document.getElementById('tr_to_after').textContent = '—';
    document.getElementById('tr_qty_hint').textContent = '';
    return;
  }

  // Load product unit info once
  if (!currentProduct || Number(currentProduct.id) !== pid) {
    currentProduct = await fetchProduct(pid);
  }
  const unit = currentProduct?.unit_type || 'pieces';
  const hint = unit === 'units'
    ? `Enter quantity in ${currentProduct?.unit_name || 'units'}`
    : `Enter quantity in base units (pieces for ${unit})`;
  document.getElementById('tr_qty_hint').textContent = hint;

  // Load all locations with stock for this product
  const res = await fetch(BASE_URL + "/api/stock.php?action=stock_locations&product_id=" + encodeURIComponent(pid));
  const json = await res.json();
  if (!json.ok) {
    console.error('Failed to load stock locations:', json.error);
    return;
  }

  const fromSel = document.getElementById('tr_from_location');
  fromSel.innerHTML = '<option value="">— Select Source —</option>';

  const locationsWithStock = json.data.filter(l => (l.qty_base || 0) > 0);
  if (locationsWithStock.length === 0) {
    fromSel.innerHTML = '<option value="">No stock available</option>';
    document.getElementById('tr_from_stock').textContent = '0';
    document.getElementById('tr_to_stock').textContent = '0';
    document.getElementById('tr_from_after').textContent = '—';
    document.getElementById('tr_to_after').textContent = '—';
    // Show Restock button
    const btn = document.getElementById('tr_restock_btn');
    if (!btn) {
      const btnEl = document.createElement('button');
      btnEl.id = 'tr_restock_btn';
      btnEl.className = 'btn btn-sm btn-outline-warning mt-2';
      btnEl.textContent = 'Adjust Stock';
      btnEl.type = 'button';
      btnEl.addEventListener('click', () => {
        // Open Stock Adjustments modal with this product pre-selected
        openStockAdjustForProduct(pid);
      });
      fromSel.parentElement.after(btnEl);
    }
  } else {
    // Hide Restock button if it exists
    const btn = document.getElementById('tr_restock_btn');
    if (btn) btn.remove();

    locationsWithStock.forEach(l => {
      const o = document.createElement('option');
      o.value = l.location_id;
      o.textContent = `${l.location_name} (${formatStockFromBase(l.qty_base, currentProduct)})`;
      fromSel.appendChild(o);
    });
  }

  // Refresh To location stock display
  const toQty = await fetchStockByLocation(pid, toId);
  document.getElementById('tr_to_stock').textContent = toQty !== null ? formatStockFromBase(toQty, currentProduct) : '0';

  updateTransferBalances(0, toQty || 0);
}

async function openStockAdjustForProduct(productId){
  // Open Stock Adjustments modal with this product pre-selected
  window.location.href = `${BASE_URL}/modules/products/stock_adjustments.php?product_id=${encodeURIComponent(productId)}`;
}

function updateTransferBalances(fromQty, toQty){
  const qty = Number(document.getElementById('tr_qty').value || 0);
  const fromAfter = Math.max(0, fromQty - qty);
  const toAfter   = toQty + qty;

  document.getElementById('tr_from_after').textContent = formatStockFromBase(fromAfter, currentProduct);
  document.getElementById('tr_to_after').textContent   = formatStockFromBase(toAfter, currentProduct);
}

async function viewMovement(id){
  document.getElementById('moveDetails').textContent = "Loading…";
  mdlViewMove.show();

  const res = await fetch(BASE_URL + "/api/stock.php?action=movement_get&id=" + encodeURIComponent(id));
  const json = await res.json();
  if(!json.ok){
    document.getElementById('moveDetails').textContent = json.error || "Failed";
    return;
  }

  const r = json.data;
  const ref = (r.reference_type || r.reference_id) ? `${r.reference_type || ''} #${r.reference_id || ''}` : '—';
  const by  = (r.full_name || r.username || '—');

  document.getElementById('moveDetails').innerHTML = `
    <div class="mb-2"><b>Movement #</b> ${r.id}</div>
    <div class="mb-2"><b>Product</b> ${escapeHtml(r.product_name)} (${escapeHtml(r.sku)})</div>
    <div class="mb-2"><b>Type</b> ${escapeHtml(r.movement_type)}</div>
    <div class="mb-2"><b>From</b> ${escapeHtml(r.from_loc || '—')} &nbsp; <b>To</b> ${escapeHtml(r.to_loc || '—')}</div>
    <div class="mb-2"><b>Before</b> ${escapeHtml(r.qty_before)} &nbsp; <b>Change</b> ${escapeHtml(r.qty_change)} &nbsp; <b>After</b> ${escapeHtml(r.qty_after)}</div>
    <div class="mb-2"><b>Reference</b> ${escapeHtml(ref)}</div>
    <div class="mb-2"><b>By</b> ${escapeHtml(by)} &nbsp; <b>When</b> ${escapeHtml(r.created_at)}</div>
    <div class="mb-0"><b>Note</b> ${escapeHtml(r.note || '—')}</div>
  `;
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, m => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[m]));
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-view-move]');
  if(!btn) return;
  viewMovement(btn.getAttribute('data-view-move'));
});

const btnStockIn = document.getElementById('btnStockIn');
if (btnStockIn){
  btnStockIn.addEventListener('click', async ()=>{
    await loadProductsInto('si_product_id');
    await loadLocationsInto('si_to_location');
    document.getElementById('si_msg').textContent = "";
    mdlStockIn.show();
  });
}

const btnTransfer = document.getElementById('btnTransfer');
if (btnTransfer){
  btnTransfer.addEventListener('click', async ()=>{
    await loadProductsInto('tr_product_id');
    await loadLocationsInto('tr_from_location');
    await loadLocationsInto('tr_to_location');
    document.getElementById('tr_msg').textContent = "";
    mdlTransfer.show();
  });
}

// Auto-refresh stock display when product changes only
document.getElementById('tr_product_id')?.addEventListener('change', refreshTransferStocks);

// Auto-refresh To stock display when To location changes (keep From)
document.getElementById('tr_to_location')?.addEventListener('change', async () => {
  const pid = Number(document.getElementById('tr_product_id').value || 0);
  const toId = Number(document.getElementById('tr_to_location').value || 0);
  if (!pid || !toId) return;
  if (!currentProduct || Number(currentProduct.id) !== pid) {
    currentProduct = await fetchProduct(pid);
  }
  const toQty = await fetchStockByLocation(pid, toId);
  document.getElementById('tr_to_stock').textContent = toQty !== null ? formatStockFromBase(toQty, currentProduct) : '0';
  // Re-calculate balances using existing From stock (if any)
  const fromId = Number(document.getElementById('tr_from_location').value || 0);
  const fromQty = fromId ? await fetchStockByLocation(pid, fromId) : 0;
  updateTransferBalances(fromQty, toQty || 0);
});

// Live balance update when quantity changes (do NOT rebuild dropdowns)
document.getElementById('tr_qty')?.addEventListener('input', () => {
  const fromId = Number(document.getElementById('tr_from_location').value || 0);
  const toId   = Number(document.getElementById('tr_to_location').value || 0);
  if (!currentProduct || !fromId || !toId) return;
  // Re-fetch current stocks to compute live balances
  Promise.all([
    fetchStockByLocation(currentProduct.id, fromId),
    fetchStockByLocation(currentProduct.id, toId)
  ]).then(([fromQty, toQty]) => {
    updateTransferBalances(fromQty || 0, toQty || 0);
  });
});

document.getElementById('btnDoTransfer').addEventListener('click', async ()=>{
  const msg = document.getElementById('tr_msg');
  msg.textContent = "Saving…";

  const payload = {
    product_id: Number(document.getElementById('tr_product_id').value || 0),
    from_location_id: Number(document.getElementById('tr_from_location').value || 0),
    to_location_id: Number(document.getElementById('tr_to_location').value || 0),
    qty_base: Number(document.getElementById('tr_qty').value || 0),
    note: document.getElementById('tr_note').value.trim()
  };

  const res = await fetch(BASE_URL + "/api/stock.php?action=transfer", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(payload)
  });
  const json = await res.json();
  if(!json.ok){ msg.textContent = json.error || "Failed"; return; }

  msg.textContent = "Transferred. Movement ID: " + json.data.movement_id + " (refresh page to see it).";
});

document.getElementById('btnDoStockIn').addEventListener('click', async ()=>{
  const msg = document.getElementById('si_msg');
  msg.textContent = "Saving…";

  const payload = {
    product_id: Number(document.getElementById('si_product_id').value || 0),
    to_location_id: Number(document.getElementById('si_to_location').value || 0),
    qty_base: Number(document.getElementById('si_qty').value || 0),
    reference_type: document.getElementById('si_ref_type').value.trim() || 'stock_in',
    reference_id: document.getElementById('si_ref_id').value.trim(),
    note: document.getElementById('si_note').value.trim()
  };

  const res = await fetch(BASE_URL + "/api/stock.php?action=stock_in", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(payload)
  });
  const json = await res.json();
  if(!json.ok){ msg.textContent = json.error || "Failed"; return; }

  msg.textContent = "Saved. Movement ID: " + json.data.movement_id + " (refresh page to see it).";
});
</script>

<?php
// modules/products/products.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_permission('products.view');

$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

$page_title = "Products";
$page_subtitle = "Manage products, units, and stock";

$extra_js = ["assets/js/app.js"]; // optional

require_once __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>

  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
        <div class="d-flex gap-2">
          <input id="q" class="form-control form-control-sm" style="max-width:320px" placeholder="Search name or SKU...">
          <button class="btn btn-sm btn-outline-secondary" id="btnSearch">Search</button>
        </div>

        <?php if (user_has_permission('products.create')): ?>
          <button class="btn btn-sm btn-primary" id="btnNew">+ New Product</button>
        <?php endif; ?>
      </div>

      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm align-middle" id="tbl">
              <thead>
                <tr>
                  <th>Image</th>
                  <th>SKU</th>
                  <th>Name</th>
                  <th>Unit</th>
                  <th class="text-end">Cost</th>
                  <th class="text-end">Wholesale</th>
                  <th class="text-end">Retail</th>
                  <th>Stock</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="text-muted small" id="hint">Loading…</div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="mdlProduct" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="mdlTitle">Product</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" id="id">

        <div class="row g-2">
          <div class="col-md-8">
            <label class="form-label">Product Name *</label>
            <input class="form-control" id="name">
          </div>
          <div class="col-md-4">
            <label class="form-label">SKU / Barcode *</label>
            <input class="form-control" id="sku">
          </div>

          <div class="col-12">
            <label class="form-label">Description</label>
            <textarea class="form-control" id="description" rows="2"></textarea>
          </div>

          <div class="col-md-4">
            <label class="form-label">Source / Supplier</label>
            <input class="form-control" id="source" placeholder="e.g. ABC Supplies">
          </div>

          <div class="col-md-4">
            <label class="form-label">Unit Type *</label>
            <select class="form-select" id="unit_type">
              <option value="boxes">Boxes / Cartons</option>
              <option value="dozens">Dozens</option>
              <option value="pairs">Pairs</option>
              <option value="pieces" selected>Pieces</option>
              <option value="units">Units (kg, liters…)</option>
            </select>
          </div>

          <div class="col-md-4" id="wrap_unit_name" style="display:none;">
            <label class="form-label">Unit Name (e.g. kg) *</label>
            <input class="form-control" id="unit_name" placeholder="kg">
          </div>

          <div class="col-md-4" id="wrap_ppb" style="display:none;">
            <label class="form-label">Pieces per Box *</label>
            <input class="form-control" id="pieces_per_box" type="number" min="1" step="1" placeholder="e.g. 24">
          </div>

          <div class="col-md-4">
            <label class="form-label">Default Location</label>
            <select class="form-select" id="default_location_id">
              <option value="">— None —</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Cost Price</label>
            <input class="form-control" id="cost_price" type="number" min="0" step="0.01">
          </div>
          <div class="col-md-4">
            <label class="form-label">Wholesale Price (Floor)</label>
            <input class="form-control" id="wholesale_price" type="number" min="0" step="0.01">
          </div>
          <div class="col-md-4">
            <label class="form-label">Retail Price</label>
            <input class="form-control" id="retail_price" type="number" min="0" step="0.01">
          </div>

          <div class="col-md-6">
            <label class="form-label">Stock (Base)</label>
            <div class="form-text">
              If Boxes/Dozens/Pairs: enter total pieces in stock. If Units(kg): enter kg quantity.
            </div>
            <input class="form-control" id="qty_base" type="number" min="0" step="0.01">
          </div>

          <div class="col-md-6">
            <label class="form-label">Low Level (Base)</label>
            <div class="form-text">
              If Boxes/Dozens/Pairs: low stock pieces. If Units: low stock in that unit.
            </div>
            <input class="form-control" id="low_level_base" type="number" min="0" step="0.01">
          </div>

          <div class="col-md-4">
            <label class="form-label">Active</label>
            <select class="form-select" id="is_active">
              <option value="1" selected>Yes</option>
              <option value="0">No</option>
            </select>
          </div>

        </div>

        <!-- Images -->
        <div class="mt-3">
          <label class="form-label">Product Images <span id="imageCount">(0/5)</span></label>
          <div class="d-flex flex-wrap gap-2 mb-2" id="imageGallery"></div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" id="btnUploadImage">Upload File</button>
            <button class="btn btn-sm btn-outline-secondary" id="btnImportUrl">Import from URL</button>
            <button class="btn btn-sm btn-outline-info" id="btnQrCapture">QR Capture</button>
            <input type="file" id="fileInput" accept="image/*" multiple style="display:none;">
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>

        <?php if (user_has_permission('products.delete')): ?>
          <button class="btn btn-outline-danger btn-sm" id="btnDelete" style="display:none;">Delete</button>
        <?php endif; ?>

        <?php if (user_has_permission('products.create') || user_has_permission('products.update')): ?>
          <button class="btn btn-primary btn-sm" id="btnSave">Save</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../templates/layout/footer.php'; ?>

<script>
  window.APP = {
    BASE_URL: <?= json_encode($BASE_URL) ?>,
    CSRF: <?= json_encode($_SESSION['csrf'] ?? '') ?>,
  };
</script>

<script>
const BASE_URL = <?= json_encode($BASE_URL) ?>;
const canUpdate = <?= json_encode(user_has_permission('products.update')) ?>;
const canDelete = <?= json_encode(user_has_permission('products.delete')) ?>;
const canCreate = <?= json_encode(user_has_permission('products.create')) ?>;

const mdl = new bootstrap.Modal(document.getElementById('mdlProduct'));
const tbody = document.querySelector('#tbl tbody');
const hint = document.getElementById('hint');

let currentProductId = null;
let productImages = [];

function showUnitFields(){
  const t = document.getElementById('unit_type').value;
  document.getElementById('wrap_ppb').style.display = (t === 'boxes') ? '' : 'none';
  document.getElementById('wrap_unit_name').style.display = (t === 'units') ? '' : 'none';
}

function renderImageGallery() {
  const gallery = document.getElementById('imageGallery');
  const counter = document.getElementById('imageCount');
  if (!gallery || !counter) return;
  gallery.innerHTML = '';
  const max = 5;
  if (!Array.isArray(productImages)) productImages = [];
  counter.textContent = `(${productImages.length}/${max})`;
  productImages.forEach((img, idx) => {
    const thumb = document.createElement('div');
    thumb.className = 'position-relative d-inline-block me-2 mb-2';
    thumb.innerHTML = `
      <img src="${BASE_URL}/${img}" width="80" height="80" class="border rounded" style="object-fit:cover;">
      <button type="button" class="btn btn-sm btn-danger position-absolute top-0 start-0 m-1" onclick="deleteImage('${img}')">×</button>
      <button type="button" class="btn btn-sm btn-primary position-absolute top-0 end-0 m-1" onclick="previewImage('${img}')">🔍</button>
      <button type="button" class="btn btn-sm btn-secondary position-absolute bottom-0 start-0 m-1" onclick="cropImage('${img}')">✂️</button>
    `;
    gallery.appendChild(thumb);
  });
  // Show warning if at limit
  if (productImages.length >= max) {
    const warning = document.createElement('div');
    warning.className = 'alert alert-warning mt-2 mb-2';
    warning.textContent = 'Maximum 5 images reached. Delete an image to upload more.';
    gallery.appendChild(warning);
  }
  // Add global refresh button
  const refreshBtn = document.createElement('button');
  refreshBtn.type = 'button';
  refreshBtn.className = 'btn btn-sm btn-outline-secondary mb-2';
  refreshBtn.innerHTML = '↻ Refresh';
  refreshBtn.onclick = refreshImages;
  gallery.appendChild(refreshBtn);
}

function previewImage(path) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">Image Preview</h6>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center">
          <img src="${BASE_URL}/${path}" class="img-fluid" style="max-height:70vh;">
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();
  modal.addEventListener('hidden.bs.modal', () => document.body.removeChild(modal));
}

function cropImage(path) {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">Crop Image</h6>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-0">
          <div class="row g-0">
            <div class="col-md-8 p-3">
              <img id="cropImage" src="${BASE_URL}/${path}" style="max-width:100%; max-height:70vh; object-fit:contain;">
            </div>
            <div class="col-md-4 bg-light p-3 d-flex flex-column">
              <h6>Preview</h6>
              <div id="cropPreview" style="width:100%;height:250px;overflow:hidden;background:#fff;border:1px solid #ddd;margin-bottom:1rem;"></div>
              <div class="mb-2">
                <label class="form-label">Aspect Ratio</label>
                <select class="form-select form-select-sm" id="cropAspectRatio">
                  <option value="free">Free</option>
                  <option value="1">1:1 (Square)</option>
                  <option value="1.7777777777777777">16:9</option>
                  <option value="1.3333333333333333">4:3</option>
                </select>
              </div>
              <div class="mt-auto">
                <button type="button" class="btn btn-secondary w-100 mb-2" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary w-100 mb-2" id="btnCropReplace">Replace Original</button>
                <button type="button" class="btn btn-outline-primary w-100" id="btnCropSaveCopy">Save Copy</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();

  const image = document.getElementById('cropImage');
  const preview = document.getElementById('cropPreview');
  const aspectSelect = document.getElementById('cropAspectRatio');
  let cropper = new Cropper(image, {
    aspectRatio: NaN,
    viewMode: 1,
    autoCropArea: 0.9,
    preview: preview,
  });

  aspectSelect.addEventListener('change', () => {
    const val = aspectSelect.value;
    cropper.setAspectRatio(val === 'free' ? NaN : parseFloat(val));
  });

  function saveCropped(overwrite = false) {
    const canvas = cropper.getCroppedCanvas({ maxWidth: 1024, maxHeight: 1024 });
    if (!canvas) {
      alert('Failed to crop image');
      return;
    }
    canvas.toBlob(async (blob) => {
      if (!blob) {
        alert('Failed to process cropped image');
        return;
      }
      const fd = new FormData();
      fd.append('product_id', currentProductId);
      fd.append('file', blob, 'crop.jpg');
      if (overwrite) fd.append('overwrite_path', path);
      const res = await fetch(`${BASE_URL}/api/images.php?action=upload`, {method:'POST', body:fd});
      const txt = await res.text();
      console.log('[products crop] raw response:', txt);
      let j;
      try {
        j = JSON.parse(txt);
      } catch (e) {
        console.error('[products crop] JSON parse error:', e);
        alert('Server returned non-JSON response. See console for details.');
        return;
      }
      if (j.ok) {
        productImages = j.data.images;
        renderImageGallery();
        // Force refresh of the overwritten image by adding timestamp
        if (overwrite) {
          const imgs = document.querySelectorAll(`img[src="${BASE_URL}/${path}"], img[src^="${BASE_URL}/${path}?"]`);
          imgs.forEach(img => {
            img.src = `${BASE_URL}/${path}?t=${Date.now()}`;
          });
        }
        bsModal.hide();
      } else {
        alert(j.error || 'Failed to save cropped image');
      }
    }, 'image/jpeg', 0.75);
  }

  document.getElementById('btnCropReplace').addEventListener('click', () => saveCropped(true));
  document.getElementById('btnCropSaveCopy').addEventListener('click', () => saveCropped(false));

  modal.addEventListener('hidden.bs.modal', () => {
    cropper.destroy();
    document.body.removeChild(modal);
  });
}

async function refreshImages() {
  if (!currentProductId) return;
  try {
    const res = await fetch(`${BASE_URL}/api/products.php?action=list&q=&id=${currentProductId}`);
    const txt = await res.text();
    console.log('[refresh] raw response:', txt);
    const j = JSON.parse(txt);
    if (j.ok && j.data && Array.isArray(j.data) && j.data.length > 0) {
      const product = j.data[0];
      productImages = [];
      if (product.images) {
        try {
          productImages = JSON.parse(product.images);
        } catch (e) {
          console.warn('[refresh] failed to parse images JSON:', e);
        }
      }
      renderImageGallery();
    } else {
      console.warn('[refresh] no product found or error:', j);
    }
  } catch (e) {
    console.error('[refresh] error:', e);
  }
}

async function deleteImage(path) {
  if (!confirm('Delete this image?')) return;
  const res = await fetch(`${BASE_URL}/api/images.php?action=delete`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({product_id: currentProductId, image_path: path})
  });
  const j = await res.json();
  if (j.ok) {
    productImages = j.data.images;
    renderImageGallery();
  } else {
    alert(j.error || 'Delete failed');
  }
}

// Upload file
document.getElementById('btnUploadImage').addEventListener('click', () => {
  document.getElementById('fileInput').click();
});
document.getElementById('fileInput').addEventListener('change', async (e) => {
  const files = Array.from(e.target.files);
  if (!files.length) return;
  // Check image limit before upload
  const remainingSlots = 5 - productImages.length;
  if (remainingSlots <= 0) {
    alert('Maximum 5 images allowed. Delete an image first.');
    e.target.value = '';
    return;
  }
  const filesToUpload = files.slice(0, remainingSlots);
  if (files.length > remainingSlots) {
    alert(`Only ${remainingSlots} image(s) can be uploaded (max 5 total).`);
  }
  for (const file of filesToUpload) {
    const fd = new FormData();
    fd.append('product_id', currentProductId);
    fd.append('file', file);
    const res = await fetch(`${BASE_URL}/api/images.php?action=upload`, {method:'POST', body:fd});
    const txt = await res.text();
    console.log('[products upload] raw response:', txt);
    let j;
    try {
      j = JSON.parse(txt);
    } catch (e) {
      console.error('[products upload] JSON parse error:', e);
      alert('Server returned non-JSON response. See console for details.');
      continue;
    }
    if (j.ok) {
      productImages = j.data.images;
      renderImageGallery();
    } else {
      alert(j.error || 'Upload failed for ' + file.name);
    }
  }
  e.target.value = '';
});

// Import from URL
document.getElementById('btnImportUrl').addEventListener('click', () => {
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">Import Images from URLs</h6>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Enter image URLs (one per line or comma-separated):</label>
          <textarea class="form-control" id="urlInput" rows="4" placeholder="https://example.com/img1.jpg&#10;https://example.com/img2.jpg"></textarea>
          <small class="text-muted">Maximum 5 images total.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="btnImport">Import</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();

  document.getElementById('btnImport').addEventListener('click', () => {
    const input = document.getElementById('urlInput').value;
    const urls = input.split(/[,\n]+/).map(u => u.trim()).filter(u => u);
    if (!urls.length) {
      alert('Please enter at least one URL.');
      return;
    }
    const remainingSlots = 5 - productImages.length;
    if (remainingSlots <= 0) {
      alert('Maximum 5 images allowed. Delete an image first.');
      return;
    }
    const urlsToImport = urls.slice(0, remainingSlots);
    if (urls.length > remainingSlots) {
      alert(`Only ${remainingSlots} image(s) can be imported (max 5 total).`);
    }
    Promise.all(urlsToImport.map(url =>
      fetch(`${BASE_URL}/api/images.php?action=import_url`, {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({product_id: currentProductId, url})
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) {
          productImages = j.data.images;
          renderImageGallery();
        } else {
          alert(j.error || 'Import failed for ' + url);
        }
      })
      .catch(e => {
        console.error('[import] error:', e);
        alert('Network error importing ' + url);
      })
    ));
    bsModal.hide();
  });

  modal.addEventListener('hidden.bs.modal', () => {
    document.body.removeChild(modal);
  });
});

// QR Capture (generate QR code)
document.getElementById('btnQrCapture').addEventListener('click', async () => {
  if (!currentProductId) {
    alert('Please save the product first to enable QR capture.');
    return;
  }
  // Fetch local IP or use stored custom IP
  let baseUrl = window.location.origin;
  const customIp = localStorage.getItem('qrCaptureCustomIp');
  if (customIp) {
    const protocol = window.location.protocol;
    const port = window.location.port ? `:${window.location.port}` : '';
    const subPath = window.APP.BASE_URL.replace(/^https?:\/\/[^\/]+/, '');
    baseUrl = `${protocol}//${customIp}${port}${subPath}`;
  } else if (baseUrl.includes('localhost') || baseUrl.includes('127.0.0.1')) {
    try {
      const ipRes = await fetch(`${window.APP.BASE_URL}/api/ip.php`);
      const ipData = await ipRes.json();
      const ip = ipData.ip;
      const protocol = window.location.protocol;
      const port = window.location.port ? `:${window.location.port}` : '';
      const subPath = window.APP.BASE_URL.replace(/^https?:\/\/[^\/]+/, '');
      baseUrl = `${protocol}//${ip}${port}${subPath}`;
    } catch (e) {
      console.warn('Failed to fetch local IP, using origin', e);
    }
  } else {
    const subPath = window.APP.BASE_URL.replace(/^https?:\/\/[^\/]+/, '');
    if (subPath && !baseUrl.endsWith(subPath)) {
      baseUrl = baseUrl.replace(/\/$/, '') + subPath;
    }
  }
  const captureUrl = `${baseUrl}/modules/products/capture.php?pid=${currentProductId}&csrf=${window.APP.CSRF}`;
  // Generate QR code using qrcode.js CDN
  const modal = document.createElement('div');
  modal.className = 'modal fade';
  modal.tabIndex = -1;
  modal.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">QR Capture</h6>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center">
          <p>Scan this QR code with your phone to capture an image:</p>
          <div class="mb-3">
            <label class="form-label">Capture URL (editable):</label>
            <input type="text" class="form-control text-break" id="captureUrlInput" value="${captureUrl}">
          </div>
          <div id="qrcode" style="display:inline-block;padding:1rem;background:#fff;border:1px solid #ddd;"></div>
          <div class="mt-3">
            <small class="text-muted">Or open this link directly on your phone:</small><br>
            <a href="${captureUrl}" target="_blank" class="text-break" id="captureLink">${captureUrl}</a>
          </div>
        </div>
        <div class="modal-footer">
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
  const bsModal = new bootstrap.Modal(modal);
  bsModal.show();

  // Load qrcode.js and generate QR code
  const script = document.createElement('script');
  script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
  script.onload = () => {
    const qrContainer = document.getElementById('qrcode');
    let qrCode = new QRCode(qrContainer, {
      text: captureUrl,
      width: 256,
      height: 256,
    });
    // Update QR code and link when input changes
    const urlInput = document.getElementById('captureUrlInput');
    const link = document.getElementById('captureLink');
    urlInput.addEventListener('input', () => {
      const newUrl = urlInput.value;
      // Clear previous QR code
      qrContainer.innerHTML = '';
      qrCode = new QRCode(qrContainer, {
        text: newUrl,
        width: 256,
        height: 256,
      });
      link.href = newUrl;
      link.textContent = newUrl;
      // Store custom IP from URL
      const match = newUrl.match(/^https?:\/\/([^\/]+)/);
      if (match && match[1]) {
        localStorage.setItem('qrCaptureCustomIp', match[1]);
      }
    });
  };
  document.head.appendChild(script);

  modal.addEventListener('hidden.bs.modal', () => {
    document.body.removeChild(modal);
    // Refresh images when QR modal closes
    refreshImages();
  });
});
window.addEventListener('message', (e) => {
  console.log('[products] received message:', e.data);
  if (e.data.action === 'openCrop' && e.data.image) {
    // Ensure we have the right product loaded
    if (!currentProductId) {
      console.log('[products] no currentProductId, loading product 1');
      // Try to load the product if not loaded
      openEdit(currentProductId || 1).then(() => {
        if (productImages.includes(e.data.image)) {
          console.log('[products] opening crop for image:', e.data.image);
          cropImage(e.data.image);
        }
      });
    } else if (productImages.includes(e.data.image)) {
      console.log('[products] opening crop for image:', e.data.image);
      cropImage(e.data.image);
    }
  }
});

window.addEventListener('hashchange', () => {
  if (window.location.hash === '#crop' && currentProductId && productImages.length > 0) {
    // Open crop modal for the last uploaded image
    const lastImage = productImages[productImages.length - 1];
    cropImage(lastImage);
  }
});

// Trigger on load if hash is present
if (window.location.hash === '#crop' && currentProductId && productImages.length > 0) {
  const lastImage = productImages[productImages.length - 1];
  cropImage(lastImage);
}

async function loadProducts(){
  hint.textContent = "Loading…";
  tbody.innerHTML = "";
  const q = document.getElementById('q').value.trim();
  const url = BASE_URL + "/api/products.php?action=list&q=" + encodeURIComponent(q);
  const res = await fetch(url);
  const json = await res.json();
  if(!json.ok){ hint.textContent = json.error || "Failed"; return; }

  json.data.forEach(p => {
    const tr = document.createElement('tr');
    const images = p.images ? JSON.parse(p.images) : [];
    const firstImage = images.length > 0 ? images[0] : null;
    const remainingImages = images.slice(1);
    let imageCell = '';
    if (firstImage) {
      imageCell = `<img src="${BASE_URL}/${firstImage}" width="40" height="40" class="border rounded" style="object-fit:cover; cursor:pointer;"`;
      if (remainingImages.length > 0) {
        const thumbs = remainingImages.map(img => `<img src="${BASE_URL}/${img}" width="40" height="40" class="border rounded me-1" style="object-fit:cover;">`).join('');
        imageCell = `<div class="position-relative d-inline-block" data-bs-toggle="tooltip" data-bs-html="true" title="${thumbs.replace(/"/g, '&quot;')}">${imageCell}</div>`;
      }
    }
    tr.innerHTML = `
      <td>${imageCell}</td>
      <td>${escapeHtml(p.sku)}</td>
      <td>${escapeHtml(p.name)}</td>
      <td>${escapeHtml(p.unit_type)}${p.unit_type==='units' ? ' ('+escapeHtml(p.unit_name||'')+')' : ''}${p.unit_type==='boxes' ? ' • '+(p.pieces_per_box||0)+' pcs/box' : ''}</td>
      <td class="text-end">${num(p.cost_price)}</td>
      <td class="text-end">${num(p.wholesale_price)}</td>
      <td class="text-end">${num(p.retail_price)}</td>
      <td>${escapeHtml(p.stock_display || '')}</td>
      <td>${p.is_active==1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>'}</td>
      <td class="text-end">
        ${(canUpdate ? `<button class="btn btn-sm btn-outline-primary" data-id="${p.id}" data-act="edit">Edit</button>` : '')}
      </td>
    `;
    tbody.appendChild(tr);
  });

  hint.textContent = json.data.length ? "" : "No products yet.";
  // Initialize Bootstrap tooltips
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });
}

function num(x){
  if(x === null || x === undefined) return '0';
  return Number(x).toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:2});
}
function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

function clearForm(){
  ['id','name','sku','description','source','unit_name','pieces_per_box','cost_price','wholesale_price','retail_price','qty_base','low_level_base','default_location_id']
    .forEach(i => document.getElementById(i).value = '');
  document.getElementById('unit_type').value = 'pieces';
  document.getElementById('is_active').value = '1';
  showUnitFields();
  document.getElementById('btnDelete').style.display = 'none';
  currentProductId = null;
  productImages = [];
  renderImageGallery();
}

async function openNew(){
  if(!canCreate) return;
  clearForm();
  document.getElementById('mdlTitle').textContent = "New Product";
  mdl.show();
}

async function openEdit(id){
  if(!canUpdate) return;
  const res = await fetch(BASE_URL + "/api/products.php?action=get&id=" + id);
  const txt = await res.text();
  console.log('[products openEdit] raw response:', txt);
  let json;
  try {
    json = JSON.parse(txt);
  } catch (e) {
    console.error('[products openEdit] JSON parse error:', e);
    alert('Server returned non-JSON response. See console for details.');
    return;
  }
  if(!json.ok){ alert(json.error || "Failed"); return; }

  const p = json.data;
  currentProductId = p.id;
  productImages = p.images ? JSON.parse(p.images) : [];
  renderImageGallery();

  document.getElementById('mdlTitle').textContent = "Edit Product";
  document.getElementById('id').value = p.id;
  document.getElementById('name').value = p.name || '';
  document.getElementById('sku').value = p.sku || '';
  document.getElementById('description').value = p.description || '';
  document.getElementById('source').value = p.source || '';
  document.getElementById('unit_type').value = p.unit_type || 'pieces';
  document.getElementById('unit_name').value = p.unit_name || '';
  document.getElementById('pieces_per_box').value = p.pieces_per_box || '';
  document.getElementById('cost_price').value = p.cost_price || 0;
  document.getElementById('wholesale_price').value = p.wholesale_price || 0;
  document.getElementById('retail_price').value = p.retail_price || 0;
  document.getElementById('qty_base').value = p.qty_base || 0;
  document.getElementById('low_level_base').value = p.low_level_base || 0;
  document.getElementById('is_active').value = String(p.is_active ?? 1);
  document.getElementById('default_location_id').value = p.default_location_id || '';

  showUnitFields();
  if(canDelete) document.getElementById('btnDelete').style.display = '';
  mdl.show();
}

async function save(){
  const id = Number(document.getElementById('id').value || 0);
  const action = id ? 'update' : 'create';
  if(action==='create' && !canCreate) return;
  if(action==='update' && !canUpdate) return;

  const payload = {
    id,
    name: document.getElementById('name').value.trim(),
    sku: document.getElementById('sku').value.trim(),
    description: document.getElementById('description').value.trim(),
    source: document.getElementById('source').value.trim(),
    unit_type: document.getElementById('unit_type').value,
    unit_name: document.getElementById('unit_name').value.trim(),
    pieces_per_box: Number(document.getElementById('pieces_per_box').value || 0),
    cost_price: Number(document.getElementById('cost_price').value || 0),
    wholesale_price: Number(document.getElementById('wholesale_price').value || 0),
    retail_price: Number(document.getElementById('retail_price').value || 0),
    qty_base: Number(document.getElementById('qty_base').value || 0),
    low_level_base: Number(document.getElementById('low_level_base').value || 0),
    default_location_id: Number(document.getElementById('default_location_id').value) || null,
    is_active: Number(document.getElementById('is_active').value || 1),
  };

  console.log('[products save] payload:', payload);
  const res = await fetch(BASE_URL + "/api/products.php?action=" + action, {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify(payload)
  });
  console.log('[products save] response status:', res.status, res.statusText);
  const txt = await res.text();
  console.log('[products save] raw response:', txt);
  let json;
  try {
    json = JSON.parse(txt);
  } catch (e) {
    console.error('[products save] JSON parse error:', e);
    alert('Server returned non-JSON response. See console for details.');
    return;
  }
  if(!json.ok){ alert(json.error || "Save failed"); return; }
  mdl.hide();
  await loadProducts();
}

async function del(){
  const id = Number(document.getElementById('id').value || 0);
  if(!id || !canDelete) return;
  if(!confirm("Delete this product?")) return;

  const res = await fetch(BASE_URL + "/api/products.php?action=delete&id=" + id);
  const json = await res.json();
  if(!json.ok){ alert(json.error || "Delete failed"); return; }
  mdl.hide();
  await loadProducts();
}

document.getElementById('btnSearch').addEventListener('click', loadProducts);
document.getElementById('q').addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); loadProducts(); } });

const btnNew = document.getElementById('btnNew');
if(btnNew) btnNew.addEventListener('click', openNew);

document.getElementById('btnSave').addEventListener('click', save);

const btnDelete = document.getElementById('btnDelete');
if(btnDelete) btnDelete.addEventListener('click', del);

tbody.addEventListener('click', (e)=>{
  const btn = e.target.closest('button[data-act]');
  if(!btn) return;
  if(btn.dataset.act === 'edit') openEdit(btn.dataset.id);
});

async function loadLocationsInto(selectId){
  const res = await fetch(BASE_URL + "/api/stock.php?action=locations");
  const json = await res.json();
  if(!json.ok){ alert(json.error || "Failed to load locations"); return; }
  const sel = document.getElementById(selectId);
  sel.innerHTML = '<option value="">— None —</option>';
  json.data.forEach(l=>{
    const o = document.createElement('option');
    o.value = l.id;
    o.textContent = l.name;
    sel.appendChild(o);
  });
}

loadLocationsInto('default_location_id');
loadProducts();
</script>

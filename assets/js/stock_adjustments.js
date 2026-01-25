// assets/js/stock_adjustments.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  const el = (id) => document.getElementById(id);

  const form = el("stockAdjustForm");
  const productId = el("productId");
  const locationId = el("locationId");
  const qtyChange = el("qtyChange");
  const reason = el("reason");
  const note = el("note");
  const btnSave = el("btnSave");
  const formError = el("formError");
  const formSuccess = el("formSuccess");
  const currentStock = el("currentStock");

  let products = [];

  function showElError(id, msg) {
    const box = el(id);
    if (!box) return;
    box.textContent = msg || "";
    box.classList.toggle("d-none", !msg);
  }

  async function loadLocations() {
    const res = await fetch(`${window.APP.BASE_URL}/api/stock.php?action=locations`);
    const j = await res.json();
    if (!j.ok) return;
    locationId.innerHTML = '<option value="">-- Select Location --</option>';
    j.data.forEach(l => {
      const o = document.createElement('option');
      o.value = l.id;
      o.textContent = l.name;
      locationId.appendChild(o);
    });
  }

  async function loadProducts() {
    const res = await fetch(api("list") + "&limit=500");
    const j = await res.json();
    if (!j.ok) return;
    products = j.data || [];

    productId.innerHTML = '<option value="">-- Select Product --</option>';
    products.forEach((p) => {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = `${p.sku || ""} ${p.name || ""}`.trim();
      productId.appendChild(opt);
    });

    // Preselect if provided
    if (window.APP.preselectedProductId) {
      productId.value = window.APP.preselectedProductId;
      updateCurrentStock();
    }
  }

  function updateCurrentStock() {
    const pid = productId.value;
    const p = products.find((x) => String(x.id) === pid);
    if (p) {
      currentStock.innerHTML = `<strong>${p.name}</strong><br>Qty on hand: ${Number(p.qty_on_hand || 0).toLocaleString()} ${p.unit || ""}`;
    } else {
      currentStock.textContent = "Select a product to see current quantity.";
    }
  }

  async function submit(e) {
    e.preventDefault();

    const pid = productId.value;
    const lid = locationId.value;
    const qty = Number(qtyChange.value || 0);
    const r = (reason.value || "").trim();
    const n = (note.value || "").trim();

    if (!pid) {
      showElError("formError", "Select a product");
      return;
    }
    if (!lid) {
      showElError("formError", "Select a location");
      return;
    }
    if (qty === 0) {
      showElError("formError", "Adjustment cannot be zero");
      return;
    }
    if (!r) {
      showElError("formError", "Select a reason");
      return;
    }

    const fd = new FormData();
    fd.append("product_id", pid);
    fd.append("location_id", lid);
    fd.append("qty_change", String(qty));
    fd.append("reason", r);
    fd.append("note", n);
    fd.append("csrf", window.APP.CSRF || "");

    btnSave.disabled = true;
    btnSave.textContent = "Saving...";

    const res = await fetch(api("stock_adjustment"), { method: "POST", body: fd });
    const j = await res.json();

    btnSave.disabled = false;
    btnSave.textContent = "Record Adjustment";

    if (!j.ok) {
      showElError("formError", j.message || "Failed to record adjustment");
      showElError("formSuccess", "");
    } else {
      showElError("formError", "");
      showElError("formSuccess", j.message || "Adjustment recorded successfully");
      form.reset();
      productId.value = "";
      updateCurrentStock();
      setTimeout(() => showElError("formSuccess", ""), 5000);
    }
  }

  if (form) form.addEventListener("submit", submit);
  if (productId) {
    productId.addEventListener("change", updateCurrentStock);
    productId.addEventListener("input", updateCurrentStock);
  }

  loadLocations();
  loadProducts();
})();

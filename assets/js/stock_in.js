// assets/js/stock_in.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  const el = (id) => document.getElementById(id);

  const form = el("stockInForm");
  const productId = el("productId");
  const qtyChange = el("qtyChange");
  const unitPrice = el("unitPrice");
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

  async function loadProducts() {
    const res = await fetch(api("list") + "&limit=500");
    const j = await res.json();
    if (!j.ok) return;
    products = j.data.items || [];

    productId.innerHTML = '<option value="">-- Select Product --</option>';
    products.forEach((p) => {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = `${p.sku || ""} ${p.name || ""}`.trim();
      productId.appendChild(opt);
    });
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
    const qty = Number(qtyChange.value || 0);
    const price = Number(unitPrice.value || 0);
    const n = (note.value || "").trim();

    if (!pid) {
      showElError("formError", "Select a product");
      return;
    }
    if (qty <= 0) {
      showElError("formError", "Quantity must be greater than 0");
      return;
    }

    const fd = new FormData();
    fd.append("product_id", pid);
    fd.append("qty_change", String(qty));
    fd.append("unit_price", String(price));
    fd.append("note", n);
    fd.append("csrf", window.APP.CSRF || "");

    btnSave.disabled = true;
    btnSave.textContent = "Saving...";

    const res = await fetch(api("stock_in_record"), { method: "POST", body: fd });
    const j = await res.json();

    btnSave.disabled = false;
    btnSave.textContent = "Add Stock";

    if (!j.ok) {
      showElError("formError", j.message || "Failed to record stock in");
      showElError("formSuccess", "");
    } else {
      showElError("formError", "");
      showElError("formSuccess", j.message || "Stock added successfully");
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

  loadProducts();
})();

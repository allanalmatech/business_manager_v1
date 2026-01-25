// assets/js/price_updates.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  const el = (id) => document.getElementById(id);

  const form = el("priceUpdateForm");
  const productId = el("productId");
  const costPrice = el("costPrice");
  const wholesalePrice = el("wholesalePrice");
  const retailPrice = el("retailPrice");
  const reason = el("reason");
  const note = el("note");
  const btnSave = el("btnSave");
  const formError = el("formError");
  const formSuccess = el("formSuccess");
  const currentPrices = el("currentPrices");

  let products = [];

  function showElError(id, msg) {
    const box = el(id);
    if (!box) return;
    box.textContent = msg || "";
    box.classList.toggle("d-none", !msg);
  }

  async function loadProducts() {
    const res = await fetch(api("list") + "&limit=500");
    const txt = await res.text();
    console.log('[price_updates loadProducts] raw response:', txt);
    let j;
    try {
      j = JSON.parse(txt);
    } catch (e) {
      console.error('[price_updates loadProducts] JSON parse error:', e);
      return;
    }
    if (!j.ok) return;
    products = j.data || [];

    productId.innerHTML = '<option value="">-- Select Product --</option>';
    products.forEach((p) => {
      const opt = document.createElement("option");
      opt.value = p.id;
      opt.textContent = `${p.sku || ""} ${p.name || ""}`.trim();
      productId.appendChild(opt);
    });
  }

  function updateCurrentPrices() {
    const pid = productId.value;
    const p = products.find((x) => String(x.id) === pid);
    if (p) {
      currentPrices.innerHTML = `
        <strong>${p.name}</strong><br>
        Cost: ${Number(p.cost_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}<br>
        Wholesale: ${Number(p.wholesale_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}<br>
        Retail: ${Number(p.retail_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
      `;
      costPrice.value = p.cost_price || "";
      wholesalePrice.value = p.wholesale_price || "";
      retailPrice.value = p.retail_price || "";
    } else {
      currentPrices.textContent = "Select a product to see current prices.";
      costPrice.value = "";
      wholesalePrice.value = "";
      retailPrice.value = "";
    }
  }

  async function submit(e) {
    e.preventDefault();

    const pid = productId.value;
    const cost = Number(costPrice.value || 0);
    const wholesale = Number(wholesalePrice.value || 0);
    const retail = Number(retailPrice.value || 0);
    const r = (reason.value || "").trim();
    const n = (note.value || "").trim();

    if (!pid) {
      showElError("formError", "Select a product");
      return;
    }
    if (!r) {
      showElError("formError", "Select a reason");
      return;
    }

    const fd = new FormData();
    fd.append("product_id", pid);
    fd.append("cost_price", String(cost));
    fd.append("wholesale_price", String(wholesale));
    fd.append("retail_price", String(retail));
    fd.append("reason", r);
    fd.append("note", n);
    fd.append("csrf", window.APP.CSRF || "");

    btnSave.disabled = true;
    btnSave.textContent = "Saving...";

    const res = await fetch(api("price_update"), { method: "POST", body: fd });
    const txt = await res.text();
    console.log('[price_updates submit] raw response:', txt);
    let j;
    try {
      j = JSON.parse(txt);
    } catch (e) {
      console.error('[price_updates submit] JSON parse error:', e);
      showElError("formError", "Server returned non-JSON response. See console for details.");
      btnSave.disabled = false;
      btnSave.textContent = "Update Prices";
      return;
    }

    btnSave.disabled = false;
    btnSave.textContent = "Update Prices";

    if (!j.ok) {
      showElError("formError", j.message || "Failed to update prices");
      showElError("formSuccess", "");
    } else {
      showElError("formError", "");
      showElError("formSuccess", j.message || "Prices updated successfully");
      form.reset();
      productId.value = "";
      updateCurrentPrices();
      setTimeout(() => showElError("formSuccess", ""), 5000);
    }
  }

  if (form) form.addEventListener("submit", submit);
  if (productId) {
    productId.addEventListener("change", updateCurrentPrices);
    productId.addEventListener("input", updateCurrentPrices);
  }

  loadProducts();
})();

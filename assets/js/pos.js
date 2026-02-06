/* assets/js/pos.js
 * Business Manager V1 - POS Machine Optimized
 */

(() => {
  const CFG = window.POS_CONFIG || {};
  const apiUrl = CFG.apiUrl || "";
  const csrf = CFG.csrf || "";
  const perms = CFG.perms || { discount: true, editPrice: true };

  const $ = (id) => document.getElementById(id);

  const fmt = (n) => {
    const x = Number(n || 0);
    return x.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    }[m]));

  const num = (v) => {
    const x = parseFloat(v);
    return Number.isFinite(x) ? x : 0;
  };

  // ---------- State ----------
  let cart = [];
  let payments = [];
  let lastSearchToken = 0;

  // ---------- Elements ----------
  const elSearchInput = $("product_search");
  const elResultsWrap = $("searchResultsWrap");
  const elResults = $("searchResults");

  const elCartPanel = $("cartPanel");
  const elCartEmptyRow = $("cartEmptyRow");
  const elCartCount = $("cartCount");

  const elDocType = $("doc_type");
  const elLoc = $("selling_location_id");
  const elCustomer = $("customer_id");
  const elNotes = $("sale_notes");

  const elNewSale = $("btnNewSale");
  const elHideResults = $("btnHideResults");

  const elBtnConfirm = $("btnConfirm");

  const elTSubtotal = $("t_subtotal");
  const elTDiscount = $("t_discount");
  const elTGrand = $("t_grand");
  const elTBalanceDisplay = $("t_balance_display");

  const payMethod = $("pay_method");
  const payAmount = $("pay_amount");
  const payBody = $("paymentsBody");
  const payEmptyRow = $("paymentsEmptyRow");
  const btnAddPaymentRow = $("btnAddPaymentRow");
  const btnToggleFullscreen = $("btnToggleFullscreen");

  // ---------- Network ----------
  async function apiPost(action, payload) {
    const res = await fetch(`${apiUrl}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(payload),
    });

    let data = null;
    let text = "";
    try {
      text = await res.text();
      data = JSON.parse(text);
    } catch (_) {
      data = null;
    }

    if (!res.ok) {
      const msg = (data && data.error) ? data.error : (text || `Request failed (${res.status})`);
      throw new Error(msg);
    }

    if (!data || !data.ok) {
      const msg = (data && data.error) ? data.error : "Unknown error";
      throw new Error(msg);
    }

    return data;
  }

  // ---------- Debounce ----------
  function debounce(fn, wait = 250) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  function getPricingMode() {
    const checked = document.querySelector('input[name="pricing_mode"]:checked');
    return checked ? checked.value : "retail";
  }

  function lineTotal(item) {
    const qty = num(item.qty);
    const unit = num(item.unit_price);
    const disc = num(item.discount);
    return Math.max(0, (qty * unit) - disc);
  }

  function calcTotals() {
    const subtotal = cart.reduce((sum, it) => sum + (num(it.qty) * num(it.unit_price)), 0);
    const discount = cart.reduce((sum, it) => sum + num(it.discount), 0);
    const grand = Math.max(0, subtotal - discount);
    const paid = payments.reduce((sum, p) => sum + num(p.amount), 0);
    const balance = grand - paid;
    return { subtotal, discount, grand, paid, balance };
  }

  function renderTotals() {
    const t = calcTotals();
    if (elTSubtotal) elTSubtotal.textContent = fmt(t.subtotal);
    if (elTDiscount) elTDiscount.textContent = fmt(t.discount);
    if (elTGrand) elTGrand.textContent = fmt(t.grand);
    if (elTBalanceDisplay) elTBalanceDisplay.textContent = `Balance: ${fmt(t.balance)}`;
    if (elCartCount) elCartCount.textContent = `${cart.length} item${cart.length === 1 ? '' : 's'}`;

    if (elBtnConfirm) elBtnConfirm.disabled = cart.length === 0;
  }

  function toImg(thumbnail) {
    if (!thumbnail) return "";
    try {
      const arr = JSON.parse(thumbnail);
      if (Array.isArray(arr) && arr.length) thumbnail = arr[0];
    } catch (_) {}

    let url = String(thumbnail || "");
    url = url.replace(/\/+/g, "/");
    if (url.startsWith("/")) url = url.substring(1);
    return CFG.baseUrl ? `${CFG.baseUrl}/${url}` : url;
  }

  // ---------- Cart rendering ----------
  function renderCart() {
    if (!elCartPanel) return;

    elCartPanel.querySelectorAll(".pos-cart-item").forEach((x) => x.remove());

    if (!cart.length) {
      if (elCartEmptyRow) elCartEmptyRow.style.display = "";
      renderTotals();
      return;
    }
    if (elCartEmptyRow) elCartEmptyRow.style.display = "none";

    cart.forEach((it, idx) => {
      const item = document.createElement("div");
      item.className = "pos-cart-item";

      const thumbUrl = toImg(it.thumbnail);
      const thumb = thumbUrl ? `<img src="${esc(thumbUrl)}" alt="">` : "";

      item.innerHTML = `
        <div class="pos-thumb">${thumb}</div>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between">
            <div class="pos-ci-title">${esc(it.name)}</div>
            <button class="btn btn-link text-danger p-0 pos-remove" data-idx="${idx}">
              <i class="bi bi-x-circle-fill"></i>
            </button>
          </div>
          <div class="pos-ci-sub">${esc(it.sku || "")} • ${fmt(it.unit_price)}</div>
          
          <div class="d-flex justify-content-between align-items-center mt-2">
            <div class="pos-qty-group">
              <button class="pos-qty-btn minus" data-idx="${idx}">-</button>
              <input class="pos-qty-input pos-qty" data-idx="${idx}" value="${esc(it.qty)}" readonly>
              <button class="pos-qty-btn plus" data-idx="${idx}">+</button>
            </div>
            <div class="pos-ci-total fw-bold">${fmt(lineTotal(it))}</div>
          </div>
        </div>
      `;

      elCartPanel.appendChild(item);
    });

    renderTotals();
  }

  function addToCart(item) {
    const keyId = item.is_external ? `ext:${item.ext_key}` : `p:${item.product_id}`;
    const existingIndex = cart.findIndex((x) => x._key === keyId);

    if (existingIndex >= 0) {
      cart[existingIndex].qty = num(cart[existingIndex].qty) + num(item.qty || 1);
      renderCart();
      return;
    }

    cart.push({
      _key: keyId,
      product_id: item.product_id || null,
      name: item.name || "Item",
      sku: item.sku || "",
      thumbnail: item.thumbnail || "",
      qty: Math.max(1, num(item.qty || 1)),
      unit_price: num(item.unit_price || 0),
      min_price: item.min_price ? num(item.min_price) : null,
      discount: num(item.discount || 0),
      is_external: !!item.is_external,
      meta: item.meta || {},
      stock_hint: item.stock_hint || "",
    });

    renderCart();
  }

  // ---------- Search & Quick Items ----------
  function showResults() {
    if (elResultsWrap) elResultsWrap.classList.remove("d-none");
  }
  function hideResults() {
    if (elResultsWrap) elResultsWrap.classList.add("d-none");
    if (elResults) elResults.innerHTML = "";
  }

  async function loadQuickItems(cat = "") {
    const grid = $("quickItems");
    if (!grid) return;

    grid.innerHTML = `<div class="p-4 text-center w-100 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</div>`;

    try {
      const data = await apiPost("quick_items", {
        csrf,
        selling_location_id: elLoc?.value || "",
        category: cat
      });

      grid.innerHTML = "";
      if (!data.items || !data.items.length) {
        grid.innerHTML = `<div class="p-4 text-center w-100 text-muted">No items found in this category.</div>`;
        return;
      }

      data.items.forEach((p) => {
        const tile = document.createElement("div");
        tile.className = "pos-quick-tile touch-tile";
        
        const thumbUrl = toImg(p.thumbnail);
        const thumb = thumbUrl 
          ? `<div class="pos-quick-thumb"><img src="${esc(thumbUrl)}" alt=""></div>`
          : `<div class="pos-quick-thumb d-flex align-items-center justify-content-center bg-light text-muted"><i class="bi bi-image" style="font-size: 2rem;"></i></div>`;

        tile.innerHTML = `
          ${thumb}
          <div class="pos-quick-info">
            <div class="pos-quick-name">${esc(p.name)}</div>
            <div class="pos-quick-price">${fmt(getPricingMode() === 'wholesale' ? p.wholesale_price : p.retail_price)}</div>
          </div>
        `;

        tile.addEventListener("click", () => {
          const mode = getPricingMode();
          addToCart({
            product_id: p.id,
            name: p.name,
            sku: p.sku,
            thumbnail: p.thumbnail,
            qty: 1,
            unit_price: mode === "wholesale" ? num(p.wholesale_price) : num(p.retail_price),
            min_price: num(p.wholesale_price || 0),
            discount: 0,
            stock_hint: p.stock_display || ""
          });
        });

        grid.appendChild(tile);
      });
    } catch (e) {
      grid.innerHTML = `<div class="alert alert-danger m-3">Failed to load items.</div>`;
    }
  }

  const doSearch = debounce(async () => {
    const q = (elSearchInput?.value || "").trim();
    if (q.length < 2) {
      hideResults();
      return;
    }

    const myToken = ++lastSearchToken;
    try {
      const data = await apiPost("search_products", {
        csrf,
        q,
        selling_location_id: elLoc?.value || "",
      });
      if (myToken !== lastSearchToken) return;

      if (!elResults) return;
      elResults.innerHTML = "";
      
      if (!data.results || !data.results.length) {
        elResults.innerHTML = `<div class="p-3 text-center text-muted">No results found</div>`;
      } else {
        data.results.forEach(p => {
          const item = document.createElement("button");
          item.className = "list-group-item list-group-item-action d-flex align-items-center gap-3";
          const thumbUrl = toImg(p.thumbnail);
          item.innerHTML = `
            <div style="width:40px;height:40px;background:#eee;border-radius:8px;overflow:hidden flex-shrink-0;">
              ${thumbUrl ? `<img src="${esc(thumbUrl)}" style="width:100%;height:100%;object-fit:cover;">` : ''}
            </div>
            <div class="flex-grow-1 minw-0">
              <div class="fw-bold text-truncate">${esc(p.name)}</div>
              <div class="small text-muted">${esc(p.sku)}</div>
            </div>
            <div class="text-end">
              <div class="fw-bold">${fmt(getPricingMode() === 'wholesale' ? p.wholesale_price : p.retail_price)}</div>
              <div class="small text-success">${esc(p.stock_display)}</div>
            </div>
          `;
          item.addEventListener("click", () => {
            const mode = getPricingMode();
            addToCart({
              product_id: p.id,
              name: p.name,
              sku: p.sku,
              thumbnail: p.thumbnail,
              qty: 1,
              unit_price: mode === "wholesale" ? num(p.wholesale_price) : num(p.retail_price),
              min_price: num(p.wholesale_price || 0),
              discount: 0,
              stock_hint: p.stock_display || ""
            });
            elSearchInput.value = "";
            hideResults();
          });
          elResults.appendChild(item);
        });
      }
      showResults();
    } catch (e) {
      console.error(e);
    }
  }, 300);

  // ---------- Payments ----------
  function renderPayments() {
    if (!payBody) return;
    payBody.querySelectorAll("tr:not(#paymentsEmptyRow)").forEach(x => x.remove());

    if (!payments.length) {
      if (payEmptyRow) payEmptyRow.style.display = "";
      renderTotals();
      return;
    }
    if (payEmptyRow) payEmptyRow.style.display = "none";

    payments.forEach((p, idx) => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td class="py-1"><span class="badge bg-light text-dark border">${esc(p.method)}</span></td>
        <td class="py-1 text-end fw-bold">${fmt(p.amount)}</td>
        <td class="py-1 text-end" style="width:40px;">
          <button class="btn btn-sm text-danger p-0" data-pay-del="${idx}"><i class="bi bi-trash"></i></button>
        </td>
      `;
      payBody.appendChild(tr);
    });

    renderTotals();
  }

  function addPaymentFromInputs() {
    const amt = num(payAmount?.value);
    if (amt <= 0) return;

    payments.push({
      method: payMethod?.value || "cash",
      provider: "",
      reference: "",
      amount: amt
    });

    if (payAmount) payAmount.value = "";
    renderPayments();
  }

  // ---------- Fullscreen ----------
  function toggleFullscreen() {
    document.body.classList.toggle("pos-fullscreen");
    const icon = btnToggleFullscreen.querySelector("i");
    if (document.body.classList.contains("pos-fullscreen")) {
      icon.className = "bi bi-fullscreen-exit";
    } else {
      icon.className = "bi bi-arrows-fullscreen";
    }
  }

  // ---------- Preview & Confirm ----------
  async function openPreview() {
    if (!cart.length) return alert("Cart is empty");
    
    const modalEl = $("previewModal");
    if (!modalEl) return;
    
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();

    const bodyHost = $("previewModalBody");
    const totals = calcTotals();

    const payload = {
      csrf,
      doc_type: elDocType?.value || "receipt",
      pricing_mode: getPricingMode(),
      selling_location_id: elLoc?.value || "",
      customer_id: elCustomer?.value || "",
      notes: (elNotes?.value || "").trim(),
      items: cart.map(it => ({
        product_id: it.product_id,
        name: it.name,
        sku: it.sku,
        thumbnail: it.thumbnail,
        qty: num(it.qty),
        unit_price: num(it.unit_price),
        discount: num(it.discount),
        is_external: !!it.is_external,
        meta: it.meta || {}
      })),
      payments: payments.map(p => ({
        method: p.method,
        provider: p.provider,
        reference: p.reference,
        amount: num(p.amount)
      })),
      totals: totals
    };

    try {
      const res = await fetch(`${CFG.baseUrl}/modules/pos/pos_preview.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        body: JSON.stringify(payload)
      });
      const html = await res.text();
      bodyHost.innerHTML = html;
    } catch (e) {
      bodyHost.innerHTML = `<div class="alert alert-danger">Failed to load preview</div>`;
    }
  }

  async function confirmSale() {
    const payload = {
      csrf,
      doc_type: elDocType?.value || "receipt",
      pricing_mode: getPricingMode(),
      selling_location_id: elLoc?.value || "",
      customer_id: elCustomer?.value || "",
      notes: (elNotes?.value || "").trim(),
      items: cart.map(it => ({
        product_id: it.product_id,
        name: it.name,
        sku: it.sku,
        qty: it.qty,
        unit_price: it.unit_price,
        discount: it.discount,
        is_external: !!it.is_external
      })),
      payments: payments.map(p => ({
        method: p.method,
        provider: p.provider,
        amount: p.amount,
        reference: p.reference
      }))
    };

    const data = await apiPost("confirm_sale", payload);
    const printUrl = data.print_url || `${CFG.baseUrl}/modules/pos/pos_print.php?id=${data.sale_id}`;
    window.open(printUrl, "_blank");
    newSale();
  }

  function newSale() {
    cart = [];
    payments = [];
    renderCart();
    renderPayments();
    if (elSearchInput) elSearchInput.value = "";
    if (payAmount) payAmount.value = "";
  }

  // ---------- Events ----------
  function wireEvents() {
    elSearchInput?.addEventListener("input", doSearch);
    elHideResults?.addEventListener("click", hideResults);
    elLoc?.addEventListener("change", () => loadQuickItems());
    
    document.querySelectorAll('input[name="pricing_mode"]').forEach(r => {
      r.addEventListener("change", () => loadQuickItems());
    });

    btnToggleFullscreen?.addEventListener("click", toggleFullscreen);

    // Cart Events
    elCartPanel?.addEventListener("click", (e) => {
      const btnRemove = e.target.closest(".pos-remove");
      const btnPlus = e.target.closest(".plus");
      const btnMinus = e.target.closest(".minus");

      if (btnRemove) {
        const idx = parseInt(btnRemove.dataset.idx);
        cart.splice(idx, 1);
        renderCart();
      } else if (btnPlus) {
        const idx = parseInt(btnPlus.dataset.idx);
        cart[idx].qty++;
        renderCart();
      } else if (btnMinus) {
        const idx = parseInt(btnMinus.dataset.idx);
        if (cart[idx].qty > 1) {
          cart[idx].qty--;
          renderCart();
        }
      }
    });

    // Payment Shortcuts
    document.querySelectorAll(".btn-shortcut").forEach(btn => {
      btn.addEventListener("click", () => {
        const amtType = btn.dataset.amt;
        const totals = calcTotals();
        
        if (amtType === "exact") {
          payAmount.value = totals.balance > 0 ? totals.balance.toFixed(2) : "";
        } else if (!isNaN(amtType)) {
          const current = num(payAmount.value);
          payAmount.value = (current + num(amtType)).toFixed(2);
        }
      });
    });

    btnAddPaymentRow?.addEventListener("click", addPaymentFromInputs);
    
    payBody?.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-pay-del]");
      if (btn) {
        const idx = parseInt(btn.dataset.payDel);
        payments.splice(idx, 1);
        renderPayments();
      }
    });

    // Category Tabs
    document.querySelectorAll(".pos-tab-modern").forEach(tab => {
      tab.addEventListener("click", () => {
        document.querySelectorAll(".pos-tab-modern").forEach(t => t.classList.remove("active"));
        tab.classList.add("active");
        loadQuickItems(tab.dataset.cat);
      });
    });

    elBtnConfirm?.addEventListener("click", openPreview);

    document.addEventListener("click", async (e) => {
      const btn = e.target.closest("#btnConfirmFromPreview");
      if (!btn) return;

      btn.disabled = true;
      const oldText = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

      try {
        await confirmSale();
        const modalEl = $("previewModal");
        if (modalEl) bootstrap.Modal.getInstance(modalEl).hide();
      } catch (err) {
        alert(err.message || "Failed to finalize sale");
        btn.disabled = false;
        btn.innerHTML = oldText;
      }
    });

    elNewSale?.addEventListener("click", () => {
      if (cart.length && confirm("Clear current sale?")) newSale();
    });
  }

  function init() {
    wireEvents();
    renderCart();
    renderPayments();
    loadQuickItems();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

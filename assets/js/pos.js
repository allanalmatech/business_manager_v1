/* assets/js/pos.js (FULL - fixed preview + finalize + modal IDs fallback)
 * Business Manager V1 - POS (Core JS + Fetch)
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
  const elTPaid = $("t_paid");
  const elTBalance = $("t_balance");

  const payMethod = $("pay_method");
  const payProvider = $("pay_provider");
  const payAmount = $("pay_amount");
  const payRef = $("pay_reference");
  const payBody = $("paymentsBody");
  const payEmptyRow = $("paymentsEmptyRow");
  const btnAddPaymentRow = $("btnAddPaymentRow");

  const btnAddExternal = $("btnAddExternal");

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
    if (elTPaid) elTPaid.textContent = fmt(t.paid);
    if (elTBalance) elTBalance.textContent = fmt(t.balance);

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

      const thumb = it.thumbnail ? `<img src="${esc(toImg(it.thumbnail))}" alt="">` : "";

      const priceDisabled = perms.editPrice ? "" : "disabled";
      const discDisabled = perms.discount ? "" : "disabled";

      item.innerHTML = `
        <div class="pos-thumb">${thumb}</div>

        <div>
          <div class="pos-ci-title">${esc(it.name)}</div>
          <div class="pos-ci-sub">
            ${it.sku ? `SKU: ${esc(it.sku)} • ` : ""}
            ${esc(it.stock_hint || "")}
          </div>

          <div class="pos-ci-controls">
            <div>
              <label>Qty</label>
              <input class="form-control form-control-sm text-end pos-qty"
                     data-idx="${idx}" value="${esc(it.qty)}" inputmode="decimal">
            </div>

            <div>
              <label>Price</label>
              <input class="form-control form-control-sm text-end pos-price"
                     data-idx="${idx}" value="${esc(it.unit_price)}" inputmode="decimal" ${priceDisabled}>
              ${it.min_price ? `<div class="small text-muted">Min: ${fmt(it.min_price)}</div>` : ``}
            </div>

            <div>
              <label>Discount</label>
              <input class="form-control form-control-sm text-end pos-discount"
                     data-idx="${idx}" value="${esc(it.discount)}" inputmode="decimal" ${discDisabled}>
            </div>
          </div>

          <div class="pos-ci-footer">
            <div class="pos-ci-total">${fmt(lineTotal(it))}</div>

            <button class="btn btn-outline-danger btn-sm pos-ci-remove pos-remove"
                    data-idx="${idx}" type="button" title="Remove">
              <i class="bi bi-trash"></i>
            </button>
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

  // ---------- Search ----------
  function showResults() {
    if (elResultsWrap) elResultsWrap.classList.remove("d-none");
  }
  function hideResults() {
    if (elResultsWrap) elResultsWrap.classList.add("d-none");
    if (elResults) elResults.innerHTML = "";
  }

  function renderSearchResults(items) {
    if (!elResults) return;

    elResults.innerHTML = "";
    if (!items || items.length === 0) {
      elResults.innerHTML = `<div class="text-muted small px-2 py-2">No results found.</div>`;
      showResults();
      return;
    }

    items.forEach((p) => {
      const thumbUrl = p.thumbnail ? toImg(p.thumbnail) : "";
      const thumb = thumbUrl
        ? `<div class="pos-s-thumb"><img src="${esc(thumbUrl)}" alt=""></div>`
        : `<div class="pos-s-thumb ph"></div>`;

      const stock = p.stock_display || "";
      const priceRetail = fmt(p.retail_price || 0);
      const priceWholesale = fmt(p.wholesale_price || 0);

      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "list-group-item list-group-item-action";
      btn.innerHTML = `
        <div class="d-flex align-items-center gap-2">
          ${thumb}
          <div class="minw-0 flex-grow-1">
            <div class="fw-semibold text-truncate">${esc(p.name)}</div>
            <div class="text-muted small text-truncate">${esc(p.sku || "")}</div>
            ${stock ? `<div class="small text-muted">${esc(stock)}</div>` : ``}
          </div>
          <div class="text-end small">
            <div><span class="text-muted">R:</span> <span class="fw-semibold">${priceRetail}</span></div>
            <div><span class="text-muted">W:</span> <span class="fw-semibold">${priceWholesale}</span></div>
          </div>
        </div>
      `;

      btn.addEventListener("click", () => {
        const mode = getPricingMode();
        const unitPrice = mode === "wholesale" ? num(p.wholesale_price) : num(p.retail_price);

        addToCart({
          product_id: p.id,
          name: p.name,
          sku: p.sku,
          thumbnail: p.thumbnail,
          qty: 1,
          unit_price: unitPrice,
          min_price: num(p.wholesale_price || 0),
          discount: 0,
          is_external: false,
          stock_hint: p.stock_display || "",
        });

        if (elSearchInput) {
          elSearchInput.value = "";
          elSearchInput.focus();
        }
        hideResults();
      });

      elResults.appendChild(btn);
    });

    showResults();
  }

  const doSearch = debounce(async () => {
    const q = (elSearchInput?.value || "").trim();
    if (!q) {
      hideResults();
      return;
    }

    const token = ++lastSearchToken;
    try {
      const data = await apiPost("search_products", {
        csrf,
        q,
        selling_location_id: elLoc?.value || "",
        pricing_mode: getPricingMode(),
      });

      if (token !== lastSearchToken) return;
      renderSearchResults(data.results || []);
    } catch (e) {
      hideResults();
      console.error(e);
    }
  }, 250);

  // ---------- Quick Items ----------
  async function loadQuickItems(categoryId = "") {
    const host = $("quickItems");
    if (!host) return;

    host.innerHTML = `<div class="text-muted small">Loading…</div>`;

    try {
      const data = await apiPost("quick_items", {
        csrf,
        category_id: categoryId,
        selling_location_id: elLoc?.value || "",
        pricing_mode: getPricingMode(),
      });

      const items = data.items || [];
      if (!items.length) {
        host.innerHTML = `<div class="text-muted small">No items found.</div>`;
        return;
      }

      host.innerHTML = "";
      items.forEach((p) => {
        const mode = getPricingMode();
        const unitPrice = mode === "wholesale" ? num(p.wholesale_price) : num(p.retail_price);

        const tile = document.createElement("button");
        tile.type = "button";
        tile.className = "pos-quick-tile";

        const thumbUrl = p.thumbnail ? toImg(p.thumbnail) : "";
        const thumb = thumbUrl ? `<img src="${esc(thumbUrl)}" alt="">` : ``;

        tile.innerHTML = `
          <div class="pos-quick-thumb">${thumb}</div>
          <div class="pos-quick-name">${esc(p.name)}</div>
          <div class="pos-quick-price">${fmt(unitPrice)}</div>
        `;

        tile.addEventListener("click", () => {
          addToCart({
            product_id: p.id,
            name: p.name,
            sku: p.sku,
            thumbnail: p.thumbnail,
            qty: 1,
            unit_price: unitPrice,
            min_price: num(p.wholesale_price || 0),
            discount: 0,
            is_external: false,
            stock_hint: p.stock_display || "",
          });
        });

        host.appendChild(tile);
      });
    } catch (e) {
      host.innerHTML = `<div class="text-danger small">${esc(e.message || "Failed to load quick items.")}</div>`;
      console.error(e);
    }
  }

  // ---------- Payments ----------
  function renderPayments() {
    if (!payBody) return;

    payBody.querySelectorAll("tr.pos-pay-row").forEach((tr) => tr.remove());

    if (!payments.length) {
      if (payEmptyRow) payEmptyRow.style.display = "";
      renderTotals();
      return;
    }
    if (payEmptyRow) payEmptyRow.style.display = "none";

    payments.forEach((p, idx) => {
      const tr = document.createElement("tr");
      tr.className = "pos-pay-row";
      tr.innerHTML = `
        <td>${esc(p.method)}</td>
        <td>${esc(p.provider || "")}</td>
        <td class="text-muted">${esc(p.reference || "")}</td>
        <td class="text-end fw-semibold">${fmt(p.amount)}</td>
        <td class="text-end">
          <button type="button" class="btn btn-sm btn-outline-danger" data-pay-del="${idx}">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      `;
      payBody.appendChild(tr);
    });

    renderTotals();
  }

  function addPaymentFromInputs() {
    const method = (payMethod?.value || "").trim();
    const provider = (payProvider?.value || "").trim();
    const amount = num(payAmount?.value || 0);
    const reference = (payRef?.value || "").trim();

    if (amount <= 0) return alert("Enter a valid payment amount.");
    if (method === "bank" && !reference) return alert("Bank payments require a reference.");

    payments.push({ method, provider, amount, reference });

    if (payAmount) payAmount.value = "";
    if (payRef) payRef.value = "";

    renderPayments();
  }

  // ---------- Preview (FIXED) ----------
  async function openPreview() {
    if (!cart.length) return alert("Add items to cart first.");

    // Check debt permissions and payment balance
    const totals = calcTotals();
    if (totals.balance > 0 && !CFG.perms.debt) {
      alert(`Insufficient payment! Balance: ${fmt(totals.balance)}\n\nYou don't have permission to allow debt. Please add more payment or reduce the sale amount.`);
      return;
    }

    const modalEl = document.getElementById("previewModal");
    const bodyHost = document.getElementById("previewModalBody") || document.getElementById("previewContent");

    if (!modalEl || !bodyHost) {
      // If modal markup isn't on the page, fallback to direct confirm
      await confirmSale();
      return;
    }

    bodyHost.innerHTML =
      '<div class="text-center py-4">' +
      '<div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>' +
      '<div class="mt-2">Loading preview...</div>' +
      "</div>";

    // show modal (safe instance)
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();

    const payload = {
      csrf,
      doc_type: elDocType?.value || "receipt",
      pricing_mode: getPricingMode(),
      selling_location_id: elLoc?.value || "",
      customer_id: elCustomer?.value || "",
      notes: (elNotes?.value || "").trim(),
      items: cart.map((it) => ({
        product_id: it.product_id,
        name: it.name,
        sku: it.sku,
        thumbnail: it.thumbnail,
        qty: num(it.qty),
        unit_price: num(it.unit_price),
        discount: num(it.discount),
        is_external: !!it.is_external,
        ext_key: it.is_external ? it._key : null,
        meta: it.meta || {},
      })),
      payments: payments.map((p) => ({
        method: p.method,
        provider: p.provider,
        reference: p.reference,
        amount: num(p.amount),
      })),
      totals: totals, // Include totals for change calculation
    };

    try {
      const url = `${CFG.baseUrl}/modules/pos/pos_preview.php`;

      const res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        body: JSON.stringify(payload),
      });

      const html = await res.text();

      if (!res.ok) {
        bodyHost.innerHTML = html || `<div class="alert alert-danger">Preview failed (${res.status})</div>`;
        return;
      }

      bodyHost.innerHTML = html;
    } catch (e) {
      console.error(e);
      bodyHost.innerHTML = `<div class="alert alert-danger m-0">Failed to load preview: ${esc(e.message || e)}</div>`;
    }
  }

  async function confirmSale() {
    if (!cart.length) return alert("Add items to cart first.");

    const payload = {
      csrf,
      doc_type: elDocType?.value || "receipt",
      pricing_mode: getPricingMode(),
      selling_location_id: elLoc?.value || "",
      customer_id: elCustomer?.value || "",
      notes: (elNotes?.value || "").trim(),
      items: cart.map((it) => ({
        product_id: it.product_id,
        name: it.name,
        sku: it.sku,
        thumbnail: it.thumbnail,
        qty: it.qty,
        unit_price: it.unit_price,
        discount: it.discount,
        is_external: !!it.is_external,
        ext_key: it.is_external ? it._key : null,
        meta: it.meta || {},
      })),
      payments: payments.map((p) => ({
        method: p.method,
        provider: p.provider,
        amount: p.amount,
        reference: p.reference,
      })),
    };

    const data = await apiPost("confirm_sale", payload);

    // Close modal if it's open
    const modalEl = document.getElementById("previewModal");
    if (modalEl) {
      const bsModal = bootstrap.Modal.getInstance(modalEl);
      if (bsModal) bsModal.hide();
    }

    // Open print window
    const printUrl =
      data.print_url ||
      `${CFG.baseUrl}/modules/pos/pos_print.php?id=${encodeURIComponent(data.sale_id || "")}`;
    window.open(printUrl, "_blank");

    // Reset and go back to POS
    newSale();
  }

  function newSale() {
    cart = [];
    payments = [];
    hideResults();
    renderCart();
    renderPayments();
    if (elNotes) elNotes.value = "";
    if (elCustomer) elCustomer.value = "";
    if (elSearchInput) elSearchInput.value = "";
  }

  // ---------- Events ----------
  function wireEvents() {
    elSearchInput?.addEventListener("input", doSearch);

    elSearchInput?.addEventListener("keydown", (e) => {
      if (e.key === "Escape") hideResults();
    });

    elHideResults?.addEventListener("click", hideResults);

    elLoc?.addEventListener("change", () => {
      loadQuickItems();
      if (!(elResultsWrap?.classList.contains("d-none"))) doSearch();
    });

    document.querySelectorAll('input[name="pricing_mode"]').forEach((r) => {
      r.addEventListener("change", () => {
        loadQuickItems();
        if (!(elResultsWrap?.classList.contains("d-none"))) doSearch();
      });
    });

    elCartPanel?.addEventListener("click", (e) => {
      const btnDel = e.target.closest?.(".pos-remove");
      if (btnDel) {
        const idx = parseInt(btnDel.getAttribute("data-idx"), 10);
        if (!Number.isFinite(idx)) return;
        cart.splice(idx, 1);
        renderCart();
      }
    });

    elCartPanel?.addEventListener("input", (e) => {
      const qtyEl = e.target.closest?.(".pos-qty");
      const priceEl = e.target.closest?.(".pos-price");
      const discEl = e.target.closest?.(".pos-discount");

      if (qtyEl) {
        const idx = parseInt(qtyEl.getAttribute("data-idx"), 10);
        if (!Number.isFinite(idx) || !cart[idx]) return;
        cart[idx].qty = Math.max(1, num(qtyEl.value || 1));
        renderCart();
        return;
      }

      if (priceEl) {
        const idx = parseInt(priceEl.getAttribute("data-idx"), 10);
        if (!Number.isFinite(idx) || !cart[idx]) return;
        let v = num(priceEl.value);
        const minP = cart[idx].min_price ? num(cart[idx].min_price) : 0;
        if (minP > 0 && v < minP) v = minP;
        cart[idx].unit_price = v;
        renderCart();
        return;
      }

      if (discEl) {
        const idx = parseInt(discEl.getAttribute("data-idx"), 10);
        if (!Number.isFinite(idx) || !cart[idx]) return;
        const gross = num(cart[idx].qty) * num(cart[idx].unit_price);
        let d = num(discEl.value);
        d = Math.max(0, Math.min(d, gross));
        cart[idx].discount = d;
        renderCart();
      }
    });

    btnAddPaymentRow?.addEventListener("click", addPaymentFromInputs);

    payBody?.addEventListener("click", (e) => {
      const btn = e.target.closest?.("[data-pay-del]");
      if (!btn) return;
      const idx = parseInt(btn.getAttribute("data-pay-del"), 10);
      if (!Number.isFinite(idx)) return;
      payments.splice(idx, 1);
      renderPayments();
    });

    // Confirm button opens preview
    elBtnConfirm?.addEventListener("click", openPreview);

    // Finalize from modal (id MUST be btnConfirmFromPreview)
    document.addEventListener("click", async (e) => {
      const btn = e.target?.closest?.("#btnConfirmFromPreview");
      if (!btn) return;

      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

      try {
        await confirmSale(); // closes modal on success + opens print + resets
      } catch (err) {
        console.error(err);
        btn.disabled = false;
        btn.innerHTML = originalHtml;

        const bodyHost = document.getElementById("previewModalBody") || document.getElementById("previewContent");
        if (bodyHost) {
          bodyHost.innerHTML = `
            <div class="alert alert-danger">
              <div class="fw-semibold mb-1">Sale Error</div>
              <div class="small">${esc(err.message || "Failed to finalize sale")}</div>
            </div>
          `;
        } else {
          alert(err.message || "Failed to finalize sale");
        }
      }
    });

    // Clear modal body when hidden
    const modalEl = document.getElementById("previewModal");
    if (modalEl) {
      modalEl.addEventListener("hidden.bs.modal", () => {
        const bodyHost = document.getElementById("previewModalBody") || document.getElementById("previewContent");
        if (bodyHost) bodyHost.innerHTML = "";
      });
    }

    elNewSale?.addEventListener("click", () => {
      if ((cart.length || payments.length) && !confirm("Start a new sale? Current cart will be cleared.")) return;
      newSale();
    });

    btnAddExternal?.addEventListener("click", () => {
      alert("External modal not included here. If you want it, tell me and I’ll add the full Bootstrap modal markup too.");
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
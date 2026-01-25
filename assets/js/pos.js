/* assets/js/pos.js */
(() => {
  const CFG = window.POS_CONFIG || {};
  const apiUrl = CFG.apiUrl;
  const csrf = CFG.csrf;
  const canDiscount = !!(CFG.perms && CFG.perms.discount);
  const canEditPrice = !!(CFG.perms && CFG.perms.editPrice);

  // DOM
  const elSearch = document.getElementById("product_search");
  const elResultsWrap = document.getElementById("searchResultsWrap");
  const elResults = document.getElementById("searchResults");
  const elHideResults = document.getElementById("btnHideResults");

  const elLocation = document.getElementById("selling_location_id");
  const elPricingModeRetail = document.getElementById("pm_retail");
  const elPricingModeWholesale = document.getElementById("pm_wholesale");

  const elCartBody = document.getElementById("cartBody");
  const elCartEmpty = document.getElementById("cartEmptyRow");

  const elSubtotal = document.getElementById("t_subtotal");
  const elDiscount = document.getElementById("t_discount");
  const elGrand = document.getElementById("t_grand");
  const elPaid = document.getElementById("t_paid");
  const elBalance = document.getElementById("t_balance");

  const btnPayments = document.getElementById("btnPayments");
  const btnConfirm = document.getElementById("btnConfirm");

  // state
  let searchTimer = null;
  let lastResults = [];
  let cart = []; // {key, is_external, product_id, name, sku, thumb, qty_base, qty_unit, unit_price, discount_amount, wholesale_floor, stock_qty_base}
  let payments = []; // {method, provider, reference, amount}

  // ---------------- helpers ----------------
  const money = (n) => {
    const x = Number(n || 0);
    return x.toLocaleString(undefined, { maximumFractionDigits: 2 });
  };

  const pricingMode = () => (elPricingModeWholesale && elPricingModeWholesale.checked) ? "wholesale" : "retail";

  function showResults() { elResultsWrap.classList.remove("d-none"); }
  function hideResults() { elResultsWrap.classList.add("d-none"); elResults.innerHTML = ""; lastResults = []; }

  function toast(msg, type="danger") {
    // Minimal fallback (replace with your toast system if you have one)
    alert(msg);
  }

  async function apiGet(params) {
    const url = apiUrl + "?" + new URLSearchParams(params).toString();
    const res = await fetch(url, { credentials: "same-origin" });
    const text = await res.text();
    
    try {
      return JSON.parse(text);
    } catch (e) {
      console.error("API Response not JSON:", text);
      throw new Error("Invalid JSON response: " + text.substring(0, 200));
    }
  }

  async function apiPost(action, data) {
    const form = new FormData();
    form.append("action", action);
    form.append("csrf", csrf);
    form.append("payload", JSON.stringify(data));

    const res = await fetch(apiUrl, { method: "POST", body: form, credentials: "same-origin" });
    
    if (!res.ok) {
      const text = await res.text();
      console.error(`API Error ${res.status}:`, text);
      throw new Error(`HTTP ${res.status}: ${text.substring(0, 200)}`);
    }
    
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      console.error("API Response not JSON:", text);
      throw new Error("Invalid JSON response: " + text.substring(0, 200));
    }
  }

  function ensureCartEmptyRow() {
    if (!elCartEmpty) return;
    elCartEmpty.style.display = cart.length ? "none" : "";
  }

  function calcTotals() {
    let subtotal = 0;
    let discount = 0;
    let grand = 0;

    for (const it of cart) {
      const qty = Number(it.qty_base || 0);
      const price = Number(it.unit_price || 0);
      const disc = Number(it.discount_amount || 0);

      subtotal += qty * price;
      discount += disc;
      grand += Math.max(0, (qty * price) - disc);
    }

    const paid = payments.reduce((a, p) => a + Number(p.amount || 0), 0);
    const balance = Math.max(0, grand - paid);

    elSubtotal.textContent = money(subtotal);
    elDiscount.textContent = money(discount);
    elGrand.textContent = money(grand);
    elPaid.textContent = money(paid);
    elBalance.textContent = money(balance);

    const hasItems = cart.length > 0;
    btnPayments.disabled = !hasItems;
    btnConfirm.disabled = !hasItems;
  }

  function renderCart() {
    ensureCartEmptyRow();

    // remove all rows except empty row
    [...elCartBody.querySelectorAll("tr.cart-row")].forEach(tr => tr.remove());

    const tbody = elCartBody.querySelector('tbody');
    if (!tbody) return;

    for (const it of cart) {
      const tr = document.createElement("tr");
      tr.className = "cart-row pos-cart-item";
      tr.dataset.key = it.key;

      const imgSrc = it.thumb ? (it.thumb.startsWith('http') ? it.thumb : (CFG.baseUrl ? `${CFG.baseUrl}/${it.thumb.replace(/^\/+/, '')}` : it.thumb)) : (CFG.baseUrl ? `${CFG.baseUrl}/assets/img/product-placeholder.svg` : "");

      tr.innerHTML = `
        <td>
          <div class="d-flex gap-2 align-items-center">
            <div class="pos-thumb">
              ${imgSrc ? `<img src="${imgSrc}" alt="">` : `<div class="pos-thumb-fallback"></div>`}
            </div>
            <div>
              <div class="fw-semibold">${escapeHtml(it.name)}</div>
              <div class="text-muted small">${it.sku ? "SKU: " + escapeHtml(it.sku) : it.is_external ? "External/B2B" : ""}</div>
              ${it.stock_qty_base != null && !it.is_external ? `<div class="small text-muted">Stock: ${money(it.stock_qty_base)}</div>` : ""}
            </div>
          </div>
        </td>

        <td class="text-end">
          <div class="d-flex align-items-center gap-1 justify-content-end">
            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease">-</button>
            <span class="qty-display px-2">${Number(it.qty_base)}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase">+</button>
            <button type="button" class="btn btn-sm btn-outline-danger qty-btn" data-action="remove">×</button>
          </div>
          <div class="small text-muted mt-1">${escapeHtml(it.qty_unit || "piece")}</div>
        </td>

        <td class="text-end">
          ${canEditPrice ? `<input type="number" class="form-control form-control-sm text-end price" min="0" step="100" value="${Number(it.unit_price)}">` : `<div class="fw-semibold">${money(it.unit_price)}</div>`}
          ${it.wholesale_floor != null ? `<div class="small text-muted mt-1">Min: ${money(it.wholesale_floor)}</div>` : ""}
        </td>

        <td class="text-end">
          ${canDiscount ? `<input type="number" class="form-control form-control-sm text-end disc" min="0" step="0.01" value="${Number(it.discount_amount || 0)}">` : `<div class="text-muted">${money(it.discount_amount || 0)}</div>`}
        </td>

        <td class="text-end">
          <div class="fw-semibold line-total">${money(Math.max(0,(it.qty_base*it.unit_price)-(it.discount_amount||0)))}</div>
        </td>

        <td>
          <!-- Remove button handled by X in quantity controls -->
        </td>
      `;

      tbody.appendChild(tr);
    }

    // bind row events
    [...elCartBody.querySelectorAll("tr.cart-row")].forEach(tr => {
      const key = tr.dataset.key;
      const it = cart.find(x => x.key === key);
      if (!it) return;

      const qtyDisplay = tr.querySelector(".qty-display");
      const lineTotalEl = tr.querySelector(".line-total");
      const priceEl = tr.querySelector(".price");
      const discEl = tr.querySelector(".disc");

      // Quantity button handlers
      tr.querySelectorAll(".qty-btn").forEach(btn => {
        btn.addEventListener("click", () => {
          const action = btn.dataset.action;
          const currentQty = Number(it.qty_base || 0);
          const unitType = it.qty_unit || "piece";
          const step = unitType === "piece" ? 1 : 0.01;
          
          if (action === "increase") {
            it.qty_base = currentQty + step;
          } else if (action === "decrease") {
            it.qty_base = Math.max(0, currentQty - step);
          } else if (action === "remove") {
            cart = cart.filter(x => x.key !== key);
            renderCart();
            return;
          }
          
          qtyDisplay.textContent = Number(it.qty_base);
          lineTotalEl.textContent = money(Math.max(0, (it.qty_base * it.unit_price) - (it.discount_amount || 0)));
          calcTotals();
        });
      });

      // Price input handler
      if (priceEl) {
        priceEl.addEventListener("input", () => {
          const v = Number(priceEl.value || 0);
          it.unit_price = v > 0 ? v : 0;
          lineTotalEl.textContent = money(Math.max(0, (it.qty_base * it.unit_price) - (it.discount_amount || 0)));
          calcTotals();
        });
      }

      // Discount input handler
      if (discEl) {
        discEl.addEventListener("input", () => {
          const v = Number(discEl.value || 0);
          it.discount_amount = v > 0 ? v : 0;
          lineTotalEl.textContent = money(Math.max(0, (it.qty_base * it.unit_price) - (it.discount_amount || 0)));
          calcTotals();
        });
      }
    });

    calcTotals();
  }

  function escapeHtml(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  // ---------------- search UI ----------------
  async function doLiveSearch(q) {
    const query = String(q || "").trim();
    if (!query) { hideResults(); return; }

    const location_id = elLocation ? elLocation.value : "";
    const data = await apiGet({ action: "product_search", q: query, location_id });

    if (!data || !data.ok) {
      hideResults();
      return;
    }

    lastResults = Array.isArray(data.results) ? data.results : [];
    if (lastResults.length === 0) { hideResults(); return; }

    renderSearchResults(lastResults);
    showResults();
  }

  function renderSearchResults(results) {
    elResults.innerHTML = "";
    const base = CFG.baseUrl || "";

    results.forEach((p, idx) => {
      const thumb = (p.thumb || "");
      const img = thumb ? (thumb.startsWith('http') ? thumb : (base ? `${base}/${thumb.replace(/^\/+/, '')}` : thumb)) : (base ? `${base}/assets/img/product-placeholder.svg` : "");
      const sku = p.sku || "";
      const name = p.name || "";
      const tags = p.tags || "";
      const stock = (p.stock_qty_base != null) ? Number(p.stock_qty_base) : null;

      const item = document.createElement("button");
      item.type = "button";
      item.className = "list-group-item list-group-item-action d-flex align-items-center gap-2";
      item.dataset.idx = String(idx);

      item.innerHTML = `
        <div class="pos-thumb">
          ${img ? `<img src="${img}" alt="">` : `<div class="pos-thumb-fallback"></div>`}
        </div>
        <div class="flex-grow-1 text-start">
          <div class="fw-semibold">${escapeHtml(name)}</div>
          <div class="small text-muted">
            ${sku ? `SKU: ${escapeHtml(sku)}` : ""}
            ${tags ? ` • Tags: ${escapeHtml(tags)}` : ""}
            ${stock != null ? ` • Stock: ${money(stock)}` : ""}
          </div>
        </div>
        <div class="text-end small">
          <div>R: <b>${money(p.retail_price)}</b></div>
          <div class="text-muted">W: ${money(p.wholesale_price)}</div>
        </div>
      `;

      item.addEventListener("click", () => addProductToCart(p));
      elResults.appendChild(item);
    });
  }

  function addProductToCart(p) {
    const mode = pricingMode();
    const price = (mode === "wholesale") ? Number(p.wholesale_price) : Number(p.retail_price);

    // if already in cart, increase qty by 1
    const existing = cart.find(x => !x.is_external && Number(x.product_id) === Number(p.id));
    if (existing) {
      existing.qty_base = Number(existing.qty_base || 0) + 1;
      renderCart();
      calcTotals();
      elSearch.value = "";
      elSearch.focus();
      hideResults();
      return;
    }

    cart.push({
      key: "p_" + p.id,
      is_external: false,
      product_id: Number(p.id),
      name: p.name,
      sku: p.sku || "",
      thumb: p.thumb || "",
      qty_base: 1,
      qty_unit: "piece",
      unit_price: price,
      discount_amount: 0,
      wholesale_floor: Number(p.wholesale_price),
      stock_qty_base: p.stock_qty_base != null ? Number(p.stock_qty_base) : null
    });

    renderCart();
    elSearch.value = "";
    elSearch.focus();
    hideResults();
  }

  // enter = add first result
  function addFirstResultIfAny() {
    if (lastResults.length > 0) {
      addProductToCart(lastResults[0]);
      return;
    }
    // fallback: exact get
    const q = elSearch.value.trim();
    if (!q) return;
    apiGet({ action: "product_get", q, location_id: elLocation ? elLocation.value : "" })
      .then(d => {
        if (d && d.ok && d.product) addProductToCart(d.product);
        else toast("Product not found");
      })
      .catch(() => toast("Search failed"));
  }

  // ---------------- confirm sale ----------------
  async function confirmSale() {
    if (!cart.length) return;

    const doc_type = document.getElementById("doc_type")?.value || "receipt";
    const selling_location_id = document.getElementById("selling_location_id")?.value || "";
    const customer_id = document.getElementById("customer_id")?.value || "";
    const notes = document.getElementById("sale_notes")?.value || "";

    const data = {
      doc_type,
      pricing_mode: pricingMode(),
      selling_location_id,
      customer_id,
      notes,
      currency: "UGX",
      items: cart.map(it => ({
        is_external: it.is_external ? 1 : 0,
        product_id: it.is_external ? null : it.product_id,
        name: it.name,
        sku: it.sku,
        qty_input: it.qty_base,
        qty_unit: it.qty_unit || "piece",
        qty_base: it.qty_base,
        unit_price: it.unit_price,
        discount_amount: it.discount_amount || 0,
        external_cost: it.external_cost || 0,
        external_source: it.external_source || ""
      })),
      payments
    };

    const res = await apiPost("confirm_sale", data);
    if (!res || !res.ok) {
      toast(res?.error || "Failed to confirm sale");
      return;
    }

    // redirect to printable view
    if (res.redirect) window.location.href = res.redirect;
    else toast("Sale confirmed: " + (res.doc_no || ""), "success");
  }

  // ---------------- events ----------------
  if (elHideResults) elHideResults.addEventListener("click", hideResults);

  if (elSearch) {
    elSearch.addEventListener("input", () => {
      clearTimeout(searchTimer);
      const q = elSearch.value;
      searchTimer = setTimeout(() => doLiveSearch(q), 180);
    });

    elSearch.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addFirstResultIfAny();
      }
      if (e.key === "Escape") hideResults();
    });

    // click outside closes suggestions
    document.addEventListener("click", (e) => {
      if (!elResultsWrap.contains(e.target) && e.target !== elSearch) hideResults();
    });
  }

  // update cart prices when pricing mode changes
  const pmRadios = [elPricingModeRetail, elPricingModeWholesale].filter(Boolean);
  pmRadios.forEach(r => r.addEventListener("change", () => {
    for (const it of cart) {
      if (it.is_external) continue;
      // don’t override if cashier edited price (optional: track a flag); for now we rebase
      // If you want: keep edited price. For now follow pricing mode strictly.
      // We'll fetch from cached floor? Not enough. So best to keep current if edited.
      // We'll just keep current to avoid surprising the cashier.
    }
    calcTotals();
  }));

  // Confirm button
  btnConfirm?.addEventListener("click", confirmSale);

  // Ctrl shortcuts
  document.addEventListener("keydown", (e) => {
    if (e.ctrlKey && e.key.toLowerCase() === "enter") {
      e.preventDefault();
      confirmSale();
    }
  });

  // Start
  renderCart();
  calcTotals();
})();

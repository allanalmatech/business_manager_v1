// assets/js/products.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  let page = 1;
  let total = 0;
  let limit = 20;

  const el = (id) => document.getElementById(id);

  const q = el("q");
  const categoryFilter = el("categoryFilter");
  const activeFilter = el("activeFilter");

  const tbody = document.querySelector("#productsTable tbody");
  const resultInfo = el("resultInfo");

  const prevPage = el("prevPage");
  const nextPage = el("nextPage");

  const btnSearch = el("btnSearch");
  const btnAdd = el("btnAdd");

  const productModal = document.getElementById("productModal");
  const priceModal = document.getElementById("priceModal");

  const bsProductModal = productModal ? new bootstrap.Modal(productModal) : null;
  const bsPriceModal = priceModal ? new bootstrap.Modal(priceModal) : null;

  async function postForm(action, formData) {
    formData.append("csrf", window.APP.CSRF || "");
    const res = await fetch(api(action), { method: "POST", body: formData });
    return res.json();
  }

  function showElError(id, msg) {
    const box = el(id);
    if (!box) return;
    box.textContent = msg || "";
    box.classList.toggle("d-none", !msg);
  }

  function money(x) {
    const n = Number(x || 0);
    return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  async function fetchCategories() {
    const res = await fetch(api("categories"));
    const j = await res.json();
    if (!j.ok) return;

    const cats = j.data.categories || [];
    const categoryId = el("categoryId");

    // Reset options (keep first option)
    if (categoryFilter) categoryFilter.querySelectorAll("option:not(:first-child)").forEach(o => o.remove());
    if (categoryId) categoryId.querySelectorAll("option:not(:first-child)").forEach(o => o.remove());

    cats.forEach((c) => {
      if (categoryFilter) {
        const o1 = document.createElement("option");
        o1.value = c.id;
        o1.textContent = c.name;
        categoryFilter.appendChild(o1);
      }

      if (categoryId) {
        const o2 = document.createElement("option");
        o2.value = c.id;
        o2.textContent = c.name;
        categoryId.appendChild(o2);
      }
    });
  }

  async function load() {
    const params = new URLSearchParams();
    if (q.value.trim()) params.set("q", q.value.trim());
    if (categoryFilter.value) params.set("category_id", categoryFilter.value);
    if (activeFilter.value !== "") params.set("active", activeFilter.value);
    params.set("page", String(page));

    const res = await fetch(`${api("list")}&${params.toString()}`);
    const j = await res.json();
    if (!j.ok) {
      tbody.innerHTML = `<tr><td colspan="10" class="text-danger">${j.message || "Failed"}</td></tr>`;
      return;
    }

    const items = j.data.items || [];
    total = Number(j.data.total || 0);
    limit = Number(j.data.limit || 20);

    tbody.innerHTML = "";
    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="10" class="text-muted">No products found.</td></tr>`;
    } else {
      items.forEach(p => {
        const status = Number(p.is_active) === 1
          ? `<span class="badge bg-success">Active</span>`
          : `<span class="badge bg-secondary">Disabled</span>`;

        const actions = [];

        if (window.APP.CAN.update) {
          actions.push(`<button class="btn btn-sm btn-outline-secondary me-1" data-act="edit" data-id="${p.id}">Edit</button>`);
          actions.push(`<button class="btn btn-sm btn-outline-secondary me-1" data-act="toggle" data-id="${p.id}" data-active="${p.is_active}">
            ${Number(p.is_active) === 1 ? "Disable" : "Enable"}
          </button>`);
        }

        if (window.APP.CAN.price) {
          actions.push(`<button class="btn btn-sm btn-outline-primary me-1" data-act="price" data-id="${p.id}">Price</button>`);
        }

        if (window.APP.CAN.delete) {
          actions.push(`<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${p.id}">Delete</button>`);
        }

        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${p.sku || ""}</td>
          <td class="fw-semibold">${p.name || ""}</td>
          <td>${p.category_name || "-"}</td>
          <td class="text-end">${money(p.cost_price)}</td>
          <td class="text-end">${money(p.wholesale_price)}</td>
          <td class="text-end">${money(p.retail_price)}</td>
          <td class="text-end">${money(p.qty_on_hand)}</td>
          <td class="text-end">${money(p.low_level)}</td>
          <td>${status}</td>
          <td class="text-end">${actions.join("") || "-"}</td>
        `;
        tbody.appendChild(tr);
      });
    }

    const start = total === 0 ? 0 : (page - 1) * limit + 1;
    const end = Math.min(start + limit - 1, total);
    resultInfo.textContent = `Showing ${start} to ${end} of ${total} products`;
  }

  // Event listeners
  if (btnSearch) btnSearch.addEventListener("click", () => { page = 1; load(); });
  if (btnAdd) {
    btnAdd.addEventListener("click", () => {
      showElError("modalError", "");
      el("productId").value = "";
      el("sku").value = "";
      el("name").value = "";
      el("categoryId").value = "";
      el("unit").value = "";
      el("description").value = "";
      el("cost_price").value = "";
      el("wholesale_price").value = "";
      el("retail_price").value = "";
      el("qty_on_hand").value = "0";
      el("low_level").value = "0";
      el("track_expiry").value = "0";
      el("expiry_date").value = "";
      el("is_active").checked = true;

      if (bsProductModal) bsProductModal.show();
    });
  }

  // Modal close handlers
  if (productModal) {
    productModal.addEventListener("hidden.bs.modal", () => {
      el("productId").value = "";
      showElError("modalError", "");
    });
  }

  if (priceModal) {
    priceModal.addEventListener("hidden.bs.modal", () => {
      el("priceProductId").value = "";
      el("priceProductName").textContent = "—";
      el("new_cost").value = "";
      el("new_wholesale").value = "";
      el("new_retail").value = "";
      el("price_reason").value = "";
      showElError("priceError", "");
    });
  }

  // Pagination
  if (prevPage) prevPage.addEventListener("click", () => { if (page > 1) { page--; load(); } });
  if (nextPage) nextPage.addEventListener("click", () => { if (page * limit < total) { page++; load(); } });

  // Row actions
  tbody.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-act]");
    if (!btn) return;
    const id = btn.getAttribute("data-id");
    const act = btn.getAttribute("data-act");

    if (act === "edit") {
      // Load product for edit
      fetch(api("get") + "&id=" + encodeURIComponent(id))
        .then(res => res.json())
        .then(j => {
          if (!j.ok) return;
          const p = j.data.product;
          showElError("modalError", "");
          el("productId").value = p.id;
          el("sku").value = p.sku || "";
          el("name").value = p.name || "";
          el("categoryId").value = p.category_id || "";
          el("unit").value = p.unit || "";
          el("description").value = p.description || "";
          el("cost_price").value = p.cost_price || "";
          el("wholesale_price").value = p.wholesale_price || "";
          el("retail_price").value = p.retail_price || "";
          el("qty_on_hand").value = p.qty_on_hand || "0";
          el("low_level").value = p.low_level || "0";
          el("track_expiry").value = String(Number(p.track_expiry) === 1 ? 1 : 0);
          el("expiry_date").value = p.expiry_date || "";
          el("is_active").checked = Number(p.is_active) === 1;

          if (bsProductModal) bsProductModal.show();
        });
    } else if (act === "toggle") {
      const active = btn.getAttribute("data-active");
      const newState = active === "1" ? 0 : 1;
      const fd = new FormData();
      fd.append("id", String(id));
      fd.append("is_active", String(newState));
      postForm("toggle", fd).then((j) => {
        if (j && j.ok) load();
      });
    } else if (act === "price") {
      // Load prices for price modal
      fetch(api("get") + "&id=" + encodeURIComponent(id))
        .then(res => res.json())
        .then(j => {
          if (!j.ok) return;
          const p = j.data.product;
          el("priceProductId").value = p.id;
          el("priceProductName").textContent = `${p.sku || ""} ${p.name || ""}`.trim() || "—";
          el("new_cost").value = p.cost_price || "";
          el("new_wholesale").value = p.wholesale_price || "";
          el("new_retail").value = p.retail_price || "";
          el("price_reason").value = "";
          showElError("priceError", "");

          if (bsPriceModal) bsPriceModal.show();
        });
    } else if (act === "delete") {
      if (confirm("Delete this product?")) {
        const fd = new FormData();
        fd.append("id", String(id));
        postForm("delete", fd).then((j) => {
          if (j && j.ok) load();
          if (j && !j.ok) alert(j.message || "Failed");
        });
      }
    }
  });

  // Save product
  const btnSave = el("btnSave");
  if (btnSave) {
    btnSave.addEventListener("click", () => {
      const id = el("productId").value;
      const data = new FormData();
      if (id) data.append("id", String(id));

      data.append("sku", el("sku").value);
      data.append("name", el("name").value);
      data.append("category_id", el("categoryId").value);
      data.append("unit", el("unit").value);
      data.append("description", el("description").value);
      data.append("track_expiry", el("track_expiry").value);
      data.append("expiry_date", el("expiry_date").value);
      data.append("cost_price", el("cost_price").value);
      data.append("wholesale_price", el("wholesale_price").value);
      data.append("retail_price", el("retail_price").value);
      data.append("qty_on_hand", el("qty_on_hand").value);
      data.append("low_level", el("low_level").value);
      data.append("is_active", el("is_active").checked ? "1" : "0");

      postForm("save", data).then((j) => {
        if (!j || !j.ok) {
          showElError("modalError", (j && j.message) ? j.message : "Failed");
          return;
        }
        if (bsProductModal) bsProductModal.hide();
        load();
      });
    });
  }

  // Save prices
  const btnApplyPrice = el("btnApplyPrice");
  if (btnApplyPrice) {
    btnApplyPrice.addEventListener("click", () => {
      const id = el("priceProductId").value;
      const data = new FormData();
      data.append("id", String(id || ""));
      data.append("new_cost", el("new_cost").value);
      data.append("new_wholesale", el("new_wholesale").value);
      data.append("new_retail", el("new_retail").value);
      data.append("reason", el("price_reason").value);

      postForm("price_update", data).then((j) => {
        if (!j || !j.ok) {
          showElError("priceError", (j && j.message) ? j.message : "Failed");
          return;
        }
        if (bsPriceModal) bsPriceModal.hide();
        load();
      });
    });
  }

  // Initialize
  fetchCategories();
  load();
})();

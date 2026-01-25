// assets/js/stock_levels.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  let page = 1;
  let total = 0;
  let limit = 20;

  const el = (id) => document.getElementById(id);

  const q = el("q");
  const categoryFilter = el("categoryFilter");
  const stockFilter = el("stockFilter");
  const btnSearch = el("btnSearch");

  const tbody = document.querySelector("#stockTable tbody");
  const resultInfo = el("resultInfo");

  const prevPage = el("prevPage");
  const nextPage = el("nextPage");

  function money(x) {
    const n = Number(x || 0);
    return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  async function fetchCategories() {
    const res = await fetch(api("categories"));
    const j = await res.json();
    if (!j.ok) return;

    const cats = j.data.categories || [];
    if (categoryFilter) categoryFilter.querySelectorAll("option:not(:first-child)").forEach(o => o.remove());

    cats.forEach((c) => {
      const o = document.createElement("option");
      o.value = c.id;
      o.textContent = c.name;
      categoryFilter.appendChild(o);
    });
  }

  function calcStatus(qty, low) {
    const qn = Number(qty || 0);
    const ln = Number(low || 0);

    if (qn <= 0) return { key: "out", label: "Out of stock", badge: "bg-danger" };
    if (ln > 0 && qn <= ln) return { key: "low", label: "Low", badge: "bg-warning text-dark" };
    return { key: "ok", label: "OK", badge: "bg-success" };
  }

  async function load() {
    const params = new URLSearchParams();
    if (q && q.value.trim()) params.set("q", q.value.trim());
    if (categoryFilter && categoryFilter.value) params.set("category_id", categoryFilter.value);
    params.set("page", String(page));

    const res = await fetch(`${api("list")}&${params.toString()}`);
    const j = await res.json();
    if (!j.ok) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-danger">${j.message || "Failed"}</td></tr>`;
      return;
    }

    const items = j.data.items || [];
    total = Number(j.data.total || 0);
    limit = Number(j.data.limit || 20);

    const filter = stockFilter ? stockFilter.value : "";

    const filtered = filter
      ? items.filter((p) => {
          const s = calcStatus(p.qty_on_hand, p.low_level).key;
          return s === filter;
        })
      : items;

    tbody.innerHTML = "";
    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-muted">No items found.</td></tr>`;
    } else {
      filtered.forEach((p) => {
        const s = calcStatus(p.qty_on_hand, p.low_level);
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${p.sku || ""}</td>
          <td class="fw-semibold">${p.name || ""}</td>
          <td>${p.category_name || "-"}</td>
          <td class="text-end">${money(p.qty_on_hand)}</td>
          <td class="text-end">${money(p.low_level)}</td>
          <td><span class="badge ${s.badge}">${s.label}</span></td>
        `;
        tbody.appendChild(tr);
      });
    }

    const start = total === 0 ? 0 : (page - 1) * limit + 1;
    const end = Math.min(start + limit - 1, total);
    if (resultInfo) {
      resultInfo.textContent = `Showing ${start} to ${end} of ${total} products (page filter may hide some)`;
    }
  }

  if (btnSearch) btnSearch.addEventListener("click", () => { page = 1; load(); });
  if (stockFilter) stockFilter.addEventListener("change", () => { load(); });

  if (prevPage) prevPage.addEventListener("click", () => { if (page > 1) { page--; load(); } });
  if (nextPage) nextPage.addEventListener("click", () => { if (page * limit < total) { page++; load(); } });

  fetchCategories();
  load();
})();

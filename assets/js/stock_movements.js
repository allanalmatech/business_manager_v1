// assets/js/stock_movements.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  let page = 1;
  let total = 0;
  let limit = 25;

  const el = (id) => document.getElementById(id);

  const q = el("q");
  const movementTypeFilter = el("movementTypeFilter");
  const dateFrom = el("dateFrom");
  const dateTo = el("dateTo");
  const btnSearch = el("btnSearch");

  const tbody = document.querySelector("#movementsTable tbody");
  const resultInfo = el("resultInfo");

  const prevPage = el("prevPage");
  const nextPage = el("nextPage");

  function money(x) {
    const n = Number(x || 0);
    return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function movementBadge(type) {
    const map = {
      stock_in: { label: "Stock In", class: "bg-success" },
      stock_out: { label: "Stock Out", class: "bg-danger" },
      adjustment: { label: "Adjustment", class: "bg-warning text-dark" },
    };
    const meta = map[type] || { label: type, class: "bg-secondary" };
    return `<span class="badge ${meta.class}">${meta.label}</span>`;
  }

  async function load() {
    const params = new URLSearchParams();
    if (q && q.value.trim()) params.set("q", q.value.trim());
    if (movementTypeFilter && movementTypeFilter.value) params.set("movement_type", movementTypeFilter.value);
    if (dateFrom && dateFrom.value) params.set("date_from", dateFrom.value);
    if (dateTo && dateTo.value) params.set("date_to", dateTo.value);
    params.set("page", String(page));

    const res = await fetch(`${api("stock_movements")}&${params.toString()}`);
    const j = await res.json();
    if (!j.ok) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-danger">${j.message || "Failed"}</td></tr>`;
      return;
    }

    const items = j.data.items || [];
    total = Number(j.data.total || 0);
    limit = Number(j.data.limit || 25);

    tbody.innerHTML = "";
    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="text-muted">No movements found.</td></tr>`;
    } else {
      items.forEach((m) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${m.created_at || ""}</td>
          <td class="fw-semibold">${m.product_name || ""}</td>
          <td>${movementBadge(m.movement_type)}</td>
          <td class="text-end">${money(m.qty_change)}</td>
          <td class="text-end">${money(m.qty_before)}</td>
          <td class="text-end">${money(m.qty_after)}</td>
          <td>${m.note || ""}</td>
          <td>${m.user_name || ""}</td>
        `;
        tbody.appendChild(tr);
      });
    }

    const start = total === 0 ? 0 : (page - 1) * limit + 1;
    const end = Math.min(start + limit - 1, total);
    if (resultInfo) {
      resultInfo.textContent = `Showing ${start} to ${end} of ${total} movements`;
    }
  }

  if (btnSearch) btnSearch.addEventListener("click", () => { page = 1; load(); });
  if (movementTypeFilter) movementTypeFilter.addEventListener("change", () => { page = 1; load(); });
  if (dateFrom) dateFrom.addEventListener("change", () => { page = 1; load(); });
  if (dateTo) dateTo.addEventListener("change", () => { page = 1; load(); });

  if (prevPage) prevPage.addEventListener("click", () => { if (page > 1) { page--; load(); } });
  if (nextPage) nextPage.addEventListener("click", () => { if (page * limit < total) { page++; load(); } });

  load();
})();

// assets/js/categories.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;

  const el = (id) => document.getElementById(id);

  const q = el("q");
  const activeFilter = el("activeFilter");
  const btnSearch = el("btnSearch");
  const btnAdd = el("btnAdd");

  const tbody = document.querySelector("#categoriesTable tbody");
  const resultInfo = el("resultInfo");

  const modalEl = document.getElementById("categoryModal");
  const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;

  function showElError(id, msg) {
    const box = el(id);
    if (!box) return;
    box.textContent = msg || "";
    box.classList.toggle("d-none", !msg);
  }

  async function postForm(action, formData) {
    formData.append("csrf", window.APP.CSRF || "");
    const res = await fetch(api(action), { method: "POST", body: formData });
    return res.json();
  }

  async function load() {
    const params = new URLSearchParams();
    if (q && q.value.trim()) params.set("q", q.value.trim());
    if (activeFilter && activeFilter.value !== "") params.set("active", activeFilter.value);

    const res = await fetch(`${api("categories_admin_list")}&${params.toString()}`);
    const j = await res.json();

    if (!j.ok) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-danger">${j.message || "Failed"}</td></tr>`;
      if (resultInfo) resultInfo.textContent = "—";
      return;
    }

    const items = j.data.items || [];
    tbody.innerHTML = "";

    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-muted">No categories found.</td></tr>`;
      if (resultInfo) resultInfo.textContent = "0 categories";
      return;
    }

    items.forEach((c) => {
      const status = Number(c.is_active) === 1
        ? `<span class="badge bg-success">Active</span>`
        : `<span class="badge bg-secondary">Disabled</span>`;

      const actions = [];
      if (window.APP.CAN.update) {
        actions.push(`<button class="btn btn-sm btn-outline-secondary me-1" data-act="edit" data-id="${c.id}">Edit</button>`);
        actions.push(`<button class="btn btn-sm btn-outline-secondary me-1" data-act="toggle" data-id="${c.id}" data-active="${c.is_active}">
          ${Number(c.is_active) === 1 ? "Disable" : "Enable"}
        </button>`);
      }
      if (window.APP.CAN.delete) {
        actions.push(`<button class="btn btn-sm btn-outline-danger" data-act="delete" data-id="${c.id}">Delete</button>`);
      }

      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${c.id}</td>
        <td class="fw-semibold">${c.name || ""}</td>
        <td>${status}</td>
        <td class="text-end">${actions.join("") || "-"}</td>
      `;
      tbody.appendChild(tr);
    });

    if (resultInfo) resultInfo.textContent = `${items.length} categories`;
  }

  function openCreate() {
    showElError("modalError", "");
    el("categoryId").value = "";
    el("name").value = "";
    el("is_active").checked = true;
    if (bsModal) bsModal.show();
  }

  function openEdit(c) {
    showElError("modalError", "");
    el("categoryId").value = c.id;
    el("name").value = c.name || "";
    el("is_active").checked = Number(c.is_active) === 1;
    if (bsModal) bsModal.show();
  }

  if (btnSearch) btnSearch.addEventListener("click", load);
  if (btnAdd) btnAdd.addEventListener("click", openCreate);

  if (modalEl) {
    modalEl.addEventListener("hidden.bs.modal", () => {
      el("categoryId").value = "";
      showElError("modalError", "");
    });
  }

  // Row actions
  tbody.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-act]");
    if (!btn) return;

    const id = btn.getAttribute("data-id");
    const act = btn.getAttribute("data-act");

    if (act === "edit") {
      const row = btn.closest("tr");
      const name = row ? (row.querySelector("td:nth-child(2)")?.textContent || "") : "";
      const isActive = btn.closest("tr")?.querySelector(".badge")?.textContent === "Active" ? 1 : 0;
      openEdit({ id, name, is_active: isActive });
      return;
    }

    if (act === "toggle") {
      const active = btn.getAttribute("data-active");
      const newState = active === "1" ? 0 : 1;
      const fd = new FormData();
      fd.append("id", String(id));
      fd.append("is_active", String(newState));
      postForm("categories_toggle", fd).then((j) => {
        if (j && j.ok) load();
        if (j && !j.ok) alert(j.message || "Failed");
      });
      return;
    }

    if (act === "delete") {
      if (!confirm("Delete this category? If products reference it, deletion may fail.")) return;
      const fd = new FormData();
      fd.append("id", String(id));
      postForm("categories_delete", fd).then((j) => {
        if (j && j.ok) load();
        if (j && !j.ok) alert(j.message || "Failed");
      });
    }
  });

  // Save
  const btnSave = el("btnSave");
  if (btnSave) {
    btnSave.addEventListener("click", () => {
      const id = el("categoryId").value;
      const name = (el("name").value || "").trim();

      const fd = new FormData();
      if (id) fd.append("id", String(id));
      fd.append("name", name);
      fd.append("is_active", el("is_active").checked ? "1" : "0");

      postForm("categories_save", fd).then((j) => {
        if (!j || !j.ok) {
          showElError("modalError", (j && j.message) ? j.message : "Failed");
          return;
        }
        if (bsModal) bsModal.hide();
        load();
      });
    });
  }

  load();
})();

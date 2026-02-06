// assets/js/products.js
(() => {
  const api = (action) => `${window.APP.BASE_URL}/api/products.php?action=${action}`;
  const el = (id) => document.getElementById(id);
  const $ = (s) => document.querySelector(s);

  const tbody = $("#tbl tbody");
  const hint = el("hint");
  const q = el("q");
  const btnSearch = el("btnSearch");
  const btnNew = el("btnNew");
  const mdlProduct = el("mdlProduct");
  const bsModal = mdlProduct ? new bootstrap.Modal(mdlProduct) : null;

  let products = [];

  function money(x) {
    return Number(x || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  async function loadProducts() {
    if (hint) hint.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Loading products...';
    
    try {
      const query = q?.value || "";
      const res = await fetch(`${api("list")}&q=${encodeURIComponent(query)}`);
      const j = await res.json();
      
      if (!j.ok) throw new Error(j.message || "Failed to load");
      
      products = j.data.items || [];
      renderTable();
    } catch (e) {
      if (tbody) tbody.innerHTML = `<tr><td colspan="8" class="text-center p-4 text-danger">${e.message}</td></tr>`;
    } finally {
      if (hint) hint.style.display = "none";
    }
  }

  function renderTable() {
    if (!tbody) return;
    tbody.innerHTML = "";
    
    if (!products.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="text-center p-5 text-muted">No products found.</td></tr>';
      return;
    }

    products.forEach(p => {
      const tr = document.createElement("tr");
      const statusClass = Number(p.is_active) === 1 ? "bg-success" : "bg-secondary";
      const statusText = Number(p.is_active) === 1 ? "Active" : "Inactive";
      
      tr.innerHTML = `
        <td class="ps-4">
          <div class="bg-light rounded" style="width:48px;height:48px;overflow:hidden;">
            ${p.image ? `<img src="${window.APP.BASE_URL}/${p.image}" style="width:100%;height:100%;object-fit:cover;">` : '<i class="bi bi-box p-2 opacity-25" style="font-size:1.5rem"></i>'}
          </div>
        </td>
        <td>
          <div class="fw-bold text-dark">${esc(p.name)}</div>
          <div class="text-muted small">${esc(p.sku)} • ${esc(p.category_name || "No Category")}</div>
        </td>
        <td class="text-end text-muted">${money(p.cost_price)}</td>
        <td class="text-end fw-semibold text-warning">${money(p.wholesale_price)}</td>
        <td class="text-end fw-bold text-primary">${money(p.retail_price)}</td>
        <td class="text-center">
          <span class="badge ${Number(p.qty_on_hand) <= Number(p.low_level) ? 'bg-danger' : 'bg-light text-dark border'}">
            ${money(p.qty_on_hand)}
          </span>
        </td>
        <td><span class="badge ${statusClass}">${statusText}</span></td>
        <td class="text-end pe-4">
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-primary" onclick="editProduct(${p.id})"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-outline-danger" onclick="deleteProduct(${p.id})"><i class="bi bi-trash"></i></button>
          </div>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }

  function esc(s) {
    return String(s || "").replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  window.editProduct = (id) => {
    const p = products.find(x => x.id == id);
    if (!p) return;
    
    el("id").value = p.id;
    el("name").value = p.name || "";
    el("sku").value = p.sku || "";
    el("description").value = p.description || "";
    el("cost_price").value = p.cost_price || 0;
    el("wholesale_price").value = p.wholesale_price || 0;
    el("retail_price").value = p.retail_price || 0;
    el("qty_base").value = p.qty_on_hand || 0;
    el("low_level_base").value = p.low_level || 0;
    el("is_active_check").checked = Number(p.is_active) === 1;
    el("mdlTitle").textContent = "Edit Product";
    el("btnDelete").style.display = "";
    
    bsModal.show();
  };

  window.deleteProduct = async (id) => {
    if (!confirm("Are you sure you want to delete this product?")) return;
    
    try {
      const fd = new FormData();
      fd.append("id", id);
      fd.append("csrf", window.APP.CSRF);
      
      const res = await fetch(api("delete"), { method: "POST", body: fd });
      const j = await res.json();
      if (j.ok) loadProducts();
      else alert(j.message || "Delete failed");
    } catch (e) {
      alert("Error deleting product");
    }
  };

  btnSearch?.addEventListener("click", loadProducts);
  q?.addEventListener("keypress", (e) => { if (e.key === "Enter") loadProducts(); });

  btnNew?.addEventListener("click", () => {
    el("id").value = "";
    el("name").value = "";
    el("sku").value = "";
    el("description").value = "";
    el("cost_price").value = "";
    el("wholesale_price").value = "";
    el("retail_price").value = "";
    el("qty_base").value = "0";
    el("low_level_base").value = "0";
    el("is_active_check").checked = true;
    el("mdlTitle").textContent = "New Product";
    el("btnDelete").style.display = "none";
    bsModal.show();
  });

  el("btnSave")?.addEventListener("click", async () => {
    const btn = el("btnSave");
    const id = el("id").value;
    const action = id ? "update" : "create";
    
    const fd = new FormData();
    fd.append("id", id);
    fd.append("name", el("name").value);
    fd.append("sku", el("sku").value);
    fd.append("description", el("description").value);
    fd.append("cost_price", el("cost_price").value);
    fd.append("wholesale_price", el("wholesale_price").value);
    fd.append("retail_price", el("retail_price").value);
    fd.append("qty_on_hand", el("qty_base").value);
    fd.append("low_level", el("low_level_base").value);
    fd.append("is_active", el("is_active_check").checked ? "1" : "0");
    fd.append("csrf", window.APP.CSRF);

    btn.disabled = true;
    btn.textContent = "Saving...";

    try {
      const res = await fetch(api(action), { method: "POST", body: fd });
      const j = await res.json();
      if (j.ok) {
        bsModal.hide();
        loadProducts();
      } else {
        alert(j.message || "Save failed");
      }
    } catch (e) {
      alert("Error saving product");
    } finally {
      btn.disabled = false;
      btn.textContent = "Save Product";
    }
  });

  loadProducts();
})();

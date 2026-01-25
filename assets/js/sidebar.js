// assets/js/sidebar.js
(function () {
  const btnToggle = document.getElementById("btnSidebarToggle");
  const btnOpen = document.getElementById("btnSidebarOpen");

  if (btnToggle) {
    btnToggle.addEventListener("click", () => {
      document.body.classList.toggle("sidebar-collapsed");
      localStorage.setItem("sidebarCollapsed", document.body.classList.contains("sidebar-collapsed") ? "1" : "0");
    });
  }

  if (btnOpen) {
    btnOpen.addEventListener("click", () => {
      document.body.classList.toggle("sidebar-open");
    });
  }

  // Restore desktop collapse state
  const saved = localStorage.getItem("sidebarCollapsed");
  if (saved === "1") document.body.classList.add("sidebar-collapsed");

  // Close on overlay click (mobile)
  document.addEventListener("click", (e) => {
    if (!document.body.classList.contains("sidebar-open")) return;
    const sidebar = document.getElementById("appSidebar");
    const isClickInside = sidebar && sidebar.contains(e.target);
    const isHamburger = e.target && (e.target.id === "btnSidebarOpen");
    if (!isClickInside && !isHamburger) {
      document.body.classList.remove("sidebar-open");
    }
  });

  // Highlight active link and keep parent collapse open
  const currentPath = window.location.pathname.replace(/\/+$/, '');
  const sidebarLinks = document.querySelectorAll('#appSidebar a.nav-link, #appSidebar a.nav-sublink');
  let activeLink = null;
  let activeCollapse = null;

  for (const a of sidebarLinks) {
    let href = a.getAttribute('href') || '';
    // Resolve to absolute path
    if (href.startsWith('/')) {
      href = href.replace(/\/+$/, '');
    } else {
      // Relative: prepend current path base up to project root
      const base = window.location.origin;
      href = new URL(href, window.location.href).pathname.replace(/\/+$/, '');
    }
    if (href && currentPath === href) {
      activeLink = a;
      break;
    }
  }

  if (activeLink) {
    activeLink.classList.add('active');
    // If it’s a sublink, expand its parent collapse
    const parentCollapse = activeLink.closest('.collapse.nav-sub');
    if (parentCollapse) {
      activeCollapse = parentCollapse;
      // Ensure Bootstrap collapse is shown
      const bsCollapse = new bootstrap.Collapse(parentCollapse, { toggle: false });
      bsCollapse.show();
      // Also update the button’s aria-expanded
      const btn = document.querySelector(`[data-bs-target="#${parentCollapse.id}"]`);
      if (btn) btn.setAttribute('aria-expanded', 'true');
    }
  }
})();

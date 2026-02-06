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

  /**
   * Enhanced Hover Logic for Collapsed Sidebar
   */
  const navGroups = document.querySelectorAll('.app-sidebar .nav-link-group');
  
  navGroups.forEach(group => {
    const submenu = group.nextElementSibling; // The .nav-sub div
    if (!submenu || !submenu.classList.contains('nav-sub')) return;

    let hoverTimeout;

    const showSubmenu = () => {
      if (!document.body.classList.contains('sidebar-collapsed')) return;
      
      clearTimeout(hoverTimeout);
      
      // Position the submenu
      const rect = group.getBoundingClientRect();
      submenu.style.position = 'fixed';
      submenu.style.top = rect.top + 'px';
      submenu.style.left = '80px';
      submenu.style.display = 'block';
      submenu.style.opacity = '1';
      submenu.style.visibility = 'visible';
      submenu.style.height = 'auto';
      submenu.classList.add('show'); // Bootstrap class
    };

    const hideSubmenu = () => {
      if (!document.body.classList.contains('sidebar-collapsed')) return;
      
      hoverTimeout = setTimeout(() => {
        submenu.style.display = '';
        submenu.style.position = '';
        submenu.style.top = '';
        submenu.style.left = '';
        submenu.style.opacity = '';
        submenu.style.visibility = '';
        submenu.classList.remove('show');
      }, 100);
    };

    group.addEventListener('mouseenter', showSubmenu);
    group.addEventListener('mouseleave', hideSubmenu);
    
    submenu.addEventListener('mouseenter', () => clearTimeout(hoverTimeout));
    submenu.addEventListener('mouseleave', hideSubmenu);
  });

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

  for (const a of sidebarLinks) {
    let href = a.getAttribute('href') || '';
    if (href.startsWith('/')) {
      href = href.replace(/\/+$/, '');
    } else {
      try {
        href = new URL(href, window.location.href).pathname.replace(/\/+$/, '');
      } catch(e) {}
    }
    if (href && currentPath === href) {
      activeLink = a;
      break;
    }
  }

  if (activeLink) {
    activeLink.classList.add('active');
    const parentCollapse = activeLink.closest('.collapse.nav-sub');
    if (parentCollapse && !document.body.classList.contains('sidebar-collapsed')) {
      const bsCollapse = new bootstrap.Collapse(parentCollapse, { toggle: false });
      bsCollapse.show();
      const btn = document.querySelector(`[data-bs-target="#${parentCollapse.id}"]`);
      if (btn) btn.setAttribute('aria-expanded', 'true');
    }
  }

  // Initialize tooltips for collapsed state
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl, {
      customClass: 'sidebar-tooltip',
      trigger: 'hover'
    });
  });
})();

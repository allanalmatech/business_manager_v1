<?php
// templates/layout/sidebar.php
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

function show_menu(string $key): bool { return true; } // replace later with RBAC

// Helper to render a group as collapsible if >2 items
function sidebar_group(string $id, string $icon, string $title, array $items, string $baseUrl): void {
    $count = count($items);

    // If 2 or fewer items, render as normal links (not collapsible)
    if ($count <= 2) {
        echo '<div class="nav-section text-uppercase small px-2 mt-3">'.htmlspecialchars($title).'</div>';
        foreach ($items as $it) {
            echo '<a class="nav-link" href="'.htmlspecialchars($baseUrl.$it['href']).'" data-bs-toggle="tooltip" data-bs-placement="right" title="'.htmlspecialchars($it['label']).'">'.$it['icon'].' <span class="nav-text">'.htmlspecialchars($it['label']).'</span></a>';
        }
        return;
    }

    // Collapsible group
    $collapseId = 'collapse_' . $id;
    echo '<div class="nav-section text-uppercase small px-2 mt-3">'.htmlspecialchars($title).'</div>';

    echo '<button class="nav-link nav-link-group" type="button" data-bs-toggle="collapse" data-bs-target="#'.$collapseId.'" aria-expanded="false" aria-controls="'.$collapseId.'" data-title="'.htmlspecialchars($title).'">';
    echo '<span class="nav-ico">'.$icon.'</span>';
    echo '<span class="nav-text">'.htmlspecialchars($title).'</span>';
    echo '<span class="ms-auto nav-caret">▾</span>';
    echo '</button>';

    echo '<div class="collapse nav-sub" id="'.$collapseId.'">';
    foreach ($items as $it) {
        echo '<a class="nav-sublink" href="'.htmlspecialchars($baseUrl.$it['href']).'">'.$it['icon'].' '.htmlspecialchars($it['label']).'</a>';
    }
    echo '</div>';
}
?>

<aside class="app-sidebar shadow-sm" id="appSidebar">

  <div class="app-sidebar__brand d-flex align-items-center gap-3 px-4 py-4 mb-2">
    <div class="brand-mark d-flex align-items-center justify-content-center shadow-sm">
      <i class="bi bi-briefcase-fill text-white fs-5"></i>
    </div>
    <div class="brand-text">
      <div class="fw-bold fs-5 lh-1">Business</div>
      <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.1em;">Manager Pro</div>
    </div>
  </div>

  <nav class="app-sidebar__nav px-2 py-2">

    <a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/index.php" data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
      <i class="bi bi-speedometer2"></i> <span class="nav-text">Dashboard</span>
    </a>

    <?php if (show_menu('sales')): ?>
      <?php sidebar_group('sales','<i class="bi bi-cart3"></i>','Sales',[
        ['href'=>'/modules/pos/pos.php','label'=>'POS (New Sale)','icon'=>'<i class="bi bi-printer"></i>'],
        ['href'=>'/modules/pos/sales_history.php','label'=>'Sales History','icon'=>'<i class="bi bi-clock-history"></i>'],
        ['href'=>'/modules/pos/unpaid.php','label'=>'Unpaid / Pending','icon'=>'<i class="bi bi-hourglass-split"></i>'],
        ['href'=>'/modules/pos/returns.php','label'=>'Returns','icon'=>'<i class="bi bi-arrow-return-left"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('documents')): ?>
      <?php sidebar_group('documents','<i class="bi bi-file-earmark-text"></i>','Documents',[
        ['href'=>'/modules/documents/receipts.php','label'=>'Receipts','icon'=>'<i class="bi bi-receipt"></i>'],
        ['href'=>'/modules/documents/invoices.php','label'=>'Invoices','icon'=>'<i class="bi bi-file-earmark-spreadsheet"></i>'],
        ['href'=>'/modules/documents/delivery_notes.php','label'=>'Delivery Notes','icon'=>'<i class="bi bi-truck"></i>'],
        ['href'=>'/modules/documents/email_log.php','label'=>'Email/Download History','icon'=>'<i class="bi bi-envelope-at"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('installments')): ?>
      <?php sidebar_group('installments','<i class="bi bi-calendar3"></i>','Installments',[
        ['href'=>'/modules/installments/installments.php','label'=>'All Installments','icon'=>'<i class="bi bi-calendar-check"></i>'],
        ['href'=>'/modules/installments/installment_payment.php','label'=>'Receive Payment','icon'=>'<i class="bi bi-cash-coin"></i>'],
        ['href'=>'/modules/installments/actions.php','label'=>'Overdue Actions','icon'=>'<i class="bi bi-exclamation-triangle"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('inventory')): ?>
      <?php sidebar_group('inventory','<i class="bi bi-box-seam"></i>','Inventory',[
        ['href'=>'/modules/products/products.php','label'=>'Products','icon'=>'<i class="bi bi-boxes"></i>'],
        ['href'=>'/modules/products/categories.php','label'=>'Categories','icon'=>'<i class="bi bi-tags"></i>'],
        ['href'=>'/modules/products/stock_levels.php','label'=>'Stock Levels','icon'=>'<i class="bi bi-graph-down"></i>'],
        ['href'=>'/modules/products/stock_movements.php','label'=>'Stock Movements','icon'=>'<i class="bi bi-arrow-left-right"></i>'],
        ['href'=>'/modules/products/stock_in.php','label'=>'Receive Stock','icon'=>'<i class="bi bi-box-arrow-in-down"></i>'],
        ['href'=>'/modules/products/stock_adjustments.php','label'=>'Stock Adjustments','icon'=>'<i class="bi bi-tools"></i>'],
        ['href'=>'/modules/products/price_updates.php','label'=>'Price Updates','icon'=>'<i class="bi bi-currency-exchange"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('stores')): ?>
      <?php sidebar_group('stores','<i class="bi bi-shop"></i>','Stores',[
        ['href'=>'/modules/stores/stores.php','label'=>'Manage Stores','icon'=>'<i class="bi bi-shop-window"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('procurement')): ?>
      <?php sidebar_group('procurement','<i class="bi bi-journal-text"></i>','Procurement',[
        ['href'=>'/modules/procurement/shopping_list.php','label'=>'Shopping List','icon'=>'<i class="bi bi-list-check"></i>'],
        ['href'=>'/modules/procurement/suggested_list.php','label'=>'Suggested List','icon'=>'<i class="bi bi-stars"></i>'],
        ['href'=>'/modules/procurement/wanted_items.php','label'=>'Wanted Items','icon'=>'<i class="bi bi-star"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('contacts')): ?>
      <?php sidebar_group('contacts','<i class="bi bi-people"></i>','Contacts',[
        ['href'=>'/modules/contacts/contacts.php','label'=>'All Contacts','icon'=>'<i class="bi bi-person-lines-fill"></i>'],
        ['href'=>'/modules/contacts/customers.php','label'=>'Customers','icon'=>'<i class="bi bi-person-check"></i>'],
        ['href'=>'/modules/contacts/suppliers.php','label'=>'Suppliers','icon'=>'<i class="bi bi-building"></i>'],
        ['href'=>'/modules/contacts/staff.php','label'=>'Staff','icon'=>'<i class="bi bi-person-workspace"></i>'],
        ['href'=>'/modules/contacts/categories_tags.php','label'=>'Categories / Tags','icon'=>'<i class="bi bi-tags"></i>'],
        ['href'=>'/modules/contacts/export_txt.php','label'=>'Bulk Export','icon'=>'<i class="bi bi-file-earmark-arrow-down"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('messaging')): ?>
      <?php sidebar_group('messaging','<i class="bi bi-envelope"></i>','Messaging',[
        ['href'=>'/modules/messaging/send.php','label'=>'Send Message','icon'=>'<i class="bi bi-send"></i>'],
        ['href'=>'/modules/messaging/templates.php','label'=>'Templates','icon'=>'<i class="bi bi-layout-text-sidebar"></i>'],
        ['href'=>'/modules/messaging/queue.php','label'=>'Queue','icon'=>'<i class="bi bi-clock"></i>'],
        ['href'=>'/modules/messaging/logs.php','label'=>'Logs','icon'=>'<i class="bi bi-list-ul"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('finance')): ?>
      <?php sidebar_group('finance','<i class="bi bi-bank"></i>','Finance',[
        ['href'=>'/modules/finance/expenses.php','label'=>'Expenses','icon'=>'<i class="bi bi-wallet2"></i>'],
        ['href'=>'/modules/finance/capital_in.php','label'=>'Capital In','icon'=>'<i class="bi bi-arrow-down-circle"></i>'],
        ['href'=>'/modules/finance/capital_out.php','label'=>'Capital Out','icon'=>'<i class="bi bi-arrow-up-circle"></i>'],
        ['href'=>'/modules/finance/banking.php','label'=>'Banking','icon'=>'<i class="bi bi-building-bank"></i>'],
        ['href'=>'/modules/finance/vouchers.php','label'=>'Vouchers','icon'=>'<i class="bi bi-file-earmark-text"></i>'],
        ['href'=>'/modules/finance/reconciliation.php','label'=>'Reconciliation','icon'=>'<i class="bi bi-check-all"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('reports')): ?>
      <?php sidebar_group('reports','<i class="bi bi-bar-chart"></i>','Reports',[
        ['href'=>'/modules/reports/sales.php','label'=>'Sales','icon'=>'<i class="bi bi-graph-up-arrow"></i>'],
        ['href'=>'/modules/reports/profit.php','label'=>'Profit','icon'=>'<i class="bi bi-pie-chart"></i>'],
        ['href'=>'/modules/reports/inventory.php','label'=>'Inventory','icon'=>'<i class="bi bi-box-seam"></i>'],
        ['href'=>'/modules/reports/installments.php','label'=>'Installments','icon'=>'<i class="bi bi-calendar-range"></i>'],
        ['href'=>'/modules/reports/expenses.php','label'=>'Expenses','icon'=>'<i class="bi bi-wallet2"></i>'],
        ['href'=>'/modules/reports/capital.php','label'=>'Capital','icon'=>'<i class="bi bi-cash-stack"></i>'],
        ['href'=>'/modules/reports/audit.php','label'=>'Audit','icon'=>'<i class="bi bi-shield-check"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('admin')): ?>
      <?php sidebar_group('admin','<i class="bi bi-gear"></i>','Admin',[
        ['href'=>'/modules/admin/settings.php','label'=>'Settings','icon'=>'<i class="bi bi-gear-wide-connected"></i>'],
        ['href'=>'/modules/admin/themes.php','label'=>'Themes & UI','icon'=>'<i class="bi bi-palette"></i>'],
        ['href'=>'/modules/admin/payment_settings.php','label'=>'Payments','icon'=>'<i class="bi bi-credit-card"></i>'],
        ['href'=>'/modules/admin/reminders.php','label'=>'Reminders','icon'=>'<i class="bi bi-alarm"></i>'],
        ['href'=>'/modules/admin/users.php','label'=>'Users','icon'=>'<i class="bi bi-people"></i>'],
        ['href'=>'/modules/admin/roles.php','label'=>'Roles','icon'=>'<i class="bi bi-shield-lock"></i>'],
        ['href'=>'/modules/admin/permissions.php','label'=>'Permissions','icon'=>'<i class="bi bi-key"></i>'],
        ['href'=>'/modules/admin/approvals.php','label'=>'Approvals','icon'=>'<i class="bi bi-check-circle"></i>'],
        ['href'=>'/modules/admin/audit_trail.php','label'=>'Audit Trail','icon'=>'<i class="bi bi-journal-text"></i>'],
        ['href'=>'/modules/admin/updates.php','label'=>'Updates','icon'=>'<i class="bi bi-cloud-arrow-up"></i>'],
        ['href'=>'/modules/admin/update_history.php','label'=>'History','icon'=>'<i class="bi bi-clock-history"></i>'],
      ], $BASE_URL); ?>
    <?php endif; ?>

  </nav>

  <div class="app-sidebar__footer border-top px-3 py-3">
    <a class="btn btn-outline-secondary btn-sm w-100" href="<?= htmlspecialchars($BASE_URL) ?>/modules/profile/my_profile.php">
      <i class="bi bi-person me-1"></i> <span class="nav-text">My Profile</span>
    </a>
  </div>

</aside>

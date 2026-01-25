<?php
// templates/layout/sidebar.php
$BASE_URL = $GLOBALS['BASE_URL'] ?? '';

function show_menu(string $key): bool { return true; } // replace later with RBAC

// Helper to render a group as collapsible if >2 items
function sidebar_group(string $id, string $icon, string $title, array $items, string $baseUrl): void {
    $count = count($items);

    // If 2 or fewer items, render as normal links (not collapsible)
    if ($count <= 2) {
        echo '<div class="nav-section text-uppercase small text-muted px-2 mt-3">'.htmlspecialchars($title).'</div>';
        foreach ($items as $it) {
            echo '<a class="nav-link" href="'.htmlspecialchars($baseUrl.$it['href']).'">'.$it['icon'].' '.htmlspecialchars($it['label']).'</a>';
        }
        return;
    }

    // Collapsible group
    $collapseId = 'collapse_' . $id;
    echo '<div class="nav-section text-uppercase small text-muted px-2 mt-3">'.htmlspecialchars($title).'</div>';

    echo '<button class="nav-link nav-link-group" type="button" data-bs-toggle="collapse" data-bs-target="#'.$collapseId.'" aria-expanded="false" aria-controls="'.$collapseId.'">';
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

<aside class="app-sidebar" id="appSidebar">

  <div class="app-sidebar__brand d-flex align-items-center gap-2 px-3 py-3 border-bottom">
    <div class="brand-mark"></div>
    <div class="brand-text">
      <div class="fw-bold">Business Manager</div>
      <div class="small text-muted">V1</div>
    </div>
  </div>

  <nav class="app-sidebar__nav px-2 py-2">

    <a class="nav-link" href="<?= htmlspecialchars($BASE_URL) ?>/index.php">🏠 Dashboard</a>

    <?php if (show_menu('sales')): ?>
      <?php sidebar_group('sales','🛒','Sales',[
        ['href'=>'/modules/pos/pos.php','label'=>'POS (New Sale)','icon'=>'🧾'],
        ['href'=>'/modules/pos/sales_history.php','label'=>'Sales History','icon'=>'📚'],
        ['href'=>'/modules/pos/unpaid.php','label'=>'Unpaid / Pending','icon'=>'⏳'],
        ['href'=>'/modules/pos/returns.php','label'=>'Returns','icon'=>'↩️'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('documents')): ?>
      <?php sidebar_group('documents','📄','Documents',[
        ['href'=>'/modules/documents/receipts.php','label'=>'Receipts','icon'=>'🧾'],
        ['href'=>'/modules/documents/invoices.php','label'=>'Invoices','icon'=>'📄'],
        ['href'=>'/modules/documents/delivery_notes.php','label'=>'Delivery Notes','icon'=>'🚚'],
        ['href'=>'/modules/documents/email_log.php','label'=>'Email/Download History','icon'=>'📤'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('installments')): ?>
      <?php sidebar_group('installments','📆','Installments',[
        ['href'=>'/modules/installments/installments.php','label'=>'All Installments','icon'=>'📆'],
        ['href'=>'/modules/installments/installment_payment.php','label'=>'Receive Payment','icon'=>'💵'],
        ['href'=>'/modules/installments/actions.php','label'=>'Overdue Actions','icon'=>'⚠️'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('inventory')): ?>
      <?php sidebar_group('inventory','📦','Products & Inventory',[
        ['href'=>'/modules/products/products.php','label'=>'Products','icon'=>'📦'],
        ['href'=>'/modules/products/categories.php','label'=>'Categories','icon'=>'🏷️'],
        ['href'=>'/modules/products/stock_levels.php','label'=>'Stock Levels','icon'=>'📉'],
        ['href'=>'/modules/products/stock_movements.php','label'=>'Stock Movements','icon'=>'🧾'],
        ['href'=>'/modules/products/stock_in.php','label'=>'Receive Stock','icon'=>'⬇️'],
        ['href'=>'/modules/products/stock_adjustments.php','label'=>'Stock Adjustments','icon'=>'🛠️'],
        ['href'=>'/modules/products/price_updates.php','label'=>'Price Updates','icon'=>'💸'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('stores')): ?>
      <?php sidebar_group('stores','🏪','Stores',[
        ['href'=>'/modules/stores/stores.php','label'=>'Manage Stores','icon'=>'🏪'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('procurement')): ?>
      <?php sidebar_group('procurement','📝','Shopping & Procurement',[
        ['href'=>'/modules/procurement/shopping_list.php','label'=>'Shopping List (Master)','icon'=>'📝'],
        ['href'=>'/modules/procurement/suggested_list.php','label'=>'Suggested Shopping List','icon'=>'✨'],
        ['href'=>'/modules/procurement/wanted_items.php','label'=>'Wanted Items','icon'=>'⭐'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('contacts')): ?>
      <?php sidebar_group('contacts','👥','Contacts',[
        ['href'=>'/modules/contacts/contacts.php','label'=>'All Contacts','icon'=>'👥'],
        ['href'=>'/modules/contacts/customers.php','label'=>'Customers','icon'=>'🧑‍🤝‍🧑'],
        ['href'=>'/modules/contacts/suppliers.php','label'=>'Suppliers','icon'=>'🏭'],
        ['href'=>'/modules/contacts/staff.php','label'=>'Staff','icon'=>'🧑‍💼'],
        ['href'=>'/modules/contacts/categories_tags.php','label'=>'Categories / Tags','icon'=>'🏷️'],
        ['href'=>'/modules/contacts/export_txt.php','label'=>'Bulk Export (TXT)','icon'=>'📄'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('messaging')): ?>
      <?php sidebar_group('messaging','✉️','Messaging',[
        ['href'=>'/modules/messaging/send.php','label'=>'Send SMS/Email','icon'=>'✉️'],
        ['href'=>'/modules/messaging/templates.php','label'=>'Templates','icon'=>'🧩'],
        ['href'=>'/modules/messaging/queue.php','label'=>'Queue','icon'=>'⏱️'],
        ['href'=>'/modules/messaging/logs.php','label'=>'Delivery Logs','icon'=>'📜'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('finance')): ?>
      <?php sidebar_group('finance','💳','Finance',[
        ['href'=>'/modules/finance/expenses.php','label'=>'Expenses','icon'=>'💳'],
        ['href'=>'/modules/finance/capital_in.php','label'=>'Capital In','icon'=>'⬇️'],
        ['href'=>'/modules/finance/capital_out.php','label'=>'Capital Out','icon'=>'⬆️'],
        ['href'=>'/modules/finance/banking.php','label'=>'Banking & Deposits','icon'=>'🏦'],
        ['href'=>'/modules/finance/vouchers.php','label'=>'Payment Vouchers','icon'=>'🧾'],
        ['href'=>'/modules/finance/reconciliation.php','label'=>'Reconciliation','icon'=>'🧮'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('reports')): ?>
      <?php sidebar_group('reports','📊','Reports',[
        ['href'=>'/modules/reports/sales.php','label'=>'Sales','icon'=>'📈'],
        ['href'=>'/modules/reports/profit.php','label'=>'Profit','icon'=>'📊'],
        ['href'=>'/modules/reports/inventory.php','label'=>'Inventory','icon'=>'📦'],
        ['href'=>'/modules/reports/installments.php','label'=>'Installments','icon'=>'📆'],
        ['href'=>'/modules/reports/expenses.php','label'=>'Expenses','icon'=>'💳'],
        ['href'=>'/modules/reports/capital.php','label'=>'Capital Movement','icon'=>'💰'],
        ['href'=>'/modules/reports/audit.php','label'=>'Audit','icon'=>'🕵️'],
      ], $BASE_URL); ?>
    <?php endif; ?>

    <?php if (show_menu('admin')): ?>
      <?php sidebar_group('admin','⚙️','Administration',[
        ['href'=>'/modules/admin/settings.php','label'=>'Business Settings','icon'=>'⚙️'],
        ['href'=>'/modules/admin/payment_settings.php','label'=>'Payment Settings','icon'=>'💱'],
        ['href'=>'/modules/admin/reminders.php','label'=>'Reminders','icon'=>'⏰'],
        ['href'=>'/modules/admin/users.php','label'=>'Users','icon'=>'🧑‍💻'],
        ['href'=>'/modules/admin/roles.php','label'=>'Roles','icon'=>'🛡️'],
        ['href'=>'/modules/admin/permissions.php','label'=>'Permissions','icon'=>'🔐'],
        ['href'=>'/modules/admin/approvals.php','label'=>'Approvals','icon'=>'✅'],
        ['href'=>'/modules/admin/audit_trail.php','label'=>'Audit Trail','icon'=>'📌'],
        ['href'=>'/modules/admin/updates.php','label'=>'Updates','icon'=>'⬆️'],
        ['href'=>'/modules/admin/update_history.php','label'=>'Update History','icon'=>'🗂️'],
      ], $BASE_URL); ?>
    <?php endif; ?>

  </nav>

  <div class="app-sidebar__footer border-top px-3 py-3">
    <a class="btn btn-outline-secondary btn-sm w-100" href="<?= htmlspecialchars($BASE_URL) ?>/modules/profile/my_profile.php">My Profile</a>
  </div>

</aside>

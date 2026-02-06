<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$db = $GLOBALS['db'] ?? null;
$base_url = $GLOBALS['BASE_URL'] ?? '/';

if (!($db instanceof mysqli)) {
    die('Database not available');
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rbac.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int)($_SESSION['user']['id'] ?? 0);
if ($user_id <= 0) {
    header('Location: ' . $base_url . 'login.php');
    exit;
}

require_permission('contacts.view');

$page_title = 'Export Contacts';
include __DIR__ . '/../../templates/layout/header.php';
?>
<div class="app-shell">
  <?php require_once __DIR__ . '/../../templates/layout/sidebar.php'; ?>
  <div class="app-content">
    <?php require_once __DIR__ . '/../../templates/layout/topbar.php'; ?>

    <main class="page-wrap">
      <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h3 class="mb-2 fw-bold">Export Contacts</h3>
            <div class="text-muted">Export contacts in various formats with customizable options</div>
          </div>
          <div class="gap-2 d-flex">
            <a href="contacts.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i> Back to Contacts
            </a>
          </div>
        </div>

        <form id="exportForm" class="row g-4">
          <!-- Export Format -->
          <div class="col-lg-6">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-light">
                <h6 class="mb-0">
                  <i class="bi bi-file-earmark"></i> Export Format
                </h6>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label for="format" class="form-label">File Format</label>
                  <select id="format" name="format" class="form-select" required>
                    <option value="txt">Plain Text (.txt)</option>
                    <option value="detailed">Detailed Text (.txt)</option>
                    <option value="csv">CSV (.csv)</option>
                    <option value="json">JSON (.json)</option>
                  </select>
                  <div class="form-text">Choose the format for your exported file</div>
                </div>

                <div class="mb-3">
                  <label for="filename" class="form-label">Filename (without extension)</label>
                  <input type="text" id="filename" name="filename" class="form-control" 
                         placeholder="contacts_export" value="contacts_export">
                  <div class="form-text">Custom filename for your export file</div>
                </div>
              </div>
            </div>
          </div>

          <!-- CSV Options -->
          <div class="col-lg-6">
            <div class="card shadow-sm h-100" id="csvOptions" style="display: none;">
              <div class="card-header bg-light">
                <h6 class="mb-0">
                  <i class="bi bi-gear"></i> CSV Options
                </h6>
              </div>
              <div class="card-body">
                <div class="mb-3">
                  <label for="delimiter" class="form-label">Delimiter</label>
                  <select id="delimiter" name="delimiter" class="form-select">
                    <option value=",">Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="\t">Tab (\t)</option>
                    <option value="|">Pipe (|)</option>
                  </select>
                  <div class="form-text">Character used to separate fields</div>
                </div>

                <div class="mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="headers" name="headers" value="true" checked>
                    <label class="form-check-label" for="headers">
                      Include column headers
                    </label>
                  </div>
                  <div class="form-text">Add header row to CSV file</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Filter Options -->
          <div class="col-lg-12">
            <div class="card shadow-sm">
              <div class="card-header bg-light">
                <h6 class="mb-0">
                  <i class="bi bi-funnel"></i> Filter Options
                </h6>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="search" class="form-label">Search Contacts</label>
                    <input type="text" id="search" name="search" class="form-control" 
                           placeholder="Search by name, email, phone...">
                    <div class="form-text">Filter contacts by search term</div>
                  </div>
                  <div class="col-md-6">
                    <label for="type" class="form-label">Contact Type</label>
                    <select id="type" name="type" class="form-select">
                      <option value="">All Types</option>
                      <option value="staff">Staff</option>
                      <option value="customer">Customer</option>
                      <option value="supplier">Supplier</option>
                    </select>
                    <div class="form-text">Export specific contact types</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Export Actions -->
          <div class="col-lg-12">
            <div class="card shadow-sm border-primary">
              <div class="card-body text-center">
                <div class="mb-3">
                  <h6 class="text-muted">Ready to Export</h6>
                  <p class="small text-muted">Your contacts will be exported with the selected options</p>
                </div>
                <div class="gap-2 d-flex justify-content-center">
                  <button type="button" id="exportBtn" class="btn btn-primary btn-lg">
                    <i class="bi bi-download me-2"></i> Export Contacts
                  </button>
                  <a href="contacts.php" class="btn btn-outline-secondary btn-lg">
                    <i class="bi bi-x-circle me-2"></i> Cancel
                  </a>
                </div>
              </div>
            </div>
          </div>
        </form>

        <!-- Export Statistics -->
        <div class="row mt-4">
          <div class="col-lg-12">
            <div class="card shadow-sm">
              <div class="card-header bg-light">
                <h6 class="mb-0">
                  <i class="bi bi-info-circle"></i> Export Information
                </h6>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <h6 class="text-muted">Available Formats</h6>
                    <ul class="list-unstyled">
                      <li><strong>Plain Text:</strong> Simple text format with basic contact information</li>
                      <li><strong>Detailed Text:</strong> Comprehensive text format with full details</li>
                      <li><strong>CSV:</strong> Comma-separated values for spreadsheet applications</li>
                      <li><strong>JSON:</strong> Structured data format for programming use</li>
                    </ul>
                  </div>
                  <div class="col-md-6">
                    <h6 class="text-muted">Export Features</h6>
                    <ul class="list-unstyled">
                      <li><i class="bi bi-check-circle text-success"></i> Custom filename support</li>
                      <li><i class="bi bi-check-circle text-success"></i> Multiple delimiter options for CSV</li>
                      <li><i class="bi bi-check-circle text-success"></i> Optional CSV headers</li>
                      <li><i class="bi bi-check-circle text-success"></i> Search and type filtering</li>
                      <li><i class="bi bi-check-circle text-success"></i> All contact types included</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const formatSelect = document.getElementById('format');
    const csvOptions = document.getElementById('csvOptions');
    const exportBtn = document.getElementById('exportBtn');
    const exportForm = document.getElementById('exportForm');
    
    // Show/hide CSV options based on format selection
    formatSelect.addEventListener('change', function() {
        if (this.value === 'csv') {
            csvOptions.style.display = 'block';
        } else {
            csvOptions.style.display = 'none';
        }
    });
    
    // Handle export button click
    exportBtn.addEventListener('click', function() {
        // Collect form data
        const formData = new FormData(exportForm);
        const params = new URLSearchParams();
        
        // Add all form data to URL parameters
        for (let [key, value] of formData.entries()) {
            params.append(key, value);
        }
        
        // Build the export URL
        const exportUrl = 'export_txt.php?' + params.toString();
        
        // Create a temporary link to trigger download
        const link = document.createElement('a');
        link.href = exportUrl;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Show success message
        showExportMessage();
    });
    
    function showExportMessage() {
        // Create success alert
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        alertDiv.style.zIndex = '9999';
        alertDiv.style.minWidth = '300px';
        alertDiv.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2"></i>
                <span>Export started! Your file will download shortly.</span>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        document.body.appendChild(alertDiv);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 3000);
    }
});
</script>

<?php include __DIR__ . '/../../templates/layout/footer.php'; ?>

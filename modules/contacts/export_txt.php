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

// Get export parameters
$format = trim((string)($_GET['format'] ?? 'txt'));
if (!in_array($format, ['txt', 'detailed', 'csv', 'json'])) {
    $format = 'txt';
}

$filename = trim((string)($_GET['filename'] ?? ''));
if (!$filename) {
    $filename = 'contacts_' . date('Y-m-d_His');
}

$delimiter = trim((string)($_GET['delimiter'] ?? ','));
if (!in_array($delimiter, [',', ';', '\t', '|'])) {
    $delimiter = ',';
}

$include_headers = ($_GET['headers'] ?? 'true') === 'true';

// Apply filters
$where = ["1 = 1"];
$params = [];
$types = '';

$type = trim((string)($_GET['type'] ?? ''));
if ($type && in_array($type, ['staff', 'customer', 'supplier'])) {
    $where[] = "type = ?";
    $params[] = $type;
    $types .= 's';
}

$search = trim((string)($_GET['search'] ?? ''));
if ($search) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

$where_clause = implode(' AND ', $where);

// Fetch contacts
$query = "
    SELECT * FROM (
        SELECT id, first_name, last_name, email, phone, address as company, 'staff' as type, created_at FROM staff WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, address as company, 'customer' as type, created_at FROM customers WHERE is_active = 1
        UNION ALL
        SELECT id, name as first_name, '' as last_name, email, phone, company_name as company, 'supplier' as type, created_at FROM suppliers WHERE status = 'active'
    ) as combined_contacts
    WHERE $where_clause
    ORDER BY first_name, last_name
";

$st = $db->prepare($query);
if ($st && $types) {
    $st->bind_param($types, ...$params);
}

$contacts = [];
if ($st) {
    $st->execute();
    $contacts = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
}

function h2($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function generate_plain_text($contacts) {
    $output = "CONTACTS EXPORT\n";
    $output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Total Records: " . count($contacts) . "\n";
    $output .= str_repeat("=", 80) . "\n\n";
    
    foreach ($contacts as $idx => $contact) {
        $output .= ($idx + 1) . ". " . h2($contact['first_name'] . ' ' . $contact['last_name']) . "\n";
        $output .= "   Email: " . h2($contact['email']) . "\n";
        
        if ($contact['phone']) {
            $output .= "   Phone: " . h2($contact['phone']) . "\n";
        }
        
        if ($contact['company']) {
            $output .= "   Company: " . h2($contact['company']) . "\n";
        }
        
        $output .= "   Type: " . ucfirst($contact['type']) . "\n";
        $output .= "   Added: " . date('M d, Y', strtotime($contact['created_at'])) . "\n";
        $output .= "\n";
    }
    
    return $output;
}

function generate_detailed_text($contacts) {
    $output = "DETAILED CONTACTS EXPORT\n";
    $output .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Total Records: " . count($contacts) . "\n";
    $output .= str_repeat("=", 100) . "\n\n";
    
    foreach ($contacts as $idx => $contact) {
        $output .= str_repeat("-", 100) . "\n";
        $output .= "CONTACT #" . ($idx + 1) . "\n";
        $output .= str_repeat("-", 100) . "\n";
        
        $output .= "Name:         " . h2($contact['first_name'] . ' ' . $contact['last_name']) . "\n";
        $output .= "Email:        " . h2($contact['email']) . "\n";
        
        if ($contact['phone']) {
            $output .= "Phone:        " . h2($contact['phone']) . "\n";
        }
        
        if ($contact['company']) {
            $output .= "Company:      " . h2($contact['company']) . "\n";
        }
        
        $output .= "Type:         " . ucfirst($contact['type']) . "\n";
        $output .= "Added:        " . date('M d, Y H:i', strtotime($contact['created_at'])) . "\n";
        $output .= "\n";
    }
    
    $output .= str_repeat("=", 100) . "\n";
    $output .= "End of Export\n";
    
    return $output;
}

function generate_csv($contacts, $delimiter, $include_headers) {
    $output = '';
    
    if ($include_headers) {
        $output .= "First Name,Last Name,Email,Phone,Company,Type,Created At\n";
    }
    
    foreach ($contacts as $contact) {
        $output .= h2($contact['first_name']) . $delimiter;
        $output .= h2($contact['last_name']) . $delimiter;
        $output .= h2($contact['email']) . $delimiter;
        $output .= h2($contact['phone'] ?? '') . $delimiter;
        $output .= h2($contact['company'] ?? '') . $delimiter;
        $output .= ucfirst($contact['type']) . $delimiter;
        $output .= date('Y-m-d H:i:s', strtotime($contact['created_at'])) . "\n";
    }
    
    return $output;
}

function generate_json($contacts) {
    $data = [];
    
    foreach ($contacts as $contact) {
        $data[] = [
            'id' => $contact['id'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'] ?? '',
            'company' => $contact['company'] ?? '',
            'type' => $contact['type'],
            'created_at' => $contact['created_at'],
            'created_at_formatted' => date('Y-m-d H:i:s', strtotime($contact['created_at']))
        ];
    }
    
    return json_encode($data, JSON_PRETTY_PRINT);
}

// Generate content based on format
switch ($format) {
    case 'detailed':
        $content = generate_detailed_text($contacts);
        $filename = $filename . '.txt';
        $content_type = 'text/plain; charset=utf-8';
        break;
        
    case 'csv':
        $content = generate_csv($contacts, $delimiter, $include_headers);
        $filename = $filename . '.csv';
        $content_type = 'text/csv; charset=utf-8';
        break;
        
    case 'json':
        $content = generate_json($contacts);
        $filename = $filename . '.json';
        $content_type = 'application/json; charset=utf-8';
        break;
        
    case 'txt':
    default:
        $content = generate_plain_text($contacts);
        $filename = $filename . '.txt';
        $content_type = 'text/plain; charset=utf-8';
        break;
}

// Send as download
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename=' . $filename);
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

echo $content;
exit;

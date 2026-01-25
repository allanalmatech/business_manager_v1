<?php
// add_users.php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
if (!$db instanceof mysqli) {
    http_response_code(500);
    die("DB not available. Check includes/bootstrap.php");
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function ensure_role(mysqli $db, string $name, string $desc): int
{
    // Try fetch
    $stmt = $db->prepare("SELECT id FROM roles WHERE name=? LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) return (int)$row['id'];

    // Insert
    $stmt = $db->prepare("INSERT INTO roles (name, description) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $desc);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function user_exists(mysqli $db, string $username, ?string $email): ?array
{
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.full_name, u.phone, u.is_active, r.name AS role
                          FROM users u
                          JOIN roles r ON r.id=u.role_id
                          WHERE u.username=? OR (u.email IS NOT NULL AND u.email=?)
                          LIMIT 1");
    $email2 = $email ?? '';
    $stmt->bind_param("ss", $username, $email2);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function add_user(mysqli $db, int $roleId, string $username, string $email, string $fullName, string $phone, string $plainPassword): int
{
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (role_id, username, email, full_name, phone, password_hash, is_active)
                          VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("isssss", $roleId, $username, $email, $fullName, $phone, $hash);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// 1) Ensure roles exist
$roleSuper = ensure_role($db, 'super_admin', 'Full access');
$roleCash  = ensure_role($db, 'cashier', 'POS and limited operations');
$roleAcc   = ensure_role($db, 'accountant', 'Finance and reports');

// 2) Define 3 sample users (edit these if you want)
$sampleUsers = [
    [
        'role_id' => $roleSuper,
        'username' => 'admin',
        'email' => 'admin@example.com',
        'full_name' => 'Super Admin',
        'phone' => '0700000000',
        'password' => 'Admin@123'
    ],
    [
        'role_id' => $roleCash,
        'username' => 'cashier1',
        'email' => 'cashier1@example.com',
        'full_name' => 'Cashier One',
        'phone' => '0700000001',
        'password' => 'Cashier@123'
    ],
    [
        'role_id' => $roleAcc,
        'username' => 'accountant1',
        'email' => 'accountant1@example.com',
        'full_name' => 'Accountant One',
        'phone' => '0700000002',
        'password' => 'Accountant@123'
    ],
];

$results = [];

foreach ($sampleUsers as $u) {
    $existing = user_exists($db, $u['username'], $u['email']);

    if ($existing) {
        $results[] = [
            'status' => 'EXISTS',
            'id' => (int)$existing['id'],
            'username' => $existing['username'],
            'email' => $existing['email'] ?? '',
            'full_name' => $existing['full_name'],
            'phone' => $existing['phone'] ?? '',
            'role' => $existing['role'],
            'active' => (int)$existing['is_active'] === 1 ? 'Yes' : 'No',
            'note' => 'User already existed (no changes made).'
        ];
        continue;
    }

    // If not exists, add
    $newId = add_user(
        $db,
        (int)$u['role_id'],
        $u['username'],
        $u['email'],
        $u['full_name'],
        $u['phone'],
        $u['password']
    );

    // Fetch inserted data for display
    $created = user_exists($db, $u['username'], $u['email']);

    $results[] = [
        'status' => 'CREATED',
        'id' => $newId,
        'username' => $created['username'] ?? $u['username'],
        'email' => $created['email'] ?? $u['email'],
        'full_name' => $created['full_name'] ?? $u['full_name'],
        'phone' => $created['phone'] ?? $u['phone'],
        'role' => $created['role'] ?? '',
        'active' => ($created && (int)$created['is_active'] === 1) ? 'Yes' : 'No',
        'note' => 'User created successfully.'
    ];
}

// Output simple page
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Sample Users</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Sample Users Setup</h4>
    <a class="btn btn-sm btn-outline-secondary" href="index.php">Back to Dashboard</a>
  </div>

  <div class="alert alert-info">
    This script checks for sample users by <b>username/email</b>. If missing, it creates them. You can run it multiple times safely.
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Status</th>
              <th>ID</th>
              <th>Username</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Role</th>
              <th>Active</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($results as $r): ?>
            <tr>
              <td>
                <?php if ($r['status'] === 'CREATED'): ?>
                  <span class="badge bg-success">CREATED</span>
                <?php else: ?>
                  <span class="badge bg-secondary">EXISTS</span>
                <?php endif; ?>
              </td>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h((string)$r['username']) ?></td>
              <td><?= h((string)$r['full_name']) ?></td>
              <td><?= h((string)$r['email']) ?></td>
              <td><?= h((string)$r['phone']) ?></td>
              <td><?= h((string)$r['role']) ?></td>
              <td><?= h((string)$r['active']) ?></td>
              <td><?= h((string)$r['note']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <hr>
      <div class="small text-muted">
        Tip: After confirming users are created, you can delete this file for security.
      </div>
    </div>
  </div>
</div>
</body>
</html>

CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120) NULL UNIQUE,
  phone VARCHAR(40) NULL,
  full_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  profile_photo VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  perm_key VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id INT NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Optional overrides per user (grant/deny)
CREATE TABLE user_permissions (
  user_id INT NOT NULL,
  permission_id INT NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1, -- 1=grant, 0=deny
  PRIMARY KEY (user_id, permission_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- DB-backed PHP sessions
CREATE TABLE sessions (
  id VARCHAR(128) PRIMARY KEY,
  user_id INT NULL,
  data MEDIUMBLOB NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  last_activity INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sessions_last_activity (last_activity),
  INDEX idx_sessions_user_id (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Audit trail
CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity VARCHAR(80) NULL,
  entity_id VARCHAR(80) NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user_id (user_id),
  INDEX idx_audit_action (action),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Seed roles
INSERT INTO roles (name, description) VALUES
('super_admin', 'Full access'),
('cashier', 'POS and limited operations'),
('accountant', 'Finance and reports');

-- Seed permissions (minimum starter set for V1)
INSERT INTO permissions (perm_key, description) VALUES
('dashboard.view', 'View dashboard'),
('pos.use', 'Use POS'),
('sales.view', 'View sales history'),
('sales.returns', 'Process returns'),
('products.view', 'View products'),
('products.create', 'Create products'),
('products.update', 'Update products'),
('products.delete', 'Delete products'),
('contacts.view', 'View contacts'),
('contacts.create', 'Create contacts'),
('contacts.update', 'Update contacts'),
('contacts.delete', 'Delete contacts'),
('finance.view', 'View finance'),
('finance.create', 'Create finance records'),
('finance.update', 'Update finance records'),
('finance.delete', 'Delete finance records'),
('reports.view', 'View reports'),
('admin.access', 'Access admin section'),
('admin.users', 'Manage users'),
('admin.rbac', 'Manage roles/permissions'),
('admin.settings', 'Manage system settings'),
('admin.updates', 'Apply updates'),
('audit.view', 'View audit trail');

-- Role permissions mapping (starter defaults)
-- super_admin gets all perms
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name='super_admin';

-- cashier minimal
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name='cashier' AND p.perm_key IN (
  'dashboard.view','pos.use','sales.view','products.view','contacts.view','contacts.create','reports.view'
);

-- accountant
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name='accountant' AND p.perm_key IN (
  'dashboard.view','finance.view','finance.create','finance.update','reports.view','audit.view','sales.view'
);

# ERP v2

> SAP-like, Job/PO-centric ERP System

## Requirements

- PHP 7.4+ or 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Web server (Apache/Nginx)

## Installation

### 1. Create Database

```sql
CREATE DATABASE erp_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Import Schema

```bash
mysql -u root -p erp_v2 < sql/schema.sql
```

### 3. Configure Database Connection

Edit `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'erp_v2');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### 4. Set Web Server Document Root

Point your web server to the `4erpv2` directory.

### 5. Create Logs Directory

```bash
mkdir -p logs
chmod 777 logs
```

## Testing

### Self-Tests (PHP)
Run the full regression suite:
```bash
php tests/run_selftests.php
```

### UI Tests (Playwright)
Run headless UI regression tests:
```bash
cd tests/ui && npm run ui:test
```

## Default Login

- **Username:** admin
- **Password:** admin123

## Project Structure

```
4erpv2/
├── config/           # Configuration files
│   ├── bootstrap.php # Application initialization
│   ├── constants.php # System constants
│   └── database.php  # Database connection
├── core/             # Core classes
│   ├── Auth.php      # Authentication
│   ├── AuditLog.php  # Audit trail (append-only)
│   ├── RBAC.php      # Permission checking
│   └── Session.php   # Session management
├── includes/         # Shared templates
│   ├── header.php
│   ├── footer.php
│   ├── nav.php
│   └── functions.php # Helper functions
├── modules/          # Application modules
│   ├── admin/        # Admin dashboard
│   ├── auth/         # Login/logout
│   └── jobs/         # Job management (Phase 2)
├── assets/           # Static files
│   ├── css/
│   ├── js/
│   └── img/
├── sql/              # Database files
│   └── schema.sql    # Database schema
└── index.php         # Main dashboard
```

## System Roles (Locked)

| Code | Name | Description |
|------|------|-------------|
| ADM | Admin | Full system access |
| SAL | Sale | Sales team |
| PLN | Planner | Job planning |
| PUR | Purchase | Procurement |
| HR | HRM | Human resources |
| WH | Warehouse | Stock management |
| ACC | Accountant | Accounting |
| MGR | Manager | Approval authority |

## Key Rules (from agents.md)

1. **No DELETE** on financial/stock tables
2. **Append-only** audit logs
3. **Status lockpoints** enforced
4. All actions are **audit logged**

## Development

### Git Workflow

```bash
# Feature branch
git checkout -b feat/feature-name

# Bug fix branch  
git checkout -b fix/bug-name

# Commit prefixes
git commit -m "feat: add feature"
git commit -m "fix: fix bug"
```

## License

Proprietary

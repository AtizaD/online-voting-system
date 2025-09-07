# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a comprehensive online voting system designed for educational institutions (Senior High Schools). The system supports multiple user roles with role-based access control, election management, and secure voting capabilities.

## Development Environment

### Requirements
- **Web Server**: XAMPP/WAMP with Apache and mod_rewrite enabled
- **PHP**: Version 8.2+ required
- **Database**: MySQL 8.0+ 
- **Browser**: Modern browser with JavaScript enabled

### Setup
1. **Database**: Import `evoting_schema.sql` to create database structure
2. **Configuration**: Update database credentials in `config/database.php`
3. **Access**: System available at `http://localhost/online_voting/`
4. **Permissions**: Ensure logs/ directory is writable

## Architecture Overview

### Multi-Role Structure
The system uses role-based dashboards with dedicated directories:
- `admin/` - Full system administration
- `election-officer/` - Election management (limited)  
- `staff/` - Student management and basic reporting
- `student/` - Voting interface and results viewing

### Database Design
- **Hierarchical relationships**: Programs → Classes → Students → Candidates → Elections → Votes
- **Reference tables**: levels, programs, election_types, user_roles for consistency
- **Foreign key constraints**: Proper referential integrity with CASCADE rules
- **Soft deletes**: Uses `is_active` boolean flags rather than hard deletes

### Authentication System
- **Unified login**: Single auth entry point at `auth/index.php`
- **Session management**: Automatic timeout (30min), secure cookies, CSRF protection
- **Role verification**: `requireAuth(['role1', 'role2'])` function checks permissions
- **Permission matrix**: JSON-based permissions stored in user_roles table

## Key Components

### Core Files
- `config/config.php` - System configuration and constants
- `config/database.php` - Database connection singleton pattern
- `auth/session.php` - Session management and authentication functions
- `includes/functions.php` - Common utility functions
- `includes/header.php` & `includes/footer.php` - UI templates

### Security Features
- **.htaccess protection**: Blocks direct access to config/, logs/, includes/
- **Input sanitization**: All user input processed via `sanitize()` function
- **File upload security**: Blocks PHP execution in uploads directory
- **Session security**: HTTPOnly cookies, strict same-site policy

## Common Development Tasks

### Testing Different Roles
Access role-specific dashboards:
- Admin: `/admin/` (full access)
- Staff: `/staff/` (student management)
- Student: `/student/` (voting interface)

### Database Operations
- **Connection**: Use `Database::getInstance()->getConnection()`
- **Queries**: Always use prepared statements for user input
- **Transactions**: Wrap multi-table operations in database transactions

### Debugging
- **Error logs**: Check `logs/error.log` for PHP errors
- **Access logs**: Check `logs/access.log` for request tracking
- **Debug mode**: Set `DEBUG_MODE = true` in config/config.php for detailed output
- **SQL debugging**: Enable query logging in database.php if needed

### File Structure Patterns
```
admin/
├── students/
│   ├── index.php      # Overview with pagination
│   ├── manage.php     # CRUD operations
│   └── verify.php     # Bulk verification
├── elections/         # Election management
├── candidates/        # Candidate management
└── reports/          # System reporting
```

## Database Schema Patterns

### Naming Conventions
- **Tables**: snake_case (e.g., `user_roles`, `election_types`)
- **Primary keys**: `table_name_id` (e.g., `student_id`, `election_id`)
- **Foreign keys**: Match referenced primary key name
- **Timestamps**: `created_at`, `updated_at` on all main tables
- **Boolean flags**: `is_active`, `is_verified`, etc.

### Relationship Structure
```
programs (1:many) classes (1:many) students
students (1:many) candidates (many:1) elections
elections (1:many) votes (many:1) students
users (1:many) audit_logs
```

## API Patterns

### AJAX Endpoints
- Located in `api/` directory organized by feature
- Return JSON responses with consistent structure:
  ```php
  ['success' => true/false, 'message' => '', 'data' => []]
  ```

### Form Handling
- Use POST for data modifications, GET for filters/pagination
- CSRF protection via session tokens
- Input validation both client-side and server-side
- Redirect after POST to prevent duplicate submissions

## Pagination Implementation

### Standard Pattern
```php
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $per_page;

// Count query for total records
// Main query with LIMIT $per_page OFFSET $offset
// Render pagination controls with preserved filters
```

### Filter Integration
- Combine search, program_filter, class_filter, status_filter
- Preserve all filter parameters in pagination links
- Use form submission instead of JavaScript for better performance

## Security Considerations

### Input Validation
```php
// Always sanitize user input
$input = sanitize($_POST['field'] ?? '');

// Use prepared statements
$stmt = $db->prepare("SELECT * FROM table WHERE field = ?");
$stmt->execute([$input]);
```

### File Uploads
- Validate file types and sizes before processing
- Store uploads outside web root when possible
- Use .htaccess to block PHP execution in upload directories

### Permission Checks
```php
// Check if user has required role
requireAuth(['admin', 'staff']);

// Check specific permissions
if (!hasPermission('manage_students')) {
    // Redirect or show error
}
```

## Troubleshooting Common Issues

### Session Problems
- Check session timeout settings in config.php
- Verify session directory is writable
- Clear browser cookies if authentication issues persist

### Database Connection Issues  
- Verify credentials in config/database.php
- Check MySQL service is running
- Ensure database exists and schema is imported

### Permission Errors
- Verify .htaccess is working (mod_rewrite enabled)
- Check file permissions on logs/ directory
- Ensure uploads/ directory is writable

## Performance Notes

- Use pagination for large datasets (students, votes, logs)
- Implement proper indexing on frequently queried columns
- Cache election results when possible
- Monitor query performance in high-traffic scenarios
- Use form-based filtering instead of JavaScript for better pagination performance
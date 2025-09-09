# Online Voting System

A comprehensive web-based voting system designed for educational institutions, particularly Senior High Schools. The system features multi-role authentication, secure voting mechanisms, and comprehensive election management capabilities.

## 🚀 Features

### Multi-Role Access Control
- **Admin**: Full system administration and management
- **Election Officer**: Election management and oversight
- **Staff**: Student management and verification
- **Student**: Voting interface and results viewing

### Election Management
- Create and manage multiple elections simultaneously
- Configure election types (Student Council, Class Representative, Prefect, etc.)
- Position management with candidate registration
- Real-time election monitoring and statistics

### Security Features
- Session-based authentication with automatic timeout
- CSRF protection and input sanitization
- Secure file upload handling
- Audit logging for all system activities
- Password hashing with bcrypt
- SQL injection prevention with prepared statements

### Voting System
- Secure voting sessions with unique tokens
- Prevention of double voting
- Vote verification capabilities
- Anonymous voting with audit trails
- Real-time vote counting and results

### Reporting & Analytics
- Comprehensive election reports
- Voter turnout statistics
- Candidate performance analytics
- Export results to PDF format
- Audit logs and security monitoring

## 🛠 Technical Stack

- **Backend**: PHP 8.2+ with PDO
- **Database**: MySQL 8.0+
- **Frontend**: Bootstrap 5, Chart.js, Font Awesome
- **Server**: Apache with mod_rewrite
- **Dependencies**: TCPDF for PDF generation

## 📋 Requirements

### Server Requirements
- PHP 8.2 or higher
- MySQL 8.0 or higher
- Apache web server with mod_rewrite enabled
- PHP extensions: PDO, PDO_MySQL, GD, mbstring

### Browser Support
- Modern browsers with JavaScript enabled
- Responsive design for mobile and tablet devices

## 🚀 Installation

### 1. Clone Repository
```bash
git clone https://github.com/AtizaD/online-voting-system.git
cd online-voting-system
```

### 2. Database Setup
1. Create a MySQL database
2. Import the database schema:
   ```sql
   mysql -u username -p database_name < evoting_schema.sql
   ```

### 3. Configuration
1. Copy and configure database settings:
   ```php
   // config/database.php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'your_database_name');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

2. Set up file permissions:
   ```bash
   chmod 755 assets/uploads/
   chmod 755 logs/
   ```

### 4. Default Login
- **Username**: `admin`
- **Password**: `admin123`
- **⚠️ Change default password immediately after first login!**

## 📁 Project Structure

```
online-voting-system/
├── admin/              # Admin panel
├── election-officer/   # Election officer interface
├── staff/             # Staff management panel
├── student/           # Student voting interface
├── api/               # REST API endpoints
├── auth/              # Authentication system
├── config/            # Configuration files
├── includes/          # Common functions and headers
├── assets/            # CSS, JS, images, uploads
└── logs/              # System logs
```

## 🔧 Usage

### For Administrators
1. **User Management**: Create and manage user accounts
2. **System Configuration**: Configure system settings and security
3. **Election Setup**: Create elections, positions, and manage candidates
4. **Monitoring**: View system logs, audit trails, and statistics

### For Election Officers
1. **Election Management**: Create and configure specific elections
2. **Candidate Registration**: Manage candidate applications
3. **Results Management**: View and publish election results

### For Staff
1. **Student Management**: Register and verify student accounts
2. **Voting Assistance**: Help students with voting process
3. **Basic Reporting**: View verification and participation reports

### For Students
1. **Secure Voting**: Cast votes in active elections
2. **Results Viewing**: View published election results
3. **Vote Verification**: Verify vote was counted correctly

## 🔒 Security Measures

- **Authentication**: Secure login with session management
- **Authorization**: Role-based access control
- **Data Protection**: Input validation and output encoding
- **File Security**: Secure upload handling with type validation
- **Database Security**: Prepared statements and parameterized queries
- **Session Security**: HTTPOnly cookies, CSRF protection
- **Audit Logging**: Comprehensive activity tracking

## 📊 Database Schema

The system uses a well-structured relational database with proper foreign key constraints:

- **Reference Tables**: levels, programs, election_types, user_roles
- **User Management**: users, user_sessions, students
- **Election System**: elections, positions, candidates
- **Voting System**: voting_sessions, votes, abstain_votes
- **Results**: election_results, voting_statistics
- **Security**: audit_logs, security_events

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/new-feature`)
3. Commit your changes (`git commit -am 'Add new feature'`)
4. Push to the branch (`git push origin feature/new-feature`)
5. Create a Pull Request

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🐛 Bug Reports & Feature Requests

Please use the [GitHub Issues](https://github.com/yourusername/online-voting-system/issues) page to report bugs or request features.

## 👥 Support

For support and questions:
- Create an issue on GitHub
- Review the documentation in `CLAUDE.md`
- Check the system logs in the `logs/` directory

## 🙏 Acknowledgments

- Bootstrap team for the responsive framework
- Chart.js for data visualization components
- TCPDF for PDF generation capabilities
- Font Awesome for the icon set

---

**⚠️ Security Notice**: This system handles sensitive voting data. Ensure you:
- Change default passwords immediately
- Keep the system updated
- Use HTTPS in production
- Regularly backup your database
- Monitor system logs for suspicious activity

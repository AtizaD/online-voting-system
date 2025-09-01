ONLINE VOTING/
│
├── index.php                          # Main landing page (redirects to auth)
├── .htaccess                          # Apache URL rewriting and security
├── config/
│   ├── database.php                   # Database connection settings
│   ├── config.php                     # System configuration
│
├── auth/
│   ├── index.php                      # Single unified login page
│   ├── handler.php                    # login/register/verify/reset
│   ├── logout.php                     # Logout functionality
│   ├── session.php                    # Session management
│
├── admin/                             # Admin Dashboard
│   ├── index.php                      # Admin main dashboard
│   ├── profile.php                    # Admin profile management
│   │
│   ├── students/                      # Student Management
│   │   ├── index.php                  # Student list/overview
│   │   ├── manage.php                 # Add/Edit/Delete students
│   │   ├── verify.php                 # Verify students
│   │   ├── import.php                 # Bulk import students (CSV)
│   │   ├── export.php                 # Export student data
│   │   └── bulk-actions.php           # Bulk student operations
│   │
│   ├── elections/                     # Election Management
│   │   ├── index.php                  # Elections overview
│   │   ├── manage.php                 # Create/Edit/Delete elections
│   │   ├── activate.php               # Activate/deactivate election
│   │   ├── positions.php              # Manage election positions
│   │   ├── settings.php               # Election-specific settings
│   │   └── duplicate.php              # Duplicate election setup
│   │
│   ├── candidates/                    # Candidate Management
│   │   ├── index.php                  # Candidates overview
│   │   ├── manage.php                 # Add/Edit/Delete candidates
│   │   └── photos.php                 # Manage candidate photos
│   │
│   ├── voting/                        # Voting Management
│   │   ├── index.php                  # Voting overview/monitoring
│   │   ├── monitor.php                # Real-time voting monitor
│   │   ├── sessions.php               # Active voting sessions
│   │   ├── audit.php                  # Voting audit trail
│   │   └── troubleshoot.php           # Voting issues resolution
│   │
│   ├── results/                       # Results Management
│   │   ├── index.php                  # Results dashboard
│   │   ├── view.php                   # View election results
│   │   ├── publish.php                # Publish/unpublish results
│   │   ├── export.php                 # Export results
│   │   ├── analytics.php              # Detailed analytics
│   │   └── archive.php                # Archive old results
│   │
│   ├── reports/                       # Reporting System
│   │   ├── index.php                  # Reports dashboard
│   │   ├── voting-stats.php           # Voting statistics
│   │   ├── turnout.php                # Voter turnout reports
│   │   ├── demographics.php           # Demographic analysis
│   │   ├── audit-logs.php             # System audit logs
│   │   ├── security-events.php        # Security incident reports
│   │   └── custom-report.php          # Custom report builder
│   │
│   ├── users/                         # User Management (Admin only)
│   │   ├── index.php                  # Users overview
│   │   ├── manage.php                 # Add/Edit/Delete users
│   │   ├── roles.php                  # Manage user roles
│   │   └── activity.php               # User activity logs
│   │
│   └── settings/                      # System Settings (Admin only)
│       ├── index.php                  # Settings dashboard
│       ├── general.php                # General system settings
│       ├── security.php               # Security settings
│       ├── voting.php                 # Voting system settings
│       ├── backup.php                 # Database backup
│       ├── maintenance.php            # System maintenance
│       └── logs.php                   # System logs viewer
│
├── election-officer/                  # Election Officer Dashboard
│   ├── index.php                      # Election officer main dashboard
│   ├── profile.php                    # Profile management
│   │
│   ├── elections/                     # Election Management (Limited)
│   │   ├── index.php                  # View assigned elections
│   │   ├── manage.php                 # Create/Edit elections
│   │   ├── positions.php              # Manage positions
│   │   └── settings.php               # Election settings
│   │
│   ├── candidates/                    # Candidate Management
│   │   ├── index.php                  # Candidates overview
│   │   ├── manage.php                 # Add/Edit/Approve candidates
│   │
│   ├── voting/                        # Voting Oversight
│   │   ├── monitor.php                # Monitor voting process
│   │   ├── issues.php                 # Handle voting issues
│   │   └── sessions.php               # View voting sessions
│   │
│   ├── results/                       # Results Management
│   │   ├── view.php                   # View results
│   │   ├── publish.php                # Publish results
│   │   └── reports.php                # Generate reports
│   │
│   └── students/                      # Student Management (Limited)
│       ├── verify.php                 # Verify student eligibility
│       └── list.php                   # View student list
│
├── staff/                             # Staff Dashboard
│   ├── index.php                      # Staff main dashboard
│   ├── profile.php                    # Profile management
│   │
│   ├── students/                      # Student Management
│   │   ├── index.php                  # Student overview
│   │   ├── manage.php                 # Add/Edit students
│   │   └── verify.php                 # Verify students
│   │
│   ├── reports/                       # Reporting (Limited)
│   │   ├── index.php                  # Reports dashboard
│   │   ├── students.php               # Student reports
│   │   └── verification.php           # Verification reports
│   │
│   └── voting/                        # Voting Support
│       ├── assist.php                 # Assist students with voting
│       └── issues.php                 # Report voting issues
│
├── student/                           # Student Dashboard
│   ├── index.php                      # Student main dashboard
│   ├── profile.php                    # Student profile view
│   ├── vote.php                       # Voting interface
│   ├── elections.php                  # Available elections
│   ├── candidates.php                 # View candidates
│   ├── results.php                    # View published results
│   ├── verify-vote.php                # Verify vote was cast
│   ├── history.php                    # Voting history
│   └── help.php                       # Voting help/FAQ
│
├── api/                               # API Endpoints
│   ├── auth/
│   │   ├── handler.php                # login/logout/verify/session
│   │   └── role-check.php             # Role permissions check
│   │
│   ├── students/
│   │   ├── crud.php                   # Student CRUD operations
│   │   ├── verify.php                 # Student verification
│   │   ├── search.php                 # Student search
│   │   └── import.php                 # Bulk import API
│   │
│   ├── elections/
│   │   ├── crud.php                   # Election CRUD 
│   │   ├── activate.php               # Activate/deactivate
│   │   ├── positions.php              # Position management
│   │   └── status.php                 # Election status
│   │
│   ├── candidates/
│   │   ├── crud.php                   # Candidate CRUD 
│   │   ├── approve.php                # Candidate approval
│   │   └── photos.php                 # Photo management
│   │
│   ├── voting/                        # Voting APIs
│   │   ├── cast-vote.php              # Cast vote endpoint
│   │   ├── verify-vote.php            # Vote verification
│   │   ├── session.php                # Voting session management
│   │   └── status.php                 # Voting status check
│   │
│   └── results/                       # Results APIs
│       ├── index.php                  # Get results
│       ├── live.php                   # Live results feed
│       └── export.php                 # Export results
│
├── includes/                          # Common Include Files
│   ├── header.php                     # Common header
│   ├── footer.php                     # Common footer
│   ├── functions.php                  # Helper functions
│   ├── security.php                   # Security functions
│   ├── validation.php                 # Form validation
│   └── pagination.php                 # Pagination helper
│
├── assets/                            # Static Assets
│   ├── css/                           # Stylesheets
│   │   ├── bootstrap.min.css          # Bootstrap CSS
│   │   ├── admin.css                  # Admin panel styles
│   │   ├── election-officer.css       # Election officer styles
│   │   ├── staff.css                  # Staff panel styles
│   │   ├── student.css                # Student interface styles
│   │   ├── voting.css                 # Voting interface styles
│   │   └── main.css                   # Main application styles
│   │
│   ├── js/                            # JavaScript Files
│   │   ├── bootstrap.bundle.min.js    # Bootstrap JavaScript
│   │   ├── jquery.min.js              # jQuery library
│   │   ├── admin.js                   # Admin panel scripts
│   │   ├── election-officer.js        # Election officer scripts
│   │   ├── staff.js                   # Staff panel scripts
│   │   ├── student.js                 # Student interface scripts
│   │   ├── voting.js                  # Voting functionality
│   │   ├── results.js                 # Results display scripts
│   │   └── main.js                    # Main application scripts
│   │
│   ├── images/                        # Image Assets
│   │   ├── logo.png                   # School/system logo
│   │   ├── default-avatar.png         # Default user avatar
│   │   ├── candidates/                # Candidate photos
│   │   ├── students/                  # Student photos
│   │   └── icons/                     # System icons
│   │
│   └── uploads/                       # File Uploads
│       ├── students/                  # Student document uploads
│       ├── candidates/                # Candidate document uploads
│       ├── imports/                   # CSV import files
│       └── exports/                   # Generated export files
│
├── logs/                              # Application Logs
│   ├── error.log                      # PHP error logs
│   ├── access.log                     # Access logs
│   ├── voting.log                     # Voting activity logs
│   ├── security.log                   # Security event logs
│   └── audit.log                      # System audit logs
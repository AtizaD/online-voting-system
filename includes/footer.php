    </main>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="footer-content">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0 text-muted">&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        Version 1.0 | 
                        <a href="<?= SITE_URL ?>admin/settings/" class="text-decoration-none">System Settings</a>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="<?= SITE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }

        // Desktop sidebar collapse toggle
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const mainHeader = document.querySelector('.main-header');
            const toggleButton = document.querySelector('.sidebar-toggle');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            mainHeader.classList.toggle('sidebar-collapsed');
            
            // Add visual feedback to button
            if (sidebar.classList.contains('collapsed')) {
                toggleButton.style.backgroundColor = '#f1f5f9';
                toggleButton.style.color = '#667eea';
            } else {
                toggleButton.style.backgroundColor = '';
                toggleButton.style.color = '';
            }
            
            // Store collapse state in localStorage
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }

        // Restore sidebar state on page load
        document.addEventListener('DOMContentLoaded', function() {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('main-content');
                const mainHeader = document.querySelector('.main-header');
                const toggleButton = document.querySelector('.sidebar-toggle');
                
                // Remove initial class and apply proper collapsed classes
                document.documentElement.classList.remove('sidebar-initially-collapsed');
                
                sidebar.classList.add('collapsed');
                mainContent.classList.add('sidebar-collapsed');
                mainHeader.classList.add('sidebar-collapsed');
                
                if (toggleButton) {
                    toggleButton.style.backgroundColor = '#f1f5f9';
                    toggleButton.style.color = '#667eea';
                }
            } else {
                // Remove initial class if not collapsed
                document.documentElement.classList.remove('sidebar-initially-collapsed');
            }
        });


        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-menu-toggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target) &&
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
            }
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    if (alert && alert.parentNode) {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(function() {
                            if (alert.parentNode) {
                                alert.parentNode.removeChild(alert);
                            }
                        }, 500);
                    }
                }, 5000);
            });
        });

        // Confirm dialogs for delete actions
        function confirmDelete(message = 'Are you sure you want to delete this item?') {
            return confirm(message);
        }

        // Form validation helpers
        function validateRequired(formSelector) {
            const form = document.querySelector(formSelector);
            if (!form) return false;
            
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            return isValid;
        }

        // Real-time search functionality
        function initializeSearch(inputSelector, itemsSelector) {
            const searchInput = document.querySelector(inputSelector);
            const items = document.querySelectorAll(itemsSelector);
            
            if (searchInput && items.length > 0) {
                searchInput.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    
                    items.forEach(function(item) {
                        const text = item.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        }

        // Session timeout warning
        let sessionTimeout;
        function resetSessionTimeout() {
            clearTimeout(sessionTimeout);
            sessionTimeout = setTimeout(function() {
                if (confirm('Your session is about to expire. Click OK to extend your session.')) {
                    fetch('<?= SITE_URL ?>auth/extend-session.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                resetSessionTimeout();
                            } else {
                                window.location.href = '<?= SITE_URL ?>';
                            }
                        })
                        .catch(() => {
                            window.location.href = '<?= SITE_URL ?>';
                        });
                } else {
                    window.location.href = '<?= SITE_URL ?>';
                }
            }, 1740000); // 29 minutes (30 min session - 1 min warning)
        }

        // Initialize session timeout for authenticated users
        <?php if ($current_user): ?>
        document.addEventListener('DOMContentLoaded', function() {
            resetSessionTimeout();
            
            // Reset timeout on user activity
            ['click', 'keypress', 'scroll', 'mousemove'].forEach(function(event) {
                document.addEventListener(event, resetSessionTimeout);
            });
        });
        <?php endif; ?>
    </script>

    <style>
        .main-footer {
            margin-left: var(--sidebar-width);
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 1.5rem 2rem;
            margin-top: 2rem;
        }

        @media (max-width: 768px) {
            .main-footer {
                margin-left: 0;
            }
        }

        .footer-content p {
            color: #64748b;
            font-size: 0.875rem;
        }

        .footer-content a {
            color: var(--primary-color);
        }

        .footer-content a:hover {
            color: var(--secondary-color);
        }
    </style>

</body>
</html>
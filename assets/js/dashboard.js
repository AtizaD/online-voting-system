class AdminDashboard {
    constructor() {
        this.charts = {};
        this.refreshInterval = null;
        this.lastUpdate = null;
        this.isRefreshing = false;
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.startAutoRefresh();
        this.initializeNotifications();
    }

    setupEventListeners() {
        // Refresh buttons
        document.querySelectorAll('[data-refresh]').forEach(button => {
            button.addEventListener('click', (e) => {
                const target = e.target.dataset.refresh;
                this.refreshSection(target);
            });
        });

        // Chart controls
        document.querySelectorAll('[data-chart-period]').forEach(button => {
            button.addEventListener('click', (e) => {
                const period = e.target.dataset.chartPeriod;
                const chartId = e.target.dataset.chartId;
                this.updateChartPeriod(chartId, period);
            });
        });

        // Real-time toggle
        const realtimeToggle = document.getElementById('realtime-toggle');
        if (realtimeToggle) {
            realtimeToggle.addEventListener('change', (e) => {
                if (e.target.checked) {
                    this.startAutoRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            });
        }
    }

    async refreshDashboard() {
        if (this.isRefreshing) return;
        
        this.isRefreshing = true;
        this.showLoadingState();

        try {
            const response = await fetch('api/dashboard-data.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            this.updateDashboard(data);
            this.lastUpdate = new Date();
            this.updateLastRefreshTime();

        } catch (error) {
            console.error('Error refreshing dashboard:', error);
            this.showError('Failed to refresh dashboard data');
        } finally {
            this.isRefreshing = false;
            this.hideLoadingState();
        }
    }

    updateDashboard(data) {
        this.updateStatistics(data.stats);
        this.updateCharts(data);
        this.updateActivities(data.recent_activities);
        this.updateSystemHealth(data.system_health);
    }

    updateStatistics(stats) {
        const statElements = {
            'active-elections': stats.active_elections,
            'verified-students': stats.verified_students,
            'total-votes': stats.total_votes,
            'security-alerts': stats.security_alerts
        };

        Object.entries(statElements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                this.animateCounter(element, parseInt(value));
            }
        });

        // Update security alert badge
        const alertBadge = document.querySelector('.alert-badge');
        if (alertBadge) {
            if (stats.security_alerts > 0) {
                alertBadge.textContent = stats.security_alerts;
                alertBadge.style.display = 'flex';
            } else {
                alertBadge.style.display = 'none';
            }
        }
    }

    updateCharts(data) {
        // Update voting trends chart
        if (this.charts.votingTrends && data.voting_trends) {
            this.charts.votingTrends.data.labels = data.voting_trends.labels;
            this.charts.votingTrends.data.datasets[0].data = data.voting_trends.data;
            this.charts.votingTrends.update('none'); // Use 'none' to prevent animations during updates
        }

        // Update security events chart
        if (this.charts.security && data.security_events && data.security_events.data.length > 0) {
            this.charts.security.data.labels = data.security_events.labels;
            this.charts.security.data.datasets[0].data = data.security_events.data;
            this.charts.security.update('none');
        }

        // Update election status chart
        if (this.charts.electionStatus && data.stats) {
            this.charts.electionStatus.data.datasets[0].data = [
                data.stats.active_elections,
                data.stats.draft_elections,
                data.stats.completed_elections
            ];
            this.charts.electionStatus.update('none');
        }

        // Update hourly votes chart (if exists)
        if (this.charts.hourlyVotes && data.hourly_votes) {
            this.charts.hourlyVotes.data.labels = data.hourly_votes.labels.map(h => h + ':00');
            this.charts.hourlyVotes.data.datasets[0].data = data.hourly_votes.data;
            this.charts.hourlyVotes.update('none');
        }
    }

    updateActivities(activities) {
        const activitiesList = document.getElementById('activities-list');
        if (!activitiesList) return;

        activitiesList.innerHTML = '';
        
        activities.forEach(activity => {
            const activityElement = this.createActivityElement(activity);
            activitiesList.appendChild(activityElement);
        });
    }

    createActivityElement(activity) {
        const div = document.createElement('div');
        div.className = 'activity-item';
        
        const userName = activity.first_name ? 
            `${activity.first_name} ${activity.last_name}` : 
            activity.student_first_name ? 
            `${activity.student_first_name} ${activity.student_last_name}` : 
            'System';

        div.innerHTML = `
            <div class="activity-icon bg-primary bg-opacity-10">
                <i class="bi bi-activity text-primary"></i>
            </div>
            <div class="activity-content">
                <div class="activity-title">${this.escapeHtml(activity.action)}</div>
                <div class="activity-description">by ${this.escapeHtml(userName)}</div>
            </div>
            <div class="activity-time">
                ${this.formatTime(activity.timestamp)}
            </div>
        `;

        return div;
    }

    updateSystemHealth(health) {
        const healthElements = document.querySelectorAll('[data-health]');
        healthElements.forEach(element => {
            const metric = element.dataset.health;
            if (health[metric] !== undefined) {
                element.textContent = health[metric];
            }
        });
    }

    animateCounter(element, targetValue) {
        const currentValue = parseInt(element.textContent.replace(/,/g, '')) || 0;
        const increment = (targetValue - currentValue) / 20;
        let current = currentValue;

        const timer = setInterval(() => {
            current += increment;
            if ((increment > 0 && current >= targetValue) || (increment < 0 && current <= targetValue)) {
                current = targetValue;
                clearInterval(timer);
            }
            element.textContent = Math.floor(current).toLocaleString();
        }, 50);
    }

    showLoadingState() {
        const refreshButton = document.querySelector('.refresh-button');
        if (refreshButton) {
            refreshButton.innerHTML = '<i class="bi bi-arrow-clockwise me-2 spinner-border spinner-border-sm"></i>Refreshing...';
            refreshButton.disabled = true;
        }

        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '0.7';
        });
    }

    hideLoadingState() {
        const refreshButton = document.querySelector('.refresh-button');
        if (refreshButton) {
            refreshButton.innerHTML = '<i class="bi bi-arrow-clockwise me-2"></i>Refresh';
            refreshButton.disabled = false;
        }

        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '1';
        });
    }

    showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
        errorDiv.style.zIndex = '9999';
        errorDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(errorDiv);
        
        setTimeout(() => {
            errorDiv.remove();
        }, 5000);
    }

    startAutoRefresh() {
        this.stopAutoRefresh(); // Clear any existing interval
        this.refreshInterval = setInterval(() => {
            this.refreshDashboard();
        }, 30000); // Refresh every 30 seconds

        // Show real-time indicator
        const indicator = document.getElementById('realtime-indicator');
        if (indicator) {
            indicator.style.display = 'inline-block';
        }
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }

        // Hide real-time indicator
        const indicator = document.getElementById('realtime-indicator');
        if (indicator) {
            indicator.style.display = 'none';
        }
    }

    updateLastRefreshTime() {
        const timeElement = document.getElementById('last-refresh-time');
        if (timeElement && this.lastUpdate) {
            timeElement.textContent = this.formatTime(this.lastUpdate.toISOString());
        }
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

        if (diff < 60) return `${diff}s ago`;
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        return date.toLocaleDateString();
    }

    escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    initializeNotifications() {
        // Request notification permission if supported
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }

    showNotification(title, message, type = 'info') {
        if ('Notification' in window && Notification.permission === 'granted') {
            const icon = type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️';
            new Notification(title, {
                body: message,
                icon: `/online_voting/assets/images/icon-${type}.png`,
                badge: '/online_voting/assets/images/badge.png'
            });
        }

        // Also show toast notification
        this.showToast(title, message, type);
    }

    showToast(title, message, type = 'info') {
        const toastContainer = document.getElementById('toast-container') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'warning' ? 'warning' : 'primary'} border-0`;
        toast.setAttribute('role', 'alert');
        
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <strong>${title}</strong><br>${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;

        toastContainer.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
        return container;
    }

    // Export dashboard data
    async exportDashboardData(format = 'json') {
        try {
            const response = await fetch('api/export-dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ format })
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = `dashboard-data-${new Date().toISOString().split('T')[0]}.${format}`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                this.showToast('Export Complete', `Dashboard data exported successfully as ${format.toUpperCase()}`, 'success');
            } else {
                throw new Error('Export failed');
            }
        } catch (error) {
            this.showToast('Export Failed', 'Failed to export dashboard data', 'error');
        }
    }

    // Refresh specific section
    async refreshSection(section) {
        const sectionElement = document.getElementById(`${section}-section`);
        if (sectionElement) {
            sectionElement.classList.add('pulse');
            
            setTimeout(() => {
                sectionElement.classList.remove('pulse');
            }, 1000);
        }

        if (section === 'activities') {
            await this.refreshDashboard();
        }
    }
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.adminDashboard = new AdminDashboard();
});

// Global functions for backward compatibility
function refreshDashboard() {
    if (window.adminDashboard) {
        window.adminDashboard.refreshDashboard();
    }
}

function refreshActivities() {
    if (window.adminDashboard) {
        window.adminDashboard.refreshSection('activities');
    }
}
<?php
session_start();

// Check if student is logged in
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Voting Dashboard';
$student_name = $_SESSION['student_name'] ?? 'Student';
$student_number = $_SESSION['student_number'] ?? '';
$student_program = $_SESSION['student_program'] ?? '';
$student_class = $_SESSION['student_class'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="<?= SITE_URL ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/font-awesome.min.css">
    <style>
        :root {
            --primary-color: #059669;
            --secondary-color: #047857;
            --success-color: #10b981;
            --danger-color: #dc2626;
            --warning-color: #d97706;
        }

        body {
            background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color), var(--success-color));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }

        .student-info-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-left: 5px solid var(--primary-color);
        }

        .election-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .election-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            border-color: var(--primary-color);
        }

        .election-status {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .status-active {
            background: #dcfce7;
            color: var(--primary-color);
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-upcoming {
            background: #fef3c7;
            color: #d97706;
        }

        .vote-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--success-color));
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .vote-btn:hover {
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
            color: white;
            transform: translateY(-2px);
        }

        .vote-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }

        .logout-btn {
            background: transparent;
            border: 2px solid white;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: white;
            color: var(--primary-color);
        }

        .no-elections {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .no-elections i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f4f6;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="mb-1">
                        <i class="fas fa-vote-yea me-2"></i>
                        Voting Dashboard
                    </h1>
                    <p class="mb-0 opacity-90">Welcome, <?= htmlspecialchars($student_name) ?></p>
                </div>
                <div class="col-auto">
                    <button class="logout-btn" onclick="logout()">
                        <i class="fas fa-sign-out-alt me-1"></i>
                        Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Student Information -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="student-info-card">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Student Number:</strong><br>
                            <?= htmlspecialchars($student_number) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Name:</strong><br>
                            <?= htmlspecialchars($student_name) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Program:</strong><br>
                            <?= htmlspecialchars($student_program) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Class:</strong><br>
                            <?= htmlspecialchars($student_class) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Elections Section -->
        <div class="row">
            <div class="col-12">
                <h3 class="mb-3">
                    <i class="fas fa-poll me-2 text-primary"></i>
                    Available Elections
                </h3>
                
                <div id="electionsContainer">
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>Loading available elections...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= SITE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            loadElections();
        });

        async function loadElections() {
            try {
                const response = await fetch('/online_voting/api/elections/list.php?status=active');
                const data = await response.json();

                const container = document.getElementById('electionsContainer');

                if (data.success && data.data && data.data.length > 0) {
                    let html = '';
                    
                    data.data.forEach(election => {
                        const startDate = new Date(election.start_date);
                        const endDate = new Date(election.end_date);
                        const now = new Date();
                        
                        let status = 'upcoming';
                        let statusText = 'Upcoming';
                        let canVote = false;
                        
                        if (now >= startDate && now <= endDate) {
                            status = 'active';
                            statusText = 'Active';
                            canVote = true;
                        } else if (now > endDate) {
                            status = 'completed';
                            statusText = 'Completed';
                        }

                        html += `
                            <div class="election-card">
                                <div class="election-status status-${status}">
                                    <i class="fas fa-circle me-1"></i>
                                    ${statusText}
                                </div>
                                
                                <h4 class="mb-3">${election.name}</h4>
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <strong>Type:</strong><br>
                                        <span class="text-muted">${election.election_type_name || 'General'}</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Start Date:</strong><br>
                                        <span class="text-muted">${startDate.toLocaleDateString()} ${startDate.toLocaleTimeString()}</span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>End Date:</strong><br>
                                        <span class="text-muted">${endDate.toLocaleDateString()} ${endDate.toLocaleTimeString()}</span>
                                    </div>
                                </div>
                                
                                <div class="row align-items-center">
                                    <div class="col">
                                        <p class="mb-0 text-muted">${election.description || 'No description available'}</p>
                                    </div>
                                    <div class="col-auto">
                                        ${canVote ? 
                                            `<button class="vote-btn" onclick="startVoting(${election.election_id})">
                                                <i class="fas fa-vote-yea me-2"></i>
                                                Cast Vote
                                            </button>` : 
                                            `<button class="vote-btn" disabled>
                                                <i class="fas fa-clock me-2"></i>
                                                ${statusText}
                                            </button>`
                                        }
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `
                        <div class="no-elections">
                            <i class="fas fa-vote-yea"></i>
                            <h4>No Elections Available</h4>
                            <p>There are currently no active elections. Please check back later.</p>
                        </div>
                    `;
                }
            } catch (error) {
                document.getElementById('electionsContainer').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Failed to load elections. Please refresh the page to try again.
                    </div>
                `;
                console.error('Error loading elections:', error);
            }
        }

        function startVoting(electionId) {
            // Redirect to voting interface for specific election
            window.location.href = `voting.php?election_id=${electionId}`;
        }

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                fetch('/online_voting/student/logout.php', {
                    method: 'POST'
                }).then(() => {
                    window.location.href = '../index.php';
                }).catch(() => {
                    // Force redirect even if logout request fails
                    window.location.href = '../index.php';
                });
            }
        }
    </script>
</body>
</html>
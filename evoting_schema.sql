-- SHS E-Voting System Database Schema
-- CORRECTED VERSION - All errors fixed, no triggers

-- ==========================================
-- CORE REFERENCE TABLES
-- ==========================================

CREATE TABLE levels (
    level_id INT PRIMARY KEY AUTO_INCREMENT,
    level_name VARCHAR(50) NOT NULL UNIQUE, -- e.g., 'SHS 1', 'SHS 2', 'SHS 3'
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE programs (
    program_id INT PRIMARY KEY AUTO_INCREMENT,
    program_name VARCHAR(100) NOT NULL UNIQUE, -- e.g., 'BUSINESS', 'SCIENCE', 'GENERAL ARTS'
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE classes (
    class_id INT PRIMARY KEY AUTO_INCREMENT,
    program_id INT NOT NULL,
    level_id INT NOT NULL,
    class_name VARCHAR(100) NOT NULL, -- e.g., '1B1', '2S1', '1A12'
    capacity INT DEFAULT 50,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE CASCADE,
    FOREIGN KEY (level_id) REFERENCES levels(level_id) ON DELETE RESTRICT,
    UNIQUE KEY unique_class_program_level (program_id, level_id, class_name)
);

-- ==========================================
-- ELECTION TYPES
-- ==========================================

CREATE TABLE election_types (
    election_type_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE, -- 'Student Council', 'Class Representative', 'Prefect', etc.
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ==========================================
-- USER MANAGEMENT & AUTHENTICATION
-- ==========================================

CREATE TABLE user_roles (
    role_id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL UNIQUE, -- 'admin', 'election_officer', 'teacher'
    description TEXT,
    permissions JSON, -- Store permissions as JSON array
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) UNIQUE,
    password_hash VARCHAR(255) NOT NULL, -- Use bcrypt or similar
    role_id INT NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    is_verified BOOLEAN DEFAULT FALSE, -- account verification after registration
    last_login TIMESTAMP NULL,
    failed_login_attempts INT DEFAULT 0,
    account_locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (role_id) REFERENCES user_roles(role_id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ==========================================
-- STUDENT MANAGEMENT
-- NOTE: All students must belong to a program and class
-- Classes are linked to both programs and levels
-- Structure: levels → programs → classes → students
-- All verified students are automatically eligible to vote
-- ==========================================

CREATE TABLE students (
    student_id INT PRIMARY KEY AUTO_INCREMENT,
    student_number VARCHAR(50) NOT NULL UNIQUE, -- School ID number
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    program_id INT NOT NULL, -- All students must belong to a program
    class_id INT NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    photo_url VARCHAR(500),
    is_verified BOOLEAN DEFAULT FALSE,
    verified_by INT, -- user_id of verifier
    verified_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE RESTRICT,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE RESTRICT,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ==========================================
-- ELECTION MANAGEMENT
-- NOTE: Simplified system - no eligibility restrictions, no candidate approval
-- ==========================================

CREATE TABLE elections (
    election_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    election_type_id INT NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
    max_votes_per_position INT DEFAULT 1,
    allow_abstain BOOLEAN DEFAULT TRUE,
    require_all_positions BOOLEAN DEFAULT FALSE, -- Must vote for all positions
    results_public BOOLEAN DEFAULT TRUE,
    results_published_at TIMESTAMP NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (election_type_id) REFERENCES election_types(election_type_id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT
);

CREATE TABLE positions (
    position_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL, -- e.g., 'President', 'Secretary', 'Class Representative'
    description TEXT,
    election_id INT NOT NULL,
    max_candidates INT DEFAULT 10,
    display_order INT DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    UNIQUE KEY unique_position_election (title, election_id)
);

CREATE TABLE candidates (
    candidate_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    position_id INT NOT NULL,
    election_id INT NOT NULL,
    photo_url VARCHAR(500),
    vote_count INT DEFAULT 0, -- Cached vote count for performance
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE CASCADE,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY unique_candidate_position (student_id, position_id, election_id)
    -- NOTE: No approval system - candidates are automatically registered when added
);

-- ==========================================
-- VOTING SYSTEM
-- NOTE: All verified students are eligible to vote - no separate eligibility checks
-- ==========================================

CREATE TABLE voting_sessions (
    session_id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    election_id INT NOT NULL,
    session_token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    status ENUM('active', 'completed', 'abandoned', 'invalid') DEFAULT 'active',
    votes_cast INT DEFAULT 0,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_election (student_id, election_id)
);

CREATE TABLE votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    candidate_id INT NOT NULL,
    position_id INT NOT NULL,
    election_id INT NOT NULL,
    session_id INT NOT NULL,
    vote_rank INT DEFAULT 1, -- For ranked choice voting (future use)
    ip_address VARCHAR(45),
    vote_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verification_code VARCHAR(100), -- For vote verification
    FOREIGN KEY (candidate_id) REFERENCES candidates(candidate_id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE CASCADE,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES voting_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_election_position (election_id, position_id),
    INDEX idx_vote_timestamp (vote_timestamp)
);

CREATE TABLE abstain_votes (
    abstain_id INT PRIMARY KEY AUTO_INCREMENT,
    position_id INT NOT NULL,
    election_id INT NOT NULL,
    session_id INT NOT NULL,
    vote_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE CASCADE,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES voting_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_abstain_election_position (election_id, position_id)
);

-- ==========================================
-- RESULTS & ANALYTICS
-- ==========================================

CREATE TABLE election_results (
    result_id INT PRIMARY KEY AUTO_INCREMENT,
    election_id INT NOT NULL,
    position_id INT NOT NULL,
    candidate_id INT NOT NULL,
    vote_count INT NOT NULL DEFAULT 0,
    abstain_count INT DEFAULT 0, -- Abstains for this position
    percentage DECIMAL(5,2),
    rank_position INT,
    is_winner BOOLEAN DEFAULT FALSE,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE CASCADE,
    FOREIGN KEY (candidate_id) REFERENCES candidates(candidate_id) ON DELETE CASCADE,
    UNIQUE KEY unique_result (election_id, position_id, candidate_id)
);

CREATE TABLE voting_statistics (
    stat_id INT PRIMARY KEY AUTO_INCREMENT,
    election_id INT NOT NULL,
    position_id INT, -- NULL for overall election stats
    total_eligible_voters INT DEFAULT 0,
    total_votes_cast INT DEFAULT 0,
    total_abstains INT DEFAULT 0,
    turnout_percentage DECIMAL(5,2),
    program_id INT, -- For program-specific stats
    level_id INT, -- For level-specific stats
    class_id INT, -- For class-specific stats
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (election_id) REFERENCES elections(election_id) ON DELETE CASCADE,
    FOREIGN KEY (position_id) REFERENCES positions(position_id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(program_id) ON DELETE SET NULL,
    FOREIGN KEY (level_id) REFERENCES levels(level_id) ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE SET NULL
);

-- ==========================================
-- SECURITY & AUDIT
-- ==========================================

CREATE TABLE audit_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    student_id INT, -- For student actions (voting, etc.)
    action VARCHAR(100) NOT NULL, -- 'login', 'vote', 'create_election', etc.
    table_name VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(128),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL,
    INDEX idx_user_action (user_id, action),
    INDEX idx_student_action (student_id, action),
    INDEX idx_timestamp (timestamp),
    INDEX idx_table_record (table_name, record_id)
);

CREATE TABLE security_events (
    event_id INT PRIMARY KEY AUTO_INCREMENT,
    event_type ENUM('failed_login', 'account_locked', 'suspicious_voting', 'multiple_vote_attempt', 'system_error') NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    description TEXT NOT NULL,
    user_id INT,
    student_id INT,
    ip_address VARCHAR(45),
    additional_data JSON,
    resolved BOOLEAN DEFAULT FALSE,
    resolved_by INT,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_event_type (event_type),
    INDEX idx_severity (severity),
    INDEX idx_resolved (resolved)
);

CREATE TABLE system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    is_encrypted BOOLEAN DEFAULT FALSE,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- ==========================================
-- FILE MANAGEMENT
-- ==========================================

CREATE TABLE uploaded_files (
    file_id INT PRIMARY KEY AUTO_INCREMENT,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_hash VARCHAR(255) NOT NULL, -- For integrity verification
    uploaded_by INT NOT NULL,
    related_table VARCHAR(100), -- 'students', 'candidates', 'users'
    related_id INT,
    is_deleted BOOLEAN DEFAULT FALSE,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_related (related_table, related_id)
);

-- ==========================================
-- PERFORMANCE INDEXES
-- ==========================================

-- Students indexes
CREATE INDEX idx_student_number ON students(student_number);
CREATE INDEX idx_student_program_class ON students(program_id, class_id);
CREATE INDEX idx_student_verified ON students(is_verified, is_active);

-- Elections indexes
CREATE INDEX idx_election_status ON elections(status);
CREATE INDEX idx_election_dates ON elections(start_date, end_date);
CREATE INDEX idx_election_type ON elections(election_type_id, status);

-- Candidates indexes
CREATE INDEX idx_candidate_election ON candidates(election_id);
CREATE INDEX idx_candidate_position ON candidates(position_id);
CREATE INDEX idx_candidate_student ON candidates(student_id);

-- Votes indexes
CREATE INDEX idx_votes_election ON votes(election_id, position_id);
CREATE INDEX idx_votes_candidate ON votes(candidate_id);

-- Sessions indexes
CREATE INDEX idx_session_expires ON user_sessions(expires_at, is_active);
CREATE INDEX idx_voting_session_status ON voting_sessions(status, completed_at);
CREATE INDEX idx_voting_session_election ON voting_sessions(election_id, status);

-- ==========================================
-- INITIAL DATA SETUP
-- ==========================================

-- Insert user roles
INSERT INTO user_roles (role_name, description, permissions) VALUES
('admin', 'System Administrator', '["all"]'),
('election_officer', 'Election Management Officer', '["manage_elections", "verify_students", "view_reports"]'),
('staff', 'Staff', '["verify_students", "view_reports"]');

-- Insert election types
INSERT INTO election_types (name, description) VALUES
('Student Council', 'School-wide student council elections'),
('Class Representative', 'Class representative elections'),
('Prefect', 'School prefect elections'),
('Committee', 'Various committee elections');

-- Insert system settings
INSERT INTO system_settings (setting_key, setting_value, description, category) VALUES
('system_name', 'SHS E-Voting System', 'Name of the voting system', 'general'),
('school_name', 'Your School Name', 'Name of the school', 'general'),
('max_login_attempts', '5', 'Maximum failed login attempts before account lock', 'security'),
('session_timeout', '3600', 'Session timeout in seconds', 'security'),
('vote_verification_enabled', 'true', 'Enable vote verification codes', 'voting'),
('results_auto_publish', 'false', 'Automatically publish results when election ends', 'voting'),
('photo_max_size', '2097152', 'Maximum photo upload size in bytes (2MB)', 'files'),
('allowed_photo_types', 'jpg,jpeg,png', 'Allowed photo file extensions', 'files'),
('voting_session_timeout', '1800', 'Voting session timeout in seconds (30 minutes)', 'voting'),
('allow_vote_verification', 'true', 'Allow students to verify their votes', 'voting'),
('auto_candidate_registration', 'true', 'Automatically register candidates without approval', 'voting');

-- Insert sample levels
INSERT INTO levels (level_name) VALUES
('SHS 1'),
('SHS 2'), 
('SHS 3');

-- Insert sample programs
INSERT INTO programs (program_name) VALUES
('General Science'),
('General Arts'),
('Business'),
('Home Economics'),
('Agriculture');

-- Insert default admin user (CHANGE PASSWORD IN PRODUCTION!)
INSERT INTO users (username, email, password_hash, role_id, first_name, last_name, is_verified) VALUES 
('admin', 'admin@school.edu.gh', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewpUmz3LRJYwZfgm', 1, 'System', 'Administrator', TRUE);
-- Default password is 'admin123' - MUST BE CHANGED after first login!
# E-Voting System API Documentation

## Overview
This API provides comprehensive endpoints for managing the e-voting system, including students, elections, candidates, positions, votes, and results. All endpoints return JSON responses and require proper authentication.

## Base URL
```
http://localhost/online_voting/api/
```

## Authentication
All API endpoints require authentication via session. Users must be logged in through the web interface first.

## Response Format
All responses follow this standard format:
```json
{
  "success": true|false,
  "message": "Response message",
  "data": {...}
}
```

## HTTP Status Codes
- `200` - Success
- `400` - Bad Request (validation errors, missing parameters)
- `401` - Unauthorized (authentication required)
- `403` - Forbidden (insufficient permissions)
- `405` - Method Not Allowed
- `500` - Internal Server Error

---

## Students API

### GET /api/students/list.php
**Description:** List students with filtering and pagination  
**Permissions:** admin, election_officer, staff  
**Parameters:**
- `page` (int) - Page number (default: 1)
- `per_page` (int) - Items per page (10-100, default: 25)
- `status` (string) - Filter by verification status: 'pending', 'verified'
- `program` (string) - Filter by program name
- `class` (string) - Filter by class name
- `search` (string) - Search by name or student number

**Response:**
```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "current_page": 1,
    "per_page": 25,
    "total_records": 150,
    "total_pages": 6
  },
  "statistics": {
    "total": 150,
    "pending": 25,
    "verified": 125
  }
}
```

### POST /api/students/create.php
**Description:** Create a new student  
**Permissions:** admin, staff  
**Required Fields:** student_number, first_name, last_name, program_id, class_id, gender  
**Optional Fields:** phone, photo_url

### PUT /api/students/update.php
**Description:** Update student information  
**Permissions:** admin, staff  
**Required Fields:** student_id

### DELETE /api/students/delete.php
**Description:** Delete/deactivate student  
**Permissions:** admin only

### POST /api/students/verify.php
**Description:** Verify or unverify student status  
**Permissions:** admin, election_officer, staff  
**Required Fields:** student_id, status ('verified' or 'pending')

---

## Elections API

### GET /api/elections/list.php
**Description:** List elections with filtering  
**Permissions:** admin, election_officer, staff, student  
**Parameters:**
- `page`, `per_page` - Pagination
- `status` (string) - Filter by status: 'draft', 'active', 'completed', 'cancelled'
- `type` (int) - Filter by election_type_id
- `search` (string) - Search by name or description

**Note:** Students can only see 'active' and 'completed' elections

### POST /api/elections/create.php
**Description:** Create a new election  
**Permissions:** admin, election_officer  
**Required Fields:** name, election_type_id, start_date, end_date  
**Optional Fields:** description, max_votes_per_position, allow_abstain, require_all_positions, results_public

---

## Candidates API

### GET /api/candidates/list.php
**Description:** List candidates with filtering  
**Permissions:** admin, election_officer, staff, student  
**Parameters:**
- `election_id` (int) - Filter by election
- `position_id` (int) - Filter by position
- `program` (string) - Filter by student program
- `search` (string) - Search by student name or number

---

## Voting API

### POST /api/votes/cast.php
**Description:** Cast votes in an election  
**Permissions:** student only  
**Required Fields:** election_id, votes (array of {position_id, candidate_id})  
**Note:** Use candidate_id = 0 for abstain votes

**Example Request:**
```json
{
  "election_id": 1,
  "votes": [
    {"position_id": 1, "candidate_id": 5},
    {"position_id": 2, "candidate_id": 0}
  ]
}
```

---

## Results API

### GET /api/results/election.php
**Description:** Get election results  
**Permissions:** admin, election_officer, staff, student (if results are public)  
**Parameters:** election_id (required)

**Response includes:**
- Election details
- Voting statistics
- Position results with candidate rankings
- Vote timeline (hourly breakdown)

---

## Database Schema Alignment

All API endpoints are carefully aligned with the database schema:

### Key Tables and Relationships:
- **students** → program_id (programs), class_id (classes)
- **elections** → election_type_id (election_types), created_by (users)
- **positions** → election_id (elections)
- **candidates** → student_id (students), position_id (positions), election_id (elections)
- **votes** → candidate_id (candidates), position_id (positions), election_id (elections), session_id (voting_sessions)
- **voting_sessions** → student_id (students), election_id (elections)

### Field Naming Convention:
- Primary keys: `table_name_id` (e.g., student_id, election_id)
- Boolean flags: `is_active`, `is_verified`
- Timestamps: `created_at`, `updated_at`, `verified_at`
- Enums: gender ('Male', 'Female'), status ('draft', 'active', 'completed', 'cancelled')

## Error Handling

All endpoints include comprehensive error handling:
- Input validation with specific error messages
- Database constraint validation
- Transaction rollback on errors
- Activity logging for audit trails
- Proper HTTP status codes

## Security Features

- Role-based access control
- Session-based authentication
- SQL injection prevention (prepared statements)
- Input sanitization
- Activity logging
- IP address tracking for votes
- Unique session tokens for voting

## Usage Examples

### List Students
```bash
curl -X GET "http://localhost/online_voting/api/students/list.php?status=pending&per_page=10"
```

### Create Election
```bash
curl -X POST "http://localhost/online_voting/api/elections/create.php" \
  -H "Content-Type: application/json" \
  -d '{"name":"Student Council 2024","election_type_id":1,"start_date":"2024-10-01 08:00:00","end_date":"2024-10-01 16:00:00"}'
```

### Get Results
```bash
curl -X GET "http://localhost/online_voting/api/results/election.php?election_id=1"
```
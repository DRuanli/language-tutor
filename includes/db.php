<?php
/**
 * Database Connection Handler
 * 
 * This file provides consistent database connection handling.
 * Include this file in any page that needs database access.
 */

// Include the configuration file
require_once 'config.php';

// Get database connection
$conn = get_db_connection();

// Function to safely close database connections
function close_connection($stmt = null, $conn = null) {
    if ($stmt !== null) {
        $stmt->close();
    }
    
    if ($conn !== null) {
        $conn->close();
    }
}
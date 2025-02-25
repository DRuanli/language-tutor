<?php
/**
 * Database Configuration
 * 
 * This file contains the database connection settings.
 * Update these values according to your MySQL setup in XAMPP.
 */

// Database credentials
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');        // Default XAMPP username
define('DB_PASSWORD', '');            // Default XAMPP password is empty
define('DB_NAME', 'language_tutor');

// Create database connection
function get_db_connection() {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}
<?php
// Database.php

class Database {
    private $host = 'localhost';
    private $db_name = 'event_mg';
    private $username = 'root';
    private $password = '';
    public $conn;

    /**
     * Get the database connection
     *
     * @return PDO|null The database connection object or null on failure
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to exceptions
            $this->conn->exec("set names utf8"); // Set character set
        } catch(PDOException $exception) {
            // Log the error in a production environment
            error_log("Database connection error: " . $exception->getMessage());
            // In a development environment, you might display the message (but not in production)
            // echo "Connection error: " . $exception->getMessage();
        }

        return $this->conn;
    }
}
?>

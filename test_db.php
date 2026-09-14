<?php
// Test database connection and tables
require_once 'config.php';

echo "<h2>Database Connection Test</h2>";
echo "<p>Connection: " . ($conn->connect_error ? "FAILED - " . $conn->connect_error : "SUCCESS") . "</p>";

if (!$conn->connect_error) {
    echo "<h3>Available Tables:</h3>";
    $tables = $conn->query('SHOW TABLES');
    if ($tables) {
        echo "<ul>";
        while($row = $tables->fetch_row()) {
            echo "<li>" . $row[0] . "</li>";
        }
        echo "</ul>";
        
        // Check bookings table specifically
        echo "<h3>Bookings Table Check:</h3>";
        $bookings_check = $conn->query("SHOW TABLES LIKE 'bookings'");
        if ($bookings_check && $bookings_check->num_rows > 0) {
            echo "<p style='color:green'>✓ Bookings table exists</p>";
            
            // Get table structure
            $columns = $conn->query("DESCRIBE bookings");
            if ($columns) {
                echo "<h4>Table Structure:</h4>";
                echo "<table border='1' cellpadding='5'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
                while($col = $columns->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $col['Field'] . "</td>";
                    echo "<td>" . $col['Type'] . "</td>";
                    echo "<td>" . $col['Null'] . "</td>";
                    echo "<td>" . $col['Key'] . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
            // Count records
            $count = $conn->query("SELECT COUNT(*) as total FROM bookings");
            if ($count) {
                $row = $count->fetch_assoc();
                echo "<p>Total bookings: " . $row['total'] . "</p>";
            }
        } else {
            echo "<p style='color:red'>✗ Bookings table does NOT exist</p>";
        }
    } else {
        echo "<p style='color:red'>Error querying tables: " . $conn->error . "</p>";
    }
}
?>

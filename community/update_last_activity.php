<?php
/**
 * Helper Function: Update User Last Activity
 * 
 * Updates the last_activity timestamp in user_credentials table
 * 
 * @param mysqli $conn Database connection
 * @param string $uni_id User unique identifier
 * @return bool True on success, false on failure
 */
function updateUserLastActivity($conn, $uni_id) {
    if (!$conn || !$uni_id) {
        return false;
    }
    
    $escapedUniId = mysqli_real_escape_string($conn, $uni_id);
    
    $sql = "UPDATE user_credentials 
            SET last_activity = NOW() 
            WHERE uni_id = '$escapedUniId'";
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        error_log("Failed to update last_activity for uni_id: $uni_id - " . mysqli_error($conn));
        return false;
    }
    
    return true;
}


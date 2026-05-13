<?php
/**
 * Function: Calculate Monthly Activity
 * 
 * Calculates and updates monthly active status for a user based on help actions
 * 
 * @param mysqli $conn Database connection
 * @param string $uni_id User unique identifier
 * @param string $month_year Month in YYYY-MM format (optional, defaults to current month)
 * @return array Result with success status and activity data
 */
function calculateMonthlyActivity($conn, $uni_id, $month_year = null) {
    if (!$conn || !$uni_id) {
        return [
            'success' => false,
            'error' => 'Invalid parameters'
        ];
    }
    
    // Default to current month if not provided
    if ($month_year === null) {
        $month_year = date('Y-m');
    }
    
    // Validate month_year format
    if (!preg_match('/^\d{4}-\d{2}$/', $month_year)) {
        return [
            'success' => false,
            'error' => 'Invalid month_year format. Expected YYYY-MM'
        ];
    }
    
    $escapedUniId = mysqli_real_escape_string($conn, $uni_id);
    $escapedMonthYear = mysqli_real_escape_string($conn, $month_year);
    
    // Get month start and end dates
    $monthStart = $month_year . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    
    // Get all verified help actions for this user in this month
    $sql = "SELECT 
                COUNT(*) as total_actions,
                COUNT(DISTINCT helped_uni_id) as unique_people,
                GROUP_CONCAT(DISTINCT helped_uni_id) as helped_users
            FROM help_actions
            WHERE helper_uni_id = '$escapedUniId'
            AND verified = 1
            AND DATE(created_at) >= '$monthStart'
            AND DATE(created_at) <= '$monthEnd'
            AND helper_uni_id != helped_uni_id"; // Exclude self-help
    
    $result = mysqli_query($conn, $sql);
    
    if (!$result) {
        return [
            'success' => false,
            'error' => 'Database query failed: ' . mysqli_error($conn)
        ];
    }
    
    $row = mysqli_fetch_assoc($result);
    $totalActions = (int)$row['total_actions'];
    $uniquePeople = (int)$row['unique_people'];
    
    // Check constraint: No user helped more than 2 times
    $constraintSql = "SELECT helped_uni_id, COUNT(*) as count
                      FROM help_actions
                      WHERE helper_uni_id = '$escapedUniId'
                      AND verified = 1
                      AND DATE(created_at) >= '$monthStart'
                      AND DATE(created_at) <= '$monthEnd'
                      AND helper_uni_id != helped_uni_id
                      GROUP BY helped_uni_id
                      HAVING count > 2";
    
    $constraintResult = mysqli_query($conn, $constraintSql);
    $violatesConstraint = ($constraintResult && mysqli_num_rows($constraintResult) > 0);
    
    // Determine if user is active
    // Criteria: 4+ actions, 2+ different people, no constraint violations
    $isActive = ($totalActions >= 4 && $uniquePeople >= 2 && !$violatesConstraint);
    
    // Update or insert into user_monthly_activity table
    $updateSql = "INSERT INTO user_monthly_activity 
                  (uni_id, month_year, help_actions_count, unique_people_helped, is_active, updated_at)
                  VALUES ('$escapedUniId', '$escapedMonthYear', $totalActions, $uniquePeople, " . ($isActive ? 1 : 0) . ", NOW())
                  ON DUPLICATE KEY UPDATE
                  help_actions_count = $totalActions,
                  unique_people_helped = $uniquePeople,
                  is_active = " . ($isActive ? 1 : 0) . ",
                  updated_at = NOW()";
    
    $updateResult = mysqli_query($conn, $updateSql);
    
    if (!$updateResult) {
        return [
            'success' => false,
            'error' => 'Failed to update monthly activity: ' . mysqli_error($conn)
        ];
    }
    
    return [
        'success' => true,
        'month_year' => $month_year,
        'help_actions_count' => $totalActions,
        'unique_people_helped' => $uniquePeople,
        'is_active' => $isActive,
        'constraint_violated' => $violatesConstraint
    ];
}


<?php
/**
 * API: Profile Help ("This user helped me")
 *
 * Flow: Helper clicks "This user helped me" -> record created as pending.
 * Helped user sees pending and can accept ("Yes, I provided help") or decline.
 *
 * GET:
 *   - uni_id + helped_uni_id: for profile page (is_helped, help_count, status if any)
 *   - pending_for=uni_id: list pending requests for this user (helped_uni_id = uni_id) with helper names
 *   - accepted_for=uni_id: list accepted rows for this user (so they can undo one)
 *
 * POST:
 *   - action=add, uni_id, helped_uni_id: create pending (helper says "this user helped me")
 *   - action=accept, uni_id, id (or helper_uni_id): helped user confirms (status -> accepted)
 *   - action=decline, uni_id, id (or helper_uni_id): helped user declines
 *   - action=undo, uni_id, id: helped user deletes one accepted row (undo confirmation)
 *   - action=cancel, uni_id, helped_uni_id: helper cancels their pending request (delete row)
 */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../conn.php';

if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'user_profile_helps'");
if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
    // Auto-create table so profile help works without running migrations manually
    $createSql = "CREATE TABLE IF NOT EXISTS `user_profile_helps` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `helper_uni_id` VARCHAR(36) NOT NULL,
        `helped_uni_id` VARCHAR(36) NOT NULL,
        `status` ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
        `rating` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1-10 help experience rating',
        `reason` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Optional: what help they provided',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_profile_help` (`helper_uni_id`, `helped_uni_id`),
        KEY `idx_helped_uni_id` (`helped_uni_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (mysqli_query($conn, $createSql)) {
        $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'user_profile_helps'");
    }
    if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $pendingFor = $_GET['pending_for'] ?? null;
            if ($pendingFor) {
                echo json_encode(['success' => true, 'pending' => []]);
            } else {
                echo json_encode(['success' => true, 'is_helped' => false, 'help_count' => 0, 'status' => null]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Profile help is not set up yet']);
        }
        mysqli_close($conn);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pendingFor = isset($_GET['pending_for']) ? trim((string)$_GET['pending_for']) : null;
    $acceptedFor = isset($_GET['accepted_for']) ? trim((string)$_GET['accepted_for']) : null;

    if ($pendingFor !== null && $pendingFor !== '') {
        // List pending requests for the helped user (so they can accept/decline)
        $esc = mysqli_real_escape_string($conn, $pendingFor);
        $sql = "SELECT ph.id, ph.helper_uni_id, ph.helped_uni_id, ph.status, ph.created_at, uc.name as helper_name
                FROM user_profile_helps ph
                INNER JOIN user_credentials uc ON uc.uni_id = ph.helper_uni_id
                WHERE ph.helped_uni_id = '$esc' AND ph.status = 'pending'
                ORDER BY ph.created_at DESC";
        $result = mysqli_query($conn, $sql);
        $pending = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $pending[] = [
                    'id' => (int)$row['id'],
                    'helper_uni_id' => $row['helper_uni_id'],
                    'helper_name' => $row['helper_name'] ?? 'Someone',
                    'created_at' => $row['created_at'],
                ];
            }
        }
        echo json_encode(['success' => true, 'pending' => $pending]);
        mysqli_close($conn);
        exit;
    }

    if ($acceptedFor !== null && $acceptedFor !== '') {
        // List accepted rows for the helped user (so they can undo one)
        $esc = mysqli_real_escape_string($conn, $acceptedFor);
        $sql = "SELECT ph.id, ph.helper_uni_id, ph.helped_uni_id, ph.created_at, uc.name as helper_name
                FROM user_profile_helps ph
                INNER JOIN user_credentials uc ON uc.uni_id = ph.helper_uni_id
                WHERE ph.helped_uni_id = '$esc' AND ph.status = 'accepted'
                ORDER BY ph.created_at DESC";
        $result = mysqli_query($conn, $sql);
        $accepted = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $accepted[] = [
                    'id' => (int)$row['id'],
                    'helper_uni_id' => $row['helper_uni_id'],
                    'helper_name' => $row['helper_name'] ?? 'Someone',
                    'created_at' => $row['created_at'],
                ];
            }
        }
        echo json_encode(['success' => true, 'accepted' => $accepted]);
        mysqli_close($conn);
        exit;
    }

    // Profile page: is current user (uni_id) helped status toward helped_uni_id, and total accepted count
    $uni_id = isset($_GET['uni_id']) ? trim((string)$_GET['uni_id']) : null;
    $helped_uni_id = isset($_GET['helped_uni_id']) ? trim((string)$_GET['helped_uni_id']) : null;
    if (!$helped_uni_id) {
        echo json_encode(['success' => false, 'error' => 'helped_uni_id is required']);
        mysqli_close($conn);
        exit;
    }
    $escHelped = mysqli_real_escape_string($conn, $helped_uni_id);

    $countSql = "SELECT COUNT(*) as c FROM user_profile_helps WHERE helped_uni_id = '$escHelped' AND status = 'accepted'";
    $countResult = mysqli_query($conn, $countSql);
    $helpCount = ($countResult && $row = mysqli_fetch_assoc($countResult)) ? (int)$row['c'] : 0;

    $isHelped = false;
    $status = null;
    if ($uni_id) {
        $escUni = mysqli_real_escape_string($conn, $uni_id);
        $rowSql = "SELECT status FROM user_profile_helps WHERE helper_uni_id = '$escUni' AND helped_uni_id = '$escHelped' LIMIT 1";
        $rowResult = mysqli_query($conn, $rowSql);
        if ($rowResult && $r = mysqli_fetch_assoc($rowResult)) {
            $status = $r['status'];
            $isHelped = in_array($status, ['pending', 'accepted'], true);
        }
    }

    echo json_encode([
        'success' => true,
        'is_helped' => $isHelped,
        'status' => $status,
        'help_count' => $helpCount,
    ]);
    mysqli_close($conn);
    exit;
}

// POST
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$uni_id = $input['uni_id'] ?? $_POST['uni_id'] ?? null;
$helped_uni_id = $input['helped_uni_id'] ?? $_POST['helped_uni_id'] ?? null;
$action = isset($input['action']) ? trim((string)$input['action']) : (isset($_POST['action']) ? trim((string)$_POST['action']) : '');
$id = isset($input['id']) ? (int)$input['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$helper_uni_id = $input['helper_uni_id'] ?? $_POST['helper_uni_id'] ?? null;

if (!$uni_id) {
    echo json_encode(['success' => false, 'error' => 'uni_id is required']);
    mysqli_close($conn);
    exit;
}

$escUni = mysqli_real_escape_string($conn, $uni_id);
$action = strtolower($action);

if ($action === 'add') {
    if (!$helped_uni_id) {
        echo json_encode(['success' => false, 'error' => 'helped_uni_id is required']);
        mysqli_close($conn);
        exit;
    }
    if ($uni_id === $helped_uni_id) {
        echo json_encode(['success' => false, 'error' => 'You cannot mark yourself as helped']);
        mysqli_close($conn);
        exit;
    }
    $escHelped = mysqli_real_escape_string($conn, $helped_uni_id);
    $check = mysqli_query($conn, "SELECT id, status FROM user_profile_helps WHERE helper_uni_id = '$escUni' AND helped_uni_id = '$escHelped' LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) {
        $existing = mysqli_fetch_assoc($check);
        echo json_encode([
            'success' => true,
            'status' => $existing['status'],
            'message' => $existing['status'] === 'pending' ? 'Waiting for their confirmation.' : 'Already recorded.',
        ]);
        mysqli_close($conn);
        exit;
    }
    $ins = "INSERT INTO user_profile_helps (helper_uni_id, helped_uni_id, status) VALUES ('$escUni', '$escHelped', 'pending')";
    if (!mysqli_query($conn, $ins)) {
        echo json_encode(['success' => false, 'error' => 'Failed to save: ' . mysqli_error($conn)]);
        mysqli_close($conn);
        exit;
    }
    echo json_encode([
        'success' => true,
        'status' => 'pending',
        'message' => 'They’ll be asked to confirm that they helped you.',
    ]);
    mysqli_close($conn);
    exit;
}

if ($action === 'accept' || $action === 'decline') {
    $newStatus = $action === 'accept' ? 'accepted' : 'declined';
    $rating = null;
    if ($action === 'accept' && isset($input['rating'])) {
        $r = (int) $input['rating'];
        if ($r >= 1 && $r <= 10) {
            $rating = $r;
        }
    }
    $reason = null;
    if ($action === 'accept' && isset($input['reason']) && is_string($input['reason'])) {
        $reason = trim($input['reason']);
        if ($reason !== '') {
            $reason = mysqli_real_escape_string($conn, mb_substr($reason, 0, 500));
        } else {
            $reason = null;
        }
    }
    $where = '';
    if ($id > 0) {
        $where = "id = " . (int)$id . " AND helped_uni_id = '$escUni'";
    } elseif ($helper_uni_id) {
        $escHelper = mysqli_real_escape_string($conn, $helper_uni_id);
        $where = "helper_uni_id = '$escHelper' AND helped_uni_id = '$escUni' AND status = 'pending'";
    } else {
        echo json_encode(['success' => false, 'error' => 'Provide id or helper_uni_id']);
        mysqli_close($conn);
        exit;
    }
    $setParts = ["status = '$newStatus'"];
    if ($rating !== null) {
        $setParts[] = 'rating = ' . (int)$rating;
    }
    if ($reason !== null) {
        $setParts[] = "reason = '$reason'";
    }
    $upd = "UPDATE user_profile_helps SET " . implode(', ', $setParts) . " WHERE $where LIMIT 1";
    if (!mysqli_query($conn, $upd) || mysqli_affected_rows($conn) === 0) {
        echo json_encode(['success' => false, 'error' => 'Could not update or record not found']);
        mysqli_close($conn);
        exit;
    }
    echo json_encode([
        'success' => true,
        'action' => $action,
        'message' => $action === 'accept'
            ? 'Thanks for confirming you provided help.'
            : 'Request declined.',
    ]);
    mysqli_close($conn);
    exit;
}

if ($action === 'undo') {
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'id is required']);
        mysqli_close($conn);
        exit;
    }
    // Only the helped user can undo (delete) an accepted row
    $del = "DELETE FROM user_profile_helps WHERE id = " . (int)$id . " AND helped_uni_id = '$escUni' AND status = 'accepted' LIMIT 1";
    if (!mysqli_query($conn, $del) || mysqli_affected_rows($conn) === 0) {
        echo json_encode(['success' => false, 'error' => 'Could not undo or record not found']);
        mysqli_close($conn);
        exit;
    }
    echo json_encode([
        'success' => true,
        'action' => 'undo',
        'message' => 'Confirmation removed.',
    ]);
    mysqli_close($conn);
    exit;
}

if ($action === 'cancel') {
    if (!$helped_uni_id) {
        echo json_encode(['success' => false, 'error' => 'helped_uni_id is required']);
        mysqli_close($conn);
        exit;
    }
    $escHelped = mysqli_real_escape_string($conn, $helped_uni_id);
    // Helper cancels their own pending request (delete row)
    $del = "DELETE FROM user_profile_helps WHERE helper_uni_id = '$escUni' AND helped_uni_id = '$escHelped' AND status = 'pending' LIMIT 1";
    if (!mysqli_query($conn, $del) || mysqli_affected_rows($conn) === 0) {
        echo json_encode(['success' => false, 'error' => 'Could not cancel or record not found']);
        mysqli_close($conn);
        exit;
    }
    echo json_encode([
        'success' => true,
        'action' => 'cancel',
        'message' => 'Request cancelled.',
    ]);
    mysqli_close($conn);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action. Use add, accept, decline, undo, or cancel']);
mysqli_close($conn);

<?php
/**
 * Helper script to understand and update account relationships
 * This shows how uni_id connects to account_id in trades
 */

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/conn.php';

echo "<h2>Account Relationship Checker</h2>";

// Step 1: Show all users
echo "<h3>Step 1: All Users (user_credentials table)</h3>";
$usersSql = "SELECT uni_id, name, email FROM user_credentials ORDER BY created_at";
$result = mysqli_query($conn, $usersSql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>uni_id</th><th>Name</th><th>Email</th></tr>";
while ($row = mysqli_fetch_assoc($result)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['uni_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Step 2: Show all accounts and which user they belong to
echo "<h3>Step 2: All Accounts (accounts table) - THE BRIDGE</h3>";
echo "<p><strong>This is the key:</strong> The accounts table has BOTH 'id' (used by trades) AND 'uni_id' (links to user)</p>";
$accountsSql = "SELECT a.id, a.uni_id, a.name, a.email, uc.name as user_name 
                 FROM accounts a 
                 LEFT JOIN user_credentials uc ON a.uni_id = uc.uni_id 
                 ORDER BY a.id";
$result = mysqli_query($conn, $accountsSql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>account.id</th><th>account.uni_id</th><th>Account Name</th><th>Account Email</th><th>Belongs to User</th><th># of Trades</th></tr>";
while ($row = mysqli_fetch_assoc($result)) {
    // Count trades for this account
    $tradeCountSql = "SELECT COUNT(*) as count FROM trades WHERE account_id = " . (int)$row['id'];
    $tradeResult = mysqli_query($conn, $tradeCountSql);
    $tradeCount = mysqli_fetch_assoc($tradeResult)['count'];
    
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($row['id']) . "</strong></td>";
    echo "<td>" . htmlspecialchars($row['uni_id'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "<td>" . htmlspecialchars($row['user_name'] ?? 'No user') . "</td>";
    echo "<td>" . $tradeCount . "</td>";
    echo "</tr>";
}
echo "</table>";

// Step 3: Show how trades connect
echo "<h3>Step 3: How Trades Connect</h3>";
echo "<p><strong>Trades table only has 'account_id' (integer). It doesn't have uni_id directly.</strong></p>";
$tradesSql = "SELECT t.account_id, COUNT(*) as trade_count, 
              GROUP_CONCAT(DISTINCT a.uni_id) as uni_ids,
              GROUP_CONCAT(DISTINCT uc.name) as user_names
              FROM trades t
              LEFT JOIN accounts a ON t.account_id = a.id
              LEFT JOIN user_credentials uc ON a.uni_id = uc.uni_id
              GROUP BY t.account_id
              ORDER BY t.account_id";
$result = mysqli_query($conn, $tradesSql);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>account_id (in trades)</th><th># of Trades</th><th>Links to uni_id</th><th>Belongs to User</th></tr>";
while ($row = mysqli_fetch_assoc($result)) {
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($row['account_id']) . "</strong></td>";
    echo "<td>" . $row['trade_count'] . "</td>";
    echo "<td>" . htmlspecialchars($row['uni_ids'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['user_names'] ?? 'No user') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Step 4: Specific check for "non" user
echo "<h3>Step 4: Check 'non' User's Account</h3>";
$nonUniId = '43eab088-8c4f-47c9-93d6-86a0203b0c5d';
$nonSql = "SELECT a.id, a.uni_id, a.name, a.email 
           FROM accounts a 
           WHERE a.uni_id = '$nonUniId'";
$result = mysqli_query($conn, $nonSql);

if (mysqli_num_rows($result) > 0) {
    echo "<p style='color: green;'><strong>✓ Account found for 'non' user:</strong></p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>account.id</th><th>account.uni_id</th><th>Name</th><th>Email</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($row['id']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['uni_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    $accountRow = mysqli_fetch_assoc(mysqli_query($conn, $nonSql));
    $nonAccountId = $accountRow['id'];
    
    echo "<h4>To move trades from account_id=1 to 'non' account (account_id=$nonAccountId):</h4>";
    echo "<pre style='background: #f0f0f0; padding: 10px;'>";
    echo "UPDATE trades SET account_id = $nonAccountId WHERE account_id = 1;\n";
    echo "</pre>";
} else {
    echo "<p style='color: red;'><strong>✗ No account found for 'non' user!</strong></p>";
    echo "<p>You need to create an account first. The account must have:</p>";
    echo "<ul>";
    echo "<li><strong>uni_id</strong> = '43eab088-8c4f-47c9-93d6-86a0203b0c5d' (to link to 'non' user)</li>";
    echo "<li><strong>id</strong> = will be auto-generated (this is what trades will use)</li>";
    echo "</ul>";
    echo "<p>After creating the account, you can update trades to use the new account_id.</p>";
}

echo "<hr>";
echo "<h3>Summary: The Connection Chain</h3>";
echo "<ol>";
echo "<li><strong>user_credentials.uni_id</strong> (UUID) - What you log in with</li>";
echo "<li>↓ matches ↓</li>";
echo "<li><strong>accounts.uni_id</strong> (UUID) - Links account to user</li>";
echo "<li>↓ same row has ↓</li>";
echo "<li><strong>accounts.id</strong> (integer) - The account ID</li>";
echo "<li>↓ matches ↓</li>";
echo "<li><strong>trades.account_id</strong> (integer) - Links trade to account</li>";
echo "</ol>";

echo "<p><strong>Key Point:</strong> The accounts table is the bridge. It stores BOTH the uni_id (to link to user) AND the id (to link to trades).</p>";

mysqli_close($conn);
?>


<?php
/**
 * OpenAI Moderation API helper.
 * Returns true if content is flagged (should be rejected), false otherwise.
 * Combines: (1) local blocklist for obvious toxic/cuss phrases, (2) OpenAI Moderation API.
 * API key: getenv('OPENAI_API_KEY') or optional config_openai.php (not committed).
 * If key is missing, only blocklist is used.
 */

/**
 * Blocklist of toxic phrases and words (lowercase). Matched as whole words or phrases.
 * Auto-reject posts containing any of these.
 */
function openai_toxic_blocklist() {
    return [
        'shut up', 'stfu', 'stfu.', 'shut the fuck up', 'stfu!',
        'fuck', 'fucking', 'fucked', 'fucker', 'fuck off', 'fuck you', 'fuck off.',
        'shit', 'bullshit', 'shitty', 'dumbass', 'dumb ass',
        'asshole', 'ass hole', 'dick', 'dickhead', 'dick head',
        'bitch', 'bastard', 'wtf', 'wth', 'damn you', 'go to hell',
        'kill yourself', 'kys', 'kys.', 'hope you die', 'go die',
        'idiot', 'you\'re stupid', 'you are stupid',
        'hate you', 'i hate you', 'shut your mouth', 'shut your face',
        'piece of shit', 'pos', 'piece of crap', 'suck my', 'suck it',
        'screw you', 'piss off', 'piss off.', 'get lost', 'get lost.',
        'f off', 'f u ', 'f.u.', 'eff you', 'motherfucker', 'mother fucker',
        'retard', 'retarded', 'r-tard', 'you\'re trash',
        'loser', 'you loser', 'pathetic', 'you\'re pathetic',
    ];
}

function openai_content_flagged($text) {
    $prefix = '[openai_moderate]';
    $text = trim((string) $text);
    if ($text === '') {
        error_log("$prefix Empty text → ALLOW");
        return false;
    }

    error_log("$prefix Checking content (length=" . strlen($text) . "): " . substr($text, 0, 100) . (strlen($text) > 100 ? '...' : ''));

    $lower = mb_strtolower($text, 'UTF-8');
    foreach (openai_toxic_blocklist() as $term) {
        $term = trim($term);
        if ($term === '') continue;
        $pattern = '/\b' . preg_quote($term, '/') . '\b/ui';
        if (preg_match($pattern, $lower)) {
            error_log("$prefix BLOCKLIST match term='" . $term . "' → REJECT");
            return true;
        }
    }
    error_log("$prefix Blocklist: no match");

    $apiKey = getenv('OPENAI_API_KEY');
    if ($apiKey === false || $apiKey === '') {
        $configPath = dirname(__DIR__) . '/config_openai.php';
        if (file_exists($configPath)) {
            $config = include $configPath;
            $apiKey = isset($config['api_key']) ? $config['api_key'] : '';
        }
    }
    if ($apiKey === '' || $apiKey === false) {
        error_log("$prefix No OPENAI_API_KEY (env + config_openai.php checked) → skip API, ALLOW");
        return false;
    }

    error_log("$prefix Calling OpenAI Moderation API...");
    $ch = curl_init('https://api.openai.com/v1/moderations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['input' => $text]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("$prefix API cURL error: " . $curlError . " → ALLOW (fail open)");
        return false;
    }
    if ($code !== 200) {
        error_log("$prefix API HTTP $code response: " . substr($response, 0, 200) . " → ALLOW (fail open)");
        return false;
    }

    $data = json_decode($response, true);
    if (!isset($data['results'][0]['flagged'])) {
        error_log("$prefix API response missing results[0].flagged: " . substr($response, 0, 300) . " → ALLOW");
        return false;
    }
    $flagged = (bool) $data['results'][0]['flagged'];
    $categories = isset($data['results'][0]['categories']) ? json_encode($data['results'][0]['categories']) : 'n/a';
    error_log("$prefix API result: flagged=" . ($flagged ? '1' : '0') . " categories=" . $categories . " → " . ($flagged ? 'REJECT' : 'ALLOW'));
    return $flagged;
}

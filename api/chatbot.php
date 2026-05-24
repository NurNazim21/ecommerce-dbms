<?php
// ══ api/chatbot.php — NVIDIA API proxy (OpenAI Compatible) ══════════════════
// Place this file at: /api/chatbot.php
// Requires: PHP 7.4+, allow_url_fopen or cURL enabled
//
// ⚠️ Set your NVIDIA API key below.
// ══════════════════════════════════════════════════════════════════════════════

// ── Config ────────────────────────────────────────────────────────────────────
define('NVIDIA_API_KEY', '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'); // ← replace this
define('NVIDIA_MODEL',   'meta/llama-3.1-8b-instruct'); // Or 'nvidia/llama-3.1-nemotron-70b-instruct'
define('MAX_TOKENS',     600);

// ── CORS / headers ────────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only allow POST from same origin
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// ── Parse request body ────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['messages']) || !is_array($body['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit();
}

// ── Sanitise messages ─────────────────────────────────────────────────────────
$allowed_roles = ['user', 'assistant'];
$messages = [];

// NVIDIA/OpenAI format uses 'assistant' instead of Anthropic's 'assistant' (both are same here)
// But we need to prepend the system message
$system_content = <<<SYSTEM
You are the friendly AI customer service assistant for E-Shop BD, a Bangladeshi e-commerce platform.
Your role:
- Help customers with order tracking, returns, payments, and delivery questions
- Answer questions about products, categories, and promotions
- Guide users through the website (cart, wishlist, checkout, profile)
- Be warm, concise, and helpful — replies should be under 120 words unless more detail is truly needed
- Always respond in the same language the customer uses (Bengali or English)
Store policies you know:
- Delivery: 24-hour delivery within Dhaka Metro; 2–4 days elsewhere in Bangladesh
- Returns: 7-day easy return policy on most items
- Payment: Cash on Delivery, bKash, Nagad, Rocket, and major credit/debit cards
- Customer service email: support@eshopbd.com
If you don't know a specific order status or personal account detail, apologize and ask the customer to visit their "My Orders" page or email support@eshopbd.com.
Never make up order numbers, prices, or product availability. Keep responses friendly and professional.
SYSTEM;

$messages[] = [
    'role'    => 'system',
    'content' => $system_content
];

foreach ($body['messages'] as $msg) {
    if (!isset($msg['role'], $msg['content'])) continue;
    if (!in_array($msg['role'], $allowed_roles)) continue;
    
    $messages[] = [
        'role'    => $msg['role'],
        'content' => mb_substr(strip_tags((string)$msg['content']), 0, 2000)
    ];
}

if (count($messages) <= 1) { // Only system message exists
    http_response_code(400);
    echo json_encode(['error' => 'No valid user messages']);
    exit();
}

// ── Call NVIDIA API (OpenAI Compatible) ──────────────────────────────────────
$payload = json_encode([
    'model'      => NVIDIA_MODEL,
    'max_tokens' => MAX_TOKENS,
    'messages'   => $messages,
    'temperature' => 0.5,
    'top_p'      => 1,
    'stream'     => false
]);

$ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . NVIDIA_API_KEY
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

// ── Handle errors ─────────────────────────────────────────────────────────────
if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach AI service']);
    exit();
}

$data = json_decode($response, true);

if ($http_code !== 200 || !isset($data['choices'][0]['message']['content'])) {
    http_response_code(502);
    $error_msg = $data['error']['message'] ?? 'Unknown error';
    echo json_encode(['error' => 'AI service error', 'detail' => $error_msg]);
    exit();
}

// ── Return reply ──────────────────────────────────────────────────────────────
echo json_encode(['reply' => $data['choices'][0]['message']['content']]);
?>

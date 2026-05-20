<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['reply' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../api/db.php';
header('Content-Type: application/json');

$pdo = db();
$action = trim((string)($_POST['action'] ?? 'chat'));
function getGeminiApiKey() {
    $apiKey = trim((string)getenv('GEMINI_API_KEY'));
    if ($apiKey === '' && defined('GEMINI_API_KEY')) {
        $apiKey = trim((string)GEMINI_API_KEY);
    }
    return $apiKey;
}
function isGeminiPlaceholderKey($apiKey) {
    return stripos((string)$apiKey, 'YOUR_REAL_') !== false;
}
function fullSystemFlowText() {
    return "System Flow Overview (E-CFunds):\n\n" .
        "1. Setup & Access\n" .
        "- SAS Admin manages organizations/clubs, accredited records, and user access.\n" .
        "- Treasurer accounts are linked to their assigned organization/club.\n\n" .
        "2. Student Encoding\n" .
        "- Treasurer encodes students and required membership dues.\n" .
        "- Student status starts as unpaid until collection is recorded.\n\n" .
        "3. Collection Phase\n" .
        "- Treasurer collects payments and marks students as Paid.\n" .
        "- Paid entries update student records and create collection transactions.\n\n" .
        "4. Monitoring Phase\n" .
        "- Dashboard reflects paid/unpaid counts, total collections, and recent transactions.\n" .
        "- Admin monitors organization/club financial activity in real time.\n\n" .
        "5. Remittance Request\n" .
        "- Treasurer submits remit requests to SAS for collected funds.\n" .
        "- Requests are tracked as Pending/Approved/Rejected.\n\n" .
        "6. SAS Verification & Approval\n" .
        "- SAS Admin verifies requests and approves valid remittances.\n" .
        "- Approval records are written to remit and financial transaction logs.\n\n" .
        "7. Fund Release\n" .
        "- SAS Admin releases funds for approved activities/events.\n" .
        "- Fund releases are logged and reflected in transaction history.\n\n" .
        "8. Expense Recording\n" .
        "- Treasurer records expenses using released funds.\n" .
        "- Expense entries update financial records and transaction totals.\n\n" .
        "9. Financial Oversight & Reporting\n" .
        "- Admin reviews collections, expenses, balances, and pending remits.\n" .
        "- The system keeps an auditable trail for accountability and reporting.";
}
function chatPeso($amount) {
    return '₱' . number_format((float)$amount, 2);
}
function fetchEntityDirectory($pdo, $category) {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.short_name, e.category,
                COUNT(s.id) AS members,
                COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN 1 ELSE 0 END),0) AS paid_members,
                COALESCE(SUM(CASE WHEN s.payment_status='Unpaid' THEN 1 ELSE 0 END),0) AS unpaid_members,
                COALESCE(SUM(CASE WHEN s.payment_status='Paid' THEN s.amount_due ELSE 0 END),0) AS collections
         FROM entities e
         LEFT JOIN students s ON s.entity_id = e.id
         WHERE e.category = :category AND e.is_active = 1
         GROUP BY e.id, e.name, e.short_name, e.category
         ORDER BY e.name"
    );
    $stmt->execute([':category' => $category]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function fetchMembersDirectory($pdo) {
    return $pdo->query(
        "SELECT s.student_id, s.full_name, s.course, s.year_level, s.payment_status, s.amount_due,
                COALESCE(e.name, 'Unassigned') AS entity_name,
                COALESCE(e.short_name, 'N/A') AS entity_short_name,
                COALESCE(e.category, 'unassigned') AS entity_category
         FROM students s
         LEFT JOIN entities e ON e.id = s.entity_id
         ORDER BY entity_category ASC, entity_name ASC, s.full_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}
function formatEntityDirectoryText($title, $rows) {
    if (!is_array($rows) || count($rows) === 0) {
        return $title . ': none found.';
    }
    $lines = [$title . ' (' . count($rows) . '):'];
    foreach ($rows as $i => $row) {
        $name = trim((string)($row['name'] ?? 'Unknown'));
        $short = trim((string)($row['short_name'] ?? $name));
        $members = (int)($row['members'] ?? 0);
        $paid = (int)($row['paid_members'] ?? 0);
        $unpaid = (int)($row['unpaid_members'] ?? 0);
        $collections = (float)($row['collections'] ?? 0);
        $lines[] = ($i + 1) . '. ' . $name . ' [' . $short . ']'
            . ' | Members: ' . $members
            . ' | Paid: ' . $paid
            . ' | Unpaid: ' . $unpaid
            . ' | Collected: ' . chatPeso($collections);
    }
    return implode("\n", $lines);
}
function formatMembersDirectoryText($rows) {
    if (!is_array($rows) || count($rows) === 0) {
        return 'All Members: none found.';
    }
    $lines = ['All Members (' . count($rows) . '):'];
    foreach ($rows as $i => $row) {
        $fullName = trim((string)($row['full_name'] ?? 'Unknown Member'));
        $studentId = trim((string)($row['student_id'] ?? 'N/A'));
        $course = trim((string)($row['course'] ?? 'N/A'));
        $year = trim((string)($row['year_level'] ?? 'N/A'));
        $status = trim((string)($row['payment_status'] ?? 'Unknown'));
        $entity = trim((string)($row['entity_name'] ?? 'Unassigned'));
        $category = ucfirst(trim((string)($row['entity_category'] ?? 'unassigned')));
        $amount = (float)($row['amount_due'] ?? 0);
        $lines[] = ($i + 1) . '. ' . $fullName . ' [' . $studentId . ']'
            . ' | ' . $course . ' - ' . $year
            . ' | ' . $category . ': ' . $entity
            . ' | Status: ' . $status
            . ' | Due: ' . chatPeso($amount);
    }
    return implode("\n", $lines);
}
function getDirectDataReply($pdo, $userMessage) {
    $q = strtolower(trim((string)$userMessage));
    $askAllData = strpos($q, 'all data') !== false
        || strpos($q, 'full data') !== false
        || strpos($q, 'system data') !== false
        || strpos($q, 'all records') !== false;
    $askOrgs = strpos($q, 'list organizations') !== false
        || strpos($q, 'list organization') !== false
        || strpos($q, 'list orgs') !== false
        || $q === 'organizations'
        || $q === 'orgs';
    $askClubs = strpos($q, 'list clubs') !== false
        || strpos($q, 'club list') !== false
        || $q === 'clubs';
    $askMembers = strpos($q, 'all members') !== false
        || strpos($q, 'list members') !== false
        || strpos($q, 'all students') !== false
        || strpos($q, 'list students') !== false
        || strpos($q, 'member list') !== false;

    if (!$askAllData && !$askOrgs && !$askClubs && !$askMembers) {
        return null;
    }

    $sections = [];
    if ($askAllData || $askOrgs) {
        $sections[] = formatEntityDirectoryText('Organizations', fetchEntityDirectory($pdo, 'organization'));
    }
    if ($askAllData || $askClubs) {
        $sections[] = formatEntityDirectoryText('Clubs', fetchEntityDirectory($pdo, 'club'));
    }
    if ($askAllData || $askMembers) {
        $sections[] = formatMembersDirectoryText(fetchMembersDirectory($pdo));
    }
    if ($askAllData) {
        array_unshift($sections, 'Complete System Directory (live data):');
    }
    return implode("\n\n", $sections);
}
function canReachGemini($apiKey) {
    $configuredModel = defined('GEMINI_MODEL') ? trim((string)GEMINI_MODEL) : '';
    $modelCandidates = array_values(array_unique(array_filter([
        $configuredModel,
        'gemini-3.1-flash-lite',
        'gemini-2.0-flash',
        'gemini-1.5-flash'
    ])));

    foreach ($modelCandidates as $model) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8
        ]);
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            continue;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return false;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['reply' => 'Method not allowed.']);
    exit;
}

if ($action === 'clear_history') {
    $_SESSION['gemini_chat_history'] = [];
    echo json_encode(['ok' => true, 'reply' => 'Chat history cleared.']);
    exit;
}

if ($action === 'status') {
    $apiKey = getGeminiApiKey();
    $hasKey = $apiKey !== '' && !isGeminiPlaceholderKey($apiKey);
    $online = $hasKey ? canReachGemini($apiKey) : false;
    echo json_encode(['ok' => true, 'online' => $online]);
    exit;
}

$msg = trim((string)($_POST['msg'] ?? ''));
if ($msg === '') {
    echo json_encode(['reply' => 'Please enter a message.']);
    exit;
}

function appSnapshotForGemini($pdo) {
    try {
        $orgs = (int)$pdo->query("SELECT COUNT(*) FROM entities WHERE category='organization' AND is_active=1")->fetchColumn();
        $clubs = (int)$pdo->query("SELECT COUNT(*) FROM entities WHERE category='club' AND is_active=1")->fetchColumn();
        $students = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
        $paidStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE payment_status='Paid'")->fetchColumn();
        $unpaidStudents = $students - $paidStudents;
        $collections = (float)$pdo->query("SELECT COALESCE(SUM(amount_due),0) FROM students WHERE payment_status='Paid'")->fetchColumn();
        $income = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='Income'")->fetchColumn();
        $expenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='Expense'")->fetchColumn();
        $remaining = ($income + $collections) - $expenses;
        $pendingRemits = (int)$pdo->query("SELECT COUNT(*) FROM remit_requests WHERE status='Pending'")->fetchColumn();
        $recent = $pdo->query(
            "SELECT t.tx_date, t.type, t.amount, COALESCE(e.short_name, e.name, 'Unknown') AS entity_name
             FROM transactions t
             LEFT JOIN entities e ON e.id = t.entity_id
             ORDER BY t.tx_date DESC, t.id DESC
             LIMIT 6"
        )->fetchAll();
        $organizations = fetchEntityDirectory($pdo, 'organization');
        $clubDirectory = fetchEntityDirectory($pdo, 'club');
        $membersSummary = $pdo->query(
            "SELECT COUNT(*) AS total_members,
                    COALESCE(SUM(CASE WHEN payment_status='Paid' THEN 1 ELSE 0 END),0) AS paid_members,
                    COALESCE(SUM(CASE WHEN payment_status='Unpaid' THEN 1 ELSE 0 END),0) AS unpaid_members
             FROM students"
        )->fetch(PDO::FETCH_ASSOC) ?: ['total_members' => 0, 'paid_members' => 0, 'unpaid_members' => 0];

        return [
            'totals' => [
                'organizations' => $orgs,
                'clubs' => $clubs,
                'students' => $students,
                'paid_students' => $paidStudents,
                'unpaid_students' => $unpaidStudents,
                'collections' => $collections,
                'income' => $income,
                'expenses' => $expenses,
                'remaining' => $remaining,
                'pending_remits' => $pendingRemits
            ],
            'directories' => [
                'organizations' => $organizations,
                'clubs' => $clubDirectory,
                'members_summary' => [
                    'total_members' => (int)($membersSummary['total_members'] ?? 0),
                    'paid_members' => (int)($membersSummary['paid_members'] ?? 0),
                    'unpaid_members' => (int)($membersSummary['unpaid_members'] ?? 0)
                ]
            ],
            'recent_transactions' => $recent
        ];
    } catch (Throwable $e) {
        return ['error' => 'snapshot_unavailable'];
    }
}

function geminiReply($userMessage, $pdo, $history = []) {
    $directDataReply = getDirectDataReply($pdo, $userMessage);
    if (is_string($directDataReply) && $directDataReply !== '') {
        return ['ok' => true, 'reply' => $directDataReply];
    }
    $flowRequest = strtolower(trim((string)$userMessage));
    if (
        $flowRequest === 'system flow' ||
        strpos($flowRequest, 'system flow') !== false ||
        strpos($flowRequest, 'full flow') !== false ||
        strpos($flowRequest, 'process flow') !== false ||
        strpos($flowRequest, 'workflow') !== false ||
        strpos($flowRequest, 'daloy ng system') !== false
    ) {
        return ['ok' => true, 'reply' => fullSystemFlowText()];
    }
    $apiKey = getGeminiApiKey();
    if ($apiKey === '' || isGeminiPlaceholderKey($apiKey)) {
        return [
            'ok' => false,
            'reply' => 'Gemini API key is not configured correctly. Replace the placeholder key in api/config.php with your real Gemini API key.'
        ];
    }

    $configuredModel = defined('GEMINI_MODEL') ? trim((string)GEMINI_MODEL) : '';
    $modelCandidates = array_values(array_unique(array_filter([
        $configuredModel,
        'gemini-3.1-flash-lite',
        'gemini-2.0-flash',
        'gemini-1.5-flash'
    ])));

    $userName = $_SESSION['user']['fullName'] ?? 'Admin';
    $snapshot = appSnapshotForGemini($pdo);
    $systemPrompt = "You are Gemini-based E-CFunds AI assistant for SAS administrators.\n" .
        "You must answer as an expert operations and finance copilot for this system.\n" .
        "Guidelines:\n" .
        "- Give practical, accurate, concise answers.\n" .
        "- When asked for system numbers, use the provided snapshot.\n" .
        "- If a calculation is needed, show the formula briefly.\n" .
        "- If data is missing, explicitly say what is missing and what page/action to check.\n" .
        "- Keep tone professional and direct.\n" .
        "- Do not invent database records not present in context.";

    $contents = [[
        'role' => 'user',
        'parts' => [[
            'text' => $systemPrompt . "\n\nCurrent admin: {$userName}\n\nLive context JSON:\n" . json_encode($snapshot)
        ]]
    ]];

    if (is_array($history)) {
        foreach ($history as $item) {
            $role = (($item['role'] ?? '') === 'assistant') ? 'model' : 'user';
            $text = trim((string)($item['text'] ?? ''));
            if ($text === '') continue;
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]]
            ];
        }
    }

    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $userMessage]]
    ];

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.5,
            'maxOutputTokens' => 900
        ]
    ];

    $raw = '';
    $httpCode = 0;
    $apiError = '';
    foreach ($modelCandidates as $candidateModel) {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($candidateModel) . ':generateContent';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 45
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            $curlErr = trim((string)curl_error($ch));
            curl_close($ch);

            if ($curlErrNo !== 0) {
                $apiError = 'Network error: ' . ($curlErr !== '' ? $curlErr : ('cURL error ' . $curlErrNo));
                if ($attempt < 2) {
                    usleep(300000);
                    continue;
                }
                continue 2;
            }

            if (is_string($raw) && $raw !== '' && $httpCode >= 200 && $httpCode < 300) {
                break 2;
            }

            $errText = '';
            if (is_string($raw) && $raw !== '') {
                $errPayload = json_decode($raw, true);
                $errText = trim((string)($errPayload['error']['message'] ?? ''));
            }
            $apiError = $errText !== '' ? $errText : ('Gemini API HTTP ' . $httpCode);

            $modelUnavailable = $httpCode === 404
                || stripos($apiError, 'not found') !== false
                || stripos($apiError, 'not supported') !== false
                || stripos($apiError, 'not available') !== false;

            if ($modelUnavailable) {
                continue 2;
            }

            if (($httpCode === 429 || $httpCode >= 500) && $attempt < 2) {
                usleep(400000);
                continue;
            }

            continue 2;
        }
    }

    if (!is_string($raw) || $raw === '' || $httpCode < 200 || $httpCode >= 300) {
        $safeDetails = trim((string)$apiError);
        if ($safeDetails === '') {
            $safeDetails = 'Check API key, internet connection, or model access.';
        }
        return [
            'ok' => false,
            'reply' => 'Gemini is currently unavailable. ' . $safeDetails
        ];
    }

    $data = json_decode($raw, true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts) || count($parts) === 0) {
        return [
            'ok' => false,
            'reply' => 'Gemini returned an empty response. Please try again.'
        ];
    }

    $text = '';
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    $text = trim($text);
    if ($text === '') {
        return [
            'ok' => false,
            'reply' => 'Gemini returned no text. Please try again.'
        ];
    }

    return ['ok' => true, 'reply' => $text];
}

try {
    $history = $_SESSION['gemini_chat_history'] ?? [];
    if (!is_array($history)) $history = [];
    $history = array_slice($history, -14);

    $result = geminiReply($msg, $pdo, $history);
    $reply = (string)($result['reply'] ?? 'Unable to generate response.');

    if (!empty($result['ok'])) {
        $history[] = ['role' => 'user', 'text' => $msg];
        $history[] = ['role' => 'assistant', 'text' => $reply];
        $_SESSION['gemini_chat_history'] = array_slice($history, -16);
    }

    echo json_encode([
        'reply' => $reply,
        'ai' => 'gemini',
        'ok' => !empty($result['ok'])
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['reply' => 'Gemini chat request failed. Please try again.']);
}
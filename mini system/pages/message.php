<?php
session_start();
if (!isset($_SESSION['user'])) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
require_once __DIR__ . '/../api/db.php';
header('Content-Type: application/json');

$pdo    = db();
$user   = $_SESSION['user'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();

// ── SEND ─────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    $msg      = trim($_POST['message'] ?? '');
    $entityId = (int)($user['entityId'] ?? 0);
    if (!$msg) { echo json_encode(['error'=>'Empty message']); exit; }

    if ($user['role'] === 'officer') {
        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,entity_id,message) VALUES (:s,:r,:e,:m)")
            ->execute([':s'=>$user['id'],':r'=>$adminId,':e'=>$entityId,':m'=>$msg]);
    } else {
        if (($user['role'] ?? '') !== 'admin') { echo json_encode(['error'=>'Unauthorized']); exit; }
        $toEntityId = (int)($_POST['entity_id'] ?? 0);
        if ($toEntityId <= 0) { echo json_encode(['error'=>'Select a treasurer conversation first']); exit; }
        $tId = $pdo->prepare("SELECT id FROM users WHERE entity_id=:e AND role='officer' LIMIT 1");
        $tId->execute([':e'=>$toEntityId]);
        $tId = $tId->fetchColumn();
        if (!$tId) { echo json_encode(['error'=>'Treasurer account not found']); exit; }
        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,entity_id,message) VALUES (:s,:r,:e,:m)")
            ->execute([':s'=>$user['id'],':r'=>$tId,':e'=>$toEntityId,':m'=>$msg]);
    }
    echo json_encode(['success'=>true]);
// ── SEND BROADCAST (admin) ───────────────────────────────────────────────────
} elseif ($action === 'send_all') {
    if (($user['role'] ?? '') !== 'admin') { echo json_encode(['error'=>'Unauthorized']); exit; }
    $msg = trim($_POST['message'] ?? '');
    if (!$msg) { echo json_encode(['error'=>'Empty message']); exit; }

    $targets = $pdo->query(
        "SELECT u.id AS treasurer_id, e.id AS entity_id
         FROM users u
         JOIN entities e ON e.id = u.entity_id
         WHERE u.role='officer' AND e.is_active=1"
    )->fetchAll();

    if (!$targets) {
        echo json_encode(['success'=>true, 'sent'=>0]);
        exit;
    }

    $insert = $pdo->prepare(
        "INSERT INTO messages (sender_id,receiver_id,entity_id,message)
         VALUES (:s,:r,:e,:m)"
    );

    $pdo->beginTransaction();
    try {
        foreach ($targets as $target) {
            $insert->execute([
                ':s' => (int)$user['id'],
                ':r' => (int)$target['treasurer_id'],
                ':e' => (int)$target['entity_id'],
                ':m' => $msg,
            ]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true, 'sent'=>count($targets)]);
    } catch (Throwable $th) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['error'=>'Failed to send broadcast']);
    }

// ── FETCH CONVERSATION ────────────────────────────────────────────────────────
} elseif ($action === 'fetch') {
    $entityId = (int)($_GET['entity_id'] ?? $user['entityId'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT m.*,
                u.full_name  AS sender_name,
                u.role       AS sender_role,
                e.name       AS entity_name,
                e.short_name AS entity_short
         FROM messages m
         JOIN users    u ON u.id = m.sender_id
         JOIN entities e ON e.id = m.entity_id
         WHERE m.entity_id = :e
         ORDER BY m.created_at ASC"
    );
    $eid = ($user['role'] === 'officer') ? (int)($user['entityId'] ?? 0) : $entityId;
    $stmt->execute([':e' => $eid]);

    // Mark as read for the current user
    $pdo->prepare("UPDATE messages SET is_read=1 WHERE entity_id=:e AND receiver_id=:u")
        ->execute([':e'=>$eid, ':u'=>$user['id']]);

    echo json_encode($stmt->fetchAll());

// ── CONVERSATIONS LIST (admin) ────────────────────────────────────────────────
} elseif ($action === 'conversations') {
    // All entities that have a treasurer — with last message + unread count
    $rows = $pdo->query(
        "SELECT
            e.id          AS entity_id,
            e.name        AS entity_name,
            e.short_name,
            e.category,
            u.id          AS treasurer_id,
            u.full_name   AS treasurer_name,
            u.email       AS treasurer_email,
            COALESCE(
                (SELECT message FROM messages
                 WHERE entity_id=e.id
                 ORDER BY created_at DESC LIMIT 1), ''
            ) AS last_message,
            COALESCE(
                (SELECT created_at FROM messages
                 WHERE entity_id=e.id
                 ORDER BY created_at DESC LIMIT 1), NULL
            ) AS last_message_at,
            COALESCE(
                (SELECT COUNT(*) FROM messages
                 WHERE entity_id=e.id
                   AND receiver_id=(SELECT id FROM users WHERE role='admin' LIMIT 1)
                   AND is_read=0), 0
            ) AS unread_count
         FROM entities e
         JOIN users u ON u.entity_id=e.id AND u.role='officer'
         WHERE e.is_active=1
         ORDER BY last_message_at DESC, e.name ASC"
    )->fetchAll();
    echo json_encode($rows);

// ── DELETE CONVERSATION (admin only) ────────────────────────────────────────
} elseif ($action === 'delete_conversation') {
    if ($user['role'] !== 'admin') { echo json_encode(['error'=>'Unauthorized']); exit; }
    $entityId = (int)($_POST['entity_id'] ?? 0);
    if ($entityId) {
        $pdo->prepare("DELETE FROM messages WHERE entity_id=:e")->execute([':e'=>$entityId]);
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['error'=>'Invalid entity']);
    }

// ── DELETE SINGLE MESSAGE ────────────────────────────────────────────────────
} elseif ($action === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId <= 0) { echo json_encode(['error'=>'Invalid message']); exit; }

    if (($user['role'] ?? '') === 'admin') {
        $del = $pdo->prepare("DELETE FROM messages WHERE id=:id AND sender_id=:uid");
        $del->execute([':id'=>$messageId, ':uid'=>(int)$user['id']]);
    } elseif (($user['role'] ?? '') === 'officer') {
        $del = $pdo->prepare("DELETE FROM messages WHERE id=:id AND sender_id=:uid AND entity_id=:eid");
        $del->execute([
            ':id'  => $messageId,
            ':uid' => (int)$user['id'],
            ':eid' => (int)($user['entityId'] ?? 0)
        ]);
    } else {
        echo json_encode(['error'=>'Unauthorized']);
        exit;
    }

    if ($del->rowCount() === 0) {
        echo json_encode(['error'=>'Message not found or cannot be deleted']);
    } else {
        echo json_encode(['success'=>true]);
    }

// ── UNREAD COUNT ──────────────────────────────────────────────────────────────
} elseif ($action === 'unread') {
    if ($user['role'] === 'admin') {
        $count = (int)$pdo->query(
            "SELECT COUNT(*) FROM messages
             WHERE receiver_id=(SELECT id FROM users WHERE role='admin' LIMIT 1)
               AND is_read=0"
        )->fetchColumn();
        echo json_encode(['unread' => $count]);
    } else {
        $count = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id=:u AND is_read=0");
        $count->execute([':u'=>$user['id']]);
        echo json_encode(['unread' => (int)$count->fetchColumn()]);
    }
} else {
    echo json_encode(['error' => 'Invalid action']);
}

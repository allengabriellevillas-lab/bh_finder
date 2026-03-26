<?php
require_once __DIR__ . '/../includes/config.php';

requireTenant();

$db = getDB();
$uid = intval($_SESSION['user_id']);

$threadId = intval($_GET['thread_id'] ?? 0);
$bhId = intval($_GET['bh_id'] ?? 0);

$thread = null;
$error = null;

function loadThread(PDO $db, int $threadId, int $uid): ?array {
    $stmt = $db->prepare("SELECT t.*, bh.name AS bh_name, bh.city AS bh_city, u.full_name AS owner_name
        FROM chat_threads t
        JOIN boarding_houses bh ON bh.id = t.boarding_house_id
        JOIN users u ON u.id = t.owner_id
        WHERE t.id = ? AND t.tenant_id = ?");
    $stmt->execute([$threadId, $uid]);
    return $stmt->fetch() ?: null;
}

try {
    if ($threadId > 0) {
        $thread = loadThread($db, $threadId, $uid);
    } elseif ($bhId > 0) {
        // Create or open a thread for this listing.
        $bh = $db->prepare("SELECT id, owner_id, name FROM boarding_houses WHERE id = ? AND status != 'inactive' LIMIT 1");
        $bh->execute([$bhId]);
        $bhRow = $bh->fetch();
        if (!$bhRow) {
            setFlash('error', 'Listing not found.');
            header('Location: ' . SITE_URL . '/index.php#listings');
            exit;
        }

        $ownerId = intval($bhRow['owner_id'] ?? 0);
        if ($ownerId === $uid) {
            setFlash('error', 'You cannot chat with yourself.');
            header('Location: ' . SITE_URL . '/pages/detail.php?id=' . $bhId);
            exit;
        }

        $ins = $db->prepare("INSERT INTO chat_threads (boarding_house_id, tenant_id, owner_id)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $ins->execute([$bhId, $uid, $ownerId]);
        $threadId = intval($db->lastInsertId() ?: 0);
        $thread = $threadId > 0 ? loadThread($db, $threadId, $uid) : null;
    }
} catch (Throwable $e) {
    $thread = null;
    $error = 'Chat is not available yet. Please run install.php or import the updated schema.sql.';
}

if (!$thread) {
    if (!$error) $error = 'Chat not found.';
    $pageTitle = 'Chat';
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="container" style="padding:36px 0;max-width:820px">
      <div class="card">
        <div class="card-body">
          <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i><?= sanitize($error) ?></div>
          <div class="mt-3"><a class="btn btn-ghost" href="<?= SITE_URL ?>/pages/chats.php"><i class="fas fa-arrow-left"></i> Back</a></div>
        </div>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = 'Chat · ' . sanitize($thread['bh_name'] ?? 'Listing');

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg !== '') {
        try {
            $db->prepare("INSERT INTO chat_messages (thread_id, sender_id, message) VALUES (?,?,?)")
               ->execute([intval($thread['id']), $uid, $msg]);
            $db->prepare("UPDATE chat_threads SET last_message_at = NOW() WHERE id = ?")
               ->execute([intval($thread['id'])]);
        } catch (Throwable $e) {
            setFlash('error', 'Failed to send message.');
        }
    }
    header('Location: ' . SITE_URL . '/pages/chat.php?thread_id=' . intval($thread['id']));
    exit;
}

// Mark incoming messages as read
try {
    $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE thread_id = ? AND sender_id <> ?")
       ->execute([intval($thread['id']), $uid]);
} catch (Throwable $e) {
    // ignore
}

$messages = [];
try {
    $stmt = $db->prepare("SELECT m.*, u.full_name
      FROM chat_messages m
      JOIN users u ON u.id = m.sender_id
      WHERE m.thread_id = ?
      ORDER BY m.created_at ASC
      LIMIT 300");
    $stmt->execute([intval($thread['id'])]);
    $messages = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $messages = [];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="padding:24px 0 60px;max-width:980px">
  <div class="chat-header">
    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/chats.php"><i class="fas fa-arrow-left"></i> Back</a>
    <div style="min-width:0">
      <div class="chat-title"><?= sanitize($thread['bh_name'] ?? 'Listing') ?></div>
      <div class="text-muted text-sm">Chatting with <?= sanitize($thread['owner_name'] ?? 'Owner') ?></div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/detail.php?id=<?= intval($thread['boarding_house_id'] ?? 0) ?>"><i class="fas fa-eye"></i> View Listing</a>
  </div>

  <div class="chat-box" id="chatBox" data-thread-id="<?= intval($thread['id']) ?>" data-user-id="<?= intval($uid) ?>" data-messages-url="<?= SITE_URL ?>/api/chat_messages.php" data-send-url="<?= SITE_URL ?>/api/chat_send.php">
    <div class="chat-messages">
      <?php if (empty($messages)): ?>
        <div class="empty-state" style="padding:40px 0">
          <i class="fas fa-comment-dots"></i>
          <h3>No messages yet</h3>
          <p>Say hi to start the conversation.</p>
        </div>
      <?php else: ?>
        <?php foreach ($messages as $m):
          $mine = intval($m['sender_id'] ?? 0) === $uid;
          $time = $m['created_at'] ? date('M d, H:i', strtotime((string)$m['created_at'])) : '';
        ?>
          <div class="chat-msg <?= $mine ? 'mine' : 'theirs' ?>" data-mid="<?= intval($m['id'] ?? 0) ?>">
            <div class="chat-bubble">
              <div class="chat-text"><?= nl2br(sanitize($m['message'] ?? '')) ?></div>
              <div class="chat-time"><?= sanitize($time) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <form class="chat-compose" method="POST" action="">
      <input type="hidden" name="send_message" value="1">
      <input class="form-control" type="text" name="message" placeholder="Type a message..." autocomplete="off" required>
      <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i></button>
    </form>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
  const chatBox = document.getElementById('chatBox');
  const messagesWrap = document.querySelector('.chat-messages');
  const form = document.querySelector('form.chat-compose');
  const input = form ? form.querySelector('input[name="message"]') : null;
  const sendBtn = form ? form.querySelector('button[type="submit"]') : null;

  if (!chatBox || !messagesWrap || !form || !input) return;

  const threadId = parseInt(chatBox.dataset.threadId || '0', 10);
  const userId = parseInt(chatBox.dataset.userId || '0', 10);
  const messagesUrl = chatBox.dataset.messagesUrl;
  const sendUrl = chatBox.dataset.sendUrl;

  let lastId = 0;
  document.querySelectorAll('.chat-msg[data-mid]').forEach(el => {
    const mid = parseInt(el.dataset.mid || '0', 10);
    if (mid > lastId) lastId = mid;
  });

  function nearBottom() {
    return (messagesWrap.scrollHeight - messagesWrap.scrollTop - messagesWrap.clientHeight) < 90;
  }

  function scrollToBottom() {
    messagesWrap.scrollTop = messagesWrap.scrollHeight;
  }

  function esc(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function formatTime(iso) {
    try {
      const d = new Date(String(iso).replace(' ', 'T'));
      return d.toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return '';
    }
  }

  function appendMessage(msg) {
    if (!msg || !msg.id) return;
    const mid = parseInt(msg.id, 10);
    if (!mid || mid <= lastId) return;

    const mine = parseInt(msg.sender_id || '0', 10) === userId;
    const row = document.createElement('div');
    row.className = 'chat-msg ' + (mine ? 'mine' : 'theirs');
    row.dataset.mid = String(mid);
    row.innerHTML = `
      <div class="chat-bubble">
        <div class="chat-text">${esc(msg.message || '')}</div>
        <div class="chat-time">${esc(formatTime(msg.created_at || ''))}</div>
      </div>
    `;
    messagesWrap.appendChild(row);
    lastId = Math.max(lastId, mid);
  }

  async function poll() {
    if (!threadId || !messagesUrl) return;
    const shouldScroll = nearBottom();
    try {
      const res = await fetch(`${messagesUrl}?thread_id=${encodeURIComponent(threadId)}&since_id=${encodeURIComponent(lastId)}`, { credentials: 'same-origin' });
      if (!res.ok) return;
      const data = await res.json();
      const msgs = Array.isArray(data.messages) ? data.messages : [];
      msgs.forEach(appendMessage);
      if (shouldScroll && msgs.length) scrollToBottom();
    } catch (e) {
      // ignore
    }
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    const text = (input.value || '').trim();
    if (!text) return;
    if (sendBtn) sendBtn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('thread_id', String(threadId));
      fd.append('message', text);
      const res = await fetch(sendUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(() => null);
      if (res.ok && data && data.message) {
        appendMessage(data.message);
        input.value = '';
        scrollToBottom();
      }
    } finally {
      if (sendBtn) sendBtn.disabled = false;
      input.focus();
    }
  });

  scrollToBottom();
  setInterval(poll, 2500);
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>


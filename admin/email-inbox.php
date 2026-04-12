<?php
require_once __DIR__ . '/../includes/auth.php';
@require_once __DIR__ . '/../includes/ontology.php';
requireAdmin();
$title = 'Email Inbox'; $page = 'email-inbox';
global $dbLocal;

// Create tables
try {
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS email_inbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT UNIQUE, from_email TEXT, from_name TEXT,
        to_email TEXT, subject TEXT, body_text TEXT, body_html TEXT, date_received DATETIME,
        is_read INTEGER DEFAULT 0, customer_id INTEGER, employee_id INTEGER, job_id INTEGER,
        category TEXT DEFAULT 'unassigned', starred INTEGER DEFAULT 0, archived INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS email_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, imap_host TEXT NOT NULL,
        imap_port INTEGER DEFAULT 993, imap_user TEXT NOT NULL, imap_pass TEXT NOT NULL,
        imap_ssl INTEGER DEFAULT 1, active INTEGER DEFAULT 1, last_sync DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_account') {
        $email = trim($_POST['email'] ?? '');
        $host = trim($_POST['imap_host'] ?? '');
        $port = (int)($_POST['imap_port'] ?? 993);
        $user = trim($_POST['imap_user'] ?? '');
        $pass = trim($_POST['imap_pass'] ?? '');
        if ($email && $host && $user && $pass) {
            try {
                $dbLocal->prepare("INSERT OR REPLACE INTO email_accounts (email, imap_host, imap_port, imap_user, imap_pass) VALUES (?,?,?,?,?)")
                    ->execute([$email, $host, $port, $user, $pass]);
                $success = "Account $email hinzugefuegt";
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
    if ($act === 'assign') {
        $emailId = (int)($_POST['email_id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $category = $_POST['category'] ?? 'assigned';
        $dbLocal->prepare("UPDATE email_inbox SET customer_id=?, category=? WHERE id=?")->execute([$customerId, $category, $emailId]);
    }
    if ($act === 'sync') {
        $synced = syncEmails();
        $success = "$synced neue Emails synchronisiert";
    }
}

function syncEmails(): int {
    global $dbLocal;
    $accounts = $dbLocal->query("SELECT * FROM email_accounts WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($accounts as $acc) {
        try {
            $mailbox = @imap_open(
                '{' . $acc['imap_host'] . ':' . $acc['imap_port'] . '/imap/ssl/novalidate-cert}INBOX',
                $acc['imap_user'], $acc['imap_pass']
            );
            if (!$mailbox) continue;

            $since = $acc['last_sync'] ? date('d-M-Y', strtotime($acc['last_sync'] . ' -1 day')) : date('d-M-Y', strtotime('-7 days'));
            $emails = @imap_search($mailbox, 'SINCE "' . $since . '"');
            if (!$emails) { imap_close($mailbox); continue; }

            foreach (array_slice($emails, -50) as $num) {
                $header = @imap_headerinfo($mailbox, $num);
                if (!$header) continue;
                $msgId = $header->message_id ?? '';
                // Skip if already exists
                $exists = $dbLocal->prepare("SELECT id FROM email_inbox WHERE message_id=?");
                $exists->execute([$msgId]);
                if ($exists->fetch()) continue;

                $from = $header->from[0] ?? null;
                $fromEmail = $from ? ($from->mailbox . '@' . $from->host) : '';
                $fromName = isset($from->personal) ? imap_utf8($from->personal) : '';
                $subject = isset($header->subject) ? imap_utf8($header->subject) : '';
                $date = isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : date('Y-m-d H:i:s');

                // Get body
                $body = '';
                $struct = @imap_fetchstructure($mailbox, $num);
                if ($struct && $struct->type === 0) {
                    $body = @imap_fetchbody($mailbox, $num, '1');
                    if ($struct->encoding === 3) $body = base64_decode($body);
                    elseif ($struct->encoding === 4) $body = quoted_printable_decode($body);
                } else {
                    $body = @imap_fetchbody($mailbox, $num, '1.1') ?: @imap_fetchbody($mailbox, $num, '1');
                    $body = quoted_printable_decode($body);
                }

                // Auto-assign to customer by email
                $customerId = null;
                $category = 'unassigned';
                if ($fromEmail) {
                    $cust = one("SELECT customer_id FROM customer WHERE email=? AND status=1", [$fromEmail]);
                    if ($cust) { $customerId = $cust['customer_id']; $category = 'customer'; }
                    else {
                        $emp = one("SELECT emp_id FROM employee WHERE email=? AND status=1", [$fromEmail]);
                        if ($emp) { $category = 'partner'; }
                    }
                }

                $dbLocal->prepare("INSERT INTO email_inbox (message_id, from_email, from_name, to_email, subject, body_text, date_received, customer_id, category) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$msgId, $fromEmail, $fromName, $acc['email'], $subject, substr($body, 0, 5000), $date, $customerId, $category]);
                $total++;

                // Gotham ontology ingest — every new email feeds the graph
                if (function_exists('ontology_upsert_object') && $fromEmail) {
                    try {
                        $emailObjId = ontology_upsert_object('email', $fromEmail, [
                            'last_subject' => mb_substr($subject, 0, 200),
                            'last_seen' => $date,
                        ], null, 0.85);
                        if ($fromName && $emailObjId) {
                            $personId = ontology_upsert_object('person', $fromName, [], null, 0.7);
                            if ($personId) {
                                ontology_upsert_link($personId, $emailObjId, 'has_email', 'email_inbox', 0.85);
                                ontology_add_event($personId, 'email_received',
                                    substr($date, 0, 10),
                                    'Email: ' . mb_substr($subject, 0, 200),
                                    ['from' => $fromEmail, 'msg_id' => $msgId],
                                    'email_inbox');
                            }
                        }
                        // Extract additional entities from the body via regex
                        if (function_exists('ontology_extract_entities') && !empty($body)) {
                            $ent = ontology_extract_entities($body);
                            foreach (array_slice($ent['emails'] ?? [], 0, 5) as $em) {
                                if (strcasecmp($em, $fromEmail) === 0) continue;
                                $eId = ontology_upsert_object('email', $em, [], null, 0.5);
                                if ($eId && $emailObjId) {
                                    ontology_upsert_link($emailObjId, $eId, 'mentioned_in_email', 'email_body', 0.5);
                                }
                            }
                            foreach (array_slice($ent['phones'] ?? [], 0, 3) as $ph) {
                                $pId = ontology_upsert_object('phone', $ph, [], null, 0.55);
                                if ($pId && $emailObjId) {
                                    ontology_upsert_link($emailObjId, $pId, 'mentioned_in_email', 'email_body', 0.55);
                                }
                            }
                        }
                    } catch (Throwable $e) { /* ingest is best-effort */ }
                }
            }
            imap_close($mailbox);
            $dbLocal->prepare("UPDATE email_accounts SET last_sync=CURRENT_TIMESTAMP WHERE id=?")->execute([$acc['id']]);
        } catch (Exception $e) { continue; }
    }
    return $total;
}

// Load data
$accounts = [];
try { $accounts = $dbLocal->query("SELECT * FROM email_accounts ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$filter = in_array($_GET['filter'] ?? '', ['all', 'unread', 'starred', 'sent'], true) ? $_GET['filter'] : 'all';
$filterSql = match($filter) {
    'unread' => "AND e.is_read=0",
    'customer' => "AND e.category='customer'",
    'partner' => "AND e.category='partner'",
    'unassigned' => "AND e.category='unassigned'",
    'starred' => "AND e.starred=1",
    default => "",
};
$emails = [];
try {
    $emails = $dbLocal->query("SELECT e.*, c.name as customer_name FROM email_inbox e LEFT JOIN customer c ON e.customer_id=c.customer_id WHERE e.archived=0 $filterSql ORDER BY e.date_received DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$counts = ['all'=>0,'unread'=>0,'customer'=>0,'partner'=>0,'unassigned'=>0,'starred'=>0];
try {
    $counts['all'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0")->fetchColumn();
    $counts['unread'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0 AND is_read=0")->fetchColumn();
    $counts['customer'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0 AND category='customer'")->fetchColumn();
    $counts['partner'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0 AND category='partner'")->fetchColumn();
    $counts['unassigned'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0 AND category='unassigned'")->fetchColumn();
    $counts['starred'] = $dbLocal->query("SELECT COUNT(*) FROM email_inbox WHERE archived=0 AND starred=1")->fetchColumn();
} catch (Exception $e) {}

$customers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($success)): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4"><?= e($success) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl mb-4"><?= e($error) ?></div><?php endif; ?>

<!-- Email Accounts Setup -->
<?php if (empty($accounts)): ?>
<div class="max-w-xl mx-auto bg-white rounded-xl border p-8 text-center mb-6">
  <h2 class="text-xl font-bold mb-2">Email-Konto verbinden</h2>
  <p class="text-gray-500 mb-4">IMAP-Zugang einrichten um Emails automatisch zu empfangen und Kunden zuzuordnen.</p>
  <form method="POST" class="text-left space-y-3">
    <?= csrfField() ?><input type="hidden" name="action" value="add_account"/>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
        <input type="email" name="email" required value="info@fleckfrei.de" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">IMAP Host</label>
        <input type="text" name="imap_host" required value="imap.gmail.com" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">IMAP User</label>
        <input type="text" name="imap_user" required value="info@fleckfrei.de" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">IMAP Passwort</label>
        <input type="password" name="imap_pass" required class="w-full px-3 py-2 border rounded-lg"/></div>
    </div>
    <button class="w-full px-4 py-2 bg-brand text-white rounded-lg font-medium">Verbinden</button>
  </form>
</div>
<?php else: ?>

<!-- Toolbar -->
<div class="flex items-center justify-between mb-4">
  <div class="flex items-center gap-2">
    <?php foreach (['all'=>'Alle','unread'=>'Ungelesen','customer'=>'Kunden','partner'=>'Partner','unassigned'=>'Nicht zugeordnet','starred'=>'Markiert'] as $fk => $fl): ?>
    <a href="?filter=<?= $fk ?>" class="px-3 py-1.5 rounded-lg text-sm <?= $filter===$fk ? 'bg-brand text-white' : 'border hover:bg-gray-50' ?>"><?= $fl ?> <span class="text-xs opacity-70"><?= $counts[$fk] ?></span></a>
    <?php endforeach; ?>
  </div>
  <form method="POST" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="sync"/>
    <button class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold">Sync</button></form>
</div>

<!-- Email List -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="divide-y">
    <?php foreach ($emails as $em): ?>
    <div class="px-5 py-3 flex items-start gap-3 hover:bg-gray-50 <?= !$em['is_read'] ? 'bg-blue-50/30' : '' ?>">
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
          <span class="font-medium text-sm <?= !$em['is_read'] ? 'font-bold' : '' ?>"><?= e($em['from_name'] ?: $em['from_email']) ?></span>
          <?php if ($em['customer_name']): ?><span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-[10px]"><?= e($em['customer_name']) ?></span><?php endif; ?>
          <span class="px-2 py-0.5 rounded text-[10px] <?= $em['category']==='customer'?'bg-green-100 text-green-700':($em['category']==='partner'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-500') ?>"><?= $em['category'] ?></span>
          <span class="text-xs text-gray-400 ml-auto"><?= date('d.m. H:i', strtotime($em['date_received'])) ?></span>
        </div>
        <div class="text-sm <?= !$em['is_read'] ? 'font-semibold' : '' ?> truncate"><?= e($em['subject']) ?></div>
        <div class="text-xs text-gray-400 truncate"><?= e(substr(strip_tags($em['body_text']), 0, 120)) ?></div>
      </div>
      <?php if ($em['category'] === 'unassigned'): ?>
      <form method="POST" class="flex items-center gap-1 shrink-0"><?= csrfField() ?>
        <input type="hidden" name="action" value="assign"/><input type="hidden" name="email_id" value="<?= $em['id'] ?>"/>
        <select name="customer_id" class="text-xs border rounded px-1 py-1">
          <option value="">Zuordnen...</option>
          <?php foreach ($customers as $c): ?><option value="<?= $c['customer_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="text-xs text-brand hover:underline">OK</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($emails)): ?>
    <div class="px-5 py-8 text-center text-gray-400">Keine Emails. Klicke "Sync" zum Abrufen.</div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

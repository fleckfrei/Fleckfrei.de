<?php
session_start();
require_once __DIR__ . '/includes/config.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = trim($_POST['password'] ?? '');
    $user = one("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
    if ($user) {
        $t = $user['type'];
        $idMap = ['admin'=>'admin_id','customer'=>'customer_id','employee'=>'emp_id'];
        $id = $idMap[$t] ?? null;
        if ($id) {
            $row = one("SELECT * FROM `$t` WHERE email = ? AND status = 1 LIMIT 1", [$email]);
            if ($row) {
                $authenticated = false;
                $storedPass = $row['password'] ?? '';

                // Check bcrypt hash first, then plaintext fallback for migration
                if (password_verify($pass, $storedPass)) {
                    $authenticated = true;
                    // Re-hash if cost factor changed
                    if (password_needs_rehash($storedPass, PASSWORD_BCRYPT, ['cost' => 12])) {
                        q("UPDATE `$t` SET password=? WHERE $id=?", [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row[$id]]);
                    }
                } elseif ($storedPass === $pass) {
                    // Plaintext match — migrate to bcrypt
                    $authenticated = true;
                    q("UPDATE `$t` SET password=? WHERE $id=?", [password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]), $row[$id]]);
                }

                if ($authenticated) {
                    $_SESSION['uid'] = $row[$id];
                    $_SESSION['uemail'] = $email;
                    $_SESSION['uname'] = $row['name'];
                    $_SESSION['utype'] = $t;
                    header("Location: /$t/");
                    exit;
                }
            }
        }
    }
    $err = 'Falsche E-Mail oder Passwort.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Login — Fleckfrei</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'#2E7D6B'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>body{font-family:'Inter',system-ui,sans-serif}</style>
</head>
<body class="min-h-screen bg-gradient-to-br from-brand/5 via-white to-brand/10 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="text-center mb-8">
      <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand text-white text-2xl font-bold mb-4">F</div>
      <h1 class="text-3xl font-bold text-gray-900">Fleckfrei</h1>
      <p class="text-gray-500 mt-1">Admin Portal</p>
    </div>
    <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8">
      <?php if ($err): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 text-sm"><?= e($err) ?></div><?php endif; ?>
      <form method="POST" class="space-y-5">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">E-Mail</label>
          <input type="email" name="email" required autofocus class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none transition" placeholder="admin@gmail.com"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Passwort</label>
          <input type="password" name="password" required class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none transition"/>
        </div>
        <button type="submit" class="w-full bg-brand hover:bg-brand/90 text-white font-semibold py-3 rounded-xl transition shadow-lg shadow-brand/20">Anmelden</button>
      </form>
    </div>
  </div>
</body>
</html>

<?php
declare(strict_types=1);
if (is_file(__DIR__ . '/.installed')) { http_response_code(403); exit('Installer is locked. Remove .installed only if you intentionally need to reinstall.'); }
session_name('sliid_install'); session_start();
if (empty($_SESSION['install_csrf'])) $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['install_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Invalid security token.');
        $required = ['app_url','db_host','db_name','db_user','admin_name','admin_email','admin_password'];
        foreach ($required as $key) if (trim((string)($_POST[$key] ?? '')) === '') throw new RuntimeException('Please complete all required fields.');
        if (!filter_var($_POST['app_url'], FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a valid backend URL.');
        if (!filter_var($_POST['admin_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid administrator email.');
        if (strlen((string)$_POST['admin_password']) < 12) throw new RuntimeException('Administrator password must contain at least 12 characters.');
        $db = [
            'host' => trim($_POST['db_host']), 'port' => (int)($_POST['db_port'] ?: 3306),
            'name' => trim($_POST['db_name']), 'user' => trim($_POST['db_user']),
            'password' => (string)($_POST['db_password'] ?? ''), 'charset' => 'utf8mb4'
        ];
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']), $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = (string)file_get_contents(__DIR__ . '/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema))) as $statement) $pdo->exec($statement);
        $stmt = $pdo->prepare("INSERT INTO admins(name,email,password_hash,role) VALUES(?,?,?,'super_admin')");
        $stmt->execute([trim($_POST['admin_name']), strtolower(trim($_POST['admin_email'])), password_hash($_POST['admin_password'], PASSWORD_DEFAULT)]);
        $origins = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($_POST['cors_origins'] ?? '')))));
        $config = [
            'app_name' => 'SLIID Administration', 'app_url' => rtrim($_POST['app_url'], '/'),
            'timezone' => 'Asia/Colombo', 'debug' => false, 'database' => $db,
            'cors_origins' => $origins, 'upload_max_bytes' => 5242880,
        ];
        $written = file_put_contents(__DIR__ . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
        if (!$written) throw new RuntimeException('Could not write config.php. Check folder permissions.');
        file_put_contents(__DIR__ . '/.installed', date(DATE_ATOM), LOCK_EX);
        header('Location: admin.php?installed=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install SLIID Backend</title><style>
:root{--navy:#071c29;--gold:#ac874b;--paper:#f5f2ec}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:#172733;font:14px/1.5 Arial,sans-serif}.wrap{width:min(850px,calc(100% - 30px));margin:45px auto}.head{background:var(--navy);color:#fff;padding:32px}.head h1{font:42px Georgia,serif;margin:0}.panel{background:#fff;padding:32px;box-shadow:0 18px 50px #071c2918}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{font-size:12px;font-weight:bold}input,textarea{display:block;width:100%;margin-top:6px;border:1px solid #d2cec6;padding:12px}.full{grid-column:1/-1}button{border:0;background:var(--gold);color:#fff;padding:14px 25px;font-weight:bold;text-transform:uppercase;letter-spacing:.1em}.error{background:#fee;border:1px solid #d99;color:#800;padding:12px;margin-bottom:18px}.note{color:#65717a;font-size:12px}@media(max-width:650px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}</style></head><body><div class="wrap"><div class="head"><h1>SLIID Backend</h1><p>Secure PHP & MySQL installation</p></div><div class="panel"><?php if($error):?><div class="error"><?=htmlspecialchars($error)?></div><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['install_csrf'])?>"><div class="grid"><label class="full">Backend URL<input name="app_url" placeholder="https://api.sliid.lk" required></label><label>Database host<input name="db_host" value="localhost" required></label><label>Database port<input name="db_port" value="3306" required></label><label>Database name<input name="db_name" required></label><label>Database user<input name="db_user" required></label><label class="full">Database password<input type="password" name="db_password"></label><label>Administrator name<input name="admin_name" required></label><label>Administrator email<input type="email" name="admin_email" required></label><label class="full">Administrator password<input type="password" name="admin_password" minlength="12" required><span class="note">Use at least 12 characters with uppercase, lowercase, numbers and symbols.</span></label><label class="full">Allowed website origins<textarea name="cors_origins" rows="3">https://naveen-jayasankha.github.io
https://www.sliid.lk
https://sliid.lk</textarea></label><div class="full"><button type="submit">Install backend →</button></div></div></form></div></div></body></html>

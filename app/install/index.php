<?php

declare(strict_types=1);

session_start();

$root = dirname(__DIR__);
$configDir = $root . '/config';
$configFile = $configDir . '/config.php';
$dataDir = $root . '/data';
$errors = [];
$messages = [];

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function request_value(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function detect_mysql_candidates(): array
{
    return [
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'password' => ''],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'password' => ''],
    ];
}

function try_mysql_connection(string $host, string $port, string $user, string $password, ?string $dbname = null): PDO
{
    $dbPart = $dbname ? ';dbname=' . $dbname : '';
    return new PDO("mysql:host={$host};port={$port}{$dbPart};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function mysql_version_label(?PDO $pdo): string
{
    if (!$pdo) {
        return 'Nao detectado';
    }
    try {
        return (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable) {
        return 'Detectado';
    }
}

function write_config_file(string $path, array $config): void
{
    $export = var_export($config, true);
    $content = "<?php\n\nreturn {$export};\n";
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Nao consegui gravar config/config.php.');
    }
}

function install_admin(PDO $db, string $name, string $email, string $password): void
{
    $stmt = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($id > 0) {
        $update = $db->prepare("UPDATE users SET name=?, password_hash=?, role='admin', active=1 WHERE id=?");
        $update->execute([$name, $hash, $id]);
        return;
    }
    $insert = $db->prepare("INSERT INTO users(name, email, password_hash, role, active) VALUES(?,?,?,?,1)");
    $insert->execute([$name, $email, $hash, 'admin']);
}

$requirements = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'JSON' => extension_loaded('json'),
    'OpenSSL' => extension_loaded('openssl'),
    'Config gravavel' => is_writable($configDir) || (!is_dir($configDir) && is_writable($root)),
    'Data gravavel' => is_writable($dataDir) || (!is_dir($dataDir) && is_writable($root)),
];

$detectedPdo = null;
foreach (detect_mysql_candidates() as $candidate) {
    try {
        $detectedPdo = try_mysql_connection($candidate['host'], $candidate['port'], $candidate['user'], $candidate['password']);
        break;
    } catch (Throwable) {
        $detectedPdo = null;
    }
}

$defaults = [
    'app_name' => 'Discadora SIP',
    'timezone' => 'America/Sao_Paulo',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'discadora',
    'db_user' => 'root',
    'db_password' => '',
    'admin_name' => 'Administrador',
    'admin_email' => 'admin@local',
    'admin_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($requirements as $label => $ok) {
        if (!$ok) {
            $errors[] = "Requisito pendente: {$label}.";
        }
    }

    $appName = request_value('app_name', $defaults['app_name']);
    $timezone = request_value('timezone', $defaults['timezone']);
    $dbHost = request_value('db_host', $defaults['db_host']);
    $dbPort = request_value('db_port', $defaults['db_port']);
    $dbName = preg_replace('/[^A-Za-z0-9_]/', '', request_value('db_name', $defaults['db_name']));
    $dbUser = request_value('db_user', $defaults['db_user']);
    $dbPassword = (string)($_POST['db_password'] ?? '');
    $adminName = request_value('admin_name', $defaults['admin_name']);
    $adminEmail = request_value('admin_email', $defaults['admin_email']);
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $overwrite = ($_POST['overwrite_config'] ?? '') === '1';

    if ($dbName === '') {
        $errors[] = 'Informe um nome de banco valido.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail valido para o administrador.';
    }
    if (strlen($adminPassword) < 6) {
        $errors[] = 'A senha do administrador precisa ter pelo menos 6 caracteres.';
    }
    if (is_file($configFile) && !$overwrite) {
        $errors[] = 'Ja existe config/config.php. Marque a opcao de atualizar configuracao para continuar.';
    }

    if (!$errors) {
        try {
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0775, true);
            }

            $server = try_mysql_connection($dbHost, $dbPort, $dbUser, $dbPassword);
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $config = [
                'app_name' => $appName,
                'timezone' => $timezone,
                'database' => [
                    'driver' => 'mysql',
                    'host' => $dbHost,
                    'port' => (int)$dbPort,
                    'dbname' => $dbName,
                    'user' => $dbUser,
                    'password' => $dbPassword,
                    'charset' => 'utf8mb4',
                    'sqlite_path' => '__DIR__PLACEHOLDER__',
                ],
                'default_admin' => [
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password' => $adminPassword,
                ],
            ];

            $config['database']['sqlite_path'] = $root . '/data/app.sqlite';
            write_config_file($configFile, $config);

            require_once $root . '/src/Database.php';
            require_once $root . '/src/helpers.php';
            require_once $root . '/src/migrations.php';
            run_migrations(require $configFile);
            install_admin(Database::conn(), $adminName, $adminEmail, $adminPassword);

            $messages[] = 'Instalacao concluida com sucesso.';
            $messages[] = 'Banco criado/atualizado: ' . $dbName;
            $messages[] = 'Administrador pronto: ' . $adminEmail;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$installed = is_file($configFile);
$mysqlLabel = mysql_version_label($detectedPdo);

?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalador - Discadora SIP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { color-scheme: light; --green:#127c63; --ink:#071426; --muted:#66758f; --line:#d7e0ec; --bg:#f3f6fa; --danger:#b42318; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Arial, Helvetica, sans-serif; background:var(--bg); color:var(--ink); }
        main { max-width:1120px; margin:0 auto; padding:40px 20px; }
        .hero { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; margin-bottom:24px; }
        .badge { display:inline-flex; gap:8px; align-items:center; background:#e8f7f1; color:var(--green); border-radius:999px; padding:8px 14px; font-weight:800; letter-spacing:.03em; text-transform:uppercase; font-size:13px; }
        h1 { font-size:42px; line-height:1.05; margin:14px 0 8px; }
        p { color:var(--muted); font-size:18px; margin:0; }
        .grid { display:grid; grid-template-columns: .9fr 1.4fr; gap:20px; align-items:start; }
        .card { background:#fff; border:1px solid var(--line); border-radius:8px; box-shadow:0 16px 40px rgba(21,31,48,.06); }
        .card-header { padding:22px 24px; border-bottom:1px solid var(--line); }
        .card-body { padding:24px; }
        .checks { display:grid; gap:10px; }
        .check { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 14px; border:1px solid var(--line); border-radius:8px; background:#fafcff; font-weight:700; }
        .ok { color:var(--green); }
        .bad { color:var(--danger); }
        form { display:grid; gap:22px; }
        fieldset { border:1px solid var(--line); border-radius:8px; padding:20px; display:grid; gap:16px; }
        legend { font-weight:900; padding:0 8px; }
        .fields { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:16px; }
        label { display:grid; gap:8px; font-weight:800; color:#344258; }
        input, select { width:100%; border:1px solid #cad5e3; border-radius:8px; min-height:50px; padding:12px 14px; font:inherit; background:#fff; color:var(--ink); }
        .full { grid-column:1 / -1; }
        .inline { display:flex; align-items:center; gap:10px; font-weight:700; color:var(--ink); }
        .inline input { width:20px; min-height:20px; }
        .btns { display:flex; justify-content:flex-end; gap:12px; flex-wrap:wrap; }
        button, .button { border:1px solid var(--line); background:#fff; color:var(--ink); border-radius:8px; min-height:54px; padding:0 22px; display:inline-flex; align-items:center; gap:10px; font-weight:900; font-size:18px; text-decoration:none; cursor:pointer; }
        .primary { background:var(--green); border-color:var(--green); color:#fff; }
        .notice { padding:14px 16px; border-radius:8px; margin-bottom:12px; font-weight:800; }
        .notice.ok { background:#ecfdf3; border:1px solid #9fe3bf; color:#087443; }
        .notice.err { background:#fff1f0; border:1px solid #ffa39e; color:var(--danger); }
        code { background:#edf2f7; border-radius:6px; padding:3px 6px; }
        @media (max-width: 860px) { .grid, .fields { grid-template-columns:1fr; } h1 { font-size:34px; } .hero { display:block; } }
    </style>
</head>
<body>
<main>
    <section class="hero">
        <div>
            <span class="badge"><i class="bi bi-hdd-network"></i> Instalador automatico</span>
            <h1>Discadora SIP</h1>
            <p>Detecta MariaDB/MySQL, cria o banco, aplica as tabelas e cadastra o primeiro administrador.</p>
        </div>
        <?php if ($installed): ?>
            <a class="button" href="../index.php"><i class="bi bi-box-arrow-up-right"></i> Abrir sistema</a>
        <?php endif; ?>
    </section>

    <?php foreach ($messages as $message): ?>
        <div class="notice ok"><i class="bi bi-check-circle"></i> <?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="notice err"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
    <?php endforeach; ?>

    <div class="grid">
        <aside class="card">
            <div class="card-header">
                <h2>Diagnostico</h2>
                <p>MariaDB/MySQL: <code><?= h($mysqlLabel) ?></code></p>
            </div>
            <div class="card-body checks">
                <?php foreach ($requirements as $label => $ok): ?>
                    <div class="check">
                        <span><?= h($label) ?></span>
                        <i class="bi <?= $ok ? 'bi-check-circle-fill ok' : 'bi-x-circle-fill bad' ?>"></i>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="card">
            <div class="card-header">
                <h2>Configuracao inicial</h2>
                <p>Use os dados do MariaDB local da VPS ou do XAMPP.</p>
            </div>
            <div class="card-body">
                <form method="post">
                    <fieldset>
                        <legend>Sistema</legend>
                        <div class="fields">
                            <label>Nome do sistema
                                <input name="app_name" value="<?= h(request_value('app_name', $defaults['app_name'])) ?>" required>
                            </label>
                            <label>Fuso horario
                                <select name="timezone">
                                    <?php foreach (['America/Sao_Paulo', 'UTC'] as $tz): ?>
                                        <option value="<?= h($tz) ?>" <?= request_value('timezone', $defaults['timezone']) === $tz ? 'selected' : '' ?>><?= h($tz) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>MariaDB / MySQL</legend>
                        <div class="fields">
                            <label>Host
                                <input name="db_host" value="<?= h(request_value('db_host', $defaults['db_host'])) ?>" required>
                            </label>
                            <label>Porta
                                <input name="db_port" value="<?= h(request_value('db_port', $defaults['db_port'])) ?>" required>
                            </label>
                            <label>Banco
                                <input name="db_name" value="<?= h(request_value('db_name', $defaults['db_name'])) ?>" required>
                            </label>
                            <label>Usuario
                                <input name="db_user" value="<?= h(request_value('db_user', $defaults['db_user'])) ?>" required>
                            </label>
                            <label class="full">Senha
                                <input name="db_password" type="password" value="<?= h((string)($_POST['db_password'] ?? $defaults['db_password'])) ?>">
                            </label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>Primeiro administrador</legend>
                        <div class="fields">
                            <label>Nome
                                <input name="admin_name" value="<?= h(request_value('admin_name', $defaults['admin_name'])) ?>" required>
                            </label>
                            <label>E-mail
                                <input name="admin_email" type="email" value="<?= h(request_value('admin_email', $defaults['admin_email'])) ?>" required>
                            </label>
                            <label class="full">Senha
                                <input name="admin_password" type="password" required>
                            </label>
                        </div>
                    </fieldset>

                    <label class="inline">
                        <input type="checkbox" name="overwrite_config" value="1" <?= $installed ? '' : 'checked' ?>>
                        Atualizar/criar <code>config/config.php</code>
                    </label>

                    <div class="btns">
                        <button class="primary" type="submit"><i class="bi bi-database-check"></i> Instalar agora</button>
                        <?php if ($installed): ?>
                            <a class="button" href="../index.php"><i class="bi bi-house"></i> Voltar ao painel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>
    </div>
</main>
</body>
</html>

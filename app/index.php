<?php

require __DIR__ . '/src/bootstrap.php';

$config = require __DIR__ . '/config/config.php';
$db = Database::conn();
$page = $_GET['page'] ?? 'dashboard';
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action === 'logout') {
    Auth::logout();
    redirect('?page=login');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
}

function audit(?int $userId, string $action, array|string|null $details = null): void
{
    $stmt = Database::conn()->prepare('INSERT INTO audit_logs(user_id, action, details, ip) VALUES(?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $action,
        is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function db_now_expr(): string
{
    return Database::isMysql() ? 'NOW()' : "datetime('now')";
}

function db_rate_cast_expr(string $column): string
{
    return Database::isMysql() ? "CAST({$column} AS DECIMAL(18,6))" : "CAST({$column} AS REAL)";
}

function db_insert_ignore(): string
{
    return Database::isMysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
}

function app_display_name(): string
{
    global $config;
    return setting('brand_name', (string)($config['app_name'] ?? 'Discadora SIP')) ?: 'Discadora SIP';
}

function brand_logo_url(): string
{
    $logo = trim((string)setting('brand_logo', ''));
    if ($logo !== '' && is_file(__DIR__ . '/' . ltrim($logo, '/'))) {
        return ltrim($logo, '/');
    }
    return '';
}

function recaptcha_enabled(): bool
{
    return setting('google_recaptcha_enabled', '0') === '1'
        && setting('google_recaptcha_site_key', '') !== ''
        && setting('google_recaptcha_secret_key', '') !== '';
}

function verify_recaptcha_response(): bool
{
    if (!recaptcha_enabled()) {
        return true;
    }
    $token = (string)($_POST['g-recaptcha-response'] ?? '');
    if ($token === '') {
        return false;
    }
    $secret = (string)setting('google_recaptcha_secret_key', '');
    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ],
    ]);
    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) && !empty($decoded['success']);
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'sync-asterisk')) {
    sync_asterisk_extensions();
    echo "Ramais sincronizados no Asterisk.\n";
    exit;
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'sync-ivrs')) {
    sync_asterisk_ivrs();
    echo "URAs sincronizadas no Asterisk.\n";
    exit;
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'magnus-test')) {
    echo magnus_mysql('SELECT 1;') . "\n";
    exit;
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'sync-magnus-users')) {
    foreach (Database::conn()->query('SELECT id FROM users WHERE active=1 ORDER BY id') as $row) {
        $remoteId = sync_user_to_magnus((int)$row['id']);
        echo "Usuario local {$row['id']} -> Magnus {$remoteId}\n";
    }
    exit;
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'magnus-apply-rate')) {
    $localUserId = (int)($argv[2] ?? 0);
    $rateId = (int)($argv[3] ?? 0);
    $stmt = Database::conn()->prepare('SELECT * FROM magnus_rates WHERE id=? AND active=1');
    $stmt->execute([$rateId]);
    $rate = $stmt->fetch();
    if ($localUserId <= 0 || !$rate) {
        fwrite(STDERR, "Uso: php index.php magnus-apply-rate <usuario_local> <taxa_local>\n");
        exit(1);
    }
    sync_user_to_magnus($localUserId);
    apply_magnus_rate_to_user($localUserId, $rate);
    echo "Rota Magnus aplicada.\n";
    exit;
}

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_recaptcha_response()) {
            flash('Confirme o captcha para entrar.', 'error');
        } elseif (Auth::attempt((string)post('email'), (string)post('password'))) {
            redirect('?page=dashboard');
        } else {
            flash('E-mail ou senha invalidos.', 'error');
        }
    }
    render_header('Login', null);
    ?>
    <main class="login-shell">
        <section class="login-brand-panel">
            <?php if (brand_logo_url()): ?>
                <img src="<?= e(brand_logo_url()) ?>" alt="<?= e(app_display_name()) ?>">
            <?php else: ?>
                <span class="brand-mark login-brand-mark">DS</span>
            <?php endif; ?>
            <h1><?= e(app_display_name()) ?></h1>
            <p>Plataforma de discagem, atendimento e campanhas SIP.</p>
        </section>
        <form method="post" class="login-card">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div>
                <p class="eyebrow">Painel web</p>
                <h1><?= e(app_display_name()) ?></h1>
                <p class="muted">Entre para configurar SIP, ramais, usuarios e campanhas.</p>
            </div>
            <?php render_flash(); ?>
            <label>E-mail
                <input name="email" type="email" required autofocus>
            </label>
            <label>Senha
                <input name="password" type="password" required>
            </label>
            <?php if (recaptcha_enabled()): ?>
                <div class="g-recaptcha" data-sitekey="<?= e((string)setting('google_recaptcha_site_key', '')) ?>"></div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <?php endif; ?>
            <button class="btn primary" type="submit">Entrar</button>
        </form>
    </main>
    <?php
    render_footer();
    exit;
}

if ($page === 'agent_login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $extension = trim((string)post('extension'));
        $password = trim((string)post('password'));
        redirect('?page=agent_phone&extension=' . urlencode($extension) . '&password=' . urlencode($password));
    }
    render_header('Atendimento', null);
    ?>
    <main class="agent-login-shell">
        <section class="agent-hero">
            <div class="agent-icon">☎</div>
            <h1>Central de Atendimento</h1>
            <p>Entre com seu ramal para atender chamadas transferidas pela discadora.</p>
        </section>
        <form method="post" class="login-card agent-login-card">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div>
                <p class="eyebrow">Atendente</p>
                <h2>Acesse seu ramal</h2>
            </div>
            <?php render_flash(); ?>
            <label>Ramal <input name="extension" required autofocus value="<?= e((string)($_GET['extension'] ?? '')) ?>"></label>
            <label>Senha <input name="password" type="password" required value="<?= e((string)($_GET['password'] ?? '')) ?>"></label>
            <button class="btn primary" type="submit">Entrar na plataforma</button>
        </form>
    </main>
    <?php
    render_footer();
    exit;
}

if ($page === 'api_extension_config') {
    $extension = trim((string)($_GET['extension'] ?? ''));
    $password = trim((string)($_GET['password'] ?? ''));
    json_response(['ok' => true, 'account' => extension_webrtc_config($extension, $password)]);
}

if ($page === 'api_agent_presence') {
    $extension = trim((string)($_REQUEST['extension'] ?? ''));
    $password = trim((string)($_REQUEST['password'] ?? ''));
    $mode = trim((string)($_REQUEST['mode'] ?? 'online'));
    $row = extension_auth_row($extension, $password);
    if (!$row) {
        json_response(['ok' => false, 'message' => 'Ramal invalido.'], 403);
    }
    if ($mode === 'offline') {
        Database::conn()->prepare('UPDATE extensions SET online_last_seen=NULL WHERE id=?')->execute([(int)$row['id']]);
        json_response(['ok' => true, 'online' => false]);
    }
    Database::conn()->prepare('UPDATE extensions SET online_last_seen=' . db_now_expr() . ' WHERE id=?')->execute([(int)$row['id']]);
    json_response(['ok' => true, 'online' => true, 'serverTime' => gmdate('c')]);
}

if ($page === 'api_agent_history') {
    $extension = trim((string)($_GET['extension'] ?? ''));
    $password = trim((string)($_GET['password'] ?? ''));
    if (!extension_auth_row($extension, $password)) {
        json_response(['ok' => false, 'message' => 'Ramal invalido.'], 403);
    }
    $stmt = Database::conn()->prepare('SELECT id, extension, number, caller_id, status, started_at, answered_at, ended_at, duration_seconds, details FROM agent_call_logs WHERE extension=? ORDER BY started_at DESC LIMIT 200');
    $stmt->execute([$extension]);
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
}

if ($page === 'api_agent_call_event') {
    $extension = trim((string)post('extension'));
    $password = trim((string)post('password'));
    $extensionRow = extension_auth_row($extension, $password);
    if (!$extensionRow) {
        json_response(['ok' => false, 'message' => 'Ramal invalido.'], 403);
    }
    $number = display_call_number(trim((string)post('number')) ?: 'desconhecido');
    $callerId = display_call_number(trim((string)post('caller_id')) ?: '');
    $status = trim((string)post('status')) ?: 'Evento';
    $type = trim((string)post('type'));
    $db = Database::conn();
    if ($type === 'incoming') {
        $recentSql = Database::isMysql() ? 'DATE_SUB(NOW(), INTERVAL 3 MINUTE)' : "datetime('now', '-3 minutes')";
        $stmt = $db->prepare("SELECT id, number FROM agent_call_logs WHERE extension=? AND status='Pendente' AND ended_at IS NULL AND started_at >= {$recentSql} ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$extension]);
        $pending = $stmt->fetch() ?: null;
        $pendingId = (int)($pending['id'] ?? 0);
        if ($pendingId > 0) {
            $pendingNumber = display_call_number((string)($pending['number'] ?? ''));
            $finalNumber = in_array($pendingNumber, ['', 'desconhecido', $extension], true) ? $number : $pendingNumber;
            $db->prepare("UPDATE agent_call_logs SET number=?, caller_id=CASE WHEN ? <> '' THEN ? ELSE caller_id END, status=?, details=? WHERE id=? AND extension=?")->execute([$finalNumber, $callerId, $callerId, $status, post('details'), $pendingId, $extension]);
            json_response(['ok' => true, 'id' => $pendingId]);
        }
        $profile = billing_profile_for_user((int)($extensionRow['user_id'] ?? 0));
        $stmt = $db->prepare('INSERT INTO agent_call_logs(extension, number, caller_id, status, details, user_id, magnus_rate_id, magnus_rate_value, billing_initial_block, billing_increment) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$extension, $number, $callerId, $status, post('details'), (int)($extensionRow['user_id'] ?? 0), $profile['rate_id'], $profile['rate_value'], $profile['initial_block'], $profile['increment']]);
        json_response(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
    $id = (int)post('id', 0);
    if ($id <= 0) {
        $stmt = $db->prepare("SELECT id FROM agent_call_logs WHERE extension=? AND number=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$extension, $number]);
        $id = (int)($stmt->fetchColumn() ?: 0);
    }
    if ($id <= 0) {
        $profile = billing_profile_for_user((int)($extensionRow['user_id'] ?? 0));
        $stmt = $db->prepare('INSERT INTO agent_call_logs(extension, number, caller_id, status, details, user_id, magnus_rate_id, magnus_rate_value, billing_initial_block, billing_increment) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$extension, $number, $callerId, $status, post('details'), (int)($extensionRow['user_id'] ?? 0), $profile['rate_id'], $profile['rate_value'], $profile['initial_block'], $profile['increment']]);
        $id = (int)$db->lastInsertId();
    }
    if ($callerId !== '') {
        $db->prepare('UPDATE agent_call_logs SET caller_id=? WHERE id=? AND extension=?')->execute([$callerId, $id, $extension]);
    }
    if ($type === 'answered') {
        $db->prepare('UPDATE agent_call_logs SET status=?, answered_at=COALESCE(answered_at, ' . db_now_expr() . ') WHERE id=? AND extension=?')->execute([$status, $id, $extension]);
        charge_agent_call_log($id, false);
    } elseif ($type === 'missed') {
        $db->prepare('UPDATE agent_call_logs SET status=?, ended_at=' . db_now_expr() . ', duration_seconds=0 WHERE id=? AND extension=?')->execute([$status, $id, $extension]);
    } elseif ($type === 'ended') {
        $durationSql = Database::isMysql()
            ? 'CASE WHEN answered_at IS NULL THEN 0 ELSE TIMESTAMPDIFF(SECOND, answered_at, NOW()) END'
            : "CASE WHEN answered_at IS NULL THEN 0 ELSE CAST((julianday(datetime('now')) - julianday(answered_at)) * 86400 AS INTEGER) END";
        $db->prepare('UPDATE agent_call_logs SET status=?, ended_at=' . db_now_expr() . ", duration_seconds={$durationSql} WHERE id=? AND extension=?")->execute([$status, $id, $extension]);
        charge_agent_call_log($id, true);
    } else {
        $db->prepare('UPDATE agent_call_logs SET status=? WHERE id=? AND extension=?')->execute([$status, $id, $extension]);
    }
    json_response(['ok' => true, 'id' => $id]);
}

if ($page === 'agent_phone') {
    render_header('Atendimento', null);
    page_agent_phone((string)($_GET['extension'] ?? ''), (string)($_GET['password'] ?? ''));
    render_footer();
    exit;
}

$user = Auth::requireLogin();

if (($user['role'] ?? '') !== 'admin') {
    if (setting('maintenance_enabled', '0') === '1') {
        render_header('Manutencao', $user);
        page_system_notice('Manutencao programada', setting('maintenance_message', 'Manutencao programada em andamento. Voltaremos em breve.'), 'bi-tools');
        render_footer();
        exit;
    }
    if (setting('system_block_enabled', '0') === '1') {
        render_header('Acesso bloqueado', $user);
        page_system_notice('Acesso bloqueado', setting('system_block_message', 'Acesso temporariamente bloqueado. Fale com o administrador.'), 'bi-lock');
        render_footer();
        exit;
    }
}

if ($page === 'api_campaign_run') {
    $campaignId = (int)($_GET['id'] ?? 0);
    $campaign = fetch_campaign_for_user($campaignId, $user);
    if (!$campaign) {
        json_response(['ok' => false, 'message' => 'Campanha nao encontrada.'], 404);
    }
    run_campaign_batch($campaign, $user);
}

if ($page === 'api_campaign_stats') {
    $campaignId = (int)($_GET['id'] ?? 0);
    if (!fetch_campaign_for_user($campaignId, $user)) {
        json_response(['ok' => false, 'message' => 'Campanha nao encontrada.'], 404);
    }
    json_response(['ok' => true, 'stats' => campaign_stats($campaignId)]);
}

if ($page === 'api_webrtc_config') {
    $accountId = (int)($_GET['account_id'] ?? 0);
    json_response(['ok' => true, 'account' => webrtc_account_config($accountId, $user)]);
}

if ($page === 'api_transfer_targets') {
    json_response(['ok' => true, 'targets' => transfer_targets()]);
}

if ($page === 'api_server_transfer') {
    verify_csrf();
    try {
        $target = trim((string)post('target'));
        $source = trim((string)post('source'));
        json_response(['ok' => true, 'result' => server_side_transfer($source, $target)]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    }
}

if ($page === 'api_online_calls') {
    Auth::requireAdmin();
    try {
        $snapshot = online_calls_snapshot();
    } catch (Throwable $e) {
        $snapshot = empty_online_calls_snapshot($e->getMessage());
    }
    $snapshot['history'] = recent_agent_call_history();
    json_response(['ok' => true, 'snapshot' => $snapshot]);
}

if ($page === 'api_ivr_digits') {
    Auth::requireAdmin();
    $ivrId = (int)($_GET['ivr_id'] ?? 0);
    if ($ivrId > 0) {
        try {
            import_remote_ivr_digit_events($ivrId);
        } catch (Throwable) {
            // Mantem o visor local funcionando mesmo se a leitura remota falhar.
        }
    }
    $where = $ivrId > 0 ? 'WHERE e.ivr_id=' . $ivrId : '';
    $rows = Database::conn()->query("SELECT e.*, i.name AS ivr_name, o.label AS option_label FROM ivr_digit_events e LEFT JOIN ivrs i ON i.id=e.ivr_id LEFT JOIN ivr_options o ON o.id=e.matched_option_id {$where} ORDER BY e.created_at DESC LIMIT 80")->fetchAll();
    json_response(['ok' => true, 'items' => $rows]);
}

if ($page === 'api_ivr_call_events') {
    Auth::requireAdmin();
    $ivrId = (int)($_GET['ivr_id'] ?? 0);
    if ($ivrId > 0) {
        try {
            advance_ivr_queue($ivrId);
        } catch (Throwable) {
            // O display nao deve travar se o Asterisk/AMI estiver momentaneamente indisponivel.
        }
    }
    $where = $ivrId > 0 ? 'WHERE ivr_id=' . $ivrId : '';
    $rows = Database::conn()->query("SELECT * FROM ivr_call_events {$where} ORDER BY created_at DESC, id DESC LIMIT 80")->fetchAll();
    json_response(['ok' => true, 'items' => $rows]);
}

if ($page === 'api_ivr_digit_event') {
    $secret = (string)setting('asterisk_ivr_event_secret', '');
    if ($secret !== '' && !hash_equals($secret, (string)($_REQUEST['secret'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Token invalido.'], 403);
    }
    $ivrId = (int)($_REQUEST['ivr_id'] ?? 0);
    $digit = preg_replace('/[^0-9*#]/', '', (string)($_REQUEST['digit'] ?? ''));
    if ($digit === '') {
        json_response(['ok' => false, 'message' => 'Digito vazio.'], 422);
    }
    $optionId = null;
    $destination = trim((string)($_REQUEST['destination'] ?? ''));
    if ($ivrId > 0) {
        $stmt = Database::conn()->prepare('SELECT id, destination FROM ivr_options WHERE ivr_id=? AND digit=? AND active=1 LIMIT 1');
        $stmt->execute([$ivrId, $digit]);
        $option = $stmt->fetch();
        if ($option) {
            $optionId = (int)$option['id'];
            $destination = $destination ?: (string)$option['destination'];
        }
    }
    Database::conn()->prepare('INSERT INTO ivr_digit_events(ivr_id, call_id, phone, digit, matched_option_id, destination) VALUES(?,?,?,?,?,?)')
        ->execute([$ivrId ?: null, trim((string)($_REQUEST['call_id'] ?? '')), display_call_number((string)($_REQUEST['phone'] ?? '')), $digit, $optionId, $destination]);
    json_response(['ok' => true]);
}

if ($page === 'call_history_export') {
    Auth::requireAdmin();
    export_call_history((string)($_GET['date'] ?? date('Y-m-d')), (string)($_GET['format'] ?? 'csv'));
}

if ($page === 'api_campaign_jobs') {
    $campaignId = (int)($_GET['id'] ?? 0);
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $campaign = require_campaign_for_user($campaignId, $user);
    if ($campaign && !user_can_spend((int)$campaign['created_by'])) {
        json_response(['ok' => true, 'jobs' => [], 'stats' => campaign_stats($campaignId), 'message' => 'Saldo insuficiente.']);
    }
    $jobs = reserve_campaign_jobs($campaignId, $limit);
    json_response(['ok' => true, 'jobs' => $jobs, 'stats' => campaign_stats($campaignId)]);
}

if ($page === 'api_call_job_update') {
    verify_csrf();
    $jobId = (int)post('job_id');
    $job = fetch_call_job_for_user($jobId, $user);
    if (!$job) {
        json_response(['ok' => false, 'message' => 'Job nao encontrado ou sem permissao.'], 404);
    }
    $status = (string)post('status');
    if (!in_array($status, ['calling', 'sent', 'answered', 'ended', 'nao_atendida', 'rejeitada', 'error'], true)) {
        json_response(['ok' => false, 'message' => 'Status invalido.'], 422);
    }
    $stmt = $db->prepare("UPDATE call_jobs SET status=?, attempts=attempts + CASE WHEN ? = 'calling' THEN 1 ELSE 0 END, response=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND campaign_id=?");
    $stmt->execute([$status, $status, (string)post('response'), $jobId, (int)$job['campaign_id']]);
    if ($status === 'answered') {
        $db->prepare("UPDATE call_jobs SET answered_at=COALESCE(answered_at, CURRENT_TIMESTAMP) WHERE id=? AND campaign_id=?")->execute([$jobId, (int)$job['campaign_id']]);
        charge_call_job($jobId, false);
    }
    if ($status === 'ended') {
        $db->prepare("UPDATE call_jobs SET ended_at=COALESCE(ended_at, CURRENT_TIMESTAMP) WHERE id=? AND campaign_id=?")->execute([$jobId, (int)$job['campaign_id']]);
        charge_call_job($jobId, true);
    }
    sync_agenda_number_from_job($jobId);
    json_response(['ok' => true]);
}

if ($page === 'api_campaign_transfer_result') {
    $jobId = (int)($_GET['job_id'] ?? 0);
    $job = fetch_call_job_for_user($jobId, $user);
    if (!$job) {
        json_response(['ok' => false, 'message' => 'Job nao encontrado.'], 404);
    }
    $number = display_call_number((string)$job['number']);
    $recentTransferSql = Database::isMysql()
        ? 'started_at >= DATE_SUB(COALESCE(?, NOW()), INTERVAL 10 SECOND)'
        : "started_at >= datetime(COALESCE(?, 'now'), '-10 seconds')";
    $stmt = $db->prepare("SELECT status, answered_at, ended_at FROM agent_call_logs WHERE number=? AND {$recentTransferSql} ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([$number, $job['answered_at'] ?: $job['updated_at']]);
    $log = $stmt->fetch() ?: null;
    if ($log && !empty($log['answered_at'])) {
        json_response(['ok' => true, 'result' => 'answered', 'message' => 'Atendente atendeu.']);
    }
    if ($log && !empty($log['ended_at'])) {
        json_response(['ok' => true, 'result' => 'rejected', 'message' => 'Atendente nao atendeu.']);
    }
    $ageSeconds = 0;
    if (!empty($job['answered_at'])) {
        $ageSeconds = max(0, time() - (int)strtotime((string)$job['answered_at']));
    }
    if ($ageSeconds >= 30) {
        $db->prepare("UPDATE call_jobs SET status='rejeitada', response='Atendente nao atendeu dentro do tempo', ended_at=COALESCE(ended_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id=? AND campaign_id=?")->execute([$jobId, (int)$job['campaign_id']]);
        sync_agenda_number_from_job($jobId);
        json_response(['ok' => true, 'result' => 'timeout', 'message' => 'Atendente nao atendeu dentro do tempo.']);
    }
    json_response(['ok' => true, 'result' => 'pending', 'message' => 'Aguardando atendente.']);
}

handle_posts($page, $user);

render_header(page_title($page), $user);
render_flash();

match ($page) {
    'dashboard' => page_dashboard(),
    'users' => page_users(),
    'ami' => page_ami(),
    'sip' => page_sip(),
    'extensions' => page_extensions(),
    'queues' => page_queues(),
    'campaigns' => page_campaigns(),
    'campaign' => page_campaign_detail((int)($_GET['id'] ?? 0)),
    'softphone' => page_softphone(),
    'online_calls' => page_online_calls(),
    'call_history' => page_call_history(),
    'credits' => page_credits(),
    'routes' => page_routes(),
    'ivrs' => page_ivrs('normal'),
    'reverse_ivrs' => page_ivrs('invertida'),
    'settings' => page_settings(),
    'auto_agendas' => page_auto_agendas(),
    'auto_audios' => page_auto_audios(),
    'monitor' => page_monitor(),
    'audit' => page_audit(),
    default => page_not_found(),
};

render_footer();

function handle_posts(string $page, array $user): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $db = Database::conn();
    $action = (string)post('action');

    if ($action === 'save_user') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $password = trim((string)post('password'));
        $rate = local_magnus_rate((int)post('default_magnus_rate_id', 0));
        if ($id > 0) {
            $fields = [
                post('name'),
                post('email'),
                post('role'),
                post('active', '0') === '1' ? 1 : 0,
                $rate ? (int)$rate['id'] : null,
                $rate ? (int)$rate['magnus_plan_id'] : null,
                $rate ? (int)$rate['magnus_route_id'] : null,
                $rate ? (string)$rate['rate_value'] : null,
                $id,
            ];
            $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ?, default_magnus_rate_id=?, default_magnus_plan_id=?, default_magnus_route_id=?, default_magnus_rate_value=? WHERE id = ?')->execute($fields);
            if ($password !== '') {
                $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            try {
                sync_user_to_magnus($id, $password);
                if ($rate) {
                    apply_magnus_rate_to_user($id, $rate);
                }
                flash('Usuario atualizado e sincronizado no Magnus.');
            } catch (Throwable $e) {
                flash('Usuario atualizado, mas falhou ao sincronizar Magnus: ' . $e->getMessage(), 'error');
            }
        } else {
            $initialCredit = max(0, (float)str_replace(',', '.', (string)post('initial_credit', '0')));
            $queueName = default_queue_name_for_local((string)post('email'));
            $db->prepare('INSERT INTO users(name, email, password_hash, role, active, default_magnus_rate_id, default_magnus_plan_id, default_magnus_route_id, default_magnus_rate_value, queue_name, queue_exten) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    post('name'),
                    post('email'),
                    password_hash($password ?: '123456', PASSWORD_DEFAULT),
                    post('role'),
                    1,
                    $rate ? (int)$rate['id'] : null,
                    $rate ? (int)$rate['magnus_plan_id'] : null,
                    $rate ? (int)$rate['magnus_route_id'] : null,
                    $rate ? (string)$rate['rate_value'] : null,
                    $queueName,
                    '',
            ]);
            $id = (int)$db->lastInsertId();
            $db->prepare('UPDATE users SET queue_name=?, queue_exten=? WHERE id=?')->execute([default_queue_name_for_local((string)post('email'), $id), default_queue_exten_for_local($id), $id]);
            if ($initialCredit > 0) {
                adjust_user_credit($id, $initialCredit, 'credit', 'Saldo inicial da conta', (int)$user['id']);
            }
            try {
                sync_user_to_magnus($id, $password ?: '123456');
                if ($rate) {
                    apply_magnus_rate_to_user($id, $rate);
                }
                flash('Usuario criado e sincronizado no Magnus.');
            } catch (Throwable $e) {
                flash('Usuario criado, mas falhou ao sincronizar Magnus: ' . $e->getMessage(), 'error');
            }
        }
        audit((int)$user['id'], 'save_user', ['email' => post('email')]);
        redirect('?page=users');
    }

    if ($action === 'credit_adjust') {
        Auth::requireAdmin();
        adjust_user_credit((int)post('user_id'), (float)str_replace(',', '.', (string)post('amount')), (string)post('type'), (string)post('note'), (int)$user['id']);
        flash('Saldo atualizado.');
        redirect('?page=credits');
    }

    if ($action === 'delete_user') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        if ($id === (int)$user['id']) {
            flash('Nao e possivel excluir a propria conta logada.', 'error');
            redirect('?page=users');
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM campaigns WHERE created_by=?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash('Nao foi possivel excluir: esta conta possui campanhas. Bloqueie a conta se quiser impedir acesso.', 'error');
            redirect('?page=users');
        }
        $db->prepare('DELETE FROM campaign_extensions WHERE extension_id IN (SELECT id FROM extensions WHERE user_id=?)')->execute([$id]);
        $db->prepare('DELETE FROM extensions WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM credit_transactions WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        audit((int)$user['id'], 'delete_user', ['id' => $id]);
        try {
            sync_asterisk_extensions();
        } catch (Throwable) {
        }
        flash('Conta excluida.');
        redirect('?page=users');
    }

    if ($action === 'sync_routes') {
        Auth::requireAdmin();
        sync_magnus_rates_from_remote();
        flash('Rotas sincronizadas com o Magnus.');
        redirect('?page=routes');
    }

    if ($action === 'save_ami') {
        Auth::requireAdmin();
        foreach (['ami_host', 'ami_port', 'ami_user', 'ami_secret', 'ami_timeout', 'monitoring_notice'] as $key) {
            save_setting($key, (string)post($key));
        }
        save_setting('monitoring_enabled', post('monitoring_enabled', '0') === '1' ? '1' : '0');
        audit((int)$user['id'], 'save_ami');
        flash('Configuracao AMI salva.');
        redirect('?page=settings');
    }

    if ($action === 'test_ami') {
        Auth::requireAdmin();
        try {
            $ami = AmiClient::fromSettings();
            $ami->connect();
            $ami->close();
            flash('Conexao AMI realizada com sucesso.');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
        }
        redirect('?page=settings');
    }

    if ($action === 'save_settings') {
        Auth::requireAdmin();
        $keys = [
            'brand_name',
            'brand_primary',
            'brand_accent',
            'default_theme',
            'google_recaptcha_site_key',
            'google_recaptcha_secret_key',
            'system_block_message',
            'maintenance_message',
            'app_public_url',
            'asterisk_ivr_event_secret',
            'asterisk_sync_host',
            'asterisk_sync_user',
            'asterisk_sync_key',
            'asterisk_sync_external_ip',
            'asterisk_sync_queue',
            'asterisk_sync_queue_exten',
            'magnus_sync_host',
            'magnus_sync_user',
            'magnus_sync_key',
            'magnus_template_user_id',
            'ami_host',
            'ami_port',
            'ami_user',
            'ami_secret',
            'ami_timeout',
            'monitoring_notice',
        ];
        foreach ($keys as $key) {
            save_setting($key, (string)post($key));
        }
        save_setting('google_recaptcha_enabled', post('google_recaptcha_enabled', '0') === '1' ? '1' : '0');
        save_setting('system_block_enabled', post('system_block_enabled', '0') === '1' ? '1' : '0');
        save_setting('maintenance_enabled', post('maintenance_enabled', '0') === '1' ? '1' : '0');
        save_setting('monitoring_enabled', post('monitoring_enabled', '0') === '1' ? '1' : '0');

        if (!empty($_FILES['brand_logo']['name'] ?? '')) {
            $original = basename((string)$_FILES['brand_logo']['name']);
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                flash('Logo precisa ser PNG, JPG, WEBP ou SVG.', 'error');
                redirect('?page=settings');
            }
            $dir = __DIR__ . '/uploads/branding';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $fileName = 'logo-' . date('YmdHis') . '.' . $ext;
            if (!move_uploaded_file((string)$_FILES['brand_logo']['tmp_name'], $dir . '/' . $fileName)) {
                flash('Falha ao salvar a logo.', 'error');
                redirect('?page=settings');
            }
            save_setting('brand_logo', 'uploads/branding/' . $fileName);
        }
        if (post('remove_logo', '0') === '1') {
            save_setting('brand_logo', '');
        }

        audit((int)$user['id'], 'save_settings');
        flash('Configuracoes salvas.');
        redirect('?page=settings');
    }

    if ($action === 'save_sip') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $websocketUrl = trim((string)post('websocket_url'));
        $webrtcEnabled = post('webrtc_enabled', '0') === '1' ? 1 : 0;
        if ($webrtcEnabled === 1 && !valid_webrtc_websocket_url($websocketUrl)) {
            flash('Para usar no WebRTC, informe um WebSocket SIP valido em WSS. Exemplo: wss://sip.protegerconta.online:8089/ws. Se for conta MicroSIP/UDP, desmarque Usar no WebRTC.', 'error');
            redirect('?page=sip' . ($id > 0 ? '&edit=' . $id : ''));
        }
        $data = [
            post('name'), post('label'), post('sip_server'), post('domain'), post('proxy'), post('username'),
            post('auth_user'), post('password'), post('display_name'), post('transport'), (int)post('port', 5060),
            post('stun_server'), post('voicemail'), post('publish_presence', '0') === '1' ? 1 : 0,
            post('dial_context'), post('channel_template'), post('caller_id'), post('active', '0') === '1' ? 1 : 0,
            $websocketUrl, $webrtcEnabled, post('ice_servers'), (int)post('register_expires', 300), post('dtmf_type'),
        ];
        if ($id > 0) {
            $data[] = $id;
            $db->prepare('UPDATE sip_accounts SET name=?, label=?, sip_server=?, domain=?, proxy=?, username=?, auth_user=?, password=?, display_name=?, transport=?, port=?, stun_server=?, voicemail=?, publish_presence=?, dial_context=?, channel_template=?, caller_id=?, active=?, websocket_url=?, webrtc_enabled=?, ice_servers=?, register_expires=?, dtmf_type=? WHERE id=?')->execute($data);
            flash('Tronco atualizado.');
        } else {
            $db->prepare('INSERT INTO sip_accounts(name,label,sip_server,domain,proxy,username,auth_user,password,display_name,transport,port,stun_server,voicemail,publish_presence,dial_context,channel_template,caller_id,active,websocket_url,webrtc_enabled,ice_servers,register_expires,dtmf_type) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
            flash('Tronco criado.');
        }
        audit((int)$user['id'], 'save_sip', ['name' => post('name')]);
        redirect('?page=sip');
    }

    if ($action === 'delete_sip') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $campaigns = (int)$db->query('SELECT COUNT(*) FROM campaigns WHERE sip_account_id = ' . $id)->fetchColumn();
        $extensions = (int)$db->query('SELECT COUNT(*) FROM extensions WHERE sip_account_id = ' . $id)->fetchColumn();
        if ($campaigns > 0 || $extensions > 0) {
            flash('Nao foi possivel deletar: este tronco esta vinculado a campanhas ou ramais.', 'error');
            redirect('?page=sip');
        }
        $stmt = $db->prepare('DELETE FROM sip_accounts WHERE id = ?');
        $stmt->execute([$id]);
        audit((int)$user['id'], 'delete_sip', ['id' => $id]);
        flash('Tronco deletado.');
        redirect('?page=sip');
    }

    if ($action === 'save_extension') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $extension = trim((string)post('extension'));
        if ($extension === '') {
            $extension = generate_unique_extension();
        }
        $secret = trim((string)post('secret')) ?: generate_extension_secret();
        $data = [
            (int)post('user_id', $user['id']),
            (int)post('sip_account_id'),
            $extension,
            post('name'),
            $secret,
            post('caller_id'),
            post('allow_monitoring', '0') === '1' ? 1 : 0,
            post('active', '0') === '1' ? 1 : 0,
            post('audio_initial_enabled', '0') === '1' ? 1 : 0,
            clean_asterisk_sound((string)post('audio_initial_file')),
            post('audio_transfer_enabled', '0') === '1' ? 1 : 0,
            clean_asterisk_sound((string)post('audio_transfer_file')),
        ];
        if ($id > 0) {
            $data[] = $id;
            $db->prepare('UPDATE extensions SET user_id=?, sip_account_id=?, extension=?, name=?, secret=?, caller_id=?, allow_monitoring=?, active=?, audio_initial_enabled=?, audio_initial_file=?, audio_transfer_enabled=?, audio_transfer_file=? WHERE id=?')->execute($data);
            flash('Ramal atualizado.');
        } else {
            $db->prepare('INSERT INTO extensions(user_id,sip_account_id,extension,name,secret,caller_id,allow_monitoring,active,audio_initial_enabled,audio_initial_file,audio_transfer_enabled,audio_transfer_file) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
            flash('Ramal criado.');
        }
        audit((int)$user['id'], 'save_extension', ['extension' => post('extension')]);
        try {
            sync_asterisk_extensions();
            flash('Ramal salvo e sincronizado no Asterisk.');
        } catch (Throwable $e) {
            flash('Ramal salvo, mas falhou ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=extensions');
    }

    if ($action === 'bulk_extensions') {
        Auth::requireAdmin();
        $quantity = max(1, min(200, (int)post('quantity', 1)));
        $sipAccountId = (int)post('sip_account_id');
        $ownerId = (int)post('user_id', $user['id']);
        $namePrefix = trim((string)post('name_prefix')) ?: 'Atendente';
        $callerId = trim((string)post('caller_id'));
    $insert = $db->prepare('INSERT INTO extensions(user_id,sip_account_id,extension,name,secret,caller_id,allow_monitoring,active,audio_initial_enabled,audio_initial_file,audio_transfer_enabled,audio_transfer_file) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $created = [];
        for ($i = 1; $i <= $quantity; $i++) {
            $extension = generate_unique_extension($created);
            $created[] = $extension;
            $name = $quantity === 1 ? $namePrefix : $namePrefix . ' ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $insert->execute([$ownerId, $sipAccountId, $extension, $name, generate_extension_secret(), $callerId, 0, 1, 0, '', 0, '']);
        }
        audit((int)$user['id'], 'bulk_extensions', ['quantity' => $quantity, 'extensions' => $created]);
        try {
            sync_asterisk_extensions();
            flash($quantity . ' ramal(is) criado(s) e sincronizado(s) no Asterisk.');
        } catch (Throwable $e) {
            flash($quantity . ' ramal(is) criado(s), mas falhou ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=extensions');
    }

    if ($action === 'delete_extension') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $stmt = $db->prepare('SELECT extension FROM extensions WHERE id = ?');
        $stmt->execute([$id]);
        $extension = (string)($stmt->fetchColumn() ?: '');
        $db->prepare('DELETE FROM extensions WHERE id = ?')->execute([$id]);
        audit((int)$user['id'], 'delete_extension', ['extension' => $extension]);
        try {
            sync_asterisk_extensions();
            flash('Ramal removido e Asterisk sincronizado.');
        } catch (Throwable $e) {
            flash('Ramal removido, mas falhou ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=extensions');
    }

    if ($action === 'sync_extensions') {
        Auth::requireAdmin();
        try {
            sync_asterisk_extensions();
            flash('Ramais sincronizados no Asterisk.');
        } catch (Throwable $e) {
            flash('Falha ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=extensions');
    }

    if ($action === 'save_queue') {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $id = (int)post('id', 0);
        $ownerId = $isAdmin ? (int)post('user_id', (int)$user['id']) : (int)$user['id'];
        $name = trim((string)post('name'));
        if ($name === '') {
            flash('Informe o nome da fila.', 'error');
            redirect('?page=queues');
        }
        $number = preg_replace('/[^0-9]/', '', (string)post('queue_number'));
        if ($number === '') {
            $number = generate_unique_queue_number($ownerId);
        }
        $strategy = queue_strategy_value((string)post('strategy', 'ringall'));
        $active = post('active', '0') === '1' ? 1 : 0;
        $joinEmpty = post('join_empty', '0') === '1' ? 1 : 0;
        $recordCalls = post('record_calls', '0') === '1' ? 1 : 0;
        $timeout = max(5, min(120, (int)post('timeout_seconds', 30)));
        $maxWait = max(10, min(3600, (int)post('max_wait_seconds', 300)));
        $maxCalls = max(0, min(500, (int)post('max_calls', 0)));
        $wrapup = max(0, min(600, (int)post('wrapup_seconds', 0)));
        $maxNoAgent = max(10, min(3600, (int)post('max_no_agent_seconds', 90)));
        $shortTalk = max(30, min(300, (int)post('short_talk_seconds', 120)));
        $musicclass = queue_musicclass_value((string)post('musicclass', 'default'));
        $announcePosition = post('announce_position', '0') === '1' ? 1 : 0;
        $announceFrequency = max(0, min(600, (int)post('announce_frequency', 30)));
        $exitDigit = preg_replace('/[^0-9*#]/', '', (string)post('exit_digit', ''));
        $overflowType = queue_overflow_type_value((string)post('overflow_type', 'hangup'));
        $overflowDestination = preg_replace('/[^A-Za-z0-9_.+-]/', '', (string)post('overflow_destination', ''));
        $tierRules = post('tier_rules_enabled', '0') === '1' ? 1 : 0;
        $description = trim((string)post('description'));
        $asteriskName = queue_asterisk_name($ownerId, $number);
        $selectedExtensions = array_values(array_filter(array_map('intval', (array)($_POST['extension_ids'] ?? []))));

        $ownerStmt = $db->prepare('SELECT id FROM users WHERE id=? AND active=1 LIMIT 1');
        $ownerStmt->execute([$ownerId]);
        if (!$ownerStmt->fetchColumn()) {
            flash('Conta da fila invalida ou bloqueada.', 'error');
            redirect('?page=queues');
        }

        $exists = $db->prepare('SELECT id FROM call_queues WHERE queue_number=? AND id<>? LIMIT 1');
        $exists->execute([$number, $id]);
        if ($exists->fetchColumn()) {
            flash('Numero de fila ja cadastrado. Escolha outro numero.', 'error');
            redirect('?page=queues' . ($id > 0 ? '&edit=' . $id : ''));
        }
        $reserved = $db->prepare('SELECT id FROM users WHERE queue_exten=? LIMIT 1');
        $reserved->execute([$number]);
        if ($reserved->fetchColumn()) {
            flash('Este numero ja esta reservado por uma fila interna de conta. Escolha outro numero.', 'error');
            redirect('?page=queues' . ($id > 0 ? '&edit=' . $id : ''));
        }

        if ($id > 0) {
            $guard = $isAdmin ? '' : ' AND user_id=' . (int)$user['id'];
            $stmt = $db->prepare("UPDATE call_queues SET user_id=?, name=?, queue_number=?, asterisk_name=?, strategy=?, timeout_seconds=?, max_wait_seconds=?, max_calls=?, wrapup_seconds=?, max_no_agent_seconds=?, short_talk_seconds=?, join_empty=?, record_calls=?, musicclass=?, announce_position=?, announce_frequency=?, exit_digit=?, overflow_type=?, overflow_destination=?, tier_rules_enabled=?, active=?, description=?, updated_at=CURRENT_TIMESTAMP WHERE id=?{$guard}");
            $stmt->execute([$ownerId, $name, $number, $asteriskName, $strategy, $timeout, $maxWait, $maxCalls, $wrapup, $maxNoAgent, $shortTalk, $joinEmpty, $recordCalls, $musicclass, $announcePosition, $announceFrequency, $exitDigit, $overflowType, $overflowDestination, $tierRules, $active, $description, $id]);
            if ($stmt->rowCount() <= 0 && !$isAdmin) {
                flash('Fila nao encontrada para esta conta.', 'error');
                redirect('?page=queues');
            }
            $db->prepare('DELETE FROM call_queue_extensions WHERE queue_id=?')->execute([$id]);
            flash('Fila atualizada.');
        } else {
            $db->prepare('INSERT INTO call_queues(user_id,name,queue_number,asterisk_name,strategy,timeout_seconds,max_wait_seconds,max_calls,wrapup_seconds,max_no_agent_seconds,short_talk_seconds,join_empty,record_calls,musicclass,announce_position,announce_frequency,exit_digit,overflow_type,overflow_destination,tier_rules_enabled,active,description) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$ownerId, $name, $number, $asteriskName, $strategy, $timeout, $maxWait, $maxCalls, $wrapup, $maxNoAgent, $shortTalk, $joinEmpty, $recordCalls, $musicclass, $announcePosition, $announceFrequency, $exitDigit, $overflowType, $overflowDestination, $tierRules, $active, $description]);
            $id = (int)$db->lastInsertId();
            flash('Fila criada.');
        }

        if ($selectedExtensions) {
            $allowed = $db->prepare('SELECT id FROM extensions WHERE id=? AND user_id=? AND active=1 LIMIT 1');
            $link = $db->prepare(db_insert_ignore() . ' INTO call_queue_extensions(queue_id, extension_id, penalty) VALUES(?,?,0)');
            foreach ($selectedExtensions as $extensionId) {
                $allowed->execute([$extensionId, $ownerId]);
                if ($allowed->fetchColumn()) {
                    $link->execute([$id, $extensionId]);
                }
            }
        }
        audit((int)$user['id'], 'save_queue', ['queue' => $number, 'owner' => $ownerId]);
        try {
            sync_asterisk_extensions();
            flash('Fila salva e sincronizada no Asterisk.');
        } catch (Throwable $e) {
            flash('Fila salva, mas falhou ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=queues');
    }

    if ($action === 'delete_queue') {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $id = (int)post('id', 0);
        $guard = $isAdmin ? '' : ' AND user_id=' . (int)$user['id'];
        $db->prepare("DELETE FROM call_queues WHERE id=?{$guard}")->execute([$id]);
        audit((int)$user['id'], 'delete_queue', ['id' => $id]);
        try {
            sync_asterisk_extensions();
            flash('Fila removida e Asterisk sincronizado.');
        } catch (Throwable $e) {
            flash('Fila removida, mas falhou ao sincronizar Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect('?page=queues');
    }

    if ($action === 'sync_queues') {
        try {
            sync_asterisk_extensions();
            flash('Filas sincronizadas no Asterisk.');
        } catch (Throwable $e) {
            flash('Falha ao sincronizar filas: ' . $e->getMessage(), 'error');
        }
        redirect('?page=queues');
    }

    if ($action === 'save_campaign') {
        $agendaId = (int)post('agenda_id', 0);
        if ($agendaId <= 0) {
            flash('Selecione uma agenda para buscar os numeros da campanha.', 'error');
            redirect('?page=campaigns');
        }
        $agendaGuard = is_admin_user($user) ? '' : ' AND a.user_id=' . (int)$user['id'];
        $stmt = $db->prepare("SELECT n.id, n.number FROM auto_agenda_numbers n JOIN auto_agendas a ON a.id=n.agenda_id WHERE n.agenda_id=?{$agendaGuard} ORDER BY n.id");
        $stmt->execute([$agendaId]);
        $agendaRows = [];
        foreach ($stmt->fetchAll() as $row) {
            $number = (string)$row['number'];
            if ($number !== '' && !isset($agendaRows[$number])) {
                $agendaRows[$number] = ['id' => (int)$row['id'], 'number' => $number];
            }
        }
        $numbers = array_values(array_column($agendaRows, 'number'));
        if (!$numbers) {
            flash('A agenda selecionada nao tem numeros validos.', 'error');
            redirect('?page=campaigns');
        }
        $selectedExtensions = array_values(array_filter(array_map('intval', (array)($_POST['extension_ids'] ?? []))));
        if (!$selectedExtensions) {
            flash('Selecione pelo menos um ramal.', 'error');
            redirect('?page=campaigns');
        }
        $extPlaceholders = implode(',', array_fill(0, count($selectedExtensions), '?'));
        $extGuard = is_admin_user($user) ? '' : ' AND user_id=' . (int)$user['id'];
        $stmt = $db->prepare("SELECT COUNT(*) FROM extensions WHERE id IN ({$extPlaceholders}) AND active=1{$extGuard}");
        $stmt->execute($selectedExtensions);
        if ((int)$stmt->fetchColumn() !== count($selectedExtensions)) {
            flash('Um ou mais ramais selecionados nao pertencem a esta conta.', 'error');
            redirect('?page=campaigns');
        }
        $rateId = (int)post('rate_id', 0);
        $rate = null;
        if ($rateId > 0 && (is_admin_user($user) || $rateId === (int)($user['default_magnus_rate_id'] ?? 0))) {
            $stmt = $db->prepare('SELECT * FROM magnus_rates WHERE id=? AND active=1');
            $stmt->execute([$rateId]);
            $rate = $stmt->fetch() ?: null;
        }
        if (!$rate && !empty($user['default_magnus_rate_id'])) {
            $rate = local_magnus_rate((int)$user['default_magnus_rate_id']);
        }
        $transferTarget = trim((string)post('transfer_target'));
        if ($transferTarget === '') {
            $transferTarget = trim((string)($user['queue_exten'] ?? ''));
        }
        if ($transferTarget === '') {
            flash('Configure uma fila propria para esta conta antes de criar campanha.', 'error');
            redirect('?page=campaigns');
        }
        $sipAccountId = (int)post('sip_account_id');
        if (is_admin_user($user)) {
            $stmt = $db->prepare('SELECT id FROM sip_accounts WHERE id=? AND active=1');
            $stmt->execute([$sipAccountId]);
        } else {
            $stmt = $db->prepare('SELECT DISTINCT s.id FROM sip_accounts s JOIN extensions e ON e.sip_account_id=s.id WHERE s.id=? AND s.active=1 AND e.active=1 AND e.user_id=?');
            $stmt->execute([$sipAccountId, (int)$user['id']]);
        }
        if (!$stmt->fetchColumn()) {
            flash('SIP selecionado nao pertence a esta conta.', 'error');
            redirect('?page=campaigns');
        }
        $waitAudioId = (int)post('wait_audio_id') ?: null;
        $startAudioId = (int)post('start_audio_id') ?: null;
        foreach (array_filter([$waitAudioId, $startAudioId]) as $audioId) {
            $audioGuard = is_admin_user($user) ? '' : ' AND user_id=' . (int)$user['id'];
            $stmt = $db->prepare("SELECT id FROM auto_audios WHERE id=?{$audioGuard}");
            $stmt->execute([(int)$audioId]);
            if (!$stmt->fetchColumn()) {
                flash('Audio selecionado nao pertence a esta conta.', 'error');
                redirect('?page=campaigns');
            }
        }
        $db->beginTransaction();
        $db->prepare('INSERT INTO campaigns(name,sip_account_id,source_extension_id,numbers,simultaneous,created_by,dialer_mode,transfer_target,agenda_id,caller_id,wait_audio_id,start_audio_id,queue_type,rate,magnus_plan_id,magnus_route_id,magnus_rate_id,magnus_rate_value,billing_initial_block,billing_increment,ddd_intelligent) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                post('name'),
                $sipAccountId,
                $selectedExtensions[0],
                implode("\n", $numbers),
                max(1, min(200, (int)post('simultaneous', 1))),
                (int)$user['id'],
                'ami',
                $transferTarget,
                $agendaId,
                trim((string)post('caller_id')),
                $waitAudioId,
                $startAudioId,
                trim((string)post('queue_type')),
                $rate ? (string)$rate['plan_name'] . ' / ' . (string)$rate['route_name'] : 'Nenhuma',
                $rate ? (int)$rate['magnus_plan_id'] : null,
                $rate ? (int)$rate['magnus_route_id'] : null,
                $rate ? (int)$rate['magnus_rate_id'] : null,
                $rate ? (string)$rate['rate_value'] : null,
                $rate ? max(1, (int)($rate['initial_block_seconds'] ?? 30)) : 30,
                $rate ? max(1, (int)($rate['billing_increment_seconds'] ?? 6)) : 6,
                post('ddd_inteligente', '0') === '1' ? 1 : 0,
            ]);
        $campaignId = (int)$db->lastInsertId();
        $job = $db->prepare('INSERT INTO call_jobs(campaign_id, number, agenda_number_id) VALUES(?, ?, ?)');
        foreach ($agendaRows as $row) {
            $job->execute([$campaignId, $row['number'], $row['id']]);
        }
        $link = $db->prepare(db_insert_ignore() . ' INTO campaign_extensions(campaign_id, extension_id) VALUES(?, ?)');
        foreach ($selectedExtensions as $extensionId) {
            $link->execute([$campaignId, $extensionId]);
        }
        $db->commit();
        if ($rate) {
            try {
                sync_user_to_magnus((int)$user['id']);
                apply_magnus_rate_to_user((int)$user['id'], $rate);
            } catch (Throwable $e) {
                flash('Campanha criada, mas falhou ao alterar rota no Magnus: ' . $e->getMessage(), 'error');
                redirect('?page=campaigns');
            }
        }
        audit((int)$user['id'], 'save_campaign', ['id' => $campaignId, 'numbers' => count($numbers)]);
        flash('Campanha criada.');
        redirect('?page=campaigns');
    }

    if ($action === 'delete_campaign') {
        $id = (int)post('id');
        if (!fetch_campaign_for_user($id, $user)) {
            flash('Campanha nao encontrada ou sem permissao.', 'error');
            redirect('?page=campaigns');
        }
        $guard = is_admin_user($user) ? '' : ' AND created_by=' . (int)$user['id'];
        $db->prepare("DELETE FROM campaigns WHERE id=?{$guard}")->execute([$id]);
        flash('Campanha removida.');
        redirect('?page=campaigns');
    }

    if ($action === 'toggle_campaign') {
        $id = (int)post('id');
        $campaign = fetch_campaign_for_user($id, $user);
        if (!$campaign) {
            flash('Campanha nao encontrada ou sem permissao.', 'error');
            redirect('?page=campaigns');
        }
        $status = (string)($campaign['status'] ?? '');
        $next = $status === 'running' ? 'paused' : 'running';
        $guard = is_admin_user($user) ? '' : ' AND created_by=' . (int)$user['id'];
        $db->prepare("UPDATE campaigns SET status=?, started_at=CASE WHEN ?='running' THEN COALESCE(started_at, CURRENT_TIMESTAMP) ELSE started_at END, finished_at=NULL WHERE id=?{$guard}")
            ->execute([$next, $next, $id]);
        audit((int)$user['id'], 'toggle_campaign', ['id' => $id, 'status' => $next]);
        flash($next === 'running' ? 'Campanha ativada.' : 'Campanha desativada.');
        redirect('?page=campaigns');
    }

    if ($action === 'reprocess_campaign') {
        $id = (int)post('id');
        if (!fetch_campaign_for_user($id, $user)) {
            flash('Campanha nao encontrada ou sem permissao.', 'error');
            redirect('?page=campaigns');
        }
        $statuses = array_values(array_intersect((array)($_POST['statuses'] ?? []), ['nao_atendida', 'rejeitada', 'error', 'calling', 'sent']));
        if (!$statuses) {
            flash('Selecione pelo menos um status para reprocessar.', 'error');
            redirect('?page=campaign&id=' . $id);
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $db->prepare("UPDATE call_jobs SET status='pending', attempts=0, response='Reprocessada', answered_at=NULL, ended_at=NULL, duration_seconds=0, updated_at=CURRENT_TIMESTAMP WHERE campaign_id=? AND status IN ({$placeholders})");
        $stmt->execute(array_merge([$id], $statuses));
        $stmt = $db->prepare("UPDATE auto_agenda_numbers SET status='Pendente' WHERE id IN (SELECT agenda_number_id FROM call_jobs WHERE campaign_id=? AND status='pending' AND agenda_number_id IS NOT NULL)");
        $stmt->execute([$id]);
        $guard = is_admin_user($user) ? '' : ' AND created_by=' . (int)$user['id'];
        $db->prepare("UPDATE campaigns SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), finished_at=NULL WHERE id=?{$guard}")->execute([$id]);
        audit((int)$user['id'], 'reprocess_campaign', ['id' => $id, 'statuses' => $statuses]);
        flash('Numeros selecionados voltaram para pendentes e a campanha foi reiniciada.');
        redirect('?page=campaign&id=' . $id);
    }

    if ($action === 'save_auto_agenda') {
        $title = trim((string)post('title'));
        if ($title === '') {
            flash('Informe o titulo da agenda.', 'error');
            redirect('?page=auto_agendas');
        }
        $removeFixed = post('remove_fixed', '0') === '1' ? 1 : 0;
        $db->prepare('INSERT INTO auto_agendas(title, remove_fixed, user_id) VALUES(?, ?, ?)')->execute([$title, $removeFixed, (int)$user['id']]);
        $agendaId = (int)$db->lastInsertId();
        $raw = '';
        if (!empty($_FILES['numbers_file']['tmp_name']) && is_uploaded_file($_FILES['numbers_file']['tmp_name'])) {
            $raw = (string)file_get_contents($_FILES['numbers_file']['tmp_name']);
        }
        $contacts = normalize_agenda_contacts($raw);
        if ($removeFixed) {
            $contacts = array_values(array_filter(
                $contacts,
                static fn(array $contact): bool => is_mobile_number((string)$contact['number'])
            ));
        }
        $insert = $db->prepare('INSERT INTO auto_agenda_numbers(agenda_id, number, cpf, name) VALUES(?, ?, ?, ?)');
        foreach ($contacts as $contact) {
            $insert->execute([
                $agendaId,
                $contact['number'],
                $contact['cpf'],
                $contact['name'],
            ]);
        }
        flash('Agenda criada com ' . count($contacts) . ' numero(s).');
        redirect('?page=auto_agendas');
    }

    if ($action === 'delete_auto_agenda') {
        $id = (int)post('id');
        $guard = is_admin_user($user) ? '' : ' AND user_id=' . (int)$user['id'];
        $db->prepare("DELETE FROM auto_agendas WHERE id=?{$guard}")->execute([$id]);
        flash('Agenda removida.');
        redirect('?page=auto_agendas');
    }

    if ($action === 'reprocess_auto_agenda') {
        $statuses = (array)($_POST['statuses'] ?? []);
        if (!$statuses) {
            flash('Selecione pelo menos um status para reprocessar.', 'error');
            redirect('?page=auto_agendas');
        }
        $agendaId = (int)post('agenda_id', 0);
        $allowed = array_values(array_intersect(array_map('strval', $statuses), ['Nao atendido', 'Rejeitada', 'Erro']));
        if ($agendaId > 0 && $allowed) {
            $guard = is_admin_user($user) ? '' : ' AND user_id=' . (int)$user['id'];
            $check = $db->prepare("SELECT id FROM auto_agendas WHERE id=?{$guard}");
            $check->execute([$agendaId]);
            if (!$check->fetchColumn()) {
                flash('Agenda nao encontrada ou sem permissao.', 'error');
                redirect('?page=auto_agendas');
            }
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $stmt = $db->prepare("UPDATE auto_agenda_numbers SET status='Pendente' WHERE agenda_id=? AND status IN ({$placeholders})");
            $stmt->execute(array_merge([$agendaId], $allowed));
        }
        flash('Numeros selecionados voltaram para pendentes.');
        redirect('?page=auto_agendas');
    }

    if ($action === 'save_auto_audio') {
        $title = trim((string)post('title'));
        $type = trim((string)post('type')) ?: 'Inicio';
        if ($title === '' || empty($_FILES['audio_file']['tmp_name']) || !is_uploaded_file($_FILES['audio_file']['tmp_name'])) {
            flash('Informe titulo e arquivo de audio.', 'error');
            redirect('?page=auto_audios');
        }
        $original = basename((string)($_FILES['audio_file']['name'] ?? 'audio.wav'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, ['wav', 'mp3', 'ogg'], true)) {
            flash('Use audio WAV, MP3 ou OGG.', 'error');
            redirect('?page=auto_audios');
        }
        $dir = __DIR__ . '/uploads/audios';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fileName = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($original, PATHINFO_FILENAME)) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $dir . '/' . $fileName;
        if (!move_uploaded_file($_FILES['audio_file']['tmp_name'], $path)) {
            flash('Falha ao salvar audio.', 'error');
            redirect('?page=auto_audios');
        }
        $db->prepare('INSERT INTO auto_audios(title, type, file_name, file_path, user_id) VALUES(?,?,?,?,?)')->execute([$title, $type, $fileName, 'uploads/audios/' . $fileName, (int)$user['id']]);
        flash('Audio salvo.');
        redirect('?page=auto_audios');
    }

    if ($action === 'delete_auto_audio') {
        $id = (int)post('id');
        $guard = is_admin_user($user) ? '' : ' AND user_id=' . (int)$user['id'];
        $stmt = $db->prepare("SELECT file_path FROM auto_audios WHERE id=?{$guard}");
        $stmt->execute([$id]);
        $path = (string)($stmt->fetchColumn() ?: '');
        $db->prepare("DELETE FROM auto_audios WHERE id=?{$guard}")->execute([$id]);
        $full = __DIR__ . '/' . $path;
        if ($path !== '' && is_file($full)) {
            unlink($full);
        }
        flash('Audio removido.');
        redirect('?page=auto_audios');
    }

    if ($action === 'save_ivr') {
        Auth::requireAdmin();
        $mode = post('mode') === 'invertida' ? 'invertida' : 'normal';
        $ivrPage = $mode === 'invertida' ? '?page=reverse_ivrs' : '?page=ivrs';
        $id = (int)post('id', 0);
        $name = trim((string)post('name'));
        if ($name === '') {
            flash('Informe o nome da ' . ($mode === 'invertida' ? 'URA Reversa.' : 'URA.'), 'error');
            redirect($ivrPage);
        }
        $data = [
            $mode,
            $name,
            trim((string)post('description')),
            (int)post('audio_id', 0) ?: null,
            max(3, min(60, (int)post('timeout_seconds', 10))),
            max(1, min(10, (int)post('max_attempts', 2))),
            trim((string)post('invalid_destination')),
            trim((string)post('timeout_destination')),
            trim((string)post('default_destination')),
            post('active', '0') === '1' ? 1 : 0,
            (int)post('sip_account_id', 0) ?: null,
            (int)post('source_extension_id', 0) ?: null,
            (int)post('agenda_id', 0) ?: null,
            display_call_number((string)post('test_phone')),
            preg_replace('/[^0-9+]/', '', (string)post('caller_id')) ?: '',
            trim((string)post('transfer_target')),
            max(1, min(200, (int)post('simultaneous', 1))),
            post('play_audio_on_answer', '0') === '1' ? 1 : 0,
            post('show_digit_viewer', '0') === '1' ? 1 : 0,
        ];
        if ($id > 0) {
            $db->prepare('UPDATE ivrs SET mode=?, name=?, description=?, audio_id=?, timeout_seconds=?, max_attempts=?, invalid_destination=?, timeout_destination=?, default_destination=?, active=?, sip_account_id=?, source_extension_id=?, agenda_id=?, test_phone=?, caller_id=?, transfer_target=?, simultaneous=?, play_audio_on_answer=?, show_digit_viewer=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([...$data, $id]);
            $db->prepare('DELETE FROM ivr_options WHERE ivr_id=?')->execute([$id]);
        } else {
            $db->prepare('INSERT INTO ivrs(mode,name,description,audio_id,timeout_seconds,max_attempts,invalid_destination,timeout_destination,default_destination,active,sip_account_id,source_extension_id,agenda_id,test_phone,caller_id,transfer_target,simultaneous,play_audio_on_answer,show_digit_viewer) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
            $id = (int)$db->lastInsertId();
        }

        $digits = (array)($_POST['option_digit'] ?? []);
        $labels = (array)($_POST['option_label'] ?? []);
        $types = (array)($_POST['option_type'] ?? []);
        $destinations = (array)($_POST['option_destination'] ?? []);
        $insertOption = $db->prepare(db_insert_ignore() . ' INTO ivr_options(ivr_id,digit,label,destination_type,destination,priority_order,active) VALUES(?,?,?,?,?,?,1)');
        foreach ($digits as $idx => $digit) {
            $digit = trim((string)$digit);
            $destination = trim((string)($destinations[$idx] ?? ''));
            if ($digit === '' || $destination === '') {
                continue;
            }
            $insertOption->execute([
                $id,
                preg_replace('/[^0-9*#]/', '', $digit) ?: $digit,
                trim((string)($labels[$idx] ?? 'Opcao ' . $digit)) ?: 'Opcao ' . $digit,
                in_array(($types[$idx] ?? ''), ['fila', 'ramal', 'audio', 'desligar'], true) ? (string)$types[$idx] : 'fila',
                $destination,
                $idx,
            ]);
        }
        audit((int)$user['id'], 'save_ivr', ['id' => $id, 'mode' => $mode]);
        try {
            sync_asterisk_ivrs();
            flash($mode === 'invertida' ? 'URA Reversa salva e sincronizada no Asterisk.' : 'URA salva e sincronizada no Asterisk.');
        } catch (Throwable $e) {
            flash(($mode === 'invertida' ? 'URA Reversa salva, mas falhou ao sincronizar Asterisk: ' : 'URA salva, mas falhou ao sincronizar Asterisk: ') . $e->getMessage(), 'error');
        }
        redirect($ivrPage);
    }

    if ($action === 'start_ivr_calls' || $action === 'test_ivr_call') {
        Auth::requireAdmin();
        $ivrId = (int)post('id', 0);
        $stmt = $db->prepare('SELECT * FROM ivrs WHERE id=?');
        $stmt->execute([$ivrId]);
        $ivr = $stmt->fetch();
        if (!$ivr) {
            flash('URA nao encontrada.', 'error');
            redirect('?page=ivrs');
        }
        $ivrPage = (($ivr['mode'] ?? '') === 'invertida') ? '?page=reverse_ivrs' : '?page=ivrs';
        try {
            sync_asterisk_ivrs();
        } catch (Throwable $e) {
            flash('Falha ao sincronizar dialplan da URA no Asterisk: ' . $e->getMessage(), 'error');
            redirect($ivrPage);
        }
        $numbers = [];
        $agendaRows = [];
        $agendaId = 0;
        if ($action === 'test_ivr_call') {
            $phone = display_call_number((string)post('test_phone', $ivr['test_phone'] ?? ''));
            if ($phone !== '') {
                $numbers[] = $phone;
                $agendaRows[] = ['id' => null, 'number' => $phone];
                $db->prepare('UPDATE ivrs SET test_phone=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$phone, (int)$ivr['id']]);
            }
        } else {
            $agendaId = (int)post('agenda_id', (int)($ivr['agenda_id'] ?? 0));
            if ($agendaId > 0) {
                $db->prepare("UPDATE auto_agenda_numbers SET status='Pendente' WHERE agenda_id=? AND status NOT IN ('Atendido','Finalizado')")->execute([$agendaId]);
                $stmt = $db->prepare("SELECT id, number FROM auto_agenda_numbers WHERE agenda_id=? AND number<>'' AND status NOT IN ('Atendido','Finalizado') ORDER BY id");
                $stmt->execute([$agendaId]);
                $agendaRows = $stmt->fetchAll();
                $numbers = array_values(array_map(static fn(array $row): string => (string)$row['number'], $agendaRows));
            }
        }
        if (!$numbers) {
            flash('Informe telefone de teste ou selecione uma agenda com numeros validos.', 'error');
            redirect($ivrPage);
        }
        $limit = max(1, min(200, (int)post('simultaneous', (int)($ivr['simultaneous'] ?? 1))));
        $numbers = array_slice($numbers, 0, $limit);
        $agendaRows = array_slice($agendaRows, 0, $limit);
        $callerId = ivr_caller_id($ivr);
        $db->prepare('DELETE FROM ivr_call_events WHERE ivr_id=? AND mode=?')->execute([(int)$ivr['id'], (string)$ivr['mode']]);
        $db->prepare('DELETE FROM ivr_digit_events WHERE ivr_id=?')->execute([(int)$ivr['id']]);
        if ($action === 'start_ivr_calls' && $agendaId > 0) {
            save_setting('ivr_run_active_' . (int)$ivr['id'], '1');
            save_setting('ivr_run_agenda_' . (int)$ivr['id'], (string)$agendaId);
            save_setting('ivr_run_limit_' . (int)$ivr['id'], (string)$limit);
        }
        try {
            $ami = AmiClient::fromSettings();
            foreach ($numbers as $number) {
                $safeNumber = preg_replace('/[^0-9+]/', '', (string)$number);
                if ($safeNumber === '') {
                    continue;
                }
                log_ivr_call_event((int)$ivr['id'], (string)$ivr['mode'], $safeNumber, $callerId, 'Enviando', 'Originate AMI enviado para o Asterisk');
                $response = originate_ivr_call($ami, $ivr, $safeNumber, $callerId);
                log_ivr_call_event((int)$ivr['id'], (string)$ivr['mode'], $safeNumber, $callerId, 'Chamando', trim($response) ?: 'Asterisk aceitou a solicitacao');
            }
            $ami->close();
        } catch (Throwable $e) {
            if ($action === 'start_ivr_calls') {
                save_setting('ivr_run_active_' . (int)$ivr['id'], '0');
            }
            foreach ($numbers as $number) {
                $safeNumber = preg_replace('/[^0-9+]/', '', (string)$number);
                if ($safeNumber !== '') {
                    log_ivr_call_event((int)$ivr['id'], (string)$ivr['mode'], $safeNumber, $callerId, 'Erro', $e->getMessage());
                }
            }
            flash('Falha ao disparar URA Reversa pelo AMI: ' . $e->getMessage(), 'error');
            redirect($ivrPage);
        }
        if ($agendaRows) {
            $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $agendaRows)));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("UPDATE auto_agenda_numbers SET status='Chamando' WHERE id IN ({$placeholders}) AND status NOT IN ('Atendido','Finalizado')")->execute($ids);
            }
        }
        flash(($ivr['mode'] ?? 'normal') === 'invertida'
            ? 'URA Reversa disparada direto no Asterisk. Nao foi criada campanha.'
            : 'Discagem da URA iniciada direto no Asterisk. Nao foi criada campanha.');
        redirect($ivrPage);
    }

    if ($action === 'delete_ivr') {
        Auth::requireAdmin();
        $mode = post('mode') === 'invertida' ? 'invertida' : 'normal';
        $db->prepare('DELETE FROM ivrs WHERE id=? AND mode=?')->execute([(int)post('id'), $mode]);
        audit((int)$user['id'], 'delete_ivr', ['id' => (int)post('id'), 'mode' => $mode]);
        try {
            sync_asterisk_ivrs();
            flash($mode === 'invertida' ? 'URA Reversa removida e Asterisk sincronizado.' : 'URA removida e Asterisk sincronizado.');
        } catch (Throwable $e) {
            flash(($mode === 'invertida' ? 'URA Reversa removida, mas falhou ao sincronizar Asterisk: ' : 'URA removida, mas falhou ao sincronizar Asterisk: ') . $e->getMessage(), 'error');
        }
        redirect($mode === 'invertida' ? '?page=reverse_ivrs' : '?page=ivrs');
    }

    if ($action === 'set_ivr_status') {
        Auth::requireAdmin();
        $id = (int)post('id', 0);
        $mode = post('mode') === 'invertida' ? 'invertida' : 'normal';
        $active = post('active', '0') === '1' ? 1 : 0;
        $db->prepare('UPDATE ivrs SET active=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND mode=?')->execute([$active, $id, $mode]);
        audit((int)$user['id'], 'set_ivr_status', ['id' => $id, 'mode' => $mode, 'active' => $active]);
        try {
            sync_asterisk_ivrs();
            flash(($mode === 'invertida' ? 'URA Reversa ' : 'URA ') . ($active ? 'iniciada.' : 'pausada.'));
        } catch (Throwable $e) {
            flash(($mode === 'invertida' ? 'URA Reversa atualizada, mas falhou ao sincronizar Asterisk: ' : 'URA atualizada, mas falhou ao sincronizar Asterisk: ') . $e->getMessage(), 'error');
        }
        redirect($mode === 'invertida' ? '?page=reverse_ivrs' : '?page=ivrs');
    }

    if ($action === 'sync_ivr') {
        Auth::requireAdmin();
        $mode = post('mode') === 'invertida' ? 'invertida' : 'normal';
        try {
            sync_asterisk_ivrs();
            flash(($mode === 'invertida' ? 'URA Reversa sincronizada no Asterisk.' : 'URA sincronizada no Asterisk.'));
        } catch (Throwable $e) {
            flash('Falha ao sincronizar URA no Asterisk: ' . $e->getMessage(), 'error');
        }
        redirect($mode === 'invertida' ? '?page=reverse_ivrs' : '?page=ivrs');
    }

    if ($action === 'start_campaign') {
        $id = (int)post('id');
        if (!fetch_campaign_for_user($id, $user)) {
            flash('Campanha nao encontrada ou sem permissao.', 'error');
            redirect('?page=campaigns');
        }
        $staleTime = Database::isMysql()
            ? "updated_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
            : "updated_at < datetime('now', '-2 minutes')";
        $db->prepare("UPDATE call_jobs SET status='pending', response='Retomada apos chamada travada', updated_at=CURRENT_TIMESTAMP WHERE campaign_id=? AND status IN ('calling','sent') AND ({$staleTime} OR updated_at IS NULL)")
            ->execute([$id]);
        $guard = is_admin_user($user) ? '' : ' AND created_by=' . (int)$user['id'];
        $db->prepare("UPDATE campaigns SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), finished_at=NULL WHERE id=?{$guard}")->execute([$id]);
        audit((int)$user['id'], 'start_campaign', ['id' => $id]);
        flash('Campanha iniciada.');
        redirect('?page=campaign&id=' . $id);
    }

    if ($action === 'pause_campaign') {
        $id = (int)post('id');
        if (!fetch_campaign_for_user($id, $user)) {
            flash('Campanha nao encontrada ou sem permissao.', 'error');
            redirect('?page=campaigns');
        }
        $guard = is_admin_user($user) ? '' : ' AND created_by=' . (int)$user['id'];
        $db->prepare("UPDATE campaigns SET status='paused' WHERE id=?{$guard}")->execute([$id]);
        audit((int)$user['id'], 'pause_campaign', ['id' => $id]);
        flash('Campanha pausada.');
        redirect('?page=campaign&id=' . $id);
    }

    if ($action === 'monitor_call') {
        Auth::requireAdmin();
        if (setting('monitoring_enabled', '0') !== '1' || post('consent') !== '1') {
            flash('Monitoramento exige habilitacao e confirmacao de consentimento.', 'error');
            redirect('?page=monitor');
        }
        try {
            $target = trim((string)post('target_channel'));
            $supervisor = trim((string)post('supervisor_channel'));
            $mode = (string)post('mode', 'listen');
            $ami = AmiClient::fromSettings();
            $response = $ami->monitorWithConsent($supervisor, $target, $mode);
            $ami->close();
            audit((int)$user['id'], 'monitor_call', ['target' => $target, 'mode' => $mode, 'response' => $response]);
            flash('Solicitacao de monitoramento enviada ao AMI.');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
        }
        redirect('?page=monitor');
    }
}

function normalize_agenda_contacts(string $raw): array
{
    $lines = preg_split('/\R+/', $raw) ?: [];
    $contacts = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $fields = str_contains($line, ';') ? str_getcsv($line, ';') : [$line];
        $fields = array_map(static fn(string $field): string => trim($field), $fields);
        $header = strtolower(implode(';', $fields));
        if (str_contains($header, 'telefone') || str_contains($header, 'numero')) {
            if (str_contains($header, 'cpf') || str_contains($header, 'nome')) {
                continue;
            }
        }

        $cpf = '';
        $name = '';
        $phone = '';
        if (count($fields) >= 3) {
            $cpf = preg_replace('/\D/', '', $fields[0]) ?: '';
            $name = preg_replace('/\s+/', ' ', $fields[1]) ?: '';
            $phone = $fields[2];
        } elseif (count($fields) === 2) {
            $name = preg_replace('/\s+/', ' ', $fields[0]) ?: '';
            $phone = $fields[1];
        } else {
            $phone = $fields[0];
        }

        $number = preg_replace('/[^\d+]/', '', $phone);
        if ($number !== '' && strlen(preg_replace('/\D/', '', $number)) >= 8) {
            $contacts[$number] = [
                'cpf' => $cpf,
                'name' => $name,
                'number' => $number,
            ];
        }
    }
    return array_values($contacts);
}

function normalize_numbers(string $raw): array
{
    $numbers = [];
    foreach (normalize_agenda_contacts($raw) as $contact) {
        $numbers[$contact['number']] = $contact['number'];
    }
    return array_values($numbers);
}

function is_mobile_number(string $number): bool
{
    $digits = preg_replace('/\D/', '', $number);
    if (str_starts_with($digits, '55')) {
        $digits = substr($digits, 2);
    }
    return strlen($digits) >= 11 && substr($digits, 2, 1) === '9';
}

function is_admin_user(?array $user = null): bool
{
    $user ??= Auth::user();
    return (string)($user['role'] ?? '') === 'admin';
}

function tenant_guard_sql(array $user, string $column): array
{
    if (is_admin_user($user)) {
        return ['', []];
    }
    return [" AND {$column}=?", [(int)$user['id']]];
}

function fetch_campaign(int $id): ?array
{
    $stmt = Database::conn()->prepare('SELECT c.*, s.channel_template, s.dial_context, s.caller_id AS sip_caller_id, e.extension, e.caller_id AS ext_caller_id FROM campaigns c JOIN sip_accounts s ON s.id = c.sip_account_id LEFT JOIN extensions e ON e.id = c.source_extension_id WHERE c.id = ?');
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
    return $campaign ?: null;
}

function fetch_campaign_for_user(int $id, array $user): ?array
{
    $campaign = fetch_campaign($id);
    if (!$campaign) {
        return null;
    }
    if (!is_admin_user($user) && (int)($campaign['created_by'] ?? 0) !== (int)$user['id']) {
        return null;
    }
    return $campaign;
}

function fetch_call_job_for_user(int $jobId, array $user): ?array
{
    $guard = is_admin_user($user) ? '' : ' AND c.created_by=' . (int)$user['id'];
    $stmt = Database::conn()->prepare("SELECT j.*, c.created_by, c.transfer_target FROM call_jobs j JOIN campaigns c ON c.id=j.campaign_id WHERE j.id=?{$guard} LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    return $job ?: null;
}

function require_campaign_for_user(int $id, array $user): array
{
    $campaign = fetch_campaign_for_user($id, $user);
    if (!$campaign) {
        json_response(['ok' => false, 'message' => 'Campanha nao encontrada ou sem permissao.'], 404);
    }
    return $campaign;
}

function reserve_campaign_jobs(int $campaignId, int $limit): array
{
    $db = Database::conn();
    $limit = max(1, min(200, $limit));
    $db->beginTransaction();
    try {
        $lock = Database::isMysql() ? ' FOR UPDATE' : '';
        $stmt = $db->prepare("SELECT id, number, status FROM call_jobs WHERE campaign_id=? AND status='pending' ORDER BY id LIMIT {$limit}{$lock}");
        $stmt->execute([$campaignId]);
        $jobs = $stmt->fetchAll();
        if ($jobs) {
            $ids = implode(',', array_map('intval', array_column($jobs, 'id')));
            $db->exec("UPDATE call_jobs SET status='calling', response='Reservada para discagem', updated_at=CURRENT_TIMESTAMP WHERE id IN ({$ids}) AND status='pending'");
            $db->exec("UPDATE auto_agenda_numbers SET status='Chamando' WHERE id IN (SELECT agenda_number_id FROM call_jobs WHERE id IN ({$ids}) AND agenda_number_id IS NOT NULL)");
            foreach ($jobs as &$job) {
                $job['status'] = 'calling';
            }
            unset($job);
        }
        $db->commit();
        return $jobs;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function run_campaign_batch(array $campaign, array $user): never
{
    $db = Database::conn();
    if (($campaign['dialer_mode'] ?? 'webrtc') !== 'ami') {
        json_response([
            'ok' => false,
            'message' => 'Esta campanha usa WebRTC/SIP e nao precisa de AMI. Abra a campanha e use Iniciar/Processar fila.',
            'stats' => campaign_stats((int)$campaign['id']),
        ]);
    }
    if ($campaign['status'] !== 'running') {
        json_response(['ok' => true, 'message' => 'Campanha parada.', 'stats' => campaign_stats((int)$campaign['id'])]);
    }

    $limit = max(1, (int)$campaign['simultaneous']);
    $jobs = reserve_campaign_jobs((int)$campaign['id'], $limit);
    $sent = 0;
    $errors = [];

    $transferTarget = preg_replace('/[^A-Za-z0-9_.+#*:-]/', '', (string)($campaign['transfer_target'] ?? ''));
    if ($transferTarget === '') {
        $owner = $db->prepare('SELECT queue_exten FROM users WHERE id=? LIMIT 1');
        $owner->execute([(int)$campaign['created_by']]);
        $transferTarget = preg_replace('/[^A-Za-z0-9_.+#*:-]/', '', (string)($owner->fetchColumn() ?: ''));
    }
    if ($transferTarget === '') {
        json_response(['ok' => false, 'message' => 'Conta sem fila propria configurada.'], 422);
    }

    foreach ($jobs as $job) {
        $number = preg_replace('/[^0-9+]/', '', (string)$job['number']);
        if ($number === '') {
            $db->prepare("UPDATE call_jobs SET status='error', attempts=attempts+1, response='Numero invalido', updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$job['id']]);
            continue;
        }
        $channel = 'Local/' . $number . '@from-webrtc';
        $callerId = outbound_caller_id((string)($campaign['caller_id'] ?: $campaign['ext_caller_id'] ?: $campaign['sip_caller_id'] ?: '')) ?: (string)$campaign['name'];
        try {
            $ami = AmiClient::fromSettings();
            $response = $ami->originate($channel, 'from-webrtc', $transferTarget, $callerId, 60000, [
                'CAMPAIGN_ID' => (string)$campaign['id'],
                'CALL_JOB_ID' => (string)$job['id'],
                'DISCADA_NUMERO' => $number,
                '__DISCADA_NUMERO' => $number,
                'CAMPAIGN_CALLERID' => $callerId,
                '__CAMPAIGN_CALLERID' => $callerId,
                'IVR_CALLERID' => $callerId,
                '__IVR_CALLERID' => $callerId,
                'TRANSFER_TARGET' => $transferTarget,
            ]);
            $ami->close();
            log_transfer_pending_history($transferTarget, $number, $callerId);
            $db->prepare("UPDATE call_jobs SET status='sent', attempts=attempts+1, response=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$response, $job['id']]);
            $sent++;
        } catch (Throwable $e) {
            $db->prepare("UPDATE call_jobs SET status='error', attempts=attempts+1, response=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$e->getMessage(), $job['id']]);
            $errors[] = $e->getMessage();
        }
    }

    $stats = campaign_stats((int)$campaign['id']);
    if (($stats['pending'] ?? 0) === 0) {
        $db->prepare("UPDATE campaigns SET status='finished', finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int)$campaign['id']]);
    }
    audit((int)$user['id'], 'run_campaign_batch', ['campaign' => $campaign['id'], 'sent' => $sent, 'errors' => $errors]);
    json_response(['ok' => true, 'sent' => $sent, 'errors' => $errors, 'stats' => campaign_stats((int)$campaign['id'])]);
}

function campaign_stats(int $campaignId): array
{
    $stmt = Database::conn()->prepare('SELECT status, COUNT(*) AS total FROM call_jobs WHERE campaign_id = ? GROUP BY status');
    $stmt->execute([$campaignId]);
    $stats = ['pending' => 0, 'calling' => 0, 'sent' => 0, 'answered' => 0, 'ended' => 0, 'nao_atendida' => 0, 'rejeitada' => 0, 'error' => 0, 'total' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['total'];
        $stats['total'] += (int)$row['total'];
    }
    return $stats;
}

function ivr_caller_id(array $ivr): string
{
    $callerId = outbound_caller_id((string)($ivr['caller_id'] ?? '')) ?: '';
    if ($callerId !== '') {
        return $callerId;
    }
    if (!empty($ivr['sip_account_id'])) {
        $stmt = Database::conn()->prepare("SELECT caller_id FROM sip_accounts WHERE id=? AND caller_id IS NOT NULL AND caller_id<>'' LIMIT 1");
        $stmt->execute([(int)$ivr['sip_account_id']]);
        $callerId = outbound_caller_id((string)($stmt->fetchColumn() ?: '')) ?: '';
        if ($callerId !== '') {
            return $callerId;
        }
    }
    $default = Database::conn()->query("SELECT caller_id FROM sip_accounts WHERE active=1 AND caller_id IS NOT NULL AND caller_id<>'' ORDER BY id LIMIT 1")->fetchColumn();
    return outbound_caller_id((string)($default ?: '')) ?: '';
}

function outbound_caller_id(string $value): string
{
    $digits = preg_replace('/[^0-9+]/', '', $value) ?: '';
    if (str_starts_with($digits, '+55')) {
        $local = substr($digits, 3);
        return strlen($local) >= 10 && strlen($local) <= 11 ? $local : $digits;
    }
    if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
        return substr($digits, 2);
    }
    return $digits;
}

function log_ivr_call_event(int $ivrId, string $mode, string $phone, string $callerId, string $status, string $message): void
{
    Database::conn()->prepare('INSERT INTO ivr_call_events(ivr_id, mode, phone, caller_id, status, message) VALUES(?,?,?,?,?,?)')
        ->execute([$ivrId ?: null, $mode, $phone, $callerId, $status, $message]);
}

function import_remote_ivr_digit_events(int $ivrId): void
{
    $output = asterisk_remote_shell('touch /var/log/asterisk/discadora_ivr_digits.log && tail -200 /var/log/asterisk/discadora_ivr_digits.log', 8);
    if (trim($output) === '') {
        return;
    }
    $db = Database::conn();
    $exists = $db->prepare('SELECT COUNT(*) FROM ivr_digit_events WHERE ivr_id=? AND call_id=? AND phone=? AND digit=?');
    $optionStmt = $db->prepare('SELECT id, destination FROM ivr_options WHERE ivr_id=? AND digit=? AND active=1 LIMIT 1');
    $insert = $db->prepare('INSERT INTO ivr_digit_events(ivr_id, call_id, phone, digit, matched_option_id, destination, created_at) VALUES(?,?,?,?,?,?,?)');
    foreach (preg_split('/\R/', trim($output)) as $line) {
        $parts = explode('|', trim($line));
        if (count($parts) < 5 || (int)$parts[1] !== $ivrId) {
            continue;
        }
        [$createdAt, $remoteIvrId, $callId, $phone, $digit] = array_pad($parts, 5, '');
        $digit = preg_replace('/[^0-9*#]/', '', (string)$digit);
        $phone = display_call_number((string)$phone);
        if ($digit === '') {
            continue;
        }
        $exists->execute([$ivrId, $callId, $phone, $digit]);
        if ((int)$exists->fetchColumn() > 0) {
            continue;
        }
        $optionId = null;
        $destination = '';
        $optionStmt->execute([$ivrId, $digit]);
        $option = $optionStmt->fetch();
        if ($option) {
            $optionId = (int)$option['id'];
            $destination = (string)$option['destination'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$createdAt)) {
            $createdAt = date('Y-m-d H:i:s');
        }
        $insert->execute([$ivrId, $callId, $phone, $digit, $optionId, $destination, $createdAt]);
    }
}

function originate_ivr_call(AmiClient $ami, array $ivr, string $safeNumber, string $callerId): string
{
    return $ami->originate(
        'Local/' . $safeNumber . '@discadora-ivr-disparo-' . (int)$ivr['id'],
        'discadora-ivr-disparo-' . (int)$ivr['id'],
        $safeNumber,
        $callerId ?: (string)$ivr['name'],
        60000,
        [
            'IVR_ID' => (string)$ivr['id'],
            'IVR_PHONE' => $safeNumber,
            'IVR_CALLERID' => $callerId,
            '__IVR_ID' => (string)$ivr['id'],
            '__IVR_PHONE' => $safeNumber,
            '__IVR_CALLERID' => $callerId,
        ]
    );
}

function advance_ivr_queue(int $ivrId): void
{
    if (setting('ivr_run_active_' . $ivrId, '0') !== '1') {
        return;
    }
    $db = Database::conn();
    $stmt = $db->prepare('SELECT * FROM ivrs WHERE id=? LIMIT 1');
    $stmt->execute([$ivrId]);
    $ivr = $stmt->fetch();
    if (!$ivr) {
        save_setting('ivr_run_active_' . $ivrId, '0');
        return;
    }
    $agendaId = (int)setting('ivr_run_agenda_' . $ivrId, (string)($ivr['agenda_id'] ?? 0));
    if ($agendaId <= 0) {
        save_setting('ivr_run_active_' . $ivrId, '0');
        return;
    }
    $limit = max(1, min(200, (int)setting('ivr_run_limit_' . $ivrId, (string)($ivr['simultaneous'] ?? 1))));

    $activeRowsStmt = $db->prepare("SELECT phone, MAX(created_at) AS created_at FROM ivr_call_events WHERE ivr_id=? AND status IN ('Enviando','Chamando') GROUP BY phone");
    $activeRowsStmt->execute([$ivrId]);
    $activeRows = $activeRowsStmt->fetchAll();

    $asteriskChannels = '';
    if ($activeRows) {
        try {
            $asteriskChannels = asterisk_remote_cli('core show channels concise', 6);
        } catch (Throwable) {
            return;
        }
    }

    $activeCount = 0;
    foreach ($activeRows as $row) {
        $phone = preg_replace('/[^0-9+]/', '', (string)$row['phone']);
        if ($phone === '') {
            continue;
        }
        $age = time() - strtotime((string)$row['created_at']);
        $stillActive = $asteriskChannels !== '' && str_contains($asteriskChannels, $phone);
        if ($stillActive || $age < 8) {
            $activeCount++;
            continue;
        }
        $db->prepare("UPDATE ivr_call_events SET status='Finalizado', message='Chamada encerrada no Asterisk' WHERE ivr_id=? AND phone=? AND status IN ('Enviando','Chamando')")
            ->execute([$ivrId, $phone]);
        $db->prepare("UPDATE auto_agenda_numbers SET status='Finalizado' WHERE agenda_id=? AND number=? AND status='Chamando'")
            ->execute([$agendaId, $phone]);
    }

    $slots = max(0, $limit - $activeCount);
    if ($slots <= 0) {
        return;
    }

    $nextStmt = $db->prepare("SELECT id, number FROM auto_agenda_numbers WHERE agenda_id=? AND number<>'' AND status NOT IN ('Atendido','Finalizado','Chamando') ORDER BY id LIMIT {$slots}");
    $nextStmt->execute([$agendaId]);
    $nextRows = $nextStmt->fetchAll();
    if (!$nextRows) {
        if ($activeCount === 0) {
            save_setting('ivr_run_active_' . $ivrId, '0');
        }
        return;
    }

    $callerId = ivr_caller_id($ivr);
    $ami = AmiClient::fromSettings();
    try {
        foreach ($nextRows as $row) {
            $safeNumber = preg_replace('/[^0-9+]/', '', (string)$row['number']);
            if ($safeNumber === '') {
                continue;
            }
            log_ivr_call_event($ivrId, (string)$ivr['mode'], $safeNumber, $callerId, 'Enviando', 'Vaga liberada; enviando proximo numero da agenda');
            $response = originate_ivr_call($ami, $ivr, $safeNumber, $callerId);
            log_ivr_call_event($ivrId, (string)$ivr['mode'], $safeNumber, $callerId, 'Chamando', trim($response) ?: 'Asterisk aceitou a solicitacao');
            $db->prepare("UPDATE auto_agenda_numbers SET status='Chamando' WHERE id=? AND status NOT IN ('Atendido','Finalizado')")
                ->execute([(int)$row['id']]);
        }
    } finally {
        $ami->close();
    }
}

function campaign_job_status_label(string $status): string
{
    return [
        'pending' => 'Pendente',
        'calling' => 'Chamando',
        'sent' => 'Enviada',
        'answered' => 'Atendida',
        'ended' => 'Finalizada',
        'nao_atendida' => 'Nao atendida',
        'rejeitada' => 'Rejeitada',
        'error' => 'Erro',
    ][$status] ?? $status;
}

function agenda_status_for_job_status(string $status): ?string
{
    return [
        'pending' => 'Pendente',
        'calling' => 'Chamando',
        'answered' => 'Atendido',
        'ended' => 'Atendido',
        'nao_atendida' => 'Nao atendido',
        'rejeitada' => 'Rejeitada',
        'error' => 'Erro',
    ][$status] ?? null;
}

function sync_agenda_number_from_job(int $jobId): void
{
    $stmt = Database::conn()->prepare('SELECT agenda_number_id, status FROM call_jobs WHERE id=?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    $agendaNumberId = (int)($job['agenda_number_id'] ?? 0);
    $agendaStatus = agenda_status_for_job_status((string)($job['status'] ?? ''));
    if ($agendaNumberId <= 0 || $agendaStatus === null) {
        return;
    }
    Database::conn()->prepare('UPDATE auto_agenda_numbers SET status=? WHERE id=?')->execute([$agendaStatus, $agendaNumberId]);
}

function user_can_spend(int $userId): bool
{
    $stmt = Database::conn()->prepare('SELECT role, active, credit_balance FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['active'] !== 1) {
        return false;
    }
    if ((string)$user['role'] === 'admin') {
        return true;
    }
    return (float)$user['credit_balance'] > 0;
}

function valid_webrtc_websocket_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    return (bool)preg_match('~^wss://[^\\s/]+(?::\\d+)?/ws/?$~i', $url);
}

function webrtc_account_config(int $accountId, ?array $user = null): ?array
{
    $db = Database::conn();
    $user ??= Auth::user() ?: [];
    if ($accountId > 0) {
        if (is_admin_user($user)) {
            $stmt = $db->prepare("SELECT * FROM sip_accounts WHERE id=? AND active=1 AND webrtc_enabled=1 AND websocket_url LIKE 'wss://%' AND websocket_url <> ''");
            $stmt->execute([$accountId]);
        } else {
            $stmt = $db->prepare("SELECT DISTINCT s.* FROM sip_accounts s JOIN extensions e ON e.sip_account_id=s.id WHERE s.id=? AND s.active=1 AND s.webrtc_enabled=1 AND s.websocket_url LIKE 'wss://%' AND s.websocket_url <> '' AND e.active=1 AND e.user_id=? LIMIT 1");
            $stmt->execute([$accountId, (int)$user['id']]);
        }
    } else {
        if (is_admin_user($user)) {
            $stmt = $db->query("SELECT * FROM sip_accounts WHERE active=1 AND webrtc_enabled=1 AND websocket_url LIKE 'wss://%' AND websocket_url <> '' ORDER BY id LIMIT 1");
        } else {
            $stmt = $db->prepare("SELECT DISTINCT s.* FROM sip_accounts s JOIN extensions e ON e.sip_account_id=s.id WHERE s.active=1 AND s.webrtc_enabled=1 AND s.websocket_url LIKE 'wss://%' AND s.websocket_url <> '' AND e.active=1 AND e.user_id=? ORDER BY s.id LIMIT 1");
            $stmt->execute([(int)$user['id']]);
        }
    }
    $account = $stmt->fetch();
    if (!$account) {
        return null;
    }
    $domain = $account['domain'] ?: $account['sip_server'];
    $authUser = $account['auth_user'] ?: $account['username'];
    return [
        'id' => (int)$account['id'],
        'name' => $account['name'],
        'uri' => 'sip:' . $account['username'] . '@' . $domain,
        'authorizationUsername' => $authUser,
        'authorizationPassword' => $account['password'],
        'displayName' => $account['display_name'] ?: $account['label'] ?: $account['username'],
        'callerId' => trim((string)$account['caller_id']),
        'websocketUrl' => $account['websocket_url'],
        'iceServers' => parse_ice_servers((string)$account['ice_servers']),
        'registerExpires' => (int)$account['register_expires'],
        'dtmfType' => $account['dtmf_type'] ?: 'rtp',
    ];
}

function extension_webrtc_config(string $extension, string $password): ?array
{
    $row = extension_auth_row($extension, $password);
    if (!$row) {
        return null;
    }
    $domain = $row['domain'] ?: $row['sip_server'];
    return [
        'id' => (int)$row['sip_account_id'],
        'name' => $row['name'],
        'uri' => 'sip:' . $row['extension'] . '@' . $domain,
        'authorizationUsername' => $row['extension'],
        'authorizationPassword' => $row['secret'],
        'displayName' => $row['name'] ?: $row['extension'],
        'callerId' => trim((string)($row['caller_id'] ?: $row['sip_caller_id'])),
        'websocketUrl' => $row['websocket_url'],
        'iceServers' => parse_ice_servers((string)$row['ice_servers']),
        'registerExpires' => (int)$row['register_expires'],
        'dtmfType' => $row['dtmf_type'] ?: 'rtp',
    ];
}

function parse_ice_servers(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }
    if ($value[0] === '[' || $value[0] === '{') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return sanitize_ice_servers(array_is_list($decoded) ? $decoded : [$decoded]);
        }
    }
    return sanitize_ice_servers(array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $value)))));
}

function sanitize_ice_servers(array $servers): array
{
    $clean = [];
    foreach ($servers as $server) {
        if (is_string($server)) {
            $url = trim($server);
            if ($url === '') {
                continue;
            }
            if (preg_match('~^turns?:~i', $url)) {
                continue;
            }
            $clean[] = $url;
            continue;
        }
        if (!is_array($server)) {
            continue;
        }
        $urls = $server['urls'] ?? null;
        $urlList = is_array($urls) ? $urls : [$urls];
        $urlList = array_values(array_filter(array_map('strval', $urlList)));
        if (!$urlList) {
            continue;
        }
        $hasTurn = false;
        foreach ($urlList as $url) {
            if (preg_match('~^turns?:~i', trim($url))) {
                $hasTurn = true;
                break;
            }
        }
        if ($hasTurn && ((string)($server['username'] ?? '') === '' || (string)($server['credential'] ?? '') === '')) {
            continue;
        }
        $server['urls'] = is_array($urls) ? $urlList : $urlList[0];
        $clean[] = $server;
    }
    return $clean;
}

function extension_auth_row(string $extension, string $password): ?array
{
    if ($extension === '' || $password === '') {
        return null;
    }
    $stmt = Database::conn()->prepare("SELECT e.*, s.name AS account_name, s.domain, s.sip_server, s.websocket_url, s.ice_servers, s.register_expires, s.dtmf_type, s.caller_id AS sip_caller_id FROM extensions e JOIN sip_accounts s ON s.id=e.sip_account_id WHERE e.extension=? AND e.secret=? AND e.active=1 AND s.active=1 AND s.webrtc_enabled=1 AND s.websocket_url LIKE 'wss://%' AND s.websocket_url <> '' LIMIT 1");
    $stmt->execute([$extension, $password]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function extension_online_expr(): string
{
    return Database::isMysql()
        ? 'online_last_seen IS NOT NULL AND online_last_seen >= DATE_SUB(NOW(), INTERVAL 20 SECOND)'
        : "online_last_seen IS NOT NULL AND online_last_seen >= datetime('now', '-20 seconds')";
}

function transfer_targets(): array
{
    $user = Auth::user();
    $db = Database::conn();
    if ($user && ($user['role'] ?? '') !== 'admin') {
        $stmt = $db->prepare("SELECT extension, name, " . extension_online_expr() . " AS online FROM extensions WHERE active=1 AND user_id=? ORDER BY extension");
        $stmt->execute([(int)$user['id']]);
        $rows = $stmt->fetchAll();
        $queueExten = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($user['queue_exten'] ?? '')) ?: default_queue_exten_for_local((int)$user['id']);
        $queueStmt = $db->prepare('SELECT name, queue_number FROM call_queues WHERE active=1 AND user_id=? ORDER BY name');
        $queueStmt->execute([(int)$user['id']]);
        $queueRows = $queueStmt->fetchAll();
    } else {
        $rows = $db->query("SELECT extension, name, " . extension_online_expr() . " AS online FROM extensions WHERE active=1 ORDER BY extension")->fetchAll();
        $queueExten = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)setting('asterisk_sync_queue_exten', '700')) ?: '700';
        $queueRows = $db->query('SELECT q.name, q.queue_number, u.name AS owner_name FROM call_queues q LEFT JOIN users u ON u.id=q.user_id WHERE q.active=1 ORDER BY u.name, q.name')->fetchAll();
    }
    $targets = [[
        'value' => $queueExten,
        'label' => $queueExten . ' - Fila do usuario',
        'online' => true,
    ]];
    foreach ($queueRows as $queue) {
        $label = trim((string)$queue['queue_number'] . ' - ' . (string)$queue['name']);
        if (!empty($queue['owner_name'])) {
            $label .= ' (' . (string)$queue['owner_name'] . ')';
        }
        $targets[] = [
            'value' => (string)$queue['queue_number'],
            'label' => $label,
            'online' => true,
        ];
    }
    foreach ($rows as $row) {
        $targets[] = [
        'value' => (string)$row['extension'],
        'label' => trim((string)$row['extension'] . ' - ' . (string)$row['name']),
        'online' => (bool)$row['online'],
        ];
    }
    return $targets;
}

function server_side_transfer(string $source, string $target): array
{
    $source = preg_replace('/[^A-Za-z0-9_.-]/', '', $source);
    $target = preg_replace('/[^A-Za-z0-9_.+#*:-]/', '', $target);
    $requestedDialedNumber = preg_replace('/[^0-9+]/', '', (string)post('dialed_number'));
    $requestedCallerId = preg_replace('/[^0-9+]/', '', (string)post('caller_id'));
    if ($source === '' || $target === '') {
        throw new RuntimeException('Origem ou destino de transferencia invalido.');
    }

    $raw = asterisk_remote_cli('core show channels concise');
    $channelRows = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        if ($line === '' || !str_contains($line, '!')) {
            continue;
        }
        $parts = array_pad(explode('!', $line), 14, '');
        $channelRows[(string)$parts[0]] = $parts;
    }
    $candidates = [];
    foreach ($channelRows as $parts) {
        $channel = (string)$parts[0];
        $duration = parse_channel_duration((string)$parts[10]);
        $bridged = bridged_channel_from_concise_parts($parts, $channel);
        $sourceMatches = str_starts_with($channel, 'PJSIP/' . $source . '-');
        $fallbackMatches = !$sourceMatches
            && !$candidates
            && str_starts_with($channel, 'PJSIP/')
            && ((string)$parts[1] === 'from-webrtc')
            && preg_match('/^PJSIP\/[A-Za-z0-9_.-]+-\d+$/', $channel);
        if ((!$sourceMatches && !$fallbackMatches) || $bridged === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_.\/@:-]+$/', $bridged)) {
            continue;
        }
        $bridgedParts = $channelRows[$bridged] ?? [];
        $callerId = preg_replace('/[^0-9+]/', '', (string)$parts[7]) ?: '';
        $dialedNumber = extract_dialed_number_from_dial_data((string)$parts[6])
            ?: extract_dialed_number_from_dial_data((string)($bridgedParts[6] ?? ''))
            ?: transfer_phone_candidate((string)($bridgedParts[2] ?? ''), $source, $target, $callerId)
            ?: transfer_phone_candidate((string)($bridgedParts[7] ?? ''), $source, $target, $callerId)
            ?: transfer_phone_candidate((string)$parts[2], $source, $target, $callerId)
            ?: '';
        $candidates[] = [
            'source_channel' => $channel,
            'external_channel' => $bridged,
            'dialed_number' => $dialedNumber,
            'caller_id' => $callerId,
            'duration_seconds' => $duration,
        ];
    }

    if (!$candidates) {
        $candidates = bridge_transfer_candidates($source, $target);
    }

    if (!$candidates) {
        throw new RuntimeException('Nao encontrei chamada ativa deste ramal para transferir.');
    }
    usort($candidates, static fn(array $a, array $b): int => $b['duration_seconds'] <=> $a['duration_seconds']);
    $chosen = $candidates[0];
    if ($requestedDialedNumber !== '') {
        $chosen['dialed_number'] = $requestedDialedNumber;
    }
    if ($requestedCallerId !== '') {
        $chosen['caller_id'] = $requestedCallerId;
    }
    if (empty($chosen['caller_id'])) {
        $chosen['caller_id'] = caller_id_for_transfer_source($source);
    }
    $stmt = Database::conn()->prepare('SELECT e.user_id, u.queue_exten FROM extensions e LEFT JOIN users u ON u.id=e.user_id WHERE e.extension=? AND e.active=1 LIMIT 1');
    $stmt->execute([$target]);
    $targetRow = $stmt->fetch() ?: null;
    $targetIsExtension = (bool)$targetRow;
    $queueExten = $targetRow ? preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($targetRow['queue_exten'] ?? '')) : '';
    if ($targetIsExtension && $queueExten === '') {
        throw new RuntimeException('O ramal selecionado nao possui fila propria configurada para a conta.');
    }
    $redirectTarget = $targetIsExtension ? $queueExten : $target;
    $queueOwnerId = $targetRow ? (int)($targetRow['user_id'] ?? 0) : 0;
    $callQueueId = 0;
    if (!$targetIsExtension) {
        $stmt = Database::conn()->prepare('SELECT id, queue_exten FROM users WHERE queue_exten=? LIMIT 1');
        $stmt->execute([$target]);
        $queueOwner = $stmt->fetch() ?: null;
        if ($queueOwner) {
            $queueOwnerId = (int)$queueOwner['id'];
            $redirectTarget = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$queueOwner['queue_exten']) ?: $target;
        } else {
            $stmt = Database::conn()->prepare('SELECT id, user_id, queue_number FROM call_queues WHERE queue_number=? AND active=1 LIMIT 1');
            $stmt->execute([$target]);
            $queueOwner = $stmt->fetch() ?: null;
            if ($queueOwner) {
                $queueOwnerId = (int)$queueOwner['user_id'];
                $redirectTarget = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$queueOwner['queue_number']) ?: $target;
                $callQueueId = (int)($queueOwner['id'] ?? 0);
            }
        }
        if (!$queueOwner && $target === (string)setting('asterisk_sync_queue_exten', '700')) {
            throw new RuntimeException('Fila legada 700 sem dono isolado. Configure e selecione a fila propria da conta.');
        }
    }
    if ($queueOwnerId > 0) {
        if ($callQueueId > 0) {
            $stmt = Database::conn()->prepare("SELECT COUNT(*) FROM call_queue_extensions qe JOIN extensions e ON e.id=qe.extension_id WHERE qe.queue_id=? AND e.active=1 AND e.user_id=? AND " . extension_online_expr());
            $stmt->execute([$callQueueId, $queueOwnerId]);
        } else {
            $stmt = Database::conn()->prepare("SELECT COUNT(*) FROM extensions WHERE active=1 AND user_id=? AND " . extension_online_expr());
            $stmt->execute([$queueOwnerId]);
        }
        if ((int)$stmt->fetchColumn() <= 0) {
            throw new RuntimeException('Nenhum ramal online encontrado na fila selecionada.');
        }
    }

    $response = transfer_redirect_and_release($chosen['external_channel'], $redirectTarget, $chosen['source_channel']);
    if (stripos($response, 'failed') !== false || stripos($response, 'not found') !== false || stripos($response, 'no such') !== false) {
        throw new RuntimeException($response ?: 'Falha ao jogar chamada na fila de transferencia.');
    }
    $releaseResponse = $response;
    $historyWarning = null;
    try {
        log_transfer_pending_history($target, (string)($chosen['dialed_number'] ?? ''), (string)($chosen['caller_id'] ?? ''));
    } catch (Throwable $e) {
        $historyWarning = 'Transferencia feita, mas o historico aguardara a atualizacao do atendente: ' . $e->getMessage();
    }
    $mode = $targetIsExtension ? 'queue' : 'redirect';

    return [
        'sourceChannel' => $chosen['source_channel'],
        'externalChannel' => $chosen['external_channel'],
        'callerNumber' => $chosen['dialed_number'] ?? '',
        'callerId' => $chosen['caller_id'] ?? '',
        'target' => $target,
        'redirectTarget' => $redirectTarget,
        'mode' => $mode,
        'agentChannel' => $agentChannel ?? null,
        'bridgeId' => $bridgeId ?? null,
        'releaseResponse' => $releaseResponse ?? null,
        'historyWarning' => $historyWarning,
        'response' => $response,
    ];
}

function transfer_redirect_and_release(string $externalChannel, string $redirectTarget, string $sourceChannel): string
{
    foreach ([$externalChannel, $sourceChannel] as $channel) {
        if (!preg_match('/^[A-Za-z0-9_.\/@:-]+$/', $channel)) {
            throw new RuntimeException('Canal invalido para transferencia.');
        }
    }
    if (!preg_match('/^[A-Za-z0-9_.+#*:-]+$/', $redirectTarget)) {
        throw new RuntimeException('Destino invalido para transferencia.');
    }

    $script = "set -e\n"
        . "asterisk -rx " . escapeshellarg('channel redirect ' . $externalChannel . ' from-webrtc,' . $redirectTarget . ',1') . "\n"
        . "asterisk -rx " . escapeshellarg('channel request hangup ' . $sourceChannel) . "\n";
    return asterisk_remote_shell($script, 8);
}

function log_transfer_pending_history(string $target, string $dialedNumber, string $callerId = ''): void
{
    $db = Database::conn();
    $extensions = [];
    $stmt = $db->prepare('SELECT extension FROM extensions WHERE extension=? AND active=1 LIMIT 1');
    $stmt->execute([$target]);
    $selected = (string)($stmt->fetchColumn() ?: '');
    if ($selected !== '') {
        $extensions[] = $selected;
    } else {
        $owner = $db->prepare('SELECT id FROM users WHERE queue_exten=? LIMIT 1');
        $owner->execute([$target]);
        $ownerId = (int)($owner->fetchColumn() ?: 0);
        if ($ownerId > 0) {
            $stmt = $db->prepare("SELECT extension FROM extensions WHERE user_id=? AND active=1 AND " . extension_online_expr() . " ORDER BY extension");
            $stmt->execute([$ownerId]);
            foreach ($stmt->fetchAll() as $row) {
                if (!empty($row['extension'])) {
                    $extensions[] = (string)$row['extension'];
                }
            }
        } else {
            $queue = $db->prepare('SELECT id, user_id FROM call_queues WHERE queue_number=? AND active=1 LIMIT 1');
            $queue->execute([$target]);
            $queueRow = $queue->fetch() ?: null;
            if ($queueRow) {
                $stmt = $db->prepare("SELECT e.extension FROM call_queue_extensions qe JOIN extensions e ON e.id=qe.extension_id WHERE qe.queue_id=? AND e.user_id=? AND e.active=1 AND " . extension_online_expr() . " ORDER BY e.extension");
                $stmt->execute([(int)$queueRow['id'], (int)$queueRow['user_id']]);
                foreach ($stmt->fetchAll() as $row) {
                    if (!empty($row['extension'])) {
                        $extensions[] = (string)$row['extension'];
                    }
                }
            }
        }
    }

    $number = preg_replace('/[^0-9+]/', '', $dialedNumber) ?: 'desconhecido';
    $callerId = preg_replace('/[^0-9+]/', '', $callerId);
    if ($number === $target) {
        $number = 'desconhecido';
    }
    $extensions = array_unique($extensions);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        try {
            foreach ($extensions as $extension) {
                $existing = $db->prepare("SELECT id FROM agent_call_logs WHERE extension=? AND number=? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1");
                $existing->execute([$extension, $number]);
                if ((int)($existing->fetchColumn() ?: 0) > 0) {
                    continue;
                }
                $ownerStmt = $db->prepare('SELECT user_id FROM extensions WHERE extension=? LIMIT 1');
                $ownerStmt->execute([$extension]);
                $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
                $profile = billing_profile_for_user($ownerId);
                $insert = $db->prepare('INSERT INTO agent_call_logs(extension, number, caller_id, status, details, user_id, magnus_rate_id, magnus_rate_value, billing_initial_block, billing_increment) VALUES(?,?,?,?,?,?,?,?,?,?)');
                $insert->execute([$extension, $number, $callerId, 'Pendente', 'Transferencia enviada para fila', $ownerId, $profile['rate_id'], $profile['rate_value'], $profile['initial_block'], $profile['increment']]);
            }
            return;
        } catch (PDOException $e) {
            if (!is_sqlite_locked_error($e) || $attempt === 5) {
                throw $e;
            }
            usleep(250000 * ($attempt + 1));
        }
    }
}

function is_sqlite_locked_error(Throwable $e): bool
{
    $message = strtolower($e->getMessage());
    return str_contains($message, 'database is locked') || str_contains($message, 'database table is locked');
}

function extract_dialed_number_from_dial_data(string $data): string
{
    if (preg_match('/PJSIP\/([0-9+]+)@/i', $data, $match)) {
        return preg_replace('/[^0-9+]/', '', $match[1]);
    }
    if (preg_match('/(?:^|[,(])([0-9+]{8,})@/i', $data, $match)) {
        return preg_replace('/[^0-9+]/', '', $match[1]);
    }
    return '';
}

function transfer_phone_candidate(string $value, string $source, string $target, string $callerId = ''): string
{
    $number = preg_replace('/[^0-9+]/', '', $value);
    if ($number === '' || $number === $source || $number === $target || $number === $callerId || strlen($number) < 8) {
        return '';
    }
    return $number;
}

function caller_id_for_transfer_source(string $source): string
{
    $stmt = Database::conn()->prepare("SELECT caller_id FROM sip_accounts WHERE active=1 AND (username=? OR auth_user=?) AND caller_id IS NOT NULL AND caller_id <> '' ORDER BY id LIMIT 1");
    $stmt->execute([$source, $source]);
    $callerId = preg_replace('/[^0-9+]/', '', (string)($stmt->fetchColumn() ?: ''));
    if ($callerId !== '') {
        return $callerId;
    }
    $stmt = Database::conn()->prepare("SELECT COALESCE(e.caller_id, s.caller_id, '') FROM extensions e LEFT JOIN sip_accounts s ON s.id=e.sip_account_id WHERE e.extension=? AND e.active=1 LIMIT 1");
    $stmt->execute([$source]);
    return preg_replace('/[^0-9+]/', '', (string)($stmt->fetchColumn() ?: ''));
}

function bridged_channel_from_concise_parts(array $parts, string $sourceChannel): string
{
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value === '' || $value === $sourceChannel) {
            continue;
        }
        if (preg_match('/((?:PJSIP|SIP|DAHDI|IAX2)\/[A-Za-z0-9_.@:-]+-[A-Za-z0-9]+|Local\/[A-Za-z0-9_.@:-]+;[12])/', $value, $match)) {
            return $match[1];
        }
    }
    return '';
}

function bridge_transfer_candidates(string $source, string $target): array
{
    $candidates = [];
    foreach (asterisk_bridge_ids() as $bridgeId) {
        $details = asterisk_remote_cli('bridge show ' . $bridgeId, 8);
        preg_match_all('/\b(PJSIP\/[A-Za-z0-9_.@:-]+-[A-Za-z0-9]+)\b/', $details, $matches);
        $channels = array_values(array_unique($matches[1] ?? []));
        if (!$channels) {
            continue;
        }

        $sourceChannel = '';
        foreach ($channels as $channel) {
            if (str_starts_with($channel, 'PJSIP/' . $source . '-')) {
                $sourceChannel = $channel;
                break;
            }
        }
        if ($sourceChannel === '') {
            foreach ($channels as $channel) {
                if (preg_match('/^PJSIP\/[0-9]+-[A-Za-z0-9]+$/', $channel)) {
                    $sourceChannel = $channel;
                    break;
                }
            }
        }
        if ($sourceChannel === '') {
            continue;
        }

        $externalChannel = '';
        foreach ($channels as $channel) {
            if ($channel !== $sourceChannel && str_starts_with($channel, 'PJSIP/magnus-')) {
                $externalChannel = $channel;
                break;
            }
        }
        if ($externalChannel === '') {
            foreach ($channels as $channel) {
                if ($channel !== $sourceChannel) {
                    $externalChannel = $channel;
                    break;
                }
            }
        }
        if ($externalChannel === '') {
            continue;
        }

        $candidates[] = [
            'source_channel' => $sourceChannel,
            'external_channel' => $externalChannel,
            'dialed_number' => preg_replace('/[^0-9+]/', '', $target) ?: $target,
            'caller_id' => '',
            'duration_seconds' => 0,
        ];
    }
    return $candidates;
}

function create_bridge_call_file(string $target, string $externalChannel, string $callerNumber): string
{
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $target) || !preg_match('/^[A-Za-z0-9_.\/@:-]+$/', $externalChannel)) {
        throw new RuntimeException('Dados invalidos para transferencia.');
    }
    $callerNumber = preg_replace('/[^0-9+]/', '', $callerNumber) ?: $target;
    $id = bin2hex(random_bytes(8));
    $content = implode("\n", [
        'Channel: PJSIP/' . $target,
        'CallerID: "' . $callerNumber . '" <' . $callerNumber . '>',
        'MaxRetries: 0',
        'RetryTime: 5',
        'WaitTime: 35',
        'Application: BridgeAdd',
        'Data: ' . $externalChannel,
        '',
    ]);
    $remoteTmp = '/tmp/codex_transfer_' . $id . '.call';
    $remoteOut = '/var/spool/asterisk/outgoing/codex_transfer_' . $id . '.call';
    $encoded = base64_encode($content);
    $script = "set -e\n"
        . "echo " . escapeshellarg($encoded) . " | base64 -d > " . escapeshellarg($remoteTmp) . "\n"
        . "chown asterisk:asterisk " . escapeshellarg($remoteTmp) . "\n"
        . "chmod 0640 " . escapeshellarg($remoteTmp) . "\n"
        . "mv " . escapeshellarg($remoteTmp) . " " . escapeshellarg($remoteOut) . "\n"
        . "echo queued:" . $id . "\n";
    return asterisk_remote_shell($script, 8);
}

function wait_for_bridgeadd_agent(string $target, int $timeoutSeconds): string
{
    $deadline = time() + $timeoutSeconds;
    do {
        $raw = asterisk_remote_cli('core show channels concise', 8);
        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            if ($line === '' || !str_contains($line, '!')) {
                continue;
            }
            $parts = array_pad(explode('!', $line), 12, '');
            $channel = (string)$parts[0];
            $state = strtolower((string)$parts[4]);
            $bridged = (string)$parts[11];
            if (str_starts_with($channel, 'PJSIP/' . $target . '-') && $state === 'up' && $bridged !== '') {
                return $channel;
            }
        }
        usleep(500000);
    } while (time() < $deadline);

    throw new RuntimeException('O atendente nao atendeu a transferencia dentro do tempo.');
}

function find_bridge_with_channels(array $channels, int $timeoutSeconds): ?string
{
    $channels = array_values(array_filter(array_map('trim', $channels)));
    if (!$channels) {
        throw new RuntimeException('Nao encontrei canais para validar a ponte da transferencia.');
    }

    $deadline = time() + $timeoutSeconds;
    do {
        foreach (asterisk_bridge_ids() as $bridgeId) {
            $details = asterisk_remote_cli('bridge show ' . $bridgeId, 8);
            $found = true;
            foreach ($channels as $channel) {
                if (!str_contains($details, $channel)) {
                    $found = false;
                    break;
                }
            }
            if ($found) {
                return $bridgeId;
            }
        }
        usleep(300000);
    } while (time() < $deadline);

    return null;
}

function asterisk_bridge_ids(): array
{
    $raw = asterisk_remote_cli('bridge show all', 8);
    $ids = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'Bridge-ID') || str_starts_with($line, 'Warning:')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        $id = (string)($parts[0] ?? '');
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function release_source_from_bridge(?string $bridgeId, string $sourceChannel): string
{
    if (!preg_match('/^[A-Za-z0-9_.\/@:-]+$/', $sourceChannel)) {
        throw new RuntimeException('Dados invalidos para liberar discador da ponte.');
    }
    if ($bridgeId === null || !preg_match('/^[A-Za-z0-9_.:-]+$/', $bridgeId)) {
        return asterisk_remote_cli('channel request hangup ' . $sourceChannel, 8);
    }

    $response = asterisk_remote_cli('bridge kick ' . $bridgeId . ' ' . $sourceChannel, 8);
    if (stripos($response, 'failed') !== false || stripos($response, 'not found') !== false || stripos($response, 'no such') !== false) {
        $fallback = asterisk_remote_cli('channel request hangup ' . $sourceChannel, 8);
        return trim($response . "\n" . $fallback);
    }
    return $response;
}

function online_calls_snapshot(): array
{
    if (setting('online_calls_ssh_enabled', '1') !== '1') {
        return empty_online_calls_snapshot('Consulta SSH desativada para evitar travamento do sistema.');
    }

    $raw = asterisk_remote_cli('core show channels concise', 4);
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '!')) {
            continue;
        }
        $parts = array_pad(explode('!', $line), 14, '');
        $channel = $parts[0];
        if ($channel === '' || str_starts_with($channel, 'Privilege:') || str_starts_with($channel, 'Warning:')) {
            continue;
        }
        $durationSeconds = parse_online_concise_duration($parts);
        $duration = format_channel_duration((string)$durationSeconds);
        $app = (string)$parts[5];
        $state = (string)$parts[4];
        $data = (string)$parts[6];
        $bridged = bridged_channel_from_concise_parts($parts, (string)$channel);
        $type = classify_online_call($state, $app, $data);
        $answered = $type === 'active';
        $rows[] = [
            'id' => sha1($line),
            'type' => $type,
            'status' => online_call_status_label($type, $state, $app),
            'channel' => $channel,
            'context' => (string)$parts[1],
            'extension' => (string)$parts[2],
            'state' => $state,
            'application' => $app,
            'data' => $data,
            'callerid' => (string)$parts[7],
            'duration' => $duration,
            'duration_seconds' => $answered ? $durationSeconds : 0,
            'wait_seconds' => $answered ? 0 : $durationSeconds,
            'answered' => $answered,
            'bridged_channel' => $bridged,
            'bridge_id' => (string)$parts[12],
            'unique_id' => (string)$parts[13],
        ];
    }
    $rows = group_online_call_channels($rows);
    usort($rows, static fn(array $a, array $b): int => $b['duration_seconds'] <=> $a['duration_seconds']);
    return [
        'generated_at' => gmdate('c'),
        'total' => count($rows),
        'types' => array_count_values(array_map(static fn(array $row): string => $row['type'], $rows)),
        'calls' => $rows,
    ];
}

function group_online_call_channels(array $rows): array
{
    $rows = group_queue_waiting_channels($rows);
    $byBridge = [];
    foreach ($rows as $row) {
        $bridgeId = trim((string)($row['bridge_id'] ?? ''));
        if ($bridgeId !== '') {
            $byBridge[$bridgeId][] = $row;
        }
    }

    $grouped = [];
    $seen = [];
    foreach ($byBridge as $bridgeRows) {
        if (count($bridgeRows) < 2) {
            continue;
        }
        foreach ($bridgeRows as $bridgeRow) {
            $seen[(string)$bridgeRow['channel']] = true;
        }
        $merged = array_shift($bridgeRows);
        foreach ($bridgeRows as $peer) {
            $merged = merge_online_call_pair($merged, $peer);
        }
        $grouped[] = $merged;
    }

    $byChannel = [];
    foreach ($rows as $row) {
        $byChannel[(string)$row['channel']] = $row;
    }

    foreach ($rows as $row) {
        $channel = (string)$row['channel'];
        if (isset($seen[$channel])) {
            continue;
        }

        $bridged = (string)($row['bridged_channel'] ?? '');
        if ($bridged !== '' && isset($byChannel[$bridged])) {
            $peer = $byChannel[$bridged];
            $seen[$channel] = true;
            $seen[$bridged] = true;
            $grouped[] = merge_online_call_pair($row, $peer);
            continue;
        }

        $seen[$channel] = true;
        $grouped[] = $row;
    }
    return $grouped;
}

function group_queue_waiting_channels(array $rows): array
{
    $queueRows = [];
    foreach ($rows as $row) {
        $app = strtolower((string)($row['application'] ?? ''));
        $data = strtolower((string)($row['data'] ?? ''));
        if ($app === 'queue' && str_contains($data, 'discadora')) {
            $queueRows[] = $row;
        }
    }

    if (!$queueRows) {
        return $rows;
    }

    $result = [];
    $used = [];
    foreach ($rows as $row) {
        $channel = (string)$row['channel'];
        if (isset($used[$channel])) {
            continue;
        }
        $app = strtolower((string)($row['application'] ?? ''));
        if ($app === 'appqueue') {
            continue;
        }
        if ($app !== 'queue') {
            $result[] = $row;
            continue;
        }

        $merged = $row;
        $used[$channel] = true;
        foreach ($rows as $peer) {
            $peerChannel = (string)$peer['channel'];
            if (isset($used[$peerChannel])) {
                continue;
            }
            $peerApp = strtolower((string)($peer['application'] ?? ''));
            if ($peerApp === 'appqueue') {
                $merged = merge_online_queue_pair($merged, $peer);
                $used[$peerChannel] = true;
                break;
            }
        }
        $result[] = $merged;
    }

    foreach ($rows as $row) {
        if (!isset($used[(string)$row['channel']])) {
            $result[] = $row;
        }
    }
    return $result;
}

function merge_online_queue_pair(array $queue, array $agent): array
{
    $merged = merge_online_call_pair($queue, $agent);
    $merged['type'] = 'waiting';
    $merged['status'] = 'Aguardando atendimento';
    $merged['state'] = (string)($agent['state'] ?? $queue['state'] ?? 'Ringing');
    $merged['application'] = 'Queue';
    $merged['data'] = trim((string)($queue['data'] ?? ''));
    $merged['context'] = trim((string)($queue['context'] ?? ''));
    $merged['extension'] = trim((string)($queue['extension'] ?? '')) ?: trim((string)($agent['extension'] ?? ''));
    $merged['callerid'] = trim((string)($queue['callerid'] ?? '')) ?: trim((string)($agent['callerid'] ?? ''));
    $merged['duration_seconds'] = 0;
    $merged['duration'] = '00:00:00';
    $merged['wait_seconds'] = max((int)($queue['wait_seconds'] ?? 0), (int)($agent['wait_seconds'] ?? 0), (int)($queue['duration_seconds'] ?? 0), (int)($agent['duration_seconds'] ?? 0));
    $merged['answered'] = false;
    return $merged;
}

function merge_online_call_pair(array $a, array $b): array
{
    $channels = array_values(array_unique(array_merge(
        preg_split('/\s+\/\s+/', (string)$a['channel']) ?: [(string)$a['channel']],
        preg_split('/\s+\/\s+/', (string)$b['channel']) ?: [(string)$b['channel']]
    )));
    $duration = max((int)$a['duration_seconds'], (int)$b['duration_seconds']);
    $types = [(string)$a['type'], (string)$b['type']];
    $type = in_array('active', $types, true) ? 'active' : (in_array('waiting', $types, true) ? 'waiting' : $types[0]);
    $caller = trim((string)$a['callerid']) ?: trim((string)$b['callerid']);
    $extension = trim((string)$a['extension']) ?: trim((string)$b['extension']);

    return [
        'id' => sha1(implode('|', $channels)),
        'type' => $type,
        'status' => online_call_status_label($type, 'Up', 'Bridge'),
        'channel' => implode(' / ', $channels),
        'context' => trim((string)$a['context']) ?: trim((string)$b['context']),
        'extension' => $extension,
        'state' => 'Up',
        'application' => 'Bridge',
        'data' => '',
        'callerid' => $caller,
        'duration' => format_channel_duration((string)$duration),
        'duration_seconds' => $duration,
        'wait_seconds' => max((int)($a['wait_seconds'] ?? 0), (int)($b['wait_seconds'] ?? 0)),
        'answered' => $type === 'active',
        'bridged_channel' => implode(' / ', array_reverse($channels)),
        'bridge_id' => trim((string)($a['bridge_id'] ?? '')) ?: trim((string)($b['bridge_id'] ?? '')),
        'unique_id' => trim((string)($a['unique_id'] ?? '')) ?: trim((string)($b['unique_id'] ?? '')),
    ];
}

function parse_online_concise_duration(array $parts): int
{
    $duration = parse_channel_duration((string)($parts[11] ?? ''));
    if ($duration > 0) {
        return $duration;
    }
    return parse_channel_duration((string)($parts[10] ?? ''));
}

function empty_online_calls_snapshot(string $message = ''): array
{
    return [
        'generated_at' => gmdate('c'),
        'total' => 0,
        'types' => [],
        'calls' => [],
        'message' => $message,
    ];
}

function recent_agent_call_history(int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    $stmt = Database::conn()->query("SELECT extension, number, caller_id, status, started_at, answered_at, ended_at, duration_seconds FROM agent_call_logs ORDER BY started_at DESC LIMIT {$limit}");
    return array_map(static function (array $item): array {
        $duration = (int)($item['duration_seconds'] ?? 0);
        $endedAt = (string)($item['ended_at'] ?? '');
        $status = (string)$item['status'];
        if (in_array($status, ['Nao atendido', 'Pausado', 'Pendente'], true)) {
            $duration = 0;
        } elseif ($endedAt === '' && $status === 'Atendido' && !empty($item['answered_at'])) {
            $start = strtotime((string)$item['answered_at']);
            if ($start !== false) {
                $duration = max(0, time() - $start);
            }
        }
        return [
            'extension' => (string)$item['extension'],
            'number' => display_call_number((string)$item['number']),
            'caller_id' => display_call_number((string)($item['caller_id'] ?? '')),
            'status' => $status,
            'started_at' => (string)$item['started_at'],
            'answered_at' => (string)($item['answered_at'] ?? ''),
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'duration' => format_channel_duration((string)$duration),
        ];
    }, $stmt->fetchAll());
}

function display_call_number(string $number): string
{
    $number = trim($number);
    if (preg_match('/^([0-9+]+)\s*-\s*\1$/', $number, $match)) {
        return $match[1];
    }
    if (preg_match('/^anonymous\s*-\s*anonymous$/i', $number)) {
        return 'desconhecido';
    }
    return $number;
}

function asterisk_remote_cli(string $command, int $timeoutSeconds = 8): string
{
    $host = trim((string)setting('asterisk_sync_host', ''));
    $user = trim((string)setting('asterisk_sync_user', 'root'));
    $key = trim((string)setting('asterisk_sync_key', __DIR__ . '/data/asterisk_sync_ed25519'));
    if ($host === '' || $user === '' || $key === '' || !is_file($key)) {
        throw new RuntimeException('Configuracao SSH do Asterisk incompleta.');
    }
    $remote = $user . '@' . $host;
    $ssh = 'ssh -i ' . dq($key) . ' -o BatchMode=yes -o ConnectTimeout=4 -o ServerAliveInterval=2 -o ServerAliveCountMax=1 -o StrictHostKeyChecking=no ';
    return run_sync_command($ssh . $remote . ' ' . dq('asterisk -rx ' . escapeshellarg($command)), $timeoutSeconds);
}

function asterisk_remote_shell(string $script, int $timeoutSeconds = 8): string
{
    $host = trim((string)setting('asterisk_sync_host', ''));
    $user = trim((string)setting('asterisk_sync_user', 'root'));
    $key = trim((string)setting('asterisk_sync_key', __DIR__ . '/data/asterisk_sync_ed25519'));
    if ($host === '' || $user === '' || $key === '' || !is_file($key)) {
        throw new RuntimeException('Configuracao SSH do Asterisk incompleta.');
    }
    $remote = $user . '@' . $host;
    $encoded = base64_encode($script);
    $ssh = 'ssh -i ' . dq($key) . ' -o BatchMode=yes -o ConnectTimeout=4 -o ServerAliveInterval=2 -o ServerAliveCountMax=1 -o StrictHostKeyChecking=no ';
    return run_sync_command($ssh . $remote . ' ' . dq('echo ' . escapeshellarg($encoded) . ' | base64 -d | bash'), $timeoutSeconds);
}

function magnus_remote_shell(string $script, int $timeoutSeconds = 20): string
{
    if (setting('magnus_sync_enabled', '1') !== '1') {
        throw new RuntimeException('Sincronizacao Magnus desativada.');
    }
    $host = trim((string)setting('magnus_sync_host', ''));
    $user = trim((string)setting('magnus_sync_user', 'root'));
    $key = trim((string)setting('magnus_sync_key', __DIR__ . '/data/magnus_sync_ed25519'));
    if ($host === '' || $user === '' || $key === '' || !is_file($key)) {
        throw new RuntimeException('Configuracao SSH do Magnus incompleta.');
    }
    $remote = $user . '@' . $host;
    $encoded = base64_encode($script);
    $ssh = 'ssh -i ' . dq($key) . ' -o BatchMode=yes -o ConnectTimeout=5 -o ServerAliveInterval=2 -o ServerAliveCountMax=1 -o StrictHostKeyChecking=no ';
    return run_sync_command($ssh . $remote . ' ' . dq('echo ' . escapeshellarg($encoded) . ' | base64 -d | bash'), $timeoutSeconds);
}

function magnus_mysql(string $sql, int $timeoutSeconds = 20): string
{
    $script = <<<'BASH'
set -euo pipefail
cfg=/etc/asterisk/res_config_mysql.conf
dbhost=$(awk -F= '/^[[:space:]]*dbhost[[:space:]]*=/{gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2}' "$cfg")
dbname=$(awk -F= '/^[[:space:]]*dbname[[:space:]]*=/{gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2}' "$cfg")
dbuser=$(awk -F= '/^[[:space:]]*dbuser[[:space:]]*=/{gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2}' "$cfg")
dbpass=$(awk -F= '/^[[:space:]]*dbpass[[:space:]]*=/{gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2); print $2}' "$cfg")
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
chmod 600 "$tmp"
cat > "$tmp" <<EOF
[client]
host=$dbhost
user=$dbuser
password=$dbpass
database=$dbname
EOF
mysql --defaults-extra-file="$tmp" -N -B <<'SQL'
BASH;
    $script .= "\n" . $sql . "\nSQL\n";
    return magnus_remote_shell($script, $timeoutSeconds);
}

function sql_value(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    return "'" . str_replace(
        ["\\", "\0", "\n", "\r", "'", "\x1a"],
        ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"],
        $value
    ) . "'";
}

function magnus_username_for_local_user(array $user): string
{
    $existing = trim((string)($user['magnus_username'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }
    $source = (string)($user['email'] ?? '');
    $source = $source !== '' ? preg_replace('/@.*/', '', $source) : (string)($user['name'] ?? 'usuario');
    $base = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $source) ?: $source));
    $base = $base !== '' ? $base : 'usuario';
    $suffix = (string)((int)$user['id']);
    return substr($base, 0, max(1, 20 - strlen($suffix))) . $suffix;
}

function default_queue_name_for_local(string $seed, int $id = 0): string
{
    $source = preg_replace('/@.*/', '', $seed) ?: 'user';
    $base = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $source) ?: $source));
    $base = $base !== '' ? $base : 'user';
    return 'discadora_' . substr($base, 0, 14) . ($id > 0 ? '_' . $id : '');
}

function default_queue_exten_for_local(int $id): string
{
    return (string)(700 + $id);
}

function queue_strategy_options(): array
{
    return [
        'ringall' => 'Tocar todos os ramais',
        'leastrecent' => 'Agente mais ocioso',
        'rrmemory' => 'Round robin',
        'linear' => 'De cima para baixo',
        'short_talk' => 'Menor tempo de fala',
        'fewestcalls' => 'Menos chamadas',
        'random' => 'Aleatorio',
    ];
}

function queue_strategy_value(string $strategy): string
{
    return array_key_exists($strategy, queue_strategy_options()) ? $strategy : 'ringall';
}

function queue_strategy_label(string $strategy): string
{
    $strategy = queue_strategy_value($strategy);
    return queue_strategy_options()[$strategy] ?? $strategy;
}

function queue_asterisk_strategy(string $strategy): string
{
    $strategy = queue_strategy_value($strategy);
    return $strategy === 'short_talk' ? 'fewestcalls' : $strategy;
}

function queue_overflow_type_value(string $type): string
{
    $allowed = ['hangup', 'ramal', 'queue', 'voicemail', 'ivr'];
    return in_array($type, $allowed, true) ? $type : 'hangup';
}

function queue_overflow_type_label(string $type): string
{
    return [
        'hangup' => 'Nenhum (desligar chamada)',
        'ramal' => 'Ramal',
        'queue' => 'Outra fila',
        'voicemail' => 'Caixa postal',
        'ivr' => 'URA',
    ][queue_overflow_type_value($type)] ?? 'Nenhum (desligar chamada)';
}

function queue_musicclass_value(string $musicclass): string
{
    $musicclass = preg_replace('/[^A-Za-z0-9_.-]/', '', trim($musicclass));
    return $musicclass !== '' ? $musicclass : 'default';
}

function queue_overflow_dialplan(array $queueInfo): string
{
    $type = queue_overflow_type_value((string)($queueInfo['overflow_type'] ?? 'hangup'));
    $destination = preg_replace('/[^A-Za-z0-9_.+-]/', '', (string)($queueInfo['overflow_destination'] ?? ''));
    if ($type === 'ramal' && $destination !== '') {
        return " same => n,Goto(from-agents,{$destination},1)\n";
    }
    if ($type === 'queue' && $destination !== '') {
        return " same => n,Goto(from-webrtc,{$destination},1)\n";
    }
    if ($type === 'ivr' && $destination !== '') {
        $ivrId = (int)$destination;
        if ($ivrId > 0) {
            return " same => n,Gosub(discadora-ivr-menu-{$ivrId},s,1)\n same => n,Hangup()\n";
        }
    }
    if ($type === 'voicemail' && $destination !== '') {
        return " same => n,VoiceMail({$destination}@default,u)\n same => n,Hangup()\n";
    }
    return " same => n,Hangup()\n";
}

function queue_asterisk_name(int $ownerId, string $queueNumber): string
{
    $queueNumber = preg_replace('/[^0-9]/', '', $queueNumber) ?: '700';
    return 'discadora_u' . max(1, $ownerId) . '_q' . $queueNumber;
}

function generate_unique_queue_number(int $ownerId = 0): string
{
    $db = Database::conn();
    $base = 7000 + max(1, $ownerId) * 10;
    for ($i = 0; $i < 500; $i++) {
        $candidate = (string)($base + $i);
        $stmt = $db->prepare('SELECT COUNT(*) FROM call_queues WHERE queue_number=?');
        $stmt->execute([$candidate]);
        $reserved = $db->prepare('SELECT COUNT(*) FROM users WHERE queue_exten=?');
        $reserved->execute([$candidate]);
        if ((int)$stmt->fetchColumn() === 0 && (int)$reserved->fetchColumn() === 0) {
            return $candidate;
        }
    }
    throw new RuntimeException('nao foi possivel gerar numero de fila unico.');
}

function local_magnus_rate(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = Database::conn()->prepare('SELECT * FROM magnus_rates WHERE id=? AND active=1');
    $stmt->execute([$id]);
    $rate = $stmt->fetch();
    return $rate ?: null;
}

function adjust_user_credit(int $userId, float $amount, string $type, string $note, ?int $createdBy = null): void
{
    if ($userId <= 0 || $amount <= 0) {
        throw new RuntimeException('Informe usuario e valor valido.');
    }
    $type = $type === 'debit' ? 'debit' : 'credit';
    $signed = $type === 'debit' ? -$amount : $amount;
    $db = Database::conn();
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT credit_balance FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $current = (float)($stmt->fetchColumn() ?: 0);
    $next = max(0, $current + $signed);
    $db->prepare('UPDATE users SET credit_balance=? WHERE id=?')->execute([number_format($next, 2, '.', ''), $userId]);
    $db->prepare('INSERT INTO credit_transactions(user_id, amount, balance_after, type, note, created_by) VALUES(?,?,?,?,?,?)')
        ->execute([$userId, number_format($signed, 2, '.', ''), number_format($next, 2, '.', ''), $type, $note, $createdBy]);
    $db->commit();
}

function money_to_cents(float|string|null $value): int
{
    $normalized = str_replace(',', '.', trim((string)($value ?? '0')));
    return max(0, (int)ceil(((float)$normalized) * 100));
}

function cents_to_money(int $cents): string
{
    return number_format(max(0, $cents) / 100, 2, '.', '');
}

function billing_billable_seconds(int $billsec, int $initialBlock, int $increment): int
{
    if ($billsec <= 0) {
        return 0;
    }
    $initialBlock = max(1, $initialBlock);
    $increment = max(1, $increment);
    if ($billsec <= $initialBlock) {
        return $initialBlock;
    }
    return $initialBlock + ((int)ceil(($billsec - $initialBlock) / $increment) * $increment);
}

function billing_cost_cents(int $billsec, float|string|null $ratePerMinute, int $initialBlock, int $increment): int
{
    $rateCents = money_to_cents($ratePerMinute);
    if ($billsec <= 0 || $rateCents <= 0) {
        return 0;
    }
    $billableSeconds = billing_billable_seconds($billsec, $initialBlock, $increment);
    return (int)ceil(($billableSeconds * $rateCents) / 60);
}

function billable_seconds_cost_cents(int $billableSeconds, float|string|null $ratePerMinute): int
{
    $rateCents = money_to_cents($ratePerMinute);
    if ($billableSeconds <= 0 || $rateCents <= 0) {
        return 0;
    }
    return (int)ceil(($billableSeconds * $rateCents) / 60);
}

function apply_call_billing_charge(
    string $sourceType,
    int $sourceId,
    int $userId,
    ?int $rateId,
    float|string|null $rateValue,
    int $initialBlock,
    int $increment,
    string $answeredAt,
    ?string $endedAt,
    bool $final,
    string $note
): array {
    $db = Database::conn();
    if ($userId <= 0 || $sourceId <= 0 || trim($answeredAt) === '') {
        return ['duration' => 0, 'billed_seconds' => 0, 'cost_cents' => 0, 'delta_cents' => 0];
    }
    $answeredTs = strtotime($answeredAt);
    $endTs = $final ? strtotime($endedAt ?: date('Y-m-d H:i:s')) : time();
    if ($answeredTs === false || $endTs === false) {
        return ['duration' => 0, 'billed_seconds' => 0, 'cost_cents' => 0, 'delta_cents' => 0];
    }
    $duration = max($final ? 0 : 1, $endTs - $answeredTs);
    $targetBilledSeconds = billing_billable_seconds($duration, $initialBlock, $increment);
    $lock = Database::isMysql() ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT * FROM call_billing_records WHERE source_type=? AND source_id=?' . $lock);
    $stmt->execute([$sourceType, $sourceId]);
    $record = $stmt->fetch();
    if ($record && (int)($record['finalized'] ?? 0) === 1) {
        return [
            'duration' => (int)$record['duration_seconds'],
            'billed_seconds' => (int)$record['billed_seconds'],
            'cost_cents' => (int)$record['charged_cents'],
            'delta_cents' => 0,
        ];
    }
    if (!$record) {
        $db->prepare('INSERT INTO call_billing_records(source_type, source_id, user_id, rate_id, rate_value, initial_block_seconds, billing_increment_seconds, answered_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$sourceType, $sourceId, $userId, $rateId, (string)($rateValue ?? '0'), max(1, $initialBlock), max(1, $increment), date('Y-m-d H:i:s', $answeredTs)]);
        $stmt->execute([$sourceType, $sourceId]);
        $record = $stmt->fetch();
    }
    $alreadyBilledSeconds = max(0, (int)($record['billed_seconds'] ?? 0));
    $deltaSeconds = max(0, $targetBilledSeconds - $alreadyBilledSeconds);
    $deltaCost = billable_seconds_cost_cents($deltaSeconds, $rateValue);
    $totalCost = max(0, (int)($record['charged_cents'] ?? 0)) + $deltaCost;
    if ($deltaCost > 0) {
        $stmt = $db->prepare('SELECT credit_balance, credit_consumed FROM users WHERE id=?' . $lock);
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user) {
            $balanceCents = max(0, money_to_cents($user['credit_balance'] ?? 0) - $deltaCost);
            $consumedCents = money_to_cents($user['credit_consumed'] ?? 0) + $deltaCost;
            $db->prepare('UPDATE users SET credit_balance=?, credit_consumed=? WHERE id=?')->execute([
                cents_to_money($balanceCents),
                cents_to_money($consumedCents),
                $userId,
            ]);
            $db->prepare('INSERT INTO credit_transactions(user_id, amount, balance_after, type, note, created_by) VALUES(?,?,?,?,?,NULL)')
                ->execute([$userId, '-' . cents_to_money($deltaCost), cents_to_money($balanceCents), 'consume', $note . ' +' . $deltaSeconds . 's']);
            if ($balanceCents <= 0) {
                $db->prepare('UPDATE users SET active=0 WHERE id=? AND role <> ?')->execute([$userId, 'admin']);
            }
        }
    }
    $db->prepare('UPDATE call_billing_records SET ended_at=?, duration_seconds=?, billed_seconds=?, charged_cents=?, finalized=?, updated_at=' . db_now_expr() . ' WHERE source_type=? AND source_id=?')
        ->execute([$final ? date('Y-m-d H:i:s', $endTs) : null, $duration, max($alreadyBilledSeconds, $targetBilledSeconds), $totalCost, $final ? 1 : 0, $sourceType, $sourceId]);
    return ['duration' => $duration, 'billed_seconds' => max($alreadyBilledSeconds, $targetBilledSeconds), 'cost_cents' => $totalCost, 'delta_cents' => $deltaCost];
}

function billing_profile_for_user(int $userId): array
{
    if ($userId <= 0) {
        return ['rate_id' => null, 'rate_value' => null, 'initial_block' => 30, 'increment' => 6];
    }
    $stmt = Database::conn()->prepare('SELECT mr.id, mr.rate_value, mr.initial_block_seconds, mr.billing_increment_seconds FROM users u LEFT JOIN magnus_rates mr ON mr.id=u.default_magnus_rate_id AND mr.active=1 WHERE u.id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return [
        'rate_id' => !empty($row['id']) ? (int)$row['id'] : null,
        'rate_value' => $row['rate_value'] ?? null,
        'initial_block' => max(1, (int)($row['initial_block_seconds'] ?? 30)),
        'increment' => max(1, (int)($row['billing_increment_seconds'] ?? 6)),
    ];
}

function charge_agent_call_log(int $logId, bool $final = true): void
{
    $db = Database::conn();
    $db->beginTransaction();
    $lock = Database::isMysql() ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT * FROM agent_call_logs WHERE id=?' . $lock);
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log || empty($log['answered_at'])) {
        $db->commit();
        return;
    }
    if (str_contains(strtolower((string)($log['details'] ?? '')), 'transferencia')) {
        $db->commit();
        return;
    }
    if ($final && !empty($log['charged_at'])) {
        $db->commit();
        return;
    }
    $answeredAt = strtotime((string)$log['answered_at']);
    $endAt = $final ? strtotime((string)($log['ended_at'] ?: date('Y-m-d H:i:s'))) : time();
    if ($answeredAt === false || $endAt === false) {
        $db->commit();
        return;
    }
    $userId = (int)($log['user_id'] ?? 0);
    $profile = ($userId > 0 && empty($log['magnus_rate_value'])) ? billing_profile_for_user($userId) : null;
    $rateValue = $log['magnus_rate_value'] ?? ($profile['rate_value'] ?? null);
    $initialBlock = max(1, (int)($log['billing_initial_block'] ?? ($profile['initial_block'] ?? 30)));
    $increment = max(1, (int)($log['billing_increment'] ?? ($profile['increment'] ?? 6)));
    $billing = apply_call_billing_charge('sip', $logId, $userId, (int)($log['magnus_rate_id'] ?? 0) ?: ($profile['rate_id'] ?? null), $rateValue, $initialBlock, $increment, (string)$log['answered_at'], $final ? (string)($log['ended_at'] ?: date('Y-m-d H:i:s')) : null, $final, 'Consumo SIP log #' . $logId . ($final ? ' fechamento' : ' bloco inicial'));
    $chargedSql = $final ? ', charged_at=CURRENT_TIMESTAMP' : '';
    $db->prepare("UPDATE agent_call_logs SET duration_seconds=?, billed_seconds=?, cost=?{$chargedSql} WHERE id=?")
        ->execute([$billing['duration'], $billing['billed_seconds'], cents_to_money($billing['cost_cents']), $logId]);
    $db->commit();
}

function charge_call_job(int $jobId, bool $final = true): void
{
    $db = Database::conn();
    $db->beginTransaction();
    $lock = Database::isMysql() ? ' FOR UPDATE' : '';
    $stmt = $db->prepare('SELECT j.*, c.created_by, c.magnus_rate_id, c.magnus_rate_value, c.billing_initial_block, c.billing_increment FROM call_jobs j JOIN campaigns c ON c.id=j.campaign_id WHERE j.id=?' . $lock);
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job || empty($job['answered_at'])) {
        $db->commit();
        return;
    }
    if ($final && !empty($job['charged_at'])) {
        $db->commit();
        return;
    }
    $answeredAt = strtotime((string)$job['answered_at']);
    $endAt = $final
        ? strtotime((string)($job['ended_at'] ?: date('Y-m-d H:i:s')))
        : time();
    if ($answeredAt === false || $endAt === false) {
        $db->commit();
        return;
    }
    $duration = max($final ? 0 : 1, $endAt - $answeredAt);
    $initialBlock = max(1, (int)($job['billing_initial_block'] ?? 30));
    $increment = max(1, (int)($job['billing_increment'] ?? 6));
    $userId = (int)$job['created_by'];
    $billing = apply_call_billing_charge('campaign', $jobId, $userId, (int)($job['magnus_rate_id'] ?? 0) ?: null, $job['magnus_rate_value'] ?? 0, $initialBlock, $increment, (string)$job['answered_at'], $final ? (string)($job['ended_at'] ?: date('Y-m-d H:i:s')) : null, $final, 'Consumo chamada job #' . $jobId . ($final ? ' fechamento' : ' bloco inicial'));
    $chargedSql = $final ? ', charged_at=CURRENT_TIMESTAMP' : '';
    $db->prepare("UPDATE call_jobs SET duration_seconds=?, billed_seconds=?, cost=?{$chargedSql} WHERE id=?")
        ->execute([$billing['duration'], $billing['billed_seconds'], cents_to_money($billing['cost_cents']), $jobId]);
    $db->commit();
}

function sync_user_to_magnus(int $localUserId, string $plainPassword = ''): int
{
    $db = Database::conn();
    $stmt = $db->prepare('SELECT id, name, email, active, magnus_user_id, magnus_username FROM users WHERE id=?');
    $stmt->execute([$localUserId]);
    $localUser = $stmt->fetch();
    if (!$localUser) {
        throw new RuntimeException('Usuario local nao encontrado.');
    }
    if ($plainPassword === '') {
        $plainPassword = bin2hex(random_bytes(8));
    }
    $username = magnus_username_for_local_user($localUser);
    $templateId = max(1, (int)setting('magnus_template_user_id', '33'));
    $active = (int)($localUser['active'] ?? 1) === 1 ? '1' : '0';
    $sql = "
SET @username := " . sql_value($username) . ";
SET @plain := " . sql_value($plainPassword) . ";
SET @template := {$templateId};
SET @active := {$active};
SET @pin := " . (700000 + ($localUserId % 299999)) . ";
SET @existing := COALESCE((SELECT id FROM pkg_user WHERE username=@username LIMIT 1), 0);
SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ',') INTO @cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pkg_user' AND COLUMN_NAME NOT IN ('id','username','password','callingcard_pin','loginkey');
SET @insert_sql := CONCAT('INSERT INTO pkg_user (`username`,`password`,`callingcard_pin`,', @cols, ') SELECT ', QUOTE(@username), ',', QUOTE(MD5(@plain)), ',', @pin, ',', @cols, ' FROM pkg_user WHERE id=', @template, ' AND ', @existing, '=0');
PREPARE stmt FROM @insert_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @remote_id := IF(@existing > 0, @existing, LAST_INSERT_ID());
UPDATE pkg_user SET active=@active WHERE id=@remote_id;
SELECT @remote_id;
";
    $output = magnus_mysql($sql, 30);
    preg_match_all('/^\d+$/m', $output, $matches);
    $remoteId = (int)end($matches[0]);
    if ($remoteId <= 0) {
        throw new RuntimeException('Magnus nao retornou o ID do usuario.');
    }
    $db->prepare('UPDATE users SET magnus_user_id=?, magnus_username=? WHERE id=?')->execute([$remoteId, $username, $localUserId]);
    return $remoteId;
}

function apply_magnus_rate_to_user(int $localUserId, array $rate): void
{
    $db = Database::conn();
    $stmt = $db->prepare('SELECT magnus_user_id, magnus_username FROM users WHERE id=?');
    $stmt->execute([$localUserId]);
    $localUser = $stmt->fetch();
    $username = trim((string)($localUser['magnus_username'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('Usuario ainda nao esta vinculado ao Magnus.');
    }
    $sourcePlanId = (int)($rate['magnus_plan_id'] ?? 0);
    if ($sourcePlanId <= 0) {
        throw new RuntimeException('Taxa/rota sem plano Magnus vinculado.');
    }
    $planName = substr($username . '-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', (string)$rate['plan_name']), 0, 50);
    $sql = "
SET @username := " . sql_value($username) . ";
SET @source_plan := {$sourcePlanId};
SET @plan_name := " . sql_value($planName) . ";
SET @user_id := COALESCE((SELECT id FROM pkg_user WHERE username=@username LIMIT 1), 0);
SET @existing_plan := COALESCE((SELECT id FROM pkg_plan WHERE name=@plan_name LIMIT 1), 0);
SELECT GROUP_CONCAT(CONCAT('`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ',') INTO @plan_cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pkg_plan' AND COLUMN_NAME NOT IN ('id','name','creationdate');
SET @plan_insert := CONCAT('INSERT INTO pkg_plan (`name`,', @plan_cols, ') SELECT ', QUOTE(@plan_name), ',', @plan_cols, ' FROM pkg_plan WHERE id=', @source_plan, ' AND ', @existing_plan, '=0');
PREPARE stmt FROM @plan_insert;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @target_plan := IF(@existing_plan > 0, @existing_plan, LAST_INSERT_ID());
DELETE FROM pkg_rate WHERE id_plan=@target_plan;
INSERT INTO pkg_rate (id_plan, id_trunk_group, id_prefix, rateinitial, initblock, billingblock, connectcharge, disconnectcharge, additional_grace, minimal_time_charge, package_offer, status, dialprefix, destination)
SELECT @target_plan, id_trunk_group, id_prefix, rateinitial, initblock, billingblock, connectcharge, disconnectcharge, additional_grace, minimal_time_charge, package_offer, status, dialprefix, destination
FROM pkg_rate WHERE id_plan=@source_plan;
UPDATE pkg_user SET id_plan=@target_plan WHERE id=@user_id AND @user_id > 0;
SELECT @user_id, @target_plan;
";
    magnus_mysql($sql, 30);
}

function sync_magnus_rates_from_remote(): void
{
    $sql = "SELECT r.id_plan, r.id, r.id_trunk_group, p.name, tg.name, r.rateinitial, COALESCE(r.destination,''), COALESCE(NULLIF(r.initblock,0),30), COALESCE(NULLIF(r.billingblock,0),6) FROM pkg_rate r JOIN pkg_plan p ON p.id=r.id_plan JOIN pkg_trunk_group tg ON tg.id=r.id_trunk_group WHERE r.status=1 ORDER BY p.id, r.id;";
    $output = magnus_mysql($sql, 30);
    $db = Database::conn();
    $insert = $db->prepare(Database::isMysql()
        ? 'INSERT INTO magnus_rates(magnus_plan_id, magnus_rate_id, magnus_route_id, plan_name, route_name, rate_value, destination, initial_block_seconds, billing_increment_seconds, active) VALUES(?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE plan_name=VALUES(plan_name), route_name=VALUES(route_name), rate_value=VALUES(rate_value), destination=VALUES(destination), initial_block_seconds=VALUES(initial_block_seconds), billing_increment_seconds=VALUES(billing_increment_seconds), active=1'
        : 'INSERT INTO magnus_rates(magnus_plan_id, magnus_rate_id, magnus_route_id, plan_name, route_name, rate_value, destination, initial_block_seconds, billing_increment_seconds, active) VALUES(?,?,?,?,?,?,?,?,?,1) ON CONFLICT(magnus_plan_id, magnus_rate_id, magnus_route_id) DO UPDATE SET plan_name=excluded.plan_name, route_name=excluded.route_name, rate_value=excluded.rate_value, destination=excluded.destination, initial_block_seconds=excluded.initial_block_seconds, billing_increment_seconds=excluded.billing_increment_seconds, active=1');
    $seen = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) < 7) {
            continue;
        }
        [$planId, $rateId, $routeId, $planName, $routeName, $value, $destination, $initialBlock, $increment] = array_pad($parts, 9, '');
        $insert->execute([(int)$planId, (int)$rateId, (int)$routeId, $planName, $routeName, $value, $destination, max(1, (int)$initialBlock), max(1, (int)$increment)]);
        $seen[] = ((int)$planId) . ':' . ((int)$rateId) . ':' . ((int)$routeId);
    }
}

function classify_online_call(string $state, string $app, string $data): string
{
    $haystack = strtolower($state . ' ' . $app . ' ' . $data);
    if (strtolower($state) === 'up' || str_contains($haystack, 'bridge')) {
        return 'active';
    }
    if (str_contains($haystack, 'queue')) {
        return 'waiting';
    }
    if (str_contains($haystack, 'playback') || str_contains($haystack, 'background') || str_contains($haystack, 'music')) {
        return 'audio';
    }
    if (str_contains($haystack, 'dial') || str_contains($haystack, 'ring') || str_contains($haystack, 'ringing')) {
        return 'routing';
    }
    return 'routing';
}

function online_call_status_label(string $type, string $state, string $app): string
{
    return match ($type) {
        'active' => 'Em atendimento',
        'waiting' => 'Aguardando atendimento',
        'audio' => 'Reproduzindo audio',
        default => $app !== '' ? 'Classificando chamada' : ($state ?: 'Em rota'),
    };
}

function parse_channel_duration(string $duration): int
{
    $duration = trim($duration);
    if ($duration === '') {
        return 0;
    }
    if (ctype_digit($duration)) {
        return (int)$duration;
    }
    $parts = array_map('intval', explode(':', $duration));
    if (count($parts) === 3) {
        return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
    }
    if (count($parts) === 2) {
        return ($parts[0] * 60) + $parts[1];
    }
    return 0;
}

function format_channel_duration(string $duration): string
{
    $seconds = parse_channel_duration($duration);
    return gmdate('H:i:s', max(0, $seconds));
}

function sync_asterisk_extensions(): void
{
    if (setting('asterisk_sync_enabled', '1') !== '1') {
        return;
    }
    $host = trim((string)setting('asterisk_sync_host', ''));
    $user = trim((string)setting('asterisk_sync_user', 'root'));
    $key = trim((string)setting('asterisk_sync_key', __DIR__ . '/data/asterisk_sync_ed25519'));
    $externalIp = trim((string)setting('asterisk_sync_external_ip', $host));
    if ($host === '' || $user === '' || $key === '' || !is_file($key)) {
        throw new RuntimeException('configuracao SSH do Asterisk incompleta.');
    }

    $defaultCallerId = trim((string)(Database::conn()->query("SELECT caller_id FROM sip_accounts WHERE active=1 AND caller_id IS NOT NULL AND caller_id <> '' ORDER BY id LIMIT 1")->fetchColumn() ?: ''));
    $defaultCallerId = preg_replace('/[^0-9+]/', '', $defaultCallerId) ?: '';
    $users = Database::conn()->query("SELECT id, email, queue_name, queue_exten FROM users WHERE active=1 ORDER BY id")->fetchAll();
    $userQueues = [];
    foreach ($users as $owner) {
        $queueName = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($owner['queue_name'] ?: default_queue_name_for_local((string)$owner['email'], (int)$owner['id'])));
        $queueExten = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($owner['queue_exten'] ?: default_queue_exten_for_local((int)$owner['id'])));
        if ($queueName !== '' && $queueExten !== '') {
            $userQueues[(int)$owner['id']] = ['name' => $queueName, 'exten' => $queueExten, 'members' => []];
        }
    }
    $rows = Database::conn()->query("SELECT e.id, e.extension, e.name, e.secret, e.caller_id, e.user_id, e.audio_initial_enabled, e.audio_initial_file, e.audio_transfer_enabled, e.audio_transfer_file FROM extensions e WHERE e.active=1 ORDER BY e.extension")->fetchAll();
    $customQueues = Database::conn()->query("SELECT q.*, u.email AS owner_email FROM call_queues q JOIN users u ON u.id=q.user_id WHERE q.active=1 AND u.active=1 ORDER BY q.user_id, q.queue_number")->fetchAll();
    $pjsip = "; Arquivo gerado pela Discadora SIP. Nao edite manualmente.\n";
    $queues = "; Arquivo gerado pela Discadora SIP. Nao edite manualmente.\n";
    $queueRoutes = '';
    $queueExitContexts = '';
    $webrtcDialplan = "[from-webrtc]\n";
    $agentDialplan = "[from-agents]\n";

    foreach ($rows as $row) {
        $ext = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$row['extension']);
        if ($ext === '') {
            continue;
        }
        $secret = str_replace(["\r", "\n"], '', (string)$row['secret']);
        $name = trim((string)$row['name']) ?: $ext;
        $caller = trim((string)$row['caller_id']);
        $callerLine = $caller !== '' ? 'callerid="' . addcslashes($name, '"\\') . "\" <{$caller}>\n" : '';
        $initialAudio = clean_asterisk_sound((string)$row['audio_initial_file']);
        $transferAudio = clean_asterisk_sound((string)$row['audio_transfer_file']);
        $pjsip .= "\n[{$ext}]\n";
        $pjsip .= "type=endpoint\ntransport=transport-ws\ncontext=from-agents\ndisallow=all\nallow=ulaw,alaw,opus\nauth={$ext}\naors={$ext}\nwebrtc=yes\n";
        $pjsip .= $callerLine;
        $pjsip .= "use_avpf=yes\nmedia_encryption=dtls\nice_support=yes\nrtcp_mux=yes\nforce_rport=yes\nrewrite_contact=yes\nrtp_symmetric=yes\nmedia_use_received_transport=yes\ndirect_media=no\ndtmf_mode=rfc4733\nidentify_by=auth_username,username\n";
        $pjsip .= "media_address={$externalIp}\nbind_rtp_to_media_address=no\ndtls_auto_generate_cert=no\ndtls_cert_file=/etc/asterisk/keys/asterisk.pem\ndtls_private_key=/etc/asterisk/keys/asterisk.key\ndtls_verify=fingerprint\ndtls_setup=passive\nforce_avp=yes\nmedia_encryption_optimistic=no\n";
        $pjsip .= "\n[{$ext}]\ntype=auth\nauth_type=userpass\nusername={$ext}\npassword={$secret}\n";
        $pjsip .= "\n[{$ext}]\ntype=aor\nmax_contacts=1\nremove_existing=yes\nsupport_path=yes\n";
        $ownerId = (int)($row['user_id'] ?? 0);
        if (isset($userQueues[$ownerId])) {
            $memberLine = "member => PJSIP/{$ext},0," . str_replace([',', "\r", "\n"], ' ', $name) . ",PJSIP/{$ext},no\n";
            $userQueues[$ownerId]['members'][] = $memberLine;
        }
        $localRoute = "\nexten => {$ext},1,NoOp(Chamada local para ramal {$ext})\n";
        $localRoute .= " same => n,Answer()\n";
        if ((int)$row['audio_transfer_enabled'] === 1 && $transferAudio !== '') {
            $localRoute .= " same => n,Playback({$transferAudio})\n";
        }
        if ((int)$row['audio_initial_enabled'] === 1 && $initialAudio !== '') {
            $localRoute .= " same => n,Playback({$initialAudio})\n";
        }
        $localRoute .= " same => n,Dial(PJSIP/{$ext},25,tT)\n";
        $localRoute .= " same => n,NoOp(Ramal {$ext} indisponivel ou sem atendimento: \${DIALSTATUS})\n";
        $localRoute .= " same => n,Hangup()\n";
        $webrtcDialplan .= $localRoute;
        $agentDialplan .= $localRoute;
    }
    foreach ($userQueues as $queueInfo) {
        $queueName = $queueInfo['name'];
        $queueExten = $queueInfo['exten'];
        $members = $queueInfo['members'] ?: [];
        $queues .= "\n[{$queueName}]\n";
        $queues .= "musicclass=default\nstrategy=linear\ntimeout=25\nretry=1\nringinuse=no\njoinempty=yes\nleavewhenempty=no\nannounce-frequency=0\ntimeoutrestart=no\nautofill=yes\n";
        foreach ($members as $memberLine) {
            $queues .= $memberLine;
        }
        $queueRoute = "\nexten => {$queueExten},1,NoOp(Entrada na fila {$queueName})\n";
        $queueRoute .= " same => n,Answer()\n";
        $queueRoute .= " same => n,Set(QUEUE_DISPLAY_NUMBER=\${FILTER(0-9+,\${DISCADA_NUMERO})})\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" = \"\"]?Set(QUEUE_DISPLAY_NUMBER=\${FILTER(0-9+,\${IVR_PHONE})}))\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" != \"\"]?Set(CALLERID(num)=\${QUEUE_DISPLAY_NUMBER}))\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" != \"\"]?Set(CALLERID(name)=\${QUEUE_DISPLAY_NUMBER}))\n";
        $queueRoute .= " same => n,Ringing()\n";
        $queueRoute .= " same => n,Queue({$queueName},trn,,,30)\n";
        $queueRoute .= " same => n,NoOp(Fila {$queueName} encerrada: \${QUEUESTATUS})\n";
        $queueRoute .= " same => n,Hangup()\n";
        $queueRoutes .= $queueRoute;
    }
    foreach ($customQueues as $queueInfo) {
        $queueName = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$queueInfo['asterisk_name']);
        $queueExten = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$queueInfo['queue_number']);
        if ($queueName === '' || $queueExten === '' || str_contains($queues, "[{$queueName}]")) {
            continue;
        }
        $strategyKey = queue_strategy_value((string)$queueInfo['strategy']);
        $strategy = queue_asterisk_strategy($strategyKey);
        $timeout = max(5, min(120, (int)$queueInfo['timeout_seconds']));
        $maxWait = max(10, min(3600, (int)$queueInfo['max_wait_seconds']));
        if ($strategyKey === 'short_talk') {
            $shortTalk = max(30, min(300, (int)($queueInfo['short_talk_seconds'] ?? 120)));
            $timeout = min($timeout, 20);
            $maxWait = min($maxWait, $shortTalk);
        }
        $maxCalls = max(0, min(500, (int)($queueInfo['max_calls'] ?? 0)));
        $wrapup = max(0, min(600, (int)($queueInfo['wrapup_seconds'] ?? 0)));
        $musicclass = queue_musicclass_value((string)($queueInfo['musicclass'] ?? 'default'));
        $announcePosition = (int)($queueInfo['announce_position'] ?? 0) === 1 ? 'yes' : 'no';
        $announceFrequency = max(0, min(600, (int)($queueInfo['announce_frequency'] ?? 0)));
        $exitDigit = preg_replace('/[^0-9*#]/', '', (string)($queueInfo['exit_digit'] ?? ''));
        $joinEmpty = (int)$queueInfo['join_empty'] === 1 ? 'yes' : 'no';
        $memberStmt = Database::conn()->prepare('SELECT e.extension, e.name FROM call_queue_extensions qe JOIN extensions e ON e.id=qe.extension_id WHERE qe.queue_id=? AND e.active=1 AND e.user_id=? ORDER BY e.extension');
        $memberStmt->execute([(int)$queueInfo['id'], (int)$queueInfo['user_id']]);
        $queues .= "\n[{$queueName}]\n";
        $queues .= "musicclass={$musicclass}\nstrategy={$strategy}\ntimeout={$timeout}\nretry=1\nringinuse=no\njoinempty={$joinEmpty}\nleavewhenempty=no\nannounce-position={$announcePosition}\nannounce-frequency={$announceFrequency}\ntimeoutrestart=no\nautofill=yes\nwrapuptime={$wrapup}\n";
        if ($maxCalls > 0) {
            $queues .= "maxlen={$maxCalls}\n";
        }
        if ($exitDigit !== '') {
            $queues .= "context=queue-exit-{$queueName}\n";
        }
        foreach ($memberStmt->fetchAll() as $member) {
            $memberExt = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$member['extension']);
            if ($memberExt === '') {
                continue;
            }
            $memberName = str_replace([',', "\r", "\n"], ' ', (string)$member['name']);
            $queues .= "member => PJSIP/{$memberExt},0,{$memberName},PJSIP/{$memberExt},no\n";
        }
        $queueRoute = "\nexten => {$queueExten},1,NoOp(Entrada na fila {$queueName})\n";
        $queueRoute .= " same => n,Answer()\n";
        $queueRoute .= " same => n,Set(QUEUE_DISPLAY_NUMBER=\${FILTER(0-9+,\${DISCADA_NUMERO})})\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" = \"\"]?Set(QUEUE_DISPLAY_NUMBER=\${FILTER(0-9+,\${IVR_PHONE})}))\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" != \"\"]?Set(CALLERID(num)=\${QUEUE_DISPLAY_NUMBER}))\n";
        $queueRoute .= " same => n,ExecIf(\$[\"\${QUEUE_DISPLAY_NUMBER}\" != \"\"]?Set(CALLERID(name)=\${QUEUE_DISPLAY_NUMBER}))\n";
        $queueRoute .= " same => n,Ringing()\n";
        if ((int)$queueInfo['record_calls'] === 1) {
            $queueRoute .= " same => n,MixMonitor(discadora-{$queueName}-\${UNIQUEID}.wav,b)\n";
        }
        $queueRoute .= " same => n,Queue({$queueName},trn,,,{$maxWait})\n";
        $queueRoute .= " same => n,NoOp(Fila {$queueName} encerrada: \${QUEUESTATUS})\n";
        $queueRoute .= queue_overflow_dialplan($queueInfo);
        $queueRoutes .= $queueRoute;
        if ($exitDigit !== '') {
            $queueExitContexts .= "\n[queue-exit-{$queueName}]\n";
            $queueExitContexts .= "exten => {$exitDigit},1,NoOp(Saida DTMF da fila {$queueName})\n";
            $queueExitContexts .= queue_overflow_dialplan($queueInfo);
        }
    }
    $webrtcDialplan = "[from-webrtc]\n" . $queueRoutes . substr($webrtcDialplan, strlen("[from-webrtc]\n"));
    $agentDialplan = "[from-agents]\n" . $queueRoutes . substr($agentDialplan, strlen("[from-agents]\n"));
    $externalRoute = "\nexten => _X.,1,NoOp(Saida externa para \${EXTEN} via Magnus)\n";
    $externalRoute .= " same => n,Set(DEFAULT_CID={$defaultCallerId})\n";
    $externalRoute .= " same => n,Set(CAMPAIGN_CID=\${FILTER(0-9+,\${CAMPAIGN_CALLERID})})\n";
    $externalRoute .= " same => n,Set(IVR_CID=\${FILTER(0-9+,\${IVR_CALLERID})})\n";
    $externalRoute .= " same => n,Set(HEADER_CID=\${FILTER(0-9+,\${PJSIP_HEADER(read,X-Caller-ID)})})\n";
    $externalRoute .= " same => n,Set(OUTBOUND_CID=\${CAMPAIGN_CID})\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" = \"\"]?Set(OUTBOUND_CID=\${IVR_CID}))\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" = \"\"]?Set(OUTBOUND_CID=\${HEADER_CID}))\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" = \"\"]?Set(OUTBOUND_CID=\${DEFAULT_CID}))\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" = \"\"]?Set(OUTBOUND_CID=\${CALLERID(num)}))\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" != \"\"]?Set(CALLERID(num)=\${OUTBOUND_CID}))\n";
    $externalRoute .= " same => n,ExecIf(\$[\"\${OUTBOUND_CID}\" != \"\"]?Set(CALLERID(name)=\${OUTBOUND_CID}))\n";
    $externalRoute .= " same => n,NoOp(CallerID saida: \${CALLERID(all)} / header=\${HEADER_CID})\n";
    $externalRoute .= " same => n,Dial(PJSIP/\${EXTEN}@magnus,60,rtTb(set-outbound-callerid^s^1(\${OUTBOUND_CID})))\n";
    $externalRoute .= " same => n,Hangup()\n";
    $extensions = "; Arquivo gerado pela Discadora SIP. Nao edite manualmente.\n\n";
    $extensions .= $webrtcDialplan . $externalRoute . "\n\n" . $agentDialplan . $externalRoute . "\n" . $queueExitContexts;

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'discadora_asterisk_' . bin2hex(random_bytes(4));
    if (!mkdir($tmp) && !is_dir($tmp)) {
        throw new RuntimeException('nao foi possivel criar temporario.');
    }
    $pjsipFile = $tmp . DIRECTORY_SEPARATOR . 'pjsip_codex_agents.conf';
    $queuesFile = $tmp . DIRECTORY_SEPARATOR . 'queues_codex_discadora.conf';
    $extensionsFile = $tmp . DIRECTORY_SEPARATOR . 'extensions_codex_agents.conf';
    $scriptFile = $tmp . DIRECTORY_SEPARATOR . 'codex_sync_reload.sh';
    file_put_contents($pjsipFile, $pjsip);
    file_put_contents($queuesFile, $queues);
    file_put_contents($extensionsFile, $extensions);
    file_put_contents($scriptFile, <<<'SH'
set -e
stamp=$(date +%s)
cp /etc/asterisk/pjsip.conf /etc/asterisk/pjsip.conf.codexbak-sync-$stamp
cp /etc/asterisk/queues.conf /etc/asterisk/queues.conf.codexbak-sync-$stamp
cp /etc/asterisk/extensions.conf /etc/asterisk/extensions.conf.codexbak-sync-$stamp
python3 - <<'PY'
from pathlib import Path
for path, marker in [
    (Path('/etc/asterisk/pjsip.conf'), '; === Codex atendentes WebRTC ==='),
    (Path('/etc/asterisk/queues.conf'), '; === Codex fila de transferencia da discadora ==='),
]:
    text = path.read_text()
    if marker in text:
        text = text.split(marker)[0].rstrip() + '\n'
        path.write_text(text)

extensions = Path('/etc/asterisk/extensions.conf')
text = extensions.read_text()
for block in ('[from-webrtc]\n', '[from-agents]\n'):
    start = text.find(block)
    if start != -1:
        next_context = text.find('\n[', start + len(block))
        if next_context == -1:
            text = text[:start].rstrip() + '\n'
        else:
            text = text[:start].rstrip() + '\n' + text[next_context + 1:]
extensions.write_text(text)
PY
install -o asterisk -g asterisk -m 0644 /tmp/pjsip_codex_agents.conf /etc/asterisk/pjsip_codex_agents.conf
install -o asterisk -g asterisk -m 0644 /tmp/queues_codex_discadora.conf /etc/asterisk/queues_codex_discadora.conf
install -o asterisk -g asterisk -m 0644 /tmp/extensions_codex_agents.conf /etc/asterisk/extensions_codex_agents.conf
grep -qxF '#include pjsip_codex_agents.conf' /etc/asterisk/pjsip.conf || printf '\n#include pjsip_codex_agents.conf\n' >> /etc/asterisk/pjsip.conf
grep -qxF '#include queues_codex_discadora.conf' /etc/asterisk/queues.conf || printf '\n#include queues_codex_discadora.conf\n' >> /etc/asterisk/queues.conf
grep -qxF '#include extensions_codex_agents.conf' /etc/asterisk/extensions.conf || printf '\n#include extensions_codex_agents.conf\n' >> /etc/asterisk/extensions.conf
asterisk -rx 'pjsip reload'
asterisk -rx 'queue reload all'
asterisk -rx 'dialplan reload'
asterisk -rx 'queue show discadora'
SH);

    $remote = $user . '@' . $host;
    $scpBase = 'scp -i ' . dq($key) . ' -o StrictHostKeyChecking=no ';
    run_sync_command($scpBase . dq($pjsipFile) . ' ' . $remote . ':/tmp/pjsip_codex_agents.conf');
    run_sync_command($scpBase . dq($queuesFile) . ' ' . $remote . ':/tmp/queues_codex_discadora.conf');
    run_sync_command($scpBase . dq($extensionsFile) . ' ' . $remote . ':/tmp/extensions_codex_agents.conf');
    run_sync_command($scpBase . dq($scriptFile) . ' ' . $remote . ':/tmp/codex_sync_reload.sh');
    run_sync_command('ssh -i ' . dq($key) . ' -o StrictHostKeyChecking=no ' . $remote . ' bash /tmp/codex_sync_reload.sh');
}

function sync_asterisk_ivrs(): void
{
    if (setting('asterisk_sync_enabled', '1') !== '1') {
        return;
    }
    $host = trim((string)setting('asterisk_sync_host', ''));
    $user = trim((string)setting('asterisk_sync_user', 'root'));
    $key = trim((string)setting('asterisk_sync_key', __DIR__ . '/data/asterisk_sync_ed25519'));
    if ($host === '' || $user === '' || $key === '' || !is_file($key)) {
        throw new RuntimeException('configuracao SSH do Asterisk incompleta.');
    }

    $db = Database::conn();
    $ivrs = $db->query("SELECT i.*, a.file_path, a.file_name FROM ivrs i LEFT JOIN auto_audios a ON a.id=i.audio_id WHERE i.active=1 ORDER BY i.id")->fetchAll();
    $optionsStmt = $db->prepare('SELECT * FROM ivr_options WHERE ivr_id=? AND active=1 ORDER BY priority_order, digit');
    $contexts = "; Arquivo gerado pela Discadora SIP. Nao edite manualmente.\n\n";
    $panelUrl = rtrim((string)setting('app_public_url', ''), '/');
    $eventSecret = (string)setting('asterisk_ivr_event_secret', '');
    $audioCopies = [];

    foreach ($ivrs as $ivr) {
        $ivrId = (int)$ivr['id'];
        $mode = (string)($ivr['mode'] ?? 'normal');
        $name = asterisk_dialplan_text((string)$ivr['name']);
        $timeout = max(3, min(60, (int)($ivr['timeout_seconds'] ?? 10)));
        $attempts = max(1, min(10, (int)($ivr['max_attempts'] ?? 2)));
        $prompt = '';
        if (!empty($ivr['file_path'])) {
            $full = __DIR__ . '/' . ltrim((string)$ivr['file_path'], '/\\');
            if (is_file($full)) {
                $remoteBase = 'discadora/ivr_' . $ivrId;
                $prompt = $remoteBase;
                $audioCopies[] = ['local' => $full, 'remote' => '/var/lib/asterisk/sounds/' . $remoteBase . '.wav'];
                $audioCopies[] = ['local' => $full, 'remote' => '/var/lib/asterisk/sounds/' . $remoteBase . '.alaw'];
                $audioCopies[] = ['local' => $full, 'remote' => '/var/lib/asterisk/sounds/' . $remoteBase . '.ulaw'];
            }
        }
        $optionsStmt->execute([$ivrId]);
        $options = $optionsStmt->fetchAll();

        $contexts .= "[discadora-ivr-{$ivrId}]\n";
        $contexts .= "exten => s,1,NoOp(URA {$ivrId} - {$name})\n";
        $contexts .= " same => n,Gosub(discadora-ivr-menu-{$ivrId},s,1)\n";
        $contexts .= " same => n,Hangup()\n\n";

        $contexts .= "[discadora-ivr-disparo-{$ivrId}]\n";
        $contexts .= "exten => _X.,1,NoOp(Disparo URA {$ivrId} para \${EXTEN})\n";
        $contexts .= " same => n,Set(__IVR_ID={$ivrId})\n";
        $contexts .= " same => n,Set(__IVR_PHONE=\${EXTEN})\n";
        $contexts .= " same => n,ExecIf(\$[\"\${IVR_CALLERID}\"!=\"\"]?Set(CALLERID(num)=\${IVR_CALLERID}))\n";
        $contexts .= " same => n,ExecIf(\$[\"\${IVR_CALLERID}\"!=\"\"]?Set(CALLERID(name)=\${IVR_CALLERID}))\n";
        $contexts .= " same => n,Dial(PJSIP/\${EXTEN}@magnus,60,U(discadora-ivr-menu-{$ivrId}^s^1))\n";
        $contexts .= " same => n,Hangup()\n\n";

        $contexts .= "[discadora-ivr-menu-{$ivrId}]\n";
        $contexts .= "exten => s,1,NoOp(Menu URA {$ivrId})\n";
        $contexts .= " same => n,Set(__IVR_ID={$ivrId})\n";
        $contexts .= " same => n,Set(__IVR_PHONE=\${IF(\$[\"\${IVR_PHONE}\"=\"\"]?\${CALLERID(num)}:\${IVR_PHONE})})\n";
        $contexts .= " same => n,ExecIf(\$[\"\${IVR_CALLERID}\"!=\"\"]?Set(CALLERID(num)=\${IVR_CALLERID}))\n";
        $contexts .= " same => n,ExecIf(\$[\"\${IVR_CALLERID}\"!=\"\"]?Set(CALLERID(name)=\${IVR_CALLERID}))\n";
        $contexts .= " same => n,Set(TIMEOUT(digit)={$timeout})\n";
        $contexts .= " same => n,Read(IVR_DIGIT," . clean_asterisk_sound($prompt) . ",1,,{$attempts},{$timeout})\n";
        $contexts .= " same => n,System(/bin/echo '\${STRFTIME(\${EPOCH},,%Y-%m-%d %H:%M:%S)}|{$ivrId}|\${UNIQUEID}|\${IVR_PHONE}|\${IVR_DIGIT}' >> /var/log/asterisk/discadora_ivr_digits.log)\n";
        if ($panelUrl !== '') {
            $callback = $panelUrl . '/index.php?page=api_ivr_digit_event'
                . '&secret=' . rawurlencode($eventSecret)
                . '&ivr_id=' . $ivrId
                . '&phone=${IVR_PHONE}'
                . '&digit=${IVR_DIGIT}'
                . '&call_id=${UNIQUEID}';
            $contexts .= " same => n,System(curl -fsS --max-time 2 '" . str_replace("'", "'\\''", $callback) . "' >/dev/null 2>&1 &)\n";
        }
        foreach ($options as $idx => $option) {
            $digit = preg_replace('/[^0-9*#]/', '', (string)$option['digit']);
            if ($digit === '') {
                continue;
            }
            $contexts .= " same => n,GotoIf(\$[\"\${IVR_DIGIT}\"=\"{$digit}\"]?opcao" . ($idx + 1) . ",1)\n";
        }
        $contexts .= " same => n,Goto(invalida,1)\n";
        foreach ($options as $idx => $option) {
            $contexts .= ivr_option_dialplan('opcao' . ($idx + 1), (string)$option['destination_type'], (string)$option['destination'], (string)$option['label']);
        }
        $contexts .= ivr_option_dialplan('invalida', 'desligar', 'hangup', 'Opcao invalida');
        $contexts .= "\n";
    }

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'discadora_ivrs_' . bin2hex(random_bytes(4));
    if (!mkdir($tmp) && !is_dir($tmp)) {
        throw new RuntimeException('nao foi possivel criar temporario de URA.');
    }
    $extensionsFile = $tmp . DIRECTORY_SEPARATOR . 'extensions_codex_ivrs.conf';
    file_put_contents($extensionsFile, $contexts);
    foreach ($audioCopies as $idx => $copy) {
        $normalized = $tmp . DIRECTORY_SEPARATOR . basename((string)$copy['remote']);
        normalize_audio_for_asterisk((string)$copy['local'], $normalized);
        $audioCopies[$idx]['local'] = $normalized;
    }

    $remote = $user . '@' . $host;
    $scpBase = 'scp -i ' . dq($key) . ' -o StrictHostKeyChecking=no ';
    run_sync_command($scpBase . dq($extensionsFile) . ' ' . $remote . ':/tmp/extensions_codex_ivrs.conf');
    foreach ($audioCopies as $copy) {
        run_sync_command('ssh -i ' . dq($key) . ' -o StrictHostKeyChecking=no ' . $remote . ' ' . dq('mkdir -p /var/lib/asterisk/sounds/discadora'));
        run_sync_command($scpBase . dq($copy['local']) . ' ' . $remote . ':' . dq($copy['remote']));
    }
    $script = <<<'SH'
set -e
install -o asterisk -g asterisk -m 0644 /tmp/extensions_codex_ivrs.conf /etc/asterisk/extensions_codex_ivrs.conf
chown -R asterisk:asterisk /var/lib/asterisk/sounds/discadora 2>/dev/null || true
grep -qxF '#include extensions_codex_ivrs.conf' /etc/asterisk/extensions.conf || printf '\n#include extensions_codex_ivrs.conf\n' >> /etc/asterisk/extensions.conf
asterisk -rx 'dialplan reload'
SH;
    asterisk_remote_shell($script, 20);
}

function ivr_option_dialplan(string $exten, string $type, string $destination, string $label): string
{
    $type = in_array($type, ['fila', 'ramal', 'audio', 'desligar'], true) ? $type : 'desligar';
    $destination = clean_asterisk_sound($destination);
    $label = asterisk_dialplan_text($label);
    $out = "exten => {$exten},1,NoOp(URA destino {$label})\n";
    if ($type === 'fila') {
        $out .= " same => n,Goto(from-webrtc,{$destination},1)\n";
    } elseif ($type === 'ramal') {
        $out .= " same => n,Goto(from-agents,{$destination},1)\n";
    } elseif ($type === 'audio') {
        $out .= " same => n,Playback({$destination})\n same => n,Hangup()\n";
    } else {
        $out .= " same => n,Hangup()\n";
    }
    return $out;
}

function normalize_audio_for_asterisk(string $source, string $destination): void
{
    $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    $format = match ($ext) {
        'alaw' => '-ac 1 -ar 8000 -f alaw',
        'ulaw' => '-ac 1 -ar 8000 -f mulaw',
        default => '-ac 1 -ar 8000 -sample_fmt s16',
    };
    $cmd = 'ffmpeg -y -hide_banner -loglevel error -i ' . escapeshellarg($source)
        . ' ' . $format . ' ' . escapeshellarg($destination);
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    $minSize = in_array($ext, ['alaw', 'ulaw'], true) ? 1 : 44;
    if ($code !== 0 || !is_file($destination) || filesize($destination) <= $minSize) {
        throw new RuntimeException('falha ao converter audio para Asterisk: ' . trim(implode("\n", $output)));
    }
}

function asterisk_dialplan_text(string $value): string
{
    return str_replace(["\r", "\n", ';'], ' ', trim($value));
}

function generate_unique_extension(array $reserved = []): string
{
    $db = Database::conn();
    for ($i = 0; $i < 500; $i++) {
        $extension = (string)random_int(10000, 99999);
        if (in_array($extension, $reserved, true)) {
            continue;
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM extensions WHERE extension = ?');
        $stmt->execute([$extension]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $extension;
        }
    }
    throw new RuntimeException('nao foi possivel gerar ramal unico.');
}

function generate_extension_secret(): string
{
    return substr(strtr(base64_encode(random_bytes(9)), '+/', 'AZ'), 0, 12);
}

function clean_asterisk_sound(string $value): string
{
    return trim(preg_replace('/[^A-Za-z0-9_\/.-]/', '', $value));
}

function dq(string $value): string
{
    return '"' . str_replace('"', '\"', $value) . '"';
}

function run_sync_command(string $command, int $timeoutSeconds = 45): string
{
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('nao foi possivel iniciar comando SSH.');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }
    $started = time();
    $output = '';
    $error = '';
    while (true) {
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((time() - $started) >= $timeoutSeconds) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            throw new RuntimeException('tempo esgotado ao consultar o Asterisk via SSH.');
        }
        usleep(100000);
    }
    foreach ($pipes as $pipe) {
        $output .= stream_get_contents($pipe);
        fclose($pipe);
    }
    $code = proc_close($process);
    $text = trim($output . "\n" . $error);
    if ($code !== 0) {
        throw new RuntimeException($text ?: 'comando SSH falhou.');
    }
    return $text;
}

function render_transfer_target_options(?string $selected = null, bool $includeLinks = false): void
{
    foreach (transfer_targets() as $target) {
        echo '<option value="' . e($target['value']) . '" ' . ((string)$selected === $target['value'] ? 'selected' : '') . '>' . e($target['label']) . '</option>';
    }
}

function normalize_history_date(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('Y-m-d') : date('Y-m-d');
}

function call_history_rows(string $date): array
{
    $date = normalize_history_date($date);
    $stmt = Database::conn()->prepare("SELECT extension, number, caller_id, status, started_at, answered_at, ended_at, duration_seconds, details FROM agent_call_logs WHERE date(started_at)=? ORDER BY started_at DESC, id DESC");
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

function call_history_day_summary(string $date): array
{
    $rows = call_history_rows($date);
    $summary = ['total' => count($rows), 'answered' => 0, 'failed' => 0, 'pending' => 0, 'duration' => 0, 'agents' => 0];
    $agents = [];
    foreach ($rows as $row) {
        $status = (string)$row['status'];
        if (in_array($status, ['Atendido', 'Finalizado'], true)) {
            $summary['answered']++;
        } elseif (in_array($status, ['Nao atendido', 'Pausado'], true)) {
            $summary['failed']++;
        } elseif ($status === 'Pendente') {
            $summary['pending']++;
        }
        $summary['duration'] += max(0, (int)$row['duration_seconds']);
        $agents[(string)$row['extension']] = true;
    }
    $summary['agents'] = count($agents);
    return $summary;
}

function call_history_week(string $date): array
{
    $selected = new DateTimeImmutable(normalize_history_date($date));
    $start = $selected->modify('monday this week');
    $labels = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'];
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $day = $start->modify('+' . $i . ' days');
        $summary = call_history_day_summary($day->format('Y-m-d'));
        $week[] = [
            'date' => $day->format('Y-m-d'),
            'label' => $labels[$i],
            'short' => $labels[$i],
            'total' => $summary['total'],
            'answered' => $summary['answered'],
            'failed' => $summary['failed'],
            'duration' => $summary['duration'],
        ];
    }
    return $week;
}

function format_seconds_short(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm ' . ($seconds % 60) . 's';
}

function export_call_history(string $date, string $format): never
{
    $date = normalize_history_date($date);
    $format = strtolower($format) === 'txt' ? 'txt' : 'csv';
    $rows = call_history_rows($date);
    $filename = 'historico_ligacoes_' . $date . '.' . $format;
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    $headers = ['Numero', 'Status', 'Ramal', 'Caller ID', 'Duracao', 'Data e hora'];
    fwrite($out, implode(';', $headers) . "\r\n");
    foreach ($rows as $row) {
        $line = [
            display_call_number((string)$row['number']),
            (string)$row['status'],
            (string)$row['extension'],
            (string)($row['caller_id'] ?: ''),
            format_channel_duration((string)((int)$row['duration_seconds'])),
            (string)$row['started_at'],
        ];
        fwrite($out, implode(';', array_map('export_cell', $line)) . "\r\n");
    }
    fclose($out);
    exit;
}

function export_cell(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', $value);
    if (str_contains($value, ';') || str_contains($value, '"')) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

function render_header(string $title, ?array $user): void
{
    $adminNav = [
        'dashboard' => ['Dashboard', 'bi-speedometer2'],
        'users' => ['Contas', 'bi-person-vcard'],
        'softphone' => ['SIP', 'bi-telephone'],
        'extensions' => ['Ramais', 'bi-headset'],
        'queues' => ['Filas', 'bi-diagram-2'],
        'auto' => ['Discagem Auto', 'bi-robot', [
            'campaigns' => ['Campanhas', 'bi-broadcast'],
            'auto_agendas' => ['Agenda', 'bi-journal-bookmark'],
            'auto_audios' => ['Audios', 'bi-music-note-beamed'],
        ]],
        'online_calls' => ['Chamadas Online', 'bi-activity'],
        'call_history' => ['Historico', 'bi-calendar-week'],
        'credits' => ['Creditos', 'bi-cash-coin'],
        'routes' => ['Rotas', 'bi-signpost-split'],
        'ivrs' => ['URA', 'bi-diagram-3'],
        'reverse_ivrs' => ['URA Reversa', 'bi-arrow-repeat'],
        'sip' => ['Troncos', 'bi-hdd-network'],
        'monitor' => ['Monitoramento', 'bi-eye'],
        'audit' => ['Auditoria', 'bi-shield-check'],
        'settings' => ['Configuracoes', 'bi-sliders2-vertical'],
    ];
    $clientNav = [
        'dashboard' => ['Dashboard', 'bi-speedometer2'],
        'softphone' => ['SIP', 'bi-telephone'],
        'queues' => ['Filas', 'bi-diagram-2'],
        'auto' => ['Discagem Auto', 'bi-robot', [
            'campaigns' => ['Campanhas', 'bi-broadcast'],
            'auto_agendas' => ['Agenda', 'bi-journal-bookmark'],
            'auto_audios' => ['Audios', 'bi-music-note-beamed'],
        ]],
    ];
    $nav = ($user && ($user['role'] ?? '') === 'admin') ? $adminNav : $clientNav;
    $logo = brand_logo_url();
    $theme = setting('default_theme', 'light') === 'dark' ? 'theme-dark' : 'theme-light';
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', (string)setting('brand_primary', '#16745f')) ? (string)setting('brand_primary', '#16745f') : '#16745f';
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)setting('brand_accent', '#d68a1f')) ? (string)setting('brand_accent', '#d68a1f') : '#d68a1f';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="assets/style.css?v=<?= e((string)filemtime(__DIR__ . '/assets/style.css')) ?>">
    </head>
    <body class="<?= e($theme) ?>" style="--primary: <?= e($primary) ?>; --primary-dark: <?= e($primary) ?>; --accent: <?= e($accent) ?>;">
    <?php if ($user): ?>
        <div class="mobile-backdrop" data-mobile-backdrop hidden></div>
        <aside class="sidebar">
            <button class="sidebar-collapse" type="button" data-sidebar-collapse aria-label="Recolher menu"><i class="bi bi-chevron-left"></i></button>
            <div class="brand">
                <?php if ($logo): ?>
                    <img class="brand-logo" src="<?= e($logo) ?>" alt="<?= e(app_display_name()) ?>">
                <?php else: ?>
                    <span class="brand-mark">DS</span>
                <?php endif; ?>
                <div>
                    <strong><?= e(app_display_name()) ?></strong>
                    <small><?= e($user['name']) ?></small>
                </div>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($nav as $key => $item): ?>
                    <?php [$label, $icon] = $item; $children = $item[2] ?? null; ?>
                    <?php if (is_array($children)): $open = array_key_exists(($_GET['page'] ?? 'dashboard'), $children); ?>
                        <div class="nav-group <?= $open ? 'open' : '' ?>">
                            <span><i class="bi <?= e($icon) ?>"></i><strong><?= e($label) ?></strong></span>
                            <div>
                                <?php foreach ($children as $childKey => [$childLabel, $childIcon]): ?>
                                    <a href="?page=<?= e($childKey) ?>" class="<?= ($_GET['page'] ?? 'dashboard') === $childKey ? 'active' : '' ?>"><i class="bi <?= e($childIcon) ?>"></i><span><?= e($childLabel) ?></span></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="?page=<?= e($key) ?>" class="<?= ($_GET['page'] ?? 'dashboard') === $key ? 'active' : '' ?>"><i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <a class="logout" href="?action=logout"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
        </aside>
        <main class="app">
            <header class="app-topbar">
                <button class="mobile-menu-btn" type="button" data-mobile-menu aria-label="Abrir menu"><i class="bi bi-list"></i></button>
                <div class="topbar-greeting">
                    <span><?= e(($user['role'] ?? '') === 'admin' ? 'Administrador' : 'Cliente') ?></span>
                    <strong>Ola, <?= e((string)$user['name']) ?></strong>
                </div>
                <nav class="topbar-actions">
                    <button class="theme-toggle" type="button" data-theme-toggle aria-label="Trocar tema"><i class="bi bi-moon-stars"></i><span>Tema</span></button>
                    <a href="?page=dashboard"><i class="bi bi-house-door"></i><span>Inicio</span></a>
                    <a class="logout-action" href="?action=logout"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
                </nav>
            </header>
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
    <?php if (Auth::user()): ?></main><?php endif; ?>
    <div class="ui-modal" data-ui-modal hidden>
        <div class="ui-modal-card" role="dialog" aria-modal="true" aria-labelledby="ui-modal-title">
            <div class="ui-modal-icon"><i class="bi bi-info-circle"></i></div>
            <h2 id="ui-modal-title" data-ui-modal-title>Aviso</h2>
            <p data-ui-modal-message></p>
            <div class="ui-modal-actions">
                <button class="btn" type="button" data-ui-modal-cancel>Cancelar</button>
                <button class="btn primary" type="button" data-ui-modal-ok>OK</button>
            </div>
        </div>
    </div>
    <script src="assets/sip.min.js?v=<?= e((string)filemtime(__DIR__ . '/assets/sip.min.js')) ?>"></script>
    <script src="assets/app.js?v=<?= e((string)filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

function render_flash(): void
{
    $flash = flash();
    if ($flash) {
        echo '<div class="flash-modal-source" data-flash-type="' . e($flash['type']) . '" data-flash-message="' . e($flash['message']) . '" hidden></div>';
    }
}

function page_title(string $page): string
{
    return [
        'dashboard' => 'Dashboard',
        'softphone' => 'SIP',
        'users' => 'Contas',
        'ami' => 'Configuracao AMI',
        'sip' => 'Troncos',
        'extensions' => 'Ramais',
        'queues' => 'Filas',
        'campaigns' => 'Discagens',
        'campaign' => 'Campanha',
        'credits' => 'Creditos e Recargas',
        'routes' => 'Rotas e Taxas',
        'ivrs' => 'URA',
        'reverse_ivrs' => 'URA Reversa',
        'settings' => 'Configuracoes',
        'auto_agendas' => 'Agenda',
        'auto_audios' => 'Audios',
        'online_calls' => 'Chamadas Online',
        'call_history' => 'Historico de Ligacoes',
        'monitor' => 'Monitoramento',
        'audit' => 'Auditoria',
    ][$page] ?? 'Discadora SIP';
}

function page_system_notice(string $title, ?string $message, string $icon): void
{
    ?>
    <section class="system-notice panel">
        <i class="bi <?= e($icon) ?>"></i>
        <h1><?= e($title) ?></h1>
        <p><?= e($message ?: '') ?></p>
        <a class="btn logout-action" href="?action=logout"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
    </section>
    <?php
}

function page_settings(): void
{
    Auth::requireAdmin();
    $logo = brand_logo_url();
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Whitelabel e infraestrutura</p>
            <h1>Configuracoes</h1>
            <p class="muted">Personalize a interface, seguranca, bloqueio, manutencao e servidores integrados.</p>
        </div>
    </section>
    <form method="post" enctype="multipart/form-data" class="settings-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">

        <section class="panel settings-card">
            <h2><i class="bi bi-palette"></i> Whitelabel</h2>
            <div class="brand-preview">
                <?php if ($logo): ?>
                    <img src="<?= e($logo) ?>" alt="<?= e(app_display_name()) ?>">
                <?php else: ?>
                    <span class="brand-mark">DS</span>
                <?php endif; ?>
                <div>
                    <strong><?= e(app_display_name()) ?></strong>
                    <small>Logo e nome usados no login, menu e cabecalho.</small>
                </div>
            </div>
            <div class="form-grid two">
                <label>Nome do sistema <input name="brand_name" value="<?= e((string)setting('brand_name', app_display_name())) ?>"></label>
                <label>Tema padrao
                    <select name="default_theme">
                        <option value="light" <?= setting('default_theme', 'light') === 'light' ? 'selected' : '' ?>>Claro</option>
                        <option value="dark" <?= setting('default_theme', 'light') === 'dark' ? 'selected' : '' ?>>Escuro</option>
                    </select>
                </label>
                <label>Cor principal <input type="color" name="brand_primary" value="<?= e((string)setting('brand_primary', '#16745f')) ?>"></label>
                <label>Cor de destaque <input type="color" name="brand_accent" value="<?= e((string)setting('brand_accent', '#d68a1f')) ?>"></label>
                <label class="full">Subir logo <input type="file" name="brand_logo" accept=".png,.jpg,.jpeg,.webp,.svg,image/*"></label>
                <label class="toggle full"><input type="checkbox" name="remove_logo" value="1"> Remover logo atual</label>
            </div>
        </section>

        <section class="panel settings-card">
            <h2><i class="bi bi-shield-check"></i> Login e Google reCAPTCHA</h2>
            <label class="toggle"><input type="checkbox" name="google_recaptcha_enabled" value="1" <?= setting('google_recaptcha_enabled', '0') === '1' ? 'checked' : '' ?>> Ativar captcha no login</label>
            <div class="form-grid two">
                <label>Site key <input name="google_recaptcha_site_key" value="<?= e((string)setting('google_recaptcha_site_key', '')) ?>"></label>
                <label>Secret key <input name="google_recaptcha_secret_key" value="<?= e((string)setting('google_recaptcha_secret_key', '')) ?>"></label>
            </div>
        </section>

        <section class="panel settings-card">
            <h2><i class="bi bi-lock"></i> Bloqueio e manutencao</h2>
            <div class="form-grid two">
                <label class="toggle"><input type="checkbox" name="system_block_enabled" value="1" <?= setting('system_block_enabled', '0') === '1' ? 'checked' : '' ?>> Ativar pagina de bloqueio para clientes</label>
                <label class="toggle"><input type="checkbox" name="maintenance_enabled" value="1" <?= setting('maintenance_enabled', '0') === '1' ? 'checked' : '' ?>> Ativar manutencao programada</label>
                <label class="full">Frase da pagina de bloqueio <textarea name="system_block_message"><?= e((string)setting('system_block_message', 'Acesso temporariamente bloqueado. Fale com o administrador.')) ?></textarea></label>
                <label class="full">Frase da manutencao <textarea name="maintenance_message"><?= e((string)setting('maintenance_message', 'Manutencao programada em andamento. Voltaremos em breve.')) ?></textarea></label>
            </div>
        </section>

        <section class="panel settings-card">
            <h2><i class="bi bi-pc-display-horizontal"></i> Servidor Asterisk</h2>
            <div class="form-grid two">
                <label>Host/IP <input name="asterisk_sync_host" value="<?= e((string)setting('asterisk_sync_host', '')) ?>"></label>
                <label>Usuario SSH <input name="asterisk_sync_user" value="<?= e((string)setting('asterisk_sync_user', 'root')) ?>"></label>
                <label class="full">Chave SSH <input name="asterisk_sync_key" value="<?= e((string)setting('asterisk_sync_key', '')) ?>"></label>
                <label>IP externo WebRTC/TURN <input name="asterisk_sync_external_ip" value="<?= e((string)setting('asterisk_sync_external_ip', '')) ?>"></label>
                <label>Fila padrao <input name="asterisk_sync_queue" value="<?= e((string)setting('asterisk_sync_queue', 'discadora')) ?>"></label>
                <label>Numero da fila padrao <input name="asterisk_sync_queue_exten" value="<?= e((string)setting('asterisk_sync_queue_exten', '700')) ?>"></label>
                <label class="full">URL publica do painel para eventos da URA <input name="app_public_url" value="<?= e((string)setting('app_public_url', '')) ?>" placeholder="https://seudominio.com/discadora"></label>
                <label class="full">Token dos eventos de digito da URA <input name="asterisk_ivr_event_secret" value="<?= e((string)setting('asterisk_ivr_event_secret', '')) ?>" placeholder="token forte para callback do Asterisk"></label>
            </div>
        </section>

        <section class="panel settings-card">
            <h2><i class="bi bi-hdd-network"></i> AMI do Asterisk</h2>
            <p class="muted">Usado para disparar campanhas, URA, URA Reversa e consultar chamadas em tempo real pelo servidor.</p>
            <div class="form-grid two">
                <label>Host AMI <input name="ami_host" value="<?= e((string)setting('ami_host', '177.7.53.168')) ?>"></label>
                <label>Porta AMI <input name="ami_port" type="number" value="<?= e((string)setting('ami_port', '5038')) ?>"></label>
                <label>Usuario AMI <input name="ami_user" value="<?= e((string)setting('ami_user', 'discadora_panel')) ?>"></label>
                <label>Senha AMI <input name="ami_secret" type="password" value="<?= e((string)setting('ami_secret', '')) ?>"></label>
                <label>Timeout <input name="ami_timeout" type="number" min="1" max="30" value="<?= e((string)setting('ami_timeout', '5')) ?>"></label>
                <label class="toggle"><input name="monitoring_enabled" value="1" type="checkbox" <?= setting('monitoring_enabled', '0') === '1' ? 'checked' : '' ?>> Habilitar monitoramento consentido</label>
                <label class="full">Aviso de monitoramento <textarea name="monitoring_notice"><?= e((string)setting('monitoring_notice', 'Monitoramento permitido somente com consentimento, finalidade administrativa e auditoria.')) ?></textarea></label>
            </div>
        </section>

        <section class="panel settings-card">
            <h2><i class="bi bi-router"></i> Servidor MagnusBilling</h2>
            <div class="form-grid two">
                <label>Host/IP <input name="magnus_sync_host" value="<?= e((string)setting('magnus_sync_host', '')) ?>"></label>
                <label>Usuario SSH <input name="magnus_sync_user" value="<?= e((string)setting('magnus_sync_user', 'root')) ?>"></label>
                <label class="full">Chave SSH <input name="magnus_sync_key" value="<?= e((string)setting('magnus_sync_key', '')) ?>"></label>
                <label>ID usuario modelo Magnus <input name="magnus_template_user_id" value="<?= e((string)setting('magnus_template_user_id', '33')) ?>"></label>
            </div>
        </section>

        <section class="panel settings-actions">
            <button class="btn primary" type="submit"><i class="bi bi-check2-circle"></i> Salvar configuracoes</button>
        </section>
    </form>
    <form method="post" class="panel settings-card inline-settings-test">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="test_ami">
        <h2><i class="bi bi-plug"></i> Teste AMI</h2>
        <p class="muted">Salve as configuracoes antes de testar caso tenha alterado algum campo acima.</p>
        <button class="btn" type="submit"><i class="bi bi-wifi"></i> Testar conexao AMI</button>
    </form>
    <?php
}

function page_dashboard(): void
{
    $today = date('Y-m-d');
    $summary = call_history_day_summary($today);
    $week = call_history_week($today);
    $activeNow = 0;
    try {
        $activeNow = (int)online_calls_snapshot()['total'];
    } catch (Throwable) {
        $activeNow = 0;
    }
    $simultaneous = (int)(Database::conn()->query("SELECT COALESCE(SUM(simultaneous), 0) FROM campaigns WHERE status='running'")->fetchColumn() ?: 0);
    $onlineAgents = (int)(Database::conn()->query("SELECT COUNT(*) FROM extensions WHERE active=1 AND " . extension_online_expr())->fetchColumn() ?: 0);
    $maxTotal = max(1, ...array_map(static fn(array $day): int => (int)$day['total'], $week));
    $chartWidth = 720;
    $chartHeight = 230;
    $chartPadX = 42;
    $chartTop = 24;
    $chartBottom = 44;
    $plotHeight = $chartHeight - $chartTop - $chartBottom;
    $points = [];
    $answeredPoints = [];
    $areaPoints = [];
    foreach ($week as $i => $day) {
        $x = $chartPadX + ($i * (($chartWidth - ($chartPadX * 2)) / 6));
        $y = $chartTop + ($plotHeight - (((int)$day['total'] / $maxTotal) * $plotHeight));
        $answeredY = $chartTop + ($plotHeight - (((int)$day['answered'] / $maxTotal) * $plotHeight));
        $points[] = round($x, 1) . ',' . round($y, 1);
        $answeredPoints[] = round($x, 1) . ',' . round($answeredY, 1);
        $areaPoints[] = [round($x, 1), round($y, 1)];
    }
    $area = $areaPoints ? implode(' ', array_map(static fn(array $p): string => $p[0] . ',' . $p[1], $areaPoints)) : '';
    if ($areaPoints) {
        $area .= ' ' . $areaPoints[array_key_last($areaPoints)][0] . ',' . ($chartHeight - $chartBottom) . ' ' . $areaPoints[0][0] . ',' . ($chartHeight - $chartBottom);
    }
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Operacao em tempo real</p>
            <h1>Dashboard</h1>
        </div>
        <div class="actions">
            <a class="btn" href="?page=call_history"><i class="bi bi-calendar-week"></i>Historico</a>
            <a class="btn primary" href="?page=campaigns"><i class="bi bi-plus-circle"></i>Nova discagem</a>
        </div>
    </section>
    <section class="dashboard-metrics">
        <article class="dash-card">
            <span class="dash-icon"><i class="bi bi-telephone-outbound"></i></span>
            <div><p>Chamadas ativas</p><strong><?= e((string)$activeNow) ?></strong></div>
        </article>
        <article class="dash-card">
            <span class="dash-icon"><i class="bi bi-layers"></i></span>
            <div><p>Limite simultaneo</p><strong><?= e((string)$simultaneous) ?></strong></div>
        </article>
        <article class="dash-card">
            <span class="dash-icon"><i class="bi bi-headset"></i></span>
            <div><p>Tempo conversado hoje</p><strong><?= e(format_seconds_short((int)$summary['duration'])) ?></strong></div>
        </article>
        <article class="dash-card">
            <span class="dash-icon"><i class="bi bi-person-check"></i></span>
            <div><p>Agentes online</p><strong><?= e((string)$onlineAgents) ?></strong></div>
        </article>
    </section>
    <section class="dashboard-grid">
        <article class="panel dashboard-chart">
            <div class="panel-title-row">
                <div>
                    <h2>Grafico semanal</h2>
                    <p class="muted">Cada coluna zera no inicio do dia.</p>
                </div>
                <a class="btn compact" href="?page=call_history"><i class="bi bi-list-ul"></i>Ver historico</a>
            </div>
            <div class="dashboard-chart-canvas">
                <svg viewBox="0 0 <?= e((string)$chartWidth) ?> <?= e((string)$chartHeight) ?>" role="img" aria-label="Grafico semanal de chamadas">
                    <defs>
                        <linearGradient id="callsArea" x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stop-color="#41b8ad" stop-opacity="0.28" />
                            <stop offset="100%" stop-color="#41b8ad" stop-opacity="0.02" />
                        </linearGradient>
                    </defs>
                    <?php for ($i = 0; $i <= 4; $i++): $y = $chartTop + (($plotHeight / 4) * $i); ?>
                        <line x1="<?= e((string)$chartPadX) ?>" y1="<?= e((string)$y) ?>" x2="<?= e((string)($chartWidth - $chartPadX)) ?>" y2="<?= e((string)$y) ?>" class="chart-grid" />
                    <?php endfor; ?>
                    <polygon points="<?= e($area) ?>" class="chart-area"></polygon>
                    <polyline points="<?= e(implode(' ', $points)) ?>" class="chart-line"></polyline>
                    <polyline points="<?= e(implode(' ', $answeredPoints)) ?>" class="chart-line answered"></polyline>
                    <?php foreach ($week as $i => $day): [$x, $y] = $areaPoints[$i]; ?>
                        <a href="?page=call_history&date=<?= e($day['date']) ?>">
                            <circle cx="<?= e((string)$x) ?>" cy="<?= e((string)$y) ?>" r="6" class="chart-point"></circle>
                            <text x="<?= e((string)$x) ?>" y="<?= e((string)($chartHeight - 18)) ?>" text-anchor="middle" class="chart-label"><?= e($day['short']) ?></text>
                            <text x="<?= e((string)$x) ?>" y="<?= e((string)max(16, $y - 12)) ?>" text-anchor="middle" class="chart-value"><?= e((string)$day['total']) ?></text>
                        </a>
                    <?php endforeach; ?>
                </svg>
                <div class="chart-legend">
                    <span><i></i>Total de chamadas</span>
                    <span><i class="answered"></i>Atendidas no dia</span>
                </div>
            </div>
        </article>
        <aside class="panel dashboard-summary">
            <h2><i class="bi bi-clipboard-data"></i>Resumo do dia</h2>
            <dl>
                <div><dt>Chamadas totais</dt><dd><?= e((string)$summary['total']) ?></dd></div>
                <div><dt>Atendidas</dt><dd><?= e((string)$summary['answered']) ?></dd></div>
                <div><dt>Falharam</dt><dd><?= e((string)$summary['failed']) ?></dd></div>
                <div><dt>Pendentes</dt><dd><?= e((string)$summary['pending']) ?></dd></div>
                <div><dt>Atend. agentes</dt><dd><?= e((string)$summary['agents']) ?></dd></div>
            </dl>
        </aside>
    </section>
    <?php recent_campaigns(); ?>
    <?php
}

function page_call_history(): void
{
    Auth::requireAdmin();
    $selectedDate = normalize_history_date((string)($_GET['date'] ?? date('Y-m-d')));
    $week = call_history_week($selectedDate);
    $rows = call_history_rows($selectedDate);
    $summary = call_history_day_summary($selectedDate);
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Calendario semanal</p>
            <h1>Historico de ligacoes</h1>
        </div>
        <form class="history-date-form" method="get">
            <input type="hidden" name="page" value="call_history">
            <label>Dia
                <input type="date" name="date" value="<?= e($selectedDate) ?>">
            </label>
            <button class="btn">Abrir</button>
        </form>
    </section>
    <section class="history-week">
        <?php foreach ($week as $day): ?>
            <a class="history-day <?= $day['date'] === $selectedDate ? 'active' : '' ?>" href="?page=call_history&date=<?= e($day['date']) ?>">
                <span><?= e($day['short']) ?></span>
                <strong><?= e((string)$day['total']) ?></strong>
                <small><?= e(date('d/m', strtotime($day['date']))) ?></small>
            </a>
        <?php endforeach; ?>
    </section>
    <section class="dashboard-metrics compact-history">
        <article class="dash-card"><span class="dash-icon"><i class="bi bi-collection"></i></span><div><p>Total do dia</p><strong><?= e((string)$summary['total']) ?></strong></div></article>
        <article class="dash-card"><span class="dash-icon"><i class="bi bi-check2-circle"></i></span><div><p>Atendidas</p><strong><?= e((string)$summary['answered']) ?></strong></div></article>
        <article class="dash-card"><span class="dash-icon"><i class="bi bi-x-circle"></i></span><div><p>Nao atendidas</p><strong><?= e((string)$summary['failed']) ?></strong></div></article>
        <article class="dash-card"><span class="dash-icon"><i class="bi bi-stopwatch"></i></span><div><p>Duracao</p><strong><?= e(format_seconds_short((int)$summary['duration'])) ?></strong></div></article>
    </section>
    <section class="panel history-panel">
        <div class="online-toolbar">
            <div>
                <h2><?= e(date('d/m/Y', strtotime($selectedDate))) ?></h2>
                <p class="muted">Registros desse dia. Ao trocar o dia, os contadores recomecam do zero.</p>
            </div>
            <div class="actions">
                <a class="btn" href="?page=call_history_export&format=txt&date=<?= e($selectedDate) ?>"><i class="bi bi-filetype-txt"></i>Exportar TXT</a>
                <a class="btn primary" href="?page=call_history_export&format=csv&date=<?= e($selectedDate) ?>"><i class="bi bi-filetype-csv"></i>Exportar CSV</a>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Numero</th><th>Status</th><th>Ramal</th><th>Caller ID</th><th>Duracao</th><th>Data e hora</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e(display_call_number((string)$row['number'])) ?></strong></td>
                            <td><span class="status-chip <?= in_array((string)$row['status'], ['Atendido', 'Finalizado'], true) ? 'ok' : ((string)$row['status'] === 'Pendente' ? '' : 'off') ?>"><?= e((string)$row['status']) ?></span></td>
                            <td><?= e((string)$row['extension']) ?></td>
                            <td><?= e((string)($row['caller_id'] ?: '-')) ?></td>
                            <td><strong><?= e(format_channel_duration((string)((int)$row['duration_seconds']))) ?></strong></td>
                            <td><?= e((string)$row['started_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="muted empty-cell">Nenhuma ligacao neste dia.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function page_users(): void
{
    Auth::requireAdmin();
    $db = Database::conn();
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = null;
    if ($editId > 0) {
        $stmt = $db->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$editId]);
        $editing = $stmt->fetch() ?: null;
    }
    $users = $db->query('SELECT u.*, mr.route_name, mr.plan_name, mr.rate_value FROM users u LEFT JOIN magnus_rates mr ON mr.id=u.default_magnus_rate_id ORDER BY u.id DESC')->fetchAll();
    $rates = $db->query('SELECT id, route_name, plan_name, rate_value, initial_block_seconds, billing_increment_seconds FROM magnus_rates WHERE active=1 ORDER BY route_name, ' . db_rate_cast_expr('rate_value') . ', plan_name')->fetchAll();
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Painel admin</p>
            <h1>Cadastro de Contas</h1>
            <p class="muted">Ao salvar, a conta e criada no Magnus usando o usuario asmo como modelo e a rota escolhida fica vinculada em plano isolado.</p>
        </div>
    </section>
    <section class="split">
        <form method="post" class="panel">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" value="<?= e((string)($editing['id'] ?? 0)) ?>">
            <h2><?= $editing ? 'Editar conta' : 'Nova conta' ?></h2>
            <label>Nome da conta <input name="name" required placeholder="Ex: Cliente teste" value="<?= e($editing['name'] ?? '') ?>"></label>
            <label>E-mail de acesso <input name="email" type="email" required placeholder="cliente@email.com" value="<?= e($editing['email'] ?? '') ?>"></label>
            <label><?= $editing ? 'Nova senha' : 'Senha inicial' ?> <input name="password" type="password" <?= $editing ? '' : 'required' ?> placeholder="<?= $editing ? 'Deixe vazio para manter' : 'Senha do painel' ?>"></label>
            <label>Tipo de conta <select name="role"><?php foreach (['cliente' => 'Cliente', 'admin' => 'Admin'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editing['role'] ?? 'cliente') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>Rota vinculada no Magnus <select name="default_magnus_rate_id" required><option value="">Escolha a rota</option><?php foreach ($rates as $rate): ?><option value="<?= e((string)$rate['id']) ?>" <?= (int)($editing['default_magnus_rate_id'] ?? 0) === (int)$rate['id'] ? 'selected' : '' ?>><?= e($rate['route_name'] . ' - ' . $rate['plan_name'] . ' (R$ ' . number_format((float)$rate['rate_value'], 2, ',', '.') . '/min | bloco ' . (int)($rate['initial_block_seconds'] ?? 30) . 's / inc. ' . (int)($rate['billing_increment_seconds'] ?? 6) . 's)') ?></option><?php endforeach; ?></select></label>
            <?php if (!$editing): ?><label>Saldo inicial <input name="initial_credit" inputmode="decimal" placeholder="0,00"></label><?php endif; ?>
            <label class="toggle"><input name="active" value="1" type="checkbox" <?= (int)($editing['active'] ?? 1) === 1 ? 'checked' : '' ?>> Liberado</label>
            <div class="actions">
                <button class="btn primary"><i class="bi <?= $editing ? 'bi-save' : 'bi-person-plus' ?>"></i><?= $editing ? 'Salvar alteracoes' : 'Criar conta e sincronizar' ?></button>
                <?php if ($editing): ?><a class="btn" href="?page=users">Cancelar</a><?php endif; ?>
            </div>
        </form>
        <div class="panel wide">
            <h2>Contas cadastradas</h2>
            <div class="table-wrap"><table><thead><tr><th>Conta</th><th>Magnus</th><th>Nivel</th><th>Saldo</th><th>Consumido</th><th>Rota</th><th>Fila</th><th>Status</th><th>Acoes</th></tr></thead><tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong><br><small><?= e($row['email']) ?></small></td>
                        <td><?= e($row['magnus_username'] ?: '-') ?><br><small>ID <?= e((string)($row['magnus_user_id'] ?: '-')) ?></small></td>
                        <td><?= e($row['role']) ?></td>
                        <td><strong>R$ <?= e(number_format((float)$row['credit_balance'], 2, ',', '.')) ?></strong></td>
                        <td>R$ <?= e(number_format((float)$row['credit_consumed'], 2, ',', '.')) ?></td>
                        <td><?= e(($row['route_name'] ?: '-') . ($row['rate_value'] ? ' R$ ' . number_format((float)$row['rate_value'], 2, ',', '.') : '')) ?></td>
                        <td><?= e(($row['queue_exten'] ?: '-') . ' / ' . ($row['queue_name'] ?: '-')) ?></td>
                        <td><?= (int)$row['active'] === 1 ? '<span class="status-chip ok">Liberado</span>' : '<span class="status-chip error">Bloqueado</span>' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn compact" href="?page=users&edit=<?= e((string)$row['id']) ?>"><i class="bi bi-pencil"></i>Editar</a>
                                <form method="post" data-confirm="Excluir esta conta?">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                    <button class="btn compact danger" type="submit"><i class="bi bi-trash"></i>Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </section>
    <?php
}

function page_ami(): void
{
    Auth::requireAdmin();
    ?>
    <section class="page-head"><div><p class="eyebrow">Asterisk Manager Interface</p><h1>Conexao AMI</h1></div></section>
    <form method="post" class="panel form-grid">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_ami">
        <label>Host <input name="ami_host" value="<?= e(setting('ami_host')) ?>"></label>
        <label>Porta <input name="ami_port" type="number" value="<?= e(setting('ami_port')) ?>"></label>
        <label>Usuario AMI <input name="ami_user" value="<?= e(setting('ami_user')) ?>"></label>
        <label>Senha AMI <input name="ami_secret" type="password" value="<?= e(setting('ami_secret')) ?>"></label>
        <label>Timeout <input name="ami_timeout" type="number" value="<?= e(setting('ami_timeout')) ?>"></label>
        <label class="toggle"><input name="monitoring_enabled" value="1" type="checkbox" <?= setting('monitoring_enabled') === '1' ? 'checked' : '' ?>> Habilitar monitoramento consentido</label>
        <label class="full">Aviso de monitoramento <textarea name="monitoring_notice"><?= e(setting('monitoring_notice')) ?></textarea></label>
        <div class="actions"><button class="btn primary">Salvar</button></div>
    </form>
    <form method="post" class="inline-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="test_ami">
        <button class="btn">Testar conexao AMI</button>
    </form>
    <?php
}

function page_credits(): void
{
    Auth::requireAdmin();
    $db = Database::conn();
    $users = $db->query("SELECT id, name, email, credit_balance, credit_consumed, active FROM users ORDER BY name")->fetchAll();
    $transactions = $db->query("SELECT ct.*, u.name AS user_name, a.name AS admin_name FROM credit_transactions ct JOIN users u ON u.id=ct.user_id LEFT JOIN users a ON a.id=ct.created_by ORDER BY ct.created_at DESC LIMIT 200")->fetchAll();
    $totalInserted = (float)($db->query("SELECT COALESCE(SUM(" . db_rate_cast_expr('amount') . "),0) FROM credit_transactions WHERE type='credit'")->fetchColumn() ?: 0);
    $totalConsumed = (float)($db->query("SELECT COALESCE(SUM(" . db_rate_cast_expr('credit_consumed') . "),0) FROM users")->fetchColumn() ?: 0);
    ?>
    <section class="page-head"><div><p class="eyebrow">Financeiro</p><h1>Creditos e Recargas</h1></div></section>
    <section class="dashboard-grid">
        <div class="metric-card"><span><i class="bi bi-cash-coin"></i>Total inserido</span><strong>R$ <?= e(number_format($totalInserted, 2, ',', '.')) ?></strong></div>
        <div class="metric-card"><span><i class="bi bi-graph-down-arrow"></i>Saldo consumido</span><strong>R$ <?= e(number_format($totalConsumed, 2, ',', '.')) ?></strong></div>
        <div class="metric-card"><span><i class="bi bi-person-check"></i>Contas liberadas</span><strong><?= e((string)count(array_filter($users, static fn(array $u): bool => (int)$u['active'] === 1))) ?></strong></div>
    </section>
    <section class="split">
        <form method="post" class="panel">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="credit_adjust">
            <h2>Recarga manual</h2>
            <label>Usuario <select name="user_id"><?php foreach ($users as $u): ?><option value="<?= e((string)$u['id']) ?>"><?= e($u['name'] . ' - saldo R$ ' . number_format((float)$u['credit_balance'], 2, ',', '.')) ?></option><?php endforeach; ?></select></label>
            <label>Operacao <select name="type"><option value="credit">Adicionar saldo</option><option value="debit">Retirar saldo</option></select></label>
            <label>Valor <input name="amount" inputmode="decimal" placeholder="10,00" required></label>
            <label class="full">Observacao <textarea name="note" placeholder="Motivo da recarga ou ajuste"></textarea></label>
            <button class="btn primary">Salvar ajuste</button>
        </form>
        <section class="panel wide">
            <h2>Saldos por usuario</h2>
            <div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Status</th><th>Saldo</th><th>Consumido</th></tr></thead><tbody>
                <?php foreach ($users as $u): ?><tr><td><?= e($u['name']) ?><br><small><?= e($u['email']) ?></small></td><td><?= (int)$u['active'] === 1 ? 'Liberado' : 'Bloqueado' ?></td><td><strong>R$ <?= e(number_format((float)$u['credit_balance'], 2, ',', '.')) ?></strong></td><td>R$ <?= e(number_format((float)$u['credit_consumed'], 2, ',', '.')) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </section>
    <section class="panel">
        <h2>Historico de recargas e consumo</h2>
        <div class="table-wrap"><table><thead><tr><th>Data</th><th>Usuario</th><th>Tipo</th><th>Valor</th><th>Saldo apos</th><th>Observacao</th></tr></thead><tbody>
            <?php foreach ($transactions as $t): ?><tr><td><?= e($t['created_at']) ?></td><td><?= e($t['user_name']) ?></td><td><?= e($t['type']) ?></td><td>R$ <?= e(number_format((float)$t['amount'], 2, ',', '.')) ?></td><td>R$ <?= e(number_format((float)$t['balance_after'], 2, ',', '.')) ?></td><td><?= e($t['note'] ?: '-') ?></td></tr><?php endforeach; ?>
            <?php if (!$transactions): ?><tr><td colspan="6" class="muted">Nenhuma movimentacao.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php
}

function page_routes(): void
{
    Auth::requireAdmin();
    $rates = Database::conn()->query('SELECT * FROM magnus_rates WHERE active=1 ORDER BY route_name, ' . db_rate_cast_expr('rate_value') . ', plan_name')->fetchAll();
    ?>
    <section class="page-head">
        <div><p class="eyebrow">MagnusBilling</p><h1>Rotas e Taxas</h1><p class="muted">Preserva as rotas atuais e usa clone isolado por usuario ao aplicar.</p></div>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="sync_routes"><button class="btn primary"><i class="bi bi-arrow-repeat"></i>Sincronizar Magnus</button></form>
    </section>
    <section class="panel">
        <h2>Taxas disponiveis</h2>
        <div class="table-wrap"><table><thead><tr><th>Rota</th><th>Plano</th><th>Taxa</th><th>Bloco</th><th>Incremento</th><th>Destino</th><th>IDs Magnus</th></tr></thead><tbody>
            <?php foreach ($rates as $rate): ?><tr><td><?= e($rate['route_name']) ?></td><td><?= e($rate['plan_name']) ?></td><td><strong>R$ <?= e(number_format((float)$rate['rate_value'], 2, ',', '.')) ?>/min</strong></td><td><?= e((string)($rate['initial_block_seconds'] ?? 30)) ?>s</td><td><?= e((string)($rate['billing_increment_seconds'] ?? 6)) ?>s</td><td><?= e($rate['destination'] ?: '-') ?></td><td><?= e('plano ' . $rate['magnus_plan_id'] . ' / rota ' . $rate['magnus_route_id']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
}

function page_ivrs(string $mode): void
{
    Auth::requireAdmin();
    $mode = $mode === 'invertida' ? 'invertida' : 'normal';
    $pageUrl = $mode === 'invertida' ? '?page=reverse_ivrs' : '?page=ivrs';
    $db = Database::conn();
    $editId = (int)($_GET['edit'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM ivrs WHERE mode=? ORDER BY id DESC');
    $stmt->execute([$mode]);
    $rows = $stmt->fetchAll();
    $editing = null;
    $editingOptions = [];
    if ($editId > 0) {
        $stmt = $db->prepare('SELECT * FROM ivrs WHERE id=? AND mode=?');
        $stmt->execute([$editId, $mode]);
        $editing = $stmt->fetch() ?: null;
        if ($editing) {
            $opt = $db->prepare('SELECT * FROM ivr_options WHERE ivr_id=? ORDER BY priority_order, digit');
            $opt->execute([$editId]);
            $editingOptions = $opt->fetchAll();
        }
    }
    if (!$editingOptions) {
        $editingOptions = $mode === 'invertida'
            ? [
                ['digit' => '1', 'label' => 'Confirmar interesse', 'destination_type' => 'fila', 'destination' => setting('asterisk_sync_queue_exten', '700')],
                ['digit' => '2', 'label' => 'Nao tenho interesse', 'destination_type' => 'desligar', 'destination' => 'hangup'],
            ]
            : [
                ['digit' => '1', 'label' => 'Comercial', 'destination_type' => 'fila', 'destination' => setting('asterisk_sync_queue_exten', '700')],
                ['digit' => '2', 'label' => 'Atendimento', 'destination_type' => 'ramal', 'destination' => '02140'],
            ];
    }
    $audios = $db->query("SELECT id, title, type FROM auto_audios ORDER BY title")->fetchAll();
    $accounts = $db->query("SELECT id, name FROM sip_accounts WHERE active=1 ORDER BY name")->fetchAll();
    $extensions = $db->query("SELECT id, extension, name FROM extensions WHERE active=1 ORDER BY extension")->fetchAll();
    $agendas = $db->query("SELECT id, title FROM auto_agendas ORDER BY title")->fetchAll();
    $queues = [['value' => (string)setting('asterisk_sync_queue_exten', '700'), 'label' => 'Fila principal ' . setting('asterisk_sync_queue_exten', '700')]];
    foreach ($db->query("SELECT q.queue_number, q.name, u.name AS owner_name FROM call_queues q LEFT JOIN users u ON u.id=q.user_id WHERE q.active=1 ORDER BY u.name, q.name")->fetchAll() as $queue) {
        $queues[] = ['value' => (string)$queue['queue_number'], 'label' => trim((string)$queue['queue_number'] . ' - ' . (string)$queue['name'] . (!empty($queue['owner_name']) ? ' (' . (string)$queue['owner_name'] . ')' : ''))];
    }
    foreach ($db->query("SELECT queue_exten, queue_name, name FROM users WHERE queue_exten IS NOT NULL AND queue_exten<>'' ORDER BY name")->fetchAll() as $queue) {
        $queues[] = ['value' => (string)$queue['queue_exten'], 'label' => trim((string)$queue['name'] . ' - ' . (string)$queue['queue_exten'])];
    }
    $launchIvr = $editing ?: ($rows[0] ?? null);
    $launchIvrId = (int)($launchIvr['id'] ?? 0);
    $digitStmt = $db->prepare('SELECT e.*, i.name AS ivr_name, o.label AS option_label FROM ivr_digit_events e LEFT JOIN ivrs i ON i.id=e.ivr_id LEFT JOIN ivr_options o ON o.id=e.matched_option_id WHERE (? = 0 OR e.ivr_id = ?) ORDER BY e.created_at DESC LIMIT 30');
    $digitStmt->execute([$launchIvrId, $launchIvrId]);
    $digitEvents = $digitStmt->fetchAll();
    $callStmt = $db->prepare('SELECT * FROM ivr_call_events WHERE (? = 0 OR ivr_id = ?) ORDER BY created_at DESC, id DESC LIMIT 30');
    $callStmt->execute([$launchIvrId, $launchIvrId]);
    $callEvents = $callStmt->fetchAll();
    $title = $mode === 'invertida' ? 'URA Reversa' : 'URA';
    $subtitle = $mode === 'invertida'
        ? 'Dispara chamadas para telefone ou agenda, toca o primeiro audio ao atender, captura digitos e transfere para fila, ramal, audio ou desligamento.'
        : 'Fluxo receptivo tradicional: toca menu, captura digito e encaminha para ramal, fila, audio ou desligamento.';
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Telefonia inteligente</p>
            <h1><?= e($title) ?></h1>
            <p class="muted"><?= e($subtitle) ?></p>
        </div>
        <button class="btn primary" type="button" data-ivr-open><i class="bi bi-plus-lg"></i>Nova <?= e($title) ?></button>
    </section>

    <?php if ($rows): ?>
        <div class="ivr-sync-modal" data-ivr-sync-modal data-modal-key="ivr-sync-modal-hidden-<?= e($mode) ?>" hidden>
            <form method="post" class="ivr-sync-card">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="sync_ivr">
                <input type="hidden" name="mode" value="<?= e($mode) ?>">
                <div class="ivr-sync-icon"><i class="bi bi-arrow-repeat"></i></div>
                <div>
                    <p class="eyebrow">Sincronizacao do Asterisk</p>
                    <h2>Selecione um fluxo para sincronizar</h2>
                    <p class="muted">Antes de testar, mantenha o dialplan atualizado no servidor Asterisk.</p>
                </div>
                <label>Fluxo
                    <select name="id">
                        <?php foreach ($rows as $row): ?>
                            <option value="<?= e((string)$row['id']) ?>" <?= $launchIvrId === (int)$row['id'] ? 'selected' : '' ?>><?= e((string)$row['name']) ?><?= (int)$row['active'] === 1 ? '' : ' - Inativa' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="toggle ivr-sync-check"><input type="checkbox" data-ivr-sync-hide> Nao mostrar novamente</label>
                <div class="actions">
                    <button class="btn" type="button" data-ivr-sync-close>Agora nao</button>
                    <button class="btn primary" type="submit"><i class="bi bi-arrow-repeat"></i>Sincronizar</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <section class="ivr-console">
        <div class="ivr-config-modal" data-ivr-config-modal <?= $editing ? '' : 'hidden' ?>>
        <div class="ivr-config-card">
            <div class="ivr-config-head">
                <div>
                    <p class="eyebrow">Configuracao</p>
                    <h2><?= $editing ? 'Editar ' . e($title) : 'Nova ' . e($title) ?></h2>
                </div>
                <button class="icon-action" type="button" data-ivr-close><i class="bi bi-x-lg"></i></button>
            </div>
        <form method="post" class="panel ivr-builder" id="ivr-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_ivr">
            <input type="hidden" name="mode" value="<?= e($mode) ?>">
            <input type="hidden" name="id" value="<?= e((string)($editing['id'] ?? 0)) ?>">
            <div class="panel-title-row">
                <div>
                    <h2><?= $editing ? 'Editar fluxo' : 'Novo fluxo' ?></h2>
                </div>
                <label class="toggle"><input type="checkbox" name="active" value="1" <?= (int)($editing['active'] ?? 1) === 1 ? 'checked' : '' ?>> Ativa</label>
            </div>
            <section class="ivr-form-block">
                <h3><i class="bi bi-sliders"></i> Dados do fluxo</h3>
                <div class="form-grid two">
                    <label>Nome <input name="name" required value="<?= e((string)($editing['name'] ?? '')) ?>" placeholder="<?= e($title . ' principal') ?>"></label>
                    <label>Tronco SIP
                        <select name="sip_account_id">
                            <option value="">Automatico</option>
                            <?php foreach ($accounts as $account): ?><option value="<?= e((string)$account['id']) ?>" <?= (int)($editing['sip_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>><?= e((string)$account['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Ramal origem
                        <select name="source_extension_id">
                            <option value="">Automatico</option>
                            <?php foreach ($extensions as $ext): ?><option value="<?= e((string)$ext['id']) ?>" <?= (int)($editing['source_extension_id'] ?? 0) === (int)$ext['id'] ? 'selected' : '' ?>><?= e($ext['extension'] . ' - ' . $ext['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Agenda padrao
                        <select name="agenda_id">
                            <option value="">Nenhuma</option>
                            <?php foreach ($agendas as $agenda): ?><option value="<?= e((string)$agenda['id']) ?>" <?= (int)($editing['agenda_id'] ?? 0) === (int)$agenda['id'] ? 'selected' : '' ?>><?= e((string)$agenda['title']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Bina da URA <input name="caller_id" value="<?= e((string)($editing['caller_id'] ?? '')) ?>" placeholder="Numero que vai aparecer na chamada"></label>
                    <label>Simultaneas <input type="number" name="simultaneous" min="1" max="200" value="<?= e((string)($editing['simultaneous'] ?? 1)) ?>"></label>
                    <label class="full">Descricao <textarea name="description" placeholder="Ex: menu de entrada, campanha ativa, pesquisa, confirmacao..."><?= e((string)($editing['description'] ?? '')) ?></textarea></label>
                </div>
            </section>

            <section class="ivr-form-block">
                <h3><i class="bi bi-volume-up"></i> Audio e captura</h3>
                <div class="form-grid two">
                    <label>Audio principal
                    <select name="audio_id">
                        <option value="">Nenhum audio</option>
                        <?php foreach ($audios as $audio): ?>
                            <option value="<?= e((string)$audio['id']) ?>" <?= (int)($editing['audio_id'] ?? 0) === (int)$audio['id'] ? 'selected' : '' ?>><?= e($audio['title'] . ' - ' . $audio['type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    </label>
                    <label>Tempo para digitar (seg.) <input type="number" name="timeout_seconds" min="3" max="60" value="<?= e((string)($editing['timeout_seconds'] ?? 10)) ?>"></label>
                    <label>Tentativas maximas <input type="number" name="max_attempts" min="1" max="10" value="<?= e((string)($editing['max_attempts'] ?? 2)) ?>"></label>
                    <label>Transferir para fila/ramal <input name="transfer_target" list="ivr-targets" value="<?= e((string)($editing['transfer_target'] ?? setting('asterisk_sync_queue_exten', '700'))) ?>" placeholder="700 ou 02140"></label>
                    <label class="toggle"><input type="checkbox" name="play_audio_on_answer" value="1" <?= (int)($editing['play_audio_on_answer'] ?? 1) === 1 ? 'checked' : '' ?>> Disparar primeiro audio quando atender</label>
                    <label class="toggle"><input type="checkbox" name="show_digit_viewer" value="1" <?= (int)($editing['show_digit_viewer'] ?? 1) === 1 ? 'checked' : '' ?>> Mostrar visor de digitos</label>
                </div>
            </section>

            <section class="ivr-form-block">
                <h3><i class="bi bi-signpost-split"></i> Destinos de seguranca</h3>
                <div class="form-grid three">
                    <label>Destino padrao <input name="default_destination" list="ivr-targets" value="<?= e((string)($editing['default_destination'] ?? setting('asterisk_sync_queue_exten', '700'))) ?>"></label>
                    <label>Destino se invalido <input name="invalid_destination" list="ivr-targets" value="<?= e((string)($editing['invalid_destination'] ?? 'hangup')) ?>"></label>
                    <label>Destino se expirar <input name="timeout_destination" list="ivr-targets" value="<?= e((string)($editing['timeout_destination'] ?? 'hangup')) ?>"></label>
                </div>
            </section>
            <datalist id="ivr-targets">
                <?php render_transfer_target_options(); ?>
                <option value="hangup">Desligar</option>
            </datalist>

            <section class="ivr-form-block">
            <h3><i class="bi bi-keypad"></i> Opcoes por tecla</h3>
            <div class="ivr-options compact-options" data-ivr-options
                data-ivr-audios="<?= e(json_encode(array_map(static fn(array $a): array => ['value' => (string)$a['id'], 'label' => $a['title'] . ' - ' . $a['type']], $audios), JSON_UNESCAPED_UNICODE)) ?>"
                data-ivr-queues="<?= e(json_encode($queues, JSON_UNESCAPED_UNICODE)) ?>"
                data-ivr-extensions="<?= e(json_encode(array_map(static fn(array $x): array => ['value' => (string)$x['extension'], 'label' => $x['extension'] . ' - ' . $x['name']], $extensions), JSON_UNESCAPED_UNICODE)) ?>">
                <?php $visibleOptions = array_slice(array_pad($editingOptions, 2, ['digit' => '', 'label' => '', 'destination_type' => 'fila', 'destination' => '']), 0, max(2, count($editingOptions))); ?>
                <?php foreach ($visibleOptions as $i => $option): ?>
                    <div class="ivr-option-row" data-ivr-option-row>
                        <label>Tecla <input name="option_digit[]" value="<?= e((string)$option['digit']) ?>" placeholder="<?= e((string)($i + 1)) ?>"></label>
                        <label>Rotulo <input name="option_label[]" value="<?= e((string)$option['label']) ?>" placeholder="Nome da opcao"></label>
                        <label>Tipo
                            <select name="option_type[]">
                                <?php foreach (['fila' => 'Fila', 'ramal' => 'Ramal', 'audio' => 'Audio', 'desligar' => 'Desligar'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($option['destination_type'] ?? 'fila') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Destino
                            <select name="option_destination[]" data-ivr-destination data-current-value="<?= e((string)$option['destination']) ?>">
                                <option value="<?= e((string)$option['destination']) ?>"><?= e((string)$option['destination'] ?: 'Selecione') ?></option>
                            </select>
                        </label>
                        <button class="btn compact danger" type="button" data-ivr-remove-option title="Remover opcao">-</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn compact" type="button" data-ivr-add-option><i class="bi bi-plus-lg"></i>Adicionar opcao</button>
            </section>
            <div class="actions">
                <button class="btn primary" type="submit"><i class="bi bi-check2-circle"></i>Salvar fluxo</button>
                <?php if ($editing): ?><a class="btn" href="<?= e($pageUrl) ?>">Cancelar edicao</a><?php endif; ?>
            </div>
        </form>
        </div>
        </div>

        <aside class="ivr-side">
        <section class="panel ivr-preview">
            <h2><i class="bi bi-telephone-outbound"></i> Telefone Softphone <?= e($title) ?></h2>
            <form method="post" class="ivr-launch-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label>Fluxo
                    <select name="id">
                        <?php foreach ($rows as $row): ?>
                            <option value="<?= e((string)$row['id']) ?>" <?= $launchIvrId === (int)$row['id'] ? 'selected' : '' ?>><?= e((string)$row['name']) ?><?= (int)$row['active'] === 1 ? '' : ' - Inativa' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="ivr-control-row">
                    <button class="btn primary" name="action" value="set_ivr_status" <?= $launchIvr ? '' : 'disabled' ?> onclick="this.form.active.value='1'"><i class="bi bi-play-fill"></i>Iniciar</button>
                    <button class="btn" name="action" value="set_ivr_status" <?= $launchIvr ? '' : 'disabled' ?> onclick="this.form.active.value='0'"><i class="bi bi-pause-fill"></i>Pausar</button>
                    <input type="hidden" name="mode" value="<?= e($mode) ?>">
                    <input type="hidden" name="active" value="<?= e((string)($launchIvr['active'] ?? 0)) ?>">
                </div>
                <div class="phone-screen ivr-phone-screen">
                    <div class="phone-call-event" data-call-title><?= $mode === 'invertida' ? 'Pronto para testar URA Reversa' : 'Pronto para testar URA' ?></div>
                    <input name="test_phone" data-dial-number value="<?= e((string)($launchIvr['test_phone'] ?? '')) ?>" placeholder="Telefone para discar">
                </div>
                <?php render_dial_pad(); ?>
                <div class="phone-actions ivr-phone-actions">
                    <button class="phone-tool" type="button" data-dial-backspace title="Apagar">Bksp</button>
                    <button class="phone-tool" type="button" data-dial-clear title="Limpar">C</button>
                    <button class="phone-call" name="action" value="test_ivr_call" <?= $launchIvr ? '' : 'disabled' ?>><i class="bi bi-telephone-outbound"></i>Discar</button>
                </div>
                <label><?= $mode === 'invertida' ? 'Agenda para disparo da URA Reversa' : 'Agenda para discagens da URA' ?>
                    <select name="agenda_id">
                        <option value="">Selecione uma agenda</option>
                        <?php foreach ($agendas as $agenda): ?><option value="<?= e((string)$agenda['id']) ?>" <?= (int)($launchIvr['agenda_id'] ?? 0) === (int)$agenda['id'] ? 'selected' : '' ?>><?= e((string)$agenda['title']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Simultaneas
                    <input type="number" name="simultaneous" min="1" max="200" value="<?= e((string)($launchIvr['simultaneous'] ?? 1)) ?>">
                </label>
                <div class="ivr-control-row">
                    <button class="btn" name="action" value="sync_ivr" <?= $launchIvr ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i>Sincronizar</button>
                    <button class="btn primary" name="action" value="start_ivr_calls" <?= $launchIvr ? '' : 'disabled' ?>><i class="bi bi-play-circle"></i><?= $mode === 'invertida' ? 'Iniciar reversa' : 'Iniciar discagens' ?></button>
                </div>
                <?php if (!$launchIvr): ?><p class="muted small">Cadastre uma <?= e($title) ?> para liberar teste e disparo.</p><?php endif; ?>
            </form>
        </section>

        <section class="panel ivr-digit-viewer" data-ivr-digit-viewer data-ivr-id="<?= e((string)$launchIvrId) ?>">
            <div class="panel-title-row">
                <div><h2><i class="bi bi-keyboard"></i> Visor de digitos</h2><p class="muted">Mostra as teclas digitadas pela pessoa na linha.</p></div>
                <span class="status-pill" data-tone="ok">Online</span>
            </div>
            <div class="ivr-digit-screen" data-ivr-digit-screen>
                <?php foreach (array_slice($digitEvents, 0, 8) as $event): ?>
                    <div><strong><?= e((string)$event['digit']) ?></strong><span><?= e(($event['phone'] ?: '-') . ' -> ' . ($event['destination'] ?: $event['option_label'] ?: '-')) ?></span><small><?= e((string)$event['created_at']) ?></small></div>
                <?php endforeach; ?>
                <?php if (!$digitEvents): ?><p class="muted">Nenhum digito recebido ainda.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel ivr-call-monitor" data-ivr-call-monitor data-ivr-id="<?= e((string)$launchIvrId) ?>">
            <div class="panel-title-row">
                <div><h2><i class="bi bi-display"></i> Display de discagem</h2><p class="muted">Acompanha teste, agenda, bina, status e resposta do Asterisk.</p></div>
                <span class="status-pill" data-tone="ok">Ao vivo</span>
            </div>
            <div class="ivr-call-screen" data-ivr-call-screen>
                <?php foreach (array_slice($callEvents, 0, 10) as $event): ?>
                    <div class="ivr-call-line">
                        <strong><?= e((string)$event['phone']) ?></strong>
                        <span><?= e((string)$event['status']) ?></span>
                        <small>Bina <?= e((string)($event['caller_id'] ?: '-')) ?> | <?= e((string)$event['created_at']) ?></small>
                        <em><?= e((string)($event['message'] ?: '')) ?></em>
                    </div>
                <?php endforeach; ?>
                <?php if (!$callEvents): ?><p class="muted">Nenhum disparo registrado ainda.</p><?php endif; ?>
            </div>
        </section>

        <section class="panel ivr-notify-panel">
            <h2><i class="bi bi-bell"></i> Avisos</h2>
            <div class="actions">
                <button class="btn" type="button" data-ivr-reset-sync-modal data-modal-key="ivr-sync-modal-hidden-<?= e($mode) ?>"><i class="bi bi-eye"></i>Reativar aviso de sincronizacao</button>
                <button class="btn primary" type="button" data-push-notifications><i class="bi bi-bell-fill"></i>Ativar notificacao push</button>
            </div>
            <p class="muted small" data-push-status>Notificacoes ajudam a avisar sobre digitos e eventos quando o navegador permitir.</p>
        </section>

        <section class="panel ivr-preview ivr-technical-preview">
            <h2><i class="bi bi-diagram-3"></i> Como vai funcionar</h2>
            <ol>
                <li><?= $mode === 'invertida' ? 'A campanha liga para o numero da agenda.' : 'A chamada entra no contexto da URA.' ?></li>
                <li>O audio principal e reproduzido e o sistema aguarda o digito.</li>
                <li>Tecla valida encaminha para o destino configurado.</li>
                <li>Digito invalido ou tempo esgotado usa os destinos de seguranca.</li>
            </ol>
            <pre class="ivr-dialplan"><?= e(ivr_dialplan_preview($mode, $editing ?: ['name' => $title, 'timeout_seconds' => 10, 'max_attempts' => 2], $editingOptions)) ?></pre>
        </section>
        </aside>
    </section>

    <section class="panel extensions-table-panel ivr-list">
        <div class="extensions-toolbar"><div><h2>Fluxos cadastrados</h2><p class="muted"><?= e(count($rows)) ?> fluxo(s) na lista.</p></div></div>
        <div class="table-wrap">
            <table><thead><tr><th>Nome</th><th>Audio</th><th>Tempo</th><th>Tentativas</th><th>Status</th><th>Opcoes</th><th>Acoes</th></tr></thead><tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $count = $db->prepare('SELECT COUNT(*) FROM ivr_options WHERE ivr_id=?');
                    $count->execute([(int)$row['id']]);
                    $audioTitle = '-';
                    if (!empty($row['audio_id'])) {
                        foreach ($audios as $audio) {
                            if ((int)$audio['id'] === (int)$row['audio_id']) {
                                $audioTitle = (string)$audio['title'];
                                break;
                            }
                        }
                    }
                    ?>
                    <tr>
                        <td><strong><?= e((string)$row['name']) ?></strong><small><?= e((string)($row['description'] ?? '')) ?></small></td>
                        <td><?= e($audioTitle) ?></td>
                        <td><?= e((string)$row['timeout_seconds']) ?>s</td>
                        <td><?= e((string)$row['max_attempts']) ?></td>
                        <td><span class="status-pill" data-tone="<?= (int)$row['active'] === 1 ? 'ok' : 'idle' ?>"><?= (int)$row['active'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                        <td><?= e((string)$count->fetchColumn()) ?></td>
                        <td><div class="row-actions">
                            <a class="btn compact" href="<?= e($pageUrl . '&edit=' . (string)$row['id']) ?>">Editar</a>
                            <form method="post" data-confirm="Remover este fluxo de <?= e($title) ?>?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_ivr"><input type="hidden" name="mode" value="<?= e($mode) ?>"><input type="hidden" name="id" value="<?= e((string)$row['id']) ?>"><button class="btn compact danger" type="submit">Excluir</button></form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="muted">Nenhum fluxo cadastrado.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </section>
    <?php
}

function ivr_dialplan_preview(string $mode, array $ivr, array $options): string
{
    $context = $mode === 'invertida' ? 'ura-invertida' : 'ura';
    $name = preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower((string)($ivr['name'] ?? 'fluxo')));
    $lines = [
        "[{$context}-{$name}]",
        'exten => s,1,Answer()',
        ' same => n,Set(TIMEOUT(digit)=' . (int)($ivr['timeout_seconds'] ?? 10) . ')',
        ' same => n,Set(MAX_ATTEMPTS=' . (int)($ivr['max_attempts'] ?? 2) . ')',
        ' same => n,Playback(audio-principal)',
        ' same => n,WaitExten()',
    ];
    foreach ($options as $option) {
        if (($option['digit'] ?? '') === '' || ($option['destination'] ?? '') === '') {
            continue;
        }
        $lines[] = 'exten => ' . $option['digit'] . ',1,Goto(' . $option['destination_type'] . ',' . $option['destination'] . ',1) ; ' . ($option['label'] ?? '');
    }
    $lines[] = 'exten => i,1,Goto(destino-invalido,1)';
    $lines[] = 'exten => t,1,Goto(destino-timeout,1)';
    return implode("\n", $lines);
}

function page_sip(): void
{
    Auth::requireAdmin();
    $db = Database::conn();
    $rows = $db->query('SELECT * FROM sip_accounts ORDER BY id DESC')->fetchAll();
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = null;
    if ($editId > 0) {
        $stmt = $db->prepare('SELECT * FROM sip_accounts WHERE id = ?');
        $stmt->execute([$editId]);
        $editing = $stmt->fetch() ?: null;
    }
    ?>
    <section class="page-head">
        <div><p class="eyebrow">Estilo MicroSIP</p><h1>Troncos</h1></div>
        <a class="btn primary" href="?page=sip">Criar tronco</a>
    </section>
    <section class="panel">
        <h2><?= $editing ? 'Editar tronco' : 'Novo tronco' ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_sip">
            <input type="hidden" name="id" value="<?= e((string)($editing['id'] ?? 0)) ?>">
            <label>Nome do tronco <input name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
            <label>Rotulo <input name="label" value="<?= e($editing['label'] ?? '') ?>"></label>
            <label>Servidor SIP <input name="sip_server" required placeholder="sip.exemplo.com" value="<?= e($editing['sip_server'] ?? '') ?>"></label>
            <label>Dominio <input name="domain" value="<?= e($editing['domain'] ?? '') ?>"></label>
            <label>Proxy <input name="proxy" value="<?= e($editing['proxy'] ?? '') ?>"></label>
            <label>Usuario <input name="username" required value="<?= e($editing['username'] ?? '') ?>"></label>
            <label>Login/Auth ID <input name="auth_user" value="<?= e($editing['auth_user'] ?? '') ?>"></label>
            <label>Senha <input name="password" type="password" value="<?= e($editing['password'] ?? '') ?>"></label>
            <label>Nome exibido <input name="display_name" value="<?= e($editing['display_name'] ?? '') ?>"></label>
            <label>Transporte <select name="transport"><?php foreach (['UDP','TCP','TLS','WS','WSS'] as $transport): ?><option value="<?= e($transport) ?>" <?= ($editing['transport'] ?? 'UDP') === $transport ? 'selected' : '' ?>><?= e($transport) ?></option><?php endforeach; ?></select></label>
            <label>Porta <input name="port" type="number" value="<?= e((string)($editing['port'] ?? 5060)) ?>"></label>
            <label>STUN <input name="stun_server" placeholder="stun.l.google.com:19302" value="<?= e($editing['stun_server'] ?? '') ?>"></label>
            <label>WebSocket SIP <input name="websocket_url" placeholder="wss://sip.protegerconta.online:8089/ws" value="<?= e($editing['websocket_url'] ?? '') ?>"><small>Necessario apenas para softphone no navegador. MicroSIP usa UDP/TCP e nao usa este campo.</small></label>
            <label>ICE/STUN/TURN <input name="ice_servers" value="<?= e($editing['ice_servers'] ?? 'stun:stun.l.google.com:19302') ?>"></label>
            <label>Registro expira <input name="register_expires" type="number" value="<?= e((string)($editing['register_expires'] ?? 300)) ?>"></label>
            <label>Correio de voz <input name="voicemail" value="<?= e($editing['voicemail'] ?? '') ?>"></label>
            <label>Contexto de discagem <input name="dial_context" value="<?= e($editing['dial_context'] ?? 'from-internal') ?>"></label>
            <label>Template de canal <input name="channel_template" value="<?= e($editing['channel_template'] ?? 'PJSIP/{extension}') ?>"></label>
            <label>Caller ID <input name="caller_id" value="<?= e($editing['caller_id'] ?? '') ?>"></label>
            <label>DTMF <select name="dtmf_type"><?php foreach (['rtp' => 'RTP', 'info' => 'SIP INFO'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editing['dtmf_type'] ?? 'rtp') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label class="toggle"><input type="checkbox" name="publish_presence" value="1" <?= (int)($editing['publish_presence'] ?? 0) === 1 ? 'checked' : '' ?>> Publicar presenca</label>
            <label class="toggle"><input type="checkbox" name="webrtc_enabled" value="1" <?= (int)($editing['webrtc_enabled'] ?? 0) === 1 ? 'checked' : '' ?>> Usar no WebRTC</label>
            <label class="toggle"><input type="checkbox" name="active" value="1" <?= (int)($editing['active'] ?? 1) === 1 ? 'checked' : '' ?>> Ativa</label>
            <div class="actions">
                <button class="btn primary"><?= $editing ? 'Atualizar tronco' : 'Salvar tronco' ?></button>
                <?php if ($editing): ?><a class="btn" href="?page=sip">Cancelar edicao</a><?php endif; ?>
            </div>
        </form>
    </section>
    <section class="panel">
        <h2>Troncos cadastrados</h2>
        <?php render_sip_table($rows); ?>
    </section>
    <?php
}

function render_sip_table(array $rows): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>Tronco</th><th>Servidor</th><th>Usuario</th><th>WebSocket</th><th>Transporte</th><th>WebRTC</th><th>Ativo</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . e($row['name']) . '</td>';
        echo '<td>' . e($row['sip_server']) . '</td>';
        echo '<td>' . e($row['username']) . '</td>';
        $ws = trim((string)($row['websocket_url'] ?? ''));
        echo '<td>' . e($ws !== '' ? $ws : 'MicroSIP/UDP') . '</td>';
        echo '<td>' . e($row['transport']) . '</td>';
        echo '<td>' . ((int)$row['webrtc_enabled'] === 1 && valid_webrtc_websocket_url($ws) ? 'Sim' : 'Nao') . '</td>';
        echo '<td>' . ((int)$row['active'] === 1 ? 'Sim' : 'Nao') . '</td>';
        echo '<td><div class="row-actions">';
        echo '<a class="btn compact" href="?page=sip&edit=' . e((string)$row['id']) . '">Editar</a>';
        echo '<form method="post" data-confirm="Deletar este tronco?">';
        echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="delete_sip">';
        echo '<input type="hidden" name="id" value="' . e((string)$row['id']) . '">';
        echo '<button class="btn compact danger" type="submit">Deletar</button>';
        echo '</form>';
        echo '</div></td>';
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="8" class="muted">Nenhum tronco cadastrado.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function page_extensions(): void
{
    Auth::requireAdmin();
    $accounts = Database::conn()->query('SELECT id, name FROM sip_accounts WHERE active=1 ORDER BY name')->fetchAll();
    $owners = Database::conn()->query("SELECT id, name, email FROM users WHERE role='cliente' OR id=1 ORDER BY name")->fetchAll();
    $rows = Database::conn()->query("SELECT e.*, u.name AS owner_name, s.name AS sip, " . extension_online_expr() . " AS online_now FROM extensions e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN sip_accounts s ON s.id=e.sip_account_id ORDER BY e.id DESC")->fetchAll();
    $total = count($rows);
    $active = count(array_filter($rows, static fn(array $row): bool => (int)$row['active'] === 1));
    $inactive = $total - $active;
    ?>
    <section class="page-head extensions-head">
        <div><p class="eyebrow">PBX WebRTC</p><h1>Ramais</h1></div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn" name="action" value="sync_extensions">Sincronizar Asterisk</button>
        </form>
    </section>
    <section class="extension-metrics">
        <div><strong><?= e((string)$total) ?></strong><span>Total de ramais</span></div>
        <div><strong><?= e((string)$active) ?></strong><span>Ativos</span></div>
        <div><strong><?= e((string)$inactive) ?></strong><span>Inativos</span></div>
        <div><strong>700</strong><span>Fila de transferencia</span></div>
    </section>
    <section class="extensions-console">
        <div class="panel extension-create-card">
            <div class="panel-title-row">
                <div><h2>Criar ramal</h2><p class="muted">Deixe o numero vazio para gerar automaticamente.</p></div>
            </div>
            <form method="post" class="extension-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_extension">
                <label>Usuario dono <select name="user_id"><?php foreach ($owners as $owner): ?><option value="<?= e((string)$owner['id']) ?>"><?= e($owner['name'] . ' - ' . $owner['email']) ?></option><?php endforeach; ?></select></label>
                <label>Usuario dono <select name="user_id"><?php foreach ($owners as $owner): ?><option value="<?= e((string)$owner['id']) ?>"><?= e($owner['name'] . ' - ' . $owner['email']) ?></option><?php endforeach; ?></select></label>
                <label>Tronco <select name="sip_account_id"><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
                <div class="extension-form-row">
                    <label>Ramal <input name="extension" placeholder="Automatico"></label>
                    <label>Senha <input name="secret" type="password" placeholder="Automatico"></label>
                </div>
                <label>Atendente <input name="name" required placeholder="Nome do atendente"></label>
                <label>Bina <input name="caller_id" placeholder="Ex: 5511999999999"></label>
                <div class="extension-form-row">
                    <label><span><input name="audio_transfer_enabled" value="1" type="checkbox"> Audio de transferencia</span><input name="audio_transfer_file" placeholder="custom/transferindo"></label>
                    <label><span><input name="audio_initial_enabled" value="1" type="checkbox"> Audio inicial</span><input name="audio_initial_file" placeholder="custom/boas-vindas"></label>
                </div>
                <div class="extension-form-row">
                    <label class="toggle"><input name="allow_monitoring" value="1" type="checkbox"> Monitoravel</label>
                    <label class="toggle"><input name="active" value="1" type="checkbox" checked> Ativo</label>
                </div>
                <button class="btn primary">Criar e sincronizar</button>
            </form>
        </div>
        <div class="panel extension-create-card">
            <div class="panel-title-row">
                <div><h2>Criar em lote</h2><p class="muted">Gera ramais e senhas unicas automaticamente.</p></div>
            </div>
            <form method="post" class="extension-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="bulk_extensions">
                <label>Tronco <select name="sip_account_id"><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
                <div class="extension-form-row">
                    <label>Quantidade <input name="quantity" type="number" min="1" max="200" value="5"></label>
                    <label>Descricao <input name="name_prefix" placeholder="Atendente"></label>
                </div>
                <label>Bina para todos <input name="caller_id" placeholder="Opcional"></label>
                <button class="btn primary">Criar lote</button>
            </form>
        </div>
    </section>
    <section class="panel extensions-table-panel">
        <div class="extensions-toolbar">
            <div>
                <h2>Ramais cadastrados</h2>
                <p class="muted"><span data-extension-count><?= e((string)$total) ?></span> ramal(is) na lista</p>
            </div>
            <label class="extension-search">Buscar
                <input data-extension-search placeholder="Pesquisar por ramal, atendente, bina ou tronco">
            </label>
        </div>
        <?php render_extensions_table($rows); ?>
    </section>
    <?php
}

function render_extensions_table(array $rows): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>Ramal</th><th>Link</th><th>Dono</th><th>Nome</th><th>Tronco</th><th>Caller ID</th><th>Janela</th><th>Ativo</th><th>Acoes</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $link = '?page=agent_phone&extension=' . urlencode((string)$row['extension']) . '&password=' . urlencode((string)$row['secret']);
        $search = strtolower(trim(implode(' ', [(string)$row['extension'], (string)$row['name'], (string)$row['sip'], (string)$row['caller_id']])));
        echo '<tr data-extension-row data-extension="' . e($row['extension']) . '" data-search="' . e($search) . '">';
        echo '<td><span class="phone-state" data-extension-state data-state="' . ((int)$row['online_now'] === 1 ? 'on' : 'off') . '"></span><strong>' . e($row['extension']) . '</strong></td>';
        echo '<td><a class="btn compact outline" href="' . e($link) . '" target="_blank">Abrir link</a></td>';
        echo '<td>' . e($row['owner_name'] ?: '-') . '</td>';
        echo '<td><strong>' . e($row['name']) . '</strong></td>';
        echo '<td>' . e($row['sip']) . '</td>';
        echo '<td>' . e($row['caller_id'] ?: '-') . '</td>';
        echo '<td><span class="status-chip ' . ((int)$row['online_now'] === 1 ? 'ok' : 'off') . '" data-extension-online>' . ((int)$row['online_now'] === 1 ? 'Online' : 'Offline') . '</span></td>';
        echo '<td><span class="status-chip ' . ((int)$row['active'] === 1 ? 'ok' : 'off') . '">' . ((int)$row['active'] === 1 ? 'Ativo' : 'Inativo') . '</span></td>';
        echo '<td><div class="row-actions"><button class="btn compact" type="button" data-copy-link="' . e($link) . '">Copiar link</button><form method="post" data-confirm="Deletar este ramal e sincronizar o Asterisk?">';
        echo '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="delete_extension">';
        echo '<input type="hidden" name="id" value="' . e((string)$row['id']) . '">';
        echo '<button class="btn compact danger" type="submit">Deletar</button>';
        echo '</form></div></td>';
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="9" class="muted">Nenhum ramal cadastrado.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function page_queues(): void
{
    $current = Auth::user();
    $isAdmin = ($current['role'] ?? '') === 'admin';
    $db = Database::conn();
    if ($isAdmin) {
        $rows = $db->query('SELECT q.*, u.name AS owner_name, (SELECT COUNT(*) FROM call_queue_extensions qe WHERE qe.queue_id=q.id) AS members FROM call_queues q JOIN users u ON u.id=q.user_id ORDER BY q.id DESC')->fetchAll();
        $owners = $db->query("SELECT id, name, email FROM users WHERE active=1 ORDER BY name")->fetchAll();
        $extensions = $db->query('SELECT e.id, e.extension, e.name, e.user_id, u.name AS owner_name FROM extensions e LEFT JOIN users u ON u.id=e.user_id WHERE e.active=1 ORDER BY u.name, e.extension')->fetchAll();
    } else {
        $stmt = $db->prepare('SELECT q.*, ? AS owner_name, (SELECT COUNT(*) FROM call_queue_extensions qe WHERE qe.queue_id=q.id) AS members FROM call_queues q WHERE q.user_id=? ORDER BY q.id DESC');
        $stmt->execute([(string)$current['name'], (int)$current['id']]);
        $rows = $stmt->fetchAll();
        $owners = [$current];
        $stmt = $db->prepare('SELECT id, extension, name, user_id, ? AS owner_name FROM extensions WHERE active=1 AND user_id=? ORDER BY extension');
        $stmt->execute([(string)$current['name'], (int)$current['id']]);
        $extensions = $stmt->fetchAll();
    }
    $editId = (int)($_GET['edit'] ?? 0);
    $editing = null;
    $selected = [];
    if ($editId > 0) {
        $guard = $isAdmin ? '' : ' AND user_id=' . (int)$current['id'];
        $stmt = $db->prepare("SELECT * FROM call_queues WHERE id=?{$guard} LIMIT 1");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch() ?: null;
        if ($editing) {
            $stmt = $db->prepare('SELECT extension_id FROM call_queue_extensions WHERE queue_id=?');
            $stmt->execute([(int)$editing['id']]);
            $selected = array_map('intval', array_column($stmt->fetchAll(), 'extension_id'));
        }
    }
    $overflowQueues = $isAdmin
        ? $db->query('SELECT queue_number, name FROM call_queues WHERE active=1 ORDER BY name')->fetchAll()
        : (function () use ($db, $current) {
            $stmt = $db->prepare('SELECT queue_number, name FROM call_queues WHERE active=1 AND user_id=? ORDER BY name');
            $stmt->execute([(int)$current['id']]);
            return $stmt->fetchAll();
        })();
    $overflowIvrs = $db->query("SELECT id, name FROM ivrs WHERE active=1 ORDER BY name")->fetchAll();
    ?>
    <section class="page-head queue-head">
        <div>
            <p class="eyebrow">Atendimento</p>
            <h1>Filas</h1>
            <p class="muted">Cada fila fica isolada por conta e sincroniza direto no Asterisk.</p>
        </div>
        <div class="actions">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="btn" name="action" value="sync_queues"><i class="bi bi-arrow-repeat"></i>Sincronizar</button>
            </form>
            <button class="btn primary" type="button" data-queue-open="simple"><i class="bi bi-plus-lg"></i>Nova Fila (Simples)</button>
            <button class="btn" type="button" data-queue-open="advanced">Modo Avancado</button>
        </div>
    </section>
    <section class="panel extensions-table-panel queue-table-panel">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>Conta</th><th>Numero</th><th>Estrategia</th><th>Ramais</th><th>Status</th><th>Acoes</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><strong><?= e((string)$row['name']) ?></strong><small><?= e((string)($row['description'] ?: $row['asterisk_name'])) ?></small></td>
                        <td><?= e((string)$row['owner_name']) ?></td>
                        <td><strong><?= e((string)$row['queue_number']) ?></strong></td>
                        <td><?= e(queue_strategy_label((string)$row['strategy'])) ?></td>
                        <td><?= e((string)$row['members']) ?></td>
                        <td><span class="status-chip <?= (int)$row['active'] === 1 ? 'ok' : 'off' ?>"><?= (int)$row['active'] === 1 ? 'Ativa' : 'Inativa' ?></span></td>
                        <td><div class="row-actions">
                            <a class="icon-action" href="?page=queues&edit=<?= e((string)$row['id']) ?>" title="Editar"><i class="bi bi-pencil-fill"></i></a>
                            <form method="post" data-confirm="Excluir esta fila? Ela tambem sera removida do Asterisk na sincronizacao.">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_queue">
                                <input type="hidden" name="id" value="<?= e((string)$row['id']) ?>">
                                <button class="icon-action danger" title="Excluir"><i class="bi bi-trash-fill"></i></button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="muted empty-cell">Nenhuma fila cadastrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="queue-modal" data-queue-modal <?= $editing ? '' : 'hidden' ?>>
        <form method="post" class="queue-modal-card">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_queue">
            <input type="hidden" name="id" value="<?= e((string)($editing['id'] ?? 0)) ?>">
            <header class="queue-modal-head">
                <div><h2><?= $editing ? 'Editar Fila' : 'Nova Fila' ?></h2><p>Configure uma fila isolada por conta e sincronizada no Asterisk.</p></div>
                <a class="icon-action" href="?page=queues" data-queue-close><i class="bi bi-x-lg"></i></a>
            </header>
            <div class="queue-tabs" data-queue-tabs>
                <button type="button" class="active" data-queue-tab="basic"><i class="bi bi-info-circle"></i>Informacoes</button>
                <button type="button" data-queue-tab="strategy"><i class="bi bi-gear"></i>Estrategia & Comportamento</button>
                <button type="button" data-queue-tab="times"><i class="bi bi-clock"></i>Tempos</button>
                <button type="button" data-queue-tab="audio"><i class="bi bi-volume-up"></i>Audio & Anuncios</button>
                <button type="button" data-queue-tab="agents"><i class="bi bi-people"></i>Ramais</button>
                <button type="button" data-queue-tab="overflow"><i class="bi bi-arrow-right-circle"></i>Overflow & Avancado</button>
            </div>
            <div class="queue-tab-panel active" data-queue-panel="basic">
                <div class="queue-info-box info"><strong>Informacoes da Fila</strong><span>Configure o nome, numero opcional e descricao da fila de atendimento.</span></div>
                <div class="form-grid two">
                    <?php if ($isAdmin): ?>
                        <label>Conta
                            <select name="user_id">
                                <?php foreach ($owners as $owner): ?><option value="<?= e((string)$owner['id']) ?>" <?= (int)($editing['user_id'] ?? $current['id']) === (int)$owner['id'] ? 'selected' : '' ?>><?= e((string)$owner['name'] . ' - ' . (string)$owner['email']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?= e((string)$current['id']) ?>">
                    <?php endif; ?>
                    <label>Nome da fila <input name="name" required value="<?= e((string)($editing['name'] ?? '')) ?>" placeholder="Ex: Atendimento Comercial"></label>
                    <label>Numero da fila <input name="queue_number" value="<?= e((string)($editing['queue_number'] ?? '')) ?>" placeholder="Automatico se vazio"><small class="muted">Use para transferir chamadas para esta fila.</small></label>
                    <label class="toggle queue-toggle"><input type="checkbox" name="active" value="1" <?= (int)($editing['active'] ?? 1) === 1 ? 'checked' : '' ?>> Fila ativa e disponivel</label>
                    <label class="full">Descricao <textarea name="description" placeholder="Objetivo desta fila"><?= e((string)($editing['description'] ?? '')) ?></textarea></label>
                </div>
            </div>
            <div class="queue-tab-panel" data-queue-panel="strategy">
                <div class="queue-info-box success"><strong>Estrategia de Distribuicao</strong><span>Defina como as chamadas serao distribuidas entre os ramais disponiveis.</span></div>
                <div class="form-grid two">
                    <label class="full">Estrategia de distribuicao
                        <select name="strategy">
                            <?php foreach (queue_strategy_options() as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editing['strategy'] ?? 'ringall') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Maximo de chamadas na fila <input type="number" name="max_calls" min="0" max="500" value="<?= e((string)($editing['max_calls'] ?? 0)) ?>"><small class="muted">0 = ilimitado</small></label>
                    <label>Limite atendimento rapido (seg.) <input type="number" name="short_talk_seconds" min="30" max="300" value="<?= e((string)($editing['short_talk_seconds'] ?? 120)) ?>"><small class="muted">Usado em Menor tempo de fala. Sugestao: 60 a 120.</small></label>
                    <label class="toggle queue-toggle"><input type="checkbox" name="join_empty" value="1" <?= (int)($editing['join_empty'] ?? 1) === 1 ? 'checked' : '' ?>> Aceitar chamadas mesmo sem ramais logados</label>
                </div>
            </div>
            <div class="queue-tab-panel" data-queue-panel="times">
                <div class="queue-info-box warn"><strong>Tempos da fila</strong><span>Configure toque, espera maxima e intervalo pos-atendimento.</span></div>
                <div class="form-grid two">
                    <label>Tempo para chamar proximo ramal (seg.) <input type="number" name="timeout_seconds" min="5" max="120" value="<?= e((string)($editing['timeout_seconds'] ?? 30)) ?>"><small class="muted">Tempo que o telefone do ramal toca.</small></label>
                    <label>Tempo de pos-atendimento (seg.) <input type="number" name="wrapup_seconds" min="0" max="600" value="<?= e((string)($editing['wrapup_seconds'] ?? 0)) ?>"><small class="muted">Pausa antes de enviar nova chamada ao ramal.</small></label>
                    <label>Tempo maximo de espera (seg.) <input type="number" name="max_wait_seconds" min="10" max="3600" value="<?= e((string)($editing['max_wait_seconds'] ?? 300)) ?>"><small class="muted">Tempo maximo que o cliente pode aguardar.</small></label>
                    <label>Tempo maximo sem ramal (seg.) <input type="number" name="max_no_agent_seconds" min="10" max="3600" value="<?= e((string)($editing['max_no_agent_seconds'] ?? 90)) ?>"><small class="muted">Referencia para fila sem ramais logados.</small></label>
                </div>
            </div>
            <div class="queue-tab-panel" data-queue-panel="audio">
                <div class="queue-info-box audio"><strong>Audio e anuncios</strong><span>Configure musica de espera, anuncios e gravacao.</span></div>
                <div class="form-grid two">
                    <label>Musica de espera
                        <select name="musicclass">
                            <?php foreach (['default' => 'Musica Padrao', 'silence' => 'Silencio'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($editing['musicclass'] ?? 'default') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="toggle queue-toggle"><input type="checkbox" name="record_calls" value="1" <?= (int)($editing['record_calls'] ?? 0) === 1 ? 'checked' : '' ?>> Gravar chamadas desta fila</label>
                    <label class="toggle queue-toggle"><input type="checkbox" name="announce_position" value="1" <?= (int)($editing['announce_position'] ?? 0) === 1 ? 'checked' : '' ?>> Anunciar posicao do cliente na fila</label>
                    <label>Frequencia de anuncio (seg.) <input type="number" name="announce_frequency" min="0" max="600" value="<?= e((string)($editing['announce_frequency'] ?? 30)) ?>"></label>
                    <label>Tecla de saida (DTMF) <input name="exit_digit" value="<?= e((string)($editing['exit_digit'] ?? '')) ?>" placeholder="Ex: * ou #"></label>
                </div>
            </div>
            <div class="queue-tab-panel" data-queue-panel="agents">
                <div class="queue-agent-head"><p class="muted">Selecione os ramais que farao parte da fila.</p><input data-queue-agent-search placeholder="Buscar ramal..."></div>
                <div class="queue-agent-grid">
                    <?php foreach ($extensions as $ext): ?>
                        <label data-queue-agent-row data-owner-id="<?= e((string)$ext['user_id']) ?>" data-search="<?= e(strtolower((string)$ext['extension'] . ' ' . (string)$ext['name'] . ' ' . (string)$ext['owner_name'])) ?>">
                            <input type="checkbox" name="extension_ids[]" value="<?= e((string)$ext['id']) ?>" <?= in_array((int)$ext['id'], $selected, true) ? 'checked' : '' ?>>
                            <strong><?= e((string)$ext['extension']) ?></strong><span><?= e((string)$ext['name']) ?></span><small><?= e((string)$ext['owner_name']) ?></small>
                        </label>
                    <?php endforeach; ?>
                    <?php if (!$extensions): ?><p class="muted">Cadastre ramais antes de criar filas.</p><?php endif; ?>
                </div>
            </div>
            <div class="queue-tab-panel" data-queue-panel="overflow">
                <div class="queue-info-box advanced"><strong>Configuracoes Avancadas</strong><span>Overflow, destino quando exceder o tempo de espera e regras futuras de prioridade.</span></div>
                <div class="form-grid two">
                    <label>Tipo de destino
                        <select name="overflow_type" data-queue-overflow-type>
                            <?php foreach (['hangup', 'ramal', 'voicemail', 'queue', 'ivr'] as $type): ?><option value="<?= e($type) ?>" <?= ($editing['overflow_type'] ?? 'hangup') === $type ? 'selected' : '' ?>><?= e(queue_overflow_type_label($type)) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Destino
                        <input name="overflow_destination" list="queue-overflow-destinations" value="<?= e((string)($editing['overflow_destination'] ?? '')) ?>" placeholder="Ramal, fila ou ID da URA">
                        <datalist id="queue-overflow-destinations">
                            <?php foreach ($extensions as $ext): ?><option value="<?= e((string)$ext['extension']) ?>"><?= e('Ramal ' . (string)$ext['extension'] . ' - ' . (string)$ext['name']) ?></option><?php endforeach; ?>
                            <?php foreach ($overflowQueues as $queue): ?><option value="<?= e((string)$queue['queue_number']) ?>"><?= e('Fila ' . (string)$queue['queue_number'] . ' - ' . (string)$queue['name']) ?></option><?php endforeach; ?>
                            <?php foreach ($overflowIvrs as $ivr): ?><option value="<?= e((string)$ivr['id']) ?>"><?= e('URA #' . (string)$ivr['id'] . ' - ' . (string)$ivr['name']) ?></option><?php endforeach; ?>
                        </datalist>
                    </label>
                    <label class="toggle queue-toggle full"><input type="checkbox" name="tier_rules_enabled" value="1" <?= (int)($editing['tier_rules_enabled'] ?? 0) === 1 ? 'checked' : '' ?>> Aplicar regras de tiers (niveis de prioridade de ramais)</label>
                </div>
                <div class="queue-review-box">
                    <h3>Resumo</h3>
                    <p>Nome: <strong data-queue-review="name"><?= e((string)($editing['name'] ?? '-')) ?></strong></p>
                    <p>Numero da fila: <strong data-queue-review="queue_number"><?= e((string)($editing['queue_number'] ?? 'Automatico')) ?></strong></p>
                    <p>Estrategia: <strong data-queue-review="strategy"><?= e(queue_strategy_label((string)($editing['strategy'] ?? 'ringall'))) ?></strong></p>
                    <p>Ramais: <strong data-queue-review="agents"><?= e((string)count($selected)) ?></strong></p>
                    <p>Overflow: <strong data-queue-review="overflow"><?= e(queue_overflow_type_label((string)($editing['overflow_type'] ?? 'hangup'))) ?></strong></p>
                </div>
            </div>
            <footer class="queue-modal-actions">
                <button class="btn" type="button" data-queue-prev>Voltar</button>
                <button class="btn" type="button" data-queue-next>Avancar</button>
                <button class="btn primary" type="submit"><i class="bi bi-check-lg"></i><?= $editing ? 'Salvar Fila' : 'Criar Fila' ?></button>
            </footer>
        </form>
    </div>
    <?php
}

function page_campaigns(): void
{
    $current = Auth::user();
    $isAdmin = ($current['role'] ?? '') === 'admin';
    $db = Database::conn();
    if ($isAdmin) {
        $accounts = $db->query('SELECT id, name FROM sip_accounts WHERE active=1 ORDER BY name')->fetchAll();
        $extensions = $db->query('SELECT id, extension, name FROM extensions WHERE active=1 ORDER BY extension')->fetchAll();
        $campaigns = $db->query('SELECT id, name, status FROM campaigns ORDER BY id DESC')->fetchAll();
        $agendas = $db->query('SELECT id, title FROM auto_agendas ORDER BY title')->fetchAll();
        $audios = Database::conn()->query('SELECT id, title, type FROM auto_audios ORDER BY title')->fetchAll();
        $rates = Database::conn()->query('SELECT id, plan_name, route_name, rate_value, initial_block_seconds, billing_increment_seconds FROM magnus_rates WHERE active=1 ORDER BY route_name, ' . db_rate_cast_expr('rate_value') . ', plan_name')->fetchAll();
    } else {
        $stmt = $db->prepare('SELECT DISTINCT s.id, s.name FROM sip_accounts s JOIN extensions e ON e.sip_account_id=s.id WHERE s.active=1 AND e.active=1 AND e.user_id=? ORDER BY s.name');
        $stmt->execute([(int)$current['id']]);
        $accounts = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT id, extension, name FROM extensions WHERE active=1 AND user_id=? ORDER BY extension');
        $stmt->execute([(int)$current['id']]);
        $extensions = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT id, name, status FROM campaigns WHERE created_by=? ORDER BY id DESC');
        $stmt->execute([(int)$current['id']]);
        $campaigns = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT id, title FROM auto_agendas WHERE user_id=? ORDER BY title');
        $stmt->execute([(int)$current['id']]);
        $agendas = $stmt->fetchAll();
        $stmt = $db->prepare('SELECT id, title, type FROM auto_audios WHERE user_id=? ORDER BY title');
        $stmt->execute([(int)$current['id']]);
        $audios = $stmt->fetchAll();
        $rates = [];
        if (!empty($current['default_magnus_rate_id'])) {
            $stmt = $db->prepare('SELECT id, plan_name, route_name, rate_value, initial_block_seconds, billing_increment_seconds FROM magnus_rates WHERE id=? AND active=1');
            $stmt->execute([(int)$current['default_magnus_rate_id']]);
            $rates = $stmt->fetchAll();
        }
    }
    $stats = campaign_board_stats($current ?: []);
    ?>
    <section class="page-head auto-head">
        <div><p class="eyebrow">Discagem Auto</p><h1>Campanhas</h1></div>
        <a class="btn primary" href="#nova-campanha"><i class="bi bi-plus-lg"></i>Adicionar</a>
    </section>
    <section class="auto-stat-row">
        <div class="auto-stat active"><span>Ativo</span><strong><?= e((string)$stats['active']) ?></strong><i class="bi bi-telephone"></i></div>
        <div class="auto-stat missed"><span>Nao atendido</span><strong><?= e((string)$stats['missed']) ?></strong><i class="bi bi-telephone-x"></i></div>
        <div class="auto-stat classified"><span>Classificado</span><strong><?= e((string)$stats['classified']) ?></strong><i class="bi bi-tags"></i></div>
        <div class="auto-stat abandoned"><span>Abandonou</span><strong><?= e((string)$stats['abandoned']) ?></strong><i class="bi bi-scissors"></i></div>
        <div class="auto-stat answered"><span>Atendido</span><strong><?= e((string)$stats['answered']) ?></strong><i class="bi bi-check-circle"></i></div>
    </section>
    <?php render_auto_subnav('campaigns'); ?>
    <section class="panel auto-form-panel campaign-config-card" id="nova-campanha">
        <h2>Cadastro de campanhas</h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_campaign">
            <label>Titulo <input name="name" required></label>
            <label>Bina <input name="caller_id" placeholder="Usar bina do tronco"></label>
            <label>Simultaneas <input name="simultaneous" type="number" min="1" max="200" value="1"><small class="muted">Maximo: 200 simultaneas por usuario</small></label>
            <label>Selecione uma agenda <select name="agenda_id" required><option value="">Nenhuma</option><?php foreach ($agendas as $agenda): ?><option value="<?= e((string)$agenda['id']) ?>"><?= e($agenda['title']) ?></option><?php endforeach; ?></select></label>
            <label>Audio de espera <select name="wait_audio_id"><option value="">Padrao</option><?php foreach ($audios as $audio): ?><option value="<?= e((string)$audio['id']) ?>"><?= e($audio['title'] . ' - ' . $audio['type']) ?></option><?php endforeach; ?></select></label>
            <label>Audio de inicio <select name="start_audio_id"><option value="">Nenhum</option><?php foreach ($audios as $audio): ?><option value="<?= e((string)$audio['id']) ?>"><?= e($audio['title'] . ' - ' . $audio['type']) ?></option><?php endforeach; ?></select></label>
            <label>Tipo da fila <select name="queue_type"><option>Qualquer Digito</option><option>Transferencia direta</option><option>Somente audio</option></select><small class="muted">Reproduz o audio e aguarda tecla por 10 segundos.</small></label>
            <label>Taxa / rota <select name="rate_id"><option value="">Nenhuma</option><?php foreach ($rates as $rate): ?><option value="<?= e((string)$rate['id']) ?>"><?= e($rate['route_name'] . ' - ' . $rate['plan_name'] . ' (R$ ' . number_format((float)$rate['rate_value'], 2, ',', '.') . '/min | bloco ' . (int)($rate['initial_block_seconds'] ?? 30) . 's / inc. ' . (int)($rate['billing_increment_seconds'] ?? 6) . 's)') ?></option><?php endforeach; ?></select></label>
            <label>Tronco <select name="sip_account_id" required><?php foreach ($accounts as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label>
            <label>Transferir atendidas para
                <input name="transfer_target" list="campaign-transfer-targets" placeholder="Ramal ou fila, ex: 700">
                <datalist id="campaign-transfer-targets">
                    <?php render_transfer_target_options(); ?>
                </datalist>
            </label>
            <div class="full ramal-picker">
                <strong>Selecione os Ramais</strong>
                <div>
                    <?php foreach ($extensions as $ext): ?>
                        <label><input type="checkbox" name="extension_ids[]" value="<?= e((string)$ext['id']) ?>"> <?= e($ext['extension'] . ' - ' . $ext['name']) ?></label>
                    <?php endforeach; ?>
                    <?php if (!$extensions): ?><p class="muted">Cadastre ramais antes de criar campanha.</p><?php endif; ?>
                </div>
            </div>
            <label class="toggle full"><input type="checkbox" name="ddd_inteligente" value="1"> DDD Inteligente</label>
            <div class="actions"><button class="btn primary">Criar campanha</button></div>
        </form>
    </section>
    <section class="panel campaigns-list-panel">
        <div class="campaign-list-head">
            <h2>Campanhas</h2>
            <input placeholder="Pesquisar" data-campaign-search>
            <a class="btn primary" href="#nova-campanha"><i class="bi bi-plus-lg"></i>Adicionar +</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome da campanha</th><th>Status</th><th>Acoes</th></tr></thead>
                <tbody>
                <?php foreach ($campaigns as $campaign): ?>
                    <tr>
                        <td><?= e($campaign['name']) ?></td>
                        <td>
                            <form method="post" <?= $campaign['status'] === 'running' ? 'data-confirm="Deseja realmente desativar esta campanha?"' : '' ?>>
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle_campaign">
                                <input type="hidden" name="id" value="<?= e((string)$campaign['id']) ?>">
                                <button class="campaign-status <?= $campaign['status'] === 'running' ? 'active' : '' ?>" type="submit"><?= e($campaign['status'] === 'running' ? 'Ativo' : 'Inativo') ?></button>
                            </form>
                        </td>
                        <td><div class="row-actions">
                            <a class="icon-action ok" href="?page=campaign&id=<?= e((string)$campaign['id']) ?>" title="Abrir"><i class="bi bi-check-circle"></i></a>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="pause_campaign"><input type="hidden" name="id" value="<?= e((string)$campaign['id']) ?>"><button class="icon-action" title="Pausar"><i class="bi bi-pause-circle"></i></button></form>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reprocess_campaign"><input type="hidden" name="id" value="<?= e((string)$campaign['id']) ?>"><input type="hidden" name="statuses[]" value="nao_atendida"><input type="hidden" name="statuses[]" value="rejeitada"><button class="icon-action" title="Reprocessar nao atendidas e rejeitadas"><i class="bi bi-arrow-repeat"></i></button></form>
                            <a class="icon-action" href="?page=campaign&id=<?= e((string)$campaign['id']) ?>" title="Editar"><i class="bi bi-pencil-fill"></i></a>
                            <form method="post" data-confirm="Excluir esta campanha?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_campaign"><input type="hidden" name="id" value="<?= e((string)$campaign['id']) ?>"><button class="icon-action danger"><i class="bi bi-trash-fill"></i></button></form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$campaigns): ?><tr><td colspan="3" class="muted">Nenhuma campanha cadastrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function page_auto_agendas(): void
{
    $current = Auth::user() ?: [];
    $agendas = auto_agenda_cards($current);
    $totalNumbers = array_sum(array_map(static fn(array $row): int => (int)$row['total'], $agendas));
    ?>
    <section class="page-head auto-head">
        <div><p class="eyebrow">Discagem Auto</p><h1>Agendas</h1><p class="muted"><?= e((string)count($agendas)) ?> agenda(s) • <?= e((string)$totalNumbers) ?> numero(s)</p></div>
        <div class="actions">
            <button class="btn" type="button" onclick="document.querySelector('[data-reprocess-panel]').hidden = !document.querySelector('[data-reprocess-panel]').hidden"><i class="bi bi-arrow-clockwise"></i>Reprocessar</button>
            <a class="btn primary" href="#nova-agenda"><i class="bi bi-plus-lg"></i>Nova Agenda</a>
        </div>
    </section>
    <?php render_auto_subnav('auto_agendas'); ?>
    <section class="panel auto-form-panel" data-reprocess-panel hidden>
        <h2>Reprocessar Agenda</h2>
        <form method="post" class="auto-reprocess">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reprocess_auto_agenda">
            <label>Selecione uma agenda <select name="agenda_id"><?php foreach ($agendas as $agenda): ?><option value="<?= e((string)$agenda['id']) ?>"><?= e($agenda['title']) ?></option><?php endforeach; ?></select></label>
            <?php foreach (['Nao atendido' => 'A pessoa chamada nao atendeu', 'Rejeitada' => 'A pessoa atendeu, mas nenhum atendente assumiu', 'Erro' => 'Chamadas com falha tecnica'] as $status => $help): ?>
                <label class="reprocess-option"><input type="checkbox" name="statuses[]" value="<?= e($status) ?>"><span><strong><?= e($status) ?></strong><small><?= e($help) ?></small></span></label>
            <?php endforeach; ?>
            <div class="actions"><button class="btn primary">Reprocessar</button></div>
        </form>
    </section>
    <section class="auto-card-grid">
        <?php foreach ($agendas as $agenda): ?>
            <article class="auto-agenda-card">
                <div class="auto-card-head">
                    <span class="auto-card-icon"><i class="bi bi-journal-bookmark"></i></span>
                    <div><h2><?= e($agenda['title']) ?></h2><p><?= e((string)$agenda['total']) ?> numeros</p></div>
                    <form method="post" data-confirm="Remover esta agenda?">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_auto_agenda">
                        <input type="hidden" name="id" value="<?= e((string)$agenda['id']) ?>">
                        <button class="icon-action danger" title="Excluir"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
                <div class="auto-mini-stats">
                    <strong><?= e((string)$agenda['total']) ?><span>Total</span></strong>
                    <strong><?= e((string)$agenda['answered']) ?><span>Conversou</span></strong>
                    <strong><?= e($agenda['rate']) ?>%<span>Taxa Trans.</span></strong>
                </div>
                <?php foreach (['Pendente' => 'pending', 'Chamando' => 'calling', 'Nao atendido' => 'missed', 'Rejeitada' => 'rejected', 'Atendido' => 'answered'] as $label => $key): $pct = $agenda['total'] > 0 ? round(((int)$agenda[$key] / (int)$agenda['total']) * 100, 1) : 0; ?>
                    <div class="progress-row"><span><?= e($label) ?></span><i><b style="width: <?= e((string)$pct) ?>%"></b></i><strong><?= e((string)$agenda[$key]) ?></strong><small><?= e((string)$pct) ?>%</small></div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <section class="panel auto-form-panel" id="nova-agenda">
        <h2>Cadastro de Agendas</h2>
        <form method="post" enctype="multipart/form-data" class="auto-upload-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_auto_agenda">
            <label>Titulo da Agenda <input name="title" required></label>
            <h2>Importar Numeros</h2>
            <label class="toggle"><input type="checkbox" name="remove_fixed" value="1"> Remover numeros fixos</label>
            <p class="muted">Aceita TXT ou CSV com um telefone por linha ou no formato cpf;nome;telefone.</p>
            <label class="upload-button"><i class="bi bi-cloud-arrow-up"></i>Carregar arquivo<input type="file" name="numbers_file" accept=".txt,.csv" hidden></label>
            <div class="actions"><button class="btn primary">Salvar</button></div>
        </form>
    </section>
    <?php
}

function page_auto_audios(): void
{
    $current = Auth::user() ?: [];
    if (is_admin_user($current)) {
        $audios = Database::conn()->query('SELECT * FROM auto_audios ORDER BY id DESC')->fetchAll();
    } else {
        $stmt = Database::conn()->prepare('SELECT * FROM auto_audios WHERE user_id=? ORDER BY id DESC');
        $stmt->execute([(int)$current['id']]);
        $audios = $stmt->fetchAll();
    }
    ?>
    <section class="page-head auto-head">
        <div><p class="eyebrow">Discagem Auto</p><h1>Audios da Discadora</h1></div>
        <a class="btn primary" href="#novo-audio"><i class="bi bi-plus-lg"></i>Adicionar</a>
    </section>
    <?php render_auto_subnav('auto_audios'); ?>
    <section class="panel extensions-table-panel">
        <div class="extensions-toolbar"><label class="extension-search">Pesquisar<input placeholder="Pesquisar"></label></div>
        <div class="table-wrap">
            <table><thead><tr><th>Titulo</th><th>Tipo</th><th>Arquivo</th><th>Acoes</th></tr></thead><tbody>
                <?php foreach ($audios as $audio): ?>
                    <tr>
                        <td><?= e($audio['title']) ?></td>
                        <td><?= e($audio['type']) ?></td>
                        <td><?= e($audio['file_name']) ?></td>
                        <td><div class="row-actions"><audio id="audio-<?= e((string)$audio['id']) ?>" src="<?= e($audio['file_path']) ?>"></audio><button class="icon-action" onclick="document.getElementById('audio-<?= e((string)$audio['id']) ?>').play()" title="Tocar"><i class="bi bi-play-circle-fill"></i></button><form method="post" data-confirm="Remover este audio?"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_auto_audio"><input type="hidden" name="id" value="<?= e((string)$audio['id']) ?>"><button class="icon-action danger"><i class="bi bi-trash"></i></button></form></div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$audios): ?><tr><td colspan="4" class="muted">Nenhum audio cadastrado.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </section>
    <section class="panel auto-form-panel" id="novo-audio">
        <h2>Cadastro de Audios</h2>
        <form method="post" enctype="multipart/form-data" class="auto-upload-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_auto_audio">
            <label>Titulo <input name="title" required></label>
            <label>Tipo <select name="type"><option>Inicio (Quando Atende)</option><option>Fila de espera</option></select></label>
            <label class="upload-button"><i class="bi bi-headphones"></i>Upload audio<input type="file" name="audio_file" accept=".wav,.mp3,.ogg,audio/*" hidden required></label>
            <div class="actions"><button class="btn primary">Salvar</button></div>
        </form>
    </section>
    <?php
}

function render_auto_subnav(string $active): void
{
    $items = [
        'campaigns' => ['Campanhas', 'bi-broadcast'],
        'auto_agendas' => ['Agenda', 'bi-journal-bookmark'],
        'auto_audios' => ['Audios', 'bi-music-note-beamed'],
    ];
    echo '<nav class="auto-subnav" aria-label="Discagem Auto">';
    foreach ($items as $page => [$label, $icon]) {
        echo '<a href="?page=' . e($page) . '" class="' . ($active === $page ? 'active' : '') . '"><i class="bi ' . e($icon) . '"></i><span>' . e($label) . '</span></a>';
    }
    echo '</nav>';
}

function render_dial_pad(): void
{
    $keys = [
        ['1', ''], ['2', 'ABC'], ['3', 'DEF'],
        ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'],
        ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'],
        ['*', ''], ['0', '+'], ['#', ''],
    ];
    echo '<div class="dial-pad ivr-dial-pad">';
    foreach ($keys as [$digit, $letters]) {
        echo '<button class="dial-key" type="button" data-digit="' . e($digit) . '"><strong>' . e($digit) . '</strong><small>' . e($letters) . '</small></button>';
    }
    echo '</div>';
}

function campaign_board_stats(?array $user = null): array
{
    $user ??= Auth::user() ?: [];
    $campaignGuard = is_admin_user($user) ? '' : ' WHERE created_by=' . (int)$user['id'];
    $jobJoin = is_admin_user($user) ? '' : ' JOIN campaigns c ON c.id=j.campaign_id';
    $jobGuard = is_admin_user($user) ? '' : ' AND c.created_by=' . (int)$user['id'];
    $stats = ['active' => 0, 'missed' => 0, 'classified' => 0, 'abandoned' => 0, 'answered' => 0];
    $activeWhere = $campaignGuard === '' ? "WHERE status='running'" : $campaignGuard . " AND status='running'";
    $stats['active'] = (int)(Database::conn()->query("SELECT COUNT(*) FROM campaigns {$activeWhere}")->fetchColumn() ?: 0);
    $stats['missed'] = (int)(Database::conn()->query("SELECT COUNT(*) FROM call_jobs j{$jobJoin} WHERE j.status='nao_atendida'{$jobGuard}")->fetchColumn() ?: 0);
    $stats['abandoned'] = (int)(Database::conn()->query("SELECT COUNT(*) FROM call_jobs j{$jobJoin} WHERE j.status='rejeitada'{$jobGuard}")->fetchColumn() ?: 0);
    $stats['answered'] = (int)(Database::conn()->query("SELECT COUNT(*) FROM call_jobs j{$jobJoin} WHERE j.status IN ('answered','ended'){$jobGuard}")->fetchColumn() ?: 0);
    return $stats;
}

function auto_agenda_cards(?array $user = null): array
{
    $user ??= Auth::user() ?: [];
    if (is_admin_user($user)) {
        $rows = Database::conn()->query('SELECT * FROM auto_agendas ORDER BY id DESC')->fetchAll();
    } else {
        $stmt = Database::conn()->prepare('SELECT * FROM auto_agendas WHERE user_id=? ORDER BY id DESC');
        $stmt->execute([(int)$user['id']]);
        $rows = $stmt->fetchAll();
    }
    $cards = [];
    foreach ($rows as $row) {
        $stmt = Database::conn()->prepare('SELECT status, COUNT(*) AS total FROM auto_agenda_numbers WHERE agenda_id=? GROUP BY status');
        $stmt->execute([(int)$row['id']]);
        $card = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'total' => 0,
            'pending' => 0,
            'calling' => 0,
            'missed' => 0,
            'rejected' => 0,
            'classified' => 0,
            'abandoned' => 0,
            'answered' => 0,
            'rate' => '0.0',
        ];
        foreach ($stmt->fetchAll() as $status) {
            $count = (int)$status['total'];
            $card['total'] += $count;
            $key = match ((string)$status['status']) {
                'Atendido' => 'answered',
                'Chamando' => 'calling',
                'Nao Atendido', 'Nao atendido' => 'missed',
                'Rejeitada' => 'rejected',
                'Classificado' => 'classified',
                'Abandonou', 'Abandonado' => 'abandoned',
                'Erro' => 'rejected',
                default => 'pending',
            };
            $card[$key] += $count;
        }
        $card['rate'] = $card['total'] > 0 ? number_format(($card['answered'] / $card['total']) * 100, 1, '.', '') : '0.0';
        $cards[] = $card;
    }
    return $cards;
}

function page_softphone(): void
{
    $accounts = Database::conn()->query('SELECT id, name, username, sip_server, domain, websocket_url FROM sip_accounts WHERE active=1 AND webrtc_enabled=1 ORDER BY name')->fetchAll();
    $transferTargets = transfer_targets();
    ?>
    <section class="page-head">
        <div><p class="eyebrow">WebRTC</p><h1>SIP</h1></div>
    </section>
    <section class="softphone-layout">
        <div class="panel softphone microsip-phone"
            data-softphone
            data-csrf="<?= e(csrf_token()) ?>">
            <div class="softphone-top">
                <div>
                    <h2>Telefone SIP</h2>
                    <p class="muted">Use um tronco SIP com WebSocket/WSS habilitado.</p>
                </div>
                <div class="top-status">
                    <span class="status-pill" data-softphone-status>Desconectado</span>
                    <button class="btn compact" type="button" data-softphone-connect>Conectar SIP</button>
                    <button class="btn compact" type="button" data-softphone-restart>Reiniciar</button>
                </div>
            </div>
            <label>Tronco
                <select data-account-select>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= e((string)$account['id']) ?>"><?= e($account['name'] . ' - ' . $account['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="transfer-box">
                <label>Transferir para ramal/fila
                    <input data-transfer-target list="softphone-transfer-targets" placeholder="Ramal ou fila">
                    <datalist id="softphone-transfer-targets">
                        <?php foreach ($transferTargets as $target): ?>
                            <option value="<?= e($target['value']) ?>"><?= e($target['label']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <button class="btn compact" type="button" data-softphone-transfer disabled>Transferir</button>
            </div>
            <div class="call-log call-log-above-dial" data-call-log>
                <strong>Eventos da chamada</strong>
                <div data-call-log-items></div>
            </div>
            <div class="phone-screen">
                <div class="phone-call-event" data-call-title>Pronto para discar</div>
                <input data-dial-number placeholder="Numero ou SIP URI">
            </div>
            <div class="dial-pad">
                <?php
                $keys = [
                    ['1', ''], ['2', 'ABC'], ['3', 'DEF'],
                    ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'],
                    ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'],
                    ['*', ''], ['0', '+'], ['#', ''],
                ];
                foreach ($keys as [$digit, $letters]):
                ?>
                    <button class="dial-key" type="button" data-digit="<?= e($digit) ?>">
                        <strong><?= e($digit) ?></strong>
                        <small><?= e($letters) ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="phone-actions">
                <button class="phone-tool" type="button" data-softphone-connect title="Conectar SIP">SIP</button>
                <button class="phone-tool" type="button" data-dial-backspace title="Apagar">Bksp</button>
                <button class="phone-tool" type="button" data-dial-clear title="Limpar">C</button>
                <button class="phone-tool" type="button" data-softphone-restart title="Reiniciar conexao">RST</button>
                <button class="phone-call" type="button" data-softphone-call title="Ligar">Ligar</button>
                <button class="phone-hangup" type="button" data-softphone-hangup title="Desligar" disabled>Desligar</button>
                <button class="phone-tool" type="button" data-softphone-mute title="Mudo">Mudo</button>
            </div>
            <audio class="remote-audio" data-remote-audio autoplay controls></audio>
        </div>
        <div class="panel">
            <h2>Troncos WebRTC</h2>
            <?php render_webrtc_accounts($accounts); ?>
            <p class="notice">MicroSIP usa UDP/TCP. O navegador usa somente WebSocket SIP. Para Magnus/Asterisk, o endereco costuma ser `wss://SEU_DOMINIO:8089/ws` quando WebRTC esta habilitado no servidor.</p>
        </div>
    </section>
    <?php
}

function page_agent_phone(string $extension, string $password): void
{
    $agent = extension_auth_row($extension, $password);
    $agentName = trim((string)($agent['name'] ?? 'Atendente'));
    ?>
    <main class="agent-app">
        <header class="agent-topbar agent-topbar-modern">
            <div class="agent-title-block">
                <?php if (brand_logo_url()): ?>
                    <img class="agent-logo" src="<?= e(brand_logo_url()) ?>" alt="<?= e(app_display_name()) ?>">
                <?php else: ?>
                    <span class="brand-mark agent-logo-mark">DS</span>
                <?php endif; ?>
                <div>
                    <span class="agent-eyebrow">Plataforma de Atendimento</span>
                    <strong>Ola, <?= e($agentName) ?></strong>
                    <small>Ramal <?= e($extension ?: '-') ?></small>
                </div>
            </div>
            <div class="agent-top-actions">
                <span data-agent-top-status class="status-pill agent-header-status">Desconectado</span>
                <span class="agent-ping" data-agent-ping><i class="bi bi-wifi"></i> --ms</span>
                <button class="btn compact" type="button" data-theme-toggle><i class="bi bi-moon-stars"></i><span>Tema</span></button>
                <button class="btn compact" type="button" data-agent-auto-answer><i class="bi bi-telephone-inbound"></i><span>Auto atender</span></button>
                <button class="btn compact" type="button" data-agent-pause><i class="bi bi-pause-circle"></i><span>Pausar</span></button>
                <a class="btn compact danger-outline" href="?page=agent_login"><i class="bi bi-box-arrow-right"></i><span>Sair</span></a>
            </div>
        </header>
        <section class="agent-layout">
            <div class="panel softphone microsip-phone agent-phone-panel"
                data-agent-phone
                data-agent-extension="<?= e($extension) ?>"
                data-agent-password="<?= e($password) ?>"
                data-csrf="<?= e(csrf_token()) ?>">
                <div class="softphone-top">
                    <div>
                        <h2>Ramal <?= e($extension ?: '-') ?></h2>
                        <p class="muted">Mantenha esta tela aberta para receber transferencias.</p>
                    </div>
                    <div class="top-status">
                    <span class="agent-presence" data-agent-online="0">Offline</span>
                        <span class="status-pill" data-softphone-status>Desconectado</span>
                        <button class="btn compact" type="button" data-softphone-connect>Conectar</button>
                        <button class="btn compact" type="button" data-softphone-restart>Reiniciar</button>
                    </div>
                </div>
                <div class="incoming-modal" data-incoming-modal hidden>
                    <div class="incoming-card">
                        <div class="incoming-pulse">☎</div>
                        <p class="eyebrow">Chamada recebida</p>
                        <h2 data-incoming-caller>Numero</h2>
                        <p class="muted">Transferencia da discadora aguardando atendimento.</p>
                        <label>Transferir depois para
                            <input data-agent-transfer-target list="agent-transfer-targets" placeholder="Ramal ou fila">
                            <datalist id="agent-transfer-targets">
                                <?php render_transfer_target_options(); ?>
                            </datalist>
                        </label>
                        <div class="incoming-actions">
                            <button class="phone-call" type="button" data-incoming-answer>Atender</button>
                            <button class="phone-tool" type="button" data-incoming-transfer>Transferir</button>
                            <button class="phone-hangup" type="button" data-incoming-reject>Recusar</button>
                            <button class="phone-hangup" type="button" data-incoming-hangup>Desligar</button>
                        </div>
                    </div>
                </div>
                <div class="call-log call-log-above-dial" data-call-log>
                    <strong>Eventos da chamada</strong>
                    <div data-call-log-items></div>
                </div>
                <div class="phone-screen">
                    <div class="phone-call-event" data-call-title>Pronto para atender</div>
                    <input data-dial-number placeholder="Numero ou ramal">
                </div>
                <div class="dial-pad">
                    <?php foreach ([['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']] as [$digit, $letters]): ?>
                        <button class="dial-key" type="button" data-digit="<?= e($digit) ?>">
                            <strong><?= e($digit) ?></strong>
                            <small><?= e($letters) ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="phone-actions">
                    <button class="phone-tool" type="button" data-dial-backspace title="Apagar">Bksp</button>
                    <button class="phone-tool" type="button" data-dial-clear title="Limpar">C</button>
                    <button class="phone-tool" type="button" data-softphone-mute title="Mudo">Mudo</button>
                    <button class="phone-call" type="button" data-softphone-call title="Ligar">Ligar</button>
                    <button class="phone-hangup" type="button" data-softphone-hangup title="Desligar" disabled>Desligar</button>
                    <button class="phone-tool" type="button" data-softphone-connect title="Reconectar">SIP</button>
                    <button class="phone-tool" type="button" data-softphone-restart title="Reiniciar conexao">RST</button>
                </div>
                <audio class="remote-audio" data-remote-audio autoplay controls></audio>
            </div>
            <div class="panel wide agent-workspace">
                <section class="agent-stats">
                    <div><span>Total</span><strong data-agent-stat="total">0</strong></div>
                    <div><span>Pendentes</span><strong data-agent-stat="pending">0</strong></div>
                    <div><span>Nao atendido</span><strong data-agent-stat="missed">0</strong></div>
                    <div><span>Atendido</span><strong data-agent-stat="answered">0</strong></div>
                </section>
                <section class="agent-history">
                    <div class="extensions-toolbar">
                        <div>
                            <h2>Historico de Chamadas</h2>
                            <p class="muted">Chamadas recebidas e transferidas salvas em tempo real.</p>
                        </div>
                        <label class="extension-search">Pesquisar
                            <input data-agent-history-search placeholder="Numero, status ou ramal">
                        </label>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Numero</th><th>Status</th><th>Ramal</th><th>Duracao</th><th>Data e Hora</th></tr></thead>
                            <tbody data-agent-history-rows>
                                <tr><td colspan="5" class="muted">Nenhuma chamada encontrada.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    </main>
    <?php
}

function page_online_calls(): void
{
    Auth::requireAdmin();
    $history = recent_agent_call_history();
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Tempo real</p>
            <h1>Chamadas Online</h1>
        </div>
        <div class="online-head-actions">
            <span class="status-pill" data-online-refresh-status>Atualizando...</span>
            <button class="btn" type="button" data-online-refresh>Atualizar</button>
        </div>
    </section>
    <section class="online-calls-shell" data-online-calls>
        <section class="online-summary">
            <div class="online-summary-card main">
                <span><i class="bi bi-activity"></i>Ativas agora</span>
                <strong data-online-total>0</strong>
                <small data-online-last>Sem leitura ainda</small>
            </div>
            <button class="online-filter active" type="button" data-call-type="all">
                <span><i class="bi bi-grid"></i>Todos</span><strong data-count-all>0</strong>
            </button>
            <button class="online-filter" type="button" data-call-type="active">
                <span><i class="bi bi-headset"></i>Em atendimento</span><strong data-count-active>0</strong>
            </button>
            <button class="online-filter" type="button" data-call-type="waiting">
                <span><i class="bi bi-hourglass-split"></i>Aguardando</span><strong data-count-waiting>0</strong>
            </button>
            <button class="online-filter" type="button" data-call-type="audio">
                <span><i class="bi bi-volume-up"></i>Reproduzindo audio</span><strong data-count-audio>0</strong>
            </button>
            <button class="online-filter" type="button" data-call-type="routing">
                <span><i class="bi bi-signpost-split"></i>Classificando</span><strong data-count-routing>0</strong>
            </button>
        </section>
        <section class="panel online-panel">
            <div class="online-toolbar">
                <div>
                    <h2>Ligações ativas no Asterisk</h2>
                    <p class="muted"><span data-online-visible>0</span> chamada(s) ativa(s) filtrada(s). Atualiza automaticamente a cada 5 segundos.</p>
                </div>
                <div class="online-controls">
                    <label>Buscar
                        <input data-online-search placeholder="Numero, ramal, canal, status ou aplicacao">
                    </label>
                    <label>Linhas
                        <select data-online-page-size>
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                        </select>
                    </label>
                </div>
            </div>
            <div class="table-wrap online-table-wrap">
                <table class="online-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Ramal/Numero</th>
                            <th>Canal</th>
                            <th>Duracao</th>
                            <th>Aplicacao</th>
                            <th>Contexto</th>
                            <th>Ponte</th>
                        </tr>
                    </thead>
                    <tbody data-online-rows>
                        <tr><td colspan="7" class="muted empty-cell">Carregando chamadas online...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="online-pagination">
                <span data-online-page-label>Pagina 1 de 1</span>
                <div>
                    <button class="btn compact" type="button" data-online-prev>Anterior</button>
                    <button class="btn compact" type="button" data-online-next>Proxima</button>
                </div>
            </div>
        </section>
        <section class="panel online-panel">
            <div class="online-toolbar">
                <div>
                    <h2>Histórico recente dos atendentes</h2>
                    <p class="muted">Últimas chamadas recebidas, atendidas e finalizadas pelos ramais.</p>
                </div>
            </div>
            <div class="table-wrap online-table-wrap">
                <table class="online-table">
                    <thead>
                        <tr>
                            <th>Número</th>
                            <th>Status</th>
                            <th>Ramal</th>
                            <th>Caller ID</th>
                            <th>Duração</th>
                            <th>Data e hora</th>
                        </tr>
                    </thead>
                    <tbody data-online-history-rows>
                        <?php foreach ($history as $item): ?>
                            <tr>
                                <td><strong><?= e((string)$item['number']) ?></strong></td>
                                <td><span class="status-chip <?= in_array((string)$item['status'], ['Atendido', 'Finalizado'], true) ? 'ok' : ((string)$item['status'] === 'Pendente' ? '' : 'off') ?>"><?= e((string)$item['status']) ?></span></td>
                                <td><?= e((string)$item['extension']) ?></td>
                                <td><?= e((string)($item['caller_id'] ?: '-')) ?></td>
                                <td><strong><?= e((string)$item['duration']) ?></strong></td>
                                <td><?= e((string)$item['started_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$history): ?>
                            <tr><td colspan="6" class="muted empty-cell">Nenhum histórico de atendente encontrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
    <?php
}

function render_webrtc_accounts(array $accounts): void
{
    echo '<div class="table-wrap"><table><thead><tr><th>Conta</th><th>Usuario</th><th>WebSocket</th><th>Diagnostico</th></tr></thead><tbody>';
    foreach ($accounts as $account) {
        $host = $account['domain'] ?: $account['sip_server'];
        $suggestion = $host ? 'wss://' . $host . ':8089/ws' : 'wss://seudominio.com:8089/ws';
        $ws = trim((string)$account['websocket_url']);
        $diagnostic = $ws === '' ? 'Preencha WebSocket SIP. Tente: ' . $suggestion : 'Configurado';
        echo '<tr>';
        echo '<td>' . e($account['name']) . '</td>';
        echo '<td>' . e($account['username']) . '</td>';
        echo '<td>' . e($ws ?: '-') . '</td>';
        echo '<td>' . e($diagnostic) . '</td>';
        echo '</tr>';
    }
    if (!$accounts) {
        echo '<tr><td colspan="4" class="muted">Nenhum tronco WebRTC ativo.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function page_campaign_detail(int $id): void
{
    $current = Auth::user();
    $campaign = fetch_campaign_for_user($id, $current ?: []);
    if (!$campaign) {
        page_not_found();
        return;
    }
    $stats = campaign_stats($id);
    $jobs = Database::conn()->prepare('SELECT * FROM call_jobs WHERE campaign_id=? ORDER BY id DESC LIMIT 100');
    $jobs->execute([$id]);
    $jobRows = array_map(static function (array $row): array {
        $row['status'] = campaign_job_status_label((string)$row['status']);
        return $row;
    }, $jobs->fetchAll());
    ?>
    <section class="page-head">
        <div><p class="eyebrow">Campanha #<?= e((string)$id) ?></p><h1><?= e($campaign['name']) ?></h1></div>
        <div class="actions">
            <form method="post" class="row-actions">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <button class="btn primary" name="action" value="start_campaign">Iniciar</button>
                <button class="btn" name="action" value="pause_campaign">Pausar</button>
            </form>
            <form method="post" class="row-actions">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$id) ?>">
                <input type="hidden" name="statuses[]" value="nao_atendida">
                <input type="hidden" name="statuses[]" value="rejeitada">
                <input type="hidden" name="statuses[]" value="error">
                <input type="hidden" name="statuses[]" value="calling">
                <input type="hidden" name="statuses[]" value="sent">
                <button class="btn" name="action" value="reprocess_campaign"><i class="bi bi-arrow-repeat"></i>Reprocessar falhas</button>
            </form>
        </div>
    </section>
    <section class="metrics campaign-runner" data-campaign-id="<?= e((string)$id) ?>" data-dialer-mode="<?= e($campaign['dialer_mode']) ?>" data-running="<?= $campaign['status'] === 'running' ? '1' : '0' ?>">
        <div><strong data-stat="total"><?= e((string)$stats['total']) ?></strong><span>Total</span></div>
        <div><strong data-stat="pending"><?= e((string)$stats['pending']) ?></strong><span>Pendentes</span></div>
        <div><strong data-stat="calling"><?= e((string)$stats['calling']) ?></strong><span>Chamando</span></div>
        <div><strong data-stat="answered"><?= e((string)$stats['answered']) ?></strong><span>Atendidas</span></div>
        <div><strong data-stat="nao_atendida"><?= e((string)$stats['nao_atendida']) ?></strong><span>Nao atendidas</span></div>
        <div><strong data-stat="rejeitada"><?= e((string)$stats['rejeitada']) ?></strong><span>Rejeitadas</span></div>
        <div><strong data-stat="ended"><?= e((string)$stats['ended']) ?></strong><span>Finalizadas</span></div>
        <div><strong data-stat="error"><?= e((string)$stats['error']) ?></strong><span>Erros</span></div>
    </section>
    <?php if ($campaign['dialer_mode'] === 'webrtc'): ?>
        <section class="panel webrtc-dialer"
            data-campaign-id="<?= e((string)$id) ?>"
            data-account-id="<?= e((string)$campaign['sip_account_id']) ?>"
            data-simultaneous="<?= e((string)$campaign['simultaneous']) ?>"
            data-running="<?= $campaign['status'] === 'running' ? '1' : '0' ?>"
            data-transfer-target="<?= e((string)($campaign['transfer_target'] ?? '')) ?>"
            data-caller-id="<?= e((string)($campaign['caller_id'] ?: $campaign['ext_caller_id'] ?: $campaign['sip_caller_id'] ?: '')) ?>"
            data-csrf="<?= e(csrf_token()) ?>">
            <div class="softphone-top">
                <div>
                    <h2>Discador WebRTC</h2>
                    <p class="muted">Dispara chamadas pelo SIP do navegador, sem AMI e sem usar microfone local. Ao atender, a chamada e enviada para a fila/ramal configurado.</p>
                </div>
                <span class="status-pill" data-softphone-status>Desconectado</span>
            </div>
            <div class="actions">
                <button class="btn primary" type="button" data-webrtc-connect>Conectar SIP</button>
                <button class="btn" type="button" data-webrtc-run>Processar fila</button>
                <button class="btn" type="button" data-webrtc-stop>Parar fila</button>
            </div>
            <?php if (!empty($campaign['transfer_target'])): ?>
                <p class="notice">Chamadas atendidas serao transferidas para <?= e((string)$campaign['transfer_target']) ?>.</p>
            <?php endif; ?>
            <div class="call-grid" data-call-grid></div>
        </section>
    <?php else: ?>
        <section class="panel">
            <div class="softphone-top">
                <div>
                    <h2>Discador pelo servidor</h2>
                    <p class="muted">Esta campanha usa AMI no Asterisk. O painel dispara as chamadas no servidor e transfere atendidas para <?= e((string)($campaign['transfer_target'] ?: '700')) ?>.</p>
                </div>
                <span class="status-pill ok">AMI ativo</span>
            </div>
            <p class="notice">Use Iniciar para o servidor processar a fila automaticamente. WebRTC continua reservado ao softphone dos atendentes.</p>
        </section>
    <?php endif; ?>
    <section class="panel">
        <h2>Ultimos jobs</h2>
        <?php render_table($jobRows, ['number' => 'Numero', 'status' => 'Status', 'attempts' => 'Tentativas', 'response' => 'Resposta', 'updated_at' => 'Atualizado']); ?>
    </section>
    <?php
}

function page_monitor(): void
{
    Auth::requireAdmin();
    ?>
    <section class="page-head"><div><p class="eyebrow">Auditoria obrigatoria</p><h1>Monitoramento consentido</h1></div></section>
    <section class="panel">
        <p class="notice"><?= e(setting('monitoring_notice')) ?></p>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="monitor_call">
            <label>Canal do supervisor <input name="supervisor_channel" required placeholder="PJSIP/1001"></label>
            <label>Canal alvo <input name="target_channel" required placeholder="PJSIP/2002"></label>
            <label>Modo <select name="mode"><option value="listen">Ouvir</option><option value="whisper">Sussurro</option><option value="barge">Conferencia</option></select></label>
            <label class="toggle full"><input type="checkbox" name="consent" value="1" required> Confirmo que ha base legal/consentimento e que esta acao sera auditada.</label>
            <div class="actions"><button class="btn primary">Iniciar monitoramento</button></div>
        </form>
    </section>
    <?php
}

function page_audit(): void
{
    Auth::requireAdmin();
    $rows = Database::conn()->query('SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
    ?>
    <section class="page-head"><div><p class="eyebrow">Seguranca</p><h1>Auditoria</h1></div></section>
    <section class="panel"><?php render_table($rows, ['created_at' => 'Data', 'email' => 'Usuario', 'action' => 'Acao', 'details' => 'Detalhes', 'ip' => 'IP']); ?></section>
    <?php
}

function recent_campaigns(): void
{
    $current = Auth::user() ?: [];
    if (is_admin_user($current)) {
        $rows = Database::conn()->query('SELECT c.*, s.name AS sip FROM campaigns c LEFT JOIN sip_accounts s ON s.id=c.sip_account_id ORDER BY c.id DESC LIMIT 10')->fetchAll();
    } else {
        $stmt = Database::conn()->prepare('SELECT c.*, s.name AS sip FROM campaigns c LEFT JOIN sip_accounts s ON s.id=c.sip_account_id WHERE c.created_by=? ORDER BY c.id DESC LIMIT 10');
        $stmt->execute([(int)$current['id']]);
        $rows = $stmt->fetchAll();
    }
    ?>
    <section class="panel">
        <h2>Campanhas recentes</h2>
        <div class="table-wrap">
            <table><thead><tr><th>Nome</th><th>SIP</th><th>Status</th><th>Simultaneas</th><th>Criada</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td><td><?= e($row['sip']) ?></td><td><span class="badge"><?= e($row['status']) ?></span></td><td><?= e((string)$row['simultaneous']) ?></td><td><?= e($row['created_at']) ?></td>
                    <td><a class="btn compact" href="?page=campaign&id=<?= e((string)$row['id']) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    </section>
    <?php
}

function render_table(array $rows, array $columns): void
{
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($columns as $label) {
        echo '<th>' . e($label) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $key => $label) {
            $value = $row[$key] ?? '';
            if (is_int($value) && ($key === 'active' || str_starts_with($key, 'allow_'))) {
                $value = $value ? 'Sim' : 'Nao';
            }
            echo '<td>' . e((string)$value) . '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="' . count($columns) . '" class="muted">Nenhum registro.</td></tr>';
    }
    echo '</tbody></table></div>';
}

function page_not_found(): void
{
    http_response_code(404);
    echo '<section class="panel"><h1>Pagina nao encontrada</h1></section>';
}

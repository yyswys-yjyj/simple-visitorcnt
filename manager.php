<?php
/**
 * 管理面板 - 2FA + Cookie 持久登录
 * 
 * 增加数据库存储的随机 token，有效期限 1 天，过期自动锁定面板。
 */

define('DATA_FILE', __DIR__ . '/data.json');
define('DB_DIR', __DIR__ . '/data');
define('DB_FILE', DB_DIR . '/2fa.db');
define('COOKIE_NAME', 'visitor_admin_token');
define('TOKEN_LIFETIME', 86400); // 1 天

// ==================== 数据库初始化 ====================
function initDB() {
    if (!is_dir(DB_DIR)) {
        if (!mkdir(DB_DIR, 0755, true)) die('无法创建 data 目录，请手动创建 ' . DB_DIR . ' 并设置权限 755');
    }
    if (!is_writable(DB_DIR)) @chmod(DB_DIR, 0700);
    $db = new SQLite3(DB_FILE);
    @chmod(DB_FILE, 0700);
    $db->exec("CREATE TABLE IF NOT EXISTS totp (
        id INTEGER PRIMARY KEY,
        secret TEXT NOT NULL,
        registered INTEGER DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS auth_token (
        id INTEGER PRIMARY KEY,
        token TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    )");
    $db->close();
}

function get2FAInfo() {
    if (!file_exists(DB_FILE)) return null;
    $db = new SQLite3(DB_FILE);
    $result = $db->querySingle("SELECT secret, registered FROM totp LIMIT 1", true);
    $db->close();
    return $result;
}

function register2FA($secret) {
    initDB();
    $db = new SQLite3(DB_FILE);
    $db->exec("DELETE FROM totp");
    $stmt = $db->prepare("INSERT INTO totp (secret, registered) VALUES (:secret, 1)");
    $stmt->bindValue(':secret', $secret, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function is2FARegistered() {
    $info = get2FAInfo();
    return $info && $info['registered'] == 1;
}

function getSecret() {
    $info = get2FAInfo();
    return ($info && $info['registered']) ? $info['secret'] : null;
}

// ==================== 全局 lock 操作 ====================
function getGlobalLock() {
    if (!file_exists(DATA_FILE)) return false;
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return $data['lock'] ?? false;
}

function setGlobalLock($lock) {
    if (!file_exists(DATA_FILE)) return;
    $data = json_decode(file_get_contents(DATA_FILE), true);
    $data['lock'] = $lock;
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

// ==================== Cookie Token 管理 ====================
function generateSecureToken($length = 128) {
    $bytes = random_bytes($length);
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function setAuthToken($token) {
    initDB();
    $expiresAt = time() + TOKEN_LIFETIME;
    $db = new SQLite3(DB_FILE);
    $db->exec("DELETE FROM auth_token"); // 只保留一条
    $stmt = $db->prepare("INSERT INTO auth_token (token, expires_at) VALUES (:token, :expires)");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':expires', $expiresAt, SQLITE3_INTEGER);
    $stmt->execute();
    $db->close();
    setcookie(COOKIE_NAME, $token, [
        'expires'  => $expiresAt,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function clearAuthToken() {
    initDB();
    $db = new SQLite3(DB_FILE);
    $db->exec("DELETE FROM auth_token");
    $db->close();
    setcookie(COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

/**
 * 验证 Cookie 有效性
 * @return bool true-有效; false-无效(不存在或不匹配); 'expired'-已过期
 */
function checkAuthToken() {
    if (!isset($_COOKIE[COOKIE_NAME])) return false;
    $token = $_COOKIE[COOKIE_NAME];
    initDB();
    $db = new SQLite3(DB_FILE);
    $result = $db->querySingle("SELECT token, expires_at FROM auth_token LIMIT 1", true);
    $db->close();
    if (!$result) return false;
    if ($result['token'] !== $token) return false;
    if ($result['expires_at'] < time()) return 'expired';
    return true;
}

// ==================== TOTP 相关 ====================
function generateSecret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function verifyTOTP($secret, $code) {
    $timeSlice = floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        $check = $timeSlice + $i;
        $hmac = hash_hmac('sha1', pack('J', $check), base32_decode($secret), true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $truncated = (
            ((ord($hmac[$offset]) & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8) |
            (ord($hmac[$offset + 3]) & 0xFF)
        ) % 1000000;
        if ($code === str_pad($truncated, 6, '0', STR_PAD_LEFT)) {
            return true;
        }
    }
    return false;
}

function base32_decode($input) {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $output = '';
    $buffer = 0;
    $bits = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $ch = $input[$i];
        $val = strpos($map, $ch);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bits += 5;
        while ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $output;
}

function generateQRCode($data) {
    if (file_exists(__DIR__ . '/qrlib.php')) {
        require_once(__DIR__ . '/qrlib.php');
        ob_start();
        QRcode::png($data, null, QR_ECLEVEL_L, 4);
        $imageData = ob_get_clean();
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($data);
}

// ==================== 辅助函数 ====================
function sanitizePath($path) {
    $path = str_replace(['..', './', '\\', '/', "\0"], '', $path);
    $path = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $path);
    if ($path === '') $path = 'default';
    return substr($path, 0, 64);
}

// ==================== 处理 AJAX 请求 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // 注册验证
    if ($action === 'register') {
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
        $secret = $_POST['secret'] ?? '';
        if ($secret && verifyTOTP($secret, $code)) {
            register2FA($secret);
            if (getGlobalLock()) setGlobalLock(false);
            // 注册成功后，生成 token 并自动登录
            $token = generateSecureToken();
            setAuthToken($token);
            echo json_encode(['success' => true, 'redirect' => $_SERVER['PHP_SELF']]);
        } else {
            echo json_encode(['success' => false, 'error' => '验证码错误，请重试。']);
        }
        exit;
    }
    
    // 管理操作（需要已注册、lock=false 且 Cookie 有效）
    if (getGlobalLock() === true) {
        echo json_encode(['success' => false, 'error' => '面板已锁定，请先解锁。']);
        exit;
    }
    $authResult = checkAuthToken();
    if ($authResult === false) {
        echo json_encode(['success' => false, 'error' => '未授权访问。']);
        exit;
    }
    if ($authResult === 'expired') {
        setGlobalLock(true);
        clearAuthToken();
        echo json_encode(['success' => false, 'error' => '登录已过期，面板已锁定。']);
        exit;
    }
    
    // 处理增删改查
    $data = json_decode(file_get_contents(DATA_FILE), true);
    if (!isset($data['paths'])) $data['paths'] = [];
    $paths = &$data['paths'];
    $response = ['success' => false];
    
    if ($action === 'edit') {
        $pathKey = $_POST['path_key'] ?? '';
        if (isset($paths[$pathKey])) {
            if (isset($_POST['tag'])) $paths[$pathKey]['tag'] = $_POST['tag'] !== '' ? $_POST['tag'] : null;
            if (isset($_POST['display_name'])) $paths[$pathKey]['display_name'] = $_POST['display_name'] !== '' ? $_POST['display_name'] : null;
            file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
            $response = ['success' => true];
        } else {
            $response = ['success' => false, 'error' => '路径不存在'];
        }
    } elseif ($action === 'delete') {
        $pathKey = $_POST['path_key'] ?? '';
        if (isset($paths[$pathKey])) {
            unset($paths[$pathKey]);
            file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
            $response = ['success' => true];
        } else {
            $response = ['success' => false, 'error' => '路径不存在'];
        }
    } elseif ($action === 'add') {
        $newPath = sanitizePath($_POST['new_path'] ?? '');
        if (empty($newPath)) {
            $response = ['success' => false, 'error' => '路径不能为空'];
        } elseif (isset($paths[$newPath])) {
            $response = ['success' => false, 'error' => '路径已存在'];
        } else {
            $newTag = $_POST['new_tag'] ?? '';
            $newDisplayName = $_POST['new_display_name'] ?? '';
            $paths[$newPath] = [
                'total' => 0,
                'today' => 0,
                'last_date' => date('Y-m-d'),
                'tag' => $newTag !== '' ? $newTag : null,
                'display_name' => $newDisplayName !== '' ? $newDisplayName : null
            ];
            file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT));
            $response = ['success' => true, 'path' => $newPath, 'tag' => $newTag, 'display_name' => $newDisplayName];
        }
    }
    echo json_encode($response);
    exit;
}

// ==================== 主流程 ====================
$lock = getGlobalLock();

// 未注册 → 注册页面
if (!is2FARegistered()) {
    $newSecret = generateSecret();
    $qrData = 'otpauth://totp/VisitorManager:admin?secret=' . $newSecret . '&issuer=VisitorManager';
    $qrSrc = generateQRCode($qrData);
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><title>初始化 | 访问量统计器</title><style>
        body{font-family:sans-serif;text-align:center;padding:2rem;}#qr img{max-width:200px;}.error{color:red;margin-top:1rem;}button{padding:6px 12px;}
    </style></head>
    <body>
        <h2>首次使用，请配置两步验证</h2>
        <p>使用 Google Authenticator 或类似应用扫描下方二维码：</p>
        <div id="qr"><img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR Code"></div>
        <p>或手动输入密钥：<code><?= htmlspecialchars($newSecret) ?></code></p>
        <form id="registerForm">
            <input type="hidden" name="secret" value="<?= htmlspecialchars($newSecret) ?>">
            <label>动态验证码：<input type="text" id="code" name="code" pattern="[0-9]{6}" required></label>
            <button type="submit">验证并激活</button>
        </form>
        <div id="error" class="error"></div>
        <script>
            document.getElementById('registerForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const code = document.getElementById('code').value;
                const secret = document.querySelector('input[name="secret"]').value;
                const errorDiv = document.getElementById('error');
                errorDiv.textContent = '';
                const fd = new FormData();
                fd.append('action', 'register');
                fd.append('code', code);
                fd.append('secret', secret);
                const resp = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
                const result = await resp.json();
                if(result.success) window.location.href = result.redirect;
                else errorDiv.textContent = result.error;
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// 已注册，处理解锁（lock=true 且带 check 参数）
if ($lock && isset($_GET['check'])) {
    $code = preg_replace('/[^0-9]/', '', $_GET['check']);
    $secret = getSecret();
    if ($secret && verifyTOTP($secret, $code)) {
        // 解锁成功：生成 token，设置 lock=false
        $token = generateSecureToken();
        setAuthToken($token);
        setGlobalLock(false);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// 如果 lock=true 且没有有效的 check，直接 404
if ($lock) {
    http_response_code(404);
    die('404 Not Found');
}

// 此时 lock=false，需要验证 Cookie
$authResult = checkAuthToken();
if ($authResult === false) {
    // 无效 Cookie → 404（不锁定）
    http_response_code(404);
    die('404 Not Found');
}
if ($authResult === 'expired') {
    // Cookie 过期 → 自动锁定并 404
    setGlobalLock(true);
    clearAuthToken();
    http_response_code(404);
    die('404 Not Found');
}

// ========== 管理面板（lock=false 且 Cookie 有效） ==========
$data = json_decode(file_get_contents(DATA_FILE), true);
if (!isset($data['paths'])) $data['paths'] = [];
$paths = $data['paths'];
$currentLock = getGlobalLock();

// 处理普通 POST（锁定按钮的同步提交）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_lock') {
        clearAuthToken();      // 清除 token（登出）
        setGlobalLock(true);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>访问量统计管理面板</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: system-ui, sans-serif; max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f2f2f2; }
        input, button { padding: 6px 12px; }
        .lock-info { background: #f9f9f9; padding: 1rem; margin-bottom: 1rem; border-left: 4px solid #2196F3; }
        .btn-lock { background: #ff9800; color: white; border: none; cursor: pointer; }
        .btn-delete { background: #f44336; color: white; border: none; cursor: pointer; }
        .btn-save { background: #4caf50; color: white; border: none; cursor: pointer; }
        .btn-add { background: #2196F3; color: white; border: none; cursor: pointer; }
        .add-form { background: #f5f5f5; padding: 1rem; margin-top: 1rem; border-radius: 8px; }
        .message { position: fixed; top: 20px; right: 20px; background: #4caf50; color: white; padding: 10px 20px; border-radius: 5px; display: none; z-index: 1000; }
        .message.error { background: #f44336; }
    </style>
</head>
<body>
    <div id="message" class="message"></div>

    <h1>管理 | 计数器路由</h1>
    <div class="lock-info">
        <strong>全局锁定状态：</strong> <?= $currentLock ? '<i class="fa-solid fa-lock"></i> 已锁定' : '<i class="fa-solid fa-unlock"></i> 未锁定' ?>
        <?php if (!$currentLock): ?>
        <form method="post" style="display:inline; margin-left:1rem;" id="lockForm">
            <input type="hidden" name="action" value="set_lock">
            <button type="submit" class="btn-lock" onclick="return confirm('锁定后将禁止新路径自动创建，并且面板需要动态码才能再次进入，确定吗？')">立即锁定</button>
        </form>
        <small>（锁定后，需要访问 <?= $_SERVER['PHP_SELF'] ?>?check=动态码 才能解锁）</small>
        <?php endif; ?>
    </div>

    <h2>现有路由</h2>
    <table id="routesTable">
        <thead>
            <tr><th>路径</th><th>总访问量</th><th>今日访问</th><th>最后更新</th><th>Tag 图片</th><th>显示名称</th><th>操作</th></tr>
        </thead>
        <tbody id="routesTbody">
            <?php foreach ($paths as $pathKey => $info): ?>
            <tr data-path="<?= htmlspecialchars($pathKey) ?>">
                <td><strong><?= htmlspecialchars($pathKey) ?></strong></td>
                <td><?= number_format($info['total']) ?></td>
                <td><?= number_format($info['today']) ?></td>
                <td><?= htmlspecialchars($info['last_date']) ?></td>
                <td><input type="text" class="tag-input" value="<?= htmlspecialchars($info['tag'] ?? '') ?>" placeholder="例如: logo.png" style="width:100px;"></td>
                <td><input type="text" class="display-name-input" value="<?= htmlspecialchars($info['display_name'] ?? '') ?>" placeholder="显示名称" style="width:120px;"></td>
                <td>
                    <button class="btn-save save-row">保存</button>
                    <button class="btn-delete delete-row">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="add-form">
        <h3>新增路由</h3>
        <form id="addForm">
            <label>路径：<input type="text" id="newPath" required placeholder="例如: my-new-page"></label><br>
            <label>Tag 图片：<input type="text" id="newTag" placeholder="tag.png"></label><br>
            <label>显示名称：<input type="text" id="newDisplayName" placeholder="友好名称"></label><br>
            <button type="submit" class="btn-add">创建</button>
        </form>
    </div>

    <script>
        function showMessage(msg, isError = false) {
            const msgDiv = document.getElementById('message');
            msgDiv.textContent = msg;
            msgDiv.className = 'message ' + (isError ? 'error' : '');
            msgDiv.style.display = 'block';
            setTimeout(() => msgDiv.style.display = 'none', 3000);
        }

        async function saveRow(row) {
            const pathKey = row.getAttribute('data-path');
            const tag = row.querySelector('.tag-input').value;
            const displayName = row.querySelector('.display-name-input').value;
            const fd = new FormData();
            fd.append('action', 'edit');
            fd.append('path_key', pathKey);
            fd.append('tag', tag);
            fd.append('display_name', displayName);
            try {
                const resp = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
                const result = await resp.json();
                if(result.success) showMessage('保存成功');
                else showMessage(result.error || '保存失败', true);
            } catch(e) { showMessage('网络错误', true); }
        }

        async function deleteRow(row) {
            if(!confirm('确定删除该路由？')) return;
            const pathKey = row.getAttribute('data-path');
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('path_key', pathKey);
            try {
                const resp = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
                const result = await resp.json();
                if(result.success) {
                    row.remove();
                    showMessage('删除成功');
                } else showMessage(result.error || '删除失败', true);
            } catch(e) { showMessage('网络错误', true); }
        }

        async function addRoute() {
            const newPath = document.getElementById('newPath').value.trim();
            if(!newPath) { showMessage('路径不能为空', true); return; }
            const newTag = document.getElementById('newTag').value;
            const newDisplayName = document.getElementById('newDisplayName').value;
            const fd = new FormData();
            fd.append('action', 'add');
            fd.append('new_path', newPath);
            fd.append('new_tag', newTag);
            fd.append('new_display_name', newDisplayName);
            try {
                const resp = await fetch(window.location.href, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd });
                const result = await resp.json();
                if(result.success) {
                    const tbody = document.getElementById('routesTbody');
                    const newRow = document.createElement('tr');
                    newRow.setAttribute('data-path', result.path);
                    newRow.innerHTML = `
                        <td><strong>${escapeHtml(result.path)}</strong></td>
                        <td>0</td><td>0</td><td>${new Date().toISOString().slice(0,10)}</td>
                        <td><input type="text" class="tag-input" value="${escapeHtml(result.tag || '')}" placeholder="例如: logo.png" style="width:100px;"></td>
                        <td><input type="text" class="display-name-input" value="${escapeHtml(result.display_name || '')}" placeholder="显示名称" style="width:120px;"></td>
                        <td><button class="btn-save save-row">保存</button> <button class="btn-delete delete-row">删除</button></td>
                    `;
                    tbody.appendChild(newRow);
                    newRow.querySelector('.save-row').addEventListener('click', () => saveRow(newRow));
                    newRow.querySelector('.delete-row').addEventListener('click', () => deleteRow(newRow));
                    document.getElementById('newPath').value = '';
                    document.getElementById('newTag').value = '';
                    document.getElementById('newDisplayName').value = '';
                    showMessage('创建成功');
                } else {
                    showMessage(result.error || '创建失败', true);
                }
            } catch(e) { showMessage('网络错误', true); }
        }

        function escapeHtml(str) {
            if(!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if(m === '&') return '&amp;';
                if(m === '<') return '&lt;';
                if(m === '>') return '&gt;';
                return m;
            });
        }

        // 绑定按钮
        document.querySelectorAll('.save-row').forEach(btn => {
            btn.addEventListener('click', (e) => saveRow(e.target.closest('tr')));
        });
        document.querySelectorAll('.delete-row').forEach(btn => {
            btn.addEventListener('click', (e) => deleteRow(e.target.closest('tr')));
        });
        document.getElementById('addForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            addRoute();
        });
        // 锁定表单
        const lockForm = document.getElementById('lockForm');
        if(lockForm) {
            lockForm.addEventListener('submit', (e) => {
                if(!confirm('锁定后将禁止新路径自动创建，并且面板需要动态码才能再次进入，确定吗？')) {
                    e.preventDefault();
                }
            });
        }
    </script>
</body>
</html>

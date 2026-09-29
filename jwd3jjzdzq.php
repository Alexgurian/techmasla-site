<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

$ADMIN_NAME = 'jwd3jjzdzq.php';
$ADMIN_SHA  = 'df2b5bf06672798a084ff726ca513d88cad081536bc5bf6d0d83b44a586dde75';
$SITE       = 'https://techmasla.ru';
$MAXIMG     = 4 * 1024 * 1024;

require __DIR__ . '/redaktor-data/lib.php';

$DATA = __DIR__ . '/redaktor-data';
$BK   = k_backup_dir();
$IMG  = __DIR__ . '/img';
foreach ([$DATA, $BK, $IMG] as $d) { if (!is_dir($d)) @mkdir($d, 0755, true); }

@ini_set('session.cookie_httponly', '1');
if (PHP_VERSION_ID >= 70300) {
    @ini_set('session.cookie_samesite', 'Strict');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
} else {
    session_set_cookie_params(0, '/');
}
session_start();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

function k_csrf() {
    if (empty($_SESSION['t'])) $_SESSION['t'] = bin2hex(random_bytes(16));
    return $_SESSION['t'];
}
function k_csrf_ok() {
    return isset($_POST['t'], $_SESSION['t']) && hash_equals($_SESSION['t'], (string)$_POST['t']);
}
function k_auth() { return !empty($_SESSION['ok']); }
function k_fail() { http_response_code(403); echo 'Доступ запрещён'; exit; }
function k_json(array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function k_login_try($code) {
    global $ADMIN_SHA, $DATA;
    usleep(400000);
    if ($code !== '' && hash_equals($ADMIN_SHA, hash('sha256', $code))) {
        session_regenerate_id(true);
        $_SESSION['ok'] = 1;
        if (empty($_SESSION['t'])) $_SESSION['t'] = bin2hex(random_bytes(16));
        @unlink($DATA . '/fails.log');
        return true;
    }
    @file_put_contents($DATA . '/fails.log', date('c') . " fail\n", FILE_APPEND | LOCK_EX);
    return false;
}
function k_page_ok($f) {
    global $ADMIN_NAME;
    $f = basename((string)$f);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,49}\.html$/', $f)) return null;
    if (!in_array($f, k_pages_list(__DIR__, $ADMIN_NAME), true)) return null;
    return $f;
}
function k_msgs() {
    return [
        'saved' => 'Сохранено. Изменения уже на сайте.',
        'added' => 'Блок добавлен на страницу.',
        'deleted' => 'Блок удалён.',
        'page' => 'Страница создана и добавлена в меню.',
        'img' => 'Картинка загружена.',
        'imgdel' => 'Картинка удалена.',
        'restored' => 'Восстановлено из резервной копии.',
        'bye' => 'Вы вышли из редактора.',
        'pagedel' => 'Страница удалена.',
        'moved' => 'Блок перемещён.',
        'err' => 'Операция не выполнена. Попробуйте ещё раз.',
    ];
}

$action = (string)(isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : ''));

if ($action === 'login') {
    $ok = k_login_try((string)(isset($_POST['code']) ? $_POST['code'] : ''));
    if (isset($_POST['plain'])) {
        header('Location: ?view=panel' . ($ok ? '' : '&err=login'));
        exit;
    }
    k_json($ok ? ['ok' => 1, 'go' => '/' . $ADMIN_NAME . '?view=panel'] : ['ok' => 0]);
}
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $ADMIN_NAME . '?msg=bye');
    exit;
}

if (!k_auth()) {
    if ($action !== '') k_fail();
    $err = isset($_GET['err']) && $_GET['err'] === 'login';
    ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Вход</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
.card{background:#fff;border:1px solid #d7dee5;border-radius:12px;padding:32px;max-width:360px;width:calc(100% - 32px)}
h1{margin:0 0 6px;font-size:22px;color:#12283c}
p{margin:0 0 18px;color:#5d6b7a;font-size:14px}
input[type=password]{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d7dee5;border-radius:8px;font-size:16px;margin-bottom:14px}
button{width:100%;padding:12px;border:0;border-radius:8px;background:#1f6fce;color:#fff;font-size:15px;font-weight:700;cursor:pointer}
.e{color:#c0392b;font-size:13px;margin-bottom:10px}
</style>
</head>
<body>
<div class="card">
<h1>Редактор сайта</h1>
<?php if ($err) { ?><p class="e">Неверный код доступа</p><?php } else { ?><p>Введите код доступа</p><?php } ?>
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>">
<input type="hidden" name="action" value="login">
<input type="hidden" name="plain" value="1">
<input type="password" name="code" autofocus autocomplete="off">
<button type="submit">Войти</button>
</form>
</div>
</body>
</html><?php
    exit;
}

$POSTPAGES = ['save_block', 'add_block', 'del_block', 'move_block', 'new_page', 'del_page', 'upload_img', 'del_img', 'restore'];
if (in_array($action, $POSTPAGES, true)) {
    if (!k_csrf_ok()) k_fail();
}

if ($action === 'save_block') {
    $f = k_page_ok(isset($_POST['file']) ? $_POST['file'] : '');
    if (!$f) k_fail();
    $do = (string)(isset($_POST['do']) ? $_POST['do'] : 'save');
    if ($do === 'up' || $do === 'down') {
        $okm = k_move_block(__DIR__ . '/' . $f, (string)(isset($_POST['id']) ? $_POST['id'] : ''), $do);
        header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($okm ? 'moved' : 'err'));
        exit;
    }
    if ($do === 'del') {
        $okd = k_del_block(__DIR__ . '/' . $f, (string)(isset($_POST['id']) ? $_POST['id'] : ''));
        header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($okd ? 'deleted' : 'err'));
        exit;
    }
    $ok = k_save_block(__DIR__ . '/' . $f, (string)(isset($_POST['id']) ? $_POST['id'] : ''), (string)(isset($_POST['content']) ? $_POST['content'] : ''));
    if (isset($_POST['ajax'])) k_json(['ok' => $ok ? 1 : 0, 'e' => $ok ? '' : 'Блок не найден']);
    header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($ok ? 'saved' : 'err'));
    exit;
}
if ($action === 'add_block') {
    $f = k_page_ok(isset($_POST['file']) ? $_POST['file'] : '');
    if (!$f) k_fail();
    $newid = '';
    $ok = k_add_block(__DIR__ . '/' . $f, (string)(isset($_POST['content']) ? $_POST['content'] : ''), $newid);
    header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($ok ? 'added' : 'err'));
    exit;
}
if ($action === 'del_block') {
    $f = k_page_ok(isset($_POST['file']) ? $_POST['file'] : '');
    if (!$f) k_fail();
    $ok = k_del_block(__DIR__ . '/' . $f, (string)(isset($_POST['id']) ? $_POST['id'] : ''));
    header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($ok ? 'deleted' : 'err'));
    exit;
}
if ($action === 'move_block') {
    $f = k_page_ok(isset($_POST['file']) ? $_POST['file'] : '');
    if (!$f) k_fail();
    $ok = k_move_block(__DIR__ . '/' . $f, (string)(isset($_POST['id']) ? $_POST['id'] : ''), (isset($_POST['dir']) && $_POST['dir'] === 'up') ? 'up' : 'down');
    header('Location: ?view=page&f=' . urlencode((string)$f) . '&msg=' . ($ok ? 'moved' : 'err'));
    exit;
}
if ($action === 'new_page') {
    $title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
    $slug = strtolower(trim((string)(isset($_POST['slug']) ? $_POST['slug'] : '')));
    $tpl = @file_get_contents($DATA . '/template.html');
    if ($tpl === false) { header('Location: ?view=panel&err=tpl'); exit; }
    $r = k_new_page(__DIR__, $tpl, $slug, $title, (string)(isset($_POST['content']) ? $_POST['content'] : ''), $ADMIN_NAME, $SITE, basename((string)(isset($_POST['tpl']) ? $_POST['tpl'] : '')));
    header('Location: ?view=panel&' . ($r[0] ? 'msg=page' : 'err=' . urlencode($r[1])));
    exit;
}
if ($action === 'del_page') {
    $slug = basename((string)(isset($_POST['file']) ? $_POST['file'] : ''));
    $r = k_del_page(__DIR__, $slug, $ADMIN_NAME);
    header('Location: ?view=panel&' . ($r[0] ? 'msg=pagedel' : 'err=' . urlencode($r[1])));
    exit;
}
if ($action === 'upload_img') {
    $r = k_upload_img($IMG, 'img', isset($_FILES['f']) ? $_FILES['f'] : null, $MAXIMG);
    k_json($r[0] ? ['ok' => 1, 'url' => $r[1]] : ['ok' => 0, 'e' => $r[1]]);
}
if ($action === 'del_img') {
    $n = basename((string)(isset($_POST['name']) ? $_POST['name'] : ''));
    $ok = k_img_name_ok($n) && is_file($IMG . '/' . $n) && @unlink($IMG . '/' . $n);
    header('Location: ?view=images&msg=' . ($ok ? 'imgdel' : 'err'));
    exit;
}
if ($action === 'restore') {
    $ok = k_restore($BK, __DIR__, (string)(isset($_POST['bname']) ? $_POST['bname'] : ''));
    header('Location: ?view=backups&msg=' . ($ok ? 'restored' : 'err'));
    exit;
}

$view = (string)(isset($_GET['view']) ? $_GET['view'] : 'panel');
$msgs = k_msgs();
$msg = isset($_GET['msg']) ? (isset($msgs[(string)$_GET['msg']]) ? $msgs[(string)$_GET['msg']] : '') : '';
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
$MSGHTML = $msg !== '' ? '<div class="msg">' . k_h($msg) . '</div>' : ($err !== '' ? '<div class="err">' . k_h($err) . '</div>' : '');

?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Редактор сайта</title>
<style>
:root{--navy:#12283c;--acc:#1f6fce;--line:#d7dee5;--mut:#5d6b7a}
*{box-sizing:border-box}
body{margin:0;background:#f4f6f8;color:#1d2733;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:15px;line-height:1.5}
.top{background:var(--navy);color:#fff;padding:12px 20px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.top b{font-size:16px}
.top a{color:#c9d6e4;text-decoration:none;font-size:14px}
.top a:hover{color:#fff}
.wrap{max-width:1000px;margin:0 auto;padding:24px 16px 60px}
.card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:20px;margin-bottom:16px}
.card h2{margin:0 0 12px;font-size:19px;color:var(--navy)}
.btn{display:inline-block;background:var(--acc);color:#fff;border:0;padding:10px 18px;border-radius:8px;font-size:14.5px;font-weight:700;cursor:pointer;text-decoration:none}
.btn:hover{background:#15549f}
.btn2{background:#fff;color:var(--navy);border:1px solid var(--line)}
.btn2:hover{background:#eef3f9}
.btn-red{background:#fff;color:#c0392b;border:1px solid #e5b9b3}
.btn-red:hover{background:#fdf1ef}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid var(--line);font-size:14.5px;vertical-align:middle}
th{color:var(--mut);font-size:12.5px;text-transform:uppercase;letter-spacing:.05em}
.msg{background:#e8f6ee;border:1px solid #b7dfc6;color:#1e6b41;padding:10px 14px;border-radius:8px;margin-bottom:16px}
.err{background:#fdf1ef;border:1px solid #e5b9b3;color:#96271b;padding:10px 14px;border-radius:8px;margin-bottom:16px}
.toolbar{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.toolbar button{border:1px solid var(--line);background:#fff;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer;font-weight:600;color:var(--navy)}
.toolbar button:hover{background:#eef3f9}
.ed{min-height:140px;border:1px solid var(--line);border-radius:8px;padding:12px;background:#fff;line-height:1.6;overflow-wrap:break-word}
.ed:focus{outline:2px solid var(--acc);outline-offset:-1px}
.ed img{max-width:100%;height:auto}
.ed h3,.ed h4{margin:.6em 0 .3em}
.ed p{margin:.4em 0}
textarea{width:100%;min-height:120px;padding:12px;border:1px solid var(--line);border-radius:8px;font-size:15px;font-family:inherit}
label{display:block;font-weight:700;font-size:13.5px;margin:0 0 6px;color:var(--navy)}
input[type=text],input[type=password]{width:100%;padding:11px;border:1px solid var(--line);border-radius:8px;font-size:15px}
.row{margin-bottom:14px}
.hint{color:var(--mut);font-size:12.5px;margin:6px 0 0}
.blk{border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}
.blk > label{font-size:14px}
.brow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
.thumbs{display:flex;flex-wrap:wrap;gap:12px}
.thumb{border:1px solid var(--line);border-radius:8px;padding:8px;width:150px;text-align:center}
.thumb img{max-width:100%;height:80px;object-fit:cover}
.thumb .brow{justify-content:center}
.small{font-size:12px;color:var(--mut)}
.pickback{position:fixed;inset:0;background:rgba(14,36,56,.55);z-index:9000;display:flex;align-items:center;justify-content:center}
.pickbox{background:#fff;border-radius:12px;max-width:820px;width:calc(100% - 32px);max-height:86vh;overflow:auto;padding:20px}
.pickgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}
.ptile{cursor:pointer;border:1px solid var(--line);border-radius:10px;padding:14px;background:#fff;text-align:center}
.ptile:hover{border-color:var(--acc);box-shadow:0 6px 18px rgba(18,40,60,.12)}
.pt-name{font-weight:700;font-size:14px;color:var(--navy)}
.back{display:inline-block;margin-bottom:14px;color:var(--acc);text-decoration:none;font-weight:600}
.imgpanel{display:none;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0;padding:8px 10px;background:#eef4fb;border:1px solid var(--line);border-radius:8px}
.imgpanel .small{margin-right:2px}
.imgpanel input[type=range]{flex:1;min-width:120px}
.imgpanel input[type=number]{width:70px;padding:5px 8px;border:1px solid var(--line);border-radius:6px}
</style>
</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
<symbol id="i-drop" viewBox="0 0 24 24"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></symbol>
<symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.2"/><line x1="12" y1="3.5" x2="12" y2="6.2"/><line x1="12" y1="17.8" x2="12" y2="20.5"/><line x1="3.5" y1="12" x2="6.2" y2="12"/><line x1="17.8" y1="12" x2="20.5" y2="12"/><line x1="5.9" y1="5.9" x2="7.8" y2="7.8"/><line x1="16.2" y1="16.2" x2="18.1" y2="18.1"/><line x1="5.9" y1="18.1" x2="7.8" y2="16.2"/><line x1="16.2" y1="7.8" x2="18.1" y2="5.9"/></symbol>
<symbol id="i-truck" viewBox="0 0 24 24"><rect x="1" y="5" width="14" height="11" rx="1"/><polygon points="15 9 19 9 22 12 22 16 15 16"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></symbol>
<symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol>
</svg>
<div class="top"><b>Редактор сайта</b><span class="small" style="color:#8ba3bd">techmasla.ru</span>
<span style="flex:1"></span>
<?php if ($view === 'panel') { ?>
<a href="?view=new">+ Новая страница</a>
<a href="?view=images">Картинки</a>
<a href="?view=backups">Бэкапы</a>
<a href="?action=logout">Выйти</a>
<?php } else { ?>
<a href="?view=panel">Все страницы</a>
<?php } ?>
</div>
<div class="wrap">
<?php echo $MSGHTML; ?>
<?php
if ($view === 'page') {
    $f = k_page_ok(isset($_GET['f']) ? $_GET['f'] : '');
    if (!$f) { echo '<div class="err">Страница не найдена</div><a class="back" href="?view=panel">&larr; Все страницы</a>'; }
    else {
        $blocks = k_blocks(__DIR__ . '/' . $f);
        echo '<a class="back" href="?view=panel">&larr; Все страницы</a>';
        echo '<div class="card"><h2>Страница: ' . k_h($f) . '</h2><p class="hint">Выделяйте текст мышкой и оформляйте кнопками. Картинка вставляется кнопкой «Картинка» в то место, где стоит курсор. Не забудьте нажать «Сохранить» под каждым блоком.</p></div>';
        if (!$blocks) echo '<div class="card"><p>На этой странице нет редактируемых блоков.</p></div>';
        foreach ($blocks as $b) {
            $deletable = preg_match('/^(block|sec)-/', $b['id']) ? 1 : 0;
            ?>
<div class="blk">
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>" class="bform">
<input type="hidden" name="action" value="save_block">
<input type="hidden" name="file" value="<?php echo k_h($f); ?>">
<input type="hidden" name="id" value="<?php echo k_h($b['id']); ?>">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<input type="hidden" name="content" class="hsrc" value="">
<label><?php echo k_h(k_block_label($b['id'])); ?></label>
<div class="toolbar">
<button type="button" data-c="bold"><b>Ж</b></button>
<button type="button" data-c="italic"><i>К</i></button>
<button type="button" data-c="underline"><u>Ч</u></button>
<button type="button" data-c="formatBlock" data-v="&lt;h3&gt;">Заголовок</button>
<button type="button" data-c="insertUnorderedList">Список</button>
<button type="button" data-c="insertOrderedList">Нумерация</button>
<button type="button" data-c="link">Ссылка</button>
<button type="button" data-c="img">Картинка</button>
<button type="button" data-c="removeFormat">Очистить стиль</button>
<button type="button" data-c="undo">&larr; Отмена</button>
</div>
<div class="ed" contenteditable="true" spellcheck="false"><?php echo $b['html']; ?></div>
<div class="brow">
<button type="submit" class="btn" name="do" value="save">Сохранить</button>
<?php if ($deletable) { ?>
<button type="submit" class="btn btn2" name="do" value="up">↑ Выше</button>
<button type="submit" class="btn btn2" name="do" value="down">↓ Ниже</button>
<button type="submit" class="btn btn-red" name="do" value="del">Удалить блок</button>
<?php } ?>
<span class="small" style="flex:1;text-align:right">Блок: <?php echo k_h($b['id']); ?></span>
</div>
</form>
</div>
<?php
        }
        ?>
<div class="blk">
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>" class="bform">
<input type="hidden" name="action" value="add_block">
<input type="hidden" name="file" value="<?php echo k_h($f); ?>">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<input type="hidden" name="content" class="hsrc" value="">
<label>Добавить новый блок: выберите готовый вид — карточки, цифры, этапы и другие — и отредактируйте текст</label>
<div class="toolbar">
<button type="button" data-blocks><b>+</b> Выбрать блок</button>
<button type="button" data-c="bold"><b>Ж</b></button>
<button type="button" data-c="italic"><i>К</i></button>
<button type="button" data-c="underline"><u>Ч</u></button>
<button type="button" data-c="formatBlock" data-v="&lt;h3&gt;">Заголовок</button>
<button type="button" data-c="insertUnorderedList">Список</button>
<button type="button" data-c="link">Ссылка</button>
<button type="button" data-c="img">Картинка</button>
<button type="button" data-c="removeFormat">Очистить стиль</button>
</div>
<div class="ed" contenteditable="true" spellcheck="false" id="add-ed"><p>Новый текст…</p></div>
<div class="brow"><button type="submit" class="btn">Добавить блок</button></div>
</form>
</div>
<?php
    }
} elseif ($view === 'new') {
    ?>
<a class="back" href="?view=panel">&larr; Все страницы</a>
<div class="card">
<h2>Новая страница</h2>
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>" class="bform">
<input type="hidden" name="action" value="new_page">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<input type="hidden" name="content" class="hsrc" value="">
<div class="row"><label>Заголовок страницы (H1 и название в меню)</label>
<input type="text" name="title" required maxlength="80" placeholder="Например: Поставка редукторов"></div>
<div class="row"><label>Адрес страницы (латиницей, через дефис)</label>
<input type="text" name="slug" required maxlength="50" placeholder="postavka-reduktorov">
<p class="hint">Страница получит адрес: <?php echo k_h($SITE); ?>/адрес.html</p></div>
<div class="row"><label>Шаблон страницы</label>
<select name="tpl" style="width:100%;padding:11px;border:1px solid var(--line);border-radius:8px;font-size:15px;background:#fff">
<option value="">Без шаблона (просто текст)</option>
<?php foreach (k_templates() as $tid => $t) { ?><option value="<?php echo k_h($tid); ?>"><?php echo k_h($t['name']); ?> — <?php echo k_h($t['desc']); ?></option><?php } ?>
</select>
<p class="hint">Шаблон наполнит страницу готовыми секциями — потом всё редактируется, перемещается и удаляется.</p></div>
<label>Текст страницы</label>
<div class="toolbar">
<button type="button" data-c="bold"><b>Ж</b></button>
<button type="button" data-c="italic"><i>К</i></button>
<button type="button" data-c="underline"><u>Ч</u></button>
<button type="button" data-c="formatBlock" data-v="&lt;h3&gt;">Заголовок</button>
<button type="button" data-c="insertUnorderedList">Список</button>
<button type="button" data-c="link">Ссылка</button>
<button type="button" data-c="img">Картинка</button>
<button type="button" data-c="removeFormat">Очистить стиль</button>
</div>
<div class="ed" contenteditable="true" spellcheck="false"><p>Текст страницы…</p></div>
<p class="hint">Первый абзац станет подзаголовком в шапке страницы, остальное — основным текстом. Страница автоматически добавится в меню и sitemap.xml.</p>
<div class="brow" style="margin-top:14px"><button type="submit" class="btn">Создать страницу</button></div>
</form>
</div>
<?php
} elseif ($view === 'images') {
    $imgs = glob($IMG . '/k-*.*');
    usort($imgs, function ($a, $b) { return filemtime($b) - filemtime($a); });
    ?>
<a class="back" href="?view=panel">&larr; Все страницы</a>
<div class="card">
<h2>Картинки</h2>
<p class="hint">Загружайте картинки кнопкой «Картинка» в редакторе страницы — они сразу вставляются в текст. Здесь можно удалить ненужные. Сжимайте фото перед загрузкой (например, на tinypng.com) — сайт должен открываться быстро.</p>
<div class="thumbs">
<?php foreach ($imgs as $im) { $n = basename($im); ?>
<div class="thumb">
<img src="img/<?php echo k_h($n); ?>" alt="">
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>" class="brow" style="margin-top:8px">
<input type="hidden" name="action" value="del_img">
<input type="hidden" name="name" value="<?php echo k_h($n); ?>">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<button type="submit" class="btn btn-red" style="padding:6px 10px;font-size:12.5px">Удалить</button>
</form>
</div>
<?php } ?>
<?php if (!$imgs) { ?><p class="hint">Пока нет загруженных картинок.</p><?php } ?>
</div>
</div>
<?php
} elseif ($view === 'backups') {
    $baks = glob($BK . '/*.bak');
    usort($baks, function ($a, $b) { return filemtime($b) - filemtime($a); });
    ?>
<a class="back" href="?view=panel">&larr; Все страницы</a>
<div class="card">
<h2>Резервные копии</h2>
<p class="hint">Перед каждым сохранением автоматически сохраняется прежняя версия файла. Восстановление тоже создаёт копию текущей версии.</p>
<table>
<tr><th>Файл</th><th>Когда</th><th></th></tr>
<?php foreach (array_slice($baks, 0, 60) as $b) { $n = basename($b); ?>
<tr>
<td><?php echo k_h(preg_replace('/\.(\d{8}-\d{6}-[a-f0-9]{6})\.bak$/', '', $n)); ?></td>
<td class="small"><?php echo k_h(date('d.m.Y H:i:s', filemtime($b))); ?></td>
<td>
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>">
<input type="hidden" name="action" value="restore">
<input type="hidden" name="bname" value="<?php echo k_h($n); ?>">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<button type="submit" class="btn btn2" style="padding:6px 12px;font-size:12.5px">Восстановить</button>
</form>
</td>
</tr>
<?php } ?>
<?php if (!$baks) { ?><tr><td colspan="3" class="hint">Копий пока нет.</td></tr><?php } ?>
</table>
</div>
<?php
} else {
    $list = k_pages_list(__DIR__, $ADMIN_NAME);
    ?>
<div class="card">
<h2>Страницы сайта</h2>
<table>
<tr><th>Страница</th><th>Блоков</th><th></th></tr>
<?php foreach ($list as $f) { if ($f === '404.html' || $f === 'mobile.html') continue; $nb = count(k_blocks(__DIR__ . '/' . $f)); ?>
<tr>
<td><?php echo k_h($f); ?></td>
<td><?php echo $nb; ?></td>
<td>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
<a class="btn btn2" style="padding:7px 14px;font-size:13px" href="?view=page&f=<?php echo k_h(urlencode($f)); ?>"><?php echo $nb ? 'Редактировать' : 'Открыть'; ?></a>
<?php if ($f !== 'index.html') { ?>
<form method="POST" action="<?php echo k_h($ADMIN_NAME); ?>" style="display:inline" onsubmit="return confirm('Удалить страницу <?php echo k_h($f); ?>? Ссылки в меню и sitemap обновятся автоматически.');">
<input type="hidden" name="action" value="del_page">
<input type="hidden" name="file" value="<?php echo k_h($f); ?>">
<input type="hidden" name="t" value="<?php echo k_h(k_csrf()); ?>">
<button type="submit" class="btn btn-red" style="padding:7px 12px;font-size:13px">Удалить</button>
</form>
<?php } ?>
</div>
</td>
</tr>
<?php } ?>
</table>
<p class="hint">Здесь можно менять текст на любой странице сайта. Изменения появляются на сайте сразу после сохранения.</p>
</div>
<?php
}
?>
</div>
<script>
var CSRF = '<?php echo k_h(k_csrf()); ?>';
window._ed = null;
document.addEventListener('focusin', function (e) {
  if (e.target.classList && e.target.classList.contains('ed')) window._ed = e.target;
});
function kApplyImg(img, mode, w) {
  img.style.width = (mode === 'full' ? 100 : w) + '%';
  img.style.height = 'auto';
  img.style.display = 'block';
  img.style.float = 'none';
  img.style.margin = '12px auto';
  if (mode === 'left') { img.style.float = 'left'; img.style.margin = '0 20px 12px 0'; }
  if (mode === 'right') { img.style.float = 'right'; img.style.margin = '0 0 12px 20px'; }
}
function kShowImgPanel(ed, img) {
  var blk = ed.closest('.blk') || ed.closest('.card');
  if (!blk) return;
  var panel = blk.querySelector('.imgpanel');
  if (!panel) {
    panel = document.createElement('div');
    panel.className = 'imgpanel';
    panel.setAttribute('data-mode', 'center');
    panel.innerHTML = '<span class="small">Положение:</span>' +
      '<button type="button" data-imgpos="left">Слева с текстом</button>' +
      '<button type="button" data-imgpos="center">По центру</button>' +
      '<button type="button" data-imgpos="right">Справа с текстом</button>' +
      '<button type="button" data-imgpos="full">Во всю ширину</button>' +
      '<span class="small" style="margin-left:10px">Размер:</span>' +
      '<input type="range" min="10" max="100" value="50" data-imgw-range>' +
      '<input type="number" min="5" max="100" value="50" data-imgw-num">%' +
      '<button type="button" class="btn btn2" data-imgdel style="padding:6px 10px;font-size:12.5px">Убрать картинку</button>';
    blk.insertBefore(panel, ed.parentNode.nextSibling || null);
    panel.querySelectorAll('[data-imgpos]').forEach(function (b) {
      b.addEventListener('click', function () {
        panel.setAttribute('data-mode', b.getAttribute('data-imgpos'));
        kApplyImg(img, panel.getAttribute('data-mode'), parseInt(panel.querySelector('[data-imgw-range]').value, 10));
        ed.dispatchEvent(new Event('input', { bubbles: true }));
      });
    });
    panel.querySelector('[data-imgw-range]').addEventListener('input', function () {
      panel.querySelector('[data-imgw-num]').value = this.value;
      kApplyImg(img, panel.getAttribute('data-mode'), parseInt(this.value, 10));
      ed.dispatchEvent(new Event('input', { bubbles: true }));
    });
    panel.querySelector('[data-imgw-num]').addEventListener('input', function () {
      var v = parseInt(this.value, 10);
      if (!isNaN(v) && v >= 5 && v <= 100) {
        panel.querySelector('[data-imgw-range]').value = v;
        kApplyImg(img, panel.getAttribute('data-mode'), v);
        ed.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    panel.querySelector('[data-imgdel]').addEventListener('click', function () {
      img.remove();
      panel.style.display = 'none';
      ed.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }
  var w = parseInt(img.style.width, 10);
  if (isNaN(w)) w = 50;
  panel.querySelector('[data-imgw-range]').value = w;
  panel.querySelector('[data-imgw-num]').value = w;
  panel.style.display = 'flex';
}
document.addEventListener('click', function (e) {
  var img = e.target && e.target.closest && e.target.closest('.ed img');
  if (img) {
    var ed = img.closest('.ed');
    if (ed) { window._ed = ed; kShowImgPanel(ed, img); }
  }
});
document.querySelectorAll('.bform').forEach(function (f) {
  var ed = f.querySelector('.ed');
  if (ed) {
    ed.addEventListener('dragstart', function (e) {
      var img = e.target.closest && e.target.closest('img');
      if (!img) return;
      window.__kdrag = img;
      try { e.dataTransfer.setData('text/plain', 'k-img'); e.dataTransfer.effectAllowed = 'move'; } catch (err) {}
    });
    ed.addEventListener('dragover', function (e) { if (window.__kdrag) e.preventDefault(); });
    ed.addEventListener('drop', function (e) {
      if (!window.__kdrag) return;
      e.preventDefault();
      var img = window.__kdrag;
      window.__kdrag = null;
      var range = null;
      if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(e.clientX, e.clientY);
      else if (document.caretPositionFromPoint) {
        var p = document.caretPositionFromPoint(e.clientX, e.clientY);
        range = document.createRange();
        range.setStart(p.offsetNode, p.offset);
      }
      if (range) { range.insertNode(img); ed.dispatchEvent(new Event('input', { bubbles: true })); }
    });
  }
  var src = f.querySelector('.hsrc');
  if (!ed || !src) return;
  ed.addEventListener('input', function () { src.value = ed.innerHTML; });
  f.addEventListener('submit', function () { src.value = ed.innerHTML; });
  ed.addEventListener('paste', function (e) {
    e.preventDefault();
    var t = e.clipboardData ? e.clipboardData.getData('text/plain') : '';
    if (!t) return;
    t = t.replace(/\r/g, '');
    var parts = t.split(/\n{2,}/).filter(function (s) { return s.trim() !== ''; });
    var html = parts.map(function (s) {
      var x = s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      return '<p>' + x.replace(/\n/g, '<br>') + '</p>';
    }).join('');
    document.execCommand('insertHTML', false, html);
  });
});
function k_cmd(c, v) {
  var ed = window._ed || document.querySelector('.ed');
  if (!ed) return;
  ed.focus();
  if (c === 'link') {
    var u = prompt('Ссылка (URL):', 'https://');
    if (u && u !== 'https://') document.execCommand('createLink', false, u);
  } else if (c === 'img') {
    document.getElementById('imgfile').click();
  } else {
    document.execCommand(c, false, v || null);
  }
}
var K_BLOCKS = [
  { id: 'text', name: 'Текст', html: '<p>Расскажите здесь о вашем предложении: что поставляете, для кого и в какие сроки.</p><p>Второй абзац — детали, условия работы, гарантии.</p>' },
  { id: 'head-text', name: 'Заголовок + текст', html: '<h3>Заголовок раздела</h3><p>Поясняющий текст под заголовком: ключевые условия, состав предложения.</p>' },
  { id: 'stats', name: 'Цифры ×3', html: '<div class="stats"><div class="stat"><b>10+</b><span>лет на рынке</span></div><div class="stat"><b>500</b><span>наименований продукции</span></div><div class="stat"><b>40</b><span>постоянных клиентов</span></div></div>' },
  { id: 'checks', name: 'Чек-лист', html: '<ul class="checks"><li>Первое преимущество или условие.</li><li>Второе преимущество или условие.</li><li>Третье преимущество или условие.</li></ul>' },
  { id: 'steps', name: 'Этапы ×3', html: '<div class="steps"><div class="step"><div class="step-num">1</div><h3>Шаг один</h3><p>Что происходит на первом шаге.</p></div><div class="step-arrow"><svg class="ico"><use href="#i-arrow"/></svg></div><div class="step"><div class="step-num">2</div><h3>Шаг два</h3><p>Что происходит на втором шаге.</p></div><div class="step-arrow"><svg class="ico"><use href="#i-arrow"/></svg></div><div class="step"><div class="step-num">3</div><h3>Шаг три</h3><p>Результат шага.</p></div></div>' },
  { id: 'quote', name: 'Цитата', html: '<blockquote class="quote">Важная мысль или обещание компании — то, что должно запомниться клиенту.</blockquote>' },
  { id: 'callout', name: 'Важно', html: '<div class="callout"><svg class="ico"><use href="#i-doc"/></svg><span>Важная информация: документы, сроки, особые условия.</span></div>' },
  { id: 'photo', name: 'Фото', html: '<div class="media-ph">Плейсхолдер под фото. Вставьте картинку кнопкой «Картинка».</div>' },
  { id: 'list', name: 'Список', html: '<ul><li>Пункт списка.</li><li>Пункт списка.</li><li>Пункт списка.</li></ul>' }
];
document.querySelectorAll('[data-blocks]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var ed = document.getElementById('add-ed');
    if (!ed) return;
    var back = document.createElement('div');
    back.className = 'pickback';
    var tiles = K_BLOCKS.map(function (s) {
      return '<div class="ptile" data-kb="' + s.id + '"><div style="font-size:22px;color:var(--acc);font-weight:800;margin-bottom:6px">▦</div>' +
        '<div class="pt-name">' + s.name + '</div></div>';
    }).join('');
    back.innerHTML = '<div class="pickbox"><h2>Выберите вид блока</h2>' +
      '<p class="hint" style="margin:0 0 12px">Блок встанет перед формой связи. После вставки отредактируйте текст прямо в блоке и нажмите «Добавить блок».</p>' +
      '<div class="pickgrid">' + tiles + '</div>' +
      '<div class="brow" style="margin-top:14px"><button type="button" class="btn btn2" id="pick-close">Отмена</button></div></div>';
    document.body.appendChild(back);
    back.querySelector('#pick-close').addEventListener('click', function () { back.remove(); });
    back.addEventListener('click', function (e) { if (e.target === back) back.remove(); });
    back.querySelectorAll('[data-kb]').forEach(function (t) {
      t.addEventListener('click', function () {
        var s = K_BLOCKS.find(function (x) { return x.id === t.getAttribute('data-kb'); });
        ed.innerHTML = s.html;
        back.remove();
        ed.focus();
      });
    });
  });
});
document.querySelectorAll('[data-c]').forEach(function (b) {
  b.addEventListener('click', function (e) {
    e.preventDefault();
    k_cmd(b.getAttribute('data-c'), b.getAttribute('data-v'));
  });
});
var fi = document.createElement('input');
fi.type = 'file';
fi.id = 'imgfile';
fi.accept = 'image/*';
fi.style.display = 'none';
document.body.appendChild(fi);
fi.addEventListener('change', function () {
  var file = fi.files && fi.files[0];
  if (!file) return;
  var fd = new FormData();
  fd.append('action', 'upload_img');
  fd.append('t', CSRF);
  fd.append('f', file);
  var x = new XMLHttpRequest();
  x.open('POST', location.pathname);
  x.onload = function () {
    try {
      var j = JSON.parse(x.responseText);
      if (j.ok) {
        var ed = window._ed || document.querySelector('.ed');
        if (ed) {
          ed.focus();
          document.execCommand('insertImage', false, j.url);
          ed.querySelectorAll('img').forEach(function (im) {
            if (!im.getAttribute('loading')) im.setAttribute('loading', 'lazy');
          });
          var src = ed.closest('.bform');
          if (src) src.querySelector('.hsrc').value = ed.innerHTML;
          var imgs = ed.querySelectorAll('img');
          if (imgs.length) kShowImgPanel(ed, imgs[imgs.length - 1]);
        }
      } else {
        alert(j.e || 'Ошибка загрузки');
      }
    } catch (e) { alert('Ошибка загрузки'); }
  };
  x.send(fd);
  fi.value = '';
});
</script>
</body>
</html>
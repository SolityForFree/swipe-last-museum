<?php
/**
 * Admin page: password login, restart today's set, anonymous visit stats.
 *
 * Password (first one found wins):
 *   1. ADMIN_PASSWORD environment variable (server / CI secret)
 *   2. data/admin_hash.php  (bcrypt hash written by deploy.sh; the plain password never leaves your computer)
 *   3. ADMIN_PASSWORD in the project's .env (local development)
 */

declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

const SESSION_IDLE = 8 * 3600;     // log out after 8 h without activity
const MAX_FAILS = 10;              // failed logins allowed …
const FAIL_WINDOW = 15 * 60;       // … within 15 minutes, then login is locked for the rest of the window

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

// ---------- password ----------
function env_file_value(string $key): ?string {
    $path = APP_ROOT . '/.env';
    if (!is_file($path)) return null;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim(preg_replace('/^export\s+/', '', $k)) !== $key) continue;
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        return $v;
    }
    return null;
}

/** Returns ['plain' => string] or ['hash' => string], or null when no password is configured. */
function admin_secret(): ?array {
    $env = getenv('ADMIN_PASSWORD');
    if (is_string($env) && $env !== '') return ['plain' => $env];
    $hashFile = APP_ROOT . '/data/admin_hash.php';
    if (is_file($hashFile)) {
        $hash = include $hashFile;
        if (is_string($hash) && $hash !== '') return ['hash' => $hash];
    }
    $local = env_file_value('ADMIN_PASSWORD');
    if ($local !== null && $local !== '') return ['plain' => $local];
    return null;
}

function password_ok(string $given): bool {
    $secret = admin_secret();
    if (!$secret || $given === '') return false;
    return isset($secret['hash']) ? password_verify($given, $secret['hash']) : hash_equals($secret['plain'], $given);
}

// ---------- session ----------
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
session_name('muse_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => $basePath, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

if (!empty($_SESSION['admin']) && time() - ($_SESSION['seen'] ?? 0) > SESSION_IDLE) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$loggedIn = !empty($_SESSION['admin']);
if ($loggedIn) $_SESSION['seen'] = time();

function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect_self(string $flash = ''): never {
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}
function csrf_ok(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }

// ---------- actions ----------
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $pdo = db();
            $pdo->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - FAIL_WINDOW]);
            $fails = (int)$pdo->query('SELECT COUNT(*) FROM login_fail')->fetchColumn();
            if (!admin_secret()) {
                $error = 'Heslo správce není nastavené (ADMIN_PASSWORD).';
            } elseif ($fails >= MAX_FAILS) {
                $error = 'Příliš mnoho neúspěšných pokusů. Zkuste to za 15 minut.';
            } elseif (csrf_ok() && password_ok((string)($_POST['password'] ?? ''))) {
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                $_SESSION['seen'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
                $pdo->exec('DELETE FROM login_fail');
                redirect_self();
            } else {
                $pdo->prepare('INSERT INTO login_fail (ts) VALUES (?)')->execute([time()]);
                sleep(1);  // slows down guessing
                $error = 'Nesprávné heslo.';
            }
        } elseif ($loggedIn && csrf_ok()) {
            if ($action === 'logout') {
                $_SESSION = [];
                session_destroy();
                redirect_self();
            }
            if ($action === 'restart') {
                restart_today();
                redirect_self('Dnešní sada byla restartována: nových 10 děl z poolu, dnešní hlasy smazány.');
            }
        } elseif ($loggedIn) {
            $error = 'Platnost formuláře vypršela, zkuste to znovu.';
        }
    }
} catch (AppError $ex) {
    $error = $ex->getMessage();
} catch (Throwable $ex) {
    error_log('admin: ' . $ex->getMessage());
    $error = 'Chyba serveru.';
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// ---------- data for the dashboard ----------
function fmt_secs(float $s): string {
    $s = (int)round($s);
    if ($s >= 3600) return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    if ($s < 60) return "$s s";
    return intdiv($s, 60) . ' min ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
}
function median(array $v): float {
    if (!$v) return 0;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}
/** Titles/artists for a few ids, read from the artwork lists. */
function artwork_info(array $ids): array {
    $want = array_flip($ids);
    $out = [];
    foreach (DATA_FILES as $file) {
        $path = APP_ROOT . '/' . $file;
        if (!is_file($path)) continue;
        $text = (string)file_get_contents($path);
        $list = json_decode(substr($text, strpos($text, '['), strrpos($text, ']') - strpos($text, '[') + 1), true) ?: [];
        foreach ($list as $a) {
            $id = (string)($a['id'] ?? '');
            if (isset($want[$id])) $out[$id] = ['title' => $a['title'] ?? $id, 'artist' => $a['artist'] ?? '', 'date' => $a['date'] ?? ''];
        }
    }
    return $out;
}

$dash = null;
if ($loggedIn) {
    try {
        $today = today();
        $ranking = ranking($today);
        if (!$ranking) { daily_ids($today); $ranking = ranking($today); }
        $info = artwork_info(array_column($ranking, 'id'));
        $views = view_times($today);
        $vt = db()->prepare('SELECT SUM(total_ms) AS ms, SUM(views) AS n FROM artwork_time WHERE day >= ?');
        $vt->execute([date('Y-m-d', strtotime('-13 days'))]);
        $vtRow = $vt->fetch();
        $viewAvg14 = ($vtRow && $vtRow['n'] > 0) ? $vtRow['ms'] / $vtRow['n'] / 1000 : null;

        $from = date('Y-m-d', strtotime('-13 days'));
        $q = db()->prepare('SELECT day, seconds, swipes FROM visit WHERE day >= ? AND seconds > 0 ORDER BY day DESC');
        $q->execute([$from]);
        $byDay = [];
        foreach ($q->fetchAll() as $r) {
            $byDay[$r['day']]['secs'][] = (int)$r['seconds'];
            $byDay[$r['day']]['swipes'][] = (int)$r['swipes'];
        }
        $votes = db()->prepare('SELECT day, COUNT(*) AS votes, COUNT(DISTINCT device_id) AS voters FROM vote WHERE day >= ? GROUP BY day');
        $votes->execute([$from]);
        $votesByDay = [];
        foreach ($votes->fetchAll() as $r) $votesByDay[$r['day']] = $r;

        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $secs = $byDay[$d]['secs'] ?? [];
            $sw = $byDay[$d]['swipes'] ?? [];
            $rows[] = [
                'day' => $d,
                'visits' => count($secs),
                'avg' => $secs ? array_sum($secs) / count($secs) : 0,
                'median' => median($secs),
                'swipes' => array_sum($sw),
                'avg_swipes' => $sw ? array_sum($sw) / count($sw) : 0,
                'completed' => count(array_filter($sw, fn($x) => $x >= DAILY_COUNT)),
                'votes' => (int)($votesByDay[$d]['votes'] ?? 0),
            ];
        }

        // distributions over the last 7 days
        $week = date('Y-m-d', strtotime('-6 days'));
        $timeBuckets = ['< 10 s' => 0, '10–30 s' => 0, '30–60 s' => 0, '1–2 min' => 0, '2–5 min' => 0, '5+ min' => 0];
        $swipeBuckets = ['0' => 0, '1–3' => 0, '4–6' => 0, '7–9' => 0, '10 (vše)' => 0, 'více než 10' => 0];
        $q = db()->prepare('SELECT seconds, swipes FROM visit WHERE day >= ? AND seconds > 0');
        $q->execute([$week]);
        $weekVisits = 0;
        foreach ($q->fetchAll() as $r) {
            $weekVisits++;
            $s = (int)$r['seconds'];
            $k = $s < 10 ? '< 10 s' : ($s < 30 ? '10–30 s' : ($s < 60 ? '30–60 s' : ($s < 120 ? '1–2 min' : ($s < 300 ? '2–5 min' : '5+ min'))));
            $timeBuckets[$k]++;
            $w = (int)$r['swipes'];
            $k = $w === 0 ? '0' : ($w <= 3 ? '1–3' : ($w <= 6 ? '4–6' : ($w <= 9 ? '7–9' : ($w === 10 ? '10 (vše)' : 'více než 10'))));
            $swipeBuckets[$k]++;
        }
        // per traffic source (?source=… on the link), over the chosen range
        $srcDays = in_array((int)($_GET['days'] ?? 14), [7, 14, 30], true) ? (int)$_GET['days'] : 14;
        $q = db()->prepare('SELECT source, seconds, swipes FROM visit WHERE day >= ? AND seconds > 0');
        $q->execute([date('Y-m-d', strtotime('-' . ($srcDays - 1) . ' days'))]);
        $bySrc = [];
        foreach ($q->fetchAll() as $r) {
            $bySrc[$r['source']]['secs'][] = (int)$r['seconds'];
            $bySrc[$r['source']]['swipes'][] = (int)$r['swipes'];
        }
        $sources = [];
        foreach ($bySrc as $src => $v) {
            $n = count($v['secs']);
            $sources[] = [
                'source' => $src,
                'visits' => $n,
                'avg' => array_sum($v['secs']) / $n,
                'median' => median($v['secs']),
                'total' => array_sum($v['secs']),
                'avg_swipes' => array_sum($v['swipes']) / $n,
                'completed' => count(array_filter($v['swipes'], fn($x) => $x >= DAILY_COUNT)),
            ];
        }
        usort($sources, fn($a, $b) => $b['visits'] <=> $a['visits'] ?: $b['total'] <=> $a['total']);

        $pool = pool_status();
        $dash = compact('today', 'ranking', 'info', 'rows', 'timeBuckets', 'swipeBuckets', 'weekVisits', 'pool', 'sources', 'srcDays', 'views', 'viewAvg14');
    } catch (Throwable $ex) {
        error_log('admin dashboard: ' . $ex->getMessage());
        $error = $error ?: ($ex instanceof AppError ? $ex->getMessage() : 'Nepodařilo se načíst data.');
    }
}

$czDay = fn(string $d) => (new DateTimeImmutable($d))->format('j. n.') . ' ' . ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'][(int)(new DateTimeImmutable($d))->format('w')];
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Správa · Umělecký swipe</title>
<style>
  :root { --bg: #9e846d; --card: #fbf8f3; --ink: #1f1812; --muted: #6b5c4d; --line: #e4dbcf; --accent: #2f6f4f; --danger: #a8402b; --bar: #c9b49c; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 40px; }
  header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0; }
  h2 { font-size: 17px; margin: 0 0 12px; }
  .card { background: var(--card); border-radius: 14px; padding: 18px; margin-bottom: 16px; box-shadow: 0 6px 20px rgba(40,25,10,.18); }
  .muted { color: var(--muted); }
  .note { font-size: 13px; color: var(--muted); margin: 10px 0 0; }
  button, .btn { font: inherit; border: 0; border-radius: 999px; padding: 9px 18px; cursor: pointer; background: var(--ink); color: #fff; }
  button.ghost { background: transparent; color: var(--ink); border: 1px solid rgba(31,24,18,.35); }
  button.danger { background: var(--danger); }
  .msg { padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; }
  .msg.ok { background: #e3f0e7; color: #1e4d33; }
  .msg.err { background: #f7e1dc; color: #7a2717; }
  /* login */
  .login { max-width: 360px; margin: 12vh auto 0; }
  .login input { width: 100%; font: inherit; padding: 11px 12px; border-radius: 10px; border: 1px solid var(--line); margin: 6px 0 14px; }
  .login button { width: 100%; }
  /* set */
  .set { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
  .art { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; }
  .art img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: #eee; }
  .art div { padding: 8px 10px; font-size: 13px; line-height: 1.3; }
  .art b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .vt { margin-top: 2px; font-size: 12.5px; color: var(--muted); }
  .votes { margin-top: 4px; } .votes .l { color: var(--accent); font-weight: 600; } .votes .d { color: var(--danger); font-weight: 600; margin-left: 8px; }
  .restart { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 14px; }
  /* tables */
  .scroll { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: right; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  th:first-child, td:first-child { text-align: left; }
  th { font-weight: 600; color: var(--muted); font-size: 13px; }
  tr.today td { font-weight: 600; }
  td.zero { color: #b3a594; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .dist { display: grid; grid-template-columns: 7.5em minmax(0, 1fr) 2.5em; gap: 6px 10px; align-items: center; font-size: 14px; }
  .dist > span { white-space: nowrap; }
  .dist .bar { height: 14px; background: var(--line); border-radius: 7px; overflow: hidden; }
  .dist .bar i { display: block; height: 100%; background: var(--bar); }
  .dist span:last-child { text-align: right; color: var(--muted); }
  .h2row { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; }
  .range { display: flex; gap: 4px; }
  .range a { font-size: 13px; padding: 4px 10px; border-radius: 999px; color: var(--muted); text-decoration: none; border: 1px solid var(--line); }
  .range a.on { background: var(--ink); color: #fff; border-color: var(--ink); font-weight: 600; }
  code { font-size: 13px; background: #f1e9de; padding: 1px 5px; border-radius: 4px; }
  @media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
<?php if (!$loggedIn): ?>
  <form class="card login" method="post" autocomplete="off">
    <h1>Správa</h1>
    <p class="muted">Umělecký swipe</p>
    <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
    <label for="pw">Heslo</label>
    <input id="pw" type="password" name="password" required autofocus autocomplete="current-password">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <button type="submit">Přihlásit</button>
  </form>
<?php else: ?>
  <header>
    <h1>Správa · Umělecký swipe</h1>
    <form method="post">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <button class="ghost" type="submit">Odhlásit</button>
    </form>
  </header>

  <?php if ($flash): ?><div class="msg ok"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>

  <?php if ($dash): ?>
  <section class="card">
    <h2>Dnešní sada · <?= e($czDay($dash['today'])) ?></h2>
    <div class="set">
      <?php foreach ($dash['ranking'] as $i => $r): $a = $dash['info'][$r['id']] ?? ['title' => $r['id'], 'artist' => '', 'date' => '']; ?>
        <div class="art">
          <img src="../pool/<?= e($r['id']) ?>.jpg" alt="" loading="lazy" onerror="this.onerror=null;this.src='../images/<?= e($r['id']) ?>.jpg'">
          <div>
            <b title="<?= e($a['title']) ?>"><?= $i + 1 ?>. <?= e($a['title']) ?></b>
            <span class="muted"><?= e(trim(preg_replace('/\s*\(.*$/', '', (string)$a['artist']) . ($a['date'] ? ', ' . $a['date'] : ''), ', ')) ?></span>
            <div class="votes"><span class="l">♥ <?= $r['likes'] ?></span><span class="d">✕ <?= $r['dislikes'] ?></span></div>
            <?php $v = $dash['views'][$r['id']] ?? null; ?>
            <div class="vt" title="Průměrná doba prohlížení před swipem<?= $v ? ' (' . $v['views'] . '×)' : '' ?>">⏱ <?= $v ? 'Ø ' . e(number_format($v['avg_s'], 1, ',', ' ')) . ' s' : '–' ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <form class="restart" method="post" onsubmit="return confirm('Opravdu restartovat dnešní sadu? Vybere se nových 10 děl z poolu a dnešní hlasy všech uživatelů se smažou.');">
      <input type="hidden" name="action" value="restart">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <button class="danger" type="submit">Restartovat dnešní sadu</button>
      <span class="muted">Nových 10 děl z poolu (7 od roku 1900, 3 starší). Dnešní hlasy se smažou.</span>
    </form>
    <p class="note">Svůj vlastní postup v telefonu vynulujete otevřením <code>swipe.html?mode=restart</code>.</p>
    <p class="note">⏱ = průměrná doba, po kterou se lidé na obrázek dívali, než swipli (jen když byla stránka na obrazovce, max. 5 min).
      Průměr všech obrázků za 14 dní: <b><?= $dash['viewAvg14'] !== null ? e(number_format($dash['viewAvg14'], 1, ',', ' ')) . ' s' : '–' ?></b>.</p>
    <p class="note">Pool: <?= $dash['pool']['total'] ?> děl, z toho <?= $dash['pool']['unshown'] ?> ještě nezobrazeno.
      Dílo se znovu objeví nejdříve po <?= MIN_REPEAT_DAYS ?> dnech; při současné velikosti poolu zhruba po <?= $dash['pool']['cycle_days'] ?> dnech.</p>
  </section>

  <section class="card">
    <h2>Návštěvy za posledních 14 dní</h2>
    <div class="scroll">
      <table>
        <thead><tr><th>Den</th><th>Návštěvy</th><th>Ø doba</th><th>Medián doby</th><th>Swipy celkem</th><th>Ø swipů</th><th>Dokončeno 10/10</th><th>Hlasy</th></tr></thead>
        <tbody>
        <?php foreach ($dash['rows'] as $r): $z = $r['visits'] === 0; ?>
          <tr class="<?= $r['day'] === $dash['today'] ? 'today' : '' ?>">
            <td><?= e($czDay($r['day'])) ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $r['visits'] ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $z ? '–' : e(fmt_secs($r['avg'])) ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $z ? '–' : e(fmt_secs($r['median'])) ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $r['swipes'] ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $z ? '–' : number_format($r['avg_swipes'], 1, ',', ' ') ?></td>
            <td class="<?= $z ? 'zero' : '' ?>"><?= $z ? '–' : $r['completed'] . ' (' . round($r['completed'] / $r['visits'] * 100) . ' %)' ?></td>
            <td class="<?= $r['votes'] ? '' : 'zero' ?>"><?= $r['votes'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="note">Návštěva = jedno otevření stránky. Doba = čas, kdy byla stránka skutečně na obrazovce. Hlasy = lajky a dislajky dnešní sady.</p>
  </section>

  <section class="card">
    <div class="h2row">
      <h2>Zdroje návštěv</h2>
      <nav class="range">
        <?php foreach ([7, 14, 30] as $d): ?>
          <a href="?days=<?= $d ?>" class="<?= $dash['srcDays'] === $d ? 'on' : '' ?>"><?= $d ?> dní</a>
        <?php endforeach; ?>
      </nav>
    </div>
    <?php if (!$dash['sources']): ?>
      <p class="muted">Za posledních <?= $dash['srcDays'] ?> dní zatím žádné návštěvy.</p>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>Zdroj</th><th>Návštěvy</th><th>Ø doba</th><th>Medián doby</th><th>Čas celkem</th><th>Ø swipů</th><th>Dokončeno 10/10</th></tr></thead>
        <tbody>
        <?php foreach ($dash['sources'] as $r): ?>
          <tr>
            <td><?= $r['source'] === '' ? '<span class="muted">přímý (bez parametru)</span>' : '<code>' . e($r['source']) . '</code>' ?></td>
            <td><?= $r['visits'] ?></td>
            <td><?= e(fmt_secs($r['avg'])) ?></td>
            <td><?= e(fmt_secs($r['median'])) ?></td>
            <td><?= e(fmt_secs($r['total'])) ?></td>
            <td><?= number_format($r['avg_swipes'], 1, ',', ' ') ?></td>
            <td><?= $r['completed'] . ' (' . round($r['completed'] / $r['visits'] * 100) . ' %)' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="note">Zdroj se nastaví odkazem, např. <code>…/swipe.html?source=instagram</code> (funguje i <code>utm_source</code>). Povolená jsou malá písmena, číslice, „-“ a „_“, max. 32 znaků. Zdroj se ukládá jen k anonymní návštěvě, ne do zařízení.</p>
  </section>

  <section class="card">
    <h2>Rozložení za posledních 7 dní <span class="muted">(<?= $dash['weekVisits'] ?> návštěv)</span></h2>
    <div class="grid2">
      <?php foreach (['Doba na stránce' => $dash['timeBuckets'], 'Počet swipů za návštěvu' => $dash['swipeBuckets']] as $title => $buckets): $max = max(1, max($buckets)); ?>
        <div>
          <h3 class="muted" style="font-size:14px;margin:0 0 8px"><?= e($title) ?></h3>
          <div class="dist">
            <?php foreach ($buckets as $label => $n): ?>
              <span><?= e($label) ?></span><div class="bar"><i style="width:<?= round($n / $max * 100) ?>%"></i></div><span><?= $n ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <p class="note">Statistiky jsou anonymní: každé otevření stránky dostane náhodné ID, které se nikam neukládá, takže návštěvy nejde propojit mezi sebou, se zařízením ani s hlasy. Ukládá se jen den, počet sekund a počet swipů (bez času, IP adresy či údajů o prohlížeči).</p>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>

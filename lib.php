<?php
/**
 * Shared code for api.php and admin/: settings, SQLite storage, daily set, rankings, visit stats.
 *
 * The database lives in data/ (blocked from the web by .htaccess, data/.htaccess and router.php).
 * Override its location with the ART_DB_PATH environment variable, ideally outside the web root.
 */

declare(strict_types=1);

const DAILY_COUNT = 10;
const MIN_REPEAT_DAYS = 30;        // a work is not shown again until at least this many days have passed
// Lets swipe.html?mode=restart reset TODAY's set on the server. Keep false on the live site:
// use the Restart button in /admin/ instead (password protected).
const ALLOW_RESTART = false;
const TIMEZONE = 'Europe/Prague';   // when "today" starts and ends
const DATA_FILES = [               // fallback artwork lists when there is no pool/
    'artworks.js', 'artworks_cma.js', 'artworks_commons.js',
];
const APP_ROOT = __DIR__;

date_default_timezone_set(TIMEZONE);

/** Errors meant for the client (with an HTTP status), as opposed to unexpected failures. */
final class AppError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 500) { parent::__construct($message); }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = getenv('ART_DB_PATH') ?: APP_ROOT . '/data/art.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new AppError('cannot create data directory');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS daily_set (
            day        TEXT NOT NULL,
            position   INTEGER NOT NULL,
            artwork_id TEXT NOT NULL,
            PRIMARY KEY (day, artwork_id)
        );
        CREATE TABLE IF NOT EXISTS vote (
            day        TEXT NOT NULL,
            artwork_id TEXT NOT NULL,
            device_id  TEXT NOT NULL,
            vote       INTEGER NOT NULL CHECK (vote IN (1, -1)),
            updated_at TEXT NOT NULL,
            PRIMARY KEY (day, artwork_id, device_id),
            FOREIGN KEY (day, artwork_id) REFERENCES daily_set (day, artwork_id)
        );
        CREATE INDEX IF NOT EXISTS vote_day ON vote (day, artwork_id);

        -- Anonymous visit stats: one row per page load. The id is random per page load and never stored
        -- in the browser, so visits cannot be linked to each other, to a device or to votes.
        -- Only the day is kept (no time, IP or browser details).
        CREATE TABLE IF NOT EXISTS visit (
            id      TEXT PRIMARY KEY,
            day     TEXT NOT NULL,
            seconds INTEGER NOT NULL DEFAULT 0,
            swipes  INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS visit_day ON visit (day);

        -- Failed admin logins (time only), for rate limiting.
        CREATE TABLE IF NOT EXISTS login_fail (ts INTEGER NOT NULL);

        -- Every day a work was shown. Kept even when the daily set is restarted, so replaced works count too.
        CREATE TABLE IF NOT EXISTS shown_history (
            artwork_id TEXT NOT NULL,
            day        TEXT NOT NULL,
            PRIMARY KEY (artwork_id, day)
        );
        INSERT OR IGNORE INTO shown_history (artwork_id, day) SELECT artwork_id, day FROM daily_set;

        -- How long pictures are looked at before a swipe: only totals per picture and day (no visit or device).
        CREATE TABLE IF NOT EXISTS artwork_time (
            day        TEXT NOT NULL,
            artwork_id TEXT NOT NULL,
            views      INTEGER NOT NULL DEFAULT 0,
            total_ms   INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, artwork_id)
        );
    ');
    // Migration: traffic source per visit (e.g. "instagram" from ?source=instagram); '' = direct.
    $cols = array_column($pdo->query('PRAGMA table_info(visit)')->fetchAll(), 'name');
    if (!in_array('source', $cols, true)) $pdo->exec("ALTER TABLE visit ADD COLUMN source TEXT NOT NULL DEFAULT ''");
    return $pdo;
}

function today(): string { return date('Y-m-d'); }
function resets_at(): string { return date('c', strtotime('tomorrow')); }

/**
 * The daily pool (pool/pool.json, made by build_pool.py), split into modern (1900+) and older works.
 * Returns null when there is no pool yet.
 */
function pool_groups(): ?array {
    $path = APP_ROOT . '/pool/pool.json';
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['works'] ?? null)) return null;
    $groups = ['modern' => [], 'older' => [], 'share' => (float)($data['modern_share'] ?? 0.7)];
    foreach ($data['works'] as $w) {
        $id = (string)($w['id'] ?? '');
        if ($id === '' || !preg_match('/^[\w-]+$/', $id) || !is_file(APP_ROOT . "/pool/$id.jpg")) continue;
        $groups[!empty($w['modern']) ? 'modern' : 'older'][] = $id;
    }
    return $groups;
}

/** Last day each work was shown: [artwork_id => 'Y-m-d']. */
function last_shown(): array {
    $rows = db()->query('SELECT artwork_id, MAX(day) AS day FROM shown_history GROUP BY artwork_id')->fetchAll();
    return array_column($rows, 'day', 'artwork_id');
}

/**
 * Pick $n ids: works never shown first, then the ones shown longest ago (random among equals).
 * Works shown within MIN_REPEAT_DAYS are only used if there is no other way to fill the set.
 */
function pick(array $ids, int $n, array $last, string $day): array {
    $cutoff = date('Y-m-d', strtotime("$day -" . MIN_REPEAT_DAYS . ' days'));
    shuffle($ids);  // random order among works with the same last-shown day
    usort($ids, fn($a, $b) => strcmp($last[$a] ?? '', $last[$b] ?? ''));  // '' (never shown) sorts first
    $ok = array_values(array_filter($ids, fn($id) => !isset($last[$id]) || $last[$id] <= $cutoff));
    if (count($ok) < $n) {
        error_log(sprintf('daily pick: only %d works not shown in the last %d days; some repeat early', count($ok), MIN_REPEAT_DAYS));
        $ok = $ids;  // still least-recently-shown first
    }
    return array_slice($ok, 0, $n);
}

/** Today's 10: from the pool with its modern/older share (7 + 3), or from all downloaded images. */
function choose_daily(string $day): array {
    $last = last_shown();
    $groups = pool_groups();
    if ($groups && count($groups['modern']) + count($groups['older']) >= DAILY_COUNT) {
        $nModern = min(count($groups['modern']), (int)round(DAILY_COUNT * $groups['share']));
        $ids = array_merge(pick($groups['modern'], $nModern, $last, $day),
                           pick($groups['older'], DAILY_COUNT - $nModern, $last, $day));
        if (count($ids) < DAILY_COUNT) {  // older side too small: top up with modern
            $rest = array_values(array_diff($groups['modern'], $ids));
            $ids = array_merge($ids, pick($rest, DAILY_COUNT - count($ids), $last, $day));
        }
        shuffle($ids);
        return $ids;
    }
    return pick(available_ids(), DAILY_COUNT, $last, $day);
}

/** For the admin page: pool size, works not shown yet, and roughly how many days until repeats start. */
function pool_status(): array {
    $groups = pool_groups() ?? ['modern' => [], 'older' => [], 'share' => 0.7];
    $last = last_shown();
    $nModern = (int)round(DAILY_COUNT * $groups['share']);
    $count = fn($ids) => count(array_filter($ids, fn($id) => !isset($last[$id])));
    $cycle = fn($ids, $perDay) => $perDay > 0 ? intdiv(count($ids), $perDay) : 0;
    return [
        'total' => count($groups['modern']) + count($groups['older']),
        'unshown' => $count($groups['modern']) + $count($groups['older']),
        'cycle_days' => min($cycle($groups['modern'], $nModern) ?: PHP_INT_MAX, $cycle($groups['older'], DAILY_COUNT - $nModern) ?: PHP_INT_MAX),
    ];
}

/** Fallback when there is no pool: all artwork ids from the data files that have a downloaded image. */
function available_ids(): array {
    $ids = [];
    foreach (DATA_FILES as $file) {
        $path = APP_ROOT . '/' . $file;
        if (!is_file($path)) continue;
        $text = file_get_contents($path);
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false) continue;
        $list = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($list)) continue;
        foreach ($list as $a) {
            $id = (string)($a['id'] ?? '');
            if ($id !== '' && preg_match('/^[\w-]+$/', $id) && is_file(APP_ROOT . "/images/$id.jpg")) {
                $ids[] = $id;
            }
        }
    }
    return array_values(array_unique($ids));
}

/** Today's set; created on first request of the day. Works shown in the last MIN_REPEAT_DAYS are skipped. */
function daily_ids(string $day): array {
    $pdo = db();
    $get = $pdo->prepare('SELECT artwork_id FROM daily_set WHERE day = ? ORDER BY position');
    $get->execute([$day]);
    $ids = $get->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) return $ids;

    $pdo->exec('BEGIN IMMEDIATE');  // only one request creates the set
    try {
        $get->execute([$day]);
        $ids = $get->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            $ids = choose_daily($day);
            if (count($ids) < DAILY_COUNT) throw new AppError('not enough artworks with images on the server', 503);
            $ins = $pdo->prepare('INSERT INTO daily_set (day, position, artwork_id) VALUES (?, ?, ?)');
            $hist = $pdo->prepare('INSERT OR IGNORE INTO shown_history (artwork_id, day) VALUES (?, ?)');
            foreach ($ids as $i => $id) { $ins->execute([$day, $i, $id]); $hist->execute([$id, $day]); }
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $ids;
}

/** Replace today's set with a fresh pick and delete today's votes. The replaced works stay in shown_history,
 *  so they won't come back for MIN_REPEAT_DAYS either. Returns the new ids. */
function restart_today(): array {
    $pdo = db();
    $day = today();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->prepare('DELETE FROM vote WHERE day = ?')->execute([$day]);
        $pdo->prepare('DELETE FROM daily_set WHERE day = ?')->execute([$day]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
    return daily_ids($day);
}

function ranking(string $day): array {
    $q = db()->prepare('
        SELECT s.artwork_id AS id,
               COALESCE(SUM(v.vote = 1), 0)  AS likes,
               COALESCE(SUM(v.vote = -1), 0) AS dislikes
        FROM daily_set s
        LEFT JOIN vote v ON v.day = s.day AND v.artwork_id = s.artwork_id
        WHERE s.day = ?
        GROUP BY s.artwork_id, s.position
        ORDER BY likes DESC, dislikes ASC, s.position ASC');
    $q->execute([$day]);
    return array_map(fn($r) => ['id' => $r['id'], 'likes' => (int)$r['likes'], 'dislikes' => (int)$r['dislikes']],
        $q->fetchAll());
}

/**
 * Record (or update) one anonymous visit. Values only grow, so a late or repeated report can't shrink them.
 * Limits keep a single bogus report from skewing the stats.
 */
function record_visit(string $visitId, int $seconds, int $swipes, string $source = ''): void {
    $seconds = max(0, min($seconds, 4 * 3600));
    $swipes = max(0, min($swipes, 200));
    db()->prepare('
        INSERT INTO visit (id, day, seconds, swipes, source) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (id) DO UPDATE SET seconds = MAX(visit.seconds, excluded.seconds),
                                       swipes  = MAX(visit.swipes, excluded.swipes)')
        ->execute([$visitId, today(), $seconds, $swipes, clean_source($source)]);
}

/** Campaign/source label: lowercase a–z, 0–9, "-" and "_", max 32 chars. Anything else becomes ''. */
function clean_source(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9_-]+/', '-', $s);
    $s = trim((string)$s, '-');
    return preg_match('/^[a-z0-9_-]{1,32}$/', $s) ? $s : '';
}

const VIEW_MAX_MS = 5 * 60 * 1000;   // longer looks (phone left on the table) count as 5 min

/** Add one viewing time for a picture in today's set. Aggregate only. */
function record_view(string $artworkId, int $ms): void {
    if (!in_array($artworkId, daily_ids(today()), true)) throw new AppError("not in today's set", 409);
    if ($ms < 200) return;  // accidental double taps
    $ms = min($ms, VIEW_MAX_MS);
    db()->prepare('
        INSERT INTO artwork_time (day, artwork_id, views, total_ms) VALUES (?, ?, 1, ?)
        ON CONFLICT (day, artwork_id) DO UPDATE SET views = views + 1, total_ms = total_ms + excluded.total_ms')
        ->execute([today(), $artworkId, $ms]);
}

/** Average viewing time per picture for a day: [artwork_id => ['avg_s' => float, 'views' => int]]. */
function view_times(string $day): array {
    $q = db()->prepare('SELECT artwork_id, views, total_ms FROM artwork_time WHERE day = ? AND views > 0');
    $q->execute([$day]);
    $out = [];
    foreach ($q->fetchAll() as $r) $out[$r['artwork_id']] = ['avg_s' => $r['total_ms'] / $r['views'] / 1000, 'views' => (int)$r['views']];
    return $out;
}

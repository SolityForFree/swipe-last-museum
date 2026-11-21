<?php
/**
 * Public API for swipe.html. Logic and storage live in lib.php.
 *
 *   GET  api.php?action=daily               -> today's 10 artwork ids (same for everyone) + next reset (midnight)
 *   POST api.php?action=vote   JSON body     {device, id, vote: "like"|"dislike"}
 *   GET  api.php?action=winners[&days=14]   -> today's ranking + past daily winners (aggregates only)
 *   POST api.php?action=stat   JSON body     {visit, seconds, swipes, source}  anonymous visit stats (see lib.php)
 *   POST api.php?action=view   JSON body     {id, ms}  time a picture was looked at before the swipe (totals only)
 *   POST api.php?action=restart              -> only when ALLOW_RESTART is true (testing); else use /admin/
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(int $max = 2048): array {
    $in = json_decode(file_get_contents('php://input', false, null, 0, $max) ?: '', true);
    return is_array($in) ? $in : [];
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $today = today();

    if ($action === 'daily' && $method === 'GET') {
        respond(200, ['day' => $today, 'ids' => daily_ids($today), 'resets_at' => resets_at()]);
    }

    if ($action === 'vote' && $method === 'POST') {
        $in = json_body();
        $device = (string)($in['device'] ?? '');
        $id = (string)($in['id'] ?? '');
        $vote = $in['vote'] ?? '';
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $device)) respond(400, ['error' => 'bad device id']);
        if (!in_array($vote, ['like', 'dislike'], true)) respond(400, ['error' => 'bad vote']);
        if (!in_array($id, daily_ids($today), true)) respond(409, ['error' => 'not in today\'s set']);

        db()->prepare('
            INSERT INTO vote (day, artwork_id, device_id, vote, updated_at) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (day, artwork_id, device_id) DO UPDATE SET vote = excluded.vote, updated_at = excluded.updated_at')
            ->execute([$today, $id, $device, $vote === 'like' ? 1 : -1, date('c')]);
        respond(200, ['ok' => true]);
    }

    if ($action === 'stat' && $method === 'POST') {
        // Sent with navigator.sendBeacon (text/plain) or fetch; body is JSON either way.
        $in = json_body(512);
        $visit = (string)($in['visit'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,40}$/', $visit)) respond(400, ['error' => 'bad visit id']);
        record_visit($visit, (int)($in['seconds'] ?? 0), (int)($in['swipes'] ?? 0), (string)($in['source'] ?? ''));
        respond(200, ['ok' => true]);
    }

    if ($action === 'view' && $method === 'POST') {
        // Time a picture was on screen before the swipe (ms). Stored only as a per-picture total.
        $in = json_body(256);
        $id = (string)($in['id'] ?? '');
        if (!preg_match('/^[\w-]{1,64}$/', $id)) respond(400, ['error' => 'bad id']);
        record_view($id, (int)($in['ms'] ?? 0));
        respond(200, ['ok' => true]);
    }

    if ($action === 'restart' && $method === 'POST') {
        if (!ALLOW_RESTART) respond(403, ['error' => 'restart is disabled; use /admin/']);
        respond(200, ['day' => $today, 'ids' => restart_today(), 'resets_at' => resets_at()]);
    }

    if ($action === 'winners' && $method === 'GET') {
        $days = max(1, min(60, (int)($_GET['days'] ?? 14)));
        daily_ids($today);  // make sure today exists
        $q = db()->prepare('SELECT DISTINCT day FROM daily_set WHERE day < ? ORDER BY day DESC LIMIT ?');
        $q->execute([$today, $days]);
        $past = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $day) {
            $top = ranking($day)[0] ?? null;
            if ($top && $top['likes'] > 0) $past[] = ['day' => $day] + $top;
        }
        $voters = db()->prepare('SELECT COUNT(DISTINCT device_id) FROM vote WHERE day = ?');
        $voters->execute([$today]);
        respond(200, [
            'day' => $today,
            'resets_at' => resets_at(),
            'voters' => (int)$voters->fetchColumn(),
            'today' => ranking($today),
            'past' => $past,
        ]);
    }

    respond(404, ['error' => 'unknown action']);
} catch (AppError $e) {
    respond($e->status, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api.php: ' . $e->getMessage());
    respond(500, ['error' => 'server error']);
}

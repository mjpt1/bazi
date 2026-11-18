<?php
// Orbit Drop online API: usernames, per-device login, scores, leaderboard.
// Every request: index.php?a=<action>  (GET for check/board, POST JSON for the rest)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') exit;

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $cfg = require __DIR__ . '/config.php';
    $db = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA busy_timeout = 4000');   // several players saving at once
        $db->exec('PRAGMA journal_mode = WAL');
    }
} catch (Throwable $e) {
    out(['error' => 'server'], 500);
}

function body(): array {
    $j = json_decode((string)file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

// Arabic ي/ك typed on some keyboards count as the same name as Persian ی/ک
function clean_name(string $u): string {
    return trim(str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $u));
}
function name_key(string $u): string {
    return mb_strtolower(str_replace("\u{200C}", '', clean_name($u)), 'UTF-8');
}
function valid_name(string $u): bool {
    return (bool)preg_match('/^[\p{Arabic}a-zA-Z0-9_\x{200C}]{3,16}$/u', $u)
        && !preg_match('/^[\d_]+$/u', $u);
}
function valid_device($d): bool {
    return is_string($d) && (bool)preg_match('/^[A-Za-z0-9\-]{8,64}$/', $d);
}
function pub(array $p): array {
    return ['username' => $p['username'], 'avatar' => (int)$p['avatar_seed'],
            'best' => (int)$p['best'], 'games' => (int)$p['games']];
}
function rank_of(PDO $db, array $p): int {
    // players with a higher best, or same best but who got there earlier
    $s = $db->prepare('SELECT COUNT(*) FROM players WHERE best > ? OR (best = ? AND best_at < ?)');
    $s->execute([(int)$p['best'], (int)$p['best'], (int)$p['best_at']]);
    return (int)$s->fetchColumn() + 1;
}
function by_token(PDO $db, $t): ?array {
    if (!is_string($t) || !preg_match('/^[0-9a-f]{64}$/', $t)) return null;
    $s = $db->prepare('SELECT * FROM players WHERE token = ?');
    $s->execute([$t]);
    return $s->fetch() ?: null;
}
function name_taken(PDO $db, string $key): bool {
    $s = $db->prepare('SELECT 1 FROM players WHERE name_key = ?');
    $s->execute([$key]);
    return (bool)$s->fetchColumn();
}

$a = $_GET['a'] ?? '';

switch ($a) {

case 'check': {   // is this username free?
    $u = clean_name((string)($_GET['username'] ?? ''));
    if (!valid_name($u)) out(['ok' => true, 'valid' => false, 'available' => false]);
    out(['ok' => true, 'valid' => true, 'available' => !name_taken($db, name_key($u))]);
}

case 'login': {   // auto-login by device; returns null player if this device never registered
    $b = body();
    if (!valid_device($b['device_id'] ?? null)) out(['error' => 'device'], 400);
    $s = $db->prepare('SELECT * FROM players WHERE device_id = ?');
    $s->execute([$b['device_id']]);
    $p = $s->fetch();
    if (!$p) out(['ok' => true, 'player' => null]);
    out(['ok' => true, 'token' => $p['token'], 'player' => pub($p) + ['rank' => rank_of($db, $p)]]);
}

case 'register': {
    $b = body();
    if (!valid_device($b['device_id'] ?? null)) out(['error' => 'device'], 400);
    $u = clean_name((string)($b['username'] ?? ''));
    if (!valid_name($u)) out(['error' => 'invalid'], 400);

    $s = $db->prepare('SELECT * FROM players WHERE device_id = ?');
    $s->execute([$b['device_id']]);
    if ($p = $s->fetch()) out(['ok' => true, 'token' => $p['token'], 'player' => pub($p) + ['rank' => rank_of($db, $p)]]);

    $key = name_key($u);
    if (name_taken($db, $key)) out(['error' => 'taken'], 409);

    $token = bin2hex(random_bytes(32));
    $seed = random_int(1, 2147483646);
    $now = time();
    try {
        $db->prepare('INSERT INTO players (device_id, username, name_key, token, avatar_seed, best, best_at, games, created_at)
                      VALUES (?, ?, ?, ?, ?, 0, ?, 0, ?)')
           ->execute([$b['device_id'], $u, $key, $token, $seed, $now, $now]);
    } catch (PDOException $e) {
        out(['error' => 'taken'], 409);   // lost a race on the unique name
    }
    $p = ['username' => $u, 'avatar_seed' => $seed, 'best' => 0, 'games' => 0, 'best_at' => $now];
    out(['ok' => true, 'token' => $token, 'player' => pub($p) + ['rank' => rank_of($db, $p)]]);
}

case 'score': {
    $b = body();
    $p = by_token($db, $b['token'] ?? null);
    if (!$p) out(['error' => 'auth'], 401);
    $score = (int)($b['score'] ?? -1);
    $moons = (int)($b['moons'] ?? -1);
    $dur = (int)($b['dur_ms'] ?? -1);
    // sanity limits: score = moons + near-miss combos, each moon takes at least ~0.1s
    $maxScore = $moons + intdiv($moons * ($moons + 1), 2);
    if ($score < 0 || $moons < 0 || $moons > 5000 || $score > $maxScore || $dur < $moons * 100 || $dur > 86400000)
        out(['error' => 'invalid'], 400);

    $s = $db->prepare('SELECT MAX(created_at) FROM scores WHERE player_id = ?');
    $s->execute([$p['id']]);
    $now = time();
    if ((int)$s->fetchColumn() >= $now - 1) out(['error' => 'slow_down'], 429);

    $db->prepare('INSERT INTO scores (player_id, score, moons, dur_ms, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$p['id'], $score, $moons, $dur, $now]);
    $newBest = $score > (int)$p['best'];
    if ($newBest) {
        $db->prepare('UPDATE players SET best = ?, best_at = ?, games = games + 1 WHERE id = ?')->execute([$score, $now, $p['id']]);
        $p['best'] = $score; $p['best_at'] = $now;
    } else {
        $db->prepare('UPDATE players SET games = games + 1 WHERE id = ?')->execute([$p['id']]);
    }
    $p['games'] = (int)$p['games'] + 1;
    out(['ok' => true, 'new_best' => $newBest, 'player' => pub($p) + ['rank' => rank_of($db, $p)]]);
}

case 'board': {   // top players + (with token) my rank and recent scores
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $rows = $db->query("SELECT username, avatar_seed, best, games, best_at FROM players WHERE best > 0
                        ORDER BY best DESC, best_at ASC LIMIT $limit")->fetchAll();
    $top = [];
    foreach ($rows as $i => $r) $top[] = ['rank' => $i + 1] + pub($r);
    $res = ['ok' => true, 'top' => $top];
    if ($p = by_token($db, $_GET['token'] ?? null)) {
        $s = $db->prepare('SELECT score, moons, created_at FROM scores WHERE player_id = ? ORDER BY id DESC LIMIT 10');
        $s->execute([$p['id']]);
        $res['me'] = pub($p) + ['rank' => rank_of($db, $p), 'recent' => array_map(
            fn($r) => ['score' => (int)$r['score'], 'moons' => (int)$r['moons'], 'at' => (int)$r['created_at']], $s->fetchAll())];
    }
    out($res);
}

default:
    out(['error' => 'unknown_action'], 404);
}

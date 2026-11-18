<?php
// One-time setup: creates the tables. Open it once in the browser, then DELETE this file.
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$db = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['pass'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

$id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';

$db->exec("CREATE TABLE IF NOT EXISTS players (
    id $id,
    device_id VARCHAR(64) NOT NULL UNIQUE,
    username VARCHAR(64) NOT NULL,
    name_key VARCHAR(64) NOT NULL UNIQUE,
    token CHAR(64) NOT NULL UNIQUE,
    avatar_seed INT NOT NULL,
    best INT NOT NULL DEFAULT 0,
    best_at INT NOT NULL DEFAULT 0,
    games INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL
)$tail");
$db->exec("CREATE TABLE IF NOT EXISTS scores (
    id $id,
    player_id INT NOT NULL,
    score INT NOT NULL,
    moons INT NOT NULL,
    dur_ms INT NOT NULL,
    created_at INT NOT NULL
)$tail");

foreach (['CREATE INDEX idx_players_best ON players (best, best_at)',
          'CREATE INDEX idx_scores_player ON scores (player_id, id)'] as $sql) {
    try { $db->exec($sql); } catch (PDOException $e) { /* already exists */ }
}
echo "OK: tables are ready. Now delete install.php from the server.\n";

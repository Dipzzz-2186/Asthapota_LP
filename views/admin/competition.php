<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

const PADEL_ALLOWED_TOTAL_POINTS = [16, 21, 24, 32];

function normalize_competition_type(string $type): string {
    $lower = strtolower(trim($type));
    if (strpos($lower, 'americano') !== false) {
        return 'Americano';
    }
    if (strpos($lower, 'mexicano') !== false) {
        return 'Mexicano';
    }
    return '';
}

function parse_score_request(array $source): array {
    $selectedTotalRaw = trim((string)($source['score_total'] ?? ''));
    $scoreARaw = trim((string)($source['score_a'] ?? ''));
    $scoreBRaw = trim((string)($source['score_b'] ?? ''));

    $selectedTotal = $selectedTotalRaw === '' ? null : (int)$selectedTotalRaw;
    $scoreA = $scoreARaw === '' ? null : (int)$scoreARaw;
    $scoreB = $scoreBRaw === '' ? null : (int)$scoreBRaw;

    if ($scoreA === null && $scoreB === null && $selectedTotal === null) {
        return [null, null, null, ''];
    }
    if (($scoreA !== null || $scoreB !== null) && $selectedTotal === null) {
        return [null, null, null, 'Pilih total poin dulu.'];
    }
    if ($selectedTotal !== null && !in_array($selectedTotal, PADEL_ALLOWED_TOTAL_POINTS, true)) {
        return [null, null, null, 'Total poin tidak valid.'];
    }
    return [$scoreA, $scoreB, $selectedTotal, ''];
}

function validate_padel_score(?int $scoreA, ?int $scoreB, ?int $selectedTotal): string {
    if ($scoreA === null && $scoreB === null) {
        return '';
    }
    if ($scoreA === null || $scoreB === null) {
        return 'Skor harus diisi lengkap untuk kedua sisi.';
    }
    if ($scoreA < 0 || $scoreB < 0) {
        return 'Skor tidak boleh negatif.';
    }
    if ($selectedTotal === null || !in_array($selectedTotal, PADEL_ALLOWED_TOTAL_POINTS, true)) {
        return 'Pilih total poin match: ' . implode(', ', PADEL_ALLOWED_TOTAL_POINTS) . '.';
    }
    if (($scoreA + $scoreB) !== $selectedTotal) {
        return 'Total skor harus sama dengan total poin yang dipilih (' . $selectedTotal . ').';
    }
    return '';
}

function is_ajax_request(): bool {
    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return strpos($accept, 'application/json') !== false;
}

function respond_json(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fetch_competition_attendees(PDO $db): array {
    try {
        $rows = $db->query(
            "SELECT DISTINCT TRIM(oa.attendee_name) AS attendee_name
             FROM order_attendees oa
             JOIN orders o ON o.id = oa.order_id
             WHERE LOWER(TRIM(o.status)) = 'accepted'
               AND TRIM(COALESCE(oa.attendee_name, '')) <> ''
             ORDER BY attendee_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rows = [];
    }
    $names = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['attendee_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

function build_ranking_points(PDO $db, string $competitionType): array {
    $points = [];
    try {
        $stmt = $db->prepare(
            "SELECT player_a_name, player_b_name, score_a, score_b
             FROM competition_games
             WHERE competition_type = ?"
        );
        $stmt->execute([$competitionType]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $a = trim((string)($row['player_a_name'] ?? ''));
            $b = trim((string)($row['player_b_name'] ?? ''));
            $sa = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
            $sb = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
            if ($a === '' || $b === '' || $sa === null || $sb === null) {
                continue;
            }
            if (!in_array($sa + $sb, PADEL_ALLOWED_TOTAL_POINTS, true)) {
                continue;
            }
            $points[$a] = (int)($points[$a] ?? 0) + $sa;
            $points[$b] = (int)($points[$b] ?? 0) + $sb;
        }
    } catch (Throwable $e) {
        $points = [];
    }
    return $points;
}

function build_round_robin(array $players): array {
    $players = array_values($players);
    if (count($players) < 2) {
        return [];
    }
    if (count($players) % 2 !== 0) {
        $players[] = 'BYE';
    }
    $n = count($players);
    $half = (int)($n / 2);
    $list = $players;
    $rounds = [];
    for ($round = 0; $round < $n - 1; $round++) {
        $matches = [];
        for ($i = 0; $i < $half; $i++) {
            $a = (string)$list[$i];
            $b = (string)$list[$n - 1 - $i];
            if ($a !== 'BYE' && $b !== 'BYE') {
                $matches[] = [$a, $b];
            }
        }
        $rounds[] = $matches;
        $fixed = $list[0];
        $tail = array_slice($list, 1);
        array_unshift($tail, array_pop($tail));
        $list = array_merge([$fixed], $tail);
    }
    return $rounds;
}

function build_teams_from_players(array $players): array {
    $players = array_values(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $players), static function (string $name): bool {
        return $name !== '';
    }));

    $teams = [];
    for ($i = 0; $i < count($players); $i += 2) {
        $left = (string)($players[$i] ?? '');
        $right = (string)($players[$i + 1] ?? '');
        if ($left === '' && $right === '') {
            continue;
        }
        if ($left !== '' && $right !== '') {
            $teams[] = $left . ' & ' . $right;
        } else {
            // Keep incomplete team visible instead of dropping the remaining attendee.
            $teams[] = $left !== '' ? $left : $right;
        }
    }
    return $teams;
}

function split_team_members(string $teamLabel): array {
    $parts = preg_split('/\s*&\s*/', trim($teamLabel)) ?: [];
    $members = [];
    foreach ($parts as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $members[] = $name;
        }
    }
    return $members;
}

function build_pairs_from_teams(array $teams): array {
    $teams = array_values(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $teams), static function (string $name): bool {
        return $name !== '';
    }));

    $pairs = [];
    for ($i = 0; $i < count($teams); $i += 2) {
        $left = (string)($teams[$i] ?? '');
        $right = (string)($teams[$i + 1] ?? '');
        if ($left === '' && $right === '') {
            continue;
        }
        if ($right === '') {
            $right = 'BYE';
        }
        $pairs[] = [$left !== '' ? $left : 'BYE', $right];
    }
    return $pairs;
}

function has_valid_game_score(array $row): bool {
    $scoreA = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
    $scoreB = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
    if ($scoreA === null || $scoreB === null) {
        return false;
    }
    if ($scoreA < 0 || $scoreB < 0) {
        return false;
    }
    return in_array($scoreA + $scoreB, PADEL_ALLOWED_TOTAL_POINTS, true);
}

function is_round_completed(array $rows): bool {
    if (!$rows) {
        return false;
    }
    foreach ($rows as $row) {
        if (!has_valid_game_score($row)) {
            return false;
        }
    }
    return true;
}

function sync_next_round_from_round(PDO $db, string $type, int $sourceRound, int $adminId): array {
    if ($sourceRound <= 0) {
        return [0, 0];
    }

    $fetchSource = $db->prepare(
        "SELECT id, match_no, player_a_name, player_b_name, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY match_no ASC, id ASC"
    );
    $fetchSource->execute([$type, $sourceRound]);
    $rows = $fetchSource->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [0, 0];
    }

    $sourceRoundCompleted = is_round_completed($rows);
    if (!$sourceRoundCompleted) {
        // Both formats now wait for full completion before creating the next round.
        return [0, 0];
    }

    $allTeams = [];
    $completedTeams = [];
    foreach ($rows as $row) {
        $a = trim((string)($row['player_a_name'] ?? ''));
        $b = trim((string)($row['player_b_name'] ?? ''));
        if ($a !== '') {
            $allTeams[] = $a;
        }
        if ($b !== '') {
            $allTeams[] = $b;
        }
        if (has_valid_game_score($row)) {
            if ($a !== '') {
                $completedTeams[] = $a;
            }
            if ($b !== '') {
                $completedTeams[] = $b;
            }
        }
    }
    $allTeams = array_values(array_unique($allTeams));
    $completedTeams = array_values(array_unique($completedTeams));
    if (count($completedTeams) < 2) {
        return [0, 0];
    }

    if ($type === 'Americano') {
        $members = [];
        foreach ($completedTeams as $teamName) {
            foreach (split_team_members($teamName) as $member) {
                $members[] = $member;
            }
        }
        $members = array_values(array_unique($members));
        shuffle($members);
        $nextTeams = build_teams_from_players($members);
        shuffle($nextTeams);
    } else {
        $ranking = build_ranking_points($db, 'Mexicano');
        // Mexicano team composition stays fixed; only opponent pairing follows ranking.
        $nextTeams = $allTeams;
        usort($nextTeams, static function (string $x, string $y) use ($ranking): int {
            $px = (int)($ranking[$x] ?? 0);
            $py = (int)($ranking[$y] ?? 0);
            if ($px !== $py) {
                return $py <=> $px;
            }
            return strcmp($x, $y);
        });
    }

    if ($type === 'Americano') {
        // For Americano, randomize slot positions as well so teams can land on different boxes.
        shuffle($nextTeams);
    }
    $pairs = build_pairs_from_teams($nextTeams);
    if (!$pairs) {
        return [0, 0];
    }

    $nextRound = $sourceRound + 1;
    $fetchExisting = $db->prepare(
        "SELECT id, match_no, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY match_no ASC, id ASC"
    );
    $fetchExisting->execute([$type, $nextRound]);
    $existingRows = $fetchExisting->fetchAll(PDO::FETCH_ASSOC);

    $insert = $db->prepare(
        "INSERT INTO competition_games (
            package_id, competition_type, game_title, round_no, match_no,
            player_a_user_id, player_a_name, player_b_user_id, player_b_name,
            score_a, score_b, game_date, notes, created_by_admin_id, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $update = $db->prepare(
        "UPDATE competition_games
         SET game_title = ?, player_a_name = ?, player_b_name = ?
         WHERE id = ?"
    );
    $deleteRow = $db->prepare(
        "DELETE FROM competition_games
         WHERE id = ? AND score_a IS NULL AND score_b IS NULL"
    );

    $created = 0;
    $updated = 0;
    foreach ($pairs as $idx => $pair) {
        $matchNo = $idx + 1;
        $title = $type . ' R' . $nextRound . ' M' . $matchNo;
        $a = (string)$pair[0];
        $b = (string)$pair[1];

        if (isset($existingRows[$idx])) {
            $row = $existingRows[$idx];
            $hasScore = ($row['score_a'] !== null || $row['score_b'] !== null);
            if (!$hasScore) {
                $update->execute([$title, $a, $b, (int)$row['id']]);
                $updated++;
            }
            continue;
        }

        $insert->execute([
            null,
            $type,
            $title,
            $nextRound,
            $matchNo,
            null,
            $a,
            null,
            $b,
            null,
            null,
            null,
            null,
            $adminId > 0 ? $adminId : null,
            date('Y-m-d H:i:s'),
        ]);
        $created++;
    }

    // Remove leftover empty rows when the new pairing list is shorter.
    for ($i = count($pairs); $i < count($existingRows); $i++) {
        $deleteRow->execute([(int)$existingRows[$i]['id']]);
    }

    return [$created, $updated];
}


$db = get_db();
ensure_session();
$flash = ['success' => '', 'error' => ''];
if (isset($_SESSION['competition_flash']) && is_array($_SESSION['competition_flash'])) {
    $flash['success'] = (string)($_SESSION['competition_flash']['success'] ?? '');
    $flash['error'] = (string)($_SESSION['competition_flash']['error'] ?? '');
    unset($_SESSION['competition_flash']);
}

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS competition_games (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NULL,
            competition_type VARCHAR(20) NULL,
            game_title VARCHAR(160) NOT NULL,
            round_no INT NULL,
            match_no INT NULL,
            player_a_user_id INT NULL,
            player_a_name VARCHAR(150) NULL,
            player_b_user_id INT NULL,
            player_b_name VARCHAR(150) NULL,
            score_a INT NULL,
            score_b INT NULL,
            game_date DATE NULL,
            notes TEXT NULL,
            created_by_admin_id INT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_competition_game_date (game_date),
            INDEX idx_competition_type_round (competition_type, round_no, match_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    $flash['error'] = 'Gagal menyiapkan tabel competition.';
}

try {
    $schema = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($schema !== '') {
        $ensureColumn = static function (PDO $db, string $schema, string $column, string $definition): void {
            $check = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'competition_games' AND COLUMN_NAME = ?"
            );
            $check->execute([$schema, $column]);
            if ((int)$check->fetchColumn() === 0) {
                $db->exec("ALTER TABLE competition_games ADD COLUMN {$column} {$definition}");
            }
        };
        $ensureColumn($db, $schema, 'competition_type', "VARCHAR(20) NULL AFTER package_id");
        $ensureColumn($db, $schema, 'round_no', "INT NULL AFTER game_title");
        $ensureColumn($db, $schema, 'match_no', "INT NULL AFTER round_no");
        $ensureColumn($db, $schema, 'player_a_name', "VARCHAR(150) NULL AFTER player_a_user_id");
        $ensureColumn($db, $schema, 'player_b_name', "VARCHAR(150) NULL AFTER player_b_user_id");
        $ensureColumn($db, $schema, 'score_a', "INT NULL AFTER player_b_name");
        $ensureColumn($db, $schema, 'score_b', "INT NULL AFTER score_a");
    }
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['competition_action'] ?? 'create_match'));
    $allowedTypes = ['Americano', 'Mexicano'];
    $ajaxRequest = is_ajax_request();

    if ($action === 'update_score') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        [$scoreA, $scoreB, $selectedTotal, $parseError] = parse_score_request($_POST);
        $roundSynced = false;
        if ($gameId <= 0) {
            $flash['error'] = 'ID game tidak valid.';
        } elseif ($parseError !== '') {
            $flash['error'] = $parseError;
        } else {
            $scoreError = validate_padel_score($scoreA, $scoreB, $selectedTotal);
            if ($scoreError !== '') {
                $flash['error'] = $scoreError;
            }
        }
        if ($flash['error'] === '') {
            try {
                $db->prepare('UPDATE competition_games SET score_a = ?, score_b = ? WHERE id = ?')
                    ->execute([$scoreA, $scoreB, $gameId]);
                $flash['success'] = 'Skor game berhasil diperbarui.';

                $typeStmt = $db->prepare('SELECT competition_type, round_no FROM competition_games WHERE id = ? LIMIT 1');
                $typeStmt->execute([$gameId]);
                $savedGame = $typeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $savedType = normalize_competition_type((string)($savedGame['competition_type'] ?? ''));
                $savedRound = (int)($savedGame['round_no'] ?? 0);
                if ($savedType !== '') {
                    [$nextCreated, $nextUpdated] = sync_next_round_from_round($db, $savedType, $savedRound, (int)($_SESSION['admin_id'] ?? 0));
                    if ($nextCreated > 0 || $nextUpdated > 0) {
                        $roundSynced = true;
                        $flash['success'] .= ' Round berikutnya disinkronkan otomatis.';
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal memperbarui skor game.';
            }
        }
        if ($ajaxRequest) {
            respond_json([
                'ok' => $flash['error'] === '',
                'message' => $flash['error'] !== '' ? $flash['error'] : $flash['success'],
                'round_synced' => $roundSynced,
            ]);
        }
    } elseif ($action === 'delete_game') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        if ($gameId <= 0) {
            $flash['error'] = 'ID game tidak valid.';
        } else {
            try {
                $stmt = $db->prepare('DELETE FROM competition_games WHERE id = ? LIMIT 1');
                $stmt->execute([$gameId]);
                $flash[$stmt->rowCount() > 0 ? 'success' : 'error'] = $stmt->rowCount() > 0 ? 'Game berhasil dihapus.' : 'Game tidak ditemukan.';
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menghapus game.';
            }
        }
    } else {
        $type = trim((string)($_POST['competition_type'] ?? ''));
        $titlePrefix = trim((string)($_POST['game_title'] ?? ''));
        $gameDateRaw = trim((string)($_POST['game_date'] ?? ''));
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        if (!in_array($type, $allowedTypes, true)) {
            $flash['error'] = 'Pilih tipe game yang valid.';
        } elseif ($titlePrefix !== '' && mb_strlen($titlePrefix) > 160) {
            $flash['error'] = 'Label game terlalu panjang (maksimal 160 karakter).';
        } elseif ($gameDateRaw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDateRaw)) {
            $flash['error'] = 'Format tanggal tidak valid.';
        } else {
            $attendees = fetch_competition_attendees($db);
            if (count($attendees) < 4) {
                $flash['error'] = 'Attendee accepted belum cukup untuk format team (minimal 4 orang).';
            } elseif (count($attendees) % 2 !== 0) {
                $flash['error'] = 'Jumlah attendee harus genap untuk format team 2 orang.';
            } else {
                // Round 1 team composition is created once.
                // Americano will re-randomize team members in next rounds,
                // while Mexicano keeps this team composition fixed.
                shuffle($attendees);

                $teams = build_teams_from_players($attendees);
                if ($type === 'Americano') {
                    shuffle($teams);
                }
                $pairs = build_pairs_from_teams($teams);
                if (!$pairs) {
                    $flash['error'] = 'Gagal membentuk bagan pertandingan.';
                } else {
                    try {
                        $insert = $db->prepare(
                            'INSERT INTO competition_games (
                                package_id, competition_type, game_title, round_no, match_no,
                                player_a_user_id, player_a_name, player_b_user_id, player_b_name,
                                score_a, score_b, game_date, notes, created_by_admin_id, created_at
                             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $created = 0;
                        $roundNo = 1;
                        foreach ($pairs as $mIdx => $pair) {
                            $matchNo = $mIdx + 1;
                            $label = $titlePrefix !== '' ? $titlePrefix : $type;
                            $title = $label . ' - R' . $roundNo . ' M' . $matchNo;
                            $insert->execute([
                                null, $type, $title, $roundNo, $matchNo,
                                null, (string)$pair[0], null, (string)$pair[1],
                                null, null, $gameDateRaw !== '' ? $gameDateRaw : null, null,
                                $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                        $flash[$created > 0 ? 'success' : 'error'] = $created > 0
                            ? ('Berhasil generate Round 1 (' . $created . ' match) untuk ' . $type . '. Round berikutnya otomatis setelah seluruh skor round selesai.')
                            : 'Tidak ada match yang berhasil dibuat.';
                    } catch (Throwable $e) {
                        $flash['error'] = 'Gagal menambahkan jadwal competition.';
                    }
                }
            }
        }
    }

    if ($ajaxRequest) {
        respond_json([
            'ok' => $flash['error'] === '',
            'message' => $flash['error'] !== '' ? $flash['error'] : $flash['success'],
        ]);
    }
    if ($flash['success'] !== '') {
        $flash['error'] = '';
    } elseif ($flash['error'] !== '') {
        $flash['success'] = '';
    }
    $_SESSION['competition_flash'] = $flash;
    redirect('/admin/competition');
}

$games = [];
try {
    $games = $db->query(
        "SELECT id, game_title, round_no, match_no, game_date, created_at, competition_type,
                player_a_name, player_b_name, score_a, score_b
         FROM competition_games
         ORDER BY
            CASE
              WHEN LOWER(TRIM(competition_type)) LIKE '%americano%' THEN 1
              WHEN LOWER(TRIM(competition_type)) LIKE '%mexicano%' THEN 2
              ELSE 3
            END,
            COALESCE(round_no, 999999), COALESCE(match_no, 999999), id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $games = [];
}

$attendeeNames = fetch_competition_attendees($db);
$registeredAttendeeCount = count($attendeeNames);
$gamesByType = ['Americano' => [], 'Mexicano' => []];
foreach ($games as $game) {
    $type = normalize_competition_type((string)($game['competition_type'] ?? ''));
    if ($type !== '') {
        $gamesByType[$type][] = $game;
    }
}

$standingsByType = ['Americano' => [], 'Mexicano' => []];
foreach ($gamesByType as $type => $rows) {
    $table = [];
    foreach ($rows as $row) {
        $a = trim((string)($row['player_a_name'] ?? ''));
        $b = trim((string)($row['player_b_name'] ?? ''));
        $sa = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
        $sb = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
        if ($a === '' || $b === '' || $sa === null || $sb === null || !in_array($sa + $sb, PADEL_ALLOWED_TOTAL_POINTS, true)) {
            continue;
        }
        if (!isset($table[$a])) $table[$a] = ['name' => $a, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'point_total' => 0];
        if (!isset($table[$b])) $table[$b] = ['name' => $b, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'point_total' => 0];
        $table[$a]['match']++; $table[$b]['match']++;
        $table[$a]['pf'] += $sa; $table[$a]['pa'] += $sb; $table[$a]['point_total'] += $sa;
        $table[$b]['pf'] += $sb; $table[$b]['pa'] += $sa; $table[$b]['point_total'] += $sb;
        if ($sa > $sb) { $table[$a]['win']++; $table[$b]['lose']++; }
        elseif ($sa < $sb) { $table[$b]['win']++; $table[$a]['lose']++; }
        else { $table[$a]['draw']++; $table[$b]['draw']++; }
    }
    $rows = array_values($table);
    usort($rows, static function (array $x, array $y): int {
        $dx = (int)$x['pf'] - (int)$x['pa'];
        $dy = (int)$y['pf'] - (int)$y['pa'];
        if ((int)$x['point_total'] !== (int)$y['point_total']) return (int)$y['point_total'] <=> (int)$x['point_total'];
        if ($dx !== $dy) return $dy <=> $dx;
        if ((int)$x['win'] !== (int)$y['win']) return (int)$y['win'] <=> (int)$x['win'];
        return strcmp((string)$x['name'], (string)$y['name']);
    });
    $standingsByType[$type] = $rows;
}

$extraHead = <<<HTML
<style>
  .competition-grid{display:grid;grid-template-columns:minmax(280px,380px) minmax(0,1fr);gap:16px;align-items:start}
  .competition-card{background:rgba(255,255,255,.92);border:1px solid rgba(15,32,60,.12);border-radius:16px;padding:16px;box-shadow:0 8px 26px rgba(15,32,60,.08)}
  .competition-form{display:grid;gap:10px}.competition-form label{font-size:12px;color:#415a80;font-weight:700}
  .competition-form input,.competition-form select{width:100%;border-radius:10px;border:1px solid #c6d4ea;padding:10px 11px;background:#fff}
  .alert{margin-bottom:14px}
  .alert.success{background:#e8f8ee;border:1px solid #b7e6c4;color:#18633a}
  .alert.success i{color:#18633a}
  .alert.error{background:#fdeeee;border:1px solid #f3bcbc;color:#b43636}
  .alert.error i{color:#b43636}
  .note{font-size:12px;color:#385885;background:#edf4ff;border:1px solid #d3e3ff;border-radius:10px;padding:8px 10px;margin:0}
  .type-filter{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 12px}
  .type-filter button{border:1px solid #bfd0ea;background:#fff;color:#163966;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:700;cursor:pointer}
  .type-filter button.active{background:#1a66e9;border-color:#1a66e9;color:#fff}
  .rounds-scroll{overflow:visible;padding-bottom:4px;margin-top:10px}
  .rounds-track{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap}
  .round-column{width:250px;max-width:100%;position:relative;flex:0 1 250px}
  .round-column + .round-column::before{content:"";position:absolute;left:-11px;top:50%;width:11px;border-top:2px solid #2e323b;opacity:.45}
  .round-block{border:1px solid #dce6f8;border-radius:12px;padding:8px;background:#f9fbff}
  .round-title{margin:0 0 8px;font-size:12px;color:#173964;font-weight:800;text-transform:uppercase}
  .match-item + .match-item{margin-top:8px}
  .match-box{border:2px solid #2e323b;border-radius:6px;background:#fff;overflow:hidden}
  .seed-line{padding:7px 8px;font-size:12px;color:#173964;min-height:34px;display:flex;align-items:center}
  .seed-line + .seed-line{border-top:1px solid #d5deed}
  .score-editor{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
  .score-label{font-size:11px;color:#47628b;font-weight:700}
  .score-vs{font-size:11px;color:#365781;font-weight:700;min-width:100%;text-align:center}
  .match-actions{margin-top:6px;display:flex;justify-content:center}
  .score-editor select,.score-editor input{border:1px solid #c6d4ea;border-radius:8px;padding:5px 6px}
  .score-editor input{width:52px;text-align:center}
  .live-status{min-height:16px;font-size:11px;text-align:center;color:#4c6388;flex:0 0 100%;margin-top:2px}
  .live-status.ok{color:#18633a}
  .live-status.error{color:#b43636}
  .standing-wrap{overflow-x:auto;margin-top:12px}
  table.standing-table{width:100%;border-collapse:collapse;min-width:560px}
  .standing-table th,.standing-table td{text-align:left;padding:8px 7px;border-bottom:1px solid #dfe8f8;font-size:12px;color:#183054}
  .standing-table th{font-size:11px;color:#5a6b86;text-transform:uppercase}
  @media (max-width:980px){.competition-grid{grid-template-columns:1fr}.round-column{width:230px}}
</style>
HTML;

render_header([
    'title' => 'Competition Admin - Asthapora',
    'isAdmin' => true,
    'showNav' => false,
    'brandSubtitle' => 'Competition Manager',
    'extraHead' => $extraHead,
]);
?>
<main class="admin-shell">
  <div class="container admin-container-wide">
    <div class="admin-header spaced">
      <div>
        <h1 class="admin-title">Competition</h1>
        <p class="admin-sub">Generate hanya Round 1 dulu. Round berikutnya terisi bertahap saat skor round sebelumnya mulai diinput.</p>
      </div>
      <div class="competition-actions"><a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left"></i> Dashboard</a></div>
    </div>

    <?php if (!empty($flash['success'])): ?><div class="alert success"><i class="bi bi-check-circle"></i> <?= h($flash['success']) ?></div><?php endif; ?>
    <?php if (!empty($flash['error'])): ?><div class="alert error"><i class="bi bi-exclamation-triangle"></i> <?= h($flash['error']) ?></div><?php endif; ?>
    <section class="competition-grid">
      <div class="competition-card">
        <h2><i class="bi bi-plus-circle"></i> Tambah Game</h2>
        <form method="post" class="competition-form">
          <input type="hidden" name="competition_action" value="create_match">
          <div>
            <label for="competitionType">Tipe Game</label>
            <select id="competitionType" name="competition_type" required>
              <option value="">-- pilih --</option>
              <option value="Americano">Americano</option>
              <option value="Mexicano">Mexicano</option>
            </select>
          </div>
          <div>
            <label for="gameTitle">Label Prefix (Opsional)</label>
            <input id="gameTitle" name="game_title" type="text" maxlength="160" placeholder="Contoh: Week 1">
          </div>
          <div>
            <label for="gameDate">Tanggal Game (Opsional)</label>
            <input id="gameDate" name="game_date" type="date">
          </div>
          <p class="admin-sub" style="margin:0;">Total attendee accepted: <strong><?= (int)$registeredAttendeeCount ?></strong>.</p>
          <p class="note">Americano: round berikutnya menunggu round sebelumnya selesai penuh, lalu tim+slot+lawan diacak ulang. Mexicano: tim tetap, round berikutnya menunggu round sebelumnya selesai penuh, lalu lawan disusun dari ranking (1 vs 2, dst).</p>
          <button class="btn primary" type="submit"><i class="bi bi-diagram-3"></i> Generate Semua Match</button>
        </form>
      </div>

      <div class="competition-card" data-live-board>
        <h2><i class="bi bi-grid-3x3-gap"></i> Bagan Match + Skor Tengah</h2>
        <div class="type-filter" data-type-filter>
          <button type="button" class="active" data-type-target="all">Semua</button>
          <button type="button" data-type-target="Americano">Americano</button>
          <button type="button" data-type-target="Mexicano">Mexicano</button>
        </div>

        <?php foreach (['Americano', 'Mexicano'] as $type): ?>
          <div data-type-panel="<?= h($type) ?>">
          <?php
            $typeGames = $gamesByType[$type] ?? [];
            $roundGroups = [];
            foreach ($typeGames as $row) {
                $roundNo = (int)($row['round_no'] ?? 0);
                if ($roundNo <= 0) $roundNo = 999999;
                if (!isset($roundGroups[$roundNo])) $roundGroups[$roundNo] = [];
                $roundGroups[$roundNo][] = $row;
            }
            ksort($roundGroups, SORT_NUMERIC);
            $roundQualifiedTeams = [];
            $roundCompletedMap = [];
            foreach ($roundGroups as $progressRoundNo => $progressRows) {
                $qualifiedCount = 0;
                foreach ($progressRows as $progressRow) {
                    if (has_valid_game_score($progressRow)) {
                        $qualifiedCount += 2;
                    }
                }
                $roundQualifiedTeams[$progressRoundNo] = $qualifiedCount;
                $roundCompletedMap[$progressRoundNo] = is_round_completed($progressRows);
            }
          ?>
          <h3 style="margin:12px 0 4px;"><?= h($type) ?> <small style="font-weight:400;color:#5a6b86;"><?= count($typeGames) ?> match</small></h3>
          <?php if (!$typeGames): ?>
            <p class="admin-sub" style="margin:0;">Belum ada match <?= h($type) ?>.</p>
          <?php else: ?>
            <div class="rounds-scroll">
              <div class="rounds-track">
                <?php foreach ($roundGroups as $roundNo => $matches): ?>
                  <div class="round-column">
                    <div class="round-block">
                      <p class="round-title"><?= h($roundNo === 999999 ? 'Round Tanpa Nomor' : ('Round ' . $roundNo)) ?></p>
                      <?php
                        $allowedTeamsForRound = PHP_INT_MAX;
                        if ($roundNo !== 999999 && $roundNo > 1) {
                            $prevRoundNo = $roundNo - 1;
                            if (!($roundCompletedMap[$prevRoundNo] ?? false)) {
                                $allowedTeamsForRound = 0;
                            }
                        }
                      ?>
                      <?php foreach ($matches as $mIdx => $game): ?>
                        <?php
                          $sa = isset($game['score_a']) && $game['score_a'] !== null ? (int)$game['score_a'] : null;
                          $sb = isset($game['score_b']) && $game['score_b'] !== null ? (int)$game['score_b'] : null;
                          $st = ($sa !== null && $sb !== null) ? ($sa + $sb) : 0;
                          $nameA = trim((string)($game['player_a_name'] ?? '')) ?: 'TBD';
                          $nameB = trim((string)($game['player_b_name'] ?? '')) ?: 'TBD';
                          $slotA = ($mIdx * 2);
                          $slotB = ($mIdx * 2) + 1;
                          $displayA = $nameA;
                          $displayB = $nameB;
                          if ($slotA >= $allowedTeamsForRound) {
                              $displayA = 'BYE';
                          }
                          if ($slotB >= $allowedTeamsForRound) {
                              $displayB = 'BYE';
                          }
                          if (strtoupper($displayA) === 'TBD') { $displayA = 'BYE'; }
                          if (strtoupper($displayB) === 'TBD') { $displayB = 'BYE'; }
                          $isLocked = ($displayA === 'BYE' || $displayB === 'BYE');
                          if ($isLocked) {
                              $sa = null;
                              $sb = null;
                              $st = 0;
                          }
                        ?>
                        <div class="match-item">
                          <div class="match-box">
                            <div class="seed-line"><?= h($displayA) ?></div>
                            <div class="seed-line"><?= h($displayB) ?></div>
                          </div>
                          <form method="post" class="score-editor" data-score-editor style="margin-top:6px;">
                            <input type="hidden" name="competition_action" value="update_score">
                            <input type="hidden" name="game_id" value="<?= (int)($game['id'] ?? 0) ?>">
                            <span class="score-vs"><?= h($displayA) ?> vs <?= h($displayB) ?></span>
                            <label class="score-label" for="score_total_<?= (int)$game['id'] ?>">Total</label>
                            <select id="score_total_<?= (int)$game['id'] ?>" name="score_total" data-score-total <?= $isLocked ? 'disabled' : '' ?>>
                              <option value="">-- total --</option>
                              <?php foreach (PADEL_ALLOWED_TOTAL_POINTS as $tp): ?>
                                <option value="<?= (int)$tp ?>"<?= $st === (int)$tp ? ' selected' : '' ?>><?= (int)$tp ?></option>
                              <?php endforeach; ?>
                            </select>
                            <label class="score-label" for="score_a_<?= (int)$game['id'] ?>">Skor 1</label>
                            <input id="score_a_<?= (int)$game['id'] ?>" type="number" name="score_a" data-score-a min="0" value="<?= $sa !== null ? (int)$sa : '' ?>" placeholder="A" <?= $isLocked ? 'disabled' : '' ?>>
                            <span>-</span>
                            <label class="score-label" for="score_b_<?= (int)$game['id'] ?>">Skor 2</label>
                            <input id="score_b_<?= (int)$game['id'] ?>" type="number" name="score_b" data-score-b min="0" value="<?= $sb !== null ? (int)$sb : '' ?>" placeholder="B" <?= $isLocked ? 'disabled' : '' ?>>
                            <button class="btn ghost small" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Save</button>
                            <div class="live-status" data-live-status aria-live="polite"></div>
                          </form>
                          <div class="match-actions">
                            <form method="post" onsubmit="return confirm('Hapus game ini?');">
                              <input type="hidden" name="competition_action" value="delete_game">
                              <input type="hidden" name="game_id" value="<?= (int)($game['id'] ?? 0) ?>">
                              <button class="btn ghost small" type="submit">Hapus</button>
                            </form>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="standing-wrap">
            <table class="standing-table">
              <thead>
                <tr><th>#</th><th>Team</th><th>M</th><th>W</th><th>D</th><th>L</th><th>PF</th><th>PA</th><th>Diff</th><th>Total Poin</th></tr>
              </thead>
              <tbody>
                <?php $rows = $standingsByType[$type] ?? []; ?>
                <?php if (!$rows): ?>
                  <tr><td colspan="10">Belum ada skor valid untuk klasemen <?= h($type) ?>.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $idx => $row): ?>
                    <?php $diff = (int)($row['pf'] ?? 0) - (int)($row['pa'] ?? 0); ?>
                    <tr>
                      <td><?= (int)($idx + 1) ?></td><td><?= h((string)($row['name'] ?? '-')) ?></td><td><?= (int)($row['match'] ?? 0) ?></td>
                      <td><?= (int)($row['win'] ?? 0) ?></td><td><?= (int)($row['draw'] ?? 0) ?></td><td><?= (int)($row['lose'] ?? 0) ?></td>
                      <td><?= (int)($row['pf'] ?? 0) ?></td><td><?= (int)($row['pa'] ?? 0) ?></td><td><?= $diff >= 0 ? '+' . $diff : (string)$diff ?></td>
                      <td><strong><?= (int)($row['point_total'] ?? 0) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>
<script>
(function () {
  var activeTypeTarget = 'all';

  function applyTypeFilter(boardRoot, target) {
    var filterWrap = boardRoot.querySelector('[data-type-filter]');
    var panels = boardRoot.querySelectorAll('[data-type-panel]');
    if (!filterWrap || !panels.length) return;
    activeTypeTarget = target || 'all';
    filterWrap.querySelectorAll('button[data-type-target]').forEach(function (btn) {
      btn.classList.toggle('active', (btn.getAttribute('data-type-target') || 'all') === activeTypeTarget);
    });
    panels.forEach(function (panel) {
      var type = panel.getAttribute('data-type-panel') || '';
      panel.style.display = (activeTypeTarget === 'all' || activeTypeTarget === type) ? '' : 'none';
    });
  }

  function refreshCompetitionBoard() {
    return fetch(window.location.pathname + window.location.search, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) { return response.text(); })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newBoard = doc.querySelector('[data-live-board]');
        var currentBoard = document.querySelector('[data-live-board]');
        if (!newBoard || !currentBoard || !currentBoard.parentNode) return;
        currentBoard.parentNode.replaceChild(newBoard, currentBoard);
        initBoardInteractions(newBoard);
        applyTypeFilter(newBoard, activeTypeTarget);
      });
  }

  function showStatusOnGameForm(gameId, message, kind, durationMs) {
    if (!gameId) return;
    var form = document.querySelector('[data-score-editor] input[name="game_id"][value="' + String(gameId) + '"]');
    if (!form) return;
    var scoreForm = form.closest('[data-score-editor]');
    if (!scoreForm) return;
    var statusEl = scoreForm.querySelector('[data-live-status]');
    if (!statusEl) return;
    statusEl.textContent = message || '';
    statusEl.classList.remove('ok', 'error');
    if (kind) statusEl.classList.add(kind);
    if (message) {
      window.setTimeout(function () {
        // Clear only if status is still showing the same message.
        if (statusEl.textContent === message) {
          statusEl.textContent = '';
          statusEl.classList.remove('ok', 'error');
        }
      }, durationMs || 5000);
    }
  }

  function initBoardInteractions(boardRoot) {
    var filterWrap = boardRoot.querySelector('[data-type-filter]');
    if (filterWrap) {
      filterWrap.querySelectorAll('button[data-type-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          applyTypeFilter(boardRoot, btn.getAttribute('data-type-target') || 'all');
        });
      });
    }

    boardRoot.querySelectorAll('[data-score-editor]').forEach(function (form) {
      var totalEl = form.querySelector('[data-score-total]');
      var aEl = form.querySelector('[data-score-a]');
      var bEl = form.querySelector('[data-score-b]');
      var statusEl = form.querySelector('[data-live-status]');
      var submitBtn = form.querySelector('button[type="submit"]');
      if (!totalEl || !aEl || !bEl) return;

      function setStatus(message, kind) {
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.remove('ok', 'error');
        if (kind) statusEl.classList.add(kind);
      }
      function parseTotal() {
        var total = parseInt(totalEl.value || '', 10);
        return Number.isFinite(total) && total >= 0 ? total : null;
      }
      function clampScore(v, total) {
        var n = parseInt(v || '', 10);
        if (!Number.isFinite(n)) return null;
        if (n < 0) n = 0;
        if (n > total) n = total;
        return n;
      }
      function applyMax(total) {
        var maxVal = total !== null ? String(total) : '';
        aEl.setAttribute('max', maxVal);
        bEl.setAttribute('max', maxVal);
      }
      function sync(source) {
        var total = parseTotal();
        applyMax(total);
        if (total === null) return;
        var a = clampScore(aEl.value, total);
        var b = clampScore(bEl.value, total);
        if (source === 'a' && a !== null) {
          aEl.value = String(a);
          bEl.value = String(total - a);
          return;
        }
        if (source === 'b' && b !== null) {
          bEl.value = String(b);
          aEl.value = String(total - b);
          return;
        }
        if (a !== null && b === null) {
          aEl.value = String(a);
          bEl.value = String(total - a);
        } else if (b !== null && a === null) {
          bEl.value = String(b);
          aEl.value = String(total - b);
        } else if (a !== null && b !== null) {
          aEl.value = String(a);
          bEl.value = String(total - a);
        }
      }

      aEl.addEventListener('input', function () { sync('a'); });
      bEl.addEventListener('input', function () { sync('b'); });
      aEl.addEventListener('change', function () { sync('a'); });
      bEl.addEventListener('change', function () { sync('b'); });
      totalEl.addEventListener('change', function () { sync('total'); });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var gameIdEl = form.querySelector('input[name="game_id"]');
        var currentGameId = gameIdEl ? parseInt(gameIdEl.value || '0', 10) : 0;
        var total = parseTotal();
        var hasScoreInput = String(aEl.value || '').trim() !== '' || String(bEl.value || '').trim() !== '';
        if (total === null) {
          if (hasScoreInput) {
            setStatus('Pilih total poin dulu sebelum simpan skor.', 'error');
          }
          return;
        }
        var a = clampScore(aEl.value, total);
        var b = clampScore(bEl.value, total);
        if (a === null && b === null) return;
        if (a === null && b !== null) {
          a = total - b;
        } else if (b === null && a !== null) {
          b = total - a;
        } else if (a !== null && b !== null && a + b !== total) {
          b = total - a;
        }
        aEl.value = a !== null ? String(a) : '';
        bEl.value = b !== null ? String(b) : '';

        setStatus('Menyimpan...', null);
        if (submitBtn) submitBtn.disabled = true;
        fetch(window.location.pathname + window.location.search, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          body: new FormData(form)
        })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (!data || !data.ok) {
              throw new Error((data && data.message) ? data.message : 'Gagal menyimpan skor.');
            }
            var okMessage = data.message || 'Skor berhasil disimpan.';
            setStatus(okMessage, 'ok');
            return refreshCompetitionBoard().then(function () {
              showStatusOnGameForm(currentGameId, okMessage, 'ok', 5000);
            });
          })
          .catch(function (error) {
            var errMsg = error && error.message ? error.message : 'Terjadi kesalahan saat simpan.';
            setStatus(errMsg, 'error');
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
      sync('total');
    });
  }

  var board = document.querySelector('[data-live-board]');
  if (board) {
    initBoardInteractions(board);
    applyTypeFilter(board, activeTypeTarget);
  }
})();
</script>
<?php render_footer(['isAdmin' => true]); ?>

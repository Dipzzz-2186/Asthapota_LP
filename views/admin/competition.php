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

function normalize_court_count($value): int {
    $n = (int)$value;
    if ($n < 1) {
        $n = 1;
    }
    if ($n > 12) {
        $n = 12;
    }
    return $n;
}

function calculate_round_estimation(int $playerCount, int $courtCount, string $type): array {
    $playerCount = max(0, $playerCount);
    $courtCount = normalize_court_count($courtCount);
    $maxMatchesPerLogicalRound = intdiv($playerCount, 4);
    if ($maxMatchesPerLogicalRound <= 0) {
        return [
            'effective_courts' => 0,
            'waves_per_logical_round' => 0,
            'logical_rounds' => 0,
            'estimated_slots' => 0,
            'recommended_rounds' => 0,
        ];
    }
    $effectiveCourts = min($courtCount, $maxMatchesPerLogicalRound);
    $wavesPerLogicalRound = (int)ceil($maxMatchesPerLogicalRound / max(1, $effectiveCourts));

    // Americano: complete partner-rotation target is (players - 1) logical rounds (for even players).
    $logicalRounds = ($type === 'Americano')
        ? max(1, $playerCount - 1)
        : 0;

    // Mexicano is adaptive per ranking and has no fixed official total round count.
    // We expose a practical recommendation: 6 logical rounds.
    $recommendedRounds = ($type === 'Mexicano') ? 6 : $logicalRounds;
    $estimatedSlots = $recommendedRounds * $wavesPerLogicalRound;

    return [
        'effective_courts' => $effectiveCourts,
        'waves_per_logical_round' => $wavesPerLogicalRound,
        'logical_rounds' => $logicalRounds,
        'estimated_slots' => $estimatedSlots,
        'recommended_rounds' => $recommendedRounds,
    ];
}

function build_competition_label_from_title(string $type, string $title): string {
    $label = preg_replace('/\s*(?:-|)?\s*R\d+\s*M\d+\s*$/i', '', trim($title));
    $label = trim((string)$label);
    return $label !== '' ? $label : $type;
}

function is_auto_competition_title(string $type, string $title): bool {
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    return (bool)preg_match('/^' . preg_quote($type, '/') . '\s*R\d+\s*M\d+$/i', $title);
}

function competition_ts(string $value): int {
    $ts = strtotime(trim($value));
    return $ts ? (int)$ts : 0;
}

function read_configured_match_total(PDO $db, int $gameId): ?int {
    if ($gameId <= 0) {
        return null;
    }
    try {
        $stmt = $db->prepare('SELECT match_total_points FROM competition_games WHERE id = ? LIMIT 1');
        $stmt->execute([$gameId]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return null;
        }
        $total = (int)$val;
        return in_array($total, PADEL_ALLOWED_TOTAL_POINTS, true) ? $total : null;
    } catch (Throwable $e) {
        return null;
    }
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

function build_player_ranking_stats(PDO $db, string $competitionType): array {
    $stats = [];
    try {
        $stmt = $db->prepare(
            "SELECT player_a_name, player_b_name, score_a, score_b
             FROM competition_games
             WHERE competition_type = ?"
        );
        $stmt->execute([$competitionType]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamA = trim((string)($row['player_a_name'] ?? ''));
            $teamB = trim((string)($row['player_b_name'] ?? ''));
            $membersA = split_team_members($teamA);
            $membersB = split_team_members($teamB);
            $sa = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
            $sb = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
            if (!$membersA || !$membersB || $sa === null || $sb === null) {
                continue;
            }
            if (!in_array($sa + $sb, PADEL_ALLOWED_TOTAL_POINTS, true)) {
                continue;
            }
            foreach ($membersA as $name) {
                if (!isset($stats[$name])) {
                    $stats[$name] = ['point_total' => 0, 'point_diff' => 0];
                }
                $stats[$name]['point_total'] += $sa;
                $stats[$name]['point_diff'] += ($sa - $sb);
            }
            foreach ($membersB as $name) {
                if (!isset($stats[$name])) {
                    $stats[$name] = ['point_total' => 0, 'point_diff' => 0];
                }
                $stats[$name]['point_total'] += $sb;
                $stats[$name]['point_diff'] += ($sb - $sa);
            }
        }
    } catch (Throwable $e) {
        $stats = [];
    }
    return $stats;
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

function canonical_pair_key(string $a, string $b): string {
    $x = trim($a);
    $y = trim($b);
    if (strcasecmp($x, $y) > 0) {
        $tmp = $x;
        $x = $y;
        $y = $tmp;
    }
    return strtolower($x . '|' . $y);
}

function build_americano_full_rounds(array $players, int $courtCount): array {
    $players = array_values(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $players), static function (string $name): bool {
        return $name !== '';
    }));
    if (count($players) < 4 || count($players) % 4 !== 0) {
        return [];
    }
    $courtCount = normalize_court_count($courtCount);
    $partnerRounds = build_round_robin($players); // single cycle: n-1 logical rounds
    if (!$partnerRounds) {
        return [];
    }
    $partnerLimit = 1;
    $pairingTracker = [];
    $allMatches = [];
    $logicalRoundNo = 1;
    $matchPerRound = intdiv(count($players), 4);
    if ($matchPerRound <= 0) {
        return [];
    }
    $sesiPerRound = (int)ceil($matchPerRound / max(1, $courtCount));

    foreach ($partnerRounds as $roundIdx => $partnerPairs) {
        $teams = [];
        foreach ($partnerPairs as $pair) {
            $left = trim((string)($pair[0] ?? ''));
            $right = trim((string)($pair[1] ?? ''));
            if ($left === '' || $right === '') {
                continue;
            }
            if ($left === 'BYE' || $right === 'BYE') {
                continue;
            }
            $pairKey = canonical_pair_key($left, $right);
            $pairingTracker[$pairKey] = (int)($pairingTracker[$pairKey] ?? 0) + 1;
            if ($pairingTracker[$pairKey] > $partnerLimit) {
                return [];
            }
            $teams[] = $left . ' & ' . $right;
        }
        if (!$teams || count($teams) % 2 !== 0) {
            continue;
        }
        for ($i = 0; $i < count($teams); $i += 2) {
            $a = (string)($teams[$i] ?? '');
            $b = (string)($teams[$i + 1] ?? '');
            if ($a === '' || $b === '') {
                continue;
            }
            $matchNo = (int)floor($i / 2) + 1;
            $sessionNo = (int)floor(($matchNo - 1) / $courtCount) + 1;
            $allMatches[] = [
                'round_no' => $logicalRoundNo,
                'session_no' => $sessionNo,
                'match_no' => $matchNo,
                'court_no' => (($matchNo - 1) % $courtCount) + 1,
                'player_a_name' => $a,
                'player_b_name' => $b,
            ];
        }
        $logicalRoundNo++;
    }

    // Validate schedule size: logical rounds * matches per round
    $expectedMatches = count($partnerRounds) * $matchPerRound;
    if (count($allMatches) !== $expectedMatches) {
        return [];
    }

    return [
        'mode_cycle' => 'single',
        'logical_rounds' => count($partnerRounds),
        'match_per_round' => $matchPerRound,
        'sesi_per_round' => $sesiPerRound,
        'total_sesi' => count($partnerRounds) * $sesiPerRound,
        'pairing_tracker' => $pairingTracker,
        'matches' => $allMatches,
    ];
}

function build_standings_from_games(array $gamesRows): array {
    $table = [];
    $headToHead = [];
    foreach ($gamesRows as $row) {
        $teamA = trim((string)($row['player_a_name'] ?? ''));
        $teamB = trim((string)($row['player_b_name'] ?? ''));
        $membersA = split_team_members($teamA);
        $membersB = split_team_members($teamB);
        $sa = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
        $sb = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
        if (!$membersA || !$membersB || $sa === null || $sb === null || !in_array($sa + $sb, PADEL_ALLOWED_TOTAL_POINTS, true)) {
            continue;
        }
        foreach ($membersA as $name) {
            if (!isset($table[$name])) {
                $table[$name] = ['name' => $name, 'point_total' => 0, 'point_diff' => 0, 'played' => 0];
            }
            $table[$name]['point_total'] += $sa;
            $table[$name]['point_diff'] += ($sa - $sb);
            $table[$name]['played']++;
        }
        foreach ($membersB as $name) {
            if (!isset($table[$name])) {
                $table[$name] = ['name' => $name, 'point_total' => 0, 'point_diff' => 0, 'played' => 0];
            }
            $table[$name]['point_total'] += $sb;
            $table[$name]['point_diff'] += ($sb - $sa);
            $table[$name]['played']++;
        }
        foreach ($membersA as $pa) {
            foreach ($membersB as $pb) {
                if (!isset($headToHead[$pa])) $headToHead[$pa] = [];
                if (!isset($headToHead[$pb])) $headToHead[$pb] = [];
                $headToHead[$pa][$pb] = (int)($headToHead[$pa][$pb] ?? 0) + ($sa - $sb);
                $headToHead[$pb][$pa] = (int)($headToHead[$pb][$pa] ?? 0) + ($sb - $sa);
            }
        }
    }

    $rows = array_values($table);
    usort($rows, static function (array $x, array $y) use ($headToHead): int {
        if ((int)$x['point_total'] !== (int)$y['point_total']) return (int)$y['point_total'] <=> (int)$x['point_total'];
        if ((int)$x['point_diff'] !== (int)$y['point_diff']) return (int)$y['point_diff'] <=> (int)$x['point_diff'];
        $hx = (int)($headToHead[$x['name']][$y['name']] ?? 0);
        if ($hx !== 0) return $hx > 0 ? -1 : 1;
        return strcmp((string)$x['name'], (string)$y['name']);
    });

    return $rows;
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
        "SELECT id, session_no, match_no, court_no, court_count, player_a_name, player_b_name, score_a, score_b, match_total_points
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
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
    $configuredTotal = null;
    foreach ($rows as $row) {
        $candidateTotal = isset($row['match_total_points']) && $row['match_total_points'] !== null
            ? (int)$row['match_total_points']
            : null;
        if ($candidateTotal !== null && in_array($candidateTotal, PADEL_ALLOWED_TOTAL_POINTS, true)) {
            $configuredTotal = $candidateTotal;
            break;
        }
    }
    if ($configuredTotal === null) {
        return [0, 0];
    }
    $configuredCourtCount = 1;
    foreach ($rows as $row) {
        $candidateCourtCount = isset($row['court_count']) && $row['court_count'] !== null
            ? (int)$row['court_count']
            : 0;
        if ($candidateCourtCount > 0) {
            $configuredCourtCount = normalize_court_count($candidateCourtCount);
            break;
        }
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
        $memberPool = [];
        foreach ($allTeams as $teamName) {
            foreach (split_team_members($teamName) as $member) {
                $memberPool[] = $member;
            }
        }
        $memberPool = array_values(array_unique($memberPool));
        if (count($memberPool) < 4) {
            return [0, 0];
        }
        $ranking = build_player_ranking_stats($db, 'Mexicano');
        usort($memberPool, static function (string $x, string $y) use ($ranking): int {
            $px = (int)($ranking[$x]['point_total'] ?? 0);
            $py = (int)($ranking[$y]['point_total'] ?? 0);
            if ($px !== $py) {
                return $py <=> $px;
            }
            $dx = (int)($ranking[$x]['point_diff'] ?? 0);
            $dy = (int)($ranking[$y]['point_diff'] ?? 0);
            if ($dx !== $dy) {
                return $dy <=> $dx;
            }
            return strcmp($x, $y);
        });

        $pairs = [];
        $totalMembers = count($memberPool);
        $fullGroups = intdiv($totalMembers, 4);
        for ($group = 0; $group < $fullGroups; $group++) {
            $offset = $group * 4;
            $chunk = array_slice($memberPool, $offset, 4);
            if (count($chunk) < 4) {
                continue;
            }
            // Mexicano round pairing: ranking 1&3 vs 2&4 (per group of 4 players).
            $teamA = $chunk[0] . ' & ' . $chunk[2];
            $teamB = $chunk[1] . ' & ' . $chunk[3];
            $pairs[] = [$teamA, $teamB];
        }
        $leftovers = array_slice($memberPool, $fullGroups * 4);
        if ($leftovers) {
            $leftoverTeams = build_teams_from_players($leftovers);
            foreach (build_pairs_from_teams($leftoverTeams) as $leftoverPair) {
                $pairs[] = $leftoverPair;
            }
        }
        if (!$pairs) {
            return [0, 0];
        }
    }

    if ($type === 'Americano') {
        // For Americano, randomize slot positions as well so teams can land on different boxes.
        shuffle($nextTeams);
        $pairs = build_pairs_from_teams($nextTeams);
    }
    if (!$pairs) {
        return [0, 0];
    }

    $nextRound = $sourceRound + 1;
    $fetchExisting = $db->prepare(
        "SELECT id, session_no, match_no, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
    );
    $fetchExisting->execute([$type, $nextRound]);
    $existingRows = $fetchExisting->fetchAll(PDO::FETCH_ASSOC);

    $insert = $db->prepare(
        "INSERT INTO competition_games (
            package_id, competition_type, game_title, round_no, session_no, match_no, court_no, court_count, match_total_points,
            player_a_user_id, player_a_name, player_b_user_id, player_b_name,
            score_a, score_b, game_date, notes, created_by_admin_id, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $update = $db->prepare(
        "UPDATE competition_games
         SET game_title = ?, session_no = ?, court_no = ?, court_count = ?, match_total_points = ?, player_a_name = ?, player_b_name = ?
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
        $sessionNo = (int)floor(($matchNo - 1) / $configuredCourtCount) + 1;
        $courtNo = (($idx % $configuredCourtCount) + 1);
        $title = $type . ' R' . $nextRound . ' M' . $matchNo;
        $a = (string)$pair[0];
        $b = (string)$pair[1];

        if (isset($existingRows[$idx])) {
            $row = $existingRows[$idx];
            $hasScore = ($row['score_a'] !== null || $row['score_b'] !== null);
            if (!$hasScore) {
                $update->execute([$title, $sessionNo, $courtNo, $configuredCourtCount, $configuredTotal, $a, $b, (int)$row['id']]);
                $updated++;
            }
            continue;
        }

        $insert->execute([
            null,
            $type,
            $title,
            $nextRound,
            $sessionNo,
            $matchNo,
            $courtNo,
            $configuredCourtCount,
            $configuredTotal,
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
            session_no INT NULL,
            match_no INT NULL,
            court_no INT NULL,
            court_count INT NULL,
            match_total_points INT NULL,
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
            INDEX idx_competition_type_round (competition_type, round_no, session_no, match_no)
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
        $ensureColumn($db, $schema, 'session_no', "INT NULL AFTER round_no");
        $ensureColumn($db, $schema, 'match_no', "INT NULL AFTER session_no");
        $ensureColumn($db, $schema, 'court_no', "INT NULL AFTER match_no");
        $ensureColumn($db, $schema, 'court_count', "INT NULL AFTER court_no");
        $ensureColumn($db, $schema, 'match_total_points', "INT NULL AFTER court_count");
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
        $configuredTotal = null;
        if ($gameId <= 0) {
            $flash['error'] = 'ID game tidak valid.';
        } elseif ($parseError !== '') {
            $flash['error'] = $parseError;
        } else {
            $configuredTotal = read_configured_match_total($db, $gameId);
            if ($configuredTotal === null) {
                $flash['error'] = 'Total poin game belum dikonfigurasi. Generate ulang match dari form atas.';
            }
            $scoreError = $flash['error'] === '' ? validate_padel_score($scoreA, $scoreB, $configuredTotal) : '';
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
                if ($savedType === 'Mexicano') {
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
        $matchTotalPoints = isset($_POST['match_total_points']) ? (int)$_POST['match_total_points'] : 0;
        $courtCount = normalize_court_count($_POST['court_count'] ?? 1);
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        if (!in_array($type, $allowedTypes, true)) {
            $flash['error'] = 'Pilih tipe game yang valid.';
        } elseif (!in_array($matchTotalPoints, PADEL_ALLOWED_TOTAL_POINTS, true)) {
            $flash['error'] = 'Pilih total poin match yang valid.';
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
            } elseif ($type === 'Americano' && count($attendees) % 4 !== 0) {
                $flash['error'] = 'Americano membutuhkan jumlah pemain kelipatan 4 agar semua pemain bermain di setiap ronde.';
            } elseif ($courtCount > intdiv(count($attendees), 4)) {
                $flash['error'] = 'Jumlah court terlalu banyak untuk total pemain. Maksimal court aktif: ' . (int)intdiv(count($attendees), 4) . '.';
            } else {
                shuffle($attendees);
                try {
                    $insert = $db->prepare(
                        'INSERT INTO competition_games (
                            package_id, competition_type, game_title, round_no, session_no, match_no, court_no, court_count, match_total_points,
                            player_a_user_id, player_a_name, player_b_user_id, player_b_name,
                            score_a, score_b, game_date, notes, created_by_admin_id, created_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $created = 0;
                    $label = $titlePrefix !== '' ? $titlePrefix : $type;
                    if ($type === 'Americano') {
                        $schedule = build_americano_full_rounds($attendees, $courtCount);
                        if (!$schedule || empty($schedule['matches'])) {
                            throw new RuntimeException('Gagal menyusun jadwal Americano.');
                        }
                        foreach ((array)$schedule['matches'] as $row) {
                            $roundNo = (int)($row['round_no'] ?? 1);
                            $sessionNo = (int)($row['session_no'] ?? 1);
                            $matchNo = (int)($row['match_no'] ?? 1);
                            $courtNo = (int)($row['court_no'] ?? 1);
                            $title = $label . ' - R' . $roundNo . ' M' . $matchNo;
                            $insert->execute([
                                null, $type, $title, $roundNo, $sessionNo, $matchNo, $courtNo, $courtCount, $matchTotalPoints,
                                null, (string)($row['player_a_name'] ?? ''), null, (string)($row['player_b_name'] ?? ''),
                                null, null, $gameDateRaw !== '' ? $gameDateRaw : null, 'mode_cycle=single',
                                $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                        $flash['success'] = 'Americano: '
                            . (int)($schedule['logical_rounds'] ?? 0) . ' ronde logika, '
                            . (int)($schedule['sesi_per_round'] ?? 0) . ' sesi/ronde, total '
                            . (int)($schedule['total_sesi'] ?? 0) . ' sesi, '
                            . $created . ' match.';
                    } else {
                        // Mexicano starts from round 1 and grows by ranking after each completed round.
                        $teams = build_teams_from_players($attendees);
                        $pairs = build_pairs_from_teams($teams);
                        if (!$pairs) {
                            throw new RuntimeException('Gagal membentuk bagan Mexicano.');
                        }
                        $roundNo = 1;
                        foreach ($pairs as $mIdx => $pair) {
                            $matchNo = $mIdx + 1;
                            $sessionNo = (int)floor(($matchNo - 1) / $courtCount) + 1;
                            $courtNo = (($mIdx % $courtCount) + 1);
                            $title = $label . ' - R' . $roundNo . ' M' . $matchNo;
                            $insert->execute([
                                null, $type, $title, $roundNo, $sessionNo, $matchNo, $courtNo, $courtCount, $matchTotalPoints,
                                null, (string)$pair[0], null, (string)$pair[1],
                                null, null, $gameDateRaw !== '' ? $gameDateRaw : null, 'mode_cycle=single',
                                $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                        $estimation = calculate_round_estimation(count($attendees), $courtCount, $type);
                        $wavesPerRound = (int)($estimation['waves_per_logical_round'] ?? 0);
                        $flash['success'] = 'Berhasil generate Round 1 Mexicano (' . $created . ' match) di ' . $courtCount . ' court. Per ronde butuh ' . max(1, $wavesPerRound) . ' wave.';
                    }
                } catch (Throwable $e) {
                    $flash['error'] = 'Gagal menambahkan jadwal competition.';
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
        "SELECT id, game_title, round_no, session_no, match_no, court_no, court_count, match_total_points, game_date, created_at, competition_type,
                player_a_name, player_b_name, score_a, score_b
         FROM competition_games
         ORDER BY
            CASE
              WHEN LOWER(TRIM(competition_type)) LIKE '%americano%' THEN 1
              WHEN LOWER(TRIM(competition_type)) LIKE '%mexicano%' THEN 2
              ELSE 3
            END,
            COALESCE(round_no, 999999), COALESCE(session_no, 1), COALESCE(match_no, 999999), id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $games = [];
}

$attendeeNames = fetch_competition_attendees($db);
$registeredAttendeeCount = count($attendeeNames);

$tournaments = [];
$recentCustomLabelByType = ['Americano' => '', 'Mexicano' => ''];
$gamesByIdAsc = $games;
usort($gamesByIdAsc, static function (array $a, array $b): int {
    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
});
foreach ($gamesByIdAsc as $row) {
    $type = normalize_competition_type((string)($row['competition_type'] ?? ''));
    if ($type === '') {
        continue;
    }
    $gameTitle = trim((string)($row['game_title'] ?? ''));
    $baseLabel = build_competition_label_from_title($type, $gameTitle !== '' ? $gameTitle : $type);
    $isAutoTitle = is_auto_competition_title($type, $gameTitle);
    if ($isAutoTitle) {
        $label = trim((string)($recentCustomLabelByType[$type] ?? ''));
        if ($label === '') {
            $label = $baseLabel;
        }
    } else {
        $label = $baseLabel;
        $recentCustomLabelByType[$type] = $label;
    }
    $key = md5(strtolower($type . '|' . $label));

    if (!isset($tournaments[$key])) {
        $tournaments[$key] = [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'games' => [],
            'players' => [],
            'updated_at' => '',
            'updated_ts' => 0,
            'rounds' => [],
            'standings' => [],
        ];
    }
    $tournaments[$key]['games'][] = $row;

    $createdAt = trim((string)($row['created_at'] ?? ''));
    $createdTs = competition_ts($createdAt);
    if ($createdTs > (int)$tournaments[$key]['updated_ts']) {
        $tournaments[$key]['updated_ts'] = $createdTs;
        $tournaments[$key]['updated_at'] = $createdAt;
    }

    $teamA = trim((string)($row['player_a_name'] ?? ''));
    $teamB = trim((string)($row['player_b_name'] ?? ''));
    foreach (array_merge(split_team_members($teamA), split_team_members($teamB)) as $member) {
        $nameKey = strtolower(trim((string)$member));
        if ($nameKey === '' || $nameKey === 'bye' || $nameKey === 'tbd') {
            continue;
        }
        $tournaments[$key]['players'][$nameKey] = trim((string)$member);
    }
}

foreach ($tournaments as $key => $tournament) {
    $gamesRows = (array)($tournament['games'] ?? []);
    usort($gamesRows, static function (array $a, array $b): int {
        $ra = (int)($a['round_no'] ?? 0);
        $rb = (int)($b['round_no'] ?? 0);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $sa = (int)($a['session_no'] ?? 1);
        $sb = (int)($b['session_no'] ?? 1);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $ma = (int)($a['match_no'] ?? 0);
        $mb = (int)($b['match_no'] ?? 0);
        if ($ma !== $mb) {
            return $ma <=> $mb;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    $rounds = [];
    foreach ($gamesRows as $row) {
        $roundNo = (int)($row['round_no'] ?? 0);
        if ($roundNo <= 0) {
            $roundNo = 999999;
        }
        if (!isset($rounds[$roundNo])) {
            $rounds[$roundNo] = [];
        }
        $rounds[$roundNo][] = $row;
    }
    ksort($rounds, SORT_NUMERIC);
    $standingRows = build_standings_from_games($gamesRows);

    $tournaments[$key]['games'] = $gamesRows;
    $tournaments[$key]['rounds'] = $rounds;
    $tournaments[$key]['standings'] = $standingRows;
}

$tournamentList = array_values($tournaments);
usort($tournamentList, static function (array $a, array $b): int {
    return (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0);
});

$tournamentsPerPage = 10;
$requestedTournamentPage = (int)($_GET['tour_page'] ?? 1);
if ($requestedTournamentPage < 1) {
    $requestedTournamentPage = 1;
}
$totalTournamentCount = count($tournamentList);
$totalTournamentPages = max(1, (int)ceil($totalTournamentCount / $tournamentsPerPage));
$currentTournamentPage = min($requestedTournamentPage, $totalTournamentPages);
$tournamentOffset = ($currentTournamentPage - 1) * $tournamentsPerPage;
$tournamentsForList = array_slice($tournamentList, $tournamentOffset, $tournamentsPerPage);

$listQueryParams = $_GET;
unset($listQueryParams['_rt']);
$buildTournamentPageUrl = static function (int $page) use ($listQueryParams): string {
    $page = max(1, $page);
    $params = $listQueryParams;
    $params['tour_page'] = $page;
    $query = http_build_query($params);
    return '/admin/competition' . ($query !== '' ? ('?' . $query) : '');
};

$extraHead = <<<HTML
<style>
  .competition-grid{display:grid;grid-template-columns:minmax(280px,380px) minmax(0,1fr);gap:16px;align-items:start}
  .competition-card{background:rgba(255,255,255,.92);border:1px solid rgba(15,32,60,.12);border-radius:16px;padding:16px;box-shadow:0 8px 26px rgba(15,32,60,.08)}
  .competition-card--full{grid-column:1 / -1}
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
  .rounds-nav{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:2px 0 8px}
  .rounds-nav-btn{width:34px;min-width:34px;height:34px;border-radius:10px;border:1px solid #c6d4ea;background:#fff;color:#163966;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
  .rounds-nav-btn:disabled{opacity:.45;cursor:not-allowed}
  .rounds-nav-label{font-size:12px;font-weight:800;color:#173964;letter-spacing:.35px;text-transform:uppercase}
  .rounds-scroll{overflow:hidden;padding-bottom:4px;margin-top:0}
  .rounds-track{display:flex;gap:0;transition:transform .28s ease}
  .round-column{width:100%;max-width:100%;position:relative;flex:0 0 100%;min-width:100%;box-sizing:border-box;padding-right:0}
  .round-column + .round-column::before{display:none}
  .round-column{--round-accent:#1f2937;--round-bg:#ffffff}
  .round-column:nth-child(6n+1),
  .round-column:nth-child(6n+2),
  .round-column:nth-child(6n+3),
  .round-column:nth-child(6n+4),
  .round-column:nth-child(6n+5),
  .round-column:nth-child(6n+6){--round-accent:#1f2937;--round-bg:#ffffff}
  .round-block{border:1px solid #e3e7ee;border-radius:16px;padding:14px;background:#fff;box-shadow:0 6px 18px rgba(15,32,60,.05)}
  .round-title{margin:0 0 16px;font-size:16px;color:#111827;font-weight:900;text-transform:uppercase;letter-spacing:.4px}
  .match-item + .match-item{margin-top:18px}
  .match-item{border:0;border-radius:0;background:transparent;padding:0;box-shadow:none}
  .match-box{display:grid;grid-template-columns:1fr auto 1fr;border:0;border-radius:12px;background:#f1f2f4;overflow:hidden;box-shadow:0 2px 8px rgba(15,32,60,.06)}
  .match-summary{display:grid;gap:8px}
  .match-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
  .match-caption{font-size:15px;color:#111827;font-weight:800;line-height:1.15}
  .match-top-actions{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;padding-top:10px}
  .match-score-preview{font-size:12px;font-weight:800;color:#173964}
  .match-score-preview .score-pill{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:24px;border-radius:6px;background:#111827;color:#fff;font-size:12px;padding:0 7px}
  .match-open-score{min-width:110px}
  .match-open-score[disabled]{opacity:.55}
  .seed-side{padding:12px 14px;display:grid;gap:4px;align-content:center}
  .seed-side + .seed-side{border-left:1px solid #d5deed}
  .seed-line{padding:0;font-size:15px;color:#2b2f36;min-height:34px;display:flex;align-items:center;line-height:1.2}
  .seed-vs{display:flex;align-items:center;justify-content:center;padding:0 16px;font-size:24px;font-weight:900;color:#353b47;text-transform:lowercase;border-left:1px solid #e3e7ee;border-right:1px solid #e3e7ee;background:#eceef2}
  .score-editor{display:grid;grid-template-columns:1fr;row-gap:10px}
  .score-label{font-size:11px;color:#47628b;font-weight:800}
  .score-vs{display:none}
  .score-total-hint{grid-column:1 / -1;display:inline-flex;align-items:center;gap:6px;color:#173964;font-weight:800;background:#eef4ff;border:1px solid #d3e1fb;border-radius:999px;padding:6px 10px;width:max-content}
  .score-strip{display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
  .score-side{font-size:15px;color:#334155;font-weight:800}
  .score-vs-inline{font-size:12px;color:#405d84;font-weight:900;text-transform:lowercase}
  .score-separator{font-weight:900;color:#4f6489;text-align:center}
  .match-actions{margin-top:10px;display:flex;justify-content:flex-end}
  .score-editor select,.score-editor input{border:1px solid #c6d4ea;border-radius:8px;padding:7px 8px;background:#fff}
  .score-editor input{width:68px;text-align:center;font-weight:700;color:#14345f}
  .score-box{
    width:66px;
    height:52px;
    border:2px solid #0d1117 !important;
    background:#0d1117 !important;
    color:#fff !important;
    font-size:36px;
    font-weight:900;
    letter-spacing:.5px;
    text-align:center;
    border-radius:8px !important;
    padding:0 2px !important;
    line-height:1;
    -webkit-appearance:none;
    appearance:none;
    background-image:none !important;
    text-indent:0;
  }
  .score-box:focus{outline:2px solid #7aa7ff;outline-offset:2px}
  .score-editor button[type="submit"]{height:34px}
  .live-status{min-height:16px;font-size:11px;color:#4c6388;grid-column:1 / -1}
  .live-status.ok{color:#18633a}
  .live-status.error{color:#b43636}
  .type-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:4px 0 14px}
  .standing-wrap{overflow-x:auto;margin-top:10px}
  .standing-wrap{cursor:grab;user-select:none}
  .standing-wrap.is-dragging{cursor:grabbing}
  table.standing-table{width:100%;border-collapse:collapse;min-width:560px}
  .standing-table th,.standing-table td{text-align:left;padding:8px 7px;border-bottom:1px solid #dfe8f8;font-size:12px;color:#183054}
  .standing-table th{font-size:11px;color:#5a6b86;text-transform:uppercase}
  .tournament-list{display:grid;gap:12px;margin-top:10px}
  .tournament-card{width:100%;text-align:left;border:1px solid #d8e2f2;background:#f8fbff;border-radius:14px;padding:14px 14px;cursor:pointer;transition:.16s ease;border-color:#d8e2f2}
  .tournament-card:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,32,60,.08);border-color:#bcd2f3}
  .tournament-card.is-active{border-color:#1a66e9;box-shadow:0 0 0 2px rgba(26,102,233,.14)}
  .tournament-name{font-size:20px;line-height:1.1;color:#0f294d;font-weight:800;letter-spacing:-.3px}
  .tournament-meta{margin-top:6px;font-size:13px;font-weight:700;color:#1c426e}
  .tournament-updated{margin-top:5px;font-size:11px;color:#5a6b86;font-weight:700;text-transform:uppercase;letter-spacing:.55px}
  .tour-pagination{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:14px}
  .tour-page-link,.tour-page-dots{min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;font-size:13px;font-weight:800}
  .tour-page-link{border:1px solid #d6dee9;background:#fff;color:#213a62;text-decoration:none}
  .tour-page-link:hover{background:#f5f8ff}
  .tour-page-link.current{background:#3b82f6;border-color:#3b82f6;color:#fff}
  .tour-page-link.disabled{opacity:.45;pointer-events:none}
  .tour-page-dots{color:#6b7280}
  .tournament-modal{position:fixed;inset:0;background:rgba(10,20,40,.52);z-index:5000;display:none;align-items:center;justify-content:center;padding:0}
  .tournament-modal.is-open{display:flex}
  .tournament-modal-card{width:min(96vw,1520px);height:92vh;display:grid;grid-template-rows:auto minmax(0,1fr);border-radius:16px;background:#fff;border:1px solid rgba(15,32,60,.15);box-shadow:0 18px 38px rgba(10,20,40,.24);overflow:hidden}
  .tournament-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:18px 22px;border-bottom:0;background:linear-gradient(180deg,#9f8ad0 0%,#7b69b0 100%);position:sticky;top:0;z-index:2}
  .tournament-modal-title{margin:0;font-size:44px;color:#fff;font-weight:900;letter-spacing:.4px;text-transform:uppercase}
  .tournament-modal-close{border:1px solid rgba(255,255,255,.5);background:rgba(255,255,255,.2);color:#fff;border-radius:10px;min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;line-height:1}
  .tournament-modal-body{padding:18px 22px 22px;overflow:auto;background:#f6f7f9;font-family:"Trebuchet MS","Segoe UI",Tahoma,sans-serif}
  .tour-detail-grid{display:grid;grid-template-columns:minmax(360px,500px) minmax(0,1fr);gap:16px;align-items:start}
  .standing-panel,.round-panel{background:#fff;border:1px solid #dfe4ec;border-radius:16px;padding:14px}
  .standing-panel-title{margin:0 0 8px;font-size:12px;font-weight:900;color:#334155;text-transform:uppercase;letter-spacing:.45px}
  .round-panel-title{margin:0 0 14px;font-size:48px;font-weight:900;color:#0f172a;letter-spacing:.2px;line-height:1}
  .game-input-trigger{width:100%;min-height:46px}
  .game-input-modal{position:fixed;inset:0;background:rgba(10,20,40,.38);z-index:5200;display:none;align-items:center;justify-content:center;padding:18px}
  .game-input-modal.is-open{display:flex}
  .game-input-modal-card{width:min(620px,100%);max-height:92vh;display:grid;grid-template-rows:auto minmax(0,1fr);border-radius:14px;background:#fff;border:1px solid #d4e0f2;box-shadow:0 18px 38px rgba(10,20,40,.24);overflow:hidden}
  .game-input-modal-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-bottom:1px solid #d9e3f4;background:#f7faff}
  .game-input-modal-title{margin:0;font-size:17px;color:#0f294d;font-weight:800}
  .game-input-modal-close{border:1px solid #c6d4ea;background:#fff;color:#163966;border-radius:10px;min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;line-height:1}
  .game-input-modal-body{padding:12px 14px 14px;overflow:auto}
  .toast-stack{position:fixed;top:18px;right:18px;z-index:9999;display:grid;gap:10px;max-width:min(420px,calc(100vw - 24px))}
  .toast-item{display:flex;align-items:flex-start;gap:10px;border-radius:12px;padding:11px 12px;box-shadow:0 10px 24px rgba(10,20,40,.18);border:1px solid transparent;background:#fff;animation:toastIn .18s ease-out}
  .toast-item.success{border-color:#9ad5b0;background:#e9f8ef;color:#14532d}
  .toast-item.error{border-color:#efb1b1;background:#feeeee;color:#991b1b}
  .toast-item i{font-size:15px;line-height:1.2;margin-top:1px}
  .toast-msg{font-size:13px;font-weight:700;line-height:1.45}
  .toast-close{border:0;background:transparent;color:inherit;cursor:pointer;padding:0 2px;font-size:16px;line-height:1;opacity:.72}
  .toast-close:hover{opacity:1}
  @keyframes toastIn{from{opacity:0;transform:translateY(-5px) translateX(10px)}to{opacity:1;transform:translateY(0) translateX(0)}}
  @media (max-width:980px){
    .competition-grid{grid-template-columns:1fr}
    .round-column{width:100%;min-width:100%}
    .tournament-name{font-size:18px}
    .tournament-modal{padding:0}
    .tournament-modal-card{width:100vw;height:100vh;max-height:none;border-radius:0}
    .tournament-modal-title{font-size:28px}
    .tour-detail-grid{grid-template-columns:1fr}
    .round-panel-title{font-size:28px}
    .score-editor{grid-template-columns:1fr 72px 14px 1fr 72px;row-gap:7px}
    .score-editor button[type="submit"]{grid-column:1 / -1;justify-self:start}
    .match-actions{justify-content:flex-start}
    .score-strip{justify-content:flex-start}
    .game-input-modal{padding:10px}
  }
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
        <p class="admin-sub">Americano langsung generate semua ronde. Mexicano generate Round 1 dulu, ronde berikutnya tersusun bertahap dari ranking saat ronde sebelumnya selesai.</p>
      </div>
      <div class="competition-actions"><a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left"></i> Dashboard</a></div>
    </div>

    <div id="toastStack" class="toast-stack" aria-live="polite" aria-atomic="true"></div>
    <noscript>
      <?php if (!empty($flash['success'])): ?><div class="alert success"><i class="bi bi-check-circle"></i> <?= h($flash['success']) ?></div><?php endif; ?>
      <?php if (!empty($flash['error'])): ?><div class="alert error"><i class="bi bi-exclamation-triangle"></i> <?= h($flash['error']) ?></div><?php endif; ?>
    </noscript>
    <section class="competition-grid">
      <div class="competition-card">
        <h2><i class="bi bi-plus-circle"></i> Tambah Game</h2>
        <p class="admin-sub" style="margin:0 0 10px;">Total attendee accepted: <strong><?= (int)$registeredAttendeeCount ?></strong>.</p>
        <button type="button" class="btn primary game-input-trigger" data-open-game-input-modal><i class="bi bi-plus-circle"></i> Input Game</button>
      </div>

      <div class="competition-card competition-card--full" data-live-board>
        <h2><i class="bi bi-grid-3x3-gap"></i> Latest Tournaments</h2>
        <p class="admin-sub" style="margin:0;">Klik satu turnamen untuk buka detail ronde + input skor di modal.</p>
        <div class="type-filter" data-game-filter style="margin-top:10px;">
          <button type="button" class="active" data-game-filter-btn="all">Semua</button>
          <button type="button" data-game-filter-btn="Americano">Americano</button>
          <button type="button" data-game-filter-btn="Mexicano">Mexicano</button>
        </div>
        <div class="tournament-list">
          <?php $hasTournament = false; ?>
          <?php foreach ($tournamentsForList as $tournament): ?>
            <?php
              $type = (string)($tournament['type'] ?? '');
              $tourKey = (string)($tournament['key'] ?? '');
              $typeGames = (array)($tournament['games'] ?? []);
              if (!$typeGames) {
                  continue;
              }
              $hasTournament = true;
              $label = (string)($tournament['label'] ?? $type);
              $playerCount = count((array)($tournament['players'] ?? []));
              $updatedAt = trim((string)($tournament['updated_at'] ?? ''));
            ?>
            <button type="button" class="tournament-card" data-open-tournament="<?= h($tourKey) ?>" data-game-type="<?= h($type) ?>">
              <div class="tournament-name"><?= h($label) ?></div>
              <div class="tournament-meta"><?= (int)$playerCount ?> players</div>
              <div class="tournament-updated">Updated <?= $updatedAt !== '' ? h(date('d M Y H:i', strtotime($updatedAt))) : '-' ?></div>
            </button>
          <?php endforeach; ?>
          <?php if (!$hasTournament): ?>
            <p class="admin-sub" style="margin:0;">Belum ada turnamen.</p>
          <?php endif; ?>
        </div>
        <?php
          $tourPrevPage = max(1, $currentTournamentPage - 1);
          $tourNextPage = min($totalTournamentPages, $currentTournamentPage + 1);
          $tourPageCandidates = [1, $totalTournamentPages, $currentTournamentPage - 1, $currentTournamentPage, $currentTournamentPage + 1];
          $tourVisiblePages = [];
          foreach ($tourPageCandidates as $candidate) {
              $candidate = (int)$candidate;
              if ($candidate < 1 || $candidate > $totalTournamentPages) {
                  continue;
              }
              if (!in_array($candidate, $tourVisiblePages, true)) {
                  $tourVisiblePages[] = $candidate;
              }
          }
          sort($tourVisiblePages, SORT_NUMERIC);
        ?>
        <div class="tour-pagination" aria-label="Tournament pagination">
          <?php if ($currentTournamentPage > 1): ?>
            <a class="tour-page-link" href="<?= h($buildTournamentPageUrl($tourPrevPage)) ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
          <?php else: ?>
            <span class="tour-page-link disabled" aria-hidden="true">&lsaquo;</span>
          <?php endif; ?>
          <?php $tourLastRenderedPage = 0; ?>
          <?php foreach ($tourVisiblePages as $pageNumber): ?>
            <?php if ($tourLastRenderedPage > 0 && $pageNumber - $tourLastRenderedPage > 1): ?>
              <span class="tour-page-dots">...</span>
            <?php endif; ?>
            <?php if ($pageNumber === $currentTournamentPage): ?>
              <span class="tour-page-link current"><?= (int)$pageNumber ?></span>
            <?php else: ?>
              <a class="tour-page-link" href="<?= h($buildTournamentPageUrl($pageNumber)) ?>"><?= (int)$pageNumber ?></a>
            <?php endif; ?>
            <?php $tourLastRenderedPage = $pageNumber; ?>
          <?php endforeach; ?>
          <?php if ($currentTournamentPage < $totalTournamentPages): ?>
            <a class="tour-page-link" href="<?= h($buildTournamentPageUrl($tourNextPage)) ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
          <?php else: ?>
            <span class="tour-page-link disabled" aria-hidden="true">&rsaquo;</span>
          <?php endif; ?>
        </div>

        <div class="tournament-modal" data-tournament-modal aria-hidden="true">
          <div class="tournament-modal-card" role="dialog" aria-modal="true" aria-labelledby="tournamentModalTitle">
            <div class="tournament-modal-head">
              <h3 class="tournament-modal-title" id="tournamentModalTitle" data-modal-title>Detail Tournament</h3>
              <button class="tournament-modal-close" type="button" data-modal-close aria-label="Tutup">&times;</button>
            </div>
            <div class="tournament-modal-body">
              <?php foreach ($tournamentList as $tournament): ?>
                <?php
                  $type = (string)($tournament['type'] ?? '');
                  $tourKey = (string)($tournament['key'] ?? '');
                  $typeGames = (array)($tournament['games'] ?? []);
                  $roundGroups = (array)($tournament['rounds'] ?? []);
                ?>
                <div data-tournament-panel="<?= h($tourKey) ?>" style="display:none;">
                <?php
                  $roundCompletedMap = [];
                  foreach ($roundGroups as $progressRoundNo => $progressRows) {
                      $roundCompletedMap[$progressRoundNo] = is_round_completed($progressRows);
                  }
                ?>
                <div class="type-head">
                  <h3 style="margin:0;"><?= h((string)($tournament['label'] ?? $type)) ?> <small style="font-weight:400;color:#5a6b86;"><?= h($type) ?> • <?= count($typeGames) ?> match</small></h3>
                </div>
                <?php if (!$typeGames): ?>
                  <p class="admin-sub" style="margin:0;">Belum ada match <?= h($type) ?>.</p>
                <?php else: ?>
                  <div class="tour-detail-grid">
                    <div class="standing-panel">
                      <p class="standing-panel-title">Toplist</p>
                      <div class="standing-wrap" style="margin-top:0;">
                        <table class="standing-table">
                          <thead>
                            <tr><th>#</th><th>Player</th><th>Diff</th><th>Total Point</th></tr>
                          </thead>
                          <tbody>
                            <?php $rows = (array)($tournament['standings'] ?? []); ?>
                            <?php if (!$rows): ?>
                              <tr><td colspan="4">Belum ada skor valid untuk klasemen <?= h($type) ?>.</td></tr>
                            <?php else: ?>
                              <?php foreach ($rows as $idx => $row): ?>
                                <tr<?= $idx === 0 ? ' style="background:#ffe44a;"' : '' ?>>
                                  <td><?= (int)($idx + 1) ?></td>
                                  <td><?= h((string)($row['name'] ?? '-')) ?></td>
                                  <td><?= (int)($row['point_diff'] ?? 0) ?></td>
                                  <td><strong><?= (int)($row['point_total'] ?? 0) ?></strong></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                    <div class="round-panel">
                      <div class="rounds-nav" data-round-nav>
                        <button type="button" class="rounds-nav-btn" data-round-prev aria-label="Round sebelumnya"><i class="bi bi-chevron-left"></i></button>
                        <div class="rounds-nav-label" data-round-label>Round 1</div>
                        <button type="button" class="rounds-nav-btn" data-round-next aria-label="Round selanjutnya"><i class="bi bi-chevron-right"></i></button>
                      </div>
                      <h4 class="round-panel-title" data-round-title-view>Round #1</h4>
                      <div class="rounds-scroll">
                        <div class="rounds-track" data-round-track>
                          <?php foreach ($roundGroups as $roundNo => $matches): ?>
                            <div class="round-column" data-round-col>
                              <div class="round-block">
                                <p class="round-title"><?= h($roundNo === 999999 ? 'Round Tanpa Nomor' : ('Round ' . $roundNo)) ?></p>
                            <?php
                              $isRoundUnlocked = true;
                              if ($roundNo !== 999999 && $roundNo > 1) {
                                  $prevRoundNo = $roundNo - 1;
                                  if (!($roundCompletedMap[$prevRoundNo] ?? false)) {
                                      $isRoundUnlocked = false;
                                  }
                              }
                            ?>
                            <?php foreach ($matches as $mIdx => $game): ?>
                              <?php
                                $sa = isset($game['score_a']) && $game['score_a'] !== null ? (int)$game['score_a'] : null;
                                $sb = isset($game['score_b']) && $game['score_b'] !== null ? (int)$game['score_b'] : null;
                                $st = ($sa !== null && $sb !== null) ? ($sa + $sb) : 0;
                                $configuredTotal = isset($game['match_total_points']) && $game['match_total_points'] !== null ? (int)$game['match_total_points'] : 0;
                                if (!in_array($configuredTotal, PADEL_ALLOWED_TOTAL_POINTS, true) && in_array($st, PADEL_ALLOWED_TOTAL_POINTS, true)) {
                                    $configuredTotal = $st;
                                }
                                $nameA = trim((string)($game['player_a_name'] ?? '')) ?: 'TBD';
                                $nameB = trim((string)($game['player_b_name'] ?? '')) ?: 'TBD';
                                $displayA = $nameA;
                                $displayB = $nameB;
                                if (strtoupper($displayA) === 'TBD') { $displayA = 'BYE'; }
                                if (strtoupper($displayB) === 'TBD') { $displayB = 'BYE'; }
                                $isLocked = ($displayA === 'BYE' || $displayB === 'BYE');
                                $isCompleted = ($sa !== null && $sb !== null && in_array(((int)$sa + (int)$sb), PADEL_ALLOWED_TOTAL_POINTS, true));
                                $isScoreInputDisabled = ($isLocked || $isCompleted || !$isRoundUnlocked);
                                if ($isLocked) {
                                    $sa = null;
                                    $sb = null;
                                    $st = 0;
                                }
                              ?>
                              <div class="match-item" data-match-item>
                                <div class="match-summary">
                                  <div class="match-meta">
                                    <?php $courtNoDisplay = (int)($game['court_no'] ?? 0); ?>
                                    <?php $sessionNoDisplay = (int)($game['session_no'] ?? 1); ?>
                                    <span class="match-caption"><?= h((string)($game['game_title'] ?? ('Match #' . ((int)($game['match_no'] ?? 0))))) ?> - Sesi <?= max(1, $sessionNoDisplay) ?> - Court <?= $courtNoDisplay > 0 ? $courtNoDisplay : (int)($game['match_no'] ?? 1) ?> - Total <?= $configuredTotal > 0 ? (int)$configuredTotal . ' poin' : 'belum set' ?></span>
                                  </div>
                                </div>
                                <div class="match-top-actions">
                                  <form method="post" class="score-strip" data-inline-score-form>
                                    <input type="hidden" name="competition_action" value="update_score">
                                    <input type="hidden" name="game_id" value="<?= (int)($game['id'] ?? 0) ?>">
                                    <input type="hidden" name="score_total" value="<?= $configuredTotal > 0 ? (int)$configuredTotal : 0 ?>" data-inline-total>
                                    <span class="score-side">Skor 1</span>
                                    <select name="score_a" class="score-box" data-inline-score-a <?= $isScoreInputDisabled ? 'disabled' : '' ?>>
                                      <?php for ($point = 0; $point <= max(0, $configuredTotal); $point++): ?>
                                        <option value="<?= $point ?>" <?= ((int)($sa ?? 0) === $point) ? 'selected' : '' ?>><?= str_pad((string)$point, 2, '0', STR_PAD_LEFT) ?></option>
                                      <?php endfor; ?>
                                    </select>
                                    <span class="score-vs-inline">vs</span>
                                    <select name="score_b" class="score-box" data-inline-score-b <?= $isScoreInputDisabled ? 'disabled' : '' ?>>
                                      <?php for ($point = 0; $point <= max(0, $configuredTotal); $point++): ?>
                                        <option value="<?= $point ?>" <?= ((int)($sb ?? 0) === $point) ? 'selected' : '' ?>><?= str_pad((string)$point, 2, '0', STR_PAD_LEFT) ?></option>
                                      <?php endfor; ?>
                                    </select>
                                    <span class="score-side">Skor 2</span>
                                    <button class="btn ghost small" type="submit" <?= $isScoreInputDisabled ? 'disabled' : '' ?>>Input Skor</button>
                                  </form>
                                </div>
                                <div class="match-box">
                                  <div class="seed-side">
                                    <div class="seed-line"><?= h($displayA) ?></div>
                                  </div>
                                  <div class="seed-vs">vs</div>
                                  <div class="seed-side">
                                    <div class="seed-line"><?= h($displayB) ?></div>
                                  </div>
                                </div>
                                <div class="live-status" data-inline-score-status aria-live="polite"></div>
                                <div class="match-actions">
                                  <form method="post" data-delete-game-form>
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
                    </div>
                  </div>
                <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="game-input-modal" data-game-input-modal aria-hidden="true">
          <div class="game-input-modal-card" role="dialog" aria-modal="true" aria-labelledby="gameInputModalTitle">
            <div class="game-input-modal-head">
              <h3 class="game-input-modal-title" id="gameInputModalTitle">Input Game</h3>
              <button class="game-input-modal-close" type="button" data-close-game-input-modal aria-label="Tutup">&times;</button>
            </div>
            <div class="game-input-modal-body">
              <form method="post" class="competition-form" data-create-match-form>
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
                <div>
                  <label for="matchTotalPoints">Total Poin Match</label>
                  <select id="matchTotalPoints" name="match_total_points" required>
                    <option value="">-- pilih total poin --</option>
                    <?php foreach (PADEL_ALLOWED_TOTAL_POINTS as $tp): ?>
                      <option value="<?= (int)$tp ?>"><?= (int)$tp ?> poin</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label for="courtCount">Jumlah Court Aktif</label>
                  <input id="courtCount" name="court_count" type="number" min="1" max="12" value="1" required>
                </div>
                <p class="note" id="courtEstimatorText">
                  Isi jumlah court untuk hitung ronde logika vs sesi eksekusi. Jika court kurang, sistem otomatis pecah jadi beberapa sesi per ronde.
                </p>
                <p class="note">Scoring: setiap pemain dapat poin sesuai skor timnya (tanpa bonus win/loss). Leaderboard diurutkan dari total poin, lalu selisih poin, lalu head-to-head. Americano pakai format default (single cycle). Mexicano tetap bertahap per ranking.</p>
                <button class="btn primary" type="submit"><i class="bi bi-diagram-3"></i> Generate Semua Match</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>
<script>
(function () {
  var activeTournamentKey = '';
  var roundIndexByTournament = {};
  var activeGameFilter = 'all';
  var isTournamentModalOpen = false;
  var toastStack = document.getElementById('toastStack');
  var acceptedPlayerCount = <?= (int)$registeredAttendeeCount ?>;
  var initialFlashSuccess = <?= json_encode((string)($flash['success'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var initialFlashError = <?= json_encode((string)($flash['error'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function getGameInputModal() {
    return document.querySelector('[data-game-input-modal]');
  }

  function setGameInputModalState(isOpen) {
    var gameInputModal = getGameInputModal();
    if (!gameInputModal) return;
    if (isOpen) {
      gameInputModal.classList.add('is-open');
      gameInputModal.setAttribute('aria-hidden', 'false');
      return;
    }
    gameInputModal.classList.remove('is-open');
    gameInputModal.setAttribute('aria-hidden', 'true');
  }

  function applyTournamentFilter(boardRoot, target) {
    var panels = boardRoot.querySelectorAll('[data-tournament-panel]');
    if (!panels.length) return;
    activeTournamentKey = target || activeTournamentKey || '';
    panels.forEach(function (panel) {
      var key = panel.getAttribute('data-tournament-panel') || '';
      panel.style.display = (activeTournamentKey !== '' && activeTournamentKey === key) ? '' : 'none';
    });
    boardRoot.querySelectorAll('[data-open-tournament]').forEach(function (card) {
      var cardKey = card.getAttribute('data-open-tournament') || '';
      card.classList.toggle('is-active', cardKey === activeTournamentKey);
    });
  }

  function setTournamentModalState(boardRoot, isOpen, key) {
    if (!boardRoot) return;
    var modal = boardRoot.querySelector('[data-tournament-modal]');
    if (!modal) return;
    if (isOpen) {
      if (key) {
        activeTournamentKey = key;
      }
      applyTournamentFilter(boardRoot, activeTournamentKey);
      var activePanel = boardRoot.querySelector('[data-tournament-panel="' + String(activeTournamentKey) + '"]');
      if (activePanel) {
        var savedRoundIndex = parseInt(roundIndexByTournament[activeTournamentKey], 10);
        if (!Number.isFinite(savedRoundIndex) || savedRoundIndex < 0) {
          savedRoundIndex = 0;
        }
        activePanel.setAttribute('data-round-index', String(savedRoundIndex));
        var resetEvent = new CustomEvent('round-navigate');
        activePanel.dispatchEvent(resetEvent);
      }
      var titleEl = boardRoot.querySelector('[data-modal-title]');
      if (titleEl) {
        var card = boardRoot.querySelector('[data-open-tournament="' + String(activeTournamentKey) + '"]');
        var cardTitleEl = card ? card.querySelector('.tournament-name') : null;
        var cardType = card ? (card.getAttribute('data-game-type') || '') : '';
        var cardTitle = cardTitleEl ? String(cardTitleEl.textContent || '').trim() : '';
        titleEl.textContent = cardTitle !== '' ? (cardTitle + (cardType !== '' ? ' - ' + cardType : '')) : 'Detail Tournament';
      }
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      isTournamentModalOpen = true;
      return;
    }
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    isTournamentModalOpen = false;
  }

  function refreshCompetitionBoard() {
    var url = new URL(window.location.pathname + window.location.search, window.location.origin);
    url.searchParams.set('_rt', String(Date.now()));
    return fetch(url.toString(), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
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
        if (isTournamentModalOpen && activeTournamentKey !== '') {
          setTournamentModalState(newBoard, true, activeTournamentKey);
        }
      });
  }

  function showToast(message, kind, durationMs) {
    if (!toastStack || !message) return;
    var tone = kind === 'error' ? 'error' : 'success';
    var item = document.createElement('div');
    item.className = 'toast-item ' + tone;
    item.innerHTML =
      '<i class="bi ' + (tone === 'error' ? 'bi-exclamation-triangle' : 'bi-check-circle') + '"></i>' +
      '<div class="toast-msg"></div>' +
      '<button type="button" class="toast-close" aria-label="Close">&times;</button>';
    var msgEl = item.querySelector('.toast-msg');
    if (msgEl) msgEl.textContent = String(message);
    var closeBtn = item.querySelector('.toast-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        if (item.parentNode) item.parentNode.removeChild(item);
      });
    }
    toastStack.appendChild(item);
    window.setTimeout(function () {
      if (item.parentNode) item.parentNode.removeChild(item);
    }, durationMs || 4500);
  }

  function postCompetitionForm(form) {
    return fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: new FormData(form),
      cache: 'no-store'
    }).then(function (response) {
      return response.json();
    });
  }

  function initBoardInteractions(boardRoot) {
    function applyGameFilter(filterValue) {
      activeGameFilter = filterValue || 'all';
      boardRoot.querySelectorAll('[data-game-filter-btn]').forEach(function (btn) {
        var btnValue = btn.getAttribute('data-game-filter-btn') || 'all';
        btn.classList.toggle('active', btnValue === activeGameFilter);
      });
      boardRoot.querySelectorAll('[data-game-type]').forEach(function (card) {
        var cardType = card.getAttribute('data-game-type') || '';
        card.style.display = (activeGameFilter === 'all' || activeGameFilter === cardType) ? '' : 'none';
      });
    }

    boardRoot.querySelectorAll('[data-game-filter-btn]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyGameFilter(btn.getAttribute('data-game-filter-btn') || 'all');
      });
    });
    applyGameFilter(activeGameFilter);

    function initRoundNavigator(panel) {
      if (!panel) return;
      var track = panel.querySelector('[data-round-track]');
      var nav = panel.querySelector('[data-round-nav]');
      if (!track || !nav) return;
      var panelType = panel.getAttribute('data-tournament-panel') || '';
      var cols = Array.prototype.slice.call(track.querySelectorAll('[data-round-col]'));
      var prevBtn = nav.querySelector('[data-round-prev]');
      var nextBtn = nav.querySelector('[data-round-next]');
      var label = nav.querySelector('[data-round-label]');
      var bigTitle = panel.querySelector('[data-round-title-view]');
      if (!cols.length) {
        nav.style.display = 'none';
        return;
      }
      if (cols.length <= 1) {
        nav.style.display = 'none';
      }
      var maxIndex = Math.max(0, cols.length - 1);
      var hasRoundAttr = panel.hasAttribute('data-round-index');
      var current = parseInt(panel.getAttribute('data-round-index') || '0', 10);
      if (!hasRoundAttr && panelType) {
        var saved = parseInt(roundIndexByTournament[panelType], 10);
        if (Number.isFinite(saved) && saved >= 0) {
          current = saved;
        }
      }
      if (!Number.isFinite(current) || current < 0) current = 0;
      if (current > maxIndex) current = maxIndex;

      function getRoundLabelText(col, fallbackIndex) {
        if (!col) return 'Round ' + String(fallbackIndex + 1);
        var titleEl = col.querySelector('.round-title');
        var t = titleEl ? String(titleEl.textContent || '').trim() : '';
        return t !== '' ? t : ('Round ' + String(fallbackIndex + 1));
      }

      function update() {
        panel.setAttribute('data-round-index', String(current));
        if (panelType) {
          roundIndexByTournament[panelType] = current;
        }
        track.style.transform = 'translateX(' + String(-100 * current) + '%)';
        if (label) {
          label.textContent = getRoundLabelText(cols[current], current) + ' (' + String(current + 1) + '/' + String(cols.length) + ')';
        }
        if (bigTitle) {
          var plainRound = getRoundLabelText(cols[current], current).replace(/\s+/g, ' ').trim();
          bigTitle.textContent = plainRound.indexOf('Round ') === 0 ? plainRound.replace('Round ', 'Round #') : plainRound;
        }
        if (prevBtn) prevBtn.disabled = current <= 0;
        if (nextBtn) nextBtn.disabled = current >= maxIndex;
      }

      if (prevBtn) {
        prevBtn.addEventListener('click', function () {
          if (current <= 0) return;
          current--;
          update();
        });
      }
      if (nextBtn) {
        nextBtn.addEventListener('click', function () {
          if (current >= maxIndex) return;
          current++;
          update();
        });
      }
      panel.addEventListener('round-navigate', function () {
        current = parseInt(panel.getAttribute('data-round-index') || '0', 10);
        if (!Number.isFinite(current) || current < 0) current = 0;
        if (current > maxIndex) current = maxIndex;
        update();
      });
      update();
    }

    boardRoot.querySelectorAll('[data-open-tournament]').forEach(function (card) {
      card.addEventListener('click', function () {
        var key = card.getAttribute('data-open-tournament') || '';
        if (!key) return;
        setTournamentModalState(boardRoot, true, key);
      });
    });
    var modal = boardRoot.querySelector('[data-tournament-modal]');
    if (modal) {
      modal.addEventListener('click', function (ev) {
        if (ev.target === modal) {
          setTournamentModalState(boardRoot, false);
        }
      });
      modal.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setTournamentModalState(boardRoot, false);
        });
      });
    }

    boardRoot.querySelectorAll('[data-tournament-panel]').forEach(function (panel) {
      initRoundNavigator(panel);
    });

    boardRoot.querySelectorAll('.standing-wrap').forEach(function (wrap) {
      var isDown = false;
      var startX = 0;
      var startScrollLeft = 0;

      wrap.addEventListener('mousedown', function (ev) {
        // Left-click drag only.
        if (ev.button !== 0) return;
        isDown = true;
        startX = ev.pageX;
        startScrollLeft = wrap.scrollLeft;
        wrap.classList.add('is-dragging');
      });
      wrap.addEventListener('mousemove', function (ev) {
        if (!isDown) return;
        ev.preventDefault();
        var delta = ev.pageX - startX;
        wrap.scrollLeft = startScrollLeft - delta;
      });
      function stopDrag() {
        isDown = false;
        wrap.classList.remove('is-dragging');
      }
      wrap.addEventListener('mouseup', stopDrag);
      wrap.addEventListener('mouseleave', stopDrag);
    });

    function parseInlineScore(selectEl) {
      if (!selectEl) return null;
      var n = parseInt(selectEl.value || '', 10);
      return Number.isFinite(n) ? n : null;
    }

    function setInlineScoreStatus(form, message, kind) {
      if (!form) return;
      var statusEl = form.closest('[data-match-item]') ? form.closest('[data-match-item]').querySelector('[data-inline-score-status]') : null;
      if (!statusEl) return;
      statusEl.textContent = message || '';
      statusEl.classList.remove('ok', 'error');
      if (kind) statusEl.classList.add(kind);
    }

    function syncInlineScore(form, side) {
      if (!form) return;
      var totalEl = form.querySelector('[data-inline-total]');
      var aEl = form.querySelector('[data-inline-score-a]');
      var bEl = form.querySelector('[data-inline-score-b]');
      if (!totalEl || !aEl || !bEl) return;
      var total = parseInt(totalEl.value || '', 10);
      if (!Number.isFinite(total) || total < 0) return;
      var a = parseInlineScore(aEl);
      var b = parseInlineScore(bEl);
      if (side === 'a' && a !== null) {
        bEl.value = String(Math.max(0, total - a));
      } else if (side === 'b' && b !== null) {
        aEl.value = String(Math.max(0, total - b));
      }
    }

    boardRoot.querySelectorAll('[data-inline-score-form]').forEach(function (form) {
      var aEl = form.querySelector('[data-inline-score-a]');
      var bEl = form.querySelector('[data-inline-score-b]');
      if (aEl) {
        aEl.addEventListener('change', function () { syncInlineScore(form, 'a'); });
        aEl.addEventListener('input', function () { syncInlineScore(form, 'a'); });
      }
      if (bEl) {
        bEl.addEventListener('change', function () { syncInlineScore(form, 'b'); });
        bEl.addEventListener('input', function () { syncInlineScore(form, 'b'); });
      }

      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var totalEl = form.querySelector('[data-inline-total]');
        var total = totalEl ? parseInt(totalEl.value || '', 10) : NaN;
        var a = parseInlineScore(aEl);
        var b = parseInlineScore(bEl);
        if (!Number.isFinite(total) || total <= 0) {
          setInlineScoreStatus(form, 'Total poin tidak valid.', 'error');
          return;
        }
        if (a === null || b === null) {
          setInlineScoreStatus(form, 'Skor harus diisi lengkap.', 'error');
          return;
        }
        if ((a + b) !== total) {
          setInlineScoreStatus(form, 'Total skor harus sama dengan total poin (' + total + ').', 'error');
          return;
        }

        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        setInlineScoreStatus(form, 'Menyimpan...', null);
        postCompetitionForm(form)
          .then(function (data) {
            if (!data || !data.ok) {
              throw new Error((data && data.message) ? data.message : 'Gagal menyimpan skor.');
            }
            showToast(data.message || 'Skor berhasil disimpan.', 'success', 4500);
            setInlineScoreStatus(form, 'Tersimpan.', 'ok');
            return refreshCompetitionBoard();
          })
          .catch(function (error) {
            var msg = error && error.message ? error.message : 'Terjadi kesalahan saat simpan.';
            setInlineScoreStatus(form, msg, 'error');
            showToast(msg, 'error', 5000);
          })
          .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
          });
      });
    });

    boardRoot.querySelectorAll('[data-delete-game-form]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!window.confirm('Hapus game ini?')) return;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        postCompetitionForm(form)
          .then(function (data) {
            if (!data || !data.ok) {
              throw new Error((data && data.message) ? data.message : 'Gagal menghapus game.');
            }
            showToast(data.message || 'Game berhasil dihapus.', 'success', 4500);
            return refreshCompetitionBoard();
          })
          .catch(function (error) {
            showToast(error && error.message ? error.message : 'Terjadi kesalahan saat menghapus game.', 'error', 5000);
          })
          .finally(function () {
            if (btn) btn.disabled = false;
          });
      });
    });
  }

  var board = document.querySelector('[data-live-board]');
  if (board) {
    initBoardInteractions(board);
  }

  function updateCourtEstimator() {
    var createForm = document.querySelector('[data-create-match-form]');
    if (!createForm) return;
    var infoEl = createForm.querySelector('#courtEstimatorText');
    var typeEl = createForm.querySelector('[name=\"competition_type\"]');
    var courtEl = createForm.querySelector('[name=\"court_count\"]');
    if (!infoEl || !typeEl || !courtEl) return;

    var type = String(typeEl.value || '');
    var courts = parseInt(courtEl.value || '1', 10);
    if (!Number.isFinite(courts) || courts < 1) courts = 1;
    if (courts > 12) courts = 12;
    var maxMatchesPerLogicalRound = Math.floor(acceptedPlayerCount / 4);
    if (maxMatchesPerLogicalRound <= 0) {
      infoEl.textContent = 'Attendee belum cukup. Minimal 4 pemain untuk 1 match.';
      return;
    }
    if (courts > maxMatchesPerLogicalRound) {
      courts = maxMatchesPerLogicalRound;
    }
    var wavesPerLogicalRound = Math.ceil(maxMatchesPerLogicalRound / Math.max(1, courts));
    if (type === 'Americano') {
      var logicalRounds = Math.max(1, acceptedPlayerCount - 1);
      var estimatedSlots = logicalRounds * wavesPerLogicalRound;
      infoEl.textContent = 'Americano: ' + acceptedPlayerCount + ' pemain, ' + courts + ' court aktif, ' + logicalRounds + ' ronde logika, ' + wavesPerLogicalRound + ' sesi/ronde, total ' + estimatedSlots + ' sesi.';
      return;
    }
    if (type === 'Mexicano') {
      var recommendedRounds = 6;
      var estimatedMexSlots = recommendedRounds * wavesPerLogicalRound;
      infoEl.textContent = 'Mexicano: pairing dinamis berdasar ranking tiap ronde. Dengan ' + courts + ' court aktif, estimasi ' + wavesPerLogicalRound + ' wave/ronde, rekomendasi awal ' + recommendedRounds + ' ronde (' + estimatedMexSlots + ' slot ronde).';
      return;
    }
    infoEl.textContent = 'Pilih tipe game dulu untuk lihat estimasi ronde dari jumlah court.';
  }

  document.addEventListener('click', function (ev) {
    var openBtn = ev.target && ev.target.closest ? ev.target.closest('[data-open-game-input-modal]') : null;
    if (openBtn) {
      setGameInputModalState(true);
      updateCourtEstimator();
      return;
    }

    var closeBtn = ev.target && ev.target.closest ? ev.target.closest('[data-close-game-input-modal]') : null;
    if (closeBtn) {
      setGameInputModalState(false);
      return;
    }

    var gameInputModal = getGameInputModal();
    if (gameInputModal && ev.target === gameInputModal) {
      setGameInputModalState(false);
    }
  });

  document.addEventListener('change', function (ev) {
    var target = ev.target;
    if (!target || !target.matches) return;
    if (target.matches('[data-create-match-form] [name="competition_type"]') || target.matches('[data-create-match-form] [name="court_count"]')) {
      updateCourtEstimator();
    }
  });
  document.addEventListener('input', function (ev) {
    var target = ev.target;
    if (!target || !target.matches) return;
    if (target.matches('[data-create-match-form] [name="court_count"]')) {
      updateCourtEstimator();
    }
  });
  updateCourtEstimator();

  document.addEventListener('submit', function (ev) {
    var createForm = ev.target && ev.target.closest ? ev.target.closest('[data-create-match-form]') : null;
    if (!createForm) return;
    ev.preventDefault();
    var btn = createForm.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    postCompetitionForm(createForm)
      .then(function (data) {
        if (!data || !data.ok) {
          throw new Error((data && data.message) ? data.message : 'Gagal generate match.');
        }
        showToast(data.message || 'Match berhasil digenerate.', 'success', 5000);
        setGameInputModalState(false);
        return refreshCompetitionBoard();
      })
      .catch(function (error) {
        showToast(error && error.message ? error.message : 'Terjadi kesalahan saat generate match.', 'error', 5500);
      })
      .finally(function () {
        if (btn) btn.disabled = false;
      });
  });

  if (initialFlashSuccess) {
    showToast(initialFlashSuccess, 'success', 5000);
  } else if (initialFlashError) {
    showToast(initialFlashError, 'error', 5500);
  }
})();
</script>
<?php render_footer(['isAdmin' => true]); ?>

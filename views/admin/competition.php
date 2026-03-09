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
    $maxMatchesPerLogicalRound = ($type === 'Mexicano')
        ? (int)ceil($playerCount / 4)
        : intdiv($playerCount, 4);
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
    $label = preg_replace('/\s*(?:-|)?\s*(?:R\d+\s*M\d+|Match\s*#\d+)\s*$/i', '', trim($title));
    $label = trim((string)$label);
    return $label !== '' ? $label : $type;
}

function is_auto_competition_title(string $type, string $title): bool {
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    return (bool)preg_match('/^' . preg_quote($type, '/') . '\s*(R\d+\s*M\d+|-\s*Match\s*#\d+)$/i', $title);
}

function competition_ts(string $value): int {
    $ts = strtotime(trim($value));
    return $ts ? (int)$ts : 0;
}

function build_tournament_state_key(string $type, string $label): string {
    return strtolower(trim($type) . '|' . trim($label));
}

function fetch_tournament_state_map(PDO $db): array {
    $map = [];
    try {
        $stmt = $db->query(
            "SELECT competition_type, tournament_label, is_completed, completed_at, winner_name, winner_point_total, winner_point_diff
             FROM competition_tournament_states"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $row) {
        $type = normalize_competition_type((string)($row['competition_type'] ?? ''));
        $label = trim((string)($row['tournament_label'] ?? ''));
        if ($type === '' || $label === '') {
            continue;
        }
        $key = build_tournament_state_key($type, $label);
        $map[$key] = [
            'is_completed' => (int)($row['is_completed'] ?? 0) === 1,
            'completed_at' => trim((string)($row['completed_at'] ?? '')),
            'winner_name' => trim((string)($row['winner_name'] ?? '')),
            'winner_point_total' => (int)($row['winner_point_total'] ?? 0),
            'winner_point_diff' => (int)($row['winner_point_diff'] ?? 0),
        ];
    }
    return $map;
}

function is_tournament_completed(PDO $db, string $type, string $label): bool {
    if ($type === '' || $label === '') {
        return false;
    }
    try {
        $stmt = $db->prepare(
            "SELECT is_completed
             FROM competition_tournament_states
             WHERE competition_type = ? AND tournament_label = ?
             LIMIT 1"
        );
        $stmt->execute([$type, $label]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
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

function build_player_ranking_stats(PDO $db, string $competitionType, string $competitionLabel = ''): array {
    $stats = [];
    $competitionLabel = trim($competitionLabel);
    try {
        $stmt = $db->prepare(
            "SELECT game_title, player_a_name, player_b_name, score_a, score_b
             FROM competition_games
             WHERE competition_type = ?"
        );
        $stmt->execute([$competitionType]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($competitionLabel !== '') {
                $rowLabel = build_competition_label_from_title($competitionType, (string)($row['game_title'] ?? ''));
                if (strcasecmp($rowLabel, $competitionLabel) !== 0) {
                    continue;
                }
            }
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

function filter_rows_by_competition_label(array $rows, string $type, string $label): array {
    $label = trim($label);
    if ($label === '') {
        return $rows;
    }
    $filtered = [];
    foreach ($rows as $row) {
        $rowLabel = build_competition_label_from_title($type, (string)($row['game_title'] ?? ''));
        if (strcasecmp($rowLabel, $label) === 0) {
            $filtered[] = $row;
        }
    }
    return $filtered;
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
    $matchPerRound = intdiv(count($players), 4);
    if ($matchPerRound <= 0) {
        return [];
    }
    $sesiPerRound = (int)ceil($matchPerRound / max(1, $courtCount));
    $executionRoundNo = 0;
    $logicalRoundNo = 0;

    foreach ($partnerRounds as $partnerPairs) {
        $logicalRoundNo++;
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
        $roundMatches = [];
        for ($i = 0; $i < count($teams); $i += 2) {
            $a = (string)($teams[$i] ?? '');
            $b = (string)($teams[$i + 1] ?? '');
            if ($a === '' || $b === '') {
                continue;
            }
            $roundMatches[] = [
                'player_a_name' => $a,
                'player_b_name' => $b,
            ];
        }

        $chunks = array_chunk($roundMatches, $courtCount);
        foreach ($chunks as $sessionChunk) {
            $executionRoundNo++;
            $playingMembers = [];
            foreach ($sessionChunk as $m) {
                foreach (split_team_members((string)($m['player_a_name'] ?? '')) as $name) {
                    $playingMembers[$name] = true;
                }
                foreach (split_team_members((string)($m['player_b_name'] ?? '')) as $name) {
                    $playingMembers[$name] = true;
                }
            }
            $bye = [];
            foreach ($players as $p) {
                $name = trim((string)$p);
                if ($name === '' || isset($playingMembers[$name])) {
                    continue;
                }
                $bye[] = $name;
            }
            foreach ($sessionChunk as $idx => $m) {
                $courtNo = $idx + 1;
                $allMatches[] = [
                    'round_no' => $executionRoundNo,
                    'session_no' => $logicalRoundNo,
                    'match_no' => $courtNo,
                    'court_no' => $courtNo,
                    'player_a_name' => (string)($m['player_a_name'] ?? ''),
                    'player_b_name' => (string)($m['player_b_name'] ?? ''),
                    'notes' => 'logical_round=' . $logicalRoundNo . ';bye:' . implode(', ', $bye),
                ];
            }
        }
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
        'total_sesi' => $executionRoundNo,
        'pairing_tracker' => $pairingTracker,
        'matches' => $allMatches,
    ];
}

function build_standings_from_games(array $gamesRows, array $seedPlayers = []): array {
    $table = [];
    foreach ($seedPlayers as $seedNameRaw) {
        $seedName = trim((string)$seedNameRaw);
        if ($seedName === '') {
            continue;
        }
        if (!isset($table[$seedName])) {
            $table[$seedName] = [
                'name' => $seedName,
                'point_total' => 0,
                'point_diff' => 0,
                'played' => 0,
                'win' => 0,
                'loss' => 0,
                'tie' => 0,
            ];
        }
    }
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
                $table[$name] = [
                    'name' => $name,
                    'point_total' => 0,
                    'point_diff' => 0,
                    'played' => 0,
                    'win' => 0,
                    'loss' => 0,
                    'tie' => 0,
                ];
            }
            $table[$name]['point_total'] += $sa;
            $table[$name]['point_diff'] += ($sa - $sb);
            $table[$name]['played']++;
            if ($sa > $sb) {
                $table[$name]['win']++;
            } elseif ($sa < $sb) {
                $table[$name]['loss']++;
            } else {
                $table[$name]['tie']++;
            }
        }
        foreach ($membersB as $name) {
            if (!isset($table[$name])) {
                $table[$name] = [
                    'name' => $name,
                    'point_total' => 0,
                    'point_diff' => 0,
                    'played' => 0,
                    'win' => 0,
                    'loss' => 0,
                    'tie' => 0,
                ];
            }
            $table[$name]['point_total'] += $sb;
            $table[$name]['point_diff'] += ($sb - $sa);
            $table[$name]['played']++;
            if ($sb > $sa) {
                $table[$name]['win']++;
            } elseif ($sb < $sa) {
                $table[$name]['loss']++;
            } else {
                $table[$name]['tie']++;
            }
        }
    }

    $rows = array_values($table);
    usort($rows, static function (array $x, array $y): int {
        if ((int)$x['point_total'] !== (int)$y['point_total']) return (int)$y['point_total'] <=> (int)$x['point_total'];
        if ((int)$x['point_diff'] !== (int)$y['point_diff']) return (int)$y['point_diff'] <=> (int)$x['point_diff'];
        if ((int)$x['win'] !== (int)$y['win']) return (int)$y['win'] <=> (int)$x['win'];
        return strcmp((string)$x['name'], (string)$y['name']);
    });

    return $rows;
}

function sort_mexicano_members_by_ranking(array $members, array $rankingStats): array {
    $members = array_values(array_unique(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $members), static function (string $name): bool {
        return $name !== '';
    })));
    usort($members, static function (string $x, string $y) use ($rankingStats): int {
        $px = (int)($rankingStats[$x]['point_total'] ?? 0);
        $py = (int)($rankingStats[$y]['point_total'] ?? 0);
        if ($px !== $py) {
            return $py <=> $px;
        }
        $dx = (int)($rankingStats[$x]['point_diff'] ?? 0);
        $dy = (int)($rankingStats[$y]['point_diff'] ?? 0);
        if ($dx !== $dy) {
            return $dy <=> $dx;
        }
        return strcmp($x, $y);
    });
    return $members;
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

function mexicano_block_round_span(int $memberCount, int $courtCount): int {
    $courtCount = normalize_court_count($courtCount);
    $memberCount = max(0, $memberCount);
    if ($memberCount < 4) {
        return 1;
    }
    // One Mexicano block should cover all players at least once.
    $matchesPerBlock = (int)ceil($memberCount / 4);
    if ($matchesPerBlock <= 0) {
        return 1;
    }
    return max(1, (int)ceil($matchesPerBlock / max(1, $courtCount)));
}

function are_rounds_completed(PDO $db, string $type, int $startRound, int $endRound, string $label = ''): bool {
    if ($startRound <= 0 || $endRound < $startRound) {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT game_title, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
    );
    for ($round = $startRound; $round <= $endRound; $round++) {
        $stmt->execute([$type, $round]);
        $rows = filter_rows_by_competition_label($stmt->fetchAll(PDO::FETCH_ASSOC), $type, $label);
        if (!$rows || !is_round_completed($rows)) {
            return false;
        }
    }
    return true;
}

function filter_mexicano_games_for_completed_blocks(array $gamesRows, array $seedPlayers): array {
    if (!$gamesRows) {
        return [];
    }
    $roundRows = [];
    $courtCount = 1;
    foreach ($gamesRows as $row) {
        $roundNo = (int)($row['round_no'] ?? 0);
        if ($roundNo <= 0) {
            continue;
        }
        if (!isset($roundRows[$roundNo])) {
            $roundRows[$roundNo] = [];
        }
        $roundRows[$roundNo][] = $row;
        $candidateCourtCount = isset($row['court_count']) && $row['court_count'] !== null
            ? (int)$row['court_count']
            : 0;
        if ($candidateCourtCount > 0) {
            $courtCount = normalize_court_count($candidateCourtCount);
        }
    }
    if (!$roundRows) {
        return [];
    }
    ksort($roundRows, SORT_NUMERIC);
    $blockSpan = mexicano_block_round_span(count($seedPlayers), $courtCount);
    $lastSettledRound = 0;
    $roundNumbers = array_keys($roundRows);
    $maxRound = (int)max($roundNumbers);

    for ($blockEnd = $blockSpan; $blockEnd <= $maxRound; $blockEnd += $blockSpan) {
        $blockStart = $blockEnd - $blockSpan + 1;
        $completed = true;
        for ($r = $blockStart; $r <= $blockEnd; $r++) {
            $rows = (array)($roundRows[$r] ?? []);
            if (!$rows || !is_round_completed($rows)) {
                $completed = false;
                break;
            }
        }
        if (!$completed) {
            break;
        }
        $lastSettledRound = $blockEnd;
    }

    if ($lastSettledRound <= 0) {
        return [];
    }

    $filtered = [];
    foreach ($gamesRows as $row) {
        $roundNo = (int)($row['round_no'] ?? 0);
        if ($roundNo > 0 && $roundNo <= $lastSettledRound) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

function filter_games_with_valid_scores(array $gamesRows): array {
    $filtered = [];
    foreach ($gamesRows as $row) {
        if (has_valid_game_score($row)) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

function build_session_bye_notes(
    array $pairs,
    int $courtCount,
    array $members,
    string $baseNotes = 'mode_cycle=single',
    int $baseRoundNo = 1,
    bool $spreadByRound = false
): array {
    $courtCount = normalize_court_count($courtCount);
    $members = array_values(array_unique(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $members), static function (string $name): bool {
        return $name !== '';
    })));

    $rows = [];
    foreach ($pairs as $idx => $pair) {
        if ($spreadByRound) {
            $roundNo = $baseRoundNo + (int)floor($idx / $courtCount);
            $matchNo = (($idx % $courtCount) + 1);
            $sessionNo = 1;
            $courtNo = $matchNo;
        } else {
            $roundNo = null;
            $matchNo = $idx + 1;
            $sessionNo = (int)floor(($matchNo - 1) / $courtCount) + 1;
            $courtNo = (($idx % $courtCount) + 1);
        }
        $rows[] = [
            'round_no' => $roundNo,
            'match_no' => $matchNo,
            'session_no' => $sessionNo,
            'court_no' => $courtNo,
            'player_a_name' => (string)($pair[0] ?? ''),
            'player_b_name' => (string)($pair[1] ?? ''),
            'notes' => $baseNotes,
        ];
    }
    if (!$rows || !$members) {
        return $rows;
    }

    $sessionPlayers = [];
    foreach ($rows as $row) {
        $groupKey = $spreadByRound
            ? ('r' . (int)($row['round_no'] ?? 0))
            : ('s' . (int)($row['session_no'] ?? 1));
        if (!isset($sessionPlayers[$groupKey])) {
            $sessionPlayers[$groupKey] = [];
        }
        foreach (split_team_members((string)($row['player_a_name'] ?? '')) as $name) {
            $sessionPlayers[$groupKey][$name] = true;
        }
        foreach (split_team_members((string)($row['player_b_name'] ?? '')) as $name) {
            $sessionPlayers[$groupKey][$name] = true;
        }
    }

    foreach ($rows as $idx => $row) {
        $groupKey = $spreadByRound
            ? ('r' . (int)($row['round_no'] ?? 0))
            : ('s' . (int)($row['session_no'] ?? 1));
        $playingMembers = $sessionPlayers[$groupKey] ?? [];
        $bye = [];
        foreach ($members as $name) {
            if (!isset($playingMembers[$name])) {
                $bye[] = $name;
            }
        }
        $notes = trim((string)($row['notes'] ?? $baseNotes));
        $notes = preg_replace('/(?:^|;)bye:\s*[^;]*/i', '', $notes ?? '');
        $notes = trim((string)$notes, '; ');
        if ($bye) {
            $byeNote = 'bye:' . implode(', ', $bye);
            $notes = $notes !== '' ? ($notes . ';' . $byeNote) : $byeNote;
        }
        $rows[$idx]['notes'] = $notes !== '' ? $notes : $baseNotes;
    }

    return $rows;
}

function is_previous_round_completed(PDO $db, string $type, int $roundNo, string $label = ''): bool {
    if ($roundNo <= 1) {
        return true;
    }
    $prevRound = $roundNo - 1;
    $stmt = $db->prepare(
        "SELECT game_title, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
    );
    $stmt->execute([$type, $prevRound]);
    $rows = filter_rows_by_competition_label($stmt->fetchAll(PDO::FETCH_ASSOC), $type, $label);
    return is_round_completed($rows);
}

function extract_bye_members_from_notes(string $notes): array {
    $notes = trim($notes);
    if ($notes === '') {
        return [];
    }
    if (!preg_match('/(?:^|;)bye:\s*([^;]+)/i', $notes, $m)) {
        return [];
    }
    $raw = trim((string)($m[1] ?? ''));
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $members = [];
    foreach ($parts as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $members[] = $name;
        }
    }
    return array_values(array_unique($members));
}

function build_mexicano_pairs_for_round(array $orderedMembers, int $courtCount): array {
    $courtCount = normalize_court_count($courtCount);
    $orderedMembers = array_values(array_unique(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $orderedMembers), static function (string $name): bool {
        return $name !== '';
    })));
    if (count($orderedMembers) < 4) {
        return [];
    }
    $matchesPerBlock = (int)ceil(count($orderedMembers) / 4);
    if ($matchesPerBlock <= 0) {
        return [];
    }
    $slotsNeeded = $matchesPerBlock * 4;
    $active = [];
    $memberCount = count($orderedMembers);
    for ($i = 0; $i < $slotsNeeded; $i++) {
        $active[] = (string)$orderedMembers[$i % $memberCount];
    }
    $pairs = [];
    for ($i = 0; $i < count($active); $i += 4) {
        $chunk = array_slice($active, $i, 4);
        if (count($chunk) < 4) {
            continue;
        }
        // Mexicano format: seed 1&4 vs 2&3.
        $pairs[] = [
            $chunk[0] . ' & ' . $chunk[3],
            $chunk[1] . ' & ' . $chunk[2],
        ];
    }
    return $pairs;
}

function fetch_competition_member_stats(PDO $db, string $type, string $label): array {
    $members = [];
    $played = [];
    try {
        $stmt = $db->prepare(
            "SELECT game_title, player_a_name, player_b_name, score_a, score_b, notes
             FROM competition_games
             WHERE competition_type = ?"
        );
        $stmt->execute([$type]);
        $rows = filter_rows_by_competition_label($stmt->fetchAll(PDO::FETCH_ASSOC), $type, $label);
        foreach ($rows as $row) {
            $roundMembers = array_merge(
                split_team_members((string)($row['player_a_name'] ?? '')),
                split_team_members((string)($row['player_b_name'] ?? '')),
                extract_bye_members_from_notes((string)($row['notes'] ?? ''))
            );
            foreach ($roundMembers as $nameRaw) {
                $name = trim((string)$nameRaw);
                if ($name === '') {
                    continue;
                }
                $members[$name] = true;
            }
            if (has_valid_game_score($row)) {
                foreach (split_team_members((string)($row['player_a_name'] ?? '')) as $nameRaw) {
                    $name = trim((string)$nameRaw);
                    if ($name === '') {
                        continue;
                    }
                    $played[$name] = (int)($played[$name] ?? 0) + 1;
                }
                foreach (split_team_members((string)($row['player_b_name'] ?? '')) as $nameRaw) {
                    $name = trim((string)$nameRaw);
                    if ($name === '') {
                        continue;
                    }
                    $played[$name] = (int)($played[$name] ?? 0) + 1;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return [
        'members' => array_keys($members),
        'played' => $played,
    ];
}

function select_mexicano_active_players(array $members, array $playedCounts, int $courtCount, array $rankingStats = []): array {
    $courtCount = normalize_court_count($courtCount);
    $members = array_values(array_unique(array_filter(array_map(static function ($name): string {
        return trim((string)$name);
    }, $members), static function (string $name): bool {
        return $name !== '';
    })));
    if (count($members) < 4) {
        return ['players' => [], 'mode' => 'ranking'];
    }
    usort($members, static function (string $x, string $y) use ($playedCounts, $rankingStats): int {
        $playedX = (int)($playedCounts[$x] ?? 0);
        $playedY = (int)($playedCounts[$y] ?? 0);
        if ($playedX !== $playedY) {
            // Ensure players with fewer played matches are scheduled first.
            return $playedX <=> $playedY;
        }
        $px = (int)($rankingStats[$x]['point_total'] ?? 0);
        $py = (int)($rankingStats[$y]['point_total'] ?? 0);
        if ($px !== $py) {
            return $py <=> $px;
        }
        $dx = (int)($rankingStats[$x]['point_diff'] ?? 0);
        $dy = (int)($rankingStats[$y]['point_diff'] ?? 0);
        if ($dx !== $dy) {
            return $dy <=> $dx;
        }
        return strcmp($x, $y);
    });
    return ['players' => $members, 'mode' => 'played_then_ranking'];
}

function sync_next_round_from_round(PDO $db, string $type, int $sourceRound, int $adminId, string $label = ''): array {
    if ($sourceRound <= 0) {
        return [0, 0];
    }
    if ($type === 'Mexicano' && is_tournament_completed($db, $type, $label)) {
        return [0, 0];
    }

    $fetchSource = $db->prepare(
        "SELECT id, game_title, session_no, match_no, court_no, court_count, player_a_name, player_b_name, score_a, score_b, match_total_points, notes
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
    );
    $fetchSource->execute([$type, $sourceRound]);
    $rows = filter_rows_by_competition_label($fetchSource->fetchAll(PDO::FETCH_ASSOC), $type, $label);
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

    $baseTitle = trim((string)($rows[0]['game_title'] ?? ''));
    $label = trim($label) !== '' ? trim($label) : build_competition_label_from_title($type, $baseTitle);

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
        $memberStats = fetch_competition_member_stats($db, 'Mexicano', $label);
        $memberPool = array_values((array)($memberStats['members'] ?? []));
        $playedCounts = (array)($memberStats['played'] ?? []);
        $ranking = build_player_ranking_stats($db, 'Mexicano', $label);
        if (count($memberPool) < 4) {
            return [0, 0];
        }
        $blockSpan = mexicano_block_round_span(count($memberPool), $configuredCourtCount);
        if (($sourceRound % $blockSpan) !== 0) {
            return [0, 0];
        }
        $blockStart = $sourceRound - $blockSpan + 1;
        if (!are_rounds_completed($db, $type, $blockStart, $sourceRound, $label)) {
            return [0, 0];
        }
        $selection = select_mexicano_active_players($memberPool, $playedCounts, $configuredCourtCount, $ranking);
        $activePlayers = array_values((array)($selection['players'] ?? []));
        if (count($activePlayers) < 4) {
            return [0, 0];
        }
        $pairs = build_mexicano_pairs_for_round($activePlayers, $configuredCourtCount);
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
    $insert = $db->prepare(
        "INSERT INTO competition_games (
            package_id, competition_type, game_title, round_no, session_no, match_no, court_no, court_count, match_total_points,
            player_a_user_id, player_a_name, player_b_user_id, player_b_name,
            score_a, score_b, game_date, notes, created_by_admin_id, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $memberUniverse = [];
    if ($type === 'Mexicano') {
        $memberStats = fetch_competition_member_stats($db, 'Mexicano', $label);
        $memberUniverse = array_values((array)($memberStats['members'] ?? []));
    } else {
        foreach ($allTeams as $teamName) {
            foreach (split_team_members($teamName) as $member) {
                $memberUniverse[] = $member;
            }
        }
        $memberUniverse = array_values(array_unique($memberUniverse));
    }

    if ($type === 'Mexicano') {
        // Freeze a full Mexicano batch from one ranking snapshot.
        // With 1 court this becomes 1 match per round; with N courts it becomes N matches per round.
        $nextRoundExistsStmt = $db->prepare(
            "SELECT id, game_title
             FROM competition_games
             WHERE competition_type = ? AND round_no = ?
             ORDER BY id ASC"
        );
        $nextRoundExistsStmt->execute([$type, $nextRound]);
        $nextRoundRows = filter_rows_by_competition_label($nextRoundExistsStmt->fetchAll(PDO::FETCH_ASSOC), $type, $label);
        if ($nextRoundRows) {
            return [0, 0];
        }

        $scheduledRows = build_session_bye_notes(
            $pairs,
            $configuredCourtCount,
            $memberUniverse,
            'mode_cycle=single',
            $nextRound,
            true
        );
        $created = 0;
        foreach ($scheduledRows as $idx => $slot) {
            $roundNo = (int)($slot['round_no'] ?? ($nextRound + (int)floor($idx / max(1, $configuredCourtCount))));
            $matchNo = (int)($slot['match_no'] ?? (($idx % max(1, $configuredCourtCount)) + 1));
            $sessionNo = (int)($slot['session_no'] ?? 1);
            $courtNo = (int)($slot['court_no'] ?? $matchNo);
            $title = $label . ' - R' . $roundNo . ' M' . $matchNo;
            $a = (string)($slot['player_a_name'] ?? '');
            $b = (string)($slot['player_b_name'] ?? '');
            $notes = (string)($slot['notes'] ?? 'mode_cycle=single');

            $insert->execute([
                null,
                $type,
                $title,
                $roundNo,
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
                $notes,
                $adminId > 0 ? $adminId : null,
                date('Y-m-d H:i:s'),
            ]);
            $created++;
        }
        return [$created, 0];
    }

    $scheduledRows = build_session_bye_notes($pairs, $configuredCourtCount, $memberUniverse, 'mode_cycle=single');
    $fetchExisting = $db->prepare(
        "SELECT id, game_title, session_no, match_no, score_a, score_b
         FROM competition_games
         WHERE competition_type = ? AND round_no = ?
         ORDER BY COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
    );
    $fetchExisting->execute([$type, $nextRound]);
    $existingRows = filter_rows_by_competition_label($fetchExisting->fetchAll(PDO::FETCH_ASSOC), $type, $label);
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
    foreach ($scheduledRows as $idx => $slot) {
        $matchNo = (int)($slot['match_no'] ?? ($idx + 1));
        $sessionNo = (int)($slot['session_no'] ?? 1);
        $courtNo = (int)($slot['court_no'] ?? (($idx % $configuredCourtCount) + 1));
        $title = $label . ' - R' . $nextRound . ' M' . $matchNo;
        $a = (string)($slot['player_a_name'] ?? '');
        $b = (string)($slot['player_b_name'] ?? '');
        $notes = (string)($slot['notes'] ?? 'mode_cycle=single');

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
            $notes,
            $adminId > 0 ? $adminId : null,
            date('Y-m-d H:i:s'),
        ]);
        $created++;
    }

    // Remove leftover empty rows when the new pairing list is shorter.
    for ($i = count($scheduledRows); $i < count($existingRows); $i++) {
        $deleteRow->execute([(int)$existingRows[$i]['id']]);
    }

    return [$created, $updated];
}

function auto_sync_mexicano_rounds(PDO $db, int $adminId): void {
    try {
        $stmt = $db->query(
            "SELECT game_title, round_no, score_a, score_b
             FROM competition_games
             WHERE competition_type = 'Mexicano'
             ORDER BY COALESCE(round_no, 0) ASC, COALESCE(session_no, 1) ASC, match_no ASC, id ASC"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return;
    }
    if (!$rows) {
        return;
    }

    $byLabel = [];
    foreach ($rows as $row) {
        $roundNo = (int)($row['round_no'] ?? 0);
        if ($roundNo <= 0) {
            continue;
        }
        $label = build_competition_label_from_title('Mexicano', (string)($row['game_title'] ?? ''));
        if ($label === '') {
            $label = 'Mexicano';
        }
        if (!isset($byLabel[$label])) {
            $byLabel[$label] = [];
        }
        if (!isset($byLabel[$label][$roundNo])) {
            $byLabel[$label][$roundNo] = [];
        }
        $byLabel[$label][$roundNo][] = $row;
    }

    foreach ($byLabel as $label => $roundMap) {
        if (!$roundMap) {
            continue;
        }
        if (is_tournament_completed($db, 'Mexicano', (string)$label)) {
            continue;
        }
        krsort($roundMap, SORT_NUMERIC);
        $latestRound = (int)array_key_first($roundMap);
        $latestRows = (array)($roundMap[$latestRound] ?? []);
        if (!$latestRows || !is_round_completed($latestRows)) {
            continue;
        }
        sync_next_round_from_round($db, 'Mexicano', $latestRound, $adminId, (string)$label);
    }
}


$db = get_db();
ensure_session();
$flash = ['success' => '', 'error' => ''];
if (isset($_SESSION['competition_flash']) && is_array($_SESSION['competition_flash'])) {
    $flash['success'] = (string)($_SESSION['competition_flash']['success'] ?? '');
    $flash['error'] = (string)($_SESSION['competition_flash']['error'] ?? '');
    unset($_SESSION['competition_flash']);
}

auto_sync_mexicano_rounds($db, (int)($_SESSION['admin_id'] ?? 0));

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
    $db->exec(
        "CREATE TABLE IF NOT EXISTS competition_tournament_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            competition_type VARCHAR(20) NOT NULL,
            tournament_label VARCHAR(160) NOT NULL,
            is_completed TINYINT(1) NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            completed_by_admin_id INT NULL,
            winner_name VARCHAR(150) NULL,
            winner_point_total INT NULL,
            winner_point_diff INT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_competition_tournament (competition_type, tournament_label)
        )"
    );
} catch (Throwable $e) {
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
        $savedType = '';
        $savedRound = 0;
        $savedLabel = '';
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
            if ($flash['error'] === '') {
                $typeStmt = $db->prepare('SELECT competition_type, round_no, game_title FROM competition_games WHERE id = ? LIMIT 1');
                $typeStmt->execute([$gameId]);
                $savedGame = $typeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $savedType = normalize_competition_type((string)($savedGame['competition_type'] ?? ''));
                $savedRound = (int)($savedGame['round_no'] ?? 0);
                $savedLabel = build_competition_label_from_title($savedType, (string)($savedGame['game_title'] ?? ''));
                if ($savedType === '') {
                    $flash['error'] = 'Game tidak ditemukan.';
                } elseif ($savedType === 'Mexicano' && is_tournament_completed($db, $savedType, $savedLabel)) {
                    $flash['error'] = 'Tournament Mexicano ini sudah selesai.';
                } elseif ($savedType === 'Mexicano' && !is_previous_round_completed($db, $savedType, $savedRound, $savedLabel)) {
                    $flash['error'] = 'Round ' . $savedRound . ' belum bisa diinput sebelum Round ' . ($savedRound - 1) . ' selesai.';
                }
            }
        }
        if ($flash['error'] === '') {
            try {
                $db->prepare('UPDATE competition_games SET score_a = ?, score_b = ? WHERE id = ?')
                    ->execute([$scoreA, $scoreB, $gameId]);
                $flash['success'] = 'Skor game berhasil diperbarui.';
                if ($savedType === 'Mexicano') {
                    [$nextCreated, $nextUpdated] = sync_next_round_from_round($db, $savedType, $savedRound, (int)($_SESSION['admin_id'] ?? 0), $savedLabel);
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
    } elseif ($action === 'complete_tournament') {
        $type = normalize_competition_type((string)($_POST['competition_type'] ?? ''));
        $label = trim((string)($_POST['tournament_label'] ?? ''));
        if ($type !== 'Mexicano') {
            $flash['error'] = 'Hanya tournament Mexicano yang bisa ditandai selesai.';
        } elseif ($label === '') {
            $flash['error'] = 'Label tournament tidak valid.';
        } elseif (is_tournament_completed($db, $type, $label)) {
            $flash['error'] = 'Tournament ini sudah ditandai selesai.';
        } else {
            try {
                $fetchStmt = $db->prepare(
                    "SELECT id, game_title, player_a_name, player_b_name, score_a, score_b, notes
                     FROM competition_games
                     WHERE competition_type = ?"
                );
                $fetchStmt->execute([$type]);
                $rows = filter_rows_by_competition_label($fetchStmt->fetchAll(PDO::FETCH_ASSOC), $type, $label);
                if (!$rows) {
                    $flash['error'] = 'Tournament tidak ditemukan.';
                } else {
                    $rowsForLeaderboard = [];
                    $pendingGameIds = [];
                    foreach ($rows as $row) {
                        if (has_valid_game_score($row)) {
                            $rowsForLeaderboard[] = $row;
                            continue;
                        }
                        $scoreASet = isset($row['score_a']) && $row['score_a'] !== null;
                        $scoreBSet = isset($row['score_b']) && $row['score_b'] !== null;
                        if ($scoreASet || $scoreBSet) {
                            $flash['error'] = 'Masih ada match dengan skor parsial/invalid. Rapikan dulu sebelum selesai.';
                            break;
                        }
                        $idVal = (int)($row['id'] ?? 0);
                        if ($idVal > 0) {
                            $pendingGameIds[] = $idVal;
                        }
                    }
                    if ($flash['error'] === '' && !$rowsForLeaderboard) {
                        $flash['error'] = 'Belum ada skor valid untuk menentukan juara.';
                    }
                    if ($flash['error'] === '') {
                        $seedPlayers = [];
                        foreach ($rowsForLeaderboard as $row) {
                            foreach (array_merge(
                                split_team_members((string)($row['player_a_name'] ?? '')),
                                split_team_members((string)($row['player_b_name'] ?? '')),
                                extract_bye_members_from_notes((string)($row['notes'] ?? ''))
                            ) as $member) {
                                $name = trim((string)$member);
                                if ($name !== '' && strcasecmp($name, 'bye') !== 0 && strcasecmp($name, 'tbd') !== 0) {
                                    $seedPlayers[$name] = true;
                                }
                            }
                        }
                        $standings = build_standings_from_games($rowsForLeaderboard, array_keys($seedPlayers));
                        if (!$standings) {
                            $flash['error'] = 'Leaderboard belum tersedia untuk menentukan pemenang.';
                        } else {
                            $playedMap = [];
                            foreach ($standings as $standingRow) {
                                $playerName = trim((string)($standingRow['name'] ?? ''));
                                if ($playerName === '') {
                                    continue;
                                }
                                $playedMap[$playerName] = (int)($standingRow['played'] ?? 0);
                            }
                            foreach (array_keys($seedPlayers) as $seedNameRaw) {
                                $seedName = trim((string)$seedNameRaw);
                                if ($seedName === '' || strcasecmp($seedName, 'bye') === 0 || strcasecmp($seedName, 'tbd') === 0) {
                                    continue;
                                }
                                if ((int)($playedMap[$seedName] ?? 0) <= 0) {
                                    $flash['error'] = 'Belum bisa selesai: masih ada peserta yang belum punya skor main.';
                                    break;
                                }
                            }
                        }
                        if ($flash['error'] === '') {
                            $winner = (array)$standings[0];
                            $winnerName = trim((string)($winner['name'] ?? ''));
                            $winnerTotal = (int)($winner['point_total'] ?? 0);
                            $winnerDiff = (int)($winner['point_diff'] ?? 0);
                            $now = date('Y-m-d H:i:s');
                            $adminId = (int)($_SESSION['admin_id'] ?? 0);
                            $upsert = $db->prepare(
                                "INSERT INTO competition_tournament_states (
                                    competition_type, tournament_label, is_completed, completed_at, completed_by_admin_id,
                                    winner_name, winner_point_total, winner_point_diff, updated_at
                                 ) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE
                                    is_completed = VALUES(is_completed),
                                    completed_at = VALUES(completed_at),
                                    completed_by_admin_id = VALUES(completed_by_admin_id),
                                    winner_name = VALUES(winner_name),
                                    winner_point_total = VALUES(winner_point_total),
                                    winner_point_diff = VALUES(winner_point_diff),
                                    updated_at = VALUES(updated_at)"
                            );
                            $upsert->execute([
                                $type,
                                $label,
                                $now,
                                $adminId > 0 ? $adminId : null,
                                $winnerName !== '' ? $winnerName : null,
                                $winnerTotal,
                                $winnerDiff,
                                $now,
                            ]);
                            if ($pendingGameIds) {
                                $placeholders = implode(',', array_fill(0, count($pendingGameIds), '?'));
                                $deletePending = $db->prepare("DELETE FROM competition_games WHERE id IN ($placeholders)");
                                $deletePending->execute($pendingGameIds);
                            }
                            $flash['success'] = 'Tournament selesai. Juara: ' . ($winnerName !== '' ? $winnerName : '-');
                        }
                    }
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menyelesaikan tournament.';
            }
        }
        if ($ajaxRequest) {
            respond_json([
                'ok' => $flash['error'] === '',
                'message' => $flash['error'] !== '' ? $flash['error'] : $flash['success'],
            ]);
        }
    } elseif ($action === 'delete_tournament') {
        $rawIds = trim((string)($_POST['game_ids'] ?? ''));
        if ($rawIds === '') {
            $flash['error'] = 'Data game tidak valid.';
        } else {
            $parts = preg_split('/\s*,\s*/', $rawIds) ?: [];
            $ids = [];
            foreach ($parts as $part) {
                if ($part !== '' && ctype_digit($part)) {
                    $idVal = (int)$part;
                    if ($idVal > 0) {
                        $ids[$idVal] = true;
                    }
                }
            }
            $ids = array_keys($ids);
            if (!$ids) {
                $flash['error'] = 'Data game tidak valid.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $db->prepare("DELETE FROM competition_games WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $deleted = (int)$stmt->rowCount();
                    $flash[$deleted > 0 ? 'success' : 'error'] = $deleted > 0
                        ? ('Berhasil menghapus 1 game (' . $deleted . ' match).')
                        : 'Game tidak ditemukan.';
                } catch (Throwable $e) {
                    $flash['error'] = 'Gagal menghapus game.';
                }
            }
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
        $playerCountRaw = trim((string)($_POST['player_count'] ?? ''));
        $playerCount = ctype_digit($playerCountRaw) ? (int)$playerCountRaw : 0;
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
            $availableCount = count($attendees);
            if ($availableCount < 4) {
                $flash['error'] = 'Attendee accepted belum cukup untuk format team (minimal 4 orang).';
            } elseif ($playerCount > 0 && ($playerCount < 4 || $playerCount > $availableCount)) {
                $flash['error'] = 'Jumlah pemain tidak valid. Isi 4 sampai ' . $availableCount . ' pemain.';
            } else {
                $effectivePlayerCount = $playerCount > 0 ? $playerCount : $availableCount;
                shuffle($attendees);
                if ($effectivePlayerCount < $availableCount) {
                    $attendees = array_slice($attendees, 0, $effectivePlayerCount);
                }
            }
            if ($flash['error'] !== '') {
                // validation message already set
            } elseif ($type === 'Americano' && count($attendees) % 2 !== 0) {
                $flash['error'] = 'Americano membutuhkan jumlah pemain genap.';
            } elseif ($type === 'Americano' && count($attendees) % 4 !== 0) {
                $flash['error'] = 'Americano membutuhkan jumlah pemain kelipatan 4 agar semua pemain bermain di setiap ronde.';
            } else {
                $maxCourtActive = ($type === 'Mexicano')
                    ? (int)ceil(count($attendees) / 4)
                    : (int)intdiv(count($attendees), 4);
                $maxCourtActive = max(1, $maxCourtActive);
                if ($courtCount > $maxCourtActive) {
                    $flash['error'] = 'Jumlah court terlalu banyak untuk total pemain. Maksimal court aktif: ' . $maxCourtActive . '.';
                }
            }
            if ($flash['error'] !== '') {
                // validation message already set
            } else {
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
                    if ($type === 'Mexicano') {
                        try {
                            $resetState = $db->prepare(
                                "INSERT INTO competition_tournament_states (
                                    competition_type, tournament_label, is_completed, completed_at, completed_by_admin_id,
                                    winner_name, winner_point_total, winner_point_diff, updated_at
                                 ) VALUES (?, ?, 0, NULL, NULL, NULL, NULL, NULL, ?)
                                 ON DUPLICATE KEY UPDATE
                                    is_completed = 0,
                                    completed_at = NULL,
                                    completed_by_admin_id = NULL,
                                    winner_name = NULL,
                                    winner_point_total = NULL,
                                    winner_point_diff = NULL,
                                    updated_at = VALUES(updated_at)"
                            );
                            $resetState->execute([$type, $label, date('Y-m-d H:i:s')]);
                        } catch (Throwable $e) {
                        }
                    }
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
                                null, null, $gameDateRaw !== '' ? $gameDateRaw : null, (string)($row['notes'] ?? 'mode_cycle=single'),
                                $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                        $flash['success'] = 'Americano: '
                            . count($attendees) . ' pemain random, '
                            . (int)($schedule['logical_rounds'] ?? 0) . ' ronde logika, '
                            . (int)($schedule['sesi_per_round'] ?? 0) . ' sesi/ronde, total '
                            . (int)($schedule['total_sesi'] ?? 0) . ' sesi, '
                            . $created . ' match.';
                    } else {
                        // Mexicano Round 1 starts from random order, then next rounds follow ranking.
                        $roundOneOrder = $attendees;
                        shuffle($roundOneOrder);
                        $pairs = build_mexicano_pairs_for_round($roundOneOrder, $courtCount);
                        if (!$pairs) {
                            throw new RuntimeException('Gagal membentuk bagan Mexicano.');
                        }
                        $scheduledRows = build_session_bye_notes($pairs, $courtCount, $attendees, 'mode_cycle=single', 1, true);
                        foreach ($scheduledRows as $mIdx => $slot) {
                            $roundNo = (int)($slot['round_no'] ?? 1);
                            $matchNo = (int)($slot['match_no'] ?? (($mIdx % max(1, $courtCount)) + 1));
                            $sessionNo = (int)($slot['session_no'] ?? 1);
                            $courtNo = (int)($slot['court_no'] ?? $matchNo);
                            $title = $label . ' - R' . $roundNo . ' M' . $matchNo;
                            $insert->execute([
                                null, $type, $title, $roundNo, $sessionNo, $matchNo, $courtNo, $courtCount, $matchTotalPoints,
                                null, (string)($slot['player_a_name'] ?? ''), null, (string)($slot['player_b_name'] ?? ''),
                                null, null, $gameDateRaw !== '' ? $gameDateRaw : null, (string)($slot['notes'] ?? 'mode_cycle=single'),
                                $adminId > 0 ? $adminId : null, date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                        $estimation = calculate_round_estimation(count($attendees), $courtCount, $type);
                        $wavesPerRound = (int)($estimation['waves_per_logical_round'] ?? 0);
                        $flash['success'] = 'Berhasil generate batch awal Mexicano (' . $created . ' match) dengan ' . count($attendees) . ' pemain random di ' . $courtCount . ' court. Per ronde butuh ' . max(1, $wavesPerRound) . ' wave.';
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
        "SELECT id, game_title, round_no, session_no, match_no, court_no, court_count, match_total_points, game_date, created_at, competition_type, notes,
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
$tournamentStateMap = fetch_tournament_state_map($db);

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
            'is_completed' => false,
            'completed_at' => '',
            'winner_name' => '',
            'winner_point_total' => 0,
            'winner_point_diff' => 0,
            'all_scored' => false,
            'can_complete' => false,
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
    foreach (extract_bye_members_from_notes((string)($row['notes'] ?? '')) as $byeMember) {
        $nameKey = strtolower(trim((string)$byeMember));
        if ($nameKey === '' || $nameKey === 'bye' || $nameKey === 'tbd') {
            continue;
        }
        $tournaments[$key]['players'][$nameKey] = trim((string)$byeMember);
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
    $seedPlayers = array_values((array)($tournament['players'] ?? []));
    $tournamentType = normalize_competition_type((string)($tournament['type'] ?? ''));
    $stateKey = build_tournament_state_key((string)($tournament['type'] ?? ''), (string)($tournament['label'] ?? ''));
    $stateRow = (array)($tournamentStateMap[$stateKey] ?? []);
    $isMexicano = ($tournamentType === 'Mexicano');
    $isCompleted = $isMexicano && ((bool)($stateRow['is_completed'] ?? false));
    $gamesForStandings = $gamesRows;
    if ($isMexicano) {
        $gamesForStandings = filter_games_with_valid_scores($gamesRows);
    }
    $standingRows = build_standings_from_games($gamesForStandings, $seedPlayers);

    $tournaments[$key]['games'] = $gamesRows;
    $tournaments[$key]['rounds'] = $rounds;
    $tournaments[$key]['standings'] = $standingRows;
    $allScored = !empty($gamesRows);
    $hasAnyValidScore = false;
    $hasPartialScore = false;
    $playedByMember = [];
    foreach ($gamesRows as $gameRow) {
        if (has_valid_game_score($gameRow)) {
            $hasAnyValidScore = true;
            foreach (array_merge(
                split_team_members((string)($gameRow['player_a_name'] ?? '')),
                split_team_members((string)($gameRow['player_b_name'] ?? ''))
            ) as $memberName) {
                $cleanMember = trim((string)$memberName);
                if ($cleanMember === '' || strcasecmp($cleanMember, 'bye') === 0 || strcasecmp($cleanMember, 'tbd') === 0) {
                    continue;
                }
                $playedByMember[$cleanMember] = (int)($playedByMember[$cleanMember] ?? 0) + 1;
            }
            continue;
        }
        $scoreASet = isset($gameRow['score_a']) && $gameRow['score_a'] !== null;
        $scoreBSet = isset($gameRow['score_b']) && $gameRow['score_b'] !== null;
        if ($scoreASet || $scoreBSet) {
            $hasPartialScore = true;
            $allScored = false;
        } else {
            $allScored = false;
        }
    }
    $allPlayersScored = !empty($seedPlayers);
    foreach ($seedPlayers as $seedPlayerName) {
        $seedName = trim((string)$seedPlayerName);
        if ($seedName === '' || strcasecmp($seedName, 'bye') === 0 || strcasecmp($seedName, 'tbd') === 0) {
            continue;
        }
        if ((int)($playedByMember[$seedName] ?? 0) <= 0) {
            $allPlayersScored = false;
            break;
        }
    }
    $tournaments[$key]['is_completed'] = $isCompleted;
    $tournaments[$key]['completed_at'] = trim((string)($stateRow['completed_at'] ?? ''));
    $tournaments[$key]['winner_name'] = trim((string)($stateRow['winner_name'] ?? ''));
    $tournaments[$key]['winner_point_total'] = (int)($stateRow['winner_point_total'] ?? 0);
    $tournaments[$key]['winner_point_diff'] = (int)($stateRow['winner_point_diff'] ?? 0);
    $tournaments[$key]['all_scored'] = $allScored;
    $tournaments[$key]['all_players_scored'] = $allPlayersScored;
    $tournaments[$key]['can_complete'] = $isMexicano && !$isCompleted && $hasAnyValidScore && !$hasPartialScore && $allPlayersScored && !empty($standingRows);
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
  .competition-grid {
    display:grid;
    grid-template-columns:minmax(280px,380px) minmax(0,1fr);
    gap:16px;
    align-items:start
  }
  .competition-card {
    background:rgba(255,255,255,.92);
    border:1px solid rgba(15,32,60,.12);
    border-radius:16px;
    padding:16px;
    box-shadow:0 8px 26px rgba(15,32,60,.08)
  }
  .competition-card--full {
    grid-column:1 / -1
  }
  .competition-form {
    display:grid;
    gap:10px
  }
  .competition-form label {
    font-size:12px;
    color:#415a80;
    font-weight:700
  }
  .competition-form input,.competition-form select {
    width:100%;
    border-radius:10px;
    border:1px solid #c6d4ea;
    padding:10px 11px;
    background:#fff
  }
  .create-step-hint {
    font-size:12px;
    font-weight:700;
    color:#1b4d8f;
    background:#eef4ff;
    border:1px solid #d3e3ff;
    border-radius:10px;
    padding:8px 10px;
    margin:0
  }
  .create-progress-body[hidden] {
    display:none!important
  }
  .create-progress-body {
    display:grid;
    gap:10px
  }
  .create-step-block[hidden] {
    display:none!important
  }
  .create-step-block {
    display:grid;
    gap:10px;
    animation:createStepReveal .2s ease-out
  }
  .alert {
    margin-bottom:14px
  }
  .alert.success {
    background:#e8f8ee;
    border:1px solid #b7e6c4;
    color:#18633a
  }
  .alert.success i {
    color:#18633a
  }
  .alert.error {
    background:#fdeeee;
    border:1px solid #f3bcbc;
    color:#b43636
  }
  .alert.error i {
    color:#b43636
  }
  .note {
    font-size:12px;
    color:#385885;
    background:#edf4ff;
    border:1px solid #d3e3ff;
    border-radius:10px;
    padding:8px 10px;
    margin:0
  }
  .type-filter {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin:8px 0 12px
  }
  .type-filter button {
    border:1px solid #bfd0ea;
    background:#fff;
    color:#163966;
    border-radius:8px;
    padding:7px 10px;
    font-size:12px;
    font-weight:700;
    cursor:pointer
  }
  .type-filter button.active {
    background:#1a66e9;
    border-color:#1a66e9;
    color:#fff
  }
  .rounds-nav {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin:2px 0 8px
  }
  .rounds-nav-btn {
    width:34px;
    min-width:34px;
    height:34px;
    border-radius:10px;
    border:1px solid #c6d4ea;
    background:#fff;
    color:#163966;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer
  }
  .rounds-nav-btn:disabled {
    opacity:.45;
    cursor:not-allowed
  }
  .rounds-nav-label {
    font-size:12px;
    font-weight:800;
    color:#173964;
    letter-spacing:.35px;
    text-transform:uppercase
  }
  .rounds-scroll {
    overflow:hidden;
    padding-bottom:4px;
    margin-top:0
  }
  .rounds-track {
    display:flex;
    gap:0;
    transition:transform .28s ease
  }
  .round-column {
    width:100%;
    max-width:100%;
    position:relative;
    flex:0 0 100%;
    min-width:100%;
    box-sizing:border-box;
    padding-right:0
  }
  .round-column + .round-column::before {
    display:none
  }
  .round-column {
    --round-accent:#1f2937;
    --round-bg:#ffffff
  }
  .round-column:nth-child(6n+1), .round-column:nth-child(6n+2), .round-column:nth-child(6n+3), .round-column:nth-child(6n+4), .round-column:nth-child(6n+5), .round-column:nth-child(6n+6) {
    --round-accent:#1f2937;
    --round-bg:#ffffff
  }
  .round-block {
    border:1px solid #e3e7ee;
    border-radius:16px;
    padding:14px;
    background:#fff;
    box-shadow:0 6px 18px rgba(15,32,60,.05)
  }
  .round-title {
    margin:0 0 16px;
    font-size:16px;
    color:#111827;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.4px
  }
  .match-item + .match-item {
    margin-top:18px
  }
  .match-item {
    border:0;
    border-radius:0;
    background:transparent;
    padding:0;
    box-shadow:none
  }
  .match-box {
    display:grid;
    grid-template-columns:1fr auto 1fr;
    border:0;
    border-radius:12px;
    background:#f1f2f4;
    overflow:hidden;
    box-shadow:0 2px 8px rgba(15,32,60,.06)
  }
  .match-summary {
    display:grid;
    gap:8px
  }
  .match-meta {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    flex-wrap:wrap
  }
  .match-caption {
    font-size:15px;
    color:#111827;
    font-weight:800;
    line-height:1.15
  }
  .match-top-actions {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
    padding-top:10px
  }
  .match-score-preview {
    font-size:12px;
    font-weight:800;
    color:#173964
  }
  .match-score-preview .score-pill {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:30px;
    height:24px;
    border-radius:6px;
    background:#111827;
    color:#fff;
    font-size:12px;
    padding:0 7px
  }
  .match-open-score {
    min-width:110px
  }
  .match-open-score[disabled] {
    opacity:.55
  }
  .seed-side {
    padding:12px 14px;
    display:grid;
    gap:4px;
    align-content:center
  }
  .seed-side + .seed-side {
    border-left:1px solid #d5deed
  }
  .seed-line {
    padding:0;
    font-size:15px;
    color:#2b2f36;
    min-height:34px;
    display:flex;
    align-items:center;
    line-height:1.2
  }
  .seed-vs {
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 16px;
    font-size:24px;
    font-weight:900;
    color:#353b47;
    text-transform:lowercase;
    border-left:1px solid #e3e7ee;
    border-right:1px solid #e3e7ee;
    background:#eceef2
  }
  .score-editor {
    display:grid;
    grid-template-columns:1fr;
    row-gap:10px
  }
  .score-label {
    font-size:11px;
    color:#47628b;
    font-weight:800
  }
  .score-vs {
    display:none
  }
  .score-total-hint {
    grid-column:1 / -1;
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#173964;
    font-weight:800;
    background:#eef4ff;
    border:1px solid #d3e1fb;
    border-radius:999px;
    padding:6px 10px;
    width:max-content
  }
  .score-strip {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    flex-wrap:wrap
  }
  .score-side {
    font-size:15px;
    color:#334155;
    font-weight:800
  }
  .score-vs-inline {
    font-size:12px;
    color:#405d84;
    font-weight:900;
    text-transform:lowercase
  }
  .score-separator {
    font-weight:900;
    color:#4f6489;
    text-align:center
  }
  .match-actions {
    margin-top:10px;
    display:flex;
    justify-content:flex-end
  }
  .score-editor select,.score-editor input {
    border:1px solid #c6d4ea;
    border-radius:8px;
    padding:7px 8px;
    background:#fff
  }
  .score-editor input {
    width:68px;
    text-align:center;
    font-weight:700;
    color:#14345f
  }
  .score-box {
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
  .score-box:focus {
    outline:2px solid #7aa7ff;
    outline-offset:2px
  }
  .score-editor button[type="submit"] {
    height:34px
  }
  .live-status {
    min-height:16px;
    font-size:11px;
    color:#4c6388;
    grid-column:1 / -1
  }
  .live-status.ok {
    color:#18633a
  }
  .live-status.error {
    color:#b43636
  }
  .type-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin:4px 0 14px
  }
  .tournament-status-chip {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:800;
    letter-spacing:.25px;
    text-transform:uppercase
  }
  .tournament-status-chip.ready {
    background:#e8f7ee;
    border:1px solid #b7e6c4;
    color:#18633a
  }
  .tournament-status-chip.waiting {
    background:#eef4ff;
    border:1px solid #d3e3ff;
    color:#1b4d8f
  }
  .tournament-complete-form {
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap
  }
  .tournament-complete-form .btn {
    min-height:34px;
    padding:7px 12px;
    border-radius:10px;
    font-weight:800
  }
  .standing-wrap {
    overflow-x:auto;
    margin-top:10px
  }
  .standing-wrap {
    cursor:grab;
    user-select:none
  }
  .standing-wrap.is-dragging {
    cursor:grabbing
  }
  table.standing-table {
    width:100%;
    border-collapse:collapse;
    min-width:560px
  }
  .standing-table th,.standing-table td {
    text-align:left;
    padding:8px 7px;
    border-bottom:1px solid #dfe8f8;
    font-size:12px;
    color:#183054
  }
  .standing-table th {
    font-size:11px;
    color:#5a6b86;
    text-transform:uppercase
  }
  .tournament-list {
    display:grid;
    gap:12px;
    margin-top:10px
  }
  .tournament-card {
    width:100%;
    text-align:left;
    border:1px solid #d8e2f2;
    background:#f8fbff;
    border-radius:14px;
    padding:14px 14px;
    cursor:pointer;
    transition:.16s ease;
    border-color:#d8e2f2
  }
  .tournament-card:hover {
    transform:translateY(-1px);
    box-shadow:0 8px 20px rgba(15,32,60,.08);
    border-color:#bcd2f3
  }
  .tournament-card.is-active {
    border-color:#1a66e9;
    box-shadow:0 0 0 2px rgba(26,102,233,.14)
  }
  .tournament-name {
    font-size:20px;
    line-height:1.1;
    color:#0f294d;
    font-weight:800;
    letter-spacing:-.3px
  }
  .tournament-meta {
    margin-top:6px;
    font-size:13px;
    font-weight:700;
    color:#1c426e
  }
  .tournament-updated {
    margin-top:5px;
    font-size:11px;
    color:#5a6b86;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.55px
  }
  .tournament-card-actions {
    margin-top:10px;
    display:flex;
    justify-content:flex-end
  }
  .tour-pagination {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    flex-wrap:wrap;
    margin-top:14px
  }
  .tour-page-link,.tour-page-dots {
    min-width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    font-size:13px;
    font-weight:800
  }
  .tour-page-link {
    border:1px solid #d6dee9;
    background:#fff;
    color:#213a62;
    text-decoration:none
  }
  .tour-page-link:hover {
    background:#f5f8ff
  }
  .tour-page-link.current {
    background:#3b82f6;
    border-color:#3b82f6;
    color:#fff
  }
  .tour-page-link.disabled {
    opacity:.45;
    pointer-events:none
  }
  .tour-page-dots {
    color:#6b7280
  }
  .tournament-modal {
    position:fixed;
    inset:0;
    background:rgba(10,20,40,.52);
    z-index:5000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:0
  }
  .tournament-modal.is-open {
    display:flex
  }
  .tournament-modal-card {
    width:min(96vw,1520px);
    height:92vh;
    display:grid;
    grid-template-rows:auto minmax(0,1fr);
    border-radius:16px;
    background:#fff;
    border:1px solid rgba(15,32,60,.15);
    box-shadow:0 18px 38px rgba(10,20,40,.24);
    overflow:hidden
  }
  .tournament-modal-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:18px 22px;
    border-bottom:0;
    background:linear-gradient(180deg,#9f8ad0 0%,#7b69b0 100%);
    position:sticky;
    top:0;
    z-index:2
  }
  .tournament-modal-title {
    margin:0;
    font-size:44px;
    color:#fff;
    font-weight:900;
    letter-spacing:.4px;
    text-transform:uppercase
  }
  .tournament-modal-close {
    border:1px solid rgba(255,255,255,.5);
    background:rgba(255,255,255,.2);
    color:#fff;
    border-radius:10px;
    min-width:36px;
    height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:18px;
    line-height:1
  }
  .tournament-modal-body {
    padding:18px 22px 22px;
    overflow:auto;
    background:#f6f7f9;
    font-family:"Trebuchet MS","Segoe UI",Tahoma,sans-serif
  }
  .tour-detail-grid {
    display:grid;
    grid-template-columns:minmax(360px,500px) minmax(0,1fr);
    gap:16px;
    align-items:start
  }
  .standing-panel,.round-panel {
    background:#fff;
    border:1px solid #dfe4ec;
    border-radius:16px;
    padding:14px
  }
  .standing-panel-title {
    margin:0 0 8px;
    font-size:12px;
    font-weight:900;
    color:#334155;
    text-transform:uppercase;
    letter-spacing:.45px
  }
  .round-panel-title {
    margin:0 0 14px;
    font-size:48px;
    font-weight:900;
    color:#0f172a;
    letter-spacing:.2px;
    line-height:1
  }
  .game-input-trigger {
    width:100%;
    min-height:46px
  }
  .game-input-modal {
    position:fixed;
    inset:0;
    background:rgba(10,20,40,.32);
    backdrop-filter:blur(2px);
    z-index:5200;
    display:none;
    align-items:center;
    justify-content:center;
    padding:18px
  }
  .game-input-modal.is-open {
    display:flex
  }
  .game-input-modal-card {
    width:min(680px,100%);
    max-height:92vh;
    display:grid;
    grid-template-rows:auto minmax(0,1fr);
    border-radius:22px;
    background:linear-gradient(180deg,#ffffff 0%,#fbf7f8 100%);
    border:1px solid #eadfe2;
    box-shadow:0 20px 52px rgba(29,20,14,.22);
    overflow:hidden
  }
  .game-input-modal-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:16px 18px;
    border-bottom:1px solid #e9dfe2;
    background:#fff
  }
  .game-input-modal-title {
    margin:0;
    font-size:32px;
    line-height:1.08;
    color:#2d1d13;
    font-weight:900;
    letter-spacing:-.4px
  }
  .game-input-modal-close {
    border:1px solid #ddd2d6;
    background:#fff;
    color:#4a3324;
    border-radius:14px;
    min-width:42px;
    height:42px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:20px;
    line-height:1;
    transition:.18s ease
  }
  .game-input-modal-close:hover {
    background:#f7f1f3;
    border-color:#cdbdc4
  }
  .game-input-modal-body {
    padding:16px 16px 18px;
    overflow:auto
  }
  .game-input-modal-body .competition-form {
    gap:12px
  }
  .game-input-modal-body .competition-form label {
    font-size:12px;
    color:#4c3324;
    font-weight:900;
    letter-spacing:.18px
  }
  .game-input-modal-body .competition-form input,.game-input-modal-body .competition-form select {
    height:58px;
    border-radius:999px;
    border:1px solid #e5dadd;
    background:#f7f3f4;
    color:#352317;
    padding:0 18px;
    font-size:16px;
    font-weight:700;
    transition:border-color .16s ease,box-shadow .16s ease,background .16s ease
  }
  .game-input-modal-body .competition-form input::placeholder {
    color:#b6a8ad;
    font-weight:600
  }
  .game-input-modal-body .competition-form input:focus,.game-input-modal-body .competition-form select:focus {
    outline:none;
    border-color:#aac47b;
    box-shadow:0 0 0 3px rgba(170,196,123,.24);
    background:#fff
  }
  .player-stepper {
    display:grid;
    grid-template-columns:58px minmax(0,1fr) 58px;
    gap:10px;
    align-items:center
  }
  .player-stepper-btn {
    height:58px;
    border-radius:999px;
    border:1px solid #d8c9ce;
    background:#efe6e9;
    color:#4a3324;
    font-size:24px;
    font-weight:900;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:background .16s ease,border-color .16s ease,transform .12s ease
  }
  .player-stepper-btn:hover {
    background:#eadfe3;
    border-color:#cdbdc4
  }
  .player-stepper-btn:active {
    transform:translateY(1px)
  }
  .player-stepper-btn:focus {
    outline:none;
    box-shadow:0 0 0 3px rgba(170,196,123,.24);
    border-color:#aac47b
  }
  .player-stepper-btn[disabled] {
    opacity:.55;
    cursor:not-allowed;
    transform:none
  }
  .player-stepper-input {
    text-align:center;
    padding:0 14px !important;
    appearance:textfield
  }
  .player-stepper-input::-webkit-outer-spin-button,.player-stepper-input::-webkit-inner-spin-button {
    -webkit-appearance:none;
    margin:0
  }
  .game-input-modal-body .competition-form .btn.primary {
    min-height:56px;
    border-radius:999px;
    font-size:20px;
    font-weight:900
  }
  .game-input-modal-body .note {
    border-radius:14px;
    background:#f3eef0;
    border:1px solid #e6dce0;
    color:#5a4251
  }
  .game-input-modal-body .create-step-hint {
    border-radius:14px;
    background:#f3eef0;
    border:1px solid #e6dce0;
    color:#5a4251
  }
  .toast-stack {
    position:fixed;
    top:18px;
    right:18px;
    z-index:9999;
    display:grid;
    gap:10px;
    max-width:min(420px,calc(100vw - 24px))
  }
  .toast-item {
    display:flex;
    align-items:flex-start;
    gap:10px;
    border-radius:12px;
    padding:11px 12px;
    box-shadow:0 10px 24px rgba(10,20,40,.18);
    border:1px solid transparent;
    background:#fff;
    animation:toastIn .18s ease-out
  }
  .toast-item.success {
    border-color:#9ad5b0;
    background:#e9f8ef;
    color:#14532d
  }
  .toast-item.error {
    border-color:#efb1b1;
    background:#feeeee;
    color:#991b1b
  }
  .toast-item i {
    font-size:15px;
    line-height:1.2;
    margin-top:1px
  }
  .toast-msg {
    font-size:13px;
    font-weight:700;
    line-height:1.45
  }
  .toast-close {
    border:0;
    background:transparent;
    color:inherit;
    cursor:pointer;
    padding:0 2px;
    font-size:16px;
    line-height:1;
    opacity:.72
  }
  .toast-close:hover {
    opacity:1
  }
  .alert-modal {
    position:fixed;
    inset:0;
    background:rgba(10,20,40,.42);
    z-index:10020;
    display:none;
    align-items:center;
    justify-content:center;
    padding:16px
  }
  .alert-modal.is-open {
    display:flex
  }
  .alert-modal-card {
    width:min(480px,100%);
    background:#fff;
    border:1px solid #d8e3f4;
    border-radius:14px;
    box-shadow:0 20px 42px rgba(10,20,40,.24);
    padding:16px
  }
  .alert-modal-head {
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:8px
  }
  .alert-modal-icon {
    width:32px;
    height:32px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    background:#eef4ff;
    color:#1b4d8f
  }
  .alert-modal-title {
    margin:0;
    font-size:16px;
    font-weight:800;
    color:#0f294d
  }
  .alert-modal-message {
    margin:0;
    font-size:14px;
    line-height:1.55;
    color:#334155;
    white-space:pre-line
  }
  .alert-modal-actions {
    margin-top:14px;
    display:flex;
    justify-content:flex-end;
    gap:8px;
    flex-wrap:wrap
  }
  @keyframes toastIn {
    from {
      opacity:0;
      transform:translateY(-5px) translateX(10px)
    }
    to {
      opacity:1;
      transform:translateY(0) translateX(0)
    }
  }
  @keyframes createStepReveal {
    from {
      opacity:0;
      transform:translateY(-4px)
    }
    to {
      opacity:1;
      transform:translateY(0)
    }
  }
  @media (max-width:980px) {
    .competition-grid {
      grid-template-columns:1fr
    }
    .round-column {
      width:100%;
      min-width:100%
    }
    .tournament-name {
      font-size:18px
    }
    .tournament-modal {
      padding:0
    }
    .tournament-modal-card {
      width:100vw;
      height:100vh;
      max-height:none;
      border-radius:0
    }
    .tournament-modal-title {
      font-size:28px
    }
    .tour-detail-grid {
      grid-template-columns:1fr
    }
    .round-panel-title {
      font-size:28px
    }
    .score-editor {
      grid-template-columns:1fr 72px 14px 1fr 72px;
      row-gap:7px
    }
    .score-editor button[type="submit"] {
      grid-column:1 / -1;
      justify-self:start
    }
    .match-actions {
      justify-content:flex-start
    }
    .score-strip {
      justify-content:flex-start
    }
    .game-input-modal {
      padding:10px
    }
    .game-input-modal-title {
      font-size:26px
    }
    .game-input-modal-body .competition-form input,.game-input-modal-body .competition-form select {
      height:54px;
      font-size:15px
    }
    .game-input-modal-body .competition-form .btn.primary {
      min-height:52px;
      font-size:18px
    }
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
      </div>
      <div class="competition-actions"><a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left"></i> Dashboard</a></div>
    </div>

    <div id="toastStack" class="toast-stack" aria-live="polite" aria-atomic="true"></div>
    <div class="alert-modal" id="competitionAlertModal" aria-hidden="true">
      <div class="alert-modal-card" role="dialog" aria-modal="true" aria-labelledby="competitionAlertTitle" aria-describedby="competitionAlertMessage">
        <div class="alert-modal-head">
          <span class="alert-modal-icon"><i class="bi bi-exclamation-circle"></i></span>
          <h3 class="alert-modal-title" id="competitionAlertTitle">Konfirmasi</h3>
        </div>
        <p class="alert-modal-message" id="competitionAlertMessage">Lanjutkan aksi ini?</p>
        <div class="alert-modal-actions">
          <button type="button" class="btn ghost small" data-alert-cancel>Batal</button>
          <button type="button" class="btn primary small" data-alert-ok>Ya, lanjut</button>
        </div>
      </div>
    </div>
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
              $isCompletedCard = (bool)($tournament['is_completed'] ?? false);
            ?>
            <div class="tournament-card" data-open-tournament="<?= h($tourKey) ?>" data-game-type="<?= h($type) ?>" role="button" tabindex="0" aria-label="Buka detail <?= h($label) ?>">
              <div class="tournament-name"><?= h($label) ?></div>
              <div class="tournament-meta"><?= (int)$playerCount ?> players<?= $type === 'Mexicano' ? (' â€¢ ' . ($isCompletedCard ? 'Selesai' : 'Berjalan')) : '' ?></div>
              <div class="tournament-updated">Updated <?= $updatedAt !== '' ? h(date('d M Y H:i', strtotime($updatedAt))) : '-' ?></div>
              <div class="tournament-card-actions">
                <form method="post" data-delete-tournament-form>
                  <input type="hidden" name="competition_action" value="delete_tournament">
                  <input type="hidden" name="game_ids" value="<?= h(implode(',', array_map(static function (array $g): int { return (int)($g['id'] ?? 0); }, $typeGames))) ?>">
                  <button class="btn ghost small" type="submit">Hapus Game</button>
                </form>
              </div>
            </div>
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
                  $tourLabel = (string)($tournament['label'] ?? $type);
                  $isTournamentCompleted = (bool)($tournament['is_completed'] ?? false);
                  $canCompleteTournament = (bool)($tournament['can_complete'] ?? false);
                  $allPlayersScored = (bool)($tournament['all_players_scored'] ?? false);
                  $winnerName = trim((string)($tournament['winner_name'] ?? ''));
                  $winnerTotal = (int)($tournament['winner_point_total'] ?? 0);
                  $winnerDiff = (int)($tournament['winner_point_diff'] ?? 0);
                ?>
                <div data-tournament-panel="<?= h($tourKey) ?>" style="display:none;">
                <?php
                  $roundCompletedMap = [];
                  foreach ($roundGroups as $progressRoundNo => $progressRows) {
                      $roundCompletedMap[$progressRoundNo] = is_round_completed($progressRows);
                  }
                ?>
                <div class="type-head">
                  <h3 style="margin:0;"><?= h($tourLabel) ?> <small style="font-weight:400;color:#5a6b86;"><?= h($type) ?> â€¢ <?= count($typeGames) ?> match</small></h3>
                  <?php if ($type === 'Mexicano'): ?>
                    <?php if ($isTournamentCompleted): ?>
                      <span class="tournament-status-chip ready">Selesai</span>
                    <?php elseif ($canCompleteTournament): ?>
                      <form method="post" data-complete-tournament-form class="tournament-complete-form">
                        <input type="hidden" name="competition_action" value="complete_tournament">
                        <input type="hidden" name="competition_type" value="Mexicano">
                        <input type="hidden" name="tournament_label" value="<?= h($tourLabel) ?>">
                        <button class="btn primary small" type="submit">Selesaikan Tournament</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
                <?php if ($type === 'Mexicano' && $isTournamentCompleted): ?>
                  <p class="note" style="margin:0 0 12px;">
                    Tournament selesai.
                    <?php if ($winnerName !== ''): ?>
                      Juara: <strong><?= h($winnerName) ?></strong> (Total <?= (int)$winnerTotal ?>, Diff <?= (int)$winnerDiff ?>).
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
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
                                $notesRaw = trim((string)($game['notes'] ?? ''));
                                $byeInfo = '';
                                if (preg_match('/(?:^|;)bye:\s*(.+)$/i', $notesRaw, $mBye)) {
                                    $byeInfo = trim((string)($mBye[1] ?? ''));
                                }
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
                                $isScoreInputDisabled = ($isLocked || $isCompleted || !$isRoundUnlocked || $isTournamentCompleted);
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
                                <?php if ($byeInfo !== ''): ?>
                                  <div class="match-meta" style="margin-top:8px;">
                                    <span class="match-caption">Istirahat: <?= h($byeInfo) ?></span>
                                  </div>
                                <?php endif; ?>
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
                <p class="create-step-hint" data-create-step-hint>Pilih tipe game dulu untuk membuka pengaturan lanjutan.</p>
                <div class="create-progress-body" data-create-progress-body hidden>
                  <div class="create-step-block" data-step-block="name">
                    <label for="gameTitle">Nama Game</label>
                    <input id="gameTitle" name="game_title" type="text" maxlength="160" placeholder="Contoh: Week 1" required>
                  </div>
                  <div class="create-step-block" data-step-block="players" hidden>
                    <label for="playerCount">Jumlah Pemain (Random)</label>
                    <div class="player-stepper" data-player-stepper>
                      <button type="button" class="player-stepper-btn" data-player-step="down" aria-label="Kurangi jumlah pemain">-</button>
                      <input id="playerCount" class="player-stepper-input" name="player_count" type="number" min="4" max="<?= max(4, (int)$registeredAttendeeCount) ?>" value="<?= max(4, (int)$registeredAttendeeCount) ?>" inputmode="none" readonly required>
                      <button type="button" class="player-stepper-btn" data-player-step="up" aria-label="Tambah jumlah pemain">+</button>
                    </div>
                  </div>
                  <div class="create-step-block" data-step-block="total" hidden>
                    <label for="matchTotalPoints">Total Poin Match</label>
                    <select id="matchTotalPoints" name="match_total_points" required>
                      <option value="">-- pilih total poin --</option>
                      <?php foreach (PADEL_ALLOWED_TOTAL_POINTS as $tp): ?>
                        <option value="<?= (int)$tp ?>"><?= (int)$tp ?> poin</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="create-step-block" data-step-block="courts" hidden>
                    <label for="courtCount">Jumlah Court Aktif</label>
                    <input id="courtCount" name="court_count" type="number" min="1" max="12" value="1" required>
                  </div>
                  <div class="create-step-block" data-step-block="final" hidden>
                    <div>
                      <label for="gameDate">Tanggal Game (Opsional)</label>
                      <input id="gameDate" name="game_date" type="date">
                    </div>
                    <p class="note" id="courtEstimatorText" hidden>
                      Isi jumlah court untuk hitung ronde logika vs sesi eksekusi. Jika court kurang, sistem otomatis pecah jadi beberapa sesi per ronde.
                    </p>
                    <p class="note" id="generateInfoRandom" hidden>Sistem akan memilih pemain secara acak dari attendee accepted sesuai jumlah yang kamu isi.</p>
                    <p class="note" id="generateInfoScoring" hidden>Scoring: setiap pemain dapat poin sesuai skor timnya (tanpa bonus win/loss). Leaderboard diurutkan dari total poin, lalu selisih poin. Americano pakai format default (single cycle). Mexicano tetap bertahap per ranking.</p>
                    <button class="btn primary" type="submit"><i class="bi bi-diagram-3"></i> Generate Semua Match</button>
                  </div>
                </div>
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
  var alertModal = document.getElementById('competitionAlertModal');
  var alertTitleEl = document.getElementById('competitionAlertTitle');
  var alertMessageEl = document.getElementById('competitionAlertMessage');
  var alertOkBtn = alertModal ? alertModal.querySelector('[data-alert-ok]') : null;
  var alertCancelBtn = alertModal ? alertModal.querySelector('[data-alert-cancel]') : null;
  var acceptedPlayerCount = <?= (int)$registeredAttendeeCount ?>;
  var initialFlashSuccess = <?= json_encode((string)($flash['success'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var initialFlashError = <?= json_encode((string)($flash['error'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function openAlertModal(options) {
    options = options || {};
    var message = String(options.message || '').trim();
    var title = String(options.title || 'Konfirmasi').trim();
    var okText = String(options.okText || 'Ya, lanjut').trim();
    var cancelText = String(options.cancelText || 'Batal').trim();
    if (!alertModal || !alertOkBtn || !alertCancelBtn) {
      return Promise.resolve(false);
    }
    return new Promise(function (resolve) {
      var done = false;
      if (alertTitleEl) alertTitleEl.textContent = title || 'Konfirmasi';
      if (alertMessageEl) alertMessageEl.textContent = message || 'Lanjutkan aksi ini?';
      alertOkBtn.textContent = okText || 'Ya, lanjut';
      alertCancelBtn.textContent = cancelText || 'Batal';
      alertModal.classList.add('is-open');
      alertModal.setAttribute('aria-hidden', 'false');
      window.setTimeout(function () {
        if (alertOkBtn) alertOkBtn.focus();
      }, 0);

      function closeModal(value) {
        if (done) return;
        done = true;
        alertModal.classList.remove('is-open');
        alertModal.setAttribute('aria-hidden', 'true');
        alertOkBtn.removeEventListener('click', onOk);
        alertCancelBtn.removeEventListener('click', onCancel);
        alertModal.removeEventListener('click', onBackdrop);
        document.removeEventListener('keydown', onKeydown);
        resolve(value);
      }

      function onOk() { closeModal(true); }
      function onCancel() { closeModal(false); }
      function onBackdrop(ev) { if (ev.target === alertModal) closeModal(false); }
      function onKeydown(ev) { if (ev.key === 'Escape') closeModal(false); }

      alertOkBtn.addEventListener('click', onOk);
      alertCancelBtn.addEventListener('click', onCancel);
      alertModal.addEventListener('click', onBackdrop);
      document.addEventListener('keydown', onKeydown);
    });
  }

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
      card.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        ev.preventDefault();
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
        openAlertModal({
          title: 'Konfirmasi Hapus',
          message: 'Hapus game ini?',
          okText: 'Hapus',
          cancelText: 'Batal'
        }).then(function (confirmed) {
          if (!confirmed) return;
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
    });

    boardRoot.querySelectorAll('[data-delete-tournament-form]').forEach(function (form) {
      form.addEventListener('click', function (ev) {
        ev.stopPropagation();
      });
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        openAlertModal({
          title: 'Konfirmasi Hapus',
          message: 'Hapus semua match di game ini?',
          okText: 'Hapus',
          cancelText: 'Batal'
        }).then(function (confirmed) {
          if (!confirmed) return;
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
    });

    boardRoot.querySelectorAll('[data-complete-tournament-form]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        openAlertModal({
          title: 'Selesaikan Tournament',
          message: 'Selesaikan tournament Mexicano ini? Setelah selesai, input skor akan dikunci.',
          okText: 'Selesaikan',
          cancelText: 'Batal'
        }).then(function (confirmed) {
          if (!confirmed) return;
          var btn = form.querySelector('button[type="submit"]');
          if (btn) btn.disabled = true;
          postCompetitionForm(form)
            .then(function (data) {
              if (!data || !data.ok) {
                throw new Error((data && data.message) ? data.message : 'Gagal menyelesaikan tournament.');
              }
              showToast(data.message || 'Tournament selesai.', 'success', 4500);
              return refreshCompetitionBoard();
            })
            .catch(function (error) {
              showToast(error && error.message ? error.message : 'Terjadi kesalahan saat menyelesaikan tournament.', 'error', 5000);
            })
            .finally(function () {
              if (btn) btn.disabled = false;
            });
          });
      });
    });
  }

  var board = document.querySelector('[data-live-board]');
  if (board) {
    initBoardInteractions(board);
  }

  function initPlayerCountStepper(createForm) {
    if (!createForm) return;
    var playerEl = createForm.querySelector('#playerCount');
    var wrap = createForm.querySelector('[data-player-stepper]');
    if (!playerEl || !wrap) return;
    if (wrap.dataset.stepperReady === '1') {
      if (typeof createForm.__syncPlayerStepper === 'function') {
        createForm.__syncPlayerStepper();
      }
      return;
    }
    var downBtn = wrap.querySelector('[data-player-step="down"]');
    var upBtn = wrap.querySelector('[data-player-step="up"]');
    var min = parseInt(playerEl.getAttribute('min') || '4', 10);
    var max = parseInt(playerEl.getAttribute('max') || String(acceptedPlayerCount), 10);
    if (!Number.isFinite(min)) min = 4;
    if (!Number.isFinite(max)) max = acceptedPlayerCount;
    if (max < min) max = min;

    function clamp(val) {
      var n = parseInt(String(val || ''), 10);
      if (!Number.isFinite(n)) n = min;
      if (n < min) n = min;
      if (n > max) n = max;
      return n;
    }

    function renderButtons(n) {
      var stepperDisabled = !!playerEl.disabled || !!wrap.closest('[hidden]');
      if (downBtn) downBtn.disabled = stepperDisabled || n <= min;
      if (upBtn) upBtn.disabled = stepperDisabled || n >= max;
    }

    function setValue(next, triggerEvents) {
      var n = clamp(next);
      playerEl.value = String(n);
      renderButtons(n);
      if (triggerEvents) {
        playerEl.dispatchEvent(new Event('input', { bubbles: true }));
        playerEl.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    if (downBtn) {
      downBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (downBtn.disabled) return;
        setValue(clamp(playerEl.value) - 1, true);
      });
    }
    if (upBtn) {
      upBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (upBtn.disabled) return;
        setValue(clamp(playerEl.value) + 1, true);
      });
    }
    playerEl.addEventListener('keydown', function (ev) {
      ev.preventDefault();
    });
    playerEl.addEventListener('wheel', function () {
      playerEl.blur();
    });
    playerEl.addEventListener('input', function () {
      renderButtons(clamp(playerEl.value));
    });
    playerEl.addEventListener('change', function () {
      renderButtons(clamp(playerEl.value));
    });
    createForm.__syncPlayerStepper = function () {
      setValue(playerEl.value, false);
    };
    wrap.dataset.stepperReady = '1';
    setValue(playerEl.value, false);
  }

  function updateCourtEstimator() {
    var createForm = document.querySelector('[data-create-match-form]');
    if (!createForm) return;
    var infoEl = createForm.querySelector('#courtEstimatorText');
    var typeEl = createForm.querySelector('[name=\"competition_type\"]');
    var courtEl = createForm.querySelector('[name=\"court_count\"]');
    var playerEl = createForm.querySelector('[name=\"player_count\"]');
    if (!infoEl || !typeEl || !courtEl || !playerEl) return;

    var type = String(typeEl.value || '');
    var courts = parseInt(courtEl.value || '1', 10);
    var selectedPlayers = parseInt(playerEl.value || String(acceptedPlayerCount), 10);
    if (!Number.isFinite(courts) || courts < 1) courts = 1;
    if (courts > 12) courts = 12;
    if (!Number.isFinite(selectedPlayers)) selectedPlayers = acceptedPlayerCount;
    if (selectedPlayers < 4) selectedPlayers = 4;
    if (selectedPlayers > acceptedPlayerCount) selectedPlayers = acceptedPlayerCount;
    if (String(playerEl.value || '') !== String(selectedPlayers)) {
      playerEl.value = String(selectedPlayers);
    }
    var maxMatchesPerLogicalRound = (type === 'Mexicano')
      ? Math.ceil(selectedPlayers / 4)
      : Math.floor(selectedPlayers / 4);
    if (maxMatchesPerLogicalRound <= 0) {
      infoEl.textContent = 'Attendee belum cukup. Minimal 4 pemain untuk 1 match.';
      return;
    }
    if (courts > maxMatchesPerLogicalRound) {
      courts = maxMatchesPerLogicalRound;
    }
    var wavesPerLogicalRound = Math.ceil(maxMatchesPerLogicalRound / Math.max(1, courts));
    if (type === 'Americano') {
      if ((selectedPlayers % 2) !== 0 || (selectedPlayers % 4) !== 0) {
        infoEl.textContent = 'Americano butuh jumlah pemain kelipatan 4. Sekarang: ' + selectedPlayers + ' pemain.';
        return;
      }
      var logicalRounds = Math.max(1, selectedPlayers - 1);
      var estimatedSlots = logicalRounds * wavesPerLogicalRound;
      infoEl.textContent = 'Americano: ' + selectedPlayers + ' pemain random, ' + courts + ' court aktif, ' + logicalRounds + ' ronde logika, ' + wavesPerLogicalRound + ' sesi/ronde, total ' + estimatedSlots + ' sesi.';
      return;
    }
    if (type === 'Mexicano') {
      var recommendedRounds = 6;
      var estimatedMexSlots = recommendedRounds * wavesPerLogicalRound;
      infoEl.textContent = 'Mexicano: ' + selectedPlayers + ' pemain random. Dengan ' + courts + ' court aktif, estimasi ' + wavesPerLogicalRound + ' wave/ronde, rekomendasi awal ' + recommendedRounds + ' ronde (' + estimatedMexSlots + ' slot ronde).';
      return;
    }
    infoEl.textContent = 'Pilih tipe game dulu untuk lihat estimasi ronde dari jumlah court.';
  }

  function syncCreateFormProgress() {
    var createForm = document.querySelector('[data-create-match-form]');
    if (!createForm) return;
    var typeEl = createForm.querySelector('[name=\"competition_type\"]');
    var progressBody = createForm.querySelector('[data-create-progress-body]');
    var stepHint = createForm.querySelector('[data-create-step-hint]');
    var nameEl = createForm.querySelector('[name=\"game_title\"]');
    var playerEl = createForm.querySelector('[name=\"player_count\"]');
    var totalEl = createForm.querySelector('[name=\"match_total_points\"]');
    var courtEl = createForm.querySelector('[name=\"court_count\"]');
    var nameBlock = createForm.querySelector('[data-step-block=\"name\"]');
    var playersBlock = createForm.querySelector('[data-step-block=\"players\"]');
    var totalBlock = createForm.querySelector('[data-step-block=\"total\"]');
    var courtsBlock = createForm.querySelector('[data-step-block=\"courts\"]');
    var finalBlock = createForm.querySelector('[data-step-block=\"final\"]');
    if (!typeEl || !progressBody) return;

    var hasType = String(typeEl.value || '').trim() !== '';
    progressBody.hidden = !hasType;
    if (nameBlock) nameBlock.hidden = !hasType;

    var hasName = hasType && nameEl && String(nameEl.value || '').trim() !== '';
    if (playersBlock) playersBlock.hidden = !hasName;

    var playerCount = playerEl ? parseInt(playerEl.value || '', 10) : NaN;
    var hasPlayers = hasName && Number.isFinite(playerCount) && playerCount >= 4;
    if (totalBlock) totalBlock.hidden = !hasPlayers;

    var totalPoints = totalEl ? parseInt(totalEl.value || '', 10) : NaN;
    var hasTotal = hasPlayers && Number.isFinite(totalPoints) && totalPoints > 0;
    if (courtsBlock) courtsBlock.hidden = !hasTotal;

    var courtCount = courtEl ? parseInt(courtEl.value || '', 10) : NaN;
    var hasCourt = hasTotal && Number.isFinite(courtCount) && courtCount >= 1;
    if (finalBlock) finalBlock.hidden = !hasCourt;

    progressBody.querySelectorAll('.create-step-block').forEach(function (block) {
      var enabled = !block.hidden && hasType;
      block.querySelectorAll('input, select, textarea, button').forEach(function (el) {
        el.disabled = !enabled;
      });
    });
    if (typeof createForm.__syncPlayerStepper === 'function') {
      createForm.__syncPlayerStepper();
    }
    if (stepHint) {
      stepHint.style.display = hasType ? 'none' : '';
    }
  }

  document.addEventListener('click', function (ev) {
    var openBtn = ev.target && ev.target.closest ? ev.target.closest('[data-open-game-input-modal]') : null;
    if (openBtn) {
      var createForm = document.querySelector('[data-create-match-form]');
      if (createForm) createForm.reset();
      initPlayerCountStepper(createForm);
      setGameInputModalState(true);
      syncCreateFormProgress();
      updateCourtEstimator();
      return;
    }

    var closeBtn = ev.target && ev.target.closest ? ev.target.closest('[data-close-game-input-modal]') : null;
    if (closeBtn) {
      setGameInputModalState(false);
      return;
    }

    // Do not close game input modal on backdrop click.
    // This avoids accidental close when using native select dropdowns.
  });

  document.addEventListener('change', function (ev) {
    var target = ev.target;
    if (!target || !target.matches) return;
    if (
      target.matches('[data-create-match-form] [name="competition_type"]') ||
      target.matches('[data-create-match-form] [name="game_title"]') ||
      target.matches('[data-create-match-form] [name="player_count"]') ||
      target.matches('[data-create-match-form] [name="match_total_points"]') ||
      target.matches('[data-create-match-form] [name="court_count"]')
    ) {
      syncCreateFormProgress();
      updateCourtEstimator();
    }
  });
  document.addEventListener('input', function (ev) {
    var target = ev.target;
    if (!target || !target.matches) return;
    if (
      target.matches('[data-create-match-form] [name="game_title"]') ||
      target.matches('[data-create-match-form] [name="player_count"]') ||
      target.matches('[data-create-match-form] [name="court_count"]')
    ) {
      syncCreateFormProgress();
      updateCourtEstimator();
    }
  });
  syncCreateFormProgress();
  initPlayerCountStepper(document.querySelector('[data-create-match-form]'));
  updateCourtEstimator();

  document.addEventListener('submit', function (ev) {
    var createForm = ev.target && ev.target.closest ? ev.target.closest('[data-create-match-form]') : null;
    if (!createForm) return;
    ev.preventDefault();
    var runGenerate = function () {
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
    };

    var estimatorEl = createForm.querySelector('#courtEstimatorText');
    var infoRandomEl = createForm.querySelector('#generateInfoRandom');
    var infoScoringEl = createForm.querySelector('#generateInfoScoring');
    var modalLines = [];
    if (estimatorEl && String(estimatorEl.textContent || '').trim() !== '') {
      modalLines.push(String(estimatorEl.textContent || '').trim());
    }
    if (infoRandomEl && String(infoRandomEl.textContent || '').trim() !== '') {
      modalLines.push(String(infoRandomEl.textContent || '').trim());
    }
    if (infoScoringEl && String(infoScoringEl.textContent || '').trim() !== '') {
      modalLines.push(String(infoScoringEl.textContent || '').trim());
    }

    openAlertModal({
      title: 'Konfirmasi Generate Match',
      message: modalLines.join('\n\n') || 'Lanjutkan generate match?',
      okText: 'Lanjut Generate',
      cancelText: 'Kembali'
    }).then(function (confirmed) {
      if (!confirmed) return;
      runGenerate();
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


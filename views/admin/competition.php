<?php
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../layout/app.php';
require_admin();

$db = get_db();
ensure_session();
$flash = ['success' => '', 'error' => ''];
if (isset($_SESSION['competition_flash']) && is_array($_SESSION['competition_flash'])) {
    $flash['success'] = (string)($_SESSION['competition_flash']['success'] ?? '');
    $flash['error'] = (string)($_SESSION['competition_flash']['error'] ?? '');
    unset($_SESSION['competition_flash']);
}
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
    $total = $scoreA + $scoreB;
    if ($total !== $selectedTotal) {
        return 'Total skor harus sama dengan total poin yang dipilih (' . $selectedTotal . ').';
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

try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS competition_games (
            id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NULL,
            competition_type VARCHAR(20) NULL,
            game_title VARCHAR(160) NOT NULL,
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
            INDEX idx_competition_package_id (package_id),
            INDEX idx_competition_game_date (game_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    $flash['error'] = 'Gagal menyiapkan tabel competition.';
}

try {
    // Backward-compatible migration for existing table versions.
    $currentDb = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($currentDb !== '') {
        $ensureColumn = static function (PDO $db, string $schema, string $column, string $definition): void {
            $columnCheck = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'competition_games' AND COLUMN_NAME = ?"
            );
            $columnCheck->execute([$schema, $column]);
            $exists = (int)$columnCheck->fetchColumn() > 0;
            if (!$exists) {
                $db->exec("ALTER TABLE competition_games ADD COLUMN {$column} {$definition}");
            }
        };

        $ensureColumn($db, $currentDb, 'competition_type', "VARCHAR(20) NULL AFTER package_id");
        $ensureColumn($db, $currentDb, 'player_a_user_id', "INT NULL AFTER game_title");
        $ensureColumn($db, $currentDb, 'player_a_name', "VARCHAR(150) NULL AFTER player_a_user_id");
        $ensureColumn($db, $currentDb, 'player_b_user_id', "INT NULL AFTER player_a_name");
        $ensureColumn($db, $currentDb, 'player_b_name', "VARCHAR(150) NULL AFTER player_b_user_id");
        $ensureColumn($db, $currentDb, 'score_a', "INT NULL AFTER player_b_name");
        $ensureColumn($db, $currentDb, 'score_b', "INT NULL AFTER score_a");
    }

    $db->exec("ALTER TABLE competition_games MODIFY package_id INT NULL");
} catch (Throwable $e) {
    // Keep page functional even if migration partially fails.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['competition_action'] ?? 'create_match'));
    $allowedTypes = ['Americano', 'Mexicano'];

    if ($action === 'update_score') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        [$scoreA, $scoreB, $selectedTotal, $scoreParseError] = parse_score_request($_POST);

        if ($gameId <= 0) {
            $flash['error'] = 'ID game tidak valid.';
        } elseif ($scoreParseError !== '') {
            $flash['error'] = $scoreParseError;
        } else {
            $scoreError = validate_padel_score($scoreA, $scoreB, $selectedTotal);
            if ($scoreError !== '') {
                $flash['error'] = $scoreError;
            }
        }
        if ($flash['error'] === '') {
            try {
                $update = $db->prepare('UPDATE competition_games SET score_a = ?, score_b = ? WHERE id = ?');
                $update->execute([$scoreA, $scoreB, $gameId]);
                $flash['success'] = 'Skor game berhasil diperbarui.';
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal memperbarui skor game.';
            }
        }
    } elseif ($action === 'delete_game') {
        $gameId = (int)($_POST['game_id'] ?? 0);
        if ($gameId <= 0) {
            $flash['error'] = 'ID game tidak valid.';
        } else {
            try {
                $delete = $db->prepare('DELETE FROM competition_games WHERE id = ? LIMIT 1');
                $delete->execute([$gameId]);
                if ($delete->rowCount() > 0) {
                    $flash['success'] = 'Game berhasil dihapus.';
                } else {
                    $flash['error'] = 'Game tidak ditemukan.';
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menghapus game.';
            }
        }
    } else {
        $competitionType = trim((string)($_POST['competition_type'] ?? ''));
        $gameTitle = trim((string)($_POST['game_title'] ?? ''));
        $gameDateRaw = trim((string)($_POST['game_date'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        [$scoreA, $scoreB, $selectedTotal, $scoreParseError] = parse_score_request($_POST);

        if (!in_array($competitionType, $allowedTypes, true)) {
            $flash['error'] = 'Pilih tipe game yang valid.';
        } elseif ($gameTitle !== '' && mb_strlen($gameTitle) > 160) {
            $flash['error'] = 'Nama game terlalu panjang (maksimal 160 karakter).';
        } elseif ($gameDateRaw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $gameDateRaw)) {
            $flash['error'] = 'Format tanggal tidak valid.';
        } elseif ($scoreParseError !== '') {
            $flash['error'] = $scoreParseError;
        } else {
            $scoreError = validate_padel_score($scoreA, $scoreB, $selectedTotal);
            if ($scoreError !== '') {
                $flash['error'] = $scoreError;
            }
        }
        if ($flash['error'] === '') {
            try {
                $randomPlayers = $db->query('SELECT id, full_name FROM users ORDER BY RAND() LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
                if (count($randomPlayers) < 2) {
                    $flash['error'] = 'Data peserta register belum cukup (minimal 2 orang).';
                } else {
                    $playerAId = (int)($randomPlayers[0]['id'] ?? 0);
                    $playerAName = trim((string)($randomPlayers[0]['full_name'] ?? 'Player A'));
                    $playerBId = (int)($randomPlayers[1]['id'] ?? 0);
                    $playerBName = trim((string)($randomPlayers[1]['full_name'] ?? 'Player B'));
                    $resolvedTitle = $gameTitle !== '' ? $gameTitle : ('Match ' . $competitionType . ' ' . date('d/m H:i'));

                    $insert = $db->prepare(
                        'INSERT INTO competition_games (
                            package_id, competition_type, game_title,
                            player_a_user_id, player_a_name, player_b_user_id, player_b_name,
                            score_a, score_b, game_date, notes, created_by_admin_id, created_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        null,
                        $competitionType,
                        $resolvedTitle,
                        $playerAId > 0 ? $playerAId : null,
                        $playerAName !== '' ? $playerAName : 'Player A',
                        $playerBId > 0 ? $playerBId : null,
                        $playerBName !== '' ? $playerBName : 'Player B',
                        $scoreA,
                        $scoreB,
                        $gameDateRaw !== '' ? $gameDateRaw : null,
                        $notes !== '' ? $notes : null,
                        $adminId > 0 ? $adminId : null,
                        date('Y-m-d H:i:s'),
                    ]);
                    $flash['success'] = 'Game competition berhasil dibuat dengan pemain random dari data register.';
                }
            } catch (Throwable $e) {
                $flash['error'] = 'Gagal menambahkan game competition.';
            }
        }
    }

    $redirectMatchPage = (int)($_POST['match_page'] ?? 1);
    if ($redirectMatchPage < 1) {
        $redirectMatchPage = 1;
    }

    $_SESSION['competition_flash'] = [
        'success' => (string)($flash['success'] ?? ''),
        'error' => (string)($flash['error'] ?? ''),
    ];
    $redirectTarget = '/admin/competition';
    if ($redirectMatchPage > 1) {
        $redirectTarget .= '?match_page=' . $redirectMatchPage;
    }
    redirect($redirectTarget);
}

$games = [];
try {
    $gamesStmt = $db->query(
        "SELECT
            cg.id,
            cg.game_title,
            cg.game_date,
            cg.notes,
            cg.created_at,
            cg.competition_type,
            cg.player_a_user_id,
            cg.player_a_name,
            cg.player_b_user_id,
            cg.player_b_name,
            cg.score_a,
            cg.score_b,
            p.name AS package_name
         FROM competition_games cg
         LEFT JOIN packages p ON p.id = cg.package_id
         ORDER BY COALESCE(cg.game_date, DATE(cg.created_at)) DESC, cg.id DESC"
    );
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $games = [];
}

$matchesPerPage = 10;
$requestedMatchPage = (int)($_GET['match_page'] ?? 1);
if ($requestedMatchPage < 1) {
    $requestedMatchPage = 1;
}
$totalMatchCount = count($games);
$totalMatchPages = max(1, (int)ceil($totalMatchCount / $matchesPerPage));
$currentMatchPage = min($requestedMatchPage, $totalMatchPages);
$matchOffset = ($currentMatchPage - 1) * $matchesPerPage;
$gamesForTable = array_slice($games, $matchOffset, $matchesPerPage);

$registeredUserCount = 0;
try {
    $registeredUserCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $registeredUserCount = 0;
}

$bracketTypes = ['Americano', 'Mexicano'];
$gamesByType = [
    'Americano' => [],
    'Mexicano' => [],
];
foreach ($games as $game) {
    $type = trim((string)($game['competition_type'] ?? ''));
    if ($type === '') {
        $type = trim((string)($game['package_name'] ?? ''));
    }
    $normalizedType = normalize_competition_type($type);
    if ($normalizedType !== '') {
        $gamesByType[$normalizedType][] = $game;
    }
}

$bracketBoardsByType = [];
foreach ($bracketTypes as $type) {
    $seedNames = [];
    foreach ($gamesByType[$type] as $row) {
        $playerA = trim((string)($row['player_a_name'] ?? ''));
        $playerB = trim((string)($row['player_b_name'] ?? ''));
        if ($playerA !== '') {
            $seedNames[] = $playerA;
        }
        if ($playerB !== '') {
            $seedNames[] = $playerB;
        }
    }
    $seedNames = array_values(array_unique($seedNames));

    $chunks = array_chunk($seedNames, 16);
    $boards = [];
    foreach ($chunks as $chunk) {
        $boards[] = array_pad($chunk, 16, 'TBD');
    }
    if (!$boards) {
        $boards[] = array_fill(0, 16, 'TBD');
    }
    $bracketBoardsByType[$type] = $boards;
}

$standingsByType = [
    'Americano' => [],
    'Mexicano' => [],
];
foreach ($gamesByType as $type => $rows) {
    $table = [];
    foreach ($rows as $row) {
        $playerAName = trim((string)($row['player_a_name'] ?? ''));
        $playerBName = trim((string)($row['player_b_name'] ?? ''));
        $scoreA = isset($row['score_a']) && $row['score_a'] !== null ? (int)$row['score_a'] : null;
        $scoreB = isset($row['score_b']) && $row['score_b'] !== null ? (int)$row['score_b'] : null;
        if ($playerAName === '' || $playerBName === '' || $scoreA === null || $scoreB === null) {
            continue;
        }
        $scoreError = validate_padel_score($scoreA, $scoreB, $scoreA + $scoreB);
        if ($scoreError !== '') {
            continue;
        }

        if (!isset($table[$playerAName])) {
            $table[$playerAName] = ['name' => $playerAName, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'point_total' => 0];
        }
        if (!isset($table[$playerBName])) {
            $table[$playerBName] = ['name' => $playerBName, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'point_total' => 0];
        }

        $table[$playerAName]['match']++;
        $table[$playerBName]['match']++;
        $table[$playerAName]['pf'] += $scoreA;
        $table[$playerAName]['pa'] += $scoreB;
        $table[$playerAName]['point_total'] += $scoreA;
        $table[$playerBName]['pf'] += $scoreB;
        $table[$playerBName]['pa'] += $scoreA;
        $table[$playerBName]['point_total'] += $scoreB;

        if ($scoreA > $scoreB) {
            $table[$playerAName]['win']++;
            $table[$playerBName]['lose']++;
        } elseif ($scoreA < $scoreB) {
            $table[$playerBName]['win']++;
            $table[$playerAName]['lose']++;
        } else {
            $table[$playerAName]['draw']++;
            $table[$playerBName]['draw']++;
        }
    }

    $rows = array_values($table);
    usort($rows, static function (array $a, array $b): int {
        $diffA = (int)$a['pf'] - (int)$a['pa'];
        $diffB = (int)$b['pf'] - (int)$b['pa'];
        if ((int)$a['point_total'] !== (int)$b['point_total']) {
            return (int)$b['point_total'] <=> (int)$a['point_total'];
        }
        if ($diffA !== $diffB) {
            return $diffB <=> $diffA;
        }
        if ((int)$a['win'] !== (int)$b['win']) {
            return (int)$b['win'] <=> (int)$a['win'];
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    $standingsByType[$type] = $rows;
}

$extraHead = <<<HTML
<style>
  .competition-grid {
    display: grid;
    grid-template-columns: minmax(280px, 380px) minmax(0, 1fr);
    gap: 16px;
    align-items: start;
  }
  .competition-card {
    background: rgba(255,255,255,.9);
    border: 1px solid rgba(15,32,60,.12);
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 8px 26px rgba(15,32,60,.08);
  }
  .competition-card h2 {
    margin: 0 0 12px;
    font-size: 18px;
    color: #10284a;
  }
  .alert.success {
    background: #e8f8ee;
    border: 1px solid #b7e6c4;
    color: #18633a;
  }
  .alert.success i {
    color: #18633a;
  }
  .alert.error {
    background: #fdeeee;
    border: 1px solid #f3bcbc;
    color: #b43636;
  }
  .competition-form {
    display: grid;
    gap: 10px;
  }
  .competition-form label {
    font-size: 12px;
    color: #415a80;
    font-weight: 700;
  }
  .competition-form input,
  .competition-form select,
  .competition-form textarea {
    width: 100%;
    border-radius: 10px;
    border: 1px solid #c6d4ea;
    padding: 10px 11px;
    background: #fff;
  }
  .competition-form textarea {
    min-height: 88px;
    resize: vertical;
  }
  .bracket-board {
    margin-top: 10px;
    border: 1px solid #dce6f8;
    border-radius: 12px;
    padding: 12px;
    background: #f9fbff;
  }
  .bracket-board + .bracket-board {
    margin-top: 14px;
  }
  .bracket-label {
    font-weight: 800;
    color: #0f2a50;
    margin-bottom: 10px;
    font-size: 14px;
    letter-spacing: .3px;
    text-transform: uppercase;
  }
  .bracket-grid {
    display: grid;
    grid-template-columns: 1.35fr .95fr .7fr .95fr 1.35fr;
    gap: 14px;
    align-items: center;
    min-height: 340px;
  }
  .round-col {
    display: grid;
    gap: 12px;
  }
  .round-col.center {
    place-items: center;
  }
  .match {
    position: relative;
    border: 2px solid #2e323b;
    border-radius: 4px;
    background: #fff;
    padding: 0;
    overflow: hidden;
  }
  .seed {
    min-height: 32px;
    padding: 7px 8px;
    font-size: 12px;
    color: #172f53;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #d5deed;
    overflow-wrap: anywhere;
  }
  .seed:last-child {
    border-bottom: 0;
  }
  .seed small {
    font-size: 11px;
    color: #526786;
    margin-right: 6px;
  }
  .left-r1 .match::after,
  .left-r2 .match::after {
    content: "";
    position: absolute;
    right: -14px;
    top: 50%;
    width: 14px;
    border-top: 2px solid #2e323b;
    transform: translateY(-50%);
  }
  .right-r1 .match::before,
  .right-r2 .match::before {
    content: "";
    position: absolute;
    left: -14px;
    top: 50%;
    width: 14px;
    border-top: 2px solid #2e323b;
    transform: translateY(-50%);
  }
  .final-box {
    border: 2px solid #2e323b;
    background: #fff;
    border-radius: 6px;
    padding: 12px 16px;
    font-weight: 900;
    color: #142b4c;
    letter-spacing: .4px;
  }
  .final-box::before,
  .final-box::after {
    content: "";
    position: absolute;
    width: 16px;
    border-top: 2px solid #2e323b;
    top: 50%;
    transform: translateY(-50%);
  }
  .final-box {
    position: relative;
  }
  .final-box::before {
    left: -16px;
  }
  .final-box::after {
    right: -16px;
  }
  .competition-table-wrap {
    overflow-x: auto;
    margin-top: 14px;
  }
  table.competition-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 620px;
  }
  .competition-table th,
  .competition-table td {
    text-align: left;
    padding: 10px 8px;
    border-bottom: 1px solid #e7eef9;
    font-size: 13px;
    color: #183054;
    vertical-align: top;
  }
  .competition-table th {
    font-size: 12px;
    color: #5a6b86;
    text-transform: uppercase;
    letter-spacing: .3px;
  }
  .competition-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
  }
  .score-form {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .score-form select {
    min-width: 108px;
  }
  .score-form input[type="number"] {
    width: 64px;
    border: 1px solid #c6d4ea;
    border-radius: 8px;
    padding: 6px 7px;
  }
  .score-separator {
    font-weight: 700;
    color: #39557e;
  }
  .table-actions {
    display: grid;
    gap: 6px;
  }
  .competition-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 12px;
  }
  .pagination-meta {
    font-size: 12px;
    color: #4c6388;
  }
  .pagination-links {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
  }
  .page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    border-radius: 8px;
    border: 1px solid #bfd0ea;
    background: #fff;
    color: #163966;
    padding: 7px 9px;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
  }
  .page-link.current {
    background: #1a66e9;
    border-color: #1a66e9;
    color: #fff;
  }
  .page-ellipsis {
    color: #6a7f9f;
    font-weight: 700;
    padding: 0 2px;
    min-width: 18px;
    text-align: center;
  }
  .page-link.disabled {
    opacity: .45;
    pointer-events: none;
  }
  .score-rule-note {
    font-size: 12px;
    color: #385885;
    background: #edf4ff;
    border: 1px solid #d3e3ff;
    border-radius: 10px;
    padding: 8px 10px;
  }
  .standing-wrap {
    overflow-x: auto;
    margin-top: 10px;
  }
  table.standing-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 560px;
  }
  .standing-table th,
  .standing-table td {
    text-align: left;
    padding: 8px 7px;
    border-bottom: 1px solid #dfe8f8;
    font-size: 12px;
    color: #183054;
  }
  .standing-table th {
    font-size: 11px;
    color: #5a6b86;
    text-transform: uppercase;
    letter-spacing: .2px;
  }
  @media (max-width: 980px) {
    .competition-grid {
      grid-template-columns: 1fr;
    }
    .bracket-grid {
      grid-template-columns: 1fr;
      min-height: 0;
    }
    .left-r1 .match::after,
    .left-r2 .match::after,
    .right-r1 .match::before,
    .right-r2 .match::before,
    .final-box::before,
    .final-box::after {
      display: none;
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
        <p class="admin-sub">Buat game Americano/Mexicano dan lihat bagan data game yang sudah ditambahkan admin.</p>
      </div>
      <div class="competition-actions">
        <a class="btn ghost" href="/admin/dashboard"><i class="bi bi-arrow-left"></i> Dashboard</a>
      </div>
    </div>

    <?php if (!empty($flash['success'])): ?>
      <div class="alert success"><i class="bi bi-check-circle"></i> <?= h($flash['success']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
      <div class="alert error"><i class="bi bi-exclamation-triangle"></i> <?= h($flash['error']) ?></div>
    <?php endif; ?>

    <section class="competition-grid">
      <div class="competition-card">
        <h2><i class="bi bi-plus-circle"></i> Tambah Game</h2>
        <form method="post" class="competition-form">
          <input type="hidden" name="competition_action" value="create_match">
          <input type="hidden" name="match_page" value="<?= (int)$currentMatchPage ?>">
          <div>
            <label for="competitionType">Tipe Game</label>
            <select id="competitionType" name="competition_type" required>
              <option value="">-- pilih --</option>
              <option value="Americano">Americano</option>
              <option value="Mexicano">Mexicano</option>
            </select>
          </div>
          <div>
            <label for="gameTitle">Label Match (Opsional)</label>
            <input id="gameTitle" name="game_title" type="text" maxlength="160" placeholder="Contoh: Quarter Final 1">
          </div>
          <div>
            <label for="gameDate">Tanggal Game</label>
            <input id="gameDate" name="game_date" type="date">
          </div>
          <div>
            <label for="scoreA">Skor (Opsional)</label>
            <div class="score-form">
              <select id="scoreA" name="score_total">
                <option value="">-- total --</option>
                <?php foreach (PADEL_ALLOWED_TOTAL_POINTS as $totalPoint): ?>
                  <option value="<?= (int)$totalPoint ?>"><?= (int)$totalPoint ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" name="score_a" min="0" placeholder="A">
              <span class="score-separator">-</span>
              <input type="number" name="score_b" min="0" placeholder="B">
            </div>
          </div>
          <div>
            <label for="gameNotes">Catatan</label>
            <textarea id="gameNotes" name="notes" placeholder="Opsional"></textarea>
          </div>
          <p class="admin-sub" style="margin:0;">Pemain akan diambil random dari data register (total: <strong><?= (int)$registeredUserCount ?></strong> orang).</p>
          <p class="score-rule-note">Rule skor padel Americano/Mexicano: total poin per match pilih salah satu <strong><?= h(implode(', ', PADEL_ALLOWED_TOTAL_POINTS)) ?></strong>.</p>
          <button class="btn primary" type="submit"><i class="bi bi-shuffle"></i> Buat Match Random</button>
        </form>
      </div>

      <div class="competition-card">
        <h2><i class="bi bi-bar-chart-line"></i> Bagan Competition</h2>
        <?php foreach ($bracketTypes as $type): ?>
          <?php
            $boards = $bracketBoardsByType[$type] ?? [array_fill(0, 16, 'TBD')];
            $allFilledCount = 0;
            foreach ($boards as $boardSlots) {
                foreach ($boardSlots as $slotName) {
                    if ($slotName !== 'TBD') {
                        $allFilledCount++;
                    }
                }
            }
          ?>
          <?php foreach ($boards as $boardIdx => $slots): ?>
            <div class="bracket-board">
              <div class="bracket-label"><?= h($type) ?> - Board <?= (int)($boardIdx + 1) ?> (16 Slot, total <?= (int)$allFilledCount ?> player)</div>
              <div class="bracket-grid">
                <div class="round-col left-r1">
                  <div class="match">
                    <div class="seed"><small>1</small><?= h($slots[0]) ?></div>
                    <div class="seed"><small>2</small><?= h($slots[1]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>3</small><?= h($slots[2]) ?></div>
                    <div class="seed"><small>4</small><?= h($slots[3]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>5</small><?= h($slots[4]) ?></div>
                    <div class="seed"><small>6</small><?= h($slots[5]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>7</small><?= h($slots[6]) ?></div>
                    <div class="seed"><small>8</small><?= h($slots[7]) ?></div>
                  </div>
                </div>

                <div class="round-col left-r2">
                  <div class="match">
                    <div class="seed">Winner Match 1</div>
                    <div class="seed">Winner Match 2</div>
                  </div>
                  <div class="match">
                    <div class="seed">Winner Match 3</div>
                    <div class="seed">Winner Match 4</div>
                  </div>
                </div>

                <div class="round-col center">
                  <div class="final-box">FINAL</div>
                </div>

                <div class="round-col right-r2">
                  <div class="match">
                    <div class="seed">Winner Match 5</div>
                    <div class="seed">Winner Match 6</div>
                  </div>
                  <div class="match">
                    <div class="seed">Winner Match 7</div>
                    <div class="seed">Winner Match 8</div>
                  </div>
                </div>

                <div class="round-col right-r1">
                  <div class="match">
                    <div class="seed"><small>9</small><?= h($slots[8]) ?></div>
                    <div class="seed"><small>10</small><?= h($slots[9]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>11</small><?= h($slots[10]) ?></div>
                    <div class="seed"><small>12</small><?= h($slots[11]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>13</small><?= h($slots[12]) ?></div>
                    <div class="seed"><small>14</small><?= h($slots[13]) ?></div>
                  </div>
                  <div class="match">
                    <div class="seed"><small>15</small><?= h($slots[14]) ?></div>
                    <div class="seed"><small>16</small><?= h($slots[15]) ?></div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="standing-wrap">
            <table class="standing-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Player</th>
                  <th>M</th>
                  <th>W</th>
                  <th>D</th>
                  <th>L</th>
                  <th>PF</th>
                  <th>PA</th>
                  <th>Diff</th>
                  <th>Total Poin</th>
                </tr>
              </thead>
              <tbody>
                <?php $standingRows = $standingsByType[$type] ?? []; ?>
                <?php if (!$standingRows): ?>
                  <tr><td colspan="10">Belum ada skor valid untuk klasemen <?= h($type) ?>.</td></tr>
                <?php else: ?>
                  <?php foreach ($standingRows as $sIdx => $standing): ?>
                    <?php $diff = (int)($standing['pf'] ?? 0) - (int)($standing['pa'] ?? 0); ?>
                    <tr>
                      <td><?= (int)($sIdx + 1) ?></td>
                      <td><?= h((string)($standing['name'] ?? '-')) ?></td>
                      <td><?= (int)($standing['match'] ?? 0) ?></td>
                      <td><?= (int)($standing['win'] ?? 0) ?></td>
                      <td><?= (int)($standing['draw'] ?? 0) ?></td>
                      <td><?= (int)($standing['lose'] ?? 0) ?></td>
                      <td><?= (int)($standing['pf'] ?? 0) ?></td>
                      <td><?= (int)($standing['pa'] ?? 0) ?></td>
                      <td><?= $diff >= 0 ? '+' . $diff : (string)$diff ?></td>
                      <td><strong><?= (int)($standing['point_total'] ?? 0) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>

        <div class="competition-table-wrap">
          <table class="competition-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Competition</th>
                <th>Game</th>
                <th>Player A</th>
                <th>Player B</th>
                <th>Skor</th>
                <th>Tanggal</th>
                <th>Catatan</th>
                <th>Dibuat</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$gamesForTable): ?>
                <tr>
                  <td colspan="10">Belum ada game competition.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($gamesForTable as $idx => $game): ?>
                  <?php
                    $competitionLabel = trim((string)($game['competition_type'] ?? ''));
                    if ($competitionLabel === '') {
                        $competitionLabel = trim((string)($game['package_name'] ?? '-'));
                    }
                    $playerAName = trim((string)($game['player_a_name'] ?? ''));
                    $playerBName = trim((string)($game['player_b_name'] ?? ''));
                    $scoreAVal = isset($game['score_a']) && $game['score_a'] !== null ? (int)$game['score_a'] : null;
                    $scoreBVal = isset($game['score_b']) && $game['score_b'] !== null ? (int)$game['score_b'] : null;
                    $currentScoreTotal = ($scoreAVal !== null && $scoreBVal !== null) ? ((int)$scoreAVal + (int)$scoreBVal) : 0;
                  ?>
                  <tr>
                    <td><?= (int)($matchOffset + $idx + 1) ?></td>
                    <td><?= h($competitionLabel !== '' ? $competitionLabel : '-') ?></td>
                    <td><?= h((string)($game['game_title'] ?? '-')) ?></td>
                    <td><?= h($playerAName !== '' ? $playerAName : '-') ?></td>
                    <td><?= h($playerBName !== '' ? $playerBName : '-') ?></td>
                    <td><?= h(($scoreAVal !== null ? (string)$scoreAVal : '-') . ' - ' . ($scoreBVal !== null ? (string)$scoreBVal : '-')) ?></td>
                    <td><?= h((string)($game['game_date'] ?? '-')) ?></td>
                    <td><?= h((string)($game['notes'] ?? '-')) ?></td>
                    <td><?= h((string)($game['created_at'] ?? '-')) ?></td>
                    <td>
                      <div class="table-actions">
                        <form method="post" class="score-form">
                          <input type="hidden" name="competition_action" value="update_score">
                          <input type="hidden" name="game_id" value="<?= (int)($game['id'] ?? 0) ?>">
                          <input type="hidden" name="match_page" value="<?= (int)$currentMatchPage ?>">
                          <select name="score_total">
                            <option value="">-- total --</option>
                            <?php foreach (PADEL_ALLOWED_TOTAL_POINTS as $totalPoint): ?>
                              <option value="<?= (int)$totalPoint ?>"<?= $currentScoreTotal === (int)$totalPoint ? ' selected' : '' ?>><?= (int)$totalPoint ?></option>
                            <?php endforeach; ?>
                          </select>
                          <input type="number" name="score_a" min="0" value="<?= $scoreAVal !== null ? (int)$scoreAVal : '' ?>" placeholder="A">
                          <span class="score-separator">-</span>
                          <input type="number" name="score_b" min="0" value="<?= $scoreBVal !== null ? (int)$scoreBVal : '' ?>" placeholder="B">
                          <button class="btn ghost small" type="submit">Save</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Hapus game ini?');">
                          <input type="hidden" name="competition_action" value="delete_game">
                          <input type="hidden" name="game_id" value="<?= (int)($game['id'] ?? 0) ?>">
                          <input type="hidden" name="match_page" value="<?= (int)$currentMatchPage ?>">
                          <button class="btn ghost small" type="submit">Hapus</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
          <?php
            $rangeStart = $totalMatchCount > 0 ? ($matchOffset + 1) : 0;
            $rangeEnd = $matchOffset + count($gamesForTable);
            $prevMatchPage = max(1, $currentMatchPage - 1);
            $nextMatchPage = min($totalMatchPages, $currentMatchPage + 1);
            $pageCandidates = [1, $totalMatchPages, $currentMatchPage - 1, $currentMatchPage, $currentMatchPage + 1];
            $visiblePages = [];
            foreach ($pageCandidates as $candidate) {
                $candidate = (int)$candidate;
                if ($candidate < 1 || $candidate > $totalMatchPages) {
                    continue;
                }
                if (!in_array($candidate, $visiblePages, true)) {
                    $visiblePages[] = $candidate;
                }
            }
            sort($visiblePages, SORT_NUMERIC);
          ?>
          <div class="competition-pagination">
            <div class="pagination-meta">
              Menampilkan <?= (int)$rangeStart ?>-<?= (int)$rangeEnd ?> dari <?= (int)$totalMatchCount ?> match.
            </div>
            <div class="pagination-links">
              <?php if ($currentMatchPage > 1): ?>
                <a class="page-link" href="/admin/competition?match_page=<?= (int)$prevMatchPage ?>" aria-label="Halaman sebelumnya">&lsaquo;</a>
              <?php else: ?>
                <span class="page-link disabled" aria-hidden="true">&lsaquo;</span>
              <?php endif; ?>
              <?php $lastRenderedPage = 0; ?>
              <?php foreach ($visiblePages as $pageNumber): ?>
                <?php if ($lastRenderedPage > 0 && $pageNumber - $lastRenderedPage > 1): ?>
                  <span class="page-ellipsis">...</span>
                <?php endif; ?>
                <?php if ($pageNumber === $currentMatchPage): ?>
                  <span class="page-link current"><?= (int)$pageNumber ?></span>
                <?php else: ?>
                  <a class="page-link" href="/admin/competition?match_page=<?= (int)$pageNumber ?>"><?= (int)$pageNumber ?></a>
                <?php endif; ?>
                <?php $lastRenderedPage = $pageNumber; ?>
              <?php endforeach; ?>
              <?php if ($currentMatchPage < $totalMatchPages): ?>
                <a class="page-link" href="/admin/competition?match_page=<?= (int)$nextMatchPage ?>" aria-label="Halaman berikutnya">&rsaquo;</a>
              <?php else: ?>
                <span class="page-link disabled" aria-hidden="true">&rsaquo;</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>
<?php render_footer(['isAdmin' => true]); ?>


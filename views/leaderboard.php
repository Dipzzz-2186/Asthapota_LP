<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/layout/app.php';

const LEADERBOARD_ALLOWED_TOTAL_POINTS = [16, 21, 24, 32];

function normalize_lb_competition_type(string $type): string {
    $lower = strtolower(trim($type));
    if (strpos($lower, 'americano') !== false) {
        return 'Americano';
    }
    if (strpos($lower, 'mexicano') !== false) {
        return 'Mexicano';
    }
    return '';
}

function is_valid_lb_score(?int $scoreA, ?int $scoreB): bool {
    if ($scoreA === null || $scoreB === null) {
        return false;
    }
    if ($scoreA < 0 || $scoreB < 0) {
        return false;
    }
    $total = $scoreA + $scoreB;
    return in_array($total, LEADERBOARD_ALLOWED_TOTAL_POINTS, true);
}

$db = get_db();
$gamesByType = [
    'Americano' => [],
    'Mexicano' => [],
];

try {
    $rows = $db->query(
        "SELECT id, competition_type, player_a_name, player_b_name, score_a, score_b, game_date, created_at
         FROM competition_games
         ORDER BY COALESCE(game_date, DATE(created_at)) DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $type = normalize_lb_competition_type((string)($row['competition_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $gamesByType[$type][] = $row;
    }
} catch (Throwable $e) {
    $gamesByType = ['Americano' => [], 'Mexicano' => []];
}

$leaderboards = [
    'Americano' => [],
    'Mexicano' => [],
];

foreach ($gamesByType as $type => $games) {
    $table = [];
    foreach ($games as $game) {
        $playerA = trim((string)($game['player_a_name'] ?? ''));
        $playerB = trim((string)($game['player_b_name'] ?? ''));
        $scoreA = isset($game['score_a']) && $game['score_a'] !== null ? (int)$game['score_a'] : null;
        $scoreB = isset($game['score_b']) && $game['score_b'] !== null ? (int)$game['score_b'] : null;

        if ($playerA === '' || $playerB === '' || !is_valid_lb_score($scoreA, $scoreB)) {
            continue;
        }

        if (!isset($table[$playerA])) {
            $table[$playerA] = ['name' => $playerA, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'points' => 0];
        }
        if (!isset($table[$playerB])) {
            $table[$playerB] = ['name' => $playerB, 'match' => 0, 'win' => 0, 'draw' => 0, 'lose' => 0, 'pf' => 0, 'pa' => 0, 'points' => 0];
        }

        $table[$playerA]['match']++;
        $table[$playerB]['match']++;
        $table[$playerA]['pf'] += $scoreA;
        $table[$playerA]['pa'] += $scoreB;
        $table[$playerA]['points'] += $scoreA;
        $table[$playerB]['pf'] += $scoreB;
        $table[$playerB]['pa'] += $scoreA;
        $table[$playerB]['points'] += $scoreB;

        if ($scoreA > $scoreB) {
            $table[$playerA]['win']++;
            $table[$playerB]['lose']++;
        } elseif ($scoreB > $scoreA) {
            $table[$playerB]['win']++;
            $table[$playerA]['lose']++;
        } else {
            $table[$playerA]['draw']++;
            $table[$playerB]['draw']++;
        }
    }

    $rows = array_values($table);
    usort($rows, static function (array $a, array $b): int {
        $diffA = (int)$a['pf'] - (int)$a['pa'];
        $diffB = (int)$b['pf'] - (int)$b['pa'];
        if ((int)$a['points'] !== (int)$b['points']) {
            return (int)$b['points'] <=> (int)$a['points'];
        }
        if ($diffA !== $diffB) {
            return $diffB <=> $diffA;
        }
        if ((int)$a['win'] !== (int)$b['win']) {
            return (int)$b['win'] <=> (int)$a['win'];
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });
    $leaderboards[$type] = $rows;
}

$extraHead = <<<HTML
<style>
  .lb-shell { padding: 22px 0 40px; }
  .lb-head { display:flex; justify-content:space-between; align-items:flex-end; gap:10px; flex-wrap:wrap; margin-bottom:14px; }
  .lb-title { margin:0; font-size:clamp(28px,4vw,42px); color:#132c4d; }
  .lb-sub { margin:6px 0 0; color:#5b7090; }
  .lb-grid { display:grid; gap:14px; grid-template-columns:repeat(2,minmax(0,1fr)); }
  .lb-card { background:rgba(255,255,255,.93); border:1px solid rgba(18,41,74,.14); border-radius:16px; padding:14px; box-shadow:0 10px 26px rgba(14,32,62,.08); }
  .lb-card h2 { margin:0 0 8px; color:#143057; font-size:22px; }
  .lb-wrap { overflow:auto; }
  table.lb-table { width:100%; border-collapse:collapse; min-width:560px; }
  .lb-table th, .lb-table td { text-align:left; border-bottom:1px solid #e1e8f6; padding:8px 7px; font-size:13px; color:#1c3558; }
  .lb-table th { font-size:11px; color:#5c7090; text-transform:uppercase; letter-spacing:.3px; }
  .lb-empty { color:#5c7090; font-size:13px; }
  @media (max-width: 980px) { .lb-grid { grid-template-columns:1fr; } }
</style>
HTML;

render_header([
    'title' => 'Leaderboard - Asthapora',
    'showNav' => false,
    'brandSubtitle' => 'Competition Leaderboard',
    'extraHead' => $extraHead,
]);
?>
<main class="lb-shell">
  <div class="container">
    <div class="lb-head">
      <div>
        <h1 class="lb-title">Leaderboard Competition</h1>
        <p class="lb-sub">Peringkat otomatis dari hasil match pada bagan competition.</p>
      </div>
      <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Kembali Home</a>
    </div>

    <section class="lb-grid">
      <?php foreach (['Americano', 'Mexicano'] as $type): ?>
        <?php $rows = $leaderboards[$type] ?? []; ?>
        <article class="lb-card">
          <h2><?= h($type) ?></h2>
          <div class="lb-wrap">
            <table class="lb-table">
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
                <?php if (!$rows): ?>
                  <tr><td colspan="10" class="lb-empty">Belum ada skor valid untuk <?= h($type) ?>.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $idx => $row): ?>
                    <?php $diff = (int)($row['pf'] ?? 0) - (int)($row['pa'] ?? 0); ?>
                    <tr>
                      <td><?= (int)($idx + 1) ?></td>
                      <td><?= h((string)($row['name'] ?? '-')) ?></td>
                      <td><?= (int)($row['match'] ?? 0) ?></td>
                      <td><?= (int)($row['win'] ?? 0) ?></td>
                      <td><?= (int)($row['draw'] ?? 0) ?></td>
                      <td><?= (int)($row['lose'] ?? 0) ?></td>
                      <td><?= (int)($row['pf'] ?? 0) ?></td>
                      <td><?= (int)($row['pa'] ?? 0) ?></td>
                      <td><?= $diff >= 0 ? '+' . $diff : (string)$diff ?></td>
                      <td><strong><?= (int)($row['points'] ?? 0) ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </div>
</main>
<?php render_footer(); ?>

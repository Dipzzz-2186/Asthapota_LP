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

function split_lb_team_members(string $teamLabel): array {
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

function build_lb_label_from_title(string $type, string $title): string {
    $label = preg_replace('/\s*(?:-|)?\s*R\d+\s*M\d+\s*$/i', '', trim($title));
    $label = trim((string)$label);
    return $label !== '' ? $label : $type;
}

function is_lb_auto_generated_title(string $type, string $title): bool {
    $title = trim($title);
    if ($title === '') {
        return false;
    }
    return (bool)preg_match('/^' . preg_quote($type, '/') . '\s*R\d+\s*M\d+$/i', $title);
}

function lb_ts(string $value): int {
    $ts = strtotime(trim($value));
    return $ts ? (int)$ts : 0;
}

$db = get_db();
$tournaments = [];

try {
    $rows = $db->query(
        "SELECT id, competition_type, game_title, round_no, match_no,
                player_a_name, player_b_name, score_a, score_b, game_date, created_at
         FROM competition_games
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recentCustomLabelByType = [
        'Americano' => '',
        'Mexicano' => '',
    ];

    foreach ($rows as $row) {
        $type = normalize_lb_competition_type((string)($row['competition_type'] ?? ''));
        if ($type === '') {
            continue;
        }
        $gameTitle = trim((string)($row['game_title'] ?? ''));
        $baseLabel = build_lb_label_from_title($type, $gameTitle !== '' ? $gameTitle : $type);
        $isAutoTitle = is_lb_auto_generated_title($type, $gameTitle);
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
        $createdTs = lb_ts($createdAt);
        if ($createdTs > (int)$tournaments[$key]['updated_ts']) {
            $tournaments[$key]['updated_ts'] = $createdTs;
            $tournaments[$key]['updated_at'] = $createdAt;
        }

        $teamA = trim((string)($row['player_a_name'] ?? ''));
        $teamB = trim((string)($row['player_b_name'] ?? ''));
        foreach (array_merge(split_lb_team_members($teamA), split_lb_team_members($teamB)) as $member) {
            $nameKey = strtolower(trim((string)$member));
            if ($nameKey === '' || $nameKey === 'bye' || $nameKey === 'tbd') {
                continue;
            }
            $tournaments[$key]['players'][$nameKey] = trim((string)$member);
        }
    }
} catch (Throwable $e) {
    $tournaments = [];
}

foreach ($tournaments as $key => $tournament) {
    $games = (array)($tournament['games'] ?? []);
    usort($games, static function (array $a, array $b): int {
        $ra = (int)($a['round_no'] ?? 0);
        $rb = (int)($b['round_no'] ?? 0);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $ma = (int)($a['match_no'] ?? 0);
        $mb = (int)($b['match_no'] ?? 0);
        if ($ma !== $mb) {
            return $ma <=> $mb;
        }
        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    $rounds = [];
    $table = [];
    foreach ($games as $game) {
        $roundNo = (int)($game['round_no'] ?? 0);
        if ($roundNo <= 0) {
            $roundNo = 999999;
        }
        if (!isset($rounds[$roundNo])) {
            $rounds[$roundNo] = [];
        }
        $rounds[$roundNo][] = $game;

        $teamA = trim((string)($game['player_a_name'] ?? ''));
        $teamB = trim((string)($game['player_b_name'] ?? ''));
        $membersA = split_lb_team_members($teamA);
        $membersB = split_lb_team_members($teamB);
        $scoreA = isset($game['score_a']) && $game['score_a'] !== null ? (int)$game['score_a'] : null;
        $scoreB = isset($game['score_b']) && $game['score_b'] !== null ? (int)$game['score_b'] : null;

        if (!$membersA || !$membersB || !is_valid_lb_score($scoreA, $scoreB)) {
            continue;
        }

        foreach ($membersA as $playerName) {
            if (!isset($table[$playerName])) {
                $table[$playerName] = ['name' => $playerName, 'win' => 0, 'points' => 0];
            }
            $table[$playerName]['points'] += (int)$scoreA;
            if ($scoreA > $scoreB) {
                $table[$playerName]['win']++;
            }
        }
        foreach ($membersB as $playerName) {
            if (!isset($table[$playerName])) {
                $table[$playerName] = ['name' => $playerName, 'win' => 0, 'points' => 0];
            }
            $table[$playerName]['points'] += (int)$scoreB;
            if ($scoreB > $scoreA) {
                $table[$playerName]['win']++;
            }
        }
    }

    ksort($rounds, SORT_NUMERIC);
    $standings = array_values($table);
    usort($standings, static function (array $a, array $b): int {
        if ((int)$a['win'] !== (int)$b['win']) {
            return (int)$b['win'] <=> (int)$a['win'];
        }
        if ((int)$a['points'] !== (int)$b['points']) {
            return (int)$b['points'] <=> (int)$a['points'];
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });

    $tournaments[$key]['games'] = $games;
    $tournaments[$key]['rounds'] = $rounds;
    $tournaments[$key]['standings'] = $standings;
}

$tournamentList = array_values($tournaments);
usort($tournamentList, static function (array $a, array $b): int {
    return (int)($b['updated_ts'] ?? 0) <=> (int)($a['updated_ts'] ?? 0);
});

$extraHead = <<<HTML
<style>
  .lb-shell {
    padding: 22px 0 40px;
  }
  .lb-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
  }
  .lb-title {
    margin:0;
    font-size:clamp(28px,4vw,42px);
    color:#132c4d;
  }
  .lb-sub {
    margin:6px 0 0;
    color:#5b7090;
  }
  .tournament-list {
    display:grid;
    gap:12px;
    margin-top:10px;
  }
  .tournament-card {
    width:100%;
    text-align:left;
    border:1px solid #d8e2f2;
    background:#f8fbff;
    border-radius:14px;
    padding:14px;
    cursor:pointer;
    transition:.16s ease;
  }
  .tournament-card:hover {
    transform:translateY(-1px);
    box-shadow:0 8px 20px rgba(15,32,60,.08);
    border-color:#bcd2f3;
  }
  .tournament-name {
    font-size:32px;
    line-height:1.05;
    color:#0f294d;
    font-weight:800;
    letter-spacing:-.5px;
  }
  .tournament-meta {
    margin-top:6px;
    font-size:18px;
    color:#1c426e;
  }
  .tournament-updated {
    margin-top:5px;
    font-size:12px;
    color:#5a6b86;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.55px;
  }
  .lb-empty {
    color:#5c7090;
    font-size:13px;
    margin:0;
  }
  .lb-modal {
    position:fixed;
    inset:0;
    background:rgba(7,16,34,.46);
    z-index:5000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:16px;
  }
  .lb-modal.is-open {
    display:flex;
  }
  .lb-modal-card {
    width:min(1280px,100%);
    max-height:94vh;
    border-radius:18px;
    background:#f4f7fb;
    border:1px solid #cfdaec;
    box-shadow:0 18px 38px rgba(10,20,40,.24);
    overflow:hidden;
    display:grid;
    grid-template-rows:auto minmax(0,1fr);
  }
  .lb-modal-head {
    padding:16px 18px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    background:linear-gradient(135deg,#3a4a8f,#6a66b4);
    color:#fff;
  }
  .lb-modal-title {
    margin:0;
    font-size:clamp(30px,4vw,48px);
    line-height:1;
    letter-spacing:-.4px;
  }
  .lb-modal-close {
    border:1px solid rgba(255,255,255,.45);
    background:rgba(255,255,255,.12);
    color:#fff;
    border-radius:10px;
    min-width:38px;
    height:38px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:20px;
    line-height:1;
  }
  .lb-modal-body {
    overflow:auto;
    padding:14px;
  }
  .lb-modal-panel {
    display:none;
  }
  .lb-modal-panel.is-active {
    display:block;
  }
  .lb-detail-grid {
    display:grid;
    gap:14px;
    grid-template-columns:minmax(280px,420px) minmax(0,1fr);
    align-items:start;
  }
  .lb-stand-card, .lb-round-card {
    background:#fff;
    border:1px solid #dbe5f4;
    border-radius:14px;
    box-shadow:0 6px 14px rgba(15,32,60,.06);
  }
  .lb-stand-card {
    padding:10px;
    position:sticky;
    top:0;
  }
  .lb-stand-head {
    display:grid;
    grid-template-columns:62px minmax(0,1fr) 54px 88px;
    gap:8px;
    font-size:12px;
    color:#5d7090;
    text-transform:uppercase;
    font-weight:700;
    letter-spacing:.4px;
    padding:4px 8px 8px;
  }
  .lb-stand-row {
    display:grid;
    grid-template-columns:62px minmax(0,1fr) 54px 88px;
    gap:8px;
    align-items:center;
    padding:9px 8px;
    border-radius:10px;
  }
  .lb-stand-row + .lb-stand-row {
    margin-top:6px;
  }
  .lb-stand-row.is-first {
    background:#ffe24a;
  }
  .lb-stand-row:not(.is-first) {
    background:#eef3fb;
  }
  .lb-rank {
    font-weight:700;
  }
  .lb-player {
    font-weight:700;
    color:#152f54;
  }
  .lb-score-strong {
    font-weight:800;
    color:#0f2a4b;
  }
  .lb-rounds {
    display:grid;
    gap:12px;
  }
  .lb-round-title {
    margin:0 0 6px;
    font-size:30px;
    color:#0f294d;
    line-height:1;
  }
  .lb-round-card {
    padding:10px;
  }
  .lb-match-head {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:8px;
  }
  .lb-match-court {
    font-size:12px;
    text-transform:uppercase;
    font-weight:700;
    color:#405d84;
  }
  .lb-score-pair {
    display:flex;
    gap:4px;
    align-items:center;
  }
  .lb-score-box {
    min-width:44px;
    height:34px;
    border-radius:7px;
    background:#0d1117;
    color:#fff;
    font-weight:900;
    font-size:24px;
    line-height:34px;
    text-align:center;
    letter-spacing:.6px;
  }
  .lb-match-lines {
    border:1px solid #dce6f3;
    border-radius:10px;
    overflow:hidden;
    background:#f8fbff;
  }
  .lb-match-line {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:8px 10px;
    font-size:19px;
    color:#243f64;
  }
  .lb-match-line + .lb-match-line {
    border-top:1px solid #e1e9f6;
  }
  @media (max-width: 980px) {
    .lb-detail-grid {
      grid-template-columns:1fr;
    }
    .lb-stand-card {
      position:static;
    }
    .tournament-name {
      font-size:26px;
    }
    .lb-round-title {
      font-size:24px;
    }
    .lb-match-line {
      font-size:16px;
    }
  }
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
        <h1 class="lb-title">Latest tournaments</h1>
        <p class="lb-sub">Klik satu turnamen untuk lihat detail ronde dan leaderboard (read only).</p>
      </div>
      <a class="btn ghost" href="/"><i class="bi bi-arrow-left"></i> Kembali Home</a>
    </div>

    <section class="tournament-list">
      <?php if (!$tournamentList): ?>
        <p class="lb-empty">Belum ada turnamen.</p>
      <?php else: ?>
        <?php foreach ($tournamentList as $t): ?>
          <?php
            $updatedTs = (int)($t['updated_ts'] ?? 0);
            $updatedText = $updatedTs > 0 ? date('d M Y H:i', $updatedTs) : '-';
          ?>
          <button type="button" class="tournament-card" data-open-tournament="<?= h((string)($t['key'] ?? '')) ?>">
            <div class="tournament-name"><?= h((string)($t['label'] ?? '-')) ?></div>
            <div class="tournament-meta"><?= (int)count((array)($t['players'] ?? [])) ?> players</div>
            <div class="tournament-updated">Updated <?= h($updatedText) ?></div>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div>
</main>
<div class="lb-modal" data-lb-modal aria-hidden="true">
  <div class="lb-modal-card" role="dialog" aria-modal="true" aria-labelledby="lbModalTitle">
    <div class="lb-modal-head">
      <h2 class="lb-modal-title" id="lbModalTitle" data-lb-modal-title>Detail Tournament</h2>
      <button type="button" class="lb-modal-close" data-lb-modal-close aria-label="Tutup">&times;</button>
    </div>
    <div class="lb-modal-body">
      <?php foreach ($tournamentList as $t): ?>
        <?php
          $panelKey = (string)($t['key'] ?? '');
          $standings = (array)($t['standings'] ?? []);
          $rounds = (array)($t['rounds'] ?? []);
        ?>
        <section class="lb-modal-panel" data-lb-panel="<?= h($panelKey) ?>">
          <div class="lb-detail-grid">
            <aside class="lb-stand-card">
              <div class="lb-stand-head">
                <div>#</div><div>Player</div><div>W</div><div>Total Point</div>
              </div>
              <?php if (!$standings): ?>
                <p class="lb-empty" style="padding:0 8px 8px;">Belum ada skor valid.</p>
              <?php else: ?>
                <?php foreach ($standings as $idx => $row): ?>
                  <div class="lb-stand-row <?= $idx === 0 ? 'is-first' : '' ?>">
                    <div class="lb-rank"><?= (int)($idx + 1) ?>.</div>
                    <div class="lb-player"><?= h((string)($row['name'] ?? '-')) ?></div>
                    <div><?= (int)($row['win'] ?? 0) ?></div>
                    <div class="lb-score-strong"><?= (int)($row['points'] ?? 0) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </aside>
            <div class="lb-rounds">
              <?php if (!$rounds): ?>
                <p class="lb-empty">Belum ada ronde.</p>
              <?php else: ?>
                <?php foreach ($rounds as $roundNo => $games): ?>
                  <article>
                    <h3 class="lb-round-title"><?= $roundNo === 999999 ? 'Round' : ('Round #' . (int)$roundNo) ?></h3>
                    <?php foreach ($games as $g): ?>
                      <?php
                        $sa = isset($g['score_a']) && $g['score_a'] !== null ? (int)$g['score_a'] : 0;
                        $sb = isset($g['score_b']) && $g['score_b'] !== null ? (int)$g['score_b'] : 0;
                        $teamA = trim((string)($g['player_a_name'] ?? ''));
                        $teamB = trim((string)($g['player_b_name'] ?? ''));
                        $membersA = split_lb_team_members($teamA);
                        $membersB = split_lb_team_members($teamB);
                        $lineA1 = (string)($membersA[0] ?? '-');
                        $lineA2 = (string)($membersA[1] ?? '-');
                        $lineB1 = (string)($membersB[0] ?? '-');
                        $lineB2 = (string)($membersB[1] ?? '-');
                        $courtNo = (int)($g['match_no'] ?? 1);
                        if ($courtNo <= 0) { $courtNo = 1; }
                      ?>
                      <div class="lb-round-card">
                        <div class="lb-match-head">
                          <div class="lb-score-pair">
                            <span class="lb-score-box"><?= str_pad((string)$sa, 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="lb-score-box"><?= str_pad((string)$sb, 2, '0', STR_PAD_LEFT) ?></span>
                          </div>
                          <div class="lb-match-court">Court <?= (int)$courtNo ?></div>
                        </div>
                        <div class="lb-match-lines">
                          <div class="lb-match-line"><span><?= h($lineA1) ?></span><span><?= h($lineB1) ?></span></div>
                          <div class="lb-match-line"><span><?= h($lineA2) ?></span><span><?= h($lineB2) ?></span></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </article>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.querySelector('[data-lb-modal]');
  if (!modal) return;
  var titleEl = modal.querySelector('[data-lb-modal-title]');
  var closeButtons = modal.querySelectorAll('[data-lb-modal-close]');
  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }
  function openPanel(key, titleText) {
    if (!key) return;
    var found = false;
    modal.querySelectorAll('[data-lb-panel]').forEach(function (panel) {
      var panelKey = panel.getAttribute('data-lb-panel') || '';
      var active = (panelKey === key);
      panel.classList.toggle('is-active', active);
      if (active) found = true;
    });
    if (!found) return;
    if (titleEl) titleEl.textContent = titleText || 'Detail Tournament';
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }
  document.querySelectorAll('[data-open-tournament]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-open-tournament') || '';
      var nameEl = btn.querySelector('.tournament-name');
      openPanel(key, nameEl ? String(nameEl.textContent || '').trim() : 'Detail Tournament');
    });
  });
  closeButtons.forEach(function (btn) {
    btn.addEventListener('click', closeModal);
  });
  modal.addEventListener('click', function (ev) {
    if (ev.target === modal) closeModal();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeModal();
  });
})();
</script>
<?php render_footer(); ?>

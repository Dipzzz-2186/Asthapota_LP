<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/layout/app.php';

$db = get_db();
$token = trim((string)($_GET['token'] ?? ''));
$flash = ['success' => '', 'error' => ''];
$form = null;
$schema = null;

if ($token !== '') {
    $stmt = $db->prepare('SELECT id, title, form_description, form_schema_json, form_status, public_token FROM admin_generated_forms WHERE public_token = ? AND form_status = ? LIMIT 1');
    $stmt->execute([$token, 'published']);
    $form = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($form) {
        $decoded = json_decode((string)($form['form_schema_json'] ?? ''), true);
        if (is_array($decoded)) {
            $schema = $decoded;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form && is_array($schema)) {
    $questions = is_array($schema['questions'] ?? null) ? $schema['questions'] : [];
    $answers = [];
    $responderName = '';
    $responderEmail = '';

    foreach ($questions as $index => $question) {
        if (!is_array($question)) {
            continue;
        }
        $label = trim((string)($question['label'] ?? 'Pertanyaan ' . ($index + 1)));
        $type = trim((string)($question['type'] ?? 'short_text'));
        $fieldKey = 'q_' . $index;
        $rawValue = $_POST[$fieldKey] ?? null;
        $answerValue = is_array($rawValue)
            ? array_values(array_filter(array_map(static function ($value): string {
                return trim((string)$value);
            }, $rawValue), static function (string $value): bool {
                return $value !== '';
            }))
            : trim((string)$rawValue);

        if (!empty($question['required'])) {
            $isEmpty = is_array($answerValue) ? count($answerValue) === 0 : $answerValue === '';
            if ($isEmpty) {
                $flash['error'] = 'Ada pertanyaan wajib yang belum diisi.';
                break;
            }
        }

        if (stripos($label, 'nama') !== false && !is_array($answerValue)) {
            $responderName = $answerValue;
        }
        if (stripos($label, 'email') !== false && !is_array($answerValue)) {
            $responderEmail = $answerValue;
        }

        $answers[] = [
            'label' => $label,
            'type' => $type,
            'answer' => $answerValue,
        ];
    }

    if ($flash['error'] === '') {
        $stmt = $db->prepare('INSERT INTO admin_generated_form_responses (form_id, responder_name, responder_email, answers_json, submitted_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            (int)$form['id'],
            $responderName !== '' ? $responderName : null,
            $responderEmail !== '' ? $responderEmail : null,
            json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('Y-m-d H:i:s'),
        ]);
        $flash['success'] = 'Jawaban berhasil dikirim. Terima kasih.';
        $_POST = [];
    }
}

render_header([
    'title' => $form ? ((string)($form['title'] ?? 'Form Survey')) : 'Form Survey',
    'showNav' => false,
    'brandSubtitle' => 'Form Response',
]);
?>
<style>
  .public-form-shell, .public-form-shell *:not(.bi) { font-family: "Segoe UI", Tahoma, sans-serif; }
  .public-form-shell { padding: 36px 0 56px; }
  .public-form-wrap { width: min(760px, calc(100vw - 24px)); margin: 0 auto; }
  .public-form-card { background:#fff; border-radius:26px; box-shadow:0 22px 44px rgba(12,31,60,0.12); overflow:hidden; border:1px solid rgba(16,44,79,0.08); }
  .public-form-hero { padding:28px 24px; background:linear-gradient(135deg, #1f6de0, #39a5d9); color:#fff; }
  .public-form-hero h1 { margin:0 0 8px; font-size:clamp(28px, 4vw, 38px); }
  .public-form-hero p { margin:0; color:rgba(255,255,255,0.9); }
  .public-form-body { padding:22px; display:grid; gap:16px; }
  .public-q { padding:18px; border-radius:18px; background:#fbfcff; border:1px solid rgba(17,47,88,0.08); }
  .public-q h3 { margin:0 0 8px; font-size:16px; color:#13355b; }
  .public-q p { margin:0 0 12px; color:#627b94; font-size:13px; }
  .public-input, .public-select, .public-textarea { width:100%; padding:12px 14px; border-radius:14px; border:1px solid rgba(17,47,88,0.14); font:inherit; }
  .public-textarea { min-height:110px; resize:vertical; }
  .public-option { display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; color:#173b67; }
  .public-alert { padding:14px 16px; border-radius:16px; font-size:14px; }
  .public-alert.success { background:rgba(28,160,98,0.12); color:#126741; }
  .public-alert.error { background:rgba(204,41,54,0.1); color:#8a1f2a; }
</style>
<main class="public-form-shell">
  <div class="public-form-wrap">
    <?php if (!$form || !is_array($schema)): ?>
      <div class="public-form-card">
        <div class="public-form-hero"><h1>Form tidak tersedia</h1><p>Link ini tidak valid atau form belum dipublish.</p></div>
      </div>
    <?php else: ?>
      <div class="public-form-card">
        <div class="public-form-hero">
          <h1><?= h((string)($schema['title'] ?? $form['title'])) ?></h1>
          <p><?= h((string)($schema['description'] ?? $form['form_description'] ?? '')) ?></p>
        </div>
        <div class="public-form-body">
          <?php if ($flash['success'] !== ''): ?><div class="public-alert success"><?= h($flash['success']) ?></div><?php endif; ?>
          <?php if ($flash['error'] !== ''): ?><div class="public-alert error"><?= h($flash['error']) ?></div><?php endif; ?>
          <?php if ($flash['success'] === ''): ?>
            <form method="post" class="public-form-body" style="padding:0;">
              <?php foreach (($schema['questions'] ?? []) as $index => $question): ?>
                <?php
                  if (!is_array($question)) {
                      continue;
                  }
                  $label = (string)($question['label'] ?? ('Pertanyaan ' . ($index + 1)));
                  $type = (string)($question['type'] ?? 'short_text');
                  $name = 'q_' . $index;
                  $value = $_POST[$name] ?? '';
                ?>
                <section class="public-q">
                  <h3><?= h($label) ?><?php if (!empty($question['required'])): ?> <span style="color:#c62828;">*</span><?php endif; ?></h3>
                  <?php if (!empty($question['help_text'])): ?><p><?= h((string)$question['help_text']) ?></p><?php endif; ?>
                  <?php if ($type === 'paragraph'): ?>
                    <textarea class="public-textarea" name="<?= h($name) ?>"><?= h((string)$value) ?></textarea>
                  <?php elseif ($type === 'single_choice' || $type === 'multiple_choice'): ?>
                    <?php foreach ((array)($question['options'] ?? []) as $option): ?>
                      <label class="public-option">
                        <input type="<?= $type === 'single_choice' ? 'radio' : 'checkbox' ?>" name="<?= h($type === 'single_choice' ? $name : ($name . '[]')) ?>" value="<?= h((string)$option) ?>"<?= (is_array($value) ? in_array((string)$option, $value, true) : (string)$value === (string)$option) ? ' checked' : '' ?>>
                        <span><?= h((string)$option) ?></span>
                      </label>
                    <?php endforeach; ?>
                  <?php elseif ($type === 'dropdown'): ?>
                    <select class="public-select" name="<?= h($name) ?>">
                      <option value="">Pilih jawaban</option>
                      <?php foreach ((array)($question['options'] ?? []) as $option): ?>
                        <option value="<?= h((string)$option) ?>"<?= (string)$value === (string)$option ? ' selected' : '' ?>><?= h((string)$option) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($type === 'rating'): ?>
                    <select class="public-select" name="<?= h($name) ?>">
                      <option value="">Pilih nilai</option>
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>"<?= (string)$value === (string)$i ? ' selected' : '' ?>><?= $i ?></option>
                      <?php endfor; ?>
                    </select>
                  <?php else: ?>
                    <input class="public-input" type="<?= in_array($type, ['email', 'date', 'number'], true) ? $type : 'text' ?>" name="<?= h($name) ?>" value="<?= h((string)$value) ?>" placeholder="<?= h((string)($question['placeholder'] ?? '')) ?>">
                  <?php endif; ?>
                </section>
              <?php endforeach; ?>
              <button class="btn primary" type="submit"><i class="bi bi-send"></i> Kirim Jawaban</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php render_footer(); ?>

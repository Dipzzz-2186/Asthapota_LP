<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/layout/app.php';

$db = get_db();
$token = trim((string)($_GET['token'] ?? ''));
$flash = ['success' => '', 'error' => ''];
$form = null;
$schema = null;

function public_form_is_info_block(array $question): bool
{
    return trim((string)($question['type'] ?? '')) === 'info_text';
}

function public_form_upload_directory(): string
{
    return __DIR__ . '/../uploads/form-responses';
}

function public_form_store_uploaded_file(array $file, int $formId, string $fieldKey): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return ['error' => 'File gagal diupload.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > (10 * 1024 * 1024)) {
        return ['error' => 'Ukuran file maksimal 10MB.'];
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        return ['error' => 'Format file tidak didukung.'];
    }

    $uploadDir = public_form_upload_directory();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return ['error' => 'Folder upload tidak dapat dibuat.'];
    }

    $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';
    $fileName = 'form-' . $formId . '-' . $fieldKey . '-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(6)), 0, 8) . '.' . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
        return ['error' => 'File tidak berhasil disimpan.'];
    }

    $relativePath = '/uploads/form-responses/' . $fileName;

    return [
        'name' => $originalName !== '' ? $originalName : ($safeBase . '.' . $ext),
        'path' => $relativePath,
        'size' => $size,
    ];
}

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
        $kind = trim((string)($question['kind'] ?? 'field'));
        $category = trim((string)($question['category'] ?? 'question'));
        if ($kind === 'page_break' || $category === 'section_break' || public_form_is_info_block($question)) {
            continue;
        }
        $label = trim((string)($question['label'] ?? 'Pertanyaan ' . ($index + 1)));
        $type = trim((string)($question['type'] ?? 'short_text'));
        $fieldKey = 'q_' . $index;
        if ($type === 'file') {
            $uploaded = $_FILES[$fieldKey] ?? null;
            if (!empty($question['required']) && (!is_array($uploaded) || (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                $flash['error'] = 'Ada pertanyaan wajib yang belum diisi.';
                break;
            }
            if (is_array($uploaded) && (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $storedFile = public_form_store_uploaded_file($uploaded, (int)$form['id'], $fieldKey);
                if (!empty($storedFile['error'])) {
                    $flash['error'] = (string)$storedFile['error'];
                    break;
                }
                $answerValue = $storedFile;
            } else {
                $answerValue = '';
            }
        } else {
            $rawValue = $_POST[$fieldKey] ?? null;
            $answerValue = is_array($rawValue)
                ? array_values(array_filter(array_map(static function ($value): string {
                    return trim((string)$value);
                }, $rawValue), static function (string $value): bool {
                    return $value !== '';
                }))
                : trim((string)$rawValue);
        }

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
  .public-form-progress { height:8px; border-radius:999px; background:rgba(31,109,224,0.12); overflow:hidden; }
  .public-form-progress-bar { display:block; height:100%; background:linear-gradient(90deg, #1f6de0, #39a5d9); width:0; transition:width .2s ease; }
  .public-form-section { display:none; gap:16px; }
  .public-form-section.is-active { display:grid; }
  .public-section-card { padding:18px; border-radius:18px; background:linear-gradient(135deg, rgba(31,109,224,0.1), rgba(57,165,217,0.08)); border:1px solid rgba(31,109,224,0.16); }
  .public-section-card h2 { margin:0 0 8px; font-size:20px; color:#13355b; }
  .public-section-card p { margin:0; color:#577190; font-size:13px; }
  .public-q { padding:18px; border-radius:18px; background:#fbfcff; border:1px solid rgba(17,47,88,0.08); }
  .public-q h3 { margin:0 0 8px; font-size:16px; color:#13355b; }
  .public-q p { margin:0 0 12px; color:#627b94; font-size:13px; }
  .public-input, .public-select, .public-textarea { width:100%; padding:12px 14px; border-radius:14px; border:1px solid rgba(17,47,88,0.14); font:inherit; }
  .public-textarea { min-height:110px; resize:vertical; }
  .public-option { display:flex; gap:10px; align-items:flex-start; margin-bottom:10px; color:#173b67; }
  .public-info { padding:18px; border-radius:18px; background:linear-gradient(135deg, rgba(255,244,214,0.72), rgba(255,251,240,0.98)); border:1px dashed rgba(199,147,0,0.35); }
  .public-info h3 { margin:0 0 8px; font-size:16px; color:#7a5610; }
  .public-info p { margin:0; color:#7b6734; font-size:13px; }
  .public-form-actions { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; }
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
            <?php
              $rawQuestions = is_array($schema['questions'] ?? null) ? $schema['questions'] : [];
              $sections = [];
              $currentSection = [
                  'title' => '',
                  'description' => '',
                  'items' => [],
              ];

              foreach ($rawQuestions as $index => $question) {
                  if (!is_array($question)) {
                      continue;
                  }
                  $kind = trim((string)($question['kind'] ?? 'field'));
                  $category = trim((string)($question['category'] ?? 'question'));
                  if ($kind === 'page_break' || $category === 'section_break') {
                      if (!empty($currentSection['items']) || $currentSection['title'] !== '' || $currentSection['description'] !== '') {
                          $sections[] = $currentSection;
                      }
                      $currentSection = [
                          'title' => trim((string)($question['label'] ?? 'Bagian Berikutnya')),
                          'description' => trim((string)($question['help_text'] ?? '')),
                          'items' => [],
                      ];
                      continue;
                  }

                  $currentSection['items'][] = [
                      'index' => $index,
                      'question' => $question,
                  ];
              }

              if (!empty($currentSection['items']) || $currentSection['title'] !== '' || $currentSection['description'] !== '') {
                  $sections[] = $currentSection;
              }
              if (empty($sections)) {
                  $sections[] = ['title' => '', 'description' => '', 'items' => []];
              }
            ?>
            <form method="post" enctype="multipart/form-data" class="public-form-body" style="padding:0;">
              <?php if (!empty($schema['settings']['show_progress'])): ?>
                <div class="public-form-progress"><span class="public-form-progress-bar" data-progress-bar></span></div>
              <?php endif; ?>
              <?php foreach ($sections as $sectionIndex => $section): ?>
                <div class="public-form-section<?= $sectionIndex === 0 ? ' is-active' : '' ?>" data-form-section>
                  <?php if ($section['title'] !== '' || $section['description'] !== ''): ?>
                    <div class="public-section-card">
                      <h2><?= h($section['title'] !== '' ? $section['title'] : ('Bagian ' . ($sectionIndex + 1))) ?></h2>
                      <?php if ($section['description'] !== ''): ?><p><?= h($section['description']) ?></p><?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php foreach ($section['items'] as $item): ?>
                    <?php
                      $index = (int)$item['index'];
                      $question = (array)$item['question'];
                      $label = (string)($question['label'] ?? ('Pertanyaan ' . ($index + 1)));
                      $type = (string)($question['type'] ?? 'short_text');
                      $name = 'q_' . $index;
                      $value = $_POST[$name] ?? '';
                    ?>
                    <?php if ($type === 'info_text'): ?>
                    <section class="public-info">
                      <h3><?= h($label) ?></h3>
                      <?php if (!empty($question['help_text'])): ?><p><?= h((string)$question['help_text']) ?></p><?php endif; ?>
                    </section>
                    <?php else: ?>
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
                      <?php elseif ($type === 'file'): ?>
                        <input class="public-input" type="file" name="<?= h($name) ?>">
                      <?php else: ?>
                        <input class="public-input" type="<?= in_array($type, ['email', 'date', 'number'], true) ? $type : 'text' ?>" name="<?= h($name) ?>" value="<?= h((string)$value) ?>" placeholder="<?= h((string)($question['placeholder'] ?? '')) ?>">
                      <?php endif; ?>
                    </section>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <div class="public-form-actions">
                    <?php if ($sectionIndex > 0): ?>
                      <button class="btn ghost" type="button" data-prev-section><i class="bi bi-arrow-left"></i> Sebelumnya</button>
                    <?php else: ?>
                      <span></span>
                    <?php endif; ?>
                    <?php if ($sectionIndex < count($sections) - 1): ?>
                      <button class="btn primary" type="button" data-next-section>Selanjutnya <i class="bi bi-arrow-right"></i></button>
                    <?php else: ?>
                      <button class="btn primary" type="submit"><i class="bi bi-send"></i> Kirim Jawaban</button>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
<script>
  (function() {
    var sections = document.querySelectorAll('[data-form-section]');
    if (!sections.length) return;

    var progressBar = document.querySelector('[data-progress-bar]');
    var currentIndex = 0;

    function updateView(nextIndex) {
      currentIndex = Math.max(0, Math.min(nextIndex, sections.length - 1));
      Array.prototype.forEach.call(sections, function(section, index) {
        section.classList.toggle('is-active', index === currentIndex);
      });
      if (progressBar) {
        progressBar.style.width = (((currentIndex + 1) / sections.length) * 100) + '%';
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-next-section]'), function(button) {
      button.addEventListener('click', function() {
        updateView(currentIndex + 1);
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-prev-section]'), function(button) {
      button.addEventListener('click', function() {
        updateView(currentIndex - 1);
      });
    });

    updateView(0);
  })();
</script>
<?php render_footer(); ?>

<?php
require_once __DIR__ . '/../../app/helpers.php';

if (!function_exists('render_navbar')) {
    function render_navbar(array $opts = []): void {
        $isAdmin = $opts['isAdmin'] ?? false;
        $showNav = $opts['showNav'] ?? true;
        $brandSubtitle = $opts['brandSubtitle'] ?? 'A Monkeybar x BAPORA Event';
        $brandSubtitle = htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8');
        ?>
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <div class="brand-badge">TP</div>
          <div>
            <div>Asthapora</div>
            <small style="color:var(--muted);"><?= $brandSubtitle ?></small>
          </div>
        </div>
        <?php if ($showNav): ?>
        <nav class="nav">
          <a href="/"><i class="bi bi-house"></i> Home</a>
          <a href="/#about"><i class="bi bi-flag"></i> About</a>
          <a href="/#social"><i class="bi bi-share"></i> Social</a>
          <a href="/#packages"><i class="bi bi-bag"></i> Packages</a>
        </nav>
        <?php endif; ?>
        <div class="topbar-actions">
          <?php if ($isAdmin): ?>
            <a class="btn ghost" href="/admin/dashboard">Admin Dashboard</a>
            <a class="btn primary" href="/admin/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
          <?php else: ?>
            <a class="icon-btn" href="/register"><i class="bi bi-person"></i></a>
            <a class="icon-btn" href="/packages-view"><i class="bi bi-bag"></i></a>
            <a class="btn primary" href="/register">Register Now <i class="bi bi-arrow-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>
<?php
    }
}

if (!function_exists('render_header')) {
    function render_header(array $opts = []): void {
        $title = $opts['title'] ?? 'Asthapora';
        $extraHead = $opts['extraHead'] ?? '';
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $safeTitle ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <?= $extraHead ?>
</head>
<body class="page">
<?php render_navbar($opts); ?>
<?php
    }
}

if (!function_exists('render_footer')) {
    function render_footer(): void {
        ?>
  <script>
    (function() {
      function isTypingTarget(target) {
        if (!target) return false;
        var tag = (target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || target.isContentEditable;
      }

      document.addEventListener('keydown', function(e) {
        if (isTypingTarget(e.target)) return;
        if (e.ctrlKey && e.altKey && !e.shiftKey && (e.key === 'l' || e.key === 'L')) {
          e.preventDefault();
          window.location.href = '/admin/login';
        }
      });
    })();
  </script>
</body>
</html>
<?php
    }
}

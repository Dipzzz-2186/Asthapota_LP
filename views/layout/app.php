<?php
require_once __DIR__ . '/../../app/helpers.php';

if (!function_exists('render_navbar')) {
    function render_navbar(array $opts = []): void {
        $isAdmin = $opts['isAdmin'] ?? false;
        $showNav = $opts['showNav'] ?? true;
        $showAdminLogout = $opts['showAdminLogout'] ?? true;
        $brandSubtitle = $opts['brandSubtitle'] ?? 'A Monkeybar x BAPORA Event';
        $brandSubtitle = htmlspecialchars($brandSubtitle, ENT_QUOTES, 'UTF-8');
        ?>
  <?php if ($isAdmin): ?>
  <header class="page-header admin-header-shell">
    <div class="container admin-container-wide">
      <div class="topbar admin-topbar">
        <div class="brand">
          <img class="brand-badge" src="/assets/img/lopad.jpg" alt="Lopad logo">
          <div>
            <div>Asthapora Admin</div>
            <small style="color:var(--muted);"><?= $brandSubtitle ?></small>
          </div>
        </div>
        <div class="topbar-actions">
          <?php if ($showAdminLogout): ?>
          <a class="btn primary" href="/admin/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>
  <?php else: ?>
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <img class="brand-badge" src="/assets/img/lopad.jpg" alt="Lopad logo">
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
          <a href="/#faq"><i class="bi bi-question-circle"></i> FAQ</a>
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
  <?php endif; ?>
<?php
    }
}

if (!function_exists('render_header')) {
    function render_header(array $opts = []): void {
        $title = $opts['title'] ?? 'Asthapora';
        $extraHead = $opts['extraHead'] ?? '';
        $isAdmin = $opts['isAdmin'] ?? false;
        $bodyClass = $isAdmin ? 'page admin-page' : 'page';
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
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<?php render_navbar($opts); ?>
<?php
    }
}

if (!function_exists('render_footer')) {
    function render_footer(array $opts = []): void {
        $isAdmin = $opts['isAdmin'] ?? false;
        if ($isAdmin) {
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
        if (e.ctrlKey && e.shiftKey && !e.altKey && (e.key === 'a' || e.key === 'A')) {
          e.preventDefault();
          window.location.href = '/admin/login';
        }
      });
    })();
  </script>
</body>
</html>
<?php
            return;
        }
        ?>
  <footer class="site-footer">
    <div class="container">
      <div class="footer-grid">
        <div class="footer-brand">
          <img class="footer-logo" src="/assets/img/lopad.jpg" alt="Lopad logo">
          <div>
            <div class="footer-title">Temu Padel 2026</div>
            <div class="footer-subtitle">A Monkeybar x BAPORA Event</div>
          </div>
        </div>
        <div class="footer-links">
          <div class="footer-heading">Explore</div>
          <a href="#about">About</a>
          <a href="#program">Program</a>
          <a href="#social">Social</a>
          <a href="#packages">Packages</a>
          <a href="#faq">FAQ</a>
        </div>
        <div class="footer-links">
          <div class="footer-heading">Quick Links</div>
          <a href="/register">Register</a>
          <a href="/packages-view">Package Detail</a>
        </div>
        <div class="footer-contact">
          <div class="footer-heading">Contact</div>
          <div class="footer-item"><i class="bi bi-geo-alt"></i> MY PADEL, Jelupang Utama</div>
          <div class="footer-item"><i class="bi bi-envelope"></i> asthaporasports@gmail.com</div>
          <div class="footer-item"><i class="bi bi-clock"></i> 4 PM - 6 PM, Feb 28, 2026</div>
        </div>
      </div>
      <div class="footer-bottom">
        <div>©2026 Asthapora. All rights reserved.</div>
        <div class="footer-social">
          <a href="#"><i class="bi bi-instagram"></i></a>
          <a href="#"><i class="bi bi-facebook"></i></a>
          <a href="#"><i class="bi bi-twitter"></i></a>
          <a href="#"><i class="bi bi-youtube"></i></a>
        </div>
      </div>
    </div>
  </footer>
  <script>
    (function() {
      function isTypingTarget(target) {
        if (!target) return false;
        var tag = (target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || target.isContentEditable;
      }

      document.addEventListener('keydown', function(e) {
        if (isTypingTarget(e.target)) return;
        if (e.ctrlKey && e.shiftKey && !e.altKey && (e.key === 'a' || e.key === 'A')) {
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




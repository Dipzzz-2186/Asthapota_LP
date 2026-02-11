<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

$can_order = !empty($_SESSION['user_id']);
if (!$can_order) {
    unset($_SESSION['order_id']);
    unset($_SESSION['order_draft']);
}

$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$can_order) {
        redirect('/register?notice=register_required');
    }

    $qtys = [];
    $total = 0;

    foreach ($packages as $p) {
        $key = 'qty_' . $p['id'];
        $qty = max(0, (int)($_POST[$key] ?? 0));
        if ($qty > 0) {
            $qtys[$p['id']] = $qty;
            $total += $qty * (int)$p['price'];
        }
    }

    if (!$qtys) {
        $errors[] = 'Please select at least one package.';
    } else {
        $draftItems = [];
        foreach ($qtys as $pid => $qty) {
            $pkg = array_values(array_filter($packages, fn($p) => (int)$p['id'] === (int)$pid))[0];
            $draftItems[] = [
                'package_id' => (int)$pid,
                'name' => (string)$pkg['name'],
                'qty' => (int)$qty,
                'price' => (int)$pkg['price'],
            ];
        }

        $_SESSION['order_draft'] = [
            'user_id' => (int)$_SESSION['user_id'],
            'total' => (int)$total,
            'items' => $draftItems,
            'created_at' => time(),
        ];
        unset($_SESSION['order_id']);
        redirect('/order');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Packages - Temu Padel 2026</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --blue: #1658ad;
      --white: #f6f7fb;
      --soft: rgba(10, 30, 66, 0.55);
      --line: rgba(255, 255, 255, 0.34);
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    * {
      box-sizing: border-box;
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    *::-webkit-scrollbar {
      width: 0;
      height: 0;
      display: none;
    }

    html, body {
      margin: 0;
      min-height: 100%;
      height: 100%;
      overflow: hidden;
      overscroll-behavior: none;
      scroll-behavior: smooth;
    }

    body {
      color: var(--white);
      font-family: var(--font-body);
      font-weight: 500;
      letter-spacing: 0.2px;
      background: url('/assets/img/wallpaper3.jpg') center/cover no-repeat fixed;
      overflow-x: hidden;
      opacity: 0;
      transform: translateY(14px) scale(0.99);
      filter: blur(8px);
      transition: opacity 0.55s ease, transform 0.55s ease, filter 0.55s ease;
    }

    body.page-ready {
      opacity: 1;
      transform: none;
      filter: none;
    }

    body.page-leaving {
      opacity: 0;
      transform: translateY(-10px) scale(0.99);
      filter: blur(8px);
      pointer-events: none;
      transition: opacity 0.28s ease, transform 0.28s ease, filter 0.28s ease;
    }

    .landing {
      min-height: 100svh;
      height: 100svh;
      width: min(1260px, 95vw);
      margin: 0 auto;
      padding: 16px 0;
      display: flex;
      align-items: center;
    }

    #packagePanel {
      width: 100%;
      background: rgba(23, 45, 79, 0.58);
      border: 1px solid rgba(255, 255, 255, 0.40);
      border-radius: 20px;
      backdrop-filter: blur(7px);
      padding: clamp(24px, 3.1vw, 42px);
      box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
      max-height: calc(100svh - 32px);
      overflow-y: auto;
      overscroll-behavior: contain;
    }

    h1 {
      margin: 0 0 16px;
      font-family: var(--font-display);
      font-size: clamp(34px, 4vw, 54px);
      line-height: 0.95;
      font-weight: 400;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      text-shadow: 0 10px 20px rgba(0, 0, 0, 0.22);
    }

    .alert {
      margin: 0 0 14px;
      background: rgba(255, 140, 140, 0.24);
      border: 1px solid rgba(255, 210, 210, 0.72);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
    }

    .package-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 18px;
    }

    .package-card {
      background: rgba(25, 52, 91, 0.62);
      border: 1px solid rgba(255, 255, 255, 0.42);
      border-radius: 16px;
      padding: 20px;
      display: grid;
      gap: 12px;
      backdrop-filter: blur(2px);
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .package-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 30px rgba(0, 0, 0, 0.26);
      border-color: rgba(255, 255, 255, 0.65);
    }

    .package-card h3 {
      margin: 0;
      font-family: var(--font-accent);
      font-size: clamp(24px, 2.2vw, 30px);
      font-weight: 700;
      letter-spacing: 0.3px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .package-card ul {
      margin: 0;
      padding-left: 0;
      list-style: none;
      display: grid;
      gap: 7px;
      font-size: 15px;
    }

    .package-card li {
      display: inline-flex;
      align-items: flex-start;
      gap: 8px;
      line-height: 1.4;
      opacity: 0.96;
    }

    .package-card li i {
      color: #d5e5ff;
      margin-top: 2px;
    }

    .price {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-family: var(--font-display);
      font-size: clamp(26px, 2.4vw, 36px);
      font-weight: 800;
      letter-spacing: 0.8px;
    }

    .qty {
      display: grid;
      grid-template-columns: 40px 1fr 40px;
      gap: 8px;
      align-items: center;
    }

    .qty input,
    .qty button {
      height: 40px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.38);
      font-size: 16px;
    }

    .qty input {
      text-align: center;
      background: rgba(255, 255, 255, 0.93);
      color: #1f2d40;
      width: 100%;
      appearance: textfield;
      -moz-appearance: textfield;
    }

    .qty input::-webkit-outer-spin-button,
    .qty input::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }

    .qty button {
      background: rgba(11, 45, 97, 0.82);
      color: #fff;
      cursor: pointer;
      transition: transform 0.15s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .qty button:hover:not(:disabled) {
      transform: translateY(-1px);
      background: rgba(18, 66, 137, 0.9);
      border-color: rgba(255, 255, 255, 0.55);
    }

    .qty button:disabled {
      opacity: 0.45;
      cursor: not-allowed;
      transform: none;
    }

    .actions {
      margin-top: 18px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .btn {
      text-decoration: none;
      border: 0;
      border-radius: 999px;
      padding: 11px 18px;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: transform 0.15s ease, filter 0.2s ease, background 0.2s ease;
    }

    .btn:hover {
      transform: translateY(-2px);
      filter: brightness(1.04);
    }

    .btn.primary {
      background: #fff;
      color: var(--blue);
    }

    .btn.ghost {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.45);
    }

    .btn.ghost:hover {
      background: rgba(255, 255, 255, 0.24);
    }

    .auth-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.6);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 50;
    }

    .auth-modal.show {
      display: flex;
    }

    .auth-modal-card {
      width: min(430px, 100%);
      background: rgba(7, 22, 45, 0.95);
      border: 1px solid rgba(255, 255, 255, 0.25);
      border-radius: 14px;
      padding: 18px;
      display: grid;
      gap: 10px;
    }

    .auth-modal-title {
      margin: 0;
      font-family: var(--font-accent);
      font-size: 28px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .auth-modal-text {
      margin: 0;
      font-size: 14px;
      opacity: 0.92;
    }

    .auth-modal-actions {
      margin-top: 4px;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    @media (max-width: 640px) {
      .landing { width: 94vw; }
      #packagePanel { padding: 18px; }
      .actions .btn { width: 100%; }
      .auth-modal .btn { width: 100%; }
    }

    @media (prefers-reduced-motion: reduce) {
      body,
      body.page-ready,
      body.page-leaving {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
    }
  </style>
</head>
<body>
  <main class="landing">
    <section id="packagePanel">
      <h1><i class="bi bi-box-seam"></i> Select Your Package</h1>

      <?php if ($errors): ?>
        <div class="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="">
        <div class="package-grid">
          <?php foreach ($packages as $p): ?>
            <article class="package-card">
              <h3><i class="bi bi-stars"></i> <?= h($p['name']) ?></h3>
              <ul>
                <?php
                $features = array_filter(explode("\n", $p['description']));
                foreach ($features as $line):
                ?>
                  <li><i class="bi bi-check2-circle"></i> <?= h(trim($line)) ?></li>
                <?php endforeach; ?>
              </ul>
              <div class="price"><i class="bi bi-cash-coin"></i> <?= h(rupiah((int)$p['price'])) ?>,-</div>
              <div class="qty">
                <button type="button" data-action="minus" aria-label="Decrease quantity"><i class="bi bi-dash-lg"></i></button>
                <input type="number" name="qty_<?= (int)$p['id'] ?>" min="0" value="0" <?= $can_order ? '' : 'disabled' ?>>
                <button type="button" data-action="plus" aria-label="Increase quantity"><i class="bi bi-plus-lg"></i></button>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="actions">
          <?php if ($can_order): ?>
            <button class="btn primary" type="submit"><i class="bi bi-arrow-right-circle"></i> Continue to Order</button>
          <?php endif; ?>
          <?php if (!$can_order): ?>
            <a class="btn primary" href="/register"><i class="bi bi-person-plus"></i> Register Now</a>
          <?php endif; ?>
          <a class="btn ghost" href="/"><i class="bi bi-house-door"></i> Back to Home</a>
        </div>
      </form>
    </section>
  </main>

  <?php if (!$can_order): ?>
    <div class="auth-modal" id="authModal" role="dialog" aria-modal="true" aria-labelledby="authModalTitle">
      <div class="auth-modal-card">
        <h2 class="auth-modal-title" id="authModalTitle"><i class="bi bi-shield-lock"></i> Register Required</h2>
        <p class="auth-modal-text">Please register first before selecting packages.</p>
        <div class="auth-modal-actions">
          <a class="btn primary" href="/register"><i class="bi bi-person-plus"></i> Register Now</a>
          <button class="btn ghost" type="button" id="closeAuthModal"><i class="bi bi-x-lg"></i> Close</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script>
    (function () {
      var body = document.body;
      var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (body && !reduceMotion) {
        requestAnimationFrame(function () {
          body.classList.add('page-ready');
        });
      } else if (body) {
        body.classList.add('page-ready');
      }

      function canAnimateLink(a) {
        if (!a) return false;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return false;
        if (href.indexOf('javascript:') === 0 || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) return false;
        if (a.target && a.target !== '_self') return false;
        try {
          var next = new URL(a.href, window.location.href);
          return next.origin === window.location.origin;
        } catch (err) {
          return false;
        }
      }

      document.querySelectorAll('a[href]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          if (reduceMotion || !body || !canAnimateLink(a) || e.defaultPrevented) return;
          e.preventDefault();
          if (body.classList.contains('page-leaving')) return;
          body.classList.add('page-leaving');
          window.setTimeout(function () {
            window.location.href = a.href;
          }, 260);
        });
      });

      var canOrder = <?= $can_order ? 'true' : 'false' ?>;
      var authModal = document.getElementById('authModal');
      var closeAuthModalBtn = document.getElementById('closeAuthModal');

      document.querySelectorAll('.qty').forEach(function(group){
        var input = group.querySelector('input');
        var minusBtn = group.querySelector('[data-action="minus"]');

        function getSafeValue() {
          return Math.max(0, parseInt(input && input.value ? input.value : '0', 10) || 0);
        }

        function syncMinusState() {
          if (!input || !minusBtn) return;
          var value = getSafeValue();
          input.value = value;
          minusBtn.disabled = value <= 0;
        }

        syncMinusState();

        if (input) {
          input.addEventListener('input', syncMinusState);
          input.addEventListener('change', syncMinusState);
        }

        group.addEventListener('click', function(e){
          var trigger = e.target.closest('[data-action]');
          var action = trigger ? trigger.dataset.action : '';
          if (action !== 'plus' && action !== 'minus') return;
          if (trigger && trigger.disabled) return;

          if (!canOrder) {
            if (authModal) authModal.classList.add('show');
            return;
          }

          if (!input) return;
          if (action === 'plus') {
            input.value = parseInt(input.value || '0', 10) + 1;
          }
          if (action === 'minus') {
            var v = Math.max(0, parseInt(input.value || '0', 10) - 1);
            input.value = v;
          }
          syncMinusState();
        });
      });

      if (authModal && closeAuthModalBtn) {
        closeAuthModalBtn.addEventListener('click', function () {
          authModal.classList.remove('show');
        });
      }
    })();
  </script>
</body>
</html>

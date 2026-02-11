<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/layout/app.php';
ensure_session();

$isAdmin = is_admin_logged_in();
$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
render_header([
  'title' => 'Packages - Asthapora',
  'isAdmin' => $isAdmin,
]);
?>

  <section class="hero-section">
    <div class="container hero-grid">
      <div class="hero-card fade-up">
        <div class="pill"><i class="bi bi-bag-heart"></i> Package Detail</div>
        <h1>Choose Your Perfect Package</h1>
        <p>
          Experience a vibrant padel gathering with curated packages, friendly matches,
          and community energy. Register first to unlock the best packages.
        </p>
        <?php if (!$isAdmin): ?>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <a class="btn primary" href="/register">
              Register Now <i class="bi bi-arrow-right"></i>
            </a>
            <a class="btn ghost" href="/#packages">Back to Home</a>
          </div>
        <?php endif; ?>

        <div class="hero-meta">
          <div class="meta-card">
            <strong><i class="bi bi-calendar-event"></i> Date</strong>
            February 28th, 2026
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-geo-alt"></i> Location</strong>
            MY PADEL, Jelupang Utama
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-clock"></i> Time</strong>
            4 PM - 6 PM
          </div>
        </div>
      </div>

      <div class="hero-media fade-up delay-1">
        <div class="media-main">
          <div class="pill" style="background:rgba(255,255,255,0.2);color:#fff;">
            <i class="bi bi-stars"></i> Temu Padel 2026
          </div>
          <div>
            <h3>Curated Package Experience</h3>
            <span>Find the package that fits your event goals and match style.</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="packages">
    <div class="container">
      <div class="section-title center">Packages</div>

      <div class="package-grid">
        <?php foreach ($packages as $p): ?>
          <div class="package-card fade-up">
            <div class="pill">
              <i class="bi bi-bag-heart"></i>
              Package
            </div>
            <h3><?= h($p['name']) ?></h3>

            <div style="color:var(--muted);">What's Included</div>
            <ul>
              <?php
              $features = array_filter(explode("\n", $p['description']));
              foreach ($features as $line):
              ?>
                <li><?= h(trim($line)) ?></li>
              <?php endforeach; ?>
            </ul>

            <div style="font-size:22px;font-weight:700;"><?= h(rupiah((int)$p['price'])) ?>,-</div>

            <?php if (!$isAdmin): ?>
              <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
                <a class="btn primary" href="/register">
                  <i class="bi bi-person-plus"></i>
                  Register
                </a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <div class="card center">
        <h2 class="section-title" style="margin-bottom:12px;">Ready to Join Temu Padel 2026?</h2>
        <p style="color:var(--muted);margin-bottom:20px;">
          Register now to secure your spot and enjoy the best padel experience with fellow enthusiasts.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
          <?php if (!$isAdmin): ?>
            <a class="btn primary" href="/register">
              <i class="bi bi-person-plus"></i>
              Register Now
            </a>
          <?php endif; ?>
          <a class="btn ghost" href="/#packages">
            <i class="bi bi-house"></i>
            Back to Home
          </a>
        </div>
      </div>
    </div>
  </section>

<?php render_footer(); ?>

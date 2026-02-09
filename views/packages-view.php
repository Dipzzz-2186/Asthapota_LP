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
  'title' => 'Packages - Temu Padel',
  'isAdmin' => $isAdmin,
]);
?>

  <section class="hero-section">
    <div class="container">
      <div class="hero-badge">
        <i class="bi bi-tag"></i>
        Available Packages
      </div>
      <h1 class="hero-title">Choose Your Perfect Package</h1>
      <p class="hero-subtitle">
        Experience a vibrant padel gathering with curated packages, friendly matches, 
        and community energy. Register first to unlock the best packages.
      </p>
    </div>
  </section>

  <section class="section">
    <div class="container">
      <!-- Info Cards -->
      <div class="info-cards">
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-calendar-event"></i>
          </div>
          <div class="info-content">
            <h4>Event Date</h4>
            <p>February 28th, 2026</p>
          </div>
        </div>
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-geo-alt"></i>
          </div>
          <div class="info-content">
            <h4>Location</h4>
            <p>MY PADEL, Jelupang Utama</p>
          </div>
        </div>
        <div class="info-card">
          <div class="info-icon">
            <i class="bi bi-clock"></i>
          </div>
          <div class="info-content">
            <h4>Time</h4>
            <p>4 PM - 6 PM</p>
          </div>
        </div>
      </div>

      <!-- Package Grid -->
      <div class="package-grid">
        <?php foreach ($packages as $p): ?>
          <div class="package-card fade-up">
            <div class="pill">
              <i class="bi bi-bag-heart"></i> 
              Package
            </div>
            <h3><?= h($p['name']) ?></h3>
            
            <div class="package-features-label">
              What's Included
            </div>
            
            <ul>
              <?php 
              $features = array_filter(explode("\n", $p['description']));
              foreach ($features as $line): 
              ?>
                <li><?= h(trim($line)) ?></li>
              <?php endforeach; ?>
            </ul>
            
            <div class="package-price">
              <?= h(rupiah((int)$p['price'])) ?>
              <small>,-</small>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="cta-section">
    <div class="container">
      <h2 class="cta-title">Ready to Join Temu Padel 2026?</h2>
      <p class="cta-text">
        Register now to secure your spot and enjoy the best padel experience with fellow enthusiasts.
      </p>
      <div class="cta-buttons">
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
  </section>

<?php render_footer(); ?>

<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/auth.php';
ensure_session();

$isAdmin = is_admin_logged_in();
$db = get_db();
$packages = $db->query('SELECT * FROM packages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asthapora</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    /* Marquee Slider Container */
    .sponsor-slider-container {
      overflow: hidden;
      width: 100%;
      position: relative;
    }

    .marquee-content {
      display: flex;
      gap: 24px;
      white-space: nowrap;
      width: max-content;
      will-change: transform;
    }

    .marquee-content:hover {
      animation-play-state: paused;
    }

    .marquee-content .logo-chip {
      flex: 0 0 auto;
      padding: 20px;
      border-radius: 15px;
      background: var(--surface-2);
      display: grid;
      place-items: center;
      min-height: 75px;
      min-width: 170px;
      transition: all 0.3s ease;
      border: 1px solid transparent;
      text-decoration: none;
      color: inherit;
    }

    .marquee-content .logo-chip:hover {
      border-color: var(--primary);
      transform: translateY(-6px);
      box-shadow: 0 15px 30px rgba(30, 94, 216, 0.15);
    }

    .marquee-content .logo-chip img {
      max-height: 35px;
      max-width: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
    }

    .marquee-content .logo-chip.dark {
      background: #0c1b36;
    }

    .marquee-content .logo-chip.dark img {
      max-height: 65px;
    }

    /* Marquee Animation - SLOW */
    @keyframes marquee {
      0% {
        transform: translateX(0);
      }
      100% {
        transform: translateX(-100%);
      }
    }

    /* Gradient overlay untuk efek fading di pinggir */
    .sponsor-slider-container::before,
    .sponsor-slider-container::after {
      content: '';
      position: absolute;
      top: 0;
      width: 100px;
      height: 100%;
      z-index: 2;
      pointer-events: none;
    }

    .sponsor-slider-container::before {
      left: 0;
      background: linear-gradient(to right, var(--surface) 0%, transparent 100%);
    }

    .sponsor-slider-container::after {
      right: 0;
      background: linear-gradient(to left, var(--surface) 0%, transparent 100%);
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .sponsor-slider-container::before,
      .sponsor-slider-container::after {
        width: 60px;
      }
      
      .marquee-content .logo-chip {
        min-width: 140px;
        padding: 16px;
      }
      
      .marquee-content {
        gap: 16px;
      }
      
      @keyframes marquee {
        0% {
          transform: translateX(0);
        }
        100% {
          transform: translateX(-100%);
        }
      }
    }
  </style>
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <div class="brand-badge">TP</div>
          <div>
            <div>Temu Padel</div>
            <small style="color:var(--muted);">A Monkeybar x BAPORA Event</small>
          </div>
        </div>
        <nav class="nav">
          <a href="#about"><i class="bi bi-flag"></i> About</a>
          <a href="#program"><i class="bi bi-calendar-event"></i> Program</a>
          <a href="#social"><i class="bi bi-share"></i> Social</a>
          <a href="#news"><i class="bi bi-newspaper"></i> News</a>
          <a href="#packages"><i class="bi bi-bag"></i> Packages</a>
        </nav>
        <div class="topbar-actions">
          <?php if ($isAdmin): ?>
            <a class="btn ghost" href="/admin/dashboard">Admin Dashboard</a>
            <a class="btn primary" href="/admin/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
          <?php else: ?>
            <a class="icon-btn" href="/register"><i class="bi bi-person"></i></a>
            <a class="icon-btn" href="#packages"><i class="bi bi-bag"></i></a>
            <a class="btn ghost" href="/admin/login">Admin Login</a>
            <a class="btn primary" href="/register">Register Now <i class="bi bi-arrow-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <section class="hero-section">
    <div class="container hero-grid">
      <div class="hero-card fade-up">
        <div class="pill"><i class="bi bi-stars"></i> Main Event 2026</div>
        <h1>Temu Padel 2026</h1>
        <p>Experience a vibrant padel gathering with curated packages, friendly matches, and community energy. Register first to unlock the best packages.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <?php if ($isAdmin): ?>
            <button class="btn primary" type="button" disabled>Admin tidak bisa order</button>
          <?php else: ?>
            <a class="btn primary" href="/register">Register Here <i class="bi bi-arrow-right"></i></a>
          <?php endif; ?>
          <a class="btn ghost" href="#packages">View Packages</a>
        </div>
        <div class="hero-meta">
          <div class="meta-card">
            <strong><i class="bi bi-calendar3"></i> Date</strong>
            February 28th, 2026
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-clock"></i> Time</strong>
            4 PM - 6 PM
          </div>
          <div class="meta-card">
            <strong><i class="bi bi-geo-alt"></i> Venue</strong>
            MY PADEL, Jelupang Utama
          </div>
        </div>
      </div>
      <div class="hero-media fade-up delay-1">
        <div class="media-main">
          <div class="pill" style="background:rgba(255,255,255,0.2);color:#fff;"><i class="bi bi-play-circle"></i> Pause Slide</div>
          <div>
            <h3>Dublin-Style Race Vibe</h3>
            <span>Dynamic, friendly competition and curated social moments.</span>
          </div>
        </div>
        <div class="media-dots">
          <span class="active"></span>
          <span></span>
          <span></span>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="about">
    <div class="container sponsor-bar fade-up delay-2">
      <div class="section-title">Supported By</div>
      <div class="sponsor-slider-container">
        <div class="marquee-content">
          <!-- Set 1 -->
          <a class="logo-chip" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="HIPPI">
            <img src="/assets/img/logo.webp" alt="HIPPI">
          </a>
          <a class="logo-chip" href="https://fcom.co.id/" target="_blank" rel="noopener" aria-label="FCOM">
            <img src="/assets/img/fcom.png" alt="FCOM">
          </a>
          <a class="logo-chip dark" href="https://ayo.co.id/" target="_blank" rel="noopener" aria-label="MyPadel">
            <img src="/assets/img/mypadel.png" alt="MyPadel">
          </a>
          <a class="logo-chip dark" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="BAPORA">
            <img src="/assets/img/hippi.png" alt="BAPORA">
          </a>
          
          <!-- Set 2 (Duplikat) -->
          <a class="logo-chip" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="HIPPI">
            <img src="/assets/img/logo.webp" alt="HIPPI">
          </a>
          <a class="logo-chip" href="https://fcom.co.id/" target="_blank" rel="noopener" aria-label="FCOM">
            <img src="/assets/img/fcom.png" alt="FCOM">
          </a>
          <a class="logo-chip dark" href="https://ayo.co.id/" target="_blank" rel="noopener" aria-label="MyPadel">
            <img src="/assets/img/mypadel.png" alt="MyPadel">
          </a>
          <a class="logo-chip dark" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="BAPORA">
            <img src="/assets/img/hippi.png" alt="BAPORA">
          </a>
          
          <!-- Set 3 (Duplikat untuk smoothness) -->
          <a class="logo-chip" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="HIPPI">
            <img src="/assets/img/logo.webp" alt="HIPPI">
          </a>
          <a class="logo-chip" href="https://fcom.co.id/" target="_blank" rel="noopener" aria-label="FCOM">
            <img src="/assets/img/fcom.png" alt="FCOM">
          </a>
          <a class="logo-chip dark" href="https://ayo.co.id/" target="_blank" rel="noopener" aria-label="MyPadel">
            <img src="/assets/img/mypadel.png" alt="MyPadel">
          </a>
          <a class="logo-chip dark" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="BAPORA">
            <img src="/assets/img/hippi.png" alt="BAPORA">
          </a>
          
          <!-- Set 4 (Duplikat untuk extra smooth) -->
          <a class="logo-chip" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="HIPPI">
            <img src="/assets/img/logo.webp" alt="HIPPI">
          </a>
          <a class="logo-chip" href="https://fcom.co.id/" target="_blank" rel="noopener" aria-label="FCOM">
            <img src="/assets/img/fcom.png" alt="FCOM">
          </a>
          <a class="logo-chip dark" href="https://ayo.co.id/" target="_blank" rel="noopener" aria-label="MyPadel">
            <img src="/assets/img/mypadel.png" alt="MyPadel">
          </a>
          <a class="logo-chip dark" href="https://www.hippi.or.id/" target="_blank" rel="noopener" aria-label="BAPORA">
            <img src="/assets/img/hippi.png" alt="BAPORA">
          </a>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="program">
    <div class="container grid-2">
      <div class="program-card float">
        <h3>Our Program & Survey</h3>
        <p>Share your preferences to tailor matchups, food, and community moments for this event.</p>
      </div>
      <div class="stack">
        <div class="stack-card">
          <div>
            <strong>Race Series 2026 Now Open</strong>
            <span>Embark on a friendly padel challenge with curated rounds.</span>
          </div>
          <div class="icon-btn"><i class="bi bi-arrow-up-right"></i></div>
        </div>
        <div class="stack-card soft">
          <div>
            <strong>Charity Entries Are Now Open</strong>
            <span>Support a meaningful cause while joining the rally.</span>
          </div>
          <div class="icon-btn"><i class="bi bi-arrow-up-right"></i></div>
        </div>
        <div class="stack-card soft">
          <div>
            <strong>Thanks To All For Completing The Survey</strong>
            <span>Your feedback makes the event experience sharper.</span>
          </div>
          <div class="icon-btn"><i class="bi bi-arrow-up-right"></i></div>
        </div>
      </div>
    </div>
  </section>

  <section class="section" id="social">
    <div class="container grid-2">
      <div class="card">
        <div class="section-title">Follow Us On Social Media</div>
        <div class="social-list">
          <a href="#"><span><i class="bi bi-instagram"></i> Instagram</span> <i class="bi bi-arrow-up-right"></i></a>
          <a href="#"><span><i class="bi bi-facebook"></i> Facebook</span> <i class="bi bi-arrow-up-right"></i></a>
          <a href="#"><span><i class="bi bi-twitter"></i> Twitter</span> <i class="bi bi-arrow-up-right"></i></a>
          <a href="#"><span><i class="bi bi-youtube"></i> YouTube</span> <i class="bi bi-arrow-up-right"></i></a>
        </div>
      </div>
      <div class="card">
        <div class="news-thumb" style="border-radius:16px;background-image:url('https://images.unsplash.com/photo-1521412644187-c49fa049e84d?auto=format&fit=crop&w=1200&q=80');height:220px;"></div>
        <div style="margin-top:14px;color:var(--muted);">Community highlights from our latest gathering.</div>
      </div>
    </div>
  </section>

  <section class="section" id="news">
    <div class="container">
      <div class="section-title center">News & Views</div>
      <div class="news-grid">
        <div class="news-card">
          <div class="news-thumb" style="background-image:url('https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=800&q=80');"></div>
          <div class="news-body">
            <strong>2026 Winners</strong>
            <span style="color:var(--muted);font-size:13px;">Spotlight on our standout pairs.</span>
          </div>
        </div>
        <div class="news-card">
          <div class="news-thumb" style="background-image:url('https://images.unsplash.com/photo-1500534314209-a25ddb2bd429?auto=format&fit=crop&w=800&q=80');"></div>
          <div class="news-body">
            <strong>Warmup Clinic</strong>
            <span style="color:var(--muted);font-size:13px;">Tips to power up your serve.</span>
          </div>
        </div>
        <div class="news-card">
          <div class="news-thumb" style="background-image:url('https://images.unsplash.com/photo-1517649763962-0c623066013b?auto=format&fit=crop&w=800&q=80');"></div>
          <div class="news-body">
            <strong>Community Stories</strong>
            <span style="color:var(--muted);font-size:13px;">What makes Temu Padel special.</span>
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
            <div class="pill"><i class="bi bi-bag-heart"></i> Package</div>
            <h3><?= h($p['name']) ?></h3>
            <div style="color:var(--muted);">What you get:</div>
            <ul>
              <?php foreach (explode("\n", $p['description']) as $line): ?>
                <li><?= h($line) ?></li>
              <?php endforeach; ?>
            </ul>
            <div style="font-size:22px;font-weight:700;"><?= h(rupiah((int)$p['price'])) ?>,-</div>
            <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
              <?php if ($isAdmin): ?>
                <button class="btn primary" type="button" disabled>Admin tidak bisa order</button>
              <?php else: ?>
                <a class="btn primary" href="/register">Register to Order</a>
              <?php endif; ?>
              <a class="btn ghost" href="/packages-view">View Detail</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <script>
document.addEventListener('DOMContentLoaded', function() {
  // Smooth scroll untuk semua link internal
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
      const targetId = this.getAttribute('href');
      if (!targetId || targetId === '#') return;

      const targetElement = document.querySelector(targetId);
      if (!targetElement) return;

      e.preventDefault();

      // Hitung offset untuk navbar
      const header = document.querySelector('.page-header');
      const headerHeight = header ? header.offsetHeight : 80;
      const extraPadding = 20;
      const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset;
      const offsetPosition = targetPosition - headerHeight - extraPadding;

      window.scrollTo({
        top: offsetPosition,
        behavior: 'smooth'
      });

      // Update active class di navbar saja
      if (this.closest('.nav')) {
        document.querySelectorAll('.nav a').forEach(link => {
          link.classList.remove('active');
        });
        this.classList.add('active');
      }

      history.pushState(null, null, targetId);
    });
  });
  
  // Update active class saat scroll
  const sections = document.querySelectorAll('section[id]');
  const navLinks = document.querySelectorAll('.nav a[href^="#"]');
  
  function updateActiveNav() {
    let current = '';
    
    sections.forEach(section => {
      const sectionTop = section.offsetTop;
      const sectionHeight = section.clientHeight;
      
      if (window.scrollY >= (sectionTop - 150)) {
        current = section.getAttribute('id');
      }
    });
    
    navLinks.forEach(link => {
      link.classList.remove('active');
      if (link.getAttribute('href') === `#${current}`) {
        link.classList.add('active');
      }
    });
  }
  
  window.addEventListener('scroll', updateActiveNav);
  updateActiveNav(); // Panggil pertama kali
  
  // Marquee Slider Logic
  (function () {
    function startMarquee(selector, speed) {
      const marquee = document.querySelector(selector);
      if (!marquee) return;

      const originalContent = marquee.innerHTML;

      // clone terus sampai panjang > 2x layar
      while (marquee.scrollWidth < window.innerWidth * 2) {
        marquee.innerHTML += originalContent;
      }

      let offset = 0;
      const resetPoint = marquee.scrollWidth / 2;

      function animate() {
        offset += speed;
        marquee.style.transform = `translateX(-${offset}px)`;

        if (offset >= resetPoint) {
          offset = 0;
        }

        requestAnimationFrame(animate);
      }

      animate();
    }

    window.addEventListener('load', function () {
      startMarquee('.marquee-content', 0.25);
    });
  })();
});
</script>
</body>
</html>

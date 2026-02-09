<?php
require_once __DIR__ . '/../app/helpers.php';
ensure_session();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Temu Padel</title>
  <link rel="stylesheet" href="/assets/css/style.css">
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
          <a href="#faq"><i class="bi bi-question-circle"></i> FAQ</a>
        </nav>
        <div class="topbar-actions">
          <a class="icon-btn" href="/register.php"><i class="bi bi-person"></i></a>
          <a class="icon-btn" href="/packages.php"><i class="bi bi-bag"></i></a>
          <a class="btn primary" href="/register.php">Register Now <i class="bi bi-arrow-right"></i></a>
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
          <a class="btn primary" href="/register.php">Register Here <i class="bi bi-arrow-right"></i></a>
          <a class="btn ghost" href="/packages.php">View Packages</a>
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
      <div class="section-title">Our Title Sponsor</div>
      <div class="sponsor-logos">
        <div class="logo-chip">Enterprise</div>
        <div class="logo-chip">Irish Life</div>
        <div class="logo-chip">Ishka</div>
        <div class="logo-chip">Activa</div>
        <div class="logo-chip">Dublin Bus</div>
        <div class="logo-chip">Fáilte</div>
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

  <section class="section">
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

  <section class="section" id="faq">
    <div class="container">
      <div class="section-title center">Frequently Asked Questions</div>
      <div class="faq">
        <div class="faq-item">
          <div>What is the date of the event?</div>
          <i class="bi bi-plus-circle"></i>
        </div>
        <div class="faq-item">
          <div>What time does the event start?</div>
          <i class="bi bi-plus-circle"></i>
        </div>
        <div class="faq-item">
          <div>Where is the start line for the event?</div>
          <i class="bi bi-plus-circle"></i>
        </div>
        <div class="faq-item">
          <div>Is this event open to walkers?</div>
          <i class="bi bi-plus-circle"></i>
        </div>
        <div class="faq-item">
          <div>Can I volunteer for this event?</div>
          <i class="bi bi-plus-circle"></i>
        </div>
      </div>
    </div>
  </section>
</body>
</html>

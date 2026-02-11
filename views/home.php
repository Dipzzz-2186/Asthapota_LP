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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asthapora</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="page">
  <header class="page-header">
    <div class="container">
      <div class="topbar">
        <div class="brand">
          <img class="brand-badge" src="/assets/img/lopad.jpg" alt="Lopad logo">
          <div>
            <div>Temu Padel</div>
            <small style="color:var(--muted);">A Monkeybar x BAPORA Event</small>
          </div>
        </div>
        <nav class="nav">
          <a href="#about"><i class="bi bi-flag"></i> About</a>
          <a href="#program"><i class="bi bi-calendar-event"></i> Program</a>
          <a href="#social"><i class="bi bi-share"></i> Social</a>
          <a href="#packages"><i class="bi bi-bag"></i> Packages</a>
        </nav>
        <div class="topbar-actions">
          <?php if ($isAdmin): ?>
            <a class="btn ghost" href="/admin/dashboard">Admin Dashboard</a>
            <a class="btn primary" href="/admin/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
          <?php else: ?>
            <a class="icon-btn" href="/register"><i class="bi bi-person"></i></a>
            <a class="icon-btn" href="#packages"><i class="bi bi-bag"></i></a>
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
        <p>Rasakan serunya padel bersama paket pilihan, pertandingan yang ramah, dan energi komunitas. Daftar lebih dulu untuk membuka paket terbaik.</p>
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
        <div class="media-main" id="heroSliderMain">
          <div class="pill" style="background:rgba(255,255,255,0.2);color:#fff;"><i class="bi bi-images"></i> Event Gallery</div>
          <div>
            <h3 id="heroSliderTitle">Temu Padel Moments</h3>
            <span id="heroSliderCaption">Geser foto dengan tombol panah untuk lihat suasana event.</span>
          </div>
        </div>
        <div class="media-slider-controls">
          <button class="media-arrow" type="button" id="heroPrev" aria-label="Slide sebelumnya">
            <i class="bi bi-chevron-left"></i>
          </button>
          <div class="media-dots" id="heroDots">
            <span class="active"></span>
            <span></span>
            <span></span>
          </div>
          <button class="media-arrow" type="button" id="heroNext" aria-label="Slide selanjutnya">
            <i class="bi bi-chevron-right"></i>
          </button>
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
        <div class="news-thumb news-thumb-photo">
          <img src="/assets/img/orpadel1.jpg" alt="Community highlights photo">
        </div>
        <div style="margin-top:14px;color:var(--muted);">Community highlights from our latest gathering.</div>
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

  <section class="section" id="faq">
    <div class="container">
      <div class="section-title center">FAQ</div>
      <div class="faq">
        <button class="faq-item" type="button">
          <div>
            <strong>Di mana lokasi acara?</strong>
            <div class="faq-answer">MY PADEL, Jelupang Utama.</div>
          </div>
          <span class="faq-icon"><i class="bi bi-geo-alt"></i></span>
        </button>
        <button class="faq-item" type="button">
          <div>
            <strong>Jam berapa acaranya?</strong>
            <div class="faq-answer">Pukul 16.00 - 18.00 WIB, 28 Februari 2026.</div>
          </div>
          <span class="faq-icon"><i class="bi bi-clock"></i></span>
        </button>
        <button class="faq-item" type="button">
          <div>
            <strong>Bagaimana cara daftar?</strong>
            <div class="faq-answer">Klik Register Now di halaman ini, isi data, lalu pilih paket.</div>
          </div>
          <span class="faq-icon"><i class="bi bi-person-plus"></i></span>
        </button>
        <button class="faq-item" type="button">
          <div>
            <strong>Apa saja yang harus dibawa?</strong>
            <div class="faq-answer">Raket padel, sepatu olahraga, dan outfit yang nyaman.</div>
          </div>
          <span class="faq-icon"><i class="bi bi-bag"></i></span>
        </button>
        <button class="faq-item" type="button">
          <div>
            <strong>Apakah kuota terbatas?</strong>
            <div class="faq-answer">Ya, kuota terbatas. Disarankan daftar lebih awal.</div>
          </div>
          <span class="faq-icon"><i class="bi bi-exclamation-circle"></i></span>
        </button>
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
  
  // Marquee Slider Logic dengan pause on hover
  (function () {
    let isHovering = false;
    let animationId = null;
    let offset = 0;
    const speed = 0.25;

    function startMarquee() {
      const marquee = document.querySelector('.marquee-content');
      if (!marquee) return;

      const originalContent = marquee.innerHTML;

      // clone konten sampai cukup panjang untuk efek seamless
      while (marquee.scrollWidth < window.innerWidth * 2) {
        marquee.innerHTML += originalContent;
      }

      // Tambah event listener untuk semua logo
      const logos = marquee.querySelectorAll('.logo-chip');
      logos.forEach(logo => {
        logo.addEventListener('mouseenter', () => {
          isHovering = true;
          if (animationId) {
            cancelAnimationFrame(animationId);
            animationId = null;
          }
        });
        
        logo.addEventListener('mouseleave', () => {
          isHovering = false;
          if (!animationId) {
            animateMarquee(marquee);
          }
        });
      });

      // Mulai animasi
      animateMarquee(marquee);
    }

    function animateMarquee(marquee) {
      if (isHovering) return; // Berhenti jika ada logo yang di-hover
      
      offset += speed;
      const resetPoint = marquee.scrollWidth / 2;
      
      if (offset >= resetPoint) {
        offset = 0;
      }
      
      marquee.style.transform = `translateX(-${offset}px)`;
      animationId = requestAnimationFrame(() => animateMarquee(marquee));
    }

  window.addEventListener('load', startMarquee);
  })();

  // FAQ: klik pertanyaan untuk menampilkan jawaban
  document.querySelectorAll('.faq-item').forEach(item => {
    item.addEventListener('click', () => {
      item.classList.toggle('open');
    });
  });

  // Hidden admin login shortcut: Ctrl + Shift + A
  function isTypingTarget(target) {
    if (!target) return false;
    const tag = (target.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || target.isContentEditable;
  }

  document.addEventListener('keydown', function(e) {
    if (isTypingTarget(e.target)) return;
    if (e.ctrlKey && e.shiftKey && !e.altKey && (e.key === 'a' || e.key === 'A')) {
      e.preventDefault();
      window.location.href = '/admin/login';
    }
  });

  // Hero image slider (manual: tombol kiri/kanan + dots)
  (function () {
    const slides = [
      {
        image: '/assets/img/orpadel1.jpg',
        title: 'Temu Padel Moments',
        caption: 'Geser foto dengan tombol panah untuk lihat suasana event.'
      },
      {
        image: '/assets/img/orpadel2.jpg',
        title: 'Friendly Match Energy',
        caption: 'Kompetisi santai, networking, dan pengalaman komunitas yang seru.'
      },
      {
        image: '/assets/img/orpadel3.jpg',
        title: 'Community & Social Vibes',
        caption: 'Setiap slide menangkap momen terbaik dari rangkaian acara.'
      }
    ];

    const mediaMain = document.getElementById('heroSliderMain');
    const title = document.getElementById('heroSliderTitle');
    const caption = document.getElementById('heroSliderCaption');
    const prevBtn = document.getElementById('heroPrev');
    const nextBtn = document.getElementById('heroNext');
    const dots = document.querySelectorAll('#heroDots span');

    if (!mediaMain || !title || !caption || !prevBtn || !nextBtn || dots.length !== slides.length) {
      return;
    }

    let currentIndex = 0;

    function renderSlide(index) {
      const slide = slides[index];
      mediaMain.style.backgroundImage = `linear-gradient(130deg, rgba(33, 40, 84, 0.58), rgba(33, 40, 84, 0.05)), url('${slide.image}')`;
      mediaMain.style.backgroundSize = 'cover';
      mediaMain.style.backgroundPosition = 'center';
      title.textContent = slide.title;
      caption.textContent = slide.caption;

      dots.forEach((dot, i) => {
        dot.classList.toggle('active', i === index);
      });
    }

    function goToSlide(index) {
      currentIndex = (index + slides.length) % slides.length;
      renderSlide(currentIndex);
    }

    prevBtn.addEventListener('click', () => goToSlide(currentIndex - 1));
    nextBtn.addEventListener('click', () => goToSlide(currentIndex + 1));

    dots.forEach((dot, index) => {
      dot.addEventListener('click', () => goToSlide(index));
    });

    renderSlide(currentIndex);
  })();
});
</script>
<?php render_footer(); ?>

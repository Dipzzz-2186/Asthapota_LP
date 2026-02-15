<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asthapora Home</title>
  <link rel="icon" type="image/png" href="/assets/img/LogoTitleAsthapora.png">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Anton&family=Manrope:wght@400;500;700;800&family=Playfair+Display:ital,wght@0,600;1,500&display=swap');
    @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css');

    :root {
      --text-white: #ffffff;
      --text-soft: rgba(255, 255, 255, 0.85);
      --soft-shadow: rgba(0, 0, 0, 0.3);
      --font-body: "Manrope", "Segoe UI", Tahoma, sans-serif;
      --font-display: "Anton", "Arial Narrow", Impact, sans-serif;
      --font-accent: "Playfair Display", Georgia, serif;
    }

    * { 
      box-sizing: border-box; 
      margin: 0; 
      padding: 0;
    }

    body {
      min-height: 100vh;
      min-height: 100svh;
      color: var(--text-white);
      font-family: var(--font-body);
      overflow-x: hidden;
      overflow-y: auto;
      position: relative;
      isolation: isolate;
      background: #03121b;
      opacity: 0;
      transform: translateY(8px);
      filter: blur(4px);
      transition: opacity 0.5s ease, transform 0.5s ease, filter 0.5s ease;
    }

    body.page-ready {
      opacity: 1;
      transform: none;
      filter: none;
    }

    body::before {
      content: "";
      position: fixed;
      inset: 0;
      z-index: -2;
      background: url('/assets/img/WallpaperHome.jpeg') center center / cover no-repeat;
    }

    body::after {
      content: "";
      position: fixed;
      inset: 0;
      z-index: -1;
      background:
        radial-gradient(58% 44% at 50% 16%, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0) 68%),
        linear-gradient(to bottom, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.15) 48%, rgba(0,0,0,0.52) 100%);
      pointer-events: none;
      animation: ambient-breathe 5.5s ease-in-out infinite;
    }

    .container {
      min-height: 100vh;
      min-height: 100svh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: clamp(20px, 3vh, 40px) clamp(20px, 4vw, 40px);
      text-align: center;
      gap: clamp(20px, 4vh, 60px);
    }

    .container * {
      -webkit-text-stroke: 0.65px rgba(0, 0, 0, 0.58);
    }

    .instagram-btn,
    .instagram-btn * {
      -webkit-text-stroke: 0;
      text-shadow: none;
    }

    .fade-item {
      opacity: 0;
      transform: translateY(12px);
      animation: fade-up 0.6s ease forwards;
      animation-delay: var(--delay, 0ms);
    }

    .launching-text {
      font-family: var(--font-display);
      font-style: normal;
      font-weight: 400;
      font-size: clamp(18px, 2.2vw, 32px);
      letter-spacing: clamp(1px, 0.16vw, 2.2px);
      text-shadow: 0 4px 14px var(--soft-shadow);
      display: inline-flex;
      align-items: center;
      gap: clamp(6px, 1vw, 10px);
      text-transform: uppercase;
      line-height: 1.3;
    }

    .logo-section {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 100%;
      max-width: 1000px;
      flex-shrink: 0;
    }

    .main-logo {
      width: 100%;
      max-width: clamp(400px, 65vw, 800px);
      height: auto;
      object-fit: contain;
      transition: transform 0.25s ease, filter 0.25s ease;
      filter: drop-shadow(0 8px 20px rgba(0, 0, 0, 0.26));
      animation: logo-float 4.8s ease-in-out infinite;
    }

    .main-logo:hover {
      transform: translateY(-4px) scale(1.01);
      filter: drop-shadow(0 14px 24px rgba(0, 0, 0, 0.36));
    }

    .footer-section {
      width: 100%;
      max-width: 1100px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: clamp(16px, 3vh, 32px);
    }

    .event-series {
      font-family: var(--font-display);
      font-weight: 400;
      font-size: clamp(20px, 3vw, 42px);
      letter-spacing: clamp(0.6px, 0.12vw, 1.6px);
      text-transform: uppercase;
      opacity: 0.9;
      color: var(--text-white);
      text-decoration: none;
      transition: opacity 0.16s ease, transform 0.16s ease, text-shadow 0.16s ease, color 0.1s linear;
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: center;
      gap: clamp(6px, 1vw, 12px);
      cursor: pointer;
      line-height: 1.2;
      text-wrap: balance;
      max-width: 100%;
      animation: text-glow-soft 3.8s ease-in-out infinite;
      padding: clamp(8px, 1.5vh, 16px) clamp(12px, 2vw, 24px);
    }

    .event-series:hover {
      opacity: 1;
      transform: translateY(-2px);
      text-shadow: 0 6px 14px rgba(0, 0, 0, 0.36);
      color: #ffd36b;
    }

    .event-series .hippi-word {
      color: inherit;
      transition: none;
    }

    .event-arrow {
      font-size: 0.95em;
      transition: transform 0.2s ease;
    }

    .event-series:hover .event-arrow {
      transform: translateX(4px);
    }

    .event-hint {
      font-family: var(--font-body);
      font-size: clamp(14px, 1.1vw, 19px);
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: none;
      color: rgba(255, 255, 255, 0.9);
      background: rgba(0, 0, 0, 0.22);
      border: 1px solid rgba(255, 255, 255, 0.35);
      border-radius: 999px;
      padding: 6px 12px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }

    .event-hint:hover {
      background: rgba(0, 0, 0, 0.35);
      border-color: rgba(255, 255, 255, 0.65);
      transform: translateY(-1px);
    }

    .instagram-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: clamp(8px, 1vw, 12px);
      background: #ffffff;
      padding: clamp(10px, 1.2vh, 14px) clamp(20px, 2.5vw, 32px);
      border-radius: 50px;
      text-decoration: none;
      color: #333;
      font-weight: 700;
      font-size: clamp(14px, 1.4vw, 20px);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      line-height: 1.4;
    }

    .instagram-btn:hover {
      transform: translateY(-2px) scale(1.03);
      box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    }

    .ig-icon-circle {
      width: clamp(28px, 2.5vw, 36px);
      height: clamp(28px, 2.5vw, 36px);
      background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285aeb 90%);
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      flex-shrink: 0;
    }

    .ig-shape {
      width: clamp(13px, 1.1vw, 16px);
      height: clamp(13px, 1.1vw, 16px);
      border: 1.5px solid white;
      border-radius: 4px;
      position: relative;
    }
    
    .ig-shape::after {
      content: '';
      position: absolute;
      width: clamp(3.5px, 0.4vw, 5px);
      height: clamp(3.5px, 0.4vw, 5px);
      border: 1.5px solid white;
      border-radius: 50%;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
    }

    .instagram-btn .bi {
      font-size: clamp(14px, 1.2vw, 18px);
    }

    @keyframes fade-up {
      from {
        opacity: 0;
        transform: translateY(12px);
      }
      to {
        opacity: 1;
        transform: none;
      }
    }

    @keyframes ambient-breathe {
      0%, 100% { opacity: 0.88; }
      50% { opacity: 1; }
    }

    @keyframes logo-float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }

    @keyframes text-glow-soft {
      0%, 100% { text-shadow: 0 0 0 rgba(255, 255, 255, 0); }
      50% { text-shadow: 0 0 16px rgba(255, 255, 255, 0.16); }
    }

    @media (prefers-reduced-motion: reduce) {
      .fade-item {
        opacity: 1;
        transform: none;
        animation: none;
      }

      .main-logo,
      .event-series,
      body::after {
        animation: none;
      }

      body {
        opacity: 1;
        transform: none;
        filter: none;
        transition: none;
      }
    }

    /* Tablet */
    @media (max-width: 1024px) {
      .container {
        gap: clamp(16px, 3vh, 40px);
        padding: clamp(16px, 2.5vh, 32px) clamp(16px, 3vw, 32px);
      }

      .main-logo {
        max-width: clamp(350px, 70vw, 600px);
      }

      .event-series {
        font-size: clamp(18px, 3.5vw, 32px);
        padding: clamp(6px, 1vh, 12px) clamp(10px, 1.5vw, 20px);
      }
    }

    /* Mobile */
    @media (max-width: 768px) {
      .container {
        gap: clamp(12px, 2.5vh, 30px);
        padding: 16px;
        justify-content: space-evenly;
      }

      .launching-text {
        font-size: clamp(18px, 5vw, 28px);
        letter-spacing: 0.8px;
      }

      .main-logo {
        max-width: clamp(280px, 80vw, 500px);
      }

      .event-series {
        font-size: clamp(19px, 5.6vw, 32px);
        letter-spacing: 0.5px;
        gap: clamp(4px, 0.8vw, 8px);
        padding: 8px 12px;
        line-height: 1.3;
      }

      .instagram-btn {
        font-size: clamp(15px, 4.6vw, 22px);
        padding: 10px 18px;
        gap: 8px;
        flex-wrap: wrap;
      }

      .footer-section {
        gap: clamp(12px, 2.5vh, 24px);
      }
    }

    /* Small Mobile */
    @media (max-width: 480px) {
      .container {
        gap: clamp(10px, 2vh, 24px);
        padding: 12px;
      }

      .launching-text {
        font-size: clamp(16px, 4.8vw, 24px);
        gap: 4px;
      }

      .main-logo {
        max-width: clamp(240px, 85vw, 400px);
      }

      .event-series {
        font-size: clamp(17px, 5.3vw, 28px);
        padding: 6px 10px;
        gap: 4px;
      }

      .instagram-btn {
        font-size: clamp(14px, 4.4vw, 19px);
        padding: 8px 14px;
      }

      .ig-icon-circle {
        width: 26px;
        height: 26px;
      }

      .ig-shape {
        width: 12px;
        height: 12px;
      }
    }

    /* Landscape Mobile */
    @media (max-width: 896px) and (max-height: 500px) and (orientation: landscape) {
      .container {
        gap: 12px;
        padding: 12px 20px;
      }

      .main-logo {
        max-width: clamp(200px, 40vw, 350px);
      }

      .launching-text {
        font-size: clamp(14px, 2.5vw, 20px);
      }

      .event-series {
        font-size: clamp(14px, 2.8vw, 24px);
        padding: 4px 8px;
      }

      .instagram-btn {
        font-size: clamp(12px, 2.2vw, 16px);
        padding: 6px 14px;
      }
    }

    /* Very Short Screens */
    @media (max-height: 600px) {
      .container {
        gap: 8px;
        padding: 12px;
      }

      .main-logo {
        max-width: min(60vw, 400px);
      }
    }
  </style>
</head>
<body>

  <div class="container">
    <div class="launching-text fade-item" style="--delay: 90ms;">
      <i class="bi bi-stars"></i> Launching Soon / March 2026
    </div>

    <div class="logo-section fade-item" style="--delay: 190ms;">
      <img src="/assets/img/AsthaporaLogo.png" alt="Asthapora 8 Sports 8 Cities" class="main-logo">
    </div>

    <div class="footer-section fade-item" style="--delay: 290ms;">
      <a href="/events" class="event-series"><i class="bi bi-calendar-event"></i> Bapora <span class="hippi-word">Hippi</span> Collaborative Event Series <i class="bi bi-arrow-right event-arrow"></i></a>
      
      <a href="https://www.instagram.com" target="_blank" class="instagram-btn">
        <div class="ig-icon-circle">
          <div class="ig-shape"></div>
        </div>
        Connect with us on Instagram! <i class="bi bi-arrow-up-right"></i>
      </a>
    </div>
  </div>

  <script>
    (function () {
      var body = document.body;
      if (!body) return;
      window.addEventListener('pageshow', function () {
        body.classList.add('page-ready');
      });
      requestAnimationFrame(function () {
        body.classList.add('page-ready');
      });
    })();
  </script>

</body>
</html>

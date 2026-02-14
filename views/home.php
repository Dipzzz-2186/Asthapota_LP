<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asthapora Home</title>
  <style>
    /* Menggunakan font yang lebih modern dan bersih sesuai gambar */
    @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700&display=swap');

    :root {
      --text-white: #ffffff;
      --text-soft: rgba(255, 255, 255, 0.85);
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
      font-family: 'Montserrat', sans-serif;
      overflow: hidden;
      position: relative;
      isolation: isolate;
      background: #03121b;
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
      background: linear-gradient(to bottom, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.15) 48%, rgba(0,0,0,0.52) 100%);
      pointer-events: none;
    }

    .container {
      height: 100vh;
      height: 100svh;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: center;
      padding: 60px 20px;
      text-align: center;
    }

    /* Bagian Atas: Launching Soon */
    .launching-text {
      font-weight: 400;
      font-size: clamp(14px, 1.2vw, 18px);
      letter-spacing: 4px;
      text-transform: uppercase;
    }

    /* Bagian Tengah: Logo & Sub-logo */
    .logo-section {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
      max-width: 1120px;
    }

    .main-logo {
      width: 100%;
      height: auto;
      max-height: 500px;
      object-fit: contain;
      margin-bottom: 6px;
    }

    /* Bagian Bawah */
    .footer-section {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 25px;
    }

    .event-series {
      font-weight: 400;
      font-size: clamp(12px, 1.1vw, 16px);
      letter-spacing: 2px;
      text-transform: uppercase;
      opacity: 0.9;
      color: var(--text-white);
      text-decoration: none;
      transition: opacity 0.2s ease;
    }

    .event-series:hover {
      opacity: 1;
    }

    /* Tombol Instagram ala Pill */
    .instagram-btn {
      display: flex;
      align-items: center;
      background: #ffffff;
      padding: 6px 20px 6px 8px;
      border-radius: 50px;
      text-decoration: none;
      color: #333;
      font-weight: 500;
      font-size: 14px;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .instagram-btn:hover {
      transform: scale(1.05);
      box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    }

    .ig-icon-circle {
      width: 30px;
      height: 30px;
      background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285aeb 90%);
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      margin-right: 12px;
    }

    /* Icon IG sederhana menggunakan CSS */
    .ig-shape {
      width: 14px;
      height: 14px;
      border: 1.5px solid white;
      border-radius: 4px;
      position: relative;
    }
    .ig-shape::after {
      content: '';
      position: absolute;
      width: 4px;
      height: 4px;
      border: 1.5px solid white;
      border-radius: 50%;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
    }

    @media (max-width: 600px) {
      .container { padding: 40px 15px; }
      .logo-section { max-width: 96vw; }
      .main-logo { width: 100%; max-height: 320px; }
      .launching-text { letter-spacing: 2px; }
    }
  </style>
</head>
<body>

  <div class="container">
    <div class="launching-text">
      Launching Soon / March 2026
    </div>

    <div class="logo-section">
      <img src="/assets/img/AsthaporaLogo.png" alt="Asthapora 8 Sports 8 Cities" class="main-logo">
    </div>

    <div class="footer-section">
      <a href="/events" class="event-series">Bapora Hippi Collaborative Event Series</a>
      
      <a href="https://instagram.com/yourprofile" target="_blank" class="instagram-btn">
        <div class="ig-icon-circle">
          <div class="ig-shape"></div>
        </div>
        Connect with us on Instagram!
      </a>
    </div>
  </div>

</body>
</html>

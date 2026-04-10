<?php
// index.php - VouchMorph Landing Page
// No session start needed here - that's for login/register
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>VouchMorph™ – Swap. Cash. Anywhere.</title>
    <meta name="description" content="VouchMorph connects every wallet to every cash point. Send from any MNO to any MNO. Cash out at any ATM. Deposit vouchers instantly. Financial freedom without boundaries.">
    <meta name="keywords" content="mobile money, cross-network payments, ATM cashout, wallet interoperability, fintech Botswana">
    <meta name="author" content="VouchMorph">
    
    <!-- Favicon placeholder - replace with your logo later -->
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    
    <!-- Google Fonts + Premium Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=general-sans@400,500,600&f[]=space-grotesk@400,500,600&display=swap" rel="stylesheet">
    
    <style>
        /* RESET & BASE - SHARP EDGES, 0 BORDER-RADIUS */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #050505;
            font-family: 'Inter', sans-serif;
            color: #FFFFFF;
            line-height: 1.5;
            overflow-x: hidden;
        }

        /* CUSTOM CURSOR (optional, sleek) */
        .cursor {
            width: 8px;
            height: 8px;
            background: #00F0FF;
            border-radius: 0%;
            position: fixed;
            pointer-events: none;
            z-index: 9999;
            mix-blend-mode: difference;
            transition: transform 0.1s ease;
        }

        .cursor-follower {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(0, 240, 255, 0.5);
            position: fixed;
            pointer-events: none;
            z-index: 9998;
            transition: 0.15s ease;
        }

        @media (max-width: 768px) {
            .cursor, .cursor-follower { display: none; }
        }

        /* TYPOGRAPHY */
        h1, h2, h3, .logo, .nav-links a {
            font-family: 'Clash Display', sans-serif;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        h1 {
            font-size: clamp(3rem, 8vw, 5.5rem);
            line-height: 1.1;
            background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        h2 {
            font-size: clamp(2rem, 5vw, 3.5rem);
            letter-spacing: -0.02em;
        }

        .section-subtitle {
            font-size: 1.125rem;
            color: #A0A0B0;
            max-width: 600px;
            margin: 1rem auto 0;
        }

        /* BUTTONS - SHARP, NO ROUNDING */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem 2rem;
            font-family: 'General Sans', sans-serif;
            font-weight: 600;
            font-size: 0.9375rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.2s ease;
            cursor: pointer;
            border-radius: 0px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
            color: #050505;
            border-color: transparent;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(0, 240, 255, 0.4);
        }

        .btn-outline {
            background: transparent;
            border-color: rgba(255, 255, 255, 0.3);
            color: #FFFFFF;
        }

        .btn-outline:hover {
            border-color: #00F0FF;
            background: rgba(0, 240, 255, 0.05);
        }

        .btn-large {
            padding: 1.125rem 2.5rem;
            font-size: 1rem;
        }

        /* NAVIGATION */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 1.5rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(20px);
            background: rgba(5, 5, 5, 0.8);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .logo {
            font-size: 1.75rem;
            font-weight: 700;
            text-decoration: none;
            background: linear-gradient(135deg, #FFFFFF, #00F0FF);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .logo sup {
            font-size: 0.7rem;
            background: none;
            -webkit-background-clip: unset;
            background-clip: unset;
            color: #00F0FF;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: #E0E0E0;
            font-size: 0.875rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: #00F0FF;
        }

        .nav-buttons {
            display: flex;
            gap: 0.75rem;
        }

        .nav-buttons .btn {
            padding: 0.5rem 1.25rem;
            font-size: 0.8125rem;
        }

        @media (max-width: 768px) {
            .nav-links { display: none; }
            .navbar { padding: 1rem 5%; }
        }

        /* HERO SECTION */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 8rem 5% 5rem;
            position: relative;
            overflow: hidden;
        }

        .hero-grid {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(rgba(0, 240, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 240, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
        }

        .hero-content {
            max-width: 700px;
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(0, 240, 255, 0.1);
            border: 1px solid rgba(0, 240, 255, 0.3);
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 1.5rem;
            border-radius: 0px;
        }

        .hero p {
            font-size: 1.25rem;
            color: #C0C0D0;
            margin: 1.5rem 0 2rem;
            max-width: 550px;
        }

        .hero-stats {
            display: flex;
            gap: 2rem;
            margin-top: 2.5rem;
        }

        .stat h3 {
            font-size: 2rem;
            font-family: 'Space Grotesk', monospace;
            color: #00F0FF;
        }

        .stat p {
            font-size: 0.75rem;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* SECTION GENERAL */
        section {
            padding: 6rem 5%;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        /* FEATURE GRID */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .feature-card {
            background: rgba(10, 10, 20, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 2rem;
            transition: all 0.3s ease;
            border-radius: 0px;
        }

        .feature-card:hover {
            border-color: rgba(0, 240, 255, 0.4);
            transform: translateY(-4px);
            background: rgba(0, 240, 255, 0.02);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1.25rem;
        }

        .feature-card h3 {
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }

        .feature-card p {
            color: #A0A0B0;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        /* PROBLEM VS SOLUTION */
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .problem-box, .solution-box {
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0px;
        }

        .problem-box {
            background: rgba(255, 30, 30, 0.05);
            border-left: 3px solid #FF3030;
        }

        .solution-box {
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00F0FF;
        }

        .comparison h3 {
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .comparison ul {
            list-style: none;
        }

        .comparison li {
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        /* STEPS */
        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .step {
            text-align: center;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 0px;
        }

        .step-number {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #00F0FF, #B000FF);
            color: #050505;
            font-family: 'Space Grotesk', monospace;
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            border-radius: 0px;
        }

        /* WAITLIST FORM */
        .waitlist {
            background: linear-gradient(135deg, rgba(0, 240, 255, 0.05), rgba(176, 0, 255, 0.05));
            border: 1px solid rgba(0, 240, 255, 0.2);
            max-width: 600px;
            margin: 0 auto;
            padding: 3rem;
            text-align: center;
        }

        .waitlist-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 2rem;
        }

        .waitlist-form input {
            padding: 1rem;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            font-family: 'Inter', sans-serif;
            border-radius: 0px;
        }

        .waitlist-form input:focus {
            outline: none;
            border-color: #00F0FF;
        }

        /* FOOTER */
        footer {
            padding: 3rem 5%;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-links {
            display: flex;
            gap: 2rem;
        }

        .footer-links a {
            color: #A0A0B0;
            text-decoration: none;
            font-size: 0.875rem;
        }

        .footer-links a:hover {
            color: #00F0FF;
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .comparison {
                grid-template-columns: 1fr;
            }
            .hero-stats {
                flex-wrap: wrap;
            }
            .waitlist {
                padding: 1.5rem;
            }
            footer {
                flex-direction: column;
                text-align: center;
            }
        }

        /* ANIMATIONS */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate {
            animation: fadeInUp 0.6s ease forwards;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
    </style>
</head>
<body>

<!-- Custom Cursor -->
<div class="cursor"></div>
<div class="cursor-follower"></div>

<!-- Navigation -->
<nav class="navbar">
    <a href="/" class="logo">VOUCHMORPH<sup>™</sup></a>
    <div class="nav-links">
        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>
        <a href="#waitlist">Early Access</a>
    </div>
    <div class="nav-buttons">
        <a href="login.php" class="btn btn-outline">Login</a>
        <a href="register.php" class="btn btn-primary">Register</a>
    </div>
</nav>

<!-- Hero Section -->
<section class="hero">
    <div class="hero-grid"></div>
    <div class="hero-content">
        <span class="hero-badge animate">🚀 Bank of Botswana Sandbox Ready</span>
        <h1 class="animate delay-1">Swap.<br>Cash.<br>Anywhere.</h1>
        <p class="animate delay-2">VouchMorph connects every wallet to every cash point. Send from any MNO to any MNO. Cash out at any ATM. Deposit vouchers instantly. No boundaries. No limits.</p>
        <div class="animate delay-3">
            <a href="#waitlist" class="btn btn-primary btn-large">Get Early Access →</a>
        </div>
        <div class="hero-stats animate delay-3">
            <div class="stat"><h3>0%</h3><p>Lock-in</p></div>
            <div class="stat"><h3>∞</h3><p>Networks</p></div>
            <div class="stat"><h3>⚡</h3><p>Instant</p></div>
        </div>
    </div>
</section>

<!-- Problem vs Solution -->
<section>
    <div class="section-header">
        <h2>The Wall Ends Here</h2>
        <p class="section-subtitle">Mobile money shouldn't be a prison. VouchMorph is the key.</p>
    </div>
    <div class="comparison">
        <div class="problem-box">
            <h3>❌ Before VouchMorph</h3>
            <ul>
                <li>🔒 M-Pesa → only M-Pesa</li>
                <li>🔒 Airtel → only Airtel</li>
                <li>🔒 ATM needs YOUR bank card</li>
                <li>🔒 Vouchers = 1 store only</li>
                <li>🔒 Cash-out? Find YOUR agent</li>
            </ul>
        </div>
        <div class="solution-box">
            <h3>✅ With VouchMorph</h3>
            <ul>
                <li>🌐 Any wallet → Any wallet</li>
                <li>🌐 Any MNO → Any MNO</li>
                <li>🌐 Any ATM accepts YOU</li>
                <li>🌐 Any voucher → Instant cash</li>
                <li>🌐 Any agent = YOUR agent</li>
            </ul>
        </div>
    </div>
</section>

<!-- Features Grid -->
<section id="features">
    <div class="section-header">
        <h2>One Identity. Infinite Possibilities.</h2>
        <p class="section-subtitle">What can you do with VouchMorph? Everything.</p>
    </div>
    <div class="feature-grid">
        <div class="feature-card"><div class="feature-icon">🏧</div><h3>Cash Out Any ATM</h3><p>No card? No problem. Use your VouchMorph ID at ANY participating ATM.</p></div>
        <div class="feature-card"><div class="feature-icon">🏪</div><h3>Agent Anywhere</h3><p>Any agent becomes your agent. Cash in, cash out, no network restrictions.</p></div>
        <div class="feature-card"><div class="feature-icon">📱➡️📱</div><h3>Wallet to Any Wallet</h3><p>MNO A to MNO B. Direct. Instant. No middleman.</p></div>
        <div class="feature-card"><div class="feature-icon">🧾</div><h3>Voucher Banking</h3><p>Load any ATM or e-wallet voucher into your VouchMorph account instantly.</p></div>
        <div class="feature-card"><div class="feature-icon">💰</div><h3>Reverse Cash-In</h3><p>Deposit physical cash at any ATM into ANY of your digital wallets.</p></div>
        <div class="feature-card"><div class="feature-icon">👨‍👩‍👧</div><h3>Family Pooling</h3><p>Link multiple wallets. One dashboard for the whole family.</p></div>
        <div class="feature-card"><div class="feature-icon">🌍</div><h3>Cross-Network Send</h3><p>Send to ANY mobile money user, regardless of their network.</p></div>
        <div class="feature-card"><div class="feature-icon">⚡</div><h3>Zero Balance? No Problem</h3><p>Access cash on trust. We've got you.</p></div>
    </div>
</section>

<!-- How It Works -->
<section id="how-it-works">
    <div class="section-header">
        <h2>Three Steps to Freedom</h2>
        <p class="section-subtitle">From locked-in to limitless. Fast.</p>
    </div>
    <div class="steps">
        <div class="step"><div class="step-number">1</div><h3>Link</h3><p>Connect your existing wallets (MNO, bank, vouchers) to your VouchMorph ID.</p></div>
        <div class="step"><div class="step-number">2</div><h3>Verify</h3><p>One biometric login. Password or fingerprint. No SIM required.</p></div>
        <div class="step"><div class="step-number">3</div><h3>Transact</h3><p>Send, withdraw, deposit, cash out. Anywhere. Any network.</p></div>
    </div>
</section>

<!-- Waitlist -->
<section id="waitlist">
    <div class="waitlist">
        <h2 style="font-size: 1.75rem;">Join the Financial Revolution</h2>
        <p style="color: #A0A0B0; margin-top: 0.5rem;">Be first. Be free. Early access launching soon.</p>
        <form class="waitlist-form" method="POST" action="waitlist-handler.php">
            <input type="text" name="fullname" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email Address" required>
            <input type="text" name="country" placeholder="Country" value="Botswana">
            <select name="primary_wallet" style="padding:1rem; background:rgba(0,0,0,0.5); border:1px solid rgba(255,255,255,0.15); color:white; border-radius:0px;">
                <option value="">Select your primary wallet</option>
                <option>M-Pesa</option>
                <option>Airtel Money</option>
                <option>Orange Money</option>
                <option>Moov Money</option>
                <option>Bank Account</option>
                <option>Other</option>
            </select>
            <button type="submit" class="btn btn-primary btn-large">Request Early Access →</button>
        </form>
        <p style="font-size: 0.75rem; margin-top: 1rem; color: #00F0FF;">First 10,000 users: lifetime 0% fees</p>
    </div>
</section>

<!-- Footer -->
<footer>
    <div class="logo" style="font-size: 1.25rem;">VOUCHMORPH<sup>™</sup></div>
    <div class="footer-links">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
        <a href="login.php">Login</a>
        <a href="register.php">Register</a>
    </div>
    <div style="color: #A0A0B0; font-size: 0.75rem;">© 2025 VouchMorph. Ready for Bank of Botswana.</div>
</footer>

<script>
    // Custom cursor
    const cursor = document.querySelector('.cursor');
    const follower = document.querySelector('.cursor-follower');
    
    document.addEventListener('mousemove', (e) => {
        cursor.style.left = e.clientX + 'px';
        cursor.style.top = e.clientY + 'px';
        follower.style.left = e.clientX - 16 + 'px';
        follower.style.top = e.clientY - 16 + 'px';
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if(target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
    
    // Animation on scroll (simple)
    const animateOnScroll = () => {
        const elements = document.querySelectorAll('.feature-card, .step, .problem-box, .solution-box');
        elements.forEach(el => {
            const rect = el.getBoundingClientRect();
            if(rect.top < window.innerHeight - 100) {
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
            }
        });
    };
    
    document.querySelectorAll('.feature-card, .step, .problem-box, .solution-box').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'all 0.4s ease';
    });
    
    window.addEventListener('scroll', animateOnScroll);
    animateOnScroll();
</script>

<!-- Logo placeholder note: 
     To add your logo, replace the .logo text with an <img> tag.
     Recommended logo size: 120x40px or 160x48px.
     Add to folder: /assets/logo.png or /images/vouchmorph-logo.png
-->
</body>
</html>

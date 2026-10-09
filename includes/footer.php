</main> <!-- Page Content End -->

<style>
    /* modern orange, blue, navy themed footer */
    .main-footer {
        background: #0A192F;
        color: #FFFFFF;
        margin-top: auto;
        padding-top: 50px;
        position: relative;
        border-top: 3px solid #FF6B00;
    }

    .footer-top {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 30px;
        padding: 0 5% 40px 5%;
        max-width: 1400px;
        margin: 0 auto;
    }

    .footer-box h3 {
        color: #FF6B00;
        font-size: 18px;
        margin-bottom: 20px;
        position: relative;
        padding-bottom: 8px;
        font-weight: 700;
    }

    .footer-box h3::after {
        content: '';
        position: absolute;
        left: 0;
        bottom: 0;
        width: 40px;
        height: 3px;
        background: #FF6B00;
        border-radius: 2px;
    }

    .footer-box p {
        color: rgba(255, 255, 255, 0.75);
        font-size: 14px;
        line-height: 1.6;
        margin-bottom: 15px;
    }

    .footer-links {
        list-style: none;
    }

    .footer-links li {
        margin-bottom: 10px;
    }

    .footer-links a {
        color: rgba(255, 255, 255, 0.75);
        text-decoration: none;
        font-size: 14px;
        transition: 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .footer-links a:hover {
        color: #FF6B00;
        transform: translateX(5px);
    }

    .coop-badge {
        background: rgba(255, 107, 0, 0.1);
        border: 1px solid rgba(255, 107, 0, 0.3);
        border-radius: 12px;
        padding: 15px;
        margin-top: 10px;
    }

    .coop-badge h4 {
        color: #FFFFFF;
        font-size: 14px;
        margin-bottom: 5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .coop-badge h4 i { color: #FF6B00; }

    .footer-bottom {
        background: rgba(0, 0, 0, 0.3);
        padding: 20px 5%;
        text-align: center;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        font-size: 13px;
        color: rgba(255, 255, 255, 0.6);
    }

    .mobile-bottom-nav {
        display: none;
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        background: #FFFFFF;
        box-shadow: 0 -4px 15px rgba(0,0,0,0.1);
        z-index: 1000;
        padding: 8px 0;
    }

    .mobile-nav-container {
        display: flex;
        justify-content: space-around;
        align-items: center;
    }

    .mobile-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-decoration: none;
        color: #64748B;
        font-size: 11px;
        font-weight: 500;
    }

    .mobile-nav-item i {
        font-size: 18px;
        margin-bottom: 3px;
    }

    .mobile-nav-item.active {
        color: #FF6B00;
    }

    @media (max-width: 768px) {
        .main-footer { padding-bottom: 70px; }
        .mobile-bottom-nav { display: block; }
    }
</style>

<footer class="main-footer">
    <div class="footer-top">
        <div class="footer-box">
            <h3>Smart Service Management System</h3>
            <p>නවීන ඩිජිටල් සේවා, කළමනාකරණ සහ පාලන කාර්යයන් සඳහා නිර්මාණය කළ සරල හා කාර්යක්ෂම පද්ධතියකි.</p>
            <div class="coop-badge">
                <h4><i class="fa-solid fa-building-flag"></i> හිමිකාරිත්වය</h4>
                <p style="font-size: 12px; margin-bottom: 0; color: rgba(255,255,255,0.85);">
                    මෙම පද්ධතිය ආයතනයේ දෛනික සේවා හා කළමනාකරණ කටයුතු පහසු කිරීම සඳහා නිර්මාණය කර ඇත.
                </p>
            </div>
        </div>

        <div class="footer-box">
            <h3>Quick Links</h3>
            <ul class="footer-links">
                <li><a href="index.php"><i class="fa-solid fa-chevron-right"></i> Dashboard</a></li>
                <li><a href="exit.php"><i class="fa-solid fa-chevron-right"></i> Service Management</a></li>
                <li><a href="vip_request.php"><i class="fa-solid fa-chevron-right"></i> Requests</a></li>
                <li><a href="admin_login.php"><i class="fa-solid fa-chevron-right"></i> Admin Panel</a></li>
            </ul>
        </div>

        <div class="footer-box">
            <h3>Contact & Location</h3>
            <p><i class="fa-solid fa-location-dot" style="color: #FF6B00;"></i>ගාල්ල‍ , ශ්‍රී ලංකාව </p>
            <p><i class="fa-solid fa-phone" style="color: #FF6B00;"></i> +94 91 000 0000</p>
            <p><i class="fa-solid fa-envelope" style="color: #FF6B00;"></i> info@example.com</p>
        </div>
    </div>

    <div class="footer-bottom">
        <p>&copy; <?php echo date('Y'); ?> Smart Service Management System. All Rights Reserved.</p>
    </div>
</footer>

<!-- Mobile Floating Bottom Navigation -->
<div class="mobile-bottom-nav">
    <div class="mobile-nav-container">
        <a href="index.php" class="mobile-nav-item active">
            <i class="fa-solid fa-gauge-high"></i>
            Home
        </a>
        <a href="exit.php" class="mobile-nav-item">
            <i class="fa-solid fa-briefcase"></i>
            Services
        </a>
        <a href="vip_request.php" class="mobile-nav-item">
            <i class="fa-solid fa-list-check"></i>
            Requests
        </a>
        <a href="admin_login.php" class="mobile-nav-item">
            <i class="fa-solid fa-user-gear"></i>
            Admin
        </a>
    </div>
</div>

<script>
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const navMenu = document.getElementById('navMenu');

    if(mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', () => {
            navMenu.classList.toggle('active');
            const icon = mobileMenuBtn.querySelector('i');
            if(navMenu.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-xmark');
            } else {
                icon.classList.remove('fa-xmark');
                icon.classList.add('fa-bars');
            }
        });
    }
</script>

</body>
</html>

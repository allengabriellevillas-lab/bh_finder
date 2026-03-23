<?php // includes/footer.php ?>
</main>

<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="<?= SITE_URL ?>/index.php" class="brand">
                    <span class="brand-icon"><i class="fas fa-home"></i></span>
                    <span class="brand-text"><?= SITE_NAME ?></span>
                </a>
                <p>Find your perfect boarding house with ease. Connecting tenants and property owners across the Philippines.</p>
            </div>
            <div class="footer-links">
                <h4>Navigation</h4>
                <a href="<?= SITE_URL ?>/index.php">Browse Listings</a>
                <a href="<?= SITE_URL ?>/register.php">Register</a>
                <a href="<?= SITE_URL ?>/login.php">Login</a>
            </div>
            <div class="footer-links">
                <h4>For Owners</h4>
                <a href="<?= SITE_URL ?>/register.php?role=owner">List Your Property</a>
                <a href="<?= SITE_URL ?>/pages/owner/dashboard.php">Manage Listings</a>
                <a href="<?= SITE_URL ?>/pages/owner/messages.php">View Messages</a>
            </div>
            <div class="footer-contact">
                <h4>Contact Us</h4>
                <p><i class="fas fa-envelope"></i> support@boardingfinder.ph</p>
                <p><i class="fas fa-phone"></i> +63 917 000 0000</p>
                <p><i class="fas fa-map-marker-alt"></i> Cebu City, Philippines</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?>. All rights reserved.</p>
        </div>
    </div>
</footer>

<script src="<?= SITE_URL ?>/js/main.js"></script>
</body>
</html>
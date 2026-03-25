<?php
require_once __DIR__ . '/includes/config.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }
$pageTitle = 'Login';
$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id,full_name,password,role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            setFlash('success', 'Welcome back, ' . $user['full_name'] . '!');
            $redirect = $_GET['redirect'] ?? (
                $user['role'] === 'admin' ? SITE_URL . '/pages/admin/dashboard.php' :
                ($user['role'] === 'owner' ? SITE_URL . '/pages/owner/dashboard.php' : SITE_URL . '/index.php')
            );
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
$showNavbar = false;
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo" aria-label="<?= sanitize(SITE_NAME) ?>">
        <img class="auth-logo-wide" src="<?= SITE_URL ?>/login-reg-logo.png" alt="<?= sanitize(SITE_NAME) ?> logo">
      </div>
      <h1 class="auth-title">Welcome Back</h1>
      <p class="auth-subtitle">Login to access your account</p>
    </div>
    <?php if ($error): ?>
      <div class="flash flash-error" style="margin-bottom:20px"><i class="fas fa-exclamation-circle"></i><?= sanitize($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="" data-validate>
      <div class="form-group">
        <label class="form-label">Email Address <span class="required">*</span></label>
        <div class="input-icon"><i class="fas fa-envelope"></i>
          <input type="email" name="email" class="form-control" placeholder="you@email.com" value="<?= sanitize($email) ?>" required autofocus>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Password <span class="required">*</span></label>
        <div class="input-icon has-trailing-action"><i class="fas fa-lock"></i>
          <input type="password" name="password" id="pw" class="form-control" placeholder="Your password" required>
          <button type="button" class="password-toggle" data-toggle-password="#pw" aria-label="Show password" aria-pressed="false" title="Show password"><i class="fas fa-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg mt-3"><i class="fas fa-sign-in-alt"></i> Login</button>
    </form>
    <div class="auth-divider">or</div>
    <div style="text-align:center;font-size:.875rem;color:var(--text-muted)">
      <strong>Demo accounts:</strong><br>
      Admin: <code>admin@demo.com</code> | Owner: <code>owner@demo.com</code> | Tenant: <code>tenant@demo.com</code><br>
      Password: <code>password</code>
    </div>
    <div class="auth-footer">Don't have an account? <a href="<?= SITE_URL ?>/register.php">Sign up for free</a></div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>







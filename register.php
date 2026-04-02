<?php
require_once __DIR__ . '/includes/config.php';
if (isLoggedIn()) { header('Location: ' . SITE_URL . '/index.php'); exit; }
$pageTitle = 'Create Account';
$errors = [];
$formData = ['full_name'=>'','email'=>'','phone'=>'','role'=>$_GET['role'] ?? 'tenant'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email'     => trim($_POST['email'] ?? ''),
        'phone'     => trim($_POST['phone'] ?? ''),
        'role'      => in_array($_POST['role'] ?? '', ['tenant','owner']) ? $_POST['role'] : 'tenant',
    ];
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if (empty($formData['full_name'])) $errors['full_name'] = 'Full name is required.';
    if (empty($formData['email'])) $errors['email'] = 'Email is required.';
    elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email.';
    if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirmPassword) $errors['confirm_password'] = 'Passwords do not match.';
    if (empty($errors)) {
        $db = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$formData['email']]);
        if ($check->fetch()) {
            $errors['email'] = 'This email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (full_name,email,password,role,phone) VALUES(?,?,?,?,?)");
            $stmt->execute([$formData['full_name'],$formData['email'],$hash,$formData['role'],$formData['phone']]);
            $newUserId = intval($db->lastInsertId());
            $_SESSION['user_id']   = $newUserId;
            $_SESSION['user_role'] = $formData['role'];
            setFlash('success', 'Welcome to ' . SITE_NAME . '!');
            $dest = ($formData['role'] === 'owner') ? (SITE_URL . '/pages/owner/verification.php') : (SITE_URL . '/index.php');
            header('Location: ' . $dest);
            exit;
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
      <h1 class="auth-title">Create Account</h1>
      <p class="auth-subtitle">Join thousands of users finding their ideal home</p>
    </div>
    <div class="form-group">
      <label class="form-label">I am a...</label>
      <div class="role-selector" id="roleSelector">
        <label class="role-option <?= $formData['role']==='tenant'?'selected':'' ?>" onclick="setRole('tenant')">
          <input type="radio" name="role_display" value="tenant" <?= $formData['role']==='tenant'?'checked':'' ?>>
          <i class="fas fa-user-graduate"></i><strong>Tenant</strong><span>Looking for a place</span>
        </label>
        <label class="role-option <?= $formData['role']==='owner'?'selected':'' ?>" onclick="setRole('owner')">
          <input type="radio" name="role_display" value="owner" <?= $formData['role']==='owner'?'checked':'' ?>>
          <i class="fas fa-building"></i><strong>Property Owner</strong><span>Listing a property</span>
        </label>
      </div>
    </div>
    <form method="POST" action="" data-validate>
      <input type="hidden" name="role" id="roleInput" value="<?= sanitize($formData['role']) ?>">
      <div class="form-group">
        <label class="form-label">Full Name <span class="required">*</span></label>
        <div class="input-icon"><i class="fas fa-user"></i>
          <input type="text" name="full_name" class="form-control <?= isset($errors['full_name'])?'error':'' ?>"
                 placeholder="Juan Dela Cruz" value="<?= sanitize($formData['full_name']) ?>" required>
        </div>
        <?php if (isset($errors['full_name'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['full_name'] ?></p><?php endif; ?>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Email <span class="required">*</span></label>
          <div class="input-icon"><i class="fas fa-envelope"></i>
            <input type="email" name="email" class="form-control <?= isset($errors['email'])?'error':'' ?>"
                   placeholder="you@email.com" value="<?= sanitize($formData['email']) ?>" required>
          </div>
          <?php if (isset($errors['email'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['email'] ?></p><?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label">Phone</label>
          <div class="input-icon"><i class="fas fa-phone"></i>
            <input type="tel" name="phone" class="form-control" placeholder="09171234567" value="<?= sanitize($formData['phone']) ?>">
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Password <span class="required">*</span></label>
          <div class="input-icon has-trailing-action"><i class="fas fa-lock"></i>
            <input type="password" name="password" id="pw" class="form-control <?= isset($errors['password'])?'error':'' ?>"
                   placeholder="Min. 8 characters" required>
            <button type="button" class="password-toggle" data-toggle-password="#pw" aria-label="Show password" aria-pressed="false" title="Show password"><i class="fas fa-eye"></i></button>
          </div>
          <?php if (isset($errors['password'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['password'] ?></p><?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm Password <span class="required">*</span></label>
          <div class="input-icon"><i class="fas fa-lock"></i>
            <input type="password" name="confirm_password" class="form-control <?= isset($errors['confirm_password'])?'error':'' ?>"
                   placeholder="Repeat password" required>
          </div>
          <?php if (isset($errors['confirm_password'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['confirm_password'] ?></p><?php endif; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg mt-3"><i class="fas fa-user-plus"></i> Create Account</button>
    </form>
    <div class="auth-footer">Already have an account? <a href="<?= SITE_URL ?>/login.php">Login here</a></div>
  </div>
</div>
<script>
function setRole(val) {
  document.getElementById('roleInput').value = val;
  document.querySelectorAll('.role-option').forEach(o => o.classList.remove('selected'));
  event.currentTarget.classList.add('selected');
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>







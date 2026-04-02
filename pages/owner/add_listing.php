<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();
requireActiveOwnerSubscriptionForListingCreate();


$pageTitle = 'Add New Listing';
$db = getDB();
$currentUser = getCurrentUser() ?: [];
$activeSub = getActiveOwnerSubscription(intval($_SESSION['user_id'] ?? 0));

function enumValuesFromColumns(array $columns, string $field): array {
    foreach ($columns as $col) {
        if (($col['Field'] ?? null) !== $field) continue;
        $type = (string)($col['Type'] ?? '');
        if (!str_starts_with($type, 'enum(')) return [];
        if (!preg_match_all("/'([^']+)'/", $type, $m)) return [];
        return $m[1] ?? [];
    }
    return [];
}

$errors = [];

$bhColumns = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhColumns);
$bhFieldSet = array_fill_keys(array_filter($bhFields), true);
$hasCol = fn(string $c): bool => isset($bhFieldSet[$c]);

$hasLocation = $hasCol('location');
$hasAddress = $hasCol('address');
$hasStatus = $hasCol('status');
$hasContactPhone = $hasCol('contact_phone');
$hasContactEmail = $hasCol('contact_email');
$hasApprovalStatusCol = $hasCol('approval_status');
$hasTotalRooms = $hasCol('total_rooms');
$hasAvailableRooms = $hasCol('available_rooms');
$hasIsActiveCol = $hasCol('is_active');
$hasExpiresAtCol = $hasCol('expires_at');
$hasSubscriptionIdCol = $hasCol('subscription_id');

$statusValues = enumValuesFromColumns($bhColumns, 'status');
if (empty($statusValues)) $statusValues = ['active','inactive'];
$defaultStatus = in_array('active', $statusValues, true) ? 'active' : ($statusValues[0] ?? 'active');

$formData = [
    'name'               => '',
    'location'           => '',
    'city'               => '',
    'description'        => '',
    'rules'              => '',
    'contact_phone'      => $currentUser['phone'] ?? '',
    'contact_email'      => $currentUser['email'] ?? '',
];

$allAmenities = $db->query("SELECT * FROM amenities ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'name'               => trim($_POST['name'] ?? ''),
        'location'           => trim($_POST['location'] ?? ''),
        'city'               => trim($_POST['city'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'rules'              => trim($_POST['rules'] ?? ''),
        'contact_phone'      => trim($_POST['contact_phone'] ?? ''),
        'contact_email'      => trim($_POST['contact_email'] ?? ''),
    ];
    $selectedAmenities = $_POST['amenities'] ?? [];

    if (empty($formData['name']))     $errors['name']      = 'Property name is required.';
    if (empty($formData['location'])) $errors['location']  = 'Location is required.';
    if (empty($formData['city']))     $errors['city']      = 'City is required.';

    if (empty($errors)) {
        $cols = ['owner_id', 'name'];
        $vals = [$_SESSION['user_id'], $formData['name']];

        // Some schemas use `location`, some use `address`.
        if ($hasLocation) { $cols[] = 'location'; $vals[] = $formData['location']; }
        if ($hasAddress)  { $cols[] = 'address';  $vals[] = $formData['location']; }

        $cols[] = 'city'; $vals[] = $formData['city'];
        $cols[] = 'description'; $vals[] = $formData['description'];
        $cols[] = 'rules'; $vals[] = $formData['rules'];

        if ($hasStatus) { $cols[] = 'status'; $vals[] = $defaultStatus; }
        if ($hasContactPhone) { $cols[] = 'contact_phone'; $vals[] = $formData['contact_phone']; }
        if ($hasContactEmail) { $cols[] = 'contact_email'; $vals[] = $formData['contact_email']; }
        if ($hasApprovalStatusCol) { $cols[] = 'approval_status'; $vals[] = 'approved'; }

        // If room counts are present, initialize to 0 so new listings don't show as 1/1 by default.
        if ($hasTotalRooms) { $cols[] = 'total_rooms'; $vals[] = 0; }
        if ($hasAvailableRooms) { $cols[] = 'available_rooms'; $vals[] = 0; }

        if ($hasIsActiveCol) { $cols[] = 'is_active'; $vals[] = 1; }
        if ($hasExpiresAtCol && is_array($activeSub) && !empty($activeSub['end_date'])) { $cols[] = 'expires_at'; $vals[] = (string)($activeSub['end_date']) . ' 23:59:59'; }
        if ($hasSubscriptionIdCol && is_array($activeSub)) { $cols[] = 'subscription_id'; $vals[] = (intval($activeSub['id'] ?? 0) > 0) ? intval($activeSub['id']) : null; }

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO boarding_houses (" . implode(',', $cols) . ") VALUES ($placeholders)";
        $ins = $db->prepare($sql);
        $ins->execute($vals);
        $bhId = $db->lastInsertId();

        // Save amenities
        if (!empty($selectedAmenities)) {
            $insAm = $db->prepare("INSERT IGNORE INTO boarding_house_amenities (boarding_house_id,amenity_id) VALUES(?,?)");
            foreach ($selectedAmenities as $amId) {
                $insAm->execute([$bhId, intval($amId)]);
            }
        }

        // Upload images
        if (!empty($_FILES['images']['name'][0])) {
            $isPrimary = true;
            for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'type'     => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                $filename = uploadImage($file, 'bh' . $bhId);
                if ($filename) {
                    $insImg = $db->prepare("INSERT INTO boarding_house_images (boarding_house_id,image_path,is_cover) VALUES(?,?,?)");
                    $insImg->execute([$bhId, $filename, $isPrimary ? 1 : 0]);
                    $isPrimary = false;
                }
            }
        }

        setFlash('success', 'Listing "' . $formData['name'] . '" added successfully!');
        header('Location: ' . SITE_URL . '/pages/owner/dashboard.php');
        exit;
    }
}

$showNavbar = false;
require_once __DIR__ . '/../../includes/header.php';
?>

<?php $me = getCurrentUser(); ?>
<div class="dash-shell">
<?php $activeNav = 'dashboard'; include __DIR__ . '/_partials/sidebar.php'; ?>

  <div class="dash-main">
<?php include __DIR__ . '/_partials/topbar.php'; ?>

    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Add New Listing</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Add Listing</span>
          </div>
        </div>
      </div>

      <main>
        <div class="listing-form-container">
  <form method="POST" action="" enctype="multipart/form-data" data-validate>

    <!-- Basic Info -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-info-circle" style="color:var(--primary)"></i> Basic Information</h2></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Property Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control <?= isset($errors['name'])?'error':'' ?>"
                 placeholder="e.g. Casa Verde Boarding House" value="<?= sanitize($formData['name']) ?>" required>
          <?php if (isset($errors['name'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['name'] ?></p><?php endif; ?>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Street Address / Location <span class="required">*</span></label>
            <input type="text" name="location" class="form-control <?= isset($errors['location'])?'error':'' ?>"
                   placeholder="e.g. 123 OsmeÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¾ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â±a Blvd" value="<?= sanitize($formData['location']) ?>" required>
            <?php if (isset($errors['location'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['location'] ?></p><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">City <span class="required">*</span></label>
            <input type="text" name="city" class="form-control <?= isset($errors['city'])?'error':'' ?>"
                   placeholder="e.g. Cebu City" value="<?= sanitize($formData['city']) ?>" required>
            <?php if (isset($errors['city'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['city'] ?></p><?php endif; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" placeholder="Describe your property, nearby landmarks, target tenants..."><?= sanitize($formData['description']) ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">House Rules</label>
          <textarea name="rules" class="form-control" placeholder="One rule per line, e.g. No pets allowed&#10;Curfew at 10PM"><?= sanitize($formData['rules']) ?></textarea>
          <p class="form-hint">Enter each rule on a new line.</p>
        </div>
      </div>
    </div>

    <!-- Rooms & Pricing -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-tags" style="color:var(--primary)"></i> Rooms & Pricing</h2></div>
      <div class="card-body">
        <p class="text-muted text-sm" style="margin:0">Pricing is based on your room prices. Add rooms and set prices in <a href="rooms.php">Room Management</a>.</p>
      </div>
    </div>

    <!-- Amenities -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-star" style="color:var(--primary)"></i> Amenities</h2></div>
      <div class="card-body">
        <div class="checkbox-group">
          <?php foreach ($allAmenities as $am): ?>
          <label class="checkbox-item">
            <input type="checkbox" name="amenities[]" value="<?= intval($am['id']) ?>">
            <?= sanitize($am['name']) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Contact -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-address-card" style="color:var(--primary)"></i> Contact Information</h2></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Contact Phone</label>
            <div class="input-icon"><i class="fas fa-phone"></i>
              <input type="tel" name="contact_phone" class="form-control" placeholder="09171234567" value="<?= sanitize($formData['contact_phone']) ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Contact Email</label>
            <div class="input-icon"><i class="fas fa-envelope"></i>
              <input type="email" name="contact_email" class="form-control" placeholder="owner@email.com" value="<?= sanitize($formData['contact_email']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Photos -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-images" style="color:var(--primary)"></i> Property Photos</h2></div>
      <div class="card-body">
        <div class="file-upload">
          <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
          <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
          <p class="file-upload-text"><strong>Click to upload</strong> or drag & drop</p>
          <p class="file-upload-text" style="font-size:.8rem;margin-top:4px">JPEG, PNG, WebP ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· Max 5MB per image ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· First image becomes the cover</p>
        </div>
        <div class="file-preview" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
          <style>
            .preview-item { position:relative; width:100px; }
            .preview-item img { width:100px; height:80px; object-fit:cover; border-radius:var(--radius-sm); }
            .preview-remove { position:absolute; top:-6px; right:-6px; background:var(--error); color:#fff; border:none; border-radius:50%; width:20px; height:20px; cursor:pointer; font-size:.8rem; line-height:1; display:flex; align-items:center; justify-content:center; }
            .preview-name { display:block; font-size:.7rem; color:var(--text-light); text-overflow:ellipsis; overflow:hidden; white-space:nowrap; }
          </style>
        </div>
      </div>
    </div>

    <!-- Submit -->
    <div style="display:flex;gap:12px;justify-content:flex-end">
      <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-plus-circle"></i> Publish Listing</button>
    </div>
  </form>
</div>
      </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>















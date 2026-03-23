<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();

$pageTitle = 'Add New Listing';
$db = getDB();
$currentUser = getCurrentUser() ?: [];

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

$accommodationTypeValues = enumValuesFromColumns($bhColumns, 'accommodation_type');
if (empty($accommodationTypeValues)) $accommodationTypeValues = ['solo_room','shared_room','studio','apartment'];
$defaultType = $accommodationTypeValues[0] ?? 'solo_room';

$statusValues = enumValuesFromColumns($bhColumns, 'status');
if (empty($statusValues)) $statusValues = ['active','inactive'];
$defaultStatus = in_array('active', $statusValues, true) ? 'active' : ($statusValues[0] ?? 'active');

$typeLabels = [
    'solo_room'   => 'Solo Room',
    'shared_room' => 'Shared Room',
    'studio'      => 'Studio',
    'apartment'   => 'Apartment',
    'bedspace'    => 'Bedspace',
    'entire_unit' => 'Entire Unit',
];
$accommodationTypeOptions = [];
foreach ($accommodationTypeValues as $v) {
    $accommodationTypeOptions[$v] = $typeLabels[$v] ?? ucwords(str_replace('_', ' ', $v));
}

$formData = [
    'name'               => '',
    'location'           => '',
    'city'               => '',
    'description'        => '',
    'rules'              => '',
    'price_min'          => '',
    'price_max'          => '',
    'accommodation_type' => $defaultType,
    'total_rooms'        => 1,
    'available_rooms'    => 1,
    'contact_phone'      => $currentUser['phone'] ?? '',
    'contact_email'      => $currentUser['email'] ?? '',
];

$allAmenities = $db->query("SELECT * FROM amenities ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedType = trim($_POST['accommodation_type'] ?? '');
    $formData = [
        'name'               => trim($_POST['name'] ?? ''),
        'location'           => trim($_POST['location'] ?? ''),
        'city'               => trim($_POST['city'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'rules'              => trim($_POST['rules'] ?? ''),
        'price_min'          => floatval($_POST['price_min'] ?? 0),
        'price_max'          => floatval($_POST['price_max'] ?? 0),
        'accommodation_type' => in_array($submittedType, $accommodationTypeValues, true) ? $submittedType : $defaultType,
        'total_rooms'        => intval($_POST['total_rooms'] ?? 1),
        'available_rooms'    => intval($_POST['available_rooms'] ?? 1),
        'contact_phone'      => trim($_POST['contact_phone'] ?? ''),
        'contact_email'      => trim($_POST['contact_email'] ?? ''),
    ];
    $selectedAmenities = $_POST['amenities'] ?? [];

    if (empty($formData['name']))     $errors['name']      = 'Property name is required.';
    if (empty($formData['location'])) $errors['location']  = 'Location is required.';
    if (empty($formData['city']))     $errors['city']      = 'City is required.';
    if ($formData['price_min'] <= 0)  $errors['price_min'] = 'Minimum price must be greater than 0.';
    if ($formData['available_rooms'] > $formData['total_rooms']) $errors['available_rooms'] = 'Available rooms cannot exceed total rooms.';

    if (empty($errors)) {
        $cols = ['owner_id', 'name'];
        $vals = [$_SESSION['user_id'], $formData['name']];

        // Some schemas use `location`, some use `address`.
        if ($hasLocation) { $cols[] = 'location'; $vals[] = $formData['location']; }
        if ($hasAddress)  { $cols[] = 'address';  $vals[] = $formData['location']; }

        $cols[] = 'city'; $vals[] = $formData['city'];
        $cols[] = 'description'; $vals[] = $formData['description'];
        $cols[] = 'rules'; $vals[] = $formData['rules'];
        $cols[] = 'price_min'; $vals[] = $formData['price_min'];
        $cols[] = 'price_max'; $vals[] = $formData['price_max'] ?: null;
        $cols[] = 'accommodation_type'; $vals[] = $formData['accommodation_type'];
        $cols[] = 'total_rooms'; $vals[] = $formData['total_rooms'];
        $cols[] = 'available_rooms'; $vals[] = $formData['available_rooms'];

        if ($hasStatus) { $cols[] = 'status'; $vals[] = $defaultStatus; }
        if ($hasContactPhone) { $cols[] = 'contact_phone'; $vals[] = $formData['contact_phone']; }
        if ($hasContactEmail) { $cols[] = 'contact_email'; $vals[] = $formData['contact_email']; }

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

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <div class="container">
    <h1 class="page-title">Add New Listing</h1>
    <nav class="page-breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
      <span>Add Listing</span>
    </nav>
  </div>
</div>

<div class="container" style="max-width:860px;padding-bottom:60px">
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
                   placeholder="e.g. 123 OsmeÃ±a Blvd" value="<?= sanitize($formData['location']) ?>" required>
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

    <!-- Pricing & Rooms -->
    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700"><i class="fas fa-peso-sign" style="color:var(--primary)"></i> Pricing & Rooms</h2></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Accommodation Type <span class="required">*</span></label>
          <div class="radio-group">
            <?php foreach ($accommodationTypeOptions as $val => $label): ?>
            <label class="radio-item">
              <input type="radio" name="accommodation_type" value="<?= $val ?>" <?= $formData['accommodation_type']===$val?'checked':'' ?>>
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Minimum Price (â‚±/month) <span class="required">*</span></label>
            <div class="input-icon"><i class="fas fa-peso-sign"></i>
              <input type="number" name="price_min" class="form-control <?= isset($errors['price_min'])?'error':'' ?>"
                     placeholder="2500" value="<?= $formData['price_min']?:'' ?>" min="1" step="0.01" required id="minPrice">
            </div>
            <?php if (isset($errors['price_min'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['price_min'] ?></p><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">Maximum Price (â‚±/month)</label>
            <div class="input-icon"><i class="fas fa-peso-sign"></i>
              <input type="number" name="price_max" class="form-control"
                     placeholder="Optional" value="<?= $formData['price_max']?:'' ?>" min="0" step="0.01" id="maxPrice">
            </div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Total Rooms</label>
            <input type="number" name="total_rooms" class="form-control" value="<?= intval($formData['total_rooms']) ?>" min="1" required>
          </div>
          <div class="form-group">
            <label class="form-label">Available Rooms</label>
            <input type="number" name="available_rooms" class="form-control <?= isset($errors['available_rooms'])?'error':'' ?>" value="<?= intval($formData['available_rooms']) ?>" min="0">
            <?php if (isset($errors['available_rooms'])): ?><p class="form-error"><i class="fas fa-exclamation-circle"></i><?= $errors['available_rooms'] ?></p><?php endif; ?>
          </div>
        </div>
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
          <p class="file-upload-text" style="font-size:.8rem;margin-top:4px">JPEG, PNG, WebP Â· Max 5MB per image Â· First image becomes the cover</p>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>



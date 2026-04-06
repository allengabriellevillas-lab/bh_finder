<?php
require_once __DIR__ . '/../../includes/config.php';
requireOwner();
requireVerifiedOwner();
requireOwnerActiveAccess();

$id = intval($_GET['id'] ?? 0);
$db = getDB();

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

$bhColumns = $db->query("SHOW COLUMNS FROM boarding_houses")->fetchAll() ?: [];
$bhFields = array_map(fn($r) => (string)($r['Field'] ?? ''), $bhColumns);
$bhFieldSet = array_fill_keys(array_filter($bhFields), true);
$hasCol = fn(string $c): bool => isset($bhFieldSet[$c]);

$hasLocation = $hasCol('location');
$hasAddress = $hasCol('address');
$hasStatusCol = $hasCol('status');
$hasContactPhone = $hasCol('contact_phone');
$hasContactEmail = $hasCol('contact_email');

$typeValues = enumValuesFromColumns($bhColumns, 'accommodation_type');
if (empty($typeValues)) $typeValues = ['solo_room','shared_room','studio','apartment'];
$defaultType = $typeValues[0] ?? 'solo_room';

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
foreach ($typeValues as $v) {
    $accommodationTypeOptions[$v] = $typeLabels[$v] ?? ucwords(str_replace('_', ' ', $v));
}

$statusLabels = [
    'active'   => 'Active',
    'available'=> 'Active',
    'inactive' => 'Inactive',
    'full'     => 'Full',
    'pending'  => 'Pending',
];
$statusOptions = [];
foreach ($statusValues as $v) {
    $statusOptions[$v] = $statusLabels[$v] ?? ucwords(str_replace('_', ' ', $v));
}

// Verify ownership
$stmt = $db->prepare("SELECT * FROM boarding_houses WHERE id=? AND owner_id=?");
$stmt->execute([$id, $_SESSION['user_id']]);
$bh = $stmt->fetch();
if (!$bh) { setFlash('error','Listing not found.'); header('Location: dashboard.php'); exit; }

$pageTitle = 'Edit: ' . ($bh['name'] ?? 'Listing');
$errors = [];
$allAmenities = $db->query("SELECT * FROM amenities ORDER BY name")->fetchAll();

$selAmStmt = $db->prepare("SELECT amenity_id FROM boarding_house_amenities WHERE boarding_house_id=?");
$selAmStmt->execute([$id]);
$selectedAmenityIds = $selAmStmt->fetchAll(PDO::FETCH_COLUMN);

$existingImagesStmt = $db->prepare("SELECT * FROM boarding_house_images WHERE boarding_house_id=? ORDER BY is_cover DESC, uploaded_at DESC");
$existingImagesStmt->execute([$id]);
$existingImages = $existingImagesStmt->fetchAll() ?: [];
$coverImage = $existingImages[0] ?? null;
$galleryImages = array_values(array_filter($existingImages, fn($img) => intval($img['is_cover'] ?? 0) !== 1));

$formData = $bh;
$formData['location'] = $bh['location'] ?? ($bh['address'] ?? '');
$formData['accommodation_type'] = $bh['accommodation_type'] ?? $defaultType;
$formData['status'] = $bh['status'] ?? $defaultStatus;
$formData['contact_phone'] = $bh['contact_phone'] ?? '';
$formData['contact_email'] = $bh['contact_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedType = trim($_POST['accommodation_type'] ?? '');
    $submittedStatus = trim($_POST['status'] ?? '');

    $formData = array_merge($bh, [
        'name'               => trim($_POST['name'] ?? ''),
        'location'           => trim($_POST['location'] ?? ''),
        'city'               => trim($_POST['city'] ?? ''),
        'description'        => trim($_POST['description'] ?? ''),
        'rules'              => trim($_POST['rules'] ?? ''),
        'accommodation_type' => in_array($submittedType, $typeValues, true) ? $submittedType : $defaultType,
        'status'             => in_array($submittedStatus, $statusValues, true) ? $submittedStatus : $defaultStatus,
        'contact_phone'      => trim($_POST['contact_phone'] ?? ''),
        'contact_email'      => trim($_POST['contact_email'] ?? ''),
    ]);
    $selectedAmenityIds = $_POST['amenities'] ?? [];

    if (empty($formData['name']))     $errors['name']      = 'Property name is required.';
    if (empty($formData['location'])) $errors['location']  = 'Location is required.';
    if (empty($formData['city']))     $errors['city']      = 'City is required.';

    if (empty($errors)) {
        $set = [];
        $vals = [];

        $set[] = 'name=?'; $vals[] = $formData['name'];

        if ($hasLocation) { $set[] = 'location=?'; $vals[] = $formData['location']; }
        if ($hasAddress)  { $set[] = 'address=?';  $vals[] = $formData['location']; }

        $set[] = 'city=?'; $vals[] = $formData['city'];
        $set[] = 'description=?'; $vals[] = $formData['description'];
        $set[] = 'rules=?'; $vals[] = $formData['rules'];
        $set[] = 'accommodation_type=?'; $vals[] = $formData['accommodation_type'];

        if ($hasStatusCol) { $set[] = 'status=?'; $vals[] = $formData['status']; }
        if ($hasContactPhone) { $set[] = 'contact_phone=?'; $vals[] = $formData['contact_phone']; }
        if ($hasContactEmail) { $set[] = 'contact_email=?'; $vals[] = $formData['contact_email']; }

        $vals[] = $id;
        $vals[] = $_SESSION['user_id'];

        $sql = 'UPDATE boarding_houses SET ' . implode(',', $set) . ' WHERE id=? AND owner_id=?';
        $upd = $db->prepare($sql);
        $upd->execute($vals);

        // Update amenities
        $db->prepare("DELETE FROM boarding_house_amenities WHERE boarding_house_id=?")->execute([$id]);
        if (!empty($selectedAmenityIds)) {
            $insAm = $db->prepare("INSERT IGNORE INTO boarding_house_amenities (boarding_house_id,amenity_id) VALUES(?,?)");
            foreach ($selectedAmenityIds as $amId) $insAm->execute([$id, intval($amId)]);
        }

        // Replace/remove current cover image first so owners can explicitly swap the main photo.
        $replaceCoverUploaded = isset($_FILES['replace_cover_image'])
            && intval($_FILES['replace_cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && trim((string)($_FILES['replace_cover_image']['name'] ?? '')) !== '';
        $removeCurrentCover = !empty($_POST['remove_cover_image']);
        if ($replaceCoverUploaded || $removeCurrentCover) {
            $currentCoverStmt = $db->prepare("SELECT id, image_path FROM boarding_house_images WHERE boarding_house_id = ? ORDER BY is_cover DESC, id ASC LIMIT 1");
            $currentCoverStmt->execute([$id]);
            $currentCover = $currentCoverStmt->fetch() ?: null;

            if ($replaceCoverUploaded) {
                $newCover = uploadImage($_FILES['replace_cover_image'], 'bh' . $id . '_cover');
                if ($newCover === false) {
                    $errors['replace_cover_image'] = 'Failed to upload cover image. Please use JPG, PNG, or WebP (max 5MB).';
                }

                // Replacement takes priority over the remove checkbox.
                $removeCurrentCover = false;
                $db->prepare("UPDATE boarding_house_images SET is_cover = 0 WHERE boarding_house_id = ?")->execute([$id]);

                if ($currentCover) {
                    $oldCoverPath = (string)($currentCover['image_path'] ?? '');
                    $db->prepare("UPDATE boarding_house_images SET image_path = ?, is_cover = 1 WHERE id = ?")
                       ->execute([$newCover, intval($currentCover['id'])]);
                    if ($oldCoverPath !== '' && $oldCoverPath !== $newCover) {
                        deleteUploadedFile($oldCoverPath);
                    }
                } else {
                    $db->prepare("INSERT INTO boarding_house_images (boarding_house_id, image_path, is_cover) VALUES (?,?,1)")
                       ->execute([$id, $newCover]);
                }
            } elseif ($removeCurrentCover && $currentCover) {
                deleteUploadedFile((string)($currentCover['image_path'] ?? ''));
                $db->prepare("DELETE FROM boarding_house_images WHERE id = ?")->execute([intval($currentCover['id'])]);
            }

            $existingImagesStmt->execute([$id]);
            $existingImages = $existingImagesStmt->fetchAll() ?: [];
            $coverImage = $existingImages[0] ?? null;
            $galleryImages = array_values(array_filter($existingImages, fn($img) => intval($img['is_cover'] ?? 0) !== 1));
        }

        // Upload new images
        if (!empty($_FILES['images']['name'][0])) {
            $hasPrimary = !empty($existingImages);
            for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                $file = ['name'=>$_FILES['images']['name'][$i],'type'=>$_FILES['images']['type'][$i],'tmp_name'=>$_FILES['images']['tmp_name'][$i],'error'=>$_FILES['images']['error'][$i],'size'=>$_FILES['images']['size'][$i]];
                $fn = uploadImage($file, 'bh'.$id);
                if ($fn) {
                    $insImg = $db->prepare("INSERT INTO boarding_house_images (boarding_house_id,image_path,is_cover) VALUES(?,?,?)");
                    $insImg->execute([$id,$fn,$hasPrimary?0:1]);
                    $hasPrimary = true;
                }
            }
        }

        // Delete selected images
        if (!empty($_POST['delete_images'])) {
            $deleteIds = array_values(array_unique(array_map('intval', (array)$_POST['delete_images'])));
            foreach ($deleteIds as $imgId) {
                $imgStmt = $db->prepare("SELECT image_path FROM boarding_house_images WHERE id=? AND boarding_house_id=?");
                $imgStmt->execute([$imgId,$id]);
                $img = $imgStmt->fetch();
                if ($img) {
                    deleteUploadedFile($img['image_path']);
                    $db->prepare("DELETE FROM boarding_house_images WHERE id=?")->execute([$imgId]);
                }
            }

            // Ensure there's still exactly one primary image (if any images remain).
            $remainingStmt = $db->prepare("SELECT id, is_cover FROM boarding_house_images WHERE boarding_house_id=? ORDER BY is_cover DESC, id ASC");
            $remainingStmt->execute([$id]);
            $remaining = $remainingStmt->fetchAll();
            if (!empty($remaining)) {
                $hasPrimary = false;
                foreach ($remaining as $row) {
                    if (intval($row['is_cover']) === 1) { $hasPrimary = true; break; }
                }
                if (!$hasPrimary) {
                    $db->prepare("UPDATE boarding_house_images SET is_cover=1 WHERE id=?")->execute([intval($remaining[0]['id'])]);
                }
            }
        }

        $existingImagesStmt->execute([$id]);
        $existingImages = $existingImagesStmt->fetchAll() ?: [];
        $coverImage = $existingImages[0] ?? null;
        $galleryImages = array_values(array_filter($existingImages, fn($img) => intval($img['is_cover'] ?? 0) !== 1));

        setFlash('success', 'Listing updated successfully!');
        header('Location: dashboard.php');
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
          <h1 class="dash-title">Edit Listing</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Dashboard</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Edit: <?= sanitize($bh['name']) ?></span>
          </div>
        </div>
      </div>

      <main>
        <div class="listing-form-container">
  <form method="POST" action="" enctype="multipart/form-data" data-validate>

    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700">Basic Information</h2></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Property Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control <?= isset($errors['name'])?'error':'' ?>" value="<?= sanitize($formData['name']) ?>" required>
          <?php if (isset($errors['name'])): ?><p class="form-error"><?= $errors['name'] ?></p><?php endif; ?>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Location <span class="required">*</span></label>
            <input type="text" name="location" class="form-control" value="<?= sanitize($formData['location']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">City <span class="required">*</span></label>
            <input type="text" name="city" class="form-control" value="<?= sanitize($formData['city']) ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control"><?= sanitize($formData['description']) ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">House Rules</label>
          <textarea name="rules" class="form-control"><?= sanitize($formData['rules']) ?></textarea>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700">Type & Status</h2></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">Type</label>
          <div class="radio-group">
            <?php foreach (['solo_room'=>'Solo Room','shared_room'=>'Shared Room','studio'=>'Studio','apartment'=>'Apartment'] as $v=>$l): ?>
            <label class="radio-item"><input type="radio" name="accommodation_type" value="<?= $v ?>" <?= $formData['accommodation_type']===$v?'checked':'' ?>><?= $l ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control">
  <?php foreach ($statusOptions as $v => $label): ?>
    <option value="<?= sanitize($v) ?>" <?= (string)($formData['status'] ?? '') === (string)$v ? 'selected' : '' ?>><?= sanitize($label) ?></option>
  <?php endforeach; ?>
</select>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700">Amenities</h2></div>
      <div class="card-body">
        <div class="checkbox-group">
          <?php foreach ($allAmenities as $am): ?>
          <label class="checkbox-item">
            <input type="checkbox" name="amenities[]" value="<?= $am['id'] ?>" <?= in_array($am['id'],$selectedAmenityIds)?'checked':'' ?>>
            <?= sanitize($am['name']) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700">Contact Information</h2></div>
      <div class="card-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Contact Phone</label>
            <input type="tel" name="contact_phone" class="form-control" value="<?= sanitize($formData['contact_phone']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Contact Email</label>
            <input type="email" name="contact_email" class="form-control" value="<?= sanitize($formData['contact_email']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header"><h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:700">Photos</h2></div>
      <div class="card-body">
        <?php if ($coverImage): ?>
        <div style="margin-bottom:20px;padding:18px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg)">
          <div class="form-label" style="margin-bottom:12px">Current Cover Photo</div>
          <div style="display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap">
            <div style="position:relative;flex-shrink:0">
              <img src="<?= UPLOAD_URL . sanitize($coverImage['image_path'] ?? '') ?>" style="width:210px;height:132px;object-fit:cover;border-radius:var(--radius-sm);border:2px solid var(--primary);box-shadow:var(--shadow-sm)">
              <span style="position:absolute;bottom:8px;left:8px;background:var(--primary);color:#fff;font-size:.72rem;padding:4px 10px;border-radius:999px;font-weight:700">Cover</span>
            </div>
            <div style="flex:1;min-width:280px">
              <div class="form-group" style="margin-bottom:12px">
                <label class="form-label">Replace cover photo</label>
                <input type="file" name="replace_cover_image" class="form-control" accept="image/jpeg,image/png,image/webp">
              </div>
              <label class="text-sm text-muted" style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px">
                <input type="checkbox" name="remove_cover_image" value="1">
                Remove current cover photo
              </label>
              <div class="form-hint">Upload a new image to replace the current cover, or check remove if you want to delete it.</div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($galleryImages)): ?>
        <div style="margin-bottom:20px">
          <div class="form-label" style="margin-bottom:10px">Other Photos</div>
          <div style="display:flex;flex-wrap:wrap;gap:12px">
            <?php foreach ($galleryImages as $img): ?>
            <div style="position:relative">
              <img src="<?= UPLOAD_URL . sanitize($img['image_path']) ?>" style="width:110px;height:84px;object-fit:cover;border-radius:var(--radius-sm);border:2px solid var(--border);box-shadow:var(--shadow-sm)">
              <label title="Delete this image" style="position:absolute;top:-7px;right:-7px;background:var(--error);color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.78rem;font-weight:700;box-shadow:var(--shadow-sm)">
                <input type="checkbox" name="delete_images[]" value="<?= $img['id'] ?>" style="display:none" onchange="this.closest('div').style.opacity=this.checked?'0.35':'1'">
                &times;
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="form-hint">Click &times; to mark an extra photo for deletion, then save your changes.</p>
        <?php endif; ?>
        <div class="file-upload" style="margin-top:12px">
          <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
          <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
          <p class="file-upload-text"><strong>Add more photos</strong></p>
          <p class="file-upload-text" style="font-size:.8rem;margin-top:4px">JPG, PNG, or WebP &middot; Max 5MB each</p>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:12px;justify-content:flex-end">
      <a href="dashboard.php" class="btn btn-ghost">Cancel</a>
      <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </form>
</div>
      </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


















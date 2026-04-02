<?php
require_once __DIR__ . '/_common.php';

$pageTitle = 'Content Management';

$tab = ($_GET['tab'] ?? 'ann') === 'pages' ? 'pages' : 'ann';

$hasAnnouncements = true;
$hasPages = true;
try { $db->query("SELECT 1 FROM announcements LIMIT 1"); } catch (Throwable $e) { $hasAnnouncements = false; }
try { $db->query("SELECT 1 FROM content_pages LIMIT 1"); } catch (Throwable $e) { $hasPages = false; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_announcement' && $hasAnnouncements) {
        $id = intval($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($title === '') {
            setFlash('error', 'Title is required.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE announcements SET title=?, body=?, is_active=?, posted_by=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$title, $body, $isActive, intval($_SESSION['user_id']), $id]);
                adminLog($db, 'announcement_updated', 'announcements', $id);
                setFlash('success', 'Announcement updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO announcements (title, body, is_active, posted_by) VALUES (?,?,?,?)");
                $stmt->execute([$title, $body, $isActive, intval($_SESSION['user_id'])]);
                $newId = intval($db->lastInsertId());
                adminLog($db, 'announcement_created', 'announcements', $newId);
                setFlash('success', 'Announcement created.');
            }
        }
        header('Location: content.php?tab=ann');
        exit;
    }

    if ($action === 'delete_announcement' && $hasAnnouncements) {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM announcements WHERE id=?");
            $stmt->execute([$id]);
            adminLog($db, 'announcement_deleted', 'announcements', $id);
            setFlash('success', 'Announcement deleted.');
        }
        header('Location: content.php?tab=ann');
        exit;
    }

    if ($action === 'save_page' && $hasPages) {
        $id = intval($_POST['id'] ?? 0);
        $slug = trim((string)($_POST['slug'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
            setFlash('error', 'Slug is required (lowercase letters, numbers, dashes).');
        } elseif ($title === '') {
            setFlash('error', 'Title is required.');
        } else {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE content_pages SET slug=?, title=?, body=?, is_active=?, updated_by=? WHERE id=?");
                $stmt->execute([$slug, $title, $body, $isActive, intval($_SESSION['user_id']), $id]);
                adminLog($db, 'page_updated', 'content_pages', $id, ['slug' => $slug]);
                setFlash('success', 'Page updated.');
            } else {
                $stmt = $db->prepare("INSERT INTO content_pages (slug, title, body, is_active, updated_by) VALUES (?,?,?,?,?)");
                $stmt->execute([$slug, $title, $body, $isActive, intval($_SESSION['user_id'])]);
                $newId = intval($db->lastInsertId());
                adminLog($db, 'page_created', 'content_pages', $newId, ['slug' => $slug]);
                setFlash('success', 'Page created.');
            }
        }
        header('Location: content.php?tab=pages');
        exit;
    }

    if ($action === 'delete_page' && $hasPages) {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM content_pages WHERE id=?");
            $stmt->execute([$id]);
            adminLog($db, 'page_deleted', 'content_pages', $id);
            setFlash('success', 'Page deleted.');
        }
        header('Location: content.php?tab=pages');
        exit;
    }
}

$editAnnId = $tab === 'ann' ? intval($_GET['edit'] ?? 0) : 0;
$editPageId = $tab === 'pages' ? intval($_GET['edit'] ?? 0) : 0;

$annEdit = null;
if ($tab === 'ann' && $editAnnId > 0 && $hasAnnouncements) {
    $st = $db->prepare("SELECT * FROM announcements WHERE id=?");
    $st->execute([$editAnnId]);
    $annEdit = $st->fetch() ?: null;
}

$pageEdit = null;
if ($tab === 'pages' && $editPageId > 0 && $hasPages) {
    $st = $db->prepare("SELECT * FROM content_pages WHERE id=?");
    $st->execute([$editPageId]);
    $pageEdit = $st->fetch() ?: null;
}

$announcements = $hasAnnouncements ? ($db->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll() ?: []) : [];
$pages = $hasPages ? ($db->query("SELECT * FROM content_pages ORDER BY created_at DESC")->fetchAll() ?: []) : [];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dash-shell">
  <?php adminSidebar('content'); ?>
  <div class="dash-main">
    <?php adminTopbar(); ?>
    <div class="dash-content">
      <div class="dash-heading">
        <div>
          <h1 class="dash-title">Content</h1>
          <div class="dash-breadcrumb">
            <a href="dashboard.php">Admin</a>
            <i class="fas fa-chevron-right" style="font-size:.7rem"></i>
            <span>Content</span>
          </div>
        </div>
      </div>

      <main>
      <div class="flex gap-2" style="margin-bottom:12px;flex-wrap:wrap">
        <a class="btn <?= $tab==='ann'?'btn-primary':'btn-ghost' ?> btn-sm" href="content.php?tab=ann"><i class="fas fa-bullhorn"></i> Announcements</a>
        <a class="btn <?= $tab==='pages'?'btn-primary':'btn-ghost' ?> btn-sm" href="content.php?tab=pages"><i class="fas fa-file-lines"></i> Pages (FAQ/Guidelines)</a>
      </div>

      <?php if ($tab === 'ann'): ?>
        <div class="card">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800"><?= $annEdit ? 'Edit Announcement' : 'New Announcement' ?></h2>
          </div>
          <div class="card-body">
            <?php if (!$hasAnnouncements): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>announcements</code> table. Run <code>install.php</code>.</div>
            <?php else: ?>
              <form method="POST" action="" data-validate>
                <input type="hidden" name="action" value="save_announcement">
                <input type="hidden" name="id" value="<?= intval($annEdit['id'] ?? 0) ?>">
                <div class="form-group">
                  <label class="form-label">Title <span class="required">*</span></label>
                  <input class="form-control" name="title" required value="<?= sanitize($annEdit['title'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label class="form-label">Body</label>
                  <textarea class="form-control" name="body" rows="5" placeholder="Write announcement..."><?= sanitize($annEdit['body'] ?? '') ?></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm" style="user-select:none">
                  <input type="checkbox" name="is_active" value="1" <?= intval($annEdit['is_active'] ?? 1) ? 'checked' : '' ?>>
                  Active
                </label>
                <div class="flex gap-2" style="margin-top:12px;flex-wrap:wrap">
                  <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
                  <?php if ($annEdit): ?><a class="btn btn-ghost" href="content.php?tab=ann"><i class="fas fa-xmark"></i> Cancel</a><?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card mt-4">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">All Announcements</h2>
          </div>
          <div class="card-body">
            <?php if (empty($announcements)): ?>
              <div class="text-muted">No announcements yet.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Title</th><th>Status</th><th>Created</th><th style="width:240px">Actions</th></tr></thead>
                  <tbody>
                  <?php foreach ($announcements as $a): $id=intval($a['id']??0); $active=intval($a['is_active']??1); ?>
                    <tr>
                      <td>
                        <div class="font-bold"><?= sanitize($a['title'] ?? '') ?></div>
                        <?php if (!empty($a['body'])): ?><div class="text-muted text-xs" style="margin-top:6px;white-space:pre-wrap"><?= sanitize(textLength($a['body'])>140?textSlice($a['body'],0,140).'…':$a['body']) ?></div><?php endif; ?>
                      </td>
                      <td><span class="badge" style="<?= $active ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--bg);border:1px solid var(--border);color:var(--text-muted)' ?>"><?= $active ? 'Active' : 'Hidden' ?></span></td>
                      <td class="text-muted text-sm"><?= sanitize(date('M d, Y', strtotime((string)($a['created_at'] ?? '')))) ?></td>
                      <td>
                        <div class="flex flex-wrap gap-2">
                          <a class="btn btn-ghost btn-sm" href="content.php?tab=ann&edit=<?= $id ?>"><i class="fas fa-pen"></i> Edit</a>
                          <form method="POST" action="" style="display:inline">
                            <input type="hidden" name="action" value="delete_announcement">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this announcement?"><i class="fas fa-trash"></i> Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

      <?php else: ?>
        <div class="card">
          <div class="card-header">
            <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800"><?= $pageEdit ? 'Edit Page' : 'New Page' ?></h2>
          </div>
          <div class="card-body">
            <?php if (!$hasPages): ?>
              <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> Missing <code>content_pages</code> table. Run <code>install.php</code>.</div>
            <?php else: ?>
              <form method="POST" action="" data-validate>
                <input type="hidden" name="action" value="save_page">
                <input type="hidden" name="id" value="<?= intval($pageEdit['id'] ?? 0) ?>">
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label">Slug <span class="required">*</span></label>
                    <input class="form-control" name="slug" required placeholder="faq" value="<?= sanitize($pageEdit['slug'] ?? '') ?>">
                    <p class="form-hint">Use lowercase + dashes (e.g. <code>faq</code>, <code>guidelines</code>).</p>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Title <span class="required">*</span></label>
                    <input class="form-control" name="title" required value="<?= sanitize($pageEdit['title'] ?? '') ?>">
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Body</label>
                  <textarea class="form-control" name="body" rows="10" placeholder="Write page content..."><?= sanitize($pageEdit['body'] ?? '') ?></textarea>
                </div>
                <label class="flex items-center gap-2 text-sm" style="user-select:none">
                  <input type="checkbox" name="is_active" value="1" <?= intval($pageEdit['is_active'] ?? 1) ? 'checked' : '' ?>>
                  Active
                </label>
                <div class="flex gap-2" style="margin-top:12px;flex-wrap:wrap">
                  <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save</button>
                  <?php if ($pageEdit): ?><a class="btn btn-ghost" href="content.php?tab=pages"><i class="fas fa-xmark"></i> Cancel</a><?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card mt-4">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <h2 style="margin:0;font-family:var(--font-display);font-size:1.2rem;font-weight:800">All Pages</h2>
              <div class="text-muted text-sm" style="margin-top:4px">Public URL: <code><?= SITE_URL ?>/pages/page.php?slug=faq</code></div>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/page.php?slug=faq" target="_blank" rel="noopener">Open FAQ</a>
          </div>
          <div class="card-body">
            <?php if (empty($pages)): ?>
              <div class="text-muted">No pages yet.</div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Slug</th><th>Title</th><th>Status</th><th style="width:240px">Actions</th></tr></thead>
                  <tbody>
                  <?php foreach ($pages as $p): $id=intval($p['id']??0); $active=intval($p['is_active']??1); ?>
                    <tr>
                      <td><code><?= sanitize($p['slug'] ?? '') ?></code></td>
                      <td class="font-bold"><?= sanitize($p['title'] ?? '') ?></td>
                      <td><span class="badge" style="<?= $active ? 'background:rgba(27,122,74,0.12);color:var(--success)' : 'background:var(--bg);border:1px solid var(--border);color:var(--text-muted)' ?>"><?= $active ? 'Active' : 'Hidden' ?></span></td>
                      <td>
                        <div class="flex flex-wrap gap-2">
                          <a class="btn btn-ghost btn-sm" href="content.php?tab=pages&edit=<?= $id ?>"><i class="fas fa-pen"></i> Edit</a>
                          <a class="btn btn-ghost btn-sm" href="<?= SITE_URL ?>/pages/page.php?slug=<?= sanitize($p['slug'] ?? '') ?>" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open</a>
                          <form method="POST" action="" style="display:inline">
                            <input type="hidden" name="action" value="delete_page">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn btn-ghost btn-sm" type="submit" data-confirm="Delete this page?"><i class="fas fa-trash"></i> Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </main>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


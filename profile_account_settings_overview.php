<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
auth_require_login();

$u = auth_user();
$pdo = db();
$userId = (int)($u['id'] ?? 0);
$avatarDir = __DIR__ . '/uploads/profile_pictures';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? 'change_password');

  if ($action === 'change_password') {
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') { flash_set('error', 'Please fill all password fields.'); redirect('profile_account_settings_overview.php'); }
    if ($newPassword !== $confirmPassword) { flash_set('error', 'New password and confirm password do not match.'); redirect('profile_account_settings_overview.php'); }
    if (strlen($newPassword) < 8) { flash_set('error', 'New password must be at least 8 characters.'); redirect('profile_account_settings_overview.php'); }
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($oldPassword, (string)$row['password_hash'])) { flash_set('error', 'Old password is incorrect.'); redirect('profile_account_settings_overview.php'); }
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$newHash, $userId]);
    flash_set('success', 'Password changed successfully.');
    redirect('profile_account_settings_overview.php');
  }

  if ($action === 'upload_avatar') {
    if (!isset($_FILES['profile_picture'])) { flash_set('error', 'Please choose an image to upload.'); redirect('profile_account_settings_overview.php'); }
    $file = $_FILES['profile_picture'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash_set('error', 'Image upload failed.'); redirect('profile_account_settings_overview.php'); }
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) { flash_set('error', 'Profile picture must be less than 2MB.'); redirect('profile_account_settings_overview.php'); }
    $imgInfo = @getimagesize($tmp);
    $mime = strtolower((string)($imgInfo['mime'] ?? ''));
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) { flash_set('error', 'Only JPG, PNG, WEBP, or GIF images are allowed.'); redirect('profile_account_settings_overview.php'); }
    if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0775, true) && !is_dir($avatarDir)) { flash_set('error', 'Could not create profile picture directory.'); redirect('profile_account_settings_overview.php'); }
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
      $existing = $avatarDir . '/' . $userId . '.' . $ext;
      if (is_file($existing)) @unlink($existing);
    }
    $dest = $avatarDir . '/' . $userId . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dest)) { flash_set('error', 'Could not save uploaded image.'); redirect('profile_account_settings_overview.php'); }
    flash_set('success', 'Profile picture updated successfully.');
    redirect('profile_account_settings_overview.php');
  }

  if ($action === 'remove_avatar') {
    $removed = false;
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
      $existing = $avatarDir . '/' . $userId . '.' . $ext;
      if (is_file($existing) && @unlink($existing)) $removed = true;
    }
    flash_set($removed ? 'success' : 'error', $removed ? 'Profile picture removed.' : 'No profile picture found to remove.');
    redirect('profile_account_settings_overview.php');
  }
}

$avatarUrl = user_avatar_url($userId);

$pageTitle = 'Account Settings';
$activeKey = 'account';
$pageHeadExtra = <<<HTML
<style>
  .page-header { padding: 28px 32px 0; max-width: 1640px; margin: 0 auto; width: 100%; display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
  .acc-wrap { max-width: 760px; margin: 24px auto; padding: 0 32px 60px; display: flex; flex-direction: column; gap: 18px; }
  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 22px; }
  .panel h3 { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
  .profile-preview { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-strong); background: var(--surface-2); display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 600; color: var(--text); flex-shrink: 0; }
  .profile-preview img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
  .profile-row { display: flex; align-items: center; gap: 14px; margin-bottom: 16px; }
  .profile-help { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
  .user-info { font-size: 14px; color: var(--text); margin-bottom: 2px; font-weight: 500; }
  .user-info-sub { font-size: 12px; color: var(--text-dim); }
  .form-row { margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px; }
  .actions { display: flex; gap: 8px; align-items: center; }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Account Settings</div>
      <div class="page-sub">Manage your profile picture and password.</div>
    </div>
  </div>
</div>

<div class="acc-wrap">
  <div class="panel">
    <h3>Profile Picture</h3>
    <div class="profile-row">
      <div class="profile-preview">
        <?php if ($avatarUrl): ?><img src="<?= h($avatarUrl) ?>" alt="Profile Picture"><?php else: ?><?= h(user_initials((string)($u['name'] ?? 'U'))) ?><?php endif; ?>
      </div>
      <div>
        <div class="user-info"><?= h($u['name'] ?? 'User') ?></div>
        <div class="user-info-sub"><?= h($u['role_name'] ?? 'Member') ?> · <?= h($u['email'] ?? '') ?></div>
        <div class="profile-help" style="margin-top:6px;">Upload a JPG, PNG, WEBP, or GIF image (max 2MB).</div>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload_avatar">
      <div class="form-row">
        <label class="field-label" for="profile_picture">Choose Profile Picture</label>
        <input class="input" type="file" id="profile_picture" name="profile_picture" accept="image/png,image/jpeg,image/webp,image/gif" required>
      </div>
      <div class="actions">
        <button class="btn btn-primary save-flash" type="submit">Upload / Change Picture</button>
        <?php if ($avatarUrl): ?>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('Remove current profile picture?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="remove_avatar">
          <button class="btn btn-ghost" type="submit">Remove Picture</button>
        </form>
        <?php else: ?>
      </div>
    </form>
        <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Change Password</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="form-row">
        <label class="field-label" for="old_password">Old Password</label>
        <input class="input" type="password" id="old_password" name="old_password" required>
      </div>
      <div class="form-row">
        <label class="field-label" for="new_password">New Password</label>
        <input class="input" type="password" id="new_password" name="new_password" required minlength="8">
      </div>
      <div class="form-row">
        <label class="field-label" for="confirm_password">Confirm New Password</label>
        <input class="input" type="password" id="confirm_password" name="confirm_password" required minlength="8">
      </div>
      <button class="btn btn-primary save-flash" type="submit">Update Password</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>

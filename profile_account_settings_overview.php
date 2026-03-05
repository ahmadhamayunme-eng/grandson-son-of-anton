<?php
require_once __DIR__ . '/layout.php';

$u = auth_user();
$pdo = db();
$userId = (int)($u['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = (string)($_POST['action'] ?? 'change_password');

  if ($action === 'change_password') {
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
      flash_set('error', 'Please fill in old password, new password, and confirm password.');
      redirect('profile_account_settings_overview.php');
    }

    if ($newPassword !== $confirmPassword) {
      flash_set('error', 'New password and confirm password do not match.');
      redirect('profile_account_settings_overview.php');
    }

    if (strlen($newPassword) < 8) {
      flash_set('error', 'New password must be at least 8 characters.');
      redirect('profile_account_settings_overview.php');
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($oldPassword, (string)$row['password_hash'])) {
      flash_set('error', 'Old password is incorrect.');
      redirect('profile_account_settings_overview.php');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?');
    $update->execute([$newHash, $userId]);

    flash_set('success', 'Password changed successfully.');
    redirect('profile_account_settings_overview.php');
  }

  if ($action === 'upload_avatar') {
    if (!isset($_FILES['profile_picture']) || !is_array($_FILES['profile_picture'])) {
      flash_set('error', 'Please choose an image to upload.');
      redirect('profile_account_settings_overview.php');
    }

    $file = $_FILES['profile_picture'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      flash_set('error', 'Image upload failed. Please try again.');
      redirect('profile_account_settings_overview.php');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
      flash_set('error', 'Profile picture must be less than 2MB.');
      redirect('profile_account_settings_overview.php');
    }

    $imgInfo = @getimagesize($tmp);
    $mime = strtolower((string)($imgInfo['mime'] ?? ''));
    $allowed = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
      flash_set('error', 'Only JPG, PNG, WEBP, or GIF images are allowed.');
      redirect('profile_account_settings_overview.php');
    }

    if (!is_dir($avatarDir) && !@mkdir($avatarDir, 0775, true) && !is_dir($avatarDir)) {
      flash_set('error', 'Could not create profile picture directory.');
      redirect('profile_account_settings_overview.php');
    }

    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
      $existing = $avatarDir . '/' . $userId . '.' . $ext;
      if (is_file($existing)) {
        @unlink($existing);
      }
    }

    $dest = $avatarDir . '/' . $userId . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $dest)) {
      flash_set('error', 'Could not save uploaded image.');
      redirect('profile_account_settings_overview.php');
    }

    flash_set('success', 'Profile picture updated successfully.');
    redirect('profile_account_settings_overview.php');
  }

  if ($action === 'remove_avatar') {
    $removed = false;
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
      $existing = $avatarDir . '/' . $userId . '.' . $ext;
      if (is_file($existing) && @unlink($existing)) {
        $removed = true;
      }
    }

    flash_set($removed ? 'success' : 'error', $removed ? 'Profile picture removed.' : 'No profile picture found to remove.');
    redirect('profile_account_settings_overview.php');
  }
}

$avatarUrl = user_avatar_url($userId);
?>

<style>
  .acc-wrap { max-width: 760px; }
  .acc-card {
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    background: linear-gradient(130deg, rgba(14,14,14,.93), rgba(9,9,9,.95));
  }
  .acc-card h5,
  .acc-card label.form-label {
    color: #fff !important;
  }
  .acc-card .form-control {
    color: #fff;
    background: rgba(0,0,0,.15);
  }
  .profile-preview {
    width: 88px;
    height: 88px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,.3);
    background: rgba(255,255,255,.06);
  }
</style>

<div class="acc-wrap">
  <h2 class="mb-3">Account Settings</h2>

  <div class="card acc-card p-3 p-md-4 mb-3">
    <h5 class="mb-3">Profile Picture</h5>

    <div class="d-flex align-items-center gap-3 mb-3">
      <?php if ($avatarUrl): ?>
        <img class="profile-preview" src="<?= h($avatarUrl) ?>" alt="Profile Picture">
      <?php else: ?>
        <div class="profile-preview d-flex align-items-center justify-content-center fw-bold fs-4"><?= h(strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1))) ?></div>
      <?php endif; ?>
      <div class="text-muted">Upload a JPG, PNG, WEBP, or GIF image (max 2MB).</div>
    </div>

    <form method="post" enctype="multipart/form-data" class="mb-2">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="upload_avatar">
      <div class="mb-3">
        <label class="form-label" for="profile_picture">Choose Profile Picture</label>
        <input class="form-control" type="file" id="profile_picture" name="profile_picture" accept="image/png,image/jpeg,image/webp,image/gif" required>
      </div>
      <button class="btn btn-yellow" type="submit">Upload / Change Picture</button>
    </form>

    <?php if ($avatarUrl): ?>
      <form method="post" onsubmit="return confirm('Remove current profile picture?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn btn-outline-light" type="submit">Remove Picture</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card acc-card p-3 p-md-4">
    <h5 class="mb-3">Change Password</h5>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">

      <div class="mb-3">
        <label class="form-label" for="old_password">Old Password</label>
        <input class="form-control" type="password" id="old_password" name="old_password" required>
      </div>

      <div class="mb-3">
        <label class="form-label" for="new_password">New Password</label>
        <input class="form-control" type="password" id="new_password" name="new_password" required minlength="8">
      </div>

      <div class="mb-3">
        <label class="form-label" for="confirm_password">Confirm New Password</label>
        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
      </div>

      <button class="btn btn-yellow" type="submit">Update Password</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/layout_end.php'; ?>

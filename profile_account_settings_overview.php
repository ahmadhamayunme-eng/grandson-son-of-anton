<?php
require_once __DIR__ . '/layout.php';

$u = auth_user();
$role = $u['role_name'] ?? '';
if (!in_array($role, ['SEO', 'Developer'], true)) {
  http_response_code(403);
  echo '<div class="alert alert-danger">Forbidden</div>';
  require_once __DIR__ . '/layout_end.php';
  exit;
}

$pdo = db();
$userId = (int)($u['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

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
?>

<style>
  .acc-wrap { max-width: 720px; }
  .acc-card { border: 1px solid rgba(255,255,255,.1); border-radius: 14px; background: linear-gradient(130deg, rgba(14,14,14,.93), rgba(9,9,9,.95)); }
</style>

<div class="acc-wrap">
  <h2 class="mb-3">Account Settings</h2>
  <div class="card acc-card p-3 p-md-4">
    <h5 class="mb-3">Change Password</h5>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

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

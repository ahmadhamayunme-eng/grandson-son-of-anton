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
$userName = (string)($u['name'] ?? 'User');
$userInitials = user_initials($userName);

$pageTitle = 'Account Settings';
$activeKey = 'account';
$pageHeadExtra = <<<HTML
<style>
  .page-header {
    padding: 28px 32px 0;
    max-width: 1640px; margin: 0 auto; width: 100%;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px;
  }
  .page-header-left { display: flex; align-items: center; gap: 14px; }
  .page-title { font-size: 26px; font-weight: 700; letter-spacing: -0.02em; line-height: 1.15; }
  .page-sub { color: var(--text-muted); font-size: 13px; margin-top: 4px; max-width: 640px; }

  .body-grid {
    max-width: 1100px; margin: 0 auto; width: 100%;
    padding: 24px 32px 60px;
    display: grid; grid-template-columns: 220px 1fr;
    gap: 28px; align-items: start;
  }

  .sec-nav {
    display: flex; flex-direction: column; gap: 2px;
    position: sticky; top: 24px;
  }
  .sec-nav .lbl {
    font-size: 10.5px; color: var(--text-dim);
    text-transform: uppercase; letter-spacing: 0.12em;
    font-weight: 600;
    padding: 6px 10px 10px;
  }
  .sec-nav a {
    padding: 8px 12px; border-radius: 8px;
    font-size: 13px; color: var(--text-muted);
    display: flex; align-items: center; gap: 9px;
    transition: all 0.12s;
    border-left: 2px solid transparent;
    text-decoration: none;
  }
  .sec-nav a:hover { color: var(--text); background: var(--surface); }
  .sec-nav a.active {
    color: var(--accent);
    background: var(--accent-soft);
    border-left-color: var(--accent);
  }
  .sec-nav a svg { width: 13px; height: 13px; }

  .card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; overflow: hidden;
    margin-bottom: 20px;
  }
  .card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
  }
  .card-title {
    font-size: 14px; font-weight: 600; color: var(--text);
    display: flex; align-items: center; gap: 9px;
  }
  .card-title .ico {
    width: 24px; height: 24px;
    background: var(--accent-soft); color: var(--accent);
    border: 1px solid rgba(250,204,21,0.2); border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .card-title .ico svg { width: 13px; height: 13px; }
  .card-sub { font-size: 12px; color: var(--text-dim); }

  .card-body { padding: 20px; }

  .pp-row {
    display: flex; align-items: center; gap: 22px;
    padding-bottom: 20px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--border);
  }
  .pp-avatar {
    width: 88px; height: 88px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1d4ed8, #312e81);
    border: 1px solid var(--border);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 26px; font-weight: 700; color: #fff;
    letter-spacing: 0.02em; flex-shrink: 0;
    position: relative;
    overflow: hidden;
  }
  .pp-avatar::after {
    content: '';
    position: absolute; inset: 0;
    background-image:
      radial-gradient(rgba(255,255,255,0.18) 1px, transparent 1.2px);
    background-size: 7px 7px;
    pointer-events: none;
  }
  .pp-avatar.has-image::after { display: none; }
  .pp-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; position: relative; z-index: 1; }
  .pp-info { flex: 1; min-width: 0; }
  .pp-name { font-size: 15px; font-weight: 600; color: var(--text); }
  .pp-meta { font-size: 12px; color: var(--text-dim); margin-top: 4px; line-height: 1.55; }
  .pp-meta b { color: var(--text-muted); font-weight: 500; }

  .file-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  .file-input {
    position: relative;
    flex: 1; min-width: 260px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 9px;
    height: 42px;
    display: flex; align-items: center;
    overflow: hidden;
    cursor: pointer;
  }
  .file-input .choose {
    height: 100%; padding: 0 14px;
    background: var(--bg);
    color: var(--text); font-size: 12.5px; font-weight: 500;
    border-right: 1px solid var(--border);
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.12s; cursor: pointer;
  }
  .file-input:hover .choose { background: var(--surface-hover); }
  .file-input .choose svg { width: 13px; height: 13px; color: var(--text-muted); }
  .file-input .filename {
    padding: 0 14px;
    font-size: 13px; color: var(--text-dim);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    flex: 1;
  }
  .file-input.has-file .filename { color: var(--text); }

  .pp-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
  .btn-remove {
    background: transparent;
    color: #fca5a5;
    border: 1px solid rgba(239,68,68,0.25);
    border-radius: 8px;
    padding: 9px 14px; font-size: 13px; font-weight: 500;
    transition: all 0.12s;
    display: inline-flex; align-items: center; gap: 7px;
    cursor: pointer;
  }
  .btn-remove:hover { background: var(--danger-soft); border-color: rgba(239,68,68,0.4); }
  .btn-remove svg { width: 13px; height: 13px; }

  .field { margin-bottom: 16px; }
  .field:last-child { margin-bottom: 0; }
  .field-label {
    font-size: 11.5px; color: var(--text-muted);
    font-weight: 500;
    margin-bottom: 6px;
    display: flex; align-items: center; gap: 6px;
  }
  .field input.input {
    height: 42px; font-size: 13.5px;
    padding: 0 14px;
    background: var(--surface-2); border-radius: 9px;
  }
  .pwd-wrap { position: relative; }
  .pwd-wrap input { padding-right: 44px; }
  .pwd-wrap .toggle-eye {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    width: 28px; height: 28px; border-radius: 6px;
    color: var(--text-muted);
    display: inline-flex; align-items: center; justify-content: center;
    transition: all 0.12s; cursor: pointer;
    background: transparent; border: 0;
  }
  .pwd-wrap .toggle-eye svg { width: 14px; height: 14px; }
  .pwd-wrap .toggle-eye:hover { background: var(--bg); color: var(--text); }

  .pwd-meter { display: flex; gap: 4px; margin-top: 8px; }
  .pwd-bar {
    flex: 1; height: 4px; background: var(--surface-2);
    border-radius: 2px; transition: background 0.2s;
  }
  .pwd-bar.on.bad { background: #ef4444; }
  .pwd-bar.on.weak { background: #f59e0b; }
  .pwd-bar.on.good { background: #22c55e; }
  .pwd-bar.on.strong { background: var(--accent); }
  .pwd-hint {
    margin-top: 6px;
    font-size: 11.5px; color: var(--text-dim);
    display: flex; align-items: center; justify-content: space-between;
  }
  .pwd-hint .strength.bad { color: #fca5a5; }
  .pwd-hint .strength.weak { color: #fcd34d; }
  .pwd-hint .strength.good { color: #86efac; }
  .pwd-hint .strength.strong { color: var(--accent); }

  .match-hint {
    font-size: 11.5px; margin-top: 6px;
    color: var(--text-dim);
    display: flex; align-items: center; gap: 6px;
  }
  .match-hint.match { color: #86efac; }
  .match-hint.mismatch { color: #fca5a5; }
  .match-hint svg { width: 12px; height: 12px; }

  .card-foot {
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    background: var(--bg);
    display: flex; justify-content: space-between; align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .foot-note {
    font-size: 11.5px; color: var(--text-dim);
    display: inline-flex; align-items: center; gap: 6px;
  }
  .foot-note svg { width: 12px; height: 12px; color: var(--accent); }

  @media (max-width: 900px) {
    .body-grid { grid-template-columns: 1fr; }
    .sec-nav { position: static; flex-direction: row; flex-wrap: wrap; }
  }

  /* ===== MOBILE (≤768px) =====
     Screenshots showed each card-head squeezing its title and the
     description into one row, so "Profile picture" wrapped to two lines
     while "JPG, PNG, WEBP… max 2 MB" piled up on the right; the section
     nav, profile row, file picker, password inputs and the card foot
     were all still on their desktop layouts. */
  @media (max-width: 768px) {
    .page-header {
      flex-direction: column;
      align-items: stretch;
      padding: 16px 16px 0;
      gap: 10px;
    }
    .page-title { font-size: clamp(20px, 5.6vw, 26px); line-height: 1.2; }
    .page-sub { font-size: 12px; }

    .body-grid { padding: 16px 16px 28px; gap: 16px; }

    /* Section nav → horizontal pill tabs that scroll if needed. The
       "Sections" label is redundant next to two self-explanatory pills. */
    .sec-nav {
      flex-direction: row;
      flex-wrap: nowrap;
      gap: 8px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
    }
    .sec-nav::-webkit-scrollbar { display: none; }
    .sec-nav .lbl { display: none; }
    .sec-nav a {
      flex-shrink: 0;
      white-space: nowrap;
      border-left: 0;
      border: 1px solid var(--border);
      border-radius: 999px;
      padding: 9px 14px;
    }
    .sec-nav a.active { border-color: rgba(250,204,21,0.4); }

    /* Card head stacks: icon + title on row 1, description on its own
       line so neither has to wrap. */
    .card-head {
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
      padding: 14px 16px;
    }
    .card-sub { font-size: 11.5px; }
    .card-body { padding: 16px; }

    /* Profile row: keep avatar + info side by side but smaller/tighter. */
    .pp-row { gap: 16px; padding-bottom: 16px; margin-bottom: 16px; }
    .pp-avatar { width: 64px; height: 64px; font-size: 20px; }
    .pp-name { font-size: 14.5px; }

    /* File picker + action buttons go full width. */
    .file-row { width: 100%; }
    .file-input { min-width: 0; flex: 1 1 100%; }
    .pp-actions { width: 100%; }
    .pp-actions .btn,
    .pp-actions .btn-remove { flex: 1; min-height: 44px; justify-content: center; }

    /* 16px inputs so iOS doesn't auto-zoom on focus. */
    .field input.input { height: 46px; font-size: 16px; }
    .pwd-wrap input { padding-right: 46px; }

    /* Card foot stacks: note above a full-width primary button. */
    .card-foot {
      flex-direction: column;
      align-items: stretch;
      gap: 12px;
      padding: 14px 16px;
    }
    .card-foot .foot-note { order: -1; }
    .card-foot .btn { width: 100%; min-height: 46px; justify-content: center; }
  }
</style>
HTML;

require_once __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <?= back_button_html() ?>
    <div>
      <div class="page-title">Account Settings</div>
      <div class="page-sub">Your profile picture and account password. Changes apply immediately.</div>
    </div>
  </div>
</div>

<div class="body-grid">

  <nav class="sec-nav">
    <span class="lbl">Sections</span>
    <a class="active" href="#profile-picture">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0116 0"></path></svg>
      Profile picture
    </a>
    <a href="#password">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
      Change password
    </a>
  </nav>

  <div>

    <!-- Profile Picture -->
    <div class="card" id="profile-picture">
      <div class="card-head">
        <div class="card-title">
          <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0116 0"></path></svg></span>
          Profile picture
        </div>
        <span class="card-sub">JPG, PNG, WEBP, or GIF · max 2 MB</span>
      </div>
      <div class="card-body">
        <div class="pp-row">
          <div class="pp-avatar <?= $avatarUrl ? 'has-image' : '' ?>" id="ppAvatar">
            <?php if ($avatarUrl): ?>
              <img src="<?= h($avatarUrl) ?>" alt="Profile Picture">
            <?php else: ?>
              <?= h($userInitials) ?>
            <?php endif; ?>
          </div>
          <div class="pp-info">
            <div class="pp-name"><?= h($userName) ?></div>
            <div class="pp-meta">
              <?= h($u['role_name'] ?? 'Member') ?> · <?= h($u['email'] ?? '') ?><br>
              Visible on every comment, assignee chip and avatar across <b>anton x</b>.
              Recommended <b>512×512px</b>, square.
            </div>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" id="avatarForm">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="upload_avatar">

          <div class="field" style="margin-bottom: 14px;">
            <label class="field-label" for="profile_picture">Choose profile picture</label>
            <div class="file-row">
              <label class="file-input" for="profile_picture">
                <span class="choose">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                  Choose file
                </span>
                <span class="filename" id="ppFilename">No file chosen</span>
              </label>
              <input id="profile_picture" name="profile_picture" type="file" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;" required>
            </div>
          </div>

          <div class="pp-actions">
            <button class="btn btn-primary save-flash" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path></svg>
              Upload / Change picture
            </button>
          </div>
        </form>

        <?php if ($avatarUrl): ?>
          <form method="post" onsubmit="return confirm('Remove current profile picture?');" style="margin-top:8px;display:inline-block;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="remove_avatar">
            <button class="btn-remove" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
              Remove picture
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Change Password -->
    <form method="post" autocomplete="off" id="pwdForm">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="card" id="password">
        <div class="card-head">
          <div class="card-title">
            <span class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg></span>
            Change password
          </div>
          <span class="card-sub">Sign-out from other sessions after update</span>
        </div>
        <div class="card-body">
          <div class="field">
            <label class="field-label" for="old_password">Old password</label>
            <div class="pwd-wrap">
              <input class="input" type="password" id="old_password" name="old_password" required>
              <button type="button" class="toggle-eye" data-target="old_password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="new_password">New password</label>
            <div class="pwd-wrap">
              <input class="input" type="password" id="new_password" name="new_password" placeholder="At least 8 characters" required minlength="8">
              <button type="button" class="toggle-eye" data-target="new_password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
            </div>
            <div class="pwd-meter" id="pwdMeter">
              <div class="pwd-bar"></div>
              <div class="pwd-bar"></div>
              <div class="pwd-bar"></div>
              <div class="pwd-bar"></div>
            </div>
            <div class="pwd-hint">
              <span>Use 8+ chars · upper · number · symbol</span>
              <span class="strength" id="pwdStrengthLbl">—</span>
            </div>
          </div>

          <div class="field" style="margin-bottom: 0;">
            <label class="field-label" for="confirm_password">Confirm new password</label>
            <div class="pwd-wrap">
              <input class="input" type="password" id="confirm_password" name="confirm_password" required minlength="8">
              <button type="button" class="toggle-eye" data-target="confirm_password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>
            </div>
            <div class="match-hint" id="matchHint">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
              <span>Type both new password fields to compare</span>
            </div>
          </div>
        </div>

        <div class="card-foot">
          <span class="foot-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
            You'll be re-prompted for the password at next sign-in.
          </span>
          <button class="btn btn-primary save-flash" type="submit" id="updatePwdBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Update password
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
  document.querySelectorAll('.toggle-eye').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.target;
      const input = document.getElementById(id);
      if (input) input.type = input.type === 'password' ? 'text' : 'password';
    });
  });

  const ppFile = document.getElementById('profile_picture');
  const ppFilename = document.getElementById('ppFilename');
  const ppAvatar = document.getElementById('ppAvatar');
  if (ppFile) {
    ppFile.addEventListener('change', () => {
      const f = ppFile.files && ppFile.files[0];
      if (!f) return;
      ppFilename.textContent = f.name;
      ppFilename.parentElement.classList.add('has-file');
      const reader = new FileReader();
      reader.onload = (e) => {
        ppAvatar.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
        ppAvatar.classList.add('has-image');
      };
      reader.readAsDataURL(f);
    });
  }

  const newPwd = document.getElementById('new_password');
  const confirmPwd = document.getElementById('confirm_password');
  const meter = document.getElementById('pwdMeter');
  const meterBars = meter ? meter.querySelectorAll('.pwd-bar') : [];
  const strengthLbl = document.getElementById('pwdStrengthLbl');
  const matchHint = document.getElementById('matchHint');

  function scorePwd(p) {
    if (!p) return 0;
    let s = 0;
    if (p.length >= 8) s++;
    if (/[A-Z]/.test(p)) s++;
    if (/[0-9]/.test(p)) s++;
    if (/[^A-Za-z0-9]/.test(p)) s++;
    return s;
  }
  function strengthBucket(s) { return ['—','bad','weak','good','strong'][s] || '—'; }
  function strengthText(s) { return ['—','Too short','Weak','Good','Strong'][s] || '—'; }
  function refreshStrength() {
    if (!newPwd) return;
    const s = scorePwd(newPwd.value);
    meterBars.forEach((b, i) => {
      b.classList.remove('on','bad','weak','good','strong');
      if (i < s) { b.classList.add('on'); b.classList.add(strengthBucket(s)); }
    });
    if (strengthLbl) { strengthLbl.className = 'strength ' + strengthBucket(s); strengthLbl.textContent = strengthText(s); }
    refreshMatch();
  }
  function refreshMatch() {
    if (!matchHint || !newPwd || !confirmPwd) return;
    const a = newPwd.value, b = confirmPwd.value;
    if (!a || !b) {
      matchHint.className = 'match-hint';
      matchHint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg><span>Type both new password fields to compare</span>';
      return;
    }
    if (a === b) {
      matchHint.className = 'match-hint match';
      matchHint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg><span>Passwords match</span>';
    } else {
      matchHint.className = 'match-hint mismatch';
      matchHint.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg><span>Passwords don\'t match yet</span>';
    }
  }
  if (newPwd) newPwd.addEventListener('input', refreshStrength);
  if (confirmPwd) confirmPwd.addEventListener('input', refreshMatch);

  document.querySelectorAll('.sec-nav a').forEach(a => {
    a.addEventListener('click', () => {
      document.querySelectorAll('.sec-nav a').forEach(x => x.classList.remove('active'));
      a.classList.add('active');
    });
  });
</script>

<?php require_once __DIR__ . '/layout_end.php'; ?>

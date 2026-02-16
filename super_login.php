<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
if (auth_user() && (auth_user()['role_name'] ?? '')==='Super Admin') redirect('dashboard.php');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $email=trim($_POST['email'] ?? '');
  $pass=$_POST['password'] ?? '';
  if (auth_login($email,$pass,true)) redirect('dashboard.php');
  $error="Invalid Super Admin credentials.";
}
include __DIR__ . '/partials/header.php';
?>
<div class="container py-5" style="max-width:520px;">
  <div class="card p-4">
    <h3 class="mb-1">Super Admin Login</h3>
    <div class="text-muted mb-4">Only Super Admin account.</div>
    <?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <div class="mb-3"><label class="form-label">Email</label><input class="form-control" name="email" type="email" required></div>
      <div class="mb-3"><label class="form-label">Password</label><input class="form-control" name="password" type="password" required></div>
      <button class="btn btn-yellow w-100" type="submit">Login as Super Admin</button>
      <div class="small-help mt-3">Normal login? <a href="login.php">Go back</a></div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

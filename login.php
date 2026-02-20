<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
if (auth_user()) redirect('dashboard.php');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $email=trim($_POST['email'] ?? '');
  $pass=$_POST['password'] ?? '';
  if (auth_login($email,$pass,false)) redirect('dashboard.php');
  $error="Invalid credentials.";
}
include __DIR__ . '/partials/header.php';
?>
<style>
  .auth-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    background:
      radial-gradient(circle at 15% 20%, rgba(255, 208, 0, 0.11), transparent 35%),
      radial-gradient(circle at 80% 5%, rgba(255, 208, 0, 0.08), transparent 25%),
      #0b0b0b;
  }

  .auth-card {
    width: 100%;
    max-width: 920px;
    display: grid;
    grid-template-columns: 1.1fr 1fr;
    gap: 0;
    border-radius: 20px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    background: #101010;
  }

  .auth-panel {
    padding: 3rem;
    background: linear-gradient(160deg, rgba(255, 208, 0, 0.17), rgba(255, 208, 0, 0.03) 45%, rgba(255, 255, 255, 0.01));
    border-right: 1px solid rgba(255, 255, 255, 0.07);
  }

  .auth-brand {
    font-weight: 700;
    letter-spacing: 0.03em;
    color: #ffd000;
    margin-bottom: 1.25rem;
  }

  .auth-panel h1 {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.75rem;
  }

  .auth-points {
    margin-top: 1.5rem;
    padding-left: 1rem;
    color: rgba(255, 255, 255, 0.85);
  }

  .auth-points li { margin-bottom: 0.55rem; }

  .auth-form {
    padding: 3rem;
    background: #121212;
  }

  .auth-form .form-label {
    color: rgba(255, 255, 255, 0.92);
    font-weight: 500;
    margin-bottom: 0.4rem;
  }

  .auth-form .form-control {
    min-height: 46px;
    border-radius: 10px;
  }

  .auth-form .btn {
    min-height: 46px;
    border-radius: 10px;
  }

  @media (max-width: 880px) {
    .auth-card {
      grid-template-columns: 1fr;
      max-width: 540px;
    }

    .auth-panel {
      border-right: 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.07);
      padding: 2rem;
    }

    .auth-form { padding: 2rem; }
  }
</style>

<div class="auth-shell">
  <div class="auth-card">
    <section class="auth-panel">
      <div class="auth-brand">AntonX Workspace</div>
      <h1>Welcome back</h1>
      <p class="text-muted mb-0">Sign in to continue managing clients, projects, tasks, and reports in one place.</p>
      <ul class="auth-points">
        <li>Track active projects and priorities.</li>
        <li>Keep communication and delivery in sync.</li>
        <li>Review status and performance quickly.</li>
      </ul>
    </section>

    <section class="auth-form">
      <h3 class="mb-1">Login</h3>
      <div class="text-muted mb-4">All roles login here.</div>
      <?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
      <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <div class="mb-3">
          <label class="form-label" for="login-email">Email</label>
          <input class="form-control" id="login-email" name="email" type="email" autocomplete="email" required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="login-password">Password</label>
          <input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-yellow w-100" type="submit">Sign in</button>
        <div class="small-help mt-3">Super Admin? <a href="super_login.php">Login here</a></div>
      </form>
    </section>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

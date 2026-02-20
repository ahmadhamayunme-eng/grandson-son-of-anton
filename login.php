<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
if (auth_user()) redirect('dashboard.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  if (auth_login($email, $pass, false)) redirect('dashboard.php');
  $error = 'Invalid credentials.';
}
include __DIR__ . '/partials/header.php';
?>
<style>
  .login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
    background-color: #07080d;
    background-image:
      radial-gradient(circle at 50% 15%, rgba(15, 29, 90, 0.4), transparent 52%),
      repeating-linear-gradient(90deg, rgba(255,255,255,0.02) 0, rgba(255,255,255,0.02) 1px, transparent 1px, transparent 6px);
  }

  .login-card {
    width: 100%;
    max-width: 648px;
    border-radius: 18px;
    padding: 44px 52px 34px;
    background: linear-gradient(105deg, rgba(255,255,255,0.05), rgba(255,255,255,0.015) 35%, rgba(255,255,255,0.04));
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 28px 100px rgba(0, 0, 0, 0.68);
    backdrop-filter: blur(2px);
  }

  .login-brand {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #f2f2f2;
    font-size: 53px;
    font-weight: 500;
    line-height: 1;
    margin-bottom: 52px;
  }

  .login-brand svg {
    width: 44px;
    height: 44px;
  }

  .login-form-group {
    margin-bottom: 22px;
  }

  .login-label {
    display: block;
    color: #f2f2f2;
    font-size: 40px;
    font-weight: 500;
    margin-bottom: 12px;
  }

  .input-shell {
    display: flex;
    align-items: center;
    gap: 14px;
    min-height: 76px;
    border-radius: 10px;
    padding: 0 20px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    background: linear-gradient(90deg, rgba(255,255,255,0.09), rgba(255,255,255,0.05));
  }

  .input-shell svg {
    width: 28px;
    height: 28px;
    flex: 0 0 auto;
    color: #b8b8b8;
  }

  .login-input {
    border: 0;
    outline: 0;
    width: 100%;
    background: transparent;
    color: #f3f3f3;
    font-size: 38px;
    font-weight: 400;
    line-height: 1;
  }

  .login-input::placeholder { color: #a5a5a5; }

  .login-links {
    display: flex;
    justify-content: flex-end;
    margin: 6px 0 24px;
  }

  .login-links a {
    color: #b7b7b7;
    text-decoration: none;
    font-size: 37px;
  }

  .login-submit {
    width: 100%;
    min-height: 76px;
    border-radius: 10px;
    border: 0;
    background: linear-gradient(180deg, #f4d85e, #ebc53e);
    color: #121212;
    font-size: 48px;
    font-weight: 600;
    margin-bottom: 28px;
  }

  .login-sep {
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    margin: 0 0 26px;
  }

  .login-signup {
    text-align: center;
    font-size: 38px;
    color: #bdbdbd;
  }

  .login-signup a {
    color: #f0cb47;
    text-decoration: none;
    font-weight: 600;
  }

  .login-alert {
    margin-bottom: 18px;
    border-radius: 10px;
  }

  @media (max-width: 900px) {
    .login-card { max-width: 560px; }
    .login-brand { font-size: 40px; margin-bottom: 40px; }
    .login-brand svg { width: 34px; height: 34px; }
    .login-label { font-size: 31px; }
    .input-shell { min-height: 64px; }
    .login-input { font-size: 30px; }
    .login-links a { font-size: 29px; }
    .login-submit { min-height: 66px; font-size: 38px; }
    .login-signup { font-size: 29px; }
  }

  @media (max-width: 640px) {
    .login-card { padding: 32px 22px 24px; border-radius: 14px; }
    .login-brand { font-size: 32px; margin-bottom: 30px; }
    .login-label { font-size: 24px; margin-bottom: 10px; }
    .input-shell { min-height: 54px; padding: 0 14px; }
    .input-shell svg { width: 22px; height: 22px; }
    .login-input { font-size: 22px; }
    .login-links a { font-size: 22px; }
    .login-submit { min-height: 56px; font-size: 31px; margin-bottom: 20px; }
    .login-signup { font-size: 21px; }
  }
</style>

<div class="login-page">
  <div class="login-card">
    <div class="login-brand" aria-label="AntonX">
      <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <circle cx="24" cy="24" r="21" stroke="#f0cb47" stroke-width="3"/>
        <path d="M27.8 10.5L16.9 26.2H24.1L20.4 37.5L31.3 21.8H24.1L27.8 10.5Z" fill="#f0cb47"/>
      </svg>
      <span>AntonX</span>
    </div>

    <?php if ($error): ?><div class="alert alert-danger login-alert"><?=h($error)?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

      <div class="login-form-group">
        <label class="login-label" for="login-email">Email</label>
        <div class="input-shell">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M3 6.75C3 5.78 3.79 5 4.75 5H19.25C20.22 5 21 5.78 21 6.75V17.25C21 18.22 20.22 19 19.25 19H4.75C3.79 19 3 18.22 3 17.25V6.75Z" stroke="currentColor" stroke-width="1.8"/>
            <path d="M4 7L12 13L20 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <input class="login-input" id="login-email" name="email" type="email" placeholder="Email" autocomplete="email" required>
        </div>
      </div>

      <div class="login-form-group">
        <label class="login-label" for="login-password">Password</label>
        <div class="input-shell">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/>
            <path d="M8.5 11V8.5C8.5 6.57 10.07 5 12 5C13.93 5 15.5 6.57 15.5 8.5V11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          <input class="login-input" id="login-password" name="password" type="password" placeholder="Password" autocomplete="current-password" required>
        </div>
      </div>

      <div class="login-links">
        <a href="forgot_password.php">Forgot password?</a>
      </div>

      <button class="login-submit" type="submit">Sign In</button>
    </form>

    <div class="login-sep"></div>
    <div class="login-signup">Don't have an account? <a href="super_login.php">Sign up</a></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

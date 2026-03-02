<?php
session_start();
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';
if (auth_user() && (auth_user()['role_name'] ?? '') === 'Super Admin') redirect('dashboard.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $email = trim($_POST['email'] ?? '');
  $pass = $_POST['password'] ?? '';
  if (auth_login($email, $pass, true)) redirect('dashboard.php');
  $error = 'Invalid Super Admin credentials.';
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
    background-color: #050505;
    background-image:
      radial-gradient(circle at 50% 15%, rgba(255,255,255,0.06), transparent 52%),
      repeating-linear-gradient(90deg, rgba(255,255,255,0.02) 0, rgba(255,255,255,0.02) 1px, transparent 1px, transparent 6px);
  }

  .login-card {
    width: 100%;
    max-width: 520px;
    border-radius: 14px;
    padding: 30px 34px 24px;
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
    font-size: 28px;
    font-weight: 500;
    line-height: 1;
    margin-bottom: 8px;
  }

  .login-brand svg {
    width: 30px;
    height: 30px;
  }

  .login-subtitle {
    text-align: center;
    margin: 0 0 24px;
    color: #bcbcbc;
    font-size: 16px;
  }

  .login-form-group {
    margin-bottom: 14px;
  }

  .login-label {
    display: block;
    color: #f2f2f2;
    font-size: 19px;
    font-weight: 500;
    margin-bottom: 8px;
  }

  .input-shell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 54px;
    border-radius: 8px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    background: linear-gradient(90deg, rgba(255,255,255,0.09), rgba(255,255,255,0.05));
  }

  .input-shell svg {
    width: 20px;
    height: 20px;
    flex: 0 0 auto;
    color: #b8b8b8;
  }

  .login-input {
    border: 0;
    outline: 0;
    width: 100%;
    background: transparent;
    color: #f3f3f3;
    font-size: 18px;
    font-weight: 400;
    line-height: 1;
  }

  .login-input::placeholder {
    color: #a5a5a5;
  }

  .login-submit {
    width: 100%;
    min-height: 54px;
    border-radius: 8px;
    border: 0;
    background: linear-gradient(180deg, #f4d85e, #ebc53e);
    color: #121212;
    font-size: 18px;
    font-weight: 600;
    margin-top: 2px;
    margin-bottom: 14px;
  }

  .login-sep {
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    margin: 0 0 16px;
  }

  .login-signup {
    text-align: center;
    font-size: 18px;
    color: #bdbdbd;
  }

  .login-signup a {
    color: #f0cb47;
    text-decoration: none;
    font-weight: 600;
  }

  .login-alert {
    margin-bottom: 14px;
    border-radius: 8px;
  }

  @media (max-width: 900px) {
    .login-card {
      max-width: 500px;
    }

    .login-brand {
      font-size: 26px;
    }

    .login-brand svg {
      width: 28px;
      height: 28px;
    }

    .login-label {
      font-size: 18px;
    }

    .input-shell {
      min-height: 50px;
    }

    .login-input {
      font-size: 14px;
    }

    .login-submit {
      min-height: 50px;
      font-size: 18px;
    }

    .login-signup {
      font-size: 18px;
    }
  }

  @media (max-width: 640px) {
    .login-card {
      padding: 24px 16px 18px;
      border-radius: 12px;
    }

    .login-brand {
      font-size: 22px;
    }

    .login-subtitle {
      font-size: 14px;
      margin-bottom: 18px;
    }

    .login-label {
      font-size: 16px;
      margin-bottom: 6px;
    }

    .input-shell {
      min-height: 46px;
      padding: 0 12px;
    }

    .input-shell svg {
      width: 18px;
      height: 18px;
    }

    .login-input {
      font-size: 13px;
    }

    .login-submit {
      min-height: 46px;
      font-size: 16px;
      margin-bottom: 14px;
    }

    .login-signup {
      font-size: 16px;
    }
  }
</style>

<div class="login-page">
  <div class="login-card">
    <div class="login-brand" aria-label="AntonX">
      <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <circle cx="24" cy="24" r="21" stroke="#f0cb47" stroke-width="3" />
        <path d="M27.8 10.5L16.9 26.2H24.1L20.4 37.5L31.3 21.8H24.1L27.8 10.5Z" fill="#f0cb47" />
      </svg>
      <span>AntonX</span>
    </div>
    <p class="login-subtitle">Super Admin Access</p>

    <?php if ($error): ?><div class="alert alert-danger login-alert"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="login-form-group">
        <label class="login-label" for="super-login-email">Email</label>
        <div class="input-shell">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M3 6.75C3 5.78 3.79 5 4.75 5H19.25C20.22 5 21 5.78 21 6.75V17.25C21 18.22 20.22 19 19.25 19H4.75C3.79 19 3 18.22 3 17.25V6.75Z" stroke="currentColor" stroke-width="1.8" />
            <path d="M4 7L12 13L20 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <input class="login-input" id="super-login-email" name="email" type="email" placeholder="Email" autocomplete="email" required>
        </div>
      </div>

      <div class="login-form-group">
        <label class="login-label" for="super-login-password">Password</label>
        <div class="input-shell">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="5" y="11" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8" />
            <path d="M8.5 11V8.5C8.5 6.57 10.07 5 12 5C13.93 5 15.5 6.57 15.5 8.5V11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          <input class="login-input" id="super-login-password" name="password" type="password" placeholder="Password" autocomplete="current-password" required>
        </div>
      </div>

      <button class="login-submit" type="submit">Sign In</button>
    </form>

    <div class="login-sep"></div>
    <div class="login-signup">Normal login? <a href="login.php">Go back</a></div>
  </div>
</div>
<?php include __DIR__ . '/partials/footer.php'; ?>

<?php
/**
 * Reset Password
 *
 * The link sent by EmailService::sendPasswordResetEmail() points here with a
 * ?token= query param. This file never existed — forgot-password.php has
 * been generating valid, expiring tokens in password_resets all along, but
 * nothing consumed them, so no one could actually complete a reset.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\RateLimiterService;
use App\Services\SiteSettingsService;

if (AuthMiddleware::isLoggedIn()) {
    redirect(APP_URL . '/');
}

$pdo = Database::getInstance()->getConnection();

$forgotPasswordBackground = SiteSettingsService::get($pdo, 'forgot_password_background');
$forgotPasswordBackgroundUrl = $forgotPasswordBackground ? '../' . $forgotPasswordBackground : '../assets/img/generic/17.jpg';

/**
 * password_resets stores a bcrypt hash of the token (see forgot-password.php),
 * so it can't be looked up with a direct WHERE — check each still-valid
 * candidate with password_verify() instead.
 */
function findPasswordResetMatch(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $stmt = $pdo->query("SELECT * FROM password_resets WHERE expires_at > NOW()");
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($token, $row['token'])) {
            return $row;
        }
    }

    return null;
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$errors = [];
$success = false;

$resetRow = findPasswordResetMatch($pdo, $token);
$validToken = $resetRow !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (!$validToken) {
        $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $rateLimitKey = 'reset-password:' . RateLimiterService::clientIp() . ':' . $resetRow['user_id'];

        if (RateLimiterService::tooManyAttempts($pdo, $rateLimitKey, 5, 900)) {
            $errors[] = 'Too many attempts. Please wait a while and try again.';
        } else {
            RateLimiterService::recordAttempt($pdo, $rateLimitKey);

            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($password)) {
                $errors[] = 'Password is required';
            } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
                $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters';
            }

            if ($password !== $confirmPassword) {
                $errors[] = 'Passwords do not match';
            }

            if (empty($errors)) {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([hashPassword($password), $resetRow['user_id']]);

                // Invalidate every pending reset for this user, not just the one used
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$resetRow['user_id']]);

                setFlashMessage('success', 'Password reset successfully! You can now sign in.');
                redirect('login');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en-US" dir="ltr">

  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Reset Password - <?php echo APP_NAME; ?></title>

    <link rel="apple-touch-icon" sizes="180x180" href="../assets/img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicons/favicon-16x16.png">
    <link rel="shortcut icon" type="image/x-icon" href="../assets/img/favicons/favicon.ico">
    <link rel="manifest" href="../assets/img/favicons/manifest.json">
    <meta name="msapplication-TileImage" content="../assets/img/favicons/mstile-150x150.png">
    <meta name="theme-color" content="#ffffff">
    <script src="../assets/js/config.js"></script>
    <script src="../vendors/simplebar/simplebar.min.js"></script>

    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700%7cPoppins:300,400,500,600,700,800,900&amp;display=swap" rel="stylesheet">
    <link href="../vendors/simplebar/simplebar.min.css" rel="stylesheet">
    <link href="../assets/css/theme-rtl.css" rel="stylesheet" id="style-rtl">
    <link href="../assets/css/theme.css" rel="stylesheet" id="style-default">
    <link href="../assets/css/user-rtl.css" rel="stylesheet" id="user-style-rtl">
    <link href="../assets/css/user.css" rel="stylesheet" id="user-style-default">
    <script>
      var isRTL = JSON.parse(localStorage.getItem('isRTL'));
      if (isRTL) {
        var linkDefault = document.getElementById('style-default');
        var userLinkDefault = document.getElementById('user-style-default');
        linkDefault.setAttribute('disabled', true);
        userLinkDefault.setAttribute('disabled', true);
        document.querySelector('html').setAttribute('dir', 'rtl');
      } else {
        var linkRTL = document.getElementById('style-rtl');
        var userLinkRTL = document.getElementById('user-style-rtl');
        linkRTL.setAttribute('disabled', true);
        userLinkRTL.setAttribute('disabled', true);
      }
    </script>
  </head>

  <body>
    <main class="main" id="top">
      <div class="container-fluid">
        <script>
          var isFluid = JSON.parse(localStorage.getItem('isFluid'));
          if (isFluid) {
            var container = document.querySelector('[data-layout]');
            container.classList.remove('container');
            container.classList.add('container-fluid');
          }
        </script>
        <div class="row min-vh-100 bg-100">
          <div class="col-6 d-none d-lg-block position-relative">
            <div class="bg-holder overlay" style="background-image:url(<?php echo htmlspecialchars($forgotPasswordBackgroundUrl); ?>);background-position: 50% 76%;"></div>
          </div>
          <div class="col-sm-10 col-md-6 px-sm-0 align-self-center mx-auto py-5">
            <div class="row justify-content-center g-0">
              <div class="col-lg-9 col-xl-8 col-xxl-6">
                <div class="card">
                  <div class="card-header bg-circle-shape bg-shape text-center p-2"><a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="../index" data-bs-theme="light"><?php echo APP_NAME; ?></a></div>
                  <div class="card-body p-4">
                    <div class="text-center">
                    <?php if (!$validToken): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle fs-4"></i>
                            <h4 class="alert-heading fw-semi-bold">Invalid or Expired Link</h4>
                            <span class="fs-10">This password reset link isn't valid anymore. Please request a new one.</span>
                        </div>
                        <a class="btn btn-primary btn-sm mt-3" href="forgot-password">
                            <span class="fas fa-chevron-left me-1" data-fa-transform="shrink-4 down-1"></span>Request New Link
                        </a>
                    <?php else: ?>
                        <div class="mb-4">
                            <i class="fas fa-lock fs-4"></i>
                            <h4 class="mb-0">Set a New Password</h4>
                            <small>Choose a new password for your account.</small>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle fs-4"></i>
                                <div>
                                    <?php foreach ($errors as $error): ?>
                                        <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                            <div class="mb-3 text-start">
                                <label class="form-label" for="reset-password">New Password</label>
                                <input class="form-control" id="reset-password" type="password" name="password" placeholder="••••••••" minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" required autofocus />
                            </div>
                            <div class="mb-3 text-start">
                                <label class="form-label" for="reset-confirm-password">Confirm New Password</label>
                                <input class="form-control" id="reset-confirm-password" type="password" name="confirm_password" placeholder="••••••••" minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" required />
                            </div>
                            <button class="btn btn-primary d-block w-100 mt-3" type="submit">Reset Password</button>
                        </form>
                    <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>

    <script src="../vendors/popper/popper.min.js"></script>
    <script src="../vendors/bootstrap/bootstrap.min.js"></script>
    <script src="../vendors/anchorjs/anchor.min.js"></script>
    <script src="../vendors/is/is.min.js"></script>
    <script src="../vendors/fontawesome/all.min.js"></script>
    <script src="../vendors/lodash/lodash.min.js"></script>
    <script src="../vendors/list.js/list.min.js"></script>
    <script src="../assets/js/theme.js"></script>
  </body>

</html>

<?php
/**
 * Forgot Password Page
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\SiteSettingsService;
use App\Services\RateLimiterService;

// Redirect if already logged in
if (AuthMiddleware::isLoggedIn()) {
    redirect(APP_URL . '/');
}

$pdo = Database::getInstance()->getConnection();
$errors = [];
$success = false;
$email = '';

$forgotPasswordBackground = SiteSettingsService::get($pdo, 'forgot_password_background');
$forgotPasswordBackgroundUrl = $forgotPasswordBackground ? '../' . $forgotPasswordBackground : '../assets/img/generic/17.jpg';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $email = sanitize($_POST['email'] ?? '');

        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!isValidEmail($email)) {
            $errors[] = 'Please enter a valid email address';
        }

        if (empty($errors)) {
            $rateLimitKey = 'forgot-password:' . RateLimiterService::clientIp() . ':' . strtolower($email);

            if (RateLimiterService::tooManyAttempts($pdo, $rateLimitKey, 3, 3600)) {
                $errors[] = 'Too many reset requests for this email. Please try again later.';
            } else {
                RateLimiterService::recordAttempt($pdo, $rateLimitKey);

                // Find user
                $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Delete any existing reset tokens for this user
                    $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

                    // Generate new token and hash it for storage
                    $token = generateToken();
                    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
                    $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_EXPIRY);

                    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$user['id'], $tokenHash, $expires]);

                    // Send reset email with plain token (user needs the plain token)
                    $emailService = new \App\Services\EmailService($pdo);
                    $emailService->sendPasswordResetEmail($user['email'], $token, $user['first_name']);
                }

                // Always show success message to prevent email enumeration
                $success = true;
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


    <!-- ===============================================-->
    <!--    Document Title-->
    <!-- ===============================================-->
    <title>Forgot Password - <?php echo APP_NAME; ?></title>


    <!-- ===============================================-->
    <!--    Favicons-->
    <!-- ===============================================-->
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicons/favicon-16x16.png">
    <link rel="shortcut icon" type="image/x-icon" href="../assets/img/favicons/favicon.ico">
    <link rel="manifest" href="../assets/img/favicons/manifest.json">
    <meta name="msapplication-TileImage" content="../assets/img/favicons/mstile-150x150.png">
    <meta name="theme-color" content="#ffffff">
    <script src="../assets/js/config.js"></script>
    <script src="../vendors/simplebar/simplebar.min.js"></script>


    <!-- ===============================================-->
    <!--    Stylesheets-->
    <!-- ===============================================-->
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

    <!-- ===============================================-->
    <!--    Main Content-->
    <!-- ===============================================-->
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
            <div class="bg-holder overlay" style="background-image:url(<?php echo htmlspecialchars($forgotPasswordBackgroundUrl); ?>);background-position: 50% 76%;">
            </div>
            <!--/.bg-holder-->

          </div>
          <div class="col-sm-10 col-md-6 px-sm-0 align-self-center mx-auto py-5">
            <div class="row justify-content-center g-0">
              <div class="col-lg-9 col-xl-8 col-xxl-6">
                <div class="card">
                  <div class="card-header bg-circle-shape bg-shape text-center p-2"><a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="../index" data-bs-theme="light"><?php echo APP_NAME; ?></a></div>
                  <div class="card-body p-4">
                    <div class="text-center">
                        <?php if ($success): ?>
                            <div>
                                <div class="alert alert-success" role="alert">
                                    <i class="fas fa-check-circle fs-4"></i>
                                    <h4 class="alert-heading fw-semi-bold">Check Your Email</h4>
                                    <span>If an account exists for <strong><?php echo $email; ?></strong>, you will receive a password reset link shortly.</span>
                                </div>
                                    <p style="margin-top: 15px; color: #86868b; font-size: 0.85rem;">
                                        The link will expire in 1 hour.
                                    </p>
                            </div>

                            <a href="login" class="btn btn-secondary btn-lg w-100">
                                <i class="fas fa-arrow-left"></i> Back to Login
                            </a>

                        <?php else: ?>
                        <div>
                            <i class="fas fa-lock fs-4"></i>
                            <h4 class="mb-0">Forgot Password?</h4>
                            <small>Enter your email and we'll send you a reset link.</small>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle fs-4"></i>
                                <div>
                                    <?php foreach ($errors as $error): ?>
                                        <p class="mb-0"><?php echo $error; ?></p>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                      <form class="mb-3 mt-4" method="POST" action="">
                        <?php echo csrfField(); ?>
                        <input class="form-control" type="email" name="email" value="<?php echo $email; ?>" placeholder="you@example.com" required autofocus />
                        <div class="mb-3"></div>
                        <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Send reset link</button>
                      </form>
                            <a class="fs-10 text-600" href="../auth/login">Back to Login<span class="d-inline-block ms-1">&rarr;</span></a>
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
    <!-- ===============================================-->
    <!--    End of Main Content-->
    <!-- ===============================================-->




    <!-- ===============================================-->
    <!--    JavaScripts-->
    <!-- ===============================================-->
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
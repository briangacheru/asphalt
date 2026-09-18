<?php
/**
 * Login Page
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
$email = '';

$loginBackground = SiteSettingsService::get($pdo, 'login_background');
$loginBackgroundUrl = $loginBackground ? '../' . $loginBackground : '../assets/img/generic/14.jpg';

$maintenanceMode = SiteSettingsService::get($pdo, 'maintenance_mode') === '1';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $loginRateLimitKey = 'login:' . RateLimiterService::clientIp() . ':' . strtolower($email);

        if (RateLimiterService::tooManyAttempts($pdo, $loginRateLimitKey, 5, 900)) {
            $errors[] = 'Too many failed login attempts. Please wait 15 minutes and try again.';
        } else {
            // Validation
            if (empty($email)) {
                $errors[] = 'Email is required';
            }
            if (empty($password)) {
                $errors[] = 'Password is required';
            }

            if (empty($errors)) {
                // Find user
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && verifyPassword($password, $user['password'])) {
                    RateLimiterService::clearAttempts($pdo, $loginRateLimitKey);

                    // Check if verified
                    if (!$user['is_verified']) {
                        $errors[] = 'Please verify your email address first. <a href="resend-verification?email=' . urlencode($email) . '">Resend verification email</a>';
                    } elseif ($maintenanceMode && ($user['role'] ?? 'user') !== 'admin') {
                        $errors[] = 'The site is currently under maintenance. Please try again later.';
                    } else {
                        // Login successful - regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['first_name'];
                        $_SESSION['user_role'] = $user['role'] ?? 'user';

                        // Update last login
                        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

                        // Handle remember me with secure cookie
                        if ($remember) {
                            $token = generateToken();
                            $expires = date('Y-m-d H:i:s', time() + REMEMBER_ME_EXPIRY);

                            $pdo->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)")
                                ->execute([$user['id'], $token, $expires]);

                            setcookie('remember_token', $token, [
                                'expires' => time() + REMEMBER_ME_EXPIRY,
                                'path' => '/',
                                'domain' => '',
                                'secure' => true,
                                'httponly' => true,
                                'samesite' => 'Strict'
                            ]);
                        }

                        // Redirect
                        $redirectTo = $_SESSION['redirect_after_login'] ?? APP_URL . '/';
                        unset($_SESSION['redirect_after_login']);

                        setFlashMessage('success', 'Welcome back, ' . $user['first_name'] . '!');
                        redirect($redirectTo);
                    }
                } else {
                    RateLimiterService::recordAttempt($pdo, $loginRateLimitKey);
                    $errors[] = 'Invalid email or password';
                }
            }
        }
    }
}

// Check for remember me cookie
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $pdo->prepare("
        SELECT u.* FROM users u
        JOIN remember_tokens rt ON u.id = rt.user_id
        WHERE rt.token = ? AND rt.expires_at > NOW() AND u.is_active = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    
    if ($user && !($maintenanceMode && ($user['role'] ?? 'user') !== 'admin')) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'];
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        redirect(APP_URL . '/');
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
    <title>Login - <?php echo APP_NAME; ?></title>

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
            <div class="bg-holder" style="background-image:url(<?php echo htmlspecialchars($loginBackgroundUrl); ?>);background-position: 50% 20%;">
            </div>
            <!--/.bg-holder-->

          </div>
          <div class="col-sm-10 col-md-6 px-sm-0 align-self-center mx-auto py-5">
            <div class="row justify-content-center g-0">
              <div class="col-lg-9 col-xl-8 col-xxl-6">
                <div class="card">
                  <div class="card-header bg-circle-shape bg-shape text-center p-2"><a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="../index" data-bs-theme="light"><?php echo APP_NAME; ?></a></div>
                  <div class="card-body p-4">
                    <div class="row flex-between-center">
                      <div class="col-auto">
                        <h3>Login</h3>
                      </div>
                      <div class="col-auto fs-10 text-600"><span class="mb-0 fw-semi-bold">New User?</span> <span><a href="../auth/register">Create account</a></span></div>
                    </div>
                    <?php if ($maintenanceMode): ?>
                      <div class="alert alert-warning">
                          <i class="fas fa-tools"></i>
                          <span>The site is currently under maintenance. Only admin accounts can sign in right now.</span>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                      <div class="alert alert-danger">
                          <i class="fas fa-exclamation-circle"></i>
                          <div>
                              <?php foreach ($errors as $error): ?>
                              <p class="mb-0"><?php echo $error; ?></p>
                              <?php endforeach; ?>
                          </div>
                      </div>
                    <?php endif; ?>
            
                    <?php 
                    $flash = getFlashMessage();
                    if ($flash): 
                    ?>
                    <div class="alert alert-<?php echo $flash['type']; ?>">
                        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'info-circle'; ?>"></i>
                        <span><?php echo $flash['message']; ?></span>
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="">
                      <?php echo csrfField(); ?>
                      <div class="mb-3">
                        <label class="form-label" for="split-login-email">Email address</label>
                        <input class="form-control" id="split-login-email" type="email" name="email" value="<?php echo $email; ?>" placeholder="you@example.com" required autofocus />
                      </div>
                      <div class="mb-3">
                        <div class="d-flex justify-content-between">
                          <label class="form-label" for="split-login-password">Password</label>
                        </div>
                        <input class="form-control" id="split-login-password" type="password" name="password" placeholder="••••••••" required />
                      </div>
                      <div class="row flex-between-center">
                        <div class="col-auto">
                          <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="remember" id="split-checkbox" />
                            <label class="form-check-label mb-0" for="split-checkbox">Remember me</label>
                          </div>
                        </div>
                        <div class="col-auto"><a class="fs-10" href="forgot-password">Forgot Password?</a></div>
                      </div>
                      <div class="mb-3">
                        <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Log in</button>
                      </div>
                    </form>
                    <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''): ?>
                    <div class="position-relative mt-4">
                      <hr />
                      <div class="divider-content-center">or sign in with</div>
                    </div>
                    <div class="row g-2 mt-2">
                      <div class="col-12"><a class="btn btn-outline-google-plus btn-sm d-block w-100" href="google-login"><span class="fab fa-google-plus-g me-2" data-fa-transform="grow-8"></span> Google</a></div>
                    </div>
                    <?php endif; ?>
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
<?php
/**
 * Registration Page
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\EmailService;
use App\Services\SiteSettingsService;
use App\Services\EmailVerificationService;
use App\Services\RateLimiterService;

// Redirect if already logged in
if (AuthMiddleware::isLoggedIn()) {
    redirect(APP_URL . '/');
}

$pdo = Database::getInstance()->getConnection();

$maintenanceMode = SiteSettingsService::get($pdo, 'maintenance_mode') === '1';
$registrationsEnabled = SiteSettingsService::get($pdo, 'registrations_enabled') !== '0';
$registrationBlocked = $maintenanceMode || !$registrationsEnabled;
$registrationBlockedMessage = $maintenanceMode
    ? 'The site is currently under maintenance. New accounts cannot be created right now — please check back later.'
    : 'New registrations are currently closed. Please check back later.';

$registerBackground = SiteSettingsService::get($pdo, 'register_background');
$registerBackgroundUrl = $registerBackground ? '../' . $registerBackground : '../assets/img/generic/19.jpg';

$errors = [];
$formData = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => ''
];

// Handle registration form submission
$registerRateLimitKey = 'register:' . RateLimiterService::clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$registrationBlocked) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid security token. Please try again.';
    } elseif (RateLimiterService::tooManyAttempts($pdo, $registerRateLimitKey, 5, 3600)) {
        $errors[] = 'Too many registration attempts from this connection. Please try again in a while.';
    } else {
        RateLimiterService::recordAttempt($pdo, $registerRateLimitKey);

        $formData = [
            'first_name' => sanitize($_POST['first_name'] ?? ''),
            'last_name' => sanitize($_POST['last_name'] ?? ''),
            'email' => sanitize($_POST['email'] ?? ''),
            'phone' => sanitize($_POST['phone'] ?? '')
        ];
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($formData['first_name'])) {
            $errors[] = 'First name is required';
        }

        if (empty($formData['last_name'])) {
            $errors[] = 'Last name is required';
        }

        if (empty($formData['email'])) {
            $errors[] = 'Email is required';
        } elseif (!isValidEmail($formData['email'])) {
            $errors[] = 'Please enter a valid email address';
        } else {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$formData['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'An account with this email already exists. <a href="login">Sign in instead?</a>';
            }
        }
        
        if (empty($password)) {
            $errors[] = 'Password is required';
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters';
        }
        
        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        }
        
        // Create account
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, password, first_name, last_name, phone, is_verified)
                    VALUES (?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([
                    $formData['email'],
                    hashPassword($password),
                    $formData['first_name'],
                    $formData['last_name'],
                    $formData['phone'],
                ]);
                $newUserId = (int) $pdo->lastInsertId();

                // Issue an expiring verification token (see EmailVerificationService)
                $verificationToken = EmailVerificationService::issue($pdo, $newUserId);

                // Send verification email
                $emailService = new EmailService($pdo);
                $emailSent = $emailService->sendWelcomeEmail($formData['email'], $verificationToken, $formData['first_name']);
                
                if ($emailSent) {
                    setFlashMessage('success', 'Account created! Please check your email to verify your account.');
                } else {
                    setFlashMessage('success', 'Account created! Please contact support if you don\'t receive a verification email.');
                }
                
                redirect('login');
                
            } catch (PDOException $e) {
                $errors[] = 'An error occurred. Please try again.';
                error_log("Registration error: " . $e->getMessage());
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
    <title>Create Account - <?php echo APP_NAME; ?></title>


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
            <div class="bg-holder" style="background-image:url(<?php echo htmlspecialchars($registerBackgroundUrl); ?>);">
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
                        <h3>Register</h3>
                      </div>
                      <div class="col-auto fs-10 text-600"><span class="mb-0 fw-semi-bold">Already User?</span> <span><a href="../auth/login">Login</a></span></div>
                    </div>
                    <?php if ($registrationBlocked): ?>
                      <div class="alert alert-warning">
                          <i class="fas fa-tools"></i>
                          <span><?php echo $registrationBlockedMessage; ?></span>
                      </div>
                      <a class="btn btn-primary d-block w-100" href="../auth/login">Back to Login</a>
                    <?php else: ?>
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
                    <form method="POST" action="">
                      <?php echo csrfField(); ?>
                      <div class="mb-3">
                        <label class="form-label" for="split-name">First Name</label>
                        <input class="form-control" type="text" autocomplete="on" id="split-name" name="first_name" value="<?php echo $formData['first_name']; ?>" required/>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="split-name">Last Name</label>
                        <input class="form-control" type="text" autocomplete="on" id="split-name" name="last_name" value="<?php echo $formData['last_name']; ?>" required/>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="split-email">Email address</label>
                        <input class="form-control" type="email" autocomplete="on" id="split-email" name="email" value="<?php echo $formData['email']; ?>" placeholder="you@example.com" required/>
                      </div>
                      <div class="mb-3">
                        <label class="form-label" for="split-phone">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo $formData['phone']; ?>" placeholder="+1 234 567 8900"/>
                      </div>
                      <div class="row gx-2">
                        <div class="mb-3 col-sm-6">
                          <label class="form-label" for="split-password">Password</label>
                          <input class="form-control" type="password" autocomplete="on" id="split-password" name="password" placeholder="••••••••" required minlength="<?php echo MIN_PASSWORD_LENGTH; ?>" />
                          <p class="mb-0 fs-10 text-white opacity-75">
                        <i class="fas fa-info-circle"></i> Minimum <?php echo MIN_PASSWORD_LENGTH; ?> characters
                    </p>
                        </div>
                        <div class="mb-3 col-sm-6">
                          <label class="form-label" for="split-confirm-password">Confirm Password</label>
                          <input class="form-control" type="password" autocomplete="on" id="split-confirm-password" name="confirm_password" placeholder="••••••••" required />
                        </div>
                      </div>
                      <!-- <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="cover-register-checkbox" />
                        <label class="form-label" for="cover-register-checkbox">I accept the <a href="#!">terms </a>and <a class="white-space-nowrap" href="#!">privacy policy</a></label>
                      </div>
 -->                      <div class="mb-3">
                        <button class="btn btn-primary d-block w-100 mt-3" type="submit" name="submit">Register</button>
                      </div>
                    </form>
                    <?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''): ?>
                    <div class="position-relative mt-4">
                      <hr />
                      <div class="divider-content-center">or register with</div>
                    </div>
                    <div class="row g-2 mt-2">
                      <div class="col-12"><a class="btn btn-outline-google-plus btn-sm d-block w-100" href="google-login"><span class="fab fa-google-plus-g me-2" data-fa-transform="grow-8"></span> Google</a></div>
                    </div>
                    <?php endif; ?>
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
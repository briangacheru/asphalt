<?php
/**
 * Email Verification
 *
 * The link sent by EmailService::sendWelcomeEmail() points here with a
 * ?token= query param. This is the page that actually flips is_verified —
 * without it, no account could ever pass login.php's verification check.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Database\Database;
use App\Services\EmailVerificationService;

$pdo = Database::getInstance()->getConnection();

$token = $_GET['token'] ?? '';
$result = EmailVerificationService::verify($pdo, $token);

$success = $result['status'] === 'success';
$alreadyVerified = $result['status'] === 'already_verified';
$expired = $result['status'] === 'expired';
$resendEmail = $result['email'] ?? '';
?>
<!DOCTYPE html>
<html data-bs-theme="light" lang="en-US" dir="ltr">

  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Verify Email - <?php echo APP_NAME; ?></title>

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
            <div class="bg-holder" style="background-image:url(../assets/img/generic/19.jpg);"></div>
          </div>
          <div class="col-sm-10 col-md-6 px-sm-0 align-self-center mx-auto py-5">
            <div class="row justify-content-center g-0">
              <div class="col-lg-9 col-xl-8 col-xxl-6">
                <div class="card">
                  <div class="card-header bg-circle-shape bg-shape text-center p-2"><a class="font-sans-serif fw-bolder fs-5 z-1 position-relative link-light" href="../index" data-bs-theme="light"><?php echo APP_NAME; ?></a></div>
                  <div class="card-body p-4">
                    <div class="text-center">
                        <?php if ($success): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="fas fa-check-circle fs-4"></i>
                                <h4 class="alert-heading fw-semi-bold">Email Verified!</h4>
                                <span>Your account is now active. You can sign in below.</span>
                            </div>
                        <?php elseif ($alreadyVerified): ?>
                            <div class="alert alert-info" role="alert">
                                <i class="fas fa-info-circle fs-4"></i>
                                <h4 class="alert-heading fw-semi-bold">Already Verified</h4>
                                <span>This account was already verified. You can sign in below.</span>
                            </div>
                        <?php elseif ($expired): ?>
                            <div class="alert alert-warning" role="alert">
                                <i class="fas fa-clock fs-4"></i>
                                <h4 class="alert-heading fw-semi-bold">Link Expired</h4>
                                <span class="fs-10">This verification link has expired. Request a fresh one below.</span>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="fas fa-exclamation-circle fs-4"></i>
                                <h4 class="alert-heading fw-semi-bold">Invalid Link</h4>
                                <span class="fs-10">This verification link isn't valid. It may have already been used.</span>
                            </div>
                        <?php endif; ?>

                        <?php if ($expired && $resendEmail !== ''): ?>
                            <a class="btn btn-primary btn-sm mt-3" href="resend-verification?email=<?php echo urlencode($resendEmail); ?>">
                                <i class="fas fa-paper-plane me-1"></i>Send a New Verification Link
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-<?php echo $expired ? 'outline-secondary' : 'primary'; ?> btn-sm mt-3" href="login">
                            <span class="fas fa-chevron-left me-1" data-fa-transform="shrink-4 down-1"></span>Go to Login
                        </a>
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

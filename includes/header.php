<?php
require_once __DIR__ . '/bootstrap.php';

\App\Middleware\AuthMiddleware::check();
$pdo = \App\Database\Database::getInstance()->getConnection();

$currentUser = getCurrentUser();
$userId = \App\Middleware\AuthMiddleware::getCurrentUserId();

// Get all active vehicles for current user
$vehiclesStmt = $pdo->prepare("SELECT id, make, model, year, current_mileage FROM vehicles WHERE user_id = ? AND is_active = 1 ORDER BY make, model");
$vehiclesStmt->execute([$userId]);
$sidebarVehicles = $vehiclesStmt->fetchAll();

// Get upcoming service alerts count for current user
try {
    $alertsStmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM vehicles v
        JOIN service_records sr ON v.id = sr.vehicle_id
        WHERE v.user_id = ? AND v.is_active = 1 
        AND sr.id = (SELECT MAX(id) FROM service_records WHERE vehicle_id = v.id)
        AND (sr.next_service_mileage - v.current_mileage) <= 1000
    ");
    $alertsStmt->execute([$userId]);
    $alertsCount = $alertsStmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    $alertsCount = 0;
}

// Current page for active nav
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
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
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    <!-- ===============================================-->
    <!--    Favicons-->
    <!-- ===============================================-->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/favicons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicons/favicon-16x16.png">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicons/favicon.ico">
    <link rel="manifest" href="assets/img/favicons/manifest.json">
    <meta name="msapplication-TileImage" content="assets/img/favicons/mstile-150x150.png">
    <meta name="theme-color" content="#ffffff">
    <script src="assets/js/config.js"></script>
    <script src="vendors/simplebar/simplebar.min.js"></script>
    <script src="vendors/datatables.net-bs5/dataTables.bootstrap5.min.css"></script>
    <script src="vendors/select2/select2.min.css"></script>
    <script src="vendors/select2-bootstrap-5-theme/select2-bootstrap-5-theme.min.css"></script>



      <!-- ===============================================-->
    <!--    Stylesheets-->
    <!-- ===============================================-->
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700%7cPoppins:300,400,500,600,700,800,900&amp;display=swap" rel="stylesheet">
    <link href="vendors/simplebar/simplebar.min.css" rel="stylesheet">
    <link href="assets/css/theme-rtl.css" rel="stylesheet" id="style-rtl">
    <link href="assets/css/theme.css" rel="stylesheet" id="style-default">
    <link href="assets/css/user-rtl.css" rel="stylesheet" id="user-style-rtl">
    <link href="assets/css/user.css" rel="stylesheet" id="user-style-default">
    <link href="vendors/flatpickr/flatpickr.min.css" rel="stylesheet" />
    <link href="vendors/dropzone/dropzone.css" rel="stylesheet" />
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
      <div class="container" data-layout="container">
        <script>
          var isFluid = JSON.parse(localStorage.getItem('isFluid'));
          if (isFluid) {
            var container = document.querySelector('[data-layout]');
            container.classList.remove('container');
            container.classList.add('container-fluid');
          }
        </script>
        <nav class="navbar navbar-light navbar-vertical navbar-expand-xl">
          <script>
            var navbarStyle = localStorage.getItem("navbarStyle");
            if (navbarStyle && navbarStyle !== 'transparent') {
              document.querySelector('.navbar-vertical').classList.add(`navbar-${navbarStyle}`);
            }
          </script>
          <div class="d-flex align-items-center">
            <div class="toggle-icon-wrapper">

              <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-toggle="tooltip" data-bs-placement="left" title="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>

            </div><a class="navbar-brand" href="index">
              <div class="d-flex align-items-center py-3"><img class="me-2" src="assets/img/icons/spot-illustrations/falcon.png" alt="" width="40" /><span class="font-sans-serif text-primary">myCar</span>
              </div>
            </a>
          </div>
          <div class="collapse navbar-collapse" id="navbarVerticalCollapse">
            <div class="navbar-vertical-content scrollbar">
              <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">
                <li class="nav-item">
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'index' ? 'active' : ''; ?>" href="index" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-chart-pie"></span></span><span class="nav-link-text ps-1">Dashboard</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'vehicles' ? 'active' : ''; ?>" href="vehicles" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-car"></span></span><span class="nav-link-text ps-1">My Vehicles</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'add-service' ? 'active' : ''; ?>" href="add-service" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-wrench"></span></span><span class="nav-link-text ps-1">Add Service</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'service-history' ? 'active' : ''; ?>" href="service-history" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-history"></span></span><span class="nav-link-text ps-1">Service History</span>
                    </div>
                  </a>
                </li>
                <li class="nav-item">
                  <!-- label-->
                  <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">QUICK ACTIONS
                    </div>
                    <div class="col ps-0">
                      <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                  </div>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'update-mileage' ? 'active' : ''; ?>" href="update-mileage" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-tachometer-alt"></span></span><span class="nav-link-text ps-1">Update Mileage</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'fuel-log' ? 'active' : ''; ?>" href="fuel-log" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-gas-pump"></span></span><span class="nav-link-text ps-1">Fuel Log</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'expenses' ? 'active' : ''; ?>" href="expenses" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-receipt"></span></span><span class="nav-link-text ps-1">Expenses</span>
                    </div>
                  </a>
                  
                </li>
                <li class="nav-item">
                  <!-- label-->
                  <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">ALERTS
                    </div>
                    <div class="col ps-0">
                      <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                  </div>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'service-reminders' ? 'active' : ''; ?>" href="service-reminders" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-bell"></span></span><span class="nav-link-text ps-1">Service Reminders</span>
                        <?php if ($alertsCount > 0): ?>
                            <span class="badge rounded-pill ms-2 badge-subtle-danger"><?php echo $alertsCount; ?></span>
                        <?php endif; ?>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'maintenance-schedule' ? 'active' : ''; ?>" href="maintenance-schedule" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-calendar-alt"></span></span><span class="nav-link-text ps-1">Maintenance Schedule</span>
                    </div>
                  </a>
                  
                </li>
                <li class="nav-item">
                  <!-- label-->
                  <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                    <div class="col-auto navbar-vertical-label">REPORTS
                    </div>
                    <div class="col ps-0">
                      <hr class="mb-0 navbar-vertical-divider" />
                    </div>
                  </div>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'vehicles' ? 'reports' : ''; ?>" href="reports" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-chart-bar"></span></span><span class="nav-link-text ps-1">Reports</span>
                    </div>
                  </a>
                  <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'email-history' ? 'active' : ''; ?>" href="email-history" role="button">
                    <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-envelope"></span></span><span class="nav-link-text ps-1">Email History</span>
                    </div>
                  </a>
                  
                </li>
                  <!-- My Vehicles Quick Access -->
                <li class="nav-item">
                      <!-- label-->
                      <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                          <div class="col-auto navbar-vertical-label">Quick Vehicle Access
                          </div>
                          <div class="col ps-0">
                              <hr class="mb-0 navbar-vertical-divider" />
                          </div>
                      </div>
                    <?php foreach (array_slice($sidebarVehicles, 0, 5) as $vehicle): ?>
                      <!-- parent pages--><a href="vehicle-details?id=<?php echo $vehicle['id']; ?>" class="nav-link" role="button">
                          <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-car-side"></span></span>
                              <span class="nav-link-text ps-1"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?></span>
                          </div>
                      </a>
                    <?php endforeach; ?>
                  </li>
                <li class="nav-item">
                      <!-- label-->
                      <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                          <div class="col-auto navbar-vertical-label">SETTINGS
                          </div>
                          <div class="col ps-0">
                              <hr class="mb-0 navbar-vertical-divider" />
                          </div>
                      </div>
                      <!-- parent pages--><a class="nav-link <?php echo $currentPage === 'settings' ? 'reports' : ''; ?>" href="settings" role="button">
                          <div class="d-flex align-items-center"><span class="nav-link-icon"><span class="fas fa-cog"></span></span><span class="nav-link-text ps-1">Settings</span>
                          </div>
                      </a>

                  </li>
              </ul>
            </div>
          </div>
        </nav>
        <div class="content">
          <nav class="navbar navbar-light navbar-glass navbar-top navbar-expand">

            <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarVerticalCollapse" aria-controls="navbarVerticalCollapse" aria-expanded="false" aria-label="Toggle Navigation"><span class="navbar-toggle-icon"><span class="toggle-line"></span></span></button>
            <a class="navbar-brand me-1 me-sm-3" href="index">
              <div class="d-flex align-items-center"><img class="me-2" src="assets/img/icons/spot-illustrations/falcon.png" alt="" width="40" /><span class="font-sans-serif text-primary">myCar</span>
              </div>
            </a>
            <ul class="navbar-nav align-items-center d-none d-lg-block">
              <li class="nav-item">
                <div class="search-box" data-list='{"valueNames":["title"]}'>
                  <form class="position-relative" data-bs-toggle="search" data-bs-display="static">
                    <input class="form-control search-input fuzzy-search" type="search" placeholder="Search..." aria-label="Search" />
                    <span class="fas fa-search search-box-icon"></span>

                  </form>
                  <div class="btn-close-falcon-container position-absolute end-0 top-50 translate-middle shadow-none" data-bs-dismiss="search">
                    <button class="btn btn-link btn-close-falcon p-0" aria-label="Close"></button>
                  </div>
                  <div class="dropdown-menu border font-base start-0 mt-2 py-0 overflow-hidden w-100">
                    <div class="scrollbar list py-3" style="max-height: 24rem;">
                      <h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">Recently Browsed</h6><a class="dropdown-item fs-10 px-x1 py-1 hover-primary" href="app/events/event-detail.html">
                        <!--<div class="d-flex align-items-center">
                          <span class="fas fa-circle me-2 text-300 fs-11"></span>

                          <div class="fw-normal title">Pages <span class="fas fa-chevron-right mx-1 text-500 fs-11" data-fa-transform="shrink-2"></span> Events</div>
                        </div>-->
                      </a>
                      <!--<a class="dropdown-item fs-10 px-x1 py-1 hover-primary" href="app/e-commerce/customers.html">
                        <div class="d-flex align-items-center">
                          <span class="fas fa-circle me-2 text-300 fs-11"></span>

                          <div class="fw-normal title">E-commerce <span class="fas fa-chevron-right mx-1 text-500 fs-11" data-fa-transform="shrink-2"></span> Customers</div>
                        </div>
                      </a>-->

                    </div>
                    <div class="text-center mt-n3">
                      <p class="fallback fw-bold fs-8 d-none">No Result Found.</p>
                    </div>
                  </div>
                </div>
              </li>
            </ul>
            <ul class="navbar-nav navbar-nav-icons ms-auto flex-row align-items-center">
              <li class="nav-item ps-2 pe-0">
                <div class="dropdown theme-control-dropdown"><a class="nav-link d-flex align-items-center dropdown-toggle fa-icon-wait fs-9 pe-1 py-0" href="#" role="button" id="themeSwitchDropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="fas fa-sun fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="light"></span><span class="fas fa-moon fs-7" data-fa-transform="shrink-3" data-theme-dropdown-toggle-icon="dark"></span><span class="fas fa-adjust fs-7" data-fa-transform="shrink-2" data-theme-dropdown-toggle-icon="auto"></span></a>
                  <div class="dropdown-menu dropdown-menu-end dropdown-caret border py-0 mt-3" aria-labelledby="themeSwitchDropdown">
                    <div class="bg-white dark__bg-1000 rounded-2 py-2">
                      <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="light" data-theme-control="theme"><span class="fas fa-sun"></span>Light<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                      <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="dark" data-theme-control="theme"><span class="fas fa-moon" data-fa-transform=""></span>Dark<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                      <button class="dropdown-item d-flex align-items-center gap-2" type="button" value="auto" data-theme-control="theme"><span class="fas fa-adjust" data-fa-transform=""></span>Auto<span class="fas fa-check dropdown-check-icon ms-auto text-600"></span></button>
                    </div>
                  </div>
                </div>
              </li>
              <!--<li class="nav-item d-none d-sm-block">
                <a class="nav-link px-0 notification-indicator notification-indicator-warning notification-indicator-fill fa-icon-wait" href="app/e-commerce/shopping-cart.html"><span class="fas fa-shopping-cart" data-fa-transform="shrink-7" style="font-size: 33px;"></span><span class="notification-indicator-number">1</span></a>

              </li>-->
              <!--<li class="nav-item dropdown">
                <a class="nav-link notification-indicator notification-indicator-primary px-0 fa-icon-wait" id="navbarDropdownNotification" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" data-hide-on-body-scroll="data-hide-on-body-scroll"><span class="fas fa-bell" data-fa-transform="shrink-6" style="font-size: 33px;"></span></a>
                <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end dropdown-menu-card dropdown-menu-notification dropdown-caret-bg" aria-labelledby="navbarDropdownNotification">
                  <div class="card card-notification shadow-none">
                    <div class="card-header">
                      <div class="row justify-content-between align-items-center">
                        <div class="col-auto">
                          <h6 class="card-header-title mb-0">Notifications</h6>
                        </div>
                        <div class="col-auto ps-0 ps-sm-3"><a class="card-link fw-normal" href="#">Mark all as read</a></div>
                      </div>
                    </div>
                    <div class="scrollbar-overlay" style="max-height:19rem">
                      <div class="list-group list-group-flush fw-normal fs-10">
                        <div class="list-group-title border-bottom">NEW</div>
                        <div class="list-group-item">
                          <a class="notification notification-flush notification-unread" href="#!">
                            <div class="notification-avatar">
                              <div class="avatar avatar-2xl me-3">
                                <img class="rounded-circle" src="assets/img/team/1-thumb.png" alt="" />

                              </div>
                            </div>
                            <div class="notification-body">
                              <p class="mb-1"><strong>Emma Watson</strong> replied to your comment : "Hello world 😍"</p>
                              <span class="notification-time"><span class="me-2" role="img" aria-label="Emoji">💬</span>Just now</span>

                            </div>
                          </a>

                        </div>
                        <div class="list-group-item">
                          <a class="notification notification-flush notification-unread" href="#!">
                            <div class="notification-avatar">
                              <div class="avatar avatar-2xl me-3">
                                <div class="avatar-name rounded-circle"><span>AB</span></div>
                              </div>
                            </div>
                            <div class="notification-body">
                              <p class="mb-1"><strong>Albert Brooks</strong> reacted to <strong>Mia Khalifa's</strong> status</p>
                              <span class="notification-time"><span class="me-2 fab fa-gratipay text-danger"></span>9hr</span>

                            </div>
                          </a>
                        </div>
                      </div>
                    </div>
                    <div class="card-footer text-center border-top"><a class="card-link d-block" href="app/social/notifications.html">View all</a></div>
                  </div>
                </div>

              </li>-->
              <li class="nav-item dropdown">
                  <a class="nav-link pe-0 ps-2" id="navbarDropdownUser" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                      <div class="avatar avatar-xl">
                          <img class="rounded-circle"
                               src="<?php echo !empty($currentUser['avatar']) ? htmlspecialchars($currentUser['avatar']) : 'assets/img/avatars/avatar-1.png'; ?>"
                               alt="<?php echo htmlspecialchars($currentUser['first_name']); ?>" />
                      </div>
                </a>
                <div class="dropdown-menu dropdown-caret dropdown-caret dropdown-menu-end py-0" aria-labelledby="navbarDropdownUser">
                  <div class="bg-white dark__bg-1000 rounded-2 py-2">
                    <a class="dropdown-item fw-bold text-warning" href="#!"><span class="fas fa-crown me-1"></span><span><?php echo htmlspecialchars($currentUser['first_name']); ?></span></a>

                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="#!">Set status</a>
                    <a class="dropdown-item" href="#!">Feedback</a>

                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="settings">Settings</a>
                    <a class="dropdown-item" href="auth/logout">Logout</a>
                  </div>
                </div>
              </li>
            </ul>
          </nav>


          <!-- ===============================================-->
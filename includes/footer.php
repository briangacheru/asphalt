<footer class="footer">
    <div class="row g-0 justify-content-between fs-10 mt-4 mb-3">
        <div class="col-12 col-sm-auto text-center">
            <p class="mb-0 text-600">Created by Brian with <i class="fa fa-heart" style="color: red;"></i> <span class="d-none d-sm-inline-block">| </span><br class="d-sm-none" /> 2024 - <?php echo date('Y'); ?> &copy</p>
        </div>
        <div class="col-12 col-sm-auto text-center">
            <p class="mb-0 text-600">v3.20.0</p>
        </div>
    </div>
</footer>

<?php
// Site-wide quick-add floating action button — lets a signed-in user log a
// fuel fill-up, expense, or mileage reading from any page without navigating
// there first. Hidden on auth pages since those never include this footer.
$quickAddPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
if (!in_array($quickAddPage, ['login', 'register', 'forgot-password', 'reset-password'], true)):
?>
<div class="quick-add-fab" id="quickAddFab">
    <div class="quick-add-fab-menu" id="quickAddFabMenu">
        <a href="update-mileage" class="quick-add-fab-item" title="Update Mileage">
            <span class="fas fa-tachometer-alt"></span><span class="quick-add-fab-label">Update Mileage</span>
        </a>
        <a href="expenses?quickadd=1" class="quick-add-fab-item" title="Add Expense">
            <span class="fas fa-receipt"></span><span class="quick-add-fab-label">Add Expense</span>
        </a>
        <a href="fuel-log?quickadd=1" class="quick-add-fab-item" title="Add Fuel">
            <span class="fas fa-gas-pump"></span><span class="quick-add-fab-label">Add Fuel</span>
        </a>
    </div>
    <button type="button" class="quick-add-fab-toggle" id="quickAddFabToggle" aria-label="Quick add" aria-expanded="false">
        <span class="fas fa-plus"></span>
    </button>
</div>
<style>
    .quick-add-fab { position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 1030; display: flex; flex-direction: column; align-items: flex-end; }
    .quick-add-fab-toggle { width: 3.25rem; height: 3.25rem; border-radius: 50%; border: none; background: var(--falcon-primary, #2a7be4); color: #fff; font-size: 1.25rem; box-shadow: 0 0.5rem 1rem rgba(0,0,0,.25); display: flex; align-items: center; justify-content: center; transition: transform .2s ease; }
    .quick-add-fab-toggle:hover { transform: scale(1.05); }
    .quick-add-fab.is-open .quick-add-fab-toggle { transform: rotate(45deg); }
    .quick-add-fab-menu { display: flex; flex-direction: column; align-items: flex-end; gap: .6rem; margin-bottom: .75rem; opacity: 0; pointer-events: none; transform: translateY(.5rem); transition: opacity .15s ease, transform .15s ease; }
    .quick-add-fab.is-open .quick-add-fab-menu { opacity: 1; pointer-events: auto; transform: translateY(0); }
    .quick-add-fab-item { display: flex; align-items: center; gap: .5rem; background: var(--falcon-card-bg, #fff); color: var(--falcon-body-color, #333); border-radius: 2rem; padding: .5rem 1rem .5rem .5rem; box-shadow: 0 0.25rem 0.75rem rgba(0,0,0,.2); text-decoration: none; font-size: .8rem; white-space: nowrap; }
    .quick-add-fab-item span.fas { width: 2rem; height: 2rem; border-radius: 50%; background: var(--falcon-primary, #2a7be4); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .8rem; }
    @media (max-width: 576px) { .quick-add-fab { right: 1rem; bottom: 1rem; } }
</style>
<script>
    (function () {
        var fab = document.getElementById('quickAddFab');
        var toggle = document.getElementById('quickAddFabToggle');
        if (!fab || !toggle) return;
        toggle.addEventListener('click', function () {
            var isOpen = fab.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!fab.contains(e.target)) {
                fab.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    })();
</script>

<script>
    // Service worker registration + a small push-subscription helper used by
    // the Push Notifications card in Settings. No-ops gracefully wherever
    // the browser lacks support or VAPID isn't configured server-side.
    window.iVehiclePush = (function () {
        var VAPID_PUBLIC_KEY = <?php echo json_encode(\App\Services\PushService::isConfigured() ? VAPID_PUBLIC_KEY : ''); ?>;
        var CSRF_TOKEN = <?php echo json_encode(generateCSRFToken()); ?>;

        function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);
            for (var i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        function isSupported() {
            return 'serviceWorker' in navigator && 'PushManager' in window && !!VAPID_PUBLIC_KEY;
        }

        function getRegistration() {
            return navigator.serviceWorker.register('sw.js');
        }

        function status() {
            if (!isSupported()) return Promise.resolve('unsupported');
            return getRegistration().then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                return sub ? 'subscribed' : 'unsubscribed';
            });
        }

        function subscribe() {
            return getRegistration().then(function (reg) {
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                });
            }).then(function (sub) {
                var json = sub.toJSON();
                json.csrf_token = CSRF_TOKEN;
                return fetch('push-subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(json)
                }).then(function (r) { return r.json(); });
            });
        }

        function unsubscribe() {
            return getRegistration().then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                if (!sub) return { success: true };
                var endpoint = sub.endpoint;
                return sub.unsubscribe().then(function () {
                    return fetch('push-unsubscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: endpoint, csrf_token: CSRF_TOKEN })
                    }).then(function (r) { return r.json(); });
                });
            });
        }

        if ('serviceWorker' in navigator) {
            // Register early (installability), independent of push permission.
            navigator.serviceWorker.register('sw.js').catch(function () {});
        }

        return { isSupported: isSupported, status: status, subscribe: subscribe, unsubscribe: unsubscribe };
    })();
</script>
<?php endif; ?>

<div class="modal fade" id="feedbackModal" tabindex="-1" aria-labelledby="feedbackModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="feedbackForm">
        <div class="modal-header">
          <h5 class="modal-title" id="feedbackModalLabel">Send Feedback</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="feedbackFormAlert" class="alert d-none" role="alert"></div>
          <div class="mb-3">
            <label class="form-label">Type</label>
            <select name="category" class="form-select">
              <option value="general">General feedback</option>
              <option value="idea">Feature idea</option>
              <option value="bug">Something's broken</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Message <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control" rows="4" required minlength="5" maxlength="4000" placeholder="What's on your mind?"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="feedbackSubmitBtn">Send</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
    (function () {
        var form = document.getElementById('feedbackForm');
        if (!form) return;
        var alertBox = document.getElementById('feedbackFormAlert');
        var submitBtn = document.getElementById('feedbackSubmitBtn');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending…';

            var data = new URLSearchParams();
            data.set('csrf_token', <?php echo json_encode(generateCSRFToken()); ?>);
            data.set('category', form.category.value);
            data.set('message', form.message.value);
            data.set('page_url', window.location.pathname + window.location.search);

            fetch('feedback-submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    alertBox.classList.remove('d-none', 'alert-success', 'alert-danger');
                    alertBox.classList.add(json.success ? 'alert-success' : 'alert-danger');
                    alertBox.textContent = json.message;
                    if (json.success) {
                        form.reset();
                        setTimeout(function () {
                            var modalEl = document.getElementById('feedbackModal');
                            var instance = bootstrap.Modal.getInstance(modalEl);
                            if (instance) instance.hide();
                            alertBox.classList.add('d-none');
                        }, 1200);
                    }
                })
                .catch(function () {
                    alertBox.classList.remove('d-none', 'alert-success');
                    alertBox.classList.add('alert-danger');
                    alertBox.textContent = 'Network error — please try again.';
                })
                .finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Send';
                });
        });
    })();
</script>

</div>
</div>
</main>
    <!-- ===============================================-->
    <!--    End of Main Content-->
    <!-- ===============================================-->


    <div class="offcanvas offcanvas-end settings-panel border-0" id="settings-offcanvas" tabindex="-1" aria-labelledby="settings-offcanvas">
      <div class="offcanvas-header settings-panel-header bg-shape">
        <div class="z-1 py-1">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <h5 class="text-white mb-0 me-2"><span class="fas fa-palette me-2 fs-9"></span>Settings</h5>
            <button class="btn btn-primary btn-sm rounded-pill mt-0 mb-0" data-theme-control="reset" style="font-size:12px"> <span class="fas fa-redo-alt me-1" data-fa-transform="shrink-3"></span>Reset</button>
          </div>
          <p class="mb-0 fs-10 text-white opacity-75"> Set your own customized style</p>
        </div>
        <div class="z-1" data-bs-theme="dark">
          <button class="btn-close z-1 mt-0" type="button" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
      </div>
      <div class="offcanvas-body scrollbar-overlay px-x1 h-100" id="themeController">
        <h5 class="fs-9">Color Scheme</h5>
        <p class="fs-10">Choose the perfect color mode for your app.</p>
        <div class="btn-group d-block w-100 btn-group-navbar-style">
          <div class="row gx-2">
            <div class="col-4">
              <input class="btn-check" id="themeSwitcherLight" name="theme-color" type="radio" value="light" data-theme-control="theme" />
              <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherLight"> <span class="hover-overlay mb-2 rounded d-block"><img class="img-fluid img-prototype mb-0" src="assets/img/generic/falcon-mode-default.jpg" alt=""/></span><span class="label-text">Light</span></label>
            </div>
            <div class="col-4">
              <input class="btn-check" id="themeSwitcherDark" name="theme-color" type="radio" value="dark" data-theme-control="theme" />
              <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherDark"> <span class="hover-overlay mb-2 rounded d-block"><img class="img-fluid img-prototype mb-0" src="assets/img/generic/falcon-mode-dark.jpg" alt=""/></span><span class="label-text"> Dark</span></label>
            </div>
            <div class="col-4">
              <input class="btn-check" id="themeSwitcherAuto" name="theme-color" type="radio" value="auto" data-theme-control="theme" />
              <label class="btn d-inline-block btn-navbar-style fs-10" for="themeSwitcherAuto"> <span class="hover-overlay mb-2 rounded d-block"><img class="img-fluid img-prototype mb-0" src="assets/img/generic/falcon-mode-auto.jpg" alt=""/></span><span class="label-text"> Auto</span></label>
            </div>
          </div>
        </div>
        <hr />
        <div class="d-flex justify-content-between">
          <div class="d-flex align-items-start"><img class="me-2" src="assets/img/icons/arrows-h.svg" width="20" alt="" />
            <div class="flex-1">
              <h5 class="fs-9">Fluid Layout</h5>
            </div>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input ms-0" id="mode-fluid" type="checkbox" data-theme-control="isFluid" />
          </div>
        </div>
        <hr />
        <h5 class="fs-9 d-flex align-items-center">Vertical Navbar Style</h5>
        <p class="fs-10 mb-0">Switch between styles for your vertical navbar </p>
        <div class="btn-group d-block w-100 btn-group-navbar-style">
          <div class="row gx-2">
            <div class="col-6">
              <input class="btn-check" id="navbar-style-transparent" type="radio" name="navbarStyle" value="transparent" data-theme-control="navbarStyle" />
              <label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-transparent"> <img class="img-fluid img-prototype" src="assets/img/generic/default.png" alt="" /><span class="label-text"> Transparent</span></label>
            </div>
            <div class="col-6">
              <input class="btn-check" id="navbar-style-inverted" type="radio" name="navbarStyle" value="inverted" data-theme-control="navbarStyle" />
              <label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-inverted"> <img class="img-fluid img-prototype" src="assets/img/generic/inverted.png" alt="" /><span class="label-text"> Inverted</span></label>
            </div>
            <div class="col-6">
              <input class="btn-check" id="navbar-style-card" type="radio" name="navbarStyle" value="card" data-theme-control="navbarStyle" />
              <label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-card"> <img class="img-fluid img-prototype" src="assets/img/generic/card.png" alt="" /><span class="label-text"> Card</span></label>
            </div>
            <div class="col-6">
              <input class="btn-check" id="navbar-style-vibrant" type="radio" name="navbarStyle" value="vibrant" data-theme-control="navbarStyle" />
              <label class="btn d-block w-100 btn-navbar-style fs-10" for="navbar-style-vibrant"> <img class="img-fluid img-prototype" src="assets/img/generic/vibrant.png" alt="" /><span class="label-text"> Vibrant</span></label>
            </div>
          </div>
        </div>
      </div>
    </div><a class="card setting-toggle" href="#settings-offcanvas" data-bs-toggle="offcanvas">
      <div class="card-body d-flex align-items-center py-md-2 px-2 py-1">
        <div class="bg-primary-subtle position-relative rounded-start" style="height:34px;width:28px">
          <div class="settings-popover"><span class="ripple"><span class="fa-spin position-absolute all-0 d-flex flex-center"><span class="icon-spin position-absolute all-0 d-flex flex-center">
                  <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M19.7369 12.3941L19.1989 12.1065C18.4459 11.7041 18.0843 10.8487 18.0843 9.99495C18.0843 9.14118 18.4459 8.28582 19.1989 7.88336L19.7369 7.59581C19.9474 7.47484 20.0316 7.23291 19.9474 7.03131C19.4842 5.57973 18.6843 4.28943 17.6738 3.20075C17.5053 3.03946 17.2527 2.99914 17.0422 3.12011L16.393 3.46714C15.6883 3.84379 14.8377 3.74529 14.1476 3.3427C14.0988 3.31422 14.0496 3.28621 14.0002 3.25868C13.2568 2.84453 12.7055 2.10629 12.7055 1.25525V0.70081C12.7055 0.499202 12.5371 0.297594 12.2845 0.257272C10.7266 -0.105622 9.16879 -0.0653007 7.69516 0.257272C7.44254 0.297594 7.31623 0.499202 7.31623 0.70081V1.23474C7.31623 2.09575 6.74999 2.8362 5.99824 3.25599C5.95774 3.27861 5.91747 3.30159 5.87744 3.32493C5.15643 3.74527 4.26453 3.85902 3.53534 3.45302L2.93743 3.12011C2.72691 2.99914 2.47429 3.03946 2.30587 3.20075C1.29538 4.28943 0.495411 5.57973 0.0322686 7.03131C-0.051939 7.23291 0.0322686 7.47484 0.242788 7.59581L0.784376 7.8853C1.54166 8.29007 1.92694 9.13627 1.92694 9.99495C1.92694 10.8536 1.54166 11.6998 0.784375 12.1046L0.242788 12.3941C0.0322686 12.515 -0.051939 12.757 0.0322686 12.9586C0.495411 14.4102 1.29538 15.7005 2.30587 16.7891C2.47429 16.9504 2.72691 16.9907 2.93743 16.8698L3.58669 16.5227C4.29133 16.1461 5.14131 16.2457 5.8331 16.6455C5.88713 16.6767 5.94159 16.7074 5.99648 16.7375C6.75162 17.1511 7.31623 17.8941 7.31623 18.7552V19.2891C7.31623 19.4425 7.41373 19.5959 7.55309 19.696C7.64066 19.7589 7.74815 19.7843 7.85406 19.8046C9.35884 20.0925 10.8609 20.0456 12.2845 19.7729C12.5371 19.6923 12.7055 19.4907 12.7055 19.2891V18.7346C12.7055 17.8836 13.2568 17.1454 14.0002 16.7312C14.0496 16.7037 14.0988 16.6757 14.1476 16.6472C14.8377 16.2446 15.6883 16.1461 16.393 16.5227L17.0422 16.8698C17.2527 16.9907 17.5053 16.9504 17.6738 16.7891C18.7264 15.7005 19.4842 14.4102 19.9895 12.9586C20.0316 12.757 19.9474 12.515 19.7369 12.3941ZM10.0109 13.2005C8.1162 13.2005 6.64257 11.7893 6.64257 9.97478C6.64257 8.20063 8.1162 6.74905 10.0109 6.74905C11.8634 6.74905 13.3792 8.20063 13.3792 9.97478C13.3792 11.7893 11.8634 13.2005 10.0109 13.2005Z" fill="#2A7BE4"></path>
                  </svg></span></span></span></div>
        </div><small class="text-uppercase text-primary fw-bold bg-primary-subtle py-2 pe-2 ps-1 rounded-end">customize</small>
      </div>
    </a>


    <!-- ===============================================-->
    <!--    JavaScripts-->
    <!-- ===============================================-->
    <script src="vendors/popper/popper.min.js"></script>
    <script src="vendors/bootstrap/bootstrap.min.js"></script>
    <script src="vendors/anchorjs/anchor.min.js"></script>
    <script src="vendors/is/is.min.js"></script>
    <script src="vendors/echarts/echarts.min.js"></script>
    <script src="vendors/fontawesome/all.min.js"></script>
    <script src="vendors/lodash/lodash.min.js"></script>

    <script src="vendors/list.js/list.min.js"></script>
    <script src="assets/js/theme.js"></script>
    <script src="assets/js/main.js"></script>
    <script src="vendors/jquery/jquery.min.js"></script>
    <script src="vendors/datatables.net/jquery.dataTables.min.js"></script>
    <script src="vendors/datatables.net-bs5/dataTables.bootstrap5.min.js"></script>
    <script src="vendors/datatables.net-fixedcolumns/dataTables.fixedColumns.min.js"></script>
    <script src="vendors/select2/select2.min.js"></script>
    <script src="vendors/select2/select2.full.min.js"></script>
    <script src="vendors/flatpickr/flatpickr.min.js"></script>
    <script src="vendors/tinymce/tinymce.min.js"></script>
    <script src="vendors/dropzone/dropzone-min.js"></script>
    <script src="vendors/glightbox/glightbox.min.js"></script>
    <?php if (isset($extraScripts)) echo $extraScripts; ?>

  </body>

</html>

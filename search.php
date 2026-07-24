<?php
require_once __DIR__ . '/includes/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Database\Database;
use App\Services\SearchService;

AuthMiddleware::check();

$q = trim($_GET['q'] ?? '');
$isAjax = ($_GET['ajax'] ?? '') === '1';

if ($isAjax) {
    $pdo = Database::getInstance()->getConnection();
    $userId = AuthMiddleware::getCurrentUserId();
    $results = $q !== '' ? SearchService::search($pdo, $userId, $q, 5) : [];

    header('Content-Type: application/json');
    echo json_encode(['query' => $q, 'results' => $results]);
    exit;
}

$pageTitle = 'Search Results';
require_once 'includes/header.php';

$results = $q !== '' ? SearchService::search($pdo, $userId, $q, 25) : [];
$totalResults = array_sum(array_map(fn($group) => count($group['items']), $results));
?>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <h1 class="h3 mb-1">Search Results</h1>
                <?php if ($q !== ''): ?>
                    <p class="text-muted"><?php echo $totalResults; ?> result<?php echo $totalResults === 1 ? '' : 's'; ?> for "<?php echo sanitize($q); ?>"</p>
                <?php else: ?>
                    <p class="text-muted">Enter a search term to look across your vehicles, expenses, service reminders, reports, and email history.</p>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" action="search" class="mb-4" role="search">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="Search vehicles, expenses, service, reports, emails..." value="<?php echo sanitize($q); ?>" minlength="2" autofocus>
                <button class="btn btn-primary" type="submit">Search</button>
            </div>
        </form>

        <?php if ($q !== '' && $totalResults === 0): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-search fa-4x text-muted mb-3"></i>
                    <h3 class="h5">No Results Found</h3>
                    <p class="text-muted">We couldn't find anything matching "<?php echo sanitize($q); ?>".</p>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($results as $group): ?>
            <?php if (empty($group['items'])) continue; ?>
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo sanitize($group['label']); ?> <span class="badge bg-secondary ms-1"><?php echo count($group['items']); ?></span></h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($group['items'] as $item): ?>
                        <a href="<?php echo htmlspecialchars($item['url']); ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                            <span class="fas <?php echo sanitize($item['icon']); ?> text-primary me-3 fs-5"></span>
                            <div>
                                <div class="fw-semi-bold"><?php echo sanitize($item['title']); ?></div>
                                <?php if (!empty($item['subtitle'])): ?>
                                    <div class="text-muted small"><?php echo sanitize($item['subtitle']); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php require_once 'includes/footer.php'; ?>

<?php
$pageTitle = 'Documents & Photos';
require_once 'includes/header.php';

use App\Helpers\IdCodec;

$vehicleId = IdCodec::decode($_GET['vehicle_id'] ?? null) ?? 0;

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$vehicleId, $userId]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    setFlashMessage('danger', 'Vehicle not found.');
    redirect('vehicles');
}

// Badge colors supported by the theme's badge-subtle-* classes
$allowedCategoryColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

// Document categories: user-manageable, shared across all users (same as expense_categories)
function loadDocumentCategories(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, slug, label, icon, color FROM vehicle_document_categories ORDER BY label")->fetchAll();
    $categories = [];
    foreach ($rows as $row) {
        $categories[$row['slug']] = [
            'id'    => $row['id'],
            'label' => $row['label'],
            'icon'  => $row['icon'],
            'color' => $row['color'],
        ];
    }
    return $categories;
}

function slugifyCategory(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    return trim($slug, '_') ?: 'category';
}

$documentCategories = loadDocumentCategories($pdo);

// Server-side MIME detection — never trust the browser-supplied $_FILES[...]['type']
$mimeToExt = [
    'image/jpeg'      => 'jpg',
    'image/pjpeg'     => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heif',
    'image/bmp'       => 'bmp',
    'image/tiff'      => 'tiff',
    'application/pdf' => 'pdf',
];
$acceptedFormatsLabel = 'JPG, PNG, GIF, WEBP, HEIC, HEIF, BMP, TIFF, PDF';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('danger', 'Invalid request. Please try again.');
        redirect('vehicle-documents?vehicle_id=' . IdCodec::encode($vehicleId));
    }

    if ($action === 'upload') {
        $category = $_POST['category'] ?? '';
        if (!array_key_exists($category, $documentCategories)) {
            $category = array_key_first($documentCategories) ?? 'other';
        }
        $title = sanitize($_POST['title'] ?? '');

        $file = $_FILES['document'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            setFlashMessage('danger', 'Please choose a file to upload.');
        } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
            setFlashMessage('danger', 'File too large. Maximum size: ' . (int)(MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.');
        } else {
            $detectedMime = mime_content_type($file['tmp_name']);

            if (!array_key_exists($detectedMime, $mimeToExt)) {
                setFlashMessage('danger', 'Unsupported file type. Allowed: ' . $acceptedFormatsLabel . '.');
            } else {
                $uploadDir = UPLOAD_DIR . 'documents/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = $mimeToExt[$detectedMime];
                $filename = 'doc_' . time() . '_' . uniqid() . '.' . $ext;

                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO vehicle_documents (vehicle_id, category, title, file_name, file_path, file_type, file_size)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $vehicleId, $category, $title ?: null,
                        $file['name'], $filename, $detectedMime, $file['size'],
                    ]);
                    setFlashMessage('success', 'Document uploaded successfully!');
                } else {
                    setFlashMessage('danger', 'Failed to upload document.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $documentId = (int)($_POST['document_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE id = ? AND vehicle_id = ?");
        $stmt->execute([$documentId, $vehicleId]);
        $document = $stmt->fetch();

        if (!$document) {
            setFlashMessage('danger', 'Document not found.');
        } else {
            $expectedName = $document['title'] ?: $document['file_name'];
            $enteredName = trim($_POST['confirm_name'] ?? '');

            if ($enteredName !== $expectedName) {
                setFlashMessage('danger', 'Document name did not match. Document not deleted.');
            } else {
                $filePath = UPLOAD_DIR . 'documents/' . $document['file_path'];
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
                $pdo->prepare("DELETE FROM vehicle_documents WHERE id = ?")->execute([$documentId]);
                setFlashMessage('success', 'Document deleted.');
            }
        }
    } elseif ($action === 'add_category') {
        $label = sanitize($_POST['label'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-file';
        $color = $_POST['color'] ?? 'dark';
        if (!in_array($color, $allowedCategoryColors, true)) {
            $color = 'dark';
        }
        // Icon must be a bare Font Awesome class name (e.g. "fa-shield-alt"), never raw markup
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-file';

        if ($label === '') {
            setFlashMessage('danger', 'Category name is required.');
        } else {
            $slug = slugifyCategory($label);
            $existsStmt = $pdo->prepare("SELECT id FROM vehicle_document_categories WHERE slug = ?");
            $existsStmt->execute([$slug]);
            $suffix = 2;
            $baseSlug = $slug;
            while ($existsStmt->fetch()) {
                $slug = $baseSlug . '_' . $suffix++;
                $existsStmt->execute([$slug]);
            }

            $pdo->prepare("INSERT INTO vehicle_document_categories (slug, label, icon, color) VALUES (?, ?, ?, ?)")
                ->execute([$slug, $label, $icon, $color]);
            setFlashMessage('success', 'Category added successfully!');
        }
    } elseif ($action === 'edit_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $label = sanitize($_POST['label'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-file';
        $color = $_POST['color'] ?? 'dark';
        if (!in_array($color, $allowedCategoryColors, true)) {
            $color = 'dark';
        }
        $icon = preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-file';

        if ($categoryId && $label !== '') {
            $pdo->prepare("UPDATE vehicle_document_categories SET label = ?, icon = ?, color = ? WHERE id = ?")
                ->execute([$label, $icon, $color, $categoryId]);
            setFlashMessage('success', 'Category updated successfully!');
        } else {
            setFlashMessage('danger', 'Category name is required.');
        }
    } elseif ($action === 'delete_category') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $slugStmt = $pdo->prepare("SELECT slug FROM vehicle_document_categories WHERE id = ?");
        $slugStmt->execute([$categoryId]);
        $slugRow = $slugStmt->fetch();

        if (!$slugRow) {
            setFlashMessage('danger', 'Category not found.');
        } else {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vehicle_documents WHERE category = ?");
            $countStmt->execute([$slugRow['slug']]);
            $inUseCount = (int) $countStmt->fetchColumn();
            $totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM vehicle_document_categories")->fetchColumn();

            if ($inUseCount > 0) {
                setFlashMessage('danger', "Can't delete: {$inUseCount} document(s) still use this category.");
            } elseif ($totalCategories <= 1) {
                setFlashMessage('danger', "Can't delete the last remaining category.");
            } else {
                $pdo->prepare("DELETE FROM vehicle_document_categories WHERE id = ?")->execute([$categoryId]);
                setFlashMessage('success', 'Category deleted.');
            }
        }
    }

    redirect('vehicle-documents?vehicle_id=' . IdCodec::encode($vehicleId));
}

$stmt = $pdo->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ? ORDER BY uploaded_at DESC, id DESC");
$stmt->execute([$vehicleId]);
$documents = $stmt->fetchAll();
?>

<div class="card mb-3">
    <div class="card-body">
        <div class="row justify-content-between align-items-center">
            <div class="col-md">
                <div class="d-flex">
                    <div class="calendar me-2"><span class="calendar-month">
                                <?php
                                $currentMonth = date('M');
                                $currentDay = date('d');
                                echo $currentMonth; ?>
                        </span><span class="calendar-day"><?php echo $currentDay; ?> </span></div>
                    <div class="flex-1">
                        <h4 class="fs-6"><i class="fas fa-images me-1"></i> Documents & Photos</h4>
                        <p class="mb-0 fs-10 text-1000"><?php echo sanitize($vehicle['make'] . ' ' . $vehicle['model']); ?> (<?php echo $vehicle['year']; ?>)</p>
                    </div>
                </div>
            </div>
            <div class="col-md-auto mt-4 mt-md-0">
                <a href="vehicle-details?id=<?php echo IdCodec::encode($vehicleId); ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Vehicle
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manage-categories-modal">
                    <i class="fas fa-tags"></i> Manage Categories
                </button>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#upload-document-modal">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$flash = getFlashMessage();
if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
        <span><?php echo $flash['message']; ?></span>
        <button class="btn-close" type="button" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (empty($documents)): ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-images empty-state-icon fs-3 text-300 mb-3"></i>
                <h6 class="fs-9 mb-1">No documents yet!</h6>
                <p class="fs-10 mb-3">Upload insurance papers, bill of lading, receipts, and other files for this vehicle.</p>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#upload-document-modal">
                    <i class="fas fa-upload"></i> Upload Document
                </button>
            </div>
        <?php else: ?>
            <div class="btn-group flex-wrap mb-3" role="group" id="category-filter">
                <button type="button" class="btn btn-sm btn-outline-primary active" data-filter="all">All</button>
                <?php foreach ($documentCategories as $key => $cat): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-filter="<?php echo sanitize($key); ?>">
                        <i class="fas <?php echo sanitize($cat['icon']); ?> me-1"></i><?php echo sanitize($cat['label']); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="row g-3">
                <?php
                $fallbackCategory = ['label' => 'Uncategorized', 'icon' => 'fa-file', 'color' => 'dark'];
                foreach ($documents as $doc):
                    $cat = $documentCategories[$doc['category']] ?? $fallbackCategory;
                    $isImage = str_starts_with($doc['file_type'], 'image/');
                    $fileUrl = 'uploads/documents/' . rawurlencode($doc['file_path']);
                    $displayTitle = $doc['title'] ?: $doc['file_name'];
                ?>
                    <div class="col-6 col-sm-4 col-md-3 col-lg-2 doc-tile" data-category="<?php echo sanitize($doc['category']); ?>">
                        <div class="card h-100 border-0 shadow-sm hover-lift position-relative">
                            <button type="button"
                                    class="btn btn-sm btn-danger rounded-circle p-0 position-absolute top-0 end-0 m-1 z-1"
                                    style="width:22px;height:22px;line-height:1;"
                                    title="Delete"
                                    onclick="confirmDeleteDocument('<?php echo (int) $doc['id']; ?>', <?php echo json_encode($displayTitle, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)">
                                <i class="fas fa-times fs-11"></i>
                            </button>
                            <?php if ($isImage): ?>
                                <a href="<?php echo $fileUrl; ?>" class="glightbox" data-gallery="vehicle-gallery" data-title="<?php echo sanitize($displayTitle); ?>">
                                    <img src="<?php echo $fileUrl; ?>" class="card-img-top" style="height:110px; object-fit:cover;" alt="<?php echo sanitize($displayTitle); ?>">
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn p-0 border-0 w-100 pdf-tile" data-pdf-src="<?php echo $fileUrl; ?>" data-pdf-title="<?php echo sanitize($displayTitle); ?>">
                                    <div class="d-flex align-items-center justify-content-center bg-body-tertiary" style="height:110px;">
                                        <i class="fas fa-file-pdf fs-1 text-danger"></i>
                                    </div>
                                </button>
                            <?php endif; ?>
                            <div class="card-body p-2">
                                <span class="badge badge-subtle-<?php echo sanitize($cat['color']); ?> fs-11 mb-1"><?php echo sanitize($cat['label']); ?></span>
                                <p class="fs-11 text-700 mb-0 text-truncate" title="<?php echo sanitize($displayTitle); ?>"><?php echo sanitize($displayTitle); ?></p>
                                <p class="fs-11 text-muted mb-0"><?php echo formatDate($doc['uploaded_at']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="upload-document-modal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadDocumentModalLabel">Upload Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="upload">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="category" class="form-select" required>
                            <?php foreach ($documentCategories as $key => $cat): ?>
                                <option value="<?php echo sanitize($key); ?>"><?php echo sanitize($cat['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Title (optional)</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Comprehensive Cover 2026">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" name="document" class="form-control" accept="image/*,.heic,.heif,application/pdf" required>
                        <small class="text-muted">Accepted: <?php echo $acceptedFormatsLabel; ?> (max <?php echo (int)(MAX_UPLOAD_SIZE / 1024 / 1024); ?>MB)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- PDF Viewer Modal (renders via the browser's native PDF viewer) -->
<div class="modal fade" id="pdf-viewer-modal" tabindex="-1" aria-labelledby="pdfViewerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pdfViewerModalLabel">Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="pdf-viewer-frame" src="" style="width:100%; height:80vh; border:0;" title="PDF document"></iframe>
            </div>
            <div class="modal-footer">
                <a id="pdf-viewer-download" href="#" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt me-1"></i>Open in New Tab
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Document Modal -->
<div class="modal fade" id="delete-document-modal" tabindex="-1" aria-labelledby="deleteDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteDocumentModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Delete Document
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="delete-document-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="document_id" id="delete-document-id">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action cannot be undone.
                    </div>

                    <p class="mb-2">To confirm deletion, type the document name below:</p>

                    <div class="card bg-light mb-3">
                        <div class="card-body py-2 d-flex align-items-center justify-content-between">
                            <strong id="delete-document-name-display"></strong>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="copy-document-name-btn" title="Copy document name">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <input type="text"
                           class="form-control"
                           name="confirm_name"
                           id="delete-document-name-input"
                           placeholder="Enter document name"
                           required
                           autocomplete="off">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash"></i> Delete Document
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Categories Modal -->
<div class="modal fade" id="manage-categories-modal" tabindex="-1" aria-labelledby="manageCategoriesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manageCategoriesModalLabel"><i class="fas fa-tags me-1"></i>Manage Categories</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive mb-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documentCategories as $cat): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-subtle-<?php echo sanitize($cat['color']); ?>">
                                        <i class="fas <?php echo sanitize($cat['icon']); ?> me-1"></i><?php echo sanitize($cat['label']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-secondary category-edit-btn"
                                            data-id="<?php echo (int) $cat['id']; ?>"
                                            data-label="<?php echo sanitize($cat['label']); ?>"
                                            data-icon="<?php echo sanitize($cat['icon']); ?>"
                                            data-color="<?php echo sanitize($cat['color']); ?>"
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category? It must not be in use by any document.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?php echo (int) $cat['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <hr>

                <h6 class="mb-3" id="category-form-heading"><i class="fas fa-plus-circle me-1"></i>Add New Category</h6>
                <form method="POST" id="category-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_category" id="category-form-action">
                    <input type="hidden" name="category_id" id="category-form-id" value="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="category-form-label" class="form-control" placeholder="e.g. Warranty" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Color</label>
                            <select name="color" id="category-form-color" class="form-select">
                                <?php foreach ($allowedCategoryColors as $color): ?>
                                    <option value="<?php echo $color; ?>"><?php echo ucfirst($color); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Icon</label>
                            <div class="input-group">
                                <span class="input-group-text" id="category-icon-preview"><i class="fas fa-file"></i></span>
                                <input type="text" name="icon" id="category-form-icon" class="form-control" value="fa-file" placeholder="e.g. fa-shield-alt">
                            </div>
                            <small class="text-muted">Font Awesome solid icon class — pick one below, or browse more at fontawesome.com/icons</small>
                            <div class="d-flex flex-wrap gap-1 mt-2" id="icon-quick-pick">
                                <?php foreach ([
                                    'fa-shield-alt', 'fa-ship', 'fa-gas-pump', 'fa-id-card', 'fa-clipboard-check',
                                    'fa-file', 'fa-file-invoice', 'fa-receipt', 'fa-car', 'fa-wrench',
                                    'fa-key', 'fa-passport', 'fa-credit-card', 'fa-camera', 'fa-folder', 'fa-file-contract',
                                ] as $iconOption): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary icon-pick-btn" data-icon="<?php echo $iconOption; ?>" title="<?php echo $iconOption; ?>">
                                        <i class="fas <?php echo $iconOption; ?>"></i>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm" id="category-form-submit"><i class="fas fa-plus me-1"></i>Add Category</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="category-form-cancel">Cancel</button>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Image previews use GLightbox
        if (window.GLightbox) {
            GLightbox({ selector: '.glightbox' });
        }

        // PDFs open in a modal using the browser's own PDF viewer (no PDF.js needed)
        var pdfModalEl = document.getElementById('pdf-viewer-modal');
        var pdfFrame = document.getElementById('pdf-viewer-frame');
        var pdfTitle = document.getElementById('pdfViewerModalLabel');
        var pdfDownload = document.getElementById('pdf-viewer-download');

        document.querySelectorAll('.pdf-tile').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var src = this.dataset.pdfSrc;
                pdfFrame.src = src;
                pdfTitle.textContent = this.dataset.pdfTitle || 'Document';
                pdfDownload.href = src;
                new bootstrap.Modal(pdfModalEl).show();
            });
        });

        // Stop rendering the PDF once the modal closes
        pdfModalEl.addEventListener('hidden.bs.modal', function () {
            pdfFrame.src = '';
        });

        // Category filter
        document.querySelectorAll('#category-filter button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('#category-filter button').forEach(function (b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                var filter = this.dataset.filter;
                document.querySelectorAll('.doc-tile').forEach(function (tile) {
                    tile.style.display = (filter === 'all' || tile.dataset.category === filter) ? '' : 'none';
                });
            });
        });

        // Delete document: copy-to-clipboard helper for the typed-name confirmation
        var deleteDocForm = document.getElementById('delete-document-form');
        var docNameInput = document.getElementById('delete-document-name-input');
        var copyDocBtn = document.getElementById('copy-document-name-btn');

        if (copyDocBtn) {
            copyDocBtn.addEventListener('click', function () {
                var expected = deleteDocForm.dataset.expectedName || '';
                navigator.clipboard.writeText(expected).then(function () {
                    docNameInput.value = expected;

                    var icon = copyDocBtn.querySelector('i');
                    icon.classList.remove('fa-copy');
                    icon.classList.add('fa-check');
                    copyDocBtn.classList.add('btn-success');
                    copyDocBtn.classList.remove('btn-outline-secondary');

                    setTimeout(function () {
                        icon.classList.remove('fa-check');
                        icon.classList.add('fa-copy');
                        copyDocBtn.classList.remove('btn-success');
                        copyDocBtn.classList.add('btn-outline-secondary');
                    }, 2000);
                }).catch(function () {
                    docNameInput.value = expected;
                    alert('Document name pasted into field');
                });
            });
        }

        if (deleteDocForm) {
            deleteDocForm.addEventListener('submit', function (e) {
                var expected = deleteDocForm.dataset.expectedName || '';
                var entered = docNameInput.value.trim();

                if (entered !== expected) {
                    e.preventDefault();
                    alert('Document name does not match. Please enter the exact name to delete this document.');
                    docNameInput.focus();
                    return false;
                }
            });
        }

        // Manage Categories: live icon preview
        var iconInput = document.getElementById('category-form-icon');
        var iconPreview = document.getElementById('category-icon-preview');

        function updateIconPreview() {
            var iconClass = (iconInput.value || 'fa-file').trim();
            iconPreview.innerHTML = '<i class="fas ' + iconClass.replace(/[^a-z0-9-]/g, '') + '"></i>';
        }
        if (iconInput) {
            iconInput.addEventListener('input', updateIconPreview);
        }

        document.querySelectorAll('.icon-pick-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                iconInput.value = this.dataset.icon;
                updateIconPreview();
            });
        });

        // Manage Categories: switch the shared form into "edit" mode
        var categoryForm = document.getElementById('category-form');
        var categoryFormAction = document.getElementById('category-form-action');
        var categoryFormId = document.getElementById('category-form-id');
        var categoryFormLabel = document.getElementById('category-form-label');
        var categoryFormColor = document.getElementById('category-form-color');
        var categoryFormHeading = document.getElementById('category-form-heading');
        var categoryFormSubmit = document.getElementById('category-form-submit');
        var categoryFormCancel = document.getElementById('category-form-cancel');

        function resetCategoryForm() {
            categoryForm.reset();
            categoryFormAction.value = 'add_category';
            categoryFormId.value = '';
            iconInput.value = 'fa-file';
            updateIconPreview();
            categoryFormHeading.innerHTML = '<i class="fas fa-plus-circle me-1"></i>Add New Category';
            categoryFormSubmit.innerHTML = '<i class="fas fa-plus me-1"></i>Add Category';
            categoryFormCancel.classList.add('d-none');
        }

        document.querySelectorAll('.category-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                categoryFormAction.value = 'edit_category';
                categoryFormId.value = this.dataset.id;
                categoryFormLabel.value = this.dataset.label;
                categoryFormColor.value = this.dataset.color;
                iconInput.value = this.dataset.icon;
                updateIconPreview();
                categoryFormHeading.innerHTML = '<i class="fas fa-edit me-1"></i>Edit Category';
                categoryFormSubmit.innerHTML = '<i class="fas fa-save me-1"></i>Update Category';
                categoryFormCancel.classList.remove('d-none');
                categoryFormLabel.focus();
            });
        });

        if (categoryFormCancel) {
            categoryFormCancel.addEventListener('click', resetCategoryForm);
        }
    });

    // Called via inline onclick from each document tile's delete button
    function confirmDeleteDocument(id, name) {
        document.getElementById('delete-document-id').value = id;
        document.getElementById('delete-document-name-display').textContent = name;
        document.getElementById('delete-document-name-input').value = '';
        document.getElementById('delete-document-form').dataset.expectedName = name;
        new bootstrap.Modal(document.getElementById('delete-document-modal')).show();
    }
</script>

<?php require_once 'includes/footer.php'; ?>

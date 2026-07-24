/**
 * Vehicle Service Tracker - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initAlertClose();
    initFileUpload();
    initConfirmButtons();
    initTopbarSearch();
});


// Alert Close Buttons
function initAlertClose() {
    document.querySelectorAll('.alert-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const alert = this.closest('.alert');
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() {
                    alert.remove();
                }, 300);
            }
        });
    });

    // Auto-hide alerts after 5 seconds (except sticky ones)
    document.querySelectorAll('.alert:not(.alert-sticky)').forEach(function(alert) {
        setTimeout(function() {
            if (alert && alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() {
                    if (alert && alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            }
        }, 5000);
    });
}

// File Upload Preview
function initFileUpload() {
    document.querySelectorAll('.file-upload').forEach(function(upload) {
        const input = upload.querySelector('input[type="file"]');
        const previewContainer = upload.querySelector('.file-preview');
        
        if (input) {
            // Drag and drop
            upload.addEventListener('dragover', function(e) {
                e.preventDefault();
                upload.classList.add('dragover');
            });
            
            upload.addEventListener('dragleave', function() {
                upload.classList.remove('dragover');
            });
            
            upload.addEventListener('drop', function(e) {
                e.preventDefault();
                upload.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    handleFileSelect(input, upload);
                }
            });
            
            // File select
            input.addEventListener('change', function() {
                handleFileSelect(input, upload);
            });
        }
    });
}

function handleFileSelect(input, upload) {
    const file = input.files[0];
    if (!file) return;
    
    let preview = upload.querySelector('.file-preview');
    if (!preview) {
        preview = document.createElement('div');
        preview.className = 'file-preview';
        upload.appendChild(preview);
    }
    
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <img src="${e.target.result}" alt="Preview">
                <div class="file-preview-info">
                    <div class="file-preview-name">${file.name}</div>
                    <div class="file-preview-size">${formatFileSize(file.size)}</div>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = `
            <div class="file-preview-info">
                <div class="file-preview-name">${file.name}</div>
                <div class="file-preview-size">${formatFileSize(file.size)}</div>
            </div>
        `;
    }
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Confirm Buttons
function initConfirmButtons() {
    document.querySelectorAll('[data-confirm]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            const message = this.dataset.confirm || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
}



// Utility Functions
function showLoading(element) {
    element.innerHTML = '<div class="spinner"></div>';
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

// Topbar Search (live dropdown + full results page on submit)
function initTopbarSearch() {
    const input = document.getElementById('topbarSearchInput');
    const resultsBox = document.getElementById('topbarSearchResults');
    const fallback = document.querySelector('.search-box .fallback');
    if (!input || !resultsBox) return;

    const categoryIcons = {
        vehicles: 'fa-car',
        expenses: 'fa-receipt',
        service: 'fa-wrench',
        reports: 'fa-chart-bar',
        emails: 'fa-envelope'
    };

    let debounceTimer = null;
    let activeController = null;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderPlaceholder(message) {
        resultsBox.innerHTML = '<p class="text-center fs-10 text-muted mb-0 px-x1 py-2">' + escapeHtml(message) + '</p>';
        if (fallback) fallback.classList.add('d-none');
    }

    function renderResults(groups, query) {
        const keys = Object.keys(groups || {});
        if (!keys.length) {
            resultsBox.innerHTML = '';
            if (fallback) fallback.classList.remove('d-none');
            return;
        }
        if (fallback) fallback.classList.add('d-none');

        let html = '';
        keys.forEach(function(key) {
            const group = groups[key];
            if (!group.items || !group.items.length) return;
            html += '<h6 class="dropdown-header fw-medium text-uppercase px-x1 fs-11 pt-0 pb-2">' + escapeHtml(group.label) + '</h6>';
            group.items.forEach(function(item) {
                html += '<a class="dropdown-item fs-10 px-x1 py-2 hover-primary" href="' + escapeHtml(item.url) + '">' +
                    '<div class="d-flex align-items-center">' +
                    '<span class="fas ' + escapeHtml(item.icon || categoryIcons[key] || 'fa-circle') + ' me-2 text-500 fs-11"></span>' +
                    '<div class="flex-1 title">' +
                    '<div class="fw-semi-bold">' + escapeHtml(item.title) + '</div>' +
                    (item.subtitle ? '<div class="text-500 fs-11">' + escapeHtml(item.subtitle) + '</div>' : '') +
                    '</div></div></a>';
            });
        });
        html += '<div class="text-center py-2"><a class="fs-10 fw-semi-bold" href="search?q=' + encodeURIComponent(query) + '">View all results <span class="fas fa-chevron-right ms-1" data-fa-transform="shrink-2"></span></a></div>';
        resultsBox.innerHTML = html;
    }

    input.addEventListener('input', function() {
        const q = input.value.trim();
        clearTimeout(debounceTimer);

        if (q.length < 2) {
            renderPlaceholder('Type at least 2 characters to search…');
            return;
        }

        debounceTimer = setTimeout(function() {
            if (activeController) activeController.abort();
            activeController = new AbortController();

            renderPlaceholder('Searching…');

            fetch('search?ajax=1&q=' + encodeURIComponent(q), { signal: activeController.signal })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    renderResults(data.results, q);
                })
                .catch(function(err) {
                    if (err.name !== 'AbortError') {
                        renderPlaceholder('Search failed. Please try again.');
                    }
                });
        }, 300);
    });
}

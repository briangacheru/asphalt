/**
 * Vehicle Service Tracker - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    initAlertClose();
    initFileUpload();
    initConfirmButtons();
    initModals();
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

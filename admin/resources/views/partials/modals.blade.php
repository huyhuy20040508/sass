{{-- System-Wide Global Action & Delete Modals --}}
<style>
    /* Modal backdrop & container styling */
    .sys-modal-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1090;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .sys-modal-backdrop.show {
        opacity: 1;
        visibility: visible;
    }

    .sys-modal-card {
        width: 100%;
        max-width: 440px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(0, 0, 0, 0.05);
        transform: scale(0.95) translateY(10px);
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }

    .sys-modal-backdrop.show .sys-modal-card {
        transform: scale(1) translateY(0);
    }

    .sys-modal-header {
        padding: 24px 24px 12px 24px;
        text-align: center;
    }

    .sys-modal-icon-wrapper {
        width: 56px;
        height: 56px;
        margin: 0 auto 16px auto;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
    }

    /* Icon theme variants */
    .sys-modal-icon-danger {
        background-color: #fef2f2;
        color: #ef4444;
        border: 1px solid #fee2e2;
    }

    .sys-modal-icon-warning {
        background-color: #fffbeb;
        color: #f59e0b;
        border: 1px solid #fef3c7;
    }

    .sys-modal-icon-info {
        background-color: #eff6ff;
        color: #3b82f6;
        border: 1px solid #dbeafe;
    }

    .sys-modal-icon-success {
        background-color: #f0fdf4;
        color: #22c55e;
        border: 1px solid #dcfce7;
    }

    .sys-modal-title {
        margin: 0 0 8px 0;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.3;
    }

    .sys-modal-message {
        margin: 0;
        font-size: 14px;
        color: #64748b;
        line-height: 1.5;
    }

    .sys-modal-item-highlight {
        margin-top: 10px;
        padding: 8px 12px;
        background-color: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        color: #334155;
        word-break: break-all;
    }

    .sys-modal-footer {
        padding: 16px 24px 24px 24px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 12px;
    }

    .sys-modal-btn {
        flex: 1;
        height: 42px;
        padding: 0 16px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.15s ease;
        border: none;
        outline: none;
    }

    .sys-modal-btn-cancel {
        background-color: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .sys-modal-btn-cancel:hover {
        background-color: #e2e8f0;
        color: #1e293b;
    }

    .sys-modal-btn-danger {
        background-color: #ef4444;
        color: #ffffff;
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.25);
    }

    .sys-modal-btn-danger:hover {
        background-color: #dc2626;
        box-shadow: 0 4px 8px rgba(239, 68, 68, 0.35);
    }

    .sys-modal-btn-warning {
        background-color: #f59e0b;
        color: #ffffff;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.25);
    }

    .sys-modal-btn-warning:hover {
        background-color: #d97706;
    }

    .sys-modal-btn-primary {
        background-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 2px 4px rgba(37, 99, 235, 0.25);
    }

    .sys-modal-btn-primary:hover {
        background-color: #1d4ed8;
    }
</style>

<!-- System-Wide Modal DOM -->
<div id="sysConfirmModal" class="sys-modal-backdrop" role="dialog" aria-modal="true" tabindex="-1">
    <div class="sys-modal-card">
        <div class="sys-modal-header">
            <div id="sysModalIconWrapper" class="sys-modal-icon-wrapper sys-modal-icon-danger">
                <i id="sysModalIcon" class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h3 id="sysModalTitle" class="sys-modal-title">Xác nhận thao tác</h3>
            <p id="sysModalMessage" class="sys-modal-message">Bạn có chắc chắn muốn thực hiện thao tác này?</p>
            <div id="sysModalHighlight" class="sys-modal-item-highlight" style="display: none;"></div>
        </div>
        <div class="sys-modal-footer">
            <button type="button" id="sysModalBtnCancel" class="sys-modal-btn sys-modal-btn-cancel">Hủy bỏ</button>
            <button type="button" id="sysModalBtnConfirm" class="sys-modal-btn sys-modal-btn-danger">
                <span id="sysModalConfirmText">Xác nhận</span>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const backdrop = document.getElementById('sysConfirmModal');
    const iconWrapper = document.getElementById('sysModalIconWrapper');
    const icon = document.getElementById('sysModalIcon');
    const titleEl = document.getElementById('sysModalTitle');
    const messageEl = document.getElementById('sysModalMessage');
    const highlightEl = document.getElementById('sysModalHighlight');
    const cancelBtn = document.getElementById('sysModalBtnCancel');
    const confirmBtn = document.getElementById('sysModalBtnConfirm');
    const confirmTextEl = document.getElementById('sysModalConfirmText');

    let currentResolve = null;

    const ICON_MAP = {
        danger: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-circle-fill',
        info: 'bi-info-circle-fill',
        success: 'bi-check-circle-fill',
        delete: 'bi-trash3-fill'
    };

    function showModal(options) {
        return new Promise((resolve) => {
            currentResolve = resolve;

            const type = options.type || (options.isDelete ? 'delete' : 'danger');
            const iconType = options.isDelete ? 'danger' : type;

            // Reset icon styling
            iconWrapper.className = 'sys-modal-icon-wrapper sys-modal-icon-' + (iconType === 'delete' ? 'danger' : iconType);
            icon.className = 'bi ' + (ICON_MAP[type] || ICON_MAP.danger);

            // Text contents
            titleEl.textContent = options.title || (options.isDelete ? 'Xác nhận xoá' : 'Xác nhận thao tác');
            messageEl.textContent = options.message || 'Bạn có chắc chắn muốn tiếp tục?';

            if (options.highlightText) {
                highlightEl.textContent = options.highlightText;
                highlightEl.style.display = 'block';
            } else {
                highlightEl.style.display = 'none';
            }

            // Buttons
            cancelBtn.textContent = options.cancelText || 'Hủy bỏ';
            confirmTextEl.textContent = options.confirmText || (options.isDelete ? 'Xoá ngay' : 'Đồng ý');

            // Button style class
            const btnClass = options.btnClass || (options.isDelete ? 'sys-modal-btn-danger' : ('sys-modal-btn-' + (type === 'delete' ? 'danger' : type)));
            confirmBtn.className = 'sys-modal-btn ' + btnClass;

            // Display modal
            backdrop.classList.add('show');
            cancelBtn.focus();
        });
    }

    function closeModal(result) {
        backdrop.classList.remove('show');
        if (currentResolve) {
            currentResolve(result);
            currentResolve = null;
        }
    }

    cancelBtn.addEventListener('click', function() {
        closeModal(false);
    });

    confirmBtn.addEventListener('click', function() {
        closeModal(true);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && backdrop.classList.contains('show')) {
            closeModal(false);
        }
    });

    // Public System API
    window.sysConfirm = function(options) {
        return showModal(options || {});
    };

    window.sysDelete = function(options) {
        const opts = Object.assign({
            isDelete: true,
            title: 'Xác nhận xoá',
            message: 'Hành động xoá này không thể hoàn tác. Bạn có chắc chắn muốn tiếp tục?',
            confirmText: 'Xoá ngay',
            cancelText: 'Hủy bỏ',
            btnClass: 'sys-modal-btn-danger'
        }, options || {});

        return showModal(opts);
    };

    // Auto Interceptor for forms & links with data attributes
    document.addEventListener('submit', function(e) {
        const form = e.target;

        // Form with data-confirm or data-confirm-delete
        const confirmMsg = form.getAttribute('data-confirm');
        const deleteMsg = form.getAttribute('data-confirm-delete');

        if (!confirmMsg && !deleteMsg) return;

        e.preventDefault();

        if (deleteMsg) {
            const itemName = deleteMsg !== 'true' && deleteMsg !== '1' ? deleteMsg : '';
            window.sysDelete({
                title: 'Xác nhận xoá',
                message: itemName ? `Bạn có chắc chắn muốn xoá "${itemName}"?` : 'Bạn có chắc chắn muốn xoá mục này?',
                highlightText: itemName || null
            }).then(function(confirmed) {
                if (confirmed) {
                    // Remove attribute to avoid loop and submit
                    form.removeAttribute('data-confirm-delete');
                    form.submit();
                }
            });
        } else if (confirmMsg) {
            const parts = confirmMsg.split('|');
            const title = parts[0] || 'Xác nhận thao tác';
            const message = parts[1] || 'Bạn có chắc chắn muốn thực hiện thao tác này?';
            const type = parts[2] || 'primary';

            window.sysConfirm({
                type: type,
                title: title,
                message: message
            }).then(function(confirmed) {
                if (confirmed) {
                    form.removeAttribute('data-confirm');
                    form.submit();
                }
            });
        }
    }, true);

    // Click handler for buttons with data-confirm-submit or links
    document.addEventListener('click', function(e) {
        const target = e.target.closest('[data-confirm-delete], [data-confirm]');
        if (!target) return;

        // Ignore if element is a form (form submit listener will handle it)
        if (target.tagName === 'FORM') return;

        const deleteMsg = target.getAttribute('data-confirm-delete');
        const confirmMsg = target.getAttribute('data-confirm');

        if (deleteMsg !== null) {
            e.preventDefault();
            e.stopPropagation();

            const itemName = deleteMsg && deleteMsg !== 'true' ? deleteMsg : '';
            window.sysDelete({
                title: 'Xác nhận xoá',
                message: itemName ? `Bạn có chắc chắn muốn xoá "${itemName}"?` : 'Bạn có chắc chắn muốn xoá mục này?',
                highlightText: itemName || null
            }).then(function(confirmed) {
                if (confirmed) {
                    const formId = target.getAttribute('data-form');
                    if (formId) {
                        const form = document.getElementById(formId);
                        if (form) form.submit();
                    } else if (target.tagName === 'A' && target.href) {
                        window.location.href = target.href;
                    } else if (target.type === 'submit' && target.form) {
                        target.form.submit();
                    }
                }
            });
        } else if (confirmMsg !== null) {
            e.preventDefault();
            e.stopPropagation();

            const parts = confirmMsg.split('|');
            const title = parts[0] || 'Xác nhận thao tác';
            const message = parts[1] || 'Bạn có chắc chắn muốn tiếp tục?';
            const type = parts[2] || 'warning';

            window.sysConfirm({
                type: type,
                title: title,
                message: message
            }).then(function(confirmed) {
                if (confirmed) {
                    const formId = target.getAttribute('data-form');
                    if (formId) {
                        const form = document.getElementById(formId);
                        if (form) form.submit();
                    } else if (target.tagName === 'A' && target.href) {
                        window.location.href = target.href;
                    } else if (target.type === 'submit' && target.form) {
                        target.form.submit();
                    }
                }
            });
        }
    }, true);

})();
</script>

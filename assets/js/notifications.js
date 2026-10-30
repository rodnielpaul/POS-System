/**
 * Capuzan Kusina Unified Custom Dialogs & Toast Notifications
 * Replaces default browser alert() and confirm() with themed restaurant modals.
 * Self-contained styles ensure 100% reliable rendering without browser cache issues.
 */

(function () {
    // 1. Inject Styles directly to guarantee styling even if external CSS is cached
    function injectStyles() {
        if (document.getElementById('pos-notifications-style')) return;
        const style = document.createElement('style');
        style.id = 'pos-notifications-style';
        style.textContent = `
            .ck-modal-backdrop {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(51, 38, 31, 0.68);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                align-items: center;
                justify-content: center;
                z-index: 999999;
                opacity: 0;
                transition: opacity 0.2s ease;
                box-sizing: border-box;
            }

            .ck-modal-backdrop.show {
                display: flex !important;
                opacity: 1 !important;
            }

            .ck-modal-box {
                background: #FFFDF8;
                border: 1px solid #E6D9C8;
                border-radius: 18px;
                box-shadow: 0 20px 48px rgba(51, 38, 31, 0.38);
                max-width: 420px;
                width: 90%;
                padding: 28px 24px 24px;
                text-align: center;
                transform: scale(0.92) translateY(10px);
                transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                box-sizing: border-box;
                font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
            }

            .ck-modal-backdrop.show .ck-modal-box {
                transform: scale(1) translateY(0);
            }

            .ck-modal-icon-badge {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                margin: 0 auto 16px auto;
            }

            .ck-modal-icon-badge.warning {
                background: #FEF8E8;
                color: #D9A441;
                border: 1.5px solid #D9A441;
            }

            .ck-modal-icon-badge.danger {
                background: #FCECE9;
                color: #B33927;
                border: 1.5px solid #F3C4BE;
            }

            .ck-modal-icon-badge.success {
                background: #EBF0DE;
                color: #6F7D45;
                border: 1.5px solid #C4D2B2;
            }

            .ck-modal-icon-badge.info {
                background: #F3EAE0;
                color: #C96B2C;
                border: 1.5px solid #E6D9C8;
            }

            .ck-modal-title {
                font-size: 1.25rem;
                font-weight: 800;
                color: #4A291C;
                margin: 0 0 10px 0;
                line-height: 1.3;
            }

            .ck-modal-message {
                font-size: 0.92rem;
                color: #6B574C;
                line-height: 1.55;
                margin: 0 0 24px 0;
                word-break: break-word;
            }

            .ck-modal-actions {
                display: flex;
                gap: 12px;
                justify-content: center;
            }

            .ck-modal-btn {
                flex: 1;
                padding: 11px 18px;
                border-radius: 10px;
                font-size: 0.92rem;
                font-weight: 700;
                cursor: pointer;
                border: 1px solid transparent;
                transition: all 0.15s ease;
                font-family: inherit;
            }

            .ck-modal-btn-cancel {
                background: #FFFDF8;
                color: #4A291C;
                border: 1px solid #E6D9C8;
            }
            .ck-modal-btn-cancel:hover {
                background: #F7F0E3;
            }

            .ck-modal-btn-confirm {
                background: #C96B2C;
                color: #FFFDF8;
                border: 1px solid #C96B2C;
            }
            .ck-modal-btn-confirm:hover {
                background: #A94F20;
            }

            .ck-modal-btn-danger {
                background: #B33927;
                color: #FFFDF8;
                border: 1px solid #B33927;
            }
            .ck-modal-btn-danger:hover {
                background: #8A2218;
            }

            /* Toast Notifications Container */
            #ck-toast-container {
                position: fixed;
                top: 24px;
                right: 24px;
                z-index: 1000000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                pointer-events: none;
                font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
            }

            .ck-toast {
                pointer-events: auto;
                background: #FFFDF8;
                border: 1px solid #E6D9C8;
                border-left: 5px solid #C96B2C;
                box-shadow: 0 10px 25px rgba(51, 38, 31, 0.22);
                border-radius: 12px;
                padding: 12px 18px;
                min-width: 270px;
                max-width: 380px;
                display: flex;
                align-items: center;
                gap: 12px;
                transform: translateX(120%);
                opacity: 0;
                transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
                font-size: 0.9rem;
                color: #33261F;
            }

            .ck-toast.show {
                transform: translateX(0);
                opacity: 1;
            }

            .ck-toast-success { border-left-color: #6F7D45; }
            .ck-toast-danger  { border-left-color: #B33927; }
            .ck-toast-warning { border-left-color: #D9A441; }
            .ck-toast-info    { border-left-color: #C96B2C; }

            .ck-toast-icon {
                font-size: 1.25rem;
                flex-shrink: 0;
            }

            .ck-toast-text {
                flex: 1;
                line-height: 1.4;
                font-weight: 600;
            }
        `;
        document.head.appendChild(style);
    }

    // 2. Inject Containers into DOM when loaded
    function injectElements() {
        injectStyles();
        if (document.getElementById('ck-toast-container')) return;

        // Toast Container
        const toastContainer = document.createElement('div');
        toastContainer.id = 'ck-toast-container';
        document.body.appendChild(toastContainer);

        // Custom Alert Modal
        const alertModal = document.createElement('div');
        alertModal.id = 'pos-alert-modal';
        alertModal.className = 'ck-modal-backdrop';
        alertModal.style.display = 'none';
        alertModal.innerHTML = `
            <div class="ck-modal-box">
                <div id="pos-alert-icon" class="ck-modal-icon-badge info">ℹ️</div>
                <h3 id="pos-alert-title" class="ck-modal-title">Notice</h3>
                <p id="pos-alert-message" class="ck-modal-message"></p>
                <div class="ck-modal-actions">
                    <button type="button" id="pos-alert-ok-btn" class="ck-modal-btn ck-modal-btn-confirm">Got It</button>
                </div>
            </div>
        `;
        document.body.appendChild(alertModal);

        // Custom Confirm Modal
        const confirmModal = document.createElement('div');
        confirmModal.id = 'pos-confirm-modal';
        confirmModal.className = 'ck-modal-backdrop';
        confirmModal.style.display = 'none';
        confirmModal.innerHTML = `
            <div class="ck-modal-box">
                <div id="pos-confirm-icon" class="ck-modal-icon-badge warning">⚠️</div>
                <h3 id="pos-confirm-title" class="ck-modal-title">Please Confirm</h3>
                <p id="pos-confirm-message" class="ck-modal-message"></p>
                <div class="ck-modal-actions">
                    <button type="button" id="pos-confirm-cancel-btn" class="ck-modal-btn ck-modal-btn-cancel">Cancel</button>
                    <button type="button" id="pos-confirm-yes-btn" class="ck-modal-btn ck-modal-btn-confirm">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(confirmModal);

        // Event Listeners for Backdrop click
        alertModal.addEventListener('click', (e) => {
            if (e.target === alertModal) closeAlertModal();
        });
        confirmModal.addEventListener('click', (e) => {
            if (e.target === confirmModal) closeConfirmModal();
        });

        document.getElementById('pos-alert-ok-btn').addEventListener('click', closeAlertModal);

        // ESC / Enter key listener
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (alertModal.classList.contains('show')) closeAlertModal();
                if (confirmModal.classList.contains('show')) closeConfirmModal();
            } else if (e.key === 'Enter') {
                if (alertModal.classList.contains('show')) {
                    e.preventDefault();
                    closeAlertModal();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectElements);
    } else {
        injectElements();
    }

    // 3. Alert Modal Logic
    let alertCallback = null;

    function closeAlertModal() {
        const modal = document.getElementById('pos-alert-modal');
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
        if (typeof alertCallback === 'function') {
            const cb = alertCallback;
            alertCallback = null;
            cb();
        }
    }

    window.posAlert = function (message, title = 'Capuzan Kusina Notice', iconType = 'info', callback = null) {
        injectElements();
        const modal = document.getElementById('pos-alert-modal');
        const titleEl = document.getElementById('pos-alert-title');
        const msgEl = document.getElementById('pos-alert-message');
        const iconEl = document.getElementById('pos-alert-icon');
        const okBtn = document.getElementById('pos-alert-ok-btn');

        titleEl.textContent = title;
        msgEl.innerHTML = String(message).replace(/\n/g, '<br>');
        alertCallback = callback;

        // Icon types
        iconEl.className = 'ck-modal-icon-badge ' + iconType;
        if (iconType === 'warning') iconEl.textContent = '⚠️';
        else if (iconType === 'danger') iconEl.textContent = '❌';
        else if (iconType === 'success') iconEl.textContent = '✅';
        else iconEl.textContent = 'ℹ️';

        modal.style.display = 'flex';
        // Force reflow for smooth animation
        modal.offsetHeight;
        modal.classList.add('show');
        setTimeout(() => okBtn.focus(), 50);
    };

    // Override browser default window.alert
    window.alert = function (message) {
        window.posAlert(message, 'Capuzan Kusina Notification', 'warning');
    };

    // 4. Confirm Modal Logic
    let currentConfirmCallback = null;

    function closeConfirmModal() {
        const modal = document.getElementById('pos-confirm-modal');
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
        currentConfirmCallback = null;
    }

    window.posConfirm = function (title, message, onConfirm, options = {}) {
        injectElements();
        const modal = document.getElementById('pos-confirm-modal');
        const titleEl = document.getElementById('pos-confirm-title');
        const msgEl = document.getElementById('pos-confirm-message');
        const iconEl = document.getElementById('pos-confirm-icon');
        const cancelBtn = document.getElementById('pos-confirm-cancel-btn');
        const yesBtn = document.getElementById('pos-confirm-yes-btn');

        const {
            confirmText = 'Yes, Proceed',
            cancelText = 'Cancel',
            isDanger = false,
            icon = isDanger ? 'danger' : 'warning'
        } = options;

        titleEl.textContent = title;
        msgEl.innerHTML = String(message).replace(/\n/g, '<br>');
        cancelBtn.textContent = cancelText;
        yesBtn.textContent = confirmText;

        // Button style
        yesBtn.className = 'ck-modal-btn ' + (isDanger ? 'ck-modal-btn-danger' : 'ck-modal-btn-confirm');

        // Icon
        iconEl.className = 'ck-modal-icon-badge ' + icon;
        if (isDanger) iconEl.textContent = '🗑️';
        else if (icon === 'warning') iconEl.textContent = '⚠️';
        else iconEl.textContent = '❓';

        currentConfirmCallback = onConfirm;

        cancelBtn.onclick = closeConfirmModal;
        yesBtn.onclick = function () {
            closeConfirmModal();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        };

        modal.style.display = 'flex';
        // Force reflow for animation
        modal.offsetHeight;
        modal.classList.add('show');
        setTimeout(() => yesBtn.focus(), 50);
    };

    // Helper for forms (Delete buttons, status toggles)
    window.confirmFormSubmit = function (form, title, message, confirmText = 'Yes, Proceed', isDanger = true) {
        window.posConfirm(title, message, function () {
            form.submit();
        }, {
            confirmText: confirmText,
            isDanger: isDanger
        });
        return false;
    };

    // 5. Toast Notifications
    window.posToast = function (message, type = 'info', duration = 3200) {
        injectElements();
        const container = document.getElementById('ck-toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `ck-toast ck-toast-${type}`;

        let icon = 'ℹ️';
        if (type === 'success') icon = '✅';
        else if (type === 'danger') icon = '❌';
        else if (type === 'warning') icon = '⚠️';

        toast.innerHTML = `
            <span class="ck-toast-icon">${icon}</span>
            <span class="ck-toast-text">${message}</span>
        `;

        container.appendChild(toast);

        // Slide in
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });

        // Slide out and remove
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, duration);
    };
})();

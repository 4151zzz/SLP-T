(function () {
    'use strict';

    const THRESHOLD_BYTES = 1.5 * 1024 * 1024;
    const MAX_UPLOAD_BYTES = 1.9 * 1024 * 1024;
    const MAX_HARD_BYTES = 2 * 1024 * 1024;
    const MAX_DIMENSION = 1600;
    const RESIZABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function showToast(message, type) {
        const container = document.getElementById('appToastContainer');
        if (!container || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }

        const variantMap = {
            success: 'text-bg-success',
            danger: 'text-bg-danger',
            error: 'text-bg-danger',
            warning: 'text-bg-warning',
            info: 'text-bg-primary'
        };
        const bgClass = variantMap[type || 'info'] || 'text-bg-primary';

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center border-0 ' + bgClass;
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');
        toastEl.setAttribute('aria-atomic', 'true');

        const flex = document.createElement('div');
        flex.className = 'd-flex';

        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = String(message || '');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close me-2 m-auto' + (bgClass === 'text-bg-warning' ? '' : ' btn-close-white');
        btn.setAttribute('data-bs-dismiss', 'toast');
        btn.setAttribute('aria-label', 'ปิด');

        flex.appendChild(body);
        flex.appendChild(btn);
        toastEl.appendChild(flex);
        container.appendChild(toastEl);

        const instance = new bootstrap.Toast(toastEl, { delay: 4000 });
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
        instance.show();
    }

    function confirmAction(message) {
        const modalEl = document.getElementById('appConfirmModal');
        const bodyEl = document.getElementById('appConfirmModalBody');
        const okBtn = document.getElementById('appConfirmModalOk');

        if (!modalEl || !bodyEl || !okBtn || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return Promise.resolve(window.confirm(message || 'ยืนยันการดำเนินการ?'));
        }

        bodyEl.textContent = message || 'คุณต้องการดำเนินการต่อหรือไม่?';

        return new Promise(function (resolve) {
            let settled = false;
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            function cleanup() {
                okBtn.removeEventListener('click', onOk);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            }

            function onOk() {
                if (settled) return;
                settled = true;
                cleanup();
                modal.hide();
                resolve(true);
            }

            function onHidden() {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(false);
            }

            okBtn.addEventListener('click', onOk);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    function loadBitmapOrImage(file) {
        if (typeof createImageBitmap === 'function') {
            return createImageBitmap(file).then(function (bmp) {
                return {
                    width: bmp.width,
                    height: bmp.height,
                    draw: function (ctx, w, h) {
                        ctx.drawImage(bmp, 0, 0, w, h);
                        if (typeof bmp.close === 'function') {
                            bmp.close();
                        }
                    }
                };
            });
        }

        return new Promise(function (resolve, reject) {
            const reader = new FileReader();
            reader.onload = function () {
                const img = new Image();
                img.onload = function () {
                    resolve({
                        width: img.naturalWidth || img.width,
                        height: img.naturalHeight || img.height,
                        draw: function (ctx, w, h) {
                            ctx.drawImage(img, 0, 0, w, h);
                        }
                    });
                };
                img.onerror = reject;
                img.src = reader.result;
            };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    function canvasToBlob(canvas, quality) {
        return new Promise(function (resolve) {
            canvas.toBlob(function (blob) {
                resolve(blob);
            }, 'image/jpeg', quality);
        });
    }

    async function resizeImageFile(file, options) {
        if (!file || RESIZABLE_TYPES.indexOf(file.type) === -1 || file.size <= THRESHOLD_BYTES) {
            return file;
        }

        const maxDim = (options && options.maxDimension) || MAX_DIMENSION;
        const maxBytes = (options && options.maxBytes) || MAX_UPLOAD_BYTES;

        try {
            const source = await loadBitmapOrImage(file);
            let width = source.width;
            let height = source.height;

            if (width > maxDim || height > maxDim) {
                const scale = Math.min(maxDim / width, maxDim / height);
                width = Math.max(1, Math.round(width * scale));
                height = Math.max(1, Math.round(height * scale));
            }

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                return file;
            }
            ctx.drawImage ? source.draw(ctx, width, height) : null;

            const qualities = [0.82, 0.70, 0.60];
            let finalBlob = null;
            for (let i = 0; i < qualities.length; i++) {
                const blob = await canvasToBlob(canvas, qualities[i]);
                if (blob) {
                    finalBlob = blob;
                    if (blob.size <= maxBytes) {
                        break;
                    }
                }
            }

            if (!finalBlob || finalBlob.size >= file.size) {
                return file;
            }

            const baseName = file.name.replace(/\.[^.]+$/, '') || 'upload';
            return new File([finalBlob], baseName + '.jpg', {
                type: 'image/jpeg',
                lastModified: Date.now()
            });
        } catch (err) {
            return file;
        }
    }

    function attachFileInputHandlers() {
        const inputs = document.querySelectorAll(
            'input[type="file"][data-resize-image="true"], input[type="file"][accept*="image"]:not([data-no-resize="true"])'
        );

        inputs.forEach(function (input) {
            input.addEventListener('change', async function () {
                const files = input.files;
                if (!files || files.length === 0) {
                    return;
                }

                const original = files[0];
                let resultFile = original;

                if (RESIZABLE_TYPES.indexOf(original.type) !== -1 && original.size > THRESHOLD_BYTES) {
                    resultFile = await resizeImageFile(original);
                    if (resultFile !== original && typeof DataTransfer !== 'undefined') {
                        const dt = new DataTransfer();
                        dt.items.add(resultFile);
                        input.files = dt.files;
                    }
                } else if (RESIZABLE_TYPES.indexOf(original.type) === -1 && original.size > MAX_HARD_BYTES) {
                    showToast('ไฟล์มีขนาดเกิน 2MB กรุณาเลือกไฟล์ที่เล็กลง', 'warning');
                }

                const previewSel = input.dataset.previewTarget;
                if (previewSel && resultFile && RESIZABLE_TYPES.indexOf(resultFile.type) !== -1) {
                    const previewEl = document.querySelector(previewSel);
                    if (previewEl) {
                        const url = URL.createObjectURL(resultFile);
                        previewEl.src = url;
                        previewEl.classList.remove('d-none');
                    }
                }
            });
        });
    }

    function attachConfirmHandlers() {
        document.addEventListener('submit', function (ev) {
            const form = ev.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('form[data-confirm]')) {
                return;
            }
            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;
                return;
            }
            ev.preventDefault();
            confirmAction(form.getAttribute('data-confirm')).then(function (ok) {
                if (ok) {
                    form.dataset.confirmed = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            });
        });

        document.addEventListener('click', function (ev) {
            const target = ev.target instanceof Element ? ev.target.closest('a[data-confirm], button[data-confirm]') : null;
            if (!target) {
                return;
            }
            if (target instanceof HTMLButtonElement && target.type === 'submit' && target.form && target.form.matches('form[data-confirm]')) {
                return;
            }
            if (target.dataset.confirmed === '1') {
                delete target.dataset.confirmed;
                return;
            }
            ev.preventDefault();
            confirmAction(target.getAttribute('data-confirm')).then(function (ok) {
                if (!ok) return;
                if (target instanceof HTMLAnchorElement && target.href) {
                    window.location.href = target.href;
                } else {
                    target.dataset.confirmed = '1';
                    target.click();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        attachFileInputHandlers();
        attachConfirmHandlers();
    });

    window.SLPApp = {
        showToast: showToast,
        confirmAction: confirmAction,
        resizeImageFile: resizeImageFile
    };
})();

(function () {
    'use strict';

    const THRESHOLD_BYTES = 1.5 * 1024 * 1024;
    const MAX_UPLOAD_BYTES = 1.9 * 1024 * 1024;
    const MAX_HARD_BYTES = 2 * 1024 * 1024;
    const MAX_DIMENSION = 1600;
    const RESIZABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function safeHref(url) {
        const s = String(url ?? '').trim();
        if (s === '') return '#';
        if (s.startsWith('/') || /^https?:\/\//i.test(s)) {
            return s;
        }
        return '#';
    }

    function showToast(message, type) {
        const msgText = String(message || '');
        const normType = (type === 'danger' || type === 'error')
            ? 'error'
            : (type === 'success' || type === 'warning' ? type : 'info');

        if (typeof window.Swal !== 'undefined' && typeof window.Swal.mixin === 'function') {
            const Toast = window.Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3200,
                timerProgressBar: true,
                didOpen: function (toastEl) {
                    if (normType === 'success' || normType === 'info') {
                        toastEl.setAttribute('role', 'status');
                    }
                    toastEl.addEventListener('mouseenter', window.Swal.stopTimer);
                    toastEl.addEventListener('mouseleave', window.Swal.resumeTimer);
                }
            });
            Toast.fire({
                icon: normType,
                title: msgText
            });
            return;
        }

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
        body.textContent = msgText;

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

    function confirmAction(message, opts) {
        const textMsg = String(message || 'คุณต้องการดำเนินการต่อหรือไม่?');
        const options = opts && typeof opts === 'object' ? opts : {};

        if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({
                icon: options.icon || 'warning',
                title: options.title || 'ยืนยันการดำเนินการ',
                text: textMsg,
                showCancelButton: true,
                confirmButtonText: options.confirmText || 'ยืนยัน',
                cancelButtonText: options.cancelText || 'ยกเลิก',
                confirmButtonColor: options.danger ? '#dc2626' : '#2563eb',
                cancelButtonColor: '#64748b',
                reverseButtons: true,
                customClass: {
                    popup: 'slp-swal-popup'
                }
            }).then(function (result) {
                return Boolean(result && result.isConfirmed);
            });
        }

        const modalEl = document.getElementById('appConfirmModal');
        const bodyEl = document.getElementById('appConfirmModalBody');
        const okBtn = document.getElementById('appConfirmModalOk');

        if (!modalEl || !bodyEl || !okBtn || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return Promise.resolve(window.confirm(textMsg));
        }

        bodyEl.textContent = textMsg;

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

    function showDetail(data) {
        const d = data && typeof data === 'object' ? data : {};
        let html = '';

        if (d.subtitle || d.badge) {
            html += '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
            if (d.subtitle) {
                html += '<span class="text-muted small">' + escapeHtml(d.subtitle) + '</span>';
            }
            if (d.badge) {
                html += '<span class="slp-swal-badge">' + escapeHtml(d.badge) + '</span>';
            }
            html += '</div>';
        }

        if (Array.isArray(d.photos) && d.photos.length > 0) {
            const validPhotos = d.photos.filter(function (ph) { return ph && ph.url; });
            if (validPhotos.length === 1) {
                const ph = validPhotos[0];
                const url = safeHref(ph.url);
                html += '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="slp-swal-hero-wrap mb-3">'
                    + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(ph.label || 'รูปภาพ') + '" class="slp-swal-hero-photo">'
                    + '<span class="slp-swal-photo-caption"><i class="bi bi-image me-1"></i>' + escapeHtml(ph.label || 'รูปภาพ') + '</span>'
                    + '</a>';
            } else if (validPhotos.length > 1) {
                html += '<div class="slp-swal-photo-grid mb-3">';
                validPhotos.forEach(function (ph) {
                    const url = safeHref(ph.url);
                    html += '<div>'
                        + '<div class="small fw-semibold text-muted mb-1">' + escapeHtml(ph.label || 'รูปภาพ') + '</div>'
                        + '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" class="d-block">'
                        + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(ph.label || 'รูปภาพ') + '" class="slp-swal-photo">'
                        + '</a></div>';
                });
                html += '</div>';
            }
        }

        if (Array.isArray(d.rows) && d.rows.length > 0) {
            html += '<div class="slp-swal-detail-list">';
            d.rows.forEach(function (row) {
                if (!row || (!row.label && !row.value)) return;
                html += '<div class="slp-swal-detail-row">'
                    + '<span class="slp-swal-detail-label">' + escapeHtml(row.label || '') + '</span>'
                    + '<span class="slp-swal-detail-val">' + escapeHtml(row.value || '-') + '</span>'
                    + '</div>';
            });
            html += '</div>';
        }

        if (d.note) {
            const toneClass = d.noteTone === 'danger' ? ' is-danger' : (d.noteTone === 'success' ? ' is-success' : '');
            html += '<div class="slp-swal-note' + toneClass + '">';
            if (d.noteTitle) {
                html += '<div class="fw-bold mb-1">' + escapeHtml(d.noteTitle) + '</div>';
            }
            html += '<div>' + escapeHtml(d.note).replace(/\n/g, '<br>') + '</div></div>';
        }

        if (Array.isArray(d.timeline) && d.timeline.length > 0) {
            html += '<div class="fw-bold small text-dark mb-2">ประวัติการพิจารณา</div>';
            html += '<div class="slp-swal-timeline">';
            d.timeline.forEach(function (item) {
                if (!item) return;
                html += '<div class="slp-swal-timeline-item">';
                html += '<div class="d-flex justify-content-between align-items-center gap-2">'
                    + '<span class="fw-bold text-dark">' + escapeHtml(item.step || '') + '</span>'
                    + '<span class="badge text-bg-light border text-dark">' + escapeHtml(item.status || '') + '</span>'
                    + '</div>';
                html += '<div class="text-muted">' + escapeHtml(item.signer || '') + (item.time ? ' · ' + escapeHtml(item.time) : '') + '</div>';
                if (item.comment) {
                    html += '<div class="text-dark mt-1">“' + escapeHtml(item.comment) + '”</div>';
                }
                html += '</div>';
            });
            html += '</div>';
        }

        if (Array.isArray(d.links) && d.links.length > 0) {
            html += '<div class="d-flex flex-wrap gap-2 mt-2">';
            d.links.forEach(function (lnk) {
                if (!lnk || !lnk.href) return;
                const btnCls = lnk.variant === 'primary'
                    ? 'btn btn-primary btn-sm rounded-3 px-3'
                    : (lnk.variant === 'success' ? 'btn btn-outline-success btn-sm rounded-3 px-3' : 'btn btn-outline-secondary btn-sm rounded-3 px-3');
                html += '<a href="' + escapeHtml(safeHref(lnk.href)) + '"'
                    + (lnk.download ? ' download' : '')
                    + ' class="' + btnCls + '">'
                    + escapeHtml(lnk.label || 'เปิด') + '</a>';
            });
            html += '</div>';
        }

        if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({
                title: String(d.title || 'รายละเอียด'),
                html: html,
                showConfirmButton: true,
                confirmButtonText: 'ปิดหน้าต่าง',
                confirmButtonColor: '#2563eb',
                showCloseButton: true,
                width: 640,
                customClass: {
                    popup: 'slp-swal-popup'
                }
            });
        }

        return Promise.resolve();
    }

    function showPhoto(url, title) {
        const cleanUrl = safeHref(url);
        const caption = String(title || 'รูปภาพประกอบ');
        if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({
                title: caption,
                imageUrl: cleanUrl,
                imageAlt: caption,
                showConfirmButton: false,
                showCloseButton: true,
                width: 640,
                customClass: {
                    popup: 'slp-swal-popup'
                }
            });
        }
        window.open(cleanUrl, '_blank', 'noopener');
        return Promise.resolve();
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
            const isDanger = form.getAttribute('data-confirm-danger') === '1';
            confirmAction(form.getAttribute('data-confirm'), { danger: isDanger }).then(function (ok) {
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
            const detailBtn = ev.target instanceof Element ? ev.target.closest('[data-swal-detail]') : null;
            if (detailBtn) {
                ev.preventDefault();
                try {
                    const raw = detailBtn.getAttribute('data-swal-detail') || '{}';
                    showDetail(JSON.parse(raw));
                } catch (err) {
                    console.error(err);
                }
                return;
            }

            const photoBtn = ev.target instanceof Element ? ev.target.closest('[data-swal-photo]') : null;
            if (photoBtn) {
                ev.preventDefault();
                const photoUrl = photoBtn.getAttribute('data-swal-photo') || '';
                const photoTitle = photoBtn.getAttribute('data-swal-title') || 'รูปภาพประกอบ';
                if (photoUrl !== '') {
                    showPhoto(photoUrl, photoTitle);
                }
                return;
            }

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
            const isDanger = target.getAttribute('data-confirm-danger') === '1';
            confirmAction(target.getAttribute('data-confirm'), { danger: isDanger }).then(function (ok) {
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

    function autoFlashToast() {
        const successAlert = document.querySelector('.app-flash-region [data-testid="flash-message"][data-flash-type="success"]');
        if (successAlert) {
            const text = (successAlert.textContent || '').trim();
            if (text !== '') {
                showToast(text, 'success');
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        attachFileInputHandlers();
        attachConfirmHandlers();
        autoFlashToast();
    });

    window.SLPApp = {
        showToast: showToast,
        confirmAction: confirmAction,
        showDetail: showDetail,
        showPhoto: showPhoto,
        resizeImageFile: resizeImageFile
    };
})();

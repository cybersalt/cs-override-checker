/**
 * Cybersalt Template Integrity — dashboard behaviour
 *
 * Wires up the "Copy prompt" button. No-op if either the button or
 * the prompt block is missing (e.g. on a future view that reuses the
 * media file).
 */

(function () {
    'use strict';

    // Public API exposed under window.csti so other scripts/inline
    // handlers can show the loading overlay or open the diagnostics
    // modal without re-implementing them.
    window.csti = window.csti || {};
    window.csti.showLoading  = showLoading;
    window.csti.hideLoading  = hideLoading;
    window.csti.openDiag     = openDiagnostics;
    window.csti.closeDiag    = closeDiagnostics;

    document.addEventListener('DOMContentLoaded', function () {
        wireCopyButton('cstemplateintegrity-copy-btn',     'cstemplateintegrity-prompt',     'btn-primary');
        wireCopyButton('cstemplateintegrity-fix-copy-btn', 'cstemplateintegrity-fix-prompt', 'btn-primary');
        wireMarkReviewedModal();
        wireFullscreenButton();
        wireGatedConfirmModal('cstemplateintegrity-restore-modal',
                              'cstemplateintegrity-restore-confirm-check',
                              'cstemplateintegrity-restore-confirm-btn');
        wireSyntaxHighlight();
        wireRunScanForm();
        wireDiagnosticsButton();
        wireRescanModal();
        wireChatForm();
    });

    /**
     * Loading overlay — single instance per page; lazily injected on
     * first call and reused on subsequent calls. Pass an optional
     * heading + body text to customise the message ("Running scan…",
     * "Asking Claude to apply fixes…").
     */
    var loadingTimer = null;
    function showLoading(headingText, bodyText) {
        var overlay = document.getElementById('csti-loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'csti-loading-overlay';
            overlay.className = 'csti-loading-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.innerHTML =
                '<div class="csti-loading-card">'
                + '<div class="csti-spinner" aria-hidden="true"></div>'
                + '<h3 id="csti-loading-heading"></h3>'
                + '<p  id="csti-loading-body"></p>'
                + '<p class="csti-elapsed">Elapsed: <span id="csti-elapsed-counter">0s</span></p>'
                + '</div>';
            document.body.appendChild(overlay);
        }
        document.getElementById('csti-loading-heading').textContent = headingText || 'Working…';
        document.getElementById('csti-loading-body').textContent    = bodyText    || 'This can take 30 to 90 seconds. Please don\'t close this window.';

        var counter = document.getElementById('csti-elapsed-counter');
        var secs = 0;
        counter.textContent = '0s';
        if (loadingTimer) clearInterval(loadingTimer);
        loadingTimer = setInterval(function () {
            secs++;
            counter.textContent = secs + 's';
        }, 1000);

        overlay.classList.add('is-active');
        document.body.style.overflow = 'hidden';
    }

    function hideLoading() {
        var overlay = document.getElementById('csti-loading-overlay');
        if (loadingTimer) { clearInterval(loadingTimer); loadingTimer = null; }
        if (overlay) {
            overlay.classList.remove('is-active');
        }
        document.body.style.overflow = '';
    }

    function wireRunScanForm() {
        var form = document.querySelector('form[data-csti-runscan]');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            var confirmText = form.getAttribute('data-confirm-text');
            if (confirmText && !window.confirm(confirmText)) {
                e.preventDefault();
                return false;
            }
            // Disable the button so a double-click doesn't fire a second scan.
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            showLoading(
                form.getAttribute('data-loading-title') || 'Running automated scan…',
                form.getAttribute('data-loading-body')  || ''
            );
            // Submit continues; overlay stays up until the page navigates.
        });
    }

    /**
     * Diagnostics modal — same vanilla-overlay pattern as the
     * disclaimer. Opens via a button with [data-csti-open-diag];
     * fetches /testApiConnection on demand to populate the result.
     */
    function wireDiagnosticsButton() {
        var btn = document.querySelector('[data-csti-open-diag]');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            openDiagnostics();
        });

        var overlay = document.getElementById('csti-diag-overlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeDiagnostics();
            });
            var closeBtn = overlay.querySelector('[data-csti-diag-close]');
            if (closeBtn) closeBtn.addEventListener('click', closeDiagnostics);
        }

        var testBtn = document.querySelector('[data-csti-test-conn]');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var url = testBtn.getAttribute('data-test-url');
                if (!url) return;
                var result = document.getElementById('csti-diag-test-result');
                result.innerHTML = '<em>Testing…</em>';
                testBtn.disabled = true;
                fetch(url, { method: 'POST', credentials: 'same-origin' })
                    .then(function (r) { return r.json().catch(function () { return null; }); })
                    .then(function (j) {
                        testBtn.disabled = false;
                        if (j === null) {
                            result.innerHTML = '<span class="csti-diag-result is-fail">Test failed: server returned non-JSON.</span>';
                            return;
                        }
                        if (j.ok) {
                            result.innerHTML = '<span class="csti-diag-result is-pass">PASS</span>'
                                + ' &mdash; HTTP ' + (j.status || 200) + ', latency ' + (j.latency_ms || '?') + 'ms.'
                                + ' Reply: <code>' + escapeHtml(j.sample_reply || '') + '</code>';
                        } else {
                            result.innerHTML = '<span class="csti-diag-result is-fail">FAIL</span>'
                                + ' &mdash; ' + escapeHtml(j.error || 'unknown');
                        }
                    })
                    .catch(function (e) {
                        testBtn.disabled = false;
                        result.innerHTML = '<span class="csti-diag-result is-fail">Test errored:</span> ' + escapeHtml(String(e));
                    });
            });
        }
    }
    function openDiagnostics() {
        var overlay = document.getElementById('csti-diag-overlay');
        if (!overlay) return;
        overlay.classList.add('is-active');
        document.body.style.overflow = 'hidden';
    }
    function closeDiagnostics() {
        var overlay = document.getElementById('csti-diag-overlay');
        if (!overlay) return;
        overlay.classList.remove('is-active');
        document.body.style.overflow = '';
    }

    /**
     * Reset overrides for review picker — modal opened by the
     * [data-csti-open-rescan] button. Lets the user pick which
     * template(s) to rescan rather than always rebuilding the tracker
     * for every enabled template. Submit is disabled until at least
     * one template is ticked.
     */
    function wireRescanModal() {
        var openBtn = document.querySelector('[data-csti-open-rescan]');
        var overlay = document.getElementById('csti-rescan-overlay');
        if (!openBtn || !overlay) return;

        openBtn.addEventListener('click', function (e) {
            e.preventDefault();
            overlay.classList.add('is-active');
            document.body.style.overflow = 'hidden';
        });

        // Click-outside-to-dismiss + explicit close buttons.
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeRescan();
        });
        var closeBtns = overlay.querySelectorAll('[data-csti-rescan-close]');
        closeBtns.forEach(function (b) { b.addEventListener('click', closeRescan); });

        var selectAll  = overlay.querySelector('[data-csti-rescan-selectall]');
        var picks      = overlay.querySelectorAll('[data-csti-rescan-pick]');
        var submitBtn  = overlay.querySelector('[data-csti-rescan-submit]');

        function refreshSubmit() {
            if (!submitBtn) return;
            var any = false;
            picks.forEach(function (cb) { if (cb.checked) any = true; });
            submitBtn.disabled = !any;
        }
        function refreshSelectAll() {
            if (!selectAll) return;
            var all = picks.length > 0;
            picks.forEach(function (cb) { if (!cb.checked) all = false; });
            selectAll.checked = all;
        }
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                picks.forEach(function (cb) { cb.checked = selectAll.checked; });
                refreshSubmit();
            });
        }
        picks.forEach(function (cb) {
            cb.addEventListener('change', function () {
                refreshSelectAll();
                refreshSubmit();
            });
        });
        refreshSubmit();

        function closeRescan() {
            overlay.classList.remove('is-active');
            document.body.style.overflow = '';
        }
    }

    /**
     * Chat-with-Claude form on the session detail view. Shows the
     * loading overlay during the synchronous server call and disables
     * the submit button to prevent double-fires.
     */
    function wireChatForm() {
        var form = document.querySelector('form[data-csti-chat]');
        if (!form) return;
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            showLoading(
                form.getAttribute('data-loading-title') || 'Asking Claude…',
                form.getAttribute('data-loading-body')  || 'Claude is reading your message and may run apply-fix or dismiss tools server-side. This can take a minute.'
            );
        });
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function wireSyntaxHighlight() {
        var codeEl = document.getElementById('cstemplateintegrity-backup-contents-code');
        if (!codeEl) {
            return;
        }

        // Poll for window.hljs in case the script load order put us
        // ahead of highlight.js (defer attribute may or may not have
        // landed on the tag depending on how HTMLHelper rendered it).
        var attempts = 0;
        var maxAttempts = 50;  // 50 * 100ms = 5 seconds
        var poll = function () {
            if (typeof window.hljs !== 'undefined' && typeof window.hljs.highlightElement === 'function') {
                try {
                    window.hljs.highlightElement(codeEl);
                } catch (e) {
                    if (window.console && console.warn) {
                        console.warn('cstemplateintegrity highlight failed:', e);
                    }
                }
                return;
            }
            attempts++;
            if (attempts >= maxAttempts) {
                if (window.console && console.warn) {
                    console.warn('cstemplateintegrity: window.hljs never became available; backup contents will render unhighlighted.');
                }
                return;
            }
            setTimeout(poll, 100);
        };
        poll();
    }

    /**
     * Bootstrap modal that gates a destructive submit button on a checkbox.
     * Re-used by Mark-all-reviewed and Restore-backup modals.
     */
    function wireGatedConfirmModal(modalId, checkboxId, confirmBtnId) {
        var checkbox   = document.getElementById(checkboxId);
        var confirmBtn = document.getElementById(confirmBtnId);
        var modalEl    = document.getElementById(modalId);

        if (!checkbox || !confirmBtn) {
            return;
        }

        checkbox.addEventListener('change', function () {
            confirmBtn.disabled = !checkbox.checked;
        });

        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                checkbox.checked    = false;
                confirmBtn.disabled = true;
            });
        }
    }

    function wireFullscreenButton() {
        var btn = document.getElementById('cstemplateintegrity-fullscreen-btn');
        var pre = document.getElementById('cstemplateintegrity-report');

        if (!btn || !pre) {
            return;
        }

        if (!document.fullscreenEnabled) {
            // Browser doesn't support the Fullscreen API — hide the button
            // rather than leaving a button that does nothing.
            btn.style.display = 'none';
            return;
        }

        var enterLabel = btn.getAttribute('data-enter-label') || 'Fullscreen';
        var exitLabel  = btn.getAttribute('data-exit-label')  || 'Exit fullscreen';

        btn.addEventListener('click', function () {
            if (document.fullscreenElement === pre) {
                document.exitFullscreen();
            } else {
                pre.requestFullscreen().catch(function () { /* user dismissed */ });
            }
        });

        document.addEventListener('fullscreenchange', function () {
            if (document.fullscreenElement === pre) {
                pre.classList.add('cstemplateintegrity-report-fullscreen');
                btn.innerHTML = '<span class="icon-shrink" aria-hidden="true"></span> ' + exitLabel;
            } else {
                pre.classList.remove('cstemplateintegrity-report-fullscreen');
                btn.innerHTML = '<span class="icon-expand" aria-hidden="true"></span> ' + enterLabel;
            }
        });
    }

    function wireMarkReviewedModal() {
        var checkbox    = document.getElementById('cstemplateintegrity-mark-reviewed-confirm-check');
        var confirmBtn  = document.getElementById('cstemplateintegrity-mark-reviewed-confirm-btn');
        var modalEl     = document.getElementById('cstemplateintegrity-mark-reviewed-modal');

        if (!checkbox || !confirmBtn) {
            return;
        }

        checkbox.addEventListener('change', function () {
            confirmBtn.disabled = !checkbox.checked;
        });

        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                checkbox.checked = false;
                confirmBtn.disabled = true;
            });
        }
    }

    function wireCopyButton(buttonId, promptId, restoreClass) {
        var btn    = document.getElementById(buttonId);
        var prompt = document.getElementById(promptId);

        if (!btn || !prompt || !navigator.clipboard) {
            return;
        }

        var defaultLabel = btn.getAttribute('data-default-label') || 'Copy prompt';
        var copiedLabel  = btn.getAttribute('data-copied-label')  || 'Copied!';

        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(prompt.innerText).then(function () {
                btn.classList.remove(restoreClass);
                btn.classList.add('btn-success');
                btn.innerHTML = '<span class="icon-checkmark" aria-hidden="true"></span> ' + copiedLabel;

                setTimeout(function () {
                    btn.classList.remove('btn-success');
                    btn.classList.add(restoreClass);
                    btn.innerHTML = '<span class="icon-copy" aria-hidden="true"></span> ' + defaultLabel;
                }, 2000);
            }).catch(function () {
                btn.innerHTML = btn.innerHTML;
            });
        });
    }
}());

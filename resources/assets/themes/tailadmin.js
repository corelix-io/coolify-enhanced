/**
 * TailAdmin theme JavaScript shim.
 * Overrides hardcoded chart colors, form focus styles, and editor colors
 * to match the TailAdmin theme's CSS custom properties.
 */
(function () {
    'use strict';

    if (document.documentElement.getAttribute('data-ce-theme') !== 'tailadmin') return;

    function getVar(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    // --- Chart color override ---

    var _origCheckTheme = null;

    function patchChartColors() {
        if (typeof window.checkTheme === 'function' && window.checkTheme !== taCheckTheme) {
            _origCheckTheme = window.checkTheme;
        }
        window.checkTheme = taCheckTheme;
        taCheckTheme();
    }

    function taCheckTheme() {
        if (_origCheckTheme) {
            try { _origCheckTheme(); } catch (_) {}
        }

        var dark = isDark();
        window.cpuColor = getVar('--ta-accent', dark ? '#7592ff' : '#465fff');
        window.ramColor = getVar('--ta-success', dark ? '#32d583' : '#12b76a');
        window.textColor = getVar('--ta-text-primary', dark ? '#f2f4f7' : '#101828');
        window.editorBackground = getVar('--ta-surface-1', dark ? '#1d2939' : '#ffffff');
        window.editorTheme = dark ? 'blackboard' : null;
    }

    // --- Form focus inline style fix ---

    var _observer = null;

    function patchInlineBoxShadows() {
        if (_observer) return;

        _observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var mutation = mutations[i];
                if (mutation.type !== 'attributes' || mutation.attributeName !== 'style') continue;

                var el = mutation.target;
                if (!el.classList || !el.classList.contains('input')) continue;

                var shadow = el.style.boxShadow;
                if (!shadow) continue;

                var accent = getVar('--ta-accent', isDark() ? '#7592ff' : '#465fff');
                var border = getVar('--ta-border', isDark() ? '#344054' : '#e4e7ec');

                var patched = shadow
                    .replace(/#fcd452/gi, accent)
                    .replace(/#6b16ed/gi, accent)
                    .replace(/#242424/gi, border)
                    .replace(/#e5e5e5/gi, border);

                if (patched !== shadow) {
                    el.style.boxShadow = patched;
                }
            }
        });

        _observer.observe(document.body, {
            attributes: true,
            attributeFilter: ['style'],
            subtree: true
        });
    }

    // --- Monaco editor colors ---

    function patchEditorColors() {
        if (typeof window.editorBackground === 'undefined') return;
        var dark = isDark();
        window.editorBackground = getVar('--ta-surface-1', dark ? '#1d2939' : '#ffffff');
        window.editorTheme = dark ? 'blackboard' : null;
    }

    // --- Lifecycle ---

    function init() {
        patchChartColors();
        patchInlineBoxShadows();
        patchEditorColors();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    document.addEventListener('livewire:navigated', init);
})();

/**
 * wuc-print-fit.js — scale printable documents to fit one A4 page (portrait, 12mm margins).
 * Used by receipts, statements, transcripts, transport invoices, and print popups.
 */
(function (global) {
    'use strict';

    var A4_PRINTABLE_HEIGHT_PX = Math.round(((297 - 24) * 96) / 25.4);
    var MIN_SCALE = 0.42;

    var ROOT_SELECTORS = [
        '.wuc-a4-sheet',
        '.report-container',
        '.receipt-container',
        '.receipt-card',
        '.statement-card',
        '.sheet',
        '.transcript-paper',
        '.ca-paper',
        '.letter-container',
        '.invoice-container',
        '.print-target',
        '.frame'
    ];

    var SINGLE_PAGE_PATHS = [
        'print_result_statement.php',
        'printReceipt.php',
        'print_admission_letter.php',
        'view_invoice.php',
        'fees_statement.php',
        'balanceStatement.php',
        'certificate.php',
        '/transport/invoice.php',
        '/transport/receipt.php'
    ];

    function findA4Root() {
        var i, el;
        for (i = 0; i < ROOT_SELECTORS.length; i++) {
            el = document.querySelector(ROOT_SELECTORS[i]);
            if (el) {
                return el;
            }
        }
        el = document.querySelector('.container, .container-fluid');
        return el || document.body;
    }

    function measureHeight(el) {
        var prevTransform = el.style.transform;
        el.style.transform = 'none';
        var height = el.scrollHeight;
        el.style.transform = prevTransform;
        return height;
    }

    function applyA4Fit() {
        if (!document.body.classList.contains('single-page-document')) {
            return;
        }

        var root = findA4Root();
        if (!root || root.dataset.wucA4Fitted) {
            return;
        }

        var contentHeight = measureHeight(root);
        if (contentHeight <= A4_PRINTABLE_HEIGHT_PX) {
            root.classList.add('wuc-a4-fits-native');
            root.dataset.wucA4Fitted = '1';
            return;
        }

        var scale = A4_PRINTABLE_HEIGHT_PX / contentHeight;
        scale = Math.max(Math.min(scale, 1), MIN_SCALE);

        root.classList.add('wuc-a4-fit-scaled');
        root.style.transform = 'scale(' + scale.toFixed(4) + ')';
        root.style.transformOrigin = 'top left';
        root.style.width = (100 / scale).toFixed(4) + '%';
        root.dataset.wucA4Fitted = '1';
        root.dataset.wucA4Scale = String(scale);

        var wrapper = root.parentElement;
        if (wrapper && wrapper !== document.body && !wrapper.dataset.wucA4Wrapper) {
            wrapper.style.height = Math.ceil(contentHeight * scale) + 'px';
            wrapper.style.overflow = 'hidden';
            wrapper.dataset.wucA4Wrapper = '1';
        }
    }

    function resetA4Fit() {
        document.querySelectorAll('[data-wuc-a4-fitted]').forEach(function (root) {
            root.style.transform = '';
            root.style.transformOrigin = '';
            root.style.width = '';
            root.classList.remove('wuc-a4-fit-scaled', 'wuc-a4-fits-native');
            delete root.dataset.wucA4Fitted;
            delete root.dataset.wucA4Scale;
        });
        document.querySelectorAll('[data-wuc-a4-wrapper]').forEach(function (wrapper) {
            wrapper.style.height = '';
            wrapper.style.overflow = '';
            delete wrapper.dataset.wucA4Wrapper;
        });
    }

    function markSinglePageDocument() {
        if (!document.body || document.body.classList.contains('single-page-document')) {
            return;
        }
        var path = global.location.pathname;
        var i;
        for (i = 0; i < SINGLE_PAGE_PATHS.length; i++) {
            if (path.indexOf(SINGLE_PAGE_PATHS[i]) !== -1) {
                document.body.classList.add('single-page-document');
                break;
            }
        }
    }

    function initA4PrintFit() {
        if (global.__wucA4PrintFitInit) {
            return;
        }
        global.__wucA4PrintFitInit = true;
        markSinglePageDocument();

        global.addEventListener('beforeprint', function () {
            if (document.body.classList.contains('single-page-document')) {
                applyA4Fit();
            }
        });
        global.addEventListener('afterprint', resetA4Fit);
    }

    function wucPrintSinglePage() {
        document.body.classList.add('single-page-document');
        applyA4Fit();

        var finish = function () {
            global.removeEventListener('afterprint', finish);
            resetA4Fit();
        };
        global.addEventListener('afterprint', finish);
        global.print();
        setTimeout(function () {
            global.removeEventListener('afterprint', finish);
            resetA4Fit();
        }, 5000);
    }

    function wucPrintElement(elementOrId, title) {
        var source = typeof elementOrId === 'string'
            ? document.getElementById(elementOrId)
            : elementOrId;
        if (!source) {
            return;
        }

        var printWindow = global.open('', '_blank', 'width=900,height=700');
        if (!printWindow) {
            wucPrintSinglePage();
            return;
        }

        var styles = Array.prototype.map.call(
            document.querySelectorAll('link[rel="stylesheet"], style'),
            function (node) { return node.outerHTML; }
        ).join('\n');

        printWindow.document.open();
        printWindow.document.write(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
            + (title || document.title || 'Print')
            + '</title>'
            + styles
            + '<link rel="stylesheet" media="print" href="/wucportal/css/wuc-print.css">'
            + '<style>@page{size:A4 portrait;margin:12mm}'
            + '@media print{button,.d-print-none,.no-print,.noprint{display:none!important}'
            + 'body{background:#fff;margin:0;padding:0}}</style>'
            + '</head><body class="single-page-document no-auto-print">'
            + '<div class="wuc-a4-sheet">' + source.innerHTML + '</div>'
            + '<script src="/wucportal/js/wuc-print-fit.js"><\/script>'
            + '<script>window.addEventListener("load",function(){'
            + 'if(window.wucPrintSinglePage){wucPrintSinglePage();}else{window.print();}'
            + 'window.onafterprint=function(){window.close();};});<\/script>'
            + '</body></html>'
        );
        printWindow.document.close();
    }

    global.wucApplyA4Fit = applyA4Fit;
    global.wucResetA4Fit = resetA4Fit;
    global.wucPrintSinglePage = wucPrintSinglePage;
    global.wucPrintElement = wucPrintElement;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initA4PrintFit);
    } else {
        initA4PrintFit();
    }
}(window));

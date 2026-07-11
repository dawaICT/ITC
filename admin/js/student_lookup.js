/**
 * Live student ID validation for admin manual-entry forms.
 * Usage: wucBindStudentLookup({ inputId, msgId, submitSelector, lookupUrl, programSelectId })
 */
(function (window) {
    function wucBindStudentLookup(opts) {
        var input = document.getElementById(opts.inputId || 'Sid');
        var msg = document.getElementById(opts.msgId || 'student-lookup-msg');
        var submitEl = opts.submitSelector ? document.querySelector(opts.submitSelector) : null;
        var programSelect = opts.programSelectId ? document.getElementById(opts.programSelectId) : null;
        var url = opts.lookupUrl || 'ajax/student_lookup.php';
        if (!input) return;

        if (!msg && opts.msgId) {
            msg = document.createElement('div');
            msg.id = opts.msgId;
            msg.className = 'form-text mt-1';
            input.closest('.input-group')?.parentNode?.appendChild(msg) ||
                input.parentNode.appendChild(msg);
        }

        input.addEventListener('blur', function () {
            var sid = input.value.trim();
            if (!sid) {
                if (msg) { msg.textContent = ''; msg.className = 'form-text mt-1'; }
                if (submitEl) submitEl.disabled = false;
                return;
            }
            fetch(url + '?sid=' + encodeURIComponent(sid), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!msg) return;
                    if (data.ok) {
                        msg.className = 'form-text mt-1 text-success';
                        msg.textContent = data.name + (data.program_code ? ' — ' + data.program_code : '');
                        if (submitEl) submitEl.disabled = false;
                        if (programSelect && data.program_code) {
                            for (var i = 0; i < programSelect.options.length; i++) {
                                if (programSelect.options[i].value === data.program_code) {
                                    programSelect.value = data.program_code;
                                    programSelect.dispatchEvent(new Event('change'));
                                    break;
                                }
                            }
                        }
                    } else {
                        msg.className = 'form-text mt-1 text-danger';
                        msg.textContent = data.message || 'Student not found';
                        if (submitEl) submitEl.disabled = true;
                    }
                })
                .catch(function () {});
        });
    }

    window.wucBindStudentLookup = wucBindStudentLookup;
})(window);

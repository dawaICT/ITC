<?php
declare(strict_types=1);
/** Skill Discovery page scripts. Requires $canDiscover from skill_discovery_run.php */
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('experience-list');
    var addButton = document.getElementById('add-experience');
    var form = document.getElementById('skill-experience-form');
    var submitButton = document.getElementById('discover-skills');
    var matchingStatus = document.getElementById('matching-status');
    var maxExperiences = 5;

    if (form && submitButton) {
        var canSubmit = <?= !empty($canDiscover) ? 'true' : 'false' ?>;
        form.addEventListener('submit', function () {
            if (!canSubmit) {
                return;
            }
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Matching…';
            if (matchingStatus) {
                matchingStatus.classList.remove('d-none');
            }
        });
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted && submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="fas fa-magnifying-glass me-2"></i>Discover skills';
            if (matchingStatus) {
                matchingStatus.classList.add('d-none');
            }
        }
    });

    function refreshEntries() {
        var entries = list.querySelectorAll('.experience-entry');
        entries.forEach(function (entry, index) {
            var label = entry.querySelector('.small.fw-semibold');
            var removeButton = entry.querySelector('.remove-experience');
            var textarea = entry.querySelector('textarea');

            if (label) {
                label.textContent = 'Experience ' + (index + 1);
            }
            if (removeButton) {
                removeButton.disabled = entries.length <= 2;
            }
            if (textarea) {
                textarea.required = index < 2;
            }
        });

        if (addButton) {
            addButton.disabled = entries.length >= maxExperiences;
        }
    }

    function createExperience(value) {
        var entry = document.createElement('div');
        entry.className = 'experience-entry mb-3';
        entry.innerHTML = [
            '<div class="d-flex justify-content-between align-items-center mb-1">',
            '<span class="small fw-semibold text-muted">Experience</span>',
            '<button type="button" class="btn btn-sm btn-outline-secondary remove-experience">Remove</button>',
            '</div>',
            '<textarea class="form-control experience-text" name="experiences[]" rows="4" maxlength="1200"></textarea>'
        ].join('');
        entry.querySelector('textarea').value = value || '';
        list.appendChild(entry);
        refreshEntries();
        return entry;
    }

    if (addButton) {
        addButton.addEventListener('click', function () {
            var entry = createExperience('');
            entry.querySelector('textarea').focus();
        });
    }

    list.addEventListener('click', function (event) {
        var button = event.target.closest('.remove-experience');
        if (!button || button.disabled) {
            return;
        }
        button.closest('.experience-entry').remove();
        refreshEntries();
    });

    document.querySelectorAll('[data-example]').forEach(function (button) {
        button.addEventListener('click', function () {
            var example = button.getAttribute('data-example') || '';
            var target = Array.prototype.find.call(
                list.querySelectorAll('textarea'),
                function (textarea) {
                    return textarea.value.trim() === '';
                }
            );

            if (!target && list.querySelectorAll('.experience-entry').length < maxExperiences) {
                target = createExperience('').querySelector('textarea');
            }

            if (target) {
                target.value = example;
                target.focus();
            }
        });
    });
    refreshEntries();
});
</script>

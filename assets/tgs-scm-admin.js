(function ($) {
    'use strict';

    var cfg = window.TgsScmAdmin || {};
    var state = {
        codeTimer: null,
        codeOk: false,
        token: '',
        previewOk: false,
        hasErrors: true,
        importOffset: 0,
        importCreated: 0,
        importSkipped: 0,
        importErrors: 0,
        importTotal: 0,
        isImporting: false,
        stopRequested: false
    };

    var timeApplyState = {
        offset: 0,
        updated: 0,
        running: false
    };

    var landingApplyState = {
        offset: 0,
        updated: 0,
        running: false
    };

    var importModes = {
        sites: {
            previewAction: 'tgs_scm_preview_import',
            importAction: 'tgs_scm_import_sites',
            importLabel: 'Import website',
            importedLabel: 'website',
            limit: 5,
            importingText: 'Dang import site...',
            doneText: 'Import thanh cong.'
        },
        users: {
            previewAction: 'tgs_scm_preview_user_import',
            importAction: 'tgs_scm_import_users',
            importLabel: 'Import user',
            importedLabel: 'user',
            limit: 10,
            importingText: 'Dang import user...',
            doneText: 'Import user thanh cong.'
        }
    };

    function text(key, fallback) {
        return cfg.i18n && cfg.i18n[key] ? cfg.i18n[key] : fallback;
    }

    function normalizeCode(value) {
        return String(value || '').replace(/\s+/g, '').toUpperCase();
    }

    function getImportKind() {
        var kind = $('.tgs-scm-import-panel').data('import-kind') || cfg.importKind || 'sites';
        return importModes[kind] ? kind : 'sites';
    }

    function getImportMode() {
        return importModes[getImportKind()];
    }

    function setCodeStatus(message, type) {
        var $status = $('#tgs-site-code-status');
        if (!$status.length) return;

        $status
            .removeClass('is-ok is-error is-muted')
            .addClass(type ? 'is-' + type : 'is-muted')
            .text(message || '');
    }

    function setAddButtonEnabled(enabled) {
        var $button = $('#add-site');
        if (!$button.length) return;
        $button.prop('disabled', !enabled).toggleClass('disabled', !enabled);
        $button.closest('p.submit').toggle(!!enabled);
    }

    function validateCodeLocal(value) {
        if (!value) {
            return text('codeRequired', 'Ma website la bat buoc.');
        }
        if (!/^[A-Z0-9_-]+$/.test(value)) {
            return text('codeInvalid', 'Ma chi duoc gom chu, so, dau gach ngang hoac gach duoi.');
        }
        if (value.length > 32) {
            return 'Ma website khong duoc vuot qua 32 ky tu.';
        }
        return '';
    }

    function runCodeCheck() {
        var $input = $('#tgs-site-code');
        if (!$input.length) return;

        var value = normalizeCode($input.val());
        $input.val(value);
        state.codeOk = false;

        var localError = validateCodeLocal(value);
        if (localError) {
            setCodeStatus(localError, 'error');
            setAddButtonEnabled(false);
            return;
        }

        setCodeStatus(text('checking', 'Dang kiem tra...'), 'muted');
        setAddButtonEnabled(false);

        $.post(cfg.ajaxUrl, {
            action: 'tgs_scm_check_code',
            nonce: cfg.nonce,
            code: value
        }).done(function (res) {
            var data = res && res.data ? res.data : {};
            state.codeOk = !!(res && res.success && data.ok);
            setCodeStatus(data.message || (state.codeOk ? text('codeAvailable', 'Ma website co the su dung.') : text('codeTaken', 'Ma website da ton tai.')), state.codeOk ? 'ok' : 'error');
            setAddButtonEnabled(state.codeOk);
        }).fail(function () {
            state.codeOk = false;
            setCodeStatus('Khong kiem tra duoc ma website.', 'error');
            setAddButtonEnabled(false);
        });
    }

    function bindSiteCodeValidation() {
        var $input = $('#tgs-site-code');
        if (!$input.length) return;

        setAddButtonEnabled(false);
        if ($input.val()) runCodeCheck();

        $input.on('input blur', function () {
            clearTimeout(state.codeTimer);
            state.codeTimer = setTimeout(runCodeCheck, 250);
        });
    }

    function setImportStatus(message, type) {
        var $status = $('#tgs-scm-import-status');
        if (!$status.length) return;

        $status
            .removeClass('is-ok is-error is-muted')
            .addClass(type ? 'is-' + type : 'is-muted')
            .text(message || '');
    }

    function resetPreview() {
        state.previewOk = false;
        state.hasErrors = true;
        state.importOffset = 0;
        state.importCreated = 0;
        state.importSkipped = 0;
        state.importErrors = 0;
        state.importTotal = 0;
        state.isImporting = false;
        state.stopRequested = false;
        $('#tgs-scm-import-button').prop('disabled', true).text(getImportMode().importLabel);
        $('#tgs-scm-stop-button').prop('disabled', true).prop('hidden', true);
        $('#tgs-scm-preview-summary').prop('hidden', true).empty();
        $('#tgs-scm-preview-table').prop('hidden', true).find('tbody').empty();
        resetProgress();
    }

    function resetProgress() {
        $('#tgs-scm-import-progress').prop('hidden', true);
        $('#tgs-scm-progress-fill').css('width', '0%');
        $('#tgs-scm-progress-meta').text('Chua import.');
        $('#tgs-scm-progress-errors').prop('hidden', true).empty();
    }

    function updateProgress(processed, total, created, skipped, errors) {
        total = Number(total || 0);
        processed = Math.min(Number(processed || 0), total);
        created = Number(created || 0);
        skipped = Number(skipped || 0);
        errors = Number(errors || 0);

        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        var mode = getImportMode();
        state.importTotal = total;
        $('#tgs-scm-import-progress').prop('hidden', false);
        $('#tgs-scm-progress-fill').css('width', percent + '%');
        $('#tgs-scm-progress-meta').text(
            'Tien do: ' + processed + '/' + total + ' dong hop le (' + percent + '%)'
            + ' | Da import ' + mode.importedLabel + ': ' + created
            + (skipped ? ' | Bo qua: ' + skipped : '')
            + ' | Loi khi tao: ' + errors
        );
    }

    function appendImportErrors(errors) {
        if (!errors || !errors.length) return;

        var $box = $('#tgs-scm-progress-errors');
        $box.prop('hidden', false);
        errors.forEach(function (err) {
            var identity = err.username || err.website || '';
            $('<div>')
                .text('Dong ' + (err.row || '') + ' - ' + identity + ' / ' + (err.code || '') + ': ' + (err.message || 'Loi khong xac dinh'))
                .appendTo($box);
        });
    }

    function setSheetOptions(sheets) {
        var $select = $('#tgs-scm-sheet-select');
        $select.empty();

        if (!sheets || !sheets.length) {
            $select.append($('<option>', { value: '', text: 'Khong co sheet' })).prop('disabled', true);
            return;
        }

        sheets.forEach(function (sheet) {
            $select.append($('<option>', {
                value: sheet.name,
                text: sheet.name
            }));
        });
        $select.prop('disabled', false);
        $('#tgs-scm-preview-button').prop('disabled', false);
    }

    function uploadExcel(file) {
        var formData = new FormData();
        formData.append('action', 'tgs_scm_upload_excel');
        formData.append('nonce', cfg.nonce);
        formData.append('kind', getImportKind());
        formData.append('file', file);

        state.token = '';
        resetPreview();
        setImportStatus(text('uploading', 'Dang doc file Excel...'), 'muted');
        $('#tgs-scm-preview-button, #tgs-scm-import-button').prop('disabled', true);

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (res) {
            if (!res || !res.success) {
                setImportStatus((res && res.data && res.data.message) || 'Khong doc duoc file Excel.', 'error');
                setSheetOptions([]);
                return;
            }

            state.token = res.data.token;
            setSheetOptions(res.data.sheets || []);
            setImportStatus('Da doc file: ' + (res.data.name || file.name), 'ok');
        }).fail(function () {
            setImportStatus('Khong upload duoc file Excel.', 'error');
            setSheetOptions([]);
        });
    }

    function renderPreview(data) {
        var summary = data.summary || {};
        var rows = data.rows || [];
        var summaryText = 'Tong dong: ' + (summary.total || 0)
            + ' | Hop le: ' + (summary.valid || 0)
            + ' | Bo qua: ' + (summary.skipped || 0)
            + ' | Loi: ' + (summary.errors || 0);

        $('#tgs-scm-preview-summary').prop('hidden', false).text(summaryText);

        var $table = $('#tgs-scm-preview-table');
        var $tbody = $table.find('tbody').empty();
        var fields = [];
        $table.find('thead th[data-field]').each(function () {
            fields.push($(this).data('field'));
        });

        rows.forEach(function (row) {
            var $tr = $('<tr>').addClass('is-' + (row.status || ''));
            fields.forEach(function (field) {
                $('<td>').text(row[field] || '').appendTo($tr);
            });
            $tbody.append($tr);
        });
        $table.prop('hidden', rows.length === 0);

        state.previewOk = true;
        state.hasErrors = !!data.has_errors;
        $('#tgs-scm-import-button').prop('disabled', !summary.valid).text(getImportMode().importLabel);
        if (summary.valid) {
            setImportStatus(state.hasErrors ? 'Co dong loi, nhung cac dong hop le van co the import.' : 'Du lieu sach, co the import.', state.hasErrors ? 'muted' : 'ok');
        } else {
            setImportStatus(text('importBlocked', 'Con loi trong du lieu, chua the import.'), 'error');
        }
    }

    function previewImport() {
        var sheet = $('#tgs-scm-sheet-select').val();
        if (!state.token) {
            setImportStatus(text('chooseFile', 'Vui long chon file Excel .xlsx.'), 'error');
            return;
        }
        if (!sheet) {
            setImportStatus(text('chooseSheet', 'Vui long chon sheet Excel.'), 'error');
            return;
        }

        resetPreview();
        setImportStatus(text('previewing', 'Dang kiem tra du lieu...'), 'muted');
        $('#tgs-scm-preview-button').prop('disabled', true);

        var mode = getImportMode();
        $.post(cfg.ajaxUrl, {
            action: mode.previewAction,
            nonce: cfg.nonce,
            token: state.token,
            sheet: sheet
        }).done(function (res) {
            if (!res || !res.success) {
                setImportStatus((res && res.data && res.data.message) || 'Kiem tra du lieu that bai.', 'error');
                return;
            }

            renderPreview(res.data || {});
        }).fail(function () {
            setImportStatus('Kiem tra du lieu that bai.', 'error');
        }).always(function () {
            $('#tgs-scm-preview-button').prop('disabled', false);
        });
    }

    function renderImportCounters(created, skipped, errors, processed, total) {
        created = created || [];
        skipped = skipped || [];
        errors = errors || [];

        var $summary = $('#tgs-scm-preview-summary');
        var current = $summary.text();
        state.importCreated += created.length;
        state.importSkipped += skipped.length;
        state.importErrors += errors.length;
        $summary.prop('hidden', false).text(current.replace(/\s\|\sDa tao:.*$/, '') + ' | Da tao: ' + state.importCreated + (total ? '/' + total : '') + ' | Bo qua: ' + state.importSkipped + ' | Loi tao: ' + state.importErrors);
        updateProgress(processed, total, state.importCreated, state.importSkipped, state.importErrors);
        appendImportErrors(errors);
    }

    function setStopButton(active) {
        $('#tgs-scm-stop-button').prop('hidden', !active).prop('disabled', !active);
    }

    function setImportIdle(canResume) {
        state.isImporting = false;
        state.stopRequested = false;
        setStopButton(false);
        $('#tgs-scm-import-button')
            .prop('disabled', !canResume)
            .text(canResume && state.importOffset > 0 ? 'Tiep tuc import' : getImportMode().importLabel);
    }

    function pauseImport(total) {
        total = Number(total || state.importTotal || 0);
        setImportIdle(true);
        $('#tgs-scm-preview-button').prop('disabled', true);

        var progressText = total > 0 ? state.importOffset + '/' + total : state.importOffset + ' dong';
        setImportStatus('Da dung import tai ' + progressText + '. Bam Tiep tuc import de chay tiep.', 'muted');
    }

    function importSitesBatch() {
        if (!state.token || !state.previewOk) {
            setImportStatus(text('previewFirst', 'Can kiem tra truoc du lieu truoc khi import.'), 'error');
            return;
        }

        if (state.stopRequested) {
            pauseImport();
            return;
        }

        $('#tgs-scm-import-button, #tgs-scm-preview-button').prop('disabled', true);
        setStopButton(true);
        var mode = getImportMode();
        setImportStatus(mode.importingText || text('importing', 'Dang import site...'), 'muted');

        $.post(cfg.ajaxUrl, {
            action: mode.importAction,
            nonce: cfg.nonce,
            token: state.token,
            offset: state.importOffset,
            limit: mode.limit || 5
        }).done(function (res) {
            if (!res || !res.success) {
                var data = res && res.data ? res.data : {};
                renderImportCounters(data.created || [], data.skipped || [], data.errors || [], data.next_offset || state.importOffset, data.total || 0);
                state.importOffset = data.next_offset || state.importOffset;
                setImportIdle(!!(state.token && state.previewOk));
                setImportStatus(data.message || 'Import bi dung lai.', 'error');
                return;
            }

            var data = res.data || {};
            renderImportCounters(data.created || [], data.skipped || [], data.errors || [], data.next_offset || state.importOffset, data.total || 0);
            state.importOffset = data.next_offset || state.importOffset;

            if (data.done) {
                var finalType = state.importErrors ? 'muted' : 'ok';
                setImportStatus((data.message || mode.doneText) + ' Da tao ' + state.importCreated + ' ' + mode.importedLabel + ', bo qua ' + state.importSkipped + ', loi ' + state.importErrors + '.', finalType);
                state.token = '';
                state.previewOk = false;
                setImportIdle(false);
                $('#tgs-scm-preview-button').prop('disabled', true);
                return;
            }

            if (state.stopRequested) {
                pauseImport(data.total || 0);
                return;
            }

            setImportStatus(data.message || ('Da import ' + state.importOffset + '/' + (data.total || 0) + ' ' + mode.importedLabel + '.'), 'muted');
            window.setTimeout(importSitesBatch, 250);
        }).fail(function () {
            setImportIdle(!!(state.token && state.previewOk));
            $('#tgs-scm-preview-button').prop('disabled', state.importOffset > 0);
            setImportStatus('Import that bai.', 'error');
        }).always(function () {
            if (!state.token) {
                $('#tgs-scm-preview-button, #tgs-scm-import-button').prop('disabled', true);
                setStopButton(false);
            }
        });
    }

    function importSites() {
        if (state.isImporting) return;

        if (!state.token || !state.previewOk) {
            setImportStatus(text('previewFirst', 'Can kiem tra truoc du lieu truoc khi import.'), 'error');
            return;
        }

        var isResume = state.importOffset > 0 || state.importCreated > 0 || state.importSkipped > 0 || state.importErrors > 0;
        if (!isResume) {
            state.importOffset = 0;
            state.importCreated = 0;
            state.importSkipped = 0;
            state.importErrors = 0;
            state.importTotal = 0;
            resetProgress();
        }

        state.isImporting = true;
        state.stopRequested = false;
        $('#tgs-scm-import-button, #tgs-scm-preview-button').prop('disabled', true);
        setStopButton(true);
        importSitesBatch();
    }

    function stopImport() {
        if (!state.isImporting) return;

        state.stopRequested = true;
        $('#tgs-scm-stop-button').prop('disabled', true);
        setImportStatus('Dang dung sau lo hien tai...', 'muted');
    }

    function bindImportUi() {
        if (!$('#tgs-scm-excel-file').length) return;

        $('#tgs-scm-toggle-import').on('click', function () {
            var $box = $('#tgs-scm-inline-import');
            $box.prop('hidden', !$box.prop('hidden'));
        });

        $('#tgs-scm-excel-file').on('change', function () {
            var file = this.files && this.files[0] ? this.files[0] : null;
            if (!file) {
                setImportStatus(text('chooseFile', 'Vui long chon file Excel .xlsx.'), 'error');
                return;
            }
            if (!/\.xlsx$/i.test(file.name || '')) {
                setImportStatus('Hien tai chi ho tro file .xlsx.', 'error');
                return;
            }
            uploadExcel(file);
        });

        $('#tgs-scm-sheet-select').on('change', resetPreview);
        $('#tgs-scm-preview-button').on('click', previewImport);
        $('#tgs-scm-import-button').on('click', importSites);
        $('#tgs-scm-stop-button').on('click', stopImport);
    }

    function setTimeApplyStatus(message, type) {
        var $status = $('#tgs-scm-time-apply-status');
        if (!$status.length) return;

        $status
            .removeClass('is-ok is-error is-muted')
            .addClass(type ? 'is-' + type : 'is-muted')
            .text(message || '');
    }

    function updateTimeApplyProgress(processed, total) {
        total = Number(total || 0);
        processed = Math.min(Number(processed || 0), total);

        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        $('#tgs-scm-time-apply-progress').prop('hidden', false);
        $('#tgs-scm-time-apply-fill').css('width', percent + '%');
        $('#tgs-scm-time-apply-meta').text('Tien do: ' + processed + '/' + total + ' website (' + percent + '%)');
    }

    function applyTimeSettingsBatch() {
        $.post(cfg.ajaxUrl, {
            action: 'tgs_scm_apply_time_settings',
            nonce: cfg.nonce,
            offset: timeApplyState.offset,
            limit: 50,
            timezone_string: $('#timezone_string').val() || '',
            date_format: $('#date_format').val() || '',
            time_format: $('#time_format').val() || '',
            start_of_week: $('#start_of_week').val() || 1
        }).done(function (res) {
            if (!res || !res.success) {
                timeApplyState.running = false;
                $('#tgs-scm-apply-time-settings').prop('disabled', false);
                setTimeApplyStatus((res && res.data && res.data.message) || 'Ap dung cau hinh that bai.', 'error');
                return;
            }

            var data = res.data || {};
            timeApplyState.offset = data.next_offset || timeApplyState.offset;
            timeApplyState.updated += Number(data.updated || 0);
            updateTimeApplyProgress(timeApplyState.offset, data.total || 0);

            if (data.done) {
                timeApplyState.running = false;
                $('#tgs-scm-apply-time-settings').prop('disabled', false);
                setTimeApplyStatus((data.message || 'Da ap dung cau hinh gio cho toan bo website.') + ' Tong so website da xu ly: ' + timeApplyState.updated + '.', 'ok');
                return;
            }

            setTimeApplyStatus(data.message || 'Dang ap dung cau hinh gio...', 'muted');
            window.setTimeout(applyTimeSettingsBatch, 200);
        }).fail(function () {
            timeApplyState.running = false;
            $('#tgs-scm-apply-time-settings').prop('disabled', false);
            setTimeApplyStatus('Ap dung cau hinh that bai.', 'error');
        });
    }

    function startTimeApply() {
        if (timeApplyState.running) return;

        timeApplyState.offset = 0;
        timeApplyState.updated = 0;
        timeApplyState.running = true;
        $('#tgs-scm-time-apply-progress').prop('hidden', true);
        $('#tgs-scm-time-apply-fill').css('width', '0%');
        $('#tgs-scm-time-apply-meta').text('Chua ap dung.');
        $('#tgs-scm-apply-time-settings').prop('disabled', true);
        setTimeApplyStatus('Dang ap dung cau hinh gio cho toan bo website...', 'muted');
        applyTimeSettingsBatch();
    }

    function bindTimeSettingsUi() {
        if (!$('#tgs-scm-apply-time-settings').length) return;

        $('#tgs-scm-apply-time-settings').on('click', startTimeApply);
    }

    function setLandingApplyStatus(message, type) {
        var $status = $('#tgs-scm-landing-apply-status');
        if (!$status.length) return;

        $status
            .removeClass('is-ok is-error is-muted')
            .addClass(type ? 'is-' + type : 'is-muted')
            .text(message || '');
    }

    function updateLandingApplyProgress(processed, total) {
        total = Number(total || 0);
        processed = Math.min(Number(processed || 0), total);

        var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        $('#tgs-scm-landing-apply-progress').prop('hidden', false);
        $('#tgs-scm-landing-apply-fill').css('width', percent + '%');
        $('#tgs-scm-landing-apply-meta').text('Tien do: ' + processed + '/' + total + ' website (' + percent + '%)');
    }

    function applyLandingPageBatch() {
        $.post(cfg.ajaxUrl, {
            action: 'tgs_scm_apply_landing_page',
            nonce: cfg.nonce,
            offset: landingApplyState.offset,
            limit: 50
        }).done(function (res) {
            if (!res || !res.success) {
                landingApplyState.running = false;
                $('#tgs-scm-apply-landing-page').prop('disabled', false);
                setLandingApplyStatus((res && res.data && res.data.message) || 'Kich hoat trang chu quan ly that bai.', 'error');
                return;
            }

            var data = res.data || {};
            landingApplyState.offset = data.next_offset || landingApplyState.offset;
            landingApplyState.updated += Number(data.updated || 0);
            updateLandingApplyProgress(landingApplyState.offset, data.total || 0);

            if (data.done) {
                landingApplyState.running = false;
                $('#tgs-scm-apply-landing-page').prop('disabled', false);
                setLandingApplyStatus((data.message || 'Da kich hoat trang chu quan ly cho toan bo website.') + ' Tong so website da xu ly: ' + landingApplyState.updated + '.', 'ok');
                return;
            }

            setLandingApplyStatus(data.message || 'Dang kich hoat trang chu quan ly...', 'muted');
            window.setTimeout(applyLandingPageBatch, 200);
        }).fail(function () {
            landingApplyState.running = false;
            $('#tgs-scm-apply-landing-page').prop('disabled', false);
            setLandingApplyStatus('Kich hoat trang chu quan ly that bai.', 'error');
        });
    }

    function startLandingApply() {
        if (landingApplyState.running) return;

        landingApplyState.offset = 0;
        landingApplyState.updated = 0;
        landingApplyState.running = true;
        $('#tgs-scm-landing-apply-progress').prop('hidden', true);
        $('#tgs-scm-landing-apply-fill').css('width', '0%');
        $('#tgs-scm-landing-apply-meta').text('Chua ap dung.');
        $('#tgs-scm-apply-landing-page').prop('disabled', true);
        setLandingApplyStatus('Dang kich hoat trang chu quan ly cho toan bo website...', 'muted');
        applyLandingPageBatch();
    }

    function bindLandingPageUi() {
        if (!$('#tgs-scm-apply-landing-page').length) return;

        $('#tgs-scm-apply-landing-page').on('click', startLandingApply);
    }

    $(function () {
        bindSiteCodeValidation();
        bindImportUi();
        bindTimeSettingsUi();
        bindLandingPageUi();
    });
})(jQuery);

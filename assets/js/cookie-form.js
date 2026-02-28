(function ($) {
    'use strict';

    var config = window.cookieFormPdfGate || window.devmyPdfGate || {};
    var cookieName = config.cookieName || 'cookie_form_pdf_gate_unlocked';
    var cookieDays = parseInt(config.cookieDays || 365, 10);
    var leadTokenKey = config.leadTokenKey || (cookieName + '_lead_token');
    var validationConfig = config.validation || {};
    var pendingDownload = null;

    function readData($element, key) {
        return $element.data(key) || $element.attr('data-' + key) || $element.attr('data-cookie-form-' + key) || $element.attr('data-devmy-' + key) || '';
    }

    function hasUnlockedAccess() {
        if (window.localStorage && localStorage.getItem(cookieName) === '1') {
            return true;
        }

        var cookies = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < cookies.length; i += 1) {
            var cookie = cookies[i].trim();
            if (cookie.indexOf(cookieName + '=') === 0) {
                return cookie.substring(cookieName.length + 1) === '1';
            }
        }

        return false;
    }

    function readCookieValue(name) {
        var cookies = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < cookies.length; i += 1) {
            var cookie = cookies[i].trim();
            if (cookie.indexOf(name + '=') === 0) {
                return decodeURIComponent(cookie.substring(name.length + 1));
            }
        }

        return '';
    }

    function setUnlockedAccess() {
        var maxAge = cookieDays * 24 * 60 * 60;
        var secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = cookieName + '=1; path=/; max-age=' + maxAge + '; SameSite=Lax' + secureFlag;

        if (window.localStorage) {
            localStorage.setItem(cookieName, '1');
        }
    }

    function getLeadToken() {
        if (window.localStorage) {
            var localToken = localStorage.getItem(leadTokenKey);
            if (localToken) {
                return localToken;
            }
        }

        return readCookieValue(leadTokenKey);
    }

    function setLeadToken(token) {
        if (!token) {
            return;
        }

        var maxAge = cookieDays * 24 * 60 * 60;
        var secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = leadTokenKey + '=' + encodeURIComponent(token) + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + secureFlag;

        if (window.localStorage) {
            localStorage.setItem(leadTokenKey, token);
        }
    }

    function trackUnlockedDownload(pdfUrl) {
        var leadToken = getLeadToken();
        var payload;

        if (!pdfUrl || !leadToken || !config.ajaxUrl) {
            return;
        }

        payload = {
            action: 'cookie_form_track_pdf_download',
            nonce: config.nonce || '',
            lead_token: leadToken,
            requested_pdf: pdfUrl,
            source: window.location.href
        };

        if (navigator.sendBeacon && window.URLSearchParams) {
            if (navigator.sendBeacon(config.ajaxUrl, new URLSearchParams(payload))) {
                return;
            }
        }

        $.post(config.ajaxUrl, payload);
    }

    function validationMessage(key, fallback) {
        return validationConfig[key] || fallback;
    }

    function collectFieldErrors(payload) {
        var errors = {};
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (!payload.name) {
            errors.name = validationMessage('nameRequired', 'Inserisci il nome.');
        }

        if (!payload.email) {
            errors.email = validationMessage('emailRequired', 'Inserisci l\'email.');
        } else if (!emailPattern.test(payload.email)) {
            errors.email = validationMessage('emailInvalid', 'Inserisci un indirizzo email valido.');
        }

        if (!payload.company) {
            errors.company = validationMessage('companyRequired', 'Inserisci il nome dell\'azienda.');
        }

        if (!payload.data_storage_consent) {
            errors.data_storage_consent = validationMessage('consentRequired', 'Devi accettare l\'archiviazione dei dati per continuare.');
        }

        if (!payload.requested_pdf) {
            errors.requested_pdf = validationMessage('pdfRequired', 'Non riesco a capire quale PDF scaricare. Chiudi il popup e riprova dal pulsante download.');
        }

        return errors;
    }

    function firstFieldError(fieldErrors) {
        var order = ['name', 'email', 'company', 'data_storage_consent', 'requested_pdf'];
        var i;

        if (!fieldErrors || typeof fieldErrors !== 'object') {
            return '';
        }

        for (i = 0; i < order.length; i += 1) {
            if (fieldErrors[order[i]]) {
                return fieldErrors[order[i]];
            }
        }

        for (i in fieldErrors) {
            if (Object.prototype.hasOwnProperty.call(fieldErrors, i) && fieldErrors[i]) {
                return fieldErrors[i];
            }
        }

        return '';
    }

    function triggerDownload(url, target) {
        if (!url) {
            return;
        }

        var link = document.createElement('a');
        link.href = url;
        link.target = target || '_blank';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function modalNode() {
        return document.getElementById('devmy-pdf-gate-modal');
    }

    function openModal(pdfUrl) {
        var modal = modalNode();
        if (!modal) {
            return;
        }

        var requestedField = modal.querySelector('input[name="requested_pdf"]');
        if (requestedField) {
            requestedField.value = pdfUrl || '';
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        var modal = modalNode();
        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openElementorPopup(popupId) {
        if (!popupId) {
            return false;
        }

        if (window.elementorProFrontend && elementorProFrontend.modules && elementorProFrontend.modules.popup) {
            elementorProFrontend.modules.popup.showPopup({ id: popupId });
            return true;
        }

        return false;
    }

    function closeElementorPopup(popupId, $form) {
        var numericId = popupId ? parseInt(popupId, 10) : 0;
        var $popup = numericId ? $('#elementor-popup-modal-' + numericId) : $form.closest('.elementor-popup-modal');

        if (!$popup.length) {
            $popup = $('[id^="elementor-popup-modal-"]:visible').first();
        }

        // Close by emulating user action first (most stable across Elementor versions).
        if ($popup.length) {
            $popup.find('.dialog-close-button').first().trigger('click');
            $popup.find('.dialog-widget-overlay').first().trigger('click');
        }

        if (window.jQuery) {
            if (numericId) {
                $(document).trigger('elementor/popup/hide', [numericId]);
            } else {
                $(document).trigger('elementor/popup/hide');
            }
        }

        // Optional API close wrapped in try/catch: some Elementor versions throw on this call.
        if (window.elementorProFrontend && elementorProFrontend.modules && elementorProFrontend.modules.popup) {
            try {
                if (numericId) {
                    elementorProFrontend.modules.popup.closePopup({ id: numericId });
                } else {
                    elementorProFrontend.modules.popup.closePopup();
                }
            } catch (error) {
                // Ignore and rely on DOM fallbacks below.
            }
        }

        if ($popup && $popup.length) {
            // Last-resort fallback when Elementor close hooks are blocked.
            $popup.removeClass('elementor-active').hide().attr('aria-hidden', 'true');
            $('body, html')
                .removeClass('dialog-prevent-scroll elementor-popup-modal-open')
                .css('overflow', '');
        }
    }

    function closeVisibleElementorPopups() {
        var $visiblePopups = $('[id^="elementor-popup-modal-"]:visible, .elementor-popup-modal:visible, .dialog-widget:visible');

        $visiblePopups.each(function () {
            var $popup = $(this);
            $popup.find('.dialog-close-button').first().trigger('click');
            $popup.find('.dialog-widget-overlay').first().trigger('click');
            $popup.hide().attr('aria-hidden', 'true');
        });

        $('.dialog-widget-overlay:visible').hide();
        $('body, html')
            .removeClass('dialog-prevent-scroll elementor-popup-modal-open')
            .css('overflow', '');
    }

    function detectPopupIdFromForm($form) {
        var popupId = '';
        var modalId = $form.closest('.elementor-popup-modal').attr('id') || '';
        var match = modalId.match(/(\d+)$/);
        if (match && match[1]) {
            popupId = match[1];
        }

        return popupId;
    }

    function setRequestedPdf(pdfUrl) {
        $('.devmy-pdf-form [name="requested_pdf"]').val(pdfUrl || '');
    }

    function setMessage($form, message, isError) {
        if (!$form || !$form.length) {
            return;
        }

        var messageNode = $form.find('.devmy-pdf-message')[0];
        if (!messageNode) {
            return;
        }

        messageNode.textContent = message || '';
        messageNode.classList.toggle('is-error', !!isError);
        messageNode.classList.toggle('is-success', !isError && !!message);
    }

    function clearMessages() {
        $('.devmy-pdf-form').each(function () {
            setMessage($(this), '', false);
        });
    }

    $(document).on('click', '.devmy-pdf-download, .devmy-pdf-download a, a.devmy-pdf-download', function (event) {
        var $clicked = $(this);
        var $container = $clicked.hasClass('devmy-pdf-download') ? $clicked : $clicked.closest('.devmy-pdf-download');
        var $link = $clicked.is('a') ? $clicked : $container.find('a').first();
        var pdfUrl = readData($container, 'pdf-url') || readData($link, 'pdf-url') || $link.attr('href') || $container.attr('href');
        var target = readData($container, 'target') || readData($link, 'target') || $link.attr('target') || '_blank';
        var popupId = readData($container, 'popup-id') || readData($link, 'popup-id');

        if (hasUnlockedAccess()) {
            trackUnlockedDownload(pdfUrl);
            return;
        }

        event.preventDefault();
        pendingDownload = {
            url: pdfUrl,
            target: target,
            popupId: popupId
        };

        setRequestedPdf(pdfUrl);
        clearMessages();

        if (popupId && openElementorPopup(popupId)) {
            return;
        }

        openModal(pdfUrl);
    });

    $(document).on('click', '[data-close="1"]', function () {
        closeModal();
    });

    $(document).on('submit', '.devmy-pdf-form', function (event) {
        event.preventDefault();

        var $form = $(this);
        var $submit = $form.find('.devmy-pdf-submit');
        var payload;
        var fieldErrors;

        setMessage($form, '', false);
        $submit.prop('disabled', true);

        payload = {
            action: 'cookie_form_submit_pdf_gate',
            nonce: config.nonce || '',
            name: $.trim($form.find('[name="name"]').val()),
            email: $.trim($form.find('[name="email"]').val()),
            company: $.trim($form.find('[name="company"]').val()),
            data_storage_consent: $form.find('[name="data_storage_consent"]').is(':checked') ? '1' : '',
            source: window.location.href,
            requested_pdf: $.trim($form.find('[name="requested_pdf"]').val()) || (pendingDownload && pendingDownload.url ? pendingDownload.url : '')
        };

        fieldErrors = collectFieldErrors(payload);
        if (firstFieldError(fieldErrors)) {
            setMessage($form, firstFieldError(fieldErrors), true);
            $submit.prop('disabled', false);
            return;
        }

        $.post(config.ajaxUrl || '', payload)
            .done(function (response) {
                var errorData;
                var errorMessage;

                if (!response || !response.success) {
                    errorData = response && response.data ? response.data : {};
                    errorMessage = errorData.message || validationMessage('genericError', config.errorText || 'Errore durante l\'invio.');

                    fieldErrors = errorData.fieldErrors || errorData.field_errors || null;
                    if (firstFieldError(fieldErrors)) {
                        errorMessage = firstFieldError(fieldErrors);
                    }

                    setMessage($form, errorMessage, true);
                    return;
                }

                setUnlockedAccess();
                setLeadToken(response && response.data ? response.data.leadToken : '');
                closeModal();
                closeElementorPopup((pendingDownload && pendingDownload.popupId) || detectPopupIdFromForm($form), $form);
                closeVisibleElementorPopups();
                setTimeout(closeVisibleElementorPopups, 150);
                $form[0].reset();
                if (pendingDownload && pendingDownload.url) {
                    triggerDownload(pendingDownload.url, pendingDownload.target);
                }
                pendingDownload = null;
                setMessage($form, '', false);
            })
            .fail(function (jqXHR) {
                var errorMessage = validationMessage('genericError', config.errorText || 'Errore durante l\'invio.');

                if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = jqXHR.responseJSON.data.message;
                } else if (jqXHR && typeof jqXHR.responseText === 'string' && jqXHR.responseText.trim() === '-1') {
                    errorMessage = validationMessage('nonceError', 'Sessione scaduta. Ricarica la pagina e riprova.');
                }

                setMessage($form, errorMessage, true);
            })
            .always(function () {
                $submit.prop('disabled', false);
            });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
})(jQuery);

(function ($) {
    'use strict';

    var config = window.cookieFormPdfGate || window.devmyPdfGate || {};
    var cookieName = config.cookieName || 'cookie_form_pdf_gate_unlocked';
    var cookieDays = parseInt(config.cookieDays || 365, 10);
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

    function setUnlockedAccess() {
        var maxAge = cookieDays * 24 * 60 * 60;
        var secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = cookieName + '=1; path=/; max-age=' + maxAge + '; SameSite=Lax' + secureFlag;

        if (window.localStorage) {
            localStorage.setItem(cookieName, '1');
        }
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

    function closeElementorPopup(popupId) {
        if (!popupId) {
            return;
        }

        if (window.elementorProFrontend && elementorProFrontend.modules && elementorProFrontend.modules.popup) {
            elementorProFrontend.modules.popup.closePopup({ id: popupId });
            return;
        }

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

    $(document).on('click', '.devmy-pdf-download', function (event) {
        var $button = $(this);
        var pdfUrl = readData($button, 'pdf-url') || $button.attr('href');
        var target = readData($button, 'target') || '_blank';
        var popupId = readData($button, 'popup-id');

        if (hasUnlockedAccess()) {
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

        setMessage($form, '', false);
        $submit.prop('disabled', true);

        var payload = {
            action: 'cookie_form_submit_pdf_gate',
            nonce: config.nonce || '',
            name: $.trim($form.find('[name="name"]').val()),
            email: $.trim($form.find('[name="email"]').val()),
            company: $.trim($form.find('[name="company"]').val()),
            source: window.location.href,
            requested_pdf: $.trim($form.find('[name="requested_pdf"]').val())
        };

        $.post(config.ajaxUrl || '', payload)
            .done(function (response) {
                if (!response || !response.success) {
                    var errorMessage = response && response.data && response.data.message ? response.data.message : config.errorText;
                    setMessage($form, errorMessage, true);
                    return;
                }

                setUnlockedAccess();
                setMessage($form, config.successText || 'Download sbloccato.', false);

                setTimeout(function () {
                    closeModal();
                    if (pendingDownload && pendingDownload.popupId) {
                        closeElementorPopup(pendingDownload.popupId);
                    }
                    $form[0].reset();
                    if (pendingDownload && pendingDownload.url) {
                        triggerDownload(pendingDownload.url, pendingDownload.target);
                    }
                    pendingDownload = null;
                    setMessage($form, '', false);
                }, 350);
            })
            .fail(function () {
                setMessage($form, config.errorText || 'Errore durante l\'invio.', true);
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

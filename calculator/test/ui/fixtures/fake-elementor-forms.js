/*
 * Test double for Elementor Pro's form handler (modules/forms/assets/js/frontend/handlers/form-sender.js)
 * and for the country-code plugin's intl-tel-input setup. Same events, classes and markup changes:
 * submit -> preventDefault, waiting class, old messages removed, FormData + action + referrer posted with
 * jQuery.ajax (dataType json), then submit_success / form_destruct / reset + success message, or inline
 * field errors + a danger message.
 */
(function ($) {
	'use strict';
	window.elementorFrontendConfig = window.elementorFrontendConfig || { experimentalFeatures: { e_font_icon_svg: true } };
	window.elementorProFrontend = window.elementorProFrontend || { config: { ajaxurl: '/wp-admin/admin-ajax.php' } };

	function FormSender($element) {
		var $form = $element.find('.elementor-form');
		var $submitButton = $form.find('[type="submit"]');

		function beforeSend() {
			$form.animate({ opacity: '0.45' }, 500).addClass('elementor-form-waiting');
			$form.find('.elementor-message').remove();
			$form.find('.elementor-error').removeClass('elementor-error');
			$form.find('div.elementor-field-group').removeClass('error')
				.find('span.elementor-form-help-inline').remove().end()
				.find(':input').attr('aria-invalid', 'false');
			$submitButton.attr('disabled', 'disabled').find('> span')
				.prepend('<span class="elementor-button-text elementor-form-spinner"><i class="fa fa-spinner fa-spin"></i>&nbsp;</span>');
		}

		function getFormData() {
			var formData = new FormData($form[0]);
			formData.append('action', 'elementor_pro_forms_send_form');
			formData.append('referrer', location.toString());
			return formData;
		}

		function onSuccess(response) {
			$submitButton.removeAttr('disabled').find('.elementor-form-spinner').remove();
			$form.animate({ opacity: '1' }, 100).removeClass('elementor-form-waiting');
			if (!response.success) {
				if (response.data.errors) {
					$.each(response.data.errors, function (key, title) {
						$form.find('#form-field-' + key).parent().addClass('elementor-error')
							.append('<span class="elementor-message elementor-message-danger elementor-help-inline elementor-form-help-inline" role="alert">' + title + '</span>')
							.find(':input').attr('aria-invalid', 'true');
					});
					$form.trigger('error');
				}
				$form.append('<div class="elementor-message elementor-message-danger" role="alert">' + response.data.message + '</div>');
			} else {
				$form.trigger('submit_success', response.data);
				$form.trigger('form_destruct', response.data);
				$form.trigger('reset');
				var successClass = 'elementor-message elementor-message-success';
				if (window.elementorFrontendConfig.experimentalFeatures.e_font_icon_svg) {
					successClass += ' elementor-message-svg';
				}
				if (typeof response.data.message !== 'undefined' && response.data.message !== '') {
					$form.append('<div class="' + successClass + '" role="alert">' + response.data.message + '</div>');
				}
			}
		}

		function onError(xhr, desc) {
			$form.append('<div class="elementor-message elementor-message-danger" role="alert">' + desc + '</div>');
			$submitButton.html($submitButton.text()).removeAttr('disabled');
			$form.animate({ opacity: '1' }, 100).removeClass('elementor-form-waiting');
			$form.trigger('error');
		}

		$form.on('submit', function (event) {
			event.preventDefault();
			if ($form.hasClass('elementor-form-waiting')) {
				return false;
			}
			window.__lpFakeSubmits = (window.__lpFakeSubmits || 0) + 1;
			beforeSend();
			$.ajax({
				url: window.elementorProFrontend.config.ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: getFormData(),
				processData: false,
				contentType: false,
				success: onSuccess,
				error: onError
			});
		});
	}

	// Elementor runs its element handlers on DOM ready (after footer scripts such as calculator.js).
	$(function () {
		$('.elementor-widget-form').each(function () {
			FormSender($(this));
		});
		// Country-code plugin: intl-tel-input on tel fields, UAE by default.
		if (window.intlTelInput) {
			$('.elementor-field-type-tel input[type="tel"]').each(function () {
				window.intlTelInput(this, { initialCountry: 'ae', separateDialCode: true, preferredCountries: ['ae', 'sa', 'in', 'gb'] });
			});
		}
		window.__lpElementorReady = true;
	});
}(jQuery));

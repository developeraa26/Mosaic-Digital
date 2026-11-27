<?php
/**
 * The calculator's Elementor Pro form, taken from the page builder's output (page/out/page.json).
 *
 * - lp_ef_widget()            the form widget element (id + settings) from page.json.
 * - lp_ef_settings_for_display() the settings with the hidden fields' __dynamic__ tags resolved
 *                             (post-title, shortcode [geturl], request-arg), like get_settings_for_display().
 * - lp_ef_render()            the widget's front-end markup, as Elementor Pro 4.x renders it (structure of
 *                             the live hero form), so the UI and e2e harnesses use the real field list.
 * - lp_ef_handler_form()      the $form array Ajax_Handler::ajax_send_form() works with.
 * - lp_ef_actions()           fake save-to-database, email and zoho_Ledgers_Plus actions that record what
 *                             the real ones would send: replace_setting_shortcodes() of the real settings.
 * - lp_ef_honeypot()          Elementor Pro's honeypot field validation.
 *
 * Requires test/wp/env.php (WordPress stubs) first.
 */

const LP_EF_ZOHO_PREFIX = 'el_pro_zoho_zoho_Ledgers_Plus_';

/**
 * @return array page.json decoded.
 */
function lp_ef_page() {
	static $page = null;
	if ( null === $page ) {
		$raw  = is_file( LP_ENV_PAGE_JSON ) ? file_get_contents( LP_ENV_PAGE_JSON ) : '';
		$page = json_decode( (string) $raw, true );
		if ( ! is_array( $page ) ) {
			throw new RuntimeException( 'page/out/page.json is missing or invalid: run python3 calculator/page/build_page.py' );
		}
		if ( is_string( $page['data'] ) ) {
			$page['data'] = json_decode( $page['data'], true );
		}
	}
	return $page;
}

/**
 * @param array $elements Elementor elements.
 * @param string|null $id Widget id, or null for the form with the CSS class lp-calc-form.
 * @return array|null
 */
function lp_ef_find( array $elements, $id = null ) {
	foreach ( $elements as $el ) {
		if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
			$classes = isset( $el['settings']['_css_classes'] ) ? preg_split( '/\s+/', (string) $el['settings']['_css_classes'] ) : array();
			if ( null === $id ? in_array( 'lp-calc-form', $classes, true ) : $id === $el['id'] ) {
				return $el;
			}
		}
		if ( ! empty( $el['elements'] ) ) {
			$hit = lp_ef_find( $el['elements'], $id );
			if ( $hit ) {
				return $hit;
			}
		}
	}
	return null;
}

/**
 * @param string|null $id Widget id (as posted in form_id), or null for the calculator form.
 * @return array|null The form widget element.
 */
function lp_ef_widget( $id = null ) {
	$page = lp_ef_page();
	return lp_ef_find( $page['data'], $id );
}

/**
 * Resolves one "[elementor-tag id=… name=… settings=…]" string.
 *
 * @param string $tag  Dynamic tag text.
 * @param array  $ctx  page_title, query (array of GET args).
 * @return string
 */
function lp_ef_dynamic( $tag, array $ctx ) {
	if ( ! preg_match( '/\[elementor-tag id="([^"]*)" name="([^"]*)" settings="([^"]*)"\]/', (string) $tag, $m ) ) {
		throw new RuntimeException( 'unrecognised dynamic tag: ' . $tag );
	}
	$settings = json_decode( urldecode( $m[3] ), true );
	switch ( $m[2] ) {
		case 'post-title':
			return (string) $ctx['page_title'];
		case 'shortcode':
			return do_shortcode( (string) $settings['shortcode'] );
		case 'request-arg':
			$name = (string) $settings['param_name'];
			$q    = isset( $ctx['query'][ $name ] ) && is_string( $ctx['query'][ $name ] ) ? $ctx['query'][ $name ] : '';
			return sanitize_text_field( $q );
	}
	throw new RuntimeException( 'unsupported dynamic tag: ' . $m[2] );
}

/**
 * The widget settings with each form field's __dynamic__ values resolved.
 *
 * @param array $widget Form widget element.
 * @param array $ctx    page_title, query.
 * @return array
 */
function lp_ef_settings_for_display( array $widget, array $ctx = array() ) {
	$ctx      = array_merge(
		array(
			'page_title' => 'Build Your Package',
			'query'      => array(),
		),
		$ctx
	);
	$settings = $widget['settings'];
	foreach ( $settings['form_fields'] as $i => $field ) {
		$field = array_merge(
			array(
				'field_type'  => 'text',
				'field_label' => '',
				'required'    => '',
				'width'       => '100',
				'field_value' => '',
				'placeholder' => '',
			),
			$field
		);
		if ( ! empty( $field['__dynamic__'] ) ) {
			foreach ( $field['__dynamic__'] as $key => $tag ) {
				$field[ $key ] = lp_ef_dynamic( $tag, $ctx );
			}
		}
		$settings['form_fields'][ $i ] = $field;
	}
	return $settings;
}

/**
 * The $form array that Ajax_Handler::ajax_send_form() reads ($form['settings']).
 *
 * @param array $widget Form widget element.
 * @return array
 */
function lp_ef_handler_form( array $widget ) {
	return array(
		'id'       => $widget['id'],
		'settings' => lp_ef_settings_for_display( $widget ),
	);
}

function lp_ef_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/**
 * Front-end markup of the form widget, as Elementor Pro 4.x prints it (field groups, labels,
 * widths, required marks, hidden values, honeypot, tel pattern, submit button).
 *
 * @param array $widget Form widget element.
 * @param array $ctx    page_title, query, post_id.
 * @return string
 */
function lp_ef_render( array $widget, array $ctx = array() ) {
	$ctx      = array_merge(
		array(
			'page_title' => 'Build Your Package',
			'query'      => array(),
			'post_id'    => LP_ENV_PAGE_ID,
		),
		$ctx
	);
	$s        = lp_ef_settings_for_display( $widget, $ctx );
	$id       = $widget['id'];
	$size     = isset( $s['input_size'] ) ? $s['input_size'] : 'sm';
	$labels   = ! empty( $s['show_labels'] );
	$mark     = ! empty( $s['mark_required'] );
	$classes  = trim( 'elementor-element elementor-element-' . $id . ' ' . ( isset( $s['_css_classes'] ) ? $s['_css_classes'] : '' ) . ' elementor-button-align-' . ( isset( $s['button_align'] ) ? $s['button_align'] : 'stretch' ) . ' elementor-widget elementor-widget-form' );
	$data     = array(
		'step_next_label'     => 'Next',
		'step_previous_label' => 'Previous',
		'button_width'        => isset( $s['button_width'] ) ? (string) $s['button_width'] : '100',
		'step_type'           => 'number_text',
		'step_icon_shape'     => 'circle',
	);
	$fields   = '';
	foreach ( $s['form_fields'] as $f ) {
		$type  = $f['field_type'];
		$cid   = $f['custom_id'];
		$input = 'form-field-' . $cid;
		$name  = 'form_fields[' . $cid . ']';
		if ( 'honeypot' === $type ) {
			$fields .= "\t\t\t\t\t\t\t\t<div class=\"elementor-field-type-text\">\n\t\t\t\t\t<input size=\"1\" type=\"text\" name=\"" . lp_ef_attr( $name ) . '" id="' . lp_ef_attr( $input ) . '" class="elementor-field elementor-size-' . lp_ef_attr( $size ) . " \" style=\"display:none !important;\">\t\t\t\t</div>\n";
			continue;
		}
		$group = array( 'elementor-field-type-' . $type, 'elementor-field-group', 'elementor-column', 'elementor-field-group-' . $cid, 'elementor-col-' . ( '' !== (string) $f['width'] ? $f['width'] : '100' ) );
		if ( ! empty( $f['width_tablet'] ) ) {
			$group[] = 'elementor-md-' . $f['width_tablet'];
		}
		if ( ! empty( $f['width_mobile'] ) ) {
			$group[] = 'elementor-sm-' . $f['width_mobile'];
		}
		$required = ! empty( $f['required'] );
		if ( $required ) {
			$group[] = 'elementor-field-required';
			if ( $mark ) {
				$group[] = 'elementor-mark-required';
			}
		}
		$attrs = 'name="' . lp_ef_attr( $name ) . '" id="' . lp_ef_attr( $input ) . '"';
		$req   = $required ? ' required="required" aria-required="true"' : '';
		$value = '' !== (string) $f['field_value'] ? ' value="' . lp_ef_attr( $f['field_value'] ) . '"' : '';
		$ph    = '' !== (string) $f['placeholder'] ? ' placeholder="' . lp_ef_attr( $f['placeholder'] ) . '"' : '';
		$label = '';
		if ( 'hidden' !== $type && '' !== (string) $f['field_label'] ) {
			$label = "\t\t\t\t\t\t\t\t\t\t\t\t<label for=\"" . lp_ef_attr( $input ) . '" class="elementor-field-label' . ( $labels ? '' : ' elementor-screen-only' ) . "\">\n\t\t\t\t\t\t\t\t" . $f['field_label'] . "\t\t\t\t\t\t\t</label>\n";
		}
		switch ( $type ) {
			case 'textarea':
				$control = '<textarea class="elementor-field-textual elementor-field  elementor-size-' . lp_ef_attr( $size ) . '" ' . $attrs . ' rows="' . lp_ef_attr( isset( $f['rows'] ) ? $f['rows'] : 4 ) . '"' . $ph . $req . '>' . lp_ef_attr( $f['field_value'] ) . '</textarea>';
				break;
			case 'tel':
				$control = '<input size="1" type="tel" ' . $attrs . ' class="elementor-field elementor-size-' . lp_ef_attr( $size ) . '  elementor-field-textual"' . $ph . $value . $req . ' pattern="[0-9()#&amp;+*-=.]+" title="Only numbers and phone characters (#, -, *, etc) are accepted.">';
				break;
			case 'text':
			case 'email':
			case 'url':
			case 'password':
			case 'hidden':
			case 'search':
				$control = '<input size="1" type="' . $type . '" ' . $attrs . ' class="elementor-field elementor-size-' . lp_ef_attr( $size ) . '  elementor-field-textual"' . $ph . $value . $req . '>';
				break;
			default:
				throw new RuntimeException( 'field type not emulated: ' . $type );
		}
		$fields .= "\t\t\t\t\t\t\t\t<div class=\"" . implode( ' ', $group ) . "\">\n" . $label . "\t\t\t\t\t\t\t\t\t\t\t\t\t" . $control . "\n\t\t\t\t\t\t\t\t\t\t\t</div>\n";
	}
	$button = isset( $s['button_text'] ) ? $s['button_text'] : 'Send';
	$bsize  = isset( $s['button_size'] ) ? $s['button_size'] : 'sm';
	$bwidth = isset( $s['button_width'] ) && '' !== $s['button_width'] ? $s['button_width'] : '100';
	$name   = isset( $s['form_name'] ) ? $s['form_name'] : 'New Form';

	return "\t\t\t\t<div class=\"" . lp_ef_attr( $classes ) . '" data-id="' . lp_ef_attr( $id ) . '" data-element_type="widget" data-e-type="widget" data-settings="' . lp_ef_attr( json_encode( $data ) ) . "\" data-widget_type=\"form.default\">\n" .
		"\t\t\t\t\t\t\t<form class=\"elementor-form\" method=\"post\" name=\"" . lp_ef_attr( $name ) . '" aria-label="' . lp_ef_attr( $name ) . "\">\n" .
		"\t\t\t<input type=\"hidden\" name=\"post_id\" value=\"" . (int) $ctx['post_id'] . "\"/>\n" .
		"\t\t\t<input type=\"hidden\" name=\"form_id\" value=\"" . lp_ef_attr( $id ) . "\"/>\n" .
		"\t\t\t<input type=\"hidden\" name=\"referer_title\" value=\"" . lp_ef_attr( $ctx['page_title'] ) . "\" />\n\n" .
		"\t\t\t\t\t\t\t<input type=\"hidden\" name=\"queried_id\" value=\"" . (int) $ctx['post_id'] . "\"/>\n\t\t\t\n" .
		"\t\t\t<div class=\"elementor-form-fields-wrapper elementor-labels-above\">\n" . $fields .
		"\t\t\t\t\t\t\t\t<div class=\"elementor-field-group elementor-column elementor-field-type-submit elementor-col-" . lp_ef_attr( $bwidth ) . " e-form__buttons\">\n" .
		"\t\t\t\t\t<button class=\"elementor-button elementor-size-" . lp_ef_attr( $bsize ) . "\" type=\"submit\">\n" .
		"\t\t\t\t\t\t<span class=\"elementor-button-content-wrapper\">\n" .
		"\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t<span class=\"elementor-button-text\">" . lp_ef_attr( $button ) . "</span>\n" .
		"\t\t\t\t\t\t\t\t\t\t\t\t\t</span>\n\t\t\t\t\t</button>\n\t\t\t\t</div>\n" .
		"\t\t\t</div>\n\t\t</form>\n\t\t\t\t\t\t</div>\n";
}

/**
 * Elementor Pro's honeypot field: a filled honeypot is an invalid form, an empty one is dropped.
 */
function lp_ef_honeypot() {
	add_action(
		'elementor_pro/forms/validation/honeypot',
		static function ( $field, $record, $ajax_handler ) {
			if ( ! empty( $field['value'] ) ) {
				$ajax_handler->add_error( $field['id'], 'Invalid Form.' );
			} else {
				$record->remove_field( $field['id'] );
			}
		},
		10,
		3
	);
}

/**
 * Fake form actions. Each records what the real action would send, by calling
 * replace_setting_shortcodes() on the real settings from page.json (Elementor Pro's Email action and
 * the "Elementor Pro Form Widget - Zoho CRM - Integration" plugin read the settings this way).
 *
 * @param array $log Receives 'saved', 'team_email' and 'zoho' entries.
 * @param array $opts zoho_throws (bool): the Zoho action throws, like a failed API call.
 * @return array<string,callable>
 */
function lp_ef_actions( array &$log, array $opts = array() ) {
	$log = array_merge(
		array(
			'saved'      => array(),
			'team_email' => array(),
			'zoho'       => array(),
		),
		$log
	);
	return array(
		'save-to-database'  => static function ( $record ) use ( &$log ) {
			$values = array();
			foreach ( $record->get( 'fields' ) as $id => $field ) {
				$values[ $id ] = $field['value'];
			}
			$log['saved'][] = $values;
		},
		'email'             => static function ( $record ) use ( &$log ) {
			$s        = $record->get( 'form_settings' );
			$reply_to = '';
			$sent     = (array) $record->get( 'sent_data' );
			$field    = isset( $s['email_reply_to'] ) ? (string) $s['email_reply_to'] : '';
			if ( '' !== $field && ! empty( $sent[ $field ] ) && is_email( $sent[ $field ] ) ) {
				$reply_to = $sent[ $field ];
			}
			$log['team_email'][] = array(
				'to'           => $record->replace_setting_shortcodes( trim( (string) $s['email_to'] ) ),
				'subject'      => $record->replace_setting_shortcodes( trim( (string) $s['email_subject'] ) ),
				'content'      => $record->replace_setting_shortcodes( trim( (string) $s['email_content'] ) ),
				'from'         => $record->replace_setting_shortcodes( trim( (string) $s['email_from'] ) ),
				'from_name'    => $record->replace_setting_shortcodes( trim( (string) $s['email_from_name'] ) ),
				'reply_to'     => $reply_to,
				'content_type' => isset( $s['email_content_type'] ) ? $s['email_content_type'] : '',
			);
		},
		'zoho_Ledgers_Plus' => static function ( $record ) use ( &$log, $opts ) {
			$lead = array();
			foreach ( $record->get( 'form_settings' ) as $key => $value ) {
				if ( 0 === strpos( $key, LP_EF_ZOHO_PREFIX ) && is_string( $value ) ) {
					$lead[ substr( $key, strlen( LP_EF_ZOHO_PREFIX ) ) ] = $record->replace_setting_shortcodes( $value );
				}
			}
			$log['zoho'][] = $lead;
			if ( ! empty( $opts['zoho_throws'] ) ) {
				throw new Exception( 'Zoho CRM API error (test)' );
			}
		},
	);
}

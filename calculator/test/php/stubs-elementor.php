<?php
/**
 * Fake Elementor Pro form classes that follow the real API (Elementor Pro 4.3.1):
 * Form_Record (fields keyed by custom_id with id/type/title/value/raw_value/required,
 * validate(), process_fields(), get(), get_form_settings(), update_field(),
 * replace_setting_shortcodes()) and Ajax_Handler (add_error(), add_error_message(),
 * add_response_data(), ajax_send_form() in the real order, send()).
 */

namespace ElementorPro\Modules\Forms\Classes;

class Form_Record {
	protected $sent_data;
	protected $fields        = array();
	protected $form_type     = 'form';
	protected $form_settings = array();
	protected $files         = array();
	protected $meta          = array();

	public function __construct( $sent_data, $form ) {
		$this->form_settings = $form['settings'];
		$this->sent_data     = $this->set_sent_data( $sent_data );
		$this->set_fields();
	}

	private function set_sent_data( $sent_data ) {
		$out = array();
		foreach ( $this->form_settings['form_fields'] as $form_field ) {
			$id = $form_field['custom_id'];
			if ( isset( $sent_data[ $id ] ) ) {
				$out[ $id ] = $sent_data[ $id ];
			}
		}
		return $out;
	}

	private function set_fields() {
		foreach ( $this->form_settings['form_fields'] as $form_field ) {
			$field = array(
				'id'        => $form_field['custom_id'],
				'type'      => $form_field['field_type'],
				'title'     => isset( $form_field['field_label'] ) ? $form_field['field_label'] : '',
				'value'     => '',
				'raw_value' => '',
				'required'  => ! empty( $form_field['required'] ),
			);
			if ( isset( $this->sent_data[ $form_field['custom_id'] ] ) ) {
				$value              = $this->sent_data[ $form_field['custom_id'] ];
				$field['raw_value'] = $value;
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				$field['value'] = $value;
			}
			$this->fields[ $form_field['custom_id'] ] = $field;
		}
	}

	public function validate( $ajax_handler ) {
		foreach ( $this->fields as $id => $field ) {
			if ( ! empty( $field['required'] ) && '' === $field['value'] && 'upload' !== $field['type'] ) {
				$ajax_handler->add_error( $id, Ajax_Handler::get_default_message( Ajax_Handler::FIELD_REQUIRED, $this->form_settings ) );
			}
			do_action( "elementor_pro/forms/validation/{$field['type']}", $field, $this, $ajax_handler );
		}
		do_action( 'elementor_pro/forms/validation', $this, $ajax_handler );
		return empty( $ajax_handler->errors );
	}

	public function process_fields( $ajax_handler ) {
		foreach ( $this->fields as $id => $field ) {
			$this->fields[ $id ]['value'] = $this->sanitize_field( $field, $field['value'] );
			do_action( "elementor_pro/forms/process/{$field['type']}", $this->fields[ $id ], $this, $ajax_handler );
		}
		do_action( 'elementor_pro/forms/process', $this, $ajax_handler );
	}

	private function sanitize_field( $field, $value ) {
		switch ( $field['type'] ) {
			case 'text':
			case 'password':
			case 'hidden':
			case 'search':
			case 'checkbox':
			case 'radio':
			case 'select':
				return sanitize_text_field( $value );
			case 'email':
				return sanitize_email( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'tel':
				return preg_replace( '/[^0-9() \-+]/', '', (string) $value );
			default:
				return apply_filters( "elementor_pro/forms/sanitize/{$field['type']}", $value, $field );
		}
	}

	public function get( $property ) {
		return isset( $this->{$property} ) ? $this->{$property} : null;
	}

	public function set( $property, $value ) {
		$this->{$property} = $value;
	}

	public function get_form_settings( $setting ) {
		return isset( $this->form_settings[ $setting ] ) ? $this->form_settings[ $setting ] : null;
	}

	public function remove_field( $id ) {
		unset( $this->fields[ $id ] );
	}

	/** Like Elementor Pro: does nothing when the field does not exist. */
	public function update_field( $field_id, $field, $value ) {
		if ( ! isset( $this->fields[ $field_id ] ) ) {
			return;
		}
		$this->fields[ $field_id ][ $field ] = $value;
	}

	public function replace_setting_shortcodes( $setting, $urlencode = false ) {
		return preg_replace_callback(
			'/(\[field[^]]*id="(\w+)"[^]]*\])/',
			function ( $matches ) use ( $urlencode ) {
				$value = '';
				if ( isset( $this->fields[ $matches[2] ] ) ) {
					$value = $this->fields[ $matches[2] ]['value'];
				}
				if ( $urlencode ) {
					$value = urlencode( $value );
				}
				return $value;
			},
			$setting
		);
	}
}

class Ajax_Handler {
	const SUCCESS        = 'success';
	const ERROR          = 'error';
	const FIELD_REQUIRED = 'required_field';
	const INVALID_FORM   = 'invalid';
	const SERVER_ERROR   = 'server';

	public $is_success = true;
	public $messages   = array(
		'error'       => array(),
		'success'     => array(),
		'admin_error' => array(),
	);
	public $data       = array();
	public $errors     = array();

	private $current_form;

	public static function get_default_messages() {
		return array(
			self::SUCCESS        => 'Your submission was successful.',
			self::ERROR          => 'Your submission failed because of an error.',
			self::FIELD_REQUIRED => 'This field is required.',
			self::INVALID_FORM   => 'There\'s something wrong. The form is invalid.',
			self::SERVER_ERROR   => 'Your submission failed because of a server error.',
		);
	}

	public static function get_default_message( $id, $settings ) {
		if ( ! empty( $settings['custom_messages'] ) ) {
			$key = $id . '_message';
			if ( isset( $settings[ $key ] ) ) {
				return $settings[ $key ];
			}
		}
		$messages = self::get_default_messages();
		return isset( $messages[ $id ] ) ? $messages[ $id ] : '';
	}

	public function get_current_form() {
		return $this->current_form;
	}

	public function add_response_data( $key, $data ) {
		$this->data[ $key ] = $data;
		return $this;
	}

	public function add_success_message( $message ) {
		$this->messages['success'][] = $message;
		return $this;
	}

	public function add_error_message( $message ) {
		$this->messages['error'][] = $message;
		$this->set_success( false );
		return $this;
	}

	public function add_admin_error_message( $message ) {
		$this->messages['admin_error'][] = $message;
		$this->set_success( false );
		return $this;
	}

	public function add_error( $field, $message = '' ) {
		$this->errors[ $field ] = $message;
		$this->set_success( false );
		return $this;
	}

	public function set_success( $bool ) {
		$this->is_success = $bool;
		return $this;
	}

	/**
	 * The real submission order. $actions maps an action name to a callable($record, $handler).
	 * Returns what send() would JSON-encode, plus the record for inspection.
	 */
	public function ajax_send_form( array $form, array $form_fields, array $actions ) {
		$this->current_form = $form;
		$record             = new Form_Record( $form_fields, $form );

		if ( ! $record->validate( $this ) ) {
			return $this->send( $record );
		}

		$record->process_fields( $this );

		if ( ! empty( $this->errors ) ) {
			return $this->send( $record );
		}

		$record = apply_filters( 'elementor_pro/forms/record/actions_before', $record, $this );

		foreach ( $form['settings']['submit_actions'] as $action ) {
			if ( ! isset( $actions[ $action ] ) ) {
				continue;
			}
			try {
				call_user_func( $actions[ $action ], $record, $this );
			} catch ( \Exception $e ) {
				$this->add_admin_error_message( $action . ' ' . $e->getMessage() );
				$this->add_error_message( self::get_default_message( self::ERROR, $form['settings'] ) );
			}
		}

		do_action( 'elementor_pro/forms/new_record', $record, $this );

		return $this->send( $record );
	}

	public function send( $record = null ) {
		$settings = $this->current_form ? $this->current_form['settings'] : array();
		if ( $this->is_success ) {
			return array(
				'success' => true,
				'data'    => array(
					'message' => self::get_default_message( self::SUCCESS, $settings ),
					'data'    => $this->data,
				),
				'record'  => $record,
			);
		}
		if ( empty( $this->messages['error'] ) && ! empty( $this->errors ) ) {
			$this->add_error_message( self::get_default_message( self::INVALID_FORM, $settings ) );
		}
		return array(
			'success' => false,
			'data'    => array(
				'message' => implode( '<br>', $this->messages['error'] ),
				'errors'  => $this->errors,
				'data'    => $this->data,
			),
			'record'  => $record,
		);
	}
}

/**
 * Elementor Pro's Tel field (modules/forms/fields/tel.php). Field_Base hooks its validation() on
 * elementor_pro/forms/validation/tel at priority 9, before any plugin, and it accepts only
 * [0-9()#&+*-=.]: no spaces. The browser's pattern check is off in the calculator (novalidate), so
 * calculator.js strips the spaces before Elementor posts the form.
 */
class Tel_Field {
	const MESSAGE = 'The field accepts only numbers and phone characters (#, -, *, etc).';

	public static function validation( $field, $record, $ajax_handler ) {
		if ( empty( $field['value'] ) ) {
			return;
		}
		if ( preg_match( '/^[0-9()#&+*-=.]+$/', $field['value'] ) !== 1 ) {
			$ajax_handler->add_error( $field['id'], self::MESSAGE );
		}
	}
}

\add_action( 'elementor_pro/forms/validation/tel', array( Tel_Field::class, 'validation' ), 9, 3 );

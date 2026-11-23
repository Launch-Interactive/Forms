<?php
/**
 * Front-end HTML output. Deliberately minimal, semantic markup with no inline styles so the
 * active theme's form styles apply. The submit button carries `wp-element-button` so block
 * themes style it like every other button via theme.json.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Renders forms.
 */
class Renderer {

	/** @var Plugin */
	private $plugin;

	/**
	 * Instance counter per form so the same form can appear more than once on a page.
	 *
	 * @var array<int,int>
	 */
	private $instances = array();

	/** @var Form|null Form being rendered (captcha fields need it). */
	private $current_form = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Reset per-request instance counters (used by tests).
	 */
	public function reset() {
		$this->instances = array();
	}

	/**
	 * Render a form by ID.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Optional: 'class' extra wrapper classes.
	 * @return string
	 */
	public function render_id( $form_id, array $args = array() ) {
		$form = $this->plugin->forms->get( $form_id );
		if ( ! $form ) {
			return current_user_can( 'edit_posts' )
				? '<p class="lf-notice">' . esc_html__( 'Form not found. It may have been deleted or is not published.', 'launch-forms' ) . '</p>'
				: '';
		}
		return $this->render( $form, $args );
	}

	/**
	 * Render a form.
	 *
	 * @param Form  $form Form.
	 * @param array $args Optional: 'class'.
	 * @return string
	 */
	public function render( Form $form, array $args = array() ) {
		$this->plugin->frontend->enqueue_assets( $form );

		$this->instances[ $form->id ] = ( $this->instances[ $form->id ] ?? 0 ) + 1;
		$instance                     = $this->instances[ $form->id ];
		$prefix                       = 'lf-' . $form->id . '-' . $instance;
		$html_id                      = 1 === $instance ? 'lf-form-' . $form->id : 'lf-form-' . $form->id . '-' . $instance;

		$result = $this->plugin->processor->get_result( $form->id );

		// Success after a non-JavaScript (redirected) submission.
		$sent    = isset( $_GET['lf_sent'] ) && (int) $_GET['lf_sent'] === $form->id; // phpcs:ignore WordPress.Security.NonceVerification
		$message = '';
		if ( $sent || ( $result && $result->success ) ) {
			$message = $result && $result->success ? $this->success_message( $form, $result ) : $this->redirected_message( $form );
			if ( $form->settings['hide_form_on_success'] ) {
				return '<div id="' . esc_attr( $html_id ) . '" class="lf-message lf-message--success" role="status">' . $message . '</div>';
			}
			$result = null; // Show a fresh form below the message.
		}

		$values = $result ? $result->values : array();
		$errors = $result ? $result->errors : array();

		$visibility = Conditional_Logic::visibility( $form->fields, $result ? $result->values : $this->default_values( $form ) );

		$attrs = array(
			'id'           => $html_id,
			'class'        => trim( 'lf-form ' . ( $args['class'] ?? '' ) ),
			'method'       => 'post',
			'data-lf-form' => $form->id,
		);
		if ( '' !== $form->settings['analytics_event'] ) {
			$attrs['data-lf-event']      = $form->settings['analytics_event'];
			$attrs['data-lf-event-form'] = $form->title;
		}
		if ( $form->has_field_type( 'file' ) ) {
			$attrs['enctype'] = 'multipart/form-data';
		}

		$html = '<form' . self::attrs( $attrs ) . '>';

		if ( '' !== $message ) {
			$html .= '<div class="lf-message lf-message--success" role="status">' . $message . '</div>';
		} elseif ( $result && ! $result->success ) {
			$html .= '<div class="lf-message lf-message--error" role="alert">' . esc_html( $result->message ) . '</div>';
		}

		$html              .= '<div class="lf-fields">';
		$this->current_form = $form;
		foreach ( $form->fields as $field ) {
			$value = array_key_exists( $field['name'], $values ) ? $values[ $field['name'] ] : null;
			$html .= $this->render_field( $field, $prefix, $value, $errors[ $field['name'] ] ?? '', $visibility[ $field['name'] ] ?? true );
		}
		$this->current_form = null;
		$html              .= '</div>';

		$html .= $this->honeypot( $prefix );
		$html .= '<input type="hidden" name="lf_form_id" value="' . esc_attr( $form->id ) . '">';
		if ( is_user_logged_in() ) {
			// Lets a plain POST be credited to the logged-in user (see Frontend::verified_user_id()).
			// Logged-out pages stay nonce-free so they can be cached.
			$html .= '<input type="hidden" name="lf_nonce" value="' . esc_attr( wp_create_nonce( 'launch_forms_submit_' . $form->id ) ) . '">';
		}
		$html .= '<div class="lf-submit"><button type="submit" class="wp-element-button">' . esc_html( $form->settings['submit_label'] ) . '</button></div>';
		$html .= '</form>';

		/**
		 * Filters the rendered form HTML.
		 *
		 * @param string $html Form HTML.
		 * @param Form   $form Form.
		 */
		return apply_filters( 'launch_forms_form_html', $html, $form );
	}

	/**
	 * Rendered success message HTML, with merge tags such as {field:email} filled in from the
	 * submission (or removed when there is no submission to take them from).
	 *
	 * @param Form                   $form   Form.
	 * @param Submission_Result|null $result Successful submission.
	 * @return string
	 */
	public function success_message( Form $form, ?Submission_Result $result = null ) {
		// Visitor values are escaped as they're inserted into the admin's (already filtered) HTML.
		$html = $this->plugin->notifications->replace_tags(
			wp_kses_post( $form->settings['success_message'] ),
			$form,
			$result ? $result->entries : array(),
			$result ? $result->context : array(),
			'esc_html'
		);
		return wpautop( $html );
	}

	/**
	 * Success message after a no-JavaScript submission redirected back to the page. If the
	 * message used merge tags, the filled-in version was kept briefly under a random token.
	 *
	 * @param Form $form Form.
	 * @return string
	 */
	private function redirected_message( Form $form ) {
		$token = isset( $_GET['lf_msg'] ) ? sanitize_key( wp_unslash( $_GET['lf_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $token ) {
			$stored = get_transient( Frontend::MESSAGE_TRANSIENT . $token );
			if ( is_array( $stored ) && (int) ( $stored['form'] ?? 0 ) === $form->id && isset( $stored['html'] ) ) {
				return (string) $stored['html'];
			}
		}
		return $this->success_message( $form );
	}

	/**
	 * Default values for initial visibility.
	 *
	 * @param Form $form Form.
	 * @return array
	 */
	private function default_values( Form $form ) {
		$values = array();
		foreach ( $form->fields as $field ) {
			$values[ $field['name'] ] = 'checkbox' === $field['type'] || ! empty( $field['multiple'] )
				? array_filter( array_map( 'trim', explode( ',', $field['default'] ) ), 'strlen' )
				: $field['default'];
		}
		return $values;
	}

	/**
	 * Render one field.
	 *
	 * @param array             $field   Field.
	 * @param string            $prefix  ID prefix.
	 * @param string|array|null $value   Submitted value (null = use default).
	 * @param string            $error   Error message.
	 * @param bool              $visible Initial visibility.
	 * @return string
	 */
	public function render_field( array $field, $prefix, $value, $error, $visible ) {
		$type = $field['type'];
		$id   = $prefix . '-' . $field['name'];
		$name = 'lf[' . $field['name'] . ']';

		if ( null === $value ) {
			$value = $field['default'];
			if ( 'checkbox' === $type || ! empty( $field['multiple'] ) ) {
				$value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' );
			}
		}

		if ( 'hidden' === $type ) {
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_array( $value ) ? '' : $value ) . '">';
		}

		$wrapper = array(
			'class'        => trim( 'lf-field lf-field--' . $type . ( ! empty( $field['width'] ) && 'full' !== $field['width'] ? ' lf-field--' . $field['width'] : '' ) . ( $field['required'] ? ' lf-field--required' : '' ) . ( $error ? ' lf-field--error' : '' ) . ' ' . $field['css_class'] ),
			'data-lf-name' => $field['name'],
		);
		if ( ! empty( $field['conditions']['enabled'] ) ) {
			$wrapper['data-lf-conditions'] = wp_json_encode( $field['conditions'] );
		}
		if ( ! $visible ) {
			$wrapper['hidden'] = true;
		}

		if ( 'content' === $type ) {
			return '<div' . self::attrs( $wrapper ) . '>' . wp_kses_post( $field['content'] ) . '</div>';
		}

		if ( in_array( $type, Form_Config::CAPTCHA_TYPES, true ) ) {
			// Rendered only when the provider's keys are set (otherwise no check runs either).
			$widget = $this->current_form && $this->plugin->captcha->provider_for( $this->current_form ) === $type ? $this->plugin->captcha->render( $this->current_form ) : '';
			if ( '' === $widget ) {
				return '';
			}
			$wrapper['class'] .= ' lf-field--captcha';
			$error_html        = $error ? '<p class="lf-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $error ) . '</p>' : '';
			return '<div' . self::attrs( $wrapper ) . '>' . $widget . $error_html . '</div>';
		}

		$describedby = array();
		$desc_html   = '';
		$description = $field['description'];
		if ( 'file' === $type ) {
			/* translators: 1: allowed extensions, 2: max size in MB. */
			$hint        = sprintf( __( 'Allowed: %1$s. Max %2$s MB.', 'launch-forms' ), implode( ', ', $field['allowed_types'] ), self::format_number( $field['max_size'] ) );
			$description = trim( $description . ' ' . $hint );
		}
		if ( '' !== $description ) {
			$describedby[] = $id . '-desc';
			$desc_html     = '<p class="lf-description" id="' . esc_attr( $id . '-desc' ) . '">' . esc_html( $description ) . '</p>';
		}
		$error_html = '';
		if ( $error ) {
			$describedby[] = $id . '-error';
			$error_html    = '<p class="lf-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $error ) . '</p>';
		}

		$common = array();
		if ( $describedby ) {
			$common['aria-describedby'] = implode( ' ', $describedby );
		}
		if ( $error ) {
			$common['aria-invalid'] = 'true';
		}
		if ( ! $visible ) {
			$common['disabled'] = true;
		}

		$required_mark = $field['required'] ? $this->required_mark( $field ) : '';
		$label         = ( 'consent' === $type ? wp_kses( $field['label'], Form_Config::LABEL_HTML ) : esc_html( $field['label'] ) ) . $required_mark;

		// Choice groups use fieldset/legend.
		if ( 'radio' === $type || 'checkbox' === $type ) {
			$selected = array_map( 'strval', (array) $value );
			$options  = '';
			foreach ( $field['options'] as $i => $option ) {
				$opt_id = $id . '-' . $i;
				$input  = array(
					'type'  => $type,
					'id'    => $opt_id,
					'name'  => 'checkbox' === $type ? $name . '[]' : $name,
					'value' => $option['value'],
				) + $common;
				if ( in_array( (string) $option['value'], $selected, true ) ) {
					$input['checked'] = true;
				}
				if ( 'radio' === $type && $field['required'] ) {
					$input['required'] = true;
				}
				unset( $input['aria-describedby'], $input['aria-invalid'] );
				$options .= '<div class="lf-option"><input' . self::attrs( $input ) . '> <label for="' . esc_attr( $opt_id ) . '">' . esc_html( $option['label'] ) . '</label></div>';
			}
			$fieldset_attrs = $wrapper;
			if ( $describedby ) {
				$fieldset_attrs['aria-describedby'] = implode( ' ', $describedby );
			}
			if ( ! $visible ) {
				$fieldset_attrs['disabled'] = true;
			}
			$fieldset_attrs['class'] .= ' lf-fieldset';
			return '<fieldset' . self::attrs( $fieldset_attrs ) . '><legend>' . $label . '</legend>' . $options . $desc_html . $error_html . '</fieldset>';
		}

		$input = array(
			'id'   => $id,
			'name' => $name,
		) + $common;
		if ( $field['required'] ) {
			$input['required'] = true;
		}
		if ( '' !== $field['placeholder'] && in_array( $type, array( 'text', 'email', 'url', 'tel', 'number', 'textarea' ), true ) ) {
			$input['placeholder'] = $field['placeholder'];
		}

		if ( 'consent' === $type ) {
			$input['type']  = 'checkbox';
			$input['value'] = '1';
			if ( '' !== (string) ( is_array( $value ) ? '' : $value ) && '0' !== (string) $value ) {
				$input['checked'] = true;
			}
			return '<div' . self::attrs( $wrapper ) . '><input' . self::attrs( $input ) . '> <label for="' . esc_attr( $id ) . '">' . $label . '</label>' . $desc_html . $error_html . '</div>';
		}

		$label_html = '<label for="' . esc_attr( $id ) . '">' . $label . '</label>';

		switch ( $type ) {
			case 'textarea':
				$input['rows'] = 5;
				$control       = '<textarea' . self::attrs( $input ) . '>' . esc_textarea( is_array( $value ) ? '' : $value ) . '</textarea>';
				break;

			case 'select':
				$selected = array_map( 'strval', (array) $value );
				if ( $field['multiple'] ) {
					$input['name']     = $name . '[]';
					$input['multiple'] = true;
				}
				unset( $input['placeholder'] );
				$options = '';
				if ( ! $field['multiple'] ) {
					$options .= '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( '— Select —', 'launch-forms' ) ) . '</option>';
				}
				foreach ( $field['options'] as $option ) {
					$options .= '<option value="' . esc_attr( $option['value'] ) . '"' . ( in_array( (string) $option['value'], $selected, true ) ? ' selected' : '' ) . '>' . esc_html( $option['label'] ) . '</option>';
				}
				$control = '<select' . self::attrs( $input ) . '>' . $options . '</select>';
				break;

			case 'country':
				unset( $input['placeholder'] );
				$input['autocomplete'] = 'country-name';
				$current               = is_array( $value ) ? '' : (string) $value;
				$options               = '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( '— Select —', 'launch-forms' ) ) . '</option>';
				foreach ( Countries::all() as $code => $country ) {
					$options .= '<option value="' . esc_attr( $country ) . '" data-code="' . esc_attr( $code ) . '"' . selected( $current, $country, false ) . '>' . esc_html( $country ) . '</option>';
				}
				$control = '<select' . self::attrs( $input ) . '>' . $options . '</select>';
				break;

			case 'file':
				$input['type']   = 'file';
				$input['accept'] = implode( ',', array_map( static function ( $ext ) {
					return '.' . $ext;
				}, $field['allowed_types'] ) );
				$control         = '<input' . self::attrs( $input ) . '>';
				break;

			default:
				$input['type']  = $type;
				$input['value'] = is_array( $value ) ? '' : $value;
				if ( 'number' === $type ) {
					foreach ( array( 'min', 'max', 'step' ) as $key ) {
						if ( '' !== $field[ $key ] ) {
							$input[ $key ] = $field[ $key ];
						}
					}
				}
				$autocomplete = array(
					'email' => 'email',
					'tel'   => 'tel',
					'url'   => 'url',
				);
				if ( isset( $autocomplete[ $type ] ) ) {
					$input['autocomplete'] = $autocomplete[ $type ];
				}
				$control = '<input' . self::attrs( $input ) . '>';
		}

		return '<div' . self::attrs( $wrapper ) . '>' . $label_html . $control . $desc_html . $error_html . '</div>';
	}

	/**
	 * Honeypot field. Bots fill it, people never see it.
	 *
	 * @param string $prefix ID prefix.
	 * @return string
	 */
	private function honeypot( $prefix ) {
		$id = $prefix . '-hp';
		return '<div class="lf-hp" aria-hidden="true"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Leave this field empty', 'launch-forms' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" name="lf_hp" value="" tabindex="-1" autocomplete="off"></div>';
	}

	/**
	 * Build an attribute string. `true` renders a boolean attribute, false/null are skipped.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function attrs( array $attrs ) {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$out .= true === $value ? ' ' . esc_attr( $key ) : ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $value ) . '"';
		}
		return $out;
	}

	/**
	 * The marker after a required field's label: "*" by default, or the text set under
	 * Forms → Settings, e.g. "(required)". Hidden from screen readers, which already announce
	 * the field as required from its `required` attribute.
	 *
	 * @param array $field Field.
	 * @return string HTML ('' when the marker is empty).
	 */
	private function required_mark( array $field ) {
		/**
		 * Filters the text shown after required field labels.
		 *
		 * @param string $marker Marker text from settings (default "*").
		 * @param array  $field  Field.
		 */
		$marker = (string) apply_filters( 'launch_forms_required_marker', (string) $this->plugin->settings->get( 'required_marker' ), $field );
		if ( '' === $marker ) {
			return '';
		}
		return ' <span class="lf-required" aria-hidden="true">' . esc_html( $marker ) . '</span>';
	}

	/**
	 * Format a number without trailing zeros.
	 *
	 * @param float $number Number.
	 * @return string
	 */
	private static function format_number( $number ) {
		return rtrim( rtrim( number_format( (float) $number, 2, '.', '' ), '0' ), '.' );
	}
}

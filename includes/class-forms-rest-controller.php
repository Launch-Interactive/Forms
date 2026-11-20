<?php
/**
 * REST controller for forms that hides form definitions from people who cannot edit them.
 *
 * Form configs contain notification addresses and Mailchimp settings, so unlike regular
 * posts they must never be readable anonymously through /wp/v2/.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Restricts read access to editors.
 */
class Forms_Rest_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Listing requires the ability to edit forms.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$check = $this->can_edit_forms();
		return true === $check ? parent::get_items_permissions_check( $request ) : $check;
	}

	/**
	 * Reading one form requires the ability to edit it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->forbidden();
		}
		return parent::get_item_permissions_check( $request );
	}

	/**
	 * Check the type-level edit capability.
	 *
	 * @return true|\WP_Error
	 */
	private function can_edit_forms() {
		$type = get_post_type_object( $this->post_type );
		return current_user_can( $type->cap->edit_posts ) ? true : $this->forbidden();
	}

	/**
	 * Forbidden error.
	 *
	 * @return \WP_Error
	 */
	private function forbidden() {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view forms.', 'launch-forms' ), array( 'status' => rest_authorization_required_code() ) );
	}
}

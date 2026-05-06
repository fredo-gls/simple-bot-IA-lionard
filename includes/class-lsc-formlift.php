<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_FormLift {

	public function __construct() {
		add_action( 'formlift_success_submit', array( $this, 'capture_successful_submission' ) );
	}

	public function capture_successful_submission( $form_id ) {
		$session_id = isset( $_POST['lsc_session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lsc_session_id'] ) ) : '';
		if ( '' === $session_id ) {
			return;
		}

		$form_data = $this->extract_form_data( $_POST );

		LSC_Conversations::mark_rdv_submission(
			$session_id,
			array(
				'rdv_url'     => isset( $_POST['lsc_rdv_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['lsc_rdv_url'] ) ) : '',
				'rdv_type'    => isset( $_POST['lsc_rdv_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lsc_rdv_type'] ) ) : '',
				'form_source' => 'formlift',
				'form_data'   => array_merge(
					array(
						'formlift_form_id' => absint( $form_id ),
					),
					$form_data
				),
				'page_url'    => isset( $_POST['lsc_page_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['lsc_page_url'] ) ) : '',
				'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			)
		);
	}

	private function extract_form_data( array $source ): array {
		$skip_keys = array(
			'action',
			'form_id',
			'timeZone',
			'lsc_session_id',
			'lsc_rdv_type',
			'lsc_rdv_url',
			'lsc_page_url',
			'lsc_form_source',
			'inf_form_xid',
			'inf_form_name',
			'infusionsoft_version',
		);

		$data = array();
		foreach ( $source as $key => $value ) {
			$key = sanitize_text_field( wp_unslash( (string) $key ) );
			if ( in_array( $key, $skip_keys, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = array_map(
					static function ( $item ) {
						return sanitize_text_field( wp_unslash( (string) $item ) );
					},
					$value
				);
			} else {
				$data[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		return $data;
	}
}

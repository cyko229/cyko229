<?php
/**
 * API d'administration (réservée aux gestionnaires de la boutique) : créer et configurer les formations à distance,
 * par exemple depuis un assistant IA authentifié par mot de passe d'application.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Rest {

	const NS = 'mla/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function routes() {
		$admin = array( __CLASS__, 'can_manage' );

		register_rest_route(
			self::NS,
			'/courses',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => function () {
						return array_map( array( __CLASS__, 'course_summary' ), MLA_Courses::all( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'callback'            => array( __CLASS__, 'save_course' ),
					'args'                => array(
						'id'         => array( 'type' => 'integer' ),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'private' ) ),
						'structure'  => array( 'type' => 'object' ),
						'drive_urls' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'products'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/courses/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function ( $request ) {
					$post = get_post( (int) $request['id'] );
					if ( ! $post || MLA_Courses::POST_TYPE !== $post->post_type ) {
						return new WP_Error( 'mla_not_found', 'Formation introuvable.', array( 'status' => 404 ) );
					}
					return self::course_summary( $post ) + array( 'structure' => MLA_Courses::structure( $post->ID ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => array( 'urls' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
				'callback'            => function ( $request ) {
					$result = MLA_Drive::import_folders( $request['urls'], mla_option( 'drive_api_key' ) );
					return is_wp_error( $result ) ? $result : MLA_Courses::sanitize_structure( $result );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/enroll',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => array(
					'user'   => array( 'required' => true, 'type' => 'integer' ),
					'course' => array( 'required' => true, 'type' => 'integer' ),
					'revoke' => array( 'type' => 'boolean' ),
				),
				'callback'            => function ( $request ) {
					if ( ! get_userdata( (int) $request['user'] ) || MLA_Courses::POST_TYPE !== get_post_type( (int) $request['course'] ) ) {
						return new WP_Error( 'mla_invalid', 'Utilisateur ou formation introuvable.', array( 'status' => 404 ) );
					}
					if ( $request['revoke'] ) {
						MLA_Access::unenroll( (int) $request['user'], (int) $request['course'] );
					} else {
						MLA_Access::enroll( (int) $request['user'], (int) $request['course'], 'manual' );
					}
					return array( 'courses' => MLA_Access::user_courses( (int) $request['user'] ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/mail',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( __CLASS__, 'can_configure' ),
					'callback'            => function () {
						return MLA_Mail::public_settings();
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( __CLASS__, 'can_configure' ),
					'callback'            => function ( $request ) {
						if ( $request['logo'] ) {
							$options              = get_option( 'mla_settings', array() );
							$options['mail_logo'] = esc_url_raw( $request['logo'] );
							update_option( 'mla_settings', $options );
						}
						$settings = MLA_Mail::save_settings( $request->get_json_params() ?: $request->get_body_params() );
						if ( $request['brand_woocommerce'] ) {
							self::brand_woocommerce_emails();
						}
						return $settings;
					},
				),
			)
		);

		register_rest_route(
			self::NS,
			'/mail/diagnostic',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_configure' ),
				'args'                => array( 'to' => array( 'type' => 'string', 'format' => 'email' ) ),
				'callback'            => function ( $request ) {
					return MLA_Mail::diagnose( $request['to'] ? sanitize_email( $request['to'] ) : '' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/mail/test',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_configure' ),
				'args'                => array( 'to' => array( 'required' => true, 'type' => 'string', 'format' => 'email' ) ),
				'callback'            => function ( $request ) {
					return MLA_Mail::send_test( sanitize_email( $request['to'] ) );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/sync-orders',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function () {
					return array( 'orders' => MLA_Access::sync_existing_orders() );
				},
			)
		);
	}

	/**
	 * Les identifiants SMTP sont réservés aux administrateurs du site.
	 */
	public static function can_configure() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Couleurs WooCommerce alignées sur la charte (utilisées dans le corps des e-mails : tableaux, liens).
	 */
	private static function brand_woocommerce_emails() {
		$options = array(
			'woocommerce_email_base_color'            => MLA_Mail::BLUE,
			'woocommerce_email_background_color'      => MLA_Mail::SOFT,
			'woocommerce_email_body_background_color' => '#ffffff',
			'woocommerce_email_text_color'            => MLA_Mail::INK,
			'woocommerce_email_footer_text_color'     => '#B9BCCB',
			'woocommerce_email_footer_text'           => 'Une question ? Répondez simplement à cet e-mail ou écrivez-nous à ' . MLA_Mail::from_email( get_option( 'admin_email' ) ) . '.',
			'woocommerce_email_reply_to_enabled'      => 'yes',
			'woocommerce_email_reply_to_name'         => get_bloginfo( 'name' ),
			'woocommerce_email_reply_to_address'      => MLA_Mail::from_email( get_option( 'admin_email' ) ),
		);
		$logo = MLA_Mail::logo_url();
		if ( $logo ) {
			$options['woocommerce_email_header_image'] = $logo;
		}
		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}
	}

	public static function course_summary( $post ) {
		$structure = MLA_Courses::structure( $post->ID );
		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'products'  => MLA_Access::products_for_course( $post->ID ),
			'modules'   => count( $structure['modules'] ),
			'lessons'   => count( MLA_Courses::lessons( $post->ID ) ),
			'resources' => count( $structure['resources'] ),
			'students'  => MLA_Access::count_students( $post->ID ),
			'url'       => MLA_Frontend::course_url( $post->ID ),
			'edit_url'  => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		);
	}

	/**
	 * Crée ou met à jour une formation, son contenu (structure ou dossiers Drive) et ses produits.
	 */
	public static function save_course( $request ) {
		$id = (int) $request['id'];
		if ( $id && MLA_Courses::POST_TYPE !== get_post_type( $id ) ) {
			return new WP_Error( 'mla_not_found', 'Formation introuvable.', array( 'status' => 404 ) );
		}

		$structure = null;
		if ( $request['drive_urls'] ) {
			$structure = MLA_Drive::import_folders( $request['drive_urls'], mla_option( 'drive_api_key' ) );
			if ( is_wp_error( $structure ) ) {
				$structure->add_data( array( 'status' => 422 ) );
				return $structure;
			}
		} elseif ( null !== $request['structure'] ) {
			$structure = $request['structure'];
		}

		$postarr = array( 'post_type' => MLA_Courses::POST_TYPE );
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ) as $param => $field ) {
			if ( null !== $request[ $param ] ) {
				$postarr[ $field ] = $request[ $param ];
			}
		}
		if ( $id ) {
			$postarr['ID'] = $id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			if ( empty( $postarr['post_title'] ) ) {
				return new WP_Error( 'mla_invalid', 'Le titre est obligatoire.', array( 'status' => 400 ) );
			}
			$postarr['post_status'] = $postarr['post_status'] ?? 'publish';
			$result                 = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result;

		if ( null !== $structure ) {
			MLA_Courses::save_structure( $id, $structure );
		}

		if ( null !== $request['products'] ) {
			// La liste fournie devient la liste exacte des produits qui ouvrent cette formation.
			$wanted = array_map( 'intval', $request['products'] );
			foreach ( MLA_Access::products_for_course( $id ) as $product_id ) {
				if ( ! in_array( $product_id, $wanted, true ) ) {
					MLA_Access::set_product_courses( $product_id, array_diff( MLA_Access::courses_for_product( $product_id ), array( $id ) ) );
				}
			}
			foreach ( $wanted as $product_id ) {
				if ( 'product' === get_post_type( $product_id ) ) {
					MLA_Access::set_product_courses( $product_id, array_merge( MLA_Access::courses_for_product( $product_id ), array( $id ) ) );
				}
			}
			if ( ! has_post_thumbnail( $id ) && $wanted && get_post_thumbnail_id( $wanted[0] ) ) {
				set_post_thumbnail( $id, get_post_thumbnail_id( $wanted[0] ) );
			}
		}

		return self::course_summary( get_post( $id ) ) + array( 'structure' => MLA_Courses::structure( $id ) );
	}
}

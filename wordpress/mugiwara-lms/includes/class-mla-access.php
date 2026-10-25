<?php
/**
 * Inscriptions : un achat WooCommerce payé ouvre l'accès aux formations liées au produit.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Access {

	const USER_META    = 'mla_enrollments';
	const PRODUCT_META = '_mla_course';

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'grant_order' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'grant_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_grant_processing' ) );

		foreach ( array( 'refunded', 'cancelled', 'failed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'revoke_order' ) );
		}

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'clean_cart' ) );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * Liens produit ↔ formation
	 * ---------------------------------------------------------------- */

	/**
	 * @return int[] Formations ouvertes par un produit.
	 */
	public static function courses_for_product( $product_id ) {
		return array_values( array_filter( array_map( 'intval', get_post_meta( $product_id, self::PRODUCT_META, false ) ) ) );
	}

	public static function set_product_courses( $product_id, $course_ids ) {
		delete_post_meta( $product_id, self::PRODUCT_META );
		foreach ( array_unique( array_filter( array_map( 'intval', (array) $course_ids ) ) ) as $course_id ) {
			add_post_meta( $product_id, self::PRODUCT_META, $course_id );
		}
	}

	/**
	 * @return int[] Produits qui donnent accès à une formation.
	 */
	public static function products_for_course( $course_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => self::PRODUCT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => (int) $course_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Inscriptions
	 * ---------------------------------------------------------------- */

	/**
	 * @return array<int,array{date:int,source:string,order:int}>
	 */
	public static function enrollments( $user_id ) {
		$data = get_user_meta( $user_id, self::USER_META, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return int[] Formations publiées auxquelles l'utilisateur a accès.
	 */
	public static function user_courses( $user_id ) {
		$ids = array_map( 'intval', array_keys( self::enrollments( $user_id ) ) );
		return array_values(
			array_filter(
				$ids,
				function ( $id ) {
					return 'publish' === get_post_status( $id );
				}
			)
		);
	}

	public static function has_access( $user_id, $course_id ) {
		$course_id = (int) $course_id;
		$access    = $user_id && ( isset( self::enrollments( $user_id )[ $course_id ] ) || user_can( $user_id, 'manage_woocommerce' ) );
		return (bool) apply_filters( 'mla_user_has_access', $access, $user_id, $course_id );
	}

	public static function enroll( $user_id, $course_id, $source = 'manual', $order_id = 0 ) {
		$data = self::enrollments( $user_id );
		if ( isset( $data[ $course_id ] ) ) {
			return false;
		}
		$data[ (int) $course_id ] = array(
			'date'   => time(),
			'source' => $source,
			'order'  => (int) $order_id,
		);
		update_user_meta( $user_id, self::USER_META, $data );
		do_action( 'mla_enrolled', $user_id, $course_id, $source, $order_id );
		return true;
	}

	public static function unenroll( $user_id, $course_id ) {
		$data = self::enrollments( $user_id );
		unset( $data[ (int) $course_id ] );
		update_user_meta( $user_id, self::USER_META, $data );
	}

	/**
	 * Nombre d'élèves inscrits à une formation.
	 */
	public static function count_students( $course_id ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'i:' . (int) $course_id . ';a:' ) . '%';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", self::USER_META, $like ) );
	}

	/* ------------------------------------------------------------------
	 * Commandes WooCommerce
	 * ---------------------------------------------------------------- */

	public static function maybe_grant_processing( $order_id ) {
		if ( 'yes' === mla_option( 'grant_processing', 'yes' ) ) {
			self::grant_order( $order_id );
		}
	}

	/**
	 * Formations achetées dans une commande.
	 *
	 * @return int[]
	 */
	public static function courses_in_order( $order ) {
		$courses = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			foreach ( array( $item->get_product_id(), $item->get_variation_id() ) as $product_id ) {
				if ( $product_id ) {
					$courses = array_merge( $courses, self::courses_for_product( $product_id ) );
				}
			}
		}
		return array_values( array_unique( $courses ) );
	}

	/**
	 * Ouvre l'accès aux formations d'une commande payée. Crée le compte client s'il n'existe pas.
	 */
	public static function grant_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		$courses = self::courses_in_order( $order );
		if ( ! $courses ) {
			return;
		}

		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			$user_id = self::customer_for_guest_order( $order );
			if ( ! $user_id ) {
				return;
			}
		}

		$granted = array();
		foreach ( $courses as $course_id ) {
			if ( self::enroll( $user_id, $course_id, 'order', $order->get_id() ) ) {
				$granted[] = get_the_title( $course_id );
			}
		}
		if ( $granted ) {
			$order->add_order_note( 'Accès ouvert à l’espace formation : ' . implode( ', ', $granted ) . '.' );
		}
	}

	/**
	 * Commande sans compte (ancienne commande invité) : on rattache ou on crée le compte.
	 */
	private static function customer_for_guest_order( $order ) {
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$user_id = $user->ID;
		} else {
			$user_id = wc_create_new_customer(
				$email,
				'',
				'',
				array(
					'first_name' => $order->get_billing_first_name(),
					'last_name'  => $order->get_billing_last_name(),
				)
			);
			if ( is_wp_error( $user_id ) ) {
				$order->add_order_note( 'Impossible de créer le compte élève : ' . $user_id->get_error_message() );
				return 0;
			}
		}
		$order->set_customer_id( $user_id );
		$order->save();
		return $user_id;
	}

	/**
	 * Remboursement ou annulation : on retire l'accès accordé par cette commande uniquement.
	 */
	public static function revoke_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		$user_id = $order->get_customer_id();
		$removed = array();
		foreach ( self::enrollments( $user_id ) as $course_id => $info ) {
			if ( 'order' === ( $info['source'] ?? '' ) && (int) ( $info['order'] ?? 0 ) === (int) $order_id ) {
				self::unenroll( $user_id, $course_id );
				$removed[] = get_the_title( $course_id );
			}
		}
		if ( $removed ) {
			$order->add_order_note( 'Accès retiré de l’espace formation : ' . implode( ', ', $removed ) . '.' );
		}
	}

	/**
	 * Rattrape les commandes déjà payées (à lancer après avoir lié les produits aux formations).
	 *
	 * @return int Nombre de commandes traitées.
	 */
	public static function sync_existing_orders() {
		$ids = wc_get_orders(
			array(
				'status' => array( 'wc-completed', 'wc-processing' ),
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			self::grant_order( $id );
		}
		return count( $ids );
	}

	/**
	 * Le produit reste achetable (sinon WooCommerce affiche « ne peut plus être acheté ») :
	 * on oriente plutôt l'élève vers la formation qu'il possède déjà.
	 */
	private static function blocks_repurchase() {
		return 'yes' === mla_option( 'block_repurchase', 'yes' ) && is_user_logged_in() && ! is_admin();
	}

	private static function owned_notice( $product_id ) {
		$courses = self::courses_for_product( $product_id );
		$url     = 1 === count( $courses ) ? MLA_Frontend::course_url( $courses[0] ) : MLA_Frontend::list_url();
		return sprintf(
			'Vous avez déjà accès à « %s ». <a href="%s" class="button wc-forward">Accéder à la formation</a>',
			esc_html( get_the_title( $product_id ) ),
			esc_url( $url )
		);
	}

	public static function validate_add_to_cart( $passed, $product_id ) {
		if ( $passed && self::blocks_repurchase() && self::user_owns_product( get_current_user_id(), $product_id ) ) {
			wc_add_notice( self::owned_notice( $product_id ), 'notice' );
			return false;
		}
		return $passed;
	}

	/**
	 * Formation ajoutée au panier avant la connexion, puis déjà possédée : retirée avec un message clair.
	 */
	public static function clean_cart( $cart ) {
		if ( ! self::blocks_repurchase() || ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::user_owns_product( get_current_user_id(), $item['product_id'] ) ) {
				$cart->remove_cart_item( $key );
				wc_add_notice( self::owned_notice( $item['product_id'] ), 'notice' );
			}
		}
	}

	/**
	 * Bouton des listes de produits : « Accéder » à la place de « Ajouter au panier ».
	 */
	public static function loop_button( $html, $product ) {
		if ( ! self::blocks_repurchase() || ! self::user_owns_product( get_current_user_id(), $product->get_id() ) ) {
			return $html;
		}
		$courses = self::courses_for_product( $product->get_id() );
		$url     = 1 === count( $courses ) ? MLA_Frontend::course_url( $courses[0] ) : MLA_Frontend::list_url();
		return '<a href="' . esc_url( $url ) . '" class="button mla-owned-button">Accéder à la formation</a>';
	}

	public static function user_owns_product( $user_id, $product_id ) {
		$courses = self::courses_for_product( $product_id );
		if ( ! $courses ) {
			return false;
		}
		$owned = self::enrollments( $user_id );
		foreach ( $courses as $course_id ) {
			if ( ! isset( $owned[ $course_id ] ) ) {
				return false;
			}
		}
		return true;
	}
}

<?php
/**
 * Programme de parrainage : adhésion validée par l'administrateur, liens de parrainage (site ou produit),
 * commissions en Berry, conversion en code promo et demandes de retrait (Mobile Money).
 */

defined( 'ABSPATH' ) || exit;

class MLA_Affiliate {

	const DB_VERSION = '1';
	const PAGE_SLUG  = 'parrainage';
	const PAGE_OPT   = 'mla_affiliate_page';
	const COOKIE     = 'mla_ref';

	const STATUS_META = 'mla_aff_status';
	const CODE_META   = 'mla_aff_code';

	const METHODS = array(
		'mtn'     => 'MTN Mobile Money',
		'moov'    => 'Moov Money',
		'celtiis' => 'Celtiis Cash',
		'orange'  => 'Orange Money',
	);

	const LEDGER_LABELS = array(
		'commission'        => 'Commission',
		'reversal'          => 'Commission annulée',
		'coupon'            => 'Conversion en code promo',
		'withdrawal'        => 'Demande de retrait',
		'withdrawal_refund' => 'Retrait refusé (Berry rendus)',
		'adjust'            => 'Ajustement',
	);

	/** @var array Message à afficher après une action (pattern POST → redirection → GET). */
	private static $flash = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'init', array( __CLASS__, 'ensure_page' ), 20 );
		add_action( 'init', array( __CLASS__, 'track_referral' ), 15 );
		add_shortcode( 'mugiwara_parrainage', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 2 );

		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'attribute_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attribute_order' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'credit_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'credit_order' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'credit_order' ) );
		foreach ( array( 'refunded', 'cancelled', 'failed' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'reverse_order' ) );
		}

		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ), 1000 );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'menu_item_url' ), 10, 2 );
	}

	/* ------------------------------------------------------------------
	 * Réglages
	 * ---------------------------------------------------------------- */

	public static function rate() {
		return max( 0, (float) mla_option( 'aff_rate', 20 ) );
	}

	/** FCFA pour 1 Berry. */
	public static function berry_value() {
		return max( 1, (float) mla_option( 'berry_value', 100 ) );
	}

	/** Seuil minimal (en Berry) pour convertir ou retirer. */
	public static function minimum() {
		return max( 1, (float) mla_option( 'berry_min', 50 ) );
	}

	public static function cookie_days() {
		return max( 1, (int) mla_option( 'aff_cookie_days', 30 ) );
	}

	public static function fcfa( $berries ) {
		return round( (float) $berries * self::berry_value() );
	}

	public static function format_berry( $berries ) {
		return number_format_i18n( (float) $berries, floor( (float) $berries ) == (float) $berries ? 0 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons
	}

	public static function format_fcfa( $amount ) {
		return number_format_i18n( (float) $amount ) . ' FCFA';
	}

	/* ------------------------------------------------------------------
	 * Base de données
	 * ---------------------------------------------------------------- */

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'mla_aff_' . $name;
	}

	public static function maybe_install() {
		if ( get_option( 'mla_aff_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table( 'ledger' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				type varchar(20) NOT NULL,
				berries decimal(12,2) NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY order_id (order_id)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'withdrawals' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				berries decimal(12,2) NOT NULL DEFAULT 0,
				amount decimal(14,2) NOT NULL DEFAULT 0,
				method varchar(20) NOT NULL,
				phone varchar(40) NOT NULL,
				holder varchar(120) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				admin_note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				processed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY status (status)
			) $charset;"
		);
		update_option( 'mla_aff_db', self::DB_VERSION );
	}

	/**
	 * Verrou par parrain : évite qu'un double clic dépense deux fois le même solde.
	 */
	private static function lock( $user_id ) {
		$key = 'mla_aff_lock_' . (int) $user_id;
		if ( ! add_option( $key, time(), '', false ) ) {
			if ( time() - (int) get_option( $key ) < 30 ) {
				return false;
			}
			update_option( $key, time(), false );
		}
		return true;
	}

	private static function unlock( $user_id ) {
		delete_option( 'mla_aff_lock_' . (int) $user_id );
	}

	public static function add_entry( $user_id, $type, $berries, $args = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'ledger' ),
			array(
				'user_id'    => (int) $user_id,
				'type'       => $type,
				'berries'    => round( (float) $berries, 2 ),
				'order_id'   => (int) ( $args['order_id'] ?? 0 ),
				'product_id' => (int) ( $args['product_id'] ?? 0 ),
				'ref_id'     => (int) ( $args['ref_id'] ?? 0 ),
				'note'       => substr( (string) ( $args['note'] ?? '' ), 0, 255 ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function balance( $user_id ) {
		global $wpdb;
		return round( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(berries),0) FROM ' . self::table( 'ledger' ) . ' WHERE user_id = %d', $user_id ) ), 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Statistiques d'un parrain.
	 */
	public static function stats( $user_id ) {
		global $wpdb;
		$t   = self::table( 'ledger' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN type IN ('commission','reversal') THEN berries ELSE 0 END),0) AS earned, COUNT(DISTINCT CASE WHEN type = 'commission' THEN order_id END) AS sales FROM $t WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reversed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM $t WHERE user_id = %d AND type = 'reversal'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending  = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(berries),0) FROM ' . self::table( 'withdrawals' ) . " WHERE user_id = %d AND status = 'pending'", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$clicks   = (int) get_user_meta( $user_id, 'mla_aff_clicks', true );
		$sales    = max( 0, (int) $row['sales'] - $reversed );
		return array(
			'balance'  => self::balance( $user_id ),
			'earned'   => round( (float) $row['earned'], 2 ),
			'sales'    => $sales,
			'clicks'   => $clicks,
			'pending'  => round( $pending, 2 ),
			'conversion' => $clicks ? round( 100 * $sales / $clicks, 1 ) : 0,
		);
	}

	public static function ledger( $user_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'ledger' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT %d', $user_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function withdrawals( $user_id = 0, $status = '', $limit = 100 ) {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();
		if ( $user_id ) {
			$where[] = 'user_id = %d';
			$vals[]  = $user_id;
		}
		if ( $status ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		$vals[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'withdrawals' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $vals ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------
	 * Parrains
	 * ---------------------------------------------------------------- */

	public static function status( $user_id ) {
		return $user_id ? (string) get_user_meta( $user_id, self::STATUS_META, true ) : '';
	}

	public static function is_approved( $user_id ) {
		return 'approved' === self::status( $user_id );
	}

	public static function code( $user_id ) {
		$code = get_user_meta( $user_id, self::CODE_META, true );
		if ( $code ) {
			return $code;
		}
		$user = get_userdata( $user_id );
		$base = strtoupper( substr( preg_replace( '~[^a-z0-9]~i', '', remove_accents( $user ? ( $user->first_name ?: $user->display_name ) : 'MA' ) ), 0, 6 ) ) ?: 'MA';
		do {
			$code = $base . wp_rand( 10, 99 ) . strtoupper( wp_generate_password( 2, false ) );
		} while ( self::user_by_code( $code ) );
		update_user_meta( $user_id, self::CODE_META, $code );
		return $code;
	}

	public static function user_by_code( $code ) {
		$code = strtoupper( preg_replace( '~[^A-Za-z0-9]~', '', (string) $code ) );
		if ( '' === $code ) {
			return 0;
		}
		$users = get_users( array( 'meta_key' => self::CODE_META, 'meta_value' => $code, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $users ? (int) $users[0] : 0;
	}

	public static function link( $user_id, $url = '' ) {
		return add_query_arg( 'ref', self::code( $user_id ), $url ?: home_url( '/' ) );
	}

	public static function apply( $user_id, $data ) {
		update_user_meta( $user_id, self::STATUS_META, 'pending' );
		update_user_meta( $user_id, 'mla_aff_applied', time() );
		update_user_meta( $user_id, 'mla_aff_phone', $data['phone'] );
		update_user_meta( $user_id, 'mla_aff_channel', $data['channel'] );
		update_user_meta( $user_id, 'mla_aff_motivation', $data['motivation'] );
		$user = get_userdata( $user_id );
		self::mail(
			get_option( 'admin_email' ),
			'Nouvelle demande de parrainage',
			'Nouvelle demande de parrainage',
			'<p><strong>' . esc_html( $user->display_name ) . '</strong> (' . esc_html( $user->user_email ) . ') souhaite rejoindre le programme de parrainage.</p>'
			. '<p>Téléphone : ' . esc_html( $data['phone'] ) . '<br>Canal : ' . esc_html( $data['channel'] ) . '</p>'
			. ( $data['motivation'] ? '<p>« ' . esc_html( $data['motivation'] ) . ' »</p>' : '' )
			. MLA_Mail::button( admin_url( 'admin.php?page=mla-aff-requests' ), 'Valider ou refuser' )
		);
	}

	public static function set_status( $user_id, $status ) {
		$previous = self::status( $user_id );
		update_user_meta( $user_id, self::STATUS_META, $status );
		$user = get_userdata( $user_id );
		if ( ! $user || $previous === $status ) {
			return;
		}
		if ( 'approved' === $status ) {
			self::code( $user_id );
			self::mail(
				$user->user_email,
				'Bienvenue dans le programme de parrainage',
				'Votre adhésion est validée',
				'<p>Bonjour ' . esc_html( $user->first_name ?: $user->display_name ) . ',</p><p>Votre demande a été acceptée. Partagez vos liens et gagnez <strong>' . esc_html( self::format_berry( self::rate() ) ) . ' % de chaque achat</strong> en Berry, à convertir en code promo ou à retirer sur Mobile Money.</p>'
				. MLA_Mail::button( self::page_url(), 'Ouvrir mon tableau de bord' )
			);
		} elseif ( 'rejected' === $status ) {
			self::mail(
				$user->user_email,
				'Votre demande de parrainage',
				'Votre demande de parrainage',
				'<p>Bonjour ' . esc_html( $user->first_name ?: $user->display_name ) . ',</p><p>Après étude, votre demande d’adhésion au programme de parrainage n’a pas été retenue pour le moment. N’hésitez pas à nous écrire pour en savoir plus.</p>'
			);
		}
	}

	private static function mail( $to, $subject, $heading, $content ) {
		return wp_mail( $to, $subject, MLA_Mail::layout( $heading, $content ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ------------------------------------------------------------------
	 * Suivi des liens et attribution des commandes
	 * ---------------------------------------------------------------- */

	public static function track_referral() {
		if ( is_admin() || empty( $_GET['ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$affiliate = self::user_by_code( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $affiliate || ! self::is_approved( $affiliate ) || get_current_user_id() === $affiliate ) {
			return;
		}
		$code   = self::code( $affiliate );
		$secure = is_ssl();
		setcookie( self::COOKIE, $code, time() + self::cookie_days() * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, $secure, true );
		$_COOKIE[ self::COOKIE ] = $code;
		// Un clic compté par navigateur et par jour.
		$seen = 'mla_ref_seen_' . $code;
		if ( empty( $_COOKIE[ $seen ] ) ) {
			setcookie( $seen, '1', time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, $secure, true );
			update_user_meta( $affiliate, 'mla_aff_clicks', (int) get_user_meta( $affiliate, 'mla_aff_clicks', true ) + 1 );
		}
	}

	/**
	 * Nouvelle commande : rattachée au parrain dont le lien a été suivi (jamais à soi-même).
	 */
	public static function attribute_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || $order->get_meta( '_mla_aff_user' ) || empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		$affiliate = self::user_by_code( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
		if ( ! $affiliate || ! self::is_approved( $affiliate ) ) {
			return;
		}
		$aff_user = get_userdata( $affiliate );
		if ( (int) $order->get_customer_id() === $affiliate || ( $aff_user && 0 === strcasecmp( $aff_user->user_email, (string) $order->get_billing_email() ) ) ) {
			return;
		}
		$order->update_meta_data( '_mla_aff_user', $affiliate );
		$order->save();
	}

	/**
	 * Commande payée : commission de X % du montant payé, convertie en Berry, une seule fois.
	 */
	public static function credit_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}
		$affiliate = (int) $order->get_meta( '_mla_aff_user' );
		if ( ! $affiliate || $order->get_meta( '_mla_aff_credited' ) || ! self::is_approved( $affiliate ) ) {
			return;
		}
		$order->update_meta_data( '_mla_aff_credited', time() );
		$order->save();

		$total = 0;
		foreach ( $order->get_items() as $item ) {
			$amount = (float) $item->get_total();
			if ( $amount <= 0 ) {
				continue;
			}
			$berries = round( $amount * self::rate() / 100 / self::berry_value(), 2 );
			if ( $berries <= 0 ) {
				continue;
			}
			self::add_entry(
				$affiliate,
				'commission',
				$berries,
				array(
					'order_id'   => $order->get_id(),
					'product_id' => $item->get_product_id(),
					'note'       => $item->get_name(),
				)
			);
			$total += $berries;
		}
		if ( $total <= 0 ) {
			return;
		}
		$order->add_order_note( sprintf( 'Parrainage : %s Berry crédités au parrain #%d.', self::format_berry( $total ), $affiliate ) );
		$user = get_userdata( $affiliate );
		if ( $user && 'no' !== get_user_meta( $affiliate, 'mla_aff_notify', true ) ) {
			self::mail(
				$user->user_email,
				'+' . self::format_berry( $total ) . ' Berry gagnés',
				'Nouvelle commission',
				'<p>Bonjour ' . esc_html( $user->first_name ?: $user->display_name ) . ',</p><p>Un achat vient d’être fait grâce à votre lien : vous gagnez <strong>' . esc_html( self::format_berry( $total ) ) . ' Berry</strong> (' . esc_html( self::format_fcfa( self::fcfa( $total ) ) ) . ').</p><p>Solde actuel : <strong>' . esc_html( self::format_berry( self::balance( $affiliate ) ) ) . ' Berry</strong>.</p>'
				. MLA_Mail::button( self::page_url(), 'Voir mon tableau de bord' )
			);
		}
	}

	/**
	 * Remboursement ou annulation après crédit : la commission est retirée.
	 */
	public static function reverse_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_mla_aff_credited' ) || $order->get_meta( '_mla_aff_reversed' ) ) {
			return;
		}
		global $wpdb;
		$affiliate = (int) $order->get_meta( '_mla_aff_user' );
		$credited  = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(berries),0) FROM ' . self::table( 'ledger' ) . " WHERE order_id = %d AND user_id = %d AND type = 'commission'", $order->get_id(), $affiliate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order->update_meta_data( '_mla_aff_reversed', time() );
		$order->save();
		if ( $credited > 0 ) {
			self::add_entry( $affiliate, 'reversal', -$credited, array( 'order_id' => $order->get_id(), 'note' => 'Commande #' . $order->get_order_number() . ' remboursée ou annulée' ) );
			$order->add_order_note( sprintf( 'Parrainage : %s Berry retirés au parrain #%d.', self::format_berry( $credited ), $affiliate ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Utilisation des Berry
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error Coupon créé {code, amount, expires}.
	 */
	public static function convert_to_coupon( $user_id, $berries ) {
		$berries = round( (float) $berries, 2 );
		$check   = self::check_amount( $user_id, $berries );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! self::lock( $user_id ) ) {
			return new WP_Error( 'mla_busy', 'Une opération est déjà en cours, réessayez dans un instant.' );
		}
		$check = self::check_amount( $user_id, $berries );
		if ( is_wp_error( $check ) ) {
			self::unlock( $user_id );
			return $check;
		}
		$user   = get_userdata( $user_id );
		$amount = self::fcfa( $berries );
		do {
			$code = 'BERRY-' . strtoupper( wp_generate_password( 6, false ) );
		} while ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'title' => $code, 'fields' => 'ids', 'numberposts' => 1 ) ) );
		$expires   = strtotime( '+6 months' );
		$coupon_id = wp_insert_post(
			array(
				'post_type'    => 'shop_coupon',
				'post_status'  => 'publish',
				'post_title'   => $code,
				'post_excerpt' => sprintf( 'Parrainage : %s Berry convertis par %s', self::format_berry( $berries ), $user->user_email ),
				'post_author'  => 1,
			),
			true
		);
		if ( is_wp_error( $coupon_id ) ) {
			self::unlock( $user_id );
			return $coupon_id;
		}
		$meta = array(
			'discount_type'          => 'fixed_cart',
			'coupon_amount'          => $amount,
			'individual_use'         => 'no',
			'usage_limit'            => 1,
			'usage_limit_per_user'   => 1,
			'usage_count'            => 0,
			'customer_email'         => array( strtolower( $user->user_email ) ),
			'date_expires'           => $expires,
			'free_shipping'          => 'no',
			'_mla_aff_user'          => $user_id,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $coupon_id, $key, $value );
		}
		self::add_entry( $user_id, 'coupon', -$berries, array( 'ref_id' => $coupon_id, 'note' => $code ) );
		self::unlock( $user_id );
		return array( 'code' => $code, 'amount' => $amount, 'expires' => $expires );
	}

	public static function coupons( $user_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => '_mla_aff_user', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return array_map(
			function ( $p ) {
				$used    = (int) get_post_meta( $p->ID, 'usage_count', true ) > 0;
				$expires = (int) get_post_meta( $p->ID, 'date_expires', true );
				return array(
					'code'    => $p->post_title,
					'amount'  => (float) get_post_meta( $p->ID, 'coupon_amount', true ),
					'expires' => $expires,
					'state'   => $used ? 'Utilisé' : ( $expires && $expires < time() ? 'Expiré' : 'Disponible' ),
				);
			},
			$posts
		);
	}

	/**
	 * @return int|WP_Error Identifiant de la demande.
	 */
	public static function request_withdrawal( $user_id, $berries, $method, $phone, $holder ) {
		$berries = round( (float) $berries, 2 );
		$phone   = preg_replace( '~[^0-9+ ]~', '', (string) $phone );
		if ( ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'mla_method', 'Choisissez un moyen de paiement.' );
		}
		if ( strlen( preg_replace( '~\D~', '', $phone ) ) < 8 ) {
			return new WP_Error( 'mla_phone', 'Indiquez un numéro de téléphone valide.' );
		}
		if ( '' === trim( $holder ) ) {
			return new WP_Error( 'mla_holder', 'Indiquez le nom du titulaire du compte.' );
		}
		$check = self::check_amount( $user_id, $berries );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! self::lock( $user_id ) ) {
			return new WP_Error( 'mla_busy', 'Une opération est déjà en cours, réessayez dans un instant.' );
		}
		$check = self::check_amount( $user_id, $berries );
		if ( is_wp_error( $check ) ) {
			self::unlock( $user_id );
			return $check;
		}
		global $wpdb;
		$wpdb->insert(
			self::table( 'withdrawals' ),
			array(
				'user_id'    => $user_id,
				'berries'    => $berries,
				'amount'     => self::fcfa( $berries ),
				'method'     => $method,
				'phone'      => $phone,
				'holder'     => sanitize_text_field( $holder ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		self::add_entry( $user_id, 'withdrawal', -$berries, array( 'ref_id' => $id, 'note' => self::METHODS[ $method ] . ' ' . $phone ) );
		self::unlock( $user_id );
		update_user_meta( $user_id, 'mla_aff_payout', array( 'method' => $method, 'phone' => $phone, 'holder' => sanitize_text_field( $holder ) ) );

		$user = get_userdata( $user_id );
		self::mail(
			get_option( 'admin_email' ),
			'Demande de retrait : ' . self::format_fcfa( self::fcfa( $berries ) ),
			'Nouvelle demande de retrait',
			'<p><strong>' . esc_html( $user->display_name ) . '</strong> (' . esc_html( $user->user_email ) . ') demande un retrait.</p>'
			. '<table role="presentation" cellpadding="6" style="border-collapse:collapse">'
			. '<tr><td>Montant</td><td><strong>' . esc_html( self::format_fcfa( self::fcfa( $berries ) ) ) . '</strong> (' . esc_html( self::format_berry( $berries ) ) . ' Berry)</td></tr>'
			. '<tr><td>Moyen</td><td><strong>' . esc_html( self::METHODS[ $method ] ) . '</strong></td></tr>'
			. '<tr><td>Numéro</td><td><strong>' . esc_html( $phone ) . '</strong></td></tr>'
			. '<tr><td>Titulaire</td><td>' . esc_html( $holder ) . '</td></tr></table>'
			. MLA_Mail::button( admin_url( 'admin.php?page=mla-withdrawals' ), 'Traiter la demande' )
		);
		return $id;
	}

	public static function process_withdrawal( $id, $status, $note = '' ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'withdrawals' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row || 'pending' !== $row['status'] || ! in_array( $status, array( 'paid', 'rejected' ), true ) ) {
			return false;
		}
		$wpdb->update( self::table( 'withdrawals' ), array( 'status' => $status, 'admin_note' => substr( $note, 0, 255 ), 'processed_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( 'rejected' === $status ) {
			self::add_entry( $row['user_id'], 'withdrawal_refund', (float) $row['berries'], array( 'ref_id' => $id, 'note' => $note ) );
		}
		$user = get_userdata( $row['user_id'] );
		if ( $user ) {
			$amount = esc_html( self::format_fcfa( $row['amount'] ) );
			$method = esc_html( self::METHODS[ $row['method'] ] ?? $row['method'] );
			self::mail(
				$user->user_email,
				'paid' === $status ? 'Votre retrait a été envoyé' : 'Votre demande de retrait',
				'paid' === $status ? 'Retrait envoyé' : 'Retrait non effectué',
				'paid' === $status
					? '<p>Votre retrait de <strong>' . $amount . '</strong> a été envoyé sur votre compte ' . $method . ' (' . esc_html( $row['phone'] ) . ').</p>'
					: '<p>Votre demande de retrait de <strong>' . $amount . '</strong> n’a pas pu être traitée' . ( $note ? ' : ' . esc_html( $note ) : '.' ) . '</p><p>Les Berry ont été rendus sur votre solde.</p>'
			);
		}
		return true;
	}

	private static function check_amount( $user_id, $berries ) {
		if ( ! self::is_approved( $user_id ) ) {
			return new WP_Error( 'mla_not_affiliate', 'Votre compte parrain n’est pas actif.' );
		}
		if ( $berries < self::minimum() ) {
			return new WP_Error( 'mla_min', sprintf( 'Le minimum est de %s Berry.', self::format_berry( self::minimum() ) ) );
		}
		if ( $berries > self::balance( $user_id ) ) {
			return new WP_Error( 'mla_balance', 'Solde insuffisant.' );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Page « Parrainage »
	 * ---------------------------------------------------------------- */

	public static function ensure_page() {
		$id = (int) get_option( self::PAGE_OPT );
		if ( $id && get_post( $id ) ) {
			return;
		}
		$page = get_page_by_path( self::PAGE_SLUG );
		$id   = $page ? $page->ID : wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Parrainage',
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '<!-- wp:shortcode -->[mugiwara_parrainage]<!-- /wp:shortcode -->',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			if ( defined( 'ELEMENTOR_VERSION' ) && ! get_post_meta( $id, '_wp_page_template', true ) ) {
				update_post_meta( $id, '_wp_page_template', 'elementor_header_footer' );
			}
			update_option( self::PAGE_OPT, (int) $id );
		}
	}

	public static function page_url( $args = array() ) {
		$id = (int) get_option( self::PAGE_OPT );
		return add_query_arg( $args, $id ? get_permalink( $id ) : home_url( '/' . self::PAGE_SLUG . '/' ) );
	}

	public static function menu_item( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out['mla-parrainage'] = 'Parrainage';
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['mla-parrainage'] ) ) {
			$out['mla-parrainage'] = 'Parrainage';
		}
		return $out;
	}

	public static function menu_item_url( $url, $endpoint ) {
		return 'mla-parrainage' === $endpoint ? self::page_url() : $url;
	}

	/**
	 * Formulaires de la page (adhésion, conversion, retrait, paramètres) → redirection avec message.
	 */
	public static function handle_post() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['mla_aff_action'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_key( $_POST['mla_aff_action'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_POST['_mla_aff_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mla_aff_nonce'] ), 'mla_aff_' . $action ) ) {
			self::redirect( 'error', 'La page a expiré, merci de réessayer.' );
		}
		$user_id = get_current_user_id();
		switch ( $action ) {
			case 'apply':
				if ( in_array( self::status( $user_id ), array( 'pending', 'approved' ), true ) ) {
					self::redirect( 'info', 'Votre demande est déjà enregistrée.' );
				}
				if ( empty( $_POST['terms'] ) ) {
					self::redirect( 'error', 'Merci d’accepter les conditions du programme.' );
				}
				$phone = preg_replace( '~[^0-9+ ]~', '', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
				if ( strlen( preg_replace( '~\D~', '', $phone ) ) < 8 ) {
					self::redirect( 'error', 'Indiquez un numéro de téléphone valide.' );
				}
				self::apply(
					$user_id,
					array(
						'phone'      => $phone,
						'channel'    => sanitize_text_field( wp_unslash( $_POST['channel'] ?? '' ) ),
						'motivation' => sanitize_textarea_field( wp_unslash( $_POST['motivation'] ?? '' ) ),
					)
				);
				self::redirect( 'success', 'Demande envoyée ! Vous recevrez un e-mail dès qu’elle sera validée.' );
				break;
			case 'coupon':
				$result = self::convert_to_coupon( $user_id, (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['berries'] ?? '0' ) ) ) );
				if ( is_wp_error( $result ) ) {
					self::redirect( 'error', $result->get_error_message(), 'berry' );
				}
				self::redirect( 'success', sprintf( 'Votre code promo %s (%s) est prêt : utilisez-le au moment du paiement.', $result['code'], self::format_fcfa( $result['amount'] ) ), 'historique' );
				break;
			case 'withdraw':
				$result = self::request_withdrawal(
					$user_id,
					(float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['berries'] ?? '0' ) ) ),
					sanitize_key( $_POST['method'] ?? '' ),
					sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['holder'] ?? '' ) )
				);
				if ( is_wp_error( $result ) ) {
					self::redirect( 'error', $result->get_error_message(), 'berry' );
				}
				self::redirect( 'success', 'Demande de retrait envoyée. Vous recevrez les fonds sous 1 jour ouvré.', 'historique' );
				break;
			case 'settings':
				$method = sanitize_key( $_POST['method'] ?? '' );
				update_user_meta(
					$user_id,
					'mla_aff_payout',
					array(
						'method' => isset( self::METHODS[ $method ] ) ? $method : '',
						'phone'  => preg_replace( '~[^0-9+ ]~', '', sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) ),
						'holder' => sanitize_text_field( wp_unslash( $_POST['holder'] ?? '' ) ),
					)
				);
				update_user_meta( $user_id, 'mla_aff_notify', empty( $_POST['notify'] ) ? 'no' : 'yes' );
				self::redirect( 'success', 'Paramètres enregistrés.', 'parametres' );
				break;
		}
	}

	private static function redirect( $type, $message, $tab = '' ) {
		set_transient( 'mla_aff_flash_' . get_current_user_id(), array( $type, $message ), 120 );
		wp_safe_redirect( self::page_url( $tab ? array( 'onglet' => $tab ) : array() ) . ( $tab ? '#' . $tab : '' ) );
		exit;
	}

	public static function shortcode() {
		wp_enqueue_style( 'mla-front' );
		wp_enqueue_script( 'mla-front' );
		$user_id = get_current_user_id();
		$flash   = $user_id ? get_transient( 'mla_aff_flash_' . $user_id ) : false;
		if ( $flash ) {
			delete_transient( 'mla_aff_flash_' . $user_id );
		}
		$status = self::status( $user_id );
		$v      = array(
			'user_id' => $user_id,
			'status'  => $status,
			'flash'   => $flash,
		);
		if ( 'approved' === $status ) {
			$v['stats']       = self::stats( $user_id );
			$v['code']        = self::code( $user_id );
			$v['ledger']      = self::ledger( $user_id );
			$v['coupons']     = self::coupons( $user_id );
			$v['withdrawals'] = self::withdrawals( $user_id );
			$v['payout']      = wp_parse_args( (array) get_user_meta( $user_id, 'mla_aff_payout', true ), array( 'method' => '', 'phone' => (string) get_user_meta( $user_id, 'mla_aff_phone', true ), 'holder' => '' ) );
			$v['products']    = self::shareable_products();
		}
		ob_start();
		$file = locate_template( 'mugiwara-lms/affiliate.php' ) ?: MLA_DIR . 'templates/affiliate.php';
		include $file;
		return ob_get_clean();
	}

	/**
	 * Produits à partager (formations et ressources publiées).
	 */
	public static function shareable_products() {
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 200, 'orderby' => 'title', 'order' => 'ASC', 'return' => 'ids' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$price = (float) $product->get_price();
			$out[] = array(
				'id'     => $id,
				'name'   => $product->get_name(),
				'url'    => $product->get_permalink(),
				'image'  => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
				'price'  => $price,
				'gain'   => round( $price * self::rate() / 100 / self::berry_value(), 2 ),
				'search' => remove_accents( strtolower( $product->get_name() ) ),
			);
		}
		return $out;
	}
}

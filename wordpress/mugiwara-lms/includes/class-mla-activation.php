<?php
/**
 * Activation du compte après achat : le client choisit son mot de passe puis le confirme
 * avec un code à 6 chiffres reçu par e-mail. Sert aussi de « mot de passe oublié ».
 */

defined( 'ABSPATH' ) || exit;

class MLA_Activation {

	const SLUG         = 'activer-mon-compte';
	const QUERY_VAR    = 'mla_activate';
	const OTP_META     = 'mla_otp';
	const PENDING_META = 'mla_needs_activation';

	const CODE_TTL     = 600;  // Validité du code : 10 minutes.
	const RESEND_DELAY = 60;   // Délai minimal entre deux envois.
	const MAX_ATTEMPTS = 5;    // Essais par code.
	const MAX_PER_HOUR = 6;    // Codes envoyés par heure et par compte.

	/** @var array État de la page pour le gabarit. */
	private static $view = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'require_activation' ), 5 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'flag_new_customer' ) );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'login_form_link' ) );
		add_filter( 'lostpassword_url', array( __CLASS__, 'lost_password_url' ), 20 );
	}

	public static function rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Lien vers la page d'activation. Avec une commande, l'e-mail est pré-rempli grâce à la clé de commande.
	 */
	public static function url( $args = array() ) {
		$base = get_option( 'permalink_structure' ) ? home_url( '/' . self::SLUG . '/' ) : add_query_arg( self::QUERY_VAR, 1, home_url( '/' ) );
		return add_query_arg( array_filter( $args ), $base );
	}

	public static function url_for_order( $order, $redirect = '' ) {
		return self::url(
			array(
				'commande'  => $order->get_id(),
				'cle'       => $order->get_order_key(),
				'redirect'  => $redirect ? rawurlencode( $redirect ) : '',
			)
		);
	}

	public static function needs_activation( $user_id ) {
		return (bool) get_user_meta( $user_id, self::PENDING_META, true );
	}

	/**
	 * Compte créé pendant la commande : mot de passe généré que le client ne connaît pas.
	 */
	public static function flag_new_customer( $user_id ) {
		update_user_meta( $user_id, self::PENDING_META, 1 );
	}

	/**
	 * Tant que le compte n'est pas activé, l'espace client renvoie vers l'activation.
	 */
	public static function require_activation() {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() || is_wc_endpoint_url( 'customer-logout' ) ) {
			return;
		}
		if ( self::needs_activation( get_current_user_id() ) ) {
			$current = home_url( add_query_arg( array() ) );
			wp_safe_redirect( self::url( array( 'redirect' => rawurlencode( $current ) ) ) );
			exit;
		}
	}

	public static function login_form_link() {
		echo '<p class="mla-login-activate">Première connexion après un achat ou mot de passe oublié ? <a href="' . esc_url( self::url() ) . '">Recevoir un code par e-mail</a></p>';
	}

	public static function lost_password_url( $url ) {
		return is_admin() ? $url : self::url( array( 'oubli' => 1 ) );
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	public static function handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();

		$view = array(
			'step'     => 'password',
			'email'    => '',
			'locked'   => false,
			'error'    => '',
			'notice'   => '',
			'order'    => null,
			'redirect' => self::safe_redirect( wp_unslash( $_REQUEST['redirect'] ?? '' ) ), // phpcs:ignore WordPress.Security
			'forgot'   => ! empty( $_GET['oubli'] ), // phpcs:ignore WordPress.Security.NonceVerification
		);

		// E-mail pré-rempli : commande (clé secrète du lien de remerciement) ou compte connecté.
		$order_id = absint( $_REQUEST['commande'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$key      = sanitize_text_field( wp_unslash( $_REQUEST['cle'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $order_id && $key && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && hash_equals( $order->get_order_key(), $key ) ) {
				$view['order']  = $order;
				$view['email']  = $order->get_billing_email();
				$view['locked'] = true;
				$customer       = $order->get_customer_id();
				// Compte déjà activé : inutile de redemander un mot de passe.
				if ( $customer && ! self::needs_activation( $customer ) && ( ! is_user_logged_in() || get_current_user_id() === $customer ) && empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$target = $view['redirect'] ?: MLA_Frontend::list_url();
					wp_safe_redirect( is_user_logged_in() ? $target : add_query_arg( 'redirect_to', rawurlencode( $target ), wc_get_page_permalink( 'myaccount' ) ) );
					exit;
				}
			}
		}
		if ( ! $view['email'] && is_user_logged_in() ) {
			$view['email']  = wp_get_current_user()->user_email;
			$view['locked'] = true;
		}
		if ( ! $view['email'] && ! empty( $_GET['email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$view['email'] = sanitize_email( wp_unslash( $_GET['email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['mla_act'] ) ) { // phpcs:ignore WordPress.Security
			$view = self::process( $view );
		}

		self::$view = $view;
		wp_enqueue_style( 'mla-front' );
		add_filter( 'body_class', function ( $c ) { $c[] = 'mla-activation-page'; return $c; } );
		add_filter( 'pre_get_document_title', function () { return 'Activer mon compte – ' . get_bloginfo( 'name' ); } );

		status_header( 200 );
		get_header();
		$file = locate_template( 'mugiwara-lms/activation.php' ) ?: MLA_DIR . 'templates/activation.php';
		$v    = self::$view;
		include $file;
		get_footer();
		exit;
	}

	private static function safe_redirect( $url ) {
		$url = rawurldecode( (string) $url );
		return $url ? wp_validate_redirect( esc_url_raw( $url ), '' ) : '';
	}

	/**
	 * Traite les deux étapes : mot de passe → envoi du code, puis vérification du code.
	 */
	private static function process( $view ) {
		if ( ! isset( $_POST['_mla_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_mla_nonce'] ), 'mla_activation' ) ) {
			$view['error'] = 'La page a expiré, merci de réessayer.';
			return $view;
		}
		$action = sanitize_key( $_POST['mla_act'] );
		$email  = $view['locked'] ? $view['email'] : sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$view['email'] = $email;
		$user  = $email ? get_user_by( 'email', $email ) : false;

		if ( 'start' === $action ) {
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- mot de passe : jamais modifié.
			$pass2 = (string) wp_unslash( $_POST['password2'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! is_email( $email ) ) {
				$view['error'] = 'Indiquez une adresse e-mail valide.';
			} elseif ( strlen( $pass ) < 8 ) {
				$view['error'] = 'Le mot de passe doit contenir au moins 8 caractères.';
			} elseif ( $pass !== $pass2 ) {
				$view['error'] = 'Les deux mots de passe ne sont pas identiques.';
			} else {
				// Sans compte, on affiche la même étape : on ne révèle pas quelles adresses sont inscrites.
				$sent = $user ? self::send_code( $user, $pass ) : true;
				if ( is_wp_error( $sent ) ) {
					$view['error'] = $sent->get_error_message();
				} else {
					$view['step']   = 'code';
					$view['notice'] = 'Un code à 6 chiffres vient d’être envoyé à ' . $email . '. Pensez à vérifier vos courriers indésirables.';
				}
			}
			return $view;
		}

		if ( 'resend' === $action ) {
			$view['step'] = 'code';
			$sent         = $user ? self::send_code( $user, null ) : true;
			if ( is_wp_error( $sent ) ) {
				$view['error'] = $sent->get_error_message();
			} else {
				$view['notice'] = 'Nouveau code envoyé à ' . $email . '.';
			}
			return $view;
		}

		if ( 'verify' === $action ) {
			$view['step'] = 'code';
			$code         = preg_replace( '~\D~', '', (string) wp_unslash( $_POST['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$result       = $user ? self::verify( $user, $code ) : new WP_Error( 'mla_code', 'Code incorrect.' );
			if ( is_wp_error( $result ) ) {
				$view['error'] = $result->get_error_message();
				if ( 'mla_restart' === $result->get_error_code() ) {
					$view['step'] = 'password';
				}
				return $view;
			}
			$target = $view['redirect'] ?: MLA_Frontend::list_url();
			wp_safe_redirect( add_query_arg( 'bienvenue', 1, $target ) );
			exit;
		}
		return $view;
	}

	/**
	 * Génère et envoie un code. Avec $password, mémorise le nouveau mot de passe (haché) en attente de confirmation.
	 *
	 * @return true|WP_Error
	 */
	public static function send_code( $user, $password ) {
		$otp  = get_user_meta( $user->ID, self::OTP_META, true );
		$otp  = is_array( $otp ) ? $otp : array();
		$now  = time();

		if ( null === $password && empty( $otp['pass'] ) ) {
			return new WP_Error( 'mla_restart', 'Choisissez d’abord votre mot de passe.' );
		}
		if ( ! empty( $otp['sent'] ) && $now - $otp['sent'] < self::RESEND_DELAY ) {
			return new WP_Error( 'mla_wait', 'Un code vient d’être envoyé. Patientez une minute avant d’en demander un autre.' );
		}
		$hour_start = (int) ( $otp['hour_start'] ?? 0 );
		$count      = $now - $hour_start < HOUR_IN_SECONDS ? (int) ( $otp['count'] ?? 0 ) : 0;
		if ( $count >= self::MAX_PER_HOUR ) {
			return new WP_Error( 'mla_limit', 'Trop de codes demandés. Réessayez dans une heure.' );
		}

		$code = (string) random_int( 100000, 999999 );
		$otp  = array(
			'code'       => wp_hash_password( $code ),
			'expires'    => $now + self::CODE_TTL,
			'attempts'   => 0,
			'sent'       => $now,
			'pass'       => null === $password ? $otp['pass'] : wp_hash_password( $password ),
			'count'      => $count + 1,
			'hour_start' => $count ? $hour_start : $now,
		);
		update_user_meta( $user->ID, self::OTP_META, $otp );

		$name    = $user->first_name ?: $user->display_name;
		$digits  = '';
		foreach ( str_split( $code ) as $d ) {
			$digits .= '<td style="width:44px;height:56px;border:1.5px solid ' . MLA_Mail::LINE . ';border-radius:12px;background:' . MLA_Mail::SOFT . ';text-align:center;font-family:' . MLA_Mail::FONT . ';font-size:28px;font-weight:800;color:' . MLA_Mail::INK . ';">' . $d . '</td><td style="width:8px"></td>';
		}
		$content = '<p style="margin:0 0 16px;">Bonjour ' . esc_html( $name ) . ',</p>'
			. '<p style="margin:0 0 6px;">Voici votre code pour confirmer votre mot de passe et accéder à vos formations :</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0"><tr>' . $digits . '</tr></table>'
			. '<p style="margin:0 0 16px;color:' . MLA_Mail::MUTED . ';font-size:14px;">Ce code est valable 10 minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez simplement cet e-mail : votre mot de passe ne sera pas modifié.</p>';
		$sent    = wp_mail(
			$user->user_email,
			$code . ' est votre code ' . get_bloginfo( 'name' ),
			MLA_Mail::layout( 'Votre code de confirmation', $content ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		if ( ! $sent ) {
			return new WP_Error( 'mla_mail', 'L’e-mail n’a pas pu être envoyé. Réessayez dans un instant ou contactez-nous.' );
		}
		return true;
	}

	/**
	 * Vérifie le code ; en cas de succès, applique le mot de passe et connecte le client.
	 *
	 * @return true|WP_Error
	 */
	public static function verify( $user, $code ) {
		$otp = get_user_meta( $user->ID, self::OTP_META, true );
		if ( ! is_array( $otp ) || empty( $otp['code'] ) || empty( $otp['pass'] ) ) {
			return new WP_Error( 'mla_restart', 'Aucun code en attente : choisissez votre mot de passe pour en recevoir un.' );
		}
		if ( time() > $otp['expires'] ) {
			return new WP_Error( 'mla_expired', 'Ce code a expiré. Demandez-en un nouveau.' );
		}
		if ( $otp['attempts'] >= self::MAX_ATTEMPTS ) {
			return new WP_Error( 'mla_expired', 'Trop d’essais incorrects. Demandez un nouveau code.' );
		}
		if ( 6 !== strlen( $code ) || ! wp_check_password( $code, $otp['code'] ) ) {
			$otp['attempts']++;
			update_user_meta( $user->ID, self::OTP_META, $otp );
			$left = self::MAX_ATTEMPTS - $otp['attempts'];
			return new WP_Error( 'mla_code', 'Code incorrect.' . ( $left > 0 ? ' Il vous reste ' . $left . ' essai' . ( $left > 1 ? 's' : '' ) . '.' : '' ) );
		}

		// Le mot de passe a été haché au moment du choix : on l'enregistre tel quel.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_pass' => $otp['pass'], 'user_activation_key' => '' ), array( 'ID' => $user->ID ) );
		clean_user_cache( $user->ID );
		delete_user_meta( $user->ID, self::OTP_META );
		delete_user_meta( $user->ID, self::PENDING_META );

		// Nouveau mot de passe : les anciennes sessions sont fermées, puis on connecte ce navigateur.
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, get_userdata( $user->ID ) );
		return true;
	}

	/**
	 * Formations d'une commande, pour rassurer le client sur la page d'activation.
	 */
	public static function order_courses( $order ) {
		return $order ? array_map( 'get_the_title', MLA_Access::courses_in_order( $order ) ) : array();
	}
}

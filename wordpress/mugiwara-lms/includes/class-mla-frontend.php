<?php
/**
 * Espace élève : « Mes formations » dans Mon compte, lecteur de leçons, ressources et téléchargements.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Frontend {

	const EP_LIST   = 'mes-formations';
	const EP_COURSE = 'formation';

	const PROGRESS_META = 'mla_progress';
	const LAST_META     = 'mla_last_lesson';
	const SEEN_META     = 'mla_last_seen';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		// Priorité tardive : certains thèmes reconstruisent le menu de Mon compte et effaceraient l'onglet.
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ), 999 );
		add_filter( 'woocommerce_endpoint_' . self::EP_LIST . '_title', array( __CLASS__, 'title_list' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP_COURSE . '_title', array( __CLASS__, 'title_course' ) );
		add_action( 'woocommerce_account_' . self::EP_LIST . '_endpoint', array( __CLASS__, 'render_dashboard' ) );
		add_action( 'woocommerce_account_' . self::EP_COURSE . '_endpoint', array( __CLASS__, 'render_course' ) );

		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_dashboard' ) );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_registration_redirect', array( __CLASS__, 'login_redirect' ), 20 );

		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_account_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'email_block' ), 5, 4 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_owned_notice' ), 29 );

		add_shortcode( 'mugiwara_formations', array( __CLASS__, 'shortcode' ) );
	}

	/* ------------------------------------------------------------------
	 * Routage
	 * ---------------------------------------------------------------- */

	public static function add_endpoints() {
		add_rewrite_endpoint( self::EP_LIST, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::EP_COURSE, EP_ROOT | EP_PAGES );
	}

	public static function query_vars( $vars ) {
		$vars[ self::EP_LIST ]   = self::EP_LIST;
		$vars[ self::EP_COURSE ] = self::EP_COURSE;
		return $vars;
	}

	public static function menu_items( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$new[ self::EP_LIST ] = 'Mes formations';
			}
		}
		if ( ! isset( $new[ self::EP_LIST ] ) ) {
			$new = array( self::EP_LIST => 'Mes formations' ) + $new;
		}
		return $new;
	}

	public static function title_list() {
		return 'Mes formations';
	}

	public static function title_course() {
		$course = self::current_course();
		return $course ? get_the_title( $course ) : 'Formation';
	}

	public static function list_url() {
		return wc_get_account_endpoint_url( self::EP_LIST );
	}

	public static function course_url( $course_id, $lesson_id = '' ) {
		$post = get_post( $course_id );
		$url  = wc_get_endpoint_url( self::EP_COURSE, $post && $post->post_name ? $post->post_name : $course_id, wc_get_page_permalink( 'myaccount' ) );
		return $lesson_id ? add_query_arg( 'lecon', $lesson_id, $url ) : $url;
	}

	public static function download_url( $course_id, $item_id ) {
		return add_query_arg( 'mla_dl', (int) $course_id . '-' . rawurlencode( $item_id ), home_url( '/' ) );
	}

	/**
	 * Formation demandée par l'URL /mon-compte/formation/{slug}/.
	 */
	public static function current_course() {
		global $wp;
		$value = isset( $wp->query_vars[ self::EP_COURSE ] ) ? sanitize_title( $wp->query_vars[ self::EP_COURSE ] ) : '';
		if ( '' === $value ) {
			return 0;
		}
		$post = get_page_by_path( $value, OBJECT, MLA_Courses::POST_TYPE );
		if ( ! $post && ctype_digit( $value ) ) {
			$post = get_post( (int) $value );
		}
		return $post && MLA_Courses::POST_TYPE === $post->post_type && 'publish' === $post->post_status ? $post->ID : 0;
	}

	public static function redirect_dashboard() {
		if ( 'yes' !== mla_option( 'redirect_dashboard', 'yes' ) || ! is_user_logged_in() || current_user_can( 'edit_posts' ) || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( ! is_wc_endpoint_url() && empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( self::list_url() );
			exit;
		}
	}

	public static function login_redirect( $redirect, $user = null ) {
		// Retour vers le fichier demandé avant la connexion (voir handle_download).
		if ( ! empty( $_GET['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$target = wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $target ) {
				return $target;
			}
		}
		$account = wc_get_page_permalink( 'myaccount' );
		if ( empty( $redirect ) || untrailingslashit( $redirect ) === untrailingslashit( $account ) ) {
			// L'équipe du site arrive sur le tableau de bord WordPress, les élèves sur leurs formations.
			if ( $user instanceof WP_User && user_can( $user, 'edit_posts' ) ) {
				return admin_url();
			}
			return self::list_url();
		}
		return $redirect;
	}

	/**
	 * /?mla_dl=12-abc : vérifie l'accès puis redirige vers le fichier Drive.
	 */
	public static function handle_download() {
		if ( empty( $_GET['mla_dl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['mla_dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		list( $course_id, $item_id ) = array_pad( explode( '-', $raw, 2 ), 2, '' );
		$course_id = (int) $course_id;

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( self::download_url( $course_id, $item_id ) ), self::list_url() ) );
			exit;
		}
		if ( ! MLA_Access::has_access( get_current_user_id(), $course_id ) ) {
			wp_die( 'Vous n’avez pas accès à cette formation.', 'Accès refusé', array( 'response' => 403 ) );
		}
		$item = MLA_Courses::find_item( $course_id, $item_id );
		$url  = $item && ! empty( $item['downloadable'] ) ? MLA_Drive::download_url( $item['url'] ) : '';
		if ( ! $url ) {
			wp_die( 'Ce fichier n’est pas disponible au téléchargement.', 'Téléchargement', array( 'response' => 404 ) );
		}
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- destination Google Drive.
		exit;
	}

	/* ------------------------------------------------------------------
	 * Progression
	 * ---------------------------------------------------------------- */

	public static function rest_routes() {
		register_rest_route(
			'mla/v1',
			'/progress',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $request ) {
					return is_user_logged_in() && MLA_Access::has_access( get_current_user_id(), (int) $request['course'] );
				},
				'args'                => array(
					'course' => array( 'required' => true, 'type' => 'integer' ),
					'lesson' => array( 'required' => true, 'type' => 'string' ),
					'done'   => array( 'type' => 'boolean' ),
					'viewed' => array( 'type' => 'boolean' ),
				),
				'callback'            => array( __CLASS__, 'rest_progress' ),
			)
		);
	}

	public static function rest_progress( $request ) {
		$user_id   = get_current_user_id();
		$course_id = (int) $request['course'];
		$lesson_id = preg_replace( '~[^a-zA-Z0-9]~', '', $request['lesson'] );

		$valid = wp_list_pluck( MLA_Courses::lessons( $course_id ), 'id' );
		if ( ! in_array( $lesson_id, $valid, true ) ) {
			return new WP_Error( 'mla_lesson', 'Leçon inconnue.', array( 'status' => 404 ) );
		}

		$last               = (array) get_user_meta( $user_id, self::LAST_META, true );
		$last[ $course_id ] = $lesson_id;
		update_user_meta( $user_id, self::LAST_META, $last );

		$seen               = (array) get_user_meta( $user_id, self::SEEN_META, true );
		$seen[ $course_id ] = time();
		update_user_meta( $user_id, self::SEEN_META, $seen );

		if ( null !== $request['done'] ) {
			$progress = self::progress_map( $user_id );
			if ( $request['done'] ) {
				$progress[ $course_id ][ $lesson_id ] = time();
			} else {
				unset( $progress[ $course_id ][ $lesson_id ] );
			}
			update_user_meta( $user_id, self::PROGRESS_META, $progress );
		}
		return rest_ensure_response( self::progress( $user_id, $course_id ) );
	}

	private static function progress_map( $user_id ) {
		$map = get_user_meta( $user_id, self::PROGRESS_META, true );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * @return array{done:string[],count:int,total:int,percent:int}
	 */
	public static function progress( $user_id, $course_id ) {
		$ids   = wp_list_pluck( MLA_Courses::lessons( $course_id ), 'id' );
		$map   = self::progress_map( $user_id );
		$done  = array_values( array_intersect( $ids, array_keys( (array) ( $map[ $course_id ] ?? array() ) ) ) );
		$total = count( $ids );
		return array(
			'done'    => $done,
			'count'   => count( $done ),
			'total'   => $total,
			'percent' => $total ? (int) floor( 100 * count( $done ) / $total ) : 0,
		);
	}

	public static function last_lesson( $user_id, $course_id ) {
		$last = get_user_meta( $user_id, self::LAST_META, true );
		return is_array( $last ) && isset( $last[ $course_id ] ) ? $last[ $course_id ] : '';
	}

	/* ------------------------------------------------------------------
	 * Affichage
	 * ---------------------------------------------------------------- */

	/**
	 * Enregistré dès init : les thèmes blocs génèrent le contenu de la page avant wp_enqueue_scripts.
	 */
	public static function register_assets() {
		wp_register_style( 'mla-front', MLA_URL . 'assets/front.css', array(), MLA_VERSION );
		wp_add_inline_style( 'mla-front', ':root{--mla-accent:' . sanitize_hex_color( mla_option( 'accent', '#1E4DF0' ) ) . ';}' );
		wp_register_script( 'mla-front', MLA_URL . 'assets/front.js', array(), MLA_VERSION, true );
	}

	public static function enqueue_account_assets() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'mla-front' );
		}
	}

	public static function body_class( $classes ) {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::EP_COURSE ) ) {
			$classes[] = 'mla-player-page';
		}
		return $classes;
	}

	private static function template( $name, $vars ) {
		$file = locate_template( 'mugiwara-lms/' . $name . '.php' );
		if ( ! $file ) {
			$file = MLA_DIR . 'templates/' . $name . '.php';
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $file;
	}

	/**
	 * Données d'une carte « formation » pour le tableau de bord.
	 */
	public static function card( $user_id, $course_id ) {
		$progress = self::progress( $user_id, $course_id );
		$last     = self::last_lesson( $user_id, $course_id );
		if ( 0 === $progress['count'] && ! $last ) {
			$cta = 'Commencer';
		} elseif ( $progress['total'] && $progress['count'] >= $progress['total'] ) {
			$cta = 'Revoir';
		} else {
			$cta = 'Reprendre';
		}
		$structure = MLA_Courses::structure( $course_id );
		return array(
			'id'        => $course_id,
			'title'     => get_the_title( $course_id ),
			'excerpt'   => get_the_excerpt( $course_id ),
			'image'     => MLA_Courses::thumbnail_url( $course_id ),
			'url'       => self::course_url( $course_id, $last ),
			'progress'  => $progress,
			'cta'       => $cta,
			'modules'   => count( $structure['modules'] ),
			'resources' => count( $structure['resources'] ),
			'enrolled'  => (int) ( MLA_Access::enrollments( $user_id )[ $course_id ]['date'] ?? 0 ),
			'seen'      => (int) ( ( (array) get_user_meta( $user_id, self::SEEN_META, true ) )[ $course_id ] ?? 0 ),
		);
	}

	public static function render_dashboard() {
		wp_enqueue_style( 'mla-front' );
		wp_enqueue_script( 'mla-front' );
		$user_id = get_current_user_id();
		$cards   = array_map(
			function ( $id ) use ( $user_id ) {
				return self::card( $user_id, $id );
			},
			MLA_Access::user_courses( $user_id )
		);
		// Les formations en cours d'abord, puis les plus récentes.
		usort(
			$cards,
			function ( $a, $b ) {
				$rank = function ( $c ) {
					return 'Reprendre' === $c['cta'] ? 0 : ( 'Commencer' === $c['cta'] ? 1 : 2 );
				};
				return array( $rank( $a ), $b['enrolled'] ) <=> array( $rank( $b ), $a['enrolled'] );
			}
		);
		$shop_url = wc_get_page_permalink( 'shop' );
		$user     = wp_get_current_user();
		self::template( 'dashboard', compact( 'cards', 'shop_url', 'user' ) );
	}

	public static function render_course() {
		$course_id = self::current_course();
		$user_id   = get_current_user_id();
		if ( ! $course_id ) {
			echo '<div class="woocommerce-info">Cette formation est introuvable. <a href="' . esc_url( self::list_url() ) . '">Retour à mes formations</a></div>';
			return;
		}
		if ( ! MLA_Access::has_access( $user_id, $course_id ) ) {
			$products = MLA_Access::products_for_course( $course_id );
			$buy      = $products ? get_permalink( $products[0] ) : wc_get_page_permalink( 'shop' );
			echo '<div class="woocommerce-info">Vous n’avez pas encore accès à cette formation. <a class="button" href="' . esc_url( $buy ) . '">Découvrir la formation</a></div>';
			return;
		}

		wp_enqueue_style( 'mla-front' );
		wp_enqueue_script( 'mla-front' );

		$structure = MLA_Courses::structure( $course_id );
		$progress  = self::progress( $user_id, $course_id );
		$lessons   = array();
		foreach ( $structure['modules'] as $module ) {
			foreach ( $module['lessons'] as $lesson ) {
				$lessons[] = array(
					'id'          => $lesson['id'],
					'title'       => $lesson['title'],
					'module'      => $module['title'],
					'type'        => $lesson['type'],
					'duration'    => $lesson['duration'],
					'description' => wpautop( $lesson['description'] ),
					'embed'       => MLA_Drive::embed_url( $lesson['url'] ),
					'open'        => 'link' === MLA_Drive::parse( $lesson['url'] )['kind'] ? $lesson['url'] : '',
					'download'    => $lesson['downloadable'] && MLA_Drive::download_url( $lesson['url'] ) ? self::download_url( $course_id, $lesson['id'] ) : '',
				);
			}
		}

		$ids = wp_list_pluck( $lessons, 'id' );
		// phpcs:ignore WordPress.Security.NonceVerification
		$current = isset( $_GET['lecon'] ) ? preg_replace( '~[^a-zA-Z0-9]~', '', wp_unslash( $_GET['lecon'] ) ) : '';
		if ( ! in_array( $current, $ids, true ) ) {
			$last    = self::last_lesson( $user_id, $course_id );
			$pending = array_values( array_diff( $ids, $progress['done'] ) );
			$current = in_array( $last, $ids, true ) ? $last : ( $pending[0] ?? ( $ids[0] ?? '' ) );
		}

		$resources = array_map(
			function ( $res ) use ( $course_id ) {
				$res['download'] = MLA_Drive::download_url( $res['url'] ) ? self::download_url( $course_id, $res['id'] ) : '';
				$res['kind']     = MLA_Drive::parse( $res['url'] )['kind'];
				return $res;
			},
			$structure['resources']
		);

		$config = array(
			'course'   => $course_id,
			'lessons'  => $lessons,
			'current'  => $current,
			'done'     => $progress['done'],
			'rest'     => esc_url_raw( rest_url( 'mla/v1/progress' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'baseUrl'  => self::course_url( $course_id ),
		);

		$course       = get_post( $course_id );
		$download_all = $structure['download_all'] ? self::download_url( $course_id, 'all' ) : '';
		$back_url     = self::list_url();
		self::template( 'course', compact( 'course', 'structure', 'lessons', 'current', 'progress', 'resources', 'download_all', 'back_url', 'config' ) );
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			wp_enqueue_style( 'mla-front' );
			return '<div class="mla-login-cta"><p>Connectez-vous pour retrouver vos formations.</p><a class="mla-btn" href="' . esc_url( self::list_url() ) . '">Se connecter</a></div>';
		}
		ob_start();
		self::render_dashboard();
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Parcours d'achat
	 * ---------------------------------------------------------------- */

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$courses = MLA_Access::courses_in_order( $order );
		if ( ! $courses ) {
			return;
		}
		wp_enqueue_style( 'mla-front' );
		echo '<section class="mla-thankyou">';
		if ( $order->is_paid() ) {
			$pending = $order->get_customer_id() && MLA_Activation::needs_activation( $order->get_customer_id() );
			echo '<h2>🎉 Votre espace formation est prêt</h2>';
			echo $pending
				? '<p>Dernière étape : choisissez votre mot de passe pour accéder à vos formations, aujourd’hui comme plus tard.</p><div class="mla-thankyou__list">'
				: '<p>Vos formations sont accessibles dès maintenant depuis « Mon compte ».</p><div class="mla-thankyou__list">';
			foreach ( $courses as $course_id ) {
				$url = $pending ? MLA_Activation::url_for_order( $order, self::course_url( $course_id ) ) : self::course_url( $course_id );
				echo '<a class="mla-btn" href="' . esc_url( $url ) . '">▶ Accéder à « ' . esc_html( get_the_title( $course_id ) ) . ' »</a>';
			}
			echo '</div>';
		} else {
			echo '<h2>Paiement en cours de validation</h2><p>L’accès à vos formations s’ouvrira automatiquement dès la confirmation du paiement. Vous le retrouverez dans <a href="' . esc_url( self::list_url() ) . '">Mes formations</a>.</p>';
		}
		echo '</section>';
	}

	public static function email_block( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $order->is_paid() || ! MLA_Access::courses_in_order( $order ) ) {
			return;
		}
		$url = $order->get_customer_id() && MLA_Activation::needs_activation( $order->get_customer_id() ) ? MLA_Activation::url_for_order( $order ) : self::list_url();
		if ( $plain_text ) {
			echo "\nAccédez à vos formations : " . esc_url_raw( $url ) . "\n\n";
			return;
		}
		$titles = array_map( 'get_the_title', MLA_Access::courses_in_order( $order ) );
		echo '<div style="margin:26px 0;padding:22px 24px;border-radius:16px;background:' . esc_attr( MLA_Mail::INK ) . ';color:#ffffff;">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . esc_attr( MLA_Mail::LIME ) . ';">Votre espace formation est prêt</p>'
			. '<p style="margin:0 0 4px;font-size:17px;font-weight:800;color:#ffffff;">' . esc_html( implode( ' · ', $titles ) ) . '</p>'
			. '<p style="margin:0 0 16px;font-size:14px;color:#B9BCCB;">Cliquez ci-dessous : à la première connexion, vous choisirez votre mot de passe, confirmé par un code reçu par e-mail.</p>'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;border-radius:99px;background:' . esc_attr( MLA_Mail::BLUE ) . ';color:#ffffff;text-decoration:none;font-weight:700;">▶ Accéder à mes formations</a>'
			. '</div>';
	}

	public static function product_owned_notice() {
		global $product;
		if ( ! $product || ! is_user_logged_in() || ! MLA_Access::user_owns_product( get_current_user_id(), $product->get_id() ) ) {
			return;
		}
		wp_enqueue_style( 'mla-front' );
		$courses = MLA_Access::courses_for_product( $product->get_id() );
		$url     = 1 === count( $courses ) ? self::course_url( $courses[0] ) : self::list_url();
		echo '<div class="mla-owned"><span>✓ Vous possédez cette formation</span><a class="mla-btn" href="' . esc_url( $url ) . '">Accéder à la formation</a></div>';
	}
}

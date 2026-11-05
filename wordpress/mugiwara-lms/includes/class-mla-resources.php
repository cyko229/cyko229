<?php
/**
 * Ressources (logiciels, PDF, modèles…) vendues comme produits WooCommerce et livrées depuis Google Drive,
 * page « Ressources », et page « Mes téléchargements » réorganisée : formations (bouton Voir) et ressources.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Resources {

	const META_URL   = '_mla_resource_url';
	const META_TYPE  = '_mla_resource_type';
	const META_NOTE  = '_mla_resource_note';
	const CATEGORY   = 'ressources';
	const PAGE_SLUG  = 'ressources';
	const PAGE_OPT   = 'mla_resources_page';

	const TYPES = array(
		'logiciel' => 'Logiciel',
		'pdf'      => 'PDF',
		'modele'   => 'Modèle',
		'pack'     => 'Pack',
		'video'    => 'Vidéo',
		'audio'    => 'Audio',
		'autre'    => 'Fichier',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_page' ), 20 );
		add_shortcode( 'mugiwara_ressources', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_download' ), 1 );

		// « Mes téléchargements » : formations et ressources à la place de la liste de fichiers WooCommerce.
		add_action( 'wp', array( __CLASS__, 'replace_downloads_endpoint' ) );
		add_filter( 'woocommerce_order_get_downloadable_items', array( __CLASS__, 'order_items' ), 20, 2 );
		add_filter( 'woocommerce_customer_get_downloadable_products', array( __CLASS__, 'customer_items' ), 20 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_access' ), 5 );

		// Message « mot de passe temporaire » : retiré dès que le client s'est connecté avec son mot de passe.
		add_action( 'wp_login', array( __CLASS__, 'clear_password_nag' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
			add_action( 'save_post_product', array( __CLASS__, 'save_product' ) );
		}
	}

	public static function clear_password_nag( $login, $user = null ) {
		$user = $user instanceof WP_User ? $user : get_user_by( 'login', $login );
		if ( $user ) {
			delete_user_option( $user->ID, 'default_password_nag', true );
			delete_user_option( $user->ID, 'default_password_nag' );
		}
	}

	/* ------------------------------------------------------------------
	 * Produits « ressource »
	 * ---------------------------------------------------------------- */

	public static function is_resource( $product_id ) {
		return '' !== (string) get_post_meta( $product_id, self::META_URL, true );
	}

	public static function type_label( $product_id ) {
		$type = get_post_meta( $product_id, self::META_TYPE, true );
		return self::TYPES[ $type ] ?? self::TYPES['autre'];
	}

	public static function meta_box() {
		add_meta_box( 'mla_resource', 'Ressource téléchargeable', array( __CLASS__, 'render_meta_box' ), 'product', 'side' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'mla_resource', 'mla_resource_nonce' );
		$url  = get_post_meta( $post->ID, self::META_URL, true );
		$type = get_post_meta( $post->ID, self::META_TYPE, true ) ?: 'pdf';
		$note = get_post_meta( $post->ID, self::META_NOTE, true );
		?>
		<p class="description">Pour vendre un logiciel, un PDF ou un pack : collez le lien Google Drive du fichier ou du dossier. Il est livré après paiement, sans jamais être visible publiquement.</p>
		<p><label for="mla_res_url"><strong>Lien Google Drive</strong></label>
			<input type="url" id="mla_res_url" name="mla_res_url" class="widefat" value="<?php echo esc_attr( $url ); ?>" placeholder="https://drive.google.com/…"></p>
		<p><label for="mla_res_type"><strong>Type</strong></label>
			<select id="mla_res_type" name="mla_res_type" class="widefat">
				<?php foreach ( self::TYPES as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="mla_res_note"><strong>Note pour l’acheteur</strong> (facultatif)</label>
			<input type="text" id="mla_res_note" name="mla_res_note" class="widefat" value="<?php echo esc_attr( $note ); ?>" placeholder="Ex. : mot de passe de l’archive, version…"></p>
		<p class="description">Le produit est automatiquement rangé dans la catégorie « Ressources » et affiché sur la page Ressources.</p>
		<?php
	}

	public static function save_product( $post_id ) {
		if ( ! isset( $_POST['mla_resource_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mla_resource_nonce'] ), 'mla_resource' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$url = isset( $_POST['mla_res_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['mla_res_url'] ) ) ) : '';
		if ( '' === $url ) {
			delete_post_meta( $post_id, self::META_URL );
			return;
		}
		update_post_meta( $post_id, self::META_URL, $url );
		$type = isset( $_POST['mla_res_type'] ) ? sanitize_key( $_POST['mla_res_type'] ) : 'autre';
		update_post_meta( $post_id, self::META_TYPE, isset( self::TYPES[ $type ] ) ? $type : 'autre' );
		update_post_meta( $post_id, self::META_NOTE, sanitize_text_field( wp_unslash( $_POST['mla_res_note'] ?? '' ) ) );

		// Une ressource est un produit virtuel, rangé dans « Ressources ».
		update_post_meta( $post_id, '_virtual', 'yes' );
		$term = self::category_id();
		if ( $term ) {
			wp_set_object_terms( $post_id, array( $term ), 'product_cat', true );
		}
	}

	public static function category_id() {
		$term = get_term_by( 'slug', self::CATEGORY, 'product_cat' );
		if ( ! $term ) {
			$created = wp_insert_term( 'Ressources', 'product_cat', array( 'slug' => self::CATEGORY, 'description' => 'Logiciels, PDF, modèles et packs à télécharger.' ) );
			return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
		}
		return (int) $term->term_id;
	}

	/**
	 * Lien de téléchargement d'une ressource, vérifié à chaque clic.
	 */
	public static function download_url( $product_id ) {
		return add_query_arg( 'mla_res', (int) $product_id, home_url( '/' ) );
	}

	public static function user_owns( $user_id, $product_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && wc_customer_bought_product( $user->user_email, $user_id, $product_id );
	}

	public static function handle_download() {
		if ( empty( $_GET['mla_res'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$product_id = absint( $_GET['mla_res'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( self::download_url( $product_id ) ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		if ( ! self::is_resource( $product_id ) || ! self::user_owns( get_current_user_id(), $product_id ) ) {
			wp_die( 'Cette ressource n’est pas disponible pour votre compte.', 'Téléchargement', array( 'response' => 403 ) );
		}
		$url = MLA_Drive::download_url( get_post_meta( $product_id, self::META_URL, true ) );
		if ( ! $url ) {
			wp_die( 'Lien de téléchargement indisponible, contactez-nous.', 'Téléchargement', array( 'response' => 404 ) );
		}
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- destination Google Drive.
		exit;
	}

	/**
	 * Ressources achetées par un client.
	 *
	 * @return array<int,array{product:WC_Product,order_date:int}>
	 */
	public static function user_resources( $user_id ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => wc_get_is_paid_statuses(),
				'limit'       => -1,
			)
		);
		$list = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( $pid && ! isset( $list[ $pid ] ) && self::is_resource( $pid ) && ( $product = wc_get_product( $pid ) ) ) {
					$list[ $pid ] = array(
						'product'    => $product,
						'order_date' => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : $order->get_date_created()->getTimestamp(),
					);
				}
			}
		}
		return $list;
	}

	/* ------------------------------------------------------------------
	 * « Mes téléchargements » et détails de commande
	 * ---------------------------------------------------------------- */

	public static function replace_downloads_endpoint() {
		remove_action( 'woocommerce_account_downloads_endpoint', 'woocommerce_account_downloads' );
		add_action( 'woocommerce_account_downloads_endpoint', array( __CLASS__, 'render_downloads' ) );
	}

	/**
	 * Fichiers WooCommerce d'un produit lié à une formation : remplacés par un accès « Voir la formation ».
	 * Les ressources pointent vers le téléchargement vérifié.
	 */
	public static function order_items( $items, $order = null ) {
		$seen = array();
		$out  = array();
		foreach ( (array) $items as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			$courses = $pid ? MLA_Access::courses_for_product( $pid ) : array();
			if ( $courses ) {
				if ( isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ]          = true;
				$item['download_url']  = 1 === count( $courses ) ? MLA_Frontend::course_url( $courses[0] ) : MLA_Frontend::list_url();
				$item['download_name'] = 'Voir la formation';
				$item['file']          = array( 'name' => 'Voir la formation', 'file' => $item['download_url'] );
			} elseif ( $pid && self::is_resource( $pid ) ) {
				if ( isset( $seen[ $pid ] ) ) {
					continue;
				}
				$seen[ $pid ]          = true;
				$item['download_url']  = self::download_url( $pid );
				$item['download_name'] = 'Télécharger';
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function customer_items( $items ) {
		return self::order_items( $items );
	}

	/**
	 * Sous le récapitulatif d'une commande : accès directs aux formations et ressources achetées.
	 */
	public static function order_access( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->is_paid() || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return;
		}
		$rows = array();
		foreach ( MLA_Access::courses_in_order( $order ) as $course_id ) {
			$rows[] = array( 'icon' => 'cap', 'title' => get_the_title( $course_id ), 'meta' => 'Formation', 'url' => MLA_Frontend::course_url( $course_id ), 'label' => 'Voir', 'btn' => 'eye' );
		}
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $pid && self::is_resource( $pid ) ) {
				$rows[] = array( 'icon' => 'package', 'title' => $item->get_name(), 'meta' => self::type_label( $pid ), 'url' => self::download_url( $pid ), 'label' => 'Télécharger', 'btn' => 'download' );
			}
		}
		if ( ! $rows ) {
			return;
		}
		wp_enqueue_style( 'mla-front' );
		echo '<section class="mla mla-access"><h2 class="mla-access__title">Vos accès</h2>';
		self::rows( $rows );
		echo '</section>';
	}

	private static function rows( $rows ) {
		echo '<ul class="mla-dl-list">';
		foreach ( $rows as $row ) {
			echo '<li class="mla-dl-row">';
			if ( ! empty( $row['image'] ) ) {
				echo '<img class="mla-dl-row__thumb" src="' . esc_url( $row['image'] ) . '" alt="" loading="lazy">';
			} else {
				echo '<span class="mla-dl-row__icon">' . MLA_Icons::get( $row['icon'], 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<div class="mla-dl-row__body"><strong>' . esc_html( $row['title'] ) . '</strong><small>' . esc_html( $row['meta'] ) . '</small>';
			if ( isset( $row['percent'] ) ) {
				echo '<div class="mla-progress"><div class="mla-bar"><span style="width:' . (int) $row['percent'] . '%"></span></div><span>' . (int) $row['percent'] . '%</span></div>';
			}
			echo '</div>';
			echo '<a class="mla-btn' . ( 'download' === $row['btn'] ? ' mla-btn--ghost' : '' ) . '" href="' . esc_url( $row['url'] ) . '"' . ( 'download' === $row['btn'] ? ' target="_blank" rel="noopener"' : '' ) . '>'
				. MLA_Icons::get( $row['btn'], 16 ) . '<span>' . esc_html( $row['label'] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function render_downloads() {
		wp_enqueue_style( 'mla-front' );
		$user_id = get_current_user_id();

		$courses = array();
		foreach ( MLA_Access::user_courses( $user_id ) as $course_id ) {
			$card      = MLA_Frontend::card( $user_id, $course_id );
			$courses[] = array(
				'image'   => $card['image'],
				'icon'    => 'cap',
				'title'   => $card['title'],
				'meta'    => $card['progress']['total'] . ' leçon' . ( $card['progress']['total'] > 1 ? 's' : '' ) . ' · ' . $card['modules'] . ' module' . ( $card['modules'] > 1 ? 's' : '' ),
				'percent' => $card['progress']['percent'],
				'url'     => MLA_Frontend::course_url( $course_id ),
				'label'   => 'Voir',
				'btn'     => 'eye',
			);
		}

		$resources = array();
		foreach ( self::user_resources( $user_id ) as $pid => $res ) {
			$note        = get_post_meta( $pid, self::META_NOTE, true );
			$resources[] = array(
				'image' => wp_get_attachment_image_url( $res['product']->get_image_id(), 'thumbnail' ) ?: '',
				'icon'  => 'package',
				'title' => $res['product']->get_name(),
				'meta'  => self::type_label( $pid ) . ' · acheté le ' . wp_date( get_option( 'date_format' ), $res['order_date'] ) . ( $note ? ' · ' . $note : '' ),
				'url'   => self::download_url( $pid ),
				'label' => 'Télécharger',
				'btn'   => 'download',
			);
		}

		// Autres fichiers WooCommerce (ni formation, ni ressource).
		$others = array();
		foreach ( (array) WC()->customer->get_downloadable_products() as $dl ) {
			$pid = (int) $dl['product_id'];
			if ( MLA_Access::courses_for_product( $pid ) || self::is_resource( $pid ) ) {
				continue;
			}
			$others[] = array(
				'icon'  => 'file',
				'title' => $dl['product_name'],
				'meta'  => $dl['download_name'],
				'url'   => $dl['download_url'],
				'label' => 'Télécharger',
				'btn'   => 'download',
			);
		}

		echo '<div class="mla mla-downloads">';
		if ( ! $courses && ! $resources && ! $others ) {
			echo '<div class="mla-empty"><div class="mla-empty__icon">' . MLA_Icons::get( 'download', 40 ) . '</div><h3>Aucun téléchargement pour le moment</h3><p>Vos formations et ressources achetées apparaîtront ici.</p>' // phpcs:ignore WordPress.Security.EscapeOutput
				. '<a class="mla-btn" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Découvrir les formations</a></div></div>';
			return;
		}
		if ( $courses ) {
			echo '<h3 class="mla-dl-title">' . MLA_Icons::get( 'cap', 20 ) . ' Mes formations</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::rows( $courses );
		}
		if ( $resources ) {
			echo '<h3 class="mla-dl-title">' . MLA_Icons::get( 'package', 20 ) . ' Mes ressources</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::rows( $resources );
		}
		if ( $others ) {
			echo '<h3 class="mla-dl-title">' . MLA_Icons::get( 'file', 20 ) . ' Autres fichiers</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput
			self::rows( $others );
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------
	 * Page « Ressources »
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
				'post_title'   => 'Ressources',
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '<!-- wp:shortcode -->[mugiwara_ressources]<!-- /wp:shortcode -->',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			if ( defined( 'ELEMENTOR_VERSION' ) && ! get_post_meta( $id, '_wp_page_template', true ) ) {
				update_post_meta( $id, '_wp_page_template', 'elementor_header_footer' );
			}
			update_option( self::PAGE_OPT, (int) $id );
			self::category_id();
		}
	}

	public static function page_url() {
		$id = (int) get_option( self::PAGE_OPT );
		return $id ? get_permalink( $id ) : home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/**
	 * [mugiwara_ressources] : catalogue des ressources, filtrable par type.
	 */
	public static function shortcode( $atts ) {
		wp_enqueue_style( 'mla-front' );
		wp_enqueue_script( 'mla-front' );
		$atts = shortcode_atts( array( 'limit' => 60 ), $atts );

		$ids = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => (int) $atts['limit'],
				'category' => array( self::CATEGORY ),
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'ids',
			)
		);
		$user_id = get_current_user_id();
		$types   = array();
		ob_start();
		?>
		<div class="mla mla-res-page mg-wrap">
			<header class="mla-res-hero">
				<span class="mg-tag mg-tag--lime">Ressources</span>
				<h1 class="mg-h1">Logiciels, PDF et modèles <span class="mg-hl">prêts à l’emploi</span></h1>
				<p class="mg-lead">Des outils sélectionnés pour aller plus vite. Achetez une fois, téléchargez quand vous voulez depuis votre espace « Mes téléchargements ».</p>
			</header>
			<?php if ( ! $ids ) : ?>
				<div class="mla-empty"><div class="mla-empty__icon"><?php echo MLA_Icons::get( 'package', 40 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div><h3>Les ressources arrivent bientôt</h3><p>Revenez très vite : de nouveaux outils sont en préparation.</p></div>
			<?php else : ?>
				<?php
				foreach ( $ids as $pid ) {
					$types[ get_post_meta( $pid, self::META_TYPE, true ) ?: 'autre' ] = true;
				}
				?>
				<div class="mla-toolbar">
					<input type="search" class="mla-search" placeholder="Rechercher une ressource…" aria-label="Rechercher une ressource" data-mla-res-search>
					<?php if ( count( $types ) > 1 ) : ?>
						<div class="mla-filters" role="tablist">
							<button type="button" class="is-active" data-mla-res-filter="all">Tout</button>
							<?php foreach ( array_keys( $types ) as $type ) : ?>
								<button type="button" data-mla-res-filter="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( self::TYPES[ $type ] ?? 'Fichier' ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<div class="mla-grid mla-res-grid" data-mla-res-grid>
					<?php
					foreach ( $ids as $pid ) :
						$product = wc_get_product( $pid );
						if ( ! $product ) {
							continue;
						}
						$type  = get_post_meta( $pid, self::META_TYPE, true ) ?: 'autre';
						$owned = self::user_owns( $user_id, $pid ) && ! current_user_can( 'manage_woocommerce' );
						$image = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
						?>
						<article class="mla-card mla-res-card" data-title="<?php echo esc_attr( remove_accents( strtolower( $product->get_name() ) ) ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
							<a class="mla-card__media" href="<?php echo esc_url( $product->get_permalink() ); ?>" tabindex="-1">
								<?php if ( $image ) : ?>
									<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy">
								<?php else : ?>
									<span class="mla-card__placeholder"><?php echo MLA_Icons::get( 'package', 44 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<?php endif; ?>
								<span class="mla-badge"><?php echo esc_html( self::TYPES[ $type ] ?? 'Fichier' ); ?></span>
							</a>
							<div class="mla-card__body">
								<h3 class="mla-card__title"><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3>
								<p class="mla-card__meta"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 18 ) ); ?></p>
								<div class="mla-res-card__foot">
									<span class="mla-res-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
									<?php if ( $owned ) : ?>
										<a class="mla-btn mla-btn--ghost" href="<?php echo esc_url( self::download_url( $pid ) ); ?>" target="_blank" rel="noopener"><?php echo MLA_Icons::get( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Télécharger</span></a>
									<?php elseif ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
										<a class="mg-btn mg-btn--sm" href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo (int) $pid; ?>" rel="nofollow">Acheter</a>
									<?php endif; ?>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
				<p class="mla-noresult" data-mla-res-noresult hidden>Aucune ressource ne correspond à votre recherche.</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

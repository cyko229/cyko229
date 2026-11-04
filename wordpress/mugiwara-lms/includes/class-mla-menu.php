<?php
/**
 * Menu principal du site : méga-menus « Formations » et « Catégories » sur ordinateur,
 * panneau compact sur mobile. À placer dans l'en-tête Elementor avec [mugiwara_menu].
 */

defined( 'ABSPATH' ) || exit;

class MLA_Menu {

	public static function init() {
		add_shortcode( 'mugiwara_menu', array( __CLASS__, 'shortcode' ) );
		add_action( 'save_post_product', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'flush_cache' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache() {
		delete_transient( 'mla_menu_data' );
	}

	private static function page_url( $slug, $fallback = '' ) {
		$page = get_page_by_path( $slug );
		return $page ? get_permalink( $page ) : ( $fallback ?: home_url( '/' . $slug . '/' ) );
	}

	/**
	 * Catégories de formations et leurs formations (mis en cache 1 h, vidé à chaque modification).
	 */
	private static function data() {
		$data = get_transient( 'mla_menu_data' );
		if ( is_array( $data ) ) {
			return $data;
		}
		$exclude = array( 'uncategorized', 'non-classe', MLA_Resources::CATEGORY );
		$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
		$cats    = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( in_array( $term->slug, $exclude, true ) ) {
				continue;
			}
			$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 4, 'category' => array( $term->slug ), 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
			$cats[] = array(
				'id'       => $term->term_id,
				'name'     => $term->name,
				'url'      => get_term_link( $term ),
				'count'    => (int) $term->count,
				'image'    => wp_get_attachment_image_url( (int) get_term_meta( $term->term_id, 'thumbnail_id', true ), 'medium' ) ?: '',
				'products' => array_values( array_filter( array_map( array( __CLASS__, 'product' ), $ids ) ) ),
			);
		}
		$latest = wc_get_products( array( 'status' => 'publish', 'limit' => 4, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );
		$data   = array(
			'cats'   => $cats,
			'latest' => array_values( array_filter( array_map( array( __CLASS__, 'product' ), $latest ) ) ),
		);
		set_transient( 'mla_menu_data', $data, HOUR_IN_SECONDS );
		return $data;
	}

	private static function product( $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return null;
		}
		return array(
			'name'  => $p->get_name(),
			'url'   => $p->get_permalink(),
			'image' => wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) ?: '',
			'price' => wp_strip_all_tags( $p->get_price_html() ),
		);
	}

	private static function current( $url ) {
		$path    = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$request = untrailingslashit( (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH ) );
		return $path === $request ? ' is-current' : '';
	}

	public static function shortcode() {
		wp_enqueue_style( 'mla-front' );
		wp_enqueue_script( 'mla-front' );
		$d       = self::data();
		$links   = array(
			'home'       => array( 'Accueil', home_url( '/' ), 'home' ),
			'formations' => array( 'Formations', self::page_url( 'formations', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '' ), 'cap' ),
			'categories' => array( 'Catégories', self::page_url( 'categories' ), 'grid' ),
			'ressources' => array( 'Ressources', MLA_Resources::page_url(), 'package' ),
			'contact'    => array( 'Contact', self::page_url( 'contact' ), 'mail' ),
		);
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		ob_start();
		?>
		<nav class="mla mla-menu" aria-label="Menu principal" data-mla-menu>
			<ul class="mla-menu__bar">
				<li><a class="mla-menu__link<?php echo esc_attr( self::current( $links['home'][1] ) ); ?>" href="<?php echo esc_url( $links['home'][1] ); ?>">Accueil</a></li>

				<li class="mla-menu__item has-mega" data-mla-mega>
					<a class="mla-menu__link<?php echo esc_attr( self::current( $links['formations'][1] ) ); ?>" href="<?php echo esc_url( $links['formations'][1] ); ?>" aria-haspopup="true" aria-expanded="false">Formations <?php echo MLA_Icons::get( 'chevron-down', 14, 'mla-menu__chev' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					<div class="mla-mega mla-mega--formations" role="region" aria-label="Formations">
						<div class="mla-mega__inner">
							<ul class="mla-mega__cats">
								<li><button type="button" class="is-active" data-mla-mega-tab="latest"><?php echo MLA_Icons::get( 'sparkles', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Nouveautés</span></button></li>
								<?php foreach ( array_slice( $d['cats'], 0, 7 ) as $cat ) : ?>
									<li><button type="button" data-mla-mega-tab="c<?php echo (int) $cat['id']; ?>"><?php echo MLA_Icons::get( 'book', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $cat['name'] ); ?></span><small><?php echo (int) $cat['count']; ?></small></button></li>
								<?php endforeach; ?>
							</ul>
							<div class="mla-mega__panels">
								<?php
								$panels = array_merge(
									array( array( 'key' => 'latest', 'title' => 'Dernières formations', 'url' => $links['formations'][1], 'products' => $d['latest'] ) ),
									array_map( function ( $c ) { return array( 'key' => 'c' . $c['id'], 'title' => $c['name'], 'url' => $c['url'], 'products' => $c['products'] ); }, array_slice( $d['cats'], 0, 7 ) )
								);
								foreach ( $panels as $i => $panel ) :
									?>
									<div class="mla-mega__panel" data-mla-mega-panel="<?php echo esc_attr( $panel['key'] ); ?>" <?php echo $i ? 'hidden' : ''; ?>>
										<div class="mla-mega__head"><strong><?php echo esc_html( $panel['title'] ); ?></strong><a href="<?php echo esc_url( $panel['url'] ); ?>">Tout voir <?php echo MLA_Icons::get( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></div>
										<ul class="mla-mega__products">
											<?php foreach ( $panel['products'] as $prod ) : ?>
												<li><a href="<?php echo esc_url( $prod['url'] ); ?>">
													<?php if ( $prod['image'] ) : ?>
														<img src="<?php echo esc_url( $prod['image'] ); ?>" alt="" loading="lazy">
													<?php else : ?>
														<span class="mla-mega__ph"><?php echo MLA_Icons::get( 'cap', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
													<?php endif; ?>
													<span><strong><?php echo esc_html( $prod['name'] ); ?></strong><small><?php echo esc_html( $prod['price'] ); ?></small></span>
												</a></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</li>

				<li class="mla-menu__item has-mega" data-mla-mega>
					<a class="mla-menu__link<?php echo esc_attr( self::current( $links['categories'][1] ) ); ?>" href="<?php echo esc_url( $links['categories'][1] ); ?>" aria-haspopup="true" aria-expanded="false">Catégories <?php echo MLA_Icons::get( 'chevron-down', 14, 'mla-menu__chev' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					<div class="mla-mega mla-mega--categories" role="region" aria-label="Catégories">
						<div class="mla-mega__inner">
							<ul class="mla-mega__grid">
								<?php foreach ( array_slice( $d['cats'], 0, 8 ) as $cat ) : ?>
									<li><a href="<?php echo esc_url( $cat['url'] ); ?>">
										<?php if ( $cat['image'] ) : ?>
											<img src="<?php echo esc_url( $cat['image'] ); ?>" alt="" loading="lazy">
										<?php else : ?>
											<span class="mla-mega__ph"><?php echo MLA_Icons::get( 'book', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
										<?php endif; ?>
										<span><strong><?php echo esc_html( $cat['name'] ); ?></strong><small><?php echo (int) $cat['count']; ?> formation<?php echo $cat['count'] > 1 ? 's' : ''; ?></small></span>
									</a></li>
								<?php endforeach; ?>
							</ul>
							<a class="mla-mega__all" href="<?php echo esc_url( $links['categories'][1] ); ?>">Toutes les catégories <?php echo MLA_Icons::get( 'arrow-right', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						</div>
					</div>
				</li>

				<li><a class="mla-menu__link<?php echo esc_attr( self::current( $links['ressources'][1] ) ); ?>" href="<?php echo esc_url( $links['ressources'][1] ); ?>">Ressources</a></li>
				<li><a class="mla-menu__link<?php echo esc_attr( self::current( $links['contact'][1] ) ); ?>" href="<?php echo esc_url( $links['contact'][1] ); ?>">Contact</a></li>
			</ul>

			<div class="mla-menu__mobile">
				<a class="mla-menu__account" href="<?php echo esc_url( $account ); ?>" aria-label="Mon compte"><?php echo MLA_Icons::get( 'user', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<button type="button" class="mla-menu__burger" aria-expanded="false" aria-controls="mla-menu-panel" data-mla-menu-toggle>
					<span class="mla-menu__burger-open"><?php echo MLA_Icons::get( 'menu', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span class="mla-menu__burger-close"><?php echo MLA_Icons::get( 'x', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span class="screen-reader-text">Menu</span>
				</button>
				<div class="mla-menu__panel" id="mla-menu-panel" hidden data-mla-menu-panel>
					<ul>
						<?php foreach ( $links as $link ) : ?>
							<li><a class="<?php echo esc_attr( trim( self::current( $link[1] ) ) ); ?>" href="<?php echo esc_url( $link[1] ); ?>"><span class="mla-menu__ico"><?php echo MLA_Icons::get( $link[2], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><?php echo esc_html( $link[0] ); ?><?php echo MLA_Icons::get( 'chevron-right', 16, 'mla-menu__go' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a></li>
						<?php endforeach; ?>
					</ul>
					<a class="mla-btn mla-btn--block" href="<?php echo esc_url( $account ); ?>"><?php echo MLA_Icons::get( 'user', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo is_user_logged_in() ? 'Mon compte' : 'Se connecter'; ?></span></a>
				</div>
			</div>
		</nav>
		<?php
		return ob_get_clean();
	}
}

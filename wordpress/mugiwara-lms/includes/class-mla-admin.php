<?php
/**
 * Administration : constructeur de formation, import Google Drive, liaison produits, accès manuels, réglages.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Admin {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . MLA_Courses::POST_TYPE, array( __CLASS__, 'save_course' ), 10, 2 );
		add_action( 'save_post_product', array( __CLASS__, 'save_product' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_mla_drive_import', array( __CLASS__, 'ajax_drive_import' ) );

		add_filter( 'manage_' . MLA_Courses::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . MLA_Courses::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );

		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user' ) );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_mla_sync_orders', array( __CLASS__, 'sync_orders' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || MLA_Courses::POST_TYPE !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		wp_enqueue_style( 'mla-admin', MLA_URL . 'assets/admin.css', array(), MLA_VERSION );
		wp_enqueue_script( 'mla-admin', MLA_URL . 'assets/admin.js', array( 'jquery-ui-sortable' ), MLA_VERSION, true );
		wp_localize_script(
			'mla-admin',
			'MLA_ADMIN',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'mla_drive_import' ),
				'types'  => MLA_Courses::LESSON_TYPES,
				'hasKey' => (bool) mla_option( 'drive_api_key' ),
			)
		);
	}

	public static function meta_boxes() {
		add_meta_box( 'mla_builder', 'Contenu de la formation', array( __CLASS__, 'builder_box' ), MLA_Courses::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'mla_products', 'Produits WooCommerce', array( __CLASS__, 'course_products_box' ), MLA_Courses::POST_TYPE, 'side' );
		add_meta_box( 'mla_product_courses', 'Espace formation', array( __CLASS__, 'product_box' ), 'product', 'side' );
	}

	/* ------------------------------------------------------------------
	 * Formation
	 * ---------------------------------------------------------------- */

	public static function builder_box( $post ) {
		wp_nonce_field( 'mla_save_course', 'mla_course_nonce' );
		$structure = MLA_Courses::structure( $post->ID );
		?>
		<div class="mla-admin" data-mla-builder>
			<input type="hidden" name="mla_structure" value="<?php echo esc_attr( wp_json_encode( $structure ) ); ?>" data-mla-structure>

			<div class="mla-admin__import">
				<h4>⚡ Importer depuis Google Drive</h4>
				<p class="description">
					Collez le lien d’un ou plusieurs dossiers Drive (un par ligne), partagés en « Tous les utilisateurs disposant du lien ».
					Chaque sous-dossier devient un module, chaque fichier une leçon ; un sous-dossier « Ressources » ou « Bonus » alimente l’onglet Ressources.
				</p>
				<div class="mla-admin__row">
					<textarea class="widefat" rows="2" placeholder="https://drive.google.com/drive/folders/…" data-mla-import-url></textarea>
					<button type="button" class="button button-primary" data-mla-import>Importer</button>
				</div>
				<p class="mla-admin__status" data-mla-import-status></p>
			</div>

			<p>
				<label for="mla_download_all"><strong>Télécharger toute la formation</strong> — lien Drive (dossier complet ou archive ZIP)</label>
				<input type="url" id="mla_download_all" class="widefat" value="<?php echo esc_attr( $structure['download_all'] ); ?>" placeholder="https://drive.google.com/drive/folders/…" data-mla-download-all>
			</p>

			<h3 class="mla-admin__h">Modules et leçons</h3>
			<div class="mla-admin__modules" data-mla-modules></div>
			<p><button type="button" class="button" data-mla-add-module>+ Ajouter un module</button></p>

			<h3 class="mla-admin__h">Ressources</h3>
			<p class="description">Fichiers complémentaires téléchargeables : fiches, modèles, bonus, liens utiles…</p>
			<div class="mla-admin__resources" data-mla-resources></div>
			<p><button type="button" class="button" data-mla-add-resource>+ Ajouter une ressource</button></p>
		</div>
		<?php
	}

	public static function course_products_box( $post ) {
		$products = MLA_Access::products_for_course( $post->ID );
		if ( $products ) {
			echo '<ul style="margin:0">';
			foreach ( $products as $product_id ) {
				echo '<li><a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( get_the_title( $product_id ) ) . '</a></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>Aucun produit ne donne encore accès à cette formation.</p>';
		}
		echo '<p class="description">L’accès se règle sur la fiche produit, encadré « Espace formation ».</p>';
		echo '<p><strong>' . (int) MLA_Access::count_students( $post->ID ) . '</strong> élève(s) inscrit(s).</p>';
		if ( 'publish' === $post->post_status && class_exists( 'MLA_Frontend' ) && function_exists( 'wc_get_page_permalink' ) ) {
			echo '<p><a class="button" target="_blank" href="' . esc_url( MLA_Frontend::course_url( $post->ID ) ) . '">Voir comme un élève</a></p>';
		}
	}

	public static function save_course( $post_id, $post ) {
		if ( ! isset( $_POST['mla_course_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mla_course_nonce'] ), 'mla_save_course' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$json = isset( $_POST['mla_structure'] ) ? json_decode( wp_unslash( $_POST['mla_structure'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nettoyé par sanitize_structure().
		if ( is_array( $json ) ) {
			MLA_Courses::save_structure( $post_id, $json );
		}
	}

	public static function ajax_drive_import() {
		check_ajax_referer( 'mla_drive_import' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Action non autorisée.', 403 );
		}
		$urls   = isset( $_POST['url'] ) ? preg_split( '~\s+~', sanitize_textarea_field( wp_unslash( $_POST['url'] ) ) ) : array();
		$result = MLA_Drive::import_folders( array_map( 'esc_url_raw', $urls ), mla_option( 'drive_api_key' ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( MLA_Courses::sanitize_structure( $result ) );
	}

	public static function columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['mla_lessons']  = 'Leçons';
		$columns['mla_products'] = 'Produits';
		$columns['mla_students'] = 'Élèves';
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'mla_lessons':
				$s = MLA_Courses::structure( $post_id );
				echo (int) count( MLA_Courses::lessons( $post_id ) ) . ' leçons · ' . count( $s['modules'] ) . ' modules';
				break;
			case 'mla_products':
				$links = array_map(
					function ( $id ) {
						return '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
					},
					MLA_Access::products_for_course( $post_id )
				);
				echo $links ? implode( ', ', $links ) : '<span style="color:#b32d2e">Aucun</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'mla_students':
				echo (int) MLA_Access::count_students( $post_id );
				break;
		}
	}

	/* ------------------------------------------------------------------
	 * Produit
	 * ---------------------------------------------------------------- */

	public static function product_box( $post ) {
		wp_nonce_field( 'mla_save_product', 'mla_product_nonce' );
		$linked  = MLA_Access::courses_for_product( $post->ID );
		$courses = MLA_Courses::all( array( 'post_status' => array( 'publish', 'draft', 'pending', 'private' ) ) );
		$new_url = add_query_arg(
			array(
				'post_type'   => MLA_Courses::POST_TYPE,
				'mla_product' => $post->ID,
			),
			admin_url( 'post-new.php' )
		);
		echo '<p class="description">L’achat de ce produit ouvre l’accès à :</p>';
		if ( $courses ) {
			echo '<div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:6px 8px;border-radius:4px">';
			foreach ( $courses as $course ) {
				printf(
					'<label style="display:block;margin:4px 0"><input type="checkbox" name="mla_courses[]" value="%d" %s> %s%s</label>',
					(int) $course->ID,
					checked( in_array( $course->ID, $linked, true ), true, false ),
					esc_html( $course->post_title ),
					'publish' === $course->post_status ? '' : ' <em>(brouillon)</em>'
				);
			}
			echo '</div>';
		} else {
			echo '<p>Aucune formation créée pour l’instant.</p>';
		}
		echo '<p><a class="button" href="' . esc_url( $new_url ) . '">+ Créer la formation de ce produit</a></p>';
	}

	public static function save_product( $post_id ) {
		if ( ! isset( $_POST['mla_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mla_product_nonce'] ), 'mla_save_product' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$ids = isset( $_POST['mla_courses'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['mla_courses'] ) ) : array();
		MLA_Access::set_product_courses( $post_id, $ids );
	}

	/* ------------------------------------------------------------------
	 * Accès manuels sur le profil utilisateur
	 * ---------------------------------------------------------------- */

	public static function user_fields( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$enrolled = MLA_Access::enrollments( $user->ID );
		$courses  = MLA_Courses::all();
		wp_nonce_field( 'mla_save_user', 'mla_user_nonce' );
		echo '<h2>Espace formation</h2><table class="form-table"><tr><th>Formations accessibles</th><td>';
		if ( ! $courses ) {
			echo '<p>Aucune formation publiée.</p>';
		}
		foreach ( $courses as $course ) {
			$info   = $enrolled[ $course->ID ] ?? null;
			$detail = '';
			if ( $info ) {
				$detail = ' <span class="description">— depuis le ' . esc_html( wp_date( get_option( 'date_format' ), $info['date'] ) )
					. ( 'order' === $info['source'] && $info['order'] ? ', commande #' . (int) $info['order'] : ', accès manuel' ) . '</span>';
			}
			printf(
				'<label style="display:block;margin:4px 0"><input type="checkbox" name="mla_user_courses[]" value="%d" %s> %s%s</label>',
				(int) $course->ID,
				checked( (bool) $info, true, false ),
				esc_html( $course->post_title ),
				$detail // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '<p class="description">Cochez pour offrir une formation, décochez pour retirer l’accès.</p></td></tr></table>';
	}

	public static function save_user( $user_id ) {
		if ( ! isset( $_POST['mla_user_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['mla_user_nonce'] ), 'mla_save_user' ) || ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$wanted  = isset( $_POST['mla_user_courses'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['mla_user_courses'] ) ) : array();
		$current = array_keys( MLA_Access::enrollments( $user_id ) );
		$listed  = wp_list_pluck( MLA_Courses::all(), 'ID' );
		foreach ( array_diff( $wanted, $current ) as $course_id ) {
			MLA_Access::enroll( $user_id, $course_id, 'manual' );
		}
		foreach ( array_intersect( array_diff( $current, $wanted ), $listed ) as $course_id ) {
			MLA_Access::unenroll( $user_id, $course_id );
		}
	}

	/* ------------------------------------------------------------------
	 * Réglages
	 * ---------------------------------------------------------------- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . MLA_Courses::POST_TYPE, 'Réglages de l’espace formation', 'Réglages', 'manage_options', 'mla-settings', array( __CLASS__, 'settings_page' ) );
	}

	public static function register_settings() {
		register_setting(
			'mla_settings',
			'mla_settings',
			array(
				'sanitize_callback' => function ( $input ) {
					return array(
						'drive_api_key'      => sanitize_text_field( $input['drive_api_key'] ?? '' ),
						'accent'             => sanitize_hex_color( $input['accent'] ?? '' ) ?: '#1E4DF0',
						'grant_processing'   => empty( $input['grant_processing'] ) ? 'no' : 'yes',
						'redirect_dashboard' => empty( $input['redirect_dashboard'] ) ? 'no' : 'yes',
						'block_repurchase'   => empty( $input['block_repurchase'] ) ? 'no' : 'yes',
					);
				},
			)
		);

		// Pré-remplit une nouvelle formation créée depuis une fiche produit.
		global $pagenow;
		if ( 'post-new.php' === $pagenow && ! empty( $_GET['mla_product'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_filter(
				'default_title',
				function ( $title, $post ) {
					return MLA_Courses::POST_TYPE === $post->post_type ? get_the_title( (int) $_GET['mla_product'] ) : $title; // phpcs:ignore WordPress.Security.NonceVerification
				},
				10,
				2
			);
			add_action(
				'save_post_' . MLA_Courses::POST_TYPE,
				function ( $post_id ) {
					$product_id = isset( $_GET['mla_product'] ) ? (int) $_GET['mla_product'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
					if ( $product_id && current_user_can( 'edit_post', $product_id ) && ! get_post_meta( $post_id, '_mla_from_product', true ) ) {
						update_post_meta( $post_id, '_mla_from_product', $product_id );
						add_post_meta( $product_id, MLA_Access::PRODUCT_META, $post_id );
						$thumb = get_post_thumbnail_id( $product_id );
						if ( $thumb ) {
							set_post_thumbnail( $post_id, $thumb );
						}
					}
				}
			);
		}
	}

	public static function settings_page() {
		$o = wp_parse_args(
			get_option( 'mla_settings', array() ),
			array(
				'drive_api_key'      => '',
				'accent'             => '#1E4DF0',
				'grant_processing'   => 'yes',
				'redirect_dashboard' => 'yes',
				'block_repurchase'   => 'yes',
			)
		);
		?>
		<div class="wrap">
			<h1>Réglages de l’espace formation</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'mla_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mla_key">Clé API Google Drive</label></th>
						<td>
							<input type="text" id="mla_key" class="regular-text" name="mla_settings[drive_api_key]" value="<?php echo esc_attr( $o['drive_api_key'] ); ?>" autocomplete="off">
							<p class="description">Facultative : sans clé, l’import lit les dossiers partagés par lien. Une clé ajoute les durées des vidéos et les très gros dossiers.
								<a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener">Activez l’API Google Drive</a>, puis créez une clé dans « Identifiants » (restreinte à l’API Drive).</p>
						</td>
					</tr>
					<tr>
						<th><label for="mla_accent">Couleur principale</label></th>
						<td><input type="color" id="mla_accent" name="mla_settings[accent]" value="<?php echo esc_attr( $o['accent'] ); ?>"></td>
					</tr>
					<tr>
						<th>Accès</th>
						<td>
							<label><input type="checkbox" name="mla_settings[grant_processing]" value="1" <?php checked( 'yes', $o['grant_processing'] ); ?>> Ouvrir l’accès dès que le paiement est reçu (commande « En cours »), sans attendre « Terminée »</label><br>
							<label><input type="checkbox" name="mla_settings[block_repurchase]" value="1" <?php checked( 'yes', $o['block_repurchase'] ); ?>> Empêcher un élève de racheter une formation qu’il possède déjà</label><br>
							<label><input type="checkbox" name="mla_settings[redirect_dashboard]" value="1" <?php checked( 'yes', $o['redirect_dashboard'] ); ?>> Ouvrir « Mes formations » à la connexion et à la place du tableau de bord de Mon compte</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2>Commandes déjà passées</h2>
			<p>Après avoir lié vos produits à leurs formations, ouvrez l’accès aux clients qui ont déjà acheté (commandes « En cours » et « Terminée »).</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mla_sync_orders">
				<?php wp_nonce_field( 'mla_sync_orders' ); ?>
				<?php submit_button( 'Donner l’accès aux anciens acheteurs', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>Intégration</h2>
			<p>L’espace élève se trouve dans <strong>Mon compte → Mes formations</strong>. Pour l’afficher dans une page Elementor, utilisez le shortcode <code>[mugiwara_formations]</code>.</p>
		</div>
		<?php
	}

	public static function sync_orders() {
		check_admin_referer( 'mla_sync_orders' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$count = MLA_Access::sync_existing_orders();
		wp_safe_redirect( add_query_arg( array( 'post_type' => MLA_Courses::POST_TYPE, 'page' => 'mla-settings', 'mla_synced' => $count ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function notices() {
		if ( isset( $_GET['mla_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . (int) $_GET['mla_synced'] . ' commande(s) vérifiée(s) : les accès manquants ont été ouverts.</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
}

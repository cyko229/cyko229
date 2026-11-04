<?php
/**
 * Administration du parrainage : demandes d'adhésion, parrains, retraits à payer, réglages.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Affiliate_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mla_aff_status', array( __CLASS__, 'post_status' ) );
		add_action( 'admin_post_mla_aff_withdrawal', array( __CLASS__, 'post_withdrawal' ) );
		add_action( 'admin_post_mla_aff_adjust', array( __CLASS__, 'post_adjust' ) );
		add_action( 'admin_post_mla_aff_settings', array( __CLASS__, 'post_settings' ) );
	}

	public static function menu() {
		$pending = count( self::users_by_status( 'pending' ) ) + count( MLA_Affiliate::withdrawals( 0, 'pending' ) );
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_menu_page( 'Parrainage', 'Parrainage' . $badge, 'manage_woocommerce', 'mla-affiliates', array( __CLASS__, 'page_affiliates' ), 'dashicons-groups', 57 );
		add_submenu_page( 'mla-affiliates', 'Parrains', 'Parrains', 'manage_woocommerce', 'mla-affiliates', array( __CLASS__, 'page_affiliates' ) );
		add_submenu_page( 'mla-affiliates', 'Retraits', 'Retraits', 'manage_woocommerce', 'mla-withdrawals', array( __CLASS__, 'page_withdrawals' ) );
		add_submenu_page( 'mla-affiliates', 'Réglages du parrainage', 'Réglages', 'manage_options', 'mla-aff-settings', array( __CLASS__, 'page_settings' ) );
	}

	private static function users_by_status( $status ) {
		return get_users( array( 'meta_key' => MLA_Affiliate::STATUS_META, 'meta_value' => $status, 'number' => 500 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	private static function notice() {
		$messages = array(
			'approved' => 'Adhésion validée : le parrain a été prévenu par e-mail.',
			'rejected' => 'Demande refusée : la personne a été prévenue par e-mail.',
			'suspended' => 'Parrain suspendu : ses liens ne rapportent plus de commission.',
			'paid'     => 'Retrait marqué comme payé : le parrain a été prévenu.',
			'refused'  => 'Retrait refusé : les Berry ont été rendus au parrain.',
			'adjusted' => 'Solde ajusté.',
			'saved'    => 'Réglages enregistrés.',
		);
		$key = isset( $_GET['mla_done'] ) ? sanitize_key( $_GET['mla_done'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	private static function action_form( $action, $fields, $label, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function page_affiliates() {
		echo '<div class="wrap"><h1>Parrainage</h1>';
		self::notice();

		$pending = self::users_by_status( 'pending' );
		echo '<h2>Demandes en attente (' . count( $pending ) . ')</h2>';
		if ( ! $pending ) {
			echo '<p>Aucune demande en attente.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Personne</th><th>Téléphone</th><th>Canal</th><th>Message</th><th>Date</th><th>Décision</th></tr></thead><tbody>';
			foreach ( $pending as $u ) {
				echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></td>';
				echo '<td>' . esc_html( get_user_meta( $u->ID, 'mla_aff_phone', true ) ) . '</td>';
				echo '<td>' . esc_html( get_user_meta( $u->ID, 'mla_aff_channel', true ) ) . '</td>';
				echo '<td style="max-width:320px">' . esc_html( get_user_meta( $u->ID, 'mla_aff_motivation', true ) ) . '</td>';
				echo '<td>' . esc_html( wp_date( 'd/m/Y H:i', (int) get_user_meta( $u->ID, 'mla_aff_applied', true ) ) ) . '</td><td>';
				self::action_form( 'mla_aff_status', array( 'user' => $u->ID, 'status' => 'approved' ), 'Valider', 'button button-primary' );
				self::action_form( 'mla_aff_status', array( 'user' => $u->ID, 'status' => 'rejected' ), 'Refuser' );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$approved = array_merge( self::users_by_status( 'approved' ), self::users_by_status( 'suspended' ) );
		echo '<h2 style="margin-top:32px">Parrains (' . count( $approved ) . ')</h2>';
		if ( ! $approved ) {
			echo '<p>Aucun parrain pour le moment.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Parrain</th><th>Code</th><th>Clics</th><th>Ventes</th><th>Gagné</th><th>Solde</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $approved as $u ) {
				$s = MLA_Affiliate::stats( $u->ID );
				$suspended = 'suspended' === MLA_Affiliate::status( $u->ID );
				echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong>' . ( $suspended ? ' <em>(suspendu)</em>' : '' ) . '<br>' . esc_html( $u->user_email ) . '</td>';
				echo '<td><code>' . esc_html( MLA_Affiliate::code( $u->ID ) ) . '</code></td>';
				echo '<td>' . (int) $s['clicks'] . '</td><td>' . (int) $s['sales'] . '</td>';
				echo '<td>' . esc_html( MLA_Affiliate::format_berry( $s['earned'] ) ) . ' Berry</td>';
				echo '<td><strong>' . esc_html( MLA_Affiliate::format_berry( $s['balance'] ) ) . ' Berry</strong><br><small>' . esc_html( MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['balance'] ) ) ) . '</small></td><td>';
				self::action_form( 'mla_aff_status', array( 'user' => $u->ID, 'status' => $suspended ? 'approved' : 'suspended' ), $suspended ? 'Réactiver' : 'Suspendre' );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:4px;margin:2px">';
				wp_nonce_field( 'mla_aff_adjust' );
				echo '<input type="hidden" name="action" value="mla_aff_adjust"><input type="hidden" name="user" value="' . (int) $u->ID . '">';
				echo '<input type="number" step="0.01" name="berries" placeholder="± Berry" style="width:90px"><input type="text" name="note" placeholder="Motif" style="width:120px"><button class="button">Ajuster</button></form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function page_withdrawals() {
		echo '<div class="wrap"><h1>Retraits</h1>';
		self::notice();
		$pending = MLA_Affiliate::withdrawals( 0, 'pending' );
		echo '<p>Envoyez le montant sur le compte indiqué, puis cliquez sur « Marquer comme payé ». Le parrain est prévenu par e-mail. Délai annoncé : 1 jour ouvré.</p>';
		echo '<h2>À payer (' . count( $pending ) . ')</h2>';
		self::withdrawal_table( $pending, true );
		$done = array_filter( MLA_Affiliate::withdrawals( 0, '', 100 ), function ( $w ) { return 'pending' !== $w['status']; } );
		echo '<h2 style="margin-top:32px">Historique</h2>';
		self::withdrawal_table( $done, false );
		echo '</div>';
	}

	private static function withdrawal_table( $rows, $actions ) {
		if ( ! $rows ) {
			echo '<p>Aucun retrait.</p>';
			return;
		}
		$states = array( 'pending' => 'En attente', 'paid' => 'Payé', 'rejected' => 'Refusé' );
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Parrain</th><th>Montant à envoyer</th><th>Moyen</th><th>Numéro</th><th>Titulaire</th><th>' . ( $actions ? 'Action' : 'État' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $w ) {
			$u = get_userdata( $w['user_id'] );
			echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $w['created_at'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $u ? $u->display_name . ' (' . $u->user_email . ')' : '#' . $w['user_id'] ) . '</td>';
			echo '<td><strong style="font-size:15px">' . esc_html( MLA_Affiliate::format_fcfa( $w['amount'] ) ) . '</strong><br><small>' . esc_html( MLA_Affiliate::format_berry( $w['berries'] ) ) . ' Berry</small></td>';
			echo '<td><strong>' . esc_html( MLA_Affiliate::METHODS[ $w['method'] ] ?? $w['method'] ) . '</strong></td>';
			echo '<td><code style="font-size:14px">' . esc_html( $w['phone'] ) . '</code></td><td>' . esc_html( $w['holder'] ) . '</td><td>';
			if ( $actions ) {
				self::action_form( 'mla_aff_withdrawal', array( 'id' => $w['id'], 'status' => 'paid' ), 'Marquer comme payé', 'button button-primary' );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:4px;margin:2px">';
				wp_nonce_field( 'mla_aff_withdrawal' );
				echo '<input type="hidden" name="action" value="mla_aff_withdrawal"><input type="hidden" name="id" value="' . (int) $w['id'] . '"><input type="hidden" name="status" value="rejected">';
				echo '<input type="text" name="note" placeholder="Motif du refus" style="width:140px"><button class="button">Refuser</button></form>';
			} else {
				echo esc_html( $states[ $w['status'] ] ?? $w['status'] ) . ( $w['processed_at'] ? '<br><small>' . esc_html( wp_date( 'd/m/Y', strtotime( $w['processed_at'] ) ) ) . '</small>' : '' ) . ( $w['admin_note'] ? '<br><small>' . esc_html( $w['admin_note'] ) . '</small>' : '' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function page_settings() {
		echo '<div class="wrap"><h1>Réglages du parrainage</h1>';
		self::notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mla_aff_settings' );
		echo '<input type="hidden" name="action" value="mla_aff_settings"><table class="form-table">';
		$fields = array(
			'aff_rate'        => array( 'Commission (%)', MLA_Affiliate::rate(), 'Part du montant payé de chaque commande reversée au parrain.' ),
			'berry_value'     => array( 'Valeur d’un Berry (FCFA)', MLA_Affiliate::berry_value(), '100 = 1 Berry vaut 100 FCFA.' ),
			'berry_min'       => array( 'Seuil minimum (Berry)', MLA_Affiliate::minimum(), 'Minimum pour convertir en code promo ou demander un retrait.' ),
			'aff_cookie_days' => array( 'Durée du suivi (jours)', MLA_Affiliate::cookie_days(), 'Un achat fait dans ce délai après un clic est attribué au parrain.' ),
		);
		foreach ( $fields as $key => $f ) {
			echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $f[0] ) . '</label></th><td><input type="number" step="0.01" min="0" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $f[1] ) . '" class="small-text"> <span class="description">' . esc_html( $f[2] ) . '</span></td></tr>';
		}
		echo '<tr><th><label for="berry_image">Image de la pièce Berry</label></th><td><input type="url" id="berry_image" name="berry_image" class="regular-text" value="' . esc_attr( mla_option( 'berry_image' ) ) . '" placeholder="https://…/berry.png"> <p class="description">Facultatif : URL d’une image carrée (PNG transparent) de la Médiathèque. Sinon, une pièce dorée est dessinée.</p></td></tr>';
		echo '</table>';
		submit_button( 'Enregistrer' );
		echo '</form><p>Page publique du programme : <a href="' . esc_url( MLA_Affiliate::page_url() ) . '" target="_blank">' . esc_html( MLA_Affiliate::page_url() ) . '</a></p></div>';
	}

	private static function done( $page, $key ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'mla_done' => $key ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function post_status() {
		check_admin_referer( 'mla_aff_status' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$status = sanitize_key( $_POST['status'] ?? '' );
		if ( in_array( $status, array( 'approved', 'rejected', 'suspended' ), true ) ) {
			MLA_Affiliate::set_status( absint( $_POST['user'] ?? 0 ), $status );
		}
		self::done( 'mla-affiliates', $status );
	}

	public static function post_withdrawal() {
		check_admin_referer( 'mla_aff_withdrawal' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$status = sanitize_key( $_POST['status'] ?? '' );
		MLA_Affiliate::process_withdrawal( absint( $_POST['id'] ?? 0 ), $status, sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		self::done( 'mla-withdrawals', 'paid' === $status ? 'paid' : 'refused' );
	}

	public static function post_adjust() {
		check_admin_referer( 'mla_aff_adjust' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$berries = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['berries'] ?? '0' ) ) );
		if ( 0.0 !== $berries ) {
			MLA_Affiliate::add_entry( absint( $_POST['user'] ?? 0 ), 'adjust', $berries, array( 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) ?: 'Ajustement manuel' ) );
		}
		self::done( 'mla-affiliates', 'adjusted' );
	}

	public static function post_settings() {
		check_admin_referer( 'mla_aff_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$options = get_option( 'mla_settings', array() );
		foreach ( array( 'aff_rate', 'berry_value', 'berry_min', 'aff_cookie_days' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
				$options[ $key ] = max( 0, (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) );
			}
		}
		$options['berry_image'] = esc_url_raw( wp_unslash( $_POST['berry_image'] ?? '' ) );
		update_option( 'mla_settings', $options );
		self::done( 'mla-aff-settings', 'saved' );
	}
}

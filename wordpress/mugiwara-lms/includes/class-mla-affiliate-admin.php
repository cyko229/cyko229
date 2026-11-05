<?php
/**
 * Administration du parrainage : tableau de bord, demandes, parrains (avec fiche détaillée),
 * retraits à payer (export CSV) et réglages.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Affiliate_Admin {

	const PAGES = array( 'mla-affiliates', 'mla-aff-requests', 'mla-aff-members', 'mla-withdrawals', 'mla-aff-settings' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_mla_aff_status', array( __CLASS__, 'post_status' ) );
		add_action( 'admin_post_mla_aff_withdrawal', array( __CLASS__, 'post_withdrawal' ) );
		add_action( 'admin_post_mla_aff_adjust', array( __CLASS__, 'post_adjust' ) );
		add_action( 'admin_post_mla_aff_settings', array( __CLASS__, 'post_settings' ) );
		add_action( 'admin_post_mla_aff_export', array( __CLASS__, 'export' ) );
	}

	public static function menu() {
		$requests = count( self::users_by_status( 'pending' ) );
		$payouts  = count( MLA_Affiliate::withdrawals( 0, 'pending' ) );
		$badge    = function ( $n ) {
			return $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		};
		add_menu_page( 'Parrainage', 'Parrainage' . $badge( $requests + $payouts ), 'manage_woocommerce', 'mla-affiliates', array( __CLASS__, 'page_dashboard' ), 'dashicons-groups', 57 );
		add_submenu_page( 'mla-affiliates', 'Tableau de bord du parrainage', 'Tableau de bord', 'manage_woocommerce', 'mla-affiliates', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'mla-affiliates', 'Demandes d’adhésion', 'Demandes' . $badge( $requests ), 'manage_woocommerce', 'mla-aff-requests', array( __CLASS__, 'page_requests' ) );
		add_submenu_page( 'mla-affiliates', 'Parrains', 'Parrains', 'manage_woocommerce', 'mla-aff-members', array( __CLASS__, 'page_members' ) );
		add_submenu_page( 'mla-affiliates', 'Retraits', 'Retraits' . $badge( $payouts ), 'manage_woocommerce', 'mla-withdrawals', array( __CLASS__, 'page_withdrawals' ) );
		add_submenu_page( 'mla-affiliates', 'Réglages du parrainage', 'Réglages', 'manage_options', 'mla-aff-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $page, self::PAGES, true ) ) {
			return;
		}
		wp_enqueue_style( 'mla-admin-aff', MLA_URL . 'assets/admin-aff.css', array(), MLA_VERSION );
		wp_enqueue_script( 'mla-admin-aff', MLA_URL . 'assets/admin-aff.js', array( 'jquery' ), MLA_VERSION, true );
		if ( 'mla-aff-settings' === $page ) {
			wp_enqueue_media();
		}
	}

	/* ------------------------------------------------------------------
	 * Données
	 * ---------------------------------------------------------------- */

	private static function users_by_status( $status, $search = '' ) {
		$args = array( 'meta_key' => MLA_Affiliate::STATUS_META, 'meta_value' => $status, 'number' => 500 ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		return get_users( $args );
	}

	/**
	 * Chiffres globaux du programme.
	 */
	private static function overview() {
		global $wpdb;
		$l     = MLA_Affiliate::table( 'ledger' );
		$w     = MLA_Affiliate::table( 'withdrawals' );
		$month = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$o = array(
			'members'        => count( self::users_by_status( 'approved' ) ),
			'requests'       => count( self::users_by_status( 'pending' ) ),
			'circulation'    => (float) $wpdb->get_var( "SELECT COALESCE(SUM(berries),0) FROM $l" ),
			'month_earned'   => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(berries),0) FROM $l WHERE type IN ('commission','reversal') AND created_at >= %s", $month ) ),
			'month_sales'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM $l WHERE type = 'commission' AND created_at >= %s", $month ) ),
			'total_earned'   => (float) $wpdb->get_var( "SELECT COALESCE(SUM(berries),0) FROM $l WHERE type IN ('commission','reversal')" ),
			'pending_count'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $w WHERE status = 'pending'" ),
			'pending_amount' => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $w WHERE status = 'pending'" ),
			'paid_amount'    => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $w WHERE status = 'paid'" ),
			'coupons'        => (float) -$wpdb->get_var( "SELECT COALESCE(SUM(berries),0) FROM $l WHERE type = 'coupon'" ),
		);
		// phpcs:enable
		return $o;
	}

	/**
	 * Commissions des 30 derniers jours, jour par jour (en Berry).
	 */
	private static function daily( $user_id = 0 ) {
		global $wpdb;
		$l     = MLA_Affiliate::table( 'ledger' );
		$since = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 29 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$sql   = "SELECT DATE(created_at) AS d, SUM(berries) AS b FROM $l WHERE type IN ('commission','reversal') AND created_at >= %s" . ( $user_id ? ' AND user_id = %d' : '' ) . ' GROUP BY DATE(created_at)';
		$rows  = $wpdb->get_results( $user_id ? $wpdb->prepare( $sql, $since, $user_id ) : $wpdb->prepare( $sql, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$map   = wp_list_pluck( $rows, 'b', 'd' );
		$days  = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$d          = gmdate( 'Y-m-d', current_time( 'timestamp' ) - $i * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			$days[ $d ] = max( 0, (float) ( $map[ $d ] ?? 0 ) );
		}
		return $days;
	}

	private static function top( $limit = 5 ) {
		global $wpdb;
		$l = MLA_Affiliate::table( 'ledger' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, SUM(berries) AS earned, COUNT(DISTINCT CASE WHEN type = 'commission' THEN order_id END) AS sales FROM $l WHERE type IN ('commission','reversal') GROUP BY user_id ORDER BY earned DESC LIMIT %d", $limit + 5 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_slice( array_values( array_filter( $rows, function ( $r ) { return (float) $r['earned'] > 0; } ) ), 0, $limit );
	}

	private static function recent( $limit = 8 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MLA_Affiliate::table( 'ledger' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------
	 * Composants d'affichage
	 * ---------------------------------------------------------------- */

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function header( $title, $subtitle = '', $action = '' ) {
		echo '<div class="wrap mla-aa">';
		echo '<header class="mla-aa__head"><div><h1>' . esc_html( $title ) . '</h1>' . ( $subtitle ? '<p>' . esc_html( $subtitle ) . '</p>' : '' ) . '</div>' . $action . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::notice();
	}

	private static function notice() {
		$messages = array(
			'approved'  => 'Adhésion validée : le parrain a été prévenu par e-mail.',
			'rejected'  => 'Demande refusée : la personne a été prévenue par e-mail.',
			'suspended' => 'Parrain suspendu : ses liens ne rapportent plus de commission.',
			'paid'      => 'Retrait marqué comme payé : le parrain a été prévenu.',
			'refused'   => 'Retrait refusé : les Berry ont été rendus au parrain.',
			'adjusted'  => 'Solde ajusté.',
			'saved'     => 'Réglages enregistrés.',
		);
		$key = isset( $_GET['mla_done'] ) ? sanitize_key( $_GET['mla_done'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	private static function kpi( $label, $value, $hint = '', $tone = '', $link = '' ) {
		$tag = $link ? 'a href="' . esc_url( $link ) . '"' : 'div';
		echo '<' . $tag . ' class="mla-aa-kpi' . ( $tone ? ' is-' . esc_attr( $tone ) : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . wp_kses_post( $value ) . '</strong>' . ( $hint ? '<small>' . esc_html( $hint ) . '</small>' : '' ) . '</' . ( $link ? 'a' : 'div' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function berry( $berries ) {
		return esc_html( MLA_Affiliate::format_berry( $berries ) ) . ' <em>Berry</em>';
	}

	private static function avatar( $user, $size = 36 ) {
		return get_avatar( $user->ID, $size, 'mystery', '', array( 'class' => 'mla-aa-avatar' ) );
	}

	private static function status_badge( $status ) {
		$labels = array( 'approved' => 'Actif', 'pending' => 'En attente', 'rejected' => 'Refusé', 'suspended' => 'Suspendu', 'paid' => 'Payé' );
		return '<span class="mla-aa-badge is-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function action_form( $action, $fields, $label, $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mla-aa-inline"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function chart( $days ) {
		$max = max( 1, max( $days ) );
		echo '<div class="mla-aa-chart" role="img" aria-label="Commissions des 30 derniers jours">';
		foreach ( $days as $d => $b ) {
			$h = $b > 0 ? max( 4, round( 100 * $b / $max ) ) : 0;
			echo '<span style="--h:' . (int) $h . '%" title="' . esc_attr( wp_date( 'd/m', strtotime( $d ) ) . ' : ' . MLA_Affiliate::format_berry( $b ) . ' Berry' ) . '"></span>';
		}
		echo '</div><div class="mla-aa-chart__axis"><span>' . esc_html( wp_date( 'd M', strtotime( array_key_first( $days ) ) ) ) . '</span><span>Aujourd’hui</span></div>';
	}

	/* ------------------------------------------------------------------
	 * Pages
	 * ---------------------------------------------------------------- */

	public static function page_dashboard() {
		$o = self::overview();
		self::header( 'Parrainage', 'Vue d’ensemble du programme', '<a class="button" href="' . esc_url( MLA_Affiliate::page_url() ) . '" target="_blank">Voir la page publique</a>' );

		if ( $o['requests'] || $o['pending_count'] ) {
			echo '<div class="mla-aa-todo">';
			if ( $o['requests'] ) {
				echo '<a href="' . esc_url( self::url( 'mla-aff-requests' ) ) . '"><span class="dashicons dashicons-id"></span><strong>' . (int) $o['requests'] . ' demande' . ( $o['requests'] > 1 ? 's' : '' ) . ' d’adhésion</strong> à valider</a>';
			}
			if ( $o['pending_count'] ) {
				echo '<a href="' . esc_url( self::url( 'mla-withdrawals' ) ) . '" class="is-money"><span class="dashicons dashicons-money-alt"></span><strong>' . (int) $o['pending_count'] . ' retrait' . ( $o['pending_count'] > 1 ? 's' : '' ) . ' à payer</strong> · ' . esc_html( MLA_Affiliate::format_fcfa( $o['pending_amount'] ) ) . '</a>';
			}
			echo '</div>';
		}

		echo '<div class="mla-aa-kpis">';
		self::kpi( 'Parrains actifs', (string) $o['members'], $o['requests'] ? $o['requests'] . ' en attente' : 'Aucune demande en attente', '', self::url( 'mla-aff-members' ) );
		self::kpi( 'Commissions ce mois-ci', self::berry( $o['month_earned'] ), MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $o['month_earned'] ) ) . ' · ' . $o['month_sales'] . ' vente' . ( $o['month_sales'] > 1 ? 's' : '' ), 'blue' );
		self::kpi( 'Berry en circulation', self::berry( $o['circulation'] ), 'Soit ' . MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $o['circulation'] ) ) . ' dus aux parrains', 'gold' );
		self::kpi( 'Retraits à payer', esc_html( MLA_Affiliate::format_fcfa( $o['pending_amount'] ) ), $o['pending_count'] . ' demande' . ( $o['pending_count'] > 1 ? 's' : '' ), $o['pending_count'] ? 'warn' : '', self::url( 'mla-withdrawals' ) );
		echo '</div>';

		echo '<div class="mla-aa-grid">';
		echo '<section class="mla-aa-card mla-aa-card--wide"><h2>Commissions des 30 derniers jours</h2>';
		self::chart( self::daily() );
		echo '<ul class="mla-aa-mini"><li><span>Total gagné par les parrains</span><strong>' . self::berry( $o['total_earned'] ) . '</strong></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<li><span>Retraits déjà payés</span><strong>' . esc_html( MLA_Affiliate::format_fcfa( $o['paid_amount'] ) ) . '</strong></li>';
		echo '<li><span>Convertis en codes promo</span><strong>' . self::berry( $o['coupons'] ) . '</strong></li></ul></section>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<section class="mla-aa-card"><h2>Meilleurs parrains</h2>';
		$top = self::top();
		if ( ! $top ) {
			echo '<p class="mla-aa-empty">Les premiers résultats apparaîtront ici dès la première vente parrainée.</p>';
		} else {
			echo '<ol class="mla-aa-top">';
			foreach ( $top as $i => $row ) {
				$u = get_userdata( $row['user_id'] );
				if ( ! $u ) {
					continue;
				}
				echo '<li><span class="mla-aa-rank">' . ( $i + 1 ) . '</span>' . self::avatar( $u, 32 ) . '<a href="' . esc_url( self::url( 'mla-aff-members', array( 'user' => $u->ID ) ) ) . '">' . esc_html( $u->display_name ) . '</a><span class="mla-aa-top__val">' . self::berry( $row['earned'] ) . '<small>' . (int) $row['sales'] . ' vente' . ( $row['sales'] > 1 ? 's' : '' ) . '</small></span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ol>';
		}
		echo '</section>';

		echo '<section class="mla-aa-card"><h2>Activité récente</h2>';
		$recent = self::recent();
		if ( ! $recent ) {
			echo '<p class="mla-aa-empty">Aucune activité pour le moment.</p>';
		} else {
			echo '<ul class="mla-aa-feed">';
			foreach ( $recent as $row ) {
				$u   = get_userdata( $row['user_id'] );
				$pos = (float) $row['berries'] >= 0;
				echo '<li><span class="mla-aa-dot ' . ( $pos ? 'is-pos' : 'is-neg' ) . '"></span><div><strong>' . esc_html( $u ? $u->display_name : '#' . $row['user_id'] ) . '</strong> · ' . esc_html( MLA_Affiliate::LEDGER_LABELS[ $row['type'] ] ?? $row['type'] ) . '<small>' . esc_html( human_time_diff( strtotime( $row['created_at'] ), current_time( 'timestamp' ) ) ) . ' · ' . esc_html( $row['note'] ) . '</small></div><span class="mla-aa-amount ' . ( $pos ? 'is-pos' : 'is-neg' ) . '">' . ( $pos ? '+' : '' ) . esc_html( MLA_Affiliate::format_berry( $row['berries'] ) ) . '</span></li>'; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			}
			echo '</ul>';
		}
		echo '</section></div></div>';
	}

	public static function page_requests() {
		$pending = self::users_by_status( 'pending' );
		self::header( 'Demandes d’adhésion', count( $pending ) . ' demande' . ( count( $pending ) > 1 ? 's' : '' ) . ' en attente de décision' );
		if ( ! $pending ) {
			echo '<div class="mla-aa-card mla-aa-emptystate"><span class="dashicons dashicons-yes-alt"></span><h2>Tout est à jour</h2><p>Aucune demande en attente. Les nouvelles demandes vous sont aussi envoyées par e-mail.</p></div></div>';
			return;
		}
		echo '<div class="mla-aa-requests">';
		foreach ( $pending as $u ) {
			$applied = (int) get_user_meta( $u->ID, 'mla_aff_applied', true );
			$orders  = function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $u->ID ) : 0;
			echo '<article class="mla-aa-card mla-aa-request"><header>' . self::avatar( $u, 48 ) . '<div><strong>' . esc_html( $u->display_name ) . '</strong><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></div><time>' . esc_html( $applied ? 'il y a ' . human_time_diff( $applied ) : '' ) . '</time></header>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<dl><div><dt>Téléphone</dt><dd>' . esc_html( get_user_meta( $u->ID, 'mla_aff_phone', true ) ?: '—' ) . '</dd></div>';
			echo '<div><dt>Canal</dt><dd>' . esc_html( get_user_meta( $u->ID, 'mla_aff_channel', true ) ?: '—' ) . '</dd></div>';
			echo '<div><dt>Client</dt><dd>' . (int) $orders . ' commande' . ( $orders > 1 ? 's' : '' ) . ' · inscrit le ' . esc_html( wp_date( 'd/m/Y', strtotime( $u->user_registered ) ) ) . '</dd></div></dl>';
			$motivation = get_user_meta( $u->ID, 'mla_aff_motivation', true );
			if ( $motivation ) {
				echo '<blockquote>' . esc_html( $motivation ) . '</blockquote>';
			}
			echo '<footer>';
			self::action_form( 'mla_aff_status', array( 'user' => $u->ID, 'status' => 'approved', 'back' => 'mla-aff-requests' ), 'Valider l’adhésion', 'button button-primary' );
			self::action_form( 'mla_aff_status', array( 'user' => $u->ID, 'status' => 'rejected', 'back' => 'mla-aff-requests' ), 'Refuser', 'button', 'Refuser cette demande ? La personne sera prévenue par e-mail.' );
			echo '</footer></article>';
		}
		echo '</div></div>';
	}

	public static function page_members() {
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $user_id && get_userdata( $user_id ) ) {
			self::page_member( $user_id );
			return;
		}
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter  = isset( $_GET['statut'] ) ? sanitize_key( $_GET['statut'] ) : 'approved'; // phpcs:ignore WordPress.Security.NonceVerification
		$sort    = isset( $_GET['tri'] ) ? sanitize_key( $_GET['tri'] ) : 'earned'; // phpcs:ignore WordPress.Security.NonceVerification
		$filters = array( 'approved' => 'Actifs', 'suspended' => 'Suspendus', 'rejected' => 'Refusés' );
		$filter  = isset( $filters[ $filter ] ) ? $filter : 'approved';
		$users   = self::users_by_status( $filter, $search );
		$rows    = array();
		foreach ( $users as $u ) {
			$rows[] = array( 'user' => $u, 'stats' => MLA_Affiliate::stats( $u->ID ) );
		}
		$keys = array( 'earned' => 'earned', 'balance' => 'balance', 'sales' => 'sales', 'clicks' => 'clicks' );
		if ( isset( $keys[ $sort ] ) ) {
			usort( $rows, function ( $a, $b ) use ( $sort ) { return $b['stats'][ $sort ] <=> $a['stats'][ $sort ]; } );
		}

		self::header( 'Parrains', count( self::users_by_status( 'approved' ) ) . ' parrain(s) actif(s)' );
		echo '<div class="mla-aa-toolbar"><nav class="mla-aa-tabs">';
		foreach ( $filters as $key => $label ) {
			echo '<a class="' . ( $key === $filter ? 'is-active' : '' ) . '" href="' . esc_url( self::url( 'mla-aff-members', array( 'statut' => $key ) ) ) . '">' . esc_html( $label ) . ' <span>' . count( self::users_by_status( $key ) ) . '</span></a>';
		}
		echo '</nav><form method="get" class="mla-aa-search"><input type="hidden" name="page" value="mla-aff-members"><input type="hidden" name="statut" value="' . esc_attr( $filter ) . '">';
		echo '<select name="tri" onchange="this.form.submit()">';
		foreach ( array( 'earned' => 'Trier : gains', 'balance' => 'Trier : solde', 'sales' => 'Trier : ventes', 'clicks' => 'Trier : clics' ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $sort, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Nom ou e-mail…"><button class="button">Rechercher</button></form></div>';

		if ( ! $rows ) {
			echo '<div class="mla-aa-card mla-aa-emptystate"><span class="dashicons dashicons-groups"></span><h2>Aucun parrain ici</h2><p>' . ( $search ? 'Aucun résultat pour cette recherche.' : 'Les parrains validés apparaîtront dans cette liste.' ) . '</p></div></div>';
			return;
		}
		echo '<div class="mla-aa-card mla-aa-tablecard"><table class="mla-aa-table"><thead><tr><th>Parrain</th><th>Code</th><th class="num">Clics</th><th class="num">Ventes</th><th class="num">Conversion</th><th class="num">Gagné</th><th class="num">Solde</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$u = $row['user'];
			$s = $row['stats'];
			$detail = self::url( 'mla-aff-members', array( 'user' => $u->ID ) );
			echo '<tr><td><a class="mla-aa-who" href="' . esc_url( $detail ) . '">' . self::avatar( $u ) . '<span><strong>' . esc_html( $u->display_name ) . '</strong><small>' . esc_html( $u->user_email ) . '</small></span></a></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td><code>' . esc_html( MLA_Affiliate::code( $u->ID ) ) . '</code></td>';
			echo '<td class="num">' . (int) $s['clicks'] . '</td><td class="num">' . (int) $s['sales'] . '</td><td class="num">' . esc_html( $s['conversion'] ) . ' %</td>';
			echo '<td class="num">' . self::berry( $s['earned'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="num"><strong>' . self::berry( $s['balance'] ) . '</strong><small>' . esc_html( MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['balance'] ) ) ) . '</small></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td class="actions"><a class="button" href="' . esc_url( $detail ) . '">Ouvrir</a></td></tr>';
		}
		echo '</tbody></table></div></div>';
	}

	private static function page_member( $user_id ) {
		$u      = get_userdata( $user_id );
		$s      = MLA_Affiliate::stats( $user_id );
		$status = MLA_Affiliate::status( $user_id );
		$back   = '<a class="button" href="' . esc_url( self::url( 'mla-aff-members' ) ) . '">← Tous les parrains</a>';
		self::header( $u->display_name, $u->user_email, $back );

		echo '<div class="mla-aa-profile mla-aa-card">' . self::avatar( $u, 64 ) . '<div><div class="mla-aa-profile__name">' . esc_html( $u->display_name ) . ' ' . self::status_badge( $status ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Code <code>' . esc_html( MLA_Affiliate::code( $user_id ) ) . '</code> · Tél. ' . esc_html( get_user_meta( $user_id, 'mla_aff_phone', true ) ?: '—' ) . ' · Lien : <a href="' . esc_url( MLA_Affiliate::link( $user_id ) ) . '" target="_blank">' . esc_html( MLA_Affiliate::link( $user_id ) ) . '</a></p></div><div class="mla-aa-profile__actions">';
		if ( 'approved' === $status ) {
			self::action_form( 'mla_aff_status', array( 'user' => $user_id, 'status' => 'suspended', 'back' => 'member' ), 'Suspendre', 'button', 'Suspendre ce parrain ? Ses liens ne rapporteront plus de commission.' );
		} else {
			self::action_form( 'mla_aff_status', array( 'user' => $user_id, 'status' => 'approved', 'back' => 'member' ), 'Activer', 'button button-primary' );
		}
		echo '<a class="button" href="' . esc_url( get_edit_user_link( $user_id ) ) . '">Profil WordPress</a></div></div>';

		echo '<div class="mla-aa-kpis">';
		self::kpi( 'Solde', self::berry( $s['balance'] ), MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['balance'] ) ), 'gold' );
		self::kpi( 'Total gagné', self::berry( $s['earned'] ), MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['earned'] ) ), 'blue' );
		self::kpi( 'Ventes', (string) $s['sales'], $s['clicks'] . ' clics · ' . $s['conversion'] . ' % de conversion' );
		self::kpi( 'En cours de retrait', self::berry( $s['pending'] ), MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['pending'] ) ), $s['pending'] > 0 ? 'warn' : '' );
		echo '</div>';

		echo '<div class="mla-aa-grid">';
		echo '<section class="mla-aa-card mla-aa-card--wide"><h2>Commissions des 30 derniers jours</h2>';
		self::chart( self::daily( $user_id ) );
		echo '</section>';

		echo '<section class="mla-aa-card"><h2>Ajuster le solde</h2><p class="mla-aa-muted">Bonus, correction ou geste commercial. Montant négatif pour retirer.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mla-aa-form">';
		wp_nonce_field( 'mla_aff_adjust' );
		echo '<input type="hidden" name="action" value="mla_aff_adjust"><input type="hidden" name="user" value="' . (int) $user_id . '">';
		echo '<label>Berry<input type="number" step="0.01" name="berries" required placeholder="ex. 10 ou -5"></label><label>Motif<input type="text" name="note" placeholder="Bonus de lancement…"></label><button class="button button-primary">Appliquer</button></form></section>';
		echo '</div>';

		echo '<section class="mla-aa-card mla-aa-tablecard"><h2>Mouvements</h2>';
		$ledger = MLA_Affiliate::ledger( $user_id, 100 );
		if ( ! $ledger ) {
			echo '<p class="mla-aa-empty">Aucun mouvement.</p>';
		} else {
			echo '<table class="mla-aa-table"><thead><tr><th>Date</th><th>Opération</th><th>Détail</th><th>Commande</th><th class="num">Berry</th></tr></thead><tbody>';
			foreach ( $ledger as $row ) {
				$pos   = (float) $row['berries'] >= 0;
				$order = $row['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $row['order_id'] . '&action=edit' ) ) . '">#' . (int) $row['order_id'] . '</a>' : '—';
				echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $row['created_at'] ) ) ) . '</td><td>' . esc_html( MLA_Affiliate::LEDGER_LABELS[ $row['type'] ] ?? $row['type'] ) . '</td><td>' . esc_html( $row['note'] ) . '</td><td>' . $order . '</td><td class="num"><span class="mla-aa-amount ' . ( $pos ? 'is-pos' : 'is-neg' ) . '">' . ( $pos ? '+' : '' ) . esc_html( MLA_Affiliate::format_berry( $row['berries'] ) ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		echo '<section class="mla-aa-card mla-aa-tablecard"><h2>Retraits</h2>';
		self::withdrawal_table( MLA_Affiliate::withdrawals( $user_id ), false, false );
		echo '</section></div>';
	}

	public static function page_withdrawals() {
		$tab   = isset( $_GET['statut'] ) ? sanitize_key( $_GET['statut'] ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs  = array( 'pending' => 'À payer', 'paid' => 'Payés', 'rejected' => 'Refusés' );
		$tab   = isset( $tabs[ $tab ] ) ? $tab : 'pending';
		$rows  = MLA_Affiliate::withdrawals( 0, $tab, 300 );
		$total = array_sum( wp_list_pluck( $rows, 'amount' ) );
		$export = '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mla_aff_export&statut=' . $tab ), 'mla_aff_export' ) ) . '">Exporter en CSV</a>';
		self::header( 'Retraits', 'Envoyez le montant sur le compte Mobile Money indiqué, puis marquez la demande comme payée. Délai annoncé : 1 jour ouvré.', $export );

		echo '<div class="mla-aa-toolbar"><nav class="mla-aa-tabs">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="' . ( $key === $tab ? 'is-active' : '' ) . '" href="' . esc_url( self::url( 'mla-withdrawals', array( 'statut' => $key ) ) ) . '">' . esc_html( $label ) . ' <span>' . count( MLA_Affiliate::withdrawals( 0, $key, 1000 ) ) . '</span></a>';
		}
		echo '</nav><div class="mla-aa-total">Total : <strong>' . esc_html( MLA_Affiliate::format_fcfa( $total ) ) . '</strong></div></div>';

		if ( ! $rows ) {
			echo '<div class="mla-aa-card mla-aa-emptystate"><span class="dashicons dashicons-yes-alt"></span><h2>' . ( 'pending' === $tab ? 'Aucun retrait à payer' : 'Rien pour le moment' ) . '</h2><p>' . ( 'pending' === $tab ? 'Les nouvelles demandes vous sont aussi envoyées par e-mail.' : '' ) . '</p></div></div>';
			return;
		}
		echo '<div class="mla-aa-card mla-aa-tablecard">';
		self::withdrawal_table( $rows, 'pending' === $tab, true );
		echo '</div></div>';
	}

	private static function withdrawal_table( $rows, $actions, $show_user ) {
		if ( ! $rows ) {
			echo '<p class="mla-aa-empty">Aucun retrait.</p>';
			return;
		}
		echo '<table class="mla-aa-table"><thead><tr><th>Date</th>' . ( $show_user ? '<th>Parrain</th>' : '' ) . '<th class="num">Montant</th><th>Paiement</th><th>' . ( $actions ? 'Action' : 'État' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $w ) {
			$u = get_userdata( $w['user_id'] );
			echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', strtotime( $w['created_at'] ) ) ) . '</td>';
			if ( $show_user ) {
				echo '<td>' . ( $u ? '<a class="mla-aa-who" href="' . esc_url( self::url( 'mla-aff-members', array( 'user' => $u->ID ) ) ) . '">' . self::avatar( $u, 28 ) . '<span><strong>' . esc_html( $u->display_name ) . '</strong></span></a>' : '#' . (int) $w['user_id'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<td class="num"><strong class="mla-aa-big">' . esc_html( MLA_Affiliate::format_fcfa( $w['amount'] ) ) . '</strong><small>' . esc_html( MLA_Affiliate::format_berry( $w['berries'] ) ) . ' Berry</small></td>';
			echo '<td><span class="mla-aa-method is-' . esc_attr( $w['method'] ) . '">' . esc_html( MLA_Affiliate::METHODS[ $w['method'] ] ?? $w['method'] ) . '</span>';
			echo '<div class="mla-aa-phone"><code>' . esc_html( $w['phone'] ) . '</code><button type="button" class="button-link" data-mla-copy="' . esc_attr( preg_replace( '~\s+~', '', $w['phone'] ) ) . '">Copier</button></div><small>' . esc_html( $w['holder'] ) . '</small></td><td>';
			if ( $actions ) {
				self::action_form( 'mla_aff_withdrawal', array( 'id' => $w['id'], 'status' => 'paid' ), 'Marquer comme payé', 'button button-primary', 'Confirmer que ' . MLA_Affiliate::format_fcfa( $w['amount'] ) . ' ont bien été envoyés ?' );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mla-aa-inline mla-aa-refuse">';
				wp_nonce_field( 'mla_aff_withdrawal' );
				echo '<input type="hidden" name="action" value="mla_aff_withdrawal"><input type="hidden" name="id" value="' . (int) $w['id'] . '"><input type="hidden" name="status" value="rejected">';
				echo '<input type="text" name="note" placeholder="Motif du refus"><button class="button">Refuser</button></form>';
			} else {
				echo self::status_badge( $w['status'] ) . ( $w['processed_at'] ? '<small>' . esc_html( wp_date( 'd/m/Y', strtotime( $w['processed_at'] ) ) ) . '</small>' : '' ) . ( $w['admin_note'] ? '<small>' . esc_html( $w['admin_note'] ) . '</small>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function page_settings() {
		self::header( 'Réglages du parrainage', 'Ces valeurs s’appliquent aux prochaines commandes ; les gains déjà crédités ne changent pas.' );
		$image = mla_option( 'berry_image' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mla-aa-settings">';
		wp_nonce_field( 'mla_aff_settings' );
		echo '<input type="hidden" name="action" value="mla_aff_settings">';

		echo '<section class="mla-aa-card"><h2>Commissions</h2>';
		self::field( 'aff_rate', 'Commission', MLA_Affiliate::rate(), '%', 'Part du montant payé (après réductions) reversée au parrain.' );
		self::field( 'aff_cookie_days', 'Durée du suivi', MLA_Affiliate::cookie_days(), 'jours', 'Un achat fait dans ce délai après un clic est attribué au parrain.' );
		echo '</section>';

		echo '<section class="mla-aa-card"><h2>Berry</h2>';
		self::field( 'berry_value', 'Valeur d’un Berry', MLA_Affiliate::berry_value(), 'FCFA', '1 Berry vaut ce montant, pour les codes promo comme pour les retraits.' );
		self::field( 'berry_min', 'Seuil minimum', MLA_Affiliate::minimum(), 'Berry', 'Minimum pour convertir en code promo ou demander un retrait.' );
		echo '<div class="mla-aa-field"><label>Image de la pièce</label><div class="mla-aa-media"><span class="mla-aa-media__preview" data-mla-media-preview>' . MLA_Icons::berry( 56 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<input type="hidden" name="berry_image" value="' . esc_attr( $image ) . '" data-mla-media-input><button type="button" class="button" data-mla-media-pick>Choisir dans la Médiathèque</button>';
		echo '<button type="button" class="button-link" data-mla-media-clear ' . ( $image ? '' : 'hidden' ) . '>Revenir à la pièce par défaut</button></div><p class="description">Image carrée, fond transparent de préférence (PNG).</p></div>';
		echo '</section>';

		$example = 10000;
		echo '<section class="mla-aa-card mla-aa-sim" data-mla-sim><h2>Simulation</h2><p class="mla-aa-muted">Pour une vente de <input type="number" value="' . (int) $example . '" min="0" step="500" data-mla-sim-price> FCFA :</p>';
		echo '<ul class="mla-aa-mini"><li><span>Commission</span><strong data-mla-sim-fcfa></strong></li><li><span>Soit en Berry</span><strong data-mla-sim-berry></strong></li><li><span>Ventes nécessaires pour atteindre le seuil</span><strong data-mla-sim-sales></strong></li></ul></section>';

		echo '<p class="submit"><button class="button button-primary button-hero">Enregistrer les réglages</button></p></form>';
		echo '<p class="mla-aa-muted">Page publique du programme : <a href="' . esc_url( MLA_Affiliate::page_url() ) . '" target="_blank">' . esc_html( MLA_Affiliate::page_url() ) . '</a></p></div>';
	}

	private static function field( $key, $label, $value, $unit, $help ) {
		echo '<div class="mla-aa-field"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><div class="mla-aa-unit"><input type="number" step="0.01" min="0" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" data-mla-sim-' . esc_attr( str_replace( '_', '-', $key ) ) . '><span>' . esc_html( $unit ) . '</span></div><p class="description">' . esc_html( $help ) . '</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Actions
	 * ---------------------------------------------------------------- */

	private static function done( $page, $key, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'mla_done' => $key ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function post_status() {
		check_admin_referer( 'mla_aff_status' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$user   = absint( $_POST['user'] ?? 0 );
		$status = sanitize_key( $_POST['status'] ?? '' );
		if ( in_array( $status, array( 'approved', 'rejected', 'suspended' ), true ) ) {
			MLA_Affiliate::set_status( $user, $status );
		}
		$back = sanitize_key( $_POST['back'] ?? '' );
		if ( 'member' === $back ) {
			self::done( 'mla-aff-members', $status, array( 'user' => $user ) );
		}
		self::done( 'mla-aff-requests' === $back ? 'mla-aff-requests' : 'mla-aff-members', $status );
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
		$user    = absint( $_POST['user'] ?? 0 );
		$berries = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['berries'] ?? '0' ) ) );
		if ( 0.0 !== $berries ) {
			MLA_Affiliate::add_entry( $user, 'adjust', $berries, array( 'note' => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) ?: 'Ajustement manuel' ) );
		}
		self::done( 'mla-aff-members', 'adjusted', array( 'user' => $user ) );
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

	/**
	 * Export CSV des retraits (Excel : séparateur point-virgule, UTF-8 avec BOM).
	 */
	public static function export() {
		check_admin_referer( 'mla_aff_export' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Action non autorisée.' );
		}
		$status = sanitize_key( $_GET['statut'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = MLA_Affiliate::withdrawals( 0, in_array( $status, array( 'pending', 'paid', 'rejected' ), true ) ? $status : '', 5000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=retraits-' . ( $status ?: 'tous' ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Date', 'Parrain', 'E-mail', 'Montant (FCFA)', 'Berry', 'Moyen', 'Numéro', 'Titulaire', 'État', 'Traité le', 'Note' ), ';' );
		foreach ( $rows as $w ) {
			$u = get_userdata( $w['user_id'] );
			fputcsv( $out, array( $w['created_at'], $u ? $u->display_name : '', $u ? $u->user_email : '', $w['amount'], $w['berries'], MLA_Affiliate::METHODS[ $w['method'] ] ?? $w['method'], $w['phone'], $w['holder'], $w['status'], $w['processed_at'], $w['admin_note'] ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}

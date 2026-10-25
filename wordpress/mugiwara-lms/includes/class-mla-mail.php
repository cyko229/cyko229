<?php
/**
 * E-mails : envoi SMTP (boîte mail du domaine) et gabarit aux couleurs du site,
 * pour les e-mails WooCommerce comme pour ceux de WordPress (mot de passe, nouveau compte…).
 */

defined( 'ABSPATH' ) || exit;

class MLA_Mail {

	const OPTION     = 'mla_mail';
	const ERROR_META = 'mla_mail_last_error';

	const INK    = '#0E1024';
	const BLUE   = '#1E4DF0';
	const VIOLET = '#7B3CF5';
	const LIME   = '#C9F26B';
	const SOFT   = '#F5F6FA';
	const LINE   = '#E6E8F0';
	const MUTED  = '#4A4F63';
	const FONT   = "'Plus Jakarta Sans',Helvetica,Arial,sans-serif";

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'smtp' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'from_email' ), 99 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ), 99 );
		add_filter( 'woocommerce_email_from_address', array( __CLASS__, 'from_email' ), 99 );
		add_filter( 'wp_mail', array( __CLASS__, 'brand_plain_mail' ), 20 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'log_failure' ) );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'log_success' ) );

		add_filter( 'wc_get_template', array( __CLASS__, 'woocommerce_template' ), 10, 2 );
		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'woocommerce_styles' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Réglages SMTP
	 * ---------------------------------------------------------------- */

	public static function settings() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'host'       => '',
				'port'       => 465,
				'secure'     => 'ssl',
				'user'       => '',
				'pass'       => '',
				'from_email' => '',
				'from_name'  => get_bloginfo( 'name' ),
			)
		);
	}

	public static function save_settings( $input ) {
		$current = self::settings();
		$new     = array(
			'host'       => sanitize_text_field( $input['host'] ?? $current['host'] ),
			'port'       => (int) ( $input['port'] ?? $current['port'] ),
			'secure'     => in_array( $input['secure'] ?? $current['secure'], array( 'ssl', 'tls', '' ), true ) ? ( $input['secure'] ?? $current['secure'] ) : 'ssl',
			'user'       => sanitize_text_field( $input['user'] ?? $current['user'] ),
			'pass'       => $current['pass'],
			'from_email' => sanitize_email( $input['from_email'] ?? $current['from_email'] ),
			'from_name'  => sanitize_text_field( $input['from_name'] ?? $current['from_name'] ),
		);
		// Mot de passe : remplacé seulement s'il est fourni ; stocké chiffré avec les clés du site.
		if ( isset( $input['pass'] ) && '' !== $input['pass'] ) {
			$new['pass'] = self::encrypt( (string) $input['pass'] );
		}
		if ( ! $new['from_email'] && is_email( $new['user'] ) ) {
			$new['from_email'] = $new['user'];
		}
		update_option( self::OPTION, $new, false );

		// WooCommerce envoie avec ses propres réglages d'expéditeur : on les aligne.
		if ( $new['from_email'] ) {
			update_option( 'woocommerce_email_from_address', $new['from_email'] );
		}
		if ( $new['from_name'] ) {
			update_option( 'woocommerce_email_from_name', $new['from_name'] );
		}
		return self::public_settings();
	}

	/**
	 * Réglages sans le mot de passe, pour l'affichage et l'API.
	 */
	public static function public_settings() {
		$s = self::settings();
		$s['pass_saved'] = '' !== $s['pass'];
		unset( $s['pass'] );
		$s['configured'] = self::configured();
		$s['last_error'] = get_option( self::ERROR_META, '' );
		return $s;
	}

	public static function configured() {
		$s = self::settings();
		return $s['host'] && $s['user'] && $s['pass'];
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'mla-mail', true );
	}

	private static function encrypt( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $value );
		}
		$iv = random_bytes( 16 );
		return 'enc:' . base64_encode( $iv . openssl_encrypt( $value, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) );
	}

	private static function decrypt( $value ) {
		if ( 0 === strpos( $value, 'b64:' ) ) {
			return base64_decode( substr( $value, 4 ) );
		}
		if ( 0 === strpos( $value, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $value, 4 ) );
			return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}
		return $value;
	}

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer
	 */
	public static function smtp( $mailer ) {
		if ( ! self::configured() ) {
			return;
		}
		$s = self::settings();
		$mailer->isSMTP();
		$mailer->Host       = $s['host'];
		$mailer->Port       = (int) $s['port'];
		$mailer->SMTPSecure = $s['secure'];
		$mailer->SMTPAuth   = true;
		$mailer->Username   = $s['user'];
		$mailer->Password   = self::decrypt( $s['pass'] );
		$mailer->Timeout    = 20;
		$mailer->CharSet    = 'UTF-8';
		// L'enveloppe doit correspondre au compte authentifié, sinon l'hébergeur refuse ou le message part en spam.
		$mailer->Sender = $s['from_email'] ?: $s['user'];
	}

	public static function from_email( $email ) {
		$s = self::settings();
		return $s['from_email'] ?: $email;
	}

	public static function from_name( $name ) {
		$s = self::settings();
		return $s['from_name'] ?: $name;
	}

	public static function log_failure( $error ) {
		update_option( self::ERROR_META, current_time( 'mysql' ) . ' — ' . $error->get_error_message(), false );
	}

	public static function log_success() {
		update_option( self::ERROR_META, '', false );
	}

	/**
	 * Envoie un e-mail de test et renvoie le résultat.
	 */
	public static function send_test( $to ) {
		delete_option( self::ERROR_META );
		$content = '<p>Bonne nouvelle : les e-mails de <strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong> partent bien par le serveur SMTP de votre boîte <strong>' . esc_html( self::from_email( '' ) ) . '</strong>.</p>'
			. '<p>Commandes, création de compte, mot de passe oublié, accès aux formations : vos clients recevront désormais tous leurs e-mails avec ce design.</p>'
			. self::button( home_url( '/' ), 'Visiter le site' );
		$ok = wp_mail( $to, 'Test d’envoi des e-mails', self::layout( 'Les e-mails fonctionnent ✅', $content ), array( 'Content-Type: text/html; charset=UTF-8' ) );
		return array(
			'sent'  => (bool) $ok,
			'to'    => $to,
			'error' => get_option( self::ERROR_META, '' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Gabarit
	 * ---------------------------------------------------------------- */

	public static function logo_url() {
		$url = mla_option( 'mail_logo' );
		if ( ! $url ) {
			$logo = get_theme_mod( 'custom_logo' );
			$url  = $logo ? wp_get_attachment_image_url( $logo, 'medium' ) : '';
		}
		return apply_filters( 'mla_mail_logo_url', $url );
	}

	public static function button( $url, $label ) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0"><tr><td style="border-radius:99px;background:' . self::BLUE . '">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 28px;border-radius:99px;background:' . self::BLUE . ';color:#ffffff;font-family:' . self::FONT . ';font-size:15px;font-weight:700;text-decoration:none">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * Haut du gabarit, jusqu'à l'ouverture de la zone de contenu.
	 */
	public static function header_html( $heading = '' ) {
		$name = get_bloginfo( 'name' );
		$logo = self::logo_url();
		ob_start();
		?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?php echo esc_html( $name ); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( self::SOFT ); ?>;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( self::SOFT ); ?>;">
<tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
	<tr><td style="padding:0 6px 20px;">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="text-decoration:none;color:<?php echo esc_attr( self::INK ); ?>;font-family:<?php echo esc_attr( self::FONT ); ?>;font-size:22px;font-weight:800;">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" width="170" alt="<?php echo esc_attr( $name ); ?>" style="display:block;width:170px;max-width:60%;height:auto;border:0;">
			<?php else : ?>
				<?php echo esc_html( $name ); ?>
			<?php endif; ?>
		</a>
	</td></tr>
	<tr><td style="background:#ffffff;border:1px solid <?php echo esc_attr( self::LINE ); ?>;border-radius:20px;overflow:hidden;">
		<div style="height:6px;line-height:6px;font-size:0;background:<?php echo esc_attr( self::BLUE ); ?>;background-image:linear-gradient(90deg,<?php echo esc_attr( self::BLUE ); ?>,<?php echo esc_attr( self::VIOLET ); ?>);">&nbsp;</div>
		<?php if ( $heading ) : ?>
			<div style="padding:34px 40px 0;">
				<h1 style="margin:0;font-family:<?php echo esc_attr( self::FONT ); ?>;font-size:26px;line-height:1.2;font-weight:800;letter-spacing:-.02em;color:<?php echo esc_attr( self::INK ); ?>;"><?php echo esc_html( $heading ); ?></h1>
			</div>
		<?php endif; ?>
		<div class="mla-mail-content" style="padding:22px 40px 38px;font-family:<?php echo esc_attr( self::FONT ); ?>;font-size:16px;line-height:1.65;color:<?php echo esc_attr( self::INK ); ?>;">
		<?php
		return ob_get_clean();
	}

	/**
	 * Bas du gabarit : fermeture du contenu et pied de page sombre, comme sur le site.
	 */
	public static function footer_html( $extra = '' ) {
		$name    = get_bloginfo( 'name' );
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		$links   = array(
			'Mes formations' => class_exists( 'MLA_Frontend' ) && function_exists( 'wc_get_account_endpoint_url' ) ? MLA_Frontend::list_url() : $account,
			'Mon compte'     => $account,
			'Contact'        => home_url( '/contact/' ),
		);
		$link_html = array();
		foreach ( $links as $label => $url ) {
			$link_html[] = '<a href="' . esc_url( $url ) . '" style="color:#ffffff;text-decoration:none;font-weight:600;">' . esc_html( $label ) . '</a>';
		}
		ob_start();
		?>
		</div>
	</td></tr>
	<tr><td style="padding-top:16px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( self::INK ); ?>;border-radius:20px;">
			<tr><td style="padding:28px 40px;font-family:<?php echo esc_attr( self::FONT ); ?>;color:#B9BCCB;font-size:13px;line-height:1.6;">
				<p style="margin:0 0 14px;font-size:20px;font-weight:800;letter-spacing:-.02em;color:#ffffff;">Apprendre. Créer. <span style="color:<?php echo esc_attr( self::LIME ); ?>;">Entreprendre.</span></p>
				<p style="margin:0 0 14px;"><?php echo implode( ' &nbsp;·&nbsp; ', $link_html ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<?php if ( $extra ) : ?>
					<div style="margin:0 0 10px;color:#B9BCCB;"><?php echo wp_kses_post( $extra ); ?></div>
				<?php endif; ?>
				<p style="margin:0;color:#7E8296;">© <?php echo esc_html( gmdate( 'Y' ) . ' ' . $name ); ?> · <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="color:#7E8296;"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></a></p>
			</td></tr>
		</table>
	</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	public static function layout( $heading, $content_html, $footer_extra = '' ) {
		return self::header_html( $heading ) . $content_html . self::footer_html( $footer_extra );
	}

	/* ------------------------------------------------------------------
	 * E-mails WordPress (texte brut) → gabarit HTML
	 * ---------------------------------------------------------------- */

	public static function brand_plain_mail( $args ) {
		$headers = is_array( $args['headers'] ) ? implode( "\n", $args['headers'] ) : (string) $args['headers'];
		$message = (string) $args['message'];
		$is_html = stripos( $headers, 'text/html' ) !== false
			|| 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' )
			|| preg_match( '~<(html|body|table|div|p|br)\b~i', $message );
		if ( $is_html || '' === trim( $message ) ) {
			return $args;
		}

		$subject = preg_replace( '~^\[[^\]]+\]\s*~', '', (string) $args['subject'] );
		$args['message'] = self::layout( $subject, self::text_to_html( $message ) );

		$headers   = is_array( $args['headers'] ) ? $args['headers'] : array_filter( explode( "\n", str_replace( "\r", '', (string) $args['headers'] ) ) );
		$headers   = array_filter(
			$headers,
			function ( $h ) {
				return stripos( $h, 'content-type' ) !== 0;
			}
		);
		$headers[]       = 'Content-Type: text/html; charset=UTF-8';
		$args['headers'] = $headers;
		return $args;
	}

	/**
	 * Texte brut de WordPress → paragraphes HTML. Le lien de réinitialisation du mot de passe devient un bouton.
	 */
	public static function text_to_html( $text ) {
		$text   = str_replace( "\r", '', $text );
		$blocks = preg_split( "~\n\s*\n~", trim( $text ) );
		$html   = '';
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			// WordPress encadre les liens par < >.
			$block = preg_replace( '~<(https?://[^>\s]+)>~', '$1', $block );
			if ( preg_match( '~^(https?://\S+)$~', $block, $m ) ) {
				$url   = $m[1];
				$label = preg_match( '~action=(rp|resetpass)|key=~', $url ) ? 'Choisir mon mot de passe' : ( false !== strpos( $url, 'wp-login.php' ) ? 'Se connecter' : 'Ouvrir le lien' );
				$html .= self::button( $url, $label ) . '<p style="margin:0 0 16px;font-size:12px;color:' . self::MUTED . ';word-break:break-all;">Si le bouton ne fonctionne pas, copiez ce lien : ' . esc_html( $url ) . '</p>';
				continue;
			}
			$escaped = esc_html( $block );
			$escaped = preg_replace( '~(https?://[^\s<]+)~', '<a href="$1" style="color:' . self::BLUE . ';">$1</a>', $escaped );
			$html   .= '<p style="margin:0 0 16px;">' . nl2br( $escaped ) . '</p>';
		}
		return $html;
	}

	/* ------------------------------------------------------------------
	 * E-mails WooCommerce
	 * ---------------------------------------------------------------- */

	public static function woocommerce_template( $template, $name ) {
		$ours = array(
			'emails/email-header.php' => MLA_DIR . 'templates/emails/email-header.php',
			'emails/email-footer.php' => MLA_DIR . 'templates/emails/email-footer.php',
		);
		// Un thème qui fournit ses propres gabarits garde la main.
		if ( isset( $ours[ $name ] ) && false === strpos( $template, get_stylesheet_directory() ) && apply_filters( 'mla_brand_woocommerce_emails', true ) ) {
			return $ours[ $name ];
		}
		return $template;
	}

	public static function woocommerce_styles( $css ) {
		$f = self::FONT;
		return $css . "
.mla-mail-content, .mla-mail-content p, .mla-mail-content td, .mla-mail-content th, .mla-mail-content li { font-family: {$f}; color: " . self::INK . "; }
.mla-mail-content p { margin: 0 0 16px; }
.mla-mail-content a { color: " . self::BLUE . "; font-weight: 600; }
.mla-mail-content h2 { font-family: {$f}; color: " . self::INK . "; font-size: 18px; font-weight: 800; margin: 26px 0 12px; line-height: 1.3; }
.mla-mail-content h3 { font-family: {$f}; color: " . self::INK . "; font-size: 16px; font-weight: 700; }
.mla-mail-content .td, .mla-mail-content table.td { border: 1px solid " . self::LINE . " !important; color: " . self::INK . "; }
.mla-mail-content table.td { border-radius: 12px; border-collapse: separate; overflow: hidden; }
.mla-mail-content th.td, .mla-mail-content td.td { padding: 12px 14px; border-width: 0 0 1px 0 !important; }
.mla-mail-content thead th, .mla-mail-content tfoot th { background: " . self::SOFT . "; }
.mla-mail-content address { border: 1px solid " . self::LINE . " !important; border-radius: 12px; padding: 14px 16px; color: " . self::MUTED . "; font-style: normal; }
.mla-mail-content .button, .mla-mail-content a.button { background: " . self::BLUE . "; color: #ffffff !important; border-radius: 99px; padding: 12px 22px; text-decoration: none; display: inline-block; }
";
	}
}

<?php
/**
 * Pied des e-mails WooCommerce aux couleurs de Mugiwara Academy (remplace emails/email-footer.php).
 */

defined( 'ABSPATH' ) || exit;

$mla_footer = apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
// Le texte par défaut ne fait que répéter le nom du site, déjà présent dans le pied de page.
$mla_footer = trim( preg_replace( '~^\s*' . preg_quote( get_bloginfo( 'name' ), '~' ) . '\s*(<br\s*/?>)?\s*~i', '', (string) $mla_footer ) );

echo MLA_Mail::footer_html( $mla_footer ? wpautop( wptexturize( $mla_footer ) ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- gabarit déjà échappé.

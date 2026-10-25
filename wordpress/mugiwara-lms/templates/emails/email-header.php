<?php
/**
 * En-tête des e-mails WooCommerce aux couleurs de Mugiwara Academy (remplace emails/email-header.php).
 *
 * @var string $email_heading
 */

defined( 'ABSPATH' ) || exit;

echo MLA_Mail::header_html( isset( $email_heading ) ? $email_heading : '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- gabarit déjà échappé.

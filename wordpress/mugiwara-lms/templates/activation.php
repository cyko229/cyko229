<?php
/**
 * Page « Activer mon compte » : choix du mot de passe puis confirmation par code e-mail.
 * Copiez ce fichier dans votre thème (mugiwara-lms/activation.php) pour le personnaliser.
 *
 * @var array $v État : step (password|code), email, locked, error, notice, order, redirect, forgot.
 */

defined( 'ABSPATH' ) || exit;

$courses = MLA_Activation::order_courses( $v['order'] );
$hidden  = function ( $with_email = true ) use ( $v ) {
	wp_nonce_field( 'mla_activation', '_mla_nonce', false );
	if ( $v['order'] ) {
		echo '<input type="hidden" name="commande" value="' . (int) $v['order']->get_id() . '">';
		echo '<input type="hidden" name="cle" value="' . esc_attr( $v['order']->get_order_key() ) . '">';
	}
	if ( $v['redirect'] ) {
		echo '<input type="hidden" name="redirect" value="' . esc_attr( $v['redirect'] ) . '">';
	}
	if ( $with_email ) {
		echo '<input type="hidden" name="email" value="' . esc_attr( $v['email'] ) . '">';
	}
};
$is_code  = 'code' === $v['step'];
$can_edit = $v['order'] && ! is_user_logged_in() || $v['order'] && (int) $v['order']->get_customer_id() === get_current_user_id();
$readonly = $v['locked'] && ! ( $can_edit && $v['editing'] );
$edit_url = $v['order'] ? MLA_Activation::url( array( 'commande' => $v['order']->get_id(), 'cle' => $v['order']->get_order_key(), 'modifier' => 1, 'redirect' => $v['redirect'] ? rawurlencode( $v['redirect'] ) : '' ) ) : '';
?>
<div class="mla mla-activation">
	<div class="mla-activation__card">
		<ol class="mla-steps" aria-label="Étapes">
			<li class="<?php echo $is_code ? 'is-done' : 'is-current'; ?>"><span>1</span> Mot de passe</li>
			<li class="<?php echo $is_code ? 'is-current' : ''; ?>"><span>2</span> Code e-mail</li>
			<li><span>3</span> Accès</li>
		</ol>

		<?php if ( $is_code ) : ?>
			<h1 class="mla-activation__title">Vérifiez vos e-mails</h1>
			<p class="mla-activation__lead">Saisissez le code à 6 chiffres reçu à <strong><?php echo esc_html( $v['email'] ); ?></strong>.</p>
		<?php elseif ( $v['order'] ) : ?>
			<h1 class="mla-activation__title">Activez votre compte</h1>
			<p class="mla-activation__lead">Merci pour votre achat ! Choisissez votre mot de passe : il vous servira à vous reconnecter pour suivre vos formations.</p>
		<?php elseif ( $v['forgot'] ) : ?>
			<h1 class="mla-activation__title">Nouveau mot de passe</h1>
			<p class="mla-activation__lead">Choisissez un nouveau mot de passe, puis confirmez-le avec le code que nous vous envoyons par e-mail.</p>
		<?php else : ?>
			<h1 class="mla-activation__title">Accéder à mes formations</h1>
			<p class="mla-activation__lead">Première connexion après un achat ? Indiquez l’e-mail utilisé pour la commande et choisissez votre mot de passe.</p>
		<?php endif; ?>

		<?php if ( $courses && ! $is_code ) : ?>
			<div class="mla-activation__courses">
				<span class="mla-eyebrow">Vos formations</span>
				<?php foreach ( $courses as $title ) : ?>
					<div>🎓 <?php echo esc_html( $title ); ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( $v['error'] ) : ?>
			<div class="mla-alert mla-alert--error" role="alert"><?php echo esc_html( $v['error'] ); ?></div>
		<?php elseif ( $v['notice'] ) : ?>
			<div class="mla-alert" role="status"><?php echo esc_html( $v['notice'] ); ?></div>
		<?php endif; ?>

		<?php if ( ! $is_code ) : ?>
			<form method="post" class="mla-form" autocomplete="on">
				<?php $hidden( false ); ?>
				<input type="hidden" name="mla_act" value="start">
				<label class="mla-field">
					<span>Adresse e-mail</span>
					<span class="mla-email-wrap">
						<input type="email" name="email" value="<?php echo esc_attr( $v['email'] ); ?>" autocomplete="email" required <?php echo $readonly ? 'readonly' : ''; ?> data-mla-email>
						<?php if ( $readonly && $can_edit ) : ?>
							<a class="mla-email-edit" href="<?php echo esc_url( $edit_url ); ?>" data-mla-email-edit>Modifier</a>
						<?php endif; ?>
					</span>
					<small class="mla-email-hint" data-mla-email-hint <?php echo $readonly ? 'hidden' : ''; ?>><?php echo $v['order'] ? 'Erreur dans l’adresse saisie à la commande ? Corrigez-la : le code sera envoyé à la nouvelle adresse, qui remplacera l’ancienne.' : ''; ?></small>
				</label>
				<label class="mla-field">
					<span>Mot de passe</span>
					<span class="mla-pass-wrap">
					<input type="password" name="password" minlength="8" autocomplete="new-password" required placeholder="8 caractères minimum" data-mla-pass>
					<button type="button" class="mla-eye" data-mla-eye aria-label="Afficher le mot de passe" aria-pressed="false">
						<svg class="mla-eye__show" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
						<svg class="mla-eye__hide" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.5 4.5-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					</span>
				</label>
				<label class="mla-field">
					<span>Confirmer le mot de passe</span>
					<span class="mla-pass-wrap">
					<input type="password" name="password2" minlength="8" autocomplete="new-password" required data-mla-pass2>
					<button type="button" class="mla-eye" data-mla-eye aria-label="Afficher le mot de passe" aria-pressed="false">
						<svg class="mla-eye__show" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
						<svg class="mla-eye__hide" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7c1.7 0 3.2-.5 4.5-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					</span>
				</label>
				<button type="submit" class="mla-btn mla-btn--block">Recevoir mon code par e-mail</button>
			</form>
			<p class="mla-activation__alt">Vous avez déjà un mot de passe ? <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">Se connecter</a></p>
		<?php else : ?>
			<form method="post" class="mla-form">
				<?php $hidden(); ?>
				<input type="hidden" name="mla_act" value="verify">
				<label class="mla-field">
					<span>Code de confirmation</span>
					<input type="text" name="code" class="mla-code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus placeholder="••••••">
				</label>
				<button type="submit" class="mla-btn mla-btn--block">Valider et accéder à mes formations</button>
			</form>
			<form method="post" class="mla-activation__resend">
				<?php $hidden(); ?>
				<input type="hidden" name="mla_act" value="resend">
				Rien reçu ? <button type="submit" class="mla-link">Renvoyer le code</button>
				· <a href="<?php echo esc_url( MLA_Activation::url( array_filter( array( 'commande' => $v['order'] ? $v['order']->get_id() : 0, 'cle' => $v['order'] ? $v['order']->get_order_key() : '' ) ) ) ); ?>">Modifier le mot de passe</a>
				<?php if ( $edit_url && $can_edit ) : ?>
					<br><a href="<?php echo esc_url( $edit_url ); ?>">Ce n’est pas la bonne adresse ? Changer d’e-mail</a>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</div>
</div>
<script>
(function () {
	// « Modifier » rend l'e-mail modifiable sans recharger la page.
	var edit = document.querySelector('[data-mla-email-edit]');
	if (edit) {
		edit.addEventListener('click', function (e) {
			e.preventDefault();
			var input = document.querySelector('[data-mla-email]');
			input.readOnly = false;
			input.focus();
			input.select();
			edit.hidden = true;
			var hint = document.querySelector('[data-mla-email-hint]');
			if (hint) { hint.hidden = false; }
		});
	}

	// L'œil affiche ou masque le mot de passe de son champ.
	document.querySelectorAll('[data-mla-eye]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var input = btn.parentNode.querySelector('input');
			var visible = input.type === 'password';
			input.type = visible ? 'text' : 'password';
			btn.classList.toggle('is-visible', visible);
			btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
			btn.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
			input.focus();
		});
	});
	var p2 = document.querySelector('[data-mla-pass2]');
	if (p2) {
		p2.addEventListener('input', function () {
			p2.setCustomValidity(p2.value && p2.value !== document.querySelector('[data-mla-pass]').value ? 'Les mots de passe ne sont pas identiques.' : '');
		});
	}
})();
</script>

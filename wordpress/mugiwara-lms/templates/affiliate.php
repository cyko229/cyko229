<?php
/**
 * Page « Parrainage » : présentation, adhésion, puis tableau de bord du parrain.
 * Copiez ce fichier dans votre thème (mugiwara-lms/affiliate.php) pour le personnaliser.
 *
 * @var array $v État préparé par MLA_Affiliate::shortcode().
 */

defined( 'ABSPATH' ) || exit;

$rate    = MLA_Affiliate::format_berry( MLA_Affiliate::rate() );
$value   = MLA_Affiliate::berry_value();
$minimum = MLA_Affiliate::minimum();
$icon    = function ( $name, $size = 18 ) {
	echo MLA_Icons::get( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput
};
$berry   = function ( $size = 20 ) {
	echo MLA_Icons::berry( $size ); // phpcs:ignore WordPress.Security.EscapeOutput
};
$nonce   = function ( $action ) {
	wp_nonce_field( 'mla_aff_' . $action, '_mla_aff_nonce', false );
	echo '<input type="hidden" name="mla_aff_action" value="' . esc_attr( $action ) . '">';
};
$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
?>
<div class="mla mla-aff" data-mla-berry-value="<?php echo esc_attr( $value ); ?>">
	<?php if ( ! empty( $v['flash'] ) ) : ?>
		<div class="mla-alert<?php echo 'error' === $v['flash'][0] ? ' mla-alert--error' : ( 'success' === $v['flash'][0] ? ' mla-alert--success' : '' ); ?>" role="status"><?php echo esc_html( $v['flash'][1] ); ?></div>
	<?php endif; ?>

	<?php if ( 'approved' !== $v['status'] ) : ?>
		<section class="mla-aff-hero">
			<div class="mla-aff-hero__text">
				<span class="mla-eyebrow">Programme de parrainage</span>
				<h1 class="mla-aff-hero__title">Recommandez Mugiwara Academy, gagnez des <span class="mla-aff-hl">Berry</span></h1>
				<p>Partagez vos liens. À chaque achat, vous gagnez <strong><?php echo esc_html( $rate ); ?> %</strong> du montant en Berry, à transformer en code promo ou à retirer sur Mobile Money.</p>
			</div>
			<div class="mla-aff-hero__coin" aria-hidden="true"><?php $berry( 120 ); ?></div>
		</section>

		<ul class="mla-aff-facts">
			<li><?php $icon( 'trending-up', 22 ); ?><strong><?php echo esc_html( $rate ); ?> %</strong><span>de chaque achat</span></li>
			<li><?php $berry( 24 ); ?><strong>1 Berry = <?php echo esc_html( MLA_Affiliate::format_fcfa( $value ) ); ?></strong><span>valeur fixe</span></li>
			<li><?php $icon( 'wallet', 22 ); ?><strong>Dès <?php echo esc_html( MLA_Affiliate::format_berry( $minimum ) ); ?> Berry</strong><span>retrait ou code promo</span></li>
			<li><?php $icon( 'phone', 22 ); ?><strong>Mobile Money</strong><span>MTN, Moov, Celtiis, Orange</span></li>
		</ul>

		<ol class="mla-aff-steps">
			<li><span>1</span><div><strong>Rejoignez le programme</strong><p>Créez votre compte et envoyez votre demande, validée par notre équipe.</p></div></li>
			<li><span>2</span><div><strong>Partagez vos liens</strong><p>Un lien pour tout le site ou un lien par formation, à publier où vous voulez.</p></div></li>
			<li><span>3</span><div><strong>Encaissez vos Berry</strong><p>Code promo instantané, ou retrait reçu sous 1 jour ouvré.</p></div></li>
		</ol>

		<div class="mla-aff-card">
			<?php if ( ! $v['user_id'] ) : ?>
				<h2 class="mla-aff-card__title">Commencer</h2>
				<p class="mla-muted">Connectez-vous ou créez votre compte gratuit pour envoyer votre demande d’adhésion.</p>
				<div class="mla-aff-actions">
					<a class="mla-btn" href="<?php echo esc_url( add_query_arg( 'redirect_to', rawurlencode( MLA_Affiliate::page_url() ), $account ) ); ?>"><?php $icon( 'user', 16 ); ?><span>Créer un compte ou se connecter</span></a>
				</div>
			<?php elseif ( 'suspended' === $v['status'] ) : ?>
				<div class="mla-aff-state">
					<span class="mla-aff-state__icon"><?php $icon( 'shield', 28 ); ?></span>
					<div>
						<h2 class="mla-aff-card__title">Compte parrain suspendu</h2>
						<p class="mla-muted">Votre compte parrain est temporairement suspendu. Contactez-nous pour en savoir plus.</p>
					</div>
				</div>
			<?php elseif ( 'pending' === $v['status'] ) : ?>
				<div class="mla-aff-state">
					<span class="mla-aff-state__icon"><?php $icon( 'hourglass', 28 ); ?></span>
					<div>
						<h2 class="mla-aff-card__title">Demande en cours d’étude</h2>
						<p class="mla-muted">Merci ! Notre équipe valide votre adhésion rapidement. Vous recevrez un e-mail dès qu’elle est acceptée.</p>
					</div>
				</div>
			<?php else : ?>
				<?php if ( 'rejected' === $v['status'] ) : ?>
					<div class="mla-alert mla-alert--error">Votre précédente demande n’a pas été retenue. Vous pouvez en envoyer une nouvelle.</div>
				<?php endif; ?>
				<h2 class="mla-aff-card__title">Demande d’adhésion</h2>
				<?php $current = wp_get_current_user(); ?>
				<form method="post" class="mla-form">
					<?php $nonce( 'apply' ); ?>
					<label class="mla-field"><span>Nom</span><input type="text" value="<?php echo esc_attr( trim( $current->first_name . ' ' . $current->last_name ) ?: $current->display_name ); ?>" readonly></label>
					<label class="mla-field"><span>Téléphone (WhatsApp de préférence)</span><input type="tel" name="phone" value="<?php echo esc_attr( get_user_meta( $v['user_id'], 'billing_phone', true ) ); ?>" required placeholder="+229 01 00 00 00 00"></label>
					<label class="mla-field"><span>Où allez-vous partager vos liens ?</span>
						<select name="channel">
							<option>Réseaux sociaux (Facebook, Instagram, TikTok)</option>
							<option>WhatsApp (statuts, groupes)</option>
							<option>YouTube</option>
							<option>Site web ou blog</option>
							<option>Bouche-à-oreille</option>
							<option>Autre</option>
						</select>
					</label>
					<label class="mla-field"><span>Quelques mots sur vous (facultatif)</span><textarea name="motivation" rows="3" maxlength="600" placeholder="Votre audience, votre projet…"></textarea></label>
					<label class="mla-check"><input type="checkbox" name="terms" value="1" required> J’accepte les règles du programme : pas de spam, pas d’achat via mon propre lien, commissions annulées en cas de remboursement.</label>
					<button type="submit" class="mla-btn mla-btn--block"><?php $icon( 'users', 16 ); ?><span>Envoyer ma demande</span></button>
				</form>
			<?php endif; ?>
		</div>

	<?php else : ?>
		<?php
		$s          = $v['stats'];
		$site_link  = MLA_Affiliate::link( $v['user_id'] );
		$can_use    = $s['balance'] >= $minimum;
		$to_minimum = min( 100, $minimum > 0 ? floor( 100 * $s['balance'] / $minimum ) : 100 );
		?>
		<header class="mla-hero mla-aff-dash-hero">
			<div>
				<p class="mla-hero__hello">Programme de parrainage · code <strong><?php echo esc_html( $v['code'] ); ?></strong></p>
				<h2 class="mla-hero__title">Mon tableau de bord</h2>
			</div>
			<div class="mla-aff-balance">
				<?php $berry( 40 ); ?>
				<div>
					<strong><?php echo esc_html( MLA_Affiliate::format_berry( $s['balance'] ) ); ?> <small>Berry</small></strong>
					<span>≈ <?php echo esc_html( MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $s['balance'] ) ) ); ?></span>
				</div>
			</div>
		</header>

		<nav class="mla-tabs mla-aff-tabs" role="tablist" data-mla-aff-tabs>
			<button type="button" role="tab" class="is-active" data-mla-aff-tab="apercu"><?php $icon( 'trending-up', 16 ); ?><span>Vue d’ensemble</span></button>
			<button type="button" role="tab" data-mla-aff-tab="liens"><?php $icon( 'link', 16 ); ?><span>Mes liens</span></button>
			<button type="button" role="tab" data-mla-aff-tab="berry"><?php $icon( 'wallet', 16 ); ?><span>Utiliser mes Berry</span></button>
			<button type="button" role="tab" data-mla-aff-tab="historique"><?php $icon( 'list', 16 ); ?><span>Historique</span></button>
			<button type="button" role="tab" data-mla-aff-tab="parametres"><?php $icon( 'settings', 16 ); ?><span>Paramètres</span></button>
		</nav>

		<section class="mla-aff-panel is-active" data-mla-aff-panel="apercu">
			<ul class="mla-aff-stats">
				<li><span class="mla-aff-stats__icon"><?php $berry( 22 ); ?></span><strong><?php echo esc_html( MLA_Affiliate::format_berry( $s['earned'] ) ); ?></strong><small>Berry gagnés au total</small></li>
				<li><span class="mla-aff-stats__icon"><?php $icon( 'bag', 20 ); ?></span><strong><?php echo (int) $s['sales']; ?></strong><small>Ventes</small></li>
				<li><span class="mla-aff-stats__icon"><?php $icon( 'pointer', 20 ); ?></span><strong><?php echo (int) $s['clicks']; ?></strong><small>Clics sur vos liens</small></li>
				<li><span class="mla-aff-stats__icon"><?php $icon( 'hourglass', 20 ); ?></span><strong><?php echo esc_html( MLA_Affiliate::format_berry( $s['pending'] ) ); ?></strong><small>Berry en cours de retrait</small></li>
			</ul>

			<div class="mla-aff-card">
				<div class="mla-aff-row">
					<div>
						<h3 class="mla-aff-card__title">Votre lien pour tout le site</h3>
						<p class="mla-muted">Chaque achat fait dans les <?php echo (int) MLA_Affiliate::cookie_days(); ?> jours qui suivent un clic vous rapporte <?php echo esc_html( $rate ); ?> %.</p>
					</div>
				</div>
				<div class="mla-copy" data-mla-copy>
					<input type="text" value="<?php echo esc_attr( $site_link ); ?>" readonly aria-label="Votre lien de parrainage">
					<button type="button" class="mla-btn" data-mla-copy-btn><?php $icon( 'copy', 16 ); ?><span>Copier</span></button>
				</div>
				<div class="mla-aff-share">
					<a class="mla-btn mla-btn--ghost" href="<?php echo esc_url( 'https://wa.me/?text=' . rawurlencode( 'Je te recommande Mugiwara Academy, des formations pour apprendre et entreprendre : ' . $site_link ) ); ?>" target="_blank" rel="noopener"><?php $icon( 'share', 16 ); ?><span>Partager sur WhatsApp</span></a>
					<a class="mla-btn mla-btn--ghost" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $site_link ) ); ?>" target="_blank" rel="noopener"><?php $icon( 'share', 16 ); ?><span>Partager sur Facebook</span></a>
				</div>
			</div>

			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Seuil d’utilisation</h3>
				<p class="mla-muted"><?php echo $can_use ? 'Vous pouvez utiliser vos Berry dès maintenant.' : esc_html( sprintf( 'Encore %s Berry avant de pouvoir convertir ou retirer vos gains.', MLA_Affiliate::format_berry( $minimum - $s['balance'] ) ) ); ?></p>
				<div class="mla-progress"><div class="mla-bar"><span style="width:<?php echo (int) $to_minimum; ?>%"></span></div><span><?php echo esc_html( MLA_Affiliate::format_berry( min( $s['balance'], $minimum ) ) . ' / ' . MLA_Affiliate::format_berry( $minimum ) ); ?></span></div>
			</div>
		</section>

		<section class="mla-aff-panel" data-mla-aff-panel="liens" hidden>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Lien pour tout le site</h3>
				<div class="mla-copy" data-mla-copy>
					<input type="text" value="<?php echo esc_attr( $site_link ); ?>" readonly aria-label="Lien pour tout le site">
					<button type="button" class="mla-btn" data-mla-copy-btn><?php $icon( 'copy', 16 ); ?><span>Copier</span></button>
				</div>
			</div>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Un lien par produit</h3>
				<p class="mla-muted">Le visiteur arrive directement sur la formation ou la ressource. Vous gagnez <?php echo esc_html( $rate ); ?> % sur tout ce qu’il achète.</p>
				<input type="search" class="mla-search" placeholder="Rechercher une formation ou une ressource…" aria-label="Rechercher un produit" data-mla-aff-search>
				<ul class="mla-aff-products" data-mla-aff-products>
					<?php foreach ( $v['products'] as $product ) : ?>
						<?php $plink = MLA_Affiliate::link( $v['user_id'], $product['url'] ); ?>
						<li data-search="<?php echo esc_attr( $product['search'] ); ?>">
							<?php if ( $product['image'] ) : ?>
								<img src="<?php echo esc_url( $product['image'] ); ?>" alt="" loading="lazy">
							<?php else : ?>
								<span class="mla-dl-row__icon"><?php $icon( 'package', 18 ); ?></span>
							<?php endif; ?>
							<div class="mla-aff-products__body">
								<strong><?php echo esc_html( $product['name'] ); ?></strong>
								<small><?php echo esc_html( MLA_Affiliate::format_fcfa( $product['price'] ) ); ?> · vous gagnez <b class="mla-aff-gain"><?php $berry( 14 ); ?> <?php echo esc_html( MLA_Affiliate::format_berry( $product['gain'] ) ); ?></b></small>
							</div>
							<button type="button" class="mla-btn mla-btn--ghost" data-mla-copy-text="<?php echo esc_attr( $plink ); ?>"><?php $icon( 'copy', 16 ); ?><span>Copier le lien</span></button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>

		<section class="mla-aff-panel" data-mla-aff-panel="berry" hidden>
			<?php if ( ! $can_use ) : ?>
				<div class="mla-alert">Il faut au moins <?php echo esc_html( MLA_Affiliate::format_berry( $minimum ) ); ?> Berry pour convertir ou retirer. Solde actuel : <?php echo esc_html( MLA_Affiliate::format_berry( $s['balance'] ) ); ?> Berry.</div>
			<?php endif; ?>
			<div class="mla-aff-use">
				<form method="post" class="mla-aff-card mla-form" data-mla-berry-form>
					<?php $nonce( 'coupon' ); ?>
					<div class="mla-aff-use__head"><span class="mla-aff-use__icon"><?php $icon( 'ticket', 22 ); ?></span><div><h3 class="mla-aff-card__title">Convertir en code promo</h3><p class="mla-muted">Instantané, à utiliser sur tout le site (valable 6 mois).</p></div></div>
					<label class="mla-field"><span>Berry à convertir</span>
						<input type="number" name="berries" min="<?php echo esc_attr( $minimum ); ?>" max="<?php echo esc_attr( $s['balance'] ); ?>" step="0.01" value="<?php echo esc_attr( $can_use ? $s['balance'] : $minimum ); ?>" required data-mla-berry-input <?php disabled( ! $can_use ); ?>>
					</label>
					<p class="mla-aff-preview">Valeur du code : <strong data-mla-berry-fcfa><?php echo esc_html( MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $can_use ? $s['balance'] : $minimum ) ) ); ?></strong></p>
					<button type="submit" class="mla-btn mla-btn--block" <?php disabled( ! $can_use ); ?>><?php $icon( 'ticket', 16 ); ?><span>Créer mon code promo</span></button>
				</form>

				<form method="post" class="mla-aff-card mla-form" data-mla-berry-form>
					<?php $nonce( 'withdraw' ); ?>
					<div class="mla-aff-use__head"><span class="mla-aff-use__icon"><?php $icon( 'banknote', 22 ); ?></span><div><h3 class="mla-aff-card__title">Retirer mes gains</h3><p class="mla-muted">Reçu sur Mobile Money sous 1 jour ouvré.</p></div></div>
					<label class="mla-field"><span>Berry à retirer</span>
						<input type="number" name="berries" min="<?php echo esc_attr( $minimum ); ?>" max="<?php echo esc_attr( $s['balance'] ); ?>" step="0.01" value="<?php echo esc_attr( $can_use ? $s['balance'] : $minimum ); ?>" required data-mla-berry-input <?php disabled( ! $can_use ); ?>>
					</label>
					<p class="mla-aff-preview">Vous recevrez : <strong data-mla-berry-fcfa><?php echo esc_html( MLA_Affiliate::format_fcfa( MLA_Affiliate::fcfa( $can_use ? $s['balance'] : $minimum ) ) ); ?></strong></p>
					<fieldset class="mla-methods">
						<legend>Moyen de paiement</legend>
						<?php foreach ( MLA_Affiliate::METHODS as $key => $label ) : ?>
							<label class="mla-method mla-method--<?php echo esc_attr( $key ); ?>"><input type="radio" name="method" value="<?php echo esc_attr( $key ); ?>" <?php checked( $v['payout']['method'], $key ); ?> required <?php disabled( ! $can_use ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</fieldset>
					<label class="mla-field"><span>Numéro Mobile Money</span><input type="tel" name="phone" value="<?php echo esc_attr( $v['payout']['phone'] ); ?>" required placeholder="+229 01 00 00 00 00" <?php disabled( ! $can_use ); ?>></label>
					<label class="mla-field"><span>Nom du titulaire</span><input type="text" name="holder" value="<?php echo esc_attr( $v['payout']['holder'] ); ?>" required <?php disabled( ! $can_use ); ?>></label>
					<button type="submit" class="mla-btn mla-btn--block" <?php disabled( ! $can_use ); ?>><?php $icon( 'banknote', 16 ); ?><span>Demander le retrait</span></button>
				</form>
			</div>
		</section>

		<section class="mla-aff-panel" data-mla-aff-panel="historique" hidden>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Mouvements de Berry</h3>
				<?php if ( ! $v['ledger'] ) : ?>
					<p class="mla-muted">Aucun mouvement pour l’instant. Partagez vos liens pour gagner vos premiers Berry.</p>
				<?php else : ?>
					<div class="mla-table-wrap"><table class="mla-table">
						<thead><tr><th>Date</th><th>Opération</th><th>Détail</th><th class="mla-num">Berry</th></tr></thead>
						<tbody>
							<?php foreach ( $v['ledger'] as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $row['created_at'] ) ) ); ?></td>
									<td><?php echo esc_html( MLA_Affiliate::LEDGER_LABELS[ $row['type'] ] ?? $row['type'] ); ?></td>
									<td><?php echo esc_html( $row['note'] ); ?></td>
									<td class="mla-num <?php echo (float) $row['berries'] < 0 ? 'is-neg' : 'is-pos'; ?>"><?php echo esc_html( ( (float) $row['berries'] > 0 ? '+' : '' ) . MLA_Affiliate::format_berry( $row['berries'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
			</div>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Mes codes promo</h3>
				<?php if ( ! $v['coupons'] ) : ?>
					<p class="mla-muted">Aucun code promo créé.</p>
				<?php else : ?>
					<div class="mla-table-wrap"><table class="mla-table">
						<thead><tr><th>Code</th><th>Valeur</th><th>Expire le</th><th>État</th></tr></thead>
						<tbody>
							<?php foreach ( $v['coupons'] as $c ) : ?>
								<tr>
									<td><button type="button" class="mla-code-chip" data-mla-copy-text="<?php echo esc_attr( $c['code'] ); ?>" title="Copier"><?php echo esc_html( $c['code'] ); ?> <?php $icon( 'copy', 14 ); ?></button></td>
									<td><?php echo esc_html( MLA_Affiliate::format_fcfa( $c['amount'] ) ); ?></td>
									<td><?php echo $c['expires'] ? esc_html( wp_date( 'd/m/Y', $c['expires'] ) ) : '—'; ?></td>
									<td><span class="mla-state mla-state--<?php echo esc_attr( sanitize_title( $c['state'] ) ); ?>"><?php echo esc_html( $c['state'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
			</div>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Mes retraits</h3>
				<?php if ( ! $v['withdrawals'] ) : ?>
					<p class="mla-muted">Aucun retrait demandé.</p>
				<?php else : ?>
					<?php $states = array( 'pending' => 'En cours', 'paid' => 'Payé', 'rejected' => 'Refusé' ); ?>
					<div class="mla-table-wrap"><table class="mla-table">
						<thead><tr><th>Date</th><th>Montant</th><th>Moyen</th><th>État</th></tr></thead>
						<tbody>
							<?php foreach ( $v['withdrawals'] as $w ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $w['created_at'] ) ) ); ?></td>
									<td><?php echo esc_html( MLA_Affiliate::format_fcfa( $w['amount'] ) ); ?></td>
									<td><?php echo esc_html( ( MLA_Affiliate::METHODS[ $w['method'] ] ?? $w['method'] ) . ' · ' . $w['phone'] ); ?></td>
									<td><span class="mla-state mla-state--<?php echo esc_attr( $w['status'] ); ?>"><?php echo esc_html( $states[ $w['status'] ] ?? $w['status'] ); ?></span><?php echo $w['admin_note'] ? '<small class="mla-muted"> ' . esc_html( $w['admin_note'] ) . '</small>' : ''; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
			</div>
		</section>

		<section class="mla-aff-panel" data-mla-aff-panel="parametres" hidden>
			<form method="post" class="mla-aff-card mla-form">
				<?php $nonce( 'settings' ); ?>
				<h3 class="mla-aff-card__title">Paiement par défaut</h3>
				<p class="mla-muted">Pré-rempli lors de vos demandes de retrait.</p>
				<fieldset class="mla-methods">
					<legend>Moyen de paiement</legend>
					<?php foreach ( MLA_Affiliate::METHODS as $key => $label ) : ?>
						<label class="mla-method mla-method--<?php echo esc_attr( $key ); ?>"><input type="radio" name="method" value="<?php echo esc_attr( $key ); ?>" <?php checked( $v['payout']['method'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<label class="mla-field"><span>Numéro Mobile Money</span><input type="tel" name="phone" value="<?php echo esc_attr( $v['payout']['phone'] ); ?>"></label>
				<label class="mla-field"><span>Nom du titulaire</span><input type="text" name="holder" value="<?php echo esc_attr( $v['payout']['holder'] ); ?>"></label>
				<label class="mla-check"><input type="checkbox" name="notify" value="1" <?php checked( 'no' !== get_user_meta( $v['user_id'], 'mla_aff_notify', true ) ); ?>> Recevoir un e-mail à chaque commission</label>
				<button type="submit" class="mla-btn"><?php $icon( 'check', 16 ); ?><span>Enregistrer</span></button>
			</form>
			<div class="mla-aff-card">
				<h3 class="mla-aff-card__title">Rappel des règles</h3>
				<ul class="mla-aff-rules">
					<li><?php echo esc_html( $rate ); ?> % du montant payé de chaque commande passée via vos liens, crédité en Berry dès le paiement.</li>
					<li>1 Berry = <?php echo esc_html( MLA_Affiliate::format_fcfa( $value ) ); ?>. Conversion ou retrait à partir de <?php echo esc_html( MLA_Affiliate::format_berry( $minimum ) ); ?> Berry.</li>
					<li>Un achat via votre propre lien ne rapporte pas de commission ; une commande remboursée annule la commission.</li>
					<li>Les retraits sont envoyés sous 1 jour ouvré sur le compte Mobile Money indiqué.</li>
				</ul>
			</div>
		</section>
	<?php endif; ?>
</div>

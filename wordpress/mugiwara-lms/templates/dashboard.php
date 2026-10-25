<?php
/**
 * Tableau de bord « Mes formations ».
 * Copiez ce fichier dans votre thème (mugiwara-lms/dashboard.php) pour le personnaliser.
 *
 * @var array   $cards
 * @var string  $shop_url
 * @var WP_User $user
 */

defined( 'ABSPATH' ) || exit;

$total_lessons = array_sum( wp_list_pluck( wp_list_pluck( $cards, 'progress' ), 'total' ) );
$done_lessons  = array_sum( wp_list_pluck( wp_list_pluck( $cards, 'progress' ), 'count' ) );
$finished      = count( array_filter( $cards, function ( $c ) { return 'Revoir' === $c['cta']; } ) );
$resume        = current( array_filter( $cards, function ( $c ) { return 'Reprendre' === $c['cta']; } ) );
?>
<div class="mla mla-dashboard">
	<header class="mla-hero">
		<div>
			<p class="mla-hero__hello">Bonjour <?php echo esc_html( $user->first_name ?: $user->display_name ); ?> 👋</p>
			<h2 class="mla-hero__title">Mes formations</h2>
		</div>
		<?php if ( $cards ) : ?>
			<ul class="mla-stats">
				<li><strong><?php echo count( $cards ); ?></strong><span>formation<?php echo count( $cards ) > 1 ? 's' : ''; ?></span></li>
				<li><strong><?php echo (int) $done_lessons; ?>/<?php echo (int) $total_lessons; ?></strong><span>leçons terminées</span></li>
				<li><strong><?php echo (int) $finished; ?></strong><span>terminée<?php echo $finished > 1 ? 's' : ''; ?></span></li>
			</ul>
		<?php endif; ?>
	</header>

	<?php if ( $resume ) : ?>
		<a class="mla-resume" href="<?php echo esc_url( $resume['url'] ); ?>">
			<?php if ( $resume['image'] ) : ?>
				<img src="<?php echo esc_url( $resume['image'] ); ?>" alt="" loading="lazy">
			<?php endif; ?>
			<div class="mla-resume__body">
				<span class="mla-eyebrow">Reprendre</span>
				<strong><?php echo esc_html( $resume['title'] ); ?></strong>
				<div class="mla-bar"><span style="width:<?php echo (int) $resume['progress']['percent']; ?>%"></span></div>
			</div>
			<span class="mla-btn">▶ Continuer</span>
		</a>
	<?php endif; ?>

	<?php if ( ! $cards ) : ?>
		<div class="mla-empty">
			<div class="mla-empty__icon">🎓</div>
			<h3>Vous n’avez pas encore de formation</h3>
			<p>Dès votre premier achat, vos formations apparaîtront ici, prêtes à être suivies et téléchargées.</p>
			<a class="mla-btn" href="<?php echo esc_url( $shop_url ); ?>">Découvrir les formations</a>
		</div>
	<?php else : ?>
		<?php if ( count( $cards ) > 4 ) : ?>
			<div class="mla-toolbar">
				<input type="search" class="mla-search" placeholder="Rechercher une formation…" aria-label="Rechercher une formation" data-mla-search>
				<div class="mla-filters" role="tablist">
					<button type="button" class="is-active" data-mla-filter="all">Toutes</button>
					<button type="button" data-mla-filter="Reprendre">En cours</button>
					<button type="button" data-mla-filter="Commencer">À commencer</button>
					<button type="button" data-mla-filter="Revoir">Terminées</button>
				</div>
			</div>
		<?php endif; ?>

		<div class="mla-grid" data-mla-grid>
			<?php foreach ( $cards as $card ) : ?>
				<article class="mla-card" data-title="<?php echo esc_attr( remove_accents( strtolower( $card['title'] ) ) ); ?>" data-state="<?php echo esc_attr( $card['cta'] ); ?>">
					<a class="mla-card__media" href="<?php echo esc_url( $card['url'] ); ?>" tabindex="-1">
						<?php if ( $card['image'] ) : ?>
							<img src="<?php echo esc_url( $card['image'] ); ?>" alt="" loading="lazy">
						<?php else : ?>
							<span class="mla-card__placeholder">🎓</span>
						<?php endif; ?>
						<?php if ( 'Revoir' === $card['cta'] ) : ?>
							<span class="mla-badge mla-badge--done">✓ Terminée</span>
						<?php elseif ( 'Commencer' === $card['cta'] ) : ?>
							<span class="mla-badge">Nouveau</span>
						<?php endif; ?>
					</a>
					<div class="mla-card__body">
						<h3 class="mla-card__title"><a href="<?php echo esc_url( $card['url'] ); ?>"><?php echo esc_html( $card['title'] ); ?></a></h3>
						<p class="mla-card__meta">
							<?php echo (int) $card['modules']; ?> module<?php echo $card['modules'] > 1 ? 's' : ''; ?>
							· <?php echo (int) $card['progress']['total']; ?> leçon<?php echo $card['progress']['total'] > 1 ? 's' : ''; ?>
							<?php if ( $card['resources'] ) : ?>
								· <?php echo (int) $card['resources']; ?> ressource<?php echo $card['resources'] > 1 ? 's' : ''; ?>
							<?php endif; ?>
						</p>
						<div class="mla-progress">
							<div class="mla-bar"><span style="width:<?php echo (int) $card['progress']['percent']; ?>%"></span></div>
							<span><?php echo (int) $card['progress']['percent']; ?>%</span>
						</div>
						<a class="mla-btn mla-btn--block" href="<?php echo esc_url( $card['url'] ); ?>"><?php echo esc_html( $card['cta'] ); ?></a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<p class="mla-noresult" data-mla-noresult hidden>Aucune formation ne correspond à votre recherche.</p>
	<?php endif; ?>
</div>

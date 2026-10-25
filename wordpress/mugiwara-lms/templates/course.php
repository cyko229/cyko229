<?php
/**
 * Lecteur de formation.
 * Copiez ce fichier dans votre thème (mugiwara-lms/course.php) pour le personnaliser.
 *
 * @var WP_Post $course
 * @var array   $structure
 * @var array   $lessons     Leçons à plat, préparées pour l'affichage.
 * @var string  $current     Identifiant de la leçon affichée.
 * @var array   $progress
 * @var array   $resources
 * @var string  $download_all
 * @var string  $back_url
 * @var array   $config      Données du lecteur JavaScript.
 */

defined( 'ABSPATH' ) || exit;

$icons = array(
	'video'    => '▶',
	'audio'    => '♪',
	'pdf'      => '📄',
	'document' => '📝',
	'image'    => '🖼',
	'folder'   => '📁',
	'archive'  => '🗜',
	'link'     => '🔗',
);

$index  = array_search( $current, wp_list_pluck( $lessons, 'id' ), true );
$lesson = false === $index ? null : $lessons[ $index ];
$prev   = false !== $index && $index > 0 ? $lessons[ $index - 1 ] : null;
$next   = false !== $index && $index < count( $lessons ) - 1 ? $lessons[ $index + 1 ] : null;
$done   = array_flip( $progress['done'] );
$about  = apply_filters( 'the_content', $course->post_content );
?>
<div class="mla mla-player" data-mla-player="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<header class="mla-player__top">
		<a class="mla-back" href="<?php echo esc_url( $back_url ); ?>">← Mes formations</a>
		<h2 class="mla-player__title"><?php echo esc_html( get_the_title( $course ) ); ?></h2>
		<div class="mla-player__progress">
			<div class="mla-bar"><span data-mla-bar style="width:<?php echo (int) $progress['percent']; ?>%"></span></div>
			<span data-mla-count><?php echo (int) $progress['count']; ?>/<?php echo (int) $progress['total']; ?> leçons · <?php echo (int) $progress['percent']; ?>%</span>
		</div>
		<?php if ( $download_all ) : ?>
			<a class="mla-btn mla-btn--ghost" href="<?php echo esc_url( $download_all ); ?>" target="_blank" rel="noopener">⬇ Télécharger la formation</a>
		<?php endif; ?>
		<button type="button" class="mla-btn mla-btn--ghost mla-toggle-outline" data-mla-outline-toggle aria-expanded="false">☰ Sommaire</button>
	</header>

	<div class="mla-player__layout">
		<main class="mla-stage">
			<?php if ( ! $lesson ) : ?>
				<div class="mla-empty">
					<div class="mla-empty__icon">🛠</div>
					<h3>Le contenu arrive bientôt</h3>
					<p>Cette formation est en cours de préparation. Les ressources ci-dessous sont déjà disponibles.</p>
				</div>
			<?php else : ?>
				<div class="mla-viewer mla-viewer--<?php echo esc_attr( $lesson['type'] ); ?>" data-mla-viewer>
					<?php if ( $lesson['embed'] ) : ?>
						<iframe src="<?php echo esc_url( $lesson['embed'] ); ?>" title="<?php echo esc_attr( $lesson['title'] ); ?>" allow="autoplay; fullscreen; encrypted-media" allowfullscreen loading="lazy"></iframe>
					<?php else : ?>
						<div class="mla-viewer__fallback">
							<p>Ce contenu s’ouvre dans un nouvel onglet.</p>
							<a class="mla-btn" href="<?php echo esc_url( $lesson['open'] ?: $lesson['download'] ); ?>" target="_blank" rel="noopener">Ouvrir le contenu</a>
						</div>
					<?php endif; ?>
				</div>

				<div class="mla-lesson-head">
					<div>
						<span class="mla-eyebrow" data-mla-module><?php echo esc_html( $lesson['module'] ); ?></span>
						<h3 class="mla-lesson-title" data-mla-title><?php echo esc_html( $lesson['title'] ); ?></h3>
						<span class="mla-lesson-duration" data-mla-duration><?php echo esc_html( $lesson['duration'] ? '⏱ ' . $lesson['duration'] : '' ); ?></span>
					</div>
					<div class="mla-lesson-actions">
						<a class="mla-btn mla-btn--ghost" data-mla-download href="<?php echo esc_url( $lesson['download'] ?: '#' ); ?>" target="_blank" rel="noopener" <?php echo $lesson['download'] ? '' : 'hidden'; ?>>⬇ Télécharger</a>
						<button type="button" class="mla-btn mla-done-btn<?php echo isset( $done[ $lesson['id'] ] ) ? ' is-done' : ''; ?>" data-mla-done>
							<?php echo isset( $done[ $lesson['id'] ] ) ? '✓ Terminée' : 'Marquer comme terminée'; ?>
						</button>
					</div>
				</div>

				<nav class="mla-pager">
					<a class="mla-pager__prev" data-mla-prev href="<?php echo $prev ? esc_url( add_query_arg( 'lecon', $prev['id'] ) ) : '#'; ?>" <?php echo $prev ? '' : 'hidden'; ?>>← Précédente</a>
					<a class="mla-pager__next" data-mla-next href="<?php echo $next ? esc_url( add_query_arg( 'lecon', $next['id'] ) ) : '#'; ?>" <?php echo $next ? '' : 'hidden'; ?>>Suivante →</a>
				</nav>
			<?php endif; ?>

			<div class="mla-tabs" role="tablist">
				<button type="button" role="tab" class="is-active" data-mla-tab="notes">Description</button>
				<button type="button" role="tab" data-mla-tab="resources">Ressources <span class="mla-pill"><?php echo count( $resources ); ?></span></button>
				<button type="button" role="tab" data-mla-tab="about">À propos</button>
			</div>

			<section class="mla-panel is-active" data-mla-panel="notes">
				<div data-mla-description><?php echo $lesson && trim( wp_strip_all_tags( $lesson['description'] ) ) ? wp_kses_post( $lesson['description'] ) : '<p class="mla-muted">Aucune note pour cette leçon.</p>'; ?></div>
			</section>

			<section class="mla-panel" data-mla-panel="resources" hidden>
				<?php if ( ! $resources ) : ?>
					<p class="mla-muted">Aucune ressource complémentaire pour cette formation.</p>
				<?php else : ?>
					<ul class="mla-resources">
						<?php foreach ( $resources as $res ) : ?>
							<li>
								<span class="mla-resources__icon"><?php echo 'folder' === $res['kind'] ? '📁' : '📎'; ?></span>
								<div>
									<strong><?php echo esc_html( $res['title'] ); ?></strong>
									<?php if ( $res['note'] ) : ?>
										<small><?php echo esc_html( $res['note'] ); ?></small>
									<?php endif; ?>
								</div>
								<a class="mla-btn mla-btn--ghost" href="<?php echo esc_url( $res['download'] ?: $res['url'] ); ?>" target="_blank" rel="noopener"><?php echo 'link' === $res['kind'] ? 'Ouvrir' : '⬇ Télécharger'; ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $download_all ) : ?>
					<p class="mla-download-all"><a class="mla-btn" href="<?php echo esc_url( $download_all ); ?>" target="_blank" rel="noopener">⬇ Télécharger toute la formation</a></p>
				<?php endif; ?>
			</section>

			<section class="mla-panel" data-mla-panel="about" hidden>
				<?php echo $about ? wp_kses_post( $about ) : '<p class="mla-muted">' . esc_html( get_the_excerpt( $course ) ) . '</p>'; ?>
			</section>
		</main>

		<aside class="mla-outline" data-mla-outline>
			<div class="mla-outline__head">
				<strong>Sommaire</strong>
				<button type="button" class="mla-outline__close" data-mla-outline-toggle aria-label="Fermer le sommaire">✕</button>
			</div>
			<?php foreach ( $structure['modules'] as $m => $module ) : ?>
				<?php
				$ids        = wp_list_pluck( $module['lessons'], 'id' );
				$mod_done   = count( array_intersect_key( $done, array_flip( $ids ) ) );
				$has_current = in_array( $current, $ids, true );
				?>
				<details class="mla-module" <?php echo $has_current || ( ! $lesson && 0 === $m ) ? 'open' : ''; ?>>
					<summary>
						<span class="mla-module__index"><?php echo (int) $m + 1; ?></span>
						<span class="mla-module__title"><?php echo esc_html( $module['title'] ); ?></span>
						<span class="mla-module__count" data-mla-module-count="<?php echo esc_attr( $module['id'] ); ?>"><?php echo (int) $mod_done; ?>/<?php echo count( $ids ); ?></span>
					</summary>
					<ol class="mla-lessons">
						<?php foreach ( $module['lessons'] as $item ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 'lecon', $item['id'] ) ); ?>"
									class="mla-lesson<?php echo $item['id'] === $current ? ' is-current' : ''; ?><?php echo isset( $done[ $item['id'] ] ) ? ' is-done' : ''; ?>"
									data-mla-lesson="<?php echo esc_attr( $item['id'] ); ?>" data-mla-in-module="<?php echo esc_attr( $module['id'] ); ?>">
									<span class="mla-lesson__check" aria-hidden="true"></span>
									<span class="mla-lesson__icon" aria-hidden="true"><?php echo esc_html( $icons[ $item['type'] ] ?? '▶' ); ?></span>
									<span class="mla-lesson__title"><?php echo esc_html( $item['title'] ); ?></span>
									<?php if ( $item['duration'] ) : ?>
										<span class="mla-lesson__duration"><?php echo esc_html( $item['duration'] ); ?></span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ol>
				</details>
			<?php endforeach; ?>
		</aside>
	</div>
</div>

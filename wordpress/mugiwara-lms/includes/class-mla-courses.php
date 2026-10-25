<?php
/**
 * Type de contenu « Formation » et lecture de sa structure (modules, leçons, ressources).
 */

defined( 'ABSPATH' ) || exit;

class MLA_Courses {

	const POST_TYPE = 'mla_course';
	const META      = '_mla_structure';

	const LESSON_TYPES = array(
		'video'    => 'Vidéo',
		'pdf'      => 'PDF',
		'audio'    => 'Audio',
		'document' => 'Document',
		'image'    => 'Image',
		'folder'   => 'Dossier',
		'archive'  => 'Archive',
		'link'     => 'Lien',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => 'Formations',
					'singular_name'      => 'Formation',
					'menu_name'          => 'Espace formation',
					'all_items'          => 'Toutes les formations',
					'add_new'            => 'Ajouter',
					'add_new_item'       => 'Ajouter une formation',
					'edit_item'          => 'Modifier la formation',
					'new_item'           => 'Nouvelle formation',
					'search_items'       => 'Rechercher une formation',
					'not_found'          => 'Aucune formation',
					'not_found_in_trash' => 'Aucune formation dans la corbeille',
				),
				// Le contenu n'est visible que dans l'espace élève, jamais en page publique.
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 56,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => false,
			)
		);
	}

	/**
	 * Structure normalisée d'une formation.
	 *
	 * @return array{download_all:string,modules:array,resources:array}
	 */
	public static function structure( $course_id ) {
		$raw = get_post_meta( $course_id, self::META, true );
		$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return self::sanitize_structure( is_array( $raw ) ? $raw : array() );
	}

	public static function save_structure( $course_id, $structure ) {
		$clean = self::sanitize_structure( $structure );
		update_post_meta( $course_id, self::META, wp_slash( wp_json_encode( $clean ) ) );
		return $clean;
	}

	/**
	 * Nettoie une structure reçue du formulaire d'administration ou de l'import Drive.
	 */
	public static function sanitize_structure( $data ) {
		$out = array(
			'download_all' => esc_url_raw( $data['download_all'] ?? '' ),
			'modules'      => array(),
			'resources'    => array(),
		);
		$seen = array();
		$id   = function ( $value ) use ( &$seen ) {
			$value = preg_replace( '~[^a-zA-Z0-9]~', '', (string) $value );
			if ( '' === $value || isset( $seen[ $value ] ) ) {
				$value = MLA_Drive::uid();
			}
			$seen[ $value ] = true;
			return $value;
		};

		foreach ( (array) ( $data['modules'] ?? array() ) as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}
			$module_id = $id( $module['id'] ?? '' );
			$lessons   = array();
			foreach ( (array) ( $module['lessons'] ?? array() ) as $lesson ) {
				if ( ! is_array( $lesson ) || ( '' === trim( $lesson['title'] ?? '' ) && '' === trim( $lesson['url'] ?? '' ) ) ) {
					continue;
				}
				$type      = $lesson['type'] ?? '';
				$lessons[] = array(
					'id'           => $id( $lesson['id'] ?? '' ),
					'title'        => sanitize_text_field( $lesson['title'] ?? '' ) ?: 'Leçon',
					'type'         => isset( self::LESSON_TYPES[ $type ] ) ? $type : self::guess_type( $lesson['url'] ?? '' ),
					'url'          => esc_url_raw( trim( $lesson['url'] ?? '' ) ),
					'duration'     => sanitize_text_field( $lesson['duration'] ?? '' ),
					'description'  => wp_kses_post( $lesson['description'] ?? '' ),
					'downloadable' => ! empty( $lesson['downloadable'] ),
				);
			}
			$out['modules'][] = array(
				'id'      => $module_id,
				'title'   => sanitize_text_field( $module['title'] ?? '' ) ?: 'Module',
				'lessons' => $lessons,
			);
		}

		foreach ( (array) ( $data['resources'] ?? array() ) as $res ) {
			if ( ! is_array( $res ) || '' === trim( $res['url'] ?? '' ) ) {
				continue;
			}
			$out['resources'][] = array(
				'id'    => $id( $res['id'] ?? '' ),
				'title' => sanitize_text_field( $res['title'] ?? '' ) ?: 'Ressource',
				'url'   => esc_url_raw( trim( $res['url'] ) ),
				'note'  => sanitize_text_field( $res['note'] ?? '' ),
			);
		}
		return $out;
	}

	public static function guess_type( $url ) {
		$p = MLA_Drive::parse( $url );
		switch ( $p['kind'] ) {
			case 'youtube':
				return 'video';
			case 'folder':
				return 'folder';
			case 'doc':
			case 'sheet':
			case 'slides':
				return 'document';
			case 'link':
				return 'link';
		}
		if ( preg_match( '~\.(mp4|mov|mkv|webm|avi)\b~i', $url ) ) {
			return 'video';
		}
		if ( preg_match( '~\.pdf\b~i', $url ) ) {
			return 'pdf';
		}
		return 'video';
	}

	/**
	 * Liste à plat des leçons, dans l'ordre du parcours.
	 */
	public static function lessons( $course_id ) {
		$list = array();
		foreach ( self::structure( $course_id )['modules'] as $module ) {
			foreach ( $module['lessons'] as $lesson ) {
				$lesson['module'] = $module['title'];
				$list[]           = $lesson;
			}
		}
		return $list;
	}

	/**
	 * Retrouve un élément téléchargeable (leçon, ressource, ou « all ») par identifiant.
	 */
	public static function find_item( $course_id, $item_id ) {
		$structure = self::structure( $course_id );
		if ( 'all' === $item_id ) {
			return $structure['download_all'] ? array( 'url' => $structure['download_all'], 'downloadable' => true ) : null;
		}
		foreach ( $structure['modules'] as $module ) {
			foreach ( $module['lessons'] as $lesson ) {
				if ( $lesson['id'] === $item_id ) {
					return $lesson;
				}
			}
		}
		foreach ( $structure['resources'] as $res ) {
			if ( $res['id'] === $item_id ) {
				$res['downloadable'] = true;
				return $res;
			}
		}
		return null;
	}

	/**
	 * Image de la formation : image mise en avant, sinon celle du produit lié.
	 */
	public static function thumbnail_url( $course_id ) {
		$url = get_the_post_thumbnail_url( $course_id, 'large' );
		if ( ! $url ) {
			foreach ( MLA_Access::products_for_course( $course_id ) as $product_id ) {
				$url = get_the_post_thumbnail_url( $product_id, 'large' );
				if ( $url ) {
					break;
				}
			}
		}
		return $url ?: '';
	}

	public static function all( $args = array() ) {
		return get_posts(
			array_merge(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				),
				$args
			)
		);
	}
}

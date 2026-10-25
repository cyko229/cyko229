<?php
/**
 * Outils Google Drive : lecture des liens, URLs d'intégration et de téléchargement, import d'un dossier.
 */

defined( 'ABSPATH' ) || exit;

class MLA_Drive {

	const FOLDER_MIME = 'application/vnd.google-apps.folder';

	/**
	 * Analyse un lien (Drive, Docs, YouTube…) et renvoie sa nature et son identifiant.
	 *
	 * @param string $url Lien collé par l'administrateur.
	 * @return array{kind:string,id:string,url:string}
	 */
	public static function parse( $url ) {
		$url = trim( (string) $url );
		$out = array(
			'kind' => '',
			'id'   => '',
			'url'  => $url,
		);
		if ( '' === $url ) {
			return $out;
		}

		$patterns = array(
			'folder' => '~drive\.google\.com/(?:drive/(?:u/\d+/)?(?:mobile/)?folders/|embeddedfolderview\?(?:.*&)?id=)([\w-]{10,})~',
			'doc'    => '~docs\.google\.com/document/(?:u/\d+/)?d/([\w-]{10,})~',
			'sheet'  => '~docs\.google\.com/spreadsheets/(?:u/\d+/)?d/([\w-]{10,})~',
			'slides' => '~docs\.google\.com/presentation/(?:u/\d+/)?d/([\w-]{10,})~',
			'file'   => '~(?:drive|docs)\.google\.com/(?:file/(?:u/\d+/)?d/|(?:open|uc)\?(?:.*&)?id=)([\w-]{10,})~',
			'youtube' => '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~',
		);
		foreach ( $patterns as $kind => $re ) {
			if ( preg_match( $re, $url, $m ) ) {
				$out['kind'] = $kind;
				$out['id']   = $m[1];
				return $out;
			}
		}
		if ( preg_match( '~drive\.usercontent\.google\.com/.*[?&]id=([\w-]{10,})~', $url, $m ) ) {
			$out['kind'] = 'file';
			$out['id']   = $m[1];
			return $out;
		}
		// Identifiant Drive collé seul.
		if ( preg_match( '~^[\w-]{25,}$~', $url ) ) {
			$out['kind'] = 'file';
			$out['id']   = $url;
			return $out;
		}
		$out['kind'] = 'link';
		return $out;
	}

	/**
	 * URL à afficher dans le lecteur (iframe), ou chaîne vide si le lien ne s'intègre pas.
	 */
	public static function embed_url( $url ) {
		$p  = self::parse( $url );
		$id = rawurlencode( $p['id'] );
		switch ( $p['kind'] ) {
			case 'file':
				return "https://drive.google.com/file/d/{$id}/preview";
			case 'folder':
				return "https://drive.google.com/embeddedfolderview?id={$id}#grid";
			case 'doc':
				return "https://docs.google.com/document/d/{$id}/preview";
			case 'sheet':
				return "https://docs.google.com/spreadsheets/d/{$id}/preview";
			case 'slides':
				return "https://docs.google.com/presentation/d/{$id}/embed?start=false&loop=false";
			case 'youtube':
				return "https://www.youtube-nocookie.com/embed/{$id}?rel=0&modestbranding=1";
		}
		return '';
	}

	/**
	 * URL de téléchargement direct, ou chaîne vide si le lien n'est pas téléchargeable.
	 * Un dossier renvoie vers Drive, qui propose « Tout télécharger » (archive ZIP).
	 */
	public static function download_url( $url ) {
		$p  = self::parse( $url );
		$id = rawurlencode( $p['id'] );
		switch ( $p['kind'] ) {
			case 'file':
				return "https://drive.google.com/uc?export=download&id={$id}";
			case 'folder':
				return "https://drive.google.com/drive/folders/{$id}";
			case 'doc':
				return "https://docs.google.com/document/d/{$id}/export?format=pdf";
			case 'sheet':
				return "https://docs.google.com/spreadsheets/d/{$id}/export?format=xlsx";
			case 'slides':
				return "https://docs.google.com/presentation/d/{$id}/export/pdf";
			case 'link':
				return $p['url'];
		}
		return '';
	}

	/**
	 * Type de leçon déduit d'un type MIME Drive.
	 */
	public static function type_from_mime( $mime, $name = '' ) {
		if ( self::FOLDER_MIME === $mime ) {
			return 'folder';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( preg_match( '~zip|rar|7z|compressed|tar~', $mime ) || preg_match( '~\.(zip|rar|7z)$~i', $name ) ) {
			return 'archive';
		}
		return 'document';
	}

	/**
	 * Liste le contenu d'un dossier Drive public (partagé « Tous les utilisateurs disposant du lien »).
	 *
	 * @return array|WP_Error Liste de fichiers {id,name,mimeType,size,videoMediaMetadata}.
	 */
	public static function list_folder( $folder_id, $api_key ) {
		if ( ! $api_key ) {
			$public = self::read_public_folder( $folder_id );
			return is_wp_error( $public ) ? $public : $public['files'];
		}
		$files = array();
		$token = '';
		do {
			$query = array(
				'q'                         => "'" . $folder_id . "' in parents and trashed = false",
				'key'                       => $api_key,
				'fields'                    => 'nextPageToken,files(id,name,mimeType,size,videoMediaMetadata(durationMillis),shortcutDetails)',
				'pageSize'                  => 1000,
				'supportsAllDrives'         => 'true',
				'includeItemsFromAllDrives' => 'true',
			);
			if ( $token ) {
				$query['pageToken'] = $token;
			}
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $query ), 'https://www.googleapis.com/drive/v3/files' ), array( 'timeout' => 20 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Réponse inattendue de Google Drive.';
				return new WP_Error( 'mla_drive', $message );
			}
			foreach ( (array) ( $body['files'] ?? array() ) as $file ) {
				// Un raccourci Drive pointe vers un autre fichier : on suit la cible.
				if ( 'application/vnd.google-apps.shortcut' === $file['mimeType'] && ! empty( $file['shortcutDetails']['targetId'] ) ) {
					$file['id']       = $file['shortcutDetails']['targetId'];
					$file['mimeType'] = $file['shortcutDetails']['targetMimeType'] ?? '';
				}
				$files[] = $file;
			}
			$token = $body['nextPageToken'] ?? '';
		} while ( $token );

		usort(
			$files,
			function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $files;
	}

	/**
	 * Lit un dossier partagé par lien sans clé API, via la vue intégrable publique de Drive.
	 * Cette vue liste le nom, le lien et l'icône (qui porte le type MIME) de chaque élément.
	 *
	 * @return array{name:string,files:array}|WP_Error
	 */
	public static function read_public_folder( $folder_id ) {
		$cache_key = 'mla_drive_' . md5( $folder_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://drive.google.com/embeddedfolderview?id=' . rawurlencode( $folder_id ),
			array(
				'timeout' => 20,
				'headers' => array( 'Accept-Language' => 'fr-FR,fr;q=0.9' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || false === strpos( $html, 'flip-entry' ) && false === strpos( $html, 'folder-view' ) ) {
			return new WP_Error( 'mla_drive', 'Dossier Drive illisible : vérifiez qu’il est partagé en « Tous les utilisateurs disposant du lien ».' );
		}

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$name  = '';
		$title = $xpath->query( '//title' )->item( 0 );
		if ( $title ) {
			$name = trim( preg_replace( '~\s*[-–]\s*Google\s*Drive\s*$~iu', '', $title->textContent ) );
		}

		$files = array();
		foreach ( $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " flip-entry ")]' ) as $entry ) {
			$id = preg_replace( '~^entry-~', '', $entry->getAttribute( 'id' ) );
			$a  = $xpath->query( './/a[@href]', $entry )->item( 0 );
			$t  = $xpath->query( './/*[contains(@class, "flip-entry-title")]', $entry )->item( 0 );
			if ( ! $id || ! $t ) {
				continue;
			}
			$href = $a ? $a->getAttribute( 'href' ) : '';
			$mime = '';
			foreach ( $xpath->query( './/img[@src]', $entry ) as $img ) {
				if ( preg_match( '~/type/([a-z0-9.+-]+/[a-z0-9.+-]+)~i', $img->getAttribute( 'src' ), $m ) ) {
					$mime = strtolower( $m[1] );
					break;
				}
			}
			if ( preg_match( '~/drive/(?:u/\d+/)?folders/~', $href ) ) {
				$mime = self::FOLDER_MIME;
			} elseif ( '' === $mime ) {
				$mime = self::mime_from_href( $href, $t->textContent );
			}
			$files[] = array(
				'id'       => $id,
				'name'     => trim( $t->textContent ),
				'mimeType' => $mime,
			);
		}
		usort(
			$files,
			function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		$result = array(
			'name'  => $name,
			'files' => $files,
		);
		set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	private static function mime_from_href( $href, $name ) {
		$google = array(
			'document'     => 'application/vnd.google-apps.document',
			'spreadsheets' => 'application/vnd.google-apps.spreadsheet',
			'presentation' => 'application/vnd.google-apps.presentation',
		);
		if ( preg_match( '~docs\.google\.com/(document|spreadsheets|presentation)/~', $href, $m ) ) {
			return $google[ $m[1] ];
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$map = array(
			'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'avi' => 'video/x-msvideo',
			'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
			'pdf' => 'application/pdf', 'zip' => 'application/zip', 'rar' => 'application/x-rar',
			'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
		);
		return $map[ $ext ] ?? 'application/octet-stream';
	}

	/**
	 * Nom d'un dossier Drive.
	 */
	public static function folder_name( $folder_id, $api_key ) {
		if ( ! $api_key ) {
			$public = self::read_public_folder( $folder_id );
			return is_wp_error( $public ) ? '' : $public['name'];
		}
		$response = wp_remote_get( 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $folder_id ) . '?fields=name&supportsAllDrives=true&key=' . rawurlencode( $api_key ), array( 'timeout' => 15 ) );
		$body     = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['name'] ?? '';
	}

	/**
	 * Importe un ou plusieurs dossiers Drive dans une seule formation.
	 * Avec plusieurs dossiers, chacun apporte ses modules (un dossier sans sous-dossier devient un module à son nom)
	 * et un lien « dossier complet » dans les ressources.
	 *
	 * @param string[] $urls
	 * @return array|WP_Error
	 */
	public static function import_folders( $urls, $api_key ) {
		$urls = array_values( array_filter( array_map( 'trim', (array) $urls ) ) );
		if ( ! $urls ) {
			return new WP_Error( 'mla_drive', 'Collez au moins un lien de dossier Google Drive.' );
		}
		if ( 1 === count( $urls ) ) {
			return self::import_folder( $urls[0], $api_key );
		}
		$merged = array(
			'download_all' => '',
			'modules'      => array(),
			'resources'    => array(),
		);
		$folder_links = array();
		foreach ( $urls as $i => $url ) {
			$part = self::import_folder( $url, $api_key );
			if ( is_wp_error( $part ) ) {
				return new WP_Error( 'mla_drive', 'Dossier ' . ( $i + 1 ) . ' : ' . $part->get_error_message() );
			}
			$name = self::clean_title( self::folder_name( self::parse( $url )['id'], $api_key ) ) ?: 'Partie ' . ( $i + 1 );
			if ( 1 === count( $part['modules'] ) && 'Contenu de la formation' === $part['modules'][0]['title'] ) {
				$part['modules'][0]['title'] = $name;
			}
			$merged['modules']   = array_merge( $merged['modules'], $part['modules'] );
			$merged['resources'] = array_merge( $merged['resources'], $part['resources'] );
			$folder_links[]      = array(
				'id'    => self::uid(),
				'title' => 'Dossier complet : ' . $name,
				'url'   => $part['download_all'],
				'note'  => 'Ouvre le dossier sur Google Drive (bouton « Tout télécharger »)',
			);
		}
		$merged['resources'] = array_merge( $merged['resources'], $folder_links );
		return $merged;
	}

	/**
	 * Construit la structure d'une formation à partir d'un dossier Drive :
	 * - chaque sous-dossier devient un module, ses fichiers deviennent des leçons ;
	 * - un sous-dossier nommé « Ressources », « Bonus » ou « Annexes » alimente l'onglet Ressources ;
	 * - les fichiers à la racine forment un module « Introduction ».
	 *
	 * @return array|WP_Error
	 */
	public static function import_folder( $folder_url, $api_key ) {
		$p = self::parse( $folder_url );
		if ( 'folder' !== $p['kind'] ) {
			return new WP_Error( 'mla_drive', 'Collez le lien d’un dossier Google Drive (drive.google.com/drive/folders/…).' );
		}
		$root = self::list_folder( $p['id'], $api_key );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$structure = array(
			'download_all' => 'https://drive.google.com/drive/folders/' . $p['id'],
			'modules'      => array(),
			'resources'    => array(),
		);
		$intro = array();

		foreach ( $root as $item ) {
			if ( self::FOLDER_MIME !== $item['mimeType'] ) {
				$intro[] = self::lesson_from_file( $item );
				continue;
			}
			$children = self::list_folder( $item['id'], $api_key );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
			if ( preg_match( '~^\W*(ressources?|resources?|bonus|annexes?|documents?)\b~iu', $item['name'] ) ) {
				foreach ( $children as $child ) {
					$structure['resources'][] = array(
						'id'    => self::uid(),
						'title' => self::clean_title( $child['name'] ),
						'url'   => self::file_url( $child ),
						'note'  => '',
					);
				}
				continue;
			}
			$structure['modules'][] = array(
				'id'      => self::uid(),
				'title'   => self::clean_title( $item['name'] ),
				'lessons' => array_map( array( __CLASS__, 'lesson_from_file' ), $children ),
			);
		}

		if ( $intro ) {
			array_unshift(
				$structure['modules'],
				array(
					'id'      => self::uid(),
					'title'   => $structure['modules'] ? 'Introduction' : 'Contenu de la formation',
					'lessons' => $intro,
				)
			);
		}
		return $structure;
	}

	private static function lesson_from_file( $file ) {
		$duration = '';
		if ( ! empty( $file['videoMediaMetadata']['durationMillis'] ) ) {
			$seconds  = (int) round( $file['videoMediaMetadata']['durationMillis'] / 1000 );
			$duration = $seconds >= 3600 ? gmdate( 'G:i:s', $seconds ) : gmdate( 'i:s', $seconds );
			$duration = ltrim( $duration, '0' );
			$duration = 0 === strpos( $duration, ':' ) ? '0' . $duration : $duration;
		}
		return array(
			'id'           => self::uid(),
			'title'        => self::clean_title( $file['name'] ),
			'type'         => self::type_from_mime( $file['mimeType'], $file['name'] ),
			'url'          => self::file_url( $file ),
			'duration'     => $duration,
			'description'  => '',
			'downloadable' => true,
		);
	}

	private static function file_url( $file ) {
		$mime = $file['mimeType'];
		$id   = $file['id'];
		if ( self::FOLDER_MIME === $mime ) {
			return "https://drive.google.com/drive/folders/{$id}";
		}
		$google = array(
			'application/vnd.google-apps.document'     => 'document',
			'application/vnd.google-apps.spreadsheet'  => 'spreadsheets',
			'application/vnd.google-apps.presentation' => 'presentation',
		);
		if ( isset( $google[ $mime ] ) ) {
			return "https://docs.google.com/{$google[ $mime ]}/d/{$id}/edit";
		}
		return "https://drive.google.com/file/d/{$id}/view";
	}

	/**
	 * « 01 - Les bases.mp4 » devient « Les bases ».
	 */
	public static function clean_title( $name ) {
		$title = preg_replace( '~\.[a-z0-9]{2,4}$~i', '', $name );
		$title = preg_replace( '~^\s*\d+\s*[\.\-_)]+\s*~', '', $title );
		$title = trim( str_replace( '_', ' ', $title ) );
		return '' === $title ? $name : $title;
	}

	public static function uid() {
		return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
	}
}

<?php
/**
 * ZIP import: unpack an exported site folder (index.html + css/js/images/
 * fonts) into a page directory, keeping its relative structure.
 *
 * @package HTMLPP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe ZIP extraction for page bundles.
 */
class HTMLPP_Zip {

	/**
	 * Extensions that may be unpacked from a bundle. Everything else is
	 * skipped (and reported), never written.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$extensions = array(
			'html',
			'htm',
			'css',
			'js',
			'mjs',
			'json',
			'map',
			'txt',
			'md',
			'xml',
			'webmanifest',
			'vtt',
			'png',
			'jpg',
			'jpeg',
			'gif',
			'svg',
			'webp',
			'avif',
			'ico',
			'bmp',
			'woff',
			'woff2',
			'ttf',
			'otf',
			'eot',
			'mp4',
			'webm',
			'ogv',
			'mp3',
			'ogg',
			'wav',
			'm4a',
			'pdf',
			'wasm',
		);

		/**
		 * Filter the file extensions that may be unpacked from a ZIP bundle.
		 *
		 * @param string[] $extensions Lowercase extensions.
		 */
		$extensions = (array) apply_filters( 'htmlpp_zip_allowed_extensions', $extensions );

		// Never allow anything the renderer refuses to serve, whatever a filter says.
		$safe = array();
		foreach ( array_map( 'strtolower', array_map( 'strval', $extensions ) ) as $ext ) {
			if ( '' !== $ext && ! HTMLPP_Renderer::is_blocked_extension( $ext ) ) {
				$safe[] = $ext;
			}
		}
		return array_values( array_unique( $safe ) );
	}

	/**
	 * Maximum total uncompressed size of a bundle.
	 *
	 * @return int Bytes.
	 */
	public static function max_total_bytes() {
		/**
		 * Filter the maximum uncompressed size of an imported ZIP bundle.
		 *
		 * @param int $bytes Default 256 MB.
		 */
		return (int) apply_filters( 'htmlpp_zip_max_bytes', 256 * MB_IN_BYTES );
	}

	/**
	 * Maximum number of files in a bundle.
	 *
	 * @return int
	 */
	public static function max_files() {
		/**
		 * Filter the maximum number of files in an imported ZIP bundle.
		 *
		 * @param int $count Default 2000.
		 */
		return (int) apply_filters( 'htmlpp_zip_max_files', 2000 );
	}

	/**
	 * Whether file content contains a PHP open tag (long, short-echo or
	 * short form). Applied to every entry regardless of its extension.
	 *
	 * @param string $content Bytes to inspect.
	 * @return bool
	 */
	public static function looks_like_php( $content ) {
		return (bool) preg_match( '/<\?(php\b|=|\s)/i', (string) $content );
	}

	/**
	 * Inspect and unpack a bundle.
	 *
	 * Every entry is normalized and validated (no absolute paths, no "..",
	 * no dotfiles, no __MACOSX, whitelisted extension with no executable
	 * intermediate extension, size and count caps); a common top-level
	 * folder is stripped; any entry containing a PHP open tag is refused.
	 * Entries are streamed to disk under a running byte cap, so a bundle
	 * whose central directory lies about sizes cannot fill the disk. All
	 * files except the page's index are written under $dest; the index HTML
	 * is returned for the caller to sanitize and write.
	 *
	 * @param string $zip_path Absolute path to the .zip.
	 * @param string $dest     Absolute page directory (must exist).
	 * @return array{ok:bool,error:string,index_html:string,written:string[],skipped:string[]}
	 */
	public static function import( $zip_path, $dest ) {
		$result = array(
			'ok'         => false,
			'error'      => '',
			'index_html' => '',
			'written'    => array(),
			'skipped'    => array(),
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = __( 'This server’s PHP has no ZipArchive extension, so ZIP bundles cannot be imported. Upload the HTML file and its assets separately instead.', 'html-page-publisher' );
			return $result;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$result['error'] = __( 'The ZIP file could not be opened. It may be corrupt or not a ZIP archive.', 'html-page-publisher' );
			return $result;
		}

		$allowed   = self::allowed_extensions();
		$max_bytes = self::max_total_bytes();
		$max_files = self::max_files();
		$entries   = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$original = (string) $stat['name'];
			$name     = str_replace( '\\', '/', $original );
			if ( '' === $name || '/' === substr( $name, -1 ) ) {
				continue; // Directory entry.
			}

			$reason = self::entry_problem( $name, $allowed );
			if ( '' !== $reason ) {
				if ( 'hidden' !== $reason ) {
					$result['skipped'][] = $name . ' (' . $reason . ')';
				}
				continue;
			}

			$entries[ $i ] = array(
				'original' => $original,
				'name'     => $name,
			);
			if ( count( $entries ) > $max_files ) {
				$zip->close();
				$result['error'] = sprintf(
					/* translators: %d: file-count limit */
					__( 'The bundle contains more than %d files.', 'html-page-publisher' ),
					$max_files
				);
				return $result;
			}
		}

		if ( empty( $entries ) ) {
			$zip->close();
			$result['error'] = __( 'The ZIP contains no usable files.', 'html-page-publisher' );
			return $result;
		}

		// Strip a single common top-level folder ("my-site/index.html" -> "index.html").
		$names  = array_column( $entries, 'name' );
		$prefix = self::common_prefix( $names );
		foreach ( $entries as $i => $entry ) {
			$entries[ $i ]['rel'] = '' === $prefix ? $entry['name'] : substr( $entry['name'], strlen( $prefix ) );
		}

		// Keep the archive indices as keys: array_column() would renumber them
		// and the extraction loop below matches on the archive index.
		$rels = array();
		foreach ( $entries as $i => $entry ) {
			$rels[ $i ] = $entry['rel'];
		}

		$index_key = self::find_index( $rels );
		if ( null === $index_key ) {
			$zip->close();
			$result['error'] = __( 'No index.html was found at the top level of the ZIP. Put the page’s HTML file at the root (or in a single folder) and name it index.html.', 'html-page-publisher' );
			return $result;
		}

		$budget = $max_bytes;

		foreach ( $entries as $i => $entry ) {
			$rel    = $entry['rel'];
			$target = $i === $index_key ? '' : trailingslashit( $dest ) . $rel;

			if ( '' !== $target ) {
				$dir = dirname( $target );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
					$result['skipped'][] = $rel . ' (' . __( 'folder could not be created', 'html-page-publisher' ) . ')';
					continue;
				}
			}

			$outcome = self::extract_entry( $zip, $entry['original'], $target, $budget );

			if ( 'too-big' === $outcome['status'] ) {
				$zip->close();
				$result['error'] = sprintf(
					/* translators: %s: size limit, e.g. 256 MB */
					__( 'The bundle unpacks to more than %s. Reduce the size or raise the htmlpp_zip_max_bytes filter.', 'html-page-publisher' ),
					size_format( $max_bytes )
				);
				return $result;
			}
			if ( 'php' === $outcome['status'] ) {
				$result['skipped'][] = $rel . ' (' . __( 'contains PHP code', 'html-page-publisher' ) . ')';
				continue;
			}
			if ( 'ok' !== $outcome['status'] ) {
				$result['skipped'][] = $rel . ' (' . __( 'could not be read', 'html-page-publisher' ) . ')';
				continue;
			}

			$budget -= $outcome['bytes'];

			if ( $i === $index_key ) {
				$result['index_html'] = $outcome['content'];
			} else {
				$result['written'][] = $rel;
			}
		}

		$zip->close();

		$result['ok'] = true;
		return $result;
	}

	/**
	 * Stream one entry to disk (or into memory when $target is '') while
	 * enforcing the remaining byte budget and scanning for PHP open tags.
	 *
	 * @param ZipArchive $zip      Open archive.
	 * @param string     $original Entry name as stored in the archive.
	 * @param string     $target   Destination path, or '' to return the content.
	 * @param int        $budget   Bytes still allowed for this import.
	 * @return array{status:string,bytes:int,content:string} status is ok|too-big|php|error.
	 */
	private static function extract_entry( $zip, $original, $target, $budget ) {
		$stream = $zip->getStream( $original );
		if ( ! $stream ) {
			return array(
				'status'  => 'error',
				'bytes'   => 0,
				'content' => '',
			);
		}

		$handle = '';
		if ( '' !== $target ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming write inside the plugin's own uploads directory.
			$handle = fopen( $target, 'wb' );
			if ( ! $handle ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return array(
					'status'  => 'error',
					'bytes'   => 0,
					'content' => '',
				);
			}
		}

		$bytes   = 0;
		$content = '';
		$tail    = '';
		$status  = 'ok';

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$bytes += strlen( $chunk );
			if ( $bytes > $budget ) {
				$status = 'too-big';
				break;
			}
			// Keep a few bytes of overlap so a tag split across chunks is seen.
			if ( self::looks_like_php( $tail . $chunk ) ) {
				$status = 'php';
				break;
			}
			$tail = substr( $chunk, -8 );

			if ( '' !== $target ) {
				fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			} else {
				$content .= $chunk;
			}
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( '' !== $target ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( 'ok' === $status ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
				@chmod( $target, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			} else {
				wp_delete_file( $target );
			}
		}

		return array(
			'status'  => $status,
			'bytes'   => $bytes,
			'content' => $content,
		);
	}

	/**
	 * Why an entry name must be skipped, or '' if it is acceptable.
	 *
	 * @param string   $name    Normalized entry name.
	 * @param string[] $allowed Allowed extensions.
	 * @return string 'hidden' (silently skipped) or a human reason.
	 */
	private static function entry_problem( $name, array $allowed ) {
		if ( false !== strpos( $name, "\0" ) || preg_match( '/[\x00-\x1f]/', $name ) ) {
			return __( 'invalid name', 'html-page-publisher' );
		}
		if ( 0 === strpos( $name, '__MACOSX/' ) ) {
			return 'hidden';
		}
		if ( '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
			return __( 'absolute path', 'html-page-publisher' );
		}
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '..' === $segment ) {
				return __( 'unsafe path', 'html-page-publisher' );
			}
			if ( '.' === $segment[0] ) {
				return 'hidden';
			}
		}

		$base = strtolower( basename( $name ) );
		$ext  = (string) pathinfo( $base, PATHINFO_EXTENSION );
		if ( '' === $ext || ! in_array( $ext, $allowed, true ) || HTMLPP_Renderer::has_blocked_extension_part( $base ) ) {
			return __( 'file type not allowed', 'html-page-publisher' );
		}

		return '';
	}

	/**
	 * Common leading folder shared by every entry ("folder/"), or ''.
	 *
	 * @param string[] $entries Entry names.
	 * @return string
	 */
	private static function common_prefix( array $entries ) {
		$prefix = null;
		foreach ( $entries as $name ) {
			$slash = strpos( $name, '/' );
			$head  = false === $slash ? '' : substr( $name, 0, $slash + 1 );
			if ( '' === $head ) {
				return '';
			}
			if ( null === $prefix ) {
				$prefix = $head;
			} elseif ( $prefix !== $head ) {
				return '';
			}
		}
		return null === $prefix ? '' : $prefix;
	}

	/**
	 * Entry index of the page's HTML: index.html, then index.htm, then the
	 * only top-level .html file.
	 *
	 * @param string[] $entries Relative names keyed by zip index.
	 * @return int|null
	 */
	private static function find_index( array $entries ) {
		foreach ( array( 'index.html', 'index.htm' ) as $candidate ) {
			foreach ( $entries as $i => $name ) {
				if ( 0 === strcasecmp( $name, $candidate ) ) {
					return $i;
				}
			}
		}
		$html = array();
		foreach ( $entries as $i => $name ) {
			if ( false === strpos( $name, '/' ) && preg_match( '/\.html?$/i', $name ) ) {
				$html[] = $i;
			}
		}
		return 1 === count( $html ) ? $html[0] : null;
	}
}

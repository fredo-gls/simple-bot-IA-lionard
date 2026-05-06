<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LSC_Knowledge {

	const TABLE_SUFFIX   = 'lsc_knowledge';
	const MAX_KB_CHARS   = 4000;
	const SMALL_KB_LIMIT = 20;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function create_table() {
		if ( get_option( 'lsc_kb_db_version' ) === '1' ) {
			return;
		}

		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
			`id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`type`       VARCHAR(20)     NOT NULL DEFAULT 'text',
			`title`      VARCHAR(500)    NOT NULL DEFAULT '',
			`content`    LONGTEXT        NOT NULL,
			`source_url` VARCHAR(2083)   NOT NULL DEFAULT '',
			`active`     TINYINT(1)      NOT NULL DEFAULT 1,
			`created_at` DATETIME        NOT NULL,
			`updated_at` DATETIME        NOT NULL,
			PRIMARY KEY (`id`),
			KEY `idx_active` (`active`)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'lsc_kb_db_version', '1' );
	}

	public static function insert( array $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		return $wpdb->insert(
			self::table_name(),
			array(
				'type'       => sanitize_text_field( (string) ( $data['type'] ?? 'text' ) ),
				'title'      => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'content'    => sanitize_textarea_field( (string) ( $data['content'] ?? '' ) ),
				'source_url' => esc_url_raw( (string) ( $data['source_url'] ?? '' ) ),
				'active'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function update( int $id, array $data ) {
		global $wpdb;
		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'type', $data ) ) {
			$fields['type'] = sanitize_text_field( (string) $data['type'] );
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = sanitize_text_field( (string) $data['title'] );
			$formats[]       = '%s';
		}
		if ( array_key_exists( 'content', $data ) ) {
			$fields['content'] = sanitize_textarea_field( (string) $data['content'] );
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'active', $data ) ) {
			$fields['active'] = (int) (bool) $data['active'];
			$formats[]        = '%d';
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		return $wpdb->update(
			self::table_name(),
			$fields,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	public static function delete( int $id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function delete_all() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	/**
	 * Search published WordPress posts/pages relevant to the user's message.
	 * Returns a formatted context string with titles, URLs, and excerpts.
	 *
	 * Scoring (higher = more relevant):
	 *   + type_weight * 2  (page=3, post=1, filterable via lsc_site_search_type_weights)
	 *   + title_hits * 3   (each keyword found in title)
	 *   + content_hits     (each keyword found in content)
	 *   + position_score   (WP_Query rank: earlier = better)
	 */
	public static function search_site_content( string $message, int $max_results = 4 ): string {
		if ( '' === trim( $message ) ) {
			return '';
		}

		$type_weights = apply_filters(
			'lsc_site_search_type_weights',
			array(
				'page' => 3,
				'post' => 1,
			)
		);
		$type_weights = is_array( $type_weights ) ? $type_weights : array( 'page' => 3, 'post' => 1 );

		$query = new WP_Query(
			array(
				's'              => $message,
				'post_type'      => array_keys( $type_weights ),
				'post_status'    => 'publish',
				'posts_per_page' => $max_results * 4,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		if ( ! $query->have_posts() ) {
			return '';
		}

		// Extract search words once for scoring
		$words = preg_split( '/[\s\-_]+/u', mb_strtolower( $message, 'UTF-8' ) );
		$words = array_filter(
			is_array( $words ) ? $words : array(),
			static function ( $w ) {
				return mb_strlen( (string) $w, 'UTF-8' ) >= 3;
			}
		);
		$words = array_values( array_unique( $words ) );

		$scored = array();
		foreach ( array_values( $query->posts ) as $position => $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$type_weight   = (int) ( $type_weights[ $post->post_type ] ?? 1 );
			$title_lower   = mb_strtolower( (string) $post->post_title, 'UTF-8' );
			$content_lower = mb_strtolower( wp_strip_all_tags( (string) $post->post_content ), 'UTF-8' );

			$title_hits   = 0;
			$content_hits = 0;
			foreach ( $words as $word ) {
				if ( false !== mb_strpos( $title_lower, (string) $word, 0, 'UTF-8' ) ) {
					$title_hits++;
				}
				if ( false !== mb_strpos( $content_lower, (string) $word, 0, 'UTF-8' ) ) {
					$content_hits++;
				}
			}

			$position_score = ( $max_results * 4 ) - $position;
			$score          = ( $type_weight * 2 ) + ( $title_hits * 3 ) + $content_hits + $position_score;

			$scored[] = array(
				'post'  => $post,
				'score' => $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		$parts       = array();
		$total_chars = 0;
		$max_chars   = 2000;

		foreach ( array_slice( $scored, 0, $max_results ) as $item ) {
			$post    = $item['post'];
			$title   = wp_strip_all_tags( (string) $post->post_title );
			$url     = (string) get_permalink( $post );
			$excerpt = self::get_post_excerpt( $post );

			$block     = "Titre: {$title}\nURL: {$url}";
			if ( '' !== $excerpt ) {
				$block .= "\nResume: {$excerpt}";
			}

			$block_len = mb_strlen( $block, 'UTF-8' );
			if ( $total_chars + $block_len > $max_chars ) {
				break;
			}

			$parts[]      = $block;
			$total_chars += $block_len;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return "Pages du site pertinentes (utiliser uniquement les URL exactes ci-dessous) :\n\n" . implode( "\n\n---\n\n", $parts );
	}

	private static function get_post_excerpt( WP_Post $post ): string {
		$raw = '' !== trim( (string) $post->post_excerpt )
			? (string) $post->post_excerpt
			: (string) $post->post_content;

		// Strip shortcodes, HTML, then decode entities
		$text = (string) preg_replace( '/\[[^\]]+\]/', ' ', $raw );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		return wp_trim_words( $text, 35, '...' );
	}

	public static function toggle_active( int $id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET `active` = 1 - `active`, `updated_at` = %s WHERE `id` = %d",
			current_time( 'mysql' ),
			$id
		) );
	}

	public static function get_by_id( int $id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1",
			$id
		), ARRAY_A );
	}

	public static function get_entries( array $args = array() ) {
		global $wpdb;
		$table = self::table_name();
		$args  = wp_parse_args( $args, array( 'active_only' => false, 'limit' => 200, 'offset' => 0 ) );
		$where = $args['active_only'] ? 'WHERE `active` = 1' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` {$where} ORDER BY `created_at` DESC LIMIT %d OFFSET %d",
			absint( $args['limit'] ),
			absint( $args['offset'] )
		), ARRAY_A );
	}

	public static function count( bool $active_only = false ) {
		global $wpdb;
		$table = self::table_name();
		$where = $active_only ? 'WHERE `active` = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` {$where}" );
	}

	/**
	 * Search for entries relevant to the user's message.
	 * For small KBs (<= SMALL_KB_LIMIT active entries), returns all active entries.
	 * For larger KBs, does a keyword search across title and content.
	 */
	public static function search( string $message, int $limit = 8 ): array {
		global $wpdb;
		$table        = self::table_name();
		$total_active = self::count( true );

		if ( 0 === $total_active ) {
			return array();
		}

		// Small KB: inject all active entries
		if ( $total_active <= self::SMALL_KB_LIMIT ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT `id`, `type`, `title`, `content` FROM `{$table}` WHERE `active` = 1 ORDER BY `id` ASC LIMIT %d",
				$limit
			), ARRAY_A );
		}

		// Extract meaningful words (>= 3 chars)
		$words = preg_split( '/[\s\-_]+/u', mb_strtolower( $message, 'UTF-8' ) );
		$words = array_filter(
			is_array( $words ) ? $words : array(),
			static function ( $w ) {
				return mb_strlen( (string) $w, 'UTF-8' ) >= 3;
			}
		);
		$words = array_values( array_unique( array_slice( array_values( $words ), 0, 8 ) ) );

		if ( empty( $words ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT `id`, `type`, `title`, `content` FROM `{$table}` WHERE `active` = 1 ORDER BY `id` ASC LIMIT %d",
				min( $limit, 5 )
			), ARRAY_A );
		}

		$conditions = array();
		$values     = array();
		foreach ( $words as $word ) {
			$like         = '%' . $wpdb->esc_like( (string) $word ) . '%';
			$conditions[] = '(`title` LIKE %s OR `content` LIKE %s)';
			$values[]     = $like;
			$values[]     = $like;
		}

		$where    = '`active` = 1 AND (' . implode( ' OR ', $conditions ) . ')';
		$values[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT `id`, `type`, `title`, `content` FROM `{$table}` WHERE {$where} ORDER BY `id` ASC LIMIT %d",
				...$values
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Format retrieved entries as a prompt context block.
	 */
	public static function format_for_prompt( array $entries ): string {
		if ( empty( $entries ) ) {
			return '';
		}

		$parts       = array();
		$total_chars = 0;

		foreach ( $entries as $entry ) {
			$type    = (string) ( $entry['type'] ?? 'text' );
			$title   = trim( (string) ( $entry['title'] ?? '' ) );
			$content = trim( (string) ( $entry['content'] ?? '' ) );

			if ( '' === $content ) {
				continue;
			}

			if ( 'faq' === $type ) {
				$block = $title ? "Q: {$title}\nR: {$content}" : $content;
			} else {
				$block = $title ? "== {$title} ==\n{$content}" : $content;
			}

			$block_len = mb_strlen( $block, 'UTF-8' );
			if ( $total_chars + $block_len > self::MAX_KB_CHARS ) {
				break;
			}

			$parts[]      = $block;
			$total_chars += $block_len;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return "Informations de reference a utiliser si pertinentes:\n\n" . implode( "\n\n---\n\n", $parts );
	}

	/**
	 * Fetch and extract plain text from a URL (for URL-type entries).
	 */
	public static function fetch_url_content( string $url ): string {
		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (compatible; LionardBot/1.0)',
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return '';
		}

		// Remove script and style blocks before stripping tags
		$body = (string) preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $body );
		$body = (string) preg_replace( '/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $body );

		$text = wp_strip_all_tags( $body );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );
		$text = trim( $text );

		if ( mb_strlen( $text, 'UTF-8' ) > 8000 ) {
			$text = mb_substr( $text, 0, 8000, 'UTF-8' ) . '...';
		}

		return $text;
	}

}


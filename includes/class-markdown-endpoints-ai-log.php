<?php
/**
 * The agent request log, daily totals and rate limiting.
 *
 * Three small tables:
 * - {prefix}mdai_log: recent requests (time, page, agent, status), kept for
 *   the retention period and capped per day, so a flood can't fill the disk.
 *   No IP addresses.
 * - {prefix}mdai_daily: one row per day, page, endpoint and agent with a
 *   count. Every request is counted here, past the cap too.
 * - {prefix}mdai_rate: per-minute request counters for rate limiting, keyed
 *   by a salted hash of the IP, deleted within minutes.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Request log.
 */
final class Markdown_Endpoints_AI_Log {

	/**
	 * Most requests written to the detailed log per day.
	 */
	const DAILY_CAP = 5000;

	/**
	 * Days of daily totals kept.
	 */
	const DAILY_KEEP = 400;

	/**
	 * Whether the tables exist, once checked.
	 *
	 * @var bool|null
	 */
	private static $installed = null;

	/**
	 * Detailed log table.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'mdai_log';
	}

	/**
	 * Daily totals table.
	 *
	 * @return string
	 */
	public static function daily_table() {
		global $wpdb;

		return $wpdb->prefix . 'mdai_daily';
	}

	/**
	 * Rate-limit counters table.
	 *
	 * @return string
	 */
	public static function rate_table() {
		global $wpdb;

		return $wpdb->prefix . 'mdai_rate';
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = self::log_table();
		$daily   = self::daily_table();
		$rate    = self::rate_table();

		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hit_time int(10) unsigned NOT NULL DEFAULT 0,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  endpoint varchar(12) NOT NULL DEFAULT '',
  agent varchar(64) NOT NULL DEFAULT '',
  grp varchar(8) NOT NULL DEFAULT '',
  status smallint(5) unsigned NOT NULL DEFAULT 0,
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  user_agent varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY hit_time (hit_time),
  KEY post_time (post_id,hit_time)
) {$charset};
CREATE TABLE {$daily} (
  day date NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  endpoint varchar(12) NOT NULL DEFAULT '',
  agent varchar(64) NOT NULL DEFAULT '',
  grp varchar(8) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,post_id,endpoint,agent),
  KEY grp_day (grp,day)
) {$charset};
CREATE TABLE {$rate} (
  bucket char(32) NOT NULL,
  expires int(10) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket),
  KEY expires (expires)
) {$charset};"
		);

		self::$installed = null;
	}

	/**
	 * Whether the tables exist.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		if ( null === self::$installed ) {
			self::$installed = Markdown_Endpoints_AI_Plugin::DB_VERSION === get_option( Markdown_Endpoints_AI_Plugin::DB_OPTION );
		}

		return self::$installed;
	}

	/**
	 * Count one request, and log it in detail while under the day's cap.
	 *
	 * @param array $hit post_id, endpoint, agent, grp, status, bytes, user_agent.
	 * @return void
	 */
	public static function record( array $hit ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return;
		}

		$daily = self::daily_table();
		$day   = current_time( 'Y-m-d' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin tables.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (day, post_id, endpoint, agent, grp, hits) VALUES (%s, %d, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1",
				$day,
				(int) $hit['post_id'],
				(string) $hit['endpoint'],
				(string) $hit['agent'],
				(string) $hit['grp']
			)
		);

		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits), 0) FROM {$daily} WHERE day = %s", $day ) );
		// phpcs:enable

		if ( $today > self::DAILY_CAP ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log_table(),
			array(
				'hit_time'   => time(),
				'post_id'    => (int) $hit['post_id'],
				'endpoint'   => substr( (string) $hit['endpoint'], 0, 12 ),
				'agent'      => substr( (string) $hit['agent'], 0, 64 ),
				'grp'        => substr( (string) $hit['grp'], 0, 8 ),
				'status'     => (int) $hit['status'],
				'bytes'      => max( 0, (int) $hit['bytes'] ),
				'user_agent' => substr( self::printable( (string) $hit['user_agent'] ), 0, 255 ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Count a request from the current visitor, identified by user agent.
	 * Logged-in users checking their own pages aren't counted.
	 *
	 * @param string $endpoint md, accept, llms or sitemap.
	 * @param int    $post_id  Post ID (0 for llms.txt and the sitemap).
	 * @param int    $status   HTTP status.
	 * @param int    $bytes    Bytes sent.
	 * @return void
	 */
	public static function record_request( $endpoint, $post_id, $status, $bytes ) {
		if ( is_user_logged_in() || ! Markdown_Endpoints_AI_Plugin::instance()->get_setting( 'log' ) || ( 0 === (int) $post_id && Markdown_Endpoints_AI_Cache::is_internal_request( 0 ) ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to printable ASCII when stored.
		$agent      = Markdown_Endpoints_AI_Agents::detect( $user_agent, $endpoint );

		self::record(
			array(
				'post_id'    => (int) $post_id,
				'endpoint'   => $endpoint,
				'agent'      => $agent['id'],
				'grp'        => $agent['group'],
				'status'     => (int) $status,
				'bytes'      => (int) $bytes,
				'user_agent' => $user_agent,
			)
		);
	}

	/**
	 * Printable ASCII only (user agents are visitor-supplied).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function printable( $value ) {
		return trim( preg_replace( '/[^\x20-\x7e]/', '', $value ) );
	}

	// Rate limiting -----------------------------------------------------------

	/**
	 * Count a request against its IP's per-minute allowance.
	 *
	 * @param string $ip    Client IP.
	 * @param int    $limit Requests allowed per minute.
	 * @return int Seconds to wait when over the limit, else 0.
	 */
	public static function rate_limited( $ip, $limit ) {
		global $wpdb;

		if ( $limit <= 0 || '' === $ip || ! self::is_installed() ) {
			return 0;
		}

		$window = (int) floor( time() / 60 );
		$bucket = hash_hmac( 'md5', $ip . '|' . $window, wp_salt( 'auth' ) );
		$rate   = self::rate_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$rate} (bucket, expires, hits) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", $bucket, ( $window + 2 ) * 60 ) );
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT hits FROM {$rate} WHERE bucket = %s", $bucket ) );

		if ( 0 === wp_rand( 0, 199 ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$rate} WHERE expires < %d", time() ) );
		}
		// phpcs:enable

		return $hits > $limit ? max( 1, 60 - ( time() % 60 ) ) : 0;
	}

	// Reports -------------------------------------------------------------------

	/**
	 * First day (site time) of a period ending today.
	 *
	 * @param int $days Days.
	 * @return string Y-m-d.
	 */
	private static function since( $days ) {
		return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' 00:00:00' ) - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * Requests per group over a period.
	 *
	 * @param int $days Days.
	 * @return array<string,int>
	 */
	public static function totals( $days ) {
		global $wpdb;

		$out = array(
			Markdown_Endpoints_AI_Agents::AI    => 0,
			Markdown_Endpoints_AI_Agents::BOT   => 0,
			Markdown_Endpoints_AI_Agents::OTHER => 0,
		);

		if ( ! self::is_installed() ) {
			return $out;
		}

		$daily = self::daily_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT grp, SUM(hits) AS hits FROM {$daily} WHERE day >= %s GROUP BY grp", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['grp'] ] ) ) {
				$out[ $row['grp'] ] = (int) $row['hits'];
			}
		}

		return $out;
	}

	/**
	 * Requests per day and group, every day of the period present.
	 *
	 * @param int $days Days.
	 * @return array<string,array<string,int>> Y-m-d => group => hits.
	 */
	public static function by_day( $days ) {
		global $wpdb;

		$out   = array();
		$first = strtotime( self::since( $days ) . ' 00:00:00' );
		for ( $i = 0; $i < $days; $i++ ) {
			$out[ gmdate( 'Y-m-d', $first + $i * DAY_IN_SECONDS ) ] = array(
				Markdown_Endpoints_AI_Agents::AI    => 0,
				Markdown_Endpoints_AI_Agents::BOT   => 0,
				Markdown_Endpoints_AI_Agents::OTHER => 0,
			);
		}

		if ( ! self::is_installed() ) {
			return $out;
		}

		$daily = self::daily_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT day, grp, SUM(hits) AS hits FROM {$daily} WHERE day >= %s GROUP BY day, grp", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ][ $row['grp'] ] ) ) {
				$out[ $row['day'] ][ $row['grp'] ] = (int) $row['hits'];
			}
		}

		return $out;
	}

	/**
	 * The pages a group asks for most, with their top agent and last visit.
	 *
	 * @param int    $days  Days.
	 * @param string $group Group.
	 * @param int    $limit Rows.
	 * @return array<int,array{post_id: int, hits: int, agents: int, top: string, last: int}>
	 */
	public static function top_pages( $days, $group, $limit ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return array();
		}

		$daily = self::daily_table();
		$since = self::since( $days );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Plugin tables; IN() list built from integers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, SUM(hits) AS hits, COUNT(DISTINCT agent) AS agents FROM {$daily} WHERE day >= %s AND grp = %s AND post_id > 0 GROUP BY post_id ORDER BY hits DESC LIMIT %d",
				$since,
				$group,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ) );

		$agents = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, agent, SUM(hits) AS hits FROM {$daily} WHERE day >= %s AND grp = %s AND post_id IN ({$ids}) GROUP BY post_id, agent", $since, $group ),
			ARRAY_A
		);

		$last = $wpdb->get_results(
			$wpdb->prepare( 'SELECT post_id, MAX(hit_time) AS last FROM ' . self::log_table() . " WHERE grp = %s AND post_id IN ({$ids}) GROUP BY post_id", $group ),
			ARRAY_A
		);
		// phpcs:enable

		$top = array();
		foreach ( (array) $agents as $row ) {
			$id = (int) $row['post_id'];
			if ( ! isset( $top[ $id ] ) || (int) $row['hits'] > $top[ $id ][1] ) {
				$top[ $id ] = array( $row['agent'], (int) $row['hits'] );
			}
		}

		$seen = array();
		foreach ( (array) $last as $row ) {
			$seen[ (int) $row['post_id'] ] = (int) $row['last'];
		}

		$out = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['post_id'];
			$out[] = array(
				'post_id' => $id,
				'hits'    => (int) $row['hits'],
				'agents'  => (int) $row['agents'],
				'top'     => isset( $top[ $id ] ) ? $top[ $id ][0] : '',
				'last'    => isset( $seen[ $id ] ) ? $seen[ $id ] : 0,
			);
		}

		return $out;
	}

	/**
	 * Requests per agent over a period.
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array<int,array{agent: string, grp: string, hits: int, pages: int, last: string}>
	 */
	public static function agents( $days, $limit = 50 ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return array();
		}

		$daily = self::daily_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT agent, grp, SUM(hits) AS hits, COUNT(DISTINCT post_id) AS pages, MAX(day) AS last FROM {$daily} WHERE day >= %s GROUP BY agent, grp ORDER BY hits DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Requests per endpoint over a period.
	 *
	 * @param int $days Days.
	 * @return array<string,int>
	 */
	public static function endpoints( $days ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return array();
		}

		$daily = self::daily_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT endpoint, SUM(hits) AS hits FROM {$daily} WHERE day >= %s GROUP BY endpoint", self::since( $days ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['endpoint'] ] = (int) $row['hits'];
		}

		return $out;
	}

	/**
	 * A page of the detailed log, newest first.
	 *
	 * @param string $group    Group filter ('' for all).
	 * @param int    $page     Page number.
	 * @param int    $per_page Rows per page.
	 * @return array{rows: array, total: int}
	 */
	public static function recent( $group, $page, $per_page ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}

		$log    = self::log_table();
		$where  = '' !== $group ? $wpdb->prepare( 'WHERE grp = %s', $group ) : '';
		$offset = max( 0, ( max( 1, (int) $page ) - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table; $where is prepared above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log} {$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Drop detailed rows past the retention period, old daily totals and
	 * expired rate counters.
	 *
	 * @param int $retention Days of detailed log to keep.
	 * @return void
	 */
	public static function prune( $retention ) {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return;
		}

		$log   = self::log_table();
		$daily = self::daily_table();
		$rate  = self::rate_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin tables.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE hit_time < %d", time() - max( 1, (int) $retention ) * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day < %s", self::since( self::DAILY_KEEP ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rate} WHERE expires < %d", time() ) );
		// phpcs:enable
	}

	/**
	 * Empty the log and the daily totals.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		if ( ! self::is_installed() ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed plugin table names, no input.
		$wpdb->query( 'DELETE FROM ' . self::log_table() );
		$wpdb->query( 'DELETE FROM ' . self::daily_table() );
		// phpcs:enable
	}

	// Client IP -----------------------------------------------------------------

	/**
	 * Cloudflare's published ranges (cloudflare.com/ips).
	 *
	 * @var string[]
	 */
	private static $cloudflare = array( '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32' );

	/**
	 * The visitor's IP. Forwarding headers can be faked by anyone, so only
	 * Cloudflare's CF-Connecting-IP is believed, and only when the request
	 * really comes from a Cloudflare address.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( '' !== $ip && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $ip, self::$cloudflare ) ) {
			$cf = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				$ip = $cf;
			}
		}

		/**
		 * Filter the client IP used for rate limiting (for sites behind a
		 * proxy other than Cloudflare).
		 *
		 * @param string $ip IP address.
		 */
		$filtered = (string) apply_filters( 'markdown_endpoints_ai_client_ip', $ip );

		return filter_var( $filtered, FILTER_VALIDATE_IP ) ? $filtered : $ip;
	}

	/**
	 * Whether an IP is inside any of the CIDR ranges.
	 *
	 * @param string   $ip     IP.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	private static function in_ranges( $ip, array $ranges ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			list( $subnet, $bits ) = explode( '/', $range );
			$net                   = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}

			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $packed, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
				continue;
			}

			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}

			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $packed[ $bytes ] & $mask ) === ( $net[ $bytes ] & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}

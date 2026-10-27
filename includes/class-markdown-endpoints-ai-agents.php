<?php
/**
 * Who's asking: AI crawlers and agents, regular bots, and everything else.
 *
 * When Content Powered's AI Crawler Controls plugin is active, its crawler
 * list (and any crawlers the site added there) is used first, so both
 * plugins name visitors the same way. This plugin's own list fills in the
 * rest, including regular search and SEO bots so they can be counted apart.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * User-agent catalog and matching.
 */
final class Markdown_Endpoints_AI_Agents {

	/**
	 * Groups.
	 */
	const AI    = 'ai';
	const BOT   = 'bot';
	const OTHER = 'other';

	/**
	 * Catalog, once built.
	 *
	 * @var array<string,array>|null
	 */
	private static $catalog = null;

	/**
	 * Matchers per group, once built.
	 *
	 * @var array<string,array{pattern: string, map: array<string,string>}>
	 */
	private static $matchers = array();

	/**
	 * Every known agent: id => name, operator, group, user-agent substrings.
	 * Nothing is translated: names are product names.
	 *
	 * @return array<string,array{name: string, operator: string, group: string, match: string[]}>
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}

		$list = array(
			// AI crawlers and agents.
			'gptbot'                => array( 'GPTBot', 'OpenAI', self::AI, array( 'GPTBot' ) ),
			'oai-searchbot'         => array( 'OAI-SearchBot', 'OpenAI', self::AI, array( 'OAI-SearchBot' ) ),
			'chatgpt-user'          => array( 'ChatGPT-User', 'OpenAI', self::AI, array( 'ChatGPT-User' ) ),
			'claudebot'             => array( 'ClaudeBot', 'Anthropic', self::AI, array( 'ClaudeBot', 'anthropic-ai' ) ),
			'claude-searchbot'      => array( 'Claude-SearchBot', 'Anthropic', self::AI, array( 'Claude-SearchBot' ) ),
			'claude-user'           => array( 'Claude-User', 'Anthropic', self::AI, array( 'Claude-User' ) ),
			'claude-code'           => array( 'Claude Code', 'Anthropic', self::AI, array( 'claude-code' ) ),
			'perplexitybot'         => array( 'PerplexityBot', 'Perplexity', self::AI, array( 'PerplexityBot' ) ),
			'perplexity-user'       => array( 'Perplexity-User', 'Perplexity', self::AI, array( 'Perplexity-User' ) ),
			'googleother'           => array( 'GoogleOther', 'Google', self::AI, array( 'GoogleOther' ) ),
			'google-cloudvertexbot' => array( 'Google-CloudVertexBot', 'Google', self::AI, array( 'Google-CloudVertexBot' ) ),
			'gemini-deep-research'  => array( 'Gemini Deep Research', 'Google', self::AI, array( 'Gemini-Deep-Research' ) ),
			'google-notebooklm'     => array( 'NotebookLM', 'Google', self::AI, array( 'Google-NotebookLM' ) ),
			'meta-externalagent'    => array( 'Meta-ExternalAgent', 'Meta', self::AI, array( 'meta-externalagent' ) ),
			'meta-externalfetcher'  => array( 'Meta-ExternalFetcher', 'Meta', self::AI, array( 'meta-externalfetcher' ) ),
			'facebookbot'           => array( 'FacebookBot', 'Meta', self::AI, array( 'FacebookBot' ) ),
			'ccbot'                 => array( 'CCBot', 'Common Crawl', self::AI, array( 'CCBot' ) ),
			'bytespider'            => array( 'Bytespider', 'ByteDance', self::AI, array( 'Bytespider' ) ),
			'amazonbot'             => array( 'Amazonbot', 'Amazon', self::AI, array( 'Amazonbot' ) ),
			'cohere'                => array( 'cohere-ai', 'Cohere', self::AI, array( 'cohere-ai', 'cohere-training-data-crawler' ) ),
			'mistralai-user'        => array( 'MistralAI-User', 'Mistral AI', self::AI, array( 'MistralAI-User' ) ),
			'duckassistbot'         => array( 'DuckAssistBot', 'DuckDuckGo', self::AI, array( 'DuckAssistBot' ) ),
			'youbot'                => array( 'YouBot', 'You.com', self::AI, array( 'YouBot' ) ),
			'ai2bot'                => array( 'AI2Bot', 'Ai2', self::AI, array( 'AI2Bot' ) ),
			'diffbot'               => array( 'Diffbot', 'Diffbot', self::AI, array( 'Diffbot' ) ),
			'pangubot'              => array( 'PanguBot', 'Huawei', self::AI, array( 'PanguBot' ) ),
			'omgili'                => array( 'omgili', 'Webz.io', self::AI, array( 'omgili' ) ),
			'imagesiftbot'          => array( 'ImagesiftBot', 'Hive', self::AI, array( 'ImagesiftBot' ) ),
			'timpibot'              => array( 'Timpibot', 'Timpi', self::AI, array( 'Timpibot' ) ),
			'icc-crawler'           => array( 'ICC-Crawler', 'NICT', self::AI, array( 'ICC-Crawler' ) ),
			'phindbot'              => array( 'PhindBot', 'Phind', self::AI, array( 'PhindBot' ) ),
			'firecrawl'             => array( 'Firecrawl', 'Firecrawl', self::AI, array( 'FirecrawlAgent' ) ),

			// Search engines, SEO tools, link previews and monitors.
			'googlebot'             => array( 'Googlebot', 'Google', self::BOT, array( 'Googlebot', 'Google-InspectionTool', 'Storebot-Google' ) ),
			'bingbot'               => array( 'Bingbot', 'Microsoft', self::BOT, array( 'bingbot', 'BingPreview' ) ),
			'applebot'              => array( 'Applebot', 'Apple', self::BOT, array( 'Applebot' ) ),
			'duckduckbot'           => array( 'DuckDuckBot', 'DuckDuckGo', self::BOT, array( 'DuckDuckBot' ) ),
			'yandexbot'             => array( 'YandexBot', 'Yandex', self::BOT, array( 'YandexBot', 'YandexAdditional' ) ),
			'baiduspider'           => array( 'Baiduspider', 'Baidu', self::BOT, array( 'Baiduspider' ) ),
			'petalbot'              => array( 'PetalBot', 'Huawei', self::BOT, array( 'PetalBot' ) ),
			'seznambot'             => array( 'SeznamBot', 'Seznam', self::BOT, array( 'SeznamBot' ) ),
			'yeti'                  => array( 'Yeti', 'Naver', self::BOT, array( 'Yeti/' ) ),
			'ahrefsbot'             => array( 'AhrefsBot', 'Ahrefs', self::BOT, array( 'AhrefsBot', 'AhrefsSiteAudit' ) ),
			'semrushbot'            => array( 'SemrushBot', 'Semrush', self::BOT, array( 'SemrushBot', 'SiteAuditBot' ) ),
			'mj12bot'               => array( 'MJ12bot', 'Majestic', self::BOT, array( 'MJ12bot' ) ),
			'dotbot'                => array( 'DotBot', 'Moz', self::BOT, array( 'DotBot', 'rogerbot' ) ),
			'screaming-frog'        => array( 'Screaming Frog', 'Screaming Frog', self::BOT, array( 'Screaming Frog SEO Spider' ) ),
			'facebookexternalhit'   => array( 'Facebook link preview', 'Meta', self::BOT, array( 'facebookexternalhit' ) ),
			'twitterbot'            => array( 'Twitterbot', 'X', self::BOT, array( 'Twitterbot' ) ),
			'linkedinbot'           => array( 'LinkedInBot', 'LinkedIn', self::BOT, array( 'LinkedInBot' ) ),
			'slackbot'              => array( 'Slackbot', 'Slack', self::BOT, array( 'Slackbot' ) ),
			'discordbot'            => array( 'Discordbot', 'Discord', self::BOT, array( 'Discordbot' ) ),
			'telegrambot'           => array( 'TelegramBot', 'Telegram', self::BOT, array( 'TelegramBot' ) ),
			'pinterestbot'          => array( 'Pinterestbot', 'Pinterest', self::BOT, array( 'Pinterestbot' ) ),
			'uptimerobot'           => array( 'UptimeRobot', 'UptimeRobot', self::BOT, array( 'UptimeRobot' ) ),
			'wordpress'             => array( 'WordPress', 'WordPress', self::BOT, array( 'WordPress/' ) ),

			// Tools and libraries (often AI agents with no name of their own).
			'curl'                  => array( 'curl', '', self::OTHER, array( 'curl/' ) ),
			'wget'                  => array( 'Wget', '', self::OTHER, array( 'Wget/' ) ),
			'python'                => array( 'Python script', '', self::OTHER, array( 'python-requests', 'python-httpx', 'aiohttp', 'Python-urllib' ) ),
			'node'                  => array( 'Node.js script', '', self::OTHER, array( 'node-fetch', 'axios/', 'undici', 'got (' ) ),
			'go'                    => array( 'Go program', '', self::OTHER, array( 'Go-http-client' ) ),
		);

		$catalog = array();
		foreach ( $list as $id => $agent ) {
			$catalog[ $id ] = array(
				'name'     => $agent[0],
				'operator' => $agent[1],
				'group'    => $agent[2],
				'match'    => $agent[3],
			);
		}

		/**
		 * Filter the agents the plugin recognises.
		 *
		 * Entries need a name, operator, group (ai, bot or other) and the
		 * user-agent substrings that identify them.
		 *
		 * @param array $catalog id => agent.
		 */
		$filtered = apply_filters( 'markdown_endpoints_ai_agents', $catalog );

		self::$catalog = is_array( $filtered ) ? self::validate( $filtered ) : $catalog;

		return self::$catalog;
	}

	/**
	 * Keep only well-formed entries.
	 *
	 * @param array $catalog id => agent.
	 * @return array
	 */
	private static function validate( array $catalog ) {
		$valid = array();

		foreach ( $catalog as $id => $agent ) {
			$id = substr( sanitize_key( (string) $id ), 0, 60 );
			if ( '' === $id || ! is_array( $agent ) || empty( $agent['name'] ) || empty( $agent['match'] ) || ! is_array( $agent['match'] ) ) {
				continue;
			}

			$valid[ $id ] = array(
				'name'     => substr( sanitize_text_field( (string) $agent['name'] ), 0, 60 ),
				'operator' => isset( $agent['operator'] ) ? substr( sanitize_text_field( (string) $agent['operator'] ), 0, 60 ) : '',
				'group'    => isset( $agent['group'] ) && in_array( $agent['group'], array( self::AI, self::BOT, self::OTHER ), true ) ? $agent['group'] : self::OTHER,
				'match'    => array_values( array_filter( array_map( 'strval', $agent['match'] ), 'strlen' ) ),
			);
		}

		return $valid;
	}

	/**
	 * Identify a user agent.
	 *
	 * @param string $user_agent User agent.
	 * @param string $via        How the Markdown was asked for (accept, md, llms, sitemap).
	 * @return array{id: string, name: string, operator: string, group: string}
	 */
	public static function detect( $user_agent, $via = '' ) {
		$user_agent = substr( (string) $user_agent, 0, 512 );

		if ( '' === trim( $user_agent ) ) {
			return self::entry( 'none', __( 'No user agent', 'markdown-endpoints-ai' ), '', self::OTHER );
		}

		// AI Crawler Controls knows the site's own additions too.
		if ( class_exists( 'AI_Crawler_Controls_Bots' ) && is_callable( array( 'AI_Crawler_Controls_Bots', 'detect' ) ) ) {
			$custom = array();
			if ( function_exists( 'ai_crawler_controls_plugin' ) ) {
				$acc = ai_crawler_controls_plugin();
				if ( is_object( $acc ) && method_exists( $acc, 'custom_tokens' ) ) {
					$custom = (array) $acc->custom_tokens();
				}
			}

			$id = (string) AI_Crawler_Controls_Bots::detect( $user_agent, $custom );
			if ( '' !== $id ) {
				$bot  = is_callable( array( 'AI_Crawler_Controls_Bots', 'get' ) ) ? AI_Crawler_Controls_Bots::get( $id ) : null;
				$name = is_array( $bot ) && ! empty( $bot['name'] ) ? $bot['name'] : (string) AI_Crawler_Controls_Bots::name( $id );
				$op   = is_array( $bot ) && ! empty( $bot['operator'] ) ? $bot['operator'] : '';
				return self::entry( substr( sanitize_key( str_replace( ':', '-', $id ) ), 0, 60 ), $name, $op, self::AI );
			}
		}

		foreach ( array( self::AI, self::BOT, self::OTHER ) as $group ) {
			$matcher = self::matcher( $group );
			if ( '' !== $matcher['pattern'] && preg_match( $matcher['pattern'], $user_agent, $found ) ) {
				$key = strtolower( $found[0] );
				if ( isset( $matcher['map'][ $key ] ) ) {
					$id    = $matcher['map'][ $key ];
					$agent = self::$catalog[ $id ];
					return self::entry( $id, $agent['name'], $agent['operator'], $agent['group'] );
				}
			}
		}

		if ( preg_match( '/bot\b|crawl|spider|slurp|scrape|headless|preview|monitor/i', $user_agent ) ) {
			return self::entry( 'other-bot', __( 'Other bot', 'markdown-endpoints-ai' ), '', self::BOT );
		}

		// Something that asked for Markdown by header is a tool or agent, not a person.
		if ( 'accept' === $via && ! preg_match( '#^Mozilla/5\.0 \((?:Windows|Macintosh|X11|Linux|iPhone|iPad)#', $user_agent ) ) {
			return self::entry( 'agent', __( 'Unnamed agent or tool', 'markdown-endpoints-ai' ), '', self::OTHER );
		}

		if ( preg_match( '#^Mozilla/5\.0 .*(?:Chrome|Safari|Firefox|Edg)/#', $user_agent ) ) {
			return self::entry( 'browser', __( 'Web browser', 'markdown-endpoints-ai' ), '', self::OTHER );
		}

		return self::entry( 'unknown', __( 'Unrecognized client', 'markdown-endpoints-ai' ), '', self::OTHER );
	}

	/**
	 * Name of an agent id (as stored in the log).
	 *
	 * @param string $id Agent id.
	 * @return string
	 */
	public static function name( $id ) {
		$catalog = self::catalog();

		if ( isset( $catalog[ $id ] ) ) {
			return $catalog[ $id ]['name'];
		}

		if ( class_exists( 'AI_Crawler_Controls_Bots' ) && is_callable( array( 'AI_Crawler_Controls_Bots', 'name' ) ) ) {
			$name = (string) AI_Crawler_Controls_Bots::name( $id );
			if ( '' !== $name && $name !== $id ) {
				return $name;
			}
		}

		$generic = array(
			'none'      => __( 'No user agent', 'markdown-endpoints-ai' ),
			'other-bot' => __( 'Other bot', 'markdown-endpoints-ai' ),
			'agent'     => __( 'Unnamed agent or tool', 'markdown-endpoints-ai' ),
			'browser'   => __( 'Web browser', 'markdown-endpoints-ai' ),
			'unknown'   => __( 'Unrecognized client', 'markdown-endpoints-ai' ),
		);

		return isset( $generic[ $id ] ) ? $generic[ $id ] : $id;
	}

	/**
	 * Operator of an agent id.
	 *
	 * @param string $id Agent id.
	 * @return string
	 */
	public static function operator( $id ) {
		$catalog = self::catalog();

		if ( isset( $catalog[ $id ] ) ) {
			return $catalog[ $id ]['operator'];
		}

		if ( class_exists( 'AI_Crawler_Controls_Bots' ) && is_callable( array( 'AI_Crawler_Controls_Bots', 'get' ) ) ) {
			$bot = AI_Crawler_Controls_Bots::get( $id );
			return is_array( $bot ) && ! empty( $bot['operator'] ) ? (string) $bot['operator'] : '';
		}

		return '';
	}

	/**
	 * Group label.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	public static function group_label( $group ) {
		$labels = array(
			self::AI    => __( 'AI crawlers and agents', 'markdown-endpoints-ai' ),
			self::BOT   => __( 'Search and other bots', 'markdown-endpoints-ai' ),
			self::OTHER => __( 'People and tools', 'markdown-endpoints-ai' ),
		);

		return isset( $labels[ $group ] ) ? $labels[ $group ] : $group;
	}

	/**
	 * Package a match.
	 *
	 * @param string $id       Id.
	 * @param string $name     Name.
	 * @param string $operator Operator.
	 * @param string $group    Group.
	 * @return array{id: string, name: string, operator: string, group: string}
	 */
	private static function entry( $id, $name, $operator, $group ) {
		return array(
			'id'       => $id,
			'name'     => $name,
			'operator' => $operator,
			'group'    => $group,
		);
	}

	/**
	 * One case-insensitive regex per group, longest substrings first, and the
	 * map from each lower-cased substring back to its agent.
	 *
	 * @param string $group Group.
	 * @return array{pattern: string, map: array<string,string>}
	 */
	private static function matcher( $group ) {
		if ( isset( self::$matchers[ $group ] ) ) {
			return self::$matchers[ $group ];
		}

		$map = array();
		foreach ( self::catalog() as $id => $agent ) {
			if ( $agent['group'] !== $group ) {
				continue;
			}
			foreach ( $agent['match'] as $needle ) {
				$map[ strtolower( $needle ) ] = $id;
			}
		}

		$needles = array_keys( $map );
		usort(
			$needles,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$quoted = array();
		foreach ( $needles as $needle ) {
			$quoted[] = preg_quote( $needle, '/' );
		}

		self::$matchers[ $group ] = array(
			'pattern' => $quoted ? '/' . implode( '|', $quoted ) . '/i' : '',
			'map'     => $map,
		);

		return self::$matchers[ $group ];
	}
}

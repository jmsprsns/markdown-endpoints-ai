<?php
/**
 * A small, strict CSS selector to XPath translator.
 *
 * Supports what clutter lists need: type and universal selectors, #id,
 * .class, [attr], [attr=value] with = ~= ^= $= *= |=, the descendant (space)
 * and child (>) combinators, and comma lists. Anything else (pseudo-classes,
 * sibling combinators, escapes) is refused, so a typo can't turn into an
 * expression nobody meant. Values only ever reach XPath as quoted literals.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSS selector translation.
 */
final class Markdown_Endpoints_AI_Selector {

	/**
	 * Longest selector accepted, in characters.
	 */
	const MAX_LENGTH = 300;

	/**
	 * Most selectors in one list.
	 */
	const MAX_SELECTORS = 200;

	/**
	 * Translations already made: selector => XPath ('' when invalid).
	 *
	 * @var array<string,string>
	 */
	private static $cache = array();

	/**
	 * Translate one selector (or comma list) into a relative XPath that finds
	 * matching descendants of a context node.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath, or '' when the selector isn't supported.
	 */
	public static function to_xpath( $selector ) {
		$selector = trim( (string) $selector );

		if ( isset( self::$cache[ $selector ] ) ) {
			return self::$cache[ $selector ];
		}

		$xpath = '';
		if ( '' !== $selector && strlen( $selector ) <= self::MAX_LENGTH * 4 ) {
			$parts = array();
			foreach ( self::split_list( $selector ) as $single ) {
				$part = self::translate( $single );
				if ( '' === $part ) {
					$parts = array();
					break;
				}
				$parts[] = $part;
			}
			$xpath = $parts ? implode( ' | ', $parts ) : '';
		}

		self::$cache[ $selector ] = $xpath;

		return $xpath;
	}

	/**
	 * Whether a selector is supported.
	 *
	 * @param string $selector CSS selector.
	 * @return bool
	 */
	public static function is_valid( $selector ) {
		return '' !== self::to_xpath( $selector );
	}

	/**
	 * Clean a list typed into a settings box: one selector per line (commas
	 * also split), unsupported ones reported back.
	 *
	 * @param string   $raw     Text from the textarea.
	 * @param string[] $invalid Filled with the refused selectors.
	 * @return string[] Valid selectors.
	 */
	public static function clean_list( $raw, &$invalid = array() ) {
		$invalid = array();
		$valid   = array();

		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			foreach ( self::split_list( $line ) as $selector ) {
				$selector = trim( $selector );
				if ( '' === $selector ) {
					continue;
				}

				if ( strlen( $selector ) <= self::MAX_LENGTH && self::is_valid( $selector ) ) {
					$valid[] = $selector;
				} else {
					$invalid[] = $selector;
				}
			}
		}

		return array_slice( array_values( array_unique( $valid ) ), 0, self::MAX_SELECTORS );
	}

	/**
	 * Split a comma list, leaving commas inside quotes and brackets alone.
	 *
	 * @param string $selector Selector list.
	 * @return string[]
	 */
	private static function split_list( $selector ) {
		$parts   = array();
		$current = '';
		$quote   = '';
		$depth   = 0;
		$length  = strlen( $selector );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $selector[ $i ];

			if ( '' !== $quote ) {
				if ( $char === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '[' === $char ) {
				++$depth;
			} elseif ( ']' === $char ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $char && 0 === $depth ) {
				$parts[] = trim( $current );
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = trim( $current );

		return array_values(
			array_filter(
				$parts,
				function ( $part ) {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * Translate a single selector (no commas).
	 *
	 * @param string $selector Selector.
	 * @return string XPath or ''.
	 */
	private static function translate( $selector ) {
		$ident = '-?[_a-zA-Z][_a-zA-Z0-9-]*';
		$pos   = 0;
		$len   = strlen( $selector );
		$xpath = '.';
		$axis  = '//';

		while ( $pos < $len ) {
			// One compound selector.
			$tag        = '*';
			$conditions = array();
			$matched    = false;

			if ( preg_match( '/\G(' . $ident . '|\*)/A', $selector, $m, 0, $pos ) ) {
				$tag     = '*' === $m[1] ? '*' : strtolower( $m[1] );
				$pos    += strlen( $m[0] );
				$matched = true;
			}

			while ( $pos < $len ) {
				if ( preg_match( '/\G#(' . $ident . ')/A', $selector, $m, 0, $pos ) ) {
					$conditions[] = '@id=' . self::literal( $m[1] );
				} elseif ( preg_match( '/\G\.(' . $ident . ')/A', $selector, $m, 0, $pos ) ) {
					$conditions[] = 'contains(concat(" ",normalize-space(@class)," "),' . self::literal( ' ' . $m[1] . ' ' ) . ')';
				} elseif ( preg_match( '/\G\[\s*(' . $ident . ')\s*(?:([~^$*|]?=)\s*(?:"([^"\x00-\x1f]{0,200})"|\'([^\'\x00-\x1f]{0,200})\'|(' . $ident . ')))?\s*\]/A', $selector, $m, 0, $pos ) ) {
					$conditions[] = self::attribute_condition( $m );
				} else {
					break;
				}

				$pos    += strlen( $m[0] );
				$matched = true;
			}

			if ( ! $matched ) {
				return '';
			}

			$xpath .= $axis . $tag . ( $conditions ? '[' . implode( ' and ', $conditions ) . ']' : '' );

			if ( $pos >= $len ) {
				break;
			}

			// The combinator before the next compound.
			if ( ! preg_match( '/\G\s*(>)?\s*/A', $selector, $m, 0, $pos ) || '' === $m[0] ) {
				return '';
			}

			$axis = isset( $m[1] ) && '>' === $m[1] ? '/' : '//';
			$pos += strlen( $m[0] );

			if ( $pos >= $len ) {
				return '';
			}
		}

		return $xpath;
	}

	/**
	 * XPath condition for an attribute selector match.
	 *
	 * @param array $m Regex groups: 1 name, 2 operator, 3/4/5 value.
	 * @return string
	 */
	private static function attribute_condition( array $m ) {
		$name = '@' . strtolower( $m[1] );

		if ( empty( $m[2] ) ) {
			return $name;
		}

		if ( isset( $m[5] ) && '' !== $m[5] ) {
			$value = $m[5];
		} elseif ( isset( $m[4] ) && '' !== $m[4] ) {
			$value = $m[4];
		} else {
			$value = isset( $m[3] ) ? $m[3] : '';
		}

		$literal = self::literal( $value );

		switch ( $m[2] ) {
			case '~=':
				return 'contains(concat(" ",normalize-space(' . $name . ')," "),' . self::literal( ' ' . $value . ' ' ) . ')';
			case '^=':
				return 'starts-with(' . $name . ',' . $literal . ')';
			case '$=':
				return 'substring(' . $name . ',string-length(' . $name . ')-string-length(' . $literal . ')+1)=' . $literal;
			case '*=':
				return 'contains(' . $name . ',' . $literal . ')';
			case '|=':
				return '(' . $name . '=' . $literal . ' or starts-with(' . $name . ',' . self::literal( $value . '-' ) . '))';
			default:
				return $name . '=' . $literal;
		}
	}

	/**
	 * Quote a string as an XPath 1.0 literal (which has no escapes).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function literal( $value ) {
		$value = (string) $value;

		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}

		return "concat('" . str_replace( "'", "',\"'\",'", $value ) . "')";
	}
}

<?php
/**
 * Turns a piece of rendered HTML into GitHub-flavoured Markdown.
 *
 * Works on a DOM tree (never on the HTML string with regexes), so content an
 * author typed can't make it slow or break out of the output. Keeps what
 * helps an AI tool read and cite the page: headings, real tables, lists,
 * code blocks with their language, image alt text and captions, footnotes,
 * and every link and image as an absolute URL.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML to Markdown converter.
 */
final class Markdown_Endpoints_AI_Converter {

	/**
	 * Stand-in for a hard line break while inline text is being built.
	 */
	const BREAK_MARK = "\x02";

	/**
	 * Deepest nesting converted element by element; anything deeper becomes
	 * plain text.
	 */
	const MAX_DEPTH = 120;

	/**
	 * Elements that start a new block.
	 *
	 * @var array<string,bool>
	 */
	private static $block_tags = array(
		'address'    => true,
		'article'    => true,
		'aside'      => true,
		'blockquote' => true,
		'caption'    => true,
		'center'     => true,
		'dd'         => true,
		'details'    => true,
		'dialog'     => true,
		'div'        => true,
		'dl'         => true,
		'dt'         => true,
		'fieldset'   => true,
		'figcaption' => true,
		'figure'     => true,
		'footer'     => true,
		'h1'         => true,
		'h2'         => true,
		'h3'         => true,
		'h4'         => true,
		'h5'         => true,
		'h6'         => true,
		'header'     => true,
		'hgroup'     => true,
		'hr'         => true,
		'iframe'     => true,
		'li'         => true,
		'main'       => true,
		'menu'       => true,
		'nav'        => true,
		'ol'         => true,
		'p'          => true,
		'pre'        => true,
		'section'    => true,
		'summary'    => true,
		'table'      => true,
		'tbody'      => true,
		'td'         => true,
		'tfoot'      => true,
		'th'         => true,
		'thead'      => true,
		'tr'         => true,
		'ul'         => true,
		'video'      => true,
		'audio'      => true,
	);

	/**
	 * Elements that never carry readable content.
	 *
	 * @var array<string,bool>
	 */
	private static $skip_tags = array(
		'area'     => true,
		'base'     => true,
		'button'   => true,
		'canvas'   => true,
		'datalist' => true,
		'embed'    => true,
		'form'     => true,
		'head'     => true,
		'link'     => true,
		'map'      => true,
		'meta'     => true,
		'meter'    => true,
		'noscript' => true,
		'object'   => true,
		'option'   => true,
		'progress' => true,
		'script'   => true,
		'select'   => true,
		'style'    => true,
		'svg'      => true,
		'template' => true,
		'textarea' => true,
		'title'    => true,
	);

	/**
	 * Classes for text only screen readers get ("opens in a new tab").
	 *
	 * @var string[]
	 */
	private static $reader_only = array( 'screen-reader-text', 'sr-only', 'visually-hidden', 'elementor-screen-only', 'skip-link', 'screen-reader-only' );

	/**
	 * URL of the page, for resolving relative links.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Include images.
	 *
	 * @var bool
	 */
	private $images;

	/**
	 * Footnote number by the id of its definition.
	 *
	 * @var array<string,int>
	 */
	private $fn_numbers = array();

	/**
	 * Footnote definition elements by id.
	 *
	 * @var array<string,DOMElement>
	 */
	private $fn_defs = array();

	/**
	 * Footnote text (Markdown) by the id of its definition.
	 *
	 * @var array<string,string>
	 */
	private $fn_texts = array();

	/**
	 * Ids of the footnote references, so their backlinks can be dropped.
	 *
	 * @var array<string,bool>
	 */
	private $fn_ref_ids = array();

	/**
	 * How deep inside bold / italic / links the walk currently is.
	 *
	 * @var array<string,int>
	 */
	private $open = array(
		'strong' => 0,
		'em'     => 0,
		'link'   => 0,
	);

	/**
	 * Current element depth.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Page URL.
	 * @param bool   $images   Include images.
	 */
	public function __construct( $base_url, $images = true ) {
		$this->base   = (string) $base_url;
		$this->images = (bool) $images;
	}

	/**
	 * Convert an element's content.
	 *
	 * @param DOMNode $root Content root.
	 * @return string Markdown.
	 */
	public function convert( DOMNode $root ) {
		$this->collect_footnotes( $root );

		return self::tidy( $this->blocks( $root ) );
	}

	/**
	 * The footnote definitions found by {@see convert()}, for the end of the
	 * file ("[^1]: The note.").
	 *
	 * @return string
	 */
	public function footnotes() {
		$notes = array();

		foreach ( $this->fn_numbers as $id => $number ) {
			if ( isset( $this->fn_texts[ $id ] ) && '' !== $this->fn_texts[ $id ] ) {
				$notes[] = '[^' . $number . ']: ' . $this->fn_texts[ $id ];
			}
		}

		return implode( "\n", $notes );
	}

	// Blocks ------------------------------------------------------------------

	/**
	 * Convert a node's children as a run of blocks.
	 *
	 * @param DOMNode $parent Parent node.
	 * @return string
	 */
	private function blocks( DOMNode $parent ) {
		return implode( "\n\n", $this->block_list( $parent ) );
	}

	/**
	 * Convert a node's children into a list of blocks. Inline content between
	 * block elements becomes its own paragraph.
	 *
	 * @param DOMNode $parent Parent node.
	 * @return string[]
	 */
	private function block_list( DOMNode $parent ) {
		$out    = array();
		$inline = '';

		if ( $this->depth > self::MAX_DEPTH ) {
			$text = self::single_line( self::escape( $parent->textContent ) );
			return '' === $text ? array() : array( $text );
		}

		++$this->depth;

		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$inline .= $this->text( $child );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( $this->skip( $child, $tag ) ) {
				continue;
			}

			if ( isset( self::$block_tags[ $tag ] ) ) {
				$this->flush( $out, $inline );
				$block = $this->block( $child, $tag );
				if ( '' !== trim( $block ) ) {
					$out[] = $block;
				}
			} else {
				$inline .= $this->inline_element( $child, $tag );
			}
		}

		$this->flush( $out, $inline );

		--$this->depth;

		return $out;
	}

	/**
	 * Turn pending inline content into a paragraph.
	 *
	 * @param string[] $out    Blocks so far.
	 * @param string   $inline Pending inline Markdown (emptied).
	 * @return void
	 */
	private function flush( array &$out, &$inline ) {
		$paragraph = self::paragraph( $inline );
		$inline    = '';

		if ( '' !== $paragraph ) {
			$out[] = $paragraph;
		}
	}

	/**
	 * Convert one block element.
	 *
	 * @param DOMElement $el  Element.
	 * @param string     $tag Lower-case tag name.
	 * @return string
	 */
	private function block( DOMElement $el, $tag ) {
		switch ( $tag ) {
			case 'p':
			case 'address':
				return self::paragraph( $this->inline( $el ) );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = self::single_line( $this->inline( $el ) );
				return '' === $text ? '' : str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . $text;

			case 'blockquote':
				return self::prefix_lines( $this->blocks( $el ), '> ', '>' );

			case 'ul':
			case 'ol':
			case 'menu':
				return $this->list_block( $el, 'ol' === $tag );

			case 'dl':
				return $this->definition_list( $el );

			case 'pre':
				return $this->code_block( $el );

			case 'table':
				return $this->table( $el );

			case 'figure':
				return $this->figure( $el );

			case 'figcaption':
			case 'caption':
				$text = self::single_line( $this->inline( $el ) );
				return '' === $text ? '' : '*' . $text . '*';

			case 'hr':
				return '---';

			case 'details':
				return $this->details( $el );

			case 'summary':
				$text = self::single_line( $this->inline( $el ) );
				return '' === $text ? '' : '**' . $text . '**';

			case 'iframe':
			case 'video':
			case 'audio':
				return $this->media( $el, $tag );

			default:
				return $this->blocks( $el );
		}
	}

	/**
	 * Bulleted or numbered list, nested lists indented under their item.
	 *
	 * @param DOMElement $list    The ul/ol.
	 * @param bool       $ordered Numbered.
	 * @return string
	 */
	private function list_block( DOMElement $list, $ordered ) {
		$number = 1;
		if ( $ordered && $list->hasAttribute( 'start' ) && is_numeric( $list->getAttribute( 'start' ) ) ) {
			$number = max( 0, min( 999999999, (int) $list->getAttribute( 'start' ) ) );
		}

		$items = array();

		foreach ( $list->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			// A list placed straight inside a list belongs to the item before it.
			if ( ( 'ul' === $tag || 'ol' === $tag ) && $items ) {
				$nested = $this->list_block( $child, 'ol' === $tag );
				if ( '' !== $nested ) {
					$last                     = count( $items ) - 1;
					$items[ $last ]['body'][] = $nested;
				}
				continue;
			}

			if ( $this->skip( $child, $tag ) ) {
				continue;
			}

			$parts = 'li' === $tag ? $this->list_item_parts( $child ) : $this->block_list( $child );
			if ( ! $parts ) {
				continue;
			}

			$items[] = array(
				'marker' => $ordered ? $number . '. ' : '- ',
				'body'   => $parts,
			);
			++$number;
		}

		$out = array();
		foreach ( $items as $item ) {
			$body = '';
			foreach ( $item['body'] as $i => $part ) {
				if ( $i > 0 ) {
					// A nested list sits right under its item; other blocks get a blank line.
					$body .= preg_match( '/^(?:- |\d+\. )/', $part ) ? "\n" : "\n\n";
				}
				$body .= $part;
			}

			$indent = str_repeat( ' ', strlen( $item['marker'] ) );
			$lines  = explode( "\n", $body );
			$text   = $item['marker'] . array_shift( $lines );
			foreach ( $lines as $line ) {
				$text .= "\n" . ( '' === $line ? '' : $indent . $line );
			}
			$out[] = $text;
		}

		return implode( "\n", $out );
	}

	/**
	 * The blocks inside one list item, with a task-list box when it starts
	 * with a checkbox.
	 *
	 * @param DOMElement $li List item.
	 * @return string[]
	 */
	private function list_item_parts( DOMElement $li ) {
		$parts = $this->block_list( $li );

		if ( $parts ) {
			$inputs = $li->getElementsByTagName( 'input' );
			$first  = $inputs->length ? $inputs->item( 0 ) : null;

			if ( $first instanceof DOMElement && 'checkbox' === strtolower( $first->getAttribute( 'type' ) ) ) {
				$parts[0] = ( $first->hasAttribute( 'checked' ) ? '[x] ' : '[ ] ' ) . $parts[0];
			}
		}

		return $parts;
	}

	/**
	 * Definition list: each term in bold, its description under it.
	 *
	 * @param DOMElement $dl The dl.
	 * @return string
	 */
	private function definition_list( DOMElement $dl ) {
		$out = array();

		foreach ( $dl->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( 'dt' === $tag ) {
				$term = self::single_line( $this->inline( $child ) );
				if ( '' !== $term ) {
					$out[] = '**' . $term . '**';
				}
			} elseif ( 'dd' === $tag || 'div' === $tag ) {
				$text = 'div' === $tag ? $this->definition_list( $child ) : $this->blocks( $child );
				if ( '' !== trim( $text ) ) {
					$out[] = $text;
				}
			}
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Details/summary (accordions): the summary in bold, then the content.
	 *
	 * @param DOMElement $el The details element.
	 * @return string
	 */
	private function details( DOMElement $el ) {
		return $this->blocks( $el );
	}

	/**
	 * Figure: its content, then the caption in italics.
	 *
	 * @param DOMElement $figure The figure.
	 * @return string
	 */
	private function figure( DOMElement $figure ) {
		$caption = '';
		$parts   = array();
		$inline  = '';

		foreach ( $figure->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'figcaption' === strtolower( $child->nodeName ) ) {
				$caption = self::single_line( $this->inline( $child ) );
				continue;
			}

			if ( $child instanceof DOMText ) {
				$inline .= $this->text( $child );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );
			if ( $this->skip( $child, $tag ) ) {
				continue;
			}

			if ( isset( self::$block_tags[ $tag ] ) ) {
				$this->flush( $parts, $inline );
				$block = $this->block( $child, $tag );
				if ( '' !== trim( $block ) ) {
					$parts[] = $block;
				}
			} else {
				$inline .= $this->inline_element( $child, $tag );
			}
		}

		$this->flush( $parts, $inline );

		if ( '' !== $caption ) {
			$parts[] = '*' . $caption . '*';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Fenced code block, with the language when the markup names one.
	 *
	 * @param DOMElement $pre The pre.
	 * @return string
	 */
	private function code_block( DOMElement $pre ) {
		$code = $pre;
		foreach ( $pre->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'code' === strtolower( $child->nodeName ) ) {
				$code = $child;
				break;
			}
		}

		$text = $this->pre_text( $pre );
		$text = preg_replace( '/^\n/', '', rtrim( str_replace( array( "\r\n", "\r" ), "\n", $text ) ) );

		if ( '' === trim( $text ) ) {
			return '';
		}

		$fence = str_repeat( '`', max( 3, self::longest_run( $text, '`' ) + 1 ) );

		return $fence . self::code_language( $pre, $code ) . "\n" . $text . "\n" . $fence;
	}

	/**
	 * The text of a pre, keeping its line breaks and leaving out the line
	 * numbers some highlighters add.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function pre_text( DOMNode $node ) {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text .= $child->data;
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( 'br' === $tag ) {
				$text .= "\n";
				continue;
			}

			if ( isset( self::$skip_tags[ $tag ] ) || preg_match( '/(?:^|\s)(?:line-numbers-rows|hljs-ln-numbers|hljs-ln-n|linenumber|line-number|line-numbers|gutter|crayon-nums|code-block-pro-copy-button)(?:\s|$)/', $child->getAttribute( 'class' ) ) ) {
				continue;
			}

			$text .= $this->pre_text( $child );

			// Highlighters that put each line in its own block.
			if ( ( 'div' === $tag || 'p' === $tag || 'li' === $tag ) && "\n" !== substr( $text, -1 ) ) {
				$text .= "\n";
			}
		}

		return $text;
	}

	/**
	 * Language of a code block from the usual highlighter markup.
	 *
	 * @param DOMElement $pre  The pre.
	 * @param DOMElement $code The code inside it (or the pre).
	 * @return string Lower-case language, or ''.
	 */
	private static function code_language( DOMElement $pre, DOMElement $code ) {
		foreach ( array( $code, $pre ) as $el ) {
			// Not the lang attribute: that's the human language ("en").
			foreach ( array( 'data-lang', 'data-language', 'data-enlighter-language', 'data-code-block-pro-language' ) as $attr ) {
				$value = strtolower( trim( $el->getAttribute( $attr ) ) );
				if ( self::valid_language( $value ) ) {
					return $value;
				}
			}

			$class = $el->getAttribute( 'class' );
			if ( preg_match( '/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.\-]{1,30})(?:\s|$)/', $class, $m ) || preg_match( '/brush:\s*([A-Za-z0-9_+#.\-]{1,30})/', $class, $m ) ) {
				$value = strtolower( $m[1] );
				if ( self::valid_language( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a language name is worth putting after the fence.
	 *
	 * @param string $value Language.
	 * @return bool
	 */
	private static function valid_language( $value ) {
		return '' !== $value && preg_match( '/^[a-z0-9_+#.\-]{1,30}$/', $value ) && ! in_array( $value, array( 'none', 'plain', 'plaintext', 'text', 'txt', 'auto' ), true );
	}

	/**
	 * A table as a GitHub-flavoured Markdown table. Merged cells are spread
	 * out with empty cells; one-column layout tables become plain blocks.
	 *
	 * @param DOMElement $table The table.
	 * @return string
	 */
	private function table( DOMElement $table ) {
		$rows    = array();
		$caption = '';

		foreach ( $table->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( 'caption' === $tag ) {
				$caption = self::single_line( $this->inline( $child ) );
			} elseif ( 'tr' === $tag ) {
				$rows[] = $child;
			} elseif ( 'thead' === $tag || 'tbody' === $tag || 'tfoot' === $tag ) {
				foreach ( $child->childNodes as $row ) {
					if ( $row instanceof DOMElement && 'tr' === strtolower( $row->nodeName ) ) {
						$rows[] = $row;
					}
				}
			}
		}

		if ( ! $rows ) {
			return '';
		}

		if ( $this->is_layout_table( $table, $rows ) ) {
			$parts = array();
			foreach ( $rows as $row ) {
				foreach ( $row->childNodes as $cell ) {
					if ( $cell instanceof DOMElement && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
						$text = $this->blocks( $cell );
						if ( '' !== trim( $text ) ) {
							$parts[] = $text;
						}
					}
				}
			}
			return implode( "\n\n", $parts );
		}

		$grid   = array();
		$aligns = array();
		$carry  = array();

		foreach ( $rows as $r => $row ) {
			$cells = array();
			$col   = 0;

			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement || ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					continue;
				}

				while ( ! empty( $carry[ $col ] ) ) {
					$cells[] = '';
					--$carry[ $col ];
					++$col;
				}

				$colspan = max( 1, min( 20, (int) $cell->getAttribute( 'colspan' ) ) );
				$rowspan = max( 1, min( 100, (int) $cell->getAttribute( 'rowspan' ) ) );

				if ( 0 === $r ) {
					$aligns[ $col ] = self::cell_align( $cell );
				}

				$cells[] = $this->cell( $cell );
				for ( $i = 0; $i < $colspan; $i++ ) {
					if ( $i > 0 ) {
						$cells[] = '';
					}
					if ( $rowspan > 1 ) {
						$carry[ $col ] = $rowspan - 1;
					}
					++$col;
				}
			}

			// Cells still covered by a rowspan from above, at the end of the row.
			$max_col = $carry ? max( array_keys( $carry ) ) : -1;
			while ( $col <= $max_col ) {
				$cells[] = '';
				if ( ! empty( $carry[ $col ] ) ) {
					--$carry[ $col ];
				}
				++$col;
			}

			$grid[] = $cells;
		}

		$columns = 0;
		foreach ( $grid as $cells ) {
			$columns = max( $columns, count( $cells ) );
		}

		if ( 0 === $columns ) {
			return '';
		}

		$lines = array();
		foreach ( $grid as $i => $cells ) {
			$cells   = array_pad( $cells, $columns, '' );
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( 0 === $i ) {
				$rule = array();
				for ( $c = 0; $c < $columns; $c++ ) {
					$align  = isset( $aligns[ $c ] ) ? $aligns[ $c ] : '';
					$rule[] = 'center' === $align ? ':---:' : ( 'right' === $align ? '---:' : ( 'left' === $align ? ':---' : '---' ) );
				}
				$lines[] = '| ' . implode( ' | ', $rule ) . ' |';
			}
		}

		$markdown = implode( "\n", $lines );

		return '' !== $caption ? '**' . $caption . "**\n\n" . $markdown : $markdown;
	}

	/**
	 * Whether a table only lays content out (one column, or cells holding
	 * headings, lists or other tables), so it reads better as plain blocks.
	 *
	 * @param DOMElement   $table The table.
	 * @param DOMElement[] $rows  Its rows.
	 * @return bool
	 */
	private function is_layout_table( DOMElement $table, array $rows ) {
		if ( 'presentation' === $table->getAttribute( 'role' ) ) {
			return true;
		}

		$widest = 0;
		foreach ( $rows as $row ) {
			$count = 0;
			foreach ( $row->childNodes as $cell ) {
				if ( $cell instanceof DOMElement && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					$count += max( 1, (int) $cell->getAttribute( 'colspan' ) );
				}
			}
			$widest = max( $widest, $count );
		}

		if ( $widest <= 1 ) {
			return true;
		}

		$xpath = new DOMXPath( $table->ownerDocument );

		return $xpath->query( './/td//table | .//th//table | .//td//h1 | .//td//h2 | .//td//h3 | .//td//blockquote | .//td//pre', $table )->length > 0;
	}

	/**
	 * One table cell's content on a single line.
	 *
	 * @param DOMElement $cell The td/th.
	 * @return string
	 */
	private function cell( DOMElement $cell ) {
		$text = $this->inline( $cell );
		$text = str_replace( self::BREAK_MARK, '<br>', $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return str_replace( '|', '\\|', $text );
	}

	/**
	 * Alignment of a header cell.
	 *
	 * @param DOMElement $cell The cell.
	 * @return string left, center, right or ''.
	 */
	private static function cell_align( DOMElement $cell ) {
		$hints = strtolower( $cell->getAttribute( 'align' ) . ' ' . $cell->getAttribute( 'class' ) . ' ' . $cell->getAttribute( 'style' ) );

		if ( preg_match( '/(?:^|\s)center(?:\s|$)|has-text-align-center|text-align:\s*center/', $hints ) ) {
			return 'center';
		}
		if ( preg_match( '/(?:^|\s)right(?:\s|$)|has-text-align-right|text-align:\s*right/', $hints ) ) {
			return 'right';
		}
		if ( preg_match( '/(?:^|\s)left(?:\s|$)|has-text-align-left|text-align:\s*left/', $hints ) ) {
			return 'left';
		}

		return '';
	}

	/**
	 * An embedded video, audio file or iframe as a link.
	 *
	 * @param DOMElement $el  The element.
	 * @param string     $tag Tag name.
	 * @return string
	 */
	private function media( DOMElement $el, $tag ) {
		$src = $el->getAttribute( 'src' );

		if ( '' === $src || 0 === strpos( $src, 'about:' ) ) {
			$src = $el->getAttribute( 'data-src' );
		}

		if ( '' === $src && 'iframe' !== $tag ) {
			foreach ( $el->getElementsByTagName( 'source' ) as $source ) {
				$src = $source->getAttribute( 'src' );
				if ( '' !== $src ) {
					break;
				}
			}
		}

		$url = $this->absolute( $src );
		if ( '' === $url ) {
			return '';
		}

		$title = self::single_line( self::escape( str_replace( array( '[', ']' ), array( '(', ')' ), $el->getAttribute( 'title' ) ) ) );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})#', $url, $m ) ) {
			$url  = 'https://www.youtube.com/watch?v=' . $m[1];
			$kind = 'Video';
		} elseif ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $url, $m ) ) {
			$url  = 'https://vimeo.com/' . $m[1];
			$kind = 'Video';
		} elseif ( 'video' === $tag || preg_match( '/(?:wistia|loom|vimeo|youtube|dailymotion|tiktok)\./', $host ) ) {
			$kind = 'Video';
		} elseif ( 'audio' === $tag || preg_match( '/(?:spotify|soundcloud|podcasts\.apple|anchor)\./', $host ) ) {
			$kind = 'Audio';
		} elseif ( false !== strpos( $url, 'google.com/maps' ) ) {
			$kind = 'Map';
		} else {
			$kind = 'Embedded content';
		}

		$label = '' !== $title && 0 !== strcasecmp( $title, $kind ) ? $kind . ': ' . $title : $kind;

		return '[' . $label . '](' . self::url( $url ) . ')';
	}

	// Inline ------------------------------------------------------------------

	/**
	 * Convert a node's children as inline content. Block elements found
	 * inside (a heading inside a link card) flatten to text.
	 *
	 * @param DOMNode $parent Parent.
	 * @return string
	 */
	private function inline( DOMNode $parent ) {
		if ( $this->depth > self::MAX_DEPTH ) {
			return self::escape( preg_replace( '/\s+/', ' ', $parent->textContent ) );
		}

		++$this->depth;
		$out = '';

		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$out .= $this->text( $child );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( $this->skip( $child, $tag ) ) {
				continue;
			}

			if ( 'iframe' === $tag || 'video' === $tag || 'audio' === $tag ) {
				$out .= ' ' . $this->media( $child, $tag ) . ' ';
			} elseif ( 'br' === $tag || 'hr' === $tag ) {
				$out .= 'br' === $tag ? self::BREAK_MARK : ' ';
			} elseif ( isset( self::$block_tags[ $tag ] ) ) {
				$out .= ' ' . $this->inline( $child ) . ' ';
			} else {
				$out .= $this->inline_element( $child, $tag );
			}
		}

		--$this->depth;

		return $out;
	}

	/**
	 * One inline element.
	 *
	 * @param DOMElement $el  Element.
	 * @param string     $tag Tag name.
	 * @return string
	 */
	private function inline_element( DOMElement $el, $tag ) {
		if ( $el->hasAttribute( 'data-mdai-fn' ) ) {
			return '[^' . (int) $el->getAttribute( 'data-mdai-fn' ) . ']';
		}

		switch ( $tag ) {
			case 'br':
				return self::BREAK_MARK;

			case 'strong':
			case 'b':
				return $this->emphasis( $el, 'strong', '**' );

			case 'em':
			case 'i':
				return $this->emphasis( $el, 'em', '*' );

			case 'del':
			case 's':
			case 'strike':
				return self::wrap( $this->inline( $el ), '~~' );

			case 'code':
			case 'kbd':
			case 'samp':
			case 'tt':
				return self::code_span( $el->textContent );

			case 'a':
				return $this->link( $el );

			case 'img':
				return $this->image( $el );

			case 'picture':
				foreach ( $el->getElementsByTagName( 'img' ) as $img ) {
					return $this->image( $img );
				}
				return '';

			case 'sup':
			case 'sub':
				$inner = trim( $this->inline( $el ) );
				return '' === $inner ? '' : '<' . $tag . '>' . $inner . '</' . $tag . '>';

			case 'q':
				return '"' . $this->inline( $el ) . '"';

			case 'cite':
				// A quote's source reads as an attribution; elsewhere it's a title.
				$parent = $el->parentNode;
				if ( $parent instanceof DOMElement && in_array( strtolower( $parent->nodeName ), array( 'blockquote', 'figure' ), true ) ) {
					$inner = trim( $this->inline( $el ) );
					return '' === $inner ? '' : "\u{2014} " . $inner;
				}
				return $this->emphasis( $el, 'em', '*' );

			case 'input':
			case 'wbr':
				return '';

			default:
				return $this->inline( $el );
		}
	}

	/**
	 * Bold or italic, without doubling up when nested.
	 *
	 * @param DOMElement $el     Element.
	 * @param string     $kind   strong or em.
	 * @param string     $marker Markdown marker.
	 * @return string
	 */
	private function emphasis( DOMElement $el, $kind, $marker ) {
		++$this->open[ $kind ];
		$inner = $this->inline( $el );
		--$this->open[ $kind ];

		return $this->open[ $kind ] > 0 ? $inner : self::wrap( $inner, $marker );
	}

	/**
	 * A link with an absolute URL. Links to nowhere useful (scripts, anchors
	 * on the HTML page) keep only their text.
	 *
	 * @param DOMElement $a The anchor.
	 * @return string
	 */
	private function link( DOMElement $a ) {
		if ( $this->open['link'] > 0 ) {
			return $this->inline( $a );
		}

		++$this->open['link'];
		$content = $this->inline( $a );
		--$this->open['link'];

		$content = trim( preg_replace( '/\s+/', ' ', str_replace( self::BREAK_MARK, ' ', $content ) ) );
		$href    = trim( $a->getAttribute( 'href' ) );

		if ( '' === $href || '#' === $href[0] ) {
			return $content;
		}

		$url = $this->absolute( $href );
		if ( '' === $url ) {
			return $content;
		}

		if ( '' === $content ) {
			$content = self::single_line( self::escape( $a->getAttribute( 'aria-label' ) ) );
			if ( '' === $content ) {
				return '';
			}
		}

		// An image that links to its own full-size file: just the big image.
		if ( 0 === strpos( $content, '![' ) && ')' === substr( $content, -1 ) && preg_match( '/\.(?:jpe?g|png|gif|webp|avif)(?:\?.*)?$/i', $url ) && substr_count( $content, '![' ) === 1 ) {
			return preg_replace( '/\]\([^)]*\)$/', '](' . self::url( $url ) . ')', $content );
		}

		$plain = rtrim( preg_replace( '#^(?:https?://|mailto:|tel:)#i', '', str_replace( '\\', '', $content ) ), '/' );
		$bare  = rtrim( preg_replace( '#^(?:https?://|mailto:|tel:)#i', '', $url ), '/' );
		if ( $bare === $plain && false === strpos( $url, '>' ) ) {
			return '<' . $url . '>';
		}

		return '[' . $content . '](' . self::url( $url ) . ')';
	}

	/**
	 * An image with alt text. Lazy-loaded images use their real source;
	 * tracking pixels, inline data images and emoji images are skipped
	 * (emoji keep their character).
	 *
	 * @param DOMElement $img The img.
	 * @return string
	 */
	private function image( DOMElement $img ) {
		// Brackets would end the alt text early, so they become parentheses.
		$alt   = self::single_line( self::escape( str_replace( array( '[', ']' ), array( '(', ')' ), $img->getAttribute( 'alt' ) ) ) );
		$class = ' ' . $img->getAttribute( 'class' ) . ' ';

		if ( false !== strpos( $class, ' emoji ' ) || false !== strpos( $class, ' wp-smiley ' ) ) {
			return $alt;
		}

		if ( ! $this->images ) {
			return '';
		}

		$width  = $img->getAttribute( 'width' );
		$height = $img->getAttribute( 'height' );
		if ( ( '' !== $width && (int) $width <= 2 ) || ( '' !== $height && (int) $height <= 2 ) ) {
			return '';
		}

		$src = '';
		foreach ( array( 'data-lazy-src', 'data-src', 'data-original', 'src' ) as $attr ) {
			$value = trim( $img->getAttribute( $attr ) );
			if ( '' !== $value && 0 !== stripos( $value, 'data:' ) ) {
				$src = $value;
				break;
			}
		}

		if ( '' === $src ) {
			foreach ( array( 'data-lazy-srcset', 'data-srcset', 'srcset' ) as $attr ) {
				$first = trim( strtok( (string) $img->getAttribute( $attr ), ' ,' ) );
				if ( '' !== $first && 0 !== stripos( $first, 'data:' ) ) {
					$src = $first;
					break;
				}
			}
		}

		$url = $this->absolute( $src );

		return '' === $url ? '' : '![' . $alt . '](' . self::url( $url ) . ')';
	}

	/**
	 * A text node, with its whitespace collapsed and Markdown characters
	 * escaped.
	 *
	 * @param DOMText $node Text node.
	 * @return string
	 */
	private function text( DOMText $node ) {
		return self::escape( self::collapse( $node->data ) );
	}

	// Footnotes ---------------------------------------------------------------

	/**
	 * Find footnotes (core's Footnotes block and the common plugins), number
	 * them by first reference, and take the notes list out of the content so
	 * it can be written as Markdown footnotes at the end.
	 *
	 * @param DOMNode $root Content root.
	 * @return void
	 */
	private function collect_footnotes( DOMNode $root ) {
		$doc = $root instanceof DOMDocument ? $root : $root->ownerDocument;
		if ( ! $doc ) {
			return;
		}

		$xpath      = new DOMXPath( $doc );
		$tokens     = array( 'wp-block-footnotes', 'footnotes', 'footnote-list', 'footnotes-list', 'easy-footnotes-wrapper', 'modern-footnotes-list', 'fn-list' );
		$conditions = array( '@role="doc-endnotes"' );
		foreach ( $tokens as $token ) {
			$conditions[] = 'contains(concat(" ",normalize-space(@class)," ")," ' . $token . ' ")';
		}

		$containers = array();
		foreach ( $xpath->query( './/*[(self::ol or self::ul or self::div or self::section or self::aside or self::footer) and (' . implode( ' or ', $conditions ) . ')]', $root ) as $container ) {
			$found = false;
			foreach ( $xpath->query( './/li[@id]', $container ) as $li ) {
				$this->fn_defs[ $li->getAttribute( 'id' ) ] = $li;
				$found                                      = true;
			}
			if ( $found ) {
				$containers[] = $container;
			}
		}

		if ( ! $this->fn_defs ) {
			return;
		}

		foreach ( $xpath->query( './/a[starts-with(@href,"#")]', $root ) as $a ) {
			$id = rawurldecode( substr( $a->getAttribute( 'href' ), 1 ) );

			if ( ! isset( $this->fn_defs[ $id ] ) || self::inside_any( $a, $containers ) ) {
				continue;
			}

			if ( ! isset( $this->fn_numbers[ $id ] ) ) {
				$this->fn_numbers[ $id ] = count( $this->fn_numbers ) + 1;
			}

			if ( '' !== $a->getAttribute( 'id' ) ) {
				$this->fn_ref_ids[ $a->getAttribute( 'id' ) ] = true;
			}

			$target = $a;
			$parent = $a->parentNode;
			if ( $parent instanceof DOMElement && 'sup' === strtolower( $parent->nodeName ) && trim( $parent->textContent ) === trim( $a->textContent ) ) {
				$target = $parent;
				if ( '' !== $parent->getAttribute( 'id' ) ) {
					$this->fn_ref_ids[ $parent->getAttribute( 'id' ) ] = true;
				}
			}
			$target->setAttribute( 'data-mdai-fn', (string) $this->fn_numbers[ $id ] );
		}

		// Notes nobody points at stay where they are, as ordinary text.
		$this->fn_defs = array_intersect_key( $this->fn_defs, $this->fn_numbers );

		// Read the notes now: once their list is taken out of the page, PHP
		// may free the detached elements.
		foreach ( $this->fn_defs as $id => $li ) {
			$this->fn_texts[ $id ] = $this->footnote_text( $li );
		}

		if ( $this->fn_numbers ) {
			foreach ( $containers as $container ) {
				if ( $container->parentNode ) {
					$container->parentNode->removeChild( $container );
				}
			}
		}
	}

	/**
	 * A footnote's text without its "back to the reference" link.
	 *
	 * @param DOMElement $li The note.
	 * @return string
	 */
	private function footnote_text( DOMElement $li ) {
		$clone = $li->cloneNode( true );
		$drop  = array();

		foreach ( $clone->getElementsByTagName( 'a' ) as $a ) {
			$href = $a->getAttribute( 'href' );
			$text = trim( $a->textContent );
			if ( ( '' !== $href && '#' === $href[0] && isset( $this->fn_ref_ids[ rawurldecode( substr( $href, 1 ) ) ] ) )
				|| preg_match( '/^(?:\x{21A9}\x{FE0E}?|\x{21A9}\x{FE0F}|\^|\x{2191}|\x{2934}|back|return)$/iu', $text ) ) {
				$drop[] = $a;
			}
		}

		foreach ( $drop as $a ) {
			$a->parentNode->removeChild( $a );
		}

		return self::single_line( $this->inline( $clone ) );
	}

	/**
	 * Whether a node sits inside any of these elements.
	 *
	 * @param DOMNode      $node       Node.
	 * @param DOMElement[] $containers Elements.
	 * @return bool
	 */
	private static function inside_any( DOMNode $node, array $containers ) {
		for ( $parent = $node->parentNode; $parent; $parent = $parent->parentNode ) {
			foreach ( $containers as $container ) {
				if ( $parent->isSameNode( $container ) ) {
					return true;
				}
			}
		}

		return false;
	}

	// Skipping ----------------------------------------------------------------

	/**
	 * Whether an element is left out entirely.
	 *
	 * @param DOMElement $el  Element.
	 * @param string     $tag Tag name.
	 * @return bool
	 */
	private function skip( DOMElement $el, $tag ) {
		if ( isset( self::$skip_tags[ $tag ] ) ) {
			return true;
		}

		if ( 'input' === $tag ) {
			return true;
		}

		$class = $el->getAttribute( 'class' );
		if ( '' !== $class ) {
			$class = ' ' . $class . ' ';
			foreach ( self::$reader_only as $hidden ) {
				if ( false !== strpos( $class, ' ' . $hidden . ' ' ) ) {
					return true;
				}
			}
		}

		// Decorative bits (icon fonts, duplicated labels) are hidden from
		// screen readers; longer hidden text may be a carousel slide, so keep it.
		if ( 'true' === $el->getAttribute( 'aria-hidden' ) && strlen( trim( $el->textContent ) ) < 40 && 0 === $el->getElementsByTagName( 'img' )->length ) {
			return true;
		}

		return false;
	}

	// Helpers -----------------------------------------------------------------

	/**
	 * Resolve a URL against the page URL. Only web, mail and phone links are
	 * kept.
	 *
	 * @param string $url URL as written in the HTML.
	 * @return string Absolute URL, or '' to drop it.
	 */
	public function absolute( $url ) {
		$url = trim( preg_replace( '/[\x00-\x20\x7f]+/', ' ', (string) $url ) );

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel', 'ftp' ), true ) ? $url : '';
		}

		$base = wp_parse_url( $this->base );
		if ( empty( $base['host'] ) ) {
			return '';
		}

		$scheme = isset( $base['scheme'] ) ? $base['scheme'] : 'https';
		$origin = $scheme . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		$path   = isset( $base['path'] ) ? $base['path'] : '/';

		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}

		if ( '/' === $url[0] ) {
			return $origin . self::remove_dots( $url );
		}

		if ( '?' === $url[0] || '#' === $url[0] ) {
			return $origin . $path . $url;
		}

		$dir = substr( $path, 0, strrpos( $path, '/' ) + 1 );

		return $origin . self::remove_dots( $dir . $url );
	}

	/**
	 * Remove ./ and ../ segments from a path.
	 *
	 * @param string $path Path, possibly with a query.
	 * @return string
	 */
	private static function remove_dots( $path ) {
		$suffix = '';
		$cut    = strcspn( $path, '?#' );
		if ( $cut < strlen( $path ) ) {
			$suffix = substr( $path, $cut );
			$path   = substr( $path, 0, $cut );
		}

		if ( false === strpos( $path, './' ) && '/.' !== substr( $path, -2 ) ) {
			return $path . $suffix;
		}

		$out = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				if ( count( $out ) > 1 ) {
					array_pop( $out );
				}
			} elseif ( '.' !== $segment ) {
				$out[] = $segment;
			}
		}

		return implode( '/', $out ) . $suffix;
	}

	/**
	 * Make a URL safe inside Markdown's link parentheses.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url( $url ) {
		return str_replace( array( ' ', '(', ')', '<', '>' ), array( '%20', '%28', '%29', '%3C', '%3E' ), $url );
	}

	/**
	 * Collapse runs of whitespace (non-breaking spaces too) to one space and
	 * drop invisible characters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function collapse( $text ) {
		$out = preg_replace( '/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text );
		if ( null === $out ) {
			$out = preg_replace( '/\s+/', ' ', $text );
		}

		$clean = preg_replace( '/[\x{00AD}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', '', $out );

		return null === $clean ? $out : $clean;
	}

	/**
	 * Escape the characters that would otherwise turn plain text into
	 * Markdown (or raw HTML).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( $text ) {
		$text = str_replace( array( '\\', '*', '`' ), array( '\\\\', '\\*', '\\`' ), (string) $text );

		$out  = preg_replace( '/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\_', $text );
		$text = null === $out ? str_replace( '_', '\\_', $text ) : $out;

		$text = preg_replace( '/<(?=[A-Za-z\/!?])/', '\\<', $text );
		$text = preg_replace( '/&(?=#?[A-Za-z0-9]{1,32};)/', '&amp;', $text );
		$text = str_replace( array( '[^', '](' ), array( '\\[^', ']\\(' ), $text );

		return $text;
	}

	/**
	 * Wrap text in a Markdown marker, keeping the spaces outside it.
	 *
	 * @param string $inner  Inline Markdown.
	 * @param string $marker Marker.
	 * @return string
	 */
	private static function wrap( $inner, $marker ) {
		$core = trim( $inner, " \t\n" . self::BREAK_MARK );

		if ( '' === $core ) {
			return $inner;
		}

		$lead  = substr( $inner, 0, strlen( $inner ) - strlen( ltrim( $inner, " \t\n" . self::BREAK_MARK ) ) );
		$trail = substr( $inner, strlen( rtrim( $inner, " \t\n" . self::BREAK_MARK ) ) );

		return $lead . $marker . $core . $marker . $trail;
	}

	/**
	 * Inline code with a fence longer than any backtick run inside it.
	 *
	 * @param string $text Code.
	 * @return string
	 */
	private static function code_span( $text ) {
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		if ( '' === trim( $text ) ) {
			return '';
		}

		$fence = str_repeat( '`', self::longest_run( $text, '`' ) + 1 );
		$pad   = ( '`' === $text[0] || '`' === substr( $text, -1 ) ) ? ' ' : '';

		return $fence . $pad . $text . $pad . $fence;
	}

	/**
	 * Longest run of one character in a string.
	 *
	 * @param string $text Text.
	 * @param string $char Character.
	 * @return int
	 */
	private static function longest_run( $text, $char ) {
		$longest = 0;
		$length  = strlen( $text );
		$run     = 0;

		for ( $i = 0; $i < $length; $i++ ) {
			$run     = $text[ $i ] === $char ? $run + 1 : 0;
			$longest = max( $longest, $run );
		}

		return $longest;
	}

	/**
	 * Finish a paragraph: tidy spaces, turn line-break markers into Markdown
	 * hard breaks, and escape characters that would start a heading, quote or
	 * list.
	 *
	 * @param string $inline Inline Markdown.
	 * @return string
	 */
	private static function paragraph( $inline ) {
		$text = preg_replace( '/ {2,}/', ' ', $inline );
		$text = preg_replace( '/ *' . self::BREAK_MARK . ' */', self::BREAK_MARK, $text );
		$text = trim( $text, ' ' . self::BREAK_MARK );

		if ( '' === $text ) {
			return '';
		}

		$lines = array();
		foreach ( explode( self::BREAK_MARK, $text ) as $line ) {
			$lines[] = self::escape_line_start( $line );
		}

		return implode( "  \n", $lines );
	}

	/**
	 * Inline Markdown on one line (headings, captions, cells).
	 *
	 * @param string $inline Inline Markdown.
	 * @return string
	 */
	private static function single_line( $inline ) {
		return trim( preg_replace( '/\s+/', ' ', str_replace( self::BREAK_MARK, ' ', $inline ) ) );
	}

	/**
	 * Escape a line that would otherwise start a block.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function escape_line_start( $line ) {
		if ( preg_match( '/^(?:#{1,6}(?:\s|$)|>|[-+](?:\s|$)|(?:=+|-+)\s*$|~~~)/', $line ) ) {
			return '\\' . $line;
		}

		if ( preg_match( '/^(\d{1,9})([.)])(\s|$)/', $line, $m ) ) {
			return $m[1] . '\\' . substr( $line, strlen( $m[1] ) );
		}

		return $line;
	}

	/**
	 * Prefix every line (blockquotes).
	 *
	 * @param string $text   Text.
	 * @param string $prefix Prefix for lines with content.
	 * @param string $empty  Prefix for empty lines.
	 * @return string
	 */
	private static function prefix_lines( $text, $prefix, $empty ) {
		if ( '' === trim( $text ) ) {
			return '';
		}

		$lines = explode( "\n", $text );
		foreach ( $lines as $i => $line ) {
			$lines[ $i ] = '' === $line ? $empty : $prefix . $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Final clean-up: no runs of blank lines or stray whitespace outside code
	 * blocks.
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	public static function tidy( $markdown ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );
		$out   = array();
		$fence = '';
		$blank = 0;

		foreach ( $lines as $line ) {
			if ( '' === $fence && preg_match( '/^\s*(`{3,}|~{3,})/', $line, $m ) ) {
				$fence = $m[1];
				$blank = 0;
				$out[] = $line;
				continue;
			}

			if ( '' !== $fence ) {
				if ( trim( $line ) === $fence ) {
					$fence = '';
				}
				$out[] = $line;
				continue;
			}

			if ( '' === trim( $line ) ) {
				++$blank;
				if ( 1 === $blank ) {
					$out[] = '';
				}
				continue;
			}

			$blank = 0;
			$out[] = $line;
		}

		return trim( implode( "\n", $out ), "\n" );
	}
}

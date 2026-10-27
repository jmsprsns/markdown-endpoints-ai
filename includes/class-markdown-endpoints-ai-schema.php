<?php
/**
 * Reads a page's JSON-LD structured data.
 *
 * Two jobs: facts that only live in schema (a product's price and rating,
 * FAQ answers, opening hours, recipe times) become short readable sections,
 * and Google's paywall markup (isAccessibleForFree: false with a hasPart
 * cssSelector) says which parts of the page are for paying readers only, so
 * they can be left out.
 *
 * @package Markdown_Endpoints_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD reader.
 */
final class Markdown_Endpoints_AI_Schema {

	/**
	 * Largest JSON-LD block read, in bytes.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Business types (schema.org LocalBusiness and its common subtypes).
	 *
	 * @var string[]
	 */
	private static $business_types = array( 'LocalBusiness', 'Store', 'Restaurant', 'FoodEstablishment', 'ProfessionalService', 'HomeAndConstructionBusiness', 'HealthAndBeautyBusiness', 'MedicalBusiness', 'Dentist', 'Physician', 'LegalService', 'Attorney', 'AutomotiveBusiness', 'FinancialService', 'LodgingBusiness', 'Hotel', 'SportsActivityLocation', 'EntertainmentBusiness', 'RealEstateAgent', 'TravelAgency', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'Plumber', 'Electrician', 'RoofingContractor', 'HVACBusiness', 'GeneralContractor', 'AccountingService', 'InsuranceAgency', 'ChildCare', 'DryCleaningOrLaundry', 'EmergencyService', 'EmploymentAgency', 'GovernmentOffice', 'Library', 'SelfStorage', 'ShoppingCenter', 'TouristInformationCenter' );

	/**
	 * Every node found, flattened out of @graph blocks and arrays.
	 *
	 * @var array<int,array>
	 */
	private $nodes = array();

	/**
	 * Nodes by @id, for following references.
	 *
	 * @var array<string,array>
	 */
	private $by_id = array();

	/**
	 * Read every JSON-LD script in a document.
	 *
	 * @param DOMDocument $doc Parsed page.
	 */
	public function __construct( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );

		foreach ( $xpath->query( '//script[@type]' ) as $script ) {
			if ( 'application/ld+json' !== strtolower( trim( $script->getAttribute( 'type' ) ) ) ) {
				continue;
			}

			$json = trim( $script->textContent );
			if ( '' === $json || strlen( $json ) > self::MAX_BYTES ) {
				continue;
			}

			$data = json_decode( $json, true, 64 );
			if ( is_array( $data ) ) {
				$this->collect( $data, 0 );
			}
		}
	}

	/**
	 * Flatten graphs and lists into $nodes.
	 *
	 * @param array $data  Decoded JSON.
	 * @param int   $depth Nesting depth.
	 * @return void
	 */
	private function collect( array $data, $depth ) {
		if ( $depth > 4 || count( $this->nodes ) > 200 ) {
			return;
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$this->collect( $node, $depth + 1 );
				}
			}
			return;
		}

		if ( isset( $data[0] ) ) {
			foreach ( $data as $node ) {
				if ( is_array( $node ) ) {
					$this->collect( $node, $depth + 1 );
				}
			}
			return;
		}

		$this->nodes[] = $data;

		if ( isset( $data['@id'] ) && is_string( $data['@id'] ) ) {
			$this->by_id[ $data['@id'] ] = $data;
		}
	}

	/**
	 * Whether any structured data was found.
	 *
	 * @return bool
	 */
	public function has_data() {
		return (bool) $this->nodes;
	}

	// Paywall -----------------------------------------------------------------

	/**
	 * What Google's paywall markup says about the page.
	 *
	 * @return array{paywalled: bool, selectors: string[]} Selectors of the
	 *         paid-only parts. Paywalled with no selectors means the markup
	 *         doesn't say which part is free.
	 */
	public function paywall() {
		$paywalled = false;
		$selectors = array();

		foreach ( $this->nodes as $node ) {
			if ( ! array_key_exists( 'isAccessibleForFree', $node ) || self::truthy( $node['isAccessibleForFree'] ) ) {
				continue;
			}

			$paywalled = true;
			$parts     = isset( $node['hasPart'] ) ? $node['hasPart'] : array();
			$parts     = is_array( $parts ) && ! isset( $parts[0] ) ? array( $parts ) : (array) $parts;

			foreach ( $parts as $part ) {
				if ( ! is_array( $part ) || ! isset( $part['cssSelector'] ) || ! is_string( $part['cssSelector'] ) ) {
					continue;
				}
				if ( array_key_exists( 'isAccessibleForFree', $part ) && self::truthy( $part['isAccessibleForFree'] ) ) {
					continue;
				}
				$selectors[] = trim( $part['cssSelector'] );
			}
		}

		return array(
			'paywalled' => $paywalled,
			'selectors' => array_values( array_unique( array_filter( $selectors ) ) ),
		);
	}

	/**
	 * Schema's loose booleans ("False", "false", false, 0).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( 'false', '0', 'no', 'https://schema.org/false', 'http://schema.org/false' ), true );
		}

		return (bool) $value;
	}

	// Sections ----------------------------------------------------------------

	/**
	 * Readable Markdown sections for the facts schema holds. Anything already
	 * in the body (an FAQ question that's printed on the page) is skipped.
	 *
	 * @param string $body Markdown body, to avoid repeating it.
	 * @return string Markdown, or ''.
	 */
	public function sections( $body ) {
		$haystack = self::normalize( $body );
		$sections = array();
		$seen     = array();

		foreach ( $this->nodes as $node ) {
			$types = self::types( $node );
			$key   = md5( wp_json_encode( $node ) );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( array_intersect( $types, array( 'Product', 'ProductGroup', 'Car', 'IndividualProduct' ) ) ) {
				$sections[] = $this->product( $node );
			} elseif ( in_array( 'FAQPage', $types, true ) ) {
				$sections[] = $this->faq( $node, $haystack );
			} elseif ( array_intersect( $types, self::$business_types ) ) {
				$sections[] = $this->business( $node );
			} elseif ( in_array( 'Recipe', $types, true ) ) {
				$sections[] = $this->recipe( $node, $haystack );
			} elseif ( array_intersect( $types, array( 'Event', 'BusinessEvent', 'EducationEvent', 'MusicEvent', 'SocialEvent', 'SportsEvent', 'Festival' ) ) ) {
				$sections[] = $this->event( $node );
			} elseif ( array_intersect( $types, array( 'SoftwareApplication', 'WebApplication', 'MobileApplication', 'VideoGame' ) ) ) {
				$sections[] = $this->software( $node );
			} elseif ( in_array( 'Review', $types, true ) ) {
				$sections[] = $this->review( $node );
			} elseif ( in_array( 'HowTo', $types, true ) ) {
				$sections[] = $this->howto( $node, $haystack );
			}
		}

		return implode( "\n\n", array_filter( $sections ) );
	}

	/**
	 * Product: price, availability, rating, brand, SKU.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function product( array $node ) {
		$rows = array();
		$name = $this->text( $node, 'name' );

		$offer = $this->first( $this->resolve( isset( $node['offers'] ) ? $node['offers'] : null ) );
		if ( $offer ) {
			$price = $this->price( $offer );
			if ( '' !== $price ) {
				$rows[] = array( 'Price', $price );
			}

			$availability = $this->text( $offer, 'availability' );
			if ( '' !== $availability ) {
				$rows[] = array( 'Availability', self::enum_label( $availability ) );
			}
		}

		$rating = $this->rating( $node );
		if ( '' !== $rating ) {
			$rows[] = array( 'Rating', $rating );
		}

		$brand = $this->resolve( isset( $node['brand'] ) ? $node['brand'] : null );
		$brand = is_array( $brand ) ? $this->text( $this->first( $brand ), 'name' ) : ( is_string( $brand ) ? $brand : '' );
		if ( '' !== $brand ) {
			$rows[] = array( 'Brand', $brand );
		}

		foreach ( array(
			'sku'    => 'SKU',
			'gtin'   => 'GTIN',
			'gtin13' => 'GTIN',
			'mpn'    => 'MPN',
		) as $key => $label ) {
			$value = $this->text( $node, $key );
			if ( '' !== $value ) {
				$rows[] = array( $label, $value );
				break;
			}
		}

		return self::section( '' !== $name ? 'Product details: ' . $name : 'Product details', $rows );
	}

	/**
	 * FAQ questions and answers that aren't already on the page.
	 *
	 * @param array  $node     Node.
	 * @param string $haystack Normalized body.
	 * @return string
	 */
	private function faq( array $node, $haystack ) {
		$items = $this->resolve( isset( $node['mainEntity'] ) ? $node['mainEntity'] : array() );
		$items = is_array( $items ) && ! isset( $items[0] ) ? array( $items ) : (array) $items;
		$out   = array();

		foreach ( array_slice( $items, 0, 50 ) as $item ) {
			$item = $this->resolve( $item );
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = $this->text( $item, 'name' );
			$answer   = $this->first( $this->resolve( isset( $item['acceptedAnswer'] ) ? $item['acceptedAnswer'] : null ) );
			$answer   = is_array( $answer ) ? $this->text( $answer, 'text' ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$needle = self::normalize( $question );
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				continue;
			}

			$out[] = '### ' . self::escape( $question ) . "\n\n" . self::escape( $answer );
		}

		return $out ? "## Frequently asked questions\n\n" . implode( "\n\n", $out ) : '';
	}

	/**
	 * Business: address, phone, hours, price range.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function business( array $node ) {
		$rows = array();

		$address = $this->address( $this->resolve( isset( $node['address'] ) ? $node['address'] : null ) );
		if ( '' !== $address ) {
			$rows[] = array( 'Address', $address );
		}

		foreach ( array(
			'telephone'  => 'Phone',
			'email'      => 'Email',
			'priceRange' => 'Price range',
		) as $key => $label ) {
			$value = $this->text( $node, $key );
			if ( '' !== $value ) {
				$rows[] = array( $label, preg_replace( '/^mailto:/i', '', $value ) );
			}
		}

		$rating = $this->rating( $node );
		if ( '' !== $rating ) {
			$rows[] = array( 'Rating', $rating );
		}

		$hours = $this->hours( $node );
		$name  = $this->text( $node, 'name' );
		$text  = self::section( '' !== $name ? 'Business details: ' . $name : 'Business details', $rows );

		if ( $hours ) {
			$list = "**Opening hours:**\n\n- " . implode( "\n- ", array_map( array( __CLASS__, 'escape' ), $hours ) );
			$text = '' === $text ? '## Business details' . ( '' !== $name ? ': ' . self::escape( $name ) : '' ) . "\n\n" . $list : $text . "\n\n" . $list;
		}

		return $text;
	}

	/**
	 * Recipe: yield, times, calories, rating, and the ingredients when the
	 * page doesn't list them.
	 *
	 * @param array  $node     Node.
	 * @param string $haystack Normalized body.
	 * @return string
	 */
	private function recipe( array $node, $haystack ) {
		$rows = array();

		$yield = $this->text( $node, 'recipeYield' );
		if ( '' !== $yield ) {
			$rows[] = array( 'Yield', $yield );
		}

		foreach ( array(
			'prepTime'  => 'Prep time',
			'cookTime'  => 'Cook time',
			'totalTime' => 'Total time',
		) as $key => $label ) {
			$value = self::duration( $this->text( $node, $key ) );
			if ( '' !== $value ) {
				$rows[] = array( $label, $value );
			}
		}

		$nutrition = isset( $node['nutrition'] ) && is_array( $node['nutrition'] ) ? $this->text( $node['nutrition'], 'calories' ) : '';
		if ( '' !== $nutrition ) {
			$rows[] = array( 'Calories', $nutrition );
		}

		$rating = $this->rating( $node );
		if ( '' !== $rating ) {
			$rows[] = array( 'Rating', $rating );
		}

		$text        = self::section( 'Recipe details', $rows );
		$ingredients = isset( $node['recipeIngredient'] ) ? array_filter( array_map( array( __CLASS__, 'plain' ), (array) $node['recipeIngredient'] ) ) : array();

		if ( $ingredients ) {
			$missing = 0;
			foreach ( $ingredients as $ingredient ) {
				if ( false === strpos( $haystack, self::normalize( $ingredient ) ) ) {
					++$missing;
				}
			}

			if ( $missing > count( $ingredients ) / 2 ) {
				$list = "**Ingredients:**\n\n- " . implode( "\n- ", array_map( array( __CLASS__, 'escape' ), array_slice( $ingredients, 0, 60 ) ) );
				$text = '' === $text ? "## Recipe details\n\n" . $list : $text . "\n\n" . $list;
			}
		}

		return $text;
	}

	/**
	 * Event: dates, place, status, price.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function event( array $node ) {
		$rows = array();

		foreach ( array(
			'startDate' => 'Starts',
			'endDate'   => 'Ends',
		) as $key => $label ) {
			$value = $this->text( $node, $key );
			if ( '' !== $value ) {
				$rows[] = array( $label, $value );
			}
		}

		$location = $this->first( $this->resolve( isset( $node['location'] ) ? $node['location'] : null ) );
		if ( is_array( $location ) ) {
			$place   = $this->text( $location, 'name' );
			$address = $this->address( $this->resolve( isset( $location['address'] ) ? $location['address'] : null ) );
			$url     = $this->text( $location, 'url' );
			$where   = trim( $place . ( '' !== $address ? ', ' . $address : '' ), ', ' );
			if ( '' === $where && '' !== $url ) {
				$where = $url;
			}
			if ( '' !== $where ) {
				$rows[] = array( 'Location', $where );
			}
		}

		foreach ( array(
			'eventStatus'         => 'Status',
			'eventAttendanceMode' => 'Attendance',
		) as $key => $label ) {
			$value = $this->text( $node, $key );
			if ( '' !== $value ) {
				$rows[] = array( $label, self::enum_label( $value ) );
			}
		}

		$offer = $this->first( $this->resolve( isset( $node['offers'] ) ? $node['offers'] : null ) );
		if ( $offer ) {
			$price = $this->price( $offer );
			if ( '' !== $price ) {
				$rows[] = array( 'Price', $price );
			}
		}

		$name = $this->text( $node, 'name' );

		return self::section( '' !== $name ? 'Event details: ' . $name : 'Event details', $rows );
	}

	/**
	 * Software: price, rating, platform, category.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function software( array $node ) {
		$rows  = array();
		$offer = $this->first( $this->resolve( isset( $node['offers'] ) ? $node['offers'] : null ) );

		if ( $offer ) {
			$price = $this->price( $offer );
			if ( '' !== $price ) {
				$rows[] = array( 'Price', $price );
			}
		}

		$rating = $this->rating( $node );
		if ( '' !== $rating ) {
			$rows[] = array( 'Rating', $rating );
		}

		foreach ( array(
			'operatingSystem'     => 'Platform',
			'applicationCategory' => 'Category',
			'softwareVersion'     => 'Version',
		) as $key => $label ) {
			$value = $this->text( $node, $key );
			if ( '' !== $value ) {
				$rows[] = array( $label, self::enum_label( $value ) );
			}
		}

		$name = $this->text( $node, 'name' );

		return self::section( '' !== $name ? 'Software details: ' . $name : 'Software details', $rows );
	}

	/**
	 * Review: what was reviewed and the score.
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function review( array $node ) {
		$rows = array();

		$item = $this->first( $this->resolve( isset( $node['itemReviewed'] ) ? $node['itemReviewed'] : null ) );
		$name = is_array( $item ) ? $this->text( $item, 'name' ) : '';
		if ( '' !== $name ) {
			$rows[] = array( 'Reviewed', $name );
		}

		$score = $this->first( $this->resolve( isset( $node['reviewRating'] ) ? $node['reviewRating'] : null ) );
		if ( is_array( $score ) ) {
			$value = $this->text( $score, 'ratingValue' );
			$best  = $this->text( $score, 'bestRating' );
			if ( '' !== $value ) {
				$rows[] = array( 'Score', $value . ' out of ' . ( '' !== $best ? $best : '5' ) );
			}
		}

		$author = $this->first( $this->resolve( isset( $node['author'] ) ? $node['author'] : null ) );
		$author = is_array( $author ) ? $this->text( $author, 'name' ) : ( is_string( $author ) ? $author : '' );
		if ( '' !== $author ) {
			$rows[] = array( 'Reviewer', $author );
		}

		return self::section( 'Review details', $rows );
	}

	/**
	 * How-to: time, cost and supplies, plus the steps when the page doesn't
	 * show them.
	 *
	 * @param array  $node     Node.
	 * @param string $haystack Normalized body.
	 * @return string
	 */
	private function howto( array $node, $haystack ) {
		$rows = array();

		$time = self::duration( $this->text( $node, 'totalTime' ) );
		if ( '' !== $time ) {
			$rows[] = array( 'Time needed', $time );
		}

		$cost = isset( $node['estimatedCost'] ) && is_array( $node['estimatedCost'] ) ? $this->price( $node['estimatedCost'] ) : $this->text( $node, 'estimatedCost' );
		if ( '' !== $cost ) {
			$rows[] = array( 'Estimated cost', $cost );
		}

		foreach ( array(
			'supply' => 'Supplies',
			'tool'   => 'Tools',
		) as $key => $label ) {
			$names = $this->names( isset( $node[ $key ] ) ? $node[ $key ] : null );
			if ( $names ) {
				$rows[] = array( $label, implode( ', ', $names ) );
			}
		}

		$text  = self::section( 'How-to details', $rows );
		$steps = array();

		foreach ( array_slice( (array) ( isset( $node['step'] ) ? $node['step'] : array() ), 0, 50 ) as $step ) {
			$step = $this->resolve( $step );
			if ( is_array( $step ) ) {
				$value = $this->text( $step, 'text' );
				$value = '' !== $value ? $value : $this->text( $step, 'name' );
				if ( '' !== $value ) {
					$steps[] = $value;
				}
			}
		}

		if ( $steps && false === strpos( $haystack, self::normalize( $steps[0] ) ) ) {
			$list = "**Steps:**\n\n";
			foreach ( $steps as $i => $step ) {
				$list .= ( $i + 1 ) . '. ' . self::escape( $step ) . "\n";
			}
			$text = '' === $text ? "## How-to details\n\n" . rtrim( $list ) : $text . "\n\n" . rtrim( $list );
		}

		return $text;
	}

	// Value helpers -------------------------------------------------------------

	/**
	 * Follow an {"@id": ...} reference to the full node.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function resolve( $value ) {
		if ( is_array( $value ) && isset( $value['@id'] ) && is_string( $value['@id'] ) && count( $value ) <= 2 && isset( $this->by_id[ $value['@id'] ] ) ) {
			return $this->by_id[ $value['@id'] ];
		}

		return $value;
	}

	/**
	 * The first node of a list (or the node itself).
	 *
	 * @param mixed $value Value.
	 * @return array|null
	 */
	private function first( $value ) {
		if ( is_array( $value ) && isset( $value[0] ) ) {
			$value = $this->resolve( $value[0] );
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * A property as plain text.
	 *
	 * @param array|null $node Node.
	 * @param string     $key  Property.
	 * @return string
	 */
	private function text( $node, $key ) {
		if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
			return '';
		}

		$value = $node[ $key ];
		if ( is_array( $value ) ) {
			$value = isset( $value[0] ) && is_scalar( $value[0] ) ? $value[0] : ( isset( $value['name'] ) && is_scalar( $value['name'] ) ? $value['name'] : '' );
		}

		return is_scalar( $value ) ? self::plain( (string) $value ) : '';
	}

	/**
	 * Names from a list of things or strings (supplies, tools).
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private function names( $value ) {
		$value = is_array( $value ) && ! isset( $value[0] ) ? array( $value ) : (array) $value;
		$names = array();

		foreach ( array_slice( $value, 0, 30 ) as $item ) {
			$item = $this->resolve( $item );
			$name = is_array( $item ) ? $this->text( $item, 'name' ) : ( is_scalar( $item ) ? self::plain( (string) $item ) : '' );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * An offer's price ("29.00 USD", "19.00 to 49.00 USD").
	 *
	 * @param array $offer Offer or PriceSpecification.
	 * @return string
	 */
	private function price( array $offer ) {
		$currency = $this->text( $offer, 'priceCurrency' );
		$price    = $this->text( $offer, 'price' );

		if ( '' === $price && isset( $offer['priceSpecification'] ) && is_array( $offer['priceSpecification'] ) ) {
			$spec     = $this->first( $offer['priceSpecification'] );
			$price    = $this->text( $spec, 'price' );
			$currency = '' !== $currency ? $currency : $this->text( $spec, 'priceCurrency' );
		}

		if ( '' === $price ) {
			$low  = $this->text( $offer, 'lowPrice' );
			$high = $this->text( $offer, 'highPrice' );
			if ( '' !== $low && '' !== $high && $low !== $high ) {
				$price = $low . ' to ' . $high;
			} elseif ( '' !== $low ) {
				$price = $low;
			}
		}

		if ( '' === $price ) {
			return '';
		}

		if ( '0' === $price || '0.00' === $price ) {
			return 'Free';
		}

		return trim( $price . ' ' . $currency );
	}

	/**
	 * Aggregate rating ("4.6 out of 5 (128 reviews)").
	 *
	 * @param array $node Node.
	 * @return string
	 */
	private function rating( array $node ) {
		$rating = $this->first( $this->resolve( isset( $node['aggregateRating'] ) ? $node['aggregateRating'] : null ) );

		if ( ! is_array( $rating ) ) {
			return '';
		}

		$value = $this->text( $rating, 'ratingValue' );
		if ( '' === $value ) {
			return '';
		}

		$best  = $this->text( $rating, 'bestRating' );
		$count = $this->text( $rating, 'reviewCount' );
		$count = '' !== $count ? $count : $this->text( $rating, 'ratingCount' );
		$text  = $value . ' out of ' . ( '' !== $best ? $best : '5' );

		if ( '' !== $count ) {
			$text .= ' (' . $count . ' ' . ( '1' === $count ? 'review' : 'reviews' ) . ')';
		}

		return $text;
	}

	/**
	 * A postal address on one line.
	 *
	 * @param mixed $address PostalAddress node or string.
	 * @return string
	 */
	private function address( $address ) {
		if ( is_string( $address ) ) {
			return self::plain( $address );
		}

		$address = $this->first( $address );
		if ( ! is_array( $address ) ) {
			return '';
		}

		$region = trim( $this->text( $address, 'addressRegion' ) . ' ' . $this->text( $address, 'postalCode' ) );
		$parts  = array_filter(
			array(
				$this->text( $address, 'streetAddress' ),
				$this->text( $address, 'addressLocality' ),
				$region,
				$this->text( $address, 'addressCountry' ),
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Opening hours as readable lines.
	 *
	 * @param array $node Business node.
	 * @return string[]
	 */
	private function hours( array $node ) {
		$lines = array();
		$specs = isset( $node['openingHoursSpecification'] ) ? $node['openingHoursSpecification'] : array();
		$specs = is_array( $specs ) && ! isset( $specs[0] ) ? array( $specs ) : (array) $specs;

		foreach ( array_slice( $specs, 0, 30 ) as $spec ) {
			$spec = $this->resolve( $spec );
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$days = array();
			foreach ( (array) ( isset( $spec['dayOfWeek'] ) ? $spec['dayOfWeek'] : array() ) as $day ) {
				if ( is_string( $day ) ) {
					$days[] = self::enum_label( $day );
				}
			}

			$opens  = $this->text( $spec, 'opens' );
			$closes = $this->text( $spec, 'closes' );
			$label  = $days ? self::day_range( $days ) : 'Every day';

			if ( '' !== $opens && '' !== $closes ) {
				$lines[] = ( '00:00' === substr( $opens, 0, 5 ) && '00:00' === substr( $closes, 0, 5 ) ) ? $label . ': Closed' : $label . ': ' . substr( $opens, 0, 5 ) . ' to ' . substr( $closes, 0, 5 );
			}
		}

		if ( ! $lines && isset( $node['openingHours'] ) ) {
			foreach ( array_slice( (array) $node['openingHours'], 0, 30 ) as $hours ) {
				if ( is_string( $hours ) && '' !== trim( $hours ) ) {
					$lines[] = self::plain( $hours );
				}
			}
		}

		return $lines;
	}

	/**
	 * Days as a range when they run in order ("Monday to Friday").
	 *
	 * @param string[] $days Day names.
	 * @return string
	 */
	private static function day_range( array $days ) {
		$week  = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$index = array();

		foreach ( $days as $day ) {
			$position = array_search( $day, $week, true );
			if ( false === $position ) {
				return implode( ', ', $days );
			}
			$index[] = $position;
		}

		$count = count( $index );
		if ( $count >= 3 && range( $index[0], $index[0] + $count - 1 ) === $index ) {
			return $week[ $index[0] ] . ' to ' . $week[ $index[ $count - 1 ] ];
		}

		return implode( ', ', $days );
	}

	/**
	 * A heading plus "- **Label:** value" rows.
	 *
	 * @param string $title Heading.
	 * @param array  $rows  [label, value] pairs.
	 * @return string
	 */
	private static function section( $title, array $rows ) {
		if ( ! $rows ) {
			return '';
		}

		$lines = array( '## ' . self::escape( $title ), '' );
		foreach ( $rows as $row ) {
			$lines[] = '- **' . $row[0] . ':** ' . self::escape( $row[1] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Schema types of a node.
	 *
	 * @param array $node Node.
	 * @return string[]
	 */
	private static function types( array $node ) {
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();

		return array_values( array_filter( $types, 'is_string' ) );
	}

	/**
	 * "https://schema.org/InStock" to "In stock".
	 *
	 * @param string $value Enumeration URL or name.
	 * @return string
	 */
	private static function enum_label( $value ) {
		$value = preg_replace( '#^https?://schema\.org/#i', '', trim( $value ) );

		// Free text ("Web, iOS, Android") isn't an enumeration name.
		if ( ! preg_match( '/^[A-Z][A-Za-z]+$/', $value ) ) {
			return $value;
		}

		$words = trim( preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $value ) );

		return '' === $words ? '' : ucfirst( strtolower( $words ) );
	}

	/**
	 * ISO 8601 duration ("PT1H30M") as "1 hr 30 min".
	 *
	 * @param string $value Duration.
	 * @return string
	 */
	private static function duration( $value ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:\d+S)?)?$/i', trim( $value ), $m ) ) {
			return '' !== $value && ! preg_match( '/^P/i', $value ) ? $value : '';
		}

		$parts = array();
		if ( ! empty( $m[1] ) ) {
			$parts[] = (int) $m[1] . ( 1 === (int) $m[1] ? ' day' : ' days' );
		}
		if ( ! empty( $m[2] ) ) {
			$parts[] = (int) $m[2] . ' hr';
		}
		if ( ! empty( $m[3] ) ) {
			$parts[] = (int) $m[3] . ' min';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Strip markup and collapse whitespace. PHP's strip_tags() is a linear
	 * scan; core's wp_strip_all_tags() uses a regex that crafted text can
	 * make very slow.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function plain( $value ) {
		$value = html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Escape text for Markdown.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function escape( $value ) {
		return Markdown_Endpoints_AI_Converter::escape( $value );
	}

	/**
	 * Lower-case letters and digits only, for "is this already on the page".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = strtolower( (string) $text );
		$out  = preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );

		return null === $out ? preg_replace( '/[^a-z0-9]+/', '', $text ) : $out;
	}
}

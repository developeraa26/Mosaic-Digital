<?php
/**
 * The PDF quotation: layout on top of LP_Calc_PDF.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the quotation PDF.
 */
final class LP_Calc_Quote_PDF {

	const NAVY   = '#041950';
	const BLUE   = '#0f5cd8';
	const INK    = '#030922';
	const TEXT   = '#2c3651';
	const MUTED  = '#5b6684';
	const BORDER = '#dde5f4';
	const SOFT   = '#f6f8fd';
	const ACCENT = '#8fb8ff';
	const WHITE  = '#ffffff';

	const MARGIN = 40.0;
	/** Content must end above this line (the footer rule sits at PAGE_H - 50 = 791.9). */
	const LIMIT = 782.0;

	/** Space between the acceptance text and the signature lines. */
	const SIGN_GAP = 36.0;

	/** Top and bottom padding of a service row. */
	const ROW_PAD = 6.0;

	const DEFAULT_LOGO = '2026/09/Logo-Assests-01-edited.png';

	const FOOTER_TEXT = 'Ledger Plus UAE · DIFC, Dubai, United Arab Emirates · +971 50 342 0832 · info@ledgerplus.ae · ledgerplus.ae';

	const NOTES = array(
		'This is an indicative estimate based on the information you provided. Prices are annual and include 5% VAT.',
		'Any services outside the agreed scope will be quoted separately.',
		'Annual payment terms, onboarding and service commencement are subject to confirmation and a final service agreement.',
	);

	const ACCEPT_TEXT = 'To proceed, reply to the email this quotation came with, or contact us on +971 50 342 0832.';

	/** @var LP_Calc_PDF */
	private $pdf;

	/** @var float Cursor, from the top of the page. */
	private $y = 0.0;

	/** @var array */
	private $data;

	/** @var float Content width. */
	private $w;

	/** @var string|null|false JPEG logo cache (false = not loaded yet). */
	private static $logo = false;

	/**
	 * @param array $data See render().
	 */
	private function __construct( array $data ) {
		$this->data = $data;
		$this->w    = LP_Calc_PDF::PAGE_W - 2 * self::MARGIN;
	}

	/**
	 * Builds the quotation.
	 *
	 * @param array $data {
	 *     @type string $ref       Quote reference.
	 *     @type string $date      Optional date text (default: today, site timezone, "j F Y").
	 *     @type array  $contact   name, company, email, phone.
	 *     @type array  $selection From LP_Calc_Pricing::normalize().
	 *     @type array  $quote     From LP_Calc_Pricing::compute().
	 * }
	 * @return string PDF bytes.
	 */
	public static function render( array $data ) {
		$self = new self( $data );
		return $self->build();
	}

	/**
	 * @param string $ref Quote reference.
	 * @return string For example "Ledger-Plus-Quotation-LPQ-261127-4F2A9.pdf".
	 */
	public static function filename( $ref ) {
		return 'Ledger-Plus-Quotation-' . preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $ref ) . '.pdf';
	}

	/**
	 * Today's date (or $timestamp) in the site timezone, English month names.
	 *
	 * @param int|null $timestamp Unix time.
	 * @return string For example "27 November 2026".
	 */
	public static function date_text( $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		try {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Dubai' );
		} catch ( Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$date = new DateTime( '@' . $timestamp );
		$date->setTimezone( $tz );
		return $date->format( 'j F Y' );
	}

	/* ------------------------------------------------------------------ Build */

	/**
	 * @return string PDF bytes.
	 */
	private function build() {
		$ref       = (string) $this->data['ref'];
		$this->pdf = new LP_Calc_PDF(
			array(
				'title'   => 'Ledger Plus quotation estimate ' . $ref,
				'subject' => 'Package estimate',
				'author'  => 'Ledger Plus UAE',
			)
		);
		$this->pdf->add_page();
		$this->header();
		$this->cards();
		$this->services();
		$this->notes();
		$this->acceptance();
		$this->footers();
		return $this->pdf->output();
	}

	/**
	 * Starts a new page if $height does not fit.
	 *
	 * @param float $height Points needed.
	 */
	private function ensure( $height ) {
		if ( $this->y + $height > self::LIMIT ) {
			$this->pdf->add_page();
			$this->continuation_header();
		}
	}

	/* ------------------------------------------------------------------ Sections */

	private function header() {
		$pdf = $this->pdf;
		$m   = self::MARGIN;
		$r   = LP_Calc_PDF::PAGE_W - $m;

		$this->logo( $m, 38, 130, 46 );

		$pdf->text( $r, 52, 'QUOTATION ESTIMATE', array( 'size' => 15, 'bold' => true, 'color' => self::NAVY, 'align' => 'right', 'spacing' => 0.8 ) );
		$this->meta_line( $r, 68, 'Reference', (string) $this->data['ref'] );
		$date = isset( $this->data['date'] ) && '' !== (string) $this->data['date'] ? (string) $this->data['date'] : self::date_text();
		$this->meta_line( $r, 81, 'Date', $date );

		$pdf->rect( $m, 94, $this->w, 2, self::BLUE );
		$this->y = 114;
	}

	/**
	 * Right-aligned "Label  value" pair.
	 *
	 * @param float  $right Right edge.
	 * @param float  $y     Baseline.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function meta_line( $right, $y, $label, $value ) {
		$w = $this->pdf->text( $right, $y, $value, array( 'size' => 9, 'bold' => true, 'color' => self::INK, 'align' => 'right' ) );
		$this->pdf->text( $right - $w - 6, $y, $label, array( 'size' => 9, 'color' => self::MUTED, 'align' => 'right' ) );
	}

	/**
	 * Logo, or the text wordmark.
	 *
	 * @param float $x     Left.
	 * @param float $y     Top.
	 * @param float $max_w Width.
	 * @param float $max_h Maximum height.
	 */
	private function logo( $x, $y, $max_w, $max_h ) {
		$jpeg = self::logo_jpeg();
		if ( null !== $jpeg ) {
			$size = @getimagesizefromstring( $jpeg ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $size && $size[0] > 0 && $size[1] > 0 ) {
				$w = $max_w;
				$h = $w * $size[1] / $size[0];
				if ( $h > $max_h ) {
					$h = $max_h;
					$w = $h * $size[0] / $size[1];
				}
				if ( false !== $this->pdf->jpeg( $jpeg, $x, $y + ( $max_h - $h ) / 2, $w, $h ) ) {
					return;
				}
			}
		}
		// Wordmark: "LEDGER" navy + "PLUS" blue, bold.
		$base = $y + $max_h / 2 + 8;
		$lw   = $this->pdf->text( $x, $base, 'LEDGER', array( 'size' => 22, 'bold' => true, 'color' => self::NAVY, 'spacing' => 0.5 ) );
		$this->pdf->text( $x + $lw + 7, $base, 'PLUS', array( 'size' => 22, 'bold' => true, 'color' => self::BLUE, 'spacing' => 0.5 ) );
	}

	private function continuation_header() {
		$m = self::MARGIN;
		$this->pdf->text( $m, 50, 'Quotation estimate ' . $this->data['ref'] . ' (continued)', array( 'size' => 8.5, 'bold' => true, 'color' => self::NAVY ) );
		$this->pdf->text( LP_Calc_PDF::PAGE_W - $m, 50, 'Ledger Plus UAE', array( 'size' => 8.5, 'color' => self::MUTED, 'align' => 'right' ) );
		$this->pdf->line( $m, 60, LP_Calc_PDF::PAGE_W - $m, 60, self::BORDER, 0.75 );
		$this->y = 78;
	}

	/**
	 * Section title.
	 *
	 * @param string $title Title.
	 */
	private function heading( $title ) {
		$this->pdf->text( self::MARGIN, $this->y + 8, strtoupper( $title ), array( 'size' => 8.5, 'bold' => true, 'color' => self::BLUE, 'spacing' => 0.9 ) );
		$this->y += 17;
	}

	/**
	 * A user value the PDF can show.
	 *
	 * @param string $key Contact key.
	 * @return string
	 */
	private function contact( $key ) {
		$value = isset( $this->data['contact'][ $key ] ) ? (string) $this->data['contact'][ $key ] : '';
		$value = LP_Calc_PDF::printable( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
		// The email is the recipient's own, validated address; everything else is free text.
		return 'email' === $key ? $value : self::defang( $value );
	}

	/**
	 * @return string Business activity for the PDF.
	 */
	private function business() {
		$sel      = $this->data['selection'];
		$business = isset( $sel['business'] ) ? (string) $sel['business'] : '';
		if ( 'Other' === $business && ! empty( $sel['business_other'] ) ) {
			$other = self::defang( LP_Calc_PDF::printable( (string) $sel['business_other'], '' ) );
			return '' === $other ? 'Other' : 'Other (' . $other . ')';
		}
		return '' === $business ? '—' : $business;
	}

	/**
	 * Breaks up anything a PDF viewer would turn into a link ("https://", "www.", "mailto:",
	 * an email address). The form already rejects such text in the name, company and "Other"
	 * activity; this is the second line of defence. WinAnsi has no zero-width space, so the
	 * pieces are split with ordinary spaces.
	 *
	 * @param string $text Visitor text.
	 * @return string
	 */
	public static function defang( $text ) {
		$text = (string) preg_replace( '#:(?=//)#', ': ', (string) $text );
		$text = (string) preg_replace( '#\b(www|mailto)([.:])#i', '$1 $2', $text );
		return str_replace( '@', ' @ ', $text );
	}

	/**
	 * Lays out the label/value rows of a card.
	 *
	 * @param array $rows  [label, value, bold].
	 * @param float $w     Card width.
	 * @return array{lw: float, vw: float, heights: float[], height: float}
	 */
	private function card_layout( array $rows, $w ) {
		$lw = 0;
		foreach ( $rows as $row ) {
			$lw = max( $lw, $this->pdf->text_width( $row[0], 8.5 ) );
		}
		$lw     += 12;
		$vw      = $w - 2 * 14 - $lw;
		$heights = array();
		foreach ( $rows as $row ) {
			$heights[] = $this->pdf->box_height( $row[1], $vw, array( 'size' => 9.5, 'leading' => 14, 'bold' => ! empty( $row[2] ) ) );
		}
		return array(
			'lw'      => $lw,
			'vw'      => $vw,
			'heights' => $heights,
			'height'  => 2 * 12 + array_sum( $heights ) + 5 * ( count( $heights ) - 1 ),
		);
	}

	/**
	 * "Prepared for" and "Business profile", side by side.
	 */
	private function cards() {
		$gap   = 16;
		$cw    = ( $this->w - $gap ) / 2;
		$cards = array(
			array(
				'Prepared for',
				array(
					array( 'Name', $this->contact( 'name' ), true ),
					array( 'Company', $this->contact( 'company' ) ),
					array( 'Email', $this->contact( 'email' ) ),
					array( 'Phone', $this->contact( 'phone' ) ),
					array( 'Business activity', $this->business() ),
				),
			),
			array( 'Business profile', LP_Calc_Pricing::profile_rows( $this->data['selection'] ) ),
		);
		$layouts = array();
		$box_h   = 0;
		foreach ( $cards as $i => $card ) {
			$layouts[ $i ] = $this->card_layout( $card[1], $cw );
			$box_h         = max( $box_h, $layouts[ $i ]['height'] );
		}

		$this->ensure( 17 + $box_h );
		foreach ( $cards as $i => $card ) {
			$x      = self::MARGIN + $i * ( $cw + $gap );
			$layout = $layouts[ $i ];
			$this->pdf->text( $x, $this->y + 8, strtoupper( $card[0] ), array( 'size' => 8.5, 'bold' => true, 'color' => self::BLUE, 'spacing' => 0.9 ) );
			$this->pdf->rounded_rect( $x, $this->y + 17, $cw, $box_h, 8, self::SOFT, self::BORDER, 0.75 );
			$y = $this->y + 17 + 12;
			foreach ( $card[1] as $j => $row ) {
				$this->pdf->text_box( $x + 14, $y, $layout['lw'], $row[0], array( 'size' => 8.5, 'leading' => 14, 'color' => self::MUTED ) );
				$this->pdf->text_box( $x + 14 + $layout['lw'], $y, $layout['vw'], $row[1], array( 'size' => 9.5, 'leading' => 14, 'color' => self::INK, 'bold' => ! empty( $row[2] ) ) );
				$y += $layout['heights'][ $j ] + 5;
			}
		}
		$this->y += 17 + $box_h + 20;
	}

	private function table_header() {
		$m   = self::MARGIN;
		$pad = 12;
		$o   = array( 'size' => 7, 'bold' => true, 'color' => self::MUTED, 'spacing' => 0.6 );
		$this->pdf->rect( $m, $this->y, $this->w, 22, self::WHITE );
		$this->pdf->text( $m + $pad, $this->y + 14, 'SERVICE', $o );
		$this->pdf->text( $m + $this->w - $pad, $this->y + 14, 'ANNUAL FEE (AED)', $o + array( 'align' => 'right' ) );
		$this->pdf->line( $m, $this->y + 22, $m + $this->w, $this->y + 22, self::NAVY, 1 );
		$this->y += 22;
	}

	/**
	 * @param array $line Quote line.
	 * @return array{0:float,1:float} Label height, detail height.
	 */
	private function row_parts( array $line ) {
		$tw = $this->w - 2 * 12 - 120;
		$lh = $this->pdf->box_height( $line['label'], $tw, array( 'size' => 10, 'bold' => true, 'leading' => 13 ) );
		$dh = '' === self::detail( $line ) ? 0.0 : $this->pdf->box_height( $line['detail'], $tw, array( 'size' => 8.5, 'leading' => 11 ) );
		return array( $lh, $dh );
	}

	/**
	 * The detail line of a quote line, or '' when it would only repeat the amount ("Included").
	 *
	 * @param array $line Quote line.
	 * @return string
	 */
	public static function detail( array $line ) {
		$detail = (string) $line['detail'];
		return 0 === strcasecmp( $detail, LP_Calc_Pricing::line_amount_text( $line ) ) ? '' : $detail;
	}

	private function services() {
		$lines = $this->data['quote']['lines'];
		$m     = self::MARGIN;
		$pad   = 12;
		$tw    = $this->w - 2 * $pad - 120;

		$first = $lines ? $this->row_parts( $lines[0] ) : array( 0, 0 );
		$this->ensure( 17 + 22 + 16 + $first[0] + $first[1] );
		$this->heading( 'Selected services and annual charges' );
		$this->table_header();

		$last = count( $lines ) - 1;
		$rp   = self::ROW_PAD;
		foreach ( $lines as $i => $line ) {
			list( $lh, $dh ) = $this->row_parts( $line );
			$row_h           = $rp + $lh + $dh + $rp;
			// The last row stays on the same page as the total bar.
			$need = $row_h + ( $i === $last ? 10 + 44 : 0 );
			if ( $this->y + $need > self::LIMIT ) {
				$this->pdf->add_page();
				$this->continuation_header();
				$this->table_header();
			}
			if ( 1 === $i % 2 ) {
				$this->pdf->rect( $m, $this->y, $this->w, $row_h, self::SOFT );
			}
			$this->pdf->text_box( $m + $pad, $this->y + $rp, $tw, $line['label'], array( 'size' => 10, 'bold' => true, 'leading' => 13, 'color' => self::INK ) );
			if ( $dh > 0 ) {
				$this->pdf->text_box( $m + $pad, $this->y + $rp + $lh, $tw, $line['detail'], array( 'size' => 8.5, 'leading' => 11, 'color' => self::MUTED ) );
			}
			$free   = 0 === (int) $line['amount'];
			$amount = $free ? 'Included' : number_format( (int) $line['amount'] );
			$this->pdf->text(
				$m + $this->w - $pad,
				$this->y + $rp + 13 / 2 + 0.355 * 10,
				$amount,
				array( 'size' => 10, 'bold' => true, 'color' => $free ? self::BLUE : self::INK, 'align' => 'right' )
			);
			$this->y += $row_h;
			$this->pdf->line( $m, $this->y, $m + $this->w, $this->y, self::BORDER, 0.75 );
		}

		// Total bar.
		$h = 44;
		if ( $this->y + 10 + $h > self::LIMIT ) {
			$this->pdf->add_page();
			$this->continuation_header();
		} else {
			$this->y += 10;
		}
		$this->pdf->rounded_rect( $m, $this->y, $this->w, $h, 8, self::NAVY );
		$this->pdf->text( $m + 16, $this->y + 26.5, LP_Calc_Pricing::TOTAL_LABEL, array( 'size' => 10.5, 'bold' => true, 'color' => self::WHITE ) );
		$this->pdf->text( $m + $this->w - 16, $this->y + 28, LP_Calc_Pricing::money( (int) $this->data['quote']['total'] ), array( 'size' => 16, 'bold' => true, 'color' => self::WHITE, 'align' => 'right' ) );
		$this->y += $h + 20;
	}

	private function notes() {
		$tw = $this->w - 12;
		$o  = array( 'size' => 8.5, 'leading' => 12, 'color' => self::MUTED );
		$h  = 0;
		foreach ( self::NOTES as $note ) {
			$h += $this->pdf->box_height( $note, $tw, $o ) + 3;
		}
		// The notes travel with the acceptance block, so a page never holds the signatures alone.
		$this->ensure( 17 + $h + 16 + $this->acceptance_height() );
		$this->heading( 'Notes' );
		foreach ( self::NOTES as $note ) {
			$this->pdf->rounded_rect( self::MARGIN + 1, $this->y + 4.6, 2.8, 2.8, 1.4, self::BLUE );
			$this->y += $this->pdf->text_box( self::MARGIN + 12, $this->y, $tw, $note, $o ) + 3;
		}
		$this->y += 16;
	}

	/**
	 * @return array Text options of the acceptance paragraph.
	 */
	private static function accept_options() {
		return array( 'size' => 9, 'leading' => 13, 'color' => self::TEXT );
	}

	/**
	 * Height the acceptance block draws: heading, text, signature gap, caption line (baseline
	 * 13pt under the rule, plus descenders).
	 *
	 * @return float
	 */
	private function acceptance_height() {
		return 17 + $this->pdf->box_height( self::ACCEPT_TEXT, $this->w, self::accept_options() ) + self::SIGN_GAP + 16;
	}

	private function acceptance() {
		$text = self::ACCEPT_TEXT;
		$o    = self::accept_options();
		$this->ensure( $this->acceptance_height() );
		$this->heading( 'Quotation acceptance' );
		$this->y += $this->pdf->text_box( self::MARGIN, $this->y, $this->w, $text, $o );

		$gap  = 40;
		$colw = ( $this->w - $gap ) / 2;
		$ly   = $this->y + self::SIGN_GAP;
		$caps = array( 'For Ledger Plus', 'For the customer: name / signature / date' );
		foreach ( $caps as $i => $cap ) {
			$x = self::MARGIN + $i * ( $colw + $gap );
			$this->pdf->line( $x, $ly, $x + $colw, $ly, self::MUTED, 0.75 );
			$this->pdf->text( $x, $ly + 13, $cap, array( 'size' => 8.5, 'bold' => true, 'color' => self::INK ) );
		}
		$this->y = $ly + 24;
	}

	/** Footer on every page. */
	private function footers() {
		$m     = self::MARGIN;
		$r     = LP_Calc_PDF::PAGE_W - $m;
		$total = $this->pdf->page_count();
		for ( $i = 0; $i < $total; $i++ ) {
			$this->pdf->set_page( $i );
			$y = LP_Calc_PDF::PAGE_H - 50;
			$this->pdf->line( $m, $y, $r, $y, self::BORDER, 0.75 );
			$this->pdf->text( $m, $y + 15, self::FOOTER_TEXT, array( 'size' => 7.5, 'color' => self::MUTED ) );
			$this->pdf->text( $r, $y + 15, 'Page ' . ( $i + 1 ) . ' of ' . $total, array( 'size' => 7.5, 'bold' => true, 'color' => self::NAVY, 'align' => 'right' ) );
		}
	}

	/* ------------------------------------------------------------------ Logo */

	/**
	 * Path of the logo file: the `lp_calc_logo_path` filter, else the uploads file.
	 *
	 * @return string
	 */
	public static function logo_path() {
		$default = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
				$default = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::DEFAULT_LOGO;
			}
		}
		$path = apply_filters( 'lp_calc_logo_path', $default );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * The logo as a JPEG on white (quality 90), or null when GD or the file is missing.
	 *
	 * @return string|null
	 */
	public static function logo_jpeg() {
		$path = self::logo_path();
		$key  = $path . '|' . ( '' !== $path && is_file( $path ) ? filemtime( $path ) : '' );
		if ( is_array( self::$logo ) && $key === self::$logo['key'] ) {
			return self::$logo['jpeg'];
		}
		$jpeg       = self::load_logo( $path );
		self::$logo = array(
			'key'  => $key,
			'jpeg' => $jpeg,
		);
		return $jpeg;
	}

	/**
	 * @param string $path Image file.
	 * @return string|null JPEG bytes.
	 */
	private static function load_logo( $path ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return null;
		}
		$gd = function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagecopyresampled' );
		if ( ! $gd ) {
			// Without GD a JPEG file can still be embedded as it is.
			if ( IMAGETYPE_JPEG === $info[2] ) {
				$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false === $raw ? null : $raw;
			}
			return null;
		}

		$src = false;
		switch ( $info[2] ) {
			case IMAGETYPE_PNG:
				$src = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_JPEG:
				$src = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_GIF:
				$src = function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			case IMAGETYPE_WEBP:
				$src = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
		}
		if ( ! $src ) {
			return null;
		}
		if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $src ) && function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $src );
		}
		$w  = imagesx( $src );
		$h  = imagesy( $src );
		$tw = min( $w, 1000 );
		$th = max( 1, (int) round( $h * $tw / max( 1, $w ) ) );

		// Flatten the alpha channel onto white.
		$dst = imagecreatetruecolor( $tw, $th );
		imagefilledrectangle( $dst, 0, 0, $tw, $th, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagealphablending( $dst, true );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h );
		$dst = self::trim_white( $dst );

		ob_start();
		$ok   = imagejpeg( $dst, null, 90 );
		$jpeg = ob_get_clean();
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $src ); // phpcs:ignore PHPCompatibility
			imagedestroy( $dst ); // phpcs:ignore PHPCompatibility
		}
		return ( $ok && is_string( $jpeg ) && '' !== $jpeg ) ? $jpeg : null;
	}

	/**
	 * Crops white margins, so the artwork lines up with the page margin.
	 *
	 * @param resource|GdImage $im Truecolor image on white.
	 * @return resource|GdImage
	 */
	private static function trim_white( $im ) {
		$w = imagesx( $im );
		$h = imagesy( $im );
		$x0 = $w;
		$y0 = $h;
		$x1 = -1;
		$y1 = -1;
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c = imagecolorat( $im, $x, $y );
				if ( ( ( $c >> 16 ) & 255 ) < 245 || ( ( $c >> 8 ) & 255 ) < 245 || ( $c & 255 ) < 245 ) {
					if ( $x < $x0 ) {
						$x0 = $x;
					}
					if ( $x > $x1 ) {
						$x1 = $x;
					}
					if ( $y < $y0 ) {
						$y0 = $y;
					}
					$y1 = $y;
				}
			}
		}
		if ( $x1 < 0 || ! function_exists( 'imagecrop' ) ) {
			return $im; // Blank image: leave it.
		}
		$pad  = 2;
		$x0   = max( 0, $x0 - $pad );
		$y0   = max( 0, $y0 - $pad );
		$crop = imagecrop(
			$im,
			array(
				'x'      => $x0,
				'y'      => $y0,
				'width'  => min( $w, $x1 + $pad + 1 ) - $x0,
				'height' => min( $h, $y1 + $pad + 1 ) - $y0,
			)
		);
		return $crop ? $crop : $im;
	}

	/**
	 * Clears the logo cache (tests).
	 */
	public static function reset_logo_cache() {
		self::$logo = false;
	}
}

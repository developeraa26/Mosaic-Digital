<?php
/**
 * A small PDF 1.4 writer in pure PHP.
 *
 * A4 pages, the core fonts Helvetica and Helvetica-Bold (WinAnsiEncoding), filled and stroked
 * shapes, JPEG images (DCTDecode) and FlateDecode content streams. Coordinates passed to the
 * public methods are in points from the TOP-LEFT corner of the page (y grows downwards); the
 * writer converts them to PDF user space.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generic PDF writer.
 */
final class LP_Calc_PDF {

	const PAGE_W = 595.28;

	/** Legal forms that do not make a name readable on their own (see printable()). */
	const LEGAL_FORMS = array( 'LLC', 'LLP', 'FZE', 'FZCO', 'FZC', 'FZ', 'FZLLC', 'LTD', 'CO', 'EST', 'PLC', 'INC', 'PJSC', 'PSC', 'SPC', 'DMCC', 'BRANCH' );
	const PAGE_H = 841.89;

	/**
	 * Standard Adobe AFM glyph widths (1/1000 em) for WinAnsiEncoding codes 32-255.
	 * Codes WinAnsi leaves undefined (127, 129, 141, 143, 144, 157) use the bullet width,
	 * which is what viewers draw for them.
	 */
	const WIDTHS_REGULAR = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, // 32
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, // 48
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, // 64
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, // 80
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, // 96
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584, 350, // 112
		556, 350, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350, // 128
		350, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 350, 500, 667, // 144
		278, 333, 556, 556, 556, 556, 260, 556, 333, 737, 370, 556, 584, 333, 737, 333, // 160
		400, 584, 333, 333, 333, 556, 537, 278, 333, 333, 365, 556, 834, 834, 834, 611, // 176
		667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, // 192
		722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, // 208
		556, 556, 556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, // 224
		556, 556, 556, 556, 556, 556, 556, 584, 611, 556, 556, 556, 556, 500, 556, 500, // 240
	);

	const WIDTHS_BOLD = array(
		278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, // 32
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, // 48
		975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, // 64
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, // 80
		333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, // 96
		611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584, 350, // 112
		556, 350, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333, 1000, 350, 611, 350, // 128
		350, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 350, 500, 667, // 144
		278, 333, 556, 556, 556, 556, 280, 556, 333, 737, 370, 556, 584, 333, 737, 333, // 160
		400, 584, 333, 333, 333, 611, 556, 278, 333, 333, 365, 556, 834, 834, 834, 611, // 176
		722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278, 278, 278, 278, // 192
		722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, // 208
		556, 556, 556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, // 224
		611, 611, 611, 611, 611, 611, 611, 584, 611, 611, 611, 611, 611, 556, 611, 556, // 240
	);

	/** @var string[] Content stream of each page. */
	private $pages = array();

	/** @var int Index of the page being drawn on. */
	private $page = -1;

	/** @var array<string,array> Images by md5 of their data. */
	private $images = array();

	/** @var array<string,string> Document information (title, subject, author). */
	private $info = array();

	/** @var array<string,string> UTF-8 => WinAnsi conversion cache. */
	private static $cache = array();

	/**
	 * @param array $info Optional title, subject, author (UTF-8).
	 */
	public function __construct( array $info = array() ) {
		$this->info = $info;
	}

	/* ------------------------------------------------------------------ Pages */

	/**
	 * Adds a page and makes it current.
	 *
	 * @return int Index of the new page (0-based).
	 */
	public function add_page() {
		$this->pages[] = '';
		$this->page    = count( $this->pages ) - 1;
		return $this->page;
	}

	/** @return int */
	public function page_count() {
		return count( $this->pages );
	}

	/** @return int */
	public function current_page() {
		return $this->page;
	}

	/**
	 * Makes an existing page current, for example to draw "Page n of N" footers at the end.
	 *
	 * @param int $index 0-based page index.
	 */
	public function set_page( $index ) {
		if ( ! isset( $this->pages[ $index ] ) ) {
			throw new InvalidArgumentException( 'No such page.' );
		}
		$this->page = (int) $index;
	}

	/**
	 * @param string $ops Raw content-stream operators.
	 */
	private function out( $ops ) {
		if ( $this->page < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->page ] .= $ops . "\n";
	}

	/* ------------------------------------------------------------------ Text encoding */

	/**
	 * UTF-8 to Windows-1252 bytes, as used by WinAnsiEncoding.
	 *
	 * @param string $utf8 Text.
	 * @return string
	 */
	public static function to_winansi( $utf8 ) {
		$s = (string) $utf8;
		if ( '' === $s || ! preg_match( '/[^\x00-\x7F]/', $s ) ) {
			return $s;
		}
		if ( isset( self::$cache[ $s ] ) ) {
			return self::$cache[ $s ];
		}
		$out = false;
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( false === $out ) {
			// Some iconv builds return false for //IGNORE. Convert one character at a time instead.
			$out = '';
			foreach ( self::chars( $s ) as $c ) {
				$out .= self::char_to_winansi( $c );
			}
		}
		if ( count( self::$cache ) > 500 ) {
			self::$cache = array();
		}
		self::$cache[ $s ] = $out;
		return $out;
	}

	/**
	 * A user-supplied value as text the core fonts can show. Characters they cannot show
	 * (for example Arabic) are dropped, with the punctuation that only sat between them
	 * ("Test Co ذ.م.م" becomes "Test Co", "Trading (النور) LLC" becomes "Trading LLC").
	 * If fewer than two letters are left, not counting legal forms such as "LLC" (so
	 * "شركة الأمل LLC" does not become just "LLC"), returns $fallback.
	 *
	 * @param string $utf8     User text.
	 * @param string $fallback Shown instead (UTF-8).
	 * @return string UTF-8.
	 */
	public static function printable( $utf8, $fallback = '—' ) {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', (string) $utf8 ) );
		if ( '' === $s ) {
			return $fallback;
		}
		$chars = self::chars( $s );
		if ( ! $chars ) {
			return $fallback;
		}
		// Classify: K kept letter/digit, D dropped, P kept punctuation/symbol, S space.
		$kind  = array();
		$lost  = false;
		$words = 0;
		foreach ( $chars as $i => $c ) {
			$is_word = (bool) preg_match( '/[\p{L}\p{N}]/u', $c );
			if ( ' ' === $c ) {
				$kind[ $i ] = 'S';
			} elseif ( 1 === strlen( $c ) ) {
				$kind[ $i ] = $is_word ? 'K' : 'P';
			} else {
				$b = self::char_to_winansi( $c );
				if ( '' === $b || '?' === $b || '*' === $b ) {
					$kind[ $i ] = 'D';
					$lost       = true;
				} else {
					$kind[ $i ] = $is_word ? 'K' : 'P';
				}
			}
			$words += ( 'K' === $kind[ $i ] && $is_word ) ? 1 : 0;
		}
		if ( ! $lost ) {
			return 0 === $words ? $fallback : $s;
		}
		// A run of punctuation and spaces with no kept letter or digit on either side only
		// separated dropped characters: drop its punctuation too.
		$n = count( $chars );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'P' !== $kind[ $i ] && 'S' !== $kind[ $i ] ) {
				continue;
			}
			$j = $i;
			while ( $j < $n && ( 'P' === $kind[ $j ] || 'S' === $kind[ $j ] ) ) {
				$j++;
			}
			$before = $i > 0 ? $kind[ $i - 1 ] : '';
			$after  = $j < $n ? $kind[ $j ] : '';
			if ( 'K' !== $before && 'K' !== $after ) {
				for ( $k = $i; $k < $j; $k++ ) {
					if ( 'P' === $kind[ $k ] ) {
						$kind[ $k ] = 'D';
					}
				}
			}
			$i = $j - 1;
		}
		$keep = '';
		foreach ( $chars as $i => $c ) {
			if ( 'D' !== $kind[ $i ] ) {
				$keep .= $c;
			}
		}
		$keep = self::balance_brackets( $keep );
		$keep = (string) preg_replace( '/\(\s*\)|\[\s*\]|\{\s*\}/u', ' ', $keep );
		$keep = (string) preg_replace( '/\s+/u', ' ', $keep );
		$keep = (string) preg_replace( '/^[\s\-–—·,;:\/|&+]+|[\s\-–—·,;:\/|&+]+$/u', '', $keep );
		$letters = 0;
		foreach ( preg_split( '/[\s(),\-\/&]+/u', $keep, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( ! in_array( strtoupper( str_replace( '.', '', $token ) ), self::LEGAL_FORMS, true ) ) {
				$letters += (int) preg_match_all( '/\p{L}/u', $token );
			}
		}
		if ( $letters < 2 || '' === $keep ) {
			return $fallback;
		}
		return $keep;
	}

	/**
	 * Removes brackets left without their partner (their other half sat among dropped characters).
	 *
	 * @param string $s UTF-8.
	 * @return string
	 */
	private static function balance_brackets( $s ) {
		$chars = self::chars( $s );
		$pairs = array(
			')' => '(',
			']' => '[',
			'}' => '{',
		);
		$open  = array();
		$drop  = array();
		foreach ( $chars as $i => $c ) {
			if ( in_array( $c, $pairs, true ) ) {
				$open[] = $i;
			} elseif ( isset( $pairs[ $c ] ) ) {
				$last = end( $open );
				if ( false !== $last && $chars[ $last ] === $pairs[ $c ] ) {
					array_pop( $open );
				} else {
					$drop[ $i ] = true;
				}
			}
		}
		foreach ( $open as $i ) {
			$drop[ $i ] = true;
		}
		$out = '';
		foreach ( $chars as $i => $c ) {
			$out .= isset( $drop[ $i ] ) ? '' : $c;
		}
		return $out;
	}

	/**
	 * @param string $s UTF-8.
	 * @return string[] Characters, or an empty array for invalid UTF-8.
	 */
	private static function chars( $s ) {
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $chars ) ? $chars : array();
	}

	/**
	 * @param string $c One UTF-8 character.
	 * @return string WinAnsi bytes ('?' when it cannot be shown).
	 */
	private static function char_to_winansi( $c ) {
		if ( 1 === strlen( $c ) ) {
			return ord( $c ) < 0x80 ? $c : '';
		}
		$r = false;
		if ( function_exists( 'iconv' ) ) {
			$r = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $c ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ( false === $r || '' === $r ) && function_exists( 'mb_convert_encoding' ) ) {
			$r = @mb_convert_encoding( $c, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return ( false === $r || '' === $r ) ? '?' : $r;
	}

	/**
	 * @param string $utf8 Text with line breaks and tabs.
	 * @return string WinAnsi bytes of one line (control characters become spaces).
	 */
	private static function line_bytes( $utf8 ) {
		return (string) preg_replace( '/[\x00-\x1F]/', ' ', self::to_winansi( $utf8 ) );
	}

	/* ------------------------------------------------------------------ Measuring */

	/**
	 * Width of WinAnsi bytes in points.
	 *
	 * @param string $bytes   WinAnsi bytes.
	 * @param float  $size    Font size.
	 * @param bool   $bold    Helvetica-Bold.
	 * @param float  $spacing Extra space after each character (Tc).
	 * @return float
	 */
	private static function bytes_width( $bytes, $size, $bold = false, $spacing = 0.0 ) {
		$widths = $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
		$units  = 0;
		$len    = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$code = ord( $bytes[ $i ] );
			if ( $code >= 32 ) {
				$units += $widths[ $code - 32 ];
			}
		}
		return $units * $size / 1000 + ( $len > 1 ? ( $len - 1 ) * $spacing : 0 );
	}

	/**
	 * Width of UTF-8 text in points.
	 *
	 * @param string $utf8    Text.
	 * @param float  $size    Font size.
	 * @param bool   $bold    Helvetica-Bold.
	 * @param float  $spacing Character spacing.
	 * @return float
	 */
	public function text_width( $utf8, $size, $bold = false, $spacing = 0.0 ) {
		return self::bytes_width( self::line_bytes( $utf8 ), $size, $bold, $spacing );
	}

	/**
	 * Splits text into lines that fit $width. Honours "\n"; breaks over-long words.
	 *
	 * @param string $utf8  Text.
	 * @param float  $width Points.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Helvetica-Bold.
	 * @return string[] Lines as WinAnsi bytes.
	 */
	private static function wrap_bytes( $utf8, $width, $size, $bold = false ) {
		$lines = array();
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $utf8 );
		foreach ( explode( "\n", $text ) as $para ) {
			$bytes = trim( self::line_bytes( $para ), ' ' );
			if ( '' === $bytes ) {
				$lines[] = '';
				continue;
			}
			$line = '';
			foreach ( preg_split( '/ +/', $bytes ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( self::bytes_width( $try, $size, $bold ) <= $width ) {
					$line = $try;
					continue;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				while ( strlen( $word ) > 1 && self::bytes_width( $word, $size, $bold ) > $width ) {
					$n = 1;
					while ( $n < strlen( $word ) && self::bytes_width( substr( $word, 0, $n + 1 ), $size, $bold ) <= $width ) {
						$n++;
					}
					// Prefer to break after a hyphen, dot, @, slash or underscore in the last two thirds.
					for ( $k = $n - 1; $k >= (int) ( $n / 3 ); $k-- ) {
						if ( false !== strpos( '-.@/_', $word[ $k ] ) ) {
							$n = $k + 1;
							break;
						}
					}
					$lines[] = substr( $word, 0, $n );
					$word    = (string) substr( $word, $n );
				}
				$line = $word;
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Number of lines text wraps to.
	 *
	 * @param string $utf8  Text.
	 * @param float  $width Points.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Helvetica-Bold.
	 * @return int
	 */
	public function line_count( $utf8, $width, $size, $bold = false ) {
		return count( self::wrap_bytes( $utf8, $width, $size, $bold ) );
	}

	/**
	 * Height a text box would take.
	 *
	 * @param string $utf8  Text.
	 * @param float  $width Points.
	 * @param array  $o     size, bold, leading.
	 * @return float
	 */
	public function box_height( $utf8, $width, array $o = array() ) {
		$o = $this->text_options( $o );
		return $this->line_count( $utf8, $width, $o['size'], $o['bold'] ) * $o['leading'];
	}

	/* ------------------------------------------------------------------ Drawing text */

	/**
	 * @param array $o Options.
	 * @return array Options with defaults.
	 */
	private function text_options( array $o ) {
		$o = array_merge(
			array(
				'size'    => 10,
				'bold'    => false,
				'color'   => '#000000',
				'align'   => 'left',
				'spacing' => 0,
				'leading' => null,
			),
			$o
		);
		if ( null === $o['leading'] ) {
			$o['leading'] = $o['size'] * 1.35;
		}
		return $o;
	}

	/**
	 * Draws one line of text. $y is the baseline, from the top of the page.
	 *
	 * @param float  $x    Left edge (or right edge with align right, centre with align center).
	 * @param float  $y    Baseline.
	 * @param string $utf8 Text.
	 * @param array  $o    size, bold, color, align (left|right|center), spacing.
	 * @return float Width drawn.
	 */
	public function text( $x, $y, $utf8, array $o = array() ) {
		$o = $this->text_options( $o );
		return $this->text_bytes( $x, $y, self::line_bytes( $utf8 ), $o );
	}

	/**
	 * @param float  $x     X.
	 * @param float  $y     Baseline.
	 * @param string $bytes WinAnsi bytes.
	 * @param array  $o     Complete options.
	 * @return float
	 */
	private function text_bytes( $x, $y, $bytes, array $o ) {
		if ( '' === $bytes ) {
			return 0.0;
		}
		$w = self::bytes_width( $bytes, $o['size'], $o['bold'], $o['spacing'] );
		if ( 'right' === $o['align'] ) {
			$x -= $w;
		} elseif ( 'center' === $o['align'] ) {
			$x -= $w / 2;
		}
		$ops  = 'BT /' . ( $o['bold'] ? 'F2' : 'F1' ) . ' ' . self::n( $o['size'] ) . ' Tf ';
		$ops .= self::color( $o['color'], 'rg' ) . ' ';
		$ops .= self::n( $o['spacing'] ) . ' Tc ';
		$ops .= self::n( $x ) . ' ' . self::n( self::PAGE_H - $y ) . ' Td ';
		$ops .= '(' . self::escape( $bytes ) . ') Tj ET';
		$this->out( $ops );
		return $w;
	}

	/**
	 * Draws wrapped text inside a box of width $w. Each line is $o['leading'] tall and the
	 * text is centred in it vertically.
	 *
	 * @param float  $x    Left.
	 * @param float  $y    Top of the box.
	 * @param float  $w    Width.
	 * @param string $utf8 Text ("\n" starts a new line).
	 * @param array  $o    size, bold, color, align, leading.
	 * @return float Height used.
	 */
	public function text_box( $x, $y, $w, $utf8, array $o = array() ) {
		$o     = $this->text_options( $o );
		$lines = self::wrap_bytes( $utf8, $w, $o['size'], $o['bold'] );
		$ax    = 'right' === $o['align'] ? $x + $w : ( 'center' === $o['align'] ? $x + $w / 2 : $x );
		foreach ( $lines as $i => $bytes ) {
			$baseline = $y + $i * $o['leading'] + $o['leading'] / 2 + 0.355 * $o['size'];
			$this->text_bytes( $ax, $baseline, $bytes, $o );
		}
		return count( $lines ) * $o['leading'];
	}

	/* ------------------------------------------------------------------ Shapes */

	/**
	 * Filled and/or stroked rectangle.
	 *
	 * @param float       $x      Left.
	 * @param float       $y      Top.
	 * @param float       $w      Width.
	 * @param float       $h      Height.
	 * @param string|null $fill   Hex colour.
	 * @param string|null $stroke Hex colour.
	 * @param float       $lw     Line width.
	 */
	public function rect( $x, $y, $w, $h, $fill = null, $stroke = null, $lw = 1.0 ) {
		$ops = self::n( $x ) . ' ' . self::n( self::PAGE_H - $y - $h ) . ' ' . self::n( $w ) . ' ' . self::n( $h ) . ' re';
		$this->paint( $ops, $fill, $stroke, $lw );
	}

	/**
	 * Rectangle with rounded corners.
	 *
	 * @param float           $x      Left.
	 * @param float           $y      Top.
	 * @param float           $w      Width.
	 * @param float           $h      Height.
	 * @param float|float[]   $r      Radius, or [top-left, top-right, bottom-right, bottom-left].
	 * @param string|null     $fill   Hex colour.
	 * @param string|null     $stroke Hex colour.
	 * @param float           $lw     Line width.
	 */
	public function rounded_rect( $x, $y, $w, $h, $r, $fill = null, $stroke = null, $lw = 1.0 ) {
		$radii = is_array( $r ) ? array_values( $r ) + array( 0, 0, 0, 0 ) : array( $r, $r, $r, $r );
		$max   = min( $w, $h ) / 2;
		foreach ( $radii as $i => $v ) {
			$radii[ $i ] = max( 0.0, min( (float) $v, $max ) );
		}
		list( $tl, $tr, $br, $bl ) = $radii;
		$k = 0.5523; // Bezier approximation of a quarter circle.

		// PDF space: bottom-left origin.
		$x0 = $x;
		$x1 = $x + $w;
		$y1 = self::PAGE_H - $y;       // Top edge.
		$y0 = self::PAGE_H - $y - $h;  // Bottom edge.

		$p   = array();
		$p[] = self::n( $x0 + $bl ) . ' ' . self::n( $y0 ) . ' m';
		$p[] = self::n( $x1 - $br ) . ' ' . self::n( $y0 ) . ' l';
		if ( $br > 0 ) {
			$p[] = self::n( $x1 - $br + $br * $k ) . ' ' . self::n( $y0 ) . ' ' . self::n( $x1 ) . ' ' . self::n( $y0 + $br - $br * $k ) . ' ' . self::n( $x1 ) . ' ' . self::n( $y0 + $br ) . ' c';
		}
		$p[] = self::n( $x1 ) . ' ' . self::n( $y1 - $tr ) . ' l';
		if ( $tr > 0 ) {
			$p[] = self::n( $x1 ) . ' ' . self::n( $y1 - $tr + $tr * $k ) . ' ' . self::n( $x1 - $tr + $tr * $k ) . ' ' . self::n( $y1 ) . ' ' . self::n( $x1 - $tr ) . ' ' . self::n( $y1 ) . ' c';
		}
		$p[] = self::n( $x0 + $tl ) . ' ' . self::n( $y1 ) . ' l';
		if ( $tl > 0 ) {
			$p[] = self::n( $x0 + $tl - $tl * $k ) . ' ' . self::n( $y1 ) . ' ' . self::n( $x0 ) . ' ' . self::n( $y1 - $tl + $tl * $k ) . ' ' . self::n( $x0 ) . ' ' . self::n( $y1 - $tl ) . ' c';
		}
		$p[] = self::n( $x0 ) . ' ' . self::n( $y0 + $bl ) . ' l';
		if ( $bl > 0 ) {
			$p[] = self::n( $x0 ) . ' ' . self::n( $y0 + $bl - $bl * $k ) . ' ' . self::n( $x0 + $bl - $bl * $k ) . ' ' . self::n( $y0 ) . ' ' . self::n( $x0 + $bl ) . ' ' . self::n( $y0 ) . ' c';
		}
		$p[] = 'h';
		$this->paint( implode( ' ', $p ), $fill, $stroke, $lw );
	}

	/**
	 * Straight line.
	 *
	 * @param float  $x1    Start x.
	 * @param float  $y1    Start y.
	 * @param float  $x2    End x.
	 * @param float  $y2    End y.
	 * @param string $color Hex colour.
	 * @param float  $width Line width.
	 * @param float  $dash  Dash length (0 = solid).
	 */
	public function line( $x1, $y1, $x2, $y2, $color = '#000000', $width = 1.0, $dash = 0.0 ) {
		$ops  = 'q ' . self::color( $color, 'RG' ) . ' ' . self::n( $width ) . ' w 0 J ';
		$ops .= $dash > 0 ? '[' . self::n( $dash ) . ' ' . self::n( $dash ) . '] 0 d ' : '';
		$ops .= self::n( $x1 ) . ' ' . self::n( self::PAGE_H - $y1 ) . ' m ' . self::n( $x2 ) . ' ' . self::n( self::PAGE_H - $y2 ) . ' l S Q';
		$this->out( $ops );
	}

	/**
	 * @param string      $path   Path operators.
	 * @param string|null $fill   Hex colour.
	 * @param string|null $stroke Hex colour.
	 * @param float       $lw     Line width.
	 */
	private function paint( $path, $fill, $stroke, $lw ) {
		if ( null === $fill && null === $stroke ) {
			return;
		}
		$ops = 'q ';
		if ( null !== $fill ) {
			$ops .= self::color( $fill, 'rg' ) . ' ';
		}
		if ( null !== $stroke ) {
			$ops .= self::color( $stroke, 'RG' ) . ' ' . self::n( $lw ) . ' w ';
		}
		$op   = ( null !== $fill && null !== $stroke ) ? 'B' : ( null !== $fill ? 'f' : 'S' );
		$ops .= $path . ' ' . $op . ' Q';
		$this->out( $ops );
	}

	/* ------------------------------------------------------------------ Images */

	/**
	 * Draws a JPEG. Give $w or $h (or both); a missing one keeps the aspect ratio.
	 *
	 * @param string     $data JPEG bytes.
	 * @param float      $x    Left.
	 * @param float      $y    Top.
	 * @param float|null $w    Width.
	 * @param float|null $h    Height.
	 * @return array{0:float,1:float}|false Drawn width and height, or false if $data is not a usable JPEG.
	 */
	public function jpeg( $data, $x, $y, $w = null, $h = null ) {
		$key = md5( (string) $data );
		if ( ! isset( $this->images[ $key ] ) ) {
			$info = @getimagesizefromstring( (string) $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $info || IMAGETYPE_JPEG !== $info[2] || $info[0] < 1 || $info[1] < 1 ) {
				return false;
			}
			$channels = isset( $info['channels'] ) ? (int) $info['channels'] : 3;
			$cs       = 1 === $channels ? '/DeviceGray' : ( 4 === $channels ? '/DeviceCMYK' : '/DeviceRGB' );

			$this->images[ $key ] = array(
				'name'   => 'Im' . ( count( $this->images ) + 1 ),
				'w'      => (int) $info[0],
				'h'      => (int) $info[1],
				'cs'     => $cs,
				// Adobe CMYK JPEGs are stored inverted.
				'decode' => 4 === $channels ? '/Decode [1 0 1 0 1 0 1 0] ' : '',
				'data'   => (string) $data,
			);
		}
		$img = $this->images[ $key ];
		if ( null === $w && null === $h ) {
			$w = $img['w'] * 0.75;
		}
		if ( null === $w ) {
			$w = $h * $img['w'] / $img['h'];
		}
		if ( null === $h ) {
			$h = $w * $img['h'] / $img['w'];
		}
		$this->out( 'q ' . self::n( $w ) . ' 0 0 ' . self::n( $h ) . ' ' . self::n( $x ) . ' ' . self::n( self::PAGE_H - $y - $h ) . ' cm /' . $img['name'] . ' Do Q' );
		return array( (float) $w, (float) $h );
	}

	/* ------------------------------------------------------------------ Output */

	/**
	 * The finished document.
	 *
	 * @return string PDF bytes.
	 */
	public function output() {
		if ( empty( $this->pages ) ) {
			$this->add_page();
		}
		$deflate = function_exists( 'gzcompress' );

		// Object numbers: 1 catalog, 2 pages, 3 resources, 4-5 fonts, then images, pages, info.
		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$next     = 6;
		$xobjects = '';
		foreach ( $this->images as $img ) {
			$objects[ $next ] = '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h']
				. ' /ColorSpace ' . $img['cs'] . ' /BitsPerComponent 8 ' . $img['decode'] . '/Filter /DCTDecode /Length ' . strlen( $img['data'] ) . " >>\nstream\n"
				. $img['data'] . "\nendstream";
			$xobjects        .= ' /' . $img['name'] . ' ' . $next . ' 0 R';
			$next++;
		}
		$objects[3] = '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << /F1 4 0 R /F2 5 0 R >>'
			. ( '' !== $xobjects ? ' /XObject <<' . $xobjects . ' >>' : '' ) . ' >>';

		$kids = array();
		foreach ( $this->pages as $content ) {
			$page_id    = $next++;
			$content_id = $next++;
			$kids[]     = $page_id . ' 0 R';

			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n( self::PAGE_W ) . ' ' . self::n( self::PAGE_H ) . ']'
				. ' /Resources 3 0 R /Contents ' . $content_id . ' 0 R >>';

			$stream = $deflate ? gzcompress( $content, 6 ) : $content;
			$objects[ $content_id ] = '<< /Length ' . strlen( $stream ) . ( $deflate ? ' /Filter /FlateDecode' : '' ) . " >>\nstream\n" . $stream . "\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';

		$info_id = $next++;
		$info    = '<< /Producer ' . self::text_string( 'Ledger Plus' ) . ' /Creator ' . self::text_string( 'Ledger Plus Package Calculator' );
		foreach ( array( 'title' => 'Title', 'subject' => 'Subject', 'author' => 'Author' ) as $key => $name ) {
			if ( isset( $this->info[ $key ] ) && '' !== (string) $this->info[ $key ] ) {
				$info .= ' /' . $name . ' ' . self::text_string( (string) $this->info[ $key ] );
			}
		}
		$info             .= ' /CreationDate (D:' . gmdate( 'YmdHis' ) . "Z) >>";
		$objects[ $info_id ] = $info;

		ksort( $objects );
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$size  = $next;
		$xref  = strlen( $pdf );
		$pdf  .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $size; $i++ ) {
			$pdf .= sprintf( '%010d 00000 n ', $offsets[ $i ] ) . "\n";
		}
		$id   = md5( uniqid( '', true ) . count( $this->pages ) . strlen( $pdf ) );
		$pdf .= "trailer\n<< /Size " . $size . ' /Root 1 0 R /Info ' . $info_id . ' 0 R /ID [<' . $id . '> <' . $id . "> ] >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	/* ------------------------------------------------------------------ Helpers */

	/**
	 * @param float $v Number.
	 * @return string Number for a content stream (no exponent, no locale).
	 */
	private static function n( $v ) {
		$s = sprintf( '%.3F', (float) $v );
		$s = rtrim( rtrim( $s, '0' ), '.' );
		return ( '-0' === $s || '' === $s ) ? '0' : $s;
	}

	/**
	 * @param string $hex "#rrggbb" or "#rgb".
	 * @param string $op  rg (fill) or RG (stroke).
	 * @return string
	 */
	private static function color( $hex, $op ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '000000';
		}
		$rgb = array();
		foreach ( str_split( $hex, 2 ) as $part ) {
			$rgb[] = self::n( hexdec( $part ) / 255 );
		}
		return implode( ' ', $rgb ) . ' ' . $op;
	}

	/**
	 * Escapes a PDF literal string.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function escape( $bytes ) {
		return str_replace( array( '\\', '(', ')', "\r" ), array( '\\\\', '\\(', '\\)', '\\r' ), $bytes );
	}

	/**
	 * A PDF text string (for /Info): a literal for ASCII, UTF-16BE with BOM otherwise.
	 *
	 * @param string $utf8 Text.
	 * @return string
	 */
	private static function text_string( $utf8 ) {
		$utf8 = (string) $utf8;
		if ( ! preg_match( '/[^\x20-\x7E]/', $utf8 ) ) {
			return '(' . self::escape( $utf8 ) . ')';
		}
		$utf16 = false;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$utf16 = mb_convert_encoding( $utf8, 'UTF-16BE', 'UTF-8' );
		} elseif ( function_exists( 'iconv' ) ) {
			$utf16 = @iconv( 'UTF-8', 'UTF-16BE//IGNORE', $utf8 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( false === $utf16 || '' === $utf16 ) {
			return '(' . self::escape( (string) preg_replace( '/[^\x20-\x7E]/', '?', $utf8 ) ) . ')';
		}
		return '<FEFF' . strtoupper( bin2hex( $utf16 ) ) . '>';
	}
}

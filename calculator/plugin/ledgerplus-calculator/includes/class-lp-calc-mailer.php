<?php
/**
 * The client email: a branded HTML estimate with the PDF quotation attached.
 *
 * @package LedgerPlusCalculator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the client email.
 */
final class LP_Calc_Mailer {

	const REPLY_TO      = 'info@ledgerplus.ae';
	const REPLY_TO_NAME = 'Ledger Plus UAE';
	const LOGO_URL      = 'https://ledgerplus.ae/wp-content/uploads/2026/09/Logo-Assests-01-edited.png';
	const WHATSAPP_URL  = 'https://wa.me/971503420832';
	const PHONE         = '+971 50 342 0832';
	const PHONE_TEL     = '+971503420832';

	const FONT = "'Helvetica Neue', Helvetica, Arial, sans-serif";

	const NAVY   = '#041950';
	const BLUE   = '#0f5cd8';
	const INK    = '#030922';
	const TEXT   = '#2c3651';
	const MUTED  = '#5b6684';
	const BORDER = '#dde5f4';
	const SOFT   = '#f6f8fd';
	const ACCENT = '#8fb8ff';

	const NEXT_TEXT = 'A Ledger Plus advisor will review your selections and contact you within one business day to confirm scope and pricing.';

	/**
	 * Sends the email.
	 *
	 * @param array       $data     ref, date, contact (name, email, ...), selection, quote.
	 * @param string|null $pdf_path PDF to attach, or null.
	 * @return bool What wp_mail() returned.
	 */
	public static function send( array $data, $pdf_path = null ) {
		$has_pdf = is_string( $pdf_path ) && '' !== $pdf_path && is_file( $pdf_path );
		$mail    = self::message( $data, $has_pdf );
		if ( '' === $mail['to'] ) {
			return false;
		}

		// A plain-text part for this message only (better spam scores, readable everywhere).
		$alt  = $mail['text'];
		$hook = static function ( $phpmailer ) use ( $alt ) {
			if ( is_object( $phpmailer ) && '' !== $alt ) {
				$phpmailer->AltBody = $alt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
		};
		add_action( 'phpmailer_init', $hook, 99 );
		try {
			$sent = wp_mail( $mail['to'], $mail['subject'], $mail['body'], $mail['headers'], $has_pdf ? array( $pdf_path ) : array() );
		} finally {
			remove_action( 'phpmailer_init', $hook, 99 );
		}
		return (bool) $sent;
	}

	/**
	 * The message parts, after the `lp_calc_client_email` filter.
	 *
	 * @param array $data    See send().
	 * @param bool  $has_pdf Whether a PDF is attached.
	 * @return array{to: string, subject: string, body: string, headers: string[], text: string}
	 */
	public static function message( array $data, $has_pdf = true ) {
		$body     = self::html( $data, $has_pdf );
		$mail     = array(
			'to'      => isset( $data['contact']['email'] ) ? trim( (string) $data['contact']['email'] ) : '',
			'subject' => 'Your Ledger Plus package estimate (' . $data['ref'] . ')',
			'body'    => $body,
			'headers' => array(
				'Content-Type: text/html; charset=UTF-8',
				'Reply-To: ' . self::REPLY_TO_NAME . ' <' . self::REPLY_TO . '>',
			),
			'text'    => self::text( $data, $has_pdf ),
		);
		$filtered = apply_filters( 'lp_calc_client_email', $mail, $data );
		if ( ! is_array( $filtered ) ) {
			return $mail;
		}
		$mail = array_merge( $mail, $filtered );
		// If a filter replaced the body but not the text, the old text no longer matches it.
		if ( $mail['body'] !== $body && ! array_key_exists( 'text', $filtered ) ) {
			$mail['text'] = '';
		}
		$mail['to']      = (string) $mail['to'];
		$mail['subject'] = (string) $mail['subject'];
		$mail['body']    = (string) $mail['body'];
		$mail['text']    = (string) $mail['text'];
		$mail['headers'] = (array) $mail['headers'];
		return $mail;
	}

	/**
	 * "Hi {first name}": the first word of the name, skipping a title such as "Dr".
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	public static function first_name( $name ) {
		$name  = trim( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
		$words = preg_split( '/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return '';
		}
		$titles = array( 'mr', 'mrs', 'ms', 'miss', 'dr', 'eng', 'prof' );
		if ( count( $words ) > 1 && in_array( strtolower( rtrim( $words[0], '.' ) ), $titles, true ) ) {
			array_shift( $words );
		}
		return $words[0];
	}

	/**
	 * Escapes user text and breaks it up so no email app turns it into a link.
	 *
	 * @param string $text User text.
	 * @return string HTML.
	 */
	private static function user_html( $text ) {
		$html = esc_html( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		// A zero-width space after . @ : / stops automatic link detection (Gmail, Apple Mail, Outlook).
		return (string) preg_replace( '#([.@:/])#', '$1&#8203;', $html );
	}

	/**
	 * Inline style helper: the font plus extra declarations.
	 *
	 * @param string $css Declarations.
	 * @return string
	 */
	private static function s( $css ) {
		return 'font-family:' . self::FONT . ';' . $css;
	}

	/**
	 * @return string Logo URL: the WooCommerce email logo, else the uploads file.
	 */
	private static function logo_url() {
		$url = function_exists( 'get_option' ) ? (string) get_option( 'woocommerce_email_header_image', '' ) : '';
		return '' !== trim( $url ) ? trim( $url ) : self::LOGO_URL;
	}

	/**
	 * The HTML body.
	 *
	 * @param array $data    See send().
	 * @param bool  $has_pdf Whether a PDF is attached.
	 * @return string
	 */
	public static function html( array $data, $has_pdf = true ) {
		$ref   = (string) $data['ref'];
		$quote = $data['quote'];
		$date  = isset( $data['date'] ) ? (string) $data['date'] : LP_Calc_Quote_PDF::date_text();
		$first = self::first_name( isset( $data['contact']['name'] ) ? $data['contact']['name'] : '' );
		$total = LP_Calc_Pricing::money( (int) $quote['total'] );

		$hi    = '' === $first ? 'Hello' : 'Hi ' . self::user_html( $first );
		$ref_html = '<span style="white-space:nowrap;">' . esc_html( $ref ) . '</span>';
		$intro    = $has_pdf
			? 'Thank you for using the Ledger Plus package calculator. Your estimate ' . $ref_html . ' is below, and the full quotation is attached as a PDF.'
			: 'Thank you for using the Ledger Plus package calculator. Your estimate ' . $ref_html . ' is below.';
		$pre   = 'Your estimate ' . $ref . ': ' . $total . ' a year, including 5% VAT.';

		$mailto = 'mailto:' . self::REPLY_TO . '?subject=' . rawurlencode( 'Quotation ' . $ref );

		// 24px sides by default: apps that drop the <style> block (Gmail app with other accounts)
		// still fit a 320px screen.
		$cell_pad = 'padding-left:24px;padding-right:24px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Your Ledger Plus package estimate</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
body{margin:0;padding:0;width:100%;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
table{border-collapse:collapse;}
img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}
a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important;font-size:inherit !important;font-family:inherit !important;font-weight:inherit !important;line-height:inherit !important;}
@media only screen and (max-width:620px){
.lp-outer{padding:12px 8px !important;}
}
@media only screen and (min-width:621px){
.lp-total-cell{padding:16px 18px !important;}
.lp-total-amount{font-size:24px !important;line-height:30px !important;}
}
@media only screen and (max-width:480px){
.lp-pad{padding-left:20px !important;padding-right:20px !important;}
.lp-h1{font-size:24px !important;line-height:30px !important;}
.lp-cellpad{padding-left:12px !important;padding-right:12px !important;}
.lp-total-label{font-size:14px !important;line-height:20px !important;}
.lp-total-amount{font-size:20px !important;line-height:26px !important;}
.lp-btn-col{display:block !important;width:100% !important;padding:0 0 10px 0 !important;}
.lp-btn-col a{display:block !important;}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:<?php echo self::SOFT; ?>;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;font-size:1px;line-height:1px;color:<?php echo self::SOFT; ?>;"><?php echo esc_html( $pre ); ?><?php echo str_repeat( '&#8199;&#847; ', 40 ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php echo self::SOFT; ?>" style="width:100%;background-color:<?php echo self::SOFT; ?>;">
<tr><td class="lp-outer" align="center" style="padding:28px 12px;">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;border-collapse:separate;background-color:#ffffff;border:1px solid <?php echo self::BORDER; ?>;border-top:4px solid <?php echo self::BLUE; ?>;border-radius:16px;">
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad . 'padding-top:30px;padding-bottom:6px;' ); ?>">
<img src="<?php echo esc_url( self::logo_url() ); ?>" width="180" alt="Ledger Plus" style="display:block;width:180px;max-width:100%;height:auto;border:0;color:<?php echo self::NAVY; ?>;font-family:<?php echo esc_attr( self::FONT ); ?>;font-size:22px;font-weight:bold;">
</td></tr>
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad . 'padding-top:22px;' ); ?>">
<h1 class="lp-h1" style="<?php echo self::s( 'margin:0 0 18px;font-size:28px;line-height:34px;font-weight:bold;color:' . self::NAVY . ';' ); ?>">Your package estimate</h1>
<p style="<?php echo self::s( 'margin:0 0 12px;font-size:16px;line-height:24px;color:' . self::TEXT . ';' ); ?>"><?php echo $hi; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in user_html(). ?>,</p>
<p style="<?php echo self::s( 'margin:0 0 22px;font-size:16px;line-height:24px;color:' . self::TEXT . ';' ); ?>"><?php echo $intro; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></p>
</td></tr>
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad ); ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
<tr>
<td style="<?php echo self::s( 'padding:0 0 8px;font-size:13px;line-height:18px;color:' . self::MUTED . ';' ); ?>">Quotation <strong style="color:<?php echo self::INK; ?>;white-space:nowrap;"><?php echo esc_html( $ref ); ?></strong></td>
<td align="right" style="<?php echo self::s( 'padding:0 0 8px;font-size:13px;line-height:18px;color:' . self::MUTED . ';text-align:right;' ); ?>"><?php echo esc_html( $date ); ?></td>
</tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
<tr>
<td class="lp-cellpad" style="<?php echo self::s( 'padding:10px 14px;border-bottom:1px solid ' . self::NAVY . ';font-size:11px;line-height:14px;font-weight:bold;letter-spacing:0.6px;text-transform:uppercase;color:' . self::MUTED . ';' ); ?>">Service</td>
<td class="lp-cellpad" align="right" style="<?php echo self::s( 'padding:10px 14px;border-bottom:1px solid ' . self::NAVY . ';font-size:11px;line-height:14px;font-weight:bold;letter-spacing:0.6px;text-transform:uppercase;color:' . self::MUTED . ';text-align:right;white-space:nowrap;' ); ?>">Annual fee</td>
</tr>
<?php
foreach ( $quote['lines'] as $i => $line ) :
	$bg     = 1 === $i % 2 ? self::SOFT : '#ffffff';
	$free   = 0 === (int) $line['amount'];
	$detail = LP_Calc_Quote_PDF::detail( $line );
	?>
<tr>
<td class="lp-cellpad" bgcolor="<?php echo $bg; ?>" style="<?php echo self::s( 'padding:12px 14px;background-color:' . $bg . ';border-bottom:1px solid ' . self::BORDER . ';' ); ?>">
<div style="<?php echo self::s( 'font-size:15px;line-height:21px;font-weight:bold;color:' . self::INK . ';' ); ?>"><?php echo esc_html( $line['label'] ); ?></div>
<?php if ( '' !== $detail ) : ?>
<div style="<?php echo self::s( 'font-size:13px;line-height:18px;color:' . self::MUTED . ';' ); ?>"><?php echo esc_html( $detail ); ?></div>
<?php endif; ?>
</td>
<td class="lp-cellpad" bgcolor="<?php echo $bg; ?>" align="right" valign="top" style="<?php echo self::s( 'padding:12px 14px;background-color:' . $bg . ';border-bottom:1px solid ' . self::BORDER . ';font-size:15px;line-height:21px;font-weight:bold;text-align:right;white-space:nowrap;color:' . ( $free ? self::BLUE : self::INK ) . ';' ); ?>"><?php echo esc_html( LP_Calc_Pricing::line_amount_text( $line ) ); ?></td>
</tr>
<?php endforeach; ?>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;margin-top:12px;">
<tr>
<td class="lp-cellpad lp-total-cell" bgcolor="<?php echo self::NAVY; ?>" style="<?php echo self::s( 'padding:14px;background-color:' . self::NAVY . ';border-radius:12px 0 0 12px;' ); ?>">
<div class="lp-total-label" style="<?php echo self::s( 'font-size:15px;line-height:21px;font-weight:bold;color:#ffffff;' ); ?>"><?php echo esc_html( LP_Calc_Pricing::TOTAL_LABEL ); ?></div>
</td>
<td class="lp-cellpad lp-total-cell" bgcolor="<?php echo self::NAVY; ?>" align="right" style="<?php echo self::s( 'padding:14px;background-color:' . self::NAVY . ';border-radius:0 12px 12px 0;text-align:right;white-space:nowrap;' ); ?>">
<div class="lp-total-amount" style="<?php echo self::s( 'font-size:20px;line-height:26px;font-weight:bold;color:#ffffff;' ); ?>"><?php echo esc_html( $total ); ?></div>
</td>
</tr>
</table>
</td></tr>
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad . 'padding-top:24px;' ); ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;background-color:<?php echo self::SOFT; ?>;border:1px solid <?php echo self::BORDER; ?>;border-radius:12px;">
<tr><td style="<?php echo self::s( 'padding:18px 20px;' ); ?>">
<p style="<?php echo self::s( 'margin:0 0 6px;font-size:12px;line-height:16px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:' . self::BLUE . ';' ); ?>">What happens next</p>
<p style="<?php echo self::s( 'margin:0;font-size:15px;line-height:22px;color:' . self::INK . ';' ); ?>"><?php echo esc_html( self::NEXT_TEXT ); ?></p>
</td></tr>
</table>
</td></tr>
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad . 'padding-top:20px;' ); ?>">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
<tr>
<td class="lp-btn-col" width="50%" valign="top" style="width:50%;padding:0 6px 0 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;"><tr>
<td align="center" bgcolor="<?php echo self::BLUE; ?>" style="background-color:<?php echo self::BLUE; ?>;border-radius:10px;mso-padding-alt:14px 16px;">
<a href="<?php echo esc_url( $mailto ); ?>" target="_blank" style="<?php echo self::s( 'display:block;padding:14px 16px;font-size:15px;line-height:20px;font-weight:bold;color:#ffffff;text-decoration:none;text-align:center;border-radius:10px;' ); ?>">Reply to this email</a>
</td></tr></table>
</td>
<td class="lp-btn-col" width="50%" valign="top" style="width:50%;padding:0 0 0 6px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:separate;"><tr>
<td align="center" bgcolor="#ffffff" style="background-color:#ffffff;border:2px solid <?php echo self::BLUE; ?>;border-radius:10px;mso-padding-alt:12px 16px;">
<a href="<?php echo esc_url( self::WHATSAPP_URL ); ?>" target="_blank" style="<?php echo self::s( 'display:block;padding:12px 16px;font-size:15px;line-height:20px;font-weight:bold;color:' . self::BLUE . ';text-decoration:none;text-align:center;border-radius:10px;' ); ?>">WhatsApp us</a>
</td></tr></table>
</td>
</tr>
</table>
</td></tr>
<tr><td class="lp-pad" style="<?php echo self::s( $cell_pad . 'padding-top:22px;padding-bottom:28px;' ); ?>">
<?php foreach ( LP_Calc_Quote_PDF::NOTES as $note ) : ?>
<p style="<?php echo self::s( 'margin:0 0 6px;font-size:12px;line-height:18px;color:' . self::MUTED . ';' ); ?>"><?php echo esc_html( $note ); ?></p>
<?php endforeach; ?>
</td></tr>
<tr><td class="lp-pad" bgcolor="<?php echo self::NAVY; ?>" align="center" style="<?php echo self::s( $cell_pad . 'padding-top:22px;padding-bottom:22px;background-color:' . self::NAVY . ';border-radius:0 0 15px 15px;text-align:center;font-size:13px;line-height:20px;color:#c9d6f2;' ); ?>">
<strong style="color:#ffffff;font-size:14px;">Ledger Plus UAE</strong><br>
DIFC, Dubai, United Arab Emirates<br>
<a href="tel:<?php echo esc_attr( self::PHONE_TEL ); ?>" style="color:<?php echo self::ACCENT; ?>;font-weight:bold;text-decoration:none;white-space:nowrap;"><?php echo esc_html( self::PHONE ); ?></a> &middot; <a href="<?php echo esc_url( self::WHATSAPP_URL ); ?>" style="color:<?php echo self::ACCENT; ?>;font-weight:bold;text-decoration:none;">WhatsApp</a><br>
<a href="mailto:<?php echo esc_attr( self::REPLY_TO ); ?>" style="color:<?php echo self::ACCENT; ?>;font-weight:bold;text-decoration:none;">info@ledgerplus.ae</a> &middot; <a href="https://ledgerplus.ae" style="color:<?php echo self::ACCENT; ?>;font-weight:bold;text-decoration:none;">ledgerplus.ae</a>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>
		<?php
		$html = (string) ob_get_clean();
		// Keep every line well under the 998 characters RFC 5322 allows.
		return trim( $html ) . "\n";
	}

	/**
	 * The plain-text part.
	 *
	 * @param array $data    See send().
	 * @param bool  $has_pdf Whether a PDF is attached.
	 * @return string
	 */
	public static function text( array $data, $has_pdf = true ) {
		$ref   = (string) $data['ref'];
		$first = self::first_name( isset( $data['contact']['name'] ) ? $data['contact']['name'] : '' );
		$out   = array();
		// Zero-width spaces after . @ : / so the name cannot turn into a link.
		$out[] = ( '' === $first ? 'Hello' : 'Hi ' . preg_replace( '#([.@:/])#u', "\$1\u{200B}", $first ) ) . ',';
		$out[] = '';
		$out[] = $has_pdf
			? 'Thank you for using the Ledger Plus package calculator. Your estimate ' . $ref . ' is below, and the full quotation is attached as a PDF.'
			: 'Thank you for using the Ledger Plus package calculator. Your estimate ' . $ref . ' is below.';
		$out[] = '';
		foreach ( $data['quote']['lines'] as $line ) {
			$detail = LP_Calc_Quote_PDF::detail( $line );
			$out[]  = '- ' . $line['label'] . ': ' . LP_Calc_Pricing::line_amount_text( $line ) . ( '' !== $detail ? ' (' . $detail . ')' : '' );
		}
		$out[] = '';
		$out[] = LP_Calc_Pricing::TOTAL_LABEL . ': ' . LP_Calc_Pricing::money( (int) $data['quote']['total'] );
		$out[] = '';
		$out[] = 'What happens next';
		$out[] = self::NEXT_TEXT;
		$out[] = '';
		$out[] = 'Reply to this email, or WhatsApp us: ' . self::WHATSAPP_URL;
		$out[] = '';
		foreach ( LP_Calc_Quote_PDF::NOTES as $note ) {
			$out[] = $note;
		}
		$out[] = '';
		$out[] = 'Ledger Plus UAE';
		$out[] = 'DIFC, Dubai, United Arab Emirates';
		$out[] = self::PHONE . ' · info@ledgerplus.ae · ledgerplus.ae';
		return implode( "\n", $out );
	}
}

<?php
/**
 * TSO Swiss Knife – branded HTML email template (same look as TSO Link Inspector notifications).
 *
 * @package TSO_Swiss_Knife
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOSK_Email_Template
 */
class TSOSK_Email_Template {

	/** Brand shown in the header and as the sender name. */
	private const BRAND = 'TSO Swiss Knife';

	/**
	 * Send a branded HTML notification.
	 *
	 * Supported $args keys: heading (string), intro (string), rows (list of label/value/url/meta),
	 * more (string shown under the rows), button_label and button_url (call to action).
	 *
	 * @param string               $to      Recipient email.
	 * @param string               $subject Subject (already translated).
	 * @param array<string, mixed> $args    Content.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send( string $to, string $subject, array $args ): bool {
		$to = sanitize_email( $to );
		if ( '' === $to ) {
			return false;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		add_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );
		$sent = wp_mail( $to, $subject, self::build_html( $args ), $headers );
		remove_filter( 'wp_mail_from_name', array( __CLASS__, 'mail_from_name' ) );

		return (bool) $sent;
	}

	/**
	 * Send an already-translated plain-text message in the branded layout.
	 *
	 * Paragraphs separated by a blank line: the first free-text paragraph becomes the intro,
	 * "Label: value" lines become rows, and any later free-text paragraph is shown under the rows.
	 *
	 * @param string $to           Recipient email.
	 * @param string $subject      Subject (already translated).
	 * @param string $message      Plain-text body (already translated).
	 * @param string $button_label Optional button label.
	 * @param string $button_url   Optional button URL.
	 * @param bool   $parse_rows   Whether "Label: value" lines become rows.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send_message( string $to, string $subject, string $message, string $button_label = '', string $button_url = '', bool $parse_rows = true ): bool {
		$intro = '';
		$rows  = array();
		$more  = array();

		$paragraphs = preg_split( '/\R{2,}/', trim( $message ) );
		foreach ( (array) $paragraphs as $paragraph ) {
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $paragraph ) ), 'strlen' ) );
			if ( empty( $lines ) ) {
				continue;
			}
			$is_rows = $parse_rows;
			if ( $is_rows ) {
				foreach ( $lines as $line ) {
					if ( ! preg_match( '/^([^:]{1,40}):\s+(.+)$/u', $line ) ) {
						$is_rows = false;
						break;
					}
				}
			}
			if ( $is_rows ) {
				foreach ( $lines as $line ) {
					preg_match( '/^([^:]{1,40}):\s+(.+)$/u', $line, $m );
					$rows[] = array(
						'label' => $m[1],
						'value' => $m[2],
					);
				}
			} elseif ( '' === $intro ) {
				$intro = implode( ' ', $lines );
			} else {
				$more[] = implode( ' ', $lines );
			}
		}

		return self::send(
			$to,
			$subject,
			array(
				'heading'      => trim( (string) preg_replace( '/^\[[^\]]*\]\s*/', '', $subject ) ),
				'intro'        => $intro,
				'rows'         => $rows,
				'more'         => implode( ' ', $more ),
				'button_label' => $button_label,
				'button_url'   => $button_url,
			)
		);
	}

	/**
	 * Sender display name for plugin emails.
	 *
	 * @return string
	 */
	public static function mail_from_name(): string {
		return self::BRAND;
	}

	/**
	 * Build the HTML document.
	 *
	 * @param array<string, mixed> $args Content (see send()).
	 * @return string
	 */
	public static function build_html( array $args ): string {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url( '/' );
		$heading   = isset( $args['heading'] ) ? (string) $args['heading'] : '';
		$intro     = isset( $args['intro'] ) ? (string) $args['intro'] : '';
		$rows      = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
		$more      = isset( $args['more'] ) ? (string) $args['more'] : '';
		$btn_label = isset( $args['button_label'] ) ? (string) $args['button_label'] : '';
		$btn_url   = isset( $args['button_url'] ) ? (string) $args['button_url'] : '';

		$footer = sprintf(
			/* translators: 1: plugin name, 2: site name */
			__( 'This notification was sent by %1$s on %2$s.', 'tso-swiss-knife-advanced-maintenance-developer-toolkit' ),
			self::BRAND,
			$site_name
		);

		$rows_html = '';
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['value'] ) || '' === (string) $row['value'] ) {
				continue;
			}
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			$value = self::truncate( (string) $row['value'], 72 );
			$url   = isset( $row['url'] ) ? (string) $row['url'] : '';
			$meta  = isset( $row['meta'] ) ? (string) $row['meta'] : '';

			$rows_html .= '<tr><td style="padding:14px 16px;border-bottom:1px solid #e5e7eb;">';
			if ( '' !== $label ) {
				$rows_html .= '<p style="margin:0 0 6px;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.02em;">' . esc_html( $label ) . '</p>';
			}
			$rows_html .= '<p style="margin:0;font-size:14px;line-height:1.45;word-break:break-all;color:#1d2327;">';
			if ( '' !== $url ) {
				$rows_html .= '<a href="' . esc_url( $url ) . '" style="color:#1d4ed8;text-decoration:underline;">' . esc_html( $value ) . '</a>';
			} else {
				$rows_html .= esc_html( $value );
			}
			$rows_html .= '</p>';
			if ( '' !== $meta ) {
				$rows_html .= '<p style="margin:8px 0 0;font-size:13px;color:#50575e;">' . esc_html( $meta ) . '</p>';
			}
			$rows_html .= '</td></tr>';
		}

		$table_html = '';
		if ( '' !== $rows_html ) {
			$table_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">'
				. $rows_html
				. '</table>';
		}

		$more_html = '';
		if ( '' !== $more ) {
			$more_html = '<p style="margin:16px 0 0;font-size:13px;color:#646970;font-style:italic;">' . esc_html( $more ) . '</p>';
		}

		$button_html = '';
		if ( '' !== $btn_label && '' !== $btn_url ) {
			$button_html = '<p style="margin:24px 0 0;text-align:center;">'
				. '<a href="' . esc_url( $btn_url ) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:12px 22px;border-radius:6px;">'
				. esc_html( $btn_label ) . '</a></p>';
		}

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen-Sans,Ubuntu,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f1;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #dcdcde;">'
			. '<tr><td style="background:linear-gradient(135deg,#1e40af 0%,#1d4ed8 100%);color:#ffffff;padding:22px 24px;">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.9;">' . esc_html( self::BRAND ) . '</p>'
			. '<h1 style="margin:0;font-size:22px;font-weight:700;line-height:1.3;">' . esc_html( $heading ) . '</h1>'
			. '<p style="margin:10px 0 0;font-size:14px;opacity:.92;"><a href="' . esc_url( $site_url ) . '" style="color:#ffffff;text-decoration:underline;">' . esc_html( $site_name ) . '</a></p>'
			. '</td></tr>'
			. '<tr><td style="padding:24px 24px 8px;">'
			. '<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#1d2327;">' . esc_html( $intro ) . '</p>'
			. $table_html
			. $more_html
			. $button_html
			. '</td></tr>'
			. '<tr><td style="padding:16px 24px 20px;background:#f6f7f7;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#646970;text-align:center;">'
			. esc_html( $footer )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Shorten long values for display (links keep the full URL).
	 *
	 * @param string $text    Text.
	 * @param int    $max_len Max characters.
	 * @return string
	 */
	private static function truncate( string $text, int $max_len ): string {
		return strlen( $text ) <= $max_len ? $text : substr( $text, 0, $max_len - 1 ) . '…';
	}
}

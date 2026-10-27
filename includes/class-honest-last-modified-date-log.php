<?php
/**
 * Activity: the latest decisions (shown on the Activity tab) and running
 * totals of dates kept and moved. Both are options that aren't autoloaded.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activity log.
 */
final class Honest_Last_Modified_Date_Log {

	/**
	 * Option holding the latest entries, newest first.
	 */
	const OPTION = 'honest_last_modified_date_log';

	/**
	 * Option holding the running totals.
	 */
	const TOTALS = 'honest_last_modified_date_totals';

	/**
	 * Entries kept.
	 */
	const MAX = 50;

	/**
	 * A save that changed no words within this many seconds of the post's
	 * last entry isn't logged again (the block editor saves twice when a
	 * post has meta boxes).
	 */
	const REPEAT_WINDOW = 60;

	/**
	 * Add an entry.
	 *
	 * @param array $entry post, time, user, changed, exact, needed, percent, result ("kept"/"updated"), by ("rule"/"choice"/"long").
	 * @param bool  $same  Whether this save left the title and content as they were.
	 * @return void
	 */
	public static function add( array $entry, $same = false ) {
		$entries = self::entries();

		if ( $same ) {
			foreach ( $entries as $previous ) {
				if ( (int) $previous['post'] === (int) $entry['post'] ) {
					if ( (int) $entry['time'] - (int) $previous['time'] < self::REPEAT_WINDOW ) {
						return;
					}
					break;
				}
			}
		}

		array_unshift( $entries, $entry );
		update_option( self::OPTION, array_slice( $entries, 0, self::MAX ), false );

		$totals = self::totals();
		$key    = 'updated' === $entry['result'] ? 'updated' : 'kept';

		++$totals[ $key ];

		if ( 'choice' === $entry['by'] ) {
			++$totals['chosen'];
		}

		update_option( self::TOTALS, $totals, false );
	}

	/**
	 * Latest entries, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$entries = get_option( self::OPTION, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$entries,
				function ( $entry ) {
					return is_array( $entry ) && isset( $entry['post'], $entry['time'], $entry['result'], $entry['by'] );
				}
			)
		);
	}

	/**
	 * Running totals since the plugin started (or the log was last cleared).
	 *
	 * @return array{kept:int,updated:int,chosen:int,since:int}
	 */
	public static function totals() {
		$totals = get_option( self::TOTALS, array() );
		$totals = is_array( $totals ) ? $totals : array();

		return array(
			'kept'    => isset( $totals['kept'] ) ? (int) $totals['kept'] : 0,
			'updated' => isset( $totals['updated'] ) ? (int) $totals['updated'] : 0,
			'chosen'  => isset( $totals['chosen'] ) ? (int) $totals['chosen'] : 0,
			'since'   => ! empty( $totals['since'] ) ? (int) $totals['since'] : time(),
		);
	}

	/**
	 * Start the totals and the list afresh.
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );
		update_option(
			self::TOTALS,
			array(
				'kept'    => 0,
				'updated' => 0,
				'chosen'  => 0,
				'since'   => time(),
			),
			false
		);
	}
}

<?php
/**
 * Measures how much of a post's visible text changed between two versions.
 *
 * Only the words readers see count. Block markup, HTML tags and their
 * attributes, shortcode tags, scripts and styles are dropped first, and words
 * are compared without case or punctuation, so formatting, link targets,
 * block settings and typography fixes don't add up.
 *
 * "Words changed" means words added, removed or rewritten, with a rewritten
 * word counted once: the larger of words added and words removed in a
 * word-level diff (max(n, m) - LCS).
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text extraction and word diff.
 */
final class Honest_Last_Modified_Date_Diff {

	/**
	 * Longest version (in words) that is compared. Anything longer is left
	 * to WordPress's normal behaviour rather than slowing a save down.
	 */
	const MAX_WORDS = 150000;

	/**
	 * Most steps the exact word diff may take (about 0.1 s on PHP 8, 0.3 s
	 * on PHP 7). Only very different versions with a high threshold get
	 * there; they fall back to a firm lower bound, then an estimate.
	 */
	const BUDGET = 1000000;

	/**
	 * Elements whose contents readers never see as text.
	 */
	const HIDDEN_TAGS = 'script|style|noscript|template|svg|math|iframe|object|textarea|select';

	/**
	 * Elements that end a word when they open or close.
	 */
	const BLOCK_TAGS = 'address|article|aside|blockquote|br|caption|dd|details|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|tbody|td|tfoot|th|thead|tr|ul';

	/**
	 * Visible text of a title or of post content, one paragraph per line.
	 *
	 * @param string $html Title or post content (unslashed).
	 * @return string
	 */
	public static function text( $html ) {
		$text = (string) $html;

		if ( '' === $text ) {
			return '';
		}

		$text = self::replace( '/<!--.*?-->/s', ' ', $text );
		$text = self::replace( '#<(' . self::HIDDEN_TAGS . ')\b[^>]*>.*?</\1\s*>#is', ' ', $text );
		$text = self::replace( '/\[\/?[A-Za-z][\w-]*(?:\s[^\[\]]*)?\/?\]/', ' ', $text );
		$text = self::replace( '#</?(?:' . self::BLOCK_TAGS . ')\b[^>]*>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = self::replace( '/[^\S\n]+/u', ' ', $text );
		$text = self::replace( '/ *\n[\s]*/', "\n", $text );

		return trim( $text );
	}

	/**
	 * Split text into comparable words: lowercase, without punctuation.
	 * Chinese and Japanese characters count one word each, since those
	 * scripts don't put spaces between words.
	 *
	 * @param string $text Text from {@see text()}.
	 * @return string[]
	 */
	public static function words( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return array();
		}

		$text = wp_check_invalid_utf8( $text, true );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = self::replace( '/([\p{Han}\p{Hiragana}\p{Katakana}])/u', ' $1 ', $text );

		$found = preg_match_all( "/[\\p{L}\\p{M}\\p{N}]+(?:['\\x{2019}][\\p{L}\\p{M}\\p{N}]+)*/u", $text, $matches );

		if ( false === $found ) {
			// No Unicode support in PCRE: fall back to runs of ASCII letters and digits.
			$words = preg_split( "/[^a-z0-9']+/", $text, -1, PREG_SPLIT_NO_EMPTY );
			return is_array( $words ) ? $words : array();
		}

		return $matches[0];
	}

	/**
	 * Words changed between two word lists.
	 *
	 * Counting stops once $enough is reached, so a big rewrite costs no
	 * more than the threshold it has to clear. Below it the count is exact.
	 *
	 * @param string[] $before Words of the earlier version.
	 * @param string[] $after  Words of the new version.
	 * @param int      $enough Count at which the answer is "enough"; 0 = always count fully.
	 * @return array{changed:int,exact:bool}
	 */
	public static function changed( array $before, array $after, $enough = 0 ) {
		$enough = (int) $enough;
		$ids    = array();
		$a      = array();
		$b      = array();

		// Compare numbers, not strings.
		foreach ( $before as $word ) {
			if ( ! isset( $ids[ $word ] ) ) {
				$ids[ $word ] = count( $ids );
			}
			$a[] = $ids[ $word ];
		}

		foreach ( $after as $word ) {
			if ( ! isset( $ids[ $word ] ) ) {
				$ids[ $word ] = count( $ids );
			}
			$b[] = $ids[ $word ];
		}

		unset( $ids );

		// Most edits touch one part of a post: skip the shared start and end.
		$n     = count( $a );
		$m     = count( $b );
		$start = 0;

		while ( $start < $n && $start < $m && $a[ $start ] === $b[ $start ] ) {
			++$start;
		}

		$end = 0;

		while ( $end < $n - $start && $end < $m - $start && $a[ $n - 1 - $end ] === $b[ $m - 1 - $end ] ) {
			++$end;
		}

		$a = array_slice( $a, $start, $n - $start - $end );
		$b = array_slice( $b, $start, $m - $start - $end );
		$n = count( $a );
		$m = count( $b );

		if ( 0 === $n || 0 === $m ) {
			return array(
				'changed' => max( $n, $m ),
				'exact'   => true,
			);
		}

		$gap = abs( $n - $m );

		// Ignoring order can only undercount, so if that's already enough, it is.
		$lower = self::changed_ignoring_order( $a, $b );

		if ( $enough > 0 && $lower >= $enough ) {
			return array(
				'changed' => $lower,
				'exact'   => false,
			);
		}

		// changed = (distance + gap) / 2, so "changed >= enough" is "distance >= 2 * enough - gap".
		$cap  = $enough > 0 ? min( $n + $m, 2 * $enough - $gap - 1 ) : $n + $m;
		$diff = self::distance( $a, $b, $cap );

		if ( null !== $diff['distance'] ) {
			return array(
				'changed' => (int) ( ( $diff['distance'] + $gap ) / 2 ),
				'exact'   => true,
			);
		}

		if ( ! $diff['out_of_budget'] ) {
			return array(
				'changed' => $enough,
				'exact'   => false,
			);
		}

		// Out of steps: every distance up to "reached" was ruled out, which is a firm floor.
		$floor = max( $lower, (int) ceil( ( $diff['reached'] + 1 + $gap ) / 2 ) );

		if ( $enough > 0 && $floor >= $enough ) {
			return array(
				'changed' => $floor,
				'exact'   => false,
			);
		}

		return array(
			'changed' => min( max( $n, $m ), max( $floor, self::estimate( $a, $b ) ) ),
			'exact'   => false,
		);
	}

	/**
	 * Estimated changed words for very different versions: a word counts as
	 * kept when one of the word pairs it's part of appears in the other
	 * version. Exact for scattered edits, and moved sections count as kept.
	 * Within about 10% of the real count up to a third of the words changed.
	 *
	 * @param int[] $a Earlier words.
	 * @param int[] $b New words.
	 * @return int
	 */
	private static function estimate( array $a, array $b ) {
		$in_a = self::runs( $a );
		$in_b = self::runs( $b );

		return max( self::uncovered( $a, $in_b ), self::uncovered( $b, $in_a ) );
	}

	/**
	 * Every pair of neighbouring words in a word list.
	 *
	 * @param int[] $words Words.
	 * @return array<string,bool>
	 */
	private static function runs( array $words ) {
		$runs = array();

		for ( $i = 0, $last = count( $words ) - 1; $i < $last; $i++ ) {
			$runs[ $words[ $i ] . ',' . $words[ $i + 1 ] ] = true;
		}

		return $runs;
	}

	/**
	 * Words not part of any word pair found in the other version.
	 *
	 * @param int[]              $words Words.
	 * @param array<string,bool> $other Pairs of the other version.
	 * @return int
	 */
	private static function uncovered( array $words, array $other ) {
		$count   = count( $words );
		$covered = array_fill( 0, $count, false );

		for ( $i = 0, $last = $count - 1; $i < $last; $i++ ) {
			if ( isset( $other[ $words[ $i ] . ',' . $words[ $i + 1 ] ] ) ) {
				$covered[ $i ]     = true;
				$covered[ $i + 1 ] = true;
			}
		}

		return $count - count( array_filter( $covered ) );
	}

	/**
	 * Changed words when word order is ignored: a lower bound of the real count.
	 *
	 * @param int[] $a Earlier words.
	 * @param int[] $b New words.
	 * @return int
	 */
	private static function changed_ignoring_order( array $a, array $b ) {
		$counts = array_count_values( $a );
		$common = 0;

		foreach ( $b as $id ) {
			if ( ! empty( $counts[ $id ] ) ) {
				--$counts[ $id ];
				++$common;
			}
		}

		return max( count( $a ), count( $b ) ) - $common;
	}

	/**
	 * Myers' O(ND) diff: the fewest words added plus removed to turn $a into $b.
	 *
	 * @param int[] $a   Earlier words.
	 * @param int[] $b   New words.
	 * @param int   $max Stop once the distance is known to be above this.
	 * @return array{distance:int|null,reached:int,out_of_budget:bool} Distance null when
	 *         it's above $max or the step budget ran out after ruling out 0 to "reached".
	 */
	private static function distance( array $a, array $b, $max ) {
		$n    = count( $a );
		$m    = count( $b );
		$max  = (int) min( $max, $n + $m );
		$none = array(
			'distance'      => null,
			'reached'       => $max,
			'out_of_budget' => false,
		);

		if ( $max < 0 ) {
			return $none;
		}

		$offset = $max + 1;
		$v      = array_fill( 0, 2 * $max + 3, 0 );
		$steps  = 0;

		for ( $d = 0; $d <= $max; $d++ ) {
			for ( $k = -$d; $k <= $d; $k += 2 ) {
				if ( -$d === $k || ( $d !== $k && $v[ $offset + $k - 1 ] < $v[ $offset + $k + 1 ] ) ) {
					$x = $v[ $offset + $k + 1 ];
				} else {
					$x = $v[ $offset + $k - 1 ] + 1;
				}

				$y     = $x - $k;
				$first = $x;

				while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
					++$x;
					++$y;
				}

				$steps            += 1 + $x - $first;
				$v[ $offset + $k ] = $x;

				if ( $x >= $n && $y >= $m ) {
					return array(
						'distance'      => $d,
						'reached'       => $d,
						'out_of_budget' => false,
					);
				}
			}

			if ( $steps > self::BUDGET && $d < $max ) {
				return array(
					'distance'      => null,
					'reached'       => $d,
					'out_of_budget' => true,
				);
			}
		}

		return $none;
	}

	/**
	 * Run preg_replace(), keeping the input when PCRE gives up (backtrack
	 * limit, bad UTF-8) instead of returning null.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Text.
	 * @return string
	 */
	private static function replace( $pattern, $replacement, $subject ) {
		$result = preg_replace( $pattern, $replacement, $subject );

		return null === $result ? $subject : $result;
	}
}

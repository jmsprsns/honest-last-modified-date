<?php
/**
 * The save guard: decides on every update of a published post whether its
 * "Last modified" date moves.
 *
 * WordPress sets post_modified to "now" on every update, whatever changed.
 * Here, the update is compared with the version from the date shown (the
 * baseline), and unless enough words changed the old date is put back before
 * the row is written. Changes add up across saves: ten small edits count
 * the same as one big one, because they're all measured from the baseline.
 *
 * Holding the date has two side effects in core that are smoothed over:
 * new revisions would take the old date (they copy post_modified), and
 * autosaves made before the save would look "more recent than the version
 * below" in the editor. Revisions get the real time, and superseded
 * autosaves are cleared the way core clears them after a normal save.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save hooks.
 */
final class Honest_Last_Modified_Date_Saves {

	/**
	 * Post meta holding the version the date shown stands for:
	 * array( 'gmt' => post_modified_gmt, 'title' => text, 'text' => text ).
	 */
	const META = '_hlmd_baseline';

	/**
	 * Request field with the editor's choice for this save ("update" or "keep").
	 */
	const FIELD = 'hlmd_choice';

	/**
	 * Nonce action (plus the post ID) for the classic editor's choice.
	 */
	const NONCE = 'hlmd_choice';

	/**
	 * Choices an editor can make for one save. Empty means "follow the rule".
	 */
	const CHOICES = array( 'update', 'keep' );

	/**
	 * Plugin instance.
	 *
	 * @var Honest_Last_Modified_Date_Plugin
	 */
	private $plugin;

	/**
	 * Choices sent with REST saves in this request, by post ID.
	 *
	 * @var array<int,string>
	 */
	private $choices = array();

	/**
	 * Saves in progress, by post ID, from the data filter to post_updated.
	 *
	 * @var array<int,array>
	 */
	private $pending = array();

	/**
	 * Posts whose date was kept in this request, by post ID.
	 *
	 * @var array<int,bool>
	 */
	private $held = array();

	/**
	 * Constructor.
	 *
	 * @param Honest_Last_Modified_Date_Plugin $plugin Plugin instance.
	 */
	public function __construct( Honest_Last_Modified_Date_Plugin $plugin ) {
		$this->plugin = $plugin;

		// Late, so content changed by other filters is what gets measured.
		add_filter( 'wp_insert_post_data', array( $this, 'filter_data' ), 20, 2 );
		add_action( 'post_updated', array( $this, 'after_update' ), 1, 3 );
		add_action( 'rest_api_init', array( $this, 'watch_rest_saves' ) );
	}

	/**
	 * Pick up the block editor's choice from REST saves of each content type.
	 *
	 * @return void
	 */
	public function watch_rest_saves() {
		foreach ( array_keys( $this->plugin->available_types() ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( $this, 'rest_choice' ), 10, 2 );
		}
	}

	/**
	 * Remember the choice sent with a REST update. The REST controller has
	 * already checked that the user can edit this post.
	 *
	 * @param stdClass|WP_Error $prepared Post about to be saved.
	 * @param WP_REST_Request   $request  Request.
	 * @return stdClass|WP_Error Unchanged.
	 */
	public function rest_choice( $prepared, $request ) {
		if ( $prepared instanceof stdClass && ! empty( $prepared->ID ) && $request instanceof WP_REST_Request ) {
			$choice = $request->get_param( self::FIELD );

			if ( is_string( $choice ) && in_array( $choice, self::CHOICES, true ) ) {
				$this->choices[ (int) $prepared->ID ] = $choice;
			}
		}

		return $prepared;
	}

	/**
	 * Put the old date back unless enough changed. Runs just before WordPress
	 * writes the row, with the date it's about to set already in $data.
	 *
	 * @param array $data    Slashed post fields about to be saved.
	 * @param array $postarr Fields passed to wp_insert_post(), with the ID on updates.
	 * @return array
	 */
	public function filter_data( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) ) {
			return $data;
		}

		$type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';

		if ( 'revision' === $type ) {
			return $this->date_revision( $data );
		}

		$post_id = empty( $postarr['ID'] ) ? 0 : (int) $postarr['ID'];

		if ( ! $post_id || ! isset( $data['post_status'] ) || 'publish' !== $data['post_status'] || ! $this->plugin->is_type_on( $type ) ) {
			return $data;
		}

		// Only updates of a post that's already out: first publishing sets the date as usual.
		$old = get_post( $post_id );

		if ( ! $old instanceof WP_Post || 'publish' !== $old->post_status || $old->post_type !== $type || '0000-00-00 00:00:00' === $old->post_modified_gmt ) {
			return $data;
		}

		$title   = isset( $data['post_title'] ) ? wp_unslash( $data['post_title'] ) : '';
		$content = isset( $data['post_content'] ) ? wp_unslash( $data['post_content'] ) : '';

		try {
			$result = $this->measure( $old, $title, $content );
		} catch ( Throwable $e ) {
			// Never break a save: leave the date to WordPress.
			return $data;
		}

		$choice = $this->choice_for( $post_id );

		if ( $result['too_long'] && '' === $choice ) {
			return $data;
		}

		$update = 'update' === $choice || ( '' === $choice && $result['met'] );

		/**
		 * Whether this save moves the post's "Last modified" date.
		 *
		 * @param bool    $update Whether the date moves.
		 * @param WP_Post $old    The post as it was before this save.
		 * @param array   $result What changed: changed, exact, needed, percent, met, old_words, new_words.
		 * @param string  $choice The editor's choice for this save: "update", "keep" or "" (the rule).
		 */
		$update = (bool) apply_filters( 'honest_last_modified_date_update', $update, $old, $result, $choice );

		// Record what the date shown stands for, the first time it's needed.
		if ( ! $result['baseline']['stored'] ) {
			$this->save_baseline( $post_id, $result['baseline']['gmt'], $result['baseline']['title'], $result['baseline']['text'] );
		}

		if ( $update ) {
			unset( $this->held[ $post_id ] );
		} else {
			$data['post_modified']     = $old->post_modified;
			$data['post_modified_gmt'] = $old->post_modified_gmt;
			$this->held[ $post_id ]    = true;
		}

		$this->pending[ $post_id ] = array(
			'result'  => $result,
			'choice'  => $choice,
			'update'  => $update,
			'old_gmt' => $old->post_modified_gmt,
			'same'    => $old->post_title === $title && $old->post_content === $content,
		);

		return $data;
	}

	/**
	 * After the row is written: move the baseline along with the date, or
	 * tidy up after keeping it.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post as saved.
	 * @param WP_Post $post_before Post before the save.
	 * @return void
	 */
	public function after_update( $post_id, $post_after, $post_before ) {
		$post_id = (int) $post_id;

		if ( ! isset( $this->pending[ $post_id ] ) || ! $post_after instanceof WP_Post ) {
			return;
		}

		$save = $this->pending[ $post_id ];
		unset( $this->pending[ $post_id ] );

		// The date moved (here or by another plugin), or it was due to and the clock hadn't ticked.
		$moved = $post_after->post_modified_gmt !== $save['old_gmt']
			|| ( $save['update'] && current_time( 'mysql', true ) === $save['old_gmt'] );
		$new   = $save['result']['new'];

		if ( $moved ) {
			$this->save_baseline( $post_id, $post_after->post_modified_gmt, $new['title'], $new['text'] );
		} else {
			if ( 'keep' === $save['choice'] ) {
				// "Keep the date and don't count these changes": measure from this version on.
				$this->save_baseline( $post_id, $save['old_gmt'], $new['title'], $new['text'] );
			}

			$this->clear_superseded_autosaves( $post_id );
		}

		if ( $this->plugin->get_setting( 'keep_log' ) ) {
			$result = $save['result'];

			Honest_Last_Modified_Date_Log::add(
				array(
					'post'    => $post_id,
					'time'    => time(),
					'user'    => get_current_user_id(),
					'changed' => (int) $result['changed'],
					'exact'   => (bool) $result['exact'],
					'needed'  => (int) $result['needed'],
					'percent' => (int) $result['percent'],
					'result'  => $moved ? 'updated' : 'kept',
					'by'      => '' !== $save['choice'] ? 'choice' : ( $result['too_long'] ? 'long' : 'rule' ),
				),
				$save['same']
			);
		}
	}

	/**
	 * How much changed between the version from the date shown and a new
	 * title and content, against the post type's rule.
	 *
	 * @param WP_Post $post        Post as saved (its date is the one shown).
	 * @param string  $new_title   New title (unslashed).
	 * @param string  $new_content New content (unslashed).
	 * @return array{baseline:array,new:array,old_words:int,new_words:int,needed:int,changed:int,exact:bool,percent:int,met:bool,too_long:bool,rule:array}
	 */
	public function measure( WP_Post $post, $new_title, $new_content ) {
		$row       = $this->plugin->type_settings( $post->post_type );
		$titles    = (bool) $this->plugin->get_setting( 'count_title' );
		$base      = $this->baseline( $post );
		$new       = array(
			'title' => Honest_Last_Modified_Date_Diff::text( $new_title ),
			'text'  => Honest_Last_Modified_Date_Diff::text( $new_content ),
		);
		$old_words = Honest_Last_Modified_Date_Diff::words( $titles ? $base['title'] . "\n" . $base['text'] : $base['text'] );
		$new_words = Honest_Last_Modified_Date_Diff::words( $titles ? $new['title'] . "\n" . $new['text'] : $new['text'] );
		$old_count = count( $old_words );
		$result    = array(
			'baseline'  => $base,
			'new'       => $new,
			'old_words' => $old_count,
			'new_words' => count( $new_words ),
			'needed'    => Honest_Last_Modified_Date_Plugin::needed( $row, $old_count ),
			'changed'   => 0,
			'exact'     => true,
			'percent'   => 0,
			'met'       => false,
			'too_long'  => false,
			'rule'      => $row,
		);

		if ( $old_count > Honest_Last_Modified_Date_Diff::MAX_WORDS || $result['new_words'] > Honest_Last_Modified_Date_Diff::MAX_WORDS ) {
			$result['too_long'] = true;
			return $result;
		}

		$diff = Honest_Last_Modified_Date_Diff::changed( $old_words, $new_words, $result['needed'] );

		$result['changed'] = (int) $diff['changed'];
		$result['exact']   = (bool) $diff['exact'];
		$result['met']     = $result['changed'] >= $result['needed'];

		if ( $old_count > 0 ) {
			$result['percent'] = (int) round( $result['changed'] * 100 / $old_count );
		} elseif ( $result['changed'] > 0 ) {
			$result['percent'] = 100;
		}

		return $result;
	}

	/**
	 * The version the date shown stands for.
	 *
	 * The stored copy counts only while its date matches the post's. Without
	 * one (first save since install, or the date moved while the plugin was
	 * off), the saved post is that version: every earlier save moved the date.
	 *
	 * @param WP_Post $post Post as saved.
	 * @return array{gmt:string,title:string,text:string,stored:bool}
	 */
	public function baseline( WP_Post $post ) {
		$saved = get_post_meta( $post->ID, self::META, true );

		if ( is_array( $saved ) && isset( $saved['gmt'], $saved['title'], $saved['text'] )
			&& is_string( $saved['title'] ) && is_string( $saved['text'] ) && $saved['gmt'] === $post->post_modified_gmt ) {
			return array(
				'gmt'    => $saved['gmt'],
				'title'  => $saved['title'],
				'text'   => $saved['text'],
				'stored' => true,
			);
		}

		return array(
			'gmt'    => $post->post_modified_gmt,
			'title'  => Honest_Last_Modified_Date_Diff::text( $post->post_title ),
			'text'   => Honest_Last_Modified_Date_Diff::text( $post->post_content ),
			'stored' => false,
		);
	}

	/**
	 * The editor's choice for this save, if choosing is allowed. Each one is used once.
	 *
	 * @param int $post_id Post ID.
	 * @return string "update", "keep" or "" (follow the rule).
	 */
	public function choice_for( $post_id ) {
		if ( ! $this->plugin->get_setting( 'allow_choice' ) ) {
			return '';
		}

		// A choice sent with a REST save is for that one save: nested saves
		// in the same request (plugins re-saving on save_post) follow the rule.
		if ( isset( $this->choices[ $post_id ] ) ) {
			$choice = $this->choices[ $post_id ];
			unset( $this->choices[ $post_id ] );
			return $choice;
		}

		// Classic editor form. Only for the post the form is for, with its nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified right here.
		if ( ! isset( $_POST[ self::FIELD ], $_POST[ self::NONCE . '_nonce' ], $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== $post_id ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE . '_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		$choice = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return in_array( $choice, self::CHOICES, true ) ? $choice : '';
	}

	/**
	 * Store the version the date shown stands for.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $gmt     The post's post_modified_gmt.
	 * @param string $title   Title text.
	 * @param string $text    Content text.
	 * @return void
	 */
	private function save_baseline( $post_id, $gmt, $title, $text ) {
		update_post_meta(
			$post_id,
			self::META,
			wp_slash(
				array(
					'gmt'   => (string) $gmt,
					'title' => (string) $title,
					'text'  => (string) $text,
				)
			)
		);
	}

	/**
	 * Give a new revision of a post whose date was kept the time it was
	 * really made. WordPress dates revisions with the post's post_modified,
	 * so every one would otherwise show the old date in the Revisions screen.
	 *
	 * @param array $data Revision fields about to be saved.
	 * @return array
	 */
	private function date_revision( array $data ) {
		$parent = isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0;
		$name   = isset( $data['post_name'] ) ? (string) $data['post_name'] : '';

		if ( ! $parent || empty( $this->held[ $parent ] ) || $parent . '-revision-v1' !== $name ) {
			return $data;
		}

		$data['post_date']         = current_time( 'mysql' );
		$data['post_date_gmt']     = current_time( 'mysql', true );
		$data['post_modified']     = $data['post_date'];
		$data['post_modified_gmt'] = $data['post_date_gmt'];

		return $data;
	}

	/**
	 * Delete autosaves made before this save. The editors show an autosave
	 * as "more recent than the version below" when it's newer than the
	 * post's date, and delete it otherwise; with the date kept, every
	 * autosave from this editing session would trip that notice.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function clear_superseded_autosaves( $post_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'revision',
				'post_status'    => 'inherit',
				'post_parent'    => $post_id,
				'name'           => $post_id . '-autosave-v1',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$now = time();

		foreach ( $ids as $id ) {
			$autosave = get_post( $id );

			if ( $autosave instanceof WP_Post && wp_is_post_autosave( $autosave ) && (int) $autosave->post_parent === $post_id
				&& strtotime( $autosave->post_modified_gmt . ' +0000' ) <= $now ) {
				wp_delete_post_revision( $autosave );
			}
		}
	}
}

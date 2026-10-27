<?php
/**
 * Editing screens: a "Last modified date" panel in the block editor and a
 * box in the classic editor. Both show the date readers see, how much has
 * changed since then against the rule, and (if allowed) let the editor set
 * today's date or keep the old one for this one update.
 *
 * The live numbers come from a REST route that measures the unsaved title
 * and content the same way a save does. The block editor sends the choice
 * along with its save request; the classic editor posts it with its form.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor panel, classic box and the check route.
 */
final class Honest_Last_Modified_Date_Editor {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'honest-last-modified-date/v1';

	/**
	 * Largest unsaved content (in bytes) the check route accepts.
	 */
	const MAX_BYTES = 4194304;

	/**
	 * Plugin instance.
	 *
	 * @var Honest_Last_Modified_Date_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Honest_Last_Modified_Date_Plugin $plugin Plugin instance.
	 */
	public function __construct( Honest_Last_Modified_Date_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_classic' ) );
	}

	/**
	 * POST /honest-last-modified-date/v1/check: how much an unsaved version changes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_check' ),
				'permission_callback' => array( $this, 'can_check' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'title'   => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Only people who can edit the post can measure it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_check( $request ) {
		$post = get_post( absint( $request['id'] ) );

		return $post instanceof WP_Post && 'revision' !== $post->post_type && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Measure an unsaved title and content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_check( $request ) {
		$post    = get_post( absint( $request['id'] ) );
		$title   = $request['title'];
		$content = $request['content'];

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'hlmd_no_post', __( 'That post doesn\'t exist.', 'honest-last-modified-date' ), array( 'status' => 404 ) );
		}

		if ( ( is_string( $content ) && strlen( $content ) > self::MAX_BYTES ) || ( is_string( $title ) && strlen( $title ) > 65535 ) ) {
			return new WP_Error( 'hlmd_too_big', __( 'This post is too long to check while editing.', 'honest-last-modified-date' ), array( 'status' => 413 ) );
		}

		return rest_ensure_response( $this->status( $post, is_string( $title ) ? $title : null, is_string( $content ) ? $content : null ) );
	}

	/**
	 * Everything the panel and the box show, as plain values and ready-made text.
	 *
	 * @param WP_Post     $post    Saved post.
	 * @param string|null $title   Unsaved title, or null for the saved one.
	 * @param string|null $content Unsaved content, or null for the saved one.
	 * @return array<string,mixed>
	 */
	public function status( WP_Post $post, $title = null, $content = null ) {
		$status = array(
			'on'        => $this->plugin->is_type_on( $post->post_type ),
			'published' => 'publish' === $post->post_status,
		);

		if ( ! $status['on'] || ! $status['published'] ) {
			return $status;
		}

		$result = $this->plugin->saves()->measure(
			$post,
			null === $title ? $post->post_title : $title,
			null === $content ? $post->post_content : $content
		);
		$needed = (int) $result['needed'];

		if ( $result['exact'] && $result['met'] ) {
			$count = sprintf(
				/* translators: 1: words changed, 2: words needed. */
				_n( '%1$s word changed, %2$s needed', '%1$s words changed, %2$s needed', (int) $result['changed'], 'honest-last-modified-date' ),
				number_format_i18n( $result['changed'] ),
				number_format_i18n( $needed )
			);
		} elseif ( $result['exact'] ) {
			$count = sprintf(
				/* translators: 1: words changed, 2: words needed. */
				_n( '%1$s of %2$s word changed', '%1$s of %2$s words changed', $needed, 'honest-last-modified-date' ),
				number_format_i18n( $result['changed'] ),
				number_format_i18n( $needed )
			);
		} elseif ( $result['met'] ) {
			/* translators: %s: number of words. */
			$count = sprintf( __( 'At least %s words changed', 'honest-last-modified-date' ), number_format_i18n( $result['changed'] ) );
		} else {
			$count = sprintf(
				/* translators: 1: words changed (estimate), 2: words needed. */
				__( 'About %1$s of %2$s words changed', 'honest-last-modified-date' ),
				number_format_i18n( $result['changed'] ),
				number_format_i18n( $needed )
			);
		}

		return array_merge(
			$status,
			array(
				'shown'       => get_the_modified_date( '', $post ),
				'changed'     => (int) $result['changed'],
				'needed'      => $needed,
				'percent'     => (int) $result['percent'],
				'met'         => (bool) $result['met'],
				'tooLong'     => (bool) $result['too_long'],
				'meter'       => $result['met'] ? 100 : (int) floor( 100 * $result['changed'] / max( 1, $needed ) ),
				'rule'        => Honest_Last_Modified_Date_Plugin::rule_text( $result['rule'], $post->post_type ),
				'countText'   => $count,
				/* translators: %s: share of the post's words that changed. */
				'percentText' => sprintf( __( '%s%%', 'honest-last-modified-date' ), number_format_i18n( (int) $result['percent'] ) ),
			)
		);
	}

	/**
	 * Whether the panel shows for this post.
	 *
	 * @param WP_Post|null $post Post being edited.
	 * @return bool
	 */
	private function shows_for( $post ) {
		return $post instanceof WP_Post
			&& $this->plugin->get_setting( 'editor_panel' )
			&& $this->plugin->is_type_on( $post->post_type )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Settings and text for the panel and the box scripts.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return array<string,mixed>
	 */
	private function script_data( WP_Post $post ) {
		$route = function_exists( 'rest_get_route_for_post' ) ? rest_get_route_for_post( $post ) : '';

		return array(
			'route'       => '/' . self::REST_NAMESPACE . '/check',
			'postId'      => (int) $post->ID,
			'postPath'    => untrailingslashit( (string) $route ),
			'allowChoice' => (bool) $this->plugin->get_setting( 'allow_choice' ),
			'status'      => $this->status( $post ),
			'icon'        => Honest_Last_Modified_Date_Icons::get( 'calendar-check', 20 ),
			'i18n'        => array(
				'title'        => __( 'Last modified date', 'honest-last-modified-date' ),
				'shown'        => __( 'Date readers see', 'honest-last-modified-date' ),
				'notPublished' => __( 'Not published yet. The date is set when you publish, and from then on it only moves when enough has changed.', 'honest-last-modified-date' ),
				'tooLong'      => __( 'This post is too long to compare, so updates set today\'s date as usual.', 'honest-last-modified-date' ),
				'willUpdate'   => __( 'Updating now sets today\'s date.', 'honest-last-modified-date' ),
				'willKeep'     => __( 'Updating now keeps this date.', 'honest-last-modified-date' ),
				'whenUpdate'   => __( 'When you update', 'honest-last-modified-date' ),
				/* translators: %s: the rule, e.g. "300 words or 20% of the post". */
				'choiceRule'   => __( 'Follow the rule: %s', 'honest-last-modified-date' ),
				'choiceUpdate' => __( 'Set today\'s date', 'honest-last-modified-date' ),
				'choiceKeep'   => __( 'Keep this date and don\'t count these changes', 'honest-last-modified-date' ),
				'failed'       => __( 'Couldn\'t check the changes just now.', 'honest-last-modified-date' ),
			),
		);
	}

	/**
	 * Load the block editor panel.
	 *
	 * @return void
	 */
	public function enqueue_block_editor() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();

		if ( ! $screen || 'post' !== $screen->base || ! $this->shows_for( $post ) ) {
			return;
		}

		wp_enqueue_style( 'honest-last-modified-date-editor', HONEST_LAST_MODIFIED_DATE_URL . 'assets/editor.css', array(), HONEST_LAST_MODIFIED_DATE_VERSION );
		wp_enqueue_script(
			'honest-last-modified-date-editor',
			HONEST_LAST_MODIFIED_DATE_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-editor', 'wp-edit-post' ),
			HONEST_LAST_MODIFIED_DATE_VERSION,
			true
		);
		wp_localize_script( 'honest-last-modified-date-editor', 'honest_last_modified_date_editor', $this->script_data( $post ) );
	}

	/**
	 * Add the classic editor box. The block editor uses the panel instead.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Post.
	 * @return void
	 */
	public function add_box( $post_type, $post = null ) {
		if ( ! $this->shows_for( $post ) || $post->post_type !== $post_type ) {
			return;
		}

		add_meta_box(
			'honest-last-modified-date',
			__( 'Last Modified Date', 'honest-last-modified-date' ),
			array( $this, 'render_box' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * The classic editor box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_box( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$data   = $this->script_data( $post );
		$status = $data['status'];
		$text   = $data['i18n'];

		if ( empty( $status['published'] ) ) {
			echo '<p class="hlmd-note">' . esc_html( $text['notPublished'] ) . '</p>';
			return;
		}

		if ( ! empty( $status['tooLong'] ) ) {
			echo '<p class="hlmd-note">' . esc_html( $text['tooLong'] ) . '</p>';
			return;
		}

		$choices = array(
			''       => sprintf( $text['choiceRule'], $status['rule'] ),
			'update' => $text['choiceUpdate'],
			'keep'   => $text['choiceKeep'],
		);
		?>
		<div class="hlmd-box" data-hlmd-box>
			<div class="hlmd-shown">
				<?php echo $data['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span class="hlmd-shown-text">
					<span class="hlmd-shown-label"><?php echo esc_html( $text['shown'] ); ?></span>
					<strong data-hlmd="shown"><?php echo esc_html( $status['shown'] ); ?></strong>
				</span>
			</div>
			<div class="hlmd-meter<?php echo $status['met'] ? ' is-met' : ''; ?>" data-hlmd="meter"><span style="width:<?php echo esc_attr( (string) $status['meter'] ); ?>%"></span></div>
			<p class="hlmd-count">
				<span data-hlmd="count"><?php echo esc_html( $status['countText'] ); ?></span>
				<span class="hlmd-muted" data-hlmd="percent"><?php echo esc_html( $status['percentText'] ); ?></span>
			</p>
			<p class="hlmd-verdict<?php echo $status['met'] ? ' is-update' : ''; ?>" data-hlmd="verdict"><?php echo esc_html( $status['met'] ? $text['willUpdate'] : $text['willKeep'] ); ?></p>

			<?php if ( $data['allowChoice'] ) : ?>
				<?php wp_nonce_field( Honest_Last_Modified_Date_Saves::NONCE . '_' . $post->ID, Honest_Last_Modified_Date_Saves::NONCE . '_nonce' ); ?>
				<fieldset class="hlmd-choices">
					<legend><?php echo esc_html( $text['whenUpdate'] ); ?></legend>
					<?php foreach ( $choices as $value => $label ) : ?>
						<label><input type="radio" name="<?php echo esc_attr( Honest_Last_Modified_Date_Saves::FIELD ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( '', $value ); ?>> <span<?php echo '' === $value ? ' data-hlmd="rule"' : ''; ?>><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Styles and live updates for the classic editor box.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue_classic( $hook ) {
		if ( 'post.php' !== $hook ) {
			return;
		}

		$post = get_post();

		if ( ! $this->shows_for( $post ) || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
			return;
		}

		wp_enqueue_style( 'honest-last-modified-date-editor', HONEST_LAST_MODIFIED_DATE_URL . 'assets/editor.css', array(), HONEST_LAST_MODIFIED_DATE_VERSION );
		wp_enqueue_script( 'honest-last-modified-date-classic', HONEST_LAST_MODIFIED_DATE_URL . 'assets/classic.js', array( 'wp-api-fetch' ), HONEST_LAST_MODIFIED_DATE_VERSION, true );
		wp_localize_script( 'honest-last-modified-date-classic', 'honest_last_modified_date_editor', $this->script_data( $post ) );
	}
}

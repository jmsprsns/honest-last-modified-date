<?php
/**
 * Admin side: the Content Powered > Honest Last Modified Date page and the Plugins-screen link.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Honest_Last_Modified_Date_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'honest-last-modified-date';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'calendar-check';

	/**
	 * Settings tabs: id => Lucide icon.
	 */
	const TABS = array(
		'rules'    => 'sliders-horizontal',
		'activity' => 'history',
		'options'  => 'settings-2',
	);

	/**
	 * The admin-post.php action (and nonce action) that clears the activity log.
	 */
	const CLEAR_ACTION = 'honest_last_modified_date_clear';

	/**
	 * Transient caching each content type's typical length in words.
	 */
	const TYPICAL = 'honest_last_modified_date_typical';

	/**
	 * Plugin instance.
	 *
	 * @var Honest_Last_Modified_Date_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Honest_Last_Modified_Date_Plugin $plugin Plugin instance.
	 */
	public function __construct( Honest_Last_Modified_Date_Plugin $plugin ) {
		$this->plugin = $plugin;

		Honest_Last_Modified_Date_Menu::init();
		Honest_Last_Modified_Date_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( $this, 'clear_log' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( HONEST_LAST_MODIFIED_DATE_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'honest-last-modified-date' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Honest_Last_Modified_Date_Icons::get( self::ICON, 17, 'honest-last-modified-date-menu-icon' );
		$menu_title = $icon . '<span class="honest-last-modified-date-menu-label">' . esc_html__( 'Honest Last Modified Date', 'honest-last-modified-date' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Honest_Last_Modified_Date_Menu::SLUG,
			__( 'Honest Last Modified Date', 'honest-last-modified-date' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' hlmd-settings-page' : $classes;
	}

	/**
	 * Register the setting. The tabs are switched in the browser, so one
	 * form saves every tab at once.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Honest_Last_Modified_Date_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Honest_Last_Modified_Date_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$in     = is_array( $input ) ? $input : array();
		$posted = isset( $in['types'] ) && is_array( $in['types'] ) ? $in['types'] : array();
		$saved  = get_option( Honest_Last_Modified_Date_Plugin::OPTION, array() );
		$types  = array();

		foreach ( array_keys( $this->plugin->available_types() ) as $type ) {
			$row = isset( $posted[ $type ] ) && is_array( $posted[ $type ] ) ? $posted[ $type ] : array();

			$types[ $type ] = array(
				'on'      => empty( $row['on'] ) ? 0 : 1,
				'rule'    => isset( $row['rule'] ) && in_array( $row['rule'], Honest_Last_Modified_Date_Plugin::RULES, true ) ? $row['rule'] : 'either',
				'words'   => self::number( $row, 'words', 1, Honest_Last_Modified_Date_Plugin::MAX_WORDS, 300 ),
				'percent' => self::number( $row, 'percent', 1, 100, 20 ),
			);
		}

		// Keep the rules of content types that aren't registered right now
		// (their plugin is switched off), so they're there when it's back.
		if ( is_array( $saved ) && isset( $saved['types'] ) && is_array( $saved['types'] ) ) {
			foreach ( $saved['types'] as $type => $row ) {
				if ( ! isset( $types[ $type ] ) && is_string( $type ) && sanitize_key( $type ) === $type && is_array( $row ) ) {
					$types[ $type ] = array(
						'on'      => empty( $row['on'] ) ? 0 : 1,
						'rule'    => isset( $row['rule'] ) && in_array( $row['rule'], Honest_Last_Modified_Date_Plugin::RULES, true ) ? $row['rule'] : 'either',
						'words'   => self::number( $row, 'words', 1, Honest_Last_Modified_Date_Plugin::MAX_WORDS, 300 ),
						'percent' => self::number( $row, 'percent', 1, 100, 20 ),
					);
				}
			}
		}

		return array(
			'types'        => $types,
			'count_title'  => empty( $in['count_title'] ) ? 0 : 1,
			'editor_panel' => empty( $in['editor_panel'] ) ? 0 : 1,
			'allow_choice' => empty( $in['allow_choice'] ) ? 0 : 1,
			'keep_log'     => empty( $in['keep_log'] ) ? 0 : 1,
		);
	}

	/**
	 * A whole number from a submitted row, kept within bounds.
	 *
	 * @param array  $row      Submitted values.
	 * @param string $key      Field.
	 * @param int    $min      Lowest allowed.
	 * @param int    $max      Highest allowed.
	 * @param int    $fallback Used when the field is missing or not a number.
	 * @return int
	 */
	private static function number( array $row, $key, $min, $max, $fallback ) {
		$value = isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? (int) $row[ $key ] : (int) $fallback;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="honest-last-modified-date-menu-icon-style">'
			. '#adminmenu a:has(>.honest-last-modified-date-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .honest-last-modified-date-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .honest-last-modified-date-menu-icon,#adminmenu a:hover .honest-last-modified-date-menu-icon,#adminmenu a:focus .honest-last-modified-date-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'honest-last-modified-date-admin', HONEST_LAST_MODIFIED_DATE_URL . 'assets/admin.css', array(), HONEST_LAST_MODIFIED_DATE_VERSION );
		wp_enqueue_script( 'honest-last-modified-date-admin', HONEST_LAST_MODIFIED_DATE_URL . 'assets/admin.js', array(), HONEST_LAST_MODIFIED_DATE_VERSION, true );

		wp_localize_script(
			'honest-last-modified-date-admin',
			'honest_last_modified_date_admin',
			array(
				'i18n' => array(
					/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
					'words'        => __( 'The date moves once %1$s words have changed since the date readers see.', 'honest-last-modified-date' ),
					/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
					'percent'      => __( 'The date moves once %2$s%% of a %3$s\'s words have changed since the date readers see.', 'honest-last-modified-date' ),
					/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
					'either'       => __( 'The date moves once %1$s words or %2$s%% of a %3$s\'s words have changed, whichever comes first.', 'honest-last-modified-date' ),
					/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
					'both'         => __( 'The date moves once at least %1$s words and %2$s%% of a %3$s\'s words have changed.', 'honest-last-modified-date' ),
					/* translators: 3: content type, e.g. "post", 4: typical length in words, 5: words needed. */
					'typicalWords' => __( 'A typical %3$s here has %4$s words, so that\'s about %5$s changed words.', 'honest-last-modified-date' ),
					/* translators: 3: content type, e.g. "post", 4: typical length in words, 5: percentage of it. */
					'typicalShare' => __( 'A typical %3$s here has %4$s words, so that\'s about %5$s%% of it.', 'honest-last-modified-date' ),
					'clearConfirm' => __( 'Clear the activity list and start the totals from zero? Dates and rules aren\'t affected.', 'honest-last-modified-date' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Honest_Last_Modified_Date_Icons::get( $name, $size );
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array>
	 */
	private function sections() {
		$sections = array();

		foreach ( $this->plugin->available_types() as $type => $object ) {
			$sections[ 'type-' . $type ] = array(
				'panel'  => 'rules',
				'icon'   => self::type_icon( $type ),
				'title'  => $object->labels->name,
				'intro'  => sprintf(
					/* translators: %s: content type, singular and lowercase, e.g. "post". */
					__( 'When an update to a published %s should move its "Last modified" date to today. Edits are measured from the version readers see dated, so small edits add up.', 'honest-last-modified-date' ),
					self::lower( $object->labels->singular_name )
				),
				'render' => array( $this, 'render_type' ),
				'args'   => array( $type, $object ),
			);
		}

		return $sections + array(
			'totals'  => array(
				'panel'  => 'activity',
				'icon'   => 'gauge',
				'title'  => __( 'Overview', 'honest-last-modified-date' ),
				'intro'  => '',
				'render' => array( $this, 'render_totals' ),
				'args'   => array(),
			),
			'recent'  => array(
				'panel'  => 'activity',
				'icon'   => 'list-checks',
				'title'  => __( 'Recent updates', 'honest-last-modified-date' ),
				'intro'  => __( 'The latest updates to published content, what changed, and whether the date moved.', 'honest-last-modified-date' ),
				'render' => array( $this, 'render_recent' ),
				'args'   => array(),
			),
			'editor'  => array(
				'panel'  => 'options',
				'icon'   => 'square-pen',
				'title'  => __( 'In the editor', 'honest-last-modified-date' ),
				'intro'  => __( 'A "Last modified date" panel in the block editor (and a box in the classic editor) shows the date readers see and how close an update is to moving it.', 'honest-last-modified-date' ),
				'render' => array( $this, 'render_editor' ),
				'args'   => array(),
			),
			'counts'  => array(
				'panel'  => 'options',
				'icon'   => 'scale',
				'title'  => __( 'What counts as a change', 'honest-last-modified-date' ),
				'intro'  => __( 'Only words readers can see are compared, without capital letters or punctuation, so formatting and behind-the-scenes edits never move the date.', 'honest-last-modified-date' ),
				'render' => array( $this, 'render_counts' ),
				'args'   => array(),
			),
			'logging' => array(
				'panel'  => 'options',
				'icon'   => 'history',
				'title'  => __( 'Activity', 'honest-last-modified-date' ),
				'intro'  => '',
				'render' => array( $this, 'render_logging' ),
				'args'   => array(),
			),
		);
	}

	/**
	 * Lucide icon for a content type.
	 *
	 * @param string $type Post type name.
	 * @return string
	 */
	private static function type_icon( $type ) {
		if ( 'post' === $type ) {
			return 'file-text';
		}

		return 'page' === $type ? 'file' : 'files';
	}

	/**
	 * Lowercase a label, multibyte-safe.
	 *
	 * @param string $text Label.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * Tab name.
	 *
	 * @param string $id Tab id.
	 * @return string
	 */
	private function tab_label( $id ) {
		switch ( $id ) {
			case 'rules':
				return __( 'Rules', 'honest-last-modified-date' );
			case 'activity':
				return __( 'Activity', 'honest-last-modified-date' );
		}

		return __( 'Options', 'honest-last-modified-date' );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="hlmd-header">
			<div class="hlmd-header-title">
				<span class="hlmd-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="hlmd-version"><?php echo esc_html( 'v' . HONEST_LAST_MODIFIED_DATE_VERSION ); ?></span>
			</div>
			<nav class="hlmd-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'honest-last-modified-date' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="hlmd-tab" role="tab" data-hlmd-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap hlmd-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			if ( isset( $_GET['hlmd-cleared'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Activity cleared.', 'honest-last-modified-date' ) . '</p></div>';
			}
			?>

			<form action="options.php" method="post" class="hlmd-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="hlmd-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One box.
	 *
	 * @param string $id      Section id.
	 * @param array  $section Section from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox hlmd-box" id="hlmd-<?php echo esc_attr( $id ); ?>" data-hlmd-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="hlmd-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func_array( $section['render'], $section['args'] ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a top-level setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Honest_Last_Modified_Date_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Name attribute for a content type's setting.
	 *
	 * @param string $type Post type name.
	 * @param string $key  Setting key.
	 * @return string
	 */
	private static function type_name( $type, $key ) {
		return Honest_Last_Modified_Date_Plugin::OPTION . '[types][' . $type . '][' . $key . ']';
	}

	/**
	 * Checkbox with a label and an optional description.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Label.
	 * @param string $desc    Description under it.
	 * @param string $depends Setting that has to be on for this one to apply.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '', $depends = '' ) {
		echo '<div class="hlmd-setting"' . ( '' !== $depends ? ' data-hlmd-depends="' . esc_attr( $depends ) . '"' : '' ) . '>';
		printf(
			'<label class="hlmd-check"><input type="checkbox" name="%1$s" value="1" %2$s> <span>%3$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * A content type's rule.
	 *
	 * @param string       $type        Post type name.
	 * @param WP_Post_Type $type_object Post type.
	 * @return void
	 */
	public function render_type( $type, $type_object ) {
		$row      = $this->plugin->type_settings( $type );
		$typical  = $this->typical_lengths();
		$singular = self::lower( $type_object->labels->singular_name );
		$rules    = array(
			'words'   => array( 'whole-word', __( 'Words changed', 'honest-last-modified-date' ), __( 'A set number of words added, removed or rewritten.', 'honest-last-modified-date' ) ),
			'percent' => array( 'percent', __( 'Share of the words', 'honest-last-modified-date' ), __( 'A percentage of its words. Scales with short and long content.', 'honest-last-modified-date' ) ),
			'either'  => array( 'split', __( 'Whichever comes first', 'honest-last-modified-date' ), __( 'The word count or the percentage, whichever is reached first.', 'honest-last-modified-date' ) ),
			'both'    => array( 'merge', __( 'Both', 'honest-last-modified-date' ), __( 'Needs the word count and the percentage, so short pages need more than a line.', 'honest-last-modified-date' ) ),
		);
		?>
		<label class="hlmd-master">
			<input type="checkbox" name="<?php echo esc_attr( self::type_name( $type, 'on' ) ); ?>" value="1" data-hlmd-master <?php checked( 1, $row['on'] ); ?>>
			<span>
				<?php
				/* translators: %s: content type, plural and lowercase, e.g. "posts". */
				echo esc_html( sprintf( __( 'Keep honest dates on %s', 'honest-last-modified-date' ), self::lower( $type_object->labels->name ) ) );
				?>
			</span>
		</label>
		<p class="hlmd-off-note" data-hlmd-off>
			<?php
			/* translators: %s: content type, plural and lowercase, e.g. "pages". */
			echo esc_html( sprintf( __( 'Off: every update to %s sets today\'s date, as WordPress normally does.', 'honest-last-modified-date' ), self::lower( $type_object->labels->name ) ) );
			?>
		</p>

		<div class="hlmd-type-body" data-hlmd-body>
			<p class="hlmd-field-label"><?php esc_html_e( 'Move the date when', 'honest-last-modified-date' ); ?></p>
			<div class="hlmd-options">
				<?php foreach ( $rules as $rule => $info ) : ?>
					<label class="hlmd-option">
						<input type="radio" name="<?php echo esc_attr( self::type_name( $type, 'rule' ) ); ?>" value="<?php echo esc_attr( $rule ); ?>" data-hlmd-rule <?php checked( $rule, $row['rule'] ); ?>>
						<span class="hlmd-option-icon"><?php echo self::icon( $info[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
						<span class="hlmd-option-text"><strong><?php echo esc_html( $info[1] ); ?></strong><span><?php echo esc_html( $info[2] ); ?></span></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="hlmd-thresholds">
				<label class="hlmd-threshold" data-hlmd-uses="words">
					<?php echo self::icon( 'whole-word', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php esc_html_e( 'At least', 'honest-last-modified-date' ); ?></span>
					<input type="number" class="small-text" name="<?php echo esc_attr( self::type_name( $type, 'words' ) ); ?>" value="<?php echo esc_attr( (string) $row['words'] ); ?>" min="1" max="<?php echo esc_attr( (string) Honest_Last_Modified_Date_Plugin::MAX_WORDS ); ?>" step="1" data-hlmd-words>
					<span><?php esc_html_e( 'words changed', 'honest-last-modified-date' ); ?></span>
				</label>
				<label class="hlmd-threshold" data-hlmd-uses="percent">
					<?php echo self::icon( 'percent', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<span><?php esc_html_e( 'At least', 'honest-last-modified-date' ); ?></span>
					<input type="number" class="small-text" name="<?php echo esc_attr( self::type_name( $type, 'percent' ) ); ?>" value="<?php echo esc_attr( (string) $row['percent'] ); ?>" min="1" max="100" step="1" data-hlmd-percent>
					<span><?php esc_html_e( '% of the words changed', 'honest-last-modified-date' ); ?></span>
				</label>
			</div>

			<p class="hlmd-callout" data-hlmd-summary data-type="<?php echo esc_attr( $singular ); ?>" data-typical="<?php echo esc_attr( (string) ( isset( $typical[ $type ] ) ? (int) $typical[ $type ] : 0 ) ); ?>">
				<?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span data-hlmd-summary-text><?php echo esc_html( $this->summary( $row, $singular ) ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * The rule as a sentence (the page's script keeps it current as you type).
	 *
	 * @param array  $row      Type settings.
	 * @param string $singular Content type, singular and lowercase.
	 * @return string
	 */
	private function summary( array $row, $singular ) {
		$words   = number_format_i18n( $row['words'] );
		$percent = number_format_i18n( $row['percent'] );

		switch ( $row['rule'] ) {
			case 'words':
				/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
				return sprintf( __( 'The date moves once %1$s words have changed since the date readers see.', 'honest-last-modified-date' ), $words, $percent, $singular );
			case 'percent':
				/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
				return sprintf( __( 'The date moves once %2$s%% of a %3$s\'s words have changed since the date readers see.', 'honest-last-modified-date' ), $words, $percent, $singular );
			case 'both':
				/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
				return sprintf( __( 'The date moves once at least %1$s words and %2$s%% of a %3$s\'s words have changed.', 'honest-last-modified-date' ), $words, $percent, $singular );
		}

		/* translators: 1: number of words, 2: percentage, 3: content type, e.g. "post". */
		return sprintf( __( 'The date moves once %1$s words or %2$s%% of a %3$s\'s words have changed, whichever comes first.', 'honest-last-modified-date' ), $words, $percent, $singular );
	}

	/**
	 * Typical length (median words) of each content type's latest 100
	 * published items, for the rule hints. Cached for 12 hours.
	 *
	 * @return array<string,int>
	 */
	private function typical_lengths() {
		static $lengths = null;

		if ( null !== $lengths ) {
			return $lengths;
		}

		$cached = get_transient( self::TYPICAL );

		if ( is_array( $cached ) ) {
			$lengths = array_map( 'intval', $cached );
			return $lengths;
		}

		$lengths = array();

		foreach ( array_keys( $this->plugin->available_types() ) as $type ) {
			$posts  = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			$counts = array();

			foreach ( $posts as $post ) {
				$counts[] = count( Honest_Last_Modified_Date_Diff::words( Honest_Last_Modified_Date_Diff::text( $post->post_content ) ) );
			}

			$counts = array_values( array_filter( $counts ) );
			sort( $counts );
			$lengths[ $type ] = $counts ? (int) $counts[ (int) floor( ( count( $counts ) - 1 ) / 2 ) ] : 0;
		}

		set_transient( self::TYPICAL, $lengths, 12 * HOUR_IN_SECONDS );

		return $lengths;
	}

	/**
	 * Activity: running totals.
	 *
	 * @return void
	 */
	public function render_totals() {
		$totals = Honest_Last_Modified_Date_Log::totals();
		$tiles  = array(
			array( 'calendar-check', $totals['kept'], __( 'Updates that kept the date', 'honest-last-modified-date' ), 'is-kept' ),
			array( 'calendar-plus', $totals['updated'], __( 'Updates that moved the date', 'honest-last-modified-date' ), 'is-moved' ),
			array( 'hand', $totals['chosen'], __( 'Chosen by an editor', 'honest-last-modified-date' ), '' ),
		);
		?>
		<div class="hlmd-stats">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="hlmd-stat <?php echo esc_attr( $tile[3] ); ?>">
					<span class="hlmd-stat-icon"><?php echo self::icon( $tile[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="hlmd-stat-num"><?php echo esc_html( number_format_i18n( $tile[1] ) ); ?></span>
					<span class="hlmd-stat-label"><?php echo esc_html( $tile[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="hlmd-muted hlmd-since">
			<?php
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'Counting since %s.', 'honest-last-modified-date' ), wp_date( get_option( 'date_format' ), $totals['since'] ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Activity: the latest updates.
	 *
	 * @return void
	 */
	public function render_recent() {
		$entries = Honest_Last_Modified_Date_Log::entries();

		if ( ! $entries ) {
			?>
			<p class="hlmd-empty">
				<?php echo self::icon( 'calendar-clock', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'Nothing yet. Updates to published posts and pages will show up here.', 'honest-last-modified-date' ); ?></span>
			</p>
			<?php
			return;
		}

		$ids = array_unique( array_map( 'intval', wp_list_pluck( $entries, 'post' ) ) );

		if ( $ids ) {
			_prime_post_caches( $ids, false, false );
		}
		?>
		<table class="widefat striped hlmd-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Content', 'honest-last-modified-date' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'honest-last-modified-date' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Words changed', 'honest-last-modified-date' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'honest-last-modified-date' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php $this->render_entry( $entry ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="hlmd-table-foot">
			<a class="button" href="<?php echo esc_url( $this->clear_url() ); ?>" data-hlmd-clear>
				<?php echo self::icon( 'trash-2', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php esc_html_e( 'Clear activity', 'honest-last-modified-date' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * One row of the activity table.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function render_entry( array $entry ) {
		$post    = get_post( (int) $entry['post'] );
		$user    = ! empty( $entry['user'] ) ? get_userdata( (int) $entry['user'] ) : false;
		$type    = $post ? get_post_type_object( $post->post_type ) : null;
		$changed = isset( $entry['changed'] ) ? (int) $entry['changed'] : 0;
		$needed  = isset( $entry['needed'] ) ? (int) $entry['needed'] : 0;
		$exact   = ! empty( $entry['exact'] );
		$moved   = 'updated' === $entry['result'];

		if ( 'choice' === $entry['by'] ) {
			$pill = $moved ? __( 'Moved by editor', 'honest-last-modified-date' ) : __( 'Kept by editor', 'honest-last-modified-date' );
		} elseif ( 'long' === $entry['by'] ) {
			$pill = __( 'Too long to compare', 'honest-last-modified-date' );
		} else {
			$pill = $moved ? __( 'Moved to today', 'honest-last-modified-date' ) : __( 'Kept', 'honest-last-modified-date' );
		}
		?>
		<tr>
			<td>
				<?php if ( $post && current_user_can( 'edit_post', $post->ID ) ) : ?>
					<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><strong><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'honest-last-modified-date' ) ); ?></strong></a>
				<?php elseif ( $post ) : ?>
					<strong><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(no title)', 'honest-last-modified-date' ) ); ?></strong>
				<?php else : ?>
					<span class="hlmd-muted"><?php esc_html_e( '(deleted)', 'honest-last-modified-date' ); ?></span>
				<?php endif; ?>
				<?php if ( $type ) : ?>
					<span class="hlmd-muted hlmd-sub"><?php echo esc_html( $type->labels->singular_name ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php
				/* translators: %s: time ago, e.g. "5 mins". */
				echo esc_html( sprintf( __( '%s ago', 'honest-last-modified-date' ), human_time_diff( (int) $entry['time'], time() ) ) );
				?>
				<span class="hlmd-muted hlmd-sub"><?php echo esc_html( $user ? $user->display_name : __( 'Automated update', 'honest-last-modified-date' ) ); ?></span>
			</td>
			<td>
				<?php if ( 'long' === $entry['by'] ) : ?>
					<span class="hlmd-muted">&mdash;</span>
				<?php else : ?>
					<?php
					echo esc_html( ( $exact ? '' : ( $changed >= $needed ? '' : '~' ) ) . number_format_i18n( $changed ) . ( ! $exact && $changed >= $needed ? '+' : '' ) );
					?>
					<span class="hlmd-muted">
						<?php
						/* translators: 1: percentage, 2: words needed. */
						echo esc_html( sprintf( __( '%1$s%% · needs %2$s', 'honest-last-modified-date' ), number_format_i18n( isset( $entry['percent'] ) ? (int) $entry['percent'] : 0 ), number_format_i18n( $needed ) ) );
						?>
					</span>
					<span class="hlmd-bar<?php echo $changed >= $needed ? ' is-met' : ''; ?>" aria-hidden="true"><span style="width:<?php echo esc_attr( (string) min( 100, (int) floor( 100 * $changed / max( 1, $needed ) ) ) ); ?>%"></span></span>
				<?php endif; ?>
			</td>
			<td><span class="hlmd-pill <?php echo $moved ? 'is-moved' : 'is-kept'; ?>"><?php echo self::icon( $moved ? 'calendar-plus' : 'calendar-check', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php echo esc_html( $pill ); ?></span></td>
		</tr>
		<?php
	}

	/**
	 * Link that clears the activity.
	 *
	 * @return string
	 */
	private function clear_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CLEAR_ACTION ), self::CLEAR_ACTION );
	}

	/**
	 * Clear the activity list and totals, then go back to the Activity tab.
	 *
	 * @return void
	 */
	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'honest-last-modified-date' ), 403 );
		}

		check_admin_referer( self::CLEAR_ACTION );
		Honest_Last_Modified_Date_Log::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&hlmd-cleared=1#activity' ) );
		exit;
	}

	/**
	 * Options: the editor panel.
	 *
	 * @return void
	 */
	public function render_editor() {
		$this->checkbox(
			'editor_panel',
			__( 'Show the "Last modified date" panel while editing', 'honest-last-modified-date' ),
			__( 'It updates as you type: the date readers see, the words changed so far against the rule, and whether updating now would move the date.', 'honest-last-modified-date' )
		);
		$this->checkbox(
			'allow_choice',
			__( 'Let editors decide for a single update', 'honest-last-modified-date' ),
			__( 'Adds "Set today\'s date" (for a short but important fix, like a new price) and "Keep this date" (for reformatting) to the panel. Anyone who can edit the post can use them.', 'honest-last-modified-date' ),
			'editor_panel'
		);
	}

	/**
	 * Options: what counts.
	 *
	 * @return void
	 */
	public function render_counts() {
		$this->checkbox(
			'count_title',
			__( 'Count changes to the title', 'honest-last-modified-date' ),
			__( 'The title\'s words are measured along with the content\'s.', 'honest-last-modified-date' )
		);

		$counts  = array(
			__( 'Words added, removed or rewritten', 'honest-last-modified-date' ),
			__( 'Headings, lists, tables, quotes and captions', 'honest-last-modified-date' ),
			__( 'Text inside shortcodes', 'honest-last-modified-date' ),
			__( 'Numbers, like prices and years', 'honest-last-modified-date' ),
		);
		$ignored = array(
			__( 'Formatting, link addresses, classes and block settings', 'honest-last-modified-date' ),
			__( 'Images, embeds and HTML attributes', 'honest-last-modified-date' ),
			__( 'Categories, tags, custom fields and SEO fields', 'honest-last-modified-date' ),
			__( 'Spacing, punctuation and capital letters', 'honest-last-modified-date' ),
		);
		?>
		<div class="hlmd-columns">
			<div>
				<p class="hlmd-field-label"><?php esc_html_e( 'Counts', 'honest-last-modified-date' ); ?></p>
				<ul class="hlmd-checklist">
					<?php foreach ( $counts as $item ) : ?>
						<li class="is-ok"><?php echo self::icon( 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div>
				<p class="hlmd-field-label"><?php esc_html_e( 'Never moves the date', 'honest-last-modified-date' ); ?></p>
				<ul class="hlmd-checklist">
					<?php foreach ( $ignored as $item ) : ?>
						<li class="is-off"><?php echo self::icon( 'circle-x', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Options: the activity log.
	 *
	 * @return void
	 */
	public function render_logging() {
		$this->checkbox(
			'keep_log',
			__( 'Keep a list of recent updates', 'honest-last-modified-date' ),
			sprintf(
				/* translators: %d: number of updates kept. */
				__( 'The latest %d updates to published content, shown on the Activity tab with running totals. Stored in one option; no extra tables.', 'honest-last-modified-date' ),
				Honest_Last_Modified_Date_Log::MAX
			)
		);
	}

	/**
	 * The sidebar: save, status, how it works, updates.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$status = array();

		foreach ( $this->plugin->available_types() as $type => $object ) {
			$row = $this->plugin->type_settings( $type );

			if ( ! $row['on'] && ! in_array( $type, array( 'post', 'page' ), true ) ) {
				continue;
			}

			$status[] = array(
				(bool) $row['on'],
				$row['on']
					/* translators: 1: content type, plural, 2: the rule, e.g. "300 words or 20% of the post". */
					? sprintf( __( '%1$s: %2$s', 'honest-last-modified-date' ), $object->labels->name, Honest_Last_Modified_Date_Plugin::rule_text( $row, $type ) )
					/* translators: %s: content type, plural. */
					: sprintf( __( '%s: every update sets today\'s date', 'honest-last-modified-date' ), $object->labels->name ),
			);
		}

		$panel    = (bool) $this->plugin->get_setting( 'editor_panel' );
		$status[] = array( $panel, $panel ? __( 'Editor panel on', 'honest-last-modified-date' ) : __( 'Editor panel off', 'honest-last-modified-date' ) );
		$status[] = array(
			$panel && $this->plugin->get_setting( 'allow_choice' ),
			$panel && $this->plugin->get_setting( 'allow_choice' ) ? __( 'Editors can decide per update', 'honest-last-modified-date' ) : __( 'Every update follows the rule', 'honest-last-modified-date' ),
		);

		$steps = array(
			array( 'file-pen-line', __( 'Someone updates a published post or page.', 'honest-last-modified-date' ) ),
			array( 'file-diff', __( 'Its words are compared with the version readers see dated. Small edits add up.', 'honest-last-modified-date' ) ),
			array( 'calendar-check', __( 'The date moves to today only once enough has changed. Otherwise it stays.', 'honest-last-modified-date' ) ),
		);
		?>
		<div class="postbox hlmd-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'honest-last-modified-date' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Settings on every tab are saved together. Dates already set aren\'t changed.', 'honest-last-modified-date' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'honest-last-modified-date' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'honest-last-modified-date' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="hlmd-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'lightbulb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'How it works', 'honest-last-modified-date' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ol class="hlmd-steps">
					<?php foreach ( $steps as $step ) : ?>
						<li><span class="hlmd-step-icon"><?php echo self::icon( $step[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span><span><?php echo esc_html( $step[1] ); ?></span></li>
					<?php endforeach; ?>
				</ol>
				<p class="hlmd-muted"><?php esc_html_e( 'Themes, sitemaps and SEO plugins all read the same date, so search engines see the honest one too.', 'honest-last-modified-date' ); ?></p>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( HONEST_LAST_MODIFIED_DATE_REPO ) );
		$check_url  = Honest_Last_Modified_Date_Updater::check_now_url();
		?>
		<div class="postbox hlmd-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'honest-last-modified-date' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'honest-last-modified-date' ), '<strong>' . esc_html( HONEST_LAST_MODIFIED_DATE_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Honest_Last_Modified_Date_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'honest-last-modified-date' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( HONEST_LAST_MODIFIED_DATE_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Honest_Last_Modified_Date_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'honest-last-modified-date' ),
							'<a href="' . esc_url( HONEST_LAST_MODIFIED_DATE_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>HONEST_LAST_MODIFIED_DATE_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'honest-last-modified-date' ),
							'<a href="' . esc_url( HONEST_LAST_MODIFIED_DATE_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'honest-last-modified-date' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

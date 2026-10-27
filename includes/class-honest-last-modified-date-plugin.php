<?php
/**
 * Main plugin orchestrator: owns the settings and the rules, and boots the
 * save guard, the editor panels and the admin side.
 *
 * @package Honest_Last_Modified_Date
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Honest_Last_Modified_Date_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'honest_last_modified_date_settings';

	/**
	 * Rules a content type can use, in the order the settings page shows them.
	 */
	const RULES = array( 'words', 'percent', 'either', 'both' );

	/**
	 * Highest word count a rule can ask for.
	 */
	const MAX_WORDS = 100000;

	/**
	 * Single shared instance.
	 *
	 * @var Honest_Last_Modified_Date_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings cache for this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * The save guard.
	 *
	 * @var Honest_Last_Modified_Date_Saves
	 */
	private $saves;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Honest_Last_Modified_Date_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		add_action( 'update_option_' . self::OPTION, array( $this, 'forget_settings' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'forget_settings' ) );

		// Saves happen everywhere (editor, REST, Quick Edit, WP-CLI, cron), so the guard always runs.
		$this->saves = new Honest_Last_Modified_Date_Saves( $this );
		new Honest_Last_Modified_Date_Editor( $this );

		if ( is_admin() ) {
			new Honest_Last_Modified_Date_Admin( $this );
		}
	}

	/**
	 * The save guard, which also measures unsaved versions for the editor.
	 *
	 * @return Honest_Last_Modified_Date_Saves
	 */
	public function saves() {
		return $this->saves;
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'types'        => array(
				'post' => array_merge( self::type_defaults(), array( 'on' => 1 ) ),
				'page' => array_merge( self::type_defaults(), array( 'on' => 1 ) ),
			),
			'count_title'  => 1,
			'editor_panel' => 1,
			'allow_choice' => 1,
			'keep_log'     => 1,
		);
	}

	/**
	 * Defaults for one content type. Types other than posts and pages start off.
	 *
	 * @return array<string,mixed>
	 */
	public static function type_defaults() {
		return array(
			'on'      => 0,
			'rule'    => 'either',
			'words'   => 300,
			'percent' => 20,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$settings = wp_parse_args( $saved, $defaults );
		$types    = isset( $saved['types'] ) && is_array( $saved['types'] ) ? $saved['types'] : array();

		$settings['types'] = array();

		foreach ( $types + $defaults['types'] as $type => $row ) {
			$base                       = isset( $defaults['types'][ $type ] ) ? $defaults['types'][ $type ] : self::type_defaults();
			$settings['types'][ $type ] = wp_parse_args( is_array( $row ) ? $row : array(), $base );
		}

		$this->settings = $settings;

		return $settings;
	}

	/**
	 * A single top-level setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Drop the cached settings after they change.
	 *
	 * @return void
	 */
	public function forget_settings() {
		$this->settings = null;
	}

	/**
	 * Content types the plugin can look after: posts, pages and every other
	 * public type with an editing screen (products, case studies...).
	 *
	 * @return array<string,WP_Post_Type> Keyed by type name, posts and pages first.
	 */
	public function available_types() {
		$types = array();

		foreach ( array( 'post', 'page' ) as $name ) {
			$object = get_post_type_object( $name );
			if ( $object ) {
				$types[ $name ] = $object;
			}
		}

		$public = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		foreach ( $public as $name => $object ) {
			if ( 'attachment' !== $name && ! isset( $types[ $name ] ) ) {
				$types[ $name ] = $object;
			}
		}

		/**
		 * Content types listed on the settings page and looked after when switched on.
		 *
		 * @param array<string,WP_Post_Type> $types Keyed by type name.
		 */
		$types = apply_filters( 'honest_last_modified_date_post_types', $types );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Settings for one content type.
	 *
	 * @param string $type Post type name.
	 * @return array{on:int,rule:string,words:int,percent:int}
	 */
	public function type_settings( $type ) {
		$settings = $this->get_settings();
		$row      = isset( $settings['types'][ $type ] ) ? $settings['types'][ $type ] : self::type_defaults();

		return array(
			'on'      => empty( $row['on'] ) ? 0 : 1,
			'rule'    => in_array( $row['rule'], self::RULES, true ) ? $row['rule'] : 'either',
			'words'   => max( 1, min( self::MAX_WORDS, (int) $row['words'] ) ),
			'percent' => max( 1, min( 100, (int) $row['percent'] ) ),
		);
	}

	/**
	 * Whether dates of this content type are looked after.
	 *
	 * @param string $type Post type name.
	 * @return bool
	 */
	public function is_type_on( $type ) {
		if ( ! is_string( $type ) || '' === $type || 'revision' === $type || 'attachment' === $type ) {
			return false;
		}

		$row = $this->type_settings( $type );

		return $row['on'] && isset( $this->available_types()[ $type ] );
	}

	/**
	 * Words that have to change before the date moves.
	 *
	 * @param array $row       Type settings from {@see type_settings()}.
	 * @param int   $old_words Words in the version from the date shown.
	 * @return int At least 1.
	 */
	public static function needed( array $row, $old_words ) {
		$by_words   = (int) $row['words'];
		$by_percent = max( 1, (int) ceil( $row['percent'] * max( 0, (int) $old_words ) / 100 ) );

		switch ( $row['rule'] ) {
			case 'words':
				return max( 1, $by_words );
			case 'percent':
				return $by_percent;
			case 'both':
				return max( $by_words, $by_percent );
		}

		return max( 1, min( $by_words, $by_percent ) );
	}

	/**
	 * The rule in a few words, e.g. "300 words or 20% of the post".
	 *
	 * @param array  $row  Type settings from {@see type_settings()}.
	 * @param string $type Post type name, for "of the page".
	 * @return string
	 */
	public static function rule_text( array $row, $type = 'post' ) {
		$words = sprintf(
			/* translators: %s: number of words. */
			_n( '%s word', '%s words', (int) $row['words'], 'honest-last-modified-date' ),
			number_format_i18n( (int) $row['words'] )
		);
		$percent = 'page' === $type
			/* translators: %s: percentage. */
			? sprintf( __( '%s%% of the page', 'honest-last-modified-date' ), number_format_i18n( (int) $row['percent'] ) )
			/* translators: %s: percentage. */
			: sprintf( __( '%s%% of the post', 'honest-last-modified-date' ), number_format_i18n( (int) $row['percent'] ) );

		switch ( $row['rule'] ) {
			case 'words':
				return $words;
			case 'percent':
				return $percent;
			case 'both':
				/* translators: 1: e.g. "300 words", 2: e.g. "20% of the post". */
				return sprintf( __( '%1$s and %2$s', 'honest-last-modified-date' ), $words, $percent );
		}

		/* translators: 1: e.g. "300 words", 2: e.g. "20% of the post". */
		return sprintf( __( '%1$s or %2$s', 'honest-last-modified-date' ), $words, $percent );
	}
}

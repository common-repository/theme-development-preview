<?php
/*
 * Plugin Name: Theme Development Preview
 * Version: 1.2
 * Description: Show and configure a preview theme for selected users
 * Author: Roland Barker, xnau webdesign
 * Textdomain: theme-dev-preview
 */
/**
 * @props this plugin was largely based on this tutorial: http://shibashake.com/wordpress-theme/switch_theme-vs-theme-switching
 * 
 * 
 * TODO:
 * customizer: theme preview doesn't work well with the theme customizer because settings aren't saved unless the theme is pulbished.
 * Look into adding a "save without publishing" button to the customizer or some other way to make it compatible.
 */
if ( !defined( 'ABSPATH' ) ) {
  exit;
}

class xnau_theme_dev_preview {

  /**
   * @var string name of the public theme
   */
  private $public_theme;

  /**
   * @var string name of the preview theme
   * 
   * this will be the name of the child theme if the preveiwing theme is a child theme
   */
  private $preview_theme;

  /**
   * @var string name of the previwing parent theme
   */
  private $preview_parent_theme;

  /**
   * @var array of previewing users
   */
  private $previewing_users;

  /**
   * @var string name of the option
   */
  const option_name = 'theme-dev-preview';

  /**
   * @var string plugin name
   */
  public $plugin_name;

  /**
   * 
   */
  public function __construct()
  {
    $this->plugin_name = __( 'Theme Development Preview', 'theme-dev-preview' );

    add_action( 'setup_theme', array( $this, 'init' ) );
    add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    add_action( 'admin_init', array( $this, 'settings_init' ) );
    add_action( 'admin_bar_menu', array( $this, 'toolbar_message' ), 999 );
    add_action( 'switch_theme', array( $this, 'switch_theme' ), 10, 3 );
  }

  /**
   * initialize the plugin
   */
  public function init()
  {
    $options = get_option( self::option_name );
    $this->public_theme = get_option( 'stylesheet' );
    $this->previewing_users = $options['enabled_users'];
    $this->setup_parent_child_themes( $options['preview_theme'] );

    if ( $this->is_previewing_theme() ) {
      add_filter( 'template', array( $this, 'preview_theme' ) );
      // Prevent theme mods to current theme being used on theme being previewed
      add_filter( 'pre_option_mods_' . $this->public_theme, array( $this, 'pre_option_mods' ) );
      add_filter( 'pre_option_current_theme', array( $this, 'preview_theme' ) );

      add_filter( 'stylesheet', array( $this, 'preview_stylesheet' ) );
      add_filter( 'pre_option_stylesheet', array( $this, 'preview_stylesheet' ) );

      // handle saving the sidebar settings separately from the main theme
      add_filter( 'sidebars_widgets', array( $this, 'get_sidebar_settings' ) );
      add_filter( 'pre_update_option_sidebars_widgets', array( $this, 'update_option' ), 10, 3 );
    }
  }

  /**
   * sets up the parent/child theme values
   * 
   * if the prevewing theme is not a child theme, the preview_theme and preview_child_theme 
   * properties will be the same
   * 
   * @param string $preview_theme name of the selected preview theme
   * 
   */
  private function setup_parent_child_themes( $preview_theme )
  {
    $this->preview_parent_theme = $this->preview_theme = $preview_theme;
    $theme = $this->current_preview_theme();
    if ( $theme->parent() !== false ) {
      $this->preview_parent_theme = $theme->parent()->Name;
    }
  }

  /**
   * supplies the preview theme name
   * 
   * this returns the child theme property because this will always be the previewed 
   * theme as opposed to being the parent theme
   * 
   * @param string $theme
   * @return string
   */
  public function preview_theme()
  {
    return $this->preview_parent_theme;
  }

  /**
   * supplies the preview theme stylesheet name
   * 
   * @param string $theme
   * @return string
   */
  public function preview_stylesheet()
  {
    return $this->preview_theme;
  }

  /**
   * tells if the current user is previewing
   * 
   * @return bool true if the current use is previewing
   */
  public function user_is_previewing()
  {
    $user = wp_get_current_user();
    return in_array( $user->ID, (array) $this->previewing_users );
  }

  /**
   * 
   * @return bool
   */
  function is_previewing_theme()
  {
    //error_log( __FUNCTION__ . ' option: ' . $this->public_theme . ' ~ ' . $this->current_preview_theme_slug() );
    return !empty( $this->preview_theme ) && $this->public_theme !== $this->preview_theme && $this->user_is_previewing();
  }

  /**
   * determines if the review theme is a child theme
   */
  private function preview_theme_is_child_theme()
  {
    return $this->parent_theme() !== false;
  }

  /**
   * supplies the parent theme object
   * 
   * @return WP_Theme
   */
  private function parent_theme()
  {
    $theme = $this->current_preview_theme();
    return $theme->parent();
  }

  /**
   * handles switching the themes
   * 
   * when a theme is getting previewed, it's sidebars_widgets settings are stored 
   * in a separate option. If the user decides to switch to that theme they have 
   * been preveiwing and configuring, we need to save that option into the general 
   * sidebars_widgets option. We also switch the old theme's sidebars_widgets option 
   * into it's own setting so that it's widget congifuration will be recovered if 
   * the user switches back to it.
   * 
   * called on the 'switch_theme' action
   * 
   * @param string    $new_name slug of the new theme
   * @param WP_Theme  $new_theme
   * @param WP_Theme  $old_theme
   */
  public function switch_theme( $new_name, $new_theme, $old_theme )
  {
    // save the current option value into a slot for the old theme
    update_option( 'sidebars_widgets_' . $old_theme->Name, get_option( 'sidebars_widgets' ) );
    update_option( 'sidebars_widgets', get_option( 'sidebars_widgets_' . $new_theme->Name ) );
  }

  /**
   * add the admin bar previwing notice
   * 
   * @param WP_Admin_Bar $wp_admin_bar
   */
  public function toolbar_message( $wp_admin_bar )
  {
    if ( $this->is_previewing_theme() ) {
      $args = array(
          'id' => self::option_name,
          'title' => '<span style="color: #D20202;background-color: yellow;padding: 4px 12px;">' . __( 'Previewing Theme:', 'theme-dev-preview' ) . ' ' . $this->current_preview_theme()->Name . '</span>',
          'meta' => array( 'class' => 'themedevpreview-message' )
      );
      $wp_admin_bar->add_node( $args );
    }
  }

  /**
   * handles saving the sidebar widgets
   * 
   * @param mixed $value the new value to save
   * @param mixed $old_value
   * @param string $option name of the option
   * 
   * @return mixed the value to save
   */
  public function update_option( $value, $old_value, $option )
  {
    if ( !$this->is_previewing_theme() ) {
      return $value;
    }
    /*
     * if we're previewing, save all widget changes to a theme-specific setting 
     * and leave the base theme setting alone
     */
    update_option( $this->theme_option_name( 'sidebars_widgets' ), $value );
    return $old_value;
  }

  /**
   * gets the theme-specific sidebar settings
   * 
   * @param array $settings the sidebar widgets setting
   * 
   * @return array
   */
  public function get_sidebar_settings( $settings )
  {
    if ( !$this->is_previewing_theme() ) {
      return $settings;
    }
    return get_option( $this->theme_option_name( 'sidebars_widgets' ), $settings );
  }

  /**
   * supplies a theme-specific option name
   * 
   * @param string $basename
   * @return string theme-specific name
   */
  private function theme_option_name( $basename )
  {
    $theme = $this->current_preview_theme();
    return $basename . '_' . $theme->get_stylesheet();
  }

  /**
   * provides the currently prevwed theme object
   * 
   * @return WP_Theme
   */
  public function current_preview_theme()
  {
    return wp_get_theme( $this->preview_theme );
  }

  /**
   * substitutes the theme option values
   * 
   * called on the 'pre_option_mods_' . $this->public_theme filter
   * 
   * @param array $values
   * @return array
   */
  public function pre_option_mods( $values )
  {
    error_log( __METHOD__ . ' values: ' . print_r( $values, 1 ) );
    return array();
  }

  /**
   * gets the "description" section of the readme.txt as a quick-and-dirty way to show somw helpful information
   * 
   * @return string the description section
   */
  private function get_description()
  {
    $readme = file_get_contents( plugin_dir_path( __FILE__ ) . '/readme.txt' );
    if ( !is_string( $readme ) ) {
      return '';
    }
    preg_match( '/== Description ==(.+?)== Installation ==/s', $readme, $matches );
    return isset( $matches[1] ) ? preg_replace( array( '/(=(.+?)=)/', '/(\*(.+?)\*)/' ), array( '<h4>$2</h4>', '<em>$2</em>' ), stripslashes( $matches[1] ) ) : '';
  }

  /**
   * adds the admin menu
   */
  public function add_admin_menu()
  {

    add_options_page( $this->plugin_name, $this->plugin_name, 'manage_options', 'theme_dev_preview', array( $this, 'options_page' ) );
  }

  function settings_init()
  {

    register_setting( 'pluginPage', self::option_name );

    add_settings_section(
            'theme_dev_preview_pluginPage_section', __( 'Preview Settings', 'theme-dev-preview' ), array( $this, 'settings_section_callback' ), 'pluginPage'
    );

    add_settings_field(
            'preview_theme', __( 'Preview Theme', 'theme-dev-preview' ), array( $this, 'preview_theme_render' ), 'pluginPage', 'theme_dev_preview_pluginPage_section'
    );

    add_settings_field(
            'enabled_users', __( 'Preview Active for the Selected Users Only', 'theme-dev-preview' ), array( $this, 'enabled_users_render' ), 'pluginPage', 'theme_dev_preview_pluginPage_section'
    );
  }

  function preview_theme_render()
  {
    $setting = 'preview_theme';
    $options = get_option( self::option_name );
    $themes = wp_get_themes();

    //echo '<pre>' . print_r($themes,1) . '</pre>';
    ?>
    <select name='<?php echo self::option_name . '[' . $setting . ']' ?>'>
      <option value='' <?php selected( $options[$setting], '' ); ?>>-- <?php _e( 'None', 'theme-dev-preview' ) ?> --</option>
      <?php foreach ($themes as $slug => $theme) : ?>
        <option value='<?php echo $slug ?>' <?php selected( $options[$setting], $slug ); ?>><?php echo ( $theme->parent() ? sprintf( '%s (%s)', $theme->Name, $theme->parent()->Name ) : $theme->Name ) ?></option>
      <?php endforeach; ?>
    </select>
    <?php
  }

  function enabled_users_render()
  {
    $setting = 'enabled_users';
    $options = get_option( self::option_name );
    $min_role = apply_filters( 'theme-dev-preview_minimum_user_role', 'author' );
    $users = get_users( 'who=' . $min_role );
    //echo '<pre>' . print_r($users,1) . '</pre>';
    ?>
    <select multiple="multiple" name='<?php echo self::option_name . '[' . $setting . ']' ?>[]'>
      <?php foreach ($users as $i => $user) : ?>
        <option value='<?php echo $user->ID ?>' <?php echo ( in_array( $user->ID, $options['enabled_users'] ) ? 'selected="selected"' : '' ); ?>><?php echo $user->data->user_nicename ?></option>
      <?php endforeach; ?>
    </select>

    <?php
  }

  function settings_section_callback()
  {
    $options = get_option( self::option_name );
    // echo '<pre>options: ' . print_r( $options, 1 ) . '</pre>';
    echo __( 'Select the theme to preview. Enable preview for the selected users.', 'theme-dev-preview' );
  }

  function options_page()
  {
    ?>
    <form action='options.php' method='post'>

      <h1><?php echo $this->plugin_name ?></h1>

      <?php
      settings_fields( 'pluginPage' );
      do_settings_sections( 'pluginPage' );
      submit_button();
      ?>

    </form>
    <?php $description = $this->get_description();
    if ( !empty( $description ) ):
      ?>
      <div style="max-width: 550px">
        <h3>Description</h3>
      <?php echo wpautop( $description ); ?>
      </div>
      <?php
    endif;
  }

}

new xnau_theme_dev_preview();

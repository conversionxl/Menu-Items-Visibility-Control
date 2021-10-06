<?php
/*
Plugin Name:    CXL - Menu Items Visibility Control
Description:    Control the display logic of individual menu items.
Author:         Hassan Derakhshandeh - refactor by CXL
Version:        0.4
Text Domain:    menu-items-visibility-control
*/

final class Menu_Items_Visibility_Control {

    protected static $instance = null;
    private $plugin_path = "";
    private $plugin_url = "";
    private $slug = "";

    private function __clone() {}

    /**
     * Constructor.
     */
    public function __construct() {

        $this->plugin_path = plugin_dir_path( __FILE__ );
        $this->plugin_url = plugins_url( "/", __FILE__ );
        $this->slug = basename( $this->plugin_path );

        self::register_eval_safe_tokens();

        if ( ! is_admin() ) {
            add_filter( 'wp_get_nav_menu_items', [ __CLASS__, 'visibility_check' ], 10, 3 );
            return;
        }

        add_action( 'wp_nav_menu_item_custom_fields', [ __CLASS__, 'wp_nav_menu_item_custom_fields' ] );
        add_action( 'wp_update_nav_menu_item', [ __CLASS__, 'update_option' ], 10, 3 );
        add_action( 'delete_post', [ __CLASS__, 'remove_visibility_meta' ], 1, 3 );

    }

    /**
     * Display condition field in menu admin.
     *
     * @param int $item_id
     * @return void
     * @since 0.3.8
     */
    public static function wp_nav_menu_item_custom_fields( int $item_id ): void {

        $value = get_post_meta( $item_id, '_menu_item_visibility', true );

        ?>
        <p class="field-visibility description description-wide">
            <label for="edit-menu-item-visibility-<?php echo $item_id; ?>">
                <?php printf( __( 'Visibility logic (<a href="%s">?</a>)', 'menu-items-visibility-control' ), 'https://codex.wordpress.org/Conditional_Tags' ); ?><br>
                <input type="text" class="widefat code" id="edit-menu-item-visibility-<?php echo $item_id; ?>" name="menu-item-visibility[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $value ); ?>" />
            </label>
        </p>
        <?php

    }

    /**
     * Update menu item visibility post meta.
     *
     * @param int $menu_id
     * @param int $menu_item_db_id
     * @param array $args
     * @return void
     * @since 0.3.8
     */
    public static function update_option( int $menu_id, int $menu_item_db_id, array $args ): void {

        $menu_item_visibility = filter_input( INPUT_POST, 'menu-item-visibility', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );

        if ( ! $menu_item_visibility || ! self::eval_security_check( $menu_item_visibility ) ) {
            return;
        }

        $meta_value     = get_post_meta( $menu_item_db_id, '_menu_item_visibility', true );
        $new_meta_value = stripcslashes( $menu_item_visibility[ $menu_item_db_id ] );

        if ( ! $new_meta_value ) {
            delete_post_meta( $menu_item_db_id, '_menu_item_visibility', $meta_value );
        }

        if ( $meta_value !== $new_meta_value ) {
            update_post_meta( $menu_item_db_id, '_menu_item_visibility', $new_meta_value );
        }

    }

    /**
     * Checks the menu items for their visibility options and
     * removes menu items that are not visible.
     *
     * @param array $items
     * @param object $menu
     * @param array $args
     * @return array
     * @since 0.1
     */
    public static function visibility_check( array $items, object $menu, array $args ): array {

        $hidden_items = [];

        foreach ( $items as $key => $item ) {

            $item_parent = get_post_meta( $item->ID, '_menu_item_menu_item_parent', true );
            $visible     = true;
            $logic       = get_post_meta( $item->ID, '_menu_item_visibility', true );

            if ( $logic ) {

                set_error_handler( 'self::error_handler' );

                try {
                    eval( '$visible = ' . $logic . ';' );
                } catch ( Error $e ) {
                    trigger_error( $e->getMessage(), E_USER_WARNING );
                }

                restore_error_handler();

            }

            if ( ! $visible || isset( $hidden_items[ $item_parent ] ) ) { // also hide the children of invisible items
                unset( $items[ $key ] );
                $hidden_items[ $item->ID ] = '1';
            }
        }

        return $items;

    }

    /**
     * Remove the _menu_item_visibility meta when the menu item is removed
     *
     * @param int $post_id
     * @return void
     * @since 0.2.2
     */
    public static function remove_visibility_meta( int $post_id ): void {
        if ( is_nav_menu_item( $post_id ) ) {
            delete_post_meta( $post_id, '_menu_item_visibility' );
        }
    }

    /**
     * Handle errors in eval-ed Logic field
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errFile
     * @param int $errLine
     * @param array $errContext
     * @return void
     * @since 0.4
     */
    public static function error_handler( int $errno, string $errstr, string $errFile, int $errLine, array $errContext ): bool {

        if ( current_user_can( 'manage_options' ) ) {

            if ( ! empty( $errContext['item']->title ) ) {
                echo sprintf( __( 'Error in "%s" menu item Visibility: ', 'menu-items-visibility-control' ), $errContext['item']->title );
            }

            echo $errstr;

        }

        /* Don't execute PHP internal error handler */
        return true;

    }

    /**
     * Check if methods run through eval() are safe.
     *
     * @param array $menu_item_visibility
     * @return bool
     * @since 2021.07.21
     */
    public static function eval_security_check( array $menu_item_visibility ): bool {

        $tokens = implode( ',', $menu_item_visibility );
        $tokens = '<?php ' . $tokens . ' ?>';
        $tokens = token_get_all( $tokens );

        $cxl_eval_safe_tokens = get_option( 'cxl_eval_safe_tokens' );

        foreach ( $tokens as $i => $token ) {

            // Identify method via '(' symbol.
            if ( ! is_array( $token ) && '(' === $token ) {

                // Previous token was a method.
                if ( ! in_array( $tokens[$i-1][1], $cxl_eval_safe_tokens ) ) {
                    return false;
                }

            }

        }

        return true;

    }



    /**
     * Register eval() safe tokens.
     *
     * @return void
     * @since 2021.07.21
     */
    public static function register_eval_safe_tokens(): void {

        $cxl_eval_safe_tokens = [
            'count',
            'empty',
            'is_page',
            'get_current_user_id',
            'wc_memberships_get_user_active_memberships',
            'wc_memberships_is_user_active_member',
        ];

        update_option( 'cxl_eval_safe_tokens', $cxl_eval_safe_tokens );

    }

    public static function get_instance() {

        null === self::$instance and self::$instance = new self;

        return self::$instance;

    }

}

add_action( 'plugins_loaded', [ new Menu_Items_Visibility_Control, 'get_instance' ] );
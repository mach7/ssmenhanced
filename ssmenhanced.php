<?php
/*
Plugin Name: Subscription Service Manager Enhanced
Description: Enhanced plugin with product management, subscription management, Stripe webhook integration, API key management, error logging, and instructions.
Version: 1.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSM_Plugin {

    const PRODUCT_TABLE         = 'ssm_products';
    const CATEGORY_TABLE        = 'ssm_product_categories';
    const PRODUCT_CAT_REL_TABLE = 'ssm_product_category';

    public function __construct() {
        // Activation hook
        register_activation_hook( __FILE__, [ $this, 'activate' ] );

        // Cron job scheduling for renewal reminders
        add_action( 'ssm_daily_cron', [ $this, 'send_renewal_reminders' ] );
        if ( ! wp_next_scheduled( 'ssm_daily_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'ssm_daily_cron' );
        }

        // Check for Stripe webhook calls (via query parameter)
        add_action( 'init', [ $this, 'maybe_handle_stripe_webhook' ] );

        // Register shortcodes
        add_shortcode( 'ssm_add_to_cart', [ $this, 'ssm_add_to_cart_shortcode' ] );
        add_shortcode( 'ssm_subscription_account', [ $this, 'ssm_subscription_account_shortcode' ] );

        // Add admin menus
        add_action( 'admin_menu', [ $this, 'register_admin_menus' ] );
    }

    /**
     * Plugin activation: create necessary tables.
     */
    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // Products table
        $table_products = $wpdb->prefix . self::PRODUCT_TABLE;
        $sql1 = "CREATE TABLE $table_products (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            price decimal(10,2) NOT NULL,
            digital tinyint(1) NOT NULL DEFAULT 0,
            subscription tinyint(1) NOT NULL DEFAULT 0,
            subscription_interval varchar(20) DEFAULT 'monthly',
            subscription_price decimal(10,2) DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta( $sql1 );

        // Categories table
        $table_categories = $wpdb->prefix . self::CATEGORY_TABLE;
        $sql2 = "CREATE TABLE $table_categories (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta( $sql2 );

        // Relationship table for products and categories (many-to-many)
        $table_rel = $wpdb->prefix . self::PRODUCT_CAT_REL_TABLE;
        $sql3 = "CREATE TABLE $table_rel (
            product_id mediumint(9) NOT NULL,
            category_id mediumint(9) NOT NULL,
            PRIMARY KEY (product_id, category_id)
        ) $charset_collate;";
        dbDelta( $sql3 );
    }

    /**
     * Check if this is a Stripe webhook call.
     */
    public function maybe_handle_stripe_webhook() {
        if ( isset( $_GET['ssm_webhook'] ) && $_GET['ssm_webhook'] === 'stripe' ) {
            $this->handle_stripe_webhook();
        }
    }

    /**
     * Handle incoming Stripe webhook events.
     */
    public function handle_stripe_webhook() {
        // Read payload and decode JSON
        $payload = @file_get_contents( 'php://input' );
        $event   = json_decode( $payload, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            http_response_code(400);
            exit();
        }

        // Example: handle checkout.session.completed for successful subscription
        if ( isset( $event['type'] ) ) {
            switch ( $event['type'] ) {
                case 'checkout.session.completed':
                    $session = $event['data']['object'];
                    // Assume metadata includes user_id and product_id
                    $user_id    = isset( $session['metadata']['user_id'] ) ? intval( $session['metadata']['user_id'] ) : 0;
                    $product_id = isset( $session['metadata']['product_id'] ) ? intval( $session['metadata']['product_id'] ) : 0;
                    if ( $user_id && $product_id ) {
                        $this->process_successful_subscription( $user_id, $product_id );
                    }
                    break;

                case 'customer.subscription.deleted':
                case 'invoice.payment_failed':
                    // On cancellation/failure, expire the API key.
                    $user_id = isset( $event['data']['object']['metadata']['user_id'] ) ? intval( $event['data']['object']['metadata']['user_id'] ) : 0;
                    if ( $user_id ) {
                        $this->expire_api_key( $user_id );
                    }
                    break;

                default:
                    // Log unhandled event
                    error_log( '[SSM] Unhandled Stripe event: ' . $event['type'] );
                    break;
            }
        }
        http_response_code(200);
        exit();
    }

    /**
     * Process a successful subscription event.
     * Create a new API key or update the expiration date.
     */
    public function process_successful_subscription( $user_id, $product_id ) {
        // Retrieve product details to get subscription length
        global $wpdb;
        $table_products = $wpdb->prefix . self::PRODUCT_TABLE;
        $product = $wpdb->get_row( $wpdb->prepare( "SELECT subscription_interval FROM $table_products WHERE id = %d", $product_id ) );

        if ( ! $product ) {
            error_log( '[SSM] Product not found for subscription.' );
            return;
        }

        // Calculate expiration based on subscription interval
        $expiration_date = current_time( 'mysql' );
        if ( $product->subscription_interval === 'yearly' ) {
            $expiration_date = date( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
        } else {
            $expiration_date = date( 'Y-m-d H:i:s', strtotime( '+1 month' ) );
        }

        // Check if user already has an API key (stored as user meta)
        $existing_key = get_user_meta( $user_id, 'ssm_api_key', true );
        if ( ! $existing_key ) {
            // Call API endpoint to create new API key.
            $response = wp_remote_post( home_url( '/wp-json/akm/v1/key' ), [
                'body' => [
                    'email'    => get_userdata( $user_id )->user_email,
                    'api_key'  => wp_generate_password( 32, false ),
                    'valid_to' => $expiration_date,
                ],
            ] );
            if ( is_wp_error( $response ) ) {
                error_log( '[SSM] API key creation failed: ' . $response->get_error_message() );
            } else {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $data['api_key'] ) ) {
                    update_user_meta( $user_id, 'ssm_api_key', $data['api_key'] );
                    update_user_meta( $user_id, 'ssm_api_key_expiry', $expiration_date );
                }
            }
        } else {
            // API key exists; update the expiration date.
            $response = wp_remote_post( home_url( '/wp-json/akm/v1/key/' . $user_id ), [
                'method' => 'PUT',
                'body'   => [
                    'email'    => get_userdata( $user_id )->user_email,
                    'api_key'  => $existing_key,
                    'valid_to' => $expiration_date,
                ],
            ] );
            if ( is_wp_error( $response ) ) {
                error_log( '[SSM] API key update failed: ' . $response->get_error_message() );
            } else {
                update_user_meta( $user_id, 'ssm_api_key_expiry', $expiration_date );
            }
        }
    }

    /**
     * Mark the API key for a user as expired.
     */
    public function expire_api_key( $user_id ) {
        $existing_key = get_user_meta( $user_id, 'ssm_api_key', true );
        if ( $existing_key ) {
            $response = wp_remote_post( home_url( '/wp-json/akm/v1/expire-key/' . $user_id ), [
                'method' => 'POST',
            ] );
            if ( is_wp_error( $response ) ) {
                error_log( '[SSM] API key expiration failed: ' . $response->get_error_message() );
            } else {
                // Optionally remove or update the API key status in user meta.
                update_user_meta( $user_id, 'ssm_api_key_expiry', current_time( 'mysql' ) );
            }
        }
    }

    /**
     * Shortcode to display an "Add to Cart" button for a given product.
     * Usage: [ssm_add_to_cart product_id="123"]
     */
    public function ssm_add_to_cart_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'product_id' => 0,
        ], $atts, 'ssm_add_to_cart' );

        $product_id = intval( $atts['product_id'] );
        if ( ! $product_id ) {
            return 'Invalid product.';
        }

        // Retrieve product details from DB
        global $wpdb;
        $table_products = $wpdb->prefix . self::PRODUCT_TABLE;
        $product = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_products WHERE id = %d", $product_id ) );

        if ( ! $product ) {
            return 'Product not found.';
        }

        // Output a button with data attributes for JS integration.
        ob_start(); ?>
        <div class="ssm-product" data-product-id="<?php echo esc_attr( $product->id ); ?>" data-price="<?php echo esc_attr( $product->price ); ?>">
            <h3><?php echo esc_html( $product->name ); ?></h3>
            <p><?php echo esc_html( $product->description ); ?></p>
            <p>Price: $<?php echo number_format( $product->price, 2 ); ?></p>
            <?php if ( $product->subscription ) : ?>
                <p>Subscription: $<?php echo number_format( $product->subscription_price, 2 ); ?> per <?php echo esc_html( $product->subscription_interval ); ?></p>
            <?php endif; ?>
            <button class="ssm-add-to-cart-btn">Add to Cart</button>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode to show customer's subscription account page.
     * This page allows customers to upgrade/downgrade their subscription.
     */
    public function ssm_subscription_account_shortcode() {
        // For simplicity, this output is a placeholder.
        ob_start();
        ?>
        <div class="ssm-subscription-account">
            <h2>Your Subscription</h2>
            <?php
            // Here you would retrieve current subscription info for the logged-in user.
            $user_id = get_current_user_id();
            $api_key = get_user_meta( $user_id, 'ssm_api_key', true );
            $expiry  = get_user_meta( $user_id, 'ssm_api_key_expiry', true );
            ?>
            <p>Your API Key: <?php echo esc_html( $api_key ); ?></p>
            <p>Expires on: <?php echo esc_html( $expiry ); ?></p>
            <form method="post" action="">
                <!-- Provide options to change subscription plan -->
                <select name="new_subscription_plan">
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
                <input type="submit" name="ssm_change_plan" value="Change Plan">
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Register all admin menus and submenus.
     */
    public function register_admin_menus() {
        add_menu_page( 'SSM Manager', 'SSM Manager', 'manage_options', 'ssm_manager', [ $this, 'render_products_page' ], 'dashicons-cart', 58 );
        add_submenu_page( 'ssm_manager', 'Products', 'Products', 'manage_options', 'ssm_products', [ $this, 'render_products_page' ] );
        add_submenu_page( 'ssm_manager', 'Categories', 'Categories', 'manage_options', 'ssm_categories', [ $this, 'render_categories_page' ] );
        add_submenu_page( 'ssm_manager', 'API Key Management', 'API Keys', 'manage_options', 'ssm_api_keys', [ $this, 'render_api_keys_page' ] );
        add_submenu_page( 'ssm_manager', 'Error Logs', 'Error Logs', 'manage_options', 'ssm_error_logs', [ $this, 'render_error_logs_page' ] );
        add_submenu_page( 'ssm_manager', 'Instructions', 'Instructions', 'manage_options', 'ssm_instructions', [ $this, 'render_instructions_page' ] );
    }

    /**
     * Render the products admin page (list, add, edit, delete).
     */
    public function render_products_page() {
        global $wpdb;
        $table_products = $wpdb->prefix . self::PRODUCT_TABLE;
        $table_rel      = $wpdb->prefix . self::PRODUCT_CAT_REL_TABLE;
        $table_cats     = $wpdb->prefix . self::CATEGORY_TABLE;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        // Process form submission for add/edit.
        if ( isset( $_POST['ssm_product_submit'] ) ) {
            $name                   = sanitize_text_field( $_POST['name'] );
            $description            = sanitize_textarea_field( $_POST['description'] );
            $price                  = floatval( $_POST['price'] );
            $digital                = isset( $_POST['digital'] ) ? 1 : 0;
            $subscription           = isset( $_POST['subscription'] ) ? 1 : 0;
            $subscription_interval  = sanitize_text_field( $_POST['subscription_interval'] );
            $subscription_price     = floatval( $_POST['subscription_price'] );
            $categories             = isset( $_POST['categories'] ) ? (array) $_POST['categories'] : array();

            if ( $action === 'add' ) {
                $wpdb->insert(
                    $table_products,
                    [
                        'name'                   => $name,
                        'description'            => $description,
                        'price'                  => $price,
                        'digital'                => $digital,
                        'subscription'           => $subscription,
                        'subscription_interval'  => $subscription_interval,
                        'subscription_price'     => $subscription_price,
                    ]
                );
                $new_product_id = $wpdb->insert_id;
                // Insert category relationships.
                foreach ( $categories as $cat_id ) {
                    $wpdb->insert(
                        $table_rel,
                        [
                            'product_id'  => $new_product_id,
                            'category_id' => intval( $cat_id ),
                        ]
                    );
                }
                echo '<div class="updated"><p>Product added successfully.</p></div>';
            } elseif ( $action === 'edit' && isset( $_GET['id'] ) ) {
                $product_id = intval( $_GET['id'] );
                $wpdb->update(
                    $table_products,
                    [
                        'name'                   => $name,
                        'description'            => $description,
                        'price'                  => $price,
                        'digital'                => $digital,
                        'subscription'           => $subscription,
                        'subscription_interval'  => $subscription_interval,
                        'subscription_price'     => $subscription_price,
                    ],
                    [ 'id' => $product_id ]
                );
                // Update categories: Clear existing then reinsert.
                $wpdb->delete( $table_rel, [ 'product_id' => $product_id ] );
                foreach ( $categories as $cat_id ) {
                    $wpdb->insert(
                        $table_rel,
                        [
                            'product_id'  => $product_id,
                            'category_id' => intval( $cat_id ),
                        ]
                    );
                }
                echo '<div class="updated"><p>Product updated successfully.</p></div>';
            }
        }

        // Check if we're in add/edit mode.
        if ( $action === 'add' || $action === 'edit' ) {
            $product    = null;
            $product_id = 0;
            if ( $action === 'edit' && isset( $_GET['id'] ) ) {
                $product_id = intval( $_GET['id'] );
                $product    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_products WHERE id = %d", $product_id ) );
                if ( ! $product ) {
                    echo '<div class="error"><p>Product not found.</p></div>';
                    return;
                }
            }

            // Retrieve assigned categories if editing.
            $assigned_categories = array();
            if ( $product ) {
                $assigned_categories = $wpdb->get_col( $wpdb->prepare( "SELECT category_id FROM $table_rel WHERE product_id = %d", $product_id ) );
            }

            // Retrieve all available categories.
            $all_categories = $wpdb->get_results( "SELECT * FROM $table_cats ORDER BY name", ARRAY_A );
            ?>
            <div class="wrap">
                <h1><?php echo ( $action === 'add' ) ? 'Add New Product' : 'Edit Product'; ?></h1>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th><label for="ssm_product_name">Product Name</label></th>
                            <td><input type="text" name="name" id="ssm_product_name" value="<?php echo $product ? esc_attr( $product->name ) : ''; ?>" required></td>
                        </tr>
                        <tr>
                            <th><label for="ssm_product_description">Description</label></th>
                            <td><textarea name="description" id="ssm_product_description" rows="5" cols="50"><?php echo $product ? esc_textarea( $product->description ) : ''; ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="ssm_product_price">Price</label></th>
                            <td><input type="number" step="0.01" name="price" id="ssm_product_price" value="<?php echo $product ? esc_attr( $product->price ) : ''; ?>" required></td>
                        </tr>
                        <tr>
                            <th><label for="ssm_product_digital">Digital Product</label></th>
                            <td><input type="checkbox" name="digital" id="ssm_product_digital" <?php checked( $product && $product->digital, 1 ); ?>></td>
                        </tr>
                        <tr>
                            <th><label for="ssm_product_subscription">Subscription Product</label></th>
                            <td><input type="checkbox" name="subscription" id="ssm_product_subscription" <?php checked( $product && $product->subscription, 1 ); ?>></td>
                        </tr>
                        <tr>
                            <th><label for="ssm_subscription_interval">Subscription Interval</label></th>
                            <td>
                                <select name="subscription_interval" id="ssm_subscription_interval">
                                    <option value="monthly" <?php selected( $product ? $product->subscription_interval : '', 'monthly' ); ?>>Monthly</option>
                                    <option value="yearly" <?php selected( $product ? $product->subscription_interval : '', 'yearly' ); ?>>Yearly</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ssm_subscription_price">Subscription Price</label></th>
                            <td><input type="number" step="0.01" name="subscription_price" id="ssm_subscription_price" value="<?php echo $product ? esc_attr( $product->subscription_price ) : ''; ?>"></td>
                        </tr>
                        <tr>
                            <th>Categories</th>
                            <td>
                                <?php if ( $all_categories ) : ?>
                                    <?php foreach ( $all_categories as $cat ) : ?>
                                        <label style="display:block;">
                                            <input type="checkbox" name="categories[]" value="<?php echo intval( $cat['id'] ); ?>" <?php if ( in_array( $cat['id'], $assigned_categories ) ) echo 'checked'; ?>>
                                            <?php echo esc_html( $cat['name'] ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <p>No categories available. Please add categories first.</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( ( $action === 'add' ) ? 'Add Product' : 'Update Product', 'primary', 'ssm_product_submit' ); ?>
                </form>
            </div>
            <?php
            return;
        }

        // If no add/edit action, display the products listing.
        $search = isset( $_GET['ssm_search'] ) ? sanitize_text_field( $_GET['ssm_search'] ) : '';
        $where  = '';
        if ( $search ) {
            $where = $wpdb->prepare( "WHERE name LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
        }
        $products = $wpdb->get_results( "SELECT * FROM $table_products $where ORDER BY id DESC" );
        ?>
        <div class="wrap">
            <h1>Products</h1>
            <form method="get">
                <input type="hidden" name="page" value="ssm_products">
                <input type="text" name="ssm_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Search Products">
                <input type="submit" value="Search">
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Price</th>
                        <th>Subscription</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $products ) : ?>
                        <?php foreach ( $products as $p ) : ?>
                            <tr>
                                <td><?php echo esc_html( $p->id ); ?></td>
                                <td><?php echo esc_html( $p->name ); ?></td>
                                <td>$<?php echo number_format( $p->price, 2 ); ?></td>
                                <td><?php echo $p->subscription ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=edit&id=' . $p->id ); ?>">Edit</a> |
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=delete&id=' . $p->id ); ?>" onclick="return confirm('Are you sure?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p><a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=add' ); ?>" class="button button-primary">Add New Product</a></p>
        </div>
        <?php
    }


    /**
     * Render the Categories admin page.
     */
    public function render_categories_page() {
        global $wpdb;
        $table_categories = $wpdb->prefix . self::CATEGORY_TABLE;
        $action = isset($_GET['action']) ? sanitize_text_field( $_GET['action'] ) : '';

        // Process form submission for add/edit.
        if ( isset( $_POST['ssm_category_submit'] ) ) {
            $cat_name = sanitize_text_field( $_POST['name'] );
            if ( $action === 'add' ) {
                $wpdb->insert(
                    $table_categories,
                    [ 'name' => $cat_name ]
                );
                echo '<div class="updated"><p>Category added successfully.</p></div>';
            } elseif ( $action === 'edit' && isset( $_GET['id'] ) ) {
                $cat_id = intval( $_GET['id'] );
                $wpdb->update(
                    $table_categories,
                    [ 'name' => $cat_name ],
                    [ 'id' => $cat_id ]
                );
                echo '<div class="updated"><p>Category updated successfully.</p></div>';
            }
        }

        // If in add/edit mode, display the form.
        if ( $action === 'add' || $action === 'edit' ) {
            $category = null;
            $cat_id = 0;
            if ( $action === 'edit' && isset( $_GET['id'] ) ) {
                $cat_id = intval( $_GET['id'] );
                $category = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_categories WHERE id = %d", $cat_id ) );
                if ( ! $category ) {
                    echo '<div class="error"><p>Category not found.</p></div>';
                    return;
                }
            }
            ?>
            <div class="wrap">
                <h1><?php echo ( $action === 'add' ) ? 'Add New Category' : 'Edit Category'; ?></h1>
                <form method="post">
                    <table class="form-table">
                        <tr>
                            <th><label for="ssm_category_name">Category Name</label></th>
                            <td>
                                <input type="text" name="name" id="ssm_category_name" value="<?php echo $category ? esc_attr( $category->name ) : ''; ?>" required>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( ( $action === 'add' ) ? 'Add Category' : 'Update Category', 'primary', 'ssm_category_submit' ); ?>
                </form>
            </div>
            <?php
            return;
        }

        // Default mode: List categories.
        $categories = $wpdb->get_results( "SELECT * FROM $table_categories ORDER BY name" );
        ?>
        <div class="wrap">
            <h1>Product Categories</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $categories ) : ?>
                        <?php foreach ( $categories as $cat ) : ?>
                            <tr>
                                <td><?php echo esc_html( $cat->id ); ?></td>
                                <td><?php echo esc_html( $cat->name ); ?></td>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_categories&action=edit&id=' . $cat->id ); ?>">Edit</a> |
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_categories&action=delete&id=' . $cat->id ); ?>" onclick="return confirm('Are you sure you want to delete this category?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="3">No categories found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p><a href="<?php echo admin_url( 'admin.php?page=ssm_categories&action=add' ); ?>" class="button button-primary">Add New Category</a></p>
        </div>
        <?php
    }


    /**
     * Render the API Key Management admin page.
     * Lists all users with an API key and offers manual controls.
     */
    public function render_api_keys_page() {
        // For simplicity, we assume API keys are stored as user meta.
        $users = get_users( [
            'meta_key' => 'ssm_api_key',
            'meta_compare' => 'EXISTS'
        ] );
        ?>
        <div class="wrap">
            <h1>API Key Management</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>API Key</th>
                        <th>Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $users ) : ?>
                        <?php foreach ( $users as $user ) : 
                            $api_key = get_user_meta( $user->ID, 'ssm_api_key', true );
                            $expiry  = get_user_meta( $user->ID, 'ssm_api_key_expiry', true );
                        ?>
                            <tr>
                                <td><?php echo esc_html( $user->display_name ); ?></td>
                                <td><?php echo esc_html( $user->user_email ); ?></td>
                                <td><?php echo esc_html( $api_key ); ?></td>
                                <td><?php echo esc_html( $expiry ); ?></td>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_api_keys&action=expire&user=' . $user->ID ); ?>">Expire</a> | 
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_api_keys&action=reissue&user=' . $user->ID ); ?>">Reissue</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5">No API keys found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the Error Logs admin page.
     * Reads the WP debug log (if exists), allows filtering by error type, and offers a clear logs option.
     */
    public function render_error_logs_page() {
        $log_file = WP_CONTENT_DIR . '/debug.log';
        $filter   = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : '';

        // Handle clear log request (if submitted)
        if ( isset( $_POST['ssm_clear_logs'] ) ) {
            if ( file_exists( $log_file ) ) {
                file_put_contents( $log_file, '' );
                echo '<div class="updated"><p>Error log cleared.</p></div>';
            }
        }

        ?>
        <div class="wrap">
            <h1>Error Logs</h1>
            <form method="get">
                <input type="hidden" name="page" value="ssm_error_logs">
                <input type="text" name="filter" value="<?php echo esc_attr( $filter ); ?>" placeholder="Filter by error type">
                <input type="submit" value="Filter">
            </form>
            <form method="post">
                <?php submit_button( 'Clear Logs', 'secondary', 'ssm_clear_logs' ); ?>
            </form>
            <pre style="background: #f7f7f7; padding: 10px; max-height: 500px; overflow: auto;">
<?php
if ( file_exists( $log_file ) ) {
    $logs = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( $filter ) {
        foreach ( $logs as $line ) {
            if ( strpos( $line, $filter ) !== false ) {
                echo esc_html( $line ) . "\n";
            }
        }
    } else {
        echo esc_html( implode( "\n", $logs ) );
    }
} else {
    echo 'No debug log file found.';
}
?>
            </pre>
        </div>
        <?php
    }

    /**
     * Render the Instructions page.
     */
    public function render_instructions_page() {
        ?>
        <div class="wrap">
            <h1>Plugin Instructions</h1>
            <h2>Product Management</h2>
            <p>Use the Products and Categories pages to add, edit, delete, and filter products and categories. Products include attributes such as name, description, price, digital flag, subscription flag, subscription interval, and subscription price.</p>
            <h2>Shortcode Integration</h2>
            <p>Embed products in your posts/pages using the <code>[ssm_add_to_cart product_id="123"]</code> shortcode.</p>
            <h2>Stripe Webhook & API Key Management</h2>
            <p>Stripe events are handled on-site. On a successful subscription, the plugin automatically creates or updates an API key. Subscription cancellations and payment failures trigger API key expiration. Manual controls are available in the API Key Management page.</p>
            <h2>Subscription Management</h2>
            <p>Customers can upgrade or downgrade their subscriptions from their account page (<code>[ssm_subscription_account]</code> shortcode). Renewal reminder emails are sent 7 days, 3 days, and on the day of renewal.</p>
            <h2>Error Logging</h2>
            <p>Error logs from WP debug log are displayed on the Error Logs page. You can filter logs by error type and clear the logs if needed.</p>
        </div>
        <?php
    }

    /**
     * Daily cron task: Send renewal reminder emails.
     */
    public function send_renewal_reminders() {
        // Loop through all users with an API key and check the expiry date.
        $users = get_users( [
            'meta_key' => 'ssm_api_key_expiry',
            'meta_compare' => 'EXISTS'
        ] );
        foreach ( $users as $user ) {
            $expiry = get_user_meta( $user->ID, 'ssm_api_key_expiry', true );
            if ( $expiry ) {
                $expiry_time = strtotime( $expiry );
                $now = time();
                $diff = $expiry_time - $now;
                $days_left = floor( $diff / (60 * 60 * 24) );
                if ( in_array( $days_left, [7, 3, 0] ) ) {
                    $subject = 'Your subscription is renewing soon';
                    $message = "Hello " . $user->display_name . ",\n\nYour subscription is set to renew in {$days_left} day(s). Please review your plan details in your account.\n\nThanks,\nSubscription Service Manager Team";
                    wp_mail( $user->user_email, $subject, $message );
                }
            }
        }
    }
}

new SSM_Plugin();

<?php
/*
Plugin Name: Subscription Service Manager Enhanced
Description: Enhanced plugin with product management, subscription management, Stripe webhook integration, API key management, error logging, instructions, checkout functionality, and account creation on checkout.
Version: 1.7.0
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.3
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define a global variable for the current plugin file
global $ssm_plugin_file;
$ssm_plugin_file = __FILE__;

/*===================================
=         Session Handling          =
===================================*/
function ssm_start_session() {
	if ( ! session_id() ) {
		session_start();
	}
}
add_action( 'init', 'ssm_start_session', 1 );

/*=============================================
=      Plugin Loaded and Update Checker      =
=============================================*/
function ssm_update_plugins_filter( $transient ) {
	global $ssm_plugin_file;
	if ( isset( $transient->response ) ) {
		foreach ( $transient->response as $plugin_slug => $plugin_data ) {
			if ( $plugin_slug === plugin_basename( $ssm_plugin_file ) ) {
				$icon_url = plugins_url( 'assets/logo-128x128.png', $ssm_plugin_file );
				$transient->response[ $plugin_slug ]->icons = array(
					'default' => $icon_url,
					'1x'      => $icon_url,
					'2x'      => plugins_url( 'assets/logo-256x256.png', $ssm_plugin_file ),
				);
			}
		}
	}
	return $transient;
}

function ssm_admin_notice_no_flw_library() {
	$pluginSlug = 'flwpluginlibrary/flwpluginlibrary.php';
	$plugins    = get_plugins();
	if ( ! isset( $plugins[ $pluginSlug ] ) ) {
		echo '<div class="notice notice-error"><p>The FLW Plugin Library is not installed. Please install and activate it to enable update functionality.</p></div>';
	} elseif ( ! is_plugin_active( $pluginSlug ) ) {
		$activateUrl = wp_nonce_url(
			admin_url( 'plugins.php?action=activate&plugin=' . $pluginSlug ),
			'activate-plugin_' . $pluginSlug
		);
		echo '<div class="notice notice-error"><p>The FLW Plugin Library is installed but not active. Please <a href="' . esc_url( $activateUrl ) . '">activate</a> it to enable update functionality.</p></div>';
	}
}

function ssm_admin_notice_flw_library_required() {
	echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Subscription Service Manager Enhanced to work.</p></div>';
}

function ssm_plugins_loaded() {
	// If the FLW Plugin Update Checker exists, initialize it and add our update filter.
	if ( class_exists( 'FLW_Plugin_Update_Checker' ) ) {
		$pluginSlug = basename( dirname( __FILE__ ) );
		FLW_Plugin_Update_Checker::initialize( __FILE__, $pluginSlug );
		add_filter( 'site_transient_update_plugins', 'ssm_update_plugins_filter' );
	} else {
		// Only show admin notices on non-AJAX requests.
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			add_action( 'admin_notices', 'ssm_admin_notice_no_flw_library' );
		}
	}

	if ( class_exists( 'FLW_Plugin_Library' )) {
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			// Initialize plugin settings submenu via FLW_Plugin_Library.
			// We define a class for settings.
			class SSM_Plugin_Settings {
				public function __construct() {
					add_action( 'admin_menu', array( $this, 'register_submenu' ) );
				}
				public function register_submenu() {
					FLW_Plugin_Library::add_submenu(
						'SSM Manager Settings',
						'ssm-manager',
						array( $this, 'render_settings_page' )
					);
				}
				public function render_settings_page() {
					echo '<div class="wrap"><h1>SSM Manager Settings</h1>';
					echo '<form method="post" action="options.php">';
					echo '<p>Here you can manage settings for Subscription Service Manager Enhanced.</p>';
					echo '</form></div>';
				}
			}
			new SSM_Plugin_Settings();
		}
	}
}
add_action( 'plugins_loaded', 'ssm_plugins_loaded' );

/*===================================
=         Main Plugin Class         =
===================================*/
class SSM_Plugin {

	const PRODUCT_TABLE         = 'ssm_products';
	const CATEGORY_TABLE        = 'ssm_product_categories';
	const PRODUCT_CAT_REL_TABLE = 'ssm_product_category';

	public function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		// Products table with subscription_user_role column.
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
			subscription_user_role varchar(50) DEFAULT '',
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql1 );

		// Categories table.
		$table_categories = $wpdb->prefix . self::CATEGORY_TABLE;
		$sql2 = "CREATE TABLE $table_categories (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql2 );

		// Relationship table.
		$table_rel = $wpdb->prefix . self::PRODUCT_CAT_REL_TABLE;
		$sql3 = "CREATE TABLE $table_rel (
			product_id mediumint(9) NOT NULL,
			category_id mediumint(9) NOT NULL,
			PRIMARY KEY (product_id, category_id)
		) $charset_collate;";
		dbDelta( $sql3 );
	}

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'ssm_daily_cron', array( $this, 'send_renewal_reminders' ) );
		if ( ! wp_next_scheduled( 'ssm_daily_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'ssm_daily_cron' );
		}
		add_action( 'init', array( $this, 'maybe_handle_stripe_webhook' ) );
		add_shortcode( 'ssm_add_to_cart', array( $this, 'ssm_add_to_cart_shortcode' ) );
		add_shortcode( 'ssm_subscription_account', array( $this, 'ssm_subscription_account_shortcode' ) );
		add_shortcode( 'ssm_checkout', array( $this, 'ssm_checkout_shortcode' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
		add_action( 'wp_ajax_ssm_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_ssm_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_ssm_update_cart_quantity', array( $this, 'ajax_update_cart_quantity' ) );
		add_action( 'wp_ajax_nopriv_ssm_update_cart_quantity', array( $this, 'ajax_update_cart_quantity' ) );
		add_action( 'wp_ajax_ssm_create_payment_intent', array( $this, 'ajax_create_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_ssm_create_payment_intent', array( $this, 'ajax_create_payment_intent' ) );
	}

	public function ajax_add_to_cart() {
		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product.' );
		}
		if ( ! isset( $_SESSION['ssm_cart'] ) ) {
			$_SESSION['ssm_cart'] = array();
		}
		if ( ! isset( $_SESSION['ssm_cart'][ $product_id ] ) ) {
			$_SESSION['ssm_cart'][ $product_id ] = 1;
		} else {
			$_SESSION['ssm_cart'][ $product_id ]++;
		}
		$cart_total = array_sum( $_SESSION['ssm_cart'] );
		wp_send_json_success( array( 'cart_total' => $cart_total ) );
	}

	public function ajax_update_cart_quantity() {
		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? intval( $_POST['quantity'] ) : 1;
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product.' );
		}
		$_SESSION['ssm_cart'][ $product_id ] = $quantity;
		global $wpdb;
		$table_products  = $wpdb->prefix . self::PRODUCT_TABLE;
		$product         = $wpdb->get_row( $wpdb->prepare( "SELECT price FROM $table_products WHERE id = %d", $product_id ) );
		$product_subtotal = ( $product ) ? $product->price * $quantity : 0;
		$total_price     = 0;
		foreach ( $_SESSION['ssm_cart'] as $pid => $qty ) {
			$p = $wpdb->get_row( $wpdb->prepare( "SELECT price FROM $table_products WHERE id = %d", $pid ) );
			if ( $p ) {
				$total_price += $p->price * $qty;
			}
		}
		wp_send_json_success( array(
			'product_subtotal' => $product_subtotal,
			'total_price'      => $total_price,
		) );
	}

	public function ajax_create_payment_intent() {
        // Start output buffering and immediately clear any existing output
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($amount <= 0) {
            wp_send_json_error('Invalid amount.');
        }
        
        // If user is not logged in, require name and email.
        if (!is_user_logged_in()) {
            $name  = isset($_POST['ssm_customer_name']) ? sanitize_text_field($_POST['ssm_customer_name']) : '';
            $email = isset($_POST['ssm_customer_email']) ? sanitize_email($_POST['ssm_customer_email']) : '';
            if (empty($name) || empty($email)) {
                wp_send_json_error('Name and email are required for checkout.');
            }
            if (!email_exists($email)) {
                $random_password = wp_generate_password(12, false);
                $user_id = wp_create_user($email, $random_password, $email);
                if (is_wp_error($user_id)) {
                    wp_send_json_error('User creation failed: ' . $user_id->get_error_message());
                }
                wp_update_user(['ID' => $user_id, 'display_name' => $name]);
                global $wpdb;
                $subscription_role = 'subscriber';
                foreach ($_SESSION['ssm_cart'] as $pid => $qty) {
                    $product = $wpdb->get_row($wpdb->prepare("SELECT subscription, subscription_user_role FROM {$wpdb->prefix}" . self::PRODUCT_TABLE . " WHERE id = %d", $pid));
                    if ($product && $product->subscription) {
                        if (!empty($product->subscription_user_role)) {
                            $subscription_role = $product->subscription_user_role;
                        }
                        break;
                    }
                }
                $user = new WP_User($user_id);
                $user->set_role($subscription_role);
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id);
            } else {
                $user = get_user_by('email', $email);
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
            }
        }
        
        $user_id = get_current_user_id();
        $amount_cents = intval($amount * 100);
        $secret_key = get_option('flw_stripe_secret_key', '');
        if (!$secret_key) {
            wp_send_json_error('Stripe secret key not found.');
        }
        $current_user = wp_get_current_user();
        $metadata = [
            'user_id'        => $user_id,
            'customer_name'  => $current_user->display_name,
            'customer_email' => $current_user->user_email,
        ];
        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        $data = [
            'amount'               => $amount_cents,
            'currency'             => 'usd',
            'payment_method_types' => ['card'],
            'description'          => 'SSM Cart Payment',
        ];
        foreach ($metadata as $key => $value) {
            $data["metadata[$key]"] = $value;
        }
        curl_setopt($ch, CURLOPT_USERPWD, $secret_key . ':');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            wp_send_json_error('cURL error: ' . $error);
        }
        $result = json_decode($response, true);
        if (isset($result['error'])) {
            wp_send_json_error('Stripe error: ' . $result['error']['message']);
        }
        if (isset($result['client_secret'])) {
            wp_send_json_success(['client_secret' => $result['client_secret']]);
        } else {
            wp_send_json_error('No client_secret in Stripe response.');
        }
    }
    

	public function maybe_handle_stripe_webhook() {
		if ( isset( $_GET['ssm_webhook'] ) && $_GET['ssm_webhook'] === 'stripe' ) {
			$this->handle_stripe_webhook();
		}
	}

	public function handle_stripe_webhook() {
		$payload = @file_get_contents( 'php://input' );
		$event   = json_decode( $payload, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			http_response_code( 400 );
			exit();
		}
		if ( isset( $event['type'] ) ) {
			switch ( $event['type'] ) {
				case 'checkout.session.completed':
					$session    = $event['data']['object'];
					$user_id    = isset( $session['metadata']['user_id'] ) ? intval( $session['metadata']['user_id'] ) : 0;
					$product_id = isset( $session['metadata']['product_id'] ) ? intval( $session['metadata']['product_id'] ) : 0;
					if ( $user_id && $product_id ) {
						$this->process_successful_subscription( $user_id, $product_id );
					}
					break;
				case 'customer.subscription.deleted':
				case 'invoice.payment_failed':
					$user_id = isset( $event['data']['object']['metadata']['user_id'] ) ? intval( $event['data']['object']['metadata']['user_id'] ) : 0;
					if ( $user_id ) {
						$this->expire_api_key( $user_id );
					}
					break;
				default:
					error_log( '[SSM] Unhandled Stripe event: ' . $event['type'] );
					break;
			}
		}
		http_response_code( 200 );
		exit();
	}

	public function process_successful_subscription( $user_id, $product_id ) {
		global $wpdb;
		$table_products = $wpdb->prefix . self::PRODUCT_TABLE;
		$product        = $wpdb->get_row( $wpdb->prepare(
			"SELECT subscription_interval FROM $table_products WHERE id = %d",
			$product_id
		) );
		if ( ! $product ) {
			error_log( '[SSM] Product not found for subscription.' );
			return;
		}
		$expiration_date = current_time( 'mysql' );
		if ( $product->subscription_interval === 'yearly' ) {
			$expiration_date = date( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
		} else {
			$expiration_date = date( 'Y-m-d H:i:s', strtotime( '+1 month' ) );
		}
		$existing_key = get_user_meta( $user_id, 'ssm_api_key', true );
		if ( ! $existing_key ) {
			$response = wp_remote_post( home_url( '/wp-json/akm/v1/key' ), array(
				'body' => array(
					'email'    => get_userdata( $user_id )->user_email,
					'api_key'  => wp_generate_password( 32, false ),
					'valid_to' => $expiration_date,
				),
			) );
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
			$response = wp_remote_post( home_url( '/wp-json/akm/v1/key/' . $user_id ), array(
				'method' => 'PUT',
				'body'   => array(
					'email'    => get_userdata( $user_id )->user_email,
					'api_key'  => $existing_key,
					'valid_to' => $expiration_date,
				),
			) );
			if ( is_wp_error( $response ) ) {
				error_log( '[SSM] API key update failed: ' . $response->get_error_message() );
			} else {
				update_user_meta( $user_id, 'ssm_api_key_expiry', $expiration_date );
			}
		}
	}

	public function expire_api_key( $user_id ) {
		$existing_key = get_user_meta( $user_id, 'ssm_api_key', true );
		if ( $existing_key ) {
			$response = wp_remote_post( home_url( '/wp-json/akm/v1/expire-key/' . $user_id ), array(
				'method' => 'POST',
			) );
			if ( is_wp_error( $response ) ) {
				error_log( '[SSM] API key expiration failed: ' . $response->get_error_message() );
			} else {
				update_user_meta( $user_id, 'ssm_api_key_expiry', current_time( 'mysql' ) );
			}
		}
	}

	public function ssm_add_to_cart_shortcode( $atts ) {
		$atts       = shortcode_atts( array(
			'product_id' => 0,
		), $atts, 'ssm_add_to_cart' );
		$product_id = intval( $atts['product_id'] );
		if ( ! $product_id ) {
			return 'Invalid product.';
		}
		global $wpdb;
		$table_products = $wpdb->prefix . self::PRODUCT_TABLE;
		$product        = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM $table_products WHERE id = %d",
			$product_id
		) );
		if ( ! $product ) {
			return 'Product not found.';
		}
		ob_start();
		?>
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

	public function ssm_checkout_shortcode() {
		if ( empty( $_SESSION['ssm_cart'] ) ) {
			return '<p>Your cart is empty.</p>';
		}
		global $wpdb;
		$table_products = $wpdb->prefix . self::PRODUCT_TABLE;
		$cart_items     = $_SESSION['ssm_cart'];
		$total_price    = 0;
		foreach ( $cart_items as $product_id => $quantity ) {
			$product  = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table_products WHERE id = %d",
				$product_id
			) );
			if ( $product ) {
				$subtotal    = $product->price * $quantity;
				$total_price += $subtotal;
			}
		}
		ob_start();
		?>
        <div class="ssm-checkout">
            <h2>Your Cart</h2>
            <table class="ssm-cart-table" style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="border-bottom:1px solid #ccc; text-align:left; padding:8px;">Product</th>
                        <th style="border-bottom:1px solid #ccc; text-align:center; padding:8px;">Quantity</th>
                        <th style="border-bottom:1px solid #ccc; text-align:right; padding:8px;">Price</th>
                        <th style="border-bottom:1px solid #ccc; text-align:right; padding:8px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
					<?php foreach ( $cart_items as $product_id => $quantity ) :
						$product = $wpdb->get_row( $wpdb->prepare(
							"SELECT * FROM $table_products WHERE id = %d",
							$product_id
						) );
						if ( ! $product ) {
							continue;
						}
						$subtotal = $product->price * $quantity;
						?>
                        <tr data-product-id="<?php echo esc_attr( $product->id ); ?>">
                            <td style="padding:8px;"><?php echo esc_html( $product->name ); ?></td>
                            <td style="padding:8px; text-align:center;">
                                <button class="ssm-qty-minus" data-product-id="<?php echo esc_attr( $product->id ); ?>">-</button>
                                <input type="text" value="<?php echo intval( $quantity ); ?>" class="ssm-qty-input" data-product-id="<?php echo esc_attr( $product->id ); ?>" style="width:40px; text-align:center;" />
                                <button class="ssm-qty-plus" data-product-id="<?php echo esc_attr( $product->id ); ?>">+</button>
                            </td>
                            <td style="padding:8px; text-align:right;">$<?php echo number_format( $product->price, 2 ); ?></td>
                            <td class="ssm-subtotal" style="padding:8px; text-align:right;">$<?php echo number_format( $subtotal, 2 ); ?></td>
                        </tr>
					<?php endforeach; ?>
                </tbody>
            </table>
            <p class="ssm-total" style="font-weight:bold; padding:8px;">
                Total: $<span id="ssm-total-amount"><?php echo number_format( $total_price, 2 ); ?></span>
            </p>
			<?php if ( ! is_user_logged_in() ) : ?>
                <div id="ssm-customer-info">
                    <label for="ssm-customer-name">Name:</label>
                    <input type="text" id="ssm-customer-name" name="ssm_customer_name" required>
                    <label for="ssm-customer-email">Email:</label>
                    <input type="email" id="ssm-customer-email" name="ssm_customer_email" required>
                </div>
			<?php endif; ?>
            <!-- Stripe Payment Form -->
            <div id="ssm-stripe-checkout">
                <h3>Enter Payment Details</h3>
                <form id="ssm-stripe-form">
                    <div id="card-element" style="margin-bottom:12px;"></div>
                    <div id="card-errors" role="alert" style="color:red;"></div>
                    <button type="submit" id="ssm-pay-now" class="button button-primary">Pay Now</button>
                </form>
            </div>
        </div>
		<?php
		return ob_get_clean();
	}

	public function ssm_subscription_account_shortcode() {
		ob_start(); ?>
        <div class="ssm-subscription-account">
            <h2>Your Subscription</h2>
			<?php
			$user_id = get_current_user_id();
			$api_key = get_user_meta( $user_id, 'ssm_api_key', true );
			$expiry  = get_user_meta( $user_id, 'ssm_api_key_expiry', true );
			?>
            <p>Your API Key: <?php echo esc_html( $api_key ); ?></p>
            <p>Expires on: <?php echo esc_html( $expiry ); ?></p>
            <form method="post" action="">
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

	public function register_admin_menus() {
		add_menu_page( 'SSM Manager', 'SSM Manager', 'manage_options', 'ssm_manager', array( $this, 'render_products_page' ), 'dashicons-cart', 58 );
		add_submenu_page( 'ssm_manager', 'Products', 'Products', 'manage_options', 'ssm_products', array( $this, 'render_products_page' ) );
		add_submenu_page( 'ssm_manager', 'Categories', 'Categories', 'manage_options', 'ssm_categories', array( $this, 'render_categories_page' ) );
		add_submenu_page( 'ssm_manager', 'API Key Management', 'API Keys', 'manage_options', 'ssm_api_keys', array( $this, 'render_api_keys_page' ) );
		add_submenu_page( 'ssm_manager', 'Error Logs', 'Error Logs', 'manage_options', 'ssm_error_logs', array( $this, 'render_error_logs_page' ) );
		add_submenu_page( 'ssm_manager', 'Instructions', 'Instructions', 'manage_options', 'ssm_instructions', array( $this, 'render_instructions_page' ) );
	}

	//------------------ Product Admin Pages ------------------

	public function render_products_page() {
		global $wpdb;
		$table_products = $wpdb->prefix . self::PRODUCT_TABLE;
		$table_rel      = $wpdb->prefix . self::PRODUCT_CAT_REL_TABLE;
		$table_cats     = $wpdb->prefix . self::CATEGORY_TABLE;
		$action         = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';

		if ( $action === 'delete' && isset( $_GET['id'] ) ) {
			$product_id = intval( $_GET['id'] );
			$result     = $wpdb->delete( $table_products, array( 'id' => $product_id ) );
			if ( $result !== false ) {
				$wpdb->delete( $table_rel, array( 'product_id' => $product_id ) );
				echo '<div class="updated"><p>Product deleted successfully.</p></div>';
			} else {
				echo '<div class="error"><p>Product deletion failed.</p></div>';
			}
			$action = '';
		}

		if ( isset( $_POST['ssm_product_submit'] ) ) {
			$name                  = sanitize_text_field( $_POST['name'] );
			$description           = sanitize_textarea_field( $_POST['description'] );
			$price                 = floatval( $_POST['price'] );
			$digital               = isset( $_POST['digital'] ) ? 1 : 0;
			$subscription          = isset( $_POST['subscription'] ) ? 1 : 0;
			$subscription_interval = sanitize_text_field( $_POST['subscription_interval'] );
			$subscription_price    = floatval( $_POST['subscription_price'] );
			$subscription_user_role = isset( $_POST['subscription_user_role'] ) ? sanitize_text_field( $_POST['subscription_user_role'] ) : '';
			$categories             = isset( $_POST['categories'] ) ? (array) $_POST['categories'] : array();

			if ( $action === 'add' ) {
				$wpdb->insert(
					$table_products,
					array(
						'name'                   => $name,
						'description'            => $description,
						'price'                  => $price,
						'digital'                => $digital,
						'subscription'           => $subscription,
						'subscription_interval'  => $subscription_interval,
						'subscription_price'     => $subscription_price,
						'subscription_user_role' => $subscription_user_role,
					)
				);
				$new_product_id = $wpdb->insert_id;
				foreach ( $categories as $cat_id ) {
					$wpdb->insert(
						$table_rel,
						array(
							'product_id'  => $new_product_id,
							'category_id' => intval( $cat_id ),
						)
					);
				}
				echo '<div class="updated"><p>Product added successfully.</p></div>';
			} elseif ( $action === 'edit' && isset( $_GET['id'] ) ) {
				$product_id = intval( $_GET['id'] );
				$wpdb->update(
					$table_products,
					array(
						'name'                   => $name,
						'description'            => $description,
						'price'                  => $price,
						'digital'                => $digital,
						'subscription'           => $subscription,
						'subscription_interval'  => $subscription_interval,
						'subscription_price'     => $subscription_price,
						'subscription_user_role' => $subscription_user_role,
					),
					array( 'id' => $product_id )
				);
				$wpdb->delete( $table_rel, array( 'product_id' => $product_id ) );
				foreach ( $categories as $cat_id ) {
					$wpdb->insert(
						$table_rel,
						array(
							'product_id'  => $product_id,
							'category_id' => intval( $cat_id ),
						)
					);
				}
				echo '<div class="updated"><p>Product updated successfully.</p></div>';
			}
		}

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
			$assigned_categories = ( $product ) ? $wpdb->get_col( $wpdb->prepare( "SELECT category_id FROM $table_rel WHERE product_id = %d", $product_id ) ) : array();
			$all_categories      = $wpdb->get_results( "SELECT * FROM $table_cats ORDER BY name", ARRAY_A );
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
                            <th><label for="ssm_subscription_user_role">Subscription User Role</label></th>
                            <td>
                                <input type="text" name="subscription_user_role" id="ssm_subscription_user_role" value="<?php echo $product ? esc_attr( $product->subscription_user_role ) : ''; ?>" placeholder="e.g., premium_member">
                                <p class="description">Role to assign upon successful subscription (defaults to 'subscriber' if left blank).</p>
                            </td>
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

		$search  = isset( $_GET['ssm_search'] ) ? sanitize_text_field( $_GET['ssm_search'] ) : '';
		$where   = ( $search ) ? $wpdb->prepare( "WHERE name LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ) : '';
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
                        <th>Shortcode</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
					<?php if ( $products ) : ?>
						<?php foreach ( $products as $p ) : 
							$shortcode = '[ssm_add_to_cart product_id="' . $p->id . '"]';
						?>
                            <tr>
                                <td><?php echo esc_html( $p->id ); ?></td>
                                <td><?php echo esc_html( $p->name ); ?></td>
                                <td>$<?php echo number_format( $p->price, 2 ); ?></td>
                                <td><?php echo $p->subscription ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <input type="text" class="ssm-shortcode-field" value="<?php echo esc_attr( $shortcode ); ?>" readonly style="width:100%; margin-bottom:4px;">
                                    <button type="button" class="button ssm-copy-btn" data-shortcode="<?php echo esc_attr( $shortcode ); ?>">Copy</button>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=edit&id=' . $p->id ); ?>">Edit</a> | 
                                    <a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=delete&id=' . $p->id ); ?>" onclick="return confirm('Are you sure?');">Delete</a>
                                </td>
                            </tr>
						<?php endforeach; ?>
					<?php else : ?>
                        <tr><td colspan="6">No products found.</td></tr>
					<?php endif; ?>
                </tbody>
            </table>
            <p><a href="<?php echo admin_url( 'admin.php?page=ssm_products&action=add' ); ?>" class="button button-primary">Add New Product</a></p>
        </div>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var copyButtons = document.querySelectorAll('.ssm-copy-btn');
            for (var i = 0; i < copyButtons.length; i++) {
                copyButtons[i].addEventListener('click', function (e) {
                    var shortcode = e.target.getAttribute('data-shortcode');
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(shortcode).then(function () {
                            e.target.innerText = 'Copied!';
                            setTimeout(function () {
                                e.target.innerText = 'Copy';
                            }, 2000);
                        });
                    }
                });
            }
        });
        </script>
		<?php
	}

	public function render_categories_page() {
		global $wpdb;
		$table_categories = $wpdb->prefix . self::CATEGORY_TABLE;
		$table_rel      = $wpdb->prefix . self::PRODUCT_CAT_REL_TABLE;
		$action         = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		if ( $action === 'delete' && isset( $_GET['id'] ) ) {
			$cat_id = intval( $_GET['id'] );
			$result = $wpdb->delete( $table_categories, array( 'id' => $cat_id ) );
			if ( $result !== false ) {
				$wpdb->delete( $table_rel, array( 'category_id' => $cat_id ) );
				echo '<div class="updated"><p>Category deleted successfully.</p></div>';
			} else {
				echo '<div class="error"><p>Category deletion failed.</p></div>';
			}
			$action = '';
		}
		if ( isset( $_POST['ssm_category_submit'] ) ) {
			$cat_name = sanitize_text_field( $_POST['name'] );
			if ( $action === 'add' ) {
				$wpdb->insert( $table_categories, array( 'name' => $cat_name ) );
				echo '<div class="updated"><p>Category added successfully.</p></div>';
			} elseif ( $action === 'edit' && isset( $_GET['id'] ) ) {
				$cat_id = intval( $_GET['id'] );
				$wpdb->update( $table_categories, array( 'name' => $cat_name ), array( 'id' => $cat_id ) );
				echo '<div class="updated"><p>Category updated successfully.</p></div>';
			}
		}
		if ( $action === 'add' || $action === 'edit' ) {
			$category = null;
			$cat_id   = 0;
			if ( $action === 'edit' && isset( $_GET['id'] ) ) {
				$cat_id   = intval( $_GET['id'] );
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

	public function render_api_keys_page() {
		$users = get_users( array(
			'meta_key'    => 'ssm_api_key',
			'meta_compare'=> 'EXISTS'
		) );
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

	public function render_error_logs_page() {
		$log_file = WP_CONTENT_DIR . '/debug.log';
		$filter   = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : '';
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

	public function render_instructions_page() {
		?>
        <div class="wrap">
            <h1>Plugin Instructions</h1>
            <h2>Product Management</h2>
            <p>Use the Products and Categories pages to add, edit, delete, and filter products and categories. Products include attributes such as name, description, price, digital flag, subscription flag, subscription interval, subscription price, and <strong>subscription user role</strong> (the role to assign when a subscription is purchased).</p>
            <h2>Shortcode Integration</h2>
            <p>Embed products in your posts/pages using the <code>[ssm_add_to_cart product_id="123"]</code> shortcode.</p>
            <h2>Checkout</h2>
            <p>Add the <code>[ssm_checkout]</code> shortcode to a page to display the cart summary, customer details fields (if not logged in), and a Stripe payment form.</p>
            <h2>Stripe Webhook & API Key Management</h2>
            <p>Stripe events are handled on-site. On a successful subscription, the plugin automatically creates or updates an API key. Subscription cancellations and payment failures trigger API key expiration. Manual controls are available in the API Key Management page.</p>
            <h2>Subscription Management</h2>
            <p>Customers can upgrade or downgrade their subscriptions from their account page (<code>[ssm_subscription_account]</code> shortcode). Renewal reminder emails are sent 7 days, 3 days, and on the day of renewal.</p>
            <h2>Error Logging</h2>
            <p>Error logs from WP debug log are displayed on the Error Logs page. You can filter logs by error type and clear the logs if needed.</p>
        </div>
		<?php
	}

	public function send_renewal_reminders() {
		$users = get_users( array(
			'meta_key'     => 'ssm_api_key_expiry',
			'meta_compare' => 'EXISTS'
		) );
		foreach ( $users as $user ) {
			$expiry = get_user_meta( $user->ID, 'ssm_api_key_expiry', true );
			if ( $expiry ) {
				$expiry_time = strtotime( $expiry );
				$now         = time();
				$diff        = $expiry_time - $now;
				$days_left   = floor( $diff / ( 60 * 60 * 24 ) );
				if ( in_array( $days_left, array( 7, 3, 0 ) ) ) {
					$subject = 'Your subscription is renewing soon';
					$message = "Hello {$user->display_name},\n\nYour subscription is set to renew in {$days_left} day(s). Please review your plan details in your account.\n\nThanks,\nSubscription Service Manager Team";
					wp_mail( $user->user_email, $subject, $message );
				}
			}
		}
	}
}

function ssm_enqueue_scripts() {
	wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugin_data = get_plugin_data( __FILE__ );
	$version     = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '1.0';
	wp_enqueue_script( 'ssm-front', plugins_url( 'assets/js/ssm-front.js', __FILE__ ), array(), $version, true );
	wp_localize_script( 'ssm-front', 'ssm_params', array(
		'ajax_url'       => admin_url( 'admin-ajax.php' ),
		'publishableKey' => get_option( 'flw_stripe_public_key', '' ),
	) );
}
add_action( 'wp_enqueue_scripts', 'ssm_enqueue_scripts' );

new SSM_Plugin();

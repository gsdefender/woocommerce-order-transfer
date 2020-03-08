<?php

/*
  Plugin Name: WooCommerce Order Transfer
  Plugin URI: https://github.com/gsdefender/woocommerce-order-transfer
  Description: WooCommerce plugin to allow transfering a pending order to another user upon checkout
  Version: 0.1.1
  Author: Emanuele Cipolla
  Author URI: https://emanuelecipolla.net/
  License: GPLv3
 */

defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @since 0.1.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 */
function wc_order_transfer_add_to_gateways( $gateways ) {
    $gateways['wc-gateway-order-transfer'] = 'WC_Gateway_Order_Transfer';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_order_transfer_add_to_gateways' );

add_filter('woocommerce_available_payment_gateways', 'filter_gateways');
function filter_gateways($gateways)
{
    $url_arr = explode('/', $_SERVER['REQUEST_URI']);
    if($url_arr[1] == 'checkout' && $url_arr[2] == 'order-pay' && is_user_logged_in() ){
        $order_id = intval($url_arr[3]);

        $order = wc_get_order($order_id);
        $dest_user_id = $order->get_meta('_dest_user_id', true, 'view');
        $dest_account_email = $order->get_meta('_dest_account_email', true, 'view');

        if (!empty($dest_user_id) || !empty($dest_account_email)) {
            unset($gateways['order_transfer_gateway']);
        }
    }

    return $gateways;
}

/**
 * Adds plugin page links
 *
 * @since 0.1.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_order_transfer_gateway_plugin_links( $links ) {

    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=order_transfer_gateway' ) . '">' . __( 'Configure', 'wc-gateway-order-transfer' ) . '</a>'
    );

    //return array_merge( array_slice($links,0,count($links)-2), $plugin_links, $links[$count($links)-1] );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_order_transfer_gateway_plugin_links' );


/**
 * Order Transfer Gateway
 *
 * Provides a virtual gateway to transfer orders to another customer.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Order_Transfer
 * @extends		WC_Payment_Gateway
 * @version		0.1.0
 * @package		WooCommerce/Classes/Payment
 * @author 		Emanuele Cipolla <emanuele@emanuelecipolla.net>
 */
add_action( 'plugins_loaded', 'wc_order_transfer_gateway_init', 11 );

function wc_order_transfer_gateway_init() {

    class WC_Gateway_Order_Transfer extends WC_Payment_Gateway {

        /**
         * Constructor for the gateway.
         */
        public function __construct() {

            $this->id                 = 'order_transfer_gateway';
            $this->icon               = apply_filters('woocommerce_order_transfer_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'Order transfer', 'wc-gateway-order-transfer' );
            $this->method_description = __( 'Allows transferring orders to another customer.  Orders are marked as "on-hold" when received.', 'wc-gateway-order-transfer' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions', $this->description );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_payment_type_meta_data' ), 10, 2 );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            // Customer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        }


        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields() {

            $this->form_fields = apply_filters( 'wc_order_transfer_form_fields', array(

                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'wc-gateway-order-transfer' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable order transfer', 'wc-gateway-order-transfer' ),
                    'default' => 'yes'
                ),

                'title' => array(
                    'title'       => __( 'Title', 'wc-gateway-order-transfer' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-order-transfer' ),
                    'default'     => __( 'Order transfer', 'wc-gateway-order-transfer' ),
                    'desc_tip'    => true,
                ),

                'description' => array(
                    'title'       => __( 'Description', 'wc-gateway-order-transfer' ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-gateway-order-transfer' ),
                    'default'     => __( 'Please transfer this order to another user', 'wc-gateway-order-transfer' ),
                    'desc_tip'    => true,
                ),

                'instructions' => array(
                    'title'       => __( 'Instructions', 'wc-gateway-order-transfer' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-gateway-order-transfer' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            ) );
        }

        /**
         * Output the "payment type" radio buttons fields in checkout.
         */
        public function payment_fields(){
            if ( $description = $this->get_description() ) {
                echo wpautop( wptexturize( $description ) );
            }

            echo '<style>#dest_account_email_field label.input { display:inline-block; margin:0 .8em 0 .4em}</style>';

            woocommerce_form_field( 'dest_account_email', array(
                'type'          => 'email',
                'required'      => true,
                'class'         => array('dest_account_email form-row-wide'),
                'label'         => __('E-mail address', $this->domain),
                'validate'      => array('email')
            ), null);
        }

        public function validate_fields(){

            if( empty( $_POST[ 'dest_account_email' ]) ) {
                wc_add_notice(  __( 'Destination email address is required', 'wc-gateway-order-transfer' ), 'error' );
                return false;
            } else if (!filter_var($_POST[ 'dest_account_email' ], FILTER_VALIDATE_EMAIL)) {
                wc_add_notice(  __( 'Invalid destination email address', 'wc-gateway-order-transfer' ), 'error' );
                return false;
            } else {
                $current_user = wp_get_current_user();
                $user_email = $current_user->user_email;

                if(strcmp($user_email, $_POST[ 'dest_account_email' ])==0) {
                    wc_add_notice(  __( 'You cannot transfer an order to yourself', 'wc-gateway-order-transfer' ), 'error' );
                    return false;
                }
            }

            return true;

        }

        /**
         * Save the chosen payment type as order meta data.
         *
         * @param object $order
         * @param array $data
         */
        public function save_order_payment_type_meta_data( $order, $data ) {
            if ( $data['payment_method'] === $this->id && isset($_POST['dest_account_email']) ) {
                $order->update_meta_data('_src_user_id', get_post_meta($this->id, '_customer_user', true));
                $user = get_user_by( 'email', $_POST['dest_account_email'] );
                $dest_user_id = null;
                if ($user !== false) {
                    $dest_user_id = $user->ID;
                }
                $order->update_meta_data('_dest_user_id', $dest_user_id);
                $order->update_meta_data('_dest_account_email', esc_attr($_POST['dest_account_email']));
            }
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ( $this->instructions ) {
                echo wpautop( wptexturize( $this->instructions ) );
            }
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

            if ( $this->instructions && ! $sent_to_admin && $this->id === $order->payment_method && $order->has_status( 'on-hold' ) ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
            }
        }


        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            // Mark as on-hold (we're awaiting the transfer confirmation)
            $order->update_status( 'on-hold', __( 'Awaiting order transfer confirmation', 'wc-gateway-order-transfer' ) );

            // Reduce stock levels
            wc_reduce_stock_levels( $order->get_id() );

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' 	=> 'success',
                'redirect'	=> $this->get_return_url( $order )
            );
        }

    } // end \WC_Gateway_Order_Transfer class
}

function wc_order_transfer_add_my_account_order_actions( $actions, $order ) {

    if($order->has_status('on-hold')) {
        $dest_user_id = $order->get_meta('_dest_user_id', true, 'view');
        $dest_account_email = $order->get_meta('_dest_account_email', true, 'view');

        if(!empty($dest_user_id) || !empty($dest_account_email)) {
            $current_user = wp_get_current_user();
            $current_user_id = (isset($current_user->ID) ? (int)$current_user->ID : 0);

            if ($dest_user_id === $current_user_id ||
                strcmp($dest_account_email, $current_user->user_email) == 0) {
                $my_account_page_url = get_permalink( get_option('woocommerce_myaccount_page_id') );
                $actions['accept_transfer'] = array(
                    'url' => $my_account_page_url.'accept-order-transfer/' . $order->ID,
                    'name' => __('Accept transfer'),
                );
                $actions['decline_transfer'] = array(
                    'url' => $my_account_page_url.'decline-order-transfer/' . $order->ID,
                    'name' => __('Decline transfer'),
                );
            }
        }
    } else if ( $order->has_status( 'pending' ) ) {
        $actions['edit-order'] = array(
            'url'  => wp_nonce_url( add_query_arg( array( 'order_again' => $order->get_id(), 'edit_order' => $order->get_id() ) ), 'woocommerce-order_again' ),
            'name' => __( 'Edit Order', 'woocommerce' )
        );
    }
    return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', 'wc_order_transfer_add_my_account_order_actions', 10, 2 );

/**
 * Handle custom query using our own metadata variables.
 * @param array $query - Args for WP_Query.
 * @param array $query_vars - Query vars from WC_Order_Query.
 * @return array modified $query
 */
function wc_order_transfer_handle_custom_query_var( $query, $query_vars ) {
    $custom_vars = array('_src_user_id', '_dest_user_id',
        '_dest_account_email');

    foreach($custom_vars as $custom_var) {
        if (!empty($query_vars[$custom_var])) {
            $query['meta_query'][] = array(
                'key' => $custom_var,
                'value' => esc_attr($query_vars[$custom_var]),
            );
        }
    }

    return $query;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'wc_order_transfer_handle_custom_query_var', 10, 2 );

// ------------------
// 1. Register new endpoint to use for My Account page
// Note: Resave Permalinks or it will give 404 error

function wc_order_transfer_add_endpoints() {
    add_rewrite_endpoint( 'order-transfer-requests', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'accept-order-transfer', EP_ROOT | EP_PAGES );
    add_rewrite_endpoint( 'decline-order-transfer', EP_ROOT | EP_PAGES );
}

add_action( 'init', 'wc_order_transfer_add_endpoints' );


// ------------------
// 2. Add new query var

function wc_order_transfer_order_transfer_requests_query_vars( $vars ) {
    $vars[] = 'order-transfer-requests';
    return $vars;
}

function wc_order_transfer_accept_order_transfer_query_vars( $vars ) {
    $vars[] = 'accept-order-transfer';
    return $vars;
}

function wc_order_transfer_decline_order_transfer_query_vars( $vars ) {
    $vars[] = 'decline-order-transfer';
    return $vars;
}

add_filter( 'query_vars', 'wc_order_transfer_order_transfer_requests_query_vars', 0 );
add_filter( 'query_vars', 'wc_order_transfer_accept_order_transfer_query_vars', 0 );
add_filter( 'query_vars', 'wc_order_transfer_decline_order_transfer_query_vars', 0 );


// ------------------
// 3. Insert the new endpoint into the My Account menu

function array_insert_after( array $array, $key, array $new ) {
    $keys = array_keys( $array );
    $index = array_search( $key, $keys );
    $pos = false === $index ? count( $array ) : $index + 1;

    return array_merge( array_slice( $array, 0, $pos ), $new, array_slice( $array, $pos ) );
}

function wc_order_transfer_add_order_transfer_requests_link_my_account( $items ) {
    $items = array_insert_after($items, 'orders', array('order-transfer-requests' => __('Order transfer requests')));
    return $items;
}

add_filter( 'woocommerce_account_menu_items', 'wc_order_transfer_add_order_transfer_requests_link_my_account', 99, 1 );


// ------------------
// 4. Add content to the new endpoint

function wc_order_transfer_order_transfer_requests_content() {
    $current_user = wp_get_current_user();

    $order_search_params = array(
        'status' => 'on-hold',
        'limit' => -1,
        'orderby' => 'date',
        'payment_method' => 'order_transfer_gateway',
        'meta_query' =>
            array(
            'relation' => 'OR',
            [
                'key'     => '_dest_user_id',
                'compare' => '=',
                'value'   => $current_user->ID,
            ],
            [
                'key'     => '_dest_account_email',
                'value'   => $current_user->user_email,
                'compare' => '='
            ])
        );

    $_orders = wc_get_orders($order_search_params);
    $orders = array();

    foreach($_orders as $_order) {
        $actions = wc_get_account_orders_actions( $_order );

        unset($actions['view']);

        if(count($actions)!=0) array_push($orders, array('order'=>$_order,'actions'=>$actions));
    }
    $has_orders = !empty($orders);
    echo '<h3>'.__('Order transfer requests').'</h3>';

    if ( $has_orders ) : ?>

	<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
		<thead>
			<tr>
				<?php foreach ( wc_get_account_orders_columns() as $column_id => $column_name ) : ?>
					<th class="woocommerce-orders-table__header woocommerce-orders-table__header-<?php echo esc_attr( $column_id ); ?>"><span class="nobr"><?php echo esc_html( $column_name ); ?></span></th>
				<?php endforeach; ?>
			</tr>
		</thead>

		<tbody>
			<?php
			foreach ( $orders as $_order ) {
			    $order = $_order['order'];
			    $actions = $_order['actions'];
				$item_count = $order->get_item_count() - $order->get_item_count_refunded();

                ?>
				<tr class="woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $order->get_status() ); ?> order">
					<?php foreach ( wc_get_account_orders_columns() as $column_id => $column_name ) : ?>
						<td class="woocommerce-orders-table__cell woocommerce-orders-table__cell-<?php echo esc_attr( $column_id ); ?>" data-title="<?php echo esc_attr( $column_name ); ?>">
							<?php if ( has_action( 'woocommerce_my_account_my_orders_column_' . $column_id ) ) : ?>
								<?php do_action( 'woocommerce_my_account_my_orders_column_' . $column_id, $order ); ?>

							<?php elseif ( 'order-number' === $column_id ) : ?>
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
									<?php echo esc_html( _x( '#', 'hash before order number', 'woocommerce' ) . $order->get_order_number() ); ?>
								</a>

							<?php elseif ( 'order-date' === $column_id ) : ?>
								<time datetime="<?php echo esc_attr( $order->get_date_created()->date( 'c' ) ); ?>"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></time>

							<?php elseif ( 'order-status' === $column_id ) : ?>
								<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>

							<?php elseif ( 'order-total' === $column_id ) : ?>
								<?php
								/* translators: 1: formatted order total 2: total order items */
								echo wp_kses_post( sprintf( _n( '%1$s for %2$s item', '%1$s for %2$s items', $item_count, 'woocommerce' ), $order->get_formatted_order_total(), $item_count ) );
								?>

							<?php elseif ( 'order-actions' === $column_id ) : ?>
								<?php
								$actions = wc_get_account_orders_actions( $order );

								unset($actions['view']);

								if ( ! empty( $actions ) ) {
									foreach ( $actions as $key => $action ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.OverrideProhibited
										echo '<a href="' . esc_url( $action['url'] ) . '" class="woocommerce-button button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a>';
									}
								}
								?>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
				<?php
			}
			?>
		</tbody>
	</table>

<?php else : ?>
	<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
		<?php esc_html_e( 'No order has been made yet.', 'woocommerce' ); ?>
	</div>
<?php endif;
}

add_action( 'woocommerce_account_order-transfer-requests_endpoint', 'wc_order_transfer_order_transfer_requests_content' );
// Note: add_action must follow 'woocommerce_account_{your-endpoint-slug}_endpoint' format

add_action( 'template_redirect', function() {
    global $wp_query;
    $my_account_page_url = get_permalink( get_option('woocommerce_myaccount_page_id') );

    if ( isset( $wp_query->query_vars['accept-order-transfer'] ) ) {
        $order_id = $wp_query->query_vars['accept-order-transfer'] ;
        if(!empty($order_id)) {
            $order = wc_get_order($order_id);
            $order->set_payment_method('');
            $order->set_customer_id(get_current_user_id());
            $order->save();
            $order->update_status('pending', $note = __('Transfer accepted.'));
        }
        wp_redirect($my_account_page_url . "orders");
        exit;
    } else if ( isset( $wp_query->query_vars['decline-order-transfer'] ) ) {
        $order_id = $wp_query->query_vars['decline-order-transfer'];
        if(!empty($order_id)) {
            $order = wc_get_order($order_id);
            $order->update_status('pending', $note = __('Transfer declined.'));
            wc_delete_order_item_meta( $order_id, '_dest_user_id' );
            wc_delete_order_item_meta( $order_id, '_dest_account_email' );
        }
       wp_redirect($my_account_page_url);
       exit;
    }
    return;
} );


add_action( 'woocommerce_cart_loaded_from_session', 'wc_order_transfer_detect_edit_order' );

function wc_order_transfer_detect_edit_order( $cart ) {
    if ( isset( $_GET['edit_order'] ) ) {
        $order_id = absint( $_GET['edit_order'] );
        WC()->session->set( 'edit_order', absint( $order_id ) );

        $order = wc_get_order($order_id);

         foreach( $order->get_items() as $product_id => $product_item ) {
             $product = $product_item->get_product();

             WC()->cart->add_to_cart($product_id,
                 $product_item->get_quantity(),
                 $product->get_variation_attributes());
         }
    }
}

// ----------------
// 4. Display Cart Notice re: Edited Order

add_action( 'woocommerce_before_cart', 'wc_order_transfer_show_me_session' );

function wc_order_transfer_show_me_session() {
    if ( ! is_cart() ) return;
    $edited = WC()->session->get('edit_order');
    if ( ! empty( $edited ) ) {
        $order = new WC_Order( $edited );
        $credit = $order->get_total();
        wc_print_notice( 'A credit of ' . wc_price($credit) . ' has been applied to this new order. Feel free to add products to it or change other details such as delivery date.', 'notice' );
    }
}

// ----------------
// 5. Calculate New Total if Edited Order

add_action( 'woocommerce_cart_calculate_fees', 'wc_order_transfer_use_edit_order_total', 20, 1 );

function wc_order_transfer_use_edit_order_total( $cart ) {

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    $edited = WC()->session->get('edit_order');
    if ( ! empty( $edited ) ) {
        $order = new WC_Order( $edited );
        $credit = -1 * $order->get_total();
        $cart->add_fee( 'Credit', $credit );
    }

}

// ----------------
// 6. Save Order Action if New Order is Placed

add_action( 'woocommerce_checkout_update_order_meta', 'wc_order_transfer_save_edit_order' );

function wc_order_transfer_save_edit_order( $order_id ) {
    $edited = WC()->session->get('edit_order');
    if ( ! empty( $edited ) ) {
        // update this new order
        update_post_meta( $order_id, '_edit_order', $edited );
        $neworder = new WC_Order( $order_id );
        $oldorder_edit = get_edit_post_link( $edited );
        $neworder->add_order_note( __('Order placed after editing. Old order number: ').'<a href="' . $oldorder_edit . '">' . $edited . '</a>' );
        // cancel previous order
        $oldorder = new WC_Order( $edited );
        $neworder_edit = get_edit_post_link( $order_id );
        $oldorder->update_status( 'cancelled', __('Order cancelled after editing. New order number: ').'<a href="' . $neworder_edit . '">' . $order_id . '</a> -' );
    }
}

register_activation_hook(__FILE__, 'woocommerce_order_transfer_activation');

function woocommerce_order_transfer_activation() {
    if (! wp_next_scheduled ( 'woocommerce_order_transfer_hourly_jobs' )) {
        wp_schedule_event(time(), 'hourly', 'woocommerce_order_transfer_hourly_jobs');
    }
}

add_action('woocommerce_order_transfer_hourly_jobs', 'check_expired_order_transfers');

function check_expired_order_transfers() {
    $order_search_params = array(
        'status' => 'on-hold',
        'limit' => -1,
        'orderby' => 'date',
        'payment_method' => 'order_transfer_gateway',
        'date_before' => strtotime("-1 day")
    );

    $_orders = wc_get_orders($order_search_params);

    foreach($_orders as $_order) {
        $_order->update_status('pending', $note = __('Transfer automatically declined.'));
        $order_id = $_order->get_id();
        wc_delete_order_item_meta( $order_id, '_dest_user_id' );
        wc_delete_order_item_meta( $order_id, '_dest_account_email' );
    }
}

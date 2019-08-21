<?php

/*
  Plugin Name: WooCommerce Order Transfer
  Plugin URI: https://github.com/gsdefender/woocommerce-order-transfer
  Description: WooCommerce plugin to allow transfering a pending order to another user upon checkout
  Version: 0.1.0
  Author: Emanuele Cipolla
  Author URI: https://emanuelecipolla.net/
  License: GPLv2
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
    $gateways[] = 'WC_Gateway_Order_Transfer';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_order_transfer_add_to_gateways' );


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

    return array_merge( $plugin_links, $links );
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
                    'default'     => __( 'Please transfer order to this user', 'wc-gateway-order-transfer' ),
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

            $option_keys = array_keys($this->options);

            woocommerce_form_field( 'dest_account_email', array(
                'type'          => 'email',
                'required'      => true,
                'class'         => array('dest_account_email form-row-wide'),
                'label'         => __('E-mail address', $this->domain),
                'validate'      => array('email')
            ), reset( $option_keys ) );
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
                $order->update_meta_data('_transfer_accepted', false);
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

        $current_user = wp_get_current_user();

        if((!empty($dest_user_id) || !empty($dest_user_email))
            && ( ($dest_user_id===get_current_user_id()) ||
                 (empty($dest_user_id) && strcmp($dest_account_email,$current_user->user_email)==0) )
          ){
            $actions['accept_transfer'] = array(
                'url' => '',
                'name' => __('Accept transfer'),
            );
            $actions['refuse_transfer'] = array(
                'url' => '',
                'name' => __('Refuse transfer'),
            );
        }
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
        '_dest_account_email',
        '_transfer_accepted');

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

function wc_order_transfer_add_order_transfer_requests_endpoint() {
    add_rewrite_endpoint( 'order-transfer-requests', EP_ROOT | EP_PAGES );
}

add_action( 'init', 'wc_order_transfer_add_order_transfer_requests_endpoint' );


// ------------------
// 2. Add new query var

function wc_order_transfer_order_transfer_requests_query_vars( $vars ) {
    $vars[] = 'order-transfer-requests';
    return $vars;
}

add_filter( 'query_vars', 'wc_order_transfer_order_transfer_requests_query_vars', 0 );


// ------------------
// 3. Insert the new endpoint into the My Account menu

function wc_order_transfer_add_order_transfer_requests_link_my_account( $items ) {
    $items['order-transfer-requests'] = __('Order transfer requests');
    return $items;
}

add_filter( 'woocommerce_account_menu_items', 'wc_order_transfer_add_order_transfer_requests_link_my_account' );


// ------------------
// 4. Add content to the new endpoint

function wc_order_transfer_order_transfer_requests_content() {
    $orders = wc_get_orders( array(
        'status' => 'on-hold',
        'limit' => -1,
        'orderby' => 'date',
        'payment_method' => 'order_transfer_gateway',
        /*'_dest_user_id' => get_current_user_id()*/
    ) );

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
			foreach ( $orders as $order ) {
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
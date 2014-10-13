<?php

class Affiliate_WP_WooCommerce extends Affiliate_WP_Base {
	
	/**
	 * The order object
	 *
	 * @access  private
	 * @since   1.1
	*/
	private $order;

	/**
	 * Setup actions and filters
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function init() {

		$this->context = 'woocommerce';

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'add_pending_referral' ), 10, 2 );
		
		// There should be an option to choose which of these is used
		add_action( 'woocommerce_order_status_completed', array( $this, 'mark_referral_complete' ), 10 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'mark_referral_complete' ), 10 );
		add_action( 'woocommerce_order_status_completed_to_refunded', array( $this, 'revoke_referral_on_refund' ), 10 );
		add_action( 'woocommerce_order_status_on-hold_to_refunded', array( $this, 'revoke_referral_on_refund' ), 10 );
		add_action( 'woocommerce_order_status_processing_to_refunded', array( $this, 'revoke_referral_on_refund' ), 10 );
		add_action( 'woocommerce_order_status_processing_to_cancelled', array( $this, 'revoke_referral_on_refund' ), 10 );
		add_action( 'woocommerce_order_status_completed_to_cancelled', array( $this, 'revoke_referral_on_refund' ), 10 );
	
		add_filter( 'affwp_referral_reference_column', array( $this, 'reference_link' ), 10, 2 );

		add_action( 'woocommerce_coupon_options', array( $this, 'coupon_option' ) );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'store_discount_affiliate' ) );

		// Per product referral rates
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_settings' ), 100 );
		add_action( 'save_post', array( $this, 'save_meta' ) );

	}

	/**
	 * Store a pending referral when a new order is created
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function add_pending_referral( $order_id = 0, $posted ) {

		$this->order = new WC_Order( $order_id );

		// Check if an affiliate coupon was used
		$affiliate_id = $this->get_coupon_affiliate_id();

		if( $this->was_referred() || $affiliate_id ) {

			if( false !== $affiliate_id ) {
				$this->affiliate_id = $affiliate_id;
			}

			if( affwp_get_affiliate_email( $this->affiliate_id ) == $this->order->billing_email ) {
				return; // Customers cannot refer themselves
			}

			$cart_discount = $this->order->get_total_discount();

			$items = $this->order->get_items();

			if( is_array( $items ) ) {

				// Calculate the referral amount based on product prices
				$amount = 0.00;
				foreach( $items as $product ) {

					// The order discount has to be divided across the items
					
					$discount = 0;
	
					if( $cart_discount > 0 ) {

						$discount = $cart_discount / count( $items );
	
					}

					$product_total = $product['line_total'] - $discount;
					
					$amount += $this->calculate_referral_amount( $product_total, $order_id, $product['product_id'] );

				}

			} else {

				$total  = $this->order->get_total() - $cart_discount;
				$amount = $this->calculate_referral_amount( $this->order->get_total(), $order_id );

			}
			
			if( 0 == $amount && affiliate_wp()->settings->get( 'ignore_zero_referrals' ) ) {
				return false; // Ignore a zero amount referral
			}

			$referral_id = affiliate_wp()->referrals->add( array(
				'amount'       => $amount,
				'reference'    => $order_id,
				'description'  => $this->get_referral_description(),
				'affiliate_id' => $this->affiliate_id,
				'visit_id'     => affiliate_wp()->tracking->get_visit_id(),
				'context'      => $this->context
			) );

			if( $referral_id ) {

				$amount = affwp_currency_filter( affwp_format_amount( $amount ) );
				$name   = affiliate_wp()->affiliates->get_affiliate_name( $this->affiliate_id );

				$this->order->add_order_note( sprintf( __( 'Referral #%d for %s recorded for %s', 'affiliate-wp' ), $referral_id, $amount, $name ) );

			}

		}

	}

	/**
	 * Mark referral as complete when payment is completed
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function mark_referral_complete( $order_id = 0 ) {

		$this->complete_referral( $order_id );

	}

	/**
	 * Revoke the referral when the order is refunded
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function revoke_referral_on_refund( $order_id = 0 ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		$this->reject_referral( $order_id );

	}

	/**
	 * Setup the reference link
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function reference_link( $reference = 0, $referral ) {

		if( empty( $referral->context ) || 'woocommerce' != $referral->context ) {

			return $reference;

		}

		$url = get_edit_post_link( $reference );

		return '<a href="' . esc_url( $url ) . '">' . $reference . '</a>';
	}

	/**
	 * Shows the affiliate drop down on the discount edit / add screens
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function coupon_option() {

		global $post;

		add_filter( 'affwp_is_admin_page', '__return_true' );
		affwp_admin_scripts();

		$affiliate_id = get_post_meta( $post->ID, 'affwp_discount_affiliate', true );
		$user_id      = affwp_get_affiliate_user_id( $affiliate_id );
		$user         = get_userdata( $user_id );
		$user_name    = $user ? $user->user_login : '';
?>
		<p class="form-field affwp-woo-coupon-field">
			<label for="user_name"><?php _e( 'Affiliate Discount?', 'affiliate-wp' ); ?></label>
			<span class="affwp-ajax-search-wrap">
				<input type="hidden" name="user_id" id="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
				<input type="text" name="user_name" id="user_name" value="<?php echo esc_attr( $user_name ); ?>" class="affwp-user-search" autocomplete="off" style="width: 300px;" />
				<img class="affwp-ajax waiting" src="<?php echo admin_url('images/wpspin_light.gif'); ?>" style="display: none;"/>
				<span id="affwp_user_search_results"></span>
				<img class="help_tip" data-tip='<?php _e( 'If you would like to connect this discount to an affiliate, enter the name of the affiliate it belongs to.', 'affiliate-wp' ); ?>' src="<?php echo WC()->plugin_url(); ?>/assets/images/help.png" height="16" width="16" />
			</span>
		</p>
<?php
	}

	/**
	 * Stores the affiliate ID in the discounts meta if it is an affiliate's discount
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function store_discount_affiliate( $coupon_id = 0 ) {

		if( empty( $_POST['user_id'] ) && empty( $_POST['user_name'] ) ) {
			return;
		}

		if( empty( $_POST['user_id'] ) ) {
			$user = get_user_by( 'login', $_POST['user_name'] );
			if( $user ) {
				$user_id = $user->ID;
			}
		} else {
			$user_id = absint( $_POST['user_id'] );
		}

		$affiliate_id = affwp_get_affiliate_id( $user_id );

		update_post_meta( $coupon_id, 'affwp_discount_affiliate', $affiliate_id );
	}

	/**
	 * Retrieve the affiliate ID for the coupon used, if any
	 *
	 * @access  public
	 * @since   1.1
	*/
	private function get_coupon_affiliate_id() {
		
		$coupons = $this->order->get_used_coupons();

		if( empty( $coupons ) ) {
			return false;
		}

		foreach( $coupons as $code ) {

			$coupon       = new WC_Coupon( $code );
			$affiliate_id = get_post_meta( $coupon->id, 'affwp_discount_affiliate', true );

			if( $affiliate_id ) {

				return $affiliate_id;
			
			}

		}

		return false;
	}

	/**
	 * Retrieves the referral description
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function get_referral_description() {
		
		$description = ''; 
		$items       = $this->order->get_items();
		foreach( $items as $key => $item ) {
			$description .= $item['name'];
			if( $key + 1 < count( $items ) ) {
				$description .= ', ';
			}
		}

		return $description;

	}

	/**
	 * Adds per-product referral rate settings input fields
	 *
	 * @access  public
	 * @since   1.2
	*/
	public function product_settings() {

		global $post;

		if( isset( $post->ID ) ) {

			$rate = $this->get_product_rate( $post->ID );
		
		} else {

			$rate = '';

		}
		
		woocommerce_wp_text_input( array( 'id' => '_affwp_woocommerce_product_rate', 'label' => __( 'Affiliate Rate', 'affiliate-wp' ), 'desc_tip' => true, 'description' => __( 'These settings will be used to calculate affiliate earnings per-sale. Leave blank to use default affiliate rates.', 'affiliate-wp' ) ) );

	}

	/**
	 * Saves per-product referral rate settings input fields
	 *
	 * @access  public
	 * @since   1.2
	*/
	public function save_meta( $post_id = 0 ) {

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		// Don't save revisions and autosaves
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );

		if( ! $post ) {
			return $post_id;
		}

		// Check post type is product
		if ( 'product' != $post->post_type ) {
			return $post_id;
		}

		// Check user permission
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		if( ! empty( $_POST['_affwp_' . $this->context . '_product_rate'] ) ) {

			$rate = sanitize_text_field( $_POST['_affwp_' . $this->context . '_product_rate'] );
			update_post_meta( $post_id, '_affwp_' . $this->context . '_product_rate', $rate );
	
		} else {

			delete_post_meta( $post_id, '_affwp_' . $this->context . '_product_rate' );

		}

	}
	
}
new Affiliate_WP_WooCommerce;
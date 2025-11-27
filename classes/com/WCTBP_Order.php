<?php 
class WCTBP_Order
{
	public function __construct()
	{
			add_action( 'woocommerce_order_status_changed', array( $this,'order_status_changed'), 10, 4 ); 
			
	}
	public function order_status_changed($order_id, $from, $to, $order)
	{
		if(!$order->get_customer_id())
			return;
		
		global $wctbp_user_model;
		
		$action = in_array($to, array( 'failed', 'cancelled', 'refunded', 'pending', 'draft')) ? 'delete' : 'update';
		$wctbp_user_model->update_product_purchased_data($order, $action);
	}
	
}
?>
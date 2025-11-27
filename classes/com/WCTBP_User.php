<?php 
class WCTBP_User
{
	public function __construct() 
	{
	}
	function belongs_to_roles_rule_or_to_selected_users($roles, $selected_users, $strategy)
	{
		if((!$roles || empty($roles) || $roles[0] == "") && empty($selected_users))
			return true;
		
		if(!is_user_logged_in())
			return false;
		
		$current_user = wp_get_current_user();
		$current_user_roles = $current_user->roles;
		$current_user_id = $current_user->ID;
		$selected_user_ids = array();
	
		foreach((array)$selected_users as $temp_user)
			$selected_user_ids[] = $temp_user['ID'];
		
		$result = false;
		if(!empty($selected_users))
			$result =  ($strategy == "all" && in_array($current_user_id,$selected_user_ids)) || ($strategy == "except" && !in_array($current_user_id,$selected_user_ids)) ? true : false;
		
		if((!$result && $strategy == "all")||($result && $strategy == "except"))
			$result =  ($strategy == "all" && array_intersect($roles,$current_user_roles)) || ($strategy == "except" && !array_intersect($roles,$current_user_roles)) ? true : false;
		
		return $result;
	}
	function update_product_purchased_data($order, $action)
	{
		$user = new WC_Customer($order->get_customer_id());
		
		foreach($order->get_items() as $order_item)
		{
			$specific_product_id = $order_item->get_variation_id() ? $order_item->get_variation_id() : $order_item->get_product_id();
			if($action == 'update')
				$user->update_meta_data('wctbp_#'.$order->get_id()."_pr".$specific_product_id, $order_item->get_quantity());
			else
				$user->delete_meta_data('wctbp_#'.$order->get_id()."_pr".$specific_product_id);
		}
		$user->save();
	}
	function get_purchased_quantity($product_id, $user_id = null)
	{
		global $wpdb;
		
		$user_id = $user_id ? $user_id : get_current_user_id();
		if(!$user_id)
			return 0;
		
		$quantity = 0;
		$query = "	SELECT meta_value 
					FROM {$wpdb->prefix}usermeta 
					WHERE meta_key 
					LIKE '%_pr".$product_id."%'
					AND user_id = '".$user_id."'";
		$results = $wpdb->get_results($query,ARRAY_A);
		
		//In multisite installation, the prefix used might be the "$wpdb->base_prefix"
		if(!$result && is_multisite())
		{
			$query = "	SELECT meta_value 
					FROM {$wpdb->base_prefix}usermeta 
					WHERE meta_key 
					LIKE '%_pr".$product_id."%'
					AND user_id = '".$user_id."'";
					
			$results = $wpdb->get_results($query,ARRAY_A);
		}
		
		if($results)
			foreach($results as $metadata)
				$quantity += $metadata["meta_value"];
				
		
		return $quantity;
	}
}
?>
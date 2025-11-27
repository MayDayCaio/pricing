<?php 
class WCTBP_Product
{
	var $rules;
	var $id_to_new_price_cache = array();
	var $last_pricing_info = array();
	
	public function __construct(){}

	 public static function get_variations($product_id)
	 {
		global $wpdb, $wctbp_wpml_helper;
		
		if($wctbp_wpml_helper->is_active())
			$product_id = $wctbp_wpml_helper->get_original_id($product_id);
		
		 $query = "SELECT products.ID, product_price.meta_value as price
		           FROM {$wpdb->posts} AS products 
		           INNER JOIN {$wpdb->postmeta} AS product_price ON product_price.post_id = products.ID
				   WHERE product_price.meta_key = '_price' 
				   AND	 products.post_parent = {$product_id} AND products.post_type = 'product_variation' AND products.post_status = 'publish' "; //_regular_price
		 $result =  $wpdb->get_results($query); 
		
		 return isset($result) ? $result : null;		 
	 }
	public function get_product_price($product_id)
	{
		 global $wpdb, $wctbp_wpml_helper;
		 if($wctbp_wpml_helper->is_active())
			$product_id = $wctbp_wpml_helper->get_original_id($product_id);
		 $query = "SELECT product_price.meta_value as price
		           FROM {$wpdb->posts} AS products 
		           INNER JOIN {$wpdb->postmeta} AS product_price ON product_price.post_id = products.ID
				   WHERE product_price.meta_key = '_price' 
				   AND	 products.ID = {$product_id} ";
		 $result =  $wpdb->get_results($query); 
		
		 return !empty($result) && isset($result[0]) ? $result[0]->price : null;	
	}
	function get_wc_product_price($product_id)
	{
		$wc_product = wc_get_product($product_id);
		if(!isset($wc_product) || $wc_product == false)
			return false;
		
		return $wc_product->get_price('numeric');
	}
	public function get_variation_complete_name($variation_id, $cart_item = null)
	{
		$error = false;
		$variation = null;
		try
		{
			
			$variation = wc_get_product($variation_id);
		}
		catch(Exception $e){$error = true;}
		if($error) 
			try
			{
				$error = false;
				
				$variation = wc_get_product($variation_id);
				return $variation->get_title();
			}catch(Exception $e){$error = true;}
		
		if($error || $variation == false || !isset($variation))
			return "";
			
		$product_name = $variation->get_title()." - ";	
		$attributes_counter = 0;
		foreach($variation->get_variation_attributes( ) as $attribute_name => $value)
		{
			
			if($attributes_counter > 0)
				$product_name .= ", ";
			$meta_key = urldecode( str_replace( 'attribute_', '', $attribute_name ) ); 
			if(isset($cart_item) && isset($cart_item['variation']) && !empty($cart_item['variation']))
					foreach($cart_item['variation'] as $temp_attribute_name => $temp_attribute_value)
						if($temp_attribute_name == "attribute_".$meta_key)
								$value = $temp_attribute_value;
			$product_name .= " ".wc_attribute_label( $meta_key).": ".$value;
			$attributes_counter++;
		}
		return $product_name;
	}
	 public function get_min_max_price_variations($product_id, $quantity = 0) //if zero, will be automatically detected
	 {
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		 $result = $this->get_variations($product_id);
		 if(!isset($result) || empty($result))
			 return false;
		 
		 $min = $max = null;
		 $min_rule_info = $max_rule_info = array();
		 $tax = null;
		 foreach( $result as $item)
		 {
			
			 $product = wc_get_product($item->ID);
			 if($product == false || !isset($product))
				 continue;
			 
			 $old_price = $product->get_price('wctbp'); //For some reasons, there is no need to remove taxes
			 $new_price = $this->get_new_price_or_discount_rule($old_price , $product, true, 'price', $quantity);
			 $hide_price = isset($new_price) && $new_price == 'hide_price';
  			 //if numeric it means is a new price
			 $new_price = is_numeric($new_price)  ? $new_price : $item->price;
			 if(!isset($min) || (is_numeric($new_price) && $new_price < $min))
			 {
				$min = $new_price;
				$min_rule_info = $this->get_last_pricing_rule_applied_info();
			 }
			 if(!isset($max) || (is_numeric($new_price) && $new_price > $max))
			 {
				$max = $new_price;
				$max_rule_info = $this->get_last_pricing_rule_applied_info();
			 }
			 
		 }
		
		 return array("min" => $min, "max" => $max, "min_rule_info" => $min_rule_info, "max_rule_info" =>$max_rule_info, "hide_price" => $hide_price);
	 }
	public function get_price_rules_by_prduct_id($post_id, $post_type)
	{
		global $wctbp_wpml_helper, $wctbp_option_model;
		if($wctbp_wpml_helper->is_active())
			$post_id = $wctbp_wpml_helper->get_original_id($post_id);
		
		$rule_names = array();
		$all_options = $wctbp_option_model->get_option('scheduling_rules');
		if(isset($all_options))
			foreach($all_options as $schedule_rule)
			{
				$custom_post_type = 'product'; 
				$custom_category_type = 'product_cat'; 
				$selected_post_ids =  $schedule_rule['selected_products'];
				$selected_post_categories_ids =  $schedule_rule['selected_product_categories'];
				$scheduled_post_ids = array();
				$additional_ids = array();
				
				if($selected_post_ids)
					if($schedule_rule['selected_strategy'] == 'all')
					{
						$scheduled_post_ids = $selected_post_ids;
					}
					else
					{
						$scheduled_post_ids = $this->get_complementry_ids($selected_post_ids, $custom_post_type);
					}
					
				//get remaining posts ids from selected categories
				if($selected_post_categories_ids)
					$additional_ids = $this->get_post_ids_using_categories($custom_category_type,$selected_post_categories_ids, $schedule_rule['categories_children'], $schedule_rule['selected_strategy']);
				
				if(!empty($additional_ids))
					$scheduled_post_ids = array_merge($scheduled_post_ids, $additional_ids);
				
				if(in_array($post_id, $scheduled_post_ids))
					$rule_names[] = $schedule_rule['rule_name_id'];
			}
	
		return $rule_names;
	}
	
	function is_any_discount_rule_active_on_product( $product ) 
	{ 
		global  $wctbp_option_model;	
		$result = array('is_active' => false, 'is_on_sale' => false, 'is_variable'=>false, 'price'=>0, 'min_price'=>0, 'max_price'=>0);
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		
		if(is_a($product, 'WC_Product_Variable'))
		{
			
			$old_numeric_min = $product->get_variation_regular_price();
			$old_numeric_max = $product->get_variation_regular_price('max');
			$result = $this->get_min_max_price_variations($product->get_id());
			
			/* if needed to remove taxes, uncomment: 
			if($prices_entered_include_tax == 'yes' )
			{
				$old_numeric_min = WCTBP_Tax::get_product_price_excluding_tax($product, 1, $old_numeric_min) ;
				$old_numeric_max = WCTBP_Tax::get_product_price_excluding_tax($product, 1, $old_numeric_max) ;
			} */
			
			$result['is_variable'] = true;
			$result['min_price'] = $result['min'] ;
			$result['max_price'] = $result['max'] ;
			if( $result['min'] < $old_numeric_min  || $result['max'] < $old_numeric_max)
				$result['is_on_sale'] = true;
			
			
		}
		else 
		{
			$old_price = $product->get_price('numeric'); //No need to remove taxes
			$price = $this->get_new_price_or_discount_rule($old_price, 
																		  $product, 
																		  $product->get_type() == 'variation' ? $product_obj->get_id() : 0, 
																		  'price', 
																		  0, 
																		  false); 
			
			$price = is_numeric($price) ? $price : $old_price;
			$result['price'] = $price;
			if($price < $old_price) 
				$result['is_on_sale'] = true;
		}
		
		return $result;
	} 
	public function get_products_with_pricing_rules_applied($search_string = null)
	{
		
		
		global $wpdb, $wctbp_wpml_helper;
		$additional_join = "  ";
		$additional_where = "  ";
																					//product_meta.meta_value as product_sku
		$query_string = "SELECT products.ID as id, products.post_title as product_name
							 FROM {$wpdb->posts} AS products
							 LEFT JOIN {$wpdb->postmeta} AS product_meta ON product_meta.post_id = products.ID ".
							 $additional_join." 
							 WHERE products.post_status IN({$this->get_selectable_post_statuses_query_string()})
							"; // AND product_meta.meta_key = '_sku'
		if($search_string)
				$query_string .=  " AND ( products.post_title LIKE '%{$search_string}%' OR product_meta.meta_value LIKE '%{$search_string}%' OR products.ID LIKE '%{$search_string}%' ) 
								    ";
		
		$query_string .= $additional_where;
		$query_string .=  " AND products.post_type ='product' GROUP BY products.ID ";
		$result = $wpdb->get_results($query_string ) ;
		
		//WPML
		if($wctbp_wpml_helper->is_active())
		{
			$result = $wctbp_wpml_helper->remove_translated_id($result);
		}
		
		return $result;
	}
	private function get_selectable_post_statuses_query_string()
	{
		return "'publish','draft'";
	}
	private function retrieve_rules_by_time_and_user_roles()
	{
		if(isset($this->rules))
			return $this->rules;
		global $wctbp_option_model, $wctbp_time_model, $wctbp_wpml_helper, $wctbp_user_model;
		$all_options = $wctbp_option_model->get_option('scheduling_rules');
		if(!$all_options)
			return false;
		
		$hide_product_due_to_unauthorized_user = false;
		
		$this->rules = array();
		foreach($all_options as $schedule_rule)
		{
			
			$custom_post_type = 'product'; 
			$custom_category_type ='product_cat'; 
			$selected_post_ids = $schedule_rule['selected_products'];
			$selected_post_categories_ids = $schedule_rule['selected_product_categories'];
			foreach($schedule_rule['dates'] as $rule_datetime)
			{
				
				$scheduled_post_ids = array();
				$additional_ids = array();
			
				$match = $wctbp_time_model->check_if_now_matches_rule_datetime($rule_datetime);
				//User role filter
				$user_has_rights = $wctbp_user_model->belongs_to_roles_rule_or_to_selected_users($rule_datetime['user_roles'],$rule_datetime['user_accounts'],$rule_datetime['user_filtering_strategy']);
				
				//Consider only the last rule. 
				$hide_product_due_to_unauthorized_user = $rule_datetime['hide_price_for_unauthorized_users'] == 'yes' && !$user_has_rights && $match ? true : false;
				
				//ids to switch computation
				if($match && ($user_has_rights || $hide_product_due_to_unauthorized_user)) 
				{ 
					
					//Selected posts ids from configuration
					if($selected_post_ids)
					{
						if($schedule_rule['selected_strategy'] == 'all')
						{
							$scheduled_post_ids = $selected_post_ids;
						}
						else
						{
							$scheduled_post_ids = $this->get_complementry_ids($selected_post_ids, $custom_post_type);
						}
					}
					//get remaining posts ids from selected categories
					if($selected_post_categories_ids)
						$additional_ids = $this->get_post_ids_using_categories($custom_category_type,$selected_post_categories_ids, $schedule_rule['categories_children'], $schedule_rule['selected_strategy']);
					
					if(!empty($additional_ids))
						foreach($additional_ids as $additional_id)
							$scheduled_post_ids[] = $additional_id;
					
					
					//WPML: get translated posts
					if($wctbp_wpml_helper->is_active())
					{
						if(!empty($scheduled_post_ids))
    						foreach($scheduled_post_ids as $post_id)
    						{
    							$temp_translated_ids = $wctbp_wpml_helper->get_all_translation_ids($post_id, $custom_post_type);
    							if(!empty($temp_translated_ids))
    								foreach($temp_translated_ids as $temp_translated_id)
    									$scheduled_post_ids[] = (int)$temp_translated_id;
    						}
						
						if(!empty($additional_ids))
    						foreach($additional_ids as $post_id)
    						{
    							$temp_translated_ids = $wctbp_wpml_helper->get_all_translation_ids($post_id, $custom_post_type);
    							
    							if(!empty($temp_translated_ids))
    								foreach($temp_translated_ids as $temp_translated_id)
    									$additional_ids[] = (int)$temp_translated_id;
    						
    						}
					}
				
					$this->rules[] = array("rule_name"=>$schedule_rule['rule_name_id'] , "hide_product_due_to_unauthorized_user" => $hide_product_due_to_unauthorized_user, "product_ids" => $scheduled_post_ids, "rule"=>$rule_datetime,  "product_ids_filtered_by_selected_categories" => $additional_ids);
				}
			} 
		} 
		
		return $this->rules;
	}
	public function retrieve_rules_by_time_and_user_roles_specific_to_the_product($product_id)
	{
		global $wctbp_time_model, $wctbp_wpml_helper, $wctbp_user_model;
		$all_rules = $this->get_product_specific_price_rules($product_id);
		if(!$all_rules)
			return false;
		
		$hide_product_due_to_unauthorized_user = false;
		$product_specific_rules = array();
		foreach($all_rules as $rule_datetime)
		{
			
			$match = $wctbp_time_model->check_if_now_matches_rule_datetime($rule_datetime);
			//User role filter
			$user_has_rights = $wctbp_user_model->belongs_to_roles_rule_or_to_selected_users($rule_datetime['user_roles'],$rule_datetime['user_accounts'],$rule_datetime['user_filtering_strategy']);
			
			//Consider only the last rule. 
			$hide_product_due_to_unauthorized_user = $rule_datetime['hide_price_for_unauthorized_users'] == 'yes' && !$user_has_rights && $match ? true : false;
			
			//ids to switch computation
			if($match && ($user_has_rights || $hide_product_due_to_unauthorized_user)) 
			{ 
				$product_specific_rules[] = array("rule_name"=>$rule_datetime['rule_name_id'] ,"hide_product_due_to_unauthorized_user" => $hide_product_due_to_unauthorized_user,  "selected_product_variants" => $rule_datetime['selected_product_variants'], "rule"=>$rule_datetime);
			}
			
		} 
		
		return $product_specific_rules;
	}
	
	public function get_last_pricing_rule_applied_info()
	{
		return $this->last_pricing_info;
	}
	public function reset_last_pricing_rule_applied_info()
	{
		$this->last_pricing_info = array('applied_price_same_of_original' => false);
	}
	public function get_new_price_or_discount_rule($current_price, $product, $use_variation_id = false, $type = 'price', $quantity = 0, $return_original_price = true)
	{
		
		global $wctbp_option_model, $wctbp_time_model, $wctbp_wpml_helper, $woocommerce;
		
		$this->reset_last_pricing_rule_applied_info();
		
		if($current_price == "")
			return false;
		$is_variation = $product->get_type() == 'variation' ? true : false;
		$product_id =  $product->get_id();
		$product_parent_id =  $is_variation ? $product->get_parent_id() : $product->get_id();
		
		
		$rules = $this->retrieve_rules_by_time_and_user_roles();
		$rules_specific_to_product = $this->retrieve_rules_by_time_and_user_roles_specific_to_the_product($product_parent_id);
		if($rules == false && $rules_specific_to_product  == false)
			return false;
		
		
		if($wctbp_wpml_helper->is_active())
		{
			$product_id = $is_variation ? $wctbp_wpml_helper->get_original_id(version_compare( WC_VERSION, '2.7', '<' ) ? $product->variation_id : $product->get_id(), 'product_variation') : $wctbp_wpml_helper->get_original_id($product->get_id());
		}
		
		//check cache
		
		$new_price = $current_price;
		$has_rule_been_applied = false;
		if($rules != false && is_array($rules))
			foreach($rules as $rule)
			{
				
				if( $rule['rule']['ignore_rule_if_coupon_is_active'] && isset($woocommerce) && isset($woocommerce->cart) && $woocommerce->cart->get_applied_coupons())
					continue;
				
				if( $has_rule_been_applied && $rule['rule']['stack_rule'] == 'no')
					continue;
				
			
				
				//Check if a simple or variable product is in the rule products filter
				if( in_array($product_id,  $rule['product_ids']) ||
				    empty($rule['product_ids']) //In case no product/category has been selected for the current rule, it will be applied to all product (event to the current examined one)
				   ) 
				{		
					if($rule['hide_product_due_to_unauthorized_user'] == 'yes')
						$new_price = 'hide_price';
					else
					{
						
						$result = $this->get_price_by_quantity($new_price,  $rule, $product, $type, $use_variation_id, $rule['product_ids_filtered_by_selected_categories'], $quantity );
						if(($type == 'price' && is_numeric($result) && ($result != $new_price || $this->last_pricing_info['applied_price_same_of_original'])) || ($type == 'discount' && is_array($result)))
						{
							$has_rule_been_applied = true;
							$new_price = $result ;
						}
							
					}
				}
				//In case of variable product, if in the rule product filter has been chosen its MASTER
				//the $use_variation_id is ignored in the get_price_by_quantity() because the rule has to be applied to all variations
				else if($use_variation_id && in_array($product_parent_id,  $rule['product_ids']))
				{
					if($rule['hide_product_due_to_unauthorized_user'] == 'yes')
						$new_price = 'hide_price';
					else
					{
						$result = $this->get_price_by_quantity($new_price, $rule, $product, $type,  false, $rule['product_ids_filtered_by_selected_categories'], $quantity);
						if(($type == 'price' && is_numeric($result) && ($result != $new_price || $this->last_pricing_info['applied_price_same_of_original'])) || ($type == 'discount' && is_array($result)))
						{
							$has_rule_been_applied = true;
							$new_price =  $result ;
						}
						
					}
				}
			}
		
		
		//Product rules have higher priority: NOTE bulk rule and product rule won't stack. ONLY same type of rule will stack
		if($rules_specific_to_product != false)
		{
			$has_rule_been_applied = false;
			$new_price = $current_price; 
			foreach($rules_specific_to_product as $rule)
			{
				
				if( $has_rule_been_applied && $rule['rule']['stack_rule'] == 'no')
					continue;
				
				if($rule['rule']['ignore_rule_if_coupon_is_active'] && isset($woocommerce) && $woocommerce->cart->get_applied_coupons())
					continue;
				
				//In case of variants, is the "master" product in the set of selected products? 
				//if so, master product will be used instead of the variant
				if(empty($rule['selected_product_variants']) || get_class($product) == 'WC_Product_Simple' /*  is_a($product, 'WC_Product_Simple') */) //Ignoring variants if product is simple
				{
					
					if($rule['hide_product_due_to_unauthorized_user'] == 'yes')
						$new_price = 'hide_price';
					else
					{
						$result = $this->get_price_by_quantity($new_price, $rule, $product, $type, false, false, $quantity);
						if(($type == 'price' && is_numeric($result) && ($result != $new_price || $this->last_pricing_info['applied_price_same_of_original'])) || ($type == 'discount' && is_array($result)))
						{
							$has_rule_been_applied = true;
							$new_price =  $result ;
						}
					}
				}
				else if($use_variation_id && in_array($product_id,  $rule['selected_product_variants']) )
				{
					$result = $this->get_price_by_quantity($new_price,  $rule, $product, $type, $use_variation_id, false, $quantity);
					if(($type == 'price' && is_numeric($result) && ($result != $new_price || $this->last_pricing_info['applied_price_same_of_original'])) || ($type == 'discount' && is_array($result)))
					{
						$has_rule_been_applied = true;
						$new_price =  $result ;
					}
				}
			}
		}
		
		$new_price =  !$return_original_price && $current_price == $new_price ? null : $new_price ;
		return $new_price;
	}
	//it can return a numeric value in case of [ITEM] strategy or an array() in case of [CART] strategy                                                                                  //if zero, will be automatically detected
	private function get_price_by_quantity($current_price, $pricing_rule, $product, $price_strategy_to_consider, $use_variation_id = false, $other_product_ids_of_same_category = null, $product_page_quantity = 0)
	{
		global $woocommerce,$wctbp_cart_addon, $wctbp_user_model;
		$rule = $pricing_rule['rule'];
		$all_selected_product_ids = isset($pricing_rule['product_ids']) ? $pricing_rule['product_ids'] : null;
		$price_rules_by_qty = $rule["prices"];
		
		$is_variation = $product->get_type() == 'variation' ? true : false;
		$product_id = $product->get_id();
		$product_parent_id =  $is_variation ? $product->get_parent_id() : $product->get_id();
		$product_variation_id = $is_variation ? $product->get_id() : 0;
		
		$quantity_strategy = $rule["quantity_strategy"];
		$terms_and_quantities = null;
		$original_price = $current_price;
		
		global $wctbp_cart;
		$cart_contents = isset($woocommerce->cart->cart_contents) ? $woocommerce->cart->cart_contents : array();	
		
		//if quantity is passed as paramenter it means that in product page the plugin wants to show the actual product price
		//according the selected quantity in page. So cart is manipulated pretending that the product is added to it with the quantity
		//specified in product page
		if($product_page_quantity != 0)
		{
			$temp_quantity_in_cart = 0;
			$temp_existis_product_in_cart = null;
			foreach((array)$cart_contents  as $key => $item)
				if( $item["product_id"] == $product_parent_id && $item["variation_id"] == $product_variation_id )
					$temp_existis_product_in_cart = $key;
			//wctbp_var_dump(count($cart_contents));
			if(isset($temp_existis_product_in_cart))
			{
				$temp_quantity_in_cart = $cart_contents[$temp_existis_product_in_cart]['quantity'];
				unset($cart_contents[$temp_existis_product_in_cart]);
			}
			
			
			$cart_contents[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $product_page_quantity + $temp_quantity_in_cart
									);
									
			
		}
		$items_to_analyze = !empty($cart_contents) ? $cart_contents : $wctbp_cart;//WC()->cart->cart_contents;
		$found_in_cart = false;
		
		
		if($quantity_strategy == 'stock')
		{
			$items_to_analyze = array();
			$found_in_cart = true;
			if($product->managing_stock( ) )
			{
				$quantity = $product->get_stock_quantity( );
				
				$items_to_analyze[] = array( "product_id" =>$product_parent_id,
											"variation_id" => $product_variation_id,
											"quantity" => $quantity
									);
			}
			
		}
		else if($quantity_strategy == 'total_sales')
		{
			$quantity = $product->get_total_sales( );
			if($is_variation ) //total sales are only stored on parent product
			{
				$parent_product = wc_get_product($product_parent_id);
				if(is_object($parent_product))
					$quantity = $parent_product->get_total_sales( );
			}
			$items_to_analyze = array();
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $quantity
									);
									
		}
		// DOESN'T WORK, NOT  USED ANY MORE
		//Quantity: sum of cart products quantities belonging to same (selected by admin) category. Quantities are grouped by category.
		else if($rule["cumulative_category_quantity"] == 'yes' && ($quantity_strategy == 'cart' || $quantity_strategy == 'amount_spent' || $quantity_strategy == 'purchased_quantity') && isset($other_product_ids_of_same_category) && $other_product_ids_of_same_category != false)
		{
			$items_to_analyze = array();
			//ToDo: quantity -> extend to stock left value
			
			if($quantity_strategy == 'purchased_quantity')
				$terms_and_quantities = $this->get_quantities_or_amount_by_categories($all_selected_product_ids, $other_product_ids_of_same_category, $quantity_strategy );
			else
				$terms_and_quantities = $this->get_quantities_or_amount_by_categories($cart_contents, $other_product_ids_of_same_category, $quantity_strategy );
			
			
			//quantity by category option has been selected
			if(isset($terms_and_quantities))
			{
				$item_category = wp_get_post_terms($product_parent_id, 'product_cat', array("fields" => "ids"));
				$item_category = isset($item_category) && !empty($item_category) ? $item_category[0] : null;
				$category_quantity = isset($item_category) && isset($terms_and_quantities[$item_category]) ? $terms_and_quantities[$item_category] : -1;
				
				
				$items_to_analyze[] = array( "product_id" =>$product_parent_id,
											 "variation_id" =>  $product_variation_id,
											 "quantity" => $category_quantity
									);
									
				
			}
		}
		//Quantity: sum of all cart products belonging to the selected products and categories.
		else if($rule["cumulative_category_quantity"] == 'linked_products' && ($quantity_strategy == 'cart' || $quantity_strategy == 'amount_spent' || $quantity_strategy == 'purchased_quantity') && isset($all_selected_product_ids) && $all_selected_product_ids !== false)
		{
			$total_items_quantity = 0;
			$items_to_analyze = array();
			
			if($quantity_strategy == 'purchased_quantity')
			{
				foreach($all_selected_product_ids as $selected_product_id)
				{
					$total_items_quantity += $wctbp_user_model->get_purchased_quantity($selected_product_id);
				}
			}
			else
				foreach((array)$cart_contents  as $item)
				{
					if(empty($all_selected_product_ids) || (in_array($item["product_id"],  $all_selected_product_ids) || (in_array($item["variation_id"],  $all_selected_product_ids))))
					{
						if($quantity_strategy == 'amount_spent')
						{
							$wc_product_price = $this->get_wc_product_price($item["variation_id"] != 0 ? $item["variation_id"] : $item["product_id"]);
							if($wc_product_price != false)
								$total_items_quantity += $item["quantity"] * $wc_product_price;
						}
						else if($quantity_strategy == 'cart')
						{
							//ToDo: quantity -> extend to stock left value	
							$total_items_quantity += $item["quantity"];
							
						}
					}
					
				}
			
					
			//Ovverride item quantity considering sum of all linked products quantities		
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" =>  $product_variation_id,
										 "quantity" => $total_items_quantity
									);
			
			 
		}
		else if($rule["cumulative_category_quantity"] == 'no' &&  $quantity_strategy == 'purchased_quantity') //Quantity of already purchased products
		{
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $wctbp_user_model->get_purchased_quantity($product_parent_id)
									);
									
		}
		else if($rule["cumulative_category_quantity"] == 'no' &&  $quantity_strategy == 'amount_spent') //Single product spent amount
		{
			$quantity = 0;
			$items_to_analyze = array();
			foreach((array)$cart_contents  as $item)
			{
				$wc_product_price = $this->get_wc_product_price($item["variation_id"] != 0 ? $item["variation_id"] : $item["product_id"]);
				if($wc_product_price == false)
					continue;
				
				if((!$use_variation_id && $item["product_id"] == $product_parent_id) || ($use_variation_id && $item["variation_id"] == $product_variation_id))
					$quantity = $item["quantity"] * $wc_product_price;
			}
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $quantity
									);
		}
		else if($rule["cumulative_category_quantity"] == 'no' &&  $quantity_strategy == 'cart') //Sngle product cart amount
		{
			$items_to_analyze = array();
			$quantity = 0;
			foreach((array)$cart_contents  as $item)
			{
				//old rule, is still working ? if((!$use_variation_id && $item["product_id"] == $product_parent_id) || ($use_variation_id && $item["variation_id"] == $product_variation_id))
				if($use_variation_id && ($item["product_id"] == $product_parent_id && $item["variation_id"] == $product_variation_id))
				{
					$quantity = $item["quantity"];
				}
				elseif(!$use_variation_id && (($is_variation && $item["product_id"] == $product_parent_id) || 
											  !$is_variation && ($item["product_id"] == $product_parent_id && $item["variation_id"] == $product_variation_id))
											  )
				{
					$quantity +=  $item["quantity"];
					
				}
			
			}
					
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $quantity
									);
									
		}
		else if($quantity_strategy == 'cart_total')
		{
			
			$items_to_analyze[] = array( "product_id" =>$product_parent_id,
										 "variation_id" => $product_variation_id,
										 "quantity" => $woocommerce->cart->subtotal_ex_tax
									);
		}
	
		//Start analyzing items
		foreach((array)$items_to_analyze  as $item)
			{
				//wctbp_var_dump($item);
				/* Format:
				 ["prices"]=>
					  array(1) {
						[0]=>
						array(4) {
						  ["price_strategy"]=>
						  string(5) "fixed"
						  ["price_value"]=>
						  string(4) "1231"
						  ["min_quantity"]=>
						  string(1) "2"
						  ["max_quantity"]=>
						  string(0) ""
						}
					  }
				  */
				
				if((!$use_variation_id && $item["product_id"] == $product_parent_id) || ($use_variation_id && $item["variation_id"] == $product_variation_id))
				{
					$found_in_cart = true;
					foreach($price_rules_by_qty as $rule)
					{
						$rule["max_quantity"] = $rule["max_quantity"] == 0 ? "" : $rule["max_quantity"];
						$rule["min_quantity"] = $rule["min_quantity"] == 0 ? "" : $rule["min_quantity"];
						
						//In case of "Cart free item" strategy, the quantity strategy considered is alway the "single product"
						if($rule['price_strategy'] == 'cart_free_item')
						{
							if($rule["give_away_strategy"] == 'by_cart_quantity_ranges')
								$item["quantity"] = $wctbp_cart_addon->get_cart_item_by_id($product_id, $use_variation_id);
							else
							{
								$rule["max_quantity"] = 999999;
								$rule["min_quantity"] = -1;
								$item["quantity"] =  floor($wctbp_cart_addon->get_cart_item_by_id($product_id, $use_variation_id)/$rule["every_x_items_vaue"]);
								//wctbp_var_dump($item["quantity"]);
								if($item["quantity"] > 0)
								{
									$rule["max_quantity"] = $rule["min_quantity"] = "";
								}
							}
						}
					
						if(($rule["min_quantity"] == "" && $rule["max_quantity"] == "") || 
							($rule["min_quantity"] == "" && $rule["max_quantity"] != "" && $item["quantity"] <= (int)$rule["max_quantity"] ) ||
							($rule["min_quantity"] != "" && $rule["max_quantity"] == "" && $item["quantity"] >= (int)$rule["min_quantity"] ) ||
							($rule["min_quantity"] != "" && $rule["max_quantity"] != "" && $item["quantity"] >= (int)$rule["min_quantity"] && $item["quantity"] <= (int)$rule["max_quantity"]) 
							)
							{
								$this->last_pricing_info['free_shipping'] = $rule["free_shipping"];
								if( $price_strategy_to_consider == 'price' && $original_price != 'hide_price') 
								{
									$this->last_pricing_info['price_strategy'] = $rule["price_strategy"];
									$this->last_pricing_info['price_value'] = $rule["price_value"];
									switch($rule["price_strategy"])
									{
										case "fixed": $current_price =  $rule["price_value"]; 
											break;
										case "percentage":  $current_price = round($original_price*($rule["price_value"]/100), wc_get_price_decimals()); //ceil
														   
											break;
										case "value_off":   $current_price = $original_price-$rule["price_value"] < 0 ? 0 : $original_price-$rule["price_value"];
											break;
										case "value_add":  $current_price = $original_price+$rule["price_value"];
											break;
									}									
									$this->last_pricing_info['applied_price_same_of_original'] = $current_price == $original_price;
								}
								elseif( $price_strategy_to_consider == 'discount' && $original_price != 'hide_price')
								{
									switch($rule["price_strategy"])
										{
											case "cart_fixed":  $current_price = array('type' => 'cart_fixed', 
																					   'value'=>$rule["price_value"], 
																					   'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																					   'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																					   'unique_id' => $rule["unique_id"],
																					   'cart_label' => $rule["cart_label"]
																					   );
												break;
											case "cart_fixed_add_fee":   $current_price = array('type' => 'cart_fixed_add_fee', 
																					   'value'=>$rule["price_value"], 
																					   'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																					   'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																					   'unique_id' => $rule["unique_id"],
																					   'cart_label' => $rule["cart_label"]);
												break;
											case "cart_percentage":  $current_price = array('type' => 'cart_percentage', 
																						    'value'=>$rule["price_value"], 
																						    'max_discount_value_applicable'=>$rule["max_discount_value_applicable"], 
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"],
																							'cart_label' => $rule["cart_label"]);
											break;
											case "cart_percentage_add_fee":  $current_price = array('type' => 'cart_percentage_add_fee', 
																						    'value'=>$rule["price_value"], 
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"],
																							'cart_label' => $rule["cart_label"]);
												break;
											case "cart_free_item":  $current_price = array('type' => 'cart_free_item', 
																							'value'=>$rule["number_of_free_items"], 
																							'every_x_items_vaue' => $rule["every_x_items_vaue"],
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'give_away_strategy' => $rule["give_away_strategy"], 
																							'quantity' => $item["quantity"], 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"],
																							'cart_label' => $rule["cart_label"]);
										}
								}
										
							}
				
					}
				
				}
			}//end foreach
			
		//For rules with 0 values and empty cart
		if(!$found_in_cart/* count(WC()->cart->cart_contents) == 0 */)
			foreach($price_rules_by_qty as $rule)
			{
				if(($rule["min_quantity"] == "" && $rule["max_quantity"] == "")     || 
						($rule["min_quantity"] == "" || $rule["min_quantity"] == "0" )  ||
						($rule["min_quantity"] == "0" || $rule["max_quantity"] == "0" ) ||
						( $rule["min_quantity"] == "0" && $rule["max_quantity"] == "")  ||
						( $rule["min_quantity"] == "" && $rule["max_quantity"] == "0")
						)
						{
							$this->last_pricing_info['free_shipping'] = $rule["free_shipping"];
							if( $price_strategy_to_consider == 'price') 
							{
								$this->last_pricing_info['price_strategy'] = $rule["price_strategy"];
								$this->last_pricing_info['price_value'] = $rule["price_value"];
								switch($rule["price_strategy"])
								{
									case "fixed": $current_price =  $rule["price_value"];
										break;
									case "percentage": $current_price = round($original_price*($rule["price_value"]/100), wc_get_price_decimals()); //ceil
										break;
									case "value_off":  $current_price = $original_price-$rule["price_value"] < 0 ? 0 : $original_price-$rule["price_value"];
										break;
									case "value_add":  $current_price = $original_price+$rule["price_value"];
										break;
								}
							}
							elseif( $price_strategy_to_consider == 'discount') 
									switch($rule["price_strategy"])
										{
											case "cart_fixed":  $current_price = array('type' => 'cart_fixed', 
																					   'value'=>$rule["price_value"], 
																					   'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																					   'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																					   'unique_id' => $rule["unique_id"]);
												break;
											case "cart_fixed_add_fee":  $current_price = array('type' => 'cart_fixed_add_fee', 
																					   'value'=>$rule["price_value"], 
																					   'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																					   'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																					   'unique_id' => $rule["unique_id"]);
												break;
											case "cart_percentage":  $current_price = array('type' => 'cart_percentage', 
																							'value'=>$rule["price_value"], 
																							'max_discount_value_applicable'=>$rule["max_discount_value_applicable"], 
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"]);
												break;
											case "cart_percentage_add_fee":  $current_price = array('type' => 'cart_percentage_add_fee', 
																						    'value'=>$rule["price_value"], 
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"]);
												break;	
											case "cart_free_item": $current_price = array('type' => 'cart_free_item', 
																							'value'=>$rule["number_of_free_items"], 
																							'individual_usage_only' => $rule["cart_discount_individual_use_only"] == 'yes' ? true:false, 
																							'apply_discount_value_per_each_matching_item' => $rule["apply_discount_value_per_each_matching_item"] == 'yes' ? true:false, 
																							'unique_id' => $rule["unique_id"]);
										}
						}
			}
							
		return $current_price;
	}
	private function get_quantities_or_amount_by_categories($items_to_analyze, $allowed_product_ids, $quantity_strategy)
	{
		global $wctbp_user_model;
		$result = array();
		foreach((array)$items_to_analyze  as $item)
		{
			$item_id = $quantity_strategy == 'purchased_quantity' ? $item : $item["product_id"];
			if(in_array($item_id,$allowed_product_ids))
			{
				$terms = wp_get_post_terms($item_id, 'product_cat', array("fields" => "ids"));
				
				if(isset($terms) && !empty($terms))
				{
					foreach($terms as $category)
					{
						
						if( $quantity_strategy == 'amount_spent')
						{
							$wc_product_price = $this->get_wc_product_price($item["variation_id"] != 0 ? $item["variation_id"] : $item["product_id"]);
							if($wc_product_price != false)
								$result[$category] = isset($result[$category]) ? $result[$category]+($item["quantity"] * $wc_product_price ) : $item["quantity"] * $wc_product_price;
						}
						else if($quantity_strategy == 'cart')
							$result[$category] = isset($result[$category]) ? $result[$category]+$item["quantity"] : $item["quantity"];
						else 
							$result[$category] = $wctbp_user_model->get_purchased_quantity($item_id);
							
					}
				}
			}
		}
		return $result;
	}
	private function bulk_change_posts_status($posts_id, $status)
	{
		
		global $wpdb;
		$query = "UPDATE {$wpdb->posts} AS posts 
				  SET posts.post_status = '{$status}' 
				  WHERE posts.ID IN ('" . implode( "','", $posts_id). "') ";
		$result = $wpdb->get_results($query);
		
		return $result;
	}
	private function get_post_ids_using_categories($category_type_name, $selected_categories, $get_post_belonging_to_children_categories, $strategy)
	{
		
		global $wpdb;
		$not_suffix = $strategy == "all" ? "  " : " NOT ";
		$results = $additional_categories_ids = array();
		
		//Retrieve children categories id
		if($get_post_belonging_to_children_categories == 'all_children')
		{
			foreach($selected_categories as $current_category)
			{
				$args = array(
						'type'                     => 'product',
						'child_of'                 => $current_category,
						'parent'                   => '',
						'orderby'                  => 'name',
						'order'                    => 'ASC',
						'hide_empty'               => 1,
						'hierarchical'             => 1,
						'exclude'                  => '',
						'include'                  => '',
						'number'                   => '',
						'taxonomy'                 => $category_type_name,
						'pad_counts'               => false

					); 

					$categories = get_categories( $args );
					//wctbp_var_dump($categories);
					foreach($categories as $result)
					{
						if(!is_array($result))
							$additional_categories_ids[] = (int)$result->term_id;
					}
			}
		}
		if(!empty($additional_categories_ids))
			$selected_categories = array_merge($selected_categories, $additional_categories_ids);
		
		
		$wpdb->query('SET group_concat_max_len=5000000'); 
		$wpdb->query('SET SQL_BIG_SELECTS=1');
		$query = "SELECT DISTINCT posts.ID
				 FROM {$wpdb->posts} AS posts 
				 INNER JOIN {$wpdb->term_relationships} AS term_rel ON term_rel.object_id = posts.ID
				 INNER JOIN {$wpdb->term_taxonomy} AS term_tax ON term_tax.term_taxonomy_id = term_rel.term_taxonomy_id 
				 INNER JOIN {$wpdb->terms} AS terms ON terms.term_id = term_tax.term_id
				 WHERE  terms.term_id {$not_suffix} IN ('" . implode( "','", $selected_categories). "')  
				 AND term_tax.taxonomy = '{$category_type_name}' "; 
		$ids = $wpdb->get_results($query, ARRAY_A);
	
		if(isset($ids) && is_array($ids))
			foreach($ids as $id)
				$results[] = $id['ID'];
		return $results;
	}
	private function get_complementry_ids($ids_to_exclude, $post_type = "product")
	{
		global $wpdb;
		$results = array();
		$query = "SELECT posts.ID 
				  FROM {$wpdb->posts} AS posts
				  WHERE posts.post_type = '{$post_type}' 
				  AND posts.ID NOT IN('".implode("','",$ids_to_exclude)."') ";
		$ids = $wpdb->get_results($query, ARRAY_A);
		foreach($ids as $id)
			$results[] = (int)$id['ID'];
		return $results;
	}
	public function get_product_specific_price_rules($product_id)
	{
		global $wctbp_wpml_helper;
		$return_value = null;
		if($wctbp_wpml_helper->is_active())
			$product_id = $wctbp_wpml_helper->get_original_id($product_id);
		
		$time_period = array();
					
		if( have_rows('wctbp_time_period', $product_id) )
			while ( have_rows('wctbp_time_period', $product_id) ) 
			{
				the_row();
				$temp_date = array(	'quantity_strategy' => get_sub_field('wctbp_quantity_strategy'),
									'stack_rule' => get_sub_field('wctbp_stack_rule'),
									'ignore_rule_if_coupon_is_active' => get_sub_field('wctbp_ignore_rule_if_coupon_is_active'),
									'rule_name_id' => get_sub_field('wctbp_rule_name'),
									'user_accounts' => get_sub_field('wctbp_user_account'),
									'user_roles' => get_sub_field('wctbp_user_roles'),
									'user_filtering_strategy' => get_sub_field('wctbp_user_roles_filtering_strategy'), //all / except
									'hide_price_for_unauthorized_users' => get_sub_field('wctbp_hide_price_for_unauthorized_users'), //yes / no
									'selected_product_variants' => get_sub_field('wctb_selected_product_variants'), 
									'day_type' => get_sub_field('wctbp_day_type'),
									'days_of_the_week' => get_sub_field('wctbp_days_of_the_week'),
									'days_of_the_month' => get_sub_field('wctbp_days_of_the_month'),
									'months' => get_sub_field('wctbp_months'),
									'years' => get_sub_field('wctbp_years'),
									'start_hour' => get_sub_field('wctbp_start_hour'),
									'start_minute' => get_sub_field('wctbp_start_minute'),
									'use_end_time' =>  "yes",// "yes" or "no"
									'end_hour' => get_sub_field('wctbp_end_hour'),
									'end_minute' => get_sub_field('wctbp_end_minute'),
									'prices' => array()
									  );
				
				//New fields, force default value for backward compatibility
				$temp_date['user_accounts'] = $temp_date['user_accounts'] ? $temp_date['user_accounts']  : array();
				$temp_date['ignore_rule_if_coupon_is_active'] = $temp_date['ignore_rule_if_coupon_is_active'] ? $temp_date['ignore_rule_if_coupon_is_active']  : false;
				$temp_date['stack_rule'] = $temp_date['stack_rule'] ? $temp_date['stack_rule']  : 'no';
				$temp_date['stack_rule'] = is_array($temp_date['stack_rule']) ? 'no' : $temp_date['stack_rule'];
				$temp_date['user_roles'] = $temp_date['user_roles'] ? $temp_date['user_roles']  : array();
				$temp_date['user_filtering_strategy'] = $temp_date['user_filtering_strategy'] ? $temp_date['user_filtering_strategy']  : 'all';
				$temp_date['hide_price_for_unauthorized_users'] = $temp_date['hide_price_for_unauthorized_users'] ? $temp_date['hide_price_for_unauthorized_users']  : 'no';
				$temp_date['quantity_strategy'] = $temp_date['quantity_strategy'] ? $temp_date['quantity_strategy']  : 'cart';							  
				$temp_date['selected_product_variants'] = $temp_date['selected_product_variants'] ? $temp_date['selected_product_variants']  : array();		// array(2) { [0]=> string(3) "901" [1]=> string(3) "902" } 					  
				$temp_date['cumulative_category_quantity'] = 'no';
				
				if( have_rows('wctbp_prices_per_quantity') )
					while ( have_rows('wctbp_prices_per_quantity') ) 
					{
						the_row();
						$temp_price_strategy = array('price_strategy' => get_sub_field('wctbp_price_strategy'), // fixed || percentage || value_off || value_add || cart_fixed || cart_percentage_add_fee || cart_fixed_add_fee || cart_percentage || cart_free_item 
													 'price_value' => get_sub_field('wctpb_price_value'),
													 'cart_label' => get_sub_field('wctpb_cart_label'),
													 'free_shipping' => get_sub_field('wctbp_free_shipping'),
													 'max_discount_value_applicable' => get_sub_field('wctbp_max_discount_value_applicable'),
													 'apply_discount_value_per_each_matching_item' => get_sub_field('wctpb_apply_discount_value_per_each_matching_item'),
													 'min_quantity' => get_sub_field('wctpb_min_quantity'),
													 'max_quantity' => get_sub_field('wctpb_max_quantity'),
													 'unique_id' => get_sub_field('wctbp_unique_id'),
													 'cart_discount_individual_use_only' => get_sub_field('wctbp_cart_discount_individual_use_only'),
													 'give_away_strategy' => get_sub_field('wctbp_give_away_strategy'), // by_cart_quantity_ranges || every_x_items
													 'number_of_free_items' => get_sub_field('wctpb_number_of_free_items'),
													 'every_x_items_vaue' => get_sub_field('wctbp_every_x_items_vaue')
													);
													
						//New fields, force default value for backward compatibility
						$temp_price_strategy['unique_id'] = $temp_price_strategy['unique_id'] ? $temp_price_strategy['unique_id'] : rand(123, 9999999999);
						$temp_price_strategy['free_shipping'] = $temp_price_strategy['free_shipping'] ? $temp_price_strategy['free_shipping'] : false;
						$temp_price_strategy['cart_discount_individual_use_only'] = $temp_price_strategy['cart_discount_individual_use_only'] ? $temp_price_strategy['cart_discount_individual_use_only'] : 'no';
					    $temp_price_strategy['give_away_strategy'] = $temp_price_strategy['give_away_strategy'] ? $temp_price_strategy['give_away_strategy'] : 'by_cart_quantity_ranges';
						$temp_price_strategy['number_of_free_items'] = $temp_price_strategy['number_of_free_items'] ? $temp_price_strategy['number_of_free_items'] : 1;
						$temp_price_strategy['every_x_items_vaue'] = $temp_price_strategy['every_x_items_vaue'] ? $temp_price_strategy['every_x_items_vaue'] : 1;
						$temp_price_strategy['apply_discount_value_per_each_matching_item'] = $temp_price_strategy['apply_discount_value_per_each_matching_item'] ? $temp_price_strategy['apply_discount_value_per_each_matching_item'] : 'no';
						$temp_price_strategy['max_discount_value_applicable'] = $temp_price_strategy['max_discount_value_applicable'] ? $temp_price_strategy['max_discount_value_applicable'] : 0;
									
						$temp_date['prices'][]	= $temp_price_strategy;					
					}
				$time_period[] = $temp_date;
			} 
	
		$return_value = $time_period;
				
		return  $return_value;
	}
	function get_product_quantity_in_cart($product)
	{
		$is_variation = $product->get_type() == 'variation' ? true : false;
		$product_id = $product->get_id();
		$product_parent_id =  $is_variation ? $product->get_parent_id() : $product->get_id();
		$product_variation_id = $is_variation ? $product->get_id() : 0;
		
		foreach((array)$woocommerce->cart->cart_contents  as $key => $item)
				if( $item["product_id"] == $product_parent_id && $item["variation_id"] == $product_variation_id)
					return $item['quantity'];
				
		return 0;
	}
	public function get_purchased_quantity($customer_id)
	{
		$args = array(
				'customer_id' => $customer_id,
		);
		$orders = wc_get_orders( $args );
	}
}
?>
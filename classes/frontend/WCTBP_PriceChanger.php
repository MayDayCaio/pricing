<?php 
class WCTBP_PriceChanger
{
	var $filter_woocommerce_shortcode_products_query_cache = null;
	var $total_saved = 0;
	var $total_saved_already_computed_items = array();
	var $modifing_cart_item_price = false;
	var $free_shipping_items = array();
	public function __construct()
	{
		add_action( 'wp_loaded', array(&$this, 'add_filters') );			
		add_filter('woocommerce_get_price_html', array(&$this, 'modify_html_price'), 10, 2 );
		add_filter('woocommerce_cart_item_price', array(&$this, 'modify_cart_html_row_price'), 10, 3 ); 
		//Sale badge
		add_filter( 'woocommerce_product_is_on_sale', array(&$this,'show_sale_badge'), 10, 2 ); 
		add_filter( 'woocommerce_sale_flash', array(&$this,'filter_woocommerce_sale_flash'), 10, 3 ); 
	
		//Ajax
		add_action('wp_ajax_nopriv_wctbp_update_price', array(&$this, 'ajax_update_price'));
		add_action('wp_ajax_wctbp_update_price', array(&$this, 'ajax_update_price'));
		
		//seach (?) and sale_products query 
		add_filter( 'woocommerce_shortcode_products_query', array(&$this,'filter_woocommerce_shortcode_products_query'), 99, 3 );
		
		//Free shippung
		add_filter( 'woocommerce_product_needs_shipping', array( $this, 'set_free_shipping_for_elegible_product' ), 10, 2 );
		
	}
	public function add_filters()
	{
		add_filter('woocommerce_product_get_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		add_filter('woocommerce_product_get_regular_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		add_filter('woocommerce_product_variation_get_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		add_filter('woocommerce_product_variation_get_regular_price', array(&$this, 'modify_product_price'), 12, 2 ); 
	}
	function remove_filters()
	{
		remove_filter('woocommerce_product_get_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		remove_filter('woocommerce_product_get_regular_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		remove_filter('woocommerce_product_variation_get_price', array(&$this, 'modify_product_price'), 12, 2 ); 
		remove_filter('woocommerce_product_variation_get_regular_price', array(&$this, 'modify_product_price'), 12, 2 ); 
	}
	//Free shipping
	public function set_free_shipping_for_elegible_product( $needs_shipping, $product )
	{
		 global $woocommerce, $wcps_product_model, $wctbp_cart_addon;
		
		if(!isset($woocommerce) || $woocommerce->cart == null)
			return $needs_shipping;
		
		$cart_items = $woocommerce->cart->get_cart_contents(); //ok
		$cart_discount_items = $wctbp_cart_addon->get_free_shipping_items();
		$price_change_items = $this->get_free_shipping_items();
		
		foreach ( $cart_items as $key => $item ) //why for each one? I do not remember...
		{
			if(isset($cart_discount_items[$product->get_id()]) || isset($price_change_items[$product->get_id()]))
			{
				$needs_shipping = false;
			}
			
		}
		  return $needs_shipping;
	}
	public function set_free_shipping_for_elegible_item( $is_available ) 
	{
		global $woocommerce, $wcps_product_model, $wctbp_cart_addon;
		$cart_items = $woocommerce->cart->get_cart_contents();
		
		$cart_discount_items = $wctbp_cart_addon->get_free_shipping_items();
		$price_change_items = $this->get_free_shipping_items();
		
		foreach ( $cart_items as $key => $item ) 
		{
			$item_id = isset($item['variation_id']) && $item['variation_id'] != 0 ? $item['variation_id'] : $item['product_id'];
			if(isset($cart_discount_items[$item_id]) || isset($price_change_items[$item_id]))
			{
				 $item['data']->set_virtual(true);
			}
			
		}
		
		return $is_available;
	}
	public function cart_update_validation($original_result, $cart_item_key, $values, $quantity )
	{
		global $woocommerce, $wcps_product_model, $wctbp_cart_addon;
		
		$cart_discount_items = $wctbp_cart_addon->get_free_shipping_items();
		$price_change_items = $this->get_free_shipping_items();
		$items = WC()->cart->cart_contents;
		if(isset($items[$cart_item_key]))
		{
			$item_id = $items[$cart_item_key]['variation_id'] != 0 ? $items[$cart_item_key]['variation_id'] : $items[$cart_item_key]['product_id'];
			if(isset($cart_discount_items[$item_id]) || isset($price_change_items[$item_id]))
			{
				$items[$cart_item_key]['data']->set_virtual(true);
			}
		}
		return $original_result;
	}
	
	private function getTextBetweenTags($string, $tagname) {
		$pattern = "/<$tagname ?.*>(.*)<\/$tagname>/";
		preg_match($pattern, $string, $matches);
		return isset($matches[1]) ? $matches[1] : $string;
	}
	function filter_woocommerce_shortcode_products_query( $query_args, $atts, $loop_name = '' ) 
	{ 
		global $wctbp_product_model, $wctbp_option_model;
		
		//optionally, add "$wctbp_option_model->get_option('wctpb_display_sale_badge', 'yes') != 'yes' ||" to avoid product to be displayed in case the sale badge option display is set to true
		if( $loop_name != 'sale_products' || @is_shop() || @is_product())
			return $query_args;
		
		
		
		$all_products = $wctbp_product_model->get_products_with_pricing_rules_applied(null);
		$var_counter = 0;
		if(!isset($query_args["posts_per_page"]))
			$query_args["posts_per_page"] = 12;
		if(!isset($this->filter_woocommerce_shortcode_products_query_cache))
		{
			$this->filter_woocommerce_shortcode_products_query_cache = array();
			if(isset($all_products) && is_array($all_products) && !empty($all_products))
			{
				foreach($all_products as $product)
				{
					$tmp_product = wc_get_product($product->id);
					$is_on_sale = false;
					if($this->show_sale_badge(false, $tmp_product))
					{
						$query_args["post__in"][] = $product->id;
						$this->filter_woocommerce_shortcode_products_query_cache[] = $product->id;
						
					}
					if($is_on_sale)
						if($var_counter++ == $query_args["posts_per_page"])
								break;
				}
			}
		}
		else 
			foreach((array)$this->filter_woocommerce_shortcode_products_query_cache as $cached_id)
				$query_args["post__in"][] = $cached_id;
				
		return $query_args; 
	}
	function ajax_update_price()
	{
		global $wctbp_product_model, $wctbp_option_model,  $wctbp_text_model;
		$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
		$product_id = isset($_POST['product_id']) ? $_POST['product_id'] : null;
		$variation_id = isset($_POST['variation_id']) && $_POST['variation_id'] != 'undefined' ? $_POST['variation_id'] : null;
		$product = $variation_id != null ? wc_get_product($variation_id)  : wc_get_product($product_id);
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		
		$old_price = $prices_entered_include_tax != 'yes' ? $product->get_price('numeric') : WCTBP_Tax::get_product_price_excluding_tax($product);
		
		$new_price = $wctbp_product_model->get_new_price_or_discount_rule($old_price, $product, isset($variation_id), 'price', $quantity, false);
		if(!isset($new_price) || $new_price == false)
		{
			$new_price = $product->get_price('numeric');
			$html = wc_price(WCTBP_Tax::get_product_price_with_tax_according_settings($product));
		}
		else
		{
			$html = $this->modify_html_price($product->get_price('numeric'), $product, $quantity, true);
		}
		
		if($quantity > 1 && $wctbp_option_model->get_option('wctbp_display_total_price_next_single_price', 'yes') == 'yes')
		{
			$text = $wctbp_text_model->get_texts();
			$new_price = $product->get_tax_status() == 'taxable' ? WCTBP_Tax::get_product_price_including_tax($product, 1, $new_price) : $new_price;
			$new_price = $new_price;
			$html .= $text['product_page_before_total_price_text'].wc_price(($new_price*$quantity));
		}
		echo $html;
			
		wp_die();
	}
	function filter_woocommerce_sale_flash($html_text, $post, $product)
	{
		global $wctbp_text_model;
		$texts = $wctbp_text_model->get_texts();
		if($texts['sale_badge_text'] != "")
			$html_text = '<span class="onsale">' . $texts['sale_badge_text'] . '</span>';
		return $html_text; 
	}
	function show_sale_badge( $is_on_sale, $product ) 
	{ 
		global  $wctbp_product_model, $wctbp_option_model;	
		$display_badge = $wctbp_option_model->get_option('wctpb_display_sale_badge', 'yes');
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		
		if($display_badge === 'no')
			return $is_on_sale;
		
		if(is_a($product, 'WC_Product_Variable'))
		{
			$old_numeric_min = $product->get_variation_regular_price();
			$old_numeric_max = $product->get_variation_regular_price('max');
			$result = $wctbp_product_model->get_min_max_price_variations($product->get_id());
			
			/* There is no need. Uncomment if needed:
			if($prices_entered_include_tax == 'yes' )
			{
				$old_numeric_min = WCTBP_Tax::get_product_price_excluding_tax($product, 1, $old_numeric_min) ;
				$old_numeric_max = WCTBP_Tax::get_product_price_excluding_tax($product, 1, $old_numeric_max) ;
			} */
			
			if( $result && ($result['min'] != "" && $result['min'] < $old_numeric_min)  || ($result['max'] != "" && $result['max'] < $old_numeric_max))
				return true;
		}
		else 
		{
			//There is no need. Uncomment if neede: $old_price = $prices_entered_include_tax != 'yes' ? $product->get_price('numeric') : WCTBP_Tax::get_product_price_excluding_tax($product);
			$old_price =  $product->get_price('numeric') ;
			$price = $this->modify_product_price($old_price, $product);
			if($price < $old_price) 
				return true;
		}
		
		return $is_on_sale;
	}
  
	function modify_cart_html_row_price($price, $cart_item = null, $cart_item_key = null)
	{
		if($cart_item == null || $cart_item_key == null)
		    return $price;
		
		return $this->modify_html_price($price, $cart_item['data'], 0, false, true);
	}
	function get_total_saved()
	{
		return $this->total_saved;
	}
	function get_free_shipping_items()
	{
		return $this->free_shipping_items;
	}
	function modify_html_price($price, $product, $quantity = 0, $is_ajax = false, $is_cart = false) 
	{
		if(is_admin() && !$is_ajax)
			return $price; 
		
		global $wctbp_product_model,$wctbp_option_model, $wctbp_text_model;
		$additional_texts = $wctbp_text_model->get_texts();
		$display_tax = !$is_cart ? get_option('woocommerce_tax_display_shop') : get_option('woocommerce_tax_display_cart');
		$page_type = $is_cart ? 'cart' : 'shop';
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		$price_display_suffix = get_option('woocommerce_price_display_suffix');
		$price_display_suffix = $display_tax == 'incl' && !$is_cart ? $price_display_suffix : "";
		$product_page_discount_percentage_text = $additional_texts['product_page_discount_percentage_text'];
		$shop_page_discount_percentage_text = $additional_texts['shop_page_discount_percentage_text'];
		$shop_page_discount_percentage_variable_product_text = $additional_texts['shop_page_discount_percentage_variable_product_text'];
		$show_percentage_discount_on_shop_page = $wctbp_option_model->get_option('wctbp_shop_page_display_discount_percentage_next_single_price', 'yes') == 'yes' ? true : false;
		$show_percentage_discount_on_product_page = $wctbp_option_model->get_option('wctbp_display_discount_percentage_next_single_price', 'yes') == 'yes' ? true : false;
		$is_variable_product = false;
		
		if(!$is_cart && $is_ajax) //product_page
		{
			$page_type = 'product';
			$price_display_suffix .= $additional_texts['product_page_after_price_text'];
		}
		
		$display_old_price = $wctbp_option_model->get_option('wctpb_display_old_price','yes');
		$display_badge = $wctbp_option_model->get_option('wctpb_display_sale_badge', 'yes');
		$display_items_price_without_tax = $wctbp_option_model->get_option('wctpb_display_items_price_without_tax','no');
		$old_price = $price;
		$original_html_price = $price;
		$old_variable_product_show_price_method = $wctbp_option_model->get_option('wctpb_use_alternative_variable_product_reange_price_display', 'no');
		$total_price = null;
        $total_old_price = null;
		$is_sale_price = false;
		$discount_applied = 0;
		$discount_applied_string = "";
		
		//$price = HTML String ---> €1,00–€3,00  get_woocommerce_currency_symbol
		if(is_a($product, 'WC_Product_Variable'))
		{
			$is_variable_product = true;
			
			$result = $wctbp_product_model->get_min_max_price_variations($product->get_id(), $quantity);
			if(!$result)
				return $price;
			
			if($result['hide_price'])
				return "";
			$old_numeric_min = $product->get_variation_price(); //regular price tiene anche conto dello sconto
			$old_numeric_max = $product->get_variation_price('max');
				
		
			if( $result['min'] < $old_numeric_min  || $result['max'] < $old_numeric_max)
			{
				$is_sale_price = true;
				$discount_percentage_min = $result['min'] < $old_numeric_min ? round ((1 - $result['min'] / $old_numeric_min) * 100) : 0;
				$discount_percentage_max = $result['max'] < $old_numeric_max ? round ((1 - $result['max'] / $old_numeric_max) * 100) : 0;
				
				//Discount display for variable product
				if($discount_percentage_min >= $discount_percentage_max && $discount_percentage_min != 0 && 
					isset($result['min_rule_info']) && isset($result['min_rule_info']['price_strategy']))
				{
					if( $result['min_rule_info']['price_strategy'] == 'value_off')
					{
						$discount_applied =  $result['min_rule_info']['price_value'] ;
						$discount_applied_string =  wc_price($discount_applied) ;
					}
					elseif($result['min_rule_info']['price_strategy'] == 'percentage' )
					{
						$discount_applied =  $discount_percentage_min ;
						$discount_applied_string =  $discount_applied."%" ;
					}
				}
				else if($discount_percentage_max >= $discount_percentage_min && $discount_percentage_max != 0 && 
						isset($result['min_rule_info']) && isset($result['min_rule_info']['price_strategy']))
				{
					if( $result['max_rule_info']['price_strategy'] == 'value_off')
					{
						$discount_applied =  $result['max_rule_info']['price_value'] ;
						$discount_percentage_max =  wc_price($discount_applied) ;
					}
					elseif($result['min_rule_info']['price_strategy'] == 'percentage' )
					{
						$discount_applied =  $discount_percentage_max ;
						$discount_applied_string =  $discount_applied."%" ;
					}
				}
			}
			
			if($display_items_price_without_tax == 'no')
			{
				$tax = false;
				$old_numeric_min = $display_tax == 'incl' && $product->get_tax_status() == 'taxable' ? WCTBP_Tax::get_product_price_including_tax($product, 1, $old_numeric_min) : $old_numeric_min;
				$old_numeric_max = $display_tax == 'incl' && $product->get_tax_status() == 'taxable' ? WCTBP_Tax::get_product_price_including_tax($product, 1, $old_numeric_max) : $old_numeric_max ;
				$result['min'] = $display_tax == 'incl' && $product->get_tax_status() == 'taxable' ? WCTBP_Tax::get_product_price_including_tax($product, 1, $result['min']) : $result['min'] ;
				$result['max'] = $display_tax == 'incl' && $product->get_tax_status() == 'taxable' ? WCTBP_Tax::get_product_price_including_tax($product, 1, $result['max']) : $result['max'] ; 
			}
			
			
			$old_price =  wc_price($old_numeric_min). " - ".wc_price($old_numeric_max);
			$price = wc_price($result['min']). " - ".wc_price($result['max']);
			$old_price = $old_numeric_min != $old_numeric_max ? wc_price($old_numeric_min). " - ".wc_price($old_numeric_max) : wc_price($old_numeric_max);
			//price html manipulation
			$old_price = !isset($old_variable_product_show_price_method) || $old_variable_product_show_price_method !== 'yes' ? $old_price : wc_price($old_numeric_min);
			$price = !isset($old_variable_product_show_price_method) || $old_variable_product_show_price_method !== 'yes' ? $price : '<span class="wctbp_variable_price_from from">'.__("From: ","woocommerce-time-based-pricing").' </span>'.wc_price($result['min']);
			
			if($old_numeric_min == $result['min'] && $old_numeric_max == $result['max'])
				return $original_html_price;
			//In case it was free and still is free no html price manipulation
			if( $old_numeric_min == 0 && $old_numeric_max ==  0 && $result['min'] ==  0 && $result['min'] == 0)
				return ""; //"Free!" text
			if( wc_price($result['min']) == wc_price($result['max']))
					$price = wc_price($result['min']);
				
		} 
		else if($display_old_price == 'yes' || $display_badge == 'yes' || $show_percentage_discount_on_shop_page || $is_ajax) //Simple and variation
		{
			$tax = WCTBP_Tax::get_product_price_excluding_tax($product) != 0 ? WCTBP_Tax::get_product_price_including_tax($product)/WCTBP_Tax::get_product_price_excluding_tax($product) : null;
			
			$original_price = $wctbp_product_model->get_product_price($product->get_id());
			
			if($prices_entered_include_tax == 'yes' )
				$original_price = isset($tax) && $tax != 0  ? $original_price/$tax : $original_price; //For some reasons, if price is retrieved get_product_price_excluding_tax() the taxes are removed two times
			
			$temp_price =  isset($original_price) ? $wctbp_product_model->get_new_price_or_discount_rule($original_price, $product,  $product->get_type() == 'variation' , 'price', $quantity, false) : 0;
			
			if(isset($temp_price) && $temp_price == 'hide_price')
				return "";
			
			if( is_numeric($temp_price)) //if is numeric is a new price
			{
				if($prices_entered_include_tax == 'yes' )
				{
					
				}
			
			
				if($temp_price < $original_price) 
				{
					$is_sale_price = true;
					$tmp_discount_applied = round ((1 - $temp_price/$original_price) * 100);
					$last_applied_pricing_rule_info = $wctbp_product_model->get_last_pricing_rule_applied_info() ;
					
					if($last_applied_pricing_rule_info['price_strategy'] == 'value_off')
					{
						$discount_applied =  $last_applied_pricing_rule_info['price_value'] ;
						$discount_applied_string =  wc_price($discount_applied) ;
					}
					else if($last_applied_pricing_rule_info['price_strategy'] == 'percentage')
					{
						$discount_applied =  $tmp_discount_applied ;
						$discount_applied_string =  $tmp_discount_applied."%" ;
					}
				}
				
				//In case it was free and still is free no html price manipulation
				if( $original_price == 0 && $temp_price == 0)
					return  $is_ajax ? wc_price($old_price): $old_price;
			
				$old_price = $original_price;
				
				if($display_items_price_without_tax == 'no')
				{
					$total_price = $display_tax == 'incl' && isset($tax) && $tax != 0 ? ($temp_price*$tax*$quantity): ($temp_price*$quantity);
					$total_old_price = $display_tax == 'incl' && isset($tax) && $tax != 0 ? ($old_price*$tax*$quantity): ($old_price*$quantity);
					$price = $display_tax == 'incl' && isset($tax) && $tax != 0 ? wc_price($temp_price*$tax): wc_price($temp_price);
					$old_price = $display_tax == 'incl' && isset($tax) && $tax != 0 ? wc_price($old_price*$tax): wc_price($old_price);  
				  
				}
				else
				{
					$total_price =  ($temp_price*$quantity);
					$total_old_price = isset($tax) && $tax != 0 ? ($old_price*$tax*$quantity): ($old_price*$quantity);
					$price =  wc_price($temp_price);
					$old_price =  wc_price($old_price);
				}
			}
			else //In case there is not price change for Variation/Simple product
			{
				return $is_ajax ? wc_price($original_html_price): $original_html_price;
				
				
			}
		}
		
		
		//Discount display
		if($is_sale_price)
		{
			if($page_type == 'shop' && $discount_applied != 0 && $show_percentage_discount_on_shop_page)
			{
				if(!$is_variable_product)
					$price_display_suffix.= $shop_page_discount_percentage_text != "" ? " ".sprintf($shop_page_discount_percentage_text,$discount_applied_string) : "";
				else
					$price_display_suffix.= $shop_page_discount_percentage_variable_product_text != "" ? " ".sprintf($shop_page_discount_percentage_variable_product_text,$discount_applied_string) : "";
			}
			else if($page_type == 'product' && $discount_applied != 0 && $show_percentage_discount_on_product_page && $product_page_discount_percentage_text != "")
			{
				$price_display_suffix.= " ".sprintf($product_page_discount_percentage_text,$discount_applied_string);
			}
		}
		
		$temp_html_old_price = $this->getTextBetweenTags($old_price, 'span') != "" ? $this->getTextBetweenTags($old_price, 'span') : $old_price;
		$temp_html_price = $this->getTextBetweenTags($price, 'span') != "" ? $this->getTextBetweenTags($price, 'span') : $price;
		if(($display_old_price == 'yes') && ($temp_html_price !=  $temp_html_old_price ))
		{
			//Price is changed and old price is displayed
			$price = $this->format_price($price, $total_price, $old_price, $total_old_price); //MAIN
			
			return $price.$price_display_suffix ;
		}
		
		if(isset($temp_price) && $temp_price == 'hide_price')
			$price = "";
		 
		 
		  //No price change
		 if($price !="" && strpos($price, get_woocommerce_currency_symbol()) === false) 
		 {
			return $this->format_price($price, $total_price).$price_display_suffix;//wc_price($price);
		 }
		 else //Old price is not dispayed and price has changed
		 {
			if ($price == '') 
					return '';
			else if (is_numeric($price)) //Quantity change on product cart (JS) without applying any rule, so price is numeric
			{
				
				return $this->format_price(($price), ($price * $quantity)).$price_display_suffix;
			}
			else 
			{	
				return $this->format_price($price, $total_price).$price_display_suffix; //MAIN
			}
				
		 }
		
	}
	function modify_product_cart_price($price, $product, $cart_item_key)
	{
		global $wctbp_cart;
		$wctbp_cart = WC()->cart->get_cart();
		return $this->modify_product_price($price, $product);
	}
	function modify_product_price($price, $product) 
	{
		global $wctbp_cart_addon, $wctbp_product_model;
		
		if(is_admin() || $this->modifing_cart_item_price)
			return $price;
		
		$tax = 1;
		$prices_entered_include_tax = get_option('woocommerce_prices_include_tax');
		
		//WooTheme Product Addon: it already adds the modified price to product meta. If so, price is not recomputed again
		if(method_exists($product , 'get_changes'))
		{
			$changes = $product->get_changes(); //This containes the changes added by Product Addon
			if(isset($changes) && isset($changes['price'])) 
			{
				return $price;
			}
		}
		
		 global $wctbp_product_model;
		
		 if($prices_entered_include_tax == 'yes' )
		 {
			 $this->remove_filters();
			 $price = WCTBP_Tax::get_product_price_excluding_tax($product) ;
			 $tax = $price != 0 ? WCTBP_Tax::get_product_price_including_tax($product)/$price : $tax;
			 $this->add_filters();
		 }
		 
		$new_price = $wctbp_product_model->get_new_price_or_discount_rule($price, $product,  $product->get_type() == 'variation', 'price', 0, false); //Without vat
		
		if(isset($new_price) && $new_price === 'hide_price')
		{
			return "";
		}
			
		if(is_numeric($new_price) && !isset($this->total_saved_already_computed_items[$product->get_id()]))
		{
			$cart_quantity = $wctbp_cart_addon->get_cart_quantity_by_product($product);
			$price_rule_metadata = $wctbp_product_model->get_last_pricing_rule_applied_info();
			
			if(isset($price_rule_metadata['free_shipping']) && $price_rule_metadata['free_shipping'])
					$this->free_shipping_items[$product->get_id()] = true;
				
			if($cart_quantity > 0)
			{
				$this->total_saved_already_computed_items[$product->get_id()] = true;
				$difference = ($price - $new_price );
				$this->total_saved += $difference  * $cart_quantity > 0 ? WCTBP_Tax::get_product_price_including_tax($product, 1, $difference)  * $cart_quantity : 0;
			}
			
		}
		$price = $price ? $price : 0;
		return is_numeric($new_price) ? $new_price*$tax  :  $price*$tax; //check 0 new price
	}
	 private function format_price($price, $total_price = null, $old_price = null, $total_old_price = null) {
       
  	    $output = "";
       
		
        if ($old_price && ($price != $old_price)) {
            $output.='<span style="color:#c3c3c3; text-decoration: line-through;">' . strip_tags($old_price) . '</span> ';
        }

         $output.= is_numeric($price) ?  wc_price($price) : $price;
       

        return $output;
    }
}
?>
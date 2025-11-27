<?php 
class WCTBP_TextCustomizerPage
{
	public function __construct()
	{
		$this->init_options_menu();
	}
	function init_options_menu()
	{
		if( function_exists('acf_add_options_page') ) 
		{
			
			 acf_add_options_sub_page(array(
				'page_title' 	=> 'Pricing! Texts',
				'menu_title'	=> 'Pricing! Texts',
				'parent_slug'	=> 'woocommerce-time-based-pricing',
			));
			
		}
	}
	/**
	 * Force ACF to use only the default language on some options pages
	 */
	function cl_set_global_options_pages($current_screen) 
	{
	  if(!is_admin())
		  return;
	  
	  $page_ids = array(
		"wctbp_ticket_page_acf-options-ticket-system-texts"
	  );
	 
	  
	}
	

	function cl_acf_set_language() 
	{
	  return acf_get_setting('default_language');
	}
}
?>
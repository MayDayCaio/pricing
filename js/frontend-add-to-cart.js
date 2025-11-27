"use strict";

function wctbp_remove_add_to_cart(event)
{
	event.stopImmediatePropagation();
	event.preventDefault();
	return false;
}


jQuery('.single_add_to_cart_button').css('display', 'none');
jQuery('.cart').css('display', 'none');
jQuery('.cart').css('opacity', 0);
jQuery('.qty-cart').css('display', 'none');
jQuery('.qty-cart').css('opacity', 0);
jQuery('.single_add_to_cart_button').click(wctbp_remove_add_to_cart);

jQuery(document).ready(function()
{	
	jQuery('.qty-cart').remove();
	jQuery('.single_add_to_cart_button').remove();
});
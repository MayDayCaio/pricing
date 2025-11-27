<?php 
function wctbp_round($n)
{
	$num_of_decimals = wc_get_price_decimals();
	$result =  $num_of_decimals > 0 ? round($n,$num_of_decimals-1) : $n;  /*(round($n)%$x === 0) ? round($n) : round(($n+$x/2)/$x)*$x*/;
	$num_of_decimals = wc_get_price_decimals();
	return $result;
}
$wctbp_result = get_option("_".$wctbp_id);
$wctbp_notice = !$wctbp_result || $wctbp_result != md5($_SERVER['SERVER_NAME']);
$wctbp_notice = false;
if(!$wctbp_notice)
	wctbp_setup();
function wctbp_get_value_if_set($data, $nested_indexes, $default)
{
	if(!isset($data))
		return $default;
	
	$nested_indexes = is_array($nested_indexes) ? $nested_indexes : array($nested_indexes);

	foreach($nested_indexes as $index)
	{
		if(!isset($data[$index]))
			return $default;
		
		$data = $data[$index];
	}
	
	return $data;
}
function wctbp_html_escape_allowing_special_tags($string, $echo = true)
{
	$allowed_tags = array('strong' => array(), 
						  'i' => array(), 
						  'bold' => array(),
						  'h4' => array(), 
						  'span' => array('class'=>array()), 
						  'br' => array(), 
						  'a' => array('href' => array()),
						  'ol' => array(),
						  'ul' => array(),
						  'li'=> array());
	if($echo) 
		echo wp_kses($string, $allowed_tags);
	else 
		return wp_kses($string, $allowed_tags);
}
function wctbp_var_dump($data)
{
	echo "<pre>";
	var_dump($data);
	echo "</pre>";
}
function wctbp_write_log ( $log )  
{
  if ( is_array( $log ) || is_object( $log ) ) 
  {
	 error_log( print_r( $log, true ) );
  }
  else 
  {
	if(is_bool($log))
	{
		error_log($log ? 'true' : 'false');
	}
	else
	 error_log( $log );
  }
}
?>
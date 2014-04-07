<?php
/**
 * Simpla CMS
 * 
 * @link         http://rlab.com.ua
 * @author       OsBen
 * @mail         php@rlab.com.ua
 *
 * Оплата через Казначей (http://kaznachey.ua/)
 *
 */

// Работаем в корневой директории
chdir ('../../');
require_once('api/Simpla.php');
$simpla = new Simpla();

if($simpla->request->method('post') && isset($_POST['Payment']))
{
	$SelectedPaySystemId 	= $simpla->request->post('SelectedPaySystemId');
	$order_id 				= $simpla->request->post('order_id');
	$order 					= $simpla->orders->get_order(intval($order_id));

	// Проверяем заказ
	if(empty($order))
		die('Оплачиваемый заказ не найден');

	// Получаем список покупок
	$purchases = $simpla->orders->get_purchases(array('order_id'=>intval($order->id)));
	if(!$purchases)
		return false;

	$products_ids = array();
	$variants_ids = array();
	foreach($purchases as $purchase)
	{
		$products_ids[] = $purchase->product_id;
		$variants_ids[] = $purchase->variant_id;
	}

	$products = array();
	foreach($simpla->products->get_products(array('id'=>$products_ids)) as $p)
		$products[$p->id] = $p;
			
	$images = $simpla->products->get_images(array('product_id'=>$products_ids));
	foreach($images as $image)
		$products[$image->product_id]->images[] = $image;

	foreach($products as &$product)
		$product->image = &$product->images[0];

	$variants = array();
	foreach($this->variants->get_variants(array('id'=>$variants_ids)) as $v)
		$variants[$v->id] = $v;
			
	foreach($variants as $variant)
		$products[$variant->product_id]->variants[] = $variant;

	foreach($purchases as &$purchase)
	{
		if(!empty($products[$purchase->product_id]))
			$purchase->product = $products[$purchase->product_id];

		if(!empty($variants[$purchase->variant_id]))
		{
			$purchase->variant = $variants[$purchase->variant_id];
		}
	}

	$payment_method = $simpla->payment->get_payment_method($order->payment_method_id);
	$payment_currency = $simpla->money->get_currency(intval($payment_method->currency_id));
	$settings = $simpla->payment->get_payment_settings($payment_method->id);
	$price = number_format(round($simpla->money->convert($order->total_price, $payment_method->currency_id, false), 2), 2, '.', '');

	$total_products = 0;

	$request = array();
	$request['SelectedPaySystemId'] = $SelectedPaySystemId;
	$request['Products'] = array();

	foreach($purchases as $purchase)
	{
		$image = '';
		if($purchase->product->image)
			$image = $simpla->design->resize_modifier($purchase->product->image->filename, 200, 200);

		$request['Products'][] = array(
			'ImageUrl' => $image,// Ссылка на изображение товара 
			'ProductItemsNum' => $purchase->amount, // Количество единиц товара 
			'ProductName' => $purchase->product_name, //Наименование товара 
			'ProductPrice' => number_format(round($simpla->money->convert($purchase->price, $payment_method->currency_id, false), 2), 2, '.', ''), //Стоимость 1 единицы товара 
			'ProductId' => $purchase->sku ? $purchase->sku : $purchase->product->id // Артикул или код товара*. 
		);
		$total_products += $purchase->amount;
	}

	
	$request['PaymentDetails'] = array(
		'MerchantInternalPaymentId' => $order->id, // Номер заказа в системе продавца (CMS) 
		'MerchantInternalUserId' => $order->user_id, // Код (id) покупателя в системе продавца 
		'EMail' => $order->email, //E-Mail пользователя. 
		'PhoneNumber' => $order->phone, // Номер телефона покупателя. 
		'Description' => 'Оплата заказа №'.$order->id, // Комментарий к платежу. 
		'DeliveryType' => '',// Способ доставки. 
		'CustomMerchantInfo' => '',
		'StatusUrl' => $simpla->config->root_url.'/payment/Kaznachey/callback.php',
		'ReturnUrl' => $simpla->config->root_url.'/order/'.$order->url,
		//Информация о покупателе 
		'BuyerLastname' => $order->name,
		'BuyerFirstname' => '',
		'BuyerPatronymic' => '',
		'BuyerStreet' => $order->address,
		'BuyerCity' => '',
		'BuyerZone' => '',
		'BuyerZip' => '',
		'BuyerCountry' => '',
		//Информация о доставке 
		'DeliveryLastname' => $order->name,
		'DeliveryFirstname' => '',
		'DeliveryPatronymic' => '',
		'DeliveryStreet' => $order->address,
		'DeliveryCity' => '',
		'DeliveryZone' => '',
		'DeliveryZip' => '',
		'DeliveryCountry' => ''
	);

	// Делаем подпись
	$request['Signature'] = md5($settings['kaznachey_id'].$price.number_format($total_products, 2, '.', '').$order->user_id.$order->id.$SelectedPaySystemId.$settings['kaznachey_key']);
	$request['MerchantGuid'] = $settings['kaznachey_id'];
	
	// получаем данные, где присудствует кодированая форма
	$resMerchantPayment = json_decode(sendRequestKaznachey('http://payment.kaznachey.net/api/PaymentInterface/CreatePayment', json_encode($request)), true);

	if(isset($resMerchantPayment['ErrorCode']) && $resMerchantPayment['ErrorCode'] == 0)
		// выводим нашу кодированую форму
		die(base64_decode($resMerchantPayment['ExternalForm']));
	else
	{
		// получили какую то ошибку
		header('Location: '.$simpla->config->root_url.'/order/'.$order->url);
		exit();	
	}

}


$json = @json_decode(file_get_contents('php://input'));

if(!empty($json))
{
	$json = (array)$json;
	$order_id			= intval($json['MerchantInternalPaymentId']);
	$user_id			= $json['MerchantInternalUserId'];
	$Sum				= round($json['Sum']/97*100, 2); 
	$Signature			= $json['Signature']; 
	$ErrorCode			= $json['ErrorCode'];
	$CustomMerchantInfo = $json['CustomMerchantInfo'];

	// Проверяем статус
	if (!isset($ErrorCode) || $ErrorCode != 0)
		die('ErrorCode: '.$ErrorCode);

	// Проверяем наличие заказа
	$order = $simpla->orders->get_order(intval($order_id));
	if(empty($order))
		die('Оплачиваемый заказ не найден');

	// Нельзя оплатить уже оплаченный заказ  
	if($order->paid)
		die('Этот заказ уже оплачен');

	if($Sum != round($simpla->money->convert($order->total_price, $method->currency_id, false), 2) || $Sum<=0)
		die("incorrect price");

	$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
	if(empty($method))
		die("Неизвестный метод оплаты");

	$settings = unserialize($method->settings);
	$payment_currency = $simpla->money->get_currency(intval($method->currency_id));

	// Проверяем контрольную подпись
	$mySignature = md5($ErrorCode.$order->id.$order->user_id.number_format($json['Sum'], 2, '.', '').$CustomMerchantInfo.$settings['kaznachey_key']);
	if($mySignature !== $Signature)
		die("bad sign");

	// Установим статус оплачен
	$simpla->orders->update_order(intval($order->id), array('paid'=>1));

	// Отправим уведомление на email
	$simpla->notify->email_order_user(intval($order->id));
	$simpla->notify->email_order_admin(intval($order->id));

	// Спишем товары  
	$simpla->orders->close(intval($order->id));

	// Перенаправим пользователя на страницу заказа
	header('Location: '.$simpla->config->root_url.'/order/'.$order->url);
	exit();

}
else
	die('not data');




	//запрос к серверу казначей
	function sendRequestKaznachey($url, $data)
	{
		// проверяем наличие CURL
		if(function_exists('curl_init'))
		{
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array('Expect: ', 'Content-Type: application/json; charset=UTF-8', 'Content-Length: '. strlen($data)));
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			$result = curl_exec($curl);
			curl_close($curl);
		}
		else
		{
		    // параметры запроса
		    $opts = array(
		        'http'=>array(
		            'method'	=> 'POST',
		            'header'	=> 'Content-Length: ' . strlen($data) . "\r\nContent-Type: application/json\r\n",
		            'content'	=> $data,
		        )
		    );
			// создание контекста потока
			$context = stream_context_create($opts); 
			$result = @file_get_contents($url, 0, $context);	
		}

		return $result;
	}
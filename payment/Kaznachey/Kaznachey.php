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

require_once('api/Simpla.php');

class Kaznachey extends Simpla
{	

	public function checkout_form($order_id, $button_text = null)
	{
		if(empty($button_text))
			$button_text = 'Перейти к оплате';
			
		$order = $this->orders->get_order((int)$order_id);
		$payment_method = $this->payment->get_payment_method($order->payment_method_id);
		$settings = $this->payment->get_payment_settings($payment_method->id);
			
		$price = round($this->money->convert($order->total_price, $payment_method->currency_id, false), 2);

		$requestMerchantInfo = Array(
			"MerchantGuid"=>$settings['kaznachey_id'],
			"Signature"=>md5($settings['kaznachey_id'].$settings['kaznachey_key'])
		);
			
		$resMerchantInfo = json_decode( $this->sendRequestKaznachey('http://payment.kaznachey.net/api/PaymentInterface/GetMerchantInformation', json_encode($requestMerchantInfo)), true);
	
		$action = $this->config->root_url.'/payment/Kaznachey/callback.php';

		$button  =	'<form action="'.$action.'" method="POST"/>';
		foreach ($resMerchantInfo['PaySystems'] as $paysystem)
		{
			if ($checked != 1) 
				$checked_text = 'checked';
			else
				$checked_text = '';
			$button .='<label><input type="radio" name="SelectedPaySystemId" value="'.$paysystem['Id'].'" '.$checked_text.'>'.$paysystem['PaySystemName'].'</label>';
			$button .='<br>';
			$checked = 1;
		}
		$button .='<br>';
		$button .='<br>';
		$button .='<input type="checkbox" name="confirm" checked> Согласен с <a target="_blank" href='.$resMerchantInfo['TermToUse'].'>условиями использования </a><br /></br>';
		$button .='<input type="hidden" name="order_id" value="'.$order->id.'">';
		$button .='<input type="submit" name="Payment" class="checkout_button" value="'.$button_text.'">';
		$button .='</form>';

		return $button;
	}

	//запрос к серверу казначей
	private function sendRequestKaznachey($url, $data)
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
}
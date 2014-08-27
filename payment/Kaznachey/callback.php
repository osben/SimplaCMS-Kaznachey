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
	foreach($simpla->variants->get_variants(array('id'=>$variants_ids)) as $v)
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

	$payment_method 			= $simpla->payment->get_payment_method($order->payment_method_id);
	$settings 					= $simpla->payment->get_payment_settings($payment_method->id);
	$delivery 					= $simpla->delivery->get_delivery($order->delivery_id);

	$total_products 			= 0;
	$total_products_price 		= 0;

	$total_price = round($simpla->money->convert($order->total_price, $payment_method->currency_id, false), 2);
	$price = number_format($total_price, 2, '.', '');


	$request = array();
	$request['SelectedPaySystemId'] = $SelectedPaySystemId;
	$request['Products'] = array();

	// список товаров которые заказали
	foreach($purchases as $pur)
	{
		$image = '';
		if($purchase->product->image)
			$image = $simpla->design->resize_modifier($pur->product->image->filename, 200, 200);




		$request['Products'][] = array(
			'ImageUrl' => $image,// Ссылка на изображение товара 
			'ProductItemsNum' => $pur->amount, // Количество единиц товара 
			'ProductName' => $pur->product_name, //Наименование товара 
			'ProductPrice' => number_format(round($simpla->money->convert($pur->price, $payment_method->currency_id, false), 2), 2, '.', ''), //Стоимость 1 единицы товара 
			'ProductId' => $pur->sku ? $pur->sku : $pur->product->id // Артикул или код товара*. 
		);
		$total_products += $purchase->amount;
		$total_products_price += round($simpla->money->convert($pur->price, $payment_method->currency_id, false), 2);
	}

	// Если доставка оплачивает не отлдельно, то добавляем ее к спику что заказали
	if($order->delivery_id && !$order->separate_delivery && $order->delivery_price>0)
	{
		$request['Products'][] = array(
			'ImageUrl' => 'data:image/png;base64,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',
			'ProductItemsNum' => 1,
			'ProductName' => $delivery->name, //Наименование товара 
			'ProductPrice' => number_format(round($simpla->money->convert($order->delivery_price, $payment_method->currency_id, false), 2), 2, '.', ''), //Стоимость 1 единицы товара 
			'ProductId' => 'delivery_id_'.$order->delivery_id // Артикул или код товара*. 
		);

		$total_products += 1;
		$total_products_price += round($simpla->money->convert($order->delivery_price, $payment_method->currency_id, false), 2);
	}

	// если у заказа есть скидка по купону
	if($order->coupon_discount>0)
	{	
		$request['Products'][] = array(
			'ImageUrl' => 'data:image/jpeg;base64,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',
			'ProductItemsNum' => 1,
			'ProductName' => 'Купон', 
			'ProductPrice' => number_format(round($simpla->money->convert($order->coupon_discount * -1, $payment_method->currency_id, false), 2), 2, '.', ''), //Стоимость 1 единицы товара 
			'ProductId' => 'coupon_discount'
		);

		$total_products += 1;
	}

	// если у заказа есть скидка
	if($order->discount>0)
	{

		$request['Products'][] = array(
			'ImageUrl' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEAYABgAAD/4QCKRXhpZgAATU0AKgAAAAgABwEaAAUAAAABAAAAYgEbAAUAAAABAAAAagEoAAMAAAABAAIAAAExAAIAAAAQAAAAclEQAAEAAAABAQAAAFERAAQAAAABAAAAAFESAAQAAAABAAAAAAAAAAAAAABgAAAAAQAAAGAAAAABcGFpbnQubmV0IDQuMC4zAP/bAEMAAgEBAgEBAgICAgICAgIDBQMDAwMDBgQEAwUHBgcHBwYHBwgJCwkICAoIBwcKDQoKCwwMDAwHCQ4PDQwOCwwMDP/bAEMBAgICAwMDBgMDBgwIBwgMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDP/AABEIAHgAeAMBIgACEQEDEQH/xAAfAAABBQEBAQEBAQAAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EAACAQMDAgQDBQUEBAAAAX0BAgMABBEFEiExQQYTUWEHInEUMoGRoQgjQrHBFVLR8CQzYnKCCQoWFxgZGiUmJygpKjQ1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4eLj5OXm5+jp6vHy8/T19vf4+fr/xAAfAQADAQEBAQEBAQEBAAAAAAAAAQIDBAUGBwgJCgv/xAC1EQACAQIEBAMEBwUEBAABAncAAQIDEQQFITEGEkFRB2FxEyIygQgUQpGhscEJIzNS8BVictEKFiQ04SXxFxgZGiYnKCkqNTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqCg4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2dri4+Tl5ufo6ery8/T19vf4+fr/2gAMAwEAAhEDEQA/AP2Ig6GrA6VXg6GrA6UAI/3aZTpXCJzUVhFda3deTY27zt/ER91PqegoAfRVfxPZ33he+8mRVm+UNmM8H8/Ss+HxZGH2yho29GGKANiiq9vqcVwPlYVOrhuhoAWiiigAoHWigdaAJKa/SnU1+lAFS8+6aKLz7pooAfYEyRVNNIsCbmP60mmRbbUE+lTeGfDbeOPEH2f5hZ2+GuHHp2UH1P8AKgCz4N8FXHji586Rng02NsM+fml9Qv8AjXm/7a//AAUW+H/7C2if2LBGuueK5ELwaLZyBTECOHuJMERg+hy56gY5rD/4KYf8FDLf9kDwbF4T8INbv461OAGEBA0ejwHjzWHQucYVT/vHjAb8dfFWq6h4x1u81TVby61LUr+Vp7m6uZDJNPIxyWZjkkk9zX6dwXwH/aEVjcfdUui2cvPyj+L6W3PynjnxC/s6TwGXWdb7Ut1Hyt1l+C63eh9HfDf/AILM/EBv2mbLXvH15DceCrxmtbzSbO22w2ETniWIcuXQgHLFiy7h3BH6qaToum+OvDNnq2k3Frqmk6pAtza3MDiSG4jYZVlI4IIr+fLX9C81G+Wvp3/gmR/wVS1X9h7WV8J+Lre6174Z30xOxHLXGhOxy0sI53Ied0XGc7gQchvoOM+CoVKar5bBRlFW5UrJr/Nfj6nzvA3HdSnUeHzSo5Rm7qTd3F+f91/h6H6v3vw4a3bMLSwnttPH5VRNhq2ln+G4VfT5W/z+Ner/AA18YeFPjr4FsfEvhLWNP13Q9TjEtvdWkokRgeoOOVYHgqcFSCCARV2+8BrIOFB/CvxWUXF8slZo/dYSUoqUHdM8ch8VeS+24jkhb0dSB+daVtq0N0PlZa7TUvh4GXmMEe4rmdR+FUaSM0StA3rGcfp0qbFEasr9DTgtZs/hnVdKb9263CDs3yt/hUX/AAkEunsFu7eaH3Iyv5jipGa4X60MtQ2WrQXiBlZfzq4kauODQBja3O1vExFFO8UwYt2ooAnvr37FpnctjAA71t/ET4i6b+yp+z1rHijVVDf2XatcvFuw1zcNwkQPuxVc9hzWV4Vtv7a8cadbsMrCxnYH/Z5H64r5f/4LkfGR7PQvCPgG1kKm+Z9Zv1B6xofLhBHcFvNP1jFe7w3lP9pZjSwj2b19Fq/wVvU+f4qzn+ysrrY1fFFWj/iei/F39Efnn8WviJrHxo+IereKNeuWutV1m4a4mf8AhXJ4VR2VRgAdgBXLyWPtWzDYSXc8cMUbyyyMEREBZnY8AAdyfSvfbT/gld8bL/wxHqS+E445JovOjsJdQgjvnT18ktu/A8+1f05XxuDwMIwrTjTWyTaW3a/Y/lHDYPG5jOdShTlUe8mk5PXq7d/xPl+603zE5Fc/rnhZblW+WvRte8Hal4c1u+03ULC7s9Q0uR4ru3liZZLZlO1g6nlcHg5rHnsFkHSuuXLOPc5YuVOVtmVv2Z/2sviZ+xH4zOseAdensoZj/pmmXA87T79fSSJuM+jrhx2YZOf09/ZU/wCDhf4c/EbTrfT/AIoabefD/XhhJLuFGvNLn/2wyjzI8/3WVgP75r8t9Q8OrOp+UGud1bwQsxOFr4zPODcFmD56kbS/mWj/AOD87n3GQ8bY/LvcpyvH+V6r/gfJo/pQ+GPxl8H/ABv8PJq3hHxNoPifTX4Fxpt7HdIp/utsJ2t7HBHpXQTaZDP95BX8vml6Vqvg3Uvtmj6hqGk3i9J7O4eCQf8AAlINel+Fv28P2gfh8qrpfxa8dRRr0jm1J7iMcY+7LuH6V+eYrw0xEX+4qprzVvxV/wAj9KwfilhpR/f0mn5NP8Hb8z+iq78JwTg7ayL/AMALKPuBs+1fgdP/AMFX/wBqCWzW3b4sa55a45Wzs1fj/bEO79a4vxh+218d/iKHXWfit48u45M5jGrSwx89cKhCj8BXNS8N8e371SKXld/ojqreKGXRV4U5N+dl+rP3R+MXibwD8HfszeKvFHh/wzNeSCO3W7v47eW4YnACITl+fQHHetW80SXw/pn2xZ/tFuHCEEfMoPf+VfzpyWF9rGoNd311dXl1IcvNPK0kjn3ZiSa/fT/gnp48m/aM/Yd8L6leTNcak2nHTryR23M9xBmPex7s21WPuxrk4n4NllOEp4hT5ruz0tZ2urb9mdvCfHEc5xdTDOChyq8db3V7O+3ddO51evOLqxZvbNFZ+n3ZutE+bhguCD2NFfCn35v/AAaIuPHt43/PG04/FhX51f8ABX3WJNa/bL1CFm3JpumWlumD90FPMI/76c1+hfwUuPK+IeoRtwZLPK++GX/Gvz3/AOCtWgvp37ZGqTlNqX+n2k6H+8PL2E/99Ia/RvC/l/th3/klb74/oflHjJKUcii47e0jf7pfrY4v/gm3o+kap+2n4Hj1ryPsyXbSRCX7rTqjGIfXeBj3xWx8ZbD4u+Nf29vE+r6To/iKbxhpOtT3dlFFA7m2t4ZD5QXsYxGFA7MD33c+C2s82m3kNxbyyQXFu4kiljYq8bA5DKRyCCMgjpXtV3/wUd+NV34PbRZPHWpfZXi8ky+XGLkrjH+uC78++c+9fsGYZbinjHi8OoS5ocjU20krt3Vk7p31Wl7LU/CsqzvBLALBYuU4ctT2icEm5OySTu1Zq11LW13ofSH7Cf8Awk3xwX4z/FXXm0XTfGmvWkfhSyuLiMWNutzIFQ7uCQxYQLwMkrjBNeOfG7wX4C+DOt2vwN8P+D7LxV42vGistX8Vagksdzb387L/AMeqcARorcZGGBByep4Gz/a91DRP2ZdC+HOm6THYLpPiFNfuNRjuCZNQdCWRGXb8oDbTkE/cHSvoPQPj38LP2iP+Cj3hH4jXOrJ4b0nS9KFzfjWgttvvoFKRKGyVJJZGBz0ixwa+Zq4HF4PFVcTOD9laTjGGy9nGKpp8utn7zUdr73PtMPm2Bx2Eo4SnUj7a8FKU937WUnVa5la8Xypy3ts118f+Mn/BP3+3P2z7r4XfCuHVdUh0uG2GqXt9KskGnSuivKzSKihY1DABTliQQMniuD+J37A/i3wp+07cfCfQpLHxl4nhthcldPfylA8kzlWMu0KwjwcE4JZQCScV9x/Bf9qPSvi5+1u/hT4YwS6X4Hsry78VeLtfYEXniFo8tlmOCsJlaNQvGUOMKvyngPhzrupfB3Rvjd+0Z4ss7mw1TxRJPpPhSG5QxzXDTt8roCM+WqiPnptjbFZ4fPs0pfu6qXMqcFGMvilUm7Rcu2zk4p6Rtd326sRkWU1l7Wi3yupNylHSEacFzSUO+8YqTVnK9lbf4h+J37JHxA+EqyN4k8F+ItJhi+/NNZOYV/7aAFf1rzibw7G38K/lX6B+EPin4w8Cf8EoPFmra54i1vVLz4jasNB0SO9vJJ/Jsx8lx5e4nCuBOhA46V3nxf8A2DvD4+DPwpsdet9J8K+EvBuiHVvGfiNI4Y76d3AZbRTgyNI7NJgEEDK4DHC160eKFRl7PGxV+eUE4315YptpPW/M1BJNtyPKfCv1iHtcDJ/w4zalbTnk1FNrS3KnNtpJRPy4fw1H/dFRnw9Gn8P6V9AeOfCPhn9ob41aL4e+DPgzWNJXUcWkdpeX5u5Lmbe370s3EaBNpbJwuGOcCrH7Z37D+ufsY+INEstY1TTdYj162knt7iyDBAY2Cuh3DqCw6evavoqeZYb2tOhU9ypNNqDtzWW97Nr8fyZ81UyzE+yqYin79Km0nNX5bva10n+Hbuj52OkKvav1z/4IKaq97+yj4gs5CfL03xHLHHz0DQQuf1Y1+U8lnX6uf8EJNEl0r9l3xFcSDEd/4jkkjPqFghQ/qpr5bxK5f7Elf+aNvv8A8j67wu5v7ejb+WV/u/zsew38YtPEWtW6/chvZlUeg3nFFQ3Vyt94q16aM5jkvpyp9RvbBor+dD+mh3hTVh4f+KmmyM22O6Jtj9WGB/49j86+ef8Ags/8IGuD4T8cW8e5Y1fR71h/CMmWE/mZgT/uivbPiBZyTaWJoWZZYvnRhwVYcgiup8d+ELL9sD9mbUNHnaOO61C3KByOLW8j5ViPTcAfoa9/hfNv7OzOlipfCnaXo9H917/I+X40yN5vk1fBQ+Nq8f8AFHVffa3oz8c3tsVE1vXUeNPBGoeAfFWoaLqts9nqOmTtb3ETdVZTg/Udwe4INY721f1TCcZxUou6eqP4flKdOThNWadmnun2ZltBUbQVpPbVE9tVGkawnhvX7zwpqy3VncXVs33JfInaEzRkjchZTnaw4Ir6N8a/8FWviPrHi64m0iPS9P8ACz28FtD4dvbWLULWJEiRGBZkVm3lWbtjdjtmvm57fFRtb15uMynB4qaqYmmpNJpXV7Xt02votd103Z7WX8Q4/BU3TwlVwUmm7O17XtrvbV6bPqtEfW3xq/bM8G/Fr9nL4SR6xpWg/wBqaD4la+1XQfD9tJYwWVpHISY1jf8Ad7plOcrlQWPQ5FbHgH9uzwj8Z/jx8Yv+E3v5tB8H/EzQ10qwS9heZbN44/LhLCMMFI3M27oGOcivi1oajaDJryf9U8F7J0lzL4rO+seaSnpfRWaVtL2Wtz6KPHmYOtGrLlduW6tpLlg4e9Zpu8ZO6va70tsfcH7NA+GH7C3wct9V8QeMoofiN8RrCVLXU9Fhj1b/AIRm1IAB2K3DknJPUkbQPkYnJ/4KL+G9L1P9hv4IatoviT/hMtO0a4vdMj1o20lq135oViXjkJdWzAQcnqPevjF4Mf8A1q6a9+M/im/+E1t4FuNWmn8K2d39ut9PkjRlgm+bLI2N653tkA4Oelc/+rVSONp4+NVymp80r2Sa5ZRtGybVlKyXNbdvVnoR4yozwFTLpUVGDhyx5W21LmjJuV2k+Zxu3y3WiWiscG8FftF+wh4Cb9nP9hrw6l5F9nvJNPfWLiNhgrJPmRVI9dpQEHoc1+cP/BPT9lqT9pn9oTT7W5tWl8O6E66hqzsv7to1OUiJ6ZkYYx3Ab0r9P/2m/HSxQ2Phe0YfaL5hNc7T/qoVPCn/AHm/RT6ivifFLOIP2eWweq96XlpaK/Fv7j9H8H8nm1VzWotH7kfPW8n+CX3nI+EUZdE8x23NINxJ6kmirtvD9g0YLjHy0V+Nn7iOuAt/pe1ucrXJfD/4ht8E/HTfanYaFqjBbkdoG6LKB7dD6j6Ct3TNR3Wqj2rA8c6DHrVm6lRyPSgDK/4KB/sUr8d9GXxt4RjSbxHbQKZoIcMurQAZUrjrIAeD/EOPSvznutPe2neOSNo5I2KurDDKRwQR2Nfo58Hv2gbz4Jaiui655l14cd8RSgbpLDPp/eTvjqO3pWp+0t+w54T/AGq9NXxP4ZvLPTdeuELreQYa11HP/PULzuz/ABLzychuMfqnBfHiwcFgMxf7tfDLfl8n3XbqvTb8L8SvC2WYVJZrlCXtXrOGyn5p7KXdPR73T3/Md7aontq9H+MP7O3i34Gaq1r4k0a6sV3lI7kDfbT/AO5IPlOeuOvqBXEPa4r9uw+IpV6aq0ZKUXs07p/cfzLiaNfC1XQxMHCa3Uk018mZT21QvbCtZ7aoXtq3JjUMp7aomt8VqvbVE9tmg1jVMtoK6T4PfBTxB8efH1n4b8N2LXmo3h7/ACxwIOskjYO1B3P8zgV7J+zX/wAE7fHP7Qt7b3M1nJ4b8OuA76lfRFTIv/TKM4Zyex4X37H788E+B/hz/wAE/vhmttZxr9tuE+eUhX1DV5B+Xy57cKv16/B8UcdYTLYOjh2qlbstVHzk/wBFr6H6rwT4b5hnE44jFRdLD93o5LtFPv8AzPTtfYsfBz4V+F/2Av2e1sY3W4uc+ZdXG0JLqt2V4A744wBztUH3rg/Cc19428S3OvaoytdXr7yB92MdlHsBxXNaz4u1j47+Lxq2rZhtYTi0s1OY7Zf6se57/TArv9BgTTLVVXjaK/nrFYqriKsq9d80pO7fdn9XYPB0cJQhhsPHlhFWSXRI0tfufIsSvTiisbxRqWYG57UVznQZmj6rvtl69KtvfeYuDWPof/Hsv0q/QBi+KvDcWsQNuUc+1cb4e8S+K/gZqjXHh28ZbVn3y2Uyl7eb6r2PuMH3r0wjNVL7SI7xMMoNAG/4U/bX8H/EDT30fxrpY0j7UuyZLqL7VYzexODj/gS4HrVDxN+wR8G/jfbSXmgsumyv83naHeq0YPvG29QPYAVw3iH4XW2pbv3a8+1cZe/BSXT7xbixmmtbiM5WSFyjqfYjkV6GBzXGYKXNhKsoejaT9Vs/meVmmQ5dmUOTH0Y1P8STa9Huvkzf17/gj5ulb+zfG37sngXWnfMPxV+fyFZEP/BHjWml/eeNNJVOxWykJ/nVu08S/ErQAFtfGXiDavQTXJnx/wB95qb/AIWf8V5Ayt4v1LDDHEUQ/XZX0kfELPYq3tr/APbsf8j4up4Q8LSlzLDtek5//JGx4S/4I6aJBOr674y1O8jz80Vjapbk/wDA3L/+g16doHwL+Av7LbLczW2gx6ha4cTahL9uuww6FUO4hv8AdUe1eF6nD458YxNFqnirxBdQvw0TXsixt9UBC/pSaB8C4bZlaRQT3yK8zH8V5tjFy168rdl7q+6Nr/M93KuA8gy6SnhcLFSXV3k16OV2vkes/EX9ve512OWy8EaTLGWyo1G/T7o/vJF/Lcfqtea6R4X1Lxfrjatrl5cajqE335p23NjsB6Aeg4rqdD8A2+mINsa8e1b9vZpbrhVFfPH1g3RbKPTYFVVxj2rS/tCqtFAGb4t1jZbN9KKz/Gf/AB7N9KKANPQ/+PZfpV+iigAooooAOtNaFX6qKKKAIm0+N/4R+VINLhH8I/KiigByWUa/wj8qkWNV6KKKKAHUUUUAFFFFAHO+M/8Aj2b6UUUUAf/Z',
			'ProductItemsNum' => 1,
			'ProductName' => 'Скидка '.$order->discount.'%', 
			'ProductPrice' => number_format(round($simpla->money->convert(($total_products_price/100) * $order->discount * -1, $payment_method->currency_id, false), 2), 2, '.', ''), //Стоимость 1 единицы товара 
			'ProductId' => 'discount'
		);

		$total_products += 1;
	}

	$request['PaymentDetails'] = array(
		'MerchantInternalPaymentId' => $order->id, // Номер заказа в системе продавца (CMS) 
		'MerchantInternalUserId' => $order->user_id, // Код (id) покупателя в системе продавца 
		'EMail' => $order->email, //E-Mail пользователя. 
		'PhoneNumber' => $order->phone, // Номер телефона покупателя. 
		'Description' => 'Оплата заказа №'.$order->id, // Комментарий к платежу. 
		'DeliveryType' => $delivery->name,// Способ доставки. 
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

logg((string)file_get_contents('php://input'));

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

	// метод оплаты определяем
	$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));

	// сумма заказа
	$total_price = $simpla->money->convert($order->total_price, $method->currency_id, false);
	if($Sum != round($total_price, 2) || $Sum <= 0)
		die("incorrect price");

	
	if(empty($method))
		die("Неизвестный метод оплаты");

	$settings = unserialize($method->settings);

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
{
	header('Location: '.$simpla->config->root_url.'/order/');
	exit();	
}




function logg($str)
{
	file_put_contents('payment/Kaznachey/log.txt', file_get_contents('payment/Kaznachey/log.txt')."\r\n".date("m.d.Y H:i:s").' '.$str);
}

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
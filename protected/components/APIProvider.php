<?php


class APIProvider {
	
	const isVirtual=true;
	
	private static $self=false;
	private $activeOrders;
	private $balance=5000;
	private $balance_btc=0;
	
	public static function get_Instance()
	{
		if (!self::$self)
			self::$self = new APIProvider();
		return self::$self;
	}
	
	private function getInfoVirtual()
	{
		$result = array(
				'funds' => array (
						'rur'=>$this->balance,
						'btc'=>$this->balance_btc,
						)
				);
		return ($result);
	}
	
	public function getInfo()
	{
		// ���� �������� ����������
		if (self::isVirtual)
			return $this->getInfoVirtual();
		
		$BTCeAPI = BTCeAPI::get_Instance();

		$info = $BTCeAPI->apiQuery('getInfo');
		if ($info['success'] == 1)
		{
			return $info['return'];
		}
		else
			return false;
	}
	
	// �������� ������
	/*
	 *����������:
	 1) �� ������� ������ - ����� ������� ������
	array
	(
			'success' => 1
			'return' => array
			(
					'received' => 0
					'remains' => 0.01
					'order_id' => 87715140
					'funds' => array
					(
							'usd' => 0
							'btc' => 0.077844
							'ltc' => 0
							'nmc' => 0
							'rur' => 4343.61904536
							'eur' => 0
							'nvc' => 0
							'trc' => 0
							'ppc' => 0
							'ftc' => 0
							'xpm' => 0
					)
			)
	)
	
	2) ������� ������, ����� ��������
	array
	(
			'success' => 1
			'return' => array
			(
					'received' => 0.01
					'remains' => 0
					'order_id' => 0
					'funds' => array
					(
							'usd' => 0
							'btc' => 0.087824
							'ltc' => 0
							'nmc' => 0
							'rur' => 4101.10904536
							'eur' => 0
							'nvc' => 0
							'trc' => 0
							'ppc' => 0
							'ftc' => 0
							'xpm' => 0
					)
			)
	)*/
	public function makeOrder($cnt, $pair, $type, $price)
	{
		// ���� �������� ����������
		if (self::isVirtual)
			return $this->makeOrderVirtual($cnt, $pair, $type, $price);
		
		$BTCeAPI = BTCeAPI::get_Instance();
		
		try {
				
			$btce = $BTCeAPI->makeOrder($cnt, $pair, $type, $price);
		
		} catch(BTCeAPIInvalidParameterException $e) {
			Log::Error('�� ������� ������� ����� '.$e->getMessage());
			return false;
		} catch(BTCeAPIException $e) {
			Log::Error('�� ������� ������� ����� '.$e->getMessage());
			return false;
		}
		
		// ������ �������� ������
		if($btce['success'] == 0)
		{
			Log::Error('�� ������� ������� ����� '.$btce['error']);
			return false;
		}
		
		return $btce['return'];
	}
	
	private function makeOrderVirtual($cnt, $pair, $type, $price)
	{
		$bot = Bot2::get_Instance();
		
		$summ = $cnt * $price;
		$fee = $summ * (Bot2::fee);		
		
		// ������� ����������� ������ �� �������, ����� ��������� ��� ��������� �������
		// ����������� ������
		if ($type == 'buy')
		{
			$balance_btc = $this->balance_btc;
			$balance = $this->balance - $summ;
			
		} else {
									
			$balance_btc = $this->balance_btc - $cnt;
			$balance = $this->balance;
		}		
		
		// �������� ������������ ������
		$result = array
					(
						'success' => 1,
						'return' => array
						(
								'received' => 0,
								'remains' => $cnt,
								'order_id' => 87715140+rand(0,100)*10000+date('m')*1000+date('h')*100+date('m')*10+date('s'),
								'funds' => array
								(										
										'btc' => (float)$balance_btc,										
										'rur' => (float)$balance,									
								)
						)
				);
		
		// ��������� ����� � ������ �������� �������
		$lastEx = Exchange::getLast();
		$this->activeOrders[$result['return']['order_id']]= array
													(
															'pair' => 'btc_rur',
															'type' => $type,
															'amount' => $cnt,
															'rate' => $price,
															'timestamp_created' => $lastEx->dtm,
															'status' => 0,
													);
		
		$this->balance = $balance;
		$this->balance_btc = $balance_btc;
		
		return $result['return'];
	}
	
	
	// �������� �������� ������
	/*
	 ����������:
	 array
	(
			'success' => 1
			'return' => array
			(
					88287800 => array
					(
							'pair' => 'btc_rur'
							'type' => 'buy'
							'amount' => 0.01
							'rate' => 19157.54
							'timestamp_created' => 1387344412
							'status' => 0
					)
			)
	)
	*/
	public function getActiveOrders($pair = 'btc_rur')
	{		
		// ���� �������� ����������
		if (self::isVirtual)
			return $this->getActiveOrdersVirtual($pair);
		
		$BTCeAPI = BTCeAPI::get_Instance();			
	
		try {
			$orders = $BTCeAPI->apiQuery('ActiveOrders', array('pair'=>$pair));
		} catch(BTCeAPIException $e) {
			Log::AddText(0, '�� ������� �������� ������ ������� '.$e->getMessage());
			return false;
		}
	
		return($orders['return']);
	}
	
	private function getActiveOrdersVirtual($pair)
	{
		$result = array
					(
							'success' => 1,
							'return' => $this->activeOrders,
					);
		
		return $result;
	}
	
	public static function CancelOrder($order)
	{
		$BTCeAPI = BTCeAPI::get_Instance();
		try {
			$res = $BTCeAPI->apiQuery('CancelOrder', array('order_id'=>$order->id));
		} catch(BTCeAPIException $e) {
			Log::Error('�� ������� ������� ����� '.$e->getMessage());
			return false;
		}
		
		if ($res['success'] == 1)
		{
			$bot = Bot2::get_Instance();
			$bot->setBalance($res['return']['funds']['rur']);
			$bot->setBalanceBtc($res['return']['funds']['btc']);
				
			$order->status = 'cancel';
			$order->close_dtm = $bot->current_exchange->dtm;
			$order->save();			
		}
	}
	
	
	// ���������� ����������� �������
	public function CompleteVirtualBuy($order)
	{		
		$this->balance_btc+=$order->count;
		return $this->balance_btc;
	}

	// ���������� ����������� �������
	public function CompleteVirtualSell($order)
	{
		$this->balance+=$order->summ-$order->fee;
		
		return $this->balance; 
	}
	
	
}
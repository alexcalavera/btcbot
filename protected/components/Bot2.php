<?php

/**
 * ��������� �� MySQL
 * @author Zaretskiy.E
 *
 * @todo ����������� ���.�� ������ - ����� ���� ������ �� �������� �� ���� ����� �� 20%, ������ ��� �������� 5 ���������
 * 
 */
class Bot2 {
	
	public $current_exchange;
	public $curtime;
	public $balance;
	public $balance_btc;
	private $order_cnt;
	private $total_income;
	private $imp_dif; // ��������� ��������, ��� ���������� ������ ����������� ���������
	private $avg_buy; // ������� ���� �������
	private $avg_sell;// ������� ���� �������
	private static $self=false;
	private $real_trade = false;
	
	//const imp_dif = 0.015; // ������� ��������� @todo ������� ��������� ������ �� ��������� ��������� � ���. �����
	//const min_buy = 0.01; // ���. ����� �������
	const buy_value = 0.02; // ������� ��������
	const fee = 0.002; // ��������
	const min_buy_interval = 86400; // 86400; // ���. �������� ���������� ������� = 1 �����
	const min_sell_interval = 86400;// 12 ����� // ���. �������� ���������� ������ = 1 �����
	const min_income = 0.04; // ���. ����� - 4%
	const long_time =  86400; // ������� ������������ ������ - ������ 2 ����
	const order_ttl = 180; // 180
	
	
	const freeze_warning_income = 0.01; // ����� ��� ������� ���� ���� ��������� ������, ��������� ��� �������
	
	public function __construct($exchange=false)
	{
		if (!$exchange)
			$exchange = Exchange::getLast();			
			
		$this->current_exchange = $exchange;
		$this->curtime = strtotime($exchange->dtm);
		
		$this->balance = Status::getParam('balance');
		$this->balance_btc = Status::getParam('balance_btc');
		$this->total_income=0;
		$this->imp_dif = 200;//self::min_income*(1+2*self::fee)*1/self::buy_value/4; // ����� �� �������� 1000 / 4, �� ������� ������ ���������� ���� ����� ��� ������� �������
		
		$this->order_cnt=0;		
		
		$from = date('Y-m-d H:i:s', time()-60*60*24*7);
		$this->avg_buy = Exchange::getAvg('buy', $from,  date('Y-m-d H:i:s', $this->curtime));
		$this->avg_sell = Exchange::getAvg('sell', $from,  date('Y-m-d H:i:s', $this->curtime));		
		
		self::$self = $this;
	}
	
	public static function get_Instance()
	{
		if (!self::$self)
			self::$self = new Bot2();
		return self::$self;
	}
	
	/**
	 * �������� ����������� ������� �� ������ - -0+
	 * @param  $period - ������ ������� � ���.
	 * @param $name - buy, sell
	 */
	public function getGraphImage($curtime, $period, $name)
	{		
		// @todo ���������� - ��������� ����������� ����� �����
		
		$step = round($period/3);
		$from_tm = $curtime-$period;
		$from = date('Y-m-d H:i:s', $from_tm);		
		$to = date('Y-m-d H:i:s', $curtime);		
		
		
		$track="";
		$prev=false;
		for($i=0;$i<=3;$i++)
		{			 			
			$step_ut = $from_tm+$step*$i;
			$step_dt = date('Y-m-d H:i:s', $step_ut);	// ����� ������ �� 4 �����
			$step_ut_f = date('Y-m-d H:i:s',$step_ut-$step/2); // ������ ������ ����� �������� ����� � ������ �������� ����
			$step_ut_t = date('Y-m-d H:i:s',$step_ut+$step/2);
			
			//$val=Exchange::NOSQL_getAvg($name, $step_ut_f, $step_ut_t);
			$val=Exchange::getAvg($name, $step_ut_f, $step_ut_t);
			
			if (!$val) 
			{
				//Log::AddText($this->curtime, '�� ����� ������ �� ������ �'.$step_ut_f.' �� '.$step_ut_t);
				return false;
			}
				
			$list[]=array(
					'dtm'=>$step_dt,
					'val'=>$val,
			);				
			
			if (!$prev)
			{
				$prev = $val;
				continue;
			}		
			
			// ���������� �����������
			$dif = ($val-$prev);			
			if ($dif<(-1*$this->imp_dif)) $track.="-";
			elseif ($dif>$this->imp_dif) $track.="+";
			else $track.="0";
			

			//if ($from == '2013-12-11 16:15:01')			
			//Log::AddText($this->curtime, '���='.$val.' ����='.$prev.' ����='.$dif.' => '.$track);
			
			$prev = $val;
		}
		
		// ��������������� ��������� ������
		//$list = array_values($list);
		
		$result = array(
				'track'=>$track,
				'from' => $from,
				'step' => $step,
				'period'=>$period,			
				'items' =>$list,	
				);
		
		return($result);
	} 
	
	/**
	 * ��������� ������ ������ �� ������� ������� ��������
	 * @param unknown_type $tracks
	 * @return multitype:unknown
	 */
	private function getBuyTracks($tracks)
	{
		$result = array();
		foreach($tracks as $track)
		{
			$ret = false;
			switch($track['track']){
				case '-0+':								 // \_/
				case '--+':								 // \\/
							// ���� ���� ��� ������� �� �������� � �������� �����
							if($track['items'][0]['val'] - $track['items'][3]['val']>$this->imp_dif)							
								$result[] = $track; 
							break; 
			//	case '00+':	$result[] = $track; break; // __/
				case '0-+':							   // _\/
							// ���� ���� ��� ������� �� �������� � �������� �����
							if($track['items'][1]['val'] - $track['items'][3]['val']>$this->imp_dif)
								$result[] = $track; 
							//Log::AddText(0, $track['items'][1]['val'] - $track['items'][3]['val'].' > '.$this->imp_dif);
							break; 
			// ���� ���� ������������ �������, �� �������� 
				case '---':								// \\\
				case '+--':								// /\\
				case '0--':								// /\\
							if ($track['period']>self::long_time) {
								//Log::Add($this->curtime, '�������� ������������ ������� '.$track['track'].' � ������� '.($track['period']/60).' ���., �� ��������');
								return false;								
							}
							break;				
			}			
		}		
		return $result;		
	}
	
	/**
	 * ��������� ������ ������ �� ������� ������� ���������
	 * @param unknown_type $tracks
	 * @return multitype:unknown
	 */
	private function getSellTracks($tracks)
	{
		$result = array();
		foreach($tracks as $track)
		{
			$ret = false;
			switch($track['track']){
				case '+0-':	$result[] = $track; break; // /-\
				case '++-':	$result[] = $track; break; // //\
			//	case '00-':	$result[] = $track; break; // --\
			//	case '0+-':	$result[] = $track; break; // -/\
			}
		}
		return $result;
	}
	
	private function AlreadyBought($period)
	{
		$key = 'track.'.$period;
		$tm = Yii::app()->cache->get($key);
		if (!$tm || $tm<$this->curtime)
			return false;
		else 
			return true;			
	}
	
	private function ReservePeriod($period)
	{
		$key = 'track.'.$period;
		return Yii::app()->cache->set($key, $this->curtime+$period, $period);
	}
	

	
	public function virtualBuy($cnt)
	{
		$summ = $cnt*$this->current_exchange->buy;
		
		$order = new Order();		
		$order->price = $this->current_exchange->buy;
		$order->count = $cnt;
		$order->fee = $summ * self::fee;
		$order->summ = $summ;
		$order->type = 'buy';
		$order->status = 'close';
		$order->create_dtm = $this->current_exchange->dtm;		
		$order->close_dtm = $this->current_exchange->dtm;
		
		//Log::Add($this->curtime, '<b>������� ������ �� ������� '.self::buy_value.' ��. �� '.$this->current_exchange->buy.' ('.$this->current_exchange->buy*(self::fee).' ��������) �� ����� '.$order->price.' ���.</b>', 1);
		//if ($btc_id) $order->btc_id = $btc_id;
		
		$order->save();
		$this->completeBuy($order);		
		$this->balance-=$summ+$order->fee;
		//echo '������ ������ '.$this->balance.'-='.$summ.'-'.$order->fee.' ()<br/>';
		$this->balance_btc+=$cnt;

		return true;
	}
	
	public function virtualSell($buy)
	{	
		
		$summ = $buy->count*$this->current_exchange->sell;
		
		$order = new Order();
		$order->price = $this->current_exchange->sell;
		$order->count = $buy->count;
		$order->fee = $summ * self::fee;
		$order->summ = $summ;
		$order->type = 'sell';
		$order->status = 'close';
		$order->create_dtm = $this->current_exchange->dtm;
		$order->close_dtm = $this->current_exchange->dtm;
		
		//$price = $this->current_exchange->sell*$buy->count*(1-self::fee);
		//Log::Add($this->curtime, '<b>������ ������ �� ������� (�'.$buy->id.')  '. $buy->count.' ��. (������� �� '.$buy->summ.') �� '.$price.', ����� = '.($price-$buy->summ).' ���.</b>', 1);
		
		//if ($buy->id) $order->btc_id = $buy->id;
		
		$order->save();
		
		$buy->order_id = $order->id;
		$buy->update('order_id');
			
		
		$this->completeSell($order);
		
		$this->balance+=$order->summ-$order->fee;
		$this->balance_btc-=$buy->count;
	
		return true;
	}
	
	
	private function makeOrder($cnt, $type)
	{		
		// ���� ������� / �������
		$price = $this->current_exchange->$type;
		
		
		$api = APIProvider::get_Instance();
		// �������� ������� ����� �� �����
		$result = $api->makeOrder($cnt, 'btc_rur', $type, $price);

		// ���� ��� ��, ��������� � ���� ��������� �����
		$order = new Order();
		$order->id = $result['order_id'];
		$order->price = $price;
		$order->count = $cnt;
		$order->summ = $cnt*$price;
		$order->fee = $order->summ*self::fee;
		$order->type = $type;
		$order->status = 'open';
		$order->create_dtm = $this->current_exchange->dtm;
		//if ($buy_id) $order->buy_id = $buy_id;
		
		// ����� ����� ���� ����� ���������, � ���� ������ ��������� ��� � ����
		if($result['received'])
			$order->close($exchange->dtm);	
		
		$order->save();
		
		// ������������� ������
		$this->setBalance($result['funds']['rur']);
		$this->setBalanceBtc($result['funds']['btc']);
		
		return($order);
	}
	
	/**
	 * ���������� � ������� (�������� ������, ������� � ��)
	 * @return boolean
	 */
	public function startBuy()
	{
		
	//	if (!$this->real_trade) 
		//	return $this->virtualBuy(self::buy_value);
		
		// ������� �����
		$order = $this->makeOrder(self::buy_value, 'buy');		
		
		// ���� ��������
		if ($order)
		{	
			// ���� ������ �����
			if ($order->status == 'open')				
				Log::Add($this->curtime, '<b>������� ������ �� ������� '.$order->count.' ��. �� '.$order->price.' ('.($order->summ*self::fee).' ��������) �� ����� '.$order->summ.' ���.</b>', 1);			
			// ���� ����� ������� �� ������� �����
			else 
				$this->completeBuy($order);
					
		return(true);
		}
		
		return false;
	}
	
	/**
	 * ���������� � ������� (�������� ������, ������� � ��)
	 * @return boolean
	 */
	public function startSell($buy)
	{	
		
/*		if (!$this->real_trade)
			return $this->virtualSell($buy);
*/
		// ������� �����
		$order = $this->makeOrder($buy->count, 'sell');
		
		if ($order)
		{	
			// ����������� BUY
			$buy->order_id = $order->id;
			$buy->update('order_id');		
			
			// ���� �� ������� ����� ������� �� ������ ���� 3 ������ ������ ����������
			if ($order->status == 'open')			
				Log::Add($this->curtime, '<b>������ ������ �� ������� (�'.$buy->id.')  '. $order->count.' ��. (������� �� '.$buy->summ.') �� '.$order->price.', ��������='.$order->fee.', ����� = '.($order->summ-$buy->summ-$buy->fee-$order->fee).' ���.</b>', 1);			
			// ���� ����� ������� �� ��������� ������� � ��
			else
			{			
				$sell = $this->completeSell($order);
				
			}			
			
			
			return(true);
		}
	
		return false;
	}
	
	public function completeBuy($order)
	{
		// ��������� �����
		$order->close($this->current_exchange->dtm);
		$order->save();
		
		// ��������� � ���� �������
		$buy = Buy::make($order);		
		
		Log::Add($this->curtime, '<b>��������� ������� �'.$buy->id.' '.$order->count.' ��. �� '.$this->current_exchange->buy.' ('.$order->fee.' ��������) �� ����� '.$order->summ.' ���.</b>', 1);
		$this->order_cnt++;
		
		// ��� ������������ ������� ��� �����
		$api = APIProvider::get_Instance();
		$this->balance_btc = $api->CompleteVirtualBuy($order);
		
	}
	
	public function completeSell($order)
	{
		// ��������� �����
		$order->close($this->current_exchange->dtm);
		$order->save();		
		
		$sell=Sell::make($order);	
		
		Log::Add($this->curtime, '<b>��������� ������� (�'.$order->buy->id.')  '. $order->count.' ��. (�������� �� '.$order->buy->summ.') �� '.$sell->summ.', ��������='.$sell->fee.', ����� = '.($sell->income).' ���.</b>', 1);
		
		$this->order_cnt++;
		$this->total_income+=$sell->income;
		
		// ��� ������������ ������� ��� �����
		$api = APIProvider::get_Instance();
		$this->balance = $api->CompleteVirtualSell($order);
	}
	
	
	public function NeedBuy()
	{		
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);
		
		
		// ���� �� ������
		if ($this->balance<$this->current_exchange->buy*self::buy_value) 
		{
			Log::Add($this->curtime, '�� ������� �����, �������� '.$this->balance.', ����� '.($this->current_exchange->buy*self::buy_value));
			return false;
		}
		
		// ���� ������� ���� ���� ������� �� ��������		
		if ($this->avg_buy && $this->avg_buy<$this->current_exchange->buy)
		{

			Log::Add($this->curtime, '���� ���� ������� �� 7 ��. ('.$this->avg_buy.'<'.$this->current_exchange->buy.'), �� ��������.');
			return false;
		}
		
		// ��������� ���� �� ��� ������� �� ��������� �����, ���� ���� � ���� ���� ����� �������� ��� ������� �� �� ��������
		$lastBuy = Buy::getLast();		
		if ($lastBuy)
		{
		$tm = strtotime($lastBuy->dtm)+self::min_buy_interval;		
		if ($tm>$this->curtime && $lastBuy->price - $this->current_exchange->buy < $this->imp_dif) return false;
		}		
		
		//���������� � ���������� ������� 15 ����, 30 ����, 1 ���, 2 ����� ...
		$periods = array(15*60, 30*60, 60*60, 2*60*60, 6*60*60, 24*60*60, 36*60*60);		
		$tracks=array();
		foreach($periods as $period)		
			$tracks[] = $this->getGraphImage($curtime, $period, 'buy');			
		
								// Log::AddText($this->curtime, '����� '.print_r($tracks, true));
								// Dump::d($tracks);
								
		//����������� �����
		$tracks = $this->getBuyTracks($tracks);
		if (!$tracks || sizeof($tracks) == 0) return false;		
							//	Log::AddText($this->curtime, "�������� ����� ".print_r($tracks, true));
		
		//������� ����� �� ������� ��� ���� �������
		foreach($tracks as $key=>$track)		
			if ($this->AlreadyBought($track['period']))		
			{
								//	Log::AddText($this->curtime, '��� ���� ������� �� ����� '.print_r($track, true));
				unset($tracks[$key]);
			}
								//	Log::AddText($this->curtime, '���������� ����� ���������� ����� '.print_r($tracks, true));
			
		// ���� �������� �����
		if (sizeof($tracks)>0)
		{
			// ��������
			if ($this->startBuy())			
			// ����������� ����� �������
				foreach($tracks as $track)	
				{
					//Log::AddText($this->curtime, '���� <b>'.$track['track'].'</b> �� '.($track['period']/60).' ���.');
					//Dump::d($track);
					$this->ReservePeriod($track['period']);					
				}	
		}				
		else
		Log::AddText($this->curtime, '��� ���������� �������');		
	}
	
	public function NeedSell()
	{
		//Log::AddText($this->curtime, '�������');
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);		
		
		
		// ���� ������� ���� ���� ������� �� �������
		if ($this->avg_sell>$this->current_exchange->sell)
		{
			//Log::AddText($this->curtime, '���� ���� ������� �� 7 ��. ('.$this->avg_sell.'>'.$this->current_exchange->buy.'), �� �������.');
			return false;
		}	
		
		// ��������� ���� �� ��� ������� �� ��������� �����, ���� ���� � ���� ���� ����� �������� ��� ������� �� �� �������		
		$lastSell = Sell::getLast();
		if ($lastSell)
		{
			$tm = strtotime($lastSell->dtm)+self::min_buy_interval;			
			//Log::Add($curtime, '��������� ���� �� �������, ���� � '.$lastSell->dtm.' �� '.date('Y-m-d H:i:s', $tm).' ������� ���� '.$this->current_exchange->sell.' ������ �������');
			if ($tm>$this->curtime && $this->current_exchange->sell - $lastSell->price < $this->imp_dif) 
			{
				//Log::Add($curtime, '��� ���� �������, ���� �� '.date('Y-m-d H:i:s', $tm).' ������� ���� '.$this->current_exchange->sell.' ������ ������� '.$lastSell->price);
				return false;
			}
		}
		
		//���������� ������� 9, 15, 30 �����, 1 ���
		$periods = array(/*9*60, 15*60, 30*60,*/ 60*60, 2*60*60, 4*60*60, 24*60*60, 36*60*60);
		//$periods = array(60*60, 6*60*60, 12*60*60, 24*60*60, 36*60*60, );
		$tracks=array();
		foreach($periods as $period)
		{
			$tracks[] = $this->getGraphImage($curtime, $period, 'sell');
		}	
		//Log::AddText($this->curtime, '����� �������'.print_r($tracks, true));
		//Dump::d($tracks);
		
		//����������� �����
		$tracks = $this->getSellTracks($tracks);
		
		if (sizeof($tracks) == 0) return false;
		
		//Log::AddText($this->curtime, '���� ���������� ����� ��� �������'.print_r($tracks, true));
		
		
		//������� ��� �������
		$bought = Buy::model()->with('sell')->findAll(array('condition'=>'sold=0 and order_id=0'));

		// ���� �������� �������
		foreach($bought as $buy)
		{
			// ���� �������
			$curcost = $buy->count*$this->current_exchange->sell*(1-self::fee);						
			// ������� ���������� ��� �������
			$income = $curcost - $buy->summ*(1+self::fee);						
			// ���������� �� ����������
			if ($income/$buy->summ < self::min_income)
			{
				//if ($income>0) Log::Add($this->curtime, '�� ������� (�'.$buy->id.'), ����� ������� ��� '.$income.' < '.(self::min_income*$curcost).' ����� �� '.$buy->summ.' ����� ������� �� '.$curcost.' sell='.$this->current_exchange->sell);							
				continue;
			}
			//else Log::Add($this->curtime, '$income='.$income.' $curcost='.$curcost.' self::min_income='.self::min_income);
			
			$this->startSell($buy);
			break;
			//Dump::d($tracks);
		}
		

		// ������� �� ��� ����� ����������
		foreach($bought as $buy)
		{
			// ���� �������
			$curcost = $buy->count*$this->current_exchange->sell*(1-self::fee);
			// ������� ���������� ��� �������
			$income = $curcost - $buy->summ*(1+self::fee);
			// ���������� �� ����������
			if ($income>0 && $income/$buy->summ < self::freeze_warning_income)
			{
				Log::Add($this->curtime, '����������� �������, ������ �� '.$buy->summ.', ������� ���� '.$curcost.', ������ '.$income);
				$this->startSell($buy);
				continue;
			}
			//else Log::Add($this->curtime, '����������� ������� �'.$buy->id.' �� ���������� $income='.$income.' $income/$buy->summ='.($income/$buy->summ).' self::freeze_warning_income='.self::freeze_warning_income);
				
			
		}
		
		
	}
	

	public function checkOrders()
	{	
		
		// �������� �������� ������
		$api = APIProvider::get_Instance();
		$active_orders = $api->getActiveOrders();		
		
		
		// �������� ��� �������� ������ �� ��
		$orders = Order::model()->findAll(array('condition'=>'status="open"'));

		foreach($orders as $order)
		{			
			// ���� ����� �� ���� ������ ����� ��������
			if (isset($active_orders[$order->id]))
			{		
				// ���� ����� ����� ����� 3 ����� - �������
				if ($active_orders[$order->id]['timestamp_created']<$this->curtime-self::order_ttl)
				{
					Log::AddText($this->curtime, '�������� ����� �'.$order->id, 1);
					//�������� �����
					$order->cancel();
					continue;
				}
			}			
			
			// ���� ����� �� ������, ������ �� ������� �������� (����� ��� ����� ���������)			
			if ($order->type == 'buy')
			{				
				$this->completeBuy($order);
				
			} elseif ($order->type == 'sell')
			{
				$this->completeSell($order);				
			}
		}
	}
	
	public function run()
	{
		$api = APIProvider::get_Instance();
		$info = $api->getInfo();
		
		$start_balance = 0;
		$start_balance_btc = 0;
		
		if ($info)
		{
			$this->balance = $info['funds']['rur'];
			$this->balance_btc = $info['funds']['btc'];
			
			Status::setParam('balance', $info['funds']['rur']);
			Status::setParam('balance_btc', $info['funds']['btc']);

			$start_balance = $this->balance;
			$start_balance_btc = $this->balance_btc;
		}	
		
		$this->NeedBuy();
		$this->NeedSell();
		$this->checkOrders();
		
		Status::setParam('balance', $this->balance);
		Status::setParam('balance_btc', $this->balance_btc);
		
		if ($this->order_cnt>0)
		{				
			Log::AddText($this->curtime, '������ �� ������');
			Log::AddText($this->curtime, '���: '.$start_balance, 1);			
			Log::AddText($this->curtime, 'Btc: '.round($start_balance_btc, 5), 1);
			
			Log::AddText($this->curtime, '������ �� �����');
			Log::AddText($this->curtime, '���: '.$this->balance, 1);
			Log::AddText($this->curtime, 'Btc: '.round($this->balance_btc, 5), 1);		
				
			Log::Add($this->curtime, '����� ����������: '.$this->total_income, 1);
		}
		
	}
	
	public function setBalance($summ)
	{
		$this->balance = $summ;
	}

	public function setBalanceBtc($summ)
	{
		$this->balance_btc = $summ;
	}
	
}
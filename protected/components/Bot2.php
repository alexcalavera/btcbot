<?php

/**
 * ��������� �� MySQL
 * @author Zaretskiy.E
 *
 * @todo ����������� ���.�� ������ - ����� ���� ������ �� �������� �� ���� ����� �� 20%, ������ ��� �������� 5 ���������
 * 
 */
class Bot2 {
	
	private $current_exchange;
	private $curtime;
	private $balance;
	private $balance_btc;
	private $order_cnt;
	private $total_income;
	private $imp_dif; // ��������� ��������, ��� ���������� ������ ����������� ���������
	private $avg_buy; // ������� ���� �������
	private $avg_sell;// ������� ���� �������
	
	//const imp_dif = 0.015; // ������� ��������� @todo ������� ��������� ������ �� ��������� ��������� � ���. �����
	const min_buy = 0.01; // ���. ����� �������
	const buy_value = 0.01; // ������� ��������
	const fee = 0; // ��������
	const min_buy_interval = 3600; // ���. �������� ���������� ������� = 60 ���.
	const min_income = 5; // ���. ����� � ������
	const long_time =  1800; // ������� ������������ ������ - ������ 30 �����
	
	
	public function __construct($exchange)
	{
		$this->current_exchange = $exchange;
		$this->curtime = strtotime($exchange->dt);
		
		$this->balance = Status::getParam('balance');
		$this->balance_btc = Status::getParam('balance_btc');
		$this->total_income=0;
		$this->imp_dif = 100;//self::min_income*(1+2*self::fee)*1/self::buy_value/4; // ����� �� �������� 1000 / 4, �� ������� ������ ���������� ���� ����� ��� ������� �������
		
		$this->order_cnt=0;		
		
		$from = date('Y-m-d H:i:s', time()-60*60*24*7);
		$this->avg_buy = Exchange::getAvg('buy', $from,  date('Y-m-d H:i:s', $this->curtime));
		$this->avg_sell = Exchange::getAvg('sell', $from,  date('Y-m-d H:i:s', $this->curtime));
		
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
			
			if (!$val) return false;
				
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
								Log::Add($this->curtime, '�������� ������������ ������� '.$track['track'].' � ������� '.($track['period']/60).' ���., �� ��������');
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
	
	public function Buy()
	{
		$result = Order::makeOrder($this->current_exchange, self::buy_value, 'buy');
		
		if ($result)
		{	 
		$price = $this->current_exchange->buy*self::buy_value*(1+self::fee);
		$this->balance=$result['balance'];
		$this->balance_btc=$result['balance_btc'];
		
		if ($result['order']->status == 'open')
		{
			Log::Add($this->curtime, '<b>������� ������ �� ������� '.self::buy_value.' ��. �� '.$this->current_exchange->buy.' ('.$this->current_exchange->buy*(self::fee).' ��������) �� ����� '.$price.' ���.</b>', 1);
		}
		else {
			Btc::buy($result['order']);
			Log::Add($this->curtime, '<b>��������� ������� '.self::buy_value.' ��. �� '.$this->current_exchange->buy.' ('.$this->current_exchange->buy*(self::fee).' ��������) �� ����� '.$price.' ���.</b>', 1);
		}
		
		return(true);
		}
		
		return false;
	}
	
	private function Sell($btc)
	{	
		$result = Order::makeOrder($this->current_exchange, $btc->count, 'sell', $btc->id);
		
		
		if ($result)
		{	
			$price = $this->current_exchange->sell*$btc->count*(1-self::fee);	
			
			if ($result['order']->status == 'open')
				Log::Add($this->curtime, '<b>������ ������ �� ������� (�'.$btc->id.')  '. $btc->count.' ��. (������� �� '.$btc->summ.') �� '.$price.', ����� = '.($price-$btc->summ).' ���.</b>', 1);
			else
			{
				Btc::sell($result['order']);
				Log::Add($this->curtime, '<b>��������� ������� (�'.$btc->id.')  '. $btc->count.' ��. (������� �� '.$btc->summ.') �� '.$price.', ����� = '.($price-$btc->summ).' ���.</b>', 1);
			}
			$this->total_income+=$price-$btc->summ;
			return(true);
		}
	
		return false;
	}
	
	public function NeedBuyRandom()
	{
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);
	
		// ��������� ���� �� ��� ������� �� ��������� �����
		$key = 'last_buy';
		$tm = Yii::app()->cache->get($key);
		if ($tm && $tm>$this->curtime)	return false;
		Yii::app()->cache->set($key, $this->curtime+self::min_buy_interval, self::min_buy_interval);
		
		if ($this->balance<$this->current_exchange->buy*self::buy_value)
		{
			Log::AddText($this->curtime, '�� ������� �����, �������� '.$this->balance.', ����� '.($this->current_exchange->buy*self::buy_value));
			return false;
		}
		
		if (rand(0, 100) == 1)
			$this->buy();
	}
	
	public function NeedBuy()
	{		
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);
		$lastBuy = Btc::getLastBuy();
		
		// ��������� ���� �� ��� ������� �� ��������� �����, ���� ���� � ���� ���� ����� �������� ��� ������� �� �� ��������
		$cache_key = 'last_buy';
		$tm = Yii::app()->cache->get($cache_key);		
		
		if ($tm && $tm>$this->curtime && $lastBuy->price - $this->current_exchange->buy < $this->imp_dif) return false;
		
		
		// ���� �� ������
		if ($this->balance<$this->current_exchange->buy*self::buy_value) 
		{
			Log::Add($this->curtime, '�� ������� �����, �������� '.$this->balance.', ����� '.($this->current_exchange->buy*self::buy_value));
			return false;
		}
		
		// ���� ������� ���� ���� ������� �� ��������		
		if ($this->avg_buy && $this->avg_buy<$this->current_exchange->buy)
		{
			Log::Add($this->curtime, '���� ���� ������� �� 7 ���� ('.$this->avg_buy.'<'.$this->current_exchange->buy.'), �� ��������.');
			return false;
		}
		
		//���������� � ���������� ������� 15 ����, 30 ����, 1 ���, 2 �����
		$periods = array(15*60, 30*60, 60*60, 2*60*60, 6*60*60, 24*60*60);
		$tracks=array();
		foreach($periods as $period)		
			$tracks[] = $this->getGraphImage($curtime, $period, 'buy');			
		
								// Log::AddText($this->curtime, '����� '.print_r($tracks, true));
								// Dump::d($tracks);
								
		//����������� �����
		$tracks = $this->getBuyTracks($tracks);
		if (!$tracks || sizeof($tracks) == 0) return false;		
								//Log::AddText($this->curtime, "�������� ����� ".print_r($tracks, true));
		
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
			if ($this->buy())			
			// ����������� ����� �������
				foreach($tracks as $track)	
				{
					//Log::AddText($this->curtime, '���� <b>'.$track['track'].'</b> �� '.($track['period']/60).' ���.');
					//Dump::d($track);
					$this->ReservePeriod($track['period']);					
				}	
					//Log::AddText($this->curtime, '������ ������� ��: '. date('Y-m-d H:i:s',$this->curtime+self::min_buy_interval));
				Yii::app()->cache->set($cache_key, $this->curtime+self::min_buy_interval, self::min_buy_interval);

				
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
			//Log::AddText($this->curtime, '���� ���� ������� �� 7 ���� ('.$this->avg_sell.'>'.$this->current_exchange->buy.'), �� �������.');
			return false;
		}		
		
		//���������� ������� 9, 15, 30 �����, 1 ���
		$periods = array(9*60, 15*60, 30*60, 60*60);
		$tracks=array();
		foreach($periods as $period)
		{
			$tracks[] = $this->getGraphImage($curtime, $period, 'sell');
		}	
		//Log::AddText($this->curtime, '����� '.print_r($tracks, true));
		//Dump::d($tracks);
		
		//����������� �����
		$tracks = $this->getSellTracks($tracks);
		
		if (sizeof($tracks) == 0) return false;
		
		//Log::AddText($this->curtime, '���� ���������� ����� ��� �������'.print_r($tracks, true));
		
		
		//������� ��� �������
		$bought = Btc::model()->with('sell')->findAll(array('condition'=>'sold=0'));
		
		foreach($bought as $btc)
		{
			// ���� �������
			$curcost = $btc->count*$this->current_exchange->sell*(1-self::fee);
						
			// ������� ���������� ��� �������
			$income = $curcost - $btc->summ;
						
			// ���������� �� ����������
			if ($income < self::min_income)
			{
				if ($income>0)
				Log::Add($this->curtime, '�� ������� (�'.$btc->id.'), ����� ������� ��� '.$income.' < '.self::min_income.' ����� �� '.$btc->summ.' ����� ������� �� '.$curcost.' sell='.$this->current_exchange->sell);
				
				//Dump::d($btc->attributes);
				continue;
			}
			
			$this->sell($btc);
			//Dump::d($tracks);
		}
		
		
	}
	

	public function checkOrders()
	{
		$orders = Order::model()->findAll(array('condition'=>'status="open"'));
					
		foreach($orders as $order)
		{
			$order_status = 'closed';
			
			if ($order->type == 'buy')
			{
				// @todo - ���� �� �� - ������� ������, ����� �������
				// @todo - �������� �� �� �������� ������
					
				// ��������� ��������� ������ ����� API
				// �������� ��� ��			
				$order->status='close';
				$order->close_dtm=date("Y-m-d H:i:s", $this->curtime);
				$order->update(array('status', 'close_dtm'));
				
				Btc::buy($order);			
				
				$this->balance_btc+=$order->count; 
				$this->order_cnt++;
			} elseif ($order->type == 'sell')
			{
				$order->status='close';
				$order->close_dtm=date("Y-m-d H:i:s", $this->curtime);
				$order->update(array('status', 'close_dtm'));
				
				if ($order->btc_id)
				{					
					Btc::sell($order);
				}			
				
				$this->balance_btc-=$order->count;
				$this->balance+=$order->summ;
				$this->order_cnt++;
				
			}
		}
	}
	
	public function run()
	{
		$this->NeedBuy();
		$this->NeedSell();
		$this->checkOrders();
		
		Status::setParam('balance', $this->balance);
		Status::setParam('balance_btc', $this->balance_btc);
		
		if ($this->order_cnt>0)
		{
				
			Log::AddText($this->curtime, '������ (���.): '.$this->balance, 1);
			//Log::Add(0, '����� ����������: '.$this->total_income, 1);
			Log::AddText($this->curtime, '������� btc: '.round($this->balance_btc, 5), 1);
		}
		
	}
	
	
}
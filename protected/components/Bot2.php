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
	
	const imp_dif = 0.02; // ������� ���������
	const min_buy = 0.01; // ���. ����� �������
	const buy_value = 0.01; // ������� ��������
	const fee = 0.002; // ��������
	const min_buy_interval = 120; // ���. �������� ���������� ������� = 2 ���.
	const min_income = 10; // ���. ����� � ������
	 
	
	public function __construct($exchange)
	{
		$this->current_exchange = $exchange;
		$this->curtime = strtotime($exchange->dt);
		
		$this->balance = Status::getParam('balance');
		$this->balance_btc = Status::getParam('balance_btc');
		$this->total_income=0;
		
		$this->order_cnt=0;
		
	}
	
	/**
	 * �������� ����������� ������� �� ������ - -0+
	 * @param  $period - ������ ������� � ���.
	 * @param $name - buy, sell
	 */
	public function getGraphImage($curtime, $period, $name)
	{
		$step = round($period/4);
		$from = date('Y-m-d H:i:s', $curtime-$period);
		$to = date('Y-m-d H:i:s', $curtime);
		
		$connection = Yii::app()->db;
		$sql = "
				SELECT 
					avg(".$name.") as val,
					from_unixtime(round(UNIX_TIMESTAMP(dt)/(".$step."))*".$step.", '%Y-%m-%d %H:%i:%s')as dtm 
				FROM `exchange`
				where
					dt between '".$from."' and '".$to."' 
				group by dtm
				order by dt
				";
		
		
		
		$command = $connection->createCommand($sql);
		$list=$command->queryAll();
		
		$track="";
		$prev=false;		
		foreach($list as $item)
		{
			// ���������� �����
			if ($item['dtm']<$from || $item['dtm']>$to) continue;
			
			if (!$prev)
			{
				$prev = $item['val'];
				continue;
			}
			
			
			// ���������� �����������
			$dif = ($item['val']-$prev)/$item['val'];			
			if ($dif<(-1*self::imp_dif)) $track.="-";
			elseif ($dif>self::imp_dif) $track.="+";
			else $track.="0";
			
			if ($name == 'sell' && $track=='00-')
			Log::AddText($this->curtime, '���='.$item['val'].' ����='.$prev.' ����='.$dif.' => '.$track);
			
		}
		
		$result = array(
				'track'=>$track,
				'from' => date('Y-m-d H:i:s', time()-$period),
				'step' => $step,
				'period'=>$period,
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
				case '-0+':	$result[] = $track; break; // \_/
				case '--+':	$result[] = $track; break; // \\/
				case '00+':	$result[] = $track; break; // __/
				case '0-+':	$result[] = $track; break; // _\/				
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
				case '00-':	$result[] = $track; break; // --\
				case '0+-':	$result[] = $track; break; // -/\
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
	
	private function Buy()
	{
		

		if ($order=Order::makeOrder($this->current_exchange, self::buy_value, 'buy'))
		{	 
		$price = $this->current_exchange->buy*self::buy_value*(1+self::fee);
		$this->balance-=$price;
		Log::AddText($this->curtime, '<b>������� ������ �� ������� '.self::buy_value.' ��. �� '.$this->current_exchange->buy.' ('.$this->current_exchange->buy*(self::fee).' ��������) �� ����� '.$price.' ���.</b>');
		return(true);
		}
		
		return false;
	}
	
	private function Sell($btc)
	{	
		if ($order=Order::makeOrder($this->current_exchange, $btc->count, 'sell', $btc->id))
		{
			$price = $this->current_exchange->buy*$btc->count*(1-self::fee);			
			Log::AddText($this->curtime, '<b>������ ������ �� ������� (�'.$btc->id.')  '. $item->count.' ��. (������� �� '.$btc->summ.') �� '.$price.', ����� = '.($price-$btc->summ).' ���.</b>');
			$this->total_income+=$price-$btc->summ;
			return(true);
		}
	
		return false;
	}
	
	public function NeedBuy()
	{		
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);
		
		// ���� �� ������
		if ($this->balance<$this->current_exchange->buy*self::buy_value) 
		{
			Log::AddText($this->curtime, '�� ������� �����, �������� '.$this->balance.', ����� '.($this->current_exchange->buy*self::buy_value));
			return false;
		}
		
		//���������� ������� 15 ����, 30 ����, 1 ���
		$periods = array(15*60, 30*60, 60*60);
		$tracks=array();
		foreach($periods as $period)
		{
			$tracks[] = $this->getGraphImage($curtime, $period, 'buy');			
		}
		// Log::AddText($this->curtime, '����� '.print_r($tracks, true));
		// Dump::d($tracks);
		
		//����������� �����
		$tracks = $this->getBuyTracks($tracks);
		if (sizeof($tracks) == 0) return false;		
		//Log::AddText($this->curtime, "�������� ����� ".print_r($tracks, true));
		
		//������� ����� �� ������� ��� ���� �������
		foreach($tracks as $key=>$track)		
			if ($this->AlreadyBought($track['period']))		
			{
			//	Log::AddText($this->curtime, '��� ���� ������� �� ����� '.print_r($track, true));
				unset($tracks[$key]);
			}
		//Log::AddText($this->curtime, '���������� ����� ���������� ����� '.print_r($tracks, true));
			
		// ���� �������� �����
		if (sizeof($tracks)>0)
		{
			// ��������
			if ($this->buy())			
			// ����������� ����� �������
				foreach($tracks as $track)	
				{
					Log::AddText($this->curtime, '���� <b>'.$track['track'].'</b> �� '.($track['period']/60).' ���.');
					$this->ReservePeriod($track['period']);
				}			
		}				
		else
		Log::AddText($this->curtime, '��� ���������� �������');		
	}
	
	public function NeedSell()
	{
		Log::Add($this->curtime, '�������');
		$curtime = $this->curtime; //���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);		
		
		//���������� ������� 15 ����, 30 ����, 1 ���
		$periods = array(15*60, 30*60, 60*60);
		$tracks=array();
		foreach($periods as $period)
		{
			$tracks[] = $this->getGraphImage($curtime, $period, 'sell');
		}		
		
		
		//����������� �����
		$tracks = $this->getSellTracks($tracks);
		
		if (sizeof($tracks) == 0) return false;
		
		Log::Add($this->curtime, '���� ���������� ����� ��� �������'.print_r($tracks, true));
		
		
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
				//if ($income>0)
				Log::Add($this->curtime, '�� ������� (�'.$btc->id.'), ����� ������� ��� '.$income.' < '.self::min_income);
				continue;
			}
			
			Log::Add($this->curtime, '�������� ����, ������� ������ � �������� �����'.$income);
			
			$this->sell($btc);
		}
		
		
	}
	

	public function checkOrders()
	{
		$orders = Order::model()->findAll(array('condition'=>'status="open"'));
	
		foreach($orders as $order)
		{
			
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
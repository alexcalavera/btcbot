<?php

/**
 * ��������� �� MySQL
 * @author Zaretskiy.E
 *
 */
class Bot2 {
	
	private $current_exchange;
	
	const imp_div = 0.01; // ������� ���������
	const min_buy = 0.01; // ���. ����� �������
	const buy_value = 0.01; // ������� ��������
	const fee = 0.002; // ��������
	const min_buy_interval = 120; // ���. �������� ���������� ������� = 2 ���. 
	
	public function __construct($exchange)
	{
		$this->current_exchange = $exchange;
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
			$dif = $item['val']-$prev;			
			if ($dif<(-1*self::imp_div)) $track.="-";
			elseif ($dif>self::imp_div) $track.="+";
			else $track.="0";			
		}
		
		$result = array(
				'track'=>$track,
				'from' => date('Y-m-d H:i:s', time()-$period),
				'step' => $step,
				);
		
		return($result);
	} 
	
	private function isRealyNeedBuy($tracks)
	{
		foreach($tracks as $track)
		{
			$ret = false;
			switch($track['track']){
				case '-0+':	$ret = true; break; // \_/
				case '--+':	$ret = true; break; // \_/
				case '00+':	$ret = true; break; // __/
				case '0-+':	$ret = true; break; // _\/
				default: $ret = false; break;
			}
			
			if ($ret) 
			{
				Log::AddText(0, "�������� ������� ".$track['track'].' ������� � '.$track['from'], 3);
				return $ret;
			}
		}
	}
	
	private function Buy()
	{
		$price = $this->current_exchange->buy*self::buy_value*(1+self::fee);	
		Log::AddText('<b>������� ������ �� ������� '.self::buy_value.' ��. �� '.$this->current_exchange->buy.' ('.$this->current_exchange->buy*(self::fee).' ��������) �� ����� '.$price.' ���.</b>', 1);
	}
	
	public function NeedBuy($curtime)
	{
		//���� ��������
		$dt = date('Y-m-d H:i:s', $curtime);
		Log::AddText($dt);
		// ������ �� ������������� �������
		$lastbuy = Btc::getLastBuy(); // �������� ���� ��������� �������
		if (time()-strtotime($lastbuy->dtm)<self::min_buy_interval) return;
		
		//���������� ������� 15 ����, 30 ����, 1 ���
		$periods = array(15*60, 30*60, 60*60);
		$tracks=array();
		foreach($periods as $period)
		{
			$tracks[] = $this->getGraphImage($curtime, $period, 'buy');			
		}
		Log::AddText('�����'.print_r($tracks, true));
		//Dump::d($tracks);
		
		// @todo ������ �������� � ������������� ������� �� ����� �������
		
		//����������� ��������, ���� �������� ��������� - ��������
		if($this->isRealyNeedBuy($tracks))
		{
			$this->buy();
		}
		Log::AddText('��� ���������� �������');
		
		
	}
	
}
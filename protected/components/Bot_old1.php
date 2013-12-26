<?php

/**
 * ������ ������ ����
 * �������� � �������
 * @author Zaretskiy.E
 *
 */
class Bot_old1 {
	
	//�������������� ����������
	private $imp_div; 	   // ������� ��� ������� ������� ������/������� = 1%	
	private $buy_value;
	//private $buy_sum;
	private $fee; // �������� �� ��������
	private $buystep_n; // ����� ��������������� ������ ��� ������� �������
	private $sellstep_n; // ����� ��������������� ��� ������� ������� 
	private $analize_period; // ������ �� ������� ����������� ������ (6 �����)
	private $bought; // ������ �������
	private $order_cnt;
	private $balance; // ������� ������
	private $balance_btc; // ������� ������
	private $total_income; // ����� ����������
	private $min_income; // ���. �����
	private $min_buy;
	
	public $virtual = 1; // 0 - �������� ������, 1 - ����������� ������, 2 - ������ �� ����������
	
	
	public function __construct()
	{
		//�������������� ����������
		$this->fee = 0.2/100; // �������� �� ��������
		$this->imp_div = 1/100; 	   // ������� ��� ������� ������� ������/������� = 1%
		//$this->buy_sum = 350; // �������� �� 300 ���.
		$this->buy_value = 0.01; // ������� ��������
		$this->buystep_n = 5; // ������� �� ... ������
		$this->sellstep_n = 4; // ������� �� ... ������
		$this->analize_period = 60*60*1; // ������ �� ������� ����������� ������ (6 �����)
		
		$this->min_income = 10; // ���. �����
		$this->balance = Status::getParam('balance');
		$this->balance_btc = Status::getParam('balance_btc');
		
		$this->order_cnt=0;		
		$this->bought = Buy::model()->with('sell')->findAll();	
		$this->total_income=Sell::getTotalIncome();
	}
	
	private function getDirection($exdata, $type)
	{
		
		$len = sizeof($exdata);
		$last = $exdata[$len-1];
		
		// ���������� ���������� �� ������
		$dif = $last[$type]-$exdata[0][$type];
		Log::Add($last['dt'], '����������: ');
		Log::Add($last['dt'], '������� '.$last[$type].' - '.$exdata[0][$type].' = '.$dif);
		$dif = round($dif /$last[$type],4); // ���� �� ���������� ��������
		Log::Add($last['dt'], '������� % �������: '.$dif*100);
			
		// ���������� ����������� ������
		if ($dif<-1*$this->imp_div) $stok_direction=-1;
		elseif($dif>$this->imp_div) $stok_direction=1;
		else $stok_direction=0;
		
		return $stok_direction;
	}
	
	private function AnalizeSell($exdata)
	{
		$exlen = sizeof($exdata);
		$prev_stok_direction=0;// ���������� �����������
		$stok_direction=0; 	   // ������� �����������
		$cansell=false;
		
		
		
		for($i=0;$i<$exlen;$i++)
		{
		$exitem = $exdata[$i];
		
		// ���� ���� ��� �������������
		if ($i<=$this->buystep_n+1) continue;
		
		//���������� �����������
		$exstep = array_slice($exdata, $i-$this->sellstep_n, $this->sellstep_n+1);
		$stok_direction = $this->getDirection($exstep, 'sell');
		Log::Add($exitem['dt'], '����������� �����: '.$stok_direction);

		// ��������� �����
		if ($prev_stok_direction!=$stok_direction)
			$cansell=true;
		
		
		// ���� ����������� ������
		if ($this->virtual == 1) {
			// ���� �� ����� �� ���������� (�����������) �������� �� �������
			if ($i < $exlen-1)
				$cansell=false;
		}
		
		
		// ��������� �������
		if ($stok_direction == -1 && $cansell) // ���� ������ ����� �������
		{
			// ���� ��� �������
			foreach($this->bought as &$item)
			{
				
				
				if ($item->sold == 1) continue;				
				if ($item->dtm>$exitem['dt']) continue;

				// ���� �������			
				$curcost = $item->count*$exitem['sell']*(1-$this->fee);
				
				// ������� ���������� ��� �������
				$income = $curcost - $item->summ;
				 
				// ���� ����� ���������� �������
				if ($income>$this->min_income)
				{
					
					$this->balance+=$curcost; // ������������� ������ RUB
					$this->balance_btc-=$item->count; // ������������� ������ BTC					
					$this->total_income+=$income; // ������������� �����
					$item->SellIt($exitem['sell']*(1-$this->fee), $item->count, $income);
					$this->order_cnt++;
					$cansell=false; // ��������� ������� �� ����� �������
					Log::Add($exitem['dt'], '<b>������ (�'.$item->id.')  '. $item->count.' ��. (������� �� '.$item->summ.') �� '.$curcost.', ����� = '.$income.' ���.</b>', 1);
				}
				elseif($income>0)
					Log::Add($exitem['dt'], '�� ������� (�'.$item->id.'), ����� ������� ��� '.$income.' < '.$this->min_income, 1);
			}
		}
		
		$prev_stok_direction=$stok_direction;
		}
	}
	
	private function AnalizeBuy($exdata)
	{
		$exlen = sizeof($exdata);
		$prev_stok_direction=0;// ���������� �����������
		$stok_direction=0; 	   // ������� �����������		
		$lastbuy = Buy::getLast(); // �������� ���� ��������� �������
		$canbuy=false;
		
		for($i=0;$i<$exlen;$i++)
		{
		$exitem = $exdata[$i];
			
		// ���� ���� ��� �������������
		if ($i<=$this->buystep_n+1) continue;
			

		//���������� �����������
		$exstep = array_slice($exdata, $i-$this->buystep_n, $this->buystep_n+1);
		$stok_direction = $this->getDirection($exstep, 'buy');
		Log::Add($exitem['dt'], '����������� �����: '.$stok_direction);

		// ��������� �����
		if ($prev_stok_direction!=$stok_direction)
			$canbuy=true;
		
		// ���� ������ �� ��������� ������� - ������ �� ��������
		if ($exitem['dt']<=$lastbuy->dtm)
			$canbuy=false;
		
		if ($this->virtual == 1) {
			// ���� �� ����� �� ���������� (�����������) �������� �� ��������
			if ($i < $exlen-1)
				$canbuy=false;			
		}		
		
		// ��� �����
		if ($this->virtual == 1 && $i == $exlen-1 && !$canbuy) 
			Log::Add($exitem['dt'], '�� �����, ���� �� ��������: '.$prev_stok_direction.' => '.$stok_direction, 1);
		
		// ��������� �������
		if ($stok_direction == 1 && $canbuy) // ���� ������ ����� ����
		{
		
			// ���� ����� ������� ������ ������� �� ��������� �� �������		
			/*if ($this->buy_sum>$this->balance)
				$this->buy_sum=$this->balance;
				
				$buy_value = floor(($this->buy_sum / $exitem['buy']*(1+$this->fee))*10000)/10000;

			// ���� 0 �� ������ ���������
			if ($buy_value == 0) continue;
						*/		 
			
						
			$price = $exitem['buy']*$this->buy_value*(1+$this->fee);		
			// ��������� ������� ����� �� �������, ���� ��������� - �������
				if ($this->balance-$price<0) break;
	
				// ��������
				$btc = new Buy();
				$btc->dtm = $exitem['dt'];
				$btc->count = $this->buy_value;
				$btc->price = $exitem['buy'];
				$btc->summ = $price;
				if($btc->save())
				{
					$this->bought[]=$btc;				
						
					$this->total_buy+=$price; // ����� �������
					$this->balance-=$price; // ������������� ������ RUB
					$this->balance_btc+=$this->buy_value; // ������������� ������ BTC				
					$this->order_cnt++;   // ����������� ����� ������
					$canbuy=false; // ��������� ������� �� ����� �����
					Log::Add($exitem['dt'], '<b>����� (�'.$btc->id.') '.$this->buy_value.' ��. �� '.$exitem['buy'].' ('.$exitem['buy']*($this->fee).' ��������) �� ����� '.$price.' ���.</b>', 1);
				}
		}
		
			
		$prev_stok_direction=$stok_direction;
		}
	}
	
	/**
	 * �������������� ���� �� ������ ������
	 */
	public function runTest()
	{		
		// �������� ���������� �� ������
		$period_from = date('Y-m-d H:i:s', time()-$this->analize_period);
		$exdata = Exchange::getDataFrom($period_from); // �������� ������ �����
		
		//$this->balance = 1000;
		//$exdata = Exchange::getTestData();
		
		Log::Add(0, '��������: ');
		$this->AnalizeBuy($exdata);
		Log::Add(0, '�������: ');
		$this->AnalizeSell($exdata);
				
		Status::setParam('balance', $this->balance);
		Status::setParam('balance_btc', $this->balance_btc);
		
		if ($this->order_cnt>0)
		{		
			
			Log::Add(0, '������ (���.): '.$this->balance, 1);
			Log::Add(0, '����� ����������: '.$this->total_income, 1);
			Log::Add(0, '������� btc: '.round($this->balance_btc, 5), 1);		
		}
		
		
		
		
	}
	
}
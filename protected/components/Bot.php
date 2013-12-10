<?php


class Bot {
	
	//�������������� ����������
	private $imp_div; 	   // ������� ��� ������� ������� ������/������� = 1%	
	private $buy_value;
	private $buy_sum;
	private $fee; // �������� �� ��������
	private $buystep_n; // ������� �� 5 ������
	private $analize_period; // ������ �� ������� ����������� ������ (6 �����)
	private $bought; // ������ �������
	private $order_cnt;
	private $balance;
	
	
	public function __construct()
	{
		//�������������� ����������
		$this->fee = 0.2/100; // �������� �� ��������
		$this->imp_div = 0.5/100; 	   // ������� ��� ������� ������� ������/������� = 1%
		$this->buy_sum = 100; // �������� �� 100 ���.		
		$this->buystep_n = 10; // ������� �� 5 ������
		$this->analize_period = 60*60*6*10; // ������ �� ������� ����������� ������ (6 �����)
		
		$this->bought = array(); // ������ �������
		$this->order_cnt=0;		
		$this->bounght = Btc::model()->findAll();
	}
	
	private function AnalizeSell($exdata)
	{
		
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
	
	private function AnalizeBuy($exdata)
	{
		$exlen = sizeof($exdata);
		$prev_stok_direction=0;// ���������� �����������
		$stok_direction=0; 	   // ������� �����������		
		$lastbuy = Btc::getLastBuy(); // �������� ���� ��������� �������
		
		for($i=0;$i<$exlen;$i++)
		{
		$exitem = $exdata[$i];
			
		// ���� ���� ��� �������������
		if ($i<=$this->buystep_n+1) continue;
			

		//���������� �����������
		$exstep = array_slice($exdata, $i-$this->buystep_n, $this->buystep_n);
		$stok_direction = $this->getDirection($exstep, 'buy');
		Log::Add($exitem['dt'], '����������� �����: '.$stok_direction);

		// ��������� �����
		if ($prev_stok_direction!=$stok_direction)
		$canbuy=true;

		// ���� ������ �� ��������� ������� - ������ �� ��������
		if ($exitem['dt']<=$lastbuy->dtm)
		$canbuy=false;
		
		// ��������� �������
		if ($stok_direction == 1 && $canbuy) // ���� ������ ����� ����
		{
		

		// ���� ����� ������� ������ ������� �� ��������� �� �������		
		if ($this->buy_sum>$this->balance)
			$this->buy_sum=$this->balance;		
		 
		$buy_value = floor(($this->buy_sum / $exitem['buy']*(1+$this->fee))*10000)/10000;
		
		// ���� 0 �� ������ ���������
		if ($buy_value == 0) continue;		
		
		$price = $exitem['buy']*$buy_value*(1+$this->fee);		
		// ��������� ������� ����� �� �������, ���� ��������� - �������
			if ($this->balance-$price<0) break;

			// ��������
			$btc = new Btc();
			$btc->dtm = $exitem['dt'];
			$btc->count = $buy_value;
			$btc->price = $exitem['buy'];
			$btc->summ = $price;
			if($btc->save())
			{
				$this->bought[]=$btc;				
					
				$this->total_buy+=$price; // ����� �������
				$this->balance-=$price; // ������������� ������ RUB
				$this->balance_btc+=$buy_value; // ������������� ������ BTC
				$this->order_cnt++;   // ����������� ����� ������
				$canbuy=false; // ��������� ������� �� ����� �����
				Log::Add($exitem['dt'], '<b>������ '.$buy_value.' ��. �� '.$exitem['buy'].' �� ����� '.$price.'</b>');
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
		
				
		
		$this->balance = Status::getParam('balance');
		
		// �������� ���������� �� ������
		$period_from = date('Y-m-d H:i:s', time()-$this->analize_period);
		$exdata = Exchange::getDataFrom($period_from); // �������� ������ �����
		
		$this->AnalizeBuy($exdata);
				
		Status::setParam('balance', $this->balance);
		
		echo '<br/>';
		echo '<br/>';
		
		Log::Add(0, '������ (���.): '.$this->balance);
		//Log::Add(0, '����� ����������: '.$total_income);
		Log::Add(0, '������� btc: '.$this->balance_btc);
		
		
		
		
	}
	
}
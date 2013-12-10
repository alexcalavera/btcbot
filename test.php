<?php 

function logit($dt, $data)
{
	echo '<i>'.$dt.'</i> '.$data.'<br/>';
}

/*
 * ��������� ���� ������
 * dt, sum
 */
$stok=array();

/* ���������
 * dt, cnt, summ, price
 * 
 */
$bought = array();

// ������� ��� ������� ������� ������/�������
$imp_div = 1/100;

// ����� ����� ��� ���������
$step_cnt = 2;

// ����������� ������� (-1,0,1)
$stok_direction=0;
$prev_stok_direction=0;

// ������� ��������
$buy_value=0.01;

// �������� �� ��������
$fee = 0.2/100; //0.2%

// ���. ��������� (���.)
$min_income = 1;

// ����� ����������
$total_income=0;

// ����� �������
$total_buy=0;

// ��������� ������
$balance = 5000;
$fbalance = $balance;
$balance_btc=0;

// ���� ������
$dt_from=$dt_to=false;

// ����� ������
$order_cnt=0;

// ��������� ��������/���������
$canbuy = true;
$cansell = true;

if (($handle = fopen("data.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 100, ";")) !== FALSE) {
       
    	$stok[]=$data;
    	
    	// ���������� ���� ������ � �����
    	if (!$dt_from) $dt_from=$data[0];
    	$dt_to = $data[0];
    	
    	
    	// ���� ���� ��� �������������
    	if (sizeof($stok)<=$step_cnt+1) continue;    	
    	
    	
    	
    	/*
    	// ���������� ������� ����������
    	$pos = sizeof($stok)-1;    	
    	logit($data[0], '��������� ������ '.$stok[$pos][0].' - '.$stok[$pos-$step_cnt][0]);
    	
    	logit($data[0], '������������� ��������:');
    	$sum_dif=0;
    	for ($i=$step_cnt;$i>0;$i--)
    	{	
    		$sum_dif+= $stok[$pos-$i][1]-$stok[$pos-$i-1][1];    		
    		logit($data[0], 'C���� ������� '.$sum_dif.' = '.$stok[$pos-$i][1].' - '.$stok[$pos-$i-1][1]);
    	}
    	$dif = $sum_dif / $step_cnt; // ����������� �������
    	logit($data[0], '������� ����������� �������: '.$dif);
    	*/
    	
    	// ���������� �������� ���������� �� ������
    	$pos = sizeof($stok)-1;
    	$dif = $stok[$pos][1]-$stok[$pos-$step_cnt][1];    	
    	logit($data[0], '����������: ');
    	logit($data[0], '������� '.$stok[$pos][1].' - '.$stok[$pos-$step_cnt][1].' = '.$dif);
    	
    	
    	$dif = round($dif / $stok[$pos][1],4); // ���� �� ���������� ��������
    	logit($data[0], '������� % �������: '.$dif*100);
    	
    	// ���������� ����������� ������
    	if ($dif<-1*$imp_div) $stok_direction=-1;
    	elseif($dif>$imp_div) $stok_direction=1;
    	else $stok_direction=0;
    	logit($data[0], '����������� �����: '.$stok_direction);
    	
    	// ��������� �����
    	if ($prev_stok_direction!=$stok_direction)
    	{
    		$canbuy=true;
    		$cansell=true;
    	}
    	
    	
    	
    	// ��������� �������
    	if ($stok_direction == 1 && $canbuy) // ���� ������ ����� ����
    	{
    		$price = $data[1]*$buy_value*(1+$fee);
    		
    		// ��������� ������� ����� �� �������
    		if ($balance-$price<0) continue;   		
    			
    		// ��������
    		$bought[]=array(
    				'dt'=>$data[0],
    				'cnt'=>$buy_value,
    				'price'=>$data[1],
    				'summ'=>$price
    				);
    		
    		
    		$total_buy+=$price; // ����� �������
    		$balance-=$price; // ������������� ������ RUB
    		$balance_btc+=$buy_value; // ������������� ������ BTC
    		$order_cnt++;   // ����������� ����� ������
    		$canbuy=false; // ��������� ������� �� ����� �����
    		logit($data[0], '<b>������ '.$buy_value.' ��. �� '.$data[1].' �� ����� '.$price.'</b>');
    	}
    	
    	// ��������� �������
    	if ($stok_direction == -1 && $cansell) // ���� ������ ����� �������
    	{
    		// ���� ��� �������
    		foreach($bought as &$item)
    		{
    			if (isset($item['sell'])) continue;
    			
    			$curcost = $item['cnt']*$data[2]*(1-$fee);
    			// ������� ���������� ��� �������
    			$income = $curcost - $item['summ'];
    			
    			// ���� ����� ���������� �������
    			if ($income>$min_income)
    			{
    				$balance+=$income; // ������������� ������ RUB
    				$balance_btc-=$item['cnt']; // ������������� ������ BTC
    				$total_income+=$income; // ������������� �����
    				$item['sell']=$income;
    				$cansell=false; // ��������� ������� �� ����� �������
    				logit($data[0], '<b>������� '. $item['cnt'].' ��. (������� �� '.$item['summ'].') �� '.$curcost.', ����� = '.$income.'</b>');    				
    			}
    		}
    	}
    	
    	$prev_stok_direction = $stok_direction;
    }
    fclose($handle);
}


echo '<br/>';
echo '<br/>';

logit(0, '�������� ������ (���.): '.$fbalance);
logit(0, '����� ����������: '.$total_income);
logit(0, '������� btc: '.$balance_btc);
logit(0, '������: '.$dt_from.' - '.$dt_to);




?>
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
$imp_div = 5/100;

// ����� ����� ��� ���������
$step_cnt = 2;

// ����������� ������� (-1,0,1)
$stok_direction=0;
$prev_stok_direction=0;

// ������� ��������
$buy_value=0.1;

// �������� �� ��������
$fee = 0.2/100; //0.2%

// ���. ��������� (���.)
$min_income = 100;

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

if (($handle = fopen("data.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 100, ";")) !== FALSE) {
       
    	$stok[]=$data;
    	
    	// ���������� ���� ������ � �����
    	if (!$dt_from) $dt_from=$data[0];
    	$dt_to = $data[0];
    	
    	
    	// ���� ���� ��� �������������
    	if (sizeof($stok)<=$step_cnt) continue;    	
    	
    	// ���������� ������� ����������
    	$pos = sizeof($stok)-1;
    	//logit($data[0], '��������� ������ '.$stok[$pos][0].' - '.$stok[$pos-$step_cnt][0]);
    	
    	//logit($data[0], '������������� ��������:');
    	for ($i=0;$i<$step_cnt;$i++)
    	{	
    		$sum_dif = $stok[$pos-$i][1]-$stok[$pos-$i-1][1];
    	//	logit($data[0], $stok[$pos-$i][1]);
    	}
    	$dif = $sum_dif / $step_cnt; // ����������� �������
    	//logit($data[0], '������� ����������� �������: '.$dif);
    	$dif = round($dif / $stok[$pos][1],4); // ���� �� ���������� ��������
    	//logit($data[0], '������� % �������: '.$dif*100);
    	
    	// ���������� ����������� ������
    	if ($dif<-1*$imp_div) $stok_direction=-1;
    	elseif($dif>$imp_div) $stok_direction=1;
    	else $stok_direction=0;
    	//logit($data[0], '����������� �����: '.$stok_direction);
    	
    	// ��������� �������
    	if ($stok_direction == 1 && ($prev_stok_direction == 0 || $prev_stok_direction == -1)) // ���� ������ ����� ����
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
    		logit($data[0], '<b>������ '.$buy_value.' ��. �� '.$data[1].' �� ����� '.$price.'</b>');
    		$total_buy+=$price;
    		$balance-=$price;
    		$balance_btc+=$buy_value;
    		$order_cnt++;
    		
    	}
    	
    	// ��������� �������
    	if ($stok_direction == -1 && ($prev_stok_direction == 0 || $prev_stok_direction == 1)) // ���� ������ ����� �������
    	{
    		// ���� ��� �������
    		foreach($bought as &$item)
    		{
    			if (isset($item['sell'])) continue;
    			
    			$curcost = $item['cnt']*$data[1]*(1-$fee);
    			// ������� ���������� ��� �������
    			$income = $curcost - $item['summ'];
    			
    			// ���� ����� ���������� ��������
    			if ($income>$min_income)
    			{
    				$balance+=$income;
    				$balance_btc-=$item['cnt'];
    				$total_income+=$income;
    				$item['sell']=$income;
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
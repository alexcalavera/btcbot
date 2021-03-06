<?php

/**
 * Аналитика на MySQL
 * @author Zaretskiy.E
 *
 * @todo Определение кол.ва закупа - когда курс падает от среднего за двое суток на 20%, пускай бот покупает 5 минимумов
 * @todo Бот должен искать прошлую вершину при анализе ямы и принятии решения о покупке. И от неё отсчитывать поздно или нет.
 */
class Bot {
	
	public $current_exchange;
	public $curtime;
	public $balance;
	public $balance_btc;
	private $order_cnt;
	private $total_income;

	


	private static $self=false;
	private $analize;	
	public $api; 
	
	private $tomail=array(); // Собираем сюда то что нужно отправить на email;
	

	
	const min_order_val = 0.011; // Мин. сумма покупки
	const buy_value = 0.02; // Сколько покупать
	const fee = 0.002; // Комиссия
	const min_buy_interval = 86400; // 86400; // Мин. интервал совершения покупок = 1 сутки
	const min_sell_interval = 86400;// 12 часов // Мин. интервал совершения продаж = 1 сутки
	const min_income = 0.04; // Мин. доход - 4%
	const income_per_year = 1; // доход в день для залежных покупок, в расчете на 100% в год
	const order_ttl = 600; // 180
	const min_income_time = 900; // Минимальное время отведенное на рост курса
	
	const freeze_warning_income = 0.005; // доход при котором есть шанс вморозить деньги, считается при падении
	
	const start_balance = 400; //$
	
	public function __construct($exchange=false)
	{
		if (!$exchange)
			$exchange = Exchange::getLast();			
			
		$this->current_exchange = $exchange;
		$this->curtime = strtotime($exchange->dtm);
		
		$this->balance = Status::getParam('balance');
		$this->balance_btc = Status::getParam('balance_btc');		
		
		$this->order_cnt=0;		
		$this->total_income=0;
				
		$this->api = APIProvider::get_Instance();
		$this->analize = new Rempel();
		
		
		self::$self = $this;
	}
	
	public static function get_Instance()
	{
		if (!self::$self)
			self::$self = new Bot();
		return self::$self;
	}

	// Создание отложенного ордера (если сразу не купили)
	private function createOrderRemains($result, $price, $type, $reason, $obj=false)
	{
		$order = new Order();
		$order->price = $price;
		$order->count = $result['remains'];
		$order->summ = $order->count * $price;

		// Комиссия может быть в btc а может быть в rur
		if ($type == 'buy')
			$order->fee = $order->count*self::fee;
		else
			$order->fee = $order->summ*self::fee;
		
		if ($obj)
			$order->assignObj($obj);
		
		$order->description = json_encode($reason);
		$order->type = $type;
		$order->status = 'open';
		$order->create_dtm = $this->current_exchange->dtm;
		
		$order->id = $result['order_id'];
		$order->save();
		
		return ($order);
	}
	
	// Создание закрытого ордера на покупку(если сразу купили)
	private function createOrderReceived($result, $price, $type, $reason, $obj=false)
	{
		$order = new Order();
		$order->price = $price;
		$order->count = $result['received'];
		$order->summ = $order->count * $price;
		
		// Комиссия может быть в btc а может быть в rur
		if ($type == 'buy')
			$order->fee = $order->count*self::fee;
		else
			$order->fee = $order->summ*self::fee;
				
		if ($obj)
			$order->assignObj($obj);
		
		$order->id = null;
		$order->description = json_encode($reason);
		$order->type = $type;		
		$order->status = 'close';
		$order->create_dtm = $this->current_exchange->dtm;
		$order->close_dtm = $this->current_exchange->dtm;
		
		$order->save();
		
		return ($order);
	}
	
	private function makeOrder($cnt, $price, $type, $reason='', $obj=false)
	{		
		// Цена покупки / продажи
	//	$price = $this->current_exchange->$type;
		
		// Пытаемся создать заказ на бирже
		$result = $this->api->makeOrder($cnt, 'btc_usd', $type, $price);

		if (!$result) return false;
		
		$orders = array();
		if ($result['remains']>0) $orders['remains'] = $this->createOrderRemains($result, $price, $type, $reason, $obj);
		if ($result['received']>0) $orders['received'] = $this->createOrderReceived($result, $price, $type, $reason, $obj);
		
		
		// Актуализируем баланс
		$this->setBalance($result['funds']['usd']);
		$this->setBalanceBtc($result['funds']['btc']);		
		
		$this->order_cnt+=sizeof($orders);
		
		return($orders);
	}
	
	/**
	 * Подготовка к покупке (создание ордера, записей в бд)
	 * @return boolean
	 */
	public function startBuy($sell, $cnt, $reason)
	{		
		// Создаем ордер		
		$orders = $this->makeOrder($cnt, $this->current_exchange->buy, 'buy', $reason, $sell);		
		
		if (!$orders) return false;
		
		// Если создался
		if (sizeof($orders)>0)
		{	
			
			// Если сразу купили
			if (isset($orders['received']))
			{
				// Пишем в сводку
				Balance::add('usd', 'Создан ордер №'.$orders['received']->id.' на покупку '.$orders['received']->count.' btc', -1*$orders['received']->summ);
				
				// Отнимаем потраченные деньги
				$sell = $orders['received']->sell;
				$sell->buyed+=$orders['received']->summ;
				$sell->update(array('buyed'));
				
				$this->completeBuy($orders['received']);
			}
			
			// Если отложенная покупка
			if (isset($orders['remains']))
			{
				// Пишем в сводку
				Balance::add('usd', 'Создан ордер №'.$orders['remains']->id.' на покупку '.$orders['remains']->count.' btc', -1*$orders['remains']->summ);
				
				// Отнимаем потраченные деньги
				$sell = $orders['remains']->sell;
				$sell->buyed+=$orders['remains']->summ;
				$sell->update(array('buyed'));
				
				
				Log::Add('<b>Создан ордер на покупку '.$orders['remains']->count.' ед. за '.$orders['remains']->price.' ('.($orders['remains']->fee).' комиссия) на сумму '.$orders['remains']->summ.' руб.</b>', 1);
			}			
			
		return(true);
		}
		
		return false;
	}
	
	/**
	 * Подготовка к продаже (создание ордера, записей в бд)
	 * @return boolean
	 */
	public function startSell($buy, $reason)
	{	

		// Создаем ордер
		$orders = $this->makeOrder($buy->count, $this->current_exchange->sell, 'sell', $reason, $buy);
		
		if (sizeof($orders)>0)
		{	
			// Если сразу продали
			if (isset($orders['received']))
			{
				// Присваиваем BUY номер заказа по которому оно будет продано
				$orders['received']->buy_id = $buy->id;
				$orders['received']->update('buy_id');
				
				// Пишем в сводку
				Balance::add('btc', 'Создан ордер №'.$orders['received']->id.' на продажу '.$orders['received']->count.' btc', -1*$orders['received']->count);
				
				$this->completeSell($orders['received']);
			}
			
			// Если отложенная покупка
			if (isset($orders['remains']))
			{
				// Присваиваем BUY номер заказа по которому оно будет продано
				$orders['remains']->buy_id = $buy->id;
				$orders['remains']->update('buy_id');				
				
				// Пишем в сводку
				Balance::add('btc', 'Создан ордер №'.$orders['remains']->id.' на продажу '.$orders['remains']->count.' btc', -1*$orders['remains']->count);
				Log::Add('<b>Создал сделку на продажу (№'.$buy->id.')  '. $orders['remains']->count.' ед. (куплено за '.$buy->summ.') за '.$orders['remains']->price.', комиссия='.$orders['remains']->fee.', доход = '.($orders['remains']->summ - ($buy->summ / $buy->count) * $orders['remains']->count  - $buy->fee - $orders['remains']->fee).' руб.</b>', 1);
			}		
			
			
			return(true);
		}
	
		return false;
	}
	
	public function completeBuy($order)
	{
		
		if ($order->status == 'open')
		{
			$order->close($this->current_exchange->dtm);
			$order->save();
		}
		
		// Фиксируем в базе покупку
		$buy = Buy::make($order);		
		
		// Для актуализации баланса при тесте с задержкой		
		if (APIProvider::isVirtual)
			$this->balance_btc = $this->api->CompleteVirtualBuy($order);
		
		// Пишем в сводку
		Balance::add('btc', 'Закрыт ордер №'.$order->id.' на покупку '.$order->count.' btc', $order->count);
		Balance::add('btc', 'Начислена комиссия '.$order->fee.' btc', -1 * $order->fee);
		Log::Add('<b>Совершена покупка №'.$buy->id.' '.$order->count.' ед. за '.$order->price.' ('.$order->fee.' btc комиссия) на сумму '.$order->summ.'$.</b>', 1);
				
		$this->tomail[]='<b>Совершена покупка №'.$buy->id.' '.$order->count.' ед. за '.$order->price.' ('.$order->fee.' btc комиссия) на сумму '.$order->summ.'$.</b>';
		
		$this->order_cnt++;
	}
	
	public function completeSell($order)
	{

		if ($order->status == 'open')
		{
			$order->close($this->current_exchange->dtm);
			$order->save();
		}
	
		$sell=Sell::make($order);
		
		// Для актуализации баланса при тесте покупок с задержкой		
		if (APIProvider::isVirtual)
			$this->balance = $this->api->CompleteVirtualSell($order);
		
		// Пишем в сводку
		Balance::add('usd', 'Закрыт ордер №'.$order->id.' на продажу '.$order->count.' btc', $order->summ);
		Balance::add('usd', 'Начислена комиссия '.$order->fee.' usd', -1*$order->fee);
		Log::Add('<b>Совершена продажа (№'.$order->buy->id.')  '. $order->count.' ед. (купленых за '.$order->buy->summ.') за '.$sell->summ.', комиссия='.$sell->fee.', доход = '.($sell->income).' $.</b>', 1);
		$this->tomail[]='<b>Совершена продажа (№'.$order->buy->id.')  '. $order->count.' ед. (купленых за '.$order->buy->summ.') за '.$sell->summ.', комиссия='.$sell->fee.', доход = '.($sell->income).' $.</b>';
		
		$this->total_income+=$sell->income;
		$this->order_cnt++;
	}
	
	public function NeedBuy()
	{		
		echo "Проверяем, нужно ли покупать".PHP_EOL;

		$reason = array(); // Фиксируем причину покупки
		
		$curtime = $this->curtime; //Дата операции		
        $reason['info'] = 'Время: '.date('Y-m-d H:i:s')." покупка ". $this->current_exchange->buy . ", продажа " .$this->current_exchange->sell;
		// Есть ли деньги
		
		if ($this->balance<$this->current_exchange->buy*self::buy_value) 
		{
			Log::notbuy('Не хватает денег, осталось '.$this->balance.', нужно '.($this->current_exchange->buy*self::buy_value));
			return false;
		}
		else
			$reason['balance']='Хватает денег '.$this->balance.'>'.($this->current_exchange->buy*self::buy_value); 
	
		/*
		// Если текущая цена выше средней не покупаем
		$from = date('Y-m-d H:i:s',$this->curtime-60*60*24*7);
		$avg_buy = Exchange::getAvg('buy', $from,  date('Y-m-d H:i:s', $this->curtime));
		if ($avg_buy && $avg_buy<$this->current_exchange->buy)
		{
			Log::notbuy('Цена выше средней за 7 дн. ('.$avg_buy.'<'.$this->current_exchange->buy.'), не покупаем.');
			return false;
		}
		else
			$reason['avg_price']='Цена ниже средней за 7 дн. '.('.$avg_buy.'>'.$this->current_exchange->buy.');
			*/
		
		$lastBuy = Buy::getLast();
		$lastSell = Sell::getLast();

		
		
		if ($lastBuy)
		{
			$tm = strtotime($lastBuy->dtm)+self::min_buy_interval;			
			$diff_buy = (1 - $this->current_exchange->buy / $lastBuy->price);
			
			if ($lastSell)  $diff_sell = (1 - $this->current_exchange->buy / $lastSell->price);

			if ( $tm > $this->curtime 								// была ли уже покупка за последнее время 
				&& $diff_buy < $this->analize->buy_imp_dif  					// и цена была более выгодная
				&&  (!$lastSell  || $lastSell->dtm < $lastBuy->dtm	// и небыло до этого продажи
						|| $diff_sell < $this->buy_imp_dif	// или была но цена была ниже текущей цены покупки
					)
				)
			{	
					// Не покупаем		
					Log::notbuy('Уже была покупка '.(($this->curtime-strtotime($lastBuy->dtm))/60).' мин. назад (допустимы покупки раз в '.(self::min_buy_interval/60/60).' часов. при отсутствии ощутимого падения цены), прошлая цена '.$lastBuy->price.' руб., текущая '.$this->current_exchange->buy.' руб., разница '.($diff_buy*100).'% , мин. порог для покупки '.($this->analize->buy_imp_dif*100).'%.');
					if ($lastSell) Log::notbuy('Прошлая продажа была '.$lastSell->dtm.', это до последней покупки '.$lastBuy->dtm);
					return false;
				
			}
			else {
				$reason['last_buy'] = 'Прошлая покупка была '.(($this->curtime-strtotime($lastBuy->dtm))/60).' мин. назад (допустимы покупки раз в '.(self::min_buy_interval/60/60).' часов. при отсутствии ощутимого падения цены), прошлая цена '.$lastBuy->price.' руб., текущая '.$this->current_exchange->buy.' руб., разница '.($diff_buy*100).'% , мин. порог для покупки '.($this->analize->buy_imp_dif*100).'% ';
				if ($lastSell) $reason['last_sell'] = 'Прошлая продажа была '.$lastSell->dtm.', это после последней покупки '.$lastBuy->dtm.' и цена последней покупки '.$lastSell->price.' выше текущей '.$this->current_exchange->buy;
			}
		}
		
		$all_tracks = $this->analize->getAllTracks($curtime, 'buy');
		
		
		//Анализируем треки
		$tracks=array();
		$tracks = $this->analize->getBuyTracks($all_tracks);
		
		if (!$tracks || sizeof($tracks) == 0) 
		{
			Log::notbuy('Не найдено подходящих для покупки треков');			
			return false;
		}
		

		foreach($tracks as $key=>$track)	
		{	
			//Удаляем треки по которым уже были покупки			
			if (Exchange::AlreadyBought_period($track['period'], $this->curtime))		
			{
				Log::notbuy('Уже была покупка '.$track['period'].' назад по треку '.print_r($track, true));
				unset($tracks[$key]);
				continue;
			}
			
			// Удаляем треки которые происходят из ям по которым уже были покупки
			$last_pit = Exchange::getLastPit($track['period']);
			if ($last_pit == $track['pit']['dtm'])
			{
				Log::notbuy('Уже была покупка в яме '.$track['pit']['dtm'].' по треку '.print_r($track, true));
				unset($tracks[$key]);
			}
			
		}
	
		
		// Если остались треки
		if (sizeof($tracks)>0)
		{
			echo "Найдены удачные треки".PHP_EOL;

			// Треки
			$reason['tracks']=$tracks;
			$reason['all_tracks'] = $all_tracks;
			
			// Берем первый удачный трек и по нему проводим покупку
			reset($tracks);
			$first_track = current($tracks);
			$reason['period'] = $first_track['period'];
			
			//Смотрим, с какой продажи покупать
			$sells = Sell::getNotBuyed();			
			// Если не с чего поупать выходим
			if (sizeof($sells) == 0) return false;

			foreach ($sells as $sell)
			{	
						
				// Расчитываем обратную прибыль						
				$cnt = self::buy_value; // кол-во
				$cost = $this->current_exchange->buy * $cnt; // стоимость
				$summ = $sell->summ - $sell->buyed;
				
				// Если после покупки не останется денег на ещё одну такую же, то берем на все
				if ($cost * 2 > $summ) { 
					$cost = $summ; 
					$cnt = round($cost / $this->current_exchange->buy, 6); // В случае округления в большую сторону денег может не хватить
				}
				$old_cost = $sell->price*$cnt; // Стоимость по старой цене
				
				$income = $old_cost - $cost;
	
				$need_income = $old_cost * $this::getMinIncome($this->curtime, $sell->dtm);
							
				// Достаточно ли заработаем
				if ($income < $need_income)
				{
					if ($income>0) Log::notbuy('Не купили (№'.$sell->id.'), доход слишком мал '.$income.' < '.$need_income.'. Продали за '.$old_cost.' можно купить за '.$cost.' цена покупки='.$this->current_exchange->buy);
					continue;
				}

				//echo "Найдена подходящая продажа".$sell->id.PHP_EOL;
				$reason['sell'] = 'Найдена подходящая продажа №'.$sell->id.' с доходом от сделки '.$income.'$., что составляет '.($income/$sell->summ*100).'% от цены покупки. А требуется не менее '.$need_income.'$';
				
					// Покупаем
					if ($this->startBuy($sell, $cnt, $reason))	
					{	
						// Резервируем время покупки по резерву 
						Exchange::ReservePeriod($first_track['period'], $this->curtime);
						// Резервируем яму				
						Exchange::ReservePit($first_track['pit']['dtm'], $first_track['period']);
						continue;
					}
					else
						Log::notbuy('Ошибка, не удалось начать покупку');
			
			}
		}				
		else
		Log::notbuy('Нет интересных покупок');		
	}
	
	public function NeedSell()
	{
		// Составляем причину покупки
		$reason=array();
        $reason['info'] = 'Время: '.date('Y-m-d H:i:s')." покупка ". $this->current_exchange->buy . ", продажа " .$this->current_exchange->sell;
		$curtime = $this->curtime; //Дата операции

		//Смотрим, что продать
		//$bought = Buy::model()->findAll(array('condition'=>'sold=0 and order_id=0'));
		$bought = Buy::getNotSold();
		
		// Если нечего продавать
		if (sizeof($bought) == 0) return false;
		
		/*
		// Если текущая цена ниже средней не продаем
		$from = date('Y-m-d H:i:s',$this->curtime-60*60*24*7);
		$avg_sell = Exchange::getAvg('sell', $from,  date('Y-m-d H:i:s', $this->curtime));
		if ($avg_sell>$this->current_exchange->sell)
		{
			Log::notsell('Цена ниже средней за 7 дн. ('.$avg_sell.'>'.$this->current_exchange->buy.'), не продаем.');
			return false;
		}
		else
			$reason['avg_price'] = 'Текущая цена выше средней за 7 дней '.('.$this->avg_sell.'>'.$this->current_exchange->buy.'); 
		*/		
		
		//Перебираем периоды		
		$all_tracks=$this->analize->getAllTracks($curtime, 'sell');
		
		// Совершаем вынужденные продажи
//		$this->NecesarySell($all_tracks, $bought);
		
		//Анализируем треки
		$tracks = $this->analize->getSellTracks($all_tracks);
		if (sizeof($tracks) == 0)
		{	
			Log::notsell('Нет подходящих треков для продажи');
			return false;
		}
		reset($tracks);
		$first_track = current($tracks);
		
		// Проверка прошлой продажи
		$lastSell = Sell::getLast();
		if ($lastSell)
		{
			$tm = strtotime($lastSell->dtm)+self::min_sell_interval;
			$diff = (1-$lastSell->price / $this->current_exchange->sell);				
			$last_hill = Exchange::getLastSellHill();
			
			$lastBuy = Buy::getLast();
			
			if ($tm>$this->curtime // Если с прошлой покупки не вышло время
					&& $diff < $this->analize->sell_imp_dif // и цена не лучше
					&& (!$last_hill || $last_hill == $first_track['hill']['dtm'])// и прошлая продажа была на той же горке
					&& (!$lastBuy || $lastBuy->dtm < $lastSell->dtm) // и с последней продажи небыло покупок
			)
			{
				Log::notsell('Уже была продажа, ждем до '.date('Y-m-d H:i:s', $tm).' текущая цена '.$this->current_exchange->sell.' меньше прошлой '.$lastSell->price);
				if ($last_hill) Log::notsell('Была уже продажа на этой ('.$last_hill.') горке');
				if ($lastBuy) Log::notsell('С последней продажи небыло покупок');
				
				return false;
			}
			else
			{
				$reason['last_sell'] = 'Прошлая продажа была '.(($this->curtime-strtotime($lastSell->dtm))/60).' мин. назад (допустимы продажи раз в '.(self::min_sell_interval/60/60).' ч. при отсутствии ощутимого роста цены), цена отличалась от текущей на '.($diff*100).'%, минимальное отличие должно быть '.($this->analize->sell_imp_dif*100).'% ';
				if ($last_hill) $reason['last_hill'] = 'Прошлая продажа была на горке '.$last_hill.', а текущая на горке '.$first_track['hill']['dtm']; 
			}
		}		
		
		$reason['tracks']=$tracks;
		$reason['all_tracks']=$all_tracks;
		
		// Ищем выгодные продажи
		foreach($bought as $buy)
		{
			// Цена продажи
			$curcost = $buy->count*$this->current_exchange->sell*(1-self::fee);
									
			// Сколько заработаем при продаже (комиссия была уже вычтена в btc при покупке)
			$income = $curcost - $buy->summ;						
		
			$need_income = $buy->summ * $this::getMinIncome($this->curtime, $buy->dtm);
			
			// Достаточно ли заработаем
			if ($income < $need_income)
			{
				if ($income>0) Log::notsell('Не продали (№'.$buy->id.'), доход слишком мал '.$income.' < '.$need_income.'. Купил за '.$buy->summ.' можно продать за '.$curcost.' цена продажи='.$this->current_exchange->sell);							
				continue;
			}			
			
					
			// Записываем причину покупки
			$reason['buy'] = 'Найдена подходящая покупка №'.$buy->id.' с доходом от сделки '.$income.' руб., что составляет '.($income/$buy->summ*100).'% от цены покупки'; 
			//Log::Add('Начало продажи №'.$buy->id);
			
			$reason['period'] = $first_track['period'];
			if ($this->startSell($buy, $reason))
			{				
				
				Exchange::ReserveLastSellHill($first_track['hill']['dtm']); // резервируем холм
				break; 	// не более одной продажи по расчету за раз
			}
			
		}
		
	}
	
	/**
	 * Возвращает накопленный процент прибыли
	 * @param int $fromTome
	 * @param string $fromTome
	 * @return number
	 */
	public static function getMinIncome($toTime, $fromTome)
	{
		// Определяем мин. доход
		$life_days = ceil( ($toTime - strtotime($fromTome))/60/60/24 ); // Число прошедших дней с покупки

		$minPercent = 0.15;

		if ($life_days > 365)	$life_days=365;

		// Расчитываем процент исходя из того что за год прибыль должна измениться с 100% до 15% годовых.
		$incomePercent = ( self::income_per_year - ((self::income_per_year - $minPercent) / 365) * $life_days )/365;

		$days_income = $life_days * $incomePercent; // Ожидаемый доход
		if ($days_income < self::min_income) $days_income = self::min_income; // Если меньше мин. дохода то увеличиваем до мин.		
				
		return $days_income;
	}
	
	// Вынужденная продажа, совершается когда купленный btc может залежаться
	private function NecesarySell($all_tracks, $bought)
	{
		$reason = array();
		//Анализируем треки		
		$tracks = $this->analize->getNecessarySellTracks($all_tracks);
		
		if (sizeof($tracks) == 0)
		{
			return false;
			Log::notsell('Нет подходящих треков для вынужденной продажи');
		}
		reset($tracks);
		$first_track = current($tracks);
		
		// Продаем то что может залежаться
		foreach($bought as $buy)
		{
			// Если с покупки прошло мало времени, то не продаем
			if ($this->curtime - strtotime($buy->dtm) < self::min_income_time) 
			{	
				Log::notsell('Не совершили вынужденную продажу №'.$buy->id.' так как не вышло время с покупки. Купили '.$buy->dtm);
				continue;
			} 
			
			// Цена продажи
			$curcost = $buy->count*$this->current_exchange->sell*(1-self::fee);
			// Сколько заработаем при продаже
			$income = $curcost - $buy->summ*(1+self::fee);
			// Достаточно ли заработаем
			
			if ( ($income>0 && $income/$buy->summ < self::freeze_warning_income) )
			//if (abs($income/$buy->summ) < self::freeze_warning_income)				
			{
				
				$reason['sale'] = 'Вынужденная продажа №'.$buy->id.', купили за '.$buy->summ.', текущая цена '.$curcost.', доход '.$income.' ('.($income/$buy->summ*100).'% < '.(self::freeze_warning_income*100).'%)';
				$reason['tracks']=$tracks;
				$reason['all_tracks']=$all_tracks;
				
				$reason['period'] = $first_track['period'];
				
				$this->startSell($buy, $reason);
				continue;
			}
			//else Log::Add('Вынужденная продажа №'.$buy->id.' не состоялась $income='.$income.' $income/$buy->summ='.($income/$buy->summ).' self::freeze_warning_income='.self::freeze_warning_income);
		
				
		}
			
	}
	
	public function cancelOrder($order)
	{	
		$res = $this->api->CancelOrder($order); 
		
		if ($res['success'] == 1)
		{
			
			$this->setBalance($res['return']['funds']['usd']);
			$this->setBalanceBtc($res['return']['funds']['btc']);
			
			$order->status = 'cancel';
			$order->close_dtm = $this->current_exchange->dtm;
			$order->save();
			
			// Пишем баланс
			if ($order->type == 'buy')			
				Balance::add('usd', 'Отмена ордера №'.$order->id.' на покупку', $order->summ);			
			else
				Balance::add('btc', 'Отмена ордера №'.$order->id.' на продажу', $order->count);

            if ($order->sell !== null)
            {
                // Возвращаем деньги в продажу
                $sell = $order->sell;
                $sell->buyed-=$order->summ;
                $sell->update(array('buyed'));
            }
            else
            if ($order->buy !== null)
            {
                // Возвращаем деньги в продажу
                $buy = $order->buy;
                $buy->sold-=$order->count;
                $buy->update(array('sold'));
            }
		}
	}

	public function checkOrders()
	{	
		
		// Получаем все открытые ордеры по бд
		$orders = Order::model()->findAll(array('condition'=>'status="open"'));
		
		// Если нет заказов ничего не проверяем
		if (sizeof($orders) == 0) return false;
		
		// Получаем активные ордеры		
		$active_orders = $this->api->getActiveOrders();		
				
		//Log::Add('Найдены активные ордеры '.Dump::d($active_orders, true));

		foreach($orders as $order)
		{			
			// Если ордер из базы найден среди активных
			if (isset($active_orders[$order->id]))
			{		
				// Если ордер висит более 3 минут - удаляем
				if ($active_orders[$order->id]['timestamp_created']<$this->curtime-self::order_ttl)
				{
					Log::Add('Отменяем ордер №'.$order->id, 1);
					//Отменить ордер
					$this->cancelOrder($order);			
				}
				else
					Log::Add('Ордер висит менее 3 минут. '.date('Y-m-d H:i:s', $active_orders[$order->id]['timestamp_created']).'<'.date('Y-m-d H:i:s', $this->curtime-self::order_ttl));
				
				// Переходим к следующему ордеру
				continue;
			}
			
			// Если заказ не найден, значит он успешно выполнен (нужно это будет проверить)			
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
		
		
		echo "Run bot".PHP_EOL;
		$info = $this->api->getInfo();
		
		$start_balance = 0;
		$start_balance_btc = 0;
		
		if ($info)
		{
			$this->balance = $info['funds']['usd'];
			$this->balance_btc = $info['funds']['btc'];
			
			Status::setParam('balance', $info['funds']['usd']);
			Status::setParam('balance_btc', $info['funds']['btc']);

			$start_balance = $this->balance;
			$start_balance_btc = $this->balance_btc;
			
			Balance::actualize('usd', $this->balance);
			Balance::actualize('btc', $this->balance_btc);
		}	
		
		
		$this->tomail=array();
		
		$this->NeedBuy();
		$this->NeedSell();		
		$this->checkOrders();
		
		if (sizeof($this->tomail)>0) $this->sendMail();
		
		Status::setParam('balance', $this->balance);
		Status::setParam('balance_btc', $this->balance_btc);
		
		if ($this->order_cnt>0)
		{				
			Log::Add('Баланс на начало');
			Log::Add('Руб: '.$start_balance, 1);			
			Log::Add('Btc: '.$start_balance_btc, 1);
			
			Log::Add('Баланс на конец');
			Log::Add('Руб: '.$this->balance, 1);
			Log::Add('Btc: '.$this->balance_btc, 1);		
				
			Log::Add('Всего заработано: '.$this->total_income, 1);
		}
		
		
		
	}
	
	private function sendMail()
	{
		$text='';
		foreach ($this->tomail as $item)
			$text.=$item.' <br/>';

		$headers  = 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
		
		mail('gorcer@gmail.com', 'Btcbot - Новые сделки', $text, $headers);
	}
	
	public function setBalance($summ)
	{
		$this->balance = round($summ, 5);
	}

	public function setBalanceBtc($summ)
	{
		$this->balance_btc = round($summ, 5);
	}
	
	
	public static function getAvgMargin($period, $pair='btc_usd')
	{
		$connection = Yii::app()->db;
		$sql = "
				SELECT AVG( t.val ) 
				FROM (				
					SELECT ABS( MIN( buy ) - MAX( sell ) )/MIN( buy ) AS val,
					from_unixtime(round(UNIX_TIMESTAMP(dtm)/(".$period."))*".$period.", '%Y.%m.%d %H:%i:%s')as dt
					FROM `exchange`
					where
					pair = '".$pair."'
					group by dt
				) as t
				";
		
				$command = $connection->createCommand($sql);
				$val=$command->queryScalar();
		
		return round($val,2);
	}
	
}
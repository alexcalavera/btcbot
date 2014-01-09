<?php

return CMap::mergeArray(
	require(dirname(__FILE__).'/main.php'),
	array(
			
		'import'=>array(
					'ext.phpunit.*',
			),
			
		'components'=>array(
			'fixture'=>array(
				'class'=>'system.test.CDbFixtureManager',
			),			 
			'db'=>array(
				'connectionString'=>'mysql:host=localhost;dbname=btcbot_4test',
			),
			
		),
	)
);

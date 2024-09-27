<?php

header("last-modified: ".gmdate("d, d m y h:i:s")." gmt");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("pragma: no-cache");

include("../../inc/init.php");
include("../../inc/_app.php");

$app = new app();
$db = $app->connect();

/*
{
	"auth": "hash",
	"sales": [
		{
			"id": 1,
			"type": "IN",
			"num": "123",
			"date": "09.10.2023",
			"payorder_num": "15",
			"payorder_date": "27.02.2024",
			"client_inn": "",
			"client_kpp": "",
			"client_name": "Meridian (Меридиан) ТОО, заказчик",
			"currency": "978",
			"amount": 1075000
		},
		{
			"id": 2,
			"type": "IN",
			"num": "0301-000002",
			"date": "01.03.2024",
			"payorder_num": "15",
			"payorder_date": "27.02.2024",
			"client_inn": "",
			"client_kpp": "",
			"client_name": "Meridian (Меридиан) ТОО, заказчик",
			"currency": "398",
			"amount": 1075000
		}
	]
}

{
	"auth": "hash",
	"sales": [
		{
			"id": 1,
			"type": "OUT",
			"payorder_num": "123",
			"payorder_date": "09.10.2023",
			"client_inn": "0122909098",
			"client_kpp": "",
			"client_name": "ЭКИП ООО",
			"currency": "643",
			"amount": 18000,
			"num": "612 от 21.02.2024"
		},
		{
			"id": 1,
			"type": "OUT",
			"num": "296487С от 21.02.2024, 296746С от 23.02.2024",
			"payorder_num": "777",
			"payorder_date": "01.03.2024",
			"client_inn": "7715943550",
			"client_kpp": "771501001",
			"client_name": "Первый элемент, ООО",
			"currency": "643",
			"amount": 400000
		}
	]
}
*/

$currencyMap = array(
	'840' => 'USD',
	'978' => 'EUR',
	'398' => 'KZT',
	'643' => 'RUR',
	'156' => 'CNY',
	'860' => 'UZS'
);
$data = json_decode($_REQUEST['data'], true);
if (sizeof($data)) {
	ini_set("max_execution_time", "0");
	
	if (strlen($data['auth']) && ($data['auth'] == Config::API_1C_AUTH_TOKEN)) {
		if (!sizeof($data['sales'])) {
			echo json_encode(array(
				'success' => false,
				'code' => -4,
				'msg' => 'Ошибка данных'
			));
		}
		else {
			$sales = array();
			foreach($data['sales'] AS $bill) {
				try {
					$db->StartTransaction();
				
					$type = $bill['type'];
					$bill['currency'] = $currencyMap[ $bill['currency'] ];
					
					if ($type == 'IN') {
						$db->Query(sprintf("
							SELECT
							t.id,
							t.multimodal,
							t.multimodal_id,
							t.multimodal_num,

							t.client_currency_rate as currency_rate,
							t.client_currency_sum as sum,
							t.client_currency_total as total,
							t.cargoinsuranceclientvalue as cargo_insurance_client,
							t.client_currency as currency,
							cc.paytype as paytype,
							
							c.inn,
							c.name
							
							FROM transportation t
							left outer join contract cc on (t.clientcontract = cc.id)
							left outer join client c on (t.client = c.id)
							WHERE (t.clientinvoicedate = to_timestamp('%s', 'DD.MM.YYYY')) and (t.clientinvoice = '%s')
						", $db->Escape($bill['date']), $db->Escape($bill['num'])));
						$transportations = $db->FetchAllAssoc();
						
						$billnum = sprintf("%s от %s", $bill['num'], $bill['date']);
					} elseif ($type == 'OUT') {
						$filtr = array();
						$billnum = explode(",", $bill['num']);
						if (sizeof($billnum)) foreach($billnum AS $num) {
							$tmp = explode(" от ", $num);
							
							$filtr[] = sprintf("( (t.ferryinvoicedate = to_timestamp('%s', 'DD.MM.YYYY')) and (t.ferryinvoice = '%s') )", $db->Escape($tmp[1]), $db->Escape($tmp[0]));
						}
						
						if (!empty($filtr)) {
							$filtr = implode(" or ", $filtr);

							$db->Query(sprintf("
								SELECT
								t.id,
								t.multimodal,
								t.multimodal_id,
								t.multimodal_num,

								t.ferry_currency_rate as currency_rate,
								t.ferry_currency_sum as sum,
								t.ferry_currency_total as total,
								t.ferry_currency as currency,
								fc.paytype as paytype,
								
								f.inn,
								f.name
								
								FROM transportation t
								left outer join contract fc on (t.ferrycontract = fc.id)
								left outer join ferryman f on (t.ferryman = f.id)
								WHERE %s
							", $filtr));
							$transportations = $db->FetchAllAssoc();
						} else {
							$transportations = array();
						}
						
						$billnum = $bill['num'];
					} else {
						$sales[] = array(
							'id' => (string)$bill['id'],
							'date' => (string)$bill['date'],
							'num' => (string)$bill['num'],
							'type' => (string)$bill['type'],
							'success' => false,
							'code' => -4,
							'msg' => 'Ошибка данных'
						);
						$db->RollBack();
						continue;
					}
					
					if (!empty($transportations)) {
						$billtids = array(); // для отображения в orderimport
						$leftPaym = array((float)$bill['amount']);
						
						//тепличный вариант. все совпало. возможен неправильный остаток
						$payments = array();
						
						//вариант с ошибками
						//попытаться разнести там где совпадает
						//если в процессе какие-то ошибки, то один платеж без перевозок, в комменте номера перевозок, счет, остаток
						$error = array();

						foreach ($transportations as $t) {
							$t = array_change_key_case($t, CASE_LOWER);

							if (!(int)$t['multimodal']) {
								$billtids[] = (int)$t['id'];
							} else {
								$billtids[] = sprintf("%s-%s", (int)$t['multimodal_id'], $t['multimodal_num']);
							}

							$sum = (float)$t['sum'];
							$contractPayType = $t['paytype'];
							$currency = ($contractPayType === 'RUR') ? 'RUR' : $t['currency'];
							if (($currency === 'RUR') && ($t['currency'] !== 'RUR')) {
								$sum = (float)$t['total'];
							}

							if ($type === 'IN') {
								$cargoInsuranceClient = $t['cargo_insurance_client'] ?? 0;
								if ($cargoInsuranceClient > 0 && $currency === 'RUR') {
									$sum = $sum + $cargoInsuranceClient;
								}
							}

							//если валюты не совпадают, нечего вычитать. ошибка
							if ($currency != $bill['currency']) {
								$error[] = $t['id'];
								$payments[] = array(
									'tid' => (int)$t['id'],
									'status' => 2,
									'sum' => 0
								);
								continue;
							}
							
							// считаем остаток
							$db->Query(sprintf("
								select
								sum(p.value) AS sum
								from payment p
								where (p.tid = '%s') and (p.type = '%s') and (p.currency = '%s') and (p.cash = '0')
							", (int)$t['id'], $db->Escape($type), $db->Escape($currency)));
							$tmp = $db->FetchRowAssoc();
							$tmp = array_change_key_case($tmp, CASE_LOWER);
							
							$sum = round($sum - (float)$tmp['sum'], 2);
							
							// если остаток 0 пропуск
							if ($sum <= 0) continue;
							
							$leftPaymInCurrency = round($leftPaym[sizeof($leftPaym)-1] - $sum, 2);
							$leftPaym[] = $leftPaymInCurrency;
							
							if ($leftPaymInCurrency >= 0) {
								$paym = array(
									'tid' => (int)$t['id'],
									'status' => 1,
									'sum' => $sum,
									'rate' => $t['currency_rate']
								);
							}
							elseif ($leftPaym[sizeof($leftPaym)-2] >= 0) {
								$paym = array(
									'tid' => (int)$t['id'],
									'status' => 2,
									'sum' => $leftPaym[sizeof($leftPaym)-2],
									'rate' => $t['currency_rate']
								);
							}
							else
								continue;

							//если контрагент не совпадает, все равно сохраняем
							if (
								(trim($bill['client_inn']) != trim($t['inn'])) ||
								!strlen(trim($bill['client_inn'])) ||
								!strlen(trim($t['inn']))
							)
								$paym['status'] = 2;

							$payments[] = $paym;
						}
						
						//весь остаток в один платеж без перевозок
						if ($leftPaym[sizeof($leftPaym)-1] > 0) {
							$payments[] = array(
								'tid' => 0,
								'status' => 2,
								'sum' => $leftPaym[sizeof($leftPaym)-1]
							);
						}
						
						//сохранение
						if (sizeof($payments)) foreach($payments AS $paym) {
							$query = new iQuery("orderimport");
							$query->Param("uid",		0);
							$query->Param("tid",		$paym['tid']);
							$query->Param("source",		'API');
							$query->Param("currency",	$bill['currency']);
							$query->Param("inouttype",	$type);
							
							if ($type == 'IN') {
								$query->Param("invalue",	$paym['sum']);
							}
							else {
								$query->Param("outvalue",	$paym['sum']);
							}
							$query->Param("contr_inn",		$db->Escape($bill['client_inn']));
							$query->Param("contr_name",		$db->Escape($bill['client_name']));
							$query->Param("payorder",		$db->Escape($bill['payorder_num']));
							$query->Param("payorderdate",	$db->Escape($bill['payorder_date']), "DATE");
							$query->Param("billnum",		$db->Escape($billnum));
							$query->Param("billtid",		$db->Escape(implode($billtids, ", ")));
							$query->Param("status",			$paym['status']);
							$query->Param("currency_rate",	($paym['rate'] === null) ? 'null' : $paym['rate'], 'RAW');
							$db->Exec($query);
						}

						$sales[] = array(
							'id' => (string)$bill['id'],
							'date' => (string)$bill['date'],
							'num' => (string)$bill['num'],
							'type' => (string)$bill['type'],
							'success' => true,
							'code' => 0
						);
					}
					else {
						//платеж не сохрянять т.к весь остаток на последнюю идет
						$sales[] = array(
							'id' => (string)$bill['id'],
							'date' => (string)$bill['date'],
							'num' => (string)$bill['num'],
							'type' => (string)$bill['type'],
							'success' => false,
							'code' => -5,
							'msg' => 'Не найдено'
						);
					}
					
					$db->Commit();
				}
				catch (Exception $e) {
					$db->RollBack();
					
					$sales[] = array(
						'id' => (string)$v['id'],
						'date' => (string)$v['date'],
						'num' => (string)$v['num'],
						'type' => (string)$v['type'],
						'success' => false,
						'code' => -3,
						'msg' => 'Ошибка выполнения'
					);
				}
			}
			
			echo json_encode(array(
				'success' => true,
				'code' => 0,
				'sales' => $sales
			));
		}
	}
	else {
		echo json_encode(array(
			'success' => false,
			'code' => -1,
			'msg' => 'Ошибка авторизации'
		));
	}
}
else {
	echo json_encode(array(
		'success' => false,
		'code' => -2,
		'msg' => 'Ошибка данных'
	));
}

?>

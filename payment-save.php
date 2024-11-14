
<?php

use App\Data\PaymentBalanceService\ProcessParams;
use App\Enums\Contractor;
use App\Enums\Currencies;
use App\Enums\DealStatus;
use App\Enums\PermissionEntity;
use App\Enums\UIPermissions\UIMainMenu;
use App\Enums\UIPermissions\UITransportations;
use App\Enums\UIPermissions\UiTransportationSeeOnlyOwn;
use App\Helpers\DataHelper;
use App\Helpers\PermissionHelper;
use App\Helpers\TransportationHelper;
use App\Helpers\PaymentBalanceCalcHelper;
use App\Helpers\TransportTypeHelper;
use App\Services\DebitCreditService;
use App\Services\PaymentBalanceCalcService;
use App\Services\Profit\ProfitCalculateService;
use App\Services\UIPermissionService;
use App\Traits\BaseTrait;

class transportation3 extends BaseTrait {
    public function __construct($app, $db, $priv, array $permissions) {
        parent::__construct($app, $db, $priv, $permissions, UIMainMenu::TAB_TRANSPORTATIONS_NAME());
    }

	public function checkLicense() {
		$res = array();

		try {
			$res['success'] = true;

			$dtNow = new DateTime();
			if ($dtNow >= $this->app->licDt) {
				$res['success'] = false;
				$res['msg'] = 'Извините, закончился оплаченный период. Обратитесть в обслуживающую организацию.';
				return $res;
			}

			$this->db->Query("select count(*) as cnt from manager where blocked='0'");
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);

			if ((int)$tmp['cnt'] > $this->app->licUsers) {
				$res['success'] = false;
				$res['msg'] = 'Извините, превышено оплаченное количество пользователей. Обратитесть в обслуживающую организацию.';
				return $res;
			}

		}
		catch (Exception $e) {
			$res['success'] = false;
			$res['msg'] = 'sql-ошибка';
		}

		return $res;
	}

	public function onOpen() {
		$res = array();

		try {
			$res['success'] = true;

			$res['priv'] = $this->priv;
			$res['permissions'] = $this->permissions;
			$res['userList'] = $this->app->getUserList(true);
			$res['regionDict'] = $this->app->getDictionary('TRANSPORTATIONREGION');
			array_unshift($res['regionDict'], array('key' => 'ALL', 'value' => "Все"));

			$rateSql = "
				select
				currency, value, to_char(date, 'DD.MM.YYYY') as dt, to_char(date, 'DD.MM') as day, 1 as last
				from rate
				where (provider = '%s') and (
					date = (
						select max(date) from rate where (provider = '%s')
					)
				)
				
				union
				
				select
				currency, value, to_char(date, 'DD.MM.YYYY') as dt, to_char(date, 'DD.MM') as day, 0 as last
				from rate
				where (provider = '%s') and (
					date = (
						select max(date) from rate where (provider = '%s') and (
							date < (
								select max(date) from rate where (provider = '%s')
							)
						)
					)
				)
			";

			$provider = 'cbr.ru';
			$this->db->Query(sprintf($rateSql, $provider, $provider, $provider, $provider, $provider));
			$rates = $this->db->FetchAllAssoc();
			if (sizeof($rates)) foreach($rates AS $v) {
				$v = array_change_key_case($v, CASE_LOWER);
				$path = (int)$v['last'] ? 'last' : 'prev';
				$res['rates']['cbr'][$path]['day'] = $v['day'];
                $value = (float)$v['value'];
                $currency = $v['currency'];
                if ($currency === Currencies::KZT()) {
                    $value = $value * 100;
                }
				$res['rates']['cbr'][$path][$currency] = sprintf('%0.4f', $value);
			}

            if (appClientConfig::$SHOW_NB_KZ_CURRENCY_RATES_BLOCK) {
                $provider = 'nationalbank.kz';
                $this->db->Query(sprintf($rateSql, $provider, $provider, $provider, $provider, $provider));
                $rates = $this->db->FetchAllAssoc();
                if (sizeof($rates)) foreach ($rates as $v) {
                    $v = array_change_key_case($v, CASE_LOWER);
                    $path = (int)$v['last'] ? 'last' : 'prev';
                    $res['rates']['nbkz'][$path]['day'] = $v['day'];
                    $res['rates']['nbkz'][$path][$v['currency']] = sprintf('%0.2f', (float)$v['value']);
                }
            }
        }

		catch (Exception $e) {
			$res['success'] = false;
			$res['msg'] = 'sql-ошибка';
		}

		return json_encode($res);
	}

    public function composeFilter($data, $unloadCheckFilter = 0) {
        $userId = $this->app->userId;

        $filtr = array(appConfig::$transportationDefaultFilter);

        if ($unloadCheckFilter) {
            $this->composeUnloadCheckFilter($filtr);
        } else {
            if (isset($data['id']) && strlen($data['id'])) {
                $tmpIds = array();
                $tmp = explode(",", $data['id']);
                foreach ($tmp as $tmpId) {
                    $tmpId = (int)trim($tmpId);
                    if ($tmpId) $tmpIds[] = $tmpId;
                }
                if (!empty($tmpIds)) {
                    $filtr[] = sprintf("(transportation.multimodal_id in (%s))", implode(",", $tmpIds));
                }
            }

            if (isset($data['createdate1']) && strlen(trim($data['createdate1'])) && $data['createdate1'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.date >= to_timestamp('%s', 'DD.MM.YYYY'))", $this->db->Escape(trim($data['createdate1'])));
            }
            if (isset($data['createdate2']) && strlen(trim($data['createdate2'])) && $data['createdate2'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.date <= to_timestamp('%s 23:59:59', 'DD.MM.YYYY HH24:MI:SS'))", $this->db->Escape(trim($data['createdate2'])));
            }
            if (isset($data['loaddate1']) && strlen(trim($data['loaddate1'])) && $data['loaddate1'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.load >= to_timestamp('%s', 'DD.MM.YYYY'))", $this->db->Escape(trim($data['loaddate1'])));
            }
            if (isset($data['loaddate2']) && strlen(trim($data['loaddate2'])) && $data['loaddate2'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.load <= to_timestamp('%s 23:59:59', 'DD.MM.YYYY HH24:MI:SS'))", $this->db->Escape(trim($data['loaddate2'])));
            }
            if (isset($data['offloaddate1']) && strlen(trim($data['offloaddate1'])) && $data['offloaddate1'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.offload >= to_timestamp('%s', 'DD.MM.YYYY'))", $this->db->Escape(trim($data['offloaddate1'])));
            }
            if (isset($data['offloaddate2']) && strlen(trim($data['offloaddate2'])) && $data['offloaddate2'] != '01.01.1970') {
                $filtr[] = sprintf("(transportation.offload <= to_timestamp('%s 23:59:59', 'DD.MM.YYYY HH24:MI:SS'))", $this->db->Escape(trim($data['offloaddate2'])));
            }
            if (isset($data['client']) && (int)$data['client']) {
                $filtr[] = sprintf("(transportation.client = '%s')", (int)$data['client']);
            }
            if (isset($data['ferryman']) && (int)$data['ferryman']) {
                $filtr[] = sprintf("(transportation.ferryman = '%s')", (int)$data['ferryman']);
            }
            if (isset($data['fromplace']) && strlen(trim($data['fromplace']))) {
                $filtr[] = sprintf("(lower(transportation.ferryfromplace) LIKE lower('%%%s%%'))", $this->db->Escape(trim($data['fromplace'])));
            }
            if (isset($data['toplace']) && strlen(trim($data['toplace']))) {
                $filtr[] = sprintf("(lower(transportation.ferrytoplace) LIKE lower('%%%s%%'))", $this->db->Escape(trim($data['toplace'])));
            }
            if (isset($data['logist']) && (int)$data['logist']) {
                $filtr[] = sprintf("(transportation.logist = '%s')", (int)$data['logist']);
            }
            if (isset($data['manager']) && (int)$data['manager']) {
                $filtr[] = sprintf("(transportation.manager = '%s')", (int)$data['manager']);
            }
            if (isset($data['typets']) && !empty($data['typets'])) {
                $filtr[] = sprintf("(transportation.typets = '%s')", addslashes($data['typets']));
            }
            if (isset($data['accountant']) && (int)$data['accountant']) {
                $filtr[] = sprintf("
                (
                    ((transportation.clientnds = 0) AND (client.accountant = '%s')) OR
                    ((transportation.clientnds = 20) AND (client.accountant_rf = '%s'))
                )
            ", (int)$data['accountant'], (int)$data['accountant']);
            }
            if (isset($data['country'])) {
                $tmp = (int)$data['country'];
                if ($tmp === 1) {
                    $filtr[] = "(transportation.clientnds = 20)";
                } elseif ($tmp === 2) {
                    $filtr[] = "(transportation.clientnds = 0)";
                }
            }
            if (isset($data['ferryfiodriver']) && strlen(trim($data['ferryfiodriver']))) {
                $tmp = trim($data['ferryfiodriver']);
                $tmpE = str_replace(array('Ё', 'ё'), array('Е', 'е'), $tmp);

                $filtr[] = sprintf("
                (
                    (lower(transportation.ferryfiodriver) LIKE lower('%%%s%%')) OR
                    (lower(transportation.ferryfiodriver) LIKE lower('%%%s%%'))
                )
            ", $this->db->Escape($tmp), $this->db->Escape($tmpE));
            }
            if (isset($data['ferrycarnumber']) && strlen(trim($data['ferrycarnumber']))) {
                $filtr[] = sprintf("(lower(transportation.ferrycarnumber) like lower('%%%s%%'))", $this->db->Escape(trim($data['ferrycarnumber'])));
            }
            if (isset($data['region']) && strlen($data['region']) && $data['region'] != 'ALL') {
                $filtr[] = sprintf("(transportation.region = '%s')", $this->db->Escape($data['region']));
            }
            if (isset($data['clientinvoice']) && strlen($data['clientinvoice'])) {
                $tmpIds = array();
                $tmp = explode(",", $data['clientinvoice']);
                foreach ($tmp as $tmpId) {
                    if (strlen(trim($tmpId))) {
                        $tmpIds[] = sprintf("(transportation.clientinvoice like '%%%s%%')", $this->db->Escape(trim($tmpId)));
                    }
                }
                if (!empty($tmpIds)) {
                    $filtr[] = sprintf("(%s)", implode(" or ", $tmpIds));
                }
            }
            if (!isset($this->priv['client']['viewKeyClient']) || !(int)$this->priv['client']['viewKeyClient']) {
                $filtr[] = sprintf("
                ((transportation.client = 0) OR (client.keyclient = '0') OR (transportation.manager = '%s') OR (transportation.logist = '%s'))
            ", $userId, $userId);
            }
            if ($this->priv['transportation']['viewMode'] == 'my') {
                $filtr[] = sprintf("((transportation.manager = '%s') OR (transportation.logist = '%s'))", $userId, $userId);
            }
            if ($this->priv['transportation']['viewMode'] == 'logistExist') {
                $filtr[] = sprintf("((transportation.manager = '%s') OR (transportation.logist > 0))", $userId);
            }
            if (isset($data['viewdeleted']) && (int)$data['viewdeleted'] && isset($this->priv['transportation']['modDelete']) && $this->priv['transportation']['modDelete']) {
                $filtr[] = "(transportation.status = " . DealStatus::DELETED() . ")";
            } else {
                $filtr[] = "(transportation.status = " . DealStatus::ACTIVE() . ")";
            }
        }

        if ($this->priv['transportation']['viewMode'] == 'region') {
            $region = array("(transportation.region = 'DENIED')");
            foreach ($this->priv['transportation']['region'] as $k => $v) {
                if ((int)$v) {
                    $region[] = sprintf("(transportation.region = '%s')", $this->db->Escape($k));
                }
            }
            $filtr[] = sprintf("(%s)", implode(" or ", $region));
        }

        $this->seeOnlyOwnFilter($filtr);

        return $filtr;
    }

	/**
	 * @param array|null $filtr
	 */
	protected function seeOnlyOwnFilter(array &$filtr=null){
		$permissionService = new UIPermissionService();
		$isCheckboxActive = $permissionService->isCheckboxActive(
			$this->permissions,
			UiTransportationSeeOnlyOwn::RESOURCE_NAME(),
			PermissionEntity::RECORD(),
			UiTransportationSeeOnlyOwn::RECORD_SEE_ONLY_OWN_NAME()
		);
		if($isCheckboxActive){
			$userId = $this->app->userId;
			$filtr[] = sprintf("
					( (transportation.manager = '%s') OR (transportation.logist = '%s') )
				", $userId, $userId);
		}
	}

	public function composeUnloadCheckFilter(&$filtr) {
		$userId = $this->app->userId;
		$dateClause = sprintf("(transportation.offload < to_timestamp('%s', 'DD.MM.YYYY'))", date("d.m.Y"));

		$filtr[] = sprintf("
			( ((transportation.manager = '%s') AND (transportation.logist = 0)) OR (transportation.logist = '%s') )
			AND (
				%s
				OR (transportation.offload IS NULL)
				OR (transportation.offload = to_timestamp('01.01.0001', 'DD.MM.YYYY'))
			)
			AND (transportation.offloadchecked = '0')
		", $userId, $userId, $dateClause);
	}

	public function transpGrid() {
        $start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$dir = '';
		$sort = json_decode(req('sort'), true);
		if (preg_match("/asc|desc/i", $sort[0]['direction'], $out)) $dir = $out[0];
        $sortProperty = $sort[0]['property'] ?? null;

        $order = '';
		if (is_array($sort) && sizeof($sort)) {
			if ($sortProperty == 'idstr') $order = "ORDER BY transportation.multimodal_id ".$dir.", transportation.multimodal_num ASC";
			elseif ($sortProperty == 'typets_str') $order = "ORDER BY transportation.typets ".$dir.", transportation.multimodal_id DESC, transportation.multimodal_num ASC";
			elseif ($sortProperty == 'transp_status_txt') $order = "ORDER BY transportation.transp_status ".$dir;
			elseif ($sortProperty == 'date_str') $order = "ORDER BY transportation.date ".$dir;
			elseif ($sortProperty == 'load_str') $order = "ORDER BY transportation.LOAD ".$dir;
			elseif ($sortProperty == 'offload_str') $order = "ORDER BY transportation.OFFLOAD ".$dir;
			//elseif ($sortProperty == 'DIRECTION') $order = "ORDER BY transportation.DIRECTION ".$dir;
			//elseif ($sortProperty == 'DESCRIPTION') $order = "ORDER BY transportation.DESCRIPTION ".$dir;
			//elseif ($sortProperty == 'clientprice') $order = "ORDER BY transportation.CLIENTPRICE ".$dir;
			elseif ($sortProperty == 'clientdocdate') $order = "ORDER BY transportation.CLIENTDOCDATE ".$dir;
			elseif ($sortProperty == 'ferryprice') $order = "ORDER BY transportation.FERRYPRICE ".$dir;
			elseif ($sortProperty == 'clientpayorderdate') $order = "ORDER BY transportation.CLIENTPAYORDERDATE ".$dir;
			elseif ($sortProperty == 'ferrydocdate') $order = "ORDER BY transportation.FERRYDOCDATE ".$dir;
			elseif ($sortProperty == 'ferrypayorderdate') $order = "ORDER BY transportation.FERRYPAYORDERDATE ".$dir;
			elseif ($sortProperty == 'profit') $order = "ORDER BY transportation.PROFIT ".$dir;
			//elseif ($sortProperty == 'profitability') $order = "ORDER BY transportation.PROFITABILITY ".$dir;
		}

        $data = isset($_REQUEST['filtr'])
            ? json_decode($_REQUEST['filtr'], true)
            : [];

		$unloadCheckFilter = (int)$_REQUEST['unloadCheckFilter'];
		$filtr = $this->composeFilter($data, $unloadCheckFilter);
        $filtrby = !empty($filtr)
            ? "WHERE " . implode(" and ", $filtr)
            : '';


		$this->db->Query(sprintf("
			SELECT
			transportation.id,
			transportation.multimodal,
			transportation.multimodal_id,
			transportation.multimodal_num,
			transportation.status,
			userLogin(
				CASE
					WHEN (transportation.clientnds = 0) THEN client.accountant
					WHEN (transportation.clientnds = 20) THEN client.accountant_rf
				END
			) AS client_accountant,
			client.name AS client_name,
			client.manager AS client_manager,
			client.paymdelay as client_paymdelay,
			ferryman.name AS ferryman_name,
			ferryman.manager AS ferryman_manager,
			ferryman.contacts AS ferrycontacts,

			transportation.manager,
			userLogin(transportation.manager) AS manager_login,
			to_char(transportation.date, 'DD.MM.YYYY HH24:MI:SS') AS date_str,
			transportation.logist,
			userLogin(transportation.logist) AS logist_login,
			to_char(transportation.load, 'DD.MM.YYYY HH24:MI') AS load_str,
			to_char(transportation.offload, 'DD.MM.YYYY HH24:MI') AS offload_str,
			transportation.typets as typets,
			transportation.ferryman_typets as ferryman_typets,
			
			transportation.offloadchecked,
			transportation.transp_status,
			-- transportation.clientrequestsend,
			-- transportation.ferryrequestsend,
			transportation.client,
			transportation.clientnds,
			transportation.clientpaid,
			transportation.clientinvoice,
			to_char(transportation.clientinvoicedate, 'DD.MM.YYYY') AS clientinvoicedate_str,
			to_char(transportation.clientdocdate, 'DD.MM.YYYY') AS clientdocdate_str,
			-- to_char(transportation.clientpayorderdate, 'DD.MM.YYYY') AS clientpayorderdate_str,
			transportation.ferrycar,
			transportation.ferryfiodriver,
			transportation.ferryprice,
			transportation.ferrypaid,
			transportation.ferryinvoice,
			to_char(transportation.ferryinvoicedate, 'DD.MM.YYYY') AS ferryinvoicedate_str,
			to_char(transportation.ferrydocdate, 'DD.MM.YYYY') AS ferrydocdate_str,
			-- to_char(transportation.ferrypayorderdate, 'DD.MM.YYYY') AS ferrypayorderdate_str,
			transportation.profit,
			transportation.profitability,
			transportation.profitfact,
			transportation.profitabilityfact,
			
			to_char(transportation.client_plandate, 'DD.MM.YYYY') AS client_plandate_str,
			to_char(transportation.ferry_plandate, 'DD.MM.YYYY') AS ferry_plandate_str,

			transportation.ferryfromplace,
			transportation.ferrytoplace,
			transportation.cargo,
			transportation.cargoprice,
			transportation.cargotemp1,
			transportation.cargotemp2,
			transportation.cargoplacesttn,
			transportation.cargoplaces,
			transportation.cargovolume,
			transportation.cargoweight,
			dictValue('WEIGHTTYPE', cast(transportation.cargoweighttype as varchar)) as cargoweighttype_str,
			transportation.cargoprofile,
			transportation.cargoother,
			
			transportation.ferrycarnumber,
			transportation.ferrycarppnumber,
			transportation.ferryphone,
			
			transportation.client_request_no,
			to_char(transportation.client_request_date, 'DD.MM.YYYY') AS client_request_date_str,
			transportation.client_currency_total,
			transportation.client_currency_sum,
			transportation.client_currency,
			transportation.ferry_currency_sum,
			transportation.ferry_currency,
			transportation.ferrynds,
			transportation.client_sns,
			transportation.ferry_sns,
			transportation.client_currency_leftpaym,
			transportation.ferry_currency_leftpaym,
			ferrymanPerson.phone AS ferrymanperson_phone,
			ferrymanPerson.fio AS ferrymanperson_fio,
			clientPerson.phone AS clientperson_phone,
			clientPerson.fio AS clientperson_fio
			
			FROM transportation
			LEFT OUTER JOIN client ON (client.id = transportation.client)
			LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
			LEFT JOIN person AS ferrymanPerson ON (ferrymanPerson.id = transportation.ferrymanperson)
			LEFT JOIN person AS clientPerson ON (clientPerson.id = transportation.clientperson)
			%s %s
		", $filtrby, $order));
		// возможно придётся добавить поле transportation.ferrymancars_types хотя это полное дублирование
		// правильнее сделать указатель на таблицу ferrymancars в таблице ferryman типа ferryman.ferrymancars_id
		// и поля из ferrymancars вынимать left join'ом
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);

			if (!(int)$item['multimodal'])
				$item['idstr'] = sprintf(appConfig::$IdStrFormat, $item['id']);
			else
				$item['idstr'] = sprintf(appConfig::$IdStrFormat."-%s", $item['multimodal_id'], $item['multimodal_num']);

			if (strlen($item['clientinvoice']) && strlen($item['clientinvoicedate_str']))
				$item['clientinvoicedate_str'] = $item['clientinvoice'] ." от ". $item['clientinvoicedate_str'];
			else
				$item['clientinvoicedate_str'] = '';
			////////
			if (strlen($item['ferryinvoice']) && strlen($item['ferryinvoicedate_str']))
				$item['ferryinvoicedate_str'] = $item['ferryinvoice'] ." от ". $item['ferryinvoicedate_str'];
			else
				$item['ferryinvoicedate_str'] = '';

			///////
			$item['direction'] = $item['ferryfromplace'] ." - ". $item['ferrytoplace'];
			$item['description'] = sprintf("%s, температурный режим: %s/%s, погрузочных мест: %s, объем: %s, вес: %sт.(%s)%s%s"
				, $item['cargo'], $item['cargotemp1'], $item['cargotemp2'], (int)$item['cargoplacesttn'] ? "по ТТН" : $item['cargoplaces'], $item['cargovolume'], $item['cargoweight'], $item['cargoweighttype_str']
				, strlen($item['cargoprofile']) ? ", габариты: ".$item['cargoprofile'] : "", strlen($item['cargoother']) ? ", иное: ".$item['cargoother'] : "");
            $statusDict = TransportationHelper::getStatusList($this->app->getDictionary("TRANSPORTATIONSTATUS"));
            $item['transp_status_txt'] = TransportationHelper::findStatusNameByKey($statusDict, $item['transp_status']);
            $item['typets_str'] = TransportTypeHelper::getTransportTypeStr($item['typets']);
            $item['ferryman_typets_str'] = TransportTypeHelper::getTransportTypeStr($item['ferryman_typets']);

			unset(
				$item['ferryfromplace'],
				$item['ferrytoplace'],
				$item['cargo'],
				$item['cargotemp1'],
				$item['cargotemp2'],
				$item['cargoplacesttn'],
				$item['cargoplaces'],
				$item['cargovolume'],
				$item['cargoweight'],
				$item['cargoweighttype'],
				$item['cargoprofile'],
				$item['cargoother'],
				$item['typets'],
				$item['ferrymanTypets'],
			);

			$t = '';
			if (strlen($item['ferrycar'])) $t .= "\n".$item['ferrycar'];
			if (strlen($item['ferrycarnumber'])) $t .= "\n".$item['ferrycarnumber'];
			if (strlen($item['ferrycarppnumber'])) $t .= "\n".$item['ferrycarppnumber'];
			if (strlen($item['ferryfiodriver'])) $t .= "\n".$item['ferryfiodriver'];
			if (strlen($item['ferryphone'])) $t .= "\n".$item['ferryphone'];
			$item['directiontip'] = sprintf("%s%s%s", $item['direction'], (strlen($item['direction']) && strlen($t)) ? "\n-------" : "", $t);

            $t = $this->getDelay(
                $item['client_paymdelay'],
                $item['clientpaid'],
                $item['ferrypaid'],
                $item['ferrydocdate'] ?? null,
                $item['offload_str']
            );

            $item['delay_client'] = $t['delay_client'];
			$item['delay_client_ind'] = $t['delay_client_ind'];
			$item['delay_ferry'] = $t['delay_ferry'];
			$item['delay_ferry_ind'] = $t['delay_ferry_ind'];

			$item['cargoprice'] = sprintf("%0.2f", round((float)$item['cargoprice']/1000000, 2));

            $item['ferrytip'] = sprintf(
                "%s%s%s",
                $item['ferryman_name'] ?? '',
                (isset($item['ferryman_name']) && strlen($item['ferryman_name']) && isset($item['ferrycontacts']) && strlen($item['ferrycontacts'])) ? "\n-------\n" : "",
                $item['ferrycontacts'] ?? ''
            );

			$ferrynds = array(
				'NDS' => 'с НДС',
				'WONDS' => 'без НДС',
				'ZERONDS' => '0% НДС'
			);
            $item['ferrynds'] = $ferrynds[$item['ferrynds']] ?? '';

			$item['client_request'] = sprintf("%s %s", $item['client_request_no'], $item['client_request_date_str']);
			unset($item['client_request_no'], $item['client_request_date_str']);

			$item['ferrycar'] = array();
			if (strlen($item['ferrycarnumber'])) $item['ferrycar'][] = $item['ferrycarnumber'];
			if (strlen($item['ferrycarppnumber'])) $item['ferrycar'][] = $item['ferrycarppnumber'];
			$item['ferrycar'] = implode("\n", $item['ferrycar']);

			if (!(int)$this->priv['transportation']['modProfit']) {
				unset($item['profit']);
				unset($item['profitfact']);
				unset($item['profitabilityfact']);
			}
			if (!(int)$this->priv['transportation']['modClientBilling']) {
				unset($item['ferryclientprice']);
			}
			if (!(int)$this->priv['transportation']['modFerryBilling']) {
				unset($item['ferryprice']);
			}

            $transportationId = (int)$item['id'];

			$this->db->Query(sprintf("
				SELECT
				ferryman.checkmark as ferrycheckmark,
				ferrymancars.checkmark as ferrycarcheckmark,
				ferrymancars.blmark as ferrycarblmark
				FROM transportation
				left outer join ferryman on (transportation.ferryman = ferryman.id)
				left outer join ferrymancars on (ferryman.id = ferrymancars.ferryman) and (transportation.ferrycar_id = ferrymancars.id)
				WHERE (transportation.id = '%s')
			", $transportationId));
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);
			$item['ferrycheckmark'] = $tmp['ferrycheckmark'];
			$item['ferrycarcheckmark'] = $tmp['ferrycarcheckmark'];
			$item['ferrycarblmark'] = $tmp['ferrycarblmark'];

			$this->db->Query(sprintf("
				SELECT
				client.checkmark
				FROM transportation
				left outer join client on (transportation.client = client.id)
				WHERE (transportation.id = '%s')
			", $transportationId));
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);
			$item['clientcheckmark'] = $tmp['checkmark'];
            $item['docTypeListPresence'] = TransportationHelper::getDocTypeListPresence($transportationId, $this->db);
			$this->db->Query(sprintf("
				select
				data
				from transp_report
				where (objid=%s)
				order by date desc
				limit 1
			", $transportationId));
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);
			$item['last_report'] = $tmp['data'] ?? null;
		}
		unset($item);
		return json_encode($result);
	}

	public function transpData() {
		$id = (int)$_REQUEST['id'];
		$mode = $_REQUEST['mode'];

		return json_encode($this->transpDataProc($id, $mode));
	}

	public function transpDataProc($id, $mode) {
		$userId = $this->app->userId;

		$res = array();

		if (
			($mode == 'new') ||
			( ($mode == 'edit') && $id) ||
			( ($mode == 'copy') && $id) ||
			( ($mode == 'multi') && $id)
		) {
			try {
				$res['priv'] = $this->priv;

				if ($id) {
					$p = new transportation3Object($this->app);
					$p->add_to_model('ferryman_typets_str', array(
						'value' => '',
						'type' => 'string',
						'caption' => 'Тип ТС'
					));
					$p->loadById($id);

					if (($mode == 'copy') || ($mode == 'multi')) {
						$res['allowed'] = array(//TODO
							'allowedCount' => 123132
						);
						$res['data'] = array();
                        $attr = array(
                            'typets',
                            'typets_desc',
							'ferryman_typets',
                            'client',
                            'clientfromplace',
                            'clienttoplace',
                            'cargo',
                            'cargotemp1',
                            'cargotemp2',
                            'cargoplaces',
                            'cargovolume',
                            'cargoweight',
                            'cargoweighttype',
                            'cargoprofile',
                            'cargoother',
                            'cargoprice',
                            'cargopricecurrency',
                            'client_currency',
                            'clientrefund',
                            'cargoinsurance',
                            'cargoinsuranceuspercent',
                            'cargoinsuranceusvalue',
                            'cargoinsuranceclientpercent',
                            'cargoinsuranceclientvalue',
                            'cargoloadtype',
                            'cargounloadtype',
                            'clientrefundpercent',
                            'clientothercharges',
                            'clientotherchargestarget',
                            'clientpricewnds',
                            'clientpricenal',
                            'clientpricedeposit',
                            'clientpaycomment',
							'client_currency_sum',
                            'clientcontract',
                            'clientperson',
                            'clientnds',
                            'ferryfromplace',
                            'ferrytoplace',
                            'region',
                            'client_downtime_currency',
                            'client_downtime_unit',
                            'client_downtime_value',
                            'client_downtime_sum'
                        );
                        foreach ($attr as $a) {
                            $res['data'][$a] = $p->$a;
                        }

                        $clientContractCurrency = $p->client_currency;
						if ($clientContractCurrency === Currencies::RUR()) $res['data']['client_currency_rate'] = 1;
						else $res['data']['client_currency_rate'] = (float)PaymentBalanceCalcHelper::getRateCBR($clientContractCurrency, date("d.m.Y"), $this->db);
						
						if ($mode == 'multi') {
							$res['data']['multimodal_id'] = $p->multimodal_id;
						}
					}
					else {
						$p->allowAll();
						$p->proccessPrivileges();
						$res['allowed'] = $p->getAllowedFields(); //TODO
						$res['data'] = $p->toArray($blocked2Null = true);
						
						if (!(int)$p->multimodal)
							$res['data']['idstr'] = sprintf("%s", $p->id);
						else
							$res['data']['idstr'] = sprintf("%s-%s", $p->multimodal_id, $p->multimodal_num);
					}

					$this->db->Query(sprintf("
						SELECT
						id,
						id as extid,
						to_char(date, 'YYYY-MM-DD HH24:MI') AS date,
						to_char(date, 'YYYY-MM-DD HH24:MI') AS time,
						comment,
						address,
						contacts
						FROM load
						WHERE tid = '%s'
					", $id));
					$res['loadGrid'] = $this->db->FetchAllAssoc();
					if (sizeof($res['loadGrid'])) foreach($res['loadGrid'] AS $ki => &$item) {
						$item = array_change_key_case($item, CASE_LOWER);

						if (($mode == 'copy') || ($mode == 'multi')) {
							unset($item['id']);
							unset($item['extid']);
							unset($item['date']);
							unset($item['time']);
							unset($item['comment']);
						}
					}
					unset($item);

					$this->db->Query(sprintf("
						SELECT
						id,
						id as extid,
						to_char(date, 'YYYY-MM-DD HH24:MI') AS date,
						to_char(date, 'YYYY-MM-DD HH24:MI') AS time,
						comment,
						address,
						contacts
						FROM offload
						WHERE tid = '%s'
					", $id));
					$res['unloadGrid'] = $this->db->FetchAllAssoc();
					if (sizeof($res['unloadGrid'])) foreach($res['unloadGrid'] AS $ki => &$item) {
						$item = array_change_key_case($item, CASE_LOWER);

						if (($mode == 'copy') || ($mode == 'multi')) {
							unset($item['id']);
							unset($item['extid']);
							unset($item['date']);
							unset($item['time']);
							unset($item['comment']);
						}
					}
					unset($item);

					if (($mode != 'copy') && ($mode != 'multi')) {
						$doctpl_type_clause=PermissionHelper::get_doctpl_type_clause($this->permissions, "doctpl");
						$this->db->Query(sprintf("
							SELECT
							doctpl.id,
							doctpl.name
							FROM doctpl
							INNER JOIN doctpl_show objshow ON (doctpl.id = objshow.did) AND (objshow.oid = '3') and (objshow.type = 'obj') and (objshow.show='1')
							WHERE (doctpl.file='1')
							".$doctpl_type_clause."
							ORDER BY doctpl.name
						"));
						$res['sdoctpl'] = $this->db->FetchAllAssoc();
						if (sizeof($res['sdoctpl'])) foreach($res['sdoctpl'] AS $ki => &$item) {
							$item = array_change_key_case($item, CASE_LOWER);
						}
						unset($item);
					}
				}
				else {
					$res['allowed'] = array(//TODO
						'allowedCount' => 123132
					);
					$res['data'] = array(
						'client' => 0,
						'ferryman' => 0,
						'clientnds' => 20
					);
				}

				$res['userList'] = $this->app->getUserList(true);
				$res['managerList'] = $this->app->getUserList(false);
				$res['statusDict'] = TransportationHelper::getStatusList($this->app->getDictionary("TRANSPORTATIONSTATUS"));
				$res['regionDict'] = $this->app->getDictionary('TRANSPORTATIONREGION');
				$res['clientDocdeliveryDict'] = $this->app->getDictionary("TRANSPCLIENTDOCDELIVERY");

				$this->db->Query("
					SELECT
					id as key,
					name as value
					FROM client
					ORDER BY name
				");
				$res['clientList'] = $this->db->FetchAllAssoc();
				if (sizeof($res['clientList'])) foreach($res['clientList'] AS $ki => &$item) {
					$item = array_change_key_case($item, CASE_LOWER);
				}
				unset($item);
				array_unshift($res['clientList'], array('key' => 0, 'value' => "Не задан"));

				$this->db->Query("
					SELECT
					id as key,
					name as value,
					nds
					FROM ferryman
					ORDER BY name
				");
				$res['ferrymanList'] = $this->db->FetchAllAssoc();
				if (sizeof($res['ferrymanList'])) foreach($res['ferrymanList'] AS $ki => &$item) {
					$item = array_change_key_case($item, CASE_LOWER);
				}
				unset($item);
				array_unshift($res['ferrymanList'], array('key' => 0, 'value' => "Не задан"));

                $res['linkedDeals'] = isset($res['data']['id']) && isset($res['data']['multimodal_id'])
                    ? TransportationHelper::getLinkedDeals($this->db, $res['data']['id'], $res['data']['multimodal_id'])
                    : [];

				$res['success'] = true;
			}
			catch (Exception $e) {
				$res['success'] = false;
				$res['msg'] = 'sql-ошибка';
			}
		}
		else {
			$res['success'] = false;
			$res['msg'] = "Ошибка! Нет параметров";
		}

		return $res;
	}

	public function transpSave() {
		$res = array();

		$id = (int)req('id');
		$mode = req('mode');
		if ($mode == 'new') $id = 0;
		$data = json_decode(req('data'), true);

		if (
			($mode == 'new') ||
			(($mode == 'edit') && $id)
		)
		{
			$p = new transportation3Object($this->app);
			$p->loadFromRequest($id, $mode, $data);
			$p->proccessChanged();
			$p->proccessPrivileges();
			$p->prepareSave();
			$res = $p->save();

            $transportationId = $p->id;
			if ($res['success']) {
                $profitCalculateService = new profitCalculateService($this->db);
                $profitCalculateService->updateTransportationProfitPlan($transportationId);
                $profitCalculateService->updateTransportationProfitFact($transportationId);
				$res['id'] = $transportationId;
				$res['data'] = $this->transpDataProc($transportationId, 'edit');
			}
		}
		else {
			$res['success'] = false;
			$res['msg'] = "Ошибка! Нет параметров";
		}

		return json_encode($res);
	}

	public function transpDel() {
		$userId = $this->app->userId;

		$id = (int)$_REQUEST['id'];
		$result = array();

		if ($id && (int)$this->priv['transportation']['modDelete']) {
			$this->db->ExecQuery(sprintf("update transportation set status=0 where id='%s'", $id));

			$tquery = new iQuery("log");
			$tquery->Param("mid",			$userId);
			$tquery->Param("tablename",		"transportation");
			$tquery->Param("tableitemid",	$id);
			$tquery->Param("date",			'now()',	"RAW");
			$tquery->Param("log",			$this->db->Escape("Удаление"));
			$this->db->Exec($tquery);
		}
		$result['success'] = true;

		return json_encode($result);
	}

	public function transpRestore() {
		$userId = $this->app->userId;

		$id = (int)$_REQUEST['id'];
		$result = array();

		if ($id && (int)$this->priv['transportation']['modDelete']) {
			$this->db->ExecQuery(sprintf("update transportation set status=1 where id='%s'", $id));

			$tquery = new iQuery("log");
			$tquery->Param("mid",			$userId);
			$tquery->Param("tablename",		"transportation");
			$tquery->Param("tableitemid",	$id);
			$tquery->Param("date",			'now()',	"RAW");
			$tquery->Param("log",			$this->db->Escape("Восстановление"));
			$this->db->Exec($tquery);
		}
		$result['success'] = true;

		return json_encode($result);
	}

	public function clientContractStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

		$id = (int)req('id');
		$tid = (int)req('tid');
		if ($id) {
			$this->db->Query(sprintf("
				SELECT
				c.id,
				c.type,
				c.no,
				to_char(c.date, 'YYYY-MM-DD') AS date,
				c.name,
				c.currency,
				c.contrlimit,
				c.main,
				(select sum(client_currency_sum) from transportation where clientcontract = c.id) as contrsum
				FROM contract c
				WHERE (c.cid='%s')
				ORDER BY c.date DESC
			", $id));
			$items = $this->db->FetchAllAssoc();
			if (!empty($items)) foreach ($items AS $v) {
				$v = array_change_key_case($v, CASE_LOWER);

				$date = date("d.m.Y");
				if ($tid) {
					$this->db->Query(sprintf("
						select
						to_char(date, 'DD.MM.YYYY') AS date
						from transportation
						where id = %s
					", $tid));
					$date = $this->db->FetchRowAssoc();
					$date = array_change_key_case($date, CASE_LOWER);
					$date = $date['date'];
				}

                $currency = $v['currency'];
                $rate = (float)PaymentBalanceCalcHelper::getRateCBR($currency, $date, $this->db);
                /*if ($currency === Currencies::KZT()) {
                    $rate *= 100;
                }*/

				$result['items'][] = array(
					'id' => (int)$v['id'],
					'sl' => ((float)$v['contrlimit'] > 0) ? round( 100 * (float)$v['contrsum'] / (float)$v['contrlimit'] ) : 0,
					'name' => sprintf(
						"%s из %s / %s %s / %s %s %s",
						number_format((float)$v['contrsum'], 2, '.', ' '),
						number_format((float)$v['contrlimit'], 2, '.', ' '),
						$v['no'], $v['date'], $v['name'], (int)$v['main'] ? '/ Основной' : '', (int)$v['type'] ? '/ Договор-заявка' : '/ Договор'
					),
					'currency' => $currency,
					'rate' => $rate
				);
			}
			$result['totalCount'] = sizeof($result['items']);
		}
		return json_encode($result);
	}

	public function clientPersonStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

		$id = (int)req('id');
		$tid = (int)req('tid');
		if ($id) {
			$this->db->Query(sprintf("
				SELECT
				id,
				fio,
				post,
				dictValue('PERSONPOST', post) as post_text,
				email,
				phone
				FROM person
				WHERE (obj='client') and (objid='%s')
			", $id));

			$items = $this->db->FetchAllAssoc();
            for ($i = 0; $i < sizeof($items); $i++) {
                $item = array_change_key_case($items[$i], CASE_LOWER);
                $result['items'][] = TransportationHelper::fillPersonItems($item);
            }

			$result['totalCount'] = sizeof($result['items']);
		}

		return json_encode($result);
	}

	public function clientLoadFavoriteStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

		$id = (int)req('id');
		if ($id) {
			$this->db->Query(sprintf("
				SELECT
				id,
				address,
				contacts
				FROM favorite
				WHERE (obj='clientload') and (objid='%s')
			", $id));
			$items = $this->db->FetchAllAssoc();
			for($i=0; $i<sizeof($items); $i++) {
				$item = array_change_key_case($items[$i], CASE_LOWER);
				$item['id'] = (int)$item['id'];
				$result['items'][] = $item;
			}
			$result['totalCount'] = sizeof($result['items']);
		}
		return json_encode($result);
	}

	public function ferryContractStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

		$id = (int)req('id');
		$tid = (int)req('tid');
		if ($id) {
			$this->db->Query(sprintf("
				SELECT
				c.id,
				c.type,
				c.no,
				to_char(c.date, 'YYYY-MM-DD') AS date,
				c.name,
				c.currency,
				c.contrlimit,
				c.payby,
				c.paydelay,
				c.main,
				(select sum(ferry_currency_sum) from transportation where ferrycontract = c.id) as contrsum
				FROM contract c
				WHERE (c.fid='%s')
				ORDER BY c.date DESC
			", $id));
			$items = $this->db->FetchAllAssoc();
			if (sizeof($items)) foreach ($items AS $v) {
				$v = array_change_key_case($v, CASE_LOWER);

				$date = date("d.m.Y");
				if ($tid) {
					$this->db->Query(sprintf("
						select
						to_char(date, 'DD.MM.YYYY') AS date
						from transportation
						where id = %s
					", $tid));
					$date = $this->db->FetchRowAssoc();
					$date = array_change_key_case($date, CASE_LOWER);
					$date = $date['date'];
				}

				$map = array(
					'ORIGINAL' => 'по оригиналам',
					'SCAN' => 'по сканам'
				);

                $currency = $v['currency'];
                $rate = (float)PaymentBalanceCalcHelper::getRateCBR($currency, $date, $this->db);
//                if ($currency === Currencies::KZT()) {
//                    $rate *= 100;
//                }

				$result['items'][] = array(
					'id' => (int)$v['id'],
					'sl' => ((float)$v['contrlimit'] > 0) ? round( 100 * (float)$v['contrsum'] / (float)$v['contrlimit'] ) : 0,
					'name' => sprintf("%s из %s / %s %s / %s %s %s",
						number_format((float)$v['contrsum'], 2, '.', ' '),
						number_format((float)$v['contrlimit'], 2, '.', ' '),
						$v['no'], $v['date'], $v['name'], (int)$v['main'] ? '/ Основной' : '', (int)$v['type'] ? '/ Договор-заявка' : '/ Договор'
					),
					'currency' => $currency,
					'rate' => $rate,
					'payby' => $map[ $v['payby'] ],
					'paydelay' => (int)$v['paydelay']
				);
			}
			$result['totalCount'] = sizeof($result['items']);
		}
		return json_encode($result);
	}

	public function ferryPersonStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

        $id = (int)req('id');
        if ($id) {
            $this->db->Query(sprintf("
				SELECT
				id,
				fio,
				post,
				dictValue('PERSONPOST', post) as post_text,
				email,
				phone
				FROM person
				WHERE (obj='ferryman') and (objid='%s')
			", $id));

            $items = $this->db->FetchAllAssoc();
            for ($i = 0; $i < sizeof($items); $i++) {
                $item = array_change_key_case($items[$i], CASE_LOWER);
                $result['items'][] = TransportationHelper::fillPersonItems($item);
            }

            $result['totalCount'] = sizeof($result['items']);
        }

        return json_encode($result);
    }

	public function ferryCarsStore() {
		$result['items'] =  array();
		$result['totalCount'] = 0;

		$id = (int)req('id');
		$filtr = array(
			sprintf("(ferryman='%s')", $id)
		);

		$search = str_replace(array('Ё', 'ё'), array('Е', 'е'), trim($_REQUEST['search']));
		if (strlen($search)) $filtr[] = sprintf("
			(
				(lower(ferrycar) LIKE lower('%%%s%%')) OR
				(lower(ferrycarnumber) LIKE lower('%%%s%%')) OR
				(lower(ferrycarpp) LIKE lower('%%%s%%')) OR
				(lower(ferrycarppnumber) LIKE lower('%%%s%%')) OR
				(lower(ferryfiodriver) LIKE lower('%%%s%%')) OR
				(lower(ferryphone) LIKE lower('%%%s%%')) OR
				(lower(ferrypassport) LIKE lower('%%%s%%'))
			)
		", $this->db->Escape($search), $this->db->Escape($search), $this->db->Escape($search), $this->db->Escape($search), $this->db->Escape($search), $this->db->Escape($search), $this->db->Escape($search));
		if (sizeof($filtr))
			$filtr = "WHERE " . implode(" and ", $filtr);
		else
			$filtr = '';

		if ($id) {
			$this->db->Query(sprintf("	SELECT
						id,
						ferryman,
						ferrycar,
						ferrycarnumber,
						ferrycarpp,
						ferrycarppnumber,
						ferryfiodriver,
						ferryphone,
						ferrypassport,
						checkmark,
						blmark,
						blreason,
	       				typets
	       				--blmark_driver,
	       				--blreason_driver,
	       				--checkmark_driver
						from ferrymancars
						%s 
						order by id asc
						", $filtr));

            $items = $this->db->FetchAllAssoc(0, 100, $pc);
			$result['totalCount'] = $pc;

			if (sizeof($items)) foreach($items AS $ki => &$item) {
				$item = array_change_key_case($item, CASE_LOWER);
                $item['typets_str'] = TransportTypeHelper::getTransportTypeStr($item['typets']);
			}
			unset($item);
			$result['items'] = $items;
		}

		return json_encode($result);
	}

	public function ferrymancarsSave() {
        // todo test Checkmark permission request (negative case)
        try {
            $res = array();
            $data = json_decode(req('data'), true);
            $permissionService = new UIPermissionService();
            $canSaveCheckmark = $permissionService->canReadField(
                $this->permissions,
                UITransportations::RESOURCE_NAME(),
                UITransportations::FIELD_CAR_CHECKMARK_NAME()
            );
            if (!$canSaveCheckmark && $data[UITransportations::FIELD_CAR_CHECKMARK_NAME()] === '1') {
                throw new Exception('Нет прав на сохранение поля Проверено');
            }

            $ferryman = (int)req('ferryman');
            $id = (int)req('id');
            if ($ferryman || $id) {
                try {
                    $this->db->StartTransaction();

                    if (!$id) {
                        $query = new iQuery("ferrymancars");
                        $query->Param("ferryman",	$ferryman);
                    }
                    else {
                        $query = new uQuery("ferrymancars");
                        $query->SetWhere( sprintf("id = '%s'", $id) );
                    }
                    $data['checkmark'] = ($data['checkmark'] == 'on') ? 1 : 0;
//                    $data['checkmark_driver'] = ($data['checkmark_driver'] == 'on') ? 1 : 0;
                    $data['blmark'] = ($data['blmark'] == 'on') ? 1 : 0;
//                    $data['blmark_driver'] = ($data['blmark_driver'] == 'on') ? 1 : 0;

                    $query->Param("ferrycar", $this->db->Escape($data['ferrycar']));
                    $query->Param("ferrycarnumber", $this->db->Escape($data['ferrycarnumber']));
                    $query->Param("ferrycarpp", $this->db->Escape($data['ferrycarpp']));
                    $query->Param("ferrycarppnumber", $this->db->Escape($data['ferrycarppnumber']));
                    $query->Param("ferryfiodriver", $this->db->Escape($data['ferryfiodriver']));
                    $query->Param("ferryphone", $this->db->Escape($data['ferryphone']));
                    $query->Param("ferrypassport", $this->db->Escape($data['ferrypassport']));
                    $query->Param("checkmark", $data['checkmark']);
                    $query->Param("blmark", $data['blmark']);
                    $query->Param("blreason", $this->db->Escape($data['blreason']));
                    $query->Param("typets", $this->db->Escape($data['typets']));
//                    $query->Param("checkmark_driver", $data['checkmark_driver']);
//                    $query->Param("blmark_driver", $data['blmark_driver']);
//                    $query->Param("blreason_driver", $this->db->Escape($data['blreason_driver']));
                    $this->db->Exec($query);



                    $this->db->Commit();
                    $res['success'] = true;
                }
                catch (Exception $e) {
                    $this->db->RollBack();
                    $res['success'] = false;
                    $res['msg'] = 'sql-ошибка';
                }
            }
            else {
                $res['success'] = false;
                $res['msg'] = "Ошибка! Нет параметров";
            }

            return json_encode($res);
        } catch (Exception $exception) {
            $res = [
                'success' => false,
                'msg' => $exception->getMessage()
            ];

            return json_encode($res);
        }
	}

	public function reportGrid() {
		$result = array();
		$tid = (int)req('tid');

		if ($tid) {
			$start = (int)req('start');
			$start = ($start >= 0) ? $start : 0;
			$limit = (int)req('limit');
			$limit = ($limit >= 0) ? $limit : 0;
			$sort = json_decode(req('sort'));

			$this->db->Query(sprintf("
				SELECT
				id,
				-- obj varchar(100), -- объект
				-- objid bigint,
				to_char(date, 'DD.MM.YYYY HH24:MI:SS') AS date_create,
				to_char(date, 'DD.MM.YYYY') AS date_str,
				to_char(date, 'HH24:MI') AS time_str,
				userLogin(owner) as owner_name,
				data
				FROM transp_report
				WHERE objid='%s'
				ORDER BY date DESC
			", $tid));
			$items = $this->db->FetchAllAssoc($start, $limit, $pc);

			$result['totalCount'] = $pc;
			for($i=0; $i<sizeof($items); $i++) {
				$items[$i] = array_change_key_case($items[$i], CASE_LOWER);
			}
			$result['items'] = $items;
		}
		else
			$result = array(
				'items' => array(),
				'totalCount' => 0
			);
		return json_encode($result);
	}

	public function editReport() {
		$userId = $this->app->userId;

		$result = array();

		$id = (int)req('id');
		$tid = (int)req('tid');
		$data = json_decode(req('data'), true);

		if ( strlen($data['data']) && ($id || $tid) ) {
			try {
				$this->db->StartTransaction();

				$tmpdt = date_create();
				$tmptime = date_create();
				if (preg_match("/\d{2}.\d{2}.\d{4}/", $data['date_str'])) $tmpdt = date_create($data['date_str']);
				if (preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/", $data['time_str'])) $tmptime = date_create($data['time_str']);

				$dt = date_create($tmpdt->format('Y-m-d') . ' ' . $tmptime->format('H:i'));

				if ($id) {
					$query = new uQuery("transp_report");
					$query->SetWhere( sprintf("id = '%s'", $id) );
				}
				else {
					$this->db->ExecQuery(sprintf("update task set date_end=now() where type='addReport' and obj='transportation' and objid='%s'", $tid ));

					$query = new iQuery("transp_report");
					$query->Param("objid",	$tid);
					$query->Param("owner",	$userId);
				}
				$query->Param("date",	$dt->format("d.m.Y H:i:s"),	"DATE",		"DD.MM.YYYY HH24:MI:SS");
				$query->Param("data",	$this->db->Escape($data['data']));
				$this->db->Exec($query);

				$this->db->Commit();
				$result['success'] = true;
			}
			catch (Exception $e) {
				$this->db->RollBack();
				$result['success'] = false;
				$result['msg'] = "Ошибка sql! Создано исключение";
			}
		}
		else {
			$result['success'] = false;
			$result['msg'] = "Ошибка! Не заданы параметры";
		}

		return json_encode($result);
	}

	public function financeGrid() {
		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;
		$sort = json_decode(req('sort'));

	// 	if (sizeof($sort))
	// 	{
	// 		if (preg_match("/asc|desc/i", $sort[0]->direction, $out))
	// 			$dir = $out[0];
	// 		else
	// 			$dir = '';
	// 		$sort = $sort[0]->property;
	// 		if ($sort == 'ID') $order = "ORDER BY transpdocs.ID ".$dir;
	// 		elseif ($sort == 'TYPE') $order = "ORDER BY transpdocs.TYPE ".$dir;
	// 		elseif ($sort == 'DATE_STR') $order = "ORDER BY transpdocs.DATE ".$dir;
	// 		elseif ($sort == 'MANAGER_LOGIN') $order = "ORDER BY manager.login ".$dir;
	// 	}

		$filtr = array(
			sprintf("(p.tid='%s')", (int)req('tid'))
		);

		if (sizeof($filtr)) $filtr = "WHERE " . implode(" and ", $filtr);
		else $filtr = '';

		$this->db->Query(sprintf("
			select
			p.id,
			p.tid,
			p.type,
			p.cash,
			p.currency,
			p.value,
			p.payorder,
			to_char(p.payorderdate, 'DD.MM.YYYY') AS payorderdate,
			p.uid,
			userLogin(p.uid) as userlogin,
			to_char(p.date, 'DD.MM.YYYY') AS date_str
			from payment p
			%s
			order by p.date asc
		", $filtr));
		$items = $this->db->FetchAllAssoc($start, $limit, $pc);

		$type = array(
			'IN' => 'Поступление',
			'OUT' => 'Списание'
		);

		$cash = array(
			0 => 'Безнал',
			1 => 'Нал'
		);

		$result['totalCount'] = $pc;
		$row = 1;
		if (sizeof($items)) foreach($items AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);

			$item['rowid'] = $row;

			$item['value'] = sprintf("%0.2f", (float)$item['value']);

			$item['type_str'] = $type[ $item['type'] ];
			$item['cash_str'] = $cash[ $item['cash'] ];

			$row++;
		}
		unset($item);
		$result['items'] = $items;

		return json_encode($result);
	}

	public function savePayment() {
		$result = array();

		$tid = (int)$_REQUEST['tid'];
		$data = json_decode($_REQUEST['data'], true);

		if (!$tid) {
			return json_encode(array(
				'success' => false,
				'msg' => 'Не задан идентификатор грузоперевозки'
			));
			return;
		}

		$result['success'] = true;

		try {
			$this->db->StartTransaction();

			if ((int)$data['id']) {
				$query = new uQuery("payment");
				$query->SetWhere( sprintf("id = '%s'", (int)$data['id']) );
			}
			else {
				$query = new iQuery("payment");
				$query->Param("tid",			$tid);
				$query->Param("uid",			(int)$user['id']);
			}
			$query->Param("type",			$this->db->Escape($data['type']));
			$query->Param("cash",			(int)$data['cash']);
			$query->Param("currency",		$this->db->Escape($data['currency']));
			$query->Param("value",			(float)$data['value']);
			$query->Param("payorder",		$this->db->Escape($data['payorder']));
			$query->Param("payorderdate",	$this->db->Escape($data['payorderdate']),	"DATE");
			$this->db->Exec($query);

			$this->db->Commit();

            $transportationCollection = PaymentBalanceCalcHelper::getTransportationQuery($tid)
                ->get();
            if (!$transportationCollection->isEmpty()) {
                $pbcs = new PaymentBalanceCalcService($transportationCollection->first(), $this->db);
                $pbcs->processPaymentBalance(Contractor::getContractorByPaymentType($data['type']), new ProcessParams());
            }

            $profitCalculateService = new profitCalculateService($this->db);
            $profitCalculateService->calcTranspFactProfit($tid);
		}
		catch (Exception $e) {
			$this->db->RollBack();
			$result['success'] = false;
			$result['msg'] = "Ошибка sql! Создано исключение";
		}

		return json_encode($result);
	}

	public function delPayment() {
		$id = (int)req('id');
		$result = array();

		$this->db->Query(sprintf("
			select
			p.id,
			p.tid,
			p.type
			from payment p
			where p.id=%s
		", $id));
		$res = $this->db->FetchRowAssoc();
		$res = array_change_key_case($res, CASE_LOWER);

		$this->db->ExecQuery(sprintf("delete from payment WHERE id='%s'", $id));
        $tid = (int)$res['tid'];
        $transportationCollection = PaymentBalanceCalcHelper::getTransportationQuery($tid)
            ->get();
        if (!$transportationCollection->isEmpty()) {
            $pbcs = new PaymentBalanceCalcService($transportationCollection->first(), $this->db);
            $pbcs->processPaymentBalance(Contractor::getContractorByPaymentType($res['type']), new ProcessParams());
        }

        $profitCalculateService = new profitCalculateService($this->db);
        $profitCalculateService->calcTranspFactProfit($tid);
		$result['success'] = true;

		return json_encode($result);
	}

	public function docGrid() {
        $permissions =  $this->app->getUserPermissions();
        $docTypePermissions = $permissions['docTypeAccess'][PermissionEntity::DOCTYPE()] ?? null;
        if (empty($docTypePermissions)) return json_encode([
            'totalCount' => 0,
            'items' => []
        ]);

        $docList = appClientConfig::$DOC_LIST;
        $filterByTypeList = array_filter(
            array_keys($docList),
            function ($key) use ($docTypePermissions, $docList) {
                return isset($docList[$key]['type']) && isset($docTypePermissions[$docList[$key]['type']]);
            }
        );


        $typeFilterQuery = "";
        $keys = array_keys($filterByTypeList);
        $lastKey = end($keys);
        foreach ($filterByTypeList as $key => $type) {
            $typeFilterQuery .= " doc.type = '" . $type . "'";
            if ($key !== $lastKey) {
                $typeFilterQuery .= " OR ";
            }
        }

        $start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;
		$sort = json_decode(req('sort'));

		if (sizeof($sort)) {
			if (preg_match("/asc|desc/i", $sort[0]->direction, $out))
				$dir = $out[0];
			else
				$dir = '';
			$sort = $sort[0]->property;
			if ($sort == 'id') $order = "ORDER BY doc.ID ".$dir;
			elseif ($sort == 'type') $order = "ORDER BY doc.TYPE ".$dir;
			elseif ($sort == 'date_str') $order = "ORDER BY doc.DATE ".$dir;
			elseif ($sort == 'user_login') $order = "ORDER BY user_login ".$dir;
		}

        $filter = array(
            "doc.obj='transportationdoc'",
            sprintf("(doc.objid='%s')", (int)req('tid'))
        );

        if (sizeof($filter)) $filter = "WHERE " . implode(" and ", $filter);
        else $filter = '';

        $query = sprintf("
            SELECT
                doc.id,
                doc.type,
                doc.name,
                to_char(doc.date, 'DD.MM.YYYY') AS date_str,
                userLogin(doc.uid) as user_login,
                doc.comment,
                doc.status
            FROM doc
            %s AND (%s)
    ", $filter, $typeFilterQuery);

        $this->db->Query($query);
		$items = $this->db->FetchAllAssoc($start, $limit, $pc);

		$result['totalCount'] = $pc;

		if (sizeof($items)) foreach($items AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
			$item['id'] = sprintf("%010d", $item['id']);
            $typeStr = '';
            if (isset($docList[$item['type']])) {
                $typeStr = $docList[$item['type']]['name'];
            }
            $item['type_str'] = $typeStr;
		}
		unset($item);
		$result['items'] = $items;

		return json_encode($result);
	}

	public function saveDoc() {
		$userId = $this->app->userId;

		$result = array();

		$did = (int)req('id');
		$tid = (int)req('tid');
		$type = req('type');
		$comment = req('comment');
		$obj = 'transportationdoc';

		$result['success'] = true;

		if ($tid) {
			try {
				$this->db->StartTransaction();

				$file_post = $_FILES['uploads'];
				$file_ary = array();
				$file_count = count($file_post['name']);
				$file_keys = array_keys($file_post);
				for ($i=0; $i<$file_count; $i++) {
					foreach ($file_keys as $key) {
						$file_ary[$i][$key] = $file_post[$key][$i];
					}
				}

				if (!$did) {
					$this->db->Query("SELECT NEXTVAL('doc_id_seq')");
					$seq = $this->db->FetchRow();
					$did = (int)$seq[0];

					$query = new iQuery("doc");
					$query->Param("id",			$did);
					$query->Param("obj",		$obj);
					$query->Param("objid",		$tid);
					$query->Param("uid",		$userId);
					$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
					$query->Param("type",		$type);
					$query->Param("comment",	$comment);
					$query->Param("status",		1);
					$this->db->Exec($query);
				}

				$filesAdded = 0;
				if (sizeof($file_ary)) foreach($file_ary AS $v) {
					if (strlen($v['name'])>=500) {
						$result['success'] = false;
						$result['msg'] = 'Имя файла слишком длинное';
						break;
					}
					if(strlen($v['tmp_name'])) {
						$filesAdded++;

						$this->db->Query("SELECT NEXTVAL('_file_id_seq')");
						$seq = $this->db->FetchRow();
						$fileid = (int)$seq[0];

						$query = new iQuery("_file");
						$query->Param("id",			$fileid);
						$query->Param("uid",		$userId);
						$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
						$query->Param("name",		$this->db->Escape($v['name']));
						$query->Param("status",		1);
						$this->db->Exec($query);

						$query = new iQuery("doc_file");
						$query->Param("docid",		$did);
						$query->Param("fileid",		$fileid);
						$query->Param("uid",		$userId);
						$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
						$query->Param("status",		1);
						$this->db->Exec($query);

						$tpl = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $fileid);
						if (!move_uploaded_file($v['tmp_name'], $tpl)) {
							$result['success'] = false;
							$result['msg'] = 'Произошла ошибка при сохранении файла';
							break;
						}
					}
				}

				if (!$filesAdded) {
					$result['success'] = false;
					$result['msg'] = 'Произошла ошибка. Нет данных для записи. Возможно файл не прикреплен';
				}

				if ($result['success']) {
					new event($this->app, array(
						'obj' => 'transportation', //объект
						'objid' => $tid,
						'event' => 'fileAdd',
						'owner' => 'user', //инициатор - юзер / клиент / перевоз / амо
						'ownerid' => (int)$app->userId,
						'value' => $did
					));

					$this->db->Commit();
				}
				else
					$this->db->RollBack();

			}
			catch (Exception $e) {
				$this->db->RollBack();
				$result['success'] = false;
				$result['msg'] = "Ошибка sql! Создано исключение";
			}
		}
		else {
			$result['success'] = false;
			$result['msg'] = 'Произошла ошибка. Нет данных для записи. Возможно файл слишком большой';
		}

		return json_encode($result);
	}

	public function delDoc() {
		$id = (int)req('id');
		$result = array();

		$id = (int)$_REQUEST['id'];
		$result = array();

		if ($id && (int)$this->priv['transportation']['modDocDelete']) {
			$this->db->ExecQuery(sprintf("update doc set status=0 where id='%s'", $id));
			$this->db->ExecQuery(sprintf("update doc_file set status=0 where docid='%s'", $id));
			$this->db->ExecQuery(sprintf("update _file set status=0 where id in (select fileid from doc_file where docid='%s')", $id));
		}
		$result['success'] = true;

		return json_encode($result);
	}

	public function downloadDoc() {
		ini_set("max_execution_time", "600");

		$id = (int)$_REQUEST['id'];

		$count = 0;
		if ($id) {
			$this->db->Query(sprintf("
				select count(*) as cnt
				from doc
				inner join doc_file on (doc.id=doc_file.docid) and (doc_file.status='1')
				where doc.id='%s' and doc.status='1'
			", $id));
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);
			$count = (int)$tmp['cnt'];
		}

		if ($count == 1) {
			$this->db->Query(sprintf("
				select id,name from _file where id in (select fileid from doc_file where docid='%s' and status='1') and status='1'
			", $id));
			$res = $this->db->FetchRowAssoc();
			$res = array_change_key_case($res, CASE_LOWER);

			if ( (int)$res['id'] && strlen($res['name']) ) {
				$path = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $res['id']);
				if ($rh = fopen($path, 'rb')) {
					header('Content-Description: File Transfer');
					header('Content-Type: application/octet-stream');
					if ( strpos ( $_SERVER [ 'HTTP_USER_AGENT' ], "MSIE" ) > 0 )
						header ( 'Content-Disposition: attachment; filename="' . rawurlencode ( $res['name'] ) . '"' );
					else
						header( 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode ( $res['name'] ) );
					header('Content-Transfer-Encoding: binary');
					header('Expires: 0');
					header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
					header('Pragma: public');
					header('Content-Length: ' . filesize($path));
					ob_clean();
					flush();
					while(!feof($rh))
						print fread($rh, 1024);
					fclose($rh);
				}
			}
			else {
				header("Content-type: text/plain; charset=utf-8");
				exit("Нет файлов для загрузки");
			}
		}
		elseif ($count > 1) {
			@mkdir(Config::DIRECTORY_TEMP_WEB_APP_INTERNAL . '/tmp_docs');
			$tmppath = sprintf(Config::DIRECTORY_TEMP_WEB_APP_INTERNAL . "/tmp_docs/%s%s/", session_id(), microtime());
			@mkdir($tmppath);

			$zip = new ZipArchive();
			if ($zip->open($tmppath.'out.zip', ZipArchive::CREATE)!==true) {
				header("Content-type: text/plain; charset=utf-8");
				exit("Невозможно открыть ".$tmppath.'out.zip');
			}

			$this->db->Query(sprintf("
				select id,name from _file where id in (select fileid from doc_file where docid='%s' and status='1') and status='1'
			", $id));
			$res = $this->db->FetchAllAssoc();
			if (sizeof($res)) {
				foreach($res AS $r) {
					$r = array_change_key_case($r, CASE_LOWER);
					$path = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $r['id']);
					$zip->addFile($path, iconv("UTF-8","CP866",$r['name']));// [, string $localname = NULL [, int $start = 0 [, int $length = 0 ]]] )
				}
			}
			else {
				$zip->close();
				rrmdir($tmppath);
				header("Content-type: text/plain; charset=utf-8");
				exit("Нет файлов для загрузки");
			}

			$zip->close();

			if ($rh = fopen($tmppath.'out.zip', 'rb')) {
				header('Content-Description: File Transfer');
				header('Content-Type: application/octet-stream');
				if ( strpos ( $_SERVER [ 'HTTP_USER_AGENT' ], "MSIE" ) > 0 )
					header ( 'Content-Disposition: attachment; filename="' . rawurlencode ( 'out.zip' ) . '"' );
				else
					header( 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode ( 'out.zip' ) );
				header('Content-Transfer-Encoding: binary');
				header('Expires: 0');
				header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
				header('Pragma: public');
				header('Content-Length: ' . filesize($tmppath.'out.zip'));
				ob_clean();
				flush();
				while(!feof($rh))
					print fread($rh, 1024);
				fclose($rh);
			}

			rrmdir($tmppath);
			exit;
		}
		else {
			header("Content-type: text/plain; charset=utf-8");
			exit("Нет файлов для загрузки");
		}
	}

	public function surveerDocGrid() {
		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;
		$sort = json_decode(req('sort'));

		$filtr = array(
			"doc.obj='surveerdoc'",
			"doc.status=1",
			sprintf("(doc.objid='%s')", (int)req('tid'))
		);

		if (sizeof($filtr)) $filtr = "WHERE " . implode(" and ", $filtr);
		else $filtr = '';
		$this->db->Query(sprintf("
			select
			doc.id,
			doc.type,
			dictValue('SURVEERDOCS', doc.type) as type_str,
			doc.name,
			to_char(doc.date, 'DD.MM.YYYY') AS date_str,
			userLogin(doc.uid) as userlogin,
			doc.comment
			from doc
			%s
		", $filtr));
		$items = $this->db->FetchAllAssoc($start, $limit, $pc);

		$result['totalCount'] = $pc;
		if (sizeof($items)) foreach($items AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);

			$item['id'] = sprintf("%010d", $item['id']);
		}
		unset($item);
		$result['items'] = $items;

		return json_encode($result);
	}

	public function saveSurveerDoc() {
		$userId = $this->app->userId;

		$result = array();

		$did = (int)req('id');
		$tid = (int)req('tid');
		$type = req('type');
		$comment = req('comment');
		$obj = 'surveerdoc';

		$result['success'] = true;

		if ($tid) {
			try {
				$this->db->StartTransaction();

				$file_post = $_FILES['uploads'];
				$file_ary = array();
				$file_count = count($file_post['name']);
				$file_keys = array_keys($file_post);
				for ($i=0; $i<$file_count; $i++) {
					foreach ($file_keys as $key) {
						$file_ary[$i][$key] = $file_post[$key][$i];
					}
				}

				if (!$did) {
					$this->db->Query("SELECT NEXTVAL('doc_id_seq')");
					$seq = $this->db->FetchRow();
					$did = (int)$seq[0];

					$query = new iQuery("doc");
					$query->Param("id",			$did);
					$query->Param("obj",		$obj);
					$query->Param("objid",		$tid);
					$query->Param("uid",		$userId);
					$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
					$query->Param("type",		$type);
					$query->Param("comment",	$comment);
					$query->Param("status",		1);
					$this->db->Exec($query);
				}

				$filesAdded = 0;
				if (sizeof($file_ary)) foreach($file_ary AS $v) {
					if (strlen($v['name'])>=500) {
						$result['error'] = true;
						$result['msg'] = 'Имя файла слишком длинное';
						break;
					}
					if(strlen($v['tmp_name'])) {
						$filesAdded++;

						$this->db->Query("SELECT NEXTVAL('_file_id_seq')");
						$seq = $this->db->FetchRow();
						$fileid = (int)$seq[0];

						$query = new iQuery("_file");
						$query->Param("id",			$fileid);
						$query->Param("uid",		$userId);
						$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
						$query->Param("name",		$this->db->Escape($v['name']));
						$query->Param("status",		1);
						$this->db->Exec($query);

						$query = new iQuery("doc_file");
						$query->Param("docid",		$did);
						$query->Param("fileid",		$fileid);
						$query->Param("uid",		$userId);
						$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",	"DD.MM.YYYY HH24:MI:SS");
						$query->Param("status",		1);
						$this->db->Exec($query);

						$tpl = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $fileid);
						if (!move_uploaded_file($v['tmp_name'], $tpl)) {
							$result['success'] = false;
							$result['msg'] = 'Произошла ошибка при сохранении файла';
							break;
						}
					}
				}

				if (!$filesAdded) {
					$result['success'] = false;
					$result['msg'] = 'Произошла ошибка. Нет данных для записи. Возможно файл не прикреплен';
				}

				if (!$result['success'])
					$this->db->RollBack();
				else
					$this->db->Commit();
			}
			catch (Exception $e) {
				$this->db->RollBack();
				$result['success'] = false;
				$result['msg'] = "Ошибка sql! Создано исключение";
			}
		}
		else {
			$result['success'] = false;
			$result['msg'] = 'Произошла ошибка. Нет данных для записи. Возможно файл слишком большой';
		}

// 		if (!$result['success'])
// 			$result['error'] = true;
		return json_encode($result);
	}

	public function downloadSurveerDoc() {
		ini_set("max_execution_time", "600");

		$id = (int)$_REQUEST['id'];

		$count = 0;
		if ($id) {
			$this->db->Query(sprintf("
				select count(*) as cnt
				from doc
				inner join doc_file on (doc.id=doc_file.docid) and (doc_file.status='1')
				where doc.id='%s' and doc.status='1'
			", $id));
			$tmp = $this->db->FetchRowAssoc();
			$tmp = array_change_key_case($tmp, CASE_LOWER);
			$count = (int)$tmp['cnt'];
		}

		if ($count == 1) {
			$this->db->Query(sprintf("
				select id,name from _file where id in (select fileid from doc_file where docid='%s' and status='1') and status='1'
			", $id));
			$res = $this->db->FetchRowAssoc();
			$res = array_change_key_case($res, CASE_LOWER);

			if ( (int)$res['id'] && strlen($res['name']) ) {
				$path = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $res['id']);
				if ($rh = fopen($path, 'rb')) {
					header('Content-Description: File Transfer');
					header('Content-Type: application/octet-stream');
					if ( strpos ( $_SERVER [ 'HTTP_USER_AGENT' ], "MSIE" ) > 0 )
						header ( 'Content-Disposition: attachment; filename="' . rawurlencode ( $res['name'] ) . '"' );
					else
						header( 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode ( $res['name'] ) );
					header('Content-Transfer-Encoding: binary');
					header('Expires: 0');
					header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
					header('Pragma: public');
					header('Content-Length: ' . filesize($path));
					ob_clean();
					flush();
					while(!feof($rh))
						print fread($rh, 1024);
					fclose($rh);
				}
			}
			else {
				header("Content-type: text/plain; charset=utf-8");
				exit("Нет файлов для загрузки");
			}
		}
		elseif ($count > 1) {
			@mkdir(Config::DIRECTORY_TEMP_WEB_APP_INTERNAL . '/tmp_docs');
			$tmppath = sprintf(Config::DIRECTORY_TEMP_WEB_APP_INTERNAL . "/tmp_docs/%s%s/", session_id(), microtime());
			@mkdir($tmppath);

			$zip = new ZipArchive();
			if ($zip->open($tmppath.'out.zip', ZipArchive::CREATE)!==true) {
				header("Content-type: text/plain; charset=utf-8");
				exit("Невозможно открыть ".$tmppath.'out.zip');
			}

			$this->db->Query(sprintf("
				select id,name from _file where id in (select fileid from doc_file where docid='%s' and status='1') and status='1'
			", $id));
			$res = $this->db->FetchAllAssoc();
			if (sizeof($res)) {
				foreach($res AS $r) {
					$r = array_change_key_case($r, CASE_LOWER);
					$path = sprintf(Config::DIRECTORY_UPLOADS . "/%010d", $r['id']);
					$zip->addFile($path, iconv("UTF-8","CP866",$r['name']));// [, string $localname = NULL [, int $start = 0 [, int $length = 0 ]]] )
				}
			}
			else {
				$zip->close();
				rrmdir($tmppath);
				header("Content-type: text/plain; charset=utf-8");
				exit("Нет файлов для загрузки");
			}

			$zip->close();

			if ($rh = fopen($tmppath.'out.zip', 'rb')) {
				header('Content-Description: File Transfer');
				header('Content-Type: application/octet-stream');
				if ( strpos ( $_SERVER [ 'HTTP_USER_AGENT' ], "MSIE" ) > 0 )
					header ( 'Content-Disposition: attachment; filename="' . rawurlencode ( 'out.zip' ) . '"' );
				else
					header( 'Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode ( 'out.zip' ) );
				header('Content-Transfer-Encoding: binary');
				header('Expires: 0');
				header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
				header('Pragma: public');
				header('Content-Length: ' . filesize($tmppath.'out.zip'));
				ob_clean();
				flush();
				while(!feof($rh))
					print fread($rh, 1024);
				fclose($rh);
			}

			rrmdir($tmppath);
			exit;
		}
		else {
			header("Content-type: text/plain; charset=utf-8");
			exit("Нет файлов для загрузки");
		}
	}

	public function survBeaconNumCmb() {
		$filtr = array(
			"(grp = 'SURV_BEACON_NUM')"
		);

		if ( req('query') ) {
			$filtr[] = sprintf("(lower(value) LIKE lower('%%%s%%'))", $this->db->Escape(req('query')));
		}

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			SELECT
			id,
			value
			FROM _dictionary
			%s
			ORDER BY value
		", $filtrby));
		$result['items'] = $this->db->FetchAllAssoc();
		if (sizeof($result['items'])) foreach($result['items'] AS $k => &$v) {
			$v = array_change_key_case($v, CASE_LOWER);
		}
		return json_encode($result);
	}

	public function survBeaconNumAdd() {
		if (req('value')) {
			$this->db->Query(sprintf("
				SELECT
				id
				FROM _dictionary
				where (grp = 'SURV_BEACON_NUM') and (lower(value) = lower('%s'))
				ORDER BY value
			", $this->db->Escape(req('value'))));
			$res = $this->db->FetchRowAssoc();
			$res = array_change_key_case($res, CASE_LOWER);
			if ((int)$res['id']) {
				$result['success'] = false;
				$result['msg'] = "Невозможно, уже добавлен";
			}
			else {
				$result['success'] = true;

				$query = new iQuery("_dictionary");
				$query->Param("grp",	'SURV_BEACON_NUM');
				$query->Param("value",	$this->db->Escape(req('value')));
				$this->db->Exec($query);
			}

			return json_encode($result);
		}
	}

	public function checkUnloadEvent() {
		$unloadCheckFilter = 1;
		$filtr = $this->composeFilter($data, $unloadCheckFilter);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			SELECT
			count(transportation.id) as cnt
			FROM transportation
			LEFT OUTER JOIN client ON (client.id = transportation.client)
			LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
			%s %s
		", $filtrby, $order));
		$res = $this->db->FetchRowAssoc();
		$res = array_change_key_case($res, CASE_LOWER);

		return json_encode(array(
			'count' => (int)$res['cnt'],
			'success' => true
		));
	}

	public function setUnloadChecked() {
		$userId = $this->app->userId;

		$result = array();

		$id = (int)$_REQUEST['id'];
		if ($id) {
			if ((int)$this->priv['transportation']['setUnloadChecked']) {
				$this->db->Query(sprintf("
					SELECT
					transportation.id
					FROM transportation
					WHERE (transportation.offloadchecked = '0')
						AND (transportation.id = '%s')
				", $id));
				$res = $this->db->FetchRowAssoc();
				$res = array_change_key_case($res, CASE_LOWER);
			}
			else {
				$this->db->Query(sprintf("
					SELECT
					transportation.id
					FROM transportation
					WHERE ( ((transportation.manager = '%s') AND (transportation.logist = 0)) OR (transportation.logist = '%s') )
						AND (transportation.offloadchecked = '0')
						AND (transportation.id = '%s')
				", $userId, $userId, $id));
				$res = $this->db->FetchRowAssoc();
				$res = array_change_key_case($res, CASE_LOWER);
			}

			if ((int)$res['id']) {
				$p = new transportation3Object($this->app);
				$p->loadById($id);
				$p->blockAll();
				$p->allow(offloadchecked);
				$p->offloadchecked = 1;
				$result = $p->save();
			}
		}
		else {
			$result['success'] = false;
			$result['msg'] = 'Произошла ошибка. Нет данных.';
		}

		return json_encode($result);
	}

	public function getDelay($client_paymdelay, $clientpaid, $ferrypaid, $ferrydocdate, $offload) {
		$res = array();

		if (strlen($ferrydocdate)) {
			$res['delay_client'] = $this->perFromDate(date("d.m.Y"), $ferrydocdate) - (int)$client_paymdelay - 5;
			if ($res['delay_client'] > 0) {
				$res['delay_client_ind'] = 1;
			}
			else {
				$res['delay_client'] = 0;
				$res['delay_client_ind'] = 0;
			}

			if ((int)$clientpaid) {
				$res['delay_client'] = 0;
				$res['delay_client_ind'] = 0;
			}
		}
		else {
			$res['delay_client'] = 0;
			$res['delay_client_ind'] = 0;
		}
		///////
		if (strlen($ferrydocdate))
			$res['delay_ferry'] = $this->perFromDate($ferrydocdate, $offload);
		else
			$res['delay_ferry'] = $this->perFromDate(date("d.m.Y"), $offload);
		if (!strlen($offload)) $res['delay_ferry'] = 0;
		if ($res['delay_ferry'] < 0) $res['delay_ferry'] = 0;
		if ($res['delay_ferry'] > 14)
			$res['delay_ferry_ind'] = 1;
		else
			$res['delay_ferry_ind'] = 0;
		if ($ferrypaid) {
			$res['delay_ferry'] = 0;
			$res['delay_ferry_ind'] = 0;
		}

		return $res;
	}

	public function perFromDate($dt1, $dt2) {
		// php 5.2.6 compat, there is no $interval = $dt1->diff($dt2);
		if (is_object($dt1))
			$dt1 = $dt1->format("d.m.Y");

		if (is_object($dt2))
			$dt2 = $dt2->format("d.m.Y");

		$res = ( strtotime($dt1) - strtotime($dt2) ) / 86400;
		if ($res < 0)
		{
			$res = ceil(abs($res));
			$res = -$res;
		}
		else
			$res = ceil($res);
		return $res;
	}

	public function clientList() {
		$userId = $this->app->userId;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$filtr = array(
			"(client.status = '3')"
		);
        if (!empty($_REQUEST['query'])) {
			$filtr[] = sprintf("(lower(client.name) LIKE lower('%%%s%%'))", $this->db->Escape(trim($_REQUEST['query'])));
		}
		if ($this->priv['client']['viewMode'] == 'my')
			$filtr[] = sprintf("(client.manager = '%s')", $userId);
		if (!(int)$this->priv['client']['viewKeyClient'])
			$filtr[] = sprintf("( (client.keyclient = '0') OR (client.manager = '%s') )", $userId);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			SELECT
			id,
			name
			FROM client
			%s
			ORDER BY name
		", $filtrby));
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
		}
		unset($item);

		return json_encode($result);
	}

	public function ferryFioDriverList() {
		$userId = $this->app->userId;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$filtr = array( appConfig::$transportationDefaultFilter	);
        if (!empty($_REQUEST['query'])) {
			$query = trim($_REQUEST['query']);
			$queryE = str_replace(array('Ё', 'ё'), array('Е', 'е'), $query);

			$filtr[] = sprintf("
				(
					(lower(transportation.ferryfiodriver) LIKE lower('%%%s%%')) OR
					(lower(transportation.ferryfiodriver) LIKE lower('%%%s%%'))
				)
			", $this->db->Escape($query), $this->db->Escape($queryE));
		}
		if (!(int)$this->priv['client']['viewKeyClient'])
			$filtr[] = sprintf("
				( (transportation.client = 0) OR (client.keyclient = '0') OR (transportation.manager = '%s') OR (transportation.logist = '%s') )
			", $userId, $userId);
		if ($this->priv['transportation']['viewMode'] == 'my')
			$filtr[] = sprintf("( (transportation.manager = '%s') OR (transportation.logist = '%s') )", $userId, $userId);
		if ($this->priv['transportation']['viewMode'] == 'logistExist')
			$filtr[] = sprintf("( (transportation.manager = '%s') OR (transportation.logist > 0) )", $userId);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			select distinct
			trim(transportation.ferryfiodriver) as name
			from transportation
			LEFT OUTER JOIN client ON (client.id = transportation.client)
			%s
			ORDER BY name
		", $filtrby));
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
		}
		unset($item);

		return json_encode($result);
	}

	public function ferrymanList() {
		$userId = $this->app->userId;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$filtr = array();
        if (!empty($_REQUEST['query'])) {
			$filtr[] = sprintf("(lower(ferryman.name) LIKE lower('%%%s%%'))", $this->db->Escape(trim($_REQUEST['query'])));
		}
		if ($this->priv['ferryman']['viewMode'] == 'my')
			$filtr[] = sprintf("(ferryman.manager = '%s')", $userId);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			SELECT
			id,
			name
			FROM ferryman
			%s
			ORDER BY name
		", $filtrby));
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
		}
		unset($item);

		return json_encode($result);
	}

	public function ferryCarNumberList() {
		$userId = $this->app->userId;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$filtr = array( appConfig::$transportationDefaultFilter	);
        if (!empty($_REQUEST['query'])) {
			$filtr[] = sprintf("(lower(transportation.ferrycarnumber) LIKE lower('%%%s%%'))", $this->db->Escape(trim($_REQUEST['query'])));
		}
		if (!(int)$this->priv['client']['viewKeyClient'])
			$filtr[] = sprintf("
				( (transportation.client = 0) OR (client.keyclient = '0') OR (transportation.manager = '%s') OR (transportation.logist = '%s') )
			", $userId, $userId);
		if ($this->priv['transportation']['viewMode'] == 'my')
 		if ($this->priv['transportation']['viewMode'] == 'logistExist')
			$filtr[] = sprintf("( (transportation.manager = '%s') OR (transportation.logist > 0) )", $userId);

		if (!empty($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		$this->db->Query(sprintf("
			select distinct
			trim(transportation.ferrycarnumber) as name
			from transportation
			LEFT OUTER JOIN client ON (client.id = transportation.client)
			%s
			ORDER BY name
		", $filtrby));
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
		}
		unset($item);

		return json_encode($result);
	}

    public function getDebitCredit()
    {
        $data = json_decode($_REQUEST['filter'], true);
        $filters = $this->composeFilter($data);

        $query = \App\Models\Transportation::query()
            ->with(['clientContract', 'carrierContract'])
            ->join('client', 'transportation.client', '=', 'client.id')
            ->select(
                'transportation.id',
                'transportation.clientcontract',
                'transportation.ferrycontract',
                'transportation.clientpricenal',
                'transportation.client_currency_sum',
                'transportation.client_currency_rate',
                'transportation.client_currency_total',
                'transportation.client_currency_rate',
                'transportation.ferrypricenal',
                'transportation.ferry_currency_sum',
                'transportation.ferry_currency_rate',
                'transportation.ferry_currency_total',
                'transportation.cargoinsuranceclientvalue',
                'transportation.finetoclient',
                'transportation.finetoferry',
                'transportation.finefromclient',
                'transportation.finefromferry',
                'transportation.clientothercharges',
                'transportation.ferryothercharges',
            );

        foreach ($filters as $filter) {
            $query->whereRaw($filter);
        }

        $transportations = $query->cursor();
        $debitCreditService = new DebitCreditService($this->db);
        $dc = array_reduce(iterator_to_array($transportations), function ($acc, $transportation) use ($debitCreditService) {
            $partialDC = $debitCreditService->calcDC([$transportation]);
            return DataHelper::combineArrays($acc, $partialDC);
        }, []);

        return json_encode(['data' => $dc]);
    }

    /**
     * Собираем данные для вкладки Транспортировка -> Расчеты
     */
    public function getCalculations() {
        $id = (int)$_REQUEST['id'];

        if ($id) {
            $client = $this->app->getTranspClientCurDiff($id);
            $ferry = $this->app->getTranspFerryCurDiff($id);
            $profitCalculateService = new ProfitCalculateService($this->db);
            $profitFactLog = $profitCalculateService->getProfitFact($id)['log'];
            $profitPlanLog = $profitCalculateService->getProfitPlan($id)['log'];
            return json_encode(array(
                'success' => true,
                'log' => sprintf("Клиент\n===================\n%s\n\n\nПодрядчик\n===================\n%s\n\n\nФактическая прибыль\n===================\n%s\n\n\nПланируемая прибыль\n===================\n%s", $client['log'], $ferry['log'], $profitFactLog, $profitPlanLog)
            ));
        }
        else {
            return json_encode(array(
                'success' => true,
                'log' => "Не задан идентификатор\nВыход 0"
            ));
        }
    }

	public function taskGrid() {
		$userId = $this->app->userId;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$sort = json_decode(req('sort'));

		$this->db->Query(sprintf("
			SELECT
			id,
			obj,
			objid,
			type,
			data
			FROM task
			WHERE (date_end is null) and (uid='%s')
			ORDER BY date DESC
		", $userId));
		$result['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
		$result['totalCount'] = $pc;

		if (sizeof($result['items'])) foreach($result['items'] AS $ki => &$item) {
			$item = array_change_key_case($item, CASE_LOWER);
		}
		unset($item);

		return json_encode($result);
	}

	public function closeTask() {
		$userId = $this->app->userId;

		$result = array();

		$id = (int)$_REQUEST['id'];

		if ($id) {
			try {
				$this->db->StartTransaction();

				$query = new uQuery("task");
				$query->SetWhere( sprintf("id = '%s'", $id) );
				$query->Param("date_end", 'now()', 'RAW');
				$this->db->Exec($query);

				$this->db->Commit();
				$result['success'] = true;
			}
			catch (Exception $e) {
				$this->db->RollBack();
				$result['success'] = false;
				$result['msg'] = "Ошибка sql! Создано исключение";
			}
		}
		else {
			$result['success'] = false;
			$result['msg'] = "Ошибка! Не заданы параметры";
		}

		return json_encode($result);
	}

	private function makeCargoInsuranceRequestAddDoc(&$files, $obj, $objid, $type, $name) {
		$this->db->Query(sprintf("
			SELECT
			_file.id,
			_file.name
			FROM doc 
			INNER JOIN doc_file ON doc_file.docid = doc.id and doc_file.status=1
			INNER JOIN _file on _file.id=doc_file.fileid
			WHERE doc.obj='%s' and doc.objid='%s' and doc.status=1 and doc.type='%s'
		", $obj, $objid, $type));
		$tmp = $this->db->FetchAllAssoc();
		$size = sizeof($tmp); $count = 0;
		if ($size) foreach($tmp AS $v) {
			$v = array_change_key_case($v, CASE_LOWER);
			$count++;

			$files[] = array(
				'obj' => '_file',
				'objid' => (int)$v['id'],
				'name' => $this->app->renameFileName( $v['name'], $name . (($size > 1) ? sprintf("_%02d", $count) : '') )
			);
		}
	}

	public function makeCargoInsuranceRequest() {
		ini_set("max_execution_time", "600");

		$result = array();
		$result['success'] = true;

		if ((int)$this->priv['transportation']['makeCargoInsuranceRequest']) {

			$id = (int)req('id');

			if ($id) {
				try {
					$this->db->StartTransaction();

					/*
					1.	Скан паспорта водителя (разворот 1й, разворот с пропиской)
					2.	Водительское удостоверение (с 2 сторон)
					3.	СТС тягач
					4.	СТС прицеп
					5.	Код АТИ
					6.	ИНН подрядчика
					7.  Договор аренды
					*/
					$files = array();

					$this->db->Query(sprintf("
						SELECT
						t.id,
						t.client,
						t.ferryman,
						t.ferrycar_id,
						to_char(t.load, 'DD.MM.YYYY') as loaddate,
						to_char(t.load + '30 day'::interval, 'DD.MM.YYYY') as enddate,
						t.clientfromplace,
						t.clienttoplace,
						t.cargoprice,
						t.cargopricecurrency,
						t.cargo,
						t.cargoweight,
						t.cargoplaces,
						t.ferrycar,
						t.ferrycarnumber,
						t.ferrycarpp,
						t.ferrycarppnumber,
						
						c.name as client_name,
						c.inn as client_inn,
						
						t.ferryfiodriver,
						t.ferryphone,
						t.ferrypassport,
						f.inn as ferry_inn,
						f.name as ferry_name,
						f.aticode as ferry_aticode

						FROM transportation t
						LEFT JOIN client c ON t.client=c.id
						LEFT JOIN ferryman f ON t.ferryman=f.id
						WHERE t.id = '%s'
					", $id));
					$data = $this->db->FetchRowAssoc(); $data = array_change_key_case($data, CASE_LOWER);

					/*
					"1";"СТС тягач"
					"2";"СТС прицеп"
					"3";"ПТС тягач"
					"4";"ПТС прицеп"
					"5";"Паспорт водителя"
					"6";"ВУ"
					"7";"Договор аренды"
					"8";"Трудовой договор"
					*/
                    $carId = (int)$data['ferrycar_id'];
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferrymandriver', $carId, 5, "Паспорт");
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferrymandriver', $carId, 6, "ВУ");
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferrymancar', $carId, 1, "СТС тягач");
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferrymancar', $carId, 2, "СТС прицеп");
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferrymancar', $carId, 7, "Договор аренды");

					/*
					"1";"ИНН"
					"2";"ОГРН"
					"3";"Устав"
					"4";"Выписка из ЕГРЮЛ"
					"5";"Паспорт ген.директора"
					"6";"Реквизиты"
					"7";"Договор"
					"8";"Ати паспорт"
					"9";"Ати активность"
					"10";"Ати контакт"
					"11";"Скрин ати НП"
					"12";"Скрин ати связанные компании"
					*/
					$this->makeCargoInsuranceRequestAddDoc($files, 'ferryman', (int)$data['ferryman'], 1, "ИНН");

					$objReader = PHPExcel_IOFactory::createReader('Excel5');
					$objPHPExcel = $objReader->load(appConfig::$DirectoryUploads . '/templates/cargoInsuranceRequest.xls');

					$objPHPExcel->setActiveSheetIndex(0)
								->setCellValue('B10', sprintf("%s ИНН %s", $data['client_name'], $data['client_inn']))

								->setCellValue('C10', date("d.m.Y"))

								->setCellValue('D10', $data['loaddate'])
								->setCellValue('E10', $data['enddate'])

								->setCellValue('G10', sprintf("%s - %s", $data['clientfromplace'], $data['clienttoplace']))
								->setCellValue('H10', $data['cargoprice'])
								->setCellValue('I10', $data['cargopricecurrency'])

								->setCellValue('K10', $data['cargo'])
//								->setCellValue('L10', "по ТСД")
								->setCellValue('L10', $data['cargoplaces'])

								->setCellValue('M10', round((float)$data['cargoweight']*1000))
								->setCellValue('O10',
									$data['ferrycar'] . ' ' . $data['ferrycarnumber'] .
									((strlen($data['ferrycarpp']) || strlen($data['ferrycarppnumber'])) ? ', прицеп '.$data['ferrycarpp'].' '.$data['ferrycarppnumber'] : '') .
									', ' . $data['ferryfiodriver'] . ' ' . $data['ferryphone'] . ' ' . $data['ferrypassport']
								)

								->setCellValue('P10', sprintf("%s ИНН %s", $data['ferry_name'], $data['ferry_inn']))
								;

					$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');

					$this->db->Query("SELECT NEXTVAL('_tmp_file_id_seq')");
					$seq = $this->db->FetchRow();
					$tmpid = (int)$seq[0];

					$objWriter->save(sprintf(Config::DIRECTORY_UPLOADS . "/tmp/%010d", $tmpid));

					$files[] = array(
						'obj' => '_tmp_file',
						'objid' => $tmpid,
						'name' => 'Заявление.xls'
					);

					$this->db->Query(sprintf("SELECT email from manager where id='%s'", (int)$this->app->userId));
					$tmp = $this->db->FetchRow();
					$email = $tmp[0];

					$this->db->Query("SELECT NEXTVAL('alerts_id_seq')");
					$seq = $this->db->FetchRow();
					$alertid = (int)$seq[0];

					$msg = sprintf("Заявление на страхование груза к ТЗ № %s
					
					ФИО водителя: %s
					Номер телефона водителя: %s
					ИНН подрядчика: %s
					Наименование подрядчика: %s
					Код АТИ: %s
					", (int)$data['id'], $data['ferryfiodriver'], $data['ferryphone'], $data['ferry_inn'], $data['ferry_name'], $data['ferry_aticode']);
					$msg = str_replace("\n", "\n<br/>", $msg);

					$query = new iQuery("alerts");
					$query->Param("id",			$alertid);
					$query->Param("type",		'mail');
					$query->Param("date",		date("d.m.Y H:i:s"),	"DATE",		"DD.MM.YYYY HH24:MI:SS");
					$query->Param("recipient",	$this->db->Escape($email));
					$query->Param("subj",		$this->db->Escape(sprintf("заявление на страхование груза к ТЗ № %s", (int)$data['id'])));
					$query->Param("data",		$this->db->Escape($msg));
					$this->db->Exec($query);

					foreach($files AS $v) {
						$query = new iQuery("alerts_file");
						$query->Param("pid",		$alertid);
						$query->Param("obj",		$v['obj']);
						$query->Param("objid",		$v['objid']);
						$query->Param("name",		$v['name']);
						$this->db->Exec($query);
					}

					$this->db->Commit();
				}
				catch (Exception $e) {
					$this->db->RollBack();
					$result['success'] = false;
					$result['msg'] = "Ошибка sql! Создано исключение";
				}
			}
			else {
				$result['success'] = false;
				$result['msg'] = 'Нет данных';
			}
		}
		else {
			$result['success'] = true;
			$result['msg'] = 'Недостаточно привилегий';
		}
		return json_encode($result);
	}

	public function makeTN() {
		ini_set("max_execution_time", "600");

		$result = array();
		$result['success'] = true;

// 		if ((int)$this->priv['transportation']['makeCargoInsuranceRequest']) {

			$id = (int)$_REQUEST['id'];

			if ($id) {
				try {
					$this->db->Query(sprintf("
						SELECT
						t.id,
						to_char(t.load, 'DD.MM.YYYY') as loaddate,
						
						c.name as client_name,
						c.inn as client_inn,
						c.kpp as client_kpp,
						c.uraddress as client_uraddress,
						c.contacts as client_contacts,
						
						f.inn as ferry_inn,
						f.name as ferry_name,

						t.cargo,
						t.cargoplaces,

						t.ferrycar,
						t.ferrycarpp,
						t.ferrycarppnumber,
						t.ferrycarnumber,
						t.ferryfiodriver,
						t.ferryphone,
						t.ferrypassport,

						(select address from load where (tid=t.id) order by date asc limit 1) as load_first_address,
						(select address from offload where (tid=t.id) order by date desc limit 1) as unload_last_address
						
						FROM transportation t
						LEFT JOIN client c ON t.client=c.id
						LEFT JOIN ferryman f ON t.ferryman=f.id
						WHERE t.id = '%s'
					", $id));
					$data = $this->db->FetchRowAssoc(); $data = array_change_key_case($data, CASE_LOWER);

					$objReader = PHPExcel_IOFactory::createReader('Excel5');
					$objPHPExcel = $objReader->load(appConfig::$DirectoryUploads . '/templates/tn.xls');

					$clientInfo = array();
					if (strlen(trim($data['client_name']))) $clientInfo[] = str_replace("\n", "", $data['client_name']);
					if (strlen(trim($data['client_inn']))) $clientInfo[] = str_replace("\n", "", $data['client_inn']);
					if (strlen(trim($data['client_kpp']))) $clientInfo[] = str_replace("\n", "", $data['client_kpp']);
					if (strlen(trim($data['client_uraddress']))) $clientInfo[] = str_replace("\n", "", $data['client_uraddress']);
// 					if (strlen(trim($data['client_contacts']))) $clientInfo[] = str_replace("\n", "", $data['client_contacts']);
					$clientInfo = implode(", ", $clientInfo);

					$loads = array();
					$this->db->Query(sprintf("
						select
						contacts,
						address
						from load
						where tid=%s
						order by date
					", $id));
					$res = $this->db->FetchAllAssoc();
					if (sizeof($res)) foreach($res AS $v) {
						$v = array_change_key_case($v, CASE_LOWER);
						if (strlen($v['contacts'])) $loads[] = $v['contacts'];
						if (strlen($v['address'])) $loads[] = $v['address'];
					}
					$loads = implode(", ", $loads);

					$loads = array();
					$this->db->Query(sprintf("
						select
						contacts,
						address
						from offload
						where tid=%s
						order by date
					", $id));
					$res = $this->db->FetchAllAssoc();
					if (sizeof($res)) foreach($res AS $v) {
						$v = array_change_key_case($v, CASE_LOWER);
						if (strlen($v['contacts'])) $loads[] = $v['contacts'];
						if (strlen($v['address'])) $loads[] = $v['address'];
					}
					$unloads = implode(", ", $loads);

					$objPHPExcel->setActiveSheetIndex(0)
						->setCellValue('G9', $data['loaddate'])
						->setCellValue('BU9', $data['loaddate'])

						->setCellValue('AC9', $id)
						->setCellValue('CQ9', $id)

						->setCellValue('BP15', $clientInfo)
						->setCellValue('B20', $unloads)

						->setCellValue('B25', $data['cargo'])
						->setCellValue('BF25', $data['cargoplaces'])

						->setCellValue('B44', sprintf("%s, %s", $data['ferry_name'], $data['ferry_inn']))
						->setCellValue('BF44', $data['ferryfiodriver'])
						->setCellValue('BF47', $data['ferrycarnumber'])

						->setCellValue('B56', $loads)
						->setCellValue('B60', $data['load_first_address'])

						->setCellValue('B66', $data['cargoplaces'])
						->setCellValue('BF66', $data['cargoplaces'])

						->setCellValue('B44', sprintf("Водитель: %s", $data['ferryfiodriver']))
					;
					$objPHPExcel->setActiveSheetIndex(1)
						->setCellValue('B3', $data['unload_last_address'])
						->setCellValue('BF11', sprintf("Водитель: %s", $data['ferryfiodriver']))
					;
					$objPHPExcel->setActiveSheetIndex(0);
					$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');

					// что-то попадало в вывод и заставляло excel writer выдавать битые файлы
					// добавлена очистка stdout, как временный фикс
					ob_clean();

					header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
					header(sprintf('Content-Disposition: attachment;filename="%s_tn.xls"', $id));
					header('Cache-Control: max-age=0');
					header('Cache-Control: max-age=1');

					header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
					header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
					header ('Cache-Control: cache, must-revalidate');
					header ('Pragma: public'); // HTTP/1.0

					$objWriter->save('php://output');
				}
				catch (Exception $e) {
					$result['success'] = false;
					$result['msg'] = "Ошибка sql! Создано исключение";
				}
			}
			else {
				$result['success'] = false;
				$result['msg'] = 'Нет данных';
			}

		return json_encode($result);
	}

	public function createBill1c2() {
		$userId = $this->app->userId;

		$result = array();
		$result['success'] = true;

		if ((int)$this->priv['transportation']['makeBill']) {
			$ids = json_decode($_REQUEST['ids'], true);
			$idsChecked = array();
			if (sizeof($ids)) foreach($ids AS $v) {
				if ((int)$v) $idsChecked[] = $v;
			}

			if (!sizeof($idsChecked)) {
				die(json_encode(array(
					'success' => false,
					'msg' => "Не задан идентификатор"
				)));
			}

			try {
				$date = new DateTime($_REQUEST['date']);
			}
			catch (Exception $e) {
				die(json_encode(array(
					'success' => false,
					'msg' => "Ошибка в дате"
				)));
			}

			$this->db->Query(sprintf("
				SELECT distinct
				client
				FROM transportation
				WHERE id in (%s)
			", implode(', ', $idsChecked)));
			$res = $this->db->FetchAllAssoc();
			if (sizeof($res) > 1) {
				die(json_encode(array(
					'success' => false,
					'msg' => 'Ошибка. Выбраны грузоперевозки с разными клиентами.'
				)));
			}

			$this->db->Query(sprintf("
				SELECT distinct
				clientnds
				FROM transportation
				WHERE id in (%s)
			", implode(', ', $idsChecked)));
			$res = $this->db->FetchAllAssoc();
			if (sizeof($res) > 1) {
				die(json_encode(array(
					'success' => false,
					'msg' => 'Ошибка. Выбраны грузоперевозки с разным НДС.'
				)));
			}

            // TODO check is it need cause it has an error: client_currency should get from client contract currency
			$this->db->Query(sprintf("
				SELECT distinct
				client_currency
				FROM transportation
				WHERE id in (%s)
			", implode(', ', $idsChecked)));
			$res = $this->db->FetchAllAssoc();
			if (sizeof($res) > 1) {
				die(json_encode(array(
					'success' => false,
					'msg' => 'Ошибка. Выбраны грузоперевозки с разной валютой.'
				)));
			}

			try {
				$this->db->StartTransaction();

				$this->db->Query("SELECT NEXTVAL('bill1c_id_seq')");
				$seq = $this->db->FetchRow();
				$id = (int)$seq[0];

				$query = new iQuery("bill1c");
				$query->Param("id",			$id);
				$query->Param("version",	2);
				$query->Param("billdate",	$date->format('d.m.Y'), "DATE");
				$query->Param("uid",		$userId);
				$this->db->Exec($query);

				foreach($idsChecked AS $v) {
					$query = new iQuery("bill1c_service");
					$query->Param("bid",		$id);
					$query->Param("tid",		(int)$v);
					$this->db->Exec($query);
				}

				$this->db->Commit();
			}
			catch (Exception $e) {
				$this->db->RollBack();
				$result['success'] = false;
				$result['msg'] = "Ошибка sql! Создано исключение";
			}
		}
		else {
			$result['success'] = true;
			$result['msg'] = 'Недостаточно привилегий';
		}

		return json_encode($result);
	}

	public function tracingData() {
		ini_set("max_execution_time", "600");

		$userId = $this->app->userId;

		$data = json_decode($_REQUEST['filtr'], true);
		$filtr = $this->composeFilter($data);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		try {
			$start = 0;
			$limit = 100;

			$wExcel = new Ellumilel\ExcelWriter();

			$type = req('type');

			if ($type == 'main') {
				$filename = 'tracing.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'№',
					'Авто',
					'Данные водителя',
					'Место загрузки',
					'Таможня отправления',
					'Таможня назначения',
					'Место выгрузки',
					'Груз',
					'Тип трака',
					'Номер заявки',
					'Согласованная дата/время подачи авто',
					'Дата прибытия  на загрузку',
					'Дата убытия с загрузки',
					'простой на загрузке/сутки',
					'Дата пересечения границы',
					'Наименование МАПП',
					'Актуальная дислокация',
					'Дата прибытия на таможню',
					'Дата убытия с таможни',
					'Дата прибытия на выгрузку',
					'Дата выгрузки',
					'простой на выгрузке/сутки',
					'Примечание'
				));
			}
			elseif ($type == 'henkel') {
				$filename = 'henkel.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'NO',
					'WEEK',
					'DRIVER',
					'Order',
					'Truck Type',
					'Trailer Type',
					'Load confirmation',
					'DESTINATION'
				));
			}
			elseif ($type == 'htns') {
				$filename = 'htns.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'NO.',
					'WEEK',
					'ROUTE',
					'TRUCK NO',
					'TRAILER NO',
					'Truck Type',
					'Trailer TYPE',
					'TYPE OF CARGO',
					'DRIVER',
					'TEL.NO',
					'CONSIGNEE',
					'Load confirmation',
					'ATA SERK',
					'ATD SERK',
					'BORDER CROSSING DATE',
					'NAME OF THE BORDER CROSSING POST',
					'ETA/ATA CONSIGNEE',
					'UNLOADING',
					'DESTINATION',
					'PRESENT POSITION',
					'DISTANCE (KM)',
					'LT',
					'REMARK',
					'Carrier'
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'номер порядковый',
					'Неделя',
					'Маршрут',
					'Номеря тягача',
					'Номер принцепа',
					'Обьем прицепа',
					'Тип прицепа',
					'Тип груза',
					'ФИО водителя',
					'конт номер водителя',
					'Получатель',
					'Ожидаемая дата прибытия авто на склад погрузки',
					'Фактическая дата прибытия ато на склад погрузки',
					'Фактическая дата убытия авто со склада',
					'Дата пересечения границы',
					'Наименование погран перехода',
					'Предварительная дата прибытия на выгрузку',
					'Фактическая дата прибытия на выгрузку',
					'Город выгрузки',
					'Трейсинг на сегодня',
					'Количество км от местонахождения до пункта выгрузки',
					'Фактическое количество дней доставки',
					'комментарии',
					'Фактическая печать в смр подрядчика'
				));
			}
			elseif ($type == 'serk_sekz') {
				$filename = 'serk_sekz.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					'',
					'NO.',
					'FWDR',
					'WEEK',
					'ROUTE',
					'TRUCK NO',
					'TRAILER NO',
					'Trailer TYPE',
					'TYPE OF CARGO',
					'DRIVER',
					'TEL.NO',
					'CLIENT',
					'INVOICE NO',
					'CMR NO',
					'MIX',
					'BOOKING DATE',
					'Load confirmation',
					'ATA SERK',
					'ATD SERK',
					'BORDER CROSSING DATE',
					'NAME OF THE BORDER CROSSING POST',
					'ETA/ATA CONSIGNEE',
					'UNLOADING',
					'DESTINATION',
					'PRESENT POSITION',
					'DISTANCE (KM)',
					'LT',
					'REMARK (delay reason, cc inspector reason…)',
					'Carrier'
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'Номер',
					'Экспедитор',
					'Неделя',
					'Маршрут',
					'Тягач',
					'Прицеп',
					'Тип прицепа',
					'Тип груза',
					'ФИО водителя',
					'конт номер водителя',
					'Получатель груза',
					'Номер инвойса (получаешь после погрузки авто)',
					'Номер СМР (получаем после погрузки авто )',
					'Дополнительная погрузка на складе',
					'Дата поступления заказа в ЭНИКу',
					'Ожидаемая дата прибытия авто на погрузку',
					'Фактическая дата прибытия авто на погрузку',
					'Фактическая дата убытия с погрузки',
					'Дата пересечения границы',
					'Погран переход',
					'Предварительная дата прибытия на выгрузку',
					'Фактическая дата прибытия на выгрузку',
					'Город выгрузки',
					'Трейсинг сегодня',
					'Количество км от местонахождения до пункта выгрузки',
					'Фактическое количество дней доставки',
					'комментарии',
					'Фактическая печать в смр подрядчика'
				));
			}
			elseif ($type == 'srdc') {
				$filename = 'srdc.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					'NO',
					'FWDR',
					'WEEK',
					'ROUTE',
					'D/O',
					'TRUCK NO',
					'TRAILER NO',
					'Trailer TYPE',
					'DRIVER',
					'TEL.NO',
					'ATA LOADING PLACE',
					'ATD LOADING PLACE',
					'ETA/ATA CONSIGNEE',
					'UNLOADING',
					'DESTINATION',
					'PRESENT POSITION',
					'REMARK'
				));
			}
			elseif ($type == 'milk') {
				$filename = 'milk.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'Слежение'
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'1',
					'Клиент',
					'Неделя',
					'Трак',
					'№ Трака',
					'тип прицепа',
					'№ прицепа',
					'Груз',
					'ФИО Водителя',
					'Номер телефона',
					'№ Заявки',
					'Дата Заявки',
					'Место загрузки',
					'Грузополучатель',
					'Место выгрузки',
					'Плановое время прибытия',
					'Фактическое время прибытия',
					'Фактическое время начала погрузки',
					'Фактическое время выезда с завода',
					'Текущее местоположение',
					'Плановое время прибытия по заявке',
					'Фактическое время прибытия на выгрузку',
					'Фактическое время начала выгрузки',
					'Фактическое время окончания выгрузки и выдачи ТСД',
					'Время работы ТС,  часов',
					'Простой,  часов',
					'Примечание'
				));
			}
			elseif ($type == 'status') {
				$filename = 'status.xlsx';

				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					''
				));
				$wExcel->writeSheetRow('Лист', array(
					'',
					'№ п/п',
					'Место загрузки',
					'Место выгрузки',
					'Номер ТС',
					'Данные водителя',
					'Дата и время прибытия на загрузку',
					'',
					'Дата и время убытия с места загрузки',
					'',
					'Дата пересечения границы',
					'Актуальная дислокация',
					'Дата и время прибытия на выгрузку',
					'',
					'Дата и время убытия с выгрузки',
					'',
					'Примечание'
				));
			}
			else
				die();

			$db2 = clone $this->db;
			$row = 1;
			do {
				$this->db->Query(sprintf("
					select
					transportation.id,
					transportation.multimodal,
					transportation.multimodal_id,
					transportation.multimodal_num,
					
					transportation.typets,
					transportation.ferryman_typets,
					transportation.order_text,
					transportation.cargo,
					transportation.clienttoplace,
					
					to_char(transportation.date, 'DD.MM.YYYY') as date_str,
					
					to_char(transportation.load, 'DD.MM.YYYY') as load_str,
					to_char(transportation.load, 'DD.MM.YYYY HH24:MI') as loadtime_str,
					
					to_char(transportation.offload, 'DD.MM.YYYY') as offload_str,
					to_char(transportation.offload, 'DD.MM.YYYY HH24:MI') as offloadtime_str,

					transportation.surv_factprint,
					to_char(transportation.bordercross_date, 'DD.MM.YYYY') as bordercross_dateoad_str,
					transportation.bordercross,

					transportation.ferrycar,
					transportation.ferrycarnumber,
					transportation.ferrycarpp,
					transportation.ferrycarppnumber,
					transportation.ferryfiodriver,
					transportation.ferryphone,
					transportation.ferrypassport,
					transportation.ferrywaybill,
					
					(select comment from offload where (tid=transportation.id) order by date desc limit 1) as unload_last_comment,
					(select data from transp_report where (objid=transportation.id) and (type='manual') order by date desc limit 1) as last_manual_report,
					(select data from transp_report where (objid=transportation.id) order by date desc limit 1) as last_report,
					
					(select to_char(date, 'DD.MM.YYYY HH24:MI') from transp_report where (objid=transportation.id) and (type='transpStatusArrivedInLoad') order by id desc limit 1) as datetime_arrived_in_load,
					(select to_char(date, 'DD.MM.YYYY HH24:MI') from transp_report where (objid=transportation.id) and (type='transpStatusLoaded') order by id desc limit 1) as datetime_loaded,
					(select to_char(date, 'DD.MM.YYYY HH24:MI') from transp_report where (objid=transportation.id) and (type='transpStatusArrivedInUnload') order by id desc limit 1) as datetime_arrived_in_unload,
					(select to_char(date, 'DD.MM.YYYY HH24:MI') from transp_report where (objid=transportation.id) and (type='transpStatusUnloaded') order by id desc limit 1) as datetime_unloaded,
					
					client.name AS client_name
	
					from transportation
					LEFT OUTER JOIN manager ON (manager.id = transportation.manager)
					LEFT OUTER JOIN manager logist ON (logist.id = transportation.logist)
					LEFT OUTER JOIN client ON (client.id = transportation.client)
					LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
					%s
					ORDER BY transportation.ID DESC
				", $filtrby));
				$items = $this->db->FetchAllAssoc($start, $limit, $pc);
				$count = sizeof($items);

				$start += $limit;

				if (sizeof($items)) foreach($items AS $v) {
					$v = array_change_key_case($v, CASE_LOWER);

					//
					$d_arrived_in_load = array( 'datetime' => $v['datetime_arrived_in_load'] );
					if (strlen($v['datetime_arrived_in_load'])) {
						$tmp = date_create($v['datetime_arrived_in_load']);
						$d_arrived_in_load['date'] = $tmp->format('d.m.Y');
						$d_arrived_in_load['time'] = $tmp->format('H:i');
						$d_arrived_in_load['timeS'] = $tmp->format('H:i:s');
					}
					//
					$d_loaded = array( 'datetime' => $v['datetime_loaded'] );
					if (strlen($v['datetime_loaded'])) {
						$tmp = date_create($v['datetime_loaded']);
						$d_loaded['date'] = $tmp->format('d.m.Y');
						$d_loaded['time'] = $tmp->format('H:i');
						$d_loaded['timeS'] = $tmp->format('H:i:s');
					}
					//
					$d_arrived_in_unload = array( 'datetime' => $v['datetime_arrived_in_unload'] );
					if (strlen($v['datetime_arrived_in_unload'])) {
						$tmp = date_create($v['datetime_arrived_in_unload']);
						$d_arrived_in_unload['date'] = $tmp->format('d.m.Y');
						$d_arrived_in_unload['time'] = $tmp->format('H:i');
						$d_arrived_in_unload['timeS'] = $tmp->format('H:i:s');
					}
					//
					$d_unloaded = array( 'datetime' => $v['datetime_unloaded'] );
					if (strlen($v['datetime_unloaded'])) {
						$tmp = date_create($v['datetime_unloaded']);
						$d_unloaded['date'] = $tmp->format('d.m.Y');
						$d_unloaded['time'] = $tmp->format('H:i');
						$d_unloaded['timeS'] = $tmp->format('H:i:s');
					}

					$carry_days = '';
					if (strlen($v['datetime_arrived_in_load']) && strlen($v['datetime_unloaded'])) {
						$tmp = date_create($v['datetime_unloaded']);
						$tmp2 = date_create($v['datetime_arrived_in_load']);
						$carry_days = $tmp2->diff($tmp)->format("%a");
					}

					$num = 1;
					$destinationAll = array();
					$db2->Query(sprintf("
						select
						address
						from offload
						where tid=%s
						order by date
					", (int)$v['id']));
					$res = $db2->FetchAllAssoc();
					if (sizeof($res)) foreach($res AS $r) {
						$r = array_change_key_case($r, CASE_LOWER);

						$destinationAll[] = $num . '. ' . $r['address'];
						$num++;
					}
					$destinationAll = implode("\n", $destinationAll);

					$db2->Query(sprintf("
						select
						address
						from load
						where tid=%s
						order by date asc
					", (int)$v['id']));
					$res = $db2->FetchRowAssoc();
					$res = array_change_key_case($res, CASE_LOWER);
					$loadPlace = $res['address'];

					$db2->Query(sprintf("
						select
						address
						from offload
						where tid=%s
						order by date desc
					", (int)$v['id']));
					$res = $db2->FetchRowAssoc();
					$res = array_change_key_case($res, CASE_LOWER);
					$unloadPlace = $res['address'];

					$car = array();
					$driver = array();
					if (strlen(trim($v['ferrycar']))) $car[] = trim($v['ferrycar']);
					if (strlen(trim($v['ferrycarnumber']))) $car[] = trim($v['ferrycarnumber']);
					if (strlen(trim($v['ferrycarpp']))) $car[] = trim($v['ferrycarpp']);
					if (strlen(trim($v['ferrycarppnumber']))) $car[] = trim($v['ferrycarppnumber']);
					if (strlen(trim($v['ferryfiodriver']))) $driver[] = trim($v['ferryfiodriver']);
					if (strlen(trim($v['ferryphone']))) $driver[] = trim($v['ferryphone']);
					if (strlen(trim($v['ferrypassport']))) $driver[] = trim($v['ferrypassport']);
					$car = implode(' ', $car);
					$driver = implode(' ', $driver);

					$week = '';
					if (strlen($v['load_str'])) {
						$week = new DateTime($v['load_str']);
						$week = $week->format('W');
					}

	// 				$db2->Query(sprintf("
	// 					select
	// 					address
	// 					from load
	// 					where tid=%s
	// 					order by date
	// 				", (int)$v['id']));
	// 				$res = $db2->FetchAllAssoc();
	// 				if (sizeof($res)) foreach($res AS $r) {
	// 					$r = array_change_key_case($r, CASE_LOWER);

					if (!(int)$v['multimodal'])
						$idstr = $v['id'];
					else
						$idstr = sprintf("%s-%s", $v['multimodal_id'], $v['multimodal_num']);

					if ($type == 'main') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'№',
							$car, //'Авто',
							$driver, //'Данные водителя',
							$loadPlace, //'Место загрузки',
							'x', //'Таможня отправления',
							'x', //'Таможня назначения',
							$unloadPlace, //'Место выгрузки',
							$v['cargo'], //'Груз',
							$v['ferrycar'], //'Тип трака',
							$idstr, //'Номер заявки',
							'x', //'Согласованная дата/время подачи авто',
							$d_arrived_in_load['datetime'], //'Дата прибытия  на загрузку',
							$d_loaded['datetime'], //'Дата убытия с загрузки',
							'x', //'простой на загрузке/сутки',
							$v['bordercross_dateoad_str'], //'Дата пересечения границы',
							'x', //'Наименование МАПП',
							$v['last_manual_report'], //'Актуальная дислокация',
							'x', //'Дата прибытия на таможню',
							'x', //'Дата убытия с таможни',
							$d_arrived_in_unload['date'], //'Дата прибытия на выгрузку',
							$d_unloaded['datetime'], //'Дата выгрузки',
							'x', //'простой на выгрузке/сутки',
							'x' //'Примечание'
						));
					}
					elseif ($type == 'henkel') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'NO',
							$week, //'WEEK',
							$car . ' ' . $driver, //'DRIVER',
							$v['order_text'], //'Order',
							'88', //'Truck Type',
							'HARD', //'Trailer Type',
							$v['loadtime_str'], //'Load confirmation',
							$destinationAll //'DESTINATION'
						));
					}
					elseif ($type == 'htns') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'номер порядковый',
							$week, //'Неделя',
							'RU - KZ', //'Маршрут',
							$v['ferrycarnumber'], //'Номеря тягача',
							$v['ferrycarppnumber'], //'Номер принцепа',
							'TN100', //'Обьем прицепа',
							'TENT', //'Тип прицепа',
							'WM', //'Тип груза',
							$v['ferryfiodriver'], //'ФИО водителя',
							$v['ferryphone'], //'конт номер водителя',
							$v['unload_last_comment'], //'Получатель',
							$v['loadtime_str'], //'Ожидаемая дата прибытия авто на склад погрузки',
							$d_arrived_in_load['datetime'], //'Фактическая дата прибытия ато на склад погрузки',
							$d_loaded['datetime'], //'Фактическая дата убытия авто со склада',
							$v['bordercross_dateoad_str'], //'Дата пересечения границы',
							$v['bordercross'], //'Наименование погран перехода',
							$v['offloadtime_str'], //'Предварительная дата прибытия на выгрузку',
							$d_arrived_in_unload['datetime'], //'Фактическая дата прибытия на выгрузку',
							$v['clienttoplace'], //'Город выгрузки',
							$v['last_manual_report'], //'Трейсинг на сегодня',
							'', //'Количество км от местонахождения до пункта выгрузки',
							$carry_days, //'Фактическое количество дней доставки',
							'', //'комментарии',
							$v['surv_factprint'] //'Фактическая печать в смр подрядчика'
						));
					}
					elseif ($type == 'serk_sekz') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'Номер',
							'Enica', //'Экспедитор',
							$week, //'Неделя',
							'RU - KZ', //'Маршрут',
							$v['ferrycarnumber'], //'Тягач',
							$v['ferrycarppnumber'], //'Прицеп',
							'HARD', //'Тип прицепа',
							'TV', //'Тип груза',
							$v['ferryfiodriver'], //'ФИО водителя',
							$v['ferryphone'], //'конт номер водителя',
							$v['unload_last_comment'], //'Получатель груза',
							$v['order_text'], //'Номер инвойса (получаешь после погрузки авто)',
							$v['ferrywaybill'], //'Номер СМР (получаем после погрузки авто )',
							'', //'Дополнительная погрузка на складе',
							$v['date_str'], //'Дата поступления заказа в ЭНИКу',
							$v['loadtime_str'], //'Ожидаемая дата прибытия авто на погрузку',
							$d_arrived_in_load['datetime'], //'Фактическая дата прибытия авто на погрузку',
							$d_loaded['datetime'], //'Фактическая дата убытия с погрузки',
							$v['bordercross_dateoad_str'], //'Дата пересечения границы',
							$v['bordercross'], //'Погран переход',
							$v['offloadtime_str'], //'Предварительная дата прибытия на выгрузку',
							$d_arrived_in_unload['datetime'], //'Фактическая дата прибытия на выгрузку',
							$v['clienttoplace'], //'Город выгрузки',
							$v['last_manual_report'], //'Трейсинг сегодня',
							'', //'Количество км от местонахождения до пункта выгрузки',
							$carry_days, //'Фактическое количество дней доставки',
							'', //'комментарии',
							$v['surv_factprint'] //'Фактическая печать в смр подрядчика'
						));
					}
					elseif ($type == 'srdc') {
						$wExcel->writeSheetRow('Лист', array(
							$row, //'NO',
							'ENICA', //'FWDR',
							$week, //'WEEK',
							'RU-KZ', //'ROUTE',
							$v['order_text'], //'D/O',
							$v['ferrycarnumber'], //'TRUCK NO',
							$v['ferrycarppnumber'], //'TRAILER NO',
							'HARD', //'Trailer TYPE',
							$v['ferryfiodriver'], //'DRIVER',
							$v['ferryphone'], //'TEL.NO',
							$d_arrived_in_load['date'], //'ATA LOADING PLACE',
							$d_loaded['date'], //'ATD LOADING PLACE',
							$v['offload_str'], //'ETA/ATA CONSIGNEE',
							$d_arrived_in_unload['date'], //'UNLOADING',
							$v['clienttoplace'], //'DESTINATION',
							$v['last_manual_report'], //'PRESENT POSITION',
							'' //'REMARK'
						));
					}
					elseif ($type == 'milk') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'1',
							$v['client_name'], //'Клиент',
							$week, //'Неделя',
							$v['ferrycar'], //'Трак',
							$v['ferrycarnumber'], //'№ Трака',
							$v['typets'], //'тип прицепа',
							$v['ferryman_typets'],
							$v['ferrycarppnumber'], //'№ прицепа',
							$v['cargo'], //'Груз',
							$v['ferryfiodriver'], //'ФИО Водителя',
							$v['ferryphone'], //'Номер телефона',
							$idstr, //'№ Заявки',
							$v['date_str'], //'Дата Заявки',
							$loadPlace, //'Место загрузки',
							$v['unload_last_comment'], //'Грузополучатель',
							$unloadPlace, //'Место выгрузки',
							$v['loadtime_str'], //'Плановое время прибытия',
							$d_arrived_in_load['datetime'], //'Фактическое время прибытия',
							'', //'Фактическое время начала погрузки',
							$d_loaded['datetime'], //'Фактическое время выезда с завода',
							$v['last_manual_report'], //'Текущее местоположение',
							$v['offloadtime_str'], //'Плановое время прибытия по заявке',
							$d_arrived_in_unload['datetime'], //'Фактическое время прибытия на выгрузку',
							'', //'Фактическое время начала выгрузки',
							$d_unloaded['datetime'], //'Фактическое время окончания выгрузки и выдачи ТСД',
							'', //'Время работы ТС,  часов',
							'', //'Простой,  часов',
							'' //'Примечание'
						));
					}
					elseif ($type == 'status') {
						$wExcel->writeSheetRow('Лист', array(
							'',
							$row, //'№ п/п',
							$loadPlace, //'Место загрузки',
							$unloadPlace, //'Место выгрузки',
							$car, //'Номер ТС',
							$driver, //'Данные водителя',
							$d_arrived_in_load['date'], //'Дата и время прибытия на загрузку',
							$d_arrived_in_load['time'], //'',
							$d_loaded['date'], //'Дата и время убытия с места загрузки',
							$d_loaded['time'], //'',
							$v['bordercross_dateoad_str'], //'Дата пересечения границы',
							$v['last_report'], //'Актуальная дислокация',
							$d_arrived_in_unload['date'], //'Дата и время прибытия на выгрузку',
							$d_arrived_in_unload['time'], //'',
							$d_unloaded['date'], //'Дата и время убытия с выгрузки',
							$d_unloaded['time'], //'',
							'' //'Примечание'
						));
					}
					$row++;
				}
			} while($count == $limit);

			// что-то попадало в вывод и заставляло excel writer выдавать битые файлы
			// добавлена очистка stdout, как временный фикс
			ob_clean();
			$wExcel->writeToStdOut($filename);
		}
		catch (Exception $e) {
            error_log($e);
			die("Извините. Что-то пошло не так.");
		}
	}

	public function reestrData() {
		ini_set("max_execution_time", "600");

		$userId = $this->app->userId;

		$data = json_decode($_REQUEST['filtr'], true);
		$filtr = $this->composeFilter($data);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		try {
			$start = 0;
			$limit = 100;

			$type = $_REQUEST['type'];

			if ($type == 'masterTool') {
				$objReader = PHPExcel_IOFactory::createReader('Excel2007');
				$objPHPExcel = $objReader->load(appConfig::$DirectoryUploads . '/reestr/reestrMasterTool.xlsx');

				$row = 9;
			}
			elseif ($type == 'lotte') {
				$objReader = PHPExcel_IOFactory::createReader('Excel2007');
				$objPHPExcel = $objReader->load(appConfig::$DirectoryUploads . '/reestr/reestrLotte.xlsx');

				$row = 12;
			}
			else
				die();

			$sheet = $objPHPExcel->getSheet(0);

			$db2 = clone $this->db;
			$num = 0;

			$sumTotal = 0.0;
			$sumFineTotal = 0.0;
			$sumOtherTotal = 0.0;
			$sumFinalTotal = 0.0;

			do {
				$this->db->Query(sprintf("
					select
					transportation.id,
					transportation.client_request_no,
					
					to_char(transportation.load, 'DD.MM.YYYY') as load_str,
					to_char(transportation.load, 'DD.MM.YYYY HH24:MI:SS') as datetime_load,
					(select to_char(date, 'DD.MM.YYYY HH24:MI:SS') from transp_report where (objid=transportation.id) and (type='transpStatusLoaded') order by id desc limit 1) as datetime_loaded,
					(select to_char(date, 'DD.MM.YYYY HH24:MI:SS') from transp_report where (objid=transportation.id) and (type='transpStatusUnloaded') order by id desc limit 1) as datetime_unloaded,

					(select concat_ws(' ', address, contacts) from load where (tid=transportation.id) order by date asc limit 1) as load_adr_contact,
					(select address from offload where (tid=transportation.id) order by date desc limit 1) as unload_adr,
					(SELECT comment FROM offload WHERE (tid = transportation.id) ORDER BY date DESC LIMIT 1) AS unload_receiver,
					
					transportation.ferrycar,
					transportation.ferrycarnumber,
					transportation.ferrycarpp,
					transportation.ferrycarppnumber,
					
					transportation.ferryfiodriver,
					transportation.ferryphone,
					transportation.ferrypassport,

					transportation.clientothercharges,
					transportation.client_currency_total,
					transportation.finetoclient,
					
					transportation.comment,
					
					client.name AS client_name
	
					from transportation
					LEFT OUTER JOIN manager ON (manager.id = transportation.manager)
					LEFT OUTER JOIN manager logist ON (logist.id = transportation.logist)
					LEFT OUTER JOIN client ON (client.id = transportation.client)
					LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
					%s
					ORDER BY transportation.load ASC, transportation.multimodal_id, transportation.multimodal_num ASC
				", $filtrby));
				$items = $this->db->FetchAllAssoc($start, $limit, $pc);
				$count = sizeof($items);

				$start += $limit;

				if (sizeof($items)) foreach($items AS $v) {
					$v = array_change_key_case($v, CASE_LOWER);

					$num++;

					$car = sprintf("%s %s", $v['ferrycar'], $v['ferrycarnumber']);
					if (strlen(trim($v['ferrycarppnumber']))) $car .= ' / '.trim($v['ferrycarppnumber']);

					$driver = sprintf("%s %s %s", $v['ferryfiodriver'], $v['ferrypassport'], $v['ferryphone']);

					if ($type == 'masterTool') {
						$sheet->setCellValue('B'.$row, $num);
						$sheet->setCellValue('C'.$row, $v['client_request_no']);
						$sheet->setCellValue('D'.$row, $v['load_str']);
						$sheet->setCellValue('E'.$row, $v['datetime_loaded']);
						$sheet->setCellValue('F'.$row, $v['datetime_unloaded']);

						$sheet->setCellValue('H'.$row, $v['load_adr_contact']);
						$sheet->setCellValue('I'.$row, $v['unload_adr']);
						$sheet->setCellValue('J'.$row, $v['unload_receiver']);

						$sheet->setCellValue('K'.$row, $car);
						$sheet->setCellValue('L'.$row, $driver);

						$sumTotal += (float)$v['client_currency_total'];
						$sumOtherTotal += (float)$v['clientothercharges'];
						$sum = (float)$v['client_currency_total'] + (float)$v['clientothercharges'];
						$sumFinalTotal += $sum;

						$sheet->getStyle('M'.$row)->getNumberFormat()->setFormatCode('0');
						$sheet->setCellValue('M'.$row, (float)$v['client_currency_total']);
						$sheet->getStyle('N'.$row)->getNumberFormat()->setFormatCode('0');
						if ((float)$v['clientothercharges']) $sheet->setCellValue('N'.$row, (float)$v['clientothercharges']);
						$sheet->getStyle('O'.$row)->getNumberFormat()->setFormatCode('0');
						$sheet->setCellValue('O'.$row, $sum);

						$sheet->setCellValue('P'.$row, $v['comment']);
					}
					elseif ($type == 'lotte') {
						$sheet->insertNewRowBefore($pBefore = $row, $pNumRows = 1);
						$sheet->setCellValue('B'.$row, $num);
						$sheet->setCellValue('C'.$row, $v['client_request_no']);
						$sheet->setCellValue('D'.$row, $v['datetime_load']);
						$sheet->setCellValue('E'.$row, 'ООО «Лотте КФ Рус», Калужская область, г. Обнинск, 106 км Киевского шоссе, фабрика LOTTE / Анастасия +7 967 172-13-89');
						$sheet->setCellValue('F'.$row, $car);
						$sheet->setCellValue('G'.$row, $driver);
						$sheet->setCellValue('H'.$row, $v['datetime_unloaded']);
						$sheet->setCellValue('I'.$row, $v['unload_adr']);
						$sheet->setCellValue('J'.$row, $v['unload_receiver']);

						$sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode('0');
						$sheet->setCellValue('K'.$row, (float)$v['client_currency_total']);
						$sheet->getStyle('O'.$row)->getNumberFormat()->setFormatCode('0');
						$sheet->setCellValue('O'.$row, (float)$v['client_currency_total']);

						if ((float)$v['finetoclient']) {
							$row++;
							$sheet->insertNewRowBefore($pBefore = $row, $pNumRows = 1);
							$sheet->getStyle('K'.$row)->getNumberFormat()->setFormatCode('0');
							$sheet->setCellValue('K'.$row, (float)$v['finetoclient']);
							$sheet->getStyle('O'.$row)->getNumberFormat()->setFormatCode('0');
							$sheet->setCellValue('O'.$row, (float)$v['finetoclient']);

							foreach(array('B','C','D','E','F','G','H','I','J','P') AS $col) $sheet->mergeCells(sprintf("%s%s:%s%s", $col, $row-1, $col, $row));
						}
					}

					$row++;
				}
			} while($count == $limit);

			if ($type == 'masterTool') {
				$sheet->setCellValue('A'.$row, 'Итого:');
				$sheet->setCellValue('B'.$row, $num);
				$sheet->getStyle('M'.$row)->getNumberFormat()->setFormatCode('0');
				$sheet->setCellValue('M'.$row, $sumTotal);
				$sheet->getStyle('N'.$row)->getNumberFormat()->setFormatCode('0');
				$sheet->setCellValue('N'.$row, $sumOtherTotal);
				$sheet->getStyle('O'.$row)->getNumberFormat()->setFormatCode('0');
				$sheet->setCellValue('O'.$row, $sumFinalTotal);
			}
			elseif ($type == 'lotte') {
				$row--;
				$sheet->removeRow($pRow = 11, $pNumRows = 1);
				$sheet->setCellValue('B'.$row, $num);
				$sheet->getStyle('M'.$row)->getNumberFormat()->setFormatCode('0');
				$sheet->setCellValue('K'.$row, sprintf('=SUM(K11:K%s)', $row-1));
				$sheet->getStyle('N'.$row)->getNumberFormat()->setFormatCode('0');
				$sheet->setCellValue('O'.$row, sprintf('=SUM(O11:O%s)', $row-1));
			}

			$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
			$objWriter->setPreCalculateFormulas(true);
// 			$objWriter = PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xls');

			// что-то попадало в вывод и заставляло excel writer выдавать битые файлы
			// добавлена очистка stdout, как временный фикс
			ob_clean();

			header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header('Content-Disposition: attachment;filename="reestr.xlsx"');
			header('Cache-Control: max-age=0');
			header('Cache-Control: max-age=1');

			header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
			header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
			header ('Cache-Control: cache, must-revalidate');
			header ('Pragma: public'); // HTTP/1.0

			$objWriter->save('php://output');
		}
		catch (Exception $e) {
            error_log($e);
			die("Извините. Что-то пошло не так.");
		}
	}

	public function performanceData() {
		ini_set("max_execution_time", "600");

		$userId = $this->app->userId;

		$data = json_decode($_REQUEST['filtr'], true);
		$filtr = $this->composeFilter($data);

		if (sizeof($filtr))
			$filtrby = "WHERE " . implode(" and ", $filtr);
		else
			$filtrby = '';

		try {
			$start = 0;
			$limit = 100;

			$type = $_REQUEST['type'];

			$objReader = PHPExcel_IOFactory::createReader('Excel2007');
			$objPHPExcel = $objReader->load(appConfig::$DirectoryUploads . '/templates/performance.xlsx');

			$sheet = $objPHPExcel->getSheet(0);
			$sheet->getStyle("A1:AO3")->applyFromArray(array(
				'fill' => array(
					'type' => PHPExcel_Style_Fill::FILL_SOLID,
					'color' => array('rgb' => '8eb4e3')
				)
			));
			
			$db2 = clone $this->db;
			
			$startRow = 4;
			$row = $startRow;
			$num = 0;

			do {
                // TODO check is it need cause it has an error: client_currency/ferry_currency should get from client contract currency
                $this->db->Query(sprintf("
					select
					transportation.id,
					transportation.multimodal,
					transportation.multimodal_id,
					transportation.multimodal_num,
					to_char(transportation.date,'DD.MM.YYYY') AS date,
					to_char(transportation.load, 'DD.MM.YYYY') AS load,
					to_char(transportation.offload, 'DD.MM.YYYY') AS offload,
					
					transportation.client,
					transportation.clientcontract,
					transportation.clientfromplace,
					transportation.clienttoplace,
					transportation.client_currency,
					transportation.client_currency_sum,
					transportation.client_currency_rate,
					transportation.client_currency_total,
					transportation.clientothercharges,
					transportation.cargoinsuranceusvalue,
					to_char(transportation.clientinvoicedate,'DD.MM.YYYY') AS clientinvoicedate,
					transportation.clientinvoice,
					to_char(transportation.clientinvoicedate,'DD.MM.YYYY') AS clientinvoice_actdate,
					transportation.clientinvoice_act,
					to_char(transportation.client_plandate,'DD.MM.YYYY') AS client_plandate,
					transportation.clientpaid,
					transportation.client_currency_leftpaym,
					to_char(transportation.clientdocdate,'DD.MM.YYYY') AS clientdocdate,

					client.name AS client_name,
					clientcontract.paytype as clientcontract_paytype,
					
					transportation.ferryman,
					transportation.ferrycar,
					transportation.ferrycarnumber,
					transportation.ferrycarpp,
					transportation.ferrycarppnumber,
					transportation.ferryfiodriver,

					transportation.ferry_currency,
					transportation.ferry_currency_sum,
					transportation.ferry_currency_rate,
					transportation.ferry_currency_total,
					to_char(transportation.ferryinvoicedate,'DD.MM.YYYY') AS ferryinvoicedate,
					transportation.ferryinvoice,
					to_char(transportation.ferry_plandate,'DD.MM.YYYY') AS ferry_plandate,
					to_char(transportation.ferrydocdate,'DD.MM.YYYY') AS ferrydocdate,
					
					ferryman.name AS ferryman_name,
					
					transportation.profit,
					transportation.profitfact,
					
					userLogin(
						CASE
							WHEN (transportation.clientnds = 0) THEN client.accountant
							WHEN (transportation.clientnds = 20) THEN client.accountant_rf
						END
					) AS client_accountant,
					
					userLogin(transportation.manager) AS manager_login
	
					from transportation
					LEFT OUTER JOIN manager ON (manager.id = transportation.manager)
					LEFT OUTER JOIN manager logist ON (logist.id = transportation.logist)
					LEFT OUTER JOIN client ON (client.id = transportation.client)
					LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
					LEFT OUTER JOIN contract clientcontract on (transportation.clientcontract = clientcontract.id)
					%s
					ORDER BY transportation.load ASC, transportation.multimodal_id, transportation.multimodal_num ASC
				", $filtrby));
				$items = $this->db->FetchAllAssoc($start, $limit, $pc);
				$count = sizeof($items);

				$start += $limit;

				
				if (sizeof($items)) foreach($items AS $v) {
					$v = array_change_key_case($v, CASE_LOWER);

					$num++;

					if (!(int)$v['multimodal'])
						$idstr = sprintf(appConfig::$IdStrFormat, $v['id']);
					else
						$idstr = sprintf(appConfig::$IdStrFormat."-%s", $v['multimodal_id'], $v['multimodal_num']);

					$sheet->getRowDimension($row)->setRowHeight(38.25);
					$sheet->getStyle(sprintf('A%s:AI%s', $row, $row))->applyFromArray(array(
						'fill' => array(
							'type' => PHPExcel_Style_Fill::FILL_SOLID,
							'color' => array('rgb' => 'e6e0ec')
						)
					));
					$sheet->getStyle(sprintf('AJ%s:AO%s', $row, $row))->applyFromArray(array(
						'fill' => array(
							'type' => PHPExcel_Style_Fill::FILL_SOLID,
							'color' => array('rgb' => 'fcd5b5')
						)
					));
					$sheet->getStyle(sprintf('A%s:AO%s', $row, $row))->applyFromArray(array(
						'borders' => array (
							'allborders' => array (
								'style' => PHPExcel_Style_Border::BORDER_THIN,
								'color' => array('rgb' => '000000'),
							)
						),
						'font' => array(
							'size' => 9
						),
						'alignment' => array(
							'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
							'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
							'wrap' => true
						)
					));
					$sheet->getStyle('E'.$row)->applyFromArray(array(
						'font' => array(
							'bold' => true
						),
						'alignment' => array(
							'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT
						)
					));
					$sheet->getStyle('G'.$row)->applyFromArray(array(
						'alignment' => array(
							'vertical' => PHPExcel_Style_Alignment::VERTICAL_TOP,
						)
					));
					$sheet->getStyle('W'.$row)->applyFromArray(array(
						'alignment' => array(
							'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT
						)
					));
					$sheet->getStyle('O'.$row)->getNumberFormat()->setFormatCode('#,##0.00'); //'#,##0.00\ "₽"'
					$sheet->getStyle('AJ'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
					$sheet->getStyle('AK'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
					
					$sheet->setCellValue('A'.$row, $num);
					$sheet->setCellValue('B'.$row, $idstr);
					$sheet->setCellValue('C'.$row, $v['load']);
					$sheet->setCellValue('D'.$row, $v['offload']);
					$sheet->setCellValue('E'.$row, $v['client_name']);
					$sheet->setCellValue('F'.$row, sprintf("%s от %s", $idstr, $v['date']));
					$sheet->setCellValue('G'.$row, sprintf("%s %s/%s %s", $v['ferrycar'], $v['ferrycarnumber'], $v['ferrycarppnumber'], $v['ferryfiodriver']));

					if ((int)$v['clientcontract']) {
						$db2->Query(sprintf("
							SELECT
							no,
							to_char(date, 'DD.MM.YYYY') AS date,
							name
							FROM contract
							WHERE id = '%s'
						", (int)$v['clientcontract']));
						$res = $db2->FetchRowAssoc();
						$res = array_change_key_case($res, CASE_LOWER);
					}
					else {
						$db2->Query(sprintf("
							SELECT
							no,
							to_char(date, 'DD.MM.YYYY') AS date,
							name
							FROM contract
							WHERE cid = '%s'
							ORDER BY main DESC, id DESC
							limit 1
						", (int)$v['client']));
						$res = $db2->FetchRowAssoc();
						$res = array_change_key_case($res, CASE_LOWER);
					}
					if (!strlen($res['no']) && !strlen($res['date']))
						$client_contr_no = $res['name'];
					else
						$client_contr_no = sprintf("%s%s%s", $res['no'], strlen($res['date']) ? " от ":"", $res['date']);
					$sheet->setCellValue('H'.$row, $client_contr_no);

					$sheet->setCellValue('J'.$row, $v['clientfromplace'] .' - '. $v['clienttoplace']);
					$sheet->setCellValue('K'.$row, (float)$v['client_currency_rate']);
                    // TODO check is it need cause it has an error: client_currency should get from client contract currency
                    $sheet->setCellValue('L'.$row, $v['client_currency']);
					$sheet->setCellValue('M'.$row, (float)$v['client_currency_sum']);
					$sheet->setCellValue('N'.$row, (float)$v['client_currency_total']);
					$sheet->setCellValue('O'.$row, (float)$v['clientothercharges']);
					$sheet->setCellValue('P'.$row, (float)$v['cargoinsuranceusvalue']);

					if (strlen(trim($v['clientinvoice'])) || strlen(trim($v['clientinvoicedate']))) {
						$billnum = str_replace(array('Счет № ', 'Счет №', ' (предоплата, auto)'), '', trim($v['clientinvoice']));
						$billnum .= sprintf(" от %s", $v['clientinvoicedate']);
						$sheet->setCellValue('Q'.$row, $billnum);
					}

					$sheet->setCellValue('R'.$row, $v['client_plandate']);

					if ((int)$v['clientpaid'] || ((float)$v['client_currency_leftpaym'] < 0)) {
						$currency = $v['client_currency'];
						if ($v['clientcontract_paytype'] == 'RUR') $currency = 'RUR';

						$db2->Query(sprintf("
							select
 							to_char(p.payorderdate, 'DD.MM.YYYY') AS payorderdate
							from payment p
							where (p.tid='%s') and (p.currency='%s') and (p.cash='0') and (p.type='IN')
							order by p.payorderdate desc
							limit 1
						", (int)$v['id'], $currency));
						$res = $db2->FetchRowAssoc();
						$res = array_change_key_case($res, CASE_LOWER);

						$sheet->setCellValue('S'.$row, $res['payorderdate']);
					}

					if (strlen(trim($v['clientinvoice_act'])) || strlen(trim($v['clientinvoice_actdate']))) {
						$billnum = trim($v['clientinvoice_act']);
						$billnum .= sprintf(" от %s", $v['clientinvoice_actdate']);
						$sheet->setCellValue('U'.$row, $billnum);
					}

					$sheet->setCellValue('V'.$row, $v['clientdocdate']);

					$sheet->setCellValue('W'.$row, $v['ferryman_name']);
					$sheet->setCellValue('X'.$row, sprintf("%s от %s", $idstr, $v['date']));
					$sheet->setCellValue('Y'.$row, $v['ferry_currency']);
					$sheet->setCellValue('Z'.$row, (float)$v['ferry_currency_sum']);
					$sheet->setCellValue('AB'.$row, (float)$v['ferry_currency_rate']);
					$sheet->setCellValue('AC'.$row, (float)$v['ferry_currency_total']);

					if (strlen(trim($v['ferryinvoice'])) || strlen(trim($v['ferryinvoicedate']))) {
						$billnum = trim($v['ferryinvoice']);
						$billnum .= sprintf(" от %s", $v['ferryinvoicedate']);
						$sheet->setCellValue('AD'.$row, $billnum);
					}

					$sheet->setCellValue('AE'.$row, $v['ferry_plandate']);
					$sheet->setCellValue('AF'.$row, $v['ferrydocdate']);

					//////////////////
					$db2->Query(sprintf("
						SELECT
						no,
						to_char(date, 'DD.MM.YYYY') AS date,
						name,
						payby,
						paydelay
						FROM contract
						WHERE fid = '%s'
						ORDER BY main DESC, id DESC
						limit 1
					", (int)$v['ferryman']));
					$res = $db2->FetchRowAssoc();
					$res = array_change_key_case($res, CASE_LOWER);
					if (!strlen($res['no']) && !strlen($res['date']))
						$client_contr_no = $res['name'];
					else
						$client_contr_no = sprintf("%s%s%s", $res['no'], strlen($res['date']) ? " от ":"", $res['date']);
					$sheet->setCellValue('AG'.$row, $client_contr_no);

					$map = array(
						'ORIGINAL' => 'По оригиналам',
						'SCAN' => 'По сканам'
					);
					$sheet->setCellValue('AH'.$row, $map[ $res['payby'] ]);
					$sheet->setCellValue('AI'.$row, $res['paydelay']);
					//////////////////

					$sheet->setCellValue('AJ'.$row, (float)$v['profit']);
					$sheet->setCellValue('AK'.$row, (float)$v['profitfact']);

					$sheet->setCellValue('AL'.$row, $v['client_accountant']);
					$sheet->setCellValue('AM'.$row, $v['manager_login']);

					$row++;
				}
			} while($count == $limit);
			
			$sheet->setCellValue('AJ'.$row, sprintf("=SUM(AJ%s:AJ%s)", $startRow, $row-1));
			$sheet->setCellValue('AK'.$row, sprintf("=SUM(AK%s:AK%s)", $startRow, $row-1));
			$sheet->getStyle(sprintf('AJ%s:AK%s', $row, $row))->applyFromArray(array(
				'font' => array(
					'size' => 9
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
					'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
					'wrap' => true
				),
				'numberformat' => array(
					'code' => '#,##0.00'
				)
			));

			$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
 			$objWriter->setPreCalculateFormulas(true);
// 			$objWriter = PhpOffice\PhpSpreadsheet\IOFactory::createWriter($objPHPExcel, 'Xls');

			// что-то попадало в вывод и заставляло excel writer выдавать битые файлы
			// добавлена очистка stdout, как временный фикс
			ob_clean();

			header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header('Content-Disposition: attachment;filename="performance.xlsx"');
			header('Cache-Control: max-age=0');
			header('Cache-Control: max-age=1');

			header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
			header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
			header ('Cache-Control: cache, must-revalidate');
			header ('Pragma: public'); // HTTP/1.0

			$objWriter->save('php://output');
		}
		catch (Exception $e) {
            error_log($e);
			die("Извините. Что-то пошло не так.");
		}
	}

	public function exportData() {
		ini_set("max_execution_time", "600");

		$userId = $this->app->userId;

		if ((int)$this->priv['transportation']['modExport']) {
			$data = json_decode($_REQUEST['filtr'], true);
			$filtr = $this->composeFilter($data);

			if (sizeof($filtr))
				$filtrby = "WHERE " . implode(" and ", $filtr);
			else
				$filtrby = '';

			try {
				$start = 0;
				$limit = 100;

				$wExcel = new Ellumilel\ExcelWriter();

				$p = new transportation3Object($this->app);
				
				$wExcel->writeSheetHeader('Лист', array(
					'id' => 'string',
					'Номер' => 'string',
					
					$p->model['typets']['caption'] => 'string',
					$p->model['ferryman_typets']['caption'] => 'string',
					$p->model['date']['caption'] => 'string',
					$p->model['logist']['caption'] => 'string',
					$p->model['manager']['caption'] => 'string',
					$p->model['client']['caption'] => 'string',
					$p->model['ferryman']['caption'] => 'string',
					$p->model['load']['caption'] => 'string',
					$p->model['offload']['caption'] => 'string',
					$p->model['ferryfromplace']['caption'] => 'string',
					$p->model['ferrytoplace']['caption'] => 'string',
					$p->model['clientpricenal']['caption'] => 'float',
					' ' => 'string',
					$p->model['client_currency']['caption'] => 'string',
					$p->model['client_currency_sum']['caption'] => 'float',
					$p->model['clientnds']['caption'] => 'string',
					$p->model['client_currency_rate']['caption'] => 'float',
					$p->model['client_currency_total']['caption'] => 'float',
					$p->model['clientrefund']['caption'] => 'float',
					$p->model['clientothercharges']['caption'] => 'float',
					$p->model['client_sns']['caption'] => 'string',
					$p->model['cargoinsuranceusvalue']['caption'] => 'float',
					$p->model['cargoinsuranceclientvalue']['caption'] => 'float',

					$p->model['ferryclientprice']['caption'] => 'float',
					$p->model['ferrypricenal']['caption'] => 'float',
					'  ' => 'string',
					$p->model['ferry_currency']['caption'] => 'string',
					$p->model['ferry_currency_sum']['caption'] => 'float',
					$p->model['ferrynds']['caption'] => 'string',
					$p->model['ferry_currency_rate']['caption'] => 'float',
					$p->model['ferry_currency_total']['caption'] => 'float',
					$p->model['ferryothercharges']['caption'] => 'float',
					$p->model['ferry_sns']['caption'] => 'string',
					$p->model['clientinvoicedate']['caption'] => 'string',
					$p->model['ferryinvoice']['caption'] => 'string',
					$p->model['ferryinvoicedate']['caption'] => 'string',
					$p->model['finetoclient']['caption'] => 'float',
					$p->model['finefromclient']['caption'] => 'float',
					$p->model['finetoferry']['caption'] => 'float',
					$p->model['finefromferry']['caption'] => 'float',
					$p->model['clientpaid']['caption'] => 'string',
					$p->model['ferrypaid']['caption'] => 'string',
					$p->model['clientdocdate']['caption'] => 'string',
					$p->model['ferrydocdate']['caption'] => 'string',
					$p->model['profit']['caption'] => 'float',
					$p->model['lprofit']['caption'] => 'float',
					$p->model['profitability']['caption'] => 'float',
					
					'Просрочка КЛ' => 'string',
					'Просрочка ПР' => 'string',
					
					$p->model['ferrycar']['caption'] => 'string',
					$p->model['ferrycarnumber']['caption'] => 'string',
					$p->model['ferrycarppnumber']['caption'] => 'string',
					$p->model['ferryfiodriver']['caption'] => 'string',
					$p->model['ferryphone']['caption'] => 'string',
					$p->model['ferrycontacts']['caption'] => 'string'
				));

				$attr = array();
				foreach($p->model AS $k => &$v) {
					if ($v['type'] == 'date')
						$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY') as %s", $k, $k);
					elseif ($v['type'] == 'datetime')
						$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY HH24:MI:SS') as %s", $k, $k);
					elseif ($v['type'] == 'datehm')
						$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY HH24:MI') as %s", $k, $k);
					else
						$attr[] = sprintf("transportation.%s", $k);
				}

				$attr[] = "manager.login AS manager_login";
				$attr[] = "logist.login AS logist_login";
				$attr[] = "client.name AS client_name";
				$attr[] = "ferryman.name AS ferryman_name";
				$attr[] = "client.paymdelay as client_paymdelay";
				$attr[] = "ferryman.contacts AS ferrycontacts";
				$attr[] = "client_contract.paytype as client_contract_paytype";
				$attr[] = "ferry_contract.paytype as ferry_contract_paytype";
                $attr[] = "transportation.typets as typets";
                $attr[] = "transportation.ferryman_typets as ferryman_typets";

				$this->db->Query(sprintf("
					SELECT %s
					FROM transportation
					LEFT OUTER JOIN manager ON (manager.id = transportation.manager)
					LEFT OUTER JOIN manager logist ON (logist.id = transportation.logist)
					LEFT OUTER JOIN client ON (client.id = transportation.client)
					LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
					
					LEFT OUTER JOIN contract client_contract on (transportation.clientcontract = client_contract.id)
					LEFT OUTER JOIN contract ferry_contract on (transportation.ferrycontract = ferry_contract.id)
					
					%s
					ORDER BY transportation.multimodal_id DESC, transportation.multimodal_num ASC
				", implode(",", $attr), $filtrby));

				do {
					$items = $this->db->FetchAllAssoc($start, $limit, $pc);
					$count = sizeof($items);

					$start += $limit;

					if (sizeof($items)) foreach($items AS $v) {
						$v = array_change_key_case($v, CASE_LOWER);
						$p->loadFromArray($v);
                        $p->typets = TransportTypeHelper::getTransportTypeStr($v['typets']);
                        $p->ferryman_typets = TransportTypeHelper::getTransportTypeStr($v['ferryman_typets']);

						$p->logist = $v['logist_login'];
						$p->manager = $v['manager_login'];
						$p->ferryman = $v['ferryman_name'];
						$p->client = $v['client_name'];

						$t = $this->getDelay($p->client_paymdelay, $p->clientpaid, $p->ferrypaid, $p->ferrydocdate, $p->offload);
						$p->delay_client = $t['delay_client'];
						$p->delay_ferry = $t['delay_ferry'];

						$p->clientinvoicedate = $p->clientinvoice ." от ". $p->clientinvoicedate;

						if (!$p->multimodal)
							$p->idstr = sprintf(appConfig::$IdStrFormat, $p->id);
						else
							$p->idstr = sprintf(appConfig::$IdStrFormat."-%s", $p->multimodal_id, $p->multimodal_num);

						$contractMap = [
							'RUR' => 'В рублях по курсу',
							'CONTRACT' => 'В валюте договора'
						];
						$p->client_contract_paytype = $contractMap[ $v['client_contract_paytype'] ];
						$p->ferry_contract_paytype = $contractMap[ $v['ferry_contract_paytype'] ];

						$clientndsMap = [
							20 => 'Внутрироссийская НДС 20%',
							0 => 'Международная НДС 0%'
						];
						$p->clientnds = $clientndsMap[ $p->clientnds ];

						$ferryndsMap = [
							'NDS' => 'с НДС',
							'WONDS' => 'без НДС',
							'ZERONDS' => '0% НДС'
						];
						$p->ferrynds = $ferryndsMap[ $p->ferrynds ];
						
						$line = array();
                        // TODO check is it need cause it has an error: client_currency should get from client contract currency
                        $attr = array('id','idstr','typets','ferryman_typets','date','logist','manager','client','ferryman','load','offload','ferryfromplace','ferrytoplace',
							'clientpricenal',
							'client_contract_paytype',
							'client_currency',
							'client_currency_sum',
							'clientnds',
							'client_currency_rate',
							'client_currency_total',
							'clientrefund','clientothercharges',
							'client_sns',
							'cargoinsuranceusvalue',
							'cargoinsuranceclientvalue',

							'ferryclientprice',

							'ferrypricenal',
							'ferry_contract_paytype',
							'ferry_currency',
							'ferry_currency_sum',
							'ferrynds',
							'ferry_currency_rate',
							'ferry_currency_total',

							'ferryothercharges',
							'ferry_sns',
							'clientinvoicedate','ferryinvoice','ferryinvoicedate','finetoclient','finefromclient','finetoferry','finefromferry','clientpaid','ferrypaid','clientdocdate','ferrydocdate','profit','lprofit','profitability','delay_client','delay_ferry', 'ferrycar', 'ferrycarnumber', 'ferrycarppnumber', 'ferryfiodriver', 'ferryphone', 'ferrycontacts');
						foreach($attr AS $a) {
							$line[] = str_replace(array("\r", "\n", "\t"), array("", " ", " "), $p->$a);
						}
						$wExcel->writeSheetRow('Лист', $line);
					}
				} while($count == $limit);

				// что-то попадало в вывод и заставляло excel writer выдавать битые файлы
				// добавлена очистка stdout, как временный фикс
				ob_clean();
				$wExcel->writeToStdOut('export.xlsx');

			}
			catch (Exception $e) {
                error_log($e);
				die("Извините. Что-то пошло не так.");
			}
		}
	}

	public function logGrid() {
		$res['items'] =  array();
		$res['totalCount'] = 0;

		$start = (int)req('start');
		$start = ($start >= 0) ? $start : 0;
		$limit = (int)req('limit');
		$limit = ($limit >= 0) ? $limit : 0;

		$id = (int)req('id');
		if ($this->priv['transportation']['viewLog'] && $id) {
			$this->db->Query(sprintf("
				SELECT
				to_char(log.date, 'DD.MM.YYYY HH24:MI:SS') AS date_str,
				userLogin(mid) AS user_login,
				log.log
				FROM log
				WHERE (tableitemid = '%s') AND (tablename = 'transportation')
				ORDER BY DATE DESC
			", $id));
			$res['items'] = $this->db->FetchAllAssoc($start, $limit, $pc);
			if (sizeof($res['items'])) foreach($res['items'] AS $ki => &$item) {
				$item = array_change_key_case($item, CASE_LOWER);
			}
			unset($item);

			$res['totalCount'] = $pc;
		}
		return json_encode($res);
	}
}

class transportation3Object {
	public $model = array(
		'id' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Номер'
		),
		'transp_status' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Статус'
		),
		'type' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Тип грузоперевозки' //0авто 1авиа 2жд 3море
		),
		'company' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Служебное.company'
		),
		'multimodal' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Мультимодальная'
		),
		'multimodal_id' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Родитель мультимодальной'
		),
		'multimodal_num' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Номер мультимодальной'
		),
		'profit' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Прибыль план'
		),
		'profitfact' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Прибыль факт'
		),
		'lprofit' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Логистическая прибыль'
		),
		'profitability' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Рентабельность'
		),
		'profitabilityfact' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Рентабельность факт'
		),
		'date' => array(
			'value' => 0,
			'type' => 'datetime',
			'caption' => 'Дата создания'
		),
		'logist' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Логист'
		),
		'comment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Примечание'
		),
		'order_text' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'ORDER'
		),

		//клиент
		'client' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Клиент'
		),
		'clientcontract' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Договор клиента'
		),
		'clientperson' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Контактное лицо клиента'
		),
		'manager' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Менеджер'
		),
		'typets' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Тип ТС'
		),
		'ferryman_typets' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Тип ТС'
		),
		'typets_desc' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Тип ТС. Описание'
		),
		'clientfromplace' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Маршрут.Откуда'
		),
		'clienttoplace' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Маршрут.Куда'
		),
		'cargo' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Характер груза'
		),
		'cargotemp1' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Температурный режим1'
		),
		'cargotemp2' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Температурный режим2'
		),
		'cargoplaces' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Количество погрузочных мест'
		),
		'cargoplacesttn' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Количество погрузочных мест. По ТТН'
		),
		'cargovolume' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Объем'
		),
		'cargoweight' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Вес'
		),
		'cargoweighttype' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Вес.Нетто/Брутто'
		),
		'cargoprofile' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Габариты'
		),
		'cargoother' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Иное'
		),
		'cargoloadtype' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Тип загрузки'
		),
		'cargounloadtype' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Тип выгрузки'
		),
		'clientnds' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Клиент НДС(Внутрироссийская/Международная)'
		),
		'cargoinsurance' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Страховка'
		),
		'cargoprice' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Стоимость груза'
		),
		'cargopricecurrency' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Валюта cтоимости груза'
		),
		'cargoinsuranceuspercent' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => '% страховщиков(наш)'
		),
		'cargoinsuranceusvalue' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Страховка.Итого.Наш'
		),
		'cargoinsuranceclientpercent' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => '% страховщиков(для клиента)'
		),
		'cargoinsuranceclientvalue' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Страховка.Итого.Для клиента'
		),
		'cargoinsurance_num' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Страховка. Номер страхового полиса'
		),
		'clientrefund' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.$'
		),
		'clientrefundpercent' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Клиент.% по $'
		),
		'clientothercharges' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Прочие расходы'
		),
		'clientotherchargestarget' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Прочие расходы.Цель'
		),
// 		'clientprice' => array(
// 			'value' => 0,
// 			'type' => 'float',
// 			'caption' => 'Клиент.Стоимость(б/н с НДС + нал)'
// 		),
// 		'clientpricewnds' => array(
// 			'value' => 0,
// 			'type' => 'float',
// 			'caption' => 'Клиент.Стоимость б/н с НДС'
// 		),
		'clientpricenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Стоимость нал'
		),
		'clientpricedeposit' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Залог'
		),
        'client_downtime_currency' => array(
            'value' => '',
            'type' => 'string',
            'caption' => 'Клиент.Простой.Валюта'
        ),
        'client_downtime_unit' => array(
            'value' => '',
            'type' => 'string',
            'caption' => 'Клиент.Простой.Ед.изм'
        ),
        'client_downtime_value' => array(
            'value' => '',
            'type' => 'string',
            'caption' => 'Клиент.Простой.Кол-во'
        ),
        'client_downtime_sum' => array(
            'value' => '',
            'type' => 'string',
            'caption' => 'Клиент.Простой.Сумма'
        ),
		'clientpaycomment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Примечания к оплате'
		),
		'clientinvoice' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.№ счета'
		),
		'clientinvoicedate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Дата счета'
		),
		'clientinvoice_act' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.№ акта'
		),
		'clientinvoice_actdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Дата акта'
		),
		'clientinvoice_scf' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.№ счф'
		),
		'clientinvoice_scfdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Дата счф'
		),
		'client_plandate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Плановая дата оплаты'
		),
		'clientpartpaid' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Клиент.Оплачено частично'
		),
		'clientpartpaidvaluewnds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено частично.Сумма.б/н с НДС'
		),
		'clientpartpayorderwnds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено частично.№ ПП.б/н с НДС'
		),
		'clientpartpayorderdatewnds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено частично.Дата ПП.б/н с НДС'
		),
		'clientpartpaidvaluenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено частично.Сумма.нал'
		),
		'clientpartpayordernal' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено частично.№ ПП.нал'
		),
		'clientpartpayorderdatenal' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено частично.Дата ПП.нал'
		),/*
		'clientpartpaidvalue' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено частично.Сумма'
		),
		'clientpartpayorder' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено частично.№ ПП'
		),
		'clientpartpayorderdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено частично.Дата ПП'
		),*/
		'clientpaid' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Клиент.Оплачено полностью'
		),
		'clientpaidvaluewnds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено полностью.Сумма.б/н с НДС'
		),
		'clientpayorderwnds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено полностью.№ ПП.б/н с НДС'
		),
		'clientpayorderdatewnds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено полностью.Дата ПП.б/н с НДС'
		),
		'clientpaidvaluenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено полностью.Сумма.нал'
		),
		'clientpayordernal' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено полностью.№ ПП.нал'
		),
		'clientpayorderdatenal' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено полностью.Дата ПП.нал'
		),
		'client_request_no' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Номер заявки клиент'
		),
		'client_request_date' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Дата заявки клиент'
		),
		'clientpaidvalue' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Оплачено RUR'
		),
		/*'clientpayorder' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Оплачено полностью.№ ПП'
		),
		'clientpayorderdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Клиент.Оплачено полностью.Дата ПП'
		),*/
		'client_tnnum' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Номер ТН клиент'
		),
		'region' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Регион/направление'
		),
		// загрузка/выгрузка
		'load' => array(
			'value' => '',
			'type' => 'datehm',
			'caption' => 'Дата загрузки'
		),
		'offload' => array(
			'value' => '',
			'type' => 'datehm',
			'caption' => 'Дата выгрузки'
		),
		'offloadchecked' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Дата выгрузки.Подтверждено'
		),
		'bordercross_date' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата пересечения границы'
		),
		'bordercross' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Погран переход'
		),
		// подрядчик
		'ferryman' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Подрядчик'
		),
		'ferrycontract' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Договор подрядчика'
		),
		'ferrymanperson' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Контактное лицо подрядчика'
		),
		'ferryfromplace' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Маршрут.Откуда'
		),
		'ferrytoplace' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Маршрут.Куда'
		),
		'ferryclientprice' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Стоимость для клиента(Бюджет)'
		),
		'ferrystampnumber' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Номер пломбы'
		),
		'ferrywaybill' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Номер накладной(ТН, ТТН, Торг-12)'
		),
		'ferrywagonnumber' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Номер вагона/контейнера'
		),
		'ferryshipname' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Наименование судна/номер рейса'
		),
		'ferrybolnumber' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Номер коносамента'
		),
		'ferryautoexpeditioatnstart' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Автоэкспедирование в пункте отправления'
		),
		'ferryautoexpeditionatdestionation' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Автоэкспедирование в пункте назначения'
		),
		'ferryairfreighttodestionation' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Авиаперевозка в аэропорт назначения'
		),
		'ferrydoortodoor' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Door to Door'
		),
		'ferrycar_id' => array(
			'value' => '',
			'type' => 'int',
			'caption' => 'Подрядчик.Ссылка на машину'
		),
		'ferrycar' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Марка а/м'
		),
		'ferrycarnumber' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Гос.номер а/м'
		),
		'ferrycarpp' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Марка п/п'
		),
		'ferrycarppnumber' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Гос.номер п/п'
		),
		'ferryfiodriver' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.ФИО водителя'
		),
		'ferryphone' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Контактный телефон'
		),
		'ferrypassport' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Паспортные данные'
		),
		'ferryothercharges' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Прочие расходы'
		),
		'ferryotherchargestarget' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Прочие расходы.Цель'
		),
		'ferryprice' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Стоимость(б/н с НДС + нал + б/н БЕЗ НДС)'
		),
		'ferrypricewnds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Стоимость б/н с НДС'
		),
		'ferrypricenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Стоимость нал'
		),
		'ferrypricewonds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Стоимость б/н БЕЗ НДС'
		),
		'ferrypayperiod' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Подрядчик.Сроки оплаты'
		),
		'ferrydowntime_currency' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Простой.Валюта'
		),
		'ferrydowntime_unit' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Простой.Ед.изм'
		),
		'ferrydowntime_value' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Подрядчик.Простой.Количество'
		),
		'ferrydowntime_sum' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Простой.Сумма'
		),
		'ferrypaycomment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Примечания к оплате'
		),
		'ferryinvoice' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.№ счета'
		),
		'ferryinvoicedate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Дата счета'
		),
		'ferryinvoice_act' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.№ акта'
		),
		'ferryinvoice_actdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Дата акта'
		),
		'ferryinvoice_scf' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.№ счф'
		),
		'ferryinvoice_scfdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Дата счф'
		),
		'ferry_plandate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Плановая дата оплаты'
		),
		'ferrypartpaid' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Подрядчик.Оплачено частично'
		),
		'ferrypartpaidvaluewnds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено частично.Сумма.б/н с НДС'
		),
		'ferrypartpayorderwnds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено частично.№ ПП.б/н с НДС'
		),
		'ferrypartpayorderdatewnds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено частично.Дата ПП.б/н с НДС'
		),
		'ferrypartpaidvaluenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено частично.Сумма.нал'
		),
		'ferrypartpayordernal' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено частично.№ ПП.нал'
		),
		'ferrypartpayorderdatenal' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено частично.Дата ПП.нал'
		),
		'ferrypartpaidvaluewonds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено частично.Сумма.б/н БЕЗ НДС'
		),
		'ferrypartpayorderwonds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено частично.№ ПП.б/н БЕЗ НДС'
		),
		'ferrypartpayorderdatewonds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено частично.Дата ПП.б/н БЕЗ НДС'
		),
		'ferrypaid' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Подрядчик.Оплачено полностью'
		),
		'ferrypaidvaluewnds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено полностью.Сумма.б/н с НДС'
		),
		'ferrypayorderwnds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено полностью.№ ПП.б/н с НДС'
		),
		'ferrypayorderdatewnds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено полностью.Дата ПП.б/н с НДС'
		),
		'ferrypaidvaluenal' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено полностью.Сумма.нал'
		),
		'ferrypayordernal' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено полностью.№ ПП.нал'
		),
		'ferrypayorderdatenal' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено полностью.Дата ПП.нал'
		),
		'ferrypaidvaluewonds' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено полностью.Сумма.б/н БЕЗ НДС'
		),
		'ferrypayorderwonds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Оплачено полностью.№ ПП.б/н БЕЗ НДС'
		),
		'ferrypayorderdatewonds' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Подрядчик.Оплачено полностью.Дата ПП.б/н БЕЗ НДС'
		),
		'ferryman_fact' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Фактический подрядчик'
		),
		'ferryman_internalcomment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Внутренний комментарий'
		),
		'ferrypaidvalue' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Оплачено RUR'
		),
		// документы
		'clientdocdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата отправки документов клиенту'
		),
		'clientdocdelivery' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Способ отправки документов клиенту'
		),
		'ferrydocdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата получения документов от подрядчика'
		),
		// штрафы
		'finetoclient' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Штраф к клиенту(платит клиент)'
		),
		'finefromclient' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Штраф от клиента(платим мы)'
		),
		'clientfinedesc' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Примечания к штрафам клиента'
		),
		'finetoferry' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Штраф к подрядчику(платит подрядчик)'
		),
		'finefromferry' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Штраф от подрядчика(платим мы)'
		),
		'ferryfinedesc' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Примечания к штрафам подрядчика'
		),
// 		'clientrequestsend' => array(
// 			'value' => 0,
// 			'type' => 'int',
// 			'caption' => 'Клиенту направлена заявка'
// 		),
// 		'ferryrequestsend' => array(
// 			'value' => 0,
// 			'type' => 'int',
// 			'caption' => 'Подрядчику направлена заявка'
// 		),
		'ferryscandocdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата получения скан копий от подрядчика'
		),
        'upd_shipment_date' => array(
            'value' => '',
            'type' => 'date',
            'caption' => 'Дата отправки счета, УПД(ЭДО)'
        ),
		'clientscandocdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата отправки скан копий'
		),
		'clientorigdocdate' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Дата получения клиентом оригиналов'
		),
		'clientdoccomment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Комментарий к отправленным клиенту документам'
		),
		'surv_spacerbar' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.Распорная штанга.Выдана'
		),
		'surv_spacerbar_count' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.Распорная штанга.Количество'
		),
		'surv_spacerbar_rcvd' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.Распорная штанга.Получена'
		),
		'surv_spacerbar_rcvd_date' => array(
			'value' => '',
			'type' => 'date',
			'caption' => 'Сюрвейер.Распорная штанга.Получена.Дата'
		),
		'surv_spacerbar_fio' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Сюрвейер.Распорная штанга.ФИО'
		),
		'surv_crm' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.CMR.Выданы'
		),
		'surv_crm_count' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.CMR.Количество'
		),
		'surv_crm_company' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.CMR.Компания'
		),
		'surv_beacon' => array(
			'value' => 0,
			'type' => 'int',
			'caption' => 'Сюрвейер.Маяк слежения.Выдан'
		),
		'surv_beacon_num' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Сюрвейер.Маяк слежения.Номер'
		),
		'surv_comment' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Сюрвейер.Комментарий'
		),
		'surv_factprint' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Сюрвейер.Фактическая печать в CMR'
		),

		'client_currency' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.Валюта'
		),
		'client_currency_sum' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Валюта.Сумма'
		),
		'client_currency_rate' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Валюта.Курс'
		),
		'client_currency_total' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Валюта.Итого'
		),
		'client_sns' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Клиент.СНС'
		),
		'ferry_currency' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Валюта'
		),
		'ferry_currency_sum' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Валюта.Сумма'
		),
		'ferry_currency_rate' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Валюта.Курс'
		),
		'ferry_currency_total' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Валюта.Итого'
		),
		'ferry_sns' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.СНС'
		),
		'ferrynds' => array(
			'value' => '',
			'type' => 'string',
			'caption' => 'Подрядчик.Способ оплаты'
		),

		'client_currency_leftpaym' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Клиент.Валюта.К оплате'
		),
		'ferry_currency_leftpaym' => array(
			'value' => 0,
			'type' => 'float',
			'caption' => 'Подрядчик.Валюта.К оплате'
		)
	);

	public function add_to_model($name, $value){
		$this->model[$name]=$value;
	}

	private $app;
	private $db;
	private $priv;
	private $old;
	private $allowedCount = 0;

	public $additional = array();

	private $data;
	private $mode;

	private $priceChanged = false;

	private $saveEvents = array();

	public function __construct($app) {
		$this->app = $app;
		$this->db = $app->db;
		$this->priv = $app->getPrivileges();
	}

	public function __set($name, $value) {
		$this->model[$name]['value'] = $value;
	}

	public function __get($name) {
        if (isset($this->model[$name])) {
            return $this->model[$name]['value'];
        }

        return null;
	}

	private function getValueByModel($model, $value) {
		if ($model['type'] == 'int') {
			$value = (int)$value;

            if ((isset($model['nullOnZero']) && $model['nullOnZero'] === true) && !$value) {
                $value = null;
            }
		}
		elseif ($model['type'] == 'float')
			$value = (float)$value;
		elseif ( ($model['type'] == 'date') || ($model['type'] == 'datetime')  || ($model['type'] == 'datehm') ) {
			if ( ($value == '01.01.1970') || ($value == '01.01.0001') ) $value = '';
		}

		return $value;
	}

	public function loadById($id) {
		if ($id) {
			$attr = array();
			foreach($this->model AS $k => &$v) {
				if ($v['type'] == 'date')
					$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY') as %s", $k, $k);
				elseif ($v['type'] == 'datetime')
					$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY HH24:MI:SS') as %s", $k, $k);
				elseif ($v['type'] == 'datehm')
					$attr[] = sprintf("to_char(transportation.%s, 'DD.MM.YYYY HH24:MI') as %s", $k, $k);
				elseif($k!='ferryman_typets_str')
					$attr[] = sprintf("transportation.%s", $k);
			}
			$attr[] = "manager.login AS manager_login";
			$attr[] = "logist.login AS logist_login";
			$attr[] = "client.name AS client_name";
			$attr[] = "
				userLogin(
					CASE
						WHEN (transportation.clientnds = 0) THEN client.accountant
						WHEN (transportation.clientnds = 20) THEN client.accountant_rf
					END
				) AS client_accountant
			";
			$attr[] = "ferryman.name AS ferryman_name";
			$attr[] = "client.paymdelay as client_paymdelay";
			$attr[] = "ferryman.contacts AS ferrycontacts";
            $attr[] = "transportation.ferryman_typets";
            $attr[] = "clientContract.currency AS clientContractCurrency";
            $attr[] = "clientContract.paytype AS clientcontractpayType";
            $attr[] = "carrierContract.currency AS carrierContractCurrency";
            $attr[] = "carrierContract.paytype AS carrierContractPayType";

			$this->db->Query(sprintf("
				SELECT %s
				FROM transportation
				LEFT OUTER JOIN manager ON (manager.id = transportation.manager)
				LEFT OUTER JOIN manager logist ON (logist.id = transportation.logist)
				LEFT OUTER JOIN client ON (client.id = transportation.client)
				LEFT OUTER JOIN ferryman ON (ferryman.id = transportation.ferryman)
			    LEFT OUTER JOIN contract clientContract ON (clientContract.id = transportation.clientcontract)
                LEFT OUTER JOIN contract carrierContract ON (carrierContract.id = transportation.ferrycontract)
				WHERE transportation.id = '%s'
			", implode(",", $attr), $id));
			$res = $this->db->FetchRowAssoc();
			if (sizeof($res)) {
				$res = array_change_key_case($res, CASE_LOWER);

                $carrierTypeTsString = isset($res['ferryman_typets'])
                    ? TransportTypeHelper::getTransportTypeStr($res['ferryman_typets'])
                    : '';

                $res['ferryman_typets_str'] = $carrierTypeTsString;

				foreach($this->model AS $k => &$v) {
					$v['value'] = $this->getValueByModel($v, $res[$k]);
				}
				unset($v);

				$this->additional['manager_login'] = $res['manager_login'];
				$this->additional['logist_login'] = $res['logist_login'];
				$this->additional['client_name'] = $res['client_name'];
				$this->additional['client_accountant'] = $res['client_accountant'];
				$this->additional['ferryman_name'] = $res['ferryman_name'];
				$this->additional['client_paymdelay'] = $res['client_paymdelay'];
				$this->additional['ferrycontacts'] = $res['ferrycontacts'];
                $this->additional['ferryman_typets_str'] = $carrierTypeTsString;
                $this->model['client_currency']['value'] = $res['clientcontractcurrency'];
                $this->model['client_contract_pay_type']['value'] = $res['clientcontractpaytype'];
                $this->model['ferry_currency']['value'] = $res['carriercontractcurrency'];
                $this->model['carrier_contract_pay_type']['value'] = $res['carriercontractpaytype'];

				return true;
			}
		}
		return false;
	}

	public function loadFromRequest($id, $mode, $data) {
		if ($mode == 'new') $id = 0;
		$data['data']['id'] = $id;

		$this->data = $data;
		$this->mode = $mode;

        foreach ($this->model as $k => &$v) {
            $v['value'] = $this->getValueByModel($v, $this->data['data'][$k] ?? null);
        }

        unset($v);
	}

	public function loadFromArray($data) {
		$this->data = array('data' => $data);

		foreach($this->model AS $k => &$v) {
			$v['value'] = $this->getValueByModel($v, $this->data['data'][$k]);
		}
		unset($v);
	}

    public function toArray($blocked2Null = true) {
        $res = $this->additional;
        foreach($this->model as $k => $v) {
            if ((isset($v['allowed']) && $v['allowed']) || (isset($v['readOnly']) && $v['readOnly']) || !$blocked2Null) {
                $res[$k] = $v['value'];
            }
        }

        return $res;
    }

    public function proccessChanged() {
        $this->blockAll();

        foreach($this->data['data'] AS $k => &$v) {
            if ( (isset($this->model[ $k ])) && ($this->model[ $k ]['value'] != $this->data['origData'][ $k ]) ) $this->allow($k);
        }

        unset($v);
    }

	public function proccessPrivileges() {
		if ($this->id) {
			$this->db->Query(sprintf("
				SELECT
				offloadchecked
				FROM transportation
				WHERE id = '%s'
			", $this->id));
			$res = $this->db->FetchRowAssoc();
			$res = array_change_key_case($res, CASE_LOWER);

            if (!isset($this->priv['transportation']['modChangeOwner']) || !(int)$this->priv['transportation']['modChangeOwner']) {
                $this->block('manager');
            }

			if ((int)$res['offloadchecked'] && !(int)$this->priv['transportation']['modEditAfterOffloadChecked']) {
				$this->blockAllReadOnly();

				$this->data['loadGridDeleted'] = array();
				$this->data['unloadGridDeleted'] = array();
				$this->data['loadGrid'] = array();
				$this->data['unloadGrid'] = array();
			}
		}


	//TODO
/*
			if ( ($p->logist == (int)$user['id']) && ((int)$user['man']) )
				$tpldata['CommonTabReadOnly'] =  array('d'=>1);
			if ( (!$p->multimodal_id) && ( ($p->manager == (int)$user['id']) || ((int)$user['role'] >=2) ) )
				$tpldata['ClientTab']
				$tpldata['ClientTab']['ClientTabCommon']
*/

	}

	public function getAllowedFields() {
		$result = array(
			'allowedCount' => $this->allowedCount,
			'fields' => array()
		);

		return $result;
	}

	private function loadUnloadPrepareSaveProc($type, $v, $origGrid) {
		$tmpdt = null;
		$tmptime = null;
		if (preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/", $v['date'])) $tmpdt = date_create($v['date']);
		if (preg_match("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/", $v['time'])) $tmptime = date_create($v['time']);

		if ($tmpdt && $tmptime) $tmpdt = date_create($tmpdt->format('Y-m-d') . ' ' . $tmptime->format('H:i'));
		elseif ($tmpdt) $tmpdt = date_create($tmpdt->format('Y-m-d'));

		if ($tmpdt && ( ($tmpdt->format("d.m.Y") == '01.01.1970') || ($tmpdt->format("d.m.Y") == '01.01.0001') ) ) $tmpdt = null;

		$v['date'] = $tmpdt ? $tmpdt->format('Y-m-d H:i') : null;

		if ((int)$v['extid'] && (int)$v['dirty']) {
			$updated = array();
			foreach(array(
				'date',
				'comment',
				'address',
				'contacts'
			) AS $k) {
				if ($v[$k] != $origGrid[ (int)$v['extid'] ][$k]) {
					$updated[$k] = $v[$k];
				}
			}
			if (sizeof($updated)) $this->saveEvents[ $type ]['update'][ (int)$v['extid'] ] = $updated;
		}
		elseif (!(int)$v['extid']) {
			$this->saveEvents[ $type ]['insert'][] = $v;
		}

		return $tmpdt;
	}

	public function prepareSave() {
		$this->old = new transportation3Object($this->app);
		$this->old->loadById($this->id);

		$this->block('multimodal');
		$this->block('multimodal_id');
		$this->block('multimodal_num');
		
		if (!$this->id) {
			$this->allow("id");
			$this->allow("manager");
			$this->allow("date");
			$this->allow("clientnds");
		}

		foreach(array(
			'clientnds',
			'cargoinsuranceusvalue',
			'cargoinsuranceclientvalue',
			'clientothercharges',
			'client_currency_total',
			'ferryothercharges',
			'ferry_currency_total',
			'ferrynds'
		) AS $a) {
			if ($this->allowed($a)) {
				$this->priceChanged = true;
				break;
			}
		}

        $this->allow("profit");

        if (!empty($this->data['loadGridDeleted'])) foreach($this->data['loadGridDeleted'] AS $v) {
			if ((int)$v['id']) $this->saveEvents['load']['delete'][] = (int)$v['id'];
		}
		if (!empty($this->data['unloadGridDeleted'])) foreach($this->data['unloadGridDeleted'] AS $v) {
			if ((int)$v['id']) $this->saveEvents['unload']['delete'][] = (int)$v['id'];
		}

		$dates = array();
        $date = null;
		$origGrid = array();
		if (!empty($this->data['loadGridOrig'])) foreach($this->data['loadGridOrig'] AS $v) $origGrid[ (int)$v['id'] ] = $v;
		if (!empty($this->data['loadGrid'])) foreach($this->data['loadGrid'] AS $v) {
			$date = $this->loadUnloadPrepareSaveProc('load', $v, $origGrid);
			if ($date) $dates[] = $date;
		}

        if (!empty($dates)) {
            $date = min($dates);
        }

		if ($date && ( ($date->format("d.m.Y") != '01.01.1970') || ($date->format("d.m.Y") != '01.01.0001') ) ) $date = $date->format("d.m.Y H:i");
		else $date = '';
		if ($this->old->load != $date) {
			$this->allow('load');
			$this->load = $date;
		}

		$dates = array();
        $date = null;
		$origGrid = array();
		if (!empty($this->data['unloadGridOrig'])) foreach($this->data['unloadGridOrig'] AS $v) $origGrid[ (int)$v['id'] ] = $v;
		if (!empty($this->data['unloadGrid'])) foreach($this->data['unloadGrid'] AS $v) {
			$date = $this->loadUnloadPrepareSaveProc('unload', $v, $origGrid);
			if ($date) $dates[] = $date;
		}
        if (!empty($dates)) {
            $date = max($dates);
        }

		if ($date && ( ($date->format("d.m.Y") != '01.01.1970') || ($date->format("d.m.Y") != '01.01.0001') ) ) $date = $date->format("d.m.Y H:i");
		else $date = '';
		if ($this->old->offload != $date) {
			$this->allow('offload');
			$this->offload = $date;
		}
	}

	public function save() {
		$userId = $this->app->userId;

		$result = array();
		$id = $this->id;
		$log = '';

		try {
			$this->db->StartTransaction();

			if ($id) {
				$is_new_record=false;
				$log = sprintf("Редактирование грузоперевозки %s", $id);

				$query = new uQuery("transportation");
				$query->SetWhere( sprintf("id = '%s'", (int)$id) );
			}
			else {
				$is_new_record=true;
				$this->db->Query("SELECT NEXTVAL('transportation_id_seq')");
				$seq = $this->db->FetchRow();
				$id = (int)$seq[0];

				$query = new iQuery("transportation");
				$this->id = $id;
				$this->manager = $userId;
				$this->date = date("d.m.Y H:i:s");

				if (!$this->multimodal_id) {
					$this->multimodal_id = $id;
					$query->Param('multimodal_id',	$id);
				}
				else {
					$query->Param('multimodal',		1);
					$query->Param('multimodal_id',	$this->multimodal_id);
				}
				$query->Param('multimodal_num',	sprintf('coalesce((select max(multimodal_num) from transportation where multimodal_id=%s), 0)+1', $this->multimodal_id), 'RAW');

				$this->db->Query(sprintf("SELECT login from manager where id='%s'", $userId));
				$tmp2 = $this->db->FetchRow();
				$log = sprintf("Создание грузоперевозки %s", $id);
				$log .= sprintf("\r\nменеджер '%s'", $tmp2[0]);
			}

			///////////////////////////
			if (!empty($this->saveEvents['load']['delete'])) foreach($this->saveEvents['load']['delete'] AS $v)
				$this->db->ExecQuery(sprintf("delete from load where id='%s'", $v));
			if (!empty($this->saveEvents['load']['insert'])) foreach($this->saveEvents['load']['insert'] AS $v) {
				$tquery = new iQuery("load");
				$tquery->Param("tid",			$id);
				if (strlen($v['date'])) $tquery->Param("date", $this->db->Escape($v['date']), "DATE", 'YYYY-MM-DD HH24:MI');
				$tquery->Param("comment",		$this->db->Escape($v['comment']));
				$tquery->Param("address",		$this->db->Escape($v['address']));
				$tquery->Param("contacts",		$this->db->Escape($v['contacts']));
				$this->db->Exec($tquery);
			}
			if (!empty($this->saveEvents['load']['update'])) foreach($this->saveEvents['load']['update'] AS $vId => $v) {
				$tquery = new uQuery("load");
				$tquery->SetWhere( sprintf("id = '%s'", $vId) );

				if (!empty($v)) foreach($v AS $vFld => $vValue) {
					if ($vFld == 'date') {
						if (strlen($v['date'])) $tquery->Param("date", $this->db->Escape($v['date']), "DATE", 'YYYY-MM-DD HH24:MI');
						else $tquery->Param("date", "NULL", "RAW");
					}
					else
						$tquery->Param($vFld, $this->db->Escape($vValue));

				}

				if (sizeof($tquery->Params)) $this->db->Exec($tquery);
			}
			///////////////////////////
			if (!empty($this->saveEvents['unload']['delete'])) foreach($this->saveEvents['unload']['delete'] AS $v)
				$this->db->ExecQuery(sprintf("delete from offload where id='%s'", $v));
			if (!empty($this->saveEvents['unload']['insert'])) foreach($this->saveEvents['unload']['insert'] AS $v) {
				$tquery = new iQuery("offload");
				$tquery->Param("tid",			$id);
				if (strlen($v['date'])) $tquery->Param("date", $this->db->Escape($v['date']), "DATE", 'YYYY-MM-DD HH24:MI');
				$tquery->Param("comment",		$this->db->Escape($v['comment']));
				$tquery->Param("address",		$this->db->Escape($v['address']));
				$tquery->Param("contacts",		$this->db->Escape($v['contacts']));
				$this->db->Exec($tquery);
			}
			if (!empty($this->saveEvents['unload']['update'])) foreach($this->saveEvents['unload']['update'] AS $vId => $v) {
				$tquery = new uQuery("offload");
				$tquery->SetWhere( sprintf("id = '%s'", $vId) );

				if (!empty($v)) foreach($v AS $vFld => $vValue) {
					if ($vFld == 'date') {
						if (strlen($v['date'])) $tquery->Param("date", $this->db->Escape($v['date']), "DATE", 'YYYY-MM-DD HH24:MI');
						else $tquery->Param("date", "NULL", "RAW");
					}
					else
						$tquery->Param($vFld, $this->db->Escape($vValue));

				}

				if (!empty($tquery->Params)) $this->db->Exec($tquery);
			}
			///////////////////////////
			if ($this->allowedCount > 0) {
				foreach($this->model AS $k => &$v) {
					if($is_new_record && $k == 'client_request_no' && $v['value']==''){
						$v['value']=$this->multimodal_id;
						$query->Param($k,	$this->$k);
					}
					if ($v['allowed']) {
						if ($v['type'] == 'date')
							$query->Param($k,	$this->$k, "DATE");
						elseif ($v['type'] == 'datetime')
							$query->Param($k,	$this->$k, "DATE", "DD.MM.YYYY HH24:MI:SS");
						elseif ($v['type'] == 'datehm')
							$query->Param($k,	$this->$k, "DATE", "DD.MM.YYYY HH24:MI");
						else
							$query->Param($k,	$this->$k);

						$log .= sprintf("\r\n%s '%s' => '%s'", $v['caption'], $this->old->$k, $this->$k);
					}
				}

				if (!empty($query->Params)) $this->db->Exec($query);

				$tquery = new iQuery("log");
				$tquery->Param("mid",			$userId);
				$tquery->Param("tablename",		"transportation");
				$tquery->Param("tableitemid",	$id);
				$tquery->Param("date",			'now()',	"RAW");
				$tquery->Param("log",			$this->db->Escape($log));
				$this->db->Exec($tquery);

				if ( $this->allowed('transp_status') ) {
					new event($this->app, array(
						'obj' => 'transportation', //объект
						'objid' => $id,
						'event' => 'statusChange',
						'owner' => 'user', //инициатор - юзер / клиент / перевоз / амо
						'ownerid' => (int)$userId, // инициатор
						'oldvalue' => $this->old->transp_status, // старое свойство
						'value' => $this->transp_status // новое свойство
					));
				}
			}

			$result['success'] = true;
			$this->db->Commit();

            $this->app->updateTransportationPaymentBalance($id, null);
            $profitCalculateService = new profitCalculateService($this->db);
            $profitCalculateService->calcTranspFactProfit($id);
		}
		catch (Exception $e) {
			$this->db->RollBack();

			$result['success'] = false;
			$result['msg'] = "Ошибка sql! Создано исключение";
		}
		return $result;
	}

	public function allowAll() {
		$this->allowedCount = 0;
		foreach($this->model AS &$v) {
			$v['allowed'] = true;
			$this->allowedCount++;
		}
	}

	public function allow($name) {
		if ( ($this->model[$name]) && (!$this->model[$name]['allowed']) ) {
			$this->model[$name]['allowed'] = true;
			$this->allowedCount++;
		}
	}

	public function allowed($name) {
		if ( ($this->model[$name]) && ($this->model[$name]['allowed']) )
			return true;
		return false;
	}

	public function blockAll($readOnly = false) {
		$this->allowedCount = 0;
		foreach($this->model AS &$v) {
			$v['allowed'] = false;
			$v['readOnly'] = $readOnly;
		}
	}

	public function blockAllReadOnly() {
		$this->blockAll($readOnly = true);
	}

	public function block($name, $readOnly = false) {
		if ( ($this->model[$name]) && ($this->model[$name]['allowed']) ) {
			$this->model[$name]['allowed'] = false;
			$this->allowedCount--;
		}
	}

	public function blockReadOnly($name) {
		$this->block($name, $readOnly = true);
	}
}

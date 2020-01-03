<?php
/**
* Interkassa payment class
* @author www.gateon.net E-mail: www@smartbyte.pro
* @package UMI CMS version 2.x (testing for 2.14 version)
* @version 1.1
*/
class interkassaPayment extends payment {

	const STATUS_SUCCESS = "success";
	const STATUS_FAIL  = "fail";
	const STATUS_PROCESS  = "process";
	const STATUS_PENDING = "waitAccept";

	/**
	* Function validate amount order
	* In this case we make off this function
	* @param NULL
	* @return boolean
	*/
	public function validate() { return true; }

	/**
	* Function create payment form and create order
	* @param $template
	* @return NULL
	*/
	public function process($template = null) {

		//initialize order and first params
		$this->order->order();
		$currency = strtoupper( mainConfiguration::getInstance()->get('system', 'default-currency') );
		$amount = number_format($this->order->getActualPrice(), 2, '.', '');
		$param = array();

		//fix currency RUB
		if($currency == 'RUR'){
			$currency = 'RUB';
		}
		
		//===================================
		$currency = 'UAH';
		//===================================


		//initialize global params for interkassa form
		$param["api_mode"] = $this->object->api_mode;
		$param["ik_co_id"] = $this->object->merchant_id;
		$param["ik_pm_no"] = $this->order->id;
		$param["ik_am"] = $amount;
		$param["ik_cur"] = $currency;
		$param["ik_desc"] = '#'.$this->order->id;

		//detect web protocol
		$httpScheme = 'http://';
		if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) {
			$httpScheme = 'https://';
		}

		//Interaction Url
		$param['ik_suc_u'] = $httpScheme . $_SERVER['SERVER_NAME'] .'/emarket/purchase/result/successful/';
		$param['ik_fal_u'] = $httpScheme . $_SERVER['SERVER_NAME'] .'/emarket/purchase/result/fail/';
		$param['ik_pnd_u'] = $httpScheme . $_SERVER['SERVER_NAME'] .'/emarket/purchase/result/successful/';
		$param['ik_ia_u'] = $httpScheme . $_SERVER['SERVER_NAME'] . "/emarket/gateway/" . $this->order->getId() . "/";
		if ($this->object->test_mode) {
			$param["ik_pw_via"] ='test_interkassa_test_xts';
		}
		if ($param["api_mode"]) {
			$param["ik_act"] ='payways';
			$param["ik_int"] ='json';
			$param["api_id"] = $this->object->api_id;
			$param["api_key"] = $this->object->api_key;
			$param["payments_systems"] =$this->getPaymentsAPI($param);
			$param['paymentId'] =  $this->order->getValue('payment_id');
		}
        $param["ik_sign"] = $this->createSign($param,$this->object->secret_key);
		//add order into system
		$this->order->setPaymentStatus('initialized');
		$data = $param;
 
		
		        list($form_block) =
            def_module::loadTemplates('emarket/payment/interkassa/' . $template, 'form_block');

        return def_module::parseTemplate($form_block, $param);
	}

	/**
	* Function validate response from Interkassa
	* @param NULL
	* @return NULL
	*/
	public function poll() { //validate request from interkassa
		$this->wrlog("====================START=========================");
		$this->wrlog("Payment for order: ".getRequest("ik_pm_no")." Time: ".date("Y-m-d H:i:s"));
		$buffer = outputBuffer::current();
		$buffer->clear();
		$buffer->contentType("text/plain");
		$this->wrlog($_REQUEST);
		if (!$this->checkIP()) {
			$this->order->setOrderStatus('canceled');
			$buffer->push("failed");
			$this->wrlog("Invalid IP reply address");
		}
		elseif($this->hash_validation()) {
			$status = getRequest("ik_inv_st");
			switch($status) {
				case interkassaPayment::STATUS_FAIL : {	//fail order
					$this->order->setPaymentStatus('declined');
					$this->order->setOrderStatus('canceled');
					$buffer->push("failed");
					break;
				}
				case interkassaPayment::STATUS_SUCCESS  : { //success order, create payment
					$this->order->setPaymentStatus('accepted');
					$this->order->payment_document_num = getRequest('ik_inv_id');
					$buffer->push("OK");
					break;
				}
				case interkassaPayment::STATUS_PENDING  : { //pedning payment, wait for payment
					$this->order->setPaymentStatus('initialized');
					$this->order->payment_document_num = getRequest('ik_inv_id');
					$buffer->push("waitAccept");
					break;
				}
				default: {
					$this->order->setPaymentStatus('initialized');
					$this->order->payment_document_num = getRequest('ik_inv_id');
					$buffer->push("waitAccept");
					break;
				}

			}
		} else {
			$buffer->push("failed");
		}
		$this->wrlog("=====================END=========================");
		$buffer->end();
	}

	/**
	* Function validation hash with change hash methods
	* @param NULL
	* @return boolean
	*/
	private function hash_validation() { //check hash

		$testMode = getRequest('ik_pw_via');

		if(isset($testMode) && $testMode == 'test_interkassa_test_xts'){
			$secretKey = $this->object->test_key;
		} else {
			$secretKey = $this->object->secret_key;
		}

		$data = array();

		foreach ($_REQUEST as $key => $value) {
            if (!preg_match('/ik_/', $key)) continue;
            $data[$key] = $value;
        }

		$ik_sign = $data['ik_sign'];
		$sign = $this->createSign($data,$secretKey);

		$this->wrlog("hash: ".$sign);
		$this->wrlog("ik_sign: ".$ik_sign);
		return $sign == $ik_sign ? true : false;
	}

	private function createSign($data, $secret_key) {
		if (!empty($data['ik_sign'])) unset($data['ik_sign']);

		$dataSet = array();
		foreach ($data as $key => $value) {
			if (!preg_match('/ik_/', $key)) continue;
			$dataSet[$key] = $value;
		}

		ksort($dataSet, SORT_STRING);
		array_push($dataSet, $secret_key);
		$signString = implode(':', $dataSet);
		$sign = base64_encode(md5($signString, true));
		return $sign;
	}

	/**
	* Function writen log messages, optional function
	* @param mixed $content
	* @return NULL || (boolean) false
	*/
	private function wrlog($content){
		$path = $_SERVER['DOCUMENT_ROOT'];
		$file = fopen($path."/errors/logs/log.txt", "a");
		if($file && $path){
			if(is_array($content)){
				foreach ($content as $line => $value) {
					if(is_array($value)){
						$this->wrlog($value);
					} else {
						fwrite($file, $line . "=>" . $value . "\r\n");
					}
				}
			} elseif(is_object($content)) {
				foreach (get_object_vars($content) as $obj_line => $value) {
					fwrite($file, $obj_line . "=>" . $value . "\r\n");
				}
			} else {
				fwrite($file, $content."\r\n");
			}
			fclose($file);
		} else {
			return false;
		}
	}

	private function checkIP()
	{
		$ip_stack = array(
			'ip_begin' => '151.80.190.97',
			'ip_end' => '35.233.69.55'
		);
		return (ip2long($_SERVER['REMOTE_ADDR'])>=ip2long($ip_stack['ip_begin']) &&
				ip2long($_SERVER['REMOTE_ADDR'])<=ip2long($ip_stack['ip_end']));
	}
	public function getPaymentsAPI($data) {
	    $payment_systems = array();
		if ($data['api_mode']) {
			$host = "https://api.interkassa.com/v1/paysystem-input-payway?checkoutId=" . $data['ik_co_id'];
			$username = $data['api_id'];
			$password = $data['api_key'];
			$businessAcc = $this->getIkBusinessAcc($username, $password);
			
			$ikHeaders = [];
            $ikHeaders[] = "Authorization: Basic " . base64_encode("$username:$password");
            if (!empty($businessAcc)) {
                $ikHeaders[] = "Ik-Api-Account-Id: " . $businessAcc;
            }
			
			
			$ch = curl_init($host);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $ikHeaders);
			$response = curl_exec($ch);
			if(isset($response) && $response) {
				$returnInter = json_decode($response);
				if ($returnInter->status == "ok" && $returnInter->code == 0) {
					$payways = $returnInter->data;
				}
			}
			if (!$payways) {
				$this->wrlog("Payment systems in the answer of the Intercass are not found.");
			}
			else {
				foreach ($payways as $ps => $info) {
					$payment_system = $info->ser;

					if (!array_key_exists($payment_system, $payment_systems)) {
						$payment_systems[$payment_system] = array();
						foreach ($info->name as $name) {
							if ($name->l == 'en') {
								$payment_systems[$payment_system]['title'] = ucfirst($name->v);
							}
							$payment_systems[$payment_system]['name'][$name->l] = $name->v;
						}
					}
					$payment_systems[$payment_system]['currency'][strtoupper($info->curAls)] = $info->als;
				}
			}
		}
		return $payment_systems;
	
	}
	    
	    
	    
	    
	    /*
	     $username = $data['api_id'];
        $password = $data['api_key'];
        $remote_url = 'https://api.interkassa.com/v1/paysystem-input-payway?checkoutId='.$ik_co_id;
        
        $businessAcc = $this->getIkBusinessAcc($username, $password);
   
            
        $ikHeaders = [];
        $ikHeaders[] = "Authorization: Basic " . base64_encode("$username:$password");
        if (!empty($businessAcc)) {
            $ikHeaders[] = "Ik-Api-Account-Id: " . $businessAcc;
        }
                
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $ikHeaders);
        $response = curl_exec($ch);
        $json_data = json_decode($response);
        
        if (empty($json_data))
            echo'<strong style="color:red;">Error!!! System response empty!</strong>';

            if ($json_data->status != 'error') {
                $payment_systems = array();
                if (!empty($json_data->data)) {
                    
                    foreach ($json_data->data as $ps => $info) {
                        $payment_system = $info->ser;
                        if (!array_key_exists($payment_system, $payment_systems)) {
                            $payment_systems[$payment_system] = array();
                            foreach ($info->name as $name) {
                                if ($name->l == 'en') {
                                    $payment_systems[$payment_system]['title'] = ucfirst($name->v);
                                }
                                $payment_systems[$payment_system]['name'][$name->l] = $name->v;
                            }
                        }
                        $payment_systems[$payment_system]['currency'][strtoupper($info->curAls)] = $info->als;
                    }
                }
                    
                return !empty($payment_systems) ? $payment_systems : '<strong style="color:red;">API connection error or system response empty!</strong>';
            } else {
                if (!empty($json_data->message))
                    echo '<strong style="color:red;">API connection error!<br>' . $json_data->message . '</strong>';
                else
                    echo '<strong style="color:red;">API connection error or system response empty!</strong>';
            }
            return $payment_systems;
        }*/
	/*
		 */
	  public function getIkBusinessAcc($username = '', $password = '')         {
            $tmpLocationFile = __DIR__ . '/tmpLocalStorageBusinessAcc.ini';
            $dataBusinessAcc = function_exists('file_get_contents') ? file_get_contents($tmpLocationFile) : '{}';
            $dataBusinessAcc = json_decode($dataBusinessAcc, 1);
            $businessAcc = is_string($dataBusinessAcc['businessAcc']) ? trim($dataBusinessAcc['businessAcc']) : '';
            if (empty($businessAcc) || sha1($username . $password) !== $dataBusinessAcc['hash']) {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.interkassa.com/v1/' . 'account');
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($curl, CURLOPT_HEADER, false);
                curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Basic " . base64_encode("$username:$password")]);
                $response = curl_exec($curl);
                $response = json_decode($response,1);


                if (!empty($response['data'])) {
                    foreach ($response['data'] as $id => $data) {
                        if ($data['tp'] == 'b') {
                            $businessAcc = $id;
                            break;
                        }
                    }
                }

                if (function_exists('file_put_contents')) {
                    $updData = [
                        'businessAcc' => $businessAcc,
                        'hash' => sha1($username . $password)
                    ];
                    file_put_contents($tmpLocationFile, json_encode($updData, JSON_PRETTY_PRINT));
                }

                return $businessAcc;
            }

            return $businessAcc;
    }

};
?>

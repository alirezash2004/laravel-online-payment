<?php
namespace Tartan\Larapay\Adapter;

use SoapClient;
use SoapFault;
use Tartan\Larapay\Adapter\Zarinpal\Exception;
use Illuminate\Support\Facades\Log;

class Zarinpal extends AdapterAbstract implements AdapterInterface
{
	protected $WSDL = 'https://www.zarinpal.com/pg/services/WebGate/wsdl';

	protected $endPoint = 'https://www.zarinpal.com/pg/StartPay/{authority}';
	protected $zarinEndPoint = 'https://www.zarinpal.com/pg/StartPay/{authority}/ZarinGate';
	protected $mobileEndPoint = 'https://www.zarinpal.com/pg/StartPay/{authority}/MobileGate';


	protected $testWSDL = 'https://sandbox.zarinpal.com/pg/services/WebGate/wsdl';
	protected $testEndPoint = 'https://sandbox.zarinpal.com/pg/StartPay/{authority}';

//	protected $testWSDL = 'https://banktest.ir/gateway/zarinpal/ws?wsdl';
//	protected $testEndPoint = 'https://banktest.ir/gateway/zarinpal/gate/{authority}';

	public $reverseSupport = false;

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function requestToken ()
	{
		if ($this->getTransaction()->checkForRequestToken() == false) {
			throw new Exception('larapay::larapay.could_not_request_payment');
		}

		$this->checkRequiredParameters([
			'merchant_id',
			'amount',
//			'description',
//			'email',
//			'mobile',
			'redirect_url',
		]);

		$sendParams = [
			'MerchantID'  => $this->merchant_id,
			'Amount'      => $this->getToman(),
			'Description' => $this->description ? $this->description : '',
			'Email'       => $this->email ? $this->email : '',
			'Mobile'      => $this->mobile ? $this->mobile : '',
			'CallbackURL' => $this->redirect_url,
		];

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('PaymentRequest call', $sendParams);

			$response = $soapClient->PaymentRequest($sendParams);

			Log::info('PaymentRequest response', $this->obj2array($response));


			if (isset($response->Status)) {

				if ($response->Status == 100) {
					$this->getTransaction()->setReferenceId($response->Authority); // update transaction reference id
					return $response->Authority;
				}
				else {
					throw new Exception($response->Status);
				}
			}
			else {
				throw new Exception('larapay::larapay.invalid_response');
			}
		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}


	/**
	 * @return mixed
	 */
	protected function generateForm ()
	{
		$authority = $this->requestToken();

		return view('larapay::zarinpal-form', [
			'endPoint'    => strtr($this->getEndPoint(), ['{authority}' => $authority]),
			'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("larapay::larapay.goto_gate"),
			'autoSubmit'  => boolval($this->auto_submit)
		]);
	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	protected function verifyTransaction ()
	{
		if($this->getTransaction()->checkForVerify() == false) {
			throw new Exception('larapay::larapay.could_not_verify_payment');
		}

		$this->checkRequiredParameters([
			'merchant_id',
			'amount',
			'Authority'
		]);

		$sendParams = [
			'MerchantID'  => $this->merchant_id,
			'Authority'   => $this->Authority,
			'Amount'      => $this->getToman(),
		];

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('PaymentVerification call', $sendParams);

			$response   = $soapClient->PaymentVerification($sendParams);

			Log::info('PaymentVerification response', $this->obj2array($response));


			if (isset($response->Status, $response->RefID)) {

				if($response->Status == 100) {
					$this->getTransaction()->setVerified();
					$this->getTransaction()->setReferenceId($response->RefID); // update transaction reference id
					return true;
				} else {
					throw new Exception($response->Status);
				}
			} else {
				throw new Exception('larapay::larapay.invalid_response');
			}

		} catch (SoapFault $e) {

			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	/**
	 * @return bool
	 */
	public function canContinueWithCallbackParameters()
	{
		if ($this->Status == "OK") {
			return true;
		}
		return false;
	}

	public function getGatewayReferenceId()
	{
		$this->checkRequiredParameters([
			'Authority',
		]);
		return $this->Authority;
	}

	private function getToman()
	{
		return (int) ($this->amount / 10);
	}
}

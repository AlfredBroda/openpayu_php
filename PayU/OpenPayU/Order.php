<?php

/*
	OpenPayU Standard Library

	@copyright  Copyright (c) 2011-2012 PayU
	@license    http://opensource.org/licenses/LGPL-3.0  Open Software License (LGPL 3.0)
	http://www.payu.com
	http://openpayu.com
	http://twitter.com/openpayu
*/

namespace PayU\OpenPayU;

use PayU\OpenPayU;

class Order extends OpenPayU
{

    /**
     * Function sending Order to PayU Service
     * @access public
     * @param array $order
     * @param bool $debug
     * @return object $result
     */
    public static function create($order, $debug = TRUE)
    {

        // preparing payu service for order initialization
        $OrderCreateRequestUrl = Configuration::getServiceUrl() . 'co/openpayu/OrderCreateRequest';

        if ($debug)
            self::addOutputConsole('OpenPayU endpoint for OrderCreateRequest message', $OrderCreateRequestUrl);

        self::setOpenPayuEndPoint($OrderCreateRequestUrl);

        // convert array to openpayu document
        $xml = self::buildOrderCreateRequest($order);

        if ($debug)
            self::addOutputConsole('OrderCreateRequest message', htmlentities($xml));

        $merchantPosId = Configuration::getMerchantPosId();
        $signatureKey = Configuration::getSignatureKey();

        // send openpayu document with order initialization structure to PayU service
        $response = self::sendOpenPayuDocumentAuth($xml, $merchantPosId, $signatureKey);

        if ($debug)
            self::addOutputConsole('OrderCreateRequest message', htmlentities($response));

        // verify response from PayU service
        $status = self::verifyOrderCreateResponse($response);

        if ($debug)
            self::addOutputConsole('OrderCreateResponse status', serialize($status));

        $result = new Result();
        $result->setStatus($status);
        $result->setError($status['StatusCode']);

        if (isset($status['StatusDesc']))
            $result->setMessage($status['StatusDesc']);

        $result->setSuccess($status['StatusCode'] == 'OPENPAYU_SUCCESS' ? TRUE : FALSE);
        $result->setRequest($order);
        $result->setResponse(self::parseOpenPayUDocument($response));

        return $result;
    }

    /**
     * Function retrieving Order data from PayU Service
     * @access public
     * @param string $sessionId
     * @param bool $debug
     * @return Result $result
     */
    public static function retrieve($sessionId, $debug = TRUE)
    {
        $req = array(
            'ReqId' => md5(rand()),
            'MerchantPosId' => Configuration::getMerchantPosId(),
            'SessionId' => $sessionId
        );

        $OrderRetrieveRequestUrl = Configuration::getServiceUrl() . 'co/openpayu/OrderRetrieveRequest';

        if ($debug)
            self::addOutputConsole('OpenPayU endpoint for OrderRetrieveRequest message', $OrderRetrieveRequestUrl);

        $oauthResult = OAuth::accessTokenByClientCredentials();

        self::setOpenPayuEndPoint($OrderRetrieveRequestUrl . '?oauth_token=' . $oauthResult->getAccessToken());
        $xml = self::buildOrderRetrieveRequest($req);

        if ($debug)
            self::addOutputConsole('OrderRetrieveRequest message', htmlentities($xml));

        $merchantPosId = Configuration::getMerchantPosId();
        $signatureKey = Configuration::getSignatureKey();
        $response = self::sendOpenPayuDocumentAuth($xml, $merchantPosId, $signatureKey);

        if ($debug)
            self::addOutputConsole('OrderRetrieveResponse message', htmlentities($response));

        $status = self::verifyOrderRetrieveResponseStatus($response);

        if ($debug)
            self::addOutputConsole('OrderRetrieveResponse status', serialize($status));

        $result = new Result();
        $result->setStatus($status);
        $result->setError($status['StatusCode']);

        if (isset($status['StatusDesc']))
            $result->setMessage($status['StatusDesc']);

        $result->setSuccess($status['StatusCode'] == 'OPENPAYU_SUCCESS' ? TRUE : FALSE);
        $result->setRequest($req);
        $result->setResponse($response);

        try {
            $assoc = self::parseOpenPayUDocument($response);
            $result->setResponse($assoc);
        } catch (Exception $ex) {
            if ($debug)
                self::addOutputConsole('OrderRetrieveResponse parse result exception', $ex->getMessage());
        }

        return $result;
    }

    /**
     * Function consume message
     * @access public
     * @param string $xml
     * @param boolean $response Show Response Xml
     * @param bool $debug
     * @return object $result
     */
    public static function consumeMessage($xml, $response = TRUE, $debug = TRUE)
    {
        $xml = stripslashes(urldecode($xml));
        $rq = self::parseOpenPayUDocument($xml);

        $msg = $rq['OpenPayU']['OrderDomainRequest'];

        switch (key($msg)) {
            case 'OrderNotifyRequest':
                return self::consumeNotification($xml, $response, $debug);
                break;
            case 'ShippingCostRetrieveRequest':
                return self::consumeShippingCostRetrieveRequest($xml, $debug);
                break;
            default:
                return key($msg);
                break;
        }
    }

    /**
     * Function consume notification message
     * @access private
     * @param string $xml
     * @param boolean $response Show Response Xml
     * @param bool $debug
     * @return Result $result
     */
    private static function consumeNotification($xml, $response = TRUE, $debug = TRUE)
    {
        if ($debug)
            self::addOutputConsole('OrderNotifyRequest message', $xml);

        $xml = stripslashes(urldecode($xml));
        $rq = self::parseOpenPayUDocument($xml);
        $reqId = $rq['OpenPayU']['OrderDomainRequest']['OrderNotifyRequest']['ReqId'];
        $sessionId = $rq['OpenPayU']['OrderDomainRequest']['OrderNotifyRequest']['SessionId'];

        if ($debug)
            self::addOutputConsole('OrderNotifyRequest data, reqId', $reqId . ', sessionId: ' . $sessionId);


        // response to payu service
        $rsp = self::buildOrderNotifyResponse($reqId);
        if ($debug)
            self::addOutputConsole('OrderNotifyResponse message', $rsp);

        // show response
        if ($response == TRUE) {
            header("Content-Type:text/xml");
            echo $rsp;
        }

        // create OpenPayU Result object
        $result = new Result();
        $result->setSessionId($sessionId);
        $result->setSuccess(TRUE);
        $result->setRequest($rq);
        $result->setResponse($rsp);
        $result->setMessage('OrderNotifyRequest');

        // if everything is alright return full data sent from payu service to client
        return $result;
    }

    /**
     * Function consume shipping cost calculation request message
     * @access private
     * @param string $xml
     * @param bool $debug
     * @return Result $result
     */
    private static function consumeShippingCostRetrieveRequest($xml, $debug = TRUE)
    {
        if ($debug)
            self::addOutputConsole('consumeShippingCostRetrieveRequest message', $xml);

        $rq = self::parseOpenPayUDocument($xml);

        $result = new Result();
        $result->setCountryCode($rq['OpenPayU']['OrderDomainRequest']['ShippingCostRetrieveRequest']['CountryCode']);
        $result->setSessionId($rq['OpenPayU']['OrderDomainRequest']['ShippingCostRetrieveRequest']['SessionId']);
        $result->setReqId($rq['OpenPayU']['OrderDomainRequest']['ShippingCostRetrieveRequest']['ReqId']);
        $result->setMessage('ShippingCostRetrieveRequest');

        if ($debug)
            self::addOutputConsole('consumeShippingCostRetrieveRequest reqId', $result->getReqId() . ', countryCode: ' . $result->getCountryCode());

        return $result;
    }

    /**
     * Function use to cancel
     * @access public
     * @param string $sessionId
     * @param bool $debug
     * @return Result $result
     */
    public static function cancel($sessionId, $debug = TRUE)
    {

        $rq = array(
            'ReqId' => md5(rand()),
            'MerchantPosId' => Configuration::getMerchantPosId(),
            'SessionId' => $sessionId
        );

        $result = new Result();
        $result->setRequest($rq);

        $url = Configuration::getServiceUrl() . 'co/openpayu/OrderCancelRequest';

        if ($debug)
            self::addOutputConsole('OpenPayU endpoint for OrderCancelRequest message', $url);

        $oauthResult = OAuth::accessTokenByClientCredentials();
        self::setOpenPayuEndPoint($url . '?oauth_token=' . $oauthResult->getAccessToken());

        $xml = self::buildOrderCancelRequest($rq);

        if ($debug)
            self::addOutputConsole('OrderCancelRequest message', htmlentities($xml));

        $merchantPosId = Configuration::getMerchantPosId();
        $signatureKey = Configuration::getSignatureKey();
        $response = self::sendOpenPayuDocumentAuth($xml, $merchantPosId, $signatureKey);

        if ($debug)
            self::addOutputConsole('OrderCancelResponse message', htmlentities($response));

        // verify response from PayU service
        $status = self::verifyOrderCancelResponseStatus($response);

        if ($debug)
            self::addOutputConsole('OrderCancelResponse status', serialize($status));

        $result->setStatus($status);
        $result->setError($status['StatusCode']);

        if (isset($status['StatusDesc']))
            $result->setMessage($status['StatusDesc']);

        $result->setSuccess($status['StatusCode'] == 'OPENPAYU_SUCCESS' ? TRUE : FALSE);
        $result->setResponse(self::parseOpenPayUDocument($response));

        return $result;
    }

    /**
     * Function use to update status
     * @access public
     * @param string $sessionId
     * @param string $status
     * @param bool $debug
     * @return Result $result
     */
    public static function updateStatus($sessionId, $status, $debug = TRUE)
    {

        $rq = array(
            'ReqId' => md5(rand()),
            'MerchantPosId' => Configuration::getMerchantPosId(),
            'SessionId' => $sessionId,
            'OrderStatus' => $status,
            'Timestamp' => date('c')
        );

        $result = new Result();
        $result->setRequest($rq);

        $url = Configuration::getServiceUrl() . 'co/openpayu/OrderStatusUpdateRequest';

        if ($debug)
            self::addOutputConsole('OpenPayU endpoint for OrderStatusUpdateRequest message', $url);

        $oauthResult = OAuth::accessTokenByClientCredentials();
        self::setOpenPayuEndPoint($url . '?oauth_token=' . $oauthResult->getAccessToken());

        $xml = self::buildOrderStatusUpdateRequest($rq);

        if ($debug)
            self::addOutputConsole('OrderStatusUpdateRequest message', htmlentities($xml));

        $merchantPosId = Configuration::getMerchantPosId();
        $signatureKey = Configuration::getSignatureKey();
        $response = self::sendOpenPayuDocumentAuth($xml, $merchantPosId, $signatureKey);

        if ($debug)
            self::addOutputConsole('OrderStatusUpdateResponse message', htmlentities($response));

        // verify response from PayU service
        $status = self::verifyOrderStatusUpdateResponseStatus($response);

        if ($debug)
            self::addOutputConsole('OrderStatusUpdateResponse status', serialize($status));

        $result->setStatus($status);
        $result->setError($status['StatusCode']);

        if (isset($status['StatusDesc']))
            $result->setMessage($status['StatusDesc']);

        $result->setSuccess($status['StatusCode'] == 'OPENPAYU_SUCCESS' ? TRUE : FALSE);
        $result->setResponse(self::parseOpenPayUDocument($response));

        return $result;
    }
}
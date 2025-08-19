<?php

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * It is available through the world-wide-web at this URL:
 * https://tldrlegal.com/license/mit-license
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to support@buckaroo.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact support@buckaroo.nl for more information.
 *
 * @copyright Copyright (c) Buckaroo B.V.
 * @license   https://tldrlegal.com/license/mit-license
 */

namespace Buckaroo\Magento2\Model\Validator;

use Buckaroo\Magento2\Helper\Data;
use Buckaroo\Magento2\Logging\Log;
use Buckaroo\Magento2\Model\ConfigProvider\Account;
use \Buckaroo\Magento2\Model\ValidatorInterface;
use \Magento\Framework\Encryption\Encryptor;

class Push implements ValidatorInterface
{
    /** @var Account $configProviderAccount */
    public $configProviderAccount;

    /** @var Data $helper */
    public $helper;

    /** @var Log $logging */
    public $logging;

    /** @var Encryptor $encryptor */
    private $encryptor;

    public $bpeResponseMessages = [
        190 => 'Success',
        490 => 'Payment failure',
        491 => 'Validation error',
        492 => 'Technical error',
        690 => 'Payment rejected',
        790 => 'Waiting for user input',
        791 => 'Waiting for processor',
        792 => 'Waiting on consumer action',
        793 => 'Payment on hold',
        890 => 'Cancelled by consumer',
        891 => 'Cancelled by merchant'
    ];

    /**
     * @param Data          $helper
     * @param Account       $configProviderAccount
     * @param Log           $logging
     * @param Encryptor     $encryptor
     */
    public function __construct(
        Data $helper,
        Account $configProviderAccount,
        Log $logging,
        Encryptor $encryptor
    ) {
        $this->helper                   = $helper;
        $this->configProviderAccount    = $configProviderAccount;
        $this->logging                  = $logging;
        $this->encryptor                = $encryptor;
    }

    /**
     * @param $data
     *
     * @return bool
     */
    public function validate($data)
    {
        return true;
    }

    /**
     * Checks if the status code is returned by the bpe push and is valid.
     *
     * @param $code
     *
     * @return array
     */
    public function validateStatusCode($code)
    {
        if (null !== $this->helper->getStatusByValue($code)
            && isset($this->bpeResponseMessages[$code])
        ) {
            return [
                'message' => $this->bpeResponseMessages[$code],
                'status'  => $this->helper->getStatusByValue($code),
                'code'    => $code,
            ];
        } else {
            return [
                'message' => 'Onbekende responsecode: ' . $code,
                'status'  => 'BUCKAROO_MAGENTO2_STATUSCODE_NEUTRAL',
                'code'    => $code,
            ];
        }
    }

    /**
     * CORE FIX: Build complete response from push data for group transactions
     * This ensures we have all required fields for proper group transaction saving
     *
     * @param array $postData Push notification data
     * @param \Magento\Sales\Model\Order $order Order object for currency info
     * @return array Complete response format compatible with API responses
     */
    public function buildCompleteResponseFromPushData($postData, $order)
    {
        // Start with basic status validation
        $statusCode = $postData['brq_statuscode'] ?? null;
        $baseResponse = $this->validateStatusCode($statusCode);
        
        // Only build complete response for successful transactions
        if ($statusCode != 190) {
            return $baseResponse;
        }
        
        // Build complete response with all required fields for group transactions
        $completeResponse = $baseResponse;
        
        // Map push data fields to API response format
        if (!empty($postData['brq_invoicenumber'])) {
            $completeResponse['Invoice'] = $postData['brq_invoicenumber'];
        }
        
        if (!empty($postData['brq_transactions'])) {
            $completeResponse['Key'] = $postData['brq_transactions'];
        }
        
        if (!empty($postData['brq_transaction_method'])) {
            $completeResponse['ServiceCode'] = $postData['brq_transaction_method'];
        }
        
        if (!empty($postData['brq_amount'])) {
            $completeResponse['AmountDebit'] = $postData['brq_amount'];
        }
        
        // Add currency from order
        if ($order && $order->getOrderCurrencyCode()) {
            $completeResponse['Currency'] = $order->getOrderCurrencyCode();
        }
        
        // Add proper status structure to match API response format
        $completeResponse['Status'] = [
            'Code' => [
                'Code' => $statusCode
            ]
        ];
        
        // Add group transaction info if available
        if (!empty($postData['brq_relatedtransaction_partialpayment'])) {
            $completeResponse['RequiredAction'] = [
                'PayRemainderDetails' => [
                    'GroupTransaction' => $postData['brq_relatedtransaction_partialpayment']
                ]
            ];
        }
        
        return $completeResponse;
    }

    /**
     * Generate/calculate the signature with the buckaroo config value and check if thats equal to the signature
     * received from the push
     *
     * @param $postData
     *
     * @return bool
     */
    public function validateSignature($originalPostData, $postData, $store = null)
    {
        if (!isset($postData['brq_signature'])) {
            return false;
        }

        $signature = $this->calculateSignature($originalPostData, $store);

        if ($signature !== $postData['brq_signature']) {
            return false;
        }

        return true;
    }

    /**
     * Determines the signature using array sorting and the SHA1 hash algorithm
     *
     * @param $postData
     *
     * @return string
     */
    public function calculateSignature($postData, $store = null)
    {
        ksort($postData, SORT_FLAG_CASE | SORT_STRING);

        $data = array_filter($postData, function ($key) {
            $acceptable_top_level = ['brq', 'add', 'cust', 'BRQ', 'ADD', 'CUST'];

            return (
                    $key != 'brq_signature' && $key != 'BRQ_SIGNATURE') &&
                in_array(explode('_', $key)[0], $acceptable_top_level);
        }, ARRAY_FILTER_USE_KEY);

        $data = array_map(function ($value, $key) {
            return $key . '=' . html_entity_decode($value);
        }, $data, array_keys($data));


        $digitalSignature = $this->encryptor->decrypt($this->configProviderAccount->getSecretKey($store));
        $signatureString = implode('', $data) . trim($digitalSignature);

        $signature = SHA1($signatureString);

        return $signature;
    }
}

<?php

namespace FunkyTime;

use Exception, SoapClient, SoapVar;

/**
 * Connector for Yuki's Sales SOAP Webservice (subset), mainly intended to create Sales Invoices.
 * Also includes a few Accounting methods; see the code.
 *
 * by FunkyTime.com
 *
 * Magic methods passed to SOAP call:
 * General:
 * @method array Authenticate(array $params)
 * @method array Administrations(array $params)
 *
 * Sales:
 * @method array ProcessSalesInvoices(array $params)
 *
 * Accounting:
 * @method array CheckOutstandingItem(array $params)
 * @method array NetRevenue(array $params)
 * @method array GLAccountBalance(array $params)
 * @method array GLAccountTransactions(array $params)
 *
 * AccountingInfo:
 * @method array GetGLAccountScheme(array $params)
 */
class Yuki
{
    const SALES_WSDL = 'https://api.yukiworks.nl/ws/Sales.asmx?WSDL';
    const ACCOUNTING_WSDL = 'https://api.yukiworks.nl/ws/Accounting.asmx?WSDL';
    const ACCOUNTINGINFO_WSDL = 'https://api.yukiworks.nl/ws/AccountingInfo.asmx?WSDL';

    private $soap, // the SOAP client
        // the currently active identifiers for the logged in Yuki user:
        $sid, // SessionID
        $aid = null; // AdministrationID


    /**
     * Wrapper for ProcessSalesInvoices.
     * Will throw a ResponseException if it did not succeed (e.g. duplicate invoice number).
     *
     * Format the parameter like this:
     * $invoice = [
     *   'Reference' => '',
     *   ...
     *   'Contact' => [
     *     'ContactCode' => '',
     *     ...
     *   ]
     *   'ContactPerson' => ['FullName' => ''],
     *   'InvoiceLines' => [
     *     'InvoiceLine' => [
     *       'ProductQuantity' => '',
     *       'LineVATAmount' => '',
     *       'Product' => [
     *          'Description' => '',
     *          ...
     *        ]
     *     ]
     *   ]
     * ];
     * See http://www.yukiworks.nl/schemas/SalesInvoices.xsd, but note that only a subset is supported here (see code)
     *
     * @param array $invoice Invoice data in associative array, with matching Yuki keys
     * @param bool $escaped Todo: Whether the data is already trimmed and escaped for inclusion in XML tags
     * @return mixed
     * @throws ResponseException
     */
    public function ProcessInvoice($invoice, $escaped = false) {
        if (!$escaped) {
            array_walk_recursive($invoice, function(&$_v) {
                $_v = htmlspecialchars(trim($_v), ENT_XML1);
            });
        }

        // General fields
        $SalesInvoice = '';
        foreach (['Reference', 'Subject', 'PaymentMethod', 'Date', 'DueDate', 'Currency', 'ProjectCode', 'Remarks'] as $k) {
            if (!empty($invoice[$k])) {
                $SalesInvoice .= "<$k>$invoice[$k]</$k>";
            }
            if ($k === 'PaymentMethod') {
                // invoice is marked as fully prepared
                $SalesInvoice .= '<Process>true</Process>';
            }
        }

        // Client (company)
        $Contact = '';
        foreach (['ContactCode', 'FullName', 'CountryCode', 'City', 'Zipcode', 'AddressLine_1', 'AddressLine_2', 'EmailAddress', 'CoCNumber', 'VATNumber', 'ContactType'] as $k) {
            if (!empty($invoice['Contact'][$k])) {
                $Contact .= "<$k>{$invoice['Contact'][$k]}</$k>";
            }
        }
        $SalesInvoice .= "<Contact>$Contact</Contact>";

        if (!empty($invoice['ContactPerson']) && !empty($invoice['ContactPerson']['FullName'])) {
            $SalesInvoice .= "<ContactPerson><FullName>{$invoice['ContactPerson']['FullName']}</FullName></ContactPerson>";
        }

        // Invoice lines
        if (!empty($invoice['InvoiceLines'])) {
            $InvoiceLines = [];
            foreach ($invoice['InvoiceLines'] as $InvoiceLine) {
                $Line = '';
                foreach (['ProductQuantity', 'LineAmount', 'LineVATAmount'] as $k) {
                    if (isset($InvoiceLine[$k]) && !is_null($InvoiceLine[$k])) {
                        $Line .= "<$k>{$InvoiceLine[$k]}</$k>";
                    }
                }
                $Product = '';
                if (empty($InvoiceLine['Product']['Description'])) {
                    $InvoiceLine['Product']['Description'] = ' '; // minimum String length for Description
                }
                foreach(['Description', 'SalesPrice', 'VATPercentage', 'VATType', 'GLAccountCode', 'Remarks'] as $k) {
                    if (isset($InvoiceLine['Product'][$k]) && !is_null($InvoiceLine['Product'][$k])) {
                        $Product .= "<$k>{$InvoiceLine['Product'][$k]}</$k>";
                    }
                }
                $InvoiceLines[] = "<InvoiceLine>$Line<Product>$Product</Product></InvoiceLine>";
            }
            $SalesInvoice .= '<InvoiceLines>'. implode($InvoiceLines) .'</InvoiceLines>';
        }

        // Generate XML doc
        $xmlvar = new SoapVar(
            '<ns1:xmlDoc><SalesInvoices xmlns="urn:xmlns:http://www.theyukicompany.com:salesinvoices" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><SalesInvoice>'
                . $SalesInvoice
                . '</SalesInvoice></SalesInvoices></ns1:xmlDoc>',
            XSD_ANYXML
        );

        $return = $this->ProcessSalesInvoices(['sessionId' => $this->sid, 'administrationId' => $this->aid, 'xmlDoc' => $xmlvar]);

        // Check the result to see whether it was succesful
        $result_xml = simplexml_load_string(current($return->ProcessSalesInvoicesResult));
        if (!$result_xml->TotalSucceeded->__toString()) {
            // None succeeded, so throw the error message
            throw new ResponseException($result_xml->Invoice->Message, $result_xml->asXML());
        }
        return true; // success
    }


    public function GetInvoiceBalance($invoiceReference){
        $yuki_invoice = $this->CheckOutstandingItem(['sessionID' => $this->sid, 'Reference' => $invoiceReference]);
        $xml = simplexml_load_string($yuki_invoice->CheckOutstandingItemResult->any);

        return [
            "openAmount" => floatval($xml->Item->OpenAmount),
            "originalAmount" => floatval($xml->Item->OriginalAmount)
        ];
    }

    /**
     * Also called from constructor, if constructed with api key
     * @param string $apiKey Yuki API key
     * @throws Exception If api key is not set
     */
    public function login($apiKey) {
        if (!$apiKey) {
            throw new Exception('Yuki API key not set. Please check your company\'s settings. You can find or create a Yuki API key (of type Administration) under Settings > Webservices in Yuki.');
        }

        $this->sid = $this->sid($apiKey);
        $this->aid = $this->aid();
    }

    /**
     * Get the AdministrationID
     * @return string AdministrationID
     * @throws Exception
     */
    private function aid() {
        // could maybe be saved to DB the first time, but an API key might later get attached to a different Administration...
        $result = $this->Administrations(['sessionID' => $this->sid]);
        // Save and return the result
        try {
            $xml = simplexml_load_string(current($result->AdministrationsResult));
            return $xml->Administration->attributes()['ID'];
        } catch (Exception $e) {
            throw new Exception('Yuki authentication failed. The API key works, but it does not seem to have access to any Administration.');
        }
    }

    /**
     * Get a sessionID
     * @param string $api_key Yuki accessKey
     * @return string sessionID
     * @throws Exception
     */
    private function sid($api_key) {
        $result = $this->Authenticate(['accessKey' => $api_key]);

        // Save and return the result
        if ($result && !empty($result->AuthenticateResult)) {
            return $result->AuthenticateResult;
        } else {
            throw new Exception('Authentication failed. Please check your company\'s Yuki accessKey.');
        }
    }

    /**
     * Get the currently loaded AdministrationID
     * (small g because it's a class function rather than a web call)
     * @return null|string
     */
    public function getAdministrationID() {
        return $this->aid;
    }


    /**
     * Generic call method (it just passes it on to the SoapClient)
     * @param string $method
     * @param array $params
     * @return object Response
     * @throws Exception
     */
    public function __call($method, $params) {
        try {
            $result = $this->soap->__soapCall($method, $params);
            return $result;
        } catch (Exception $e) {
            // rethrow with a little more information in Message
            throw new Exception("$method failed: " . @$e->faultcode . ' ' . $e->getMessage() . '.');
        }
    }

    /**
     * Yuki constructor (creates the SOAP client).
     *
     * @param null|string $apikey If provided, will immediately connect
     * @param null|string $wsdl 'sales' or 'accounting' (if null then 'sales')
     * @throws Exception If the SOAP client could not be instantiated, or the login failed
     */
    public function __construct($apikey = null, $wsdl = null) {
        $this->soap = new SoapClient($this->getWSDL($wsdl), ['trace' => true]);
        if ($apikey) {
            $this->login($apikey);
        }
    }

    /***
     * @param string $wsdl name of the corresponding WSDL
     * @return string URL of the corresponding WSDL
     */
    private function getWSDL($wsdl){
        switch ($wsdl){
            case 'sales':
                return self::SALES_WSDL;
                break;
            case 'accounting':
                return self::ACCOUNTING_WSDL;
                break;
            case 'accountinginfo':
                return self::ACCOUNTINGINFO_WSDL;
                break;
            default:
                return self::SALES_WSDL;
                break;
        }
    }

    /**
     * @param $start
     * @param $end
     * @return array
     * @throws Exception
     */
    public function GetAdministrationNetRevenue($start, $end){
        try {
            return $this->NetRevenue(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'StartDate' => $start, 'EndDate' => $end]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve Net Revenue for administration ' . $this->aid . ' from ' . $start . ' to ' . $end);
        }
    }

    /**
     * @param $date
     * @return array
     * @throws Exception
     */
    public function GetAccountBalance($date){
        try {
            return $this->GLAccountBalance(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'transactionDate' => $date]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve AccountBalance for administration ' . $this->aid . ' on date: ' . $date);
        }
    }

    /**
     * @return array
     * @throws Exception
     */
    public function GetAccountCodes(){
        try {
            return $this->GetGLAccountScheme(['sessionID' => $this->sid, 'administrationID' => $this->aid]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve AccountCodes for administration ' . $this->aid . ' and session: ' . $this->sid);
        }
    }

    /**
     * @param $accountCode
     * @param $start
     * @param $end
     * @return array
     * @throws Exception
     */
    public function GetTransactions($accountCode, $start, $end){
        try {
            return $this->GLAccountTransactions(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'GLAccountCode' => $accountCode, 'StartDate' => $start, 'EndDate' => $end]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve Transactions for administration ' . $this->aid . ' and accountcode: ' . $accountCode . ' from ' . $start . ' to ' . $end);
        }
    }

    /**
     * @param $accountCode
     * @param $start
     * @param $end
     * @return array
     * @throws Exception
     */
    public function GetDetails($accountCode, $start, $end)
    {
        try {
            return $this->GetTransactionDetails(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'GLAccountCode' => $accountCode, 'StartDate' => $start, 'EndDate' => $end, 'financialMode' => 1]);
        } catch (ResponseException $e) {
            throw new Exception('Could not retrieve Transactions for administration ' . $this->aid . ' and accountcode: ' . $accountCode . ' from ' . $start . ' to ' . $end);
        }
    }
}

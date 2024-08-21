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
 * @method object Authenticate(array $params)
 * @method object Administrations(array $params)
 *
 * Sales:
 * @method object ProcessSalesInvoices(array $params)
 *
 * Accounting:
 * @method object CheckOutstandingItem(array $params)
 * @method object NetRevenue(array $params)
 * @method object GLAccountBalance(array $params)
 * @method object GLAccountTransactions(array $params)
 *
 * AccountingInfo:
 * @method object GetGLAccountScheme(array $params)
 */
class Yuki
{
    private const SALES_WSDL = 'https://api.yukiworks.nl/ws/Sales.asmx?WSDL';
    private const ACCOUNTING_WSDL = 'https://api.yukiworks.nl/ws/Accounting.asmx?WSDL';
    private const ACCOUNTINGINFO_WSDL = 'https://api.yukiworks.nl/ws/AccountingInfo.asmx?WSDL';

    private SoapClient $soap; // the SOAP client

    // The currently active identifiers for the logged in Yuki user:
    // SessionID
    private string $sid;
    // AdministrationID
    private ?string $aid = null;


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
     * @param array $invoice Invoice data in associative array, with matching Yuki keys.
     * @param bool $escaped Todo: Whether the data is already trimmed and escaped for inclusion in XML tags.
     * @return bool
     * @throws ResponseException
     */
    public function ProcessInvoice(array $invoice, bool $escaped = false): bool {
        if (!$escaped) {
            array_walk_recursive($invoice, function(&$_v) {
                $_v = htmlspecialchars(trim($_v), ENT_XML1);
            });
        }

        // General fields.
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

        // Client (company).
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

        // Invoice lines.
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

        // Generate XML doc.
        $xmlDoc = new SoapVar(
            '<ns1:xmlDoc><SalesInvoices xmlns="urn:xmlns:http://www.theyukicompany.com:salesinvoices" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><SalesInvoice>'
                . $SalesInvoice
                . '</SalesInvoice></SalesInvoices></ns1:xmlDoc>',
            XSD_ANYXML
        );

        $return = $this->ProcessSalesInvoices(['sessionId' => $this->sid, 'administrationId' => $this->aid, 'xmlDoc' => $xmlDoc]);

        // Check the result to see whether it was successful.
        $result_xml = simplexml_load_string(current($return->ProcessSalesInvoicesResult));
        if (!$result_xml->TotalSucceeded->__toString()) {
            // None succeeded, so throw the error message.
            throw new ResponseException($result_xml->Invoice->Message, $result_xml->asXML());
        }
        return true; // success
    }


    public function GetInvoiceBalance($invoiceReference): array {
        $yuki_invoice = $this->CheckOutstandingItem(['sessionID' => $this->sid, 'Reference' => $invoiceReference]);
        $xml = simplexml_load_string($yuki_invoice->CheckOutstandingItemResult->any);

        return [
            "openAmount" => floatval($xml->Item->OpenAmount),
            "originalAmount" => floatval($xml->Item->OriginalAmount)
        ];
    }

    /**
     * Also called from constructor, if constructed with api key.
     * @param string $apiKey Yuki API key.
     * @throws Exception If api key is not set.
     */
    public function login(string $apiKey): void {
        if (!$apiKey) {
            throw new Exception('Yuki API key not set. Please check your company\'s settings. You can find or create a Yuki API key (of type Administration) under Settings > Webservices in Yuki.');
        }

        $this->sid = $this->sid($apiKey);
        $this->aid = $this->aid();
    }

    /**
     * Get the AdministrationID.
     * @return string AdministrationID
     * @throws Exception
     */
    private function aid(): string {
        // could maybe be saved to DB the first time, but an API key might later get attached to a different Administration...
        $result = $this->Administrations(['sessionID' => $this->sid]);
        // Save and return the result
        try {
            $result = $result->AdministrationsResult;
            if (!is_array($result)) {
                throw new Exception();
            }
            $xml = simplexml_load_string($result[0]);
            if (!$xml) {
                throw new Exception();
            }
            return $xml->Administration->attributes()['ID'];
        } catch (Exception $e) {
            throw new Exception('Yuki authentication failed. The API key works, but it does not seem to have access to any Administration.');
        }
    }

    /**
     * Get a sessionID.
     * @param string $api_key Yuki accessKey
     * @return string sessionID
     * @throws Exception
     */
    private function sid(string $api_key): string {
        $result = $this->Authenticate(['accessKey' => $api_key]);

        // Save and return the result
        if ($result && !empty($result->AuthenticateResult)) {
            return $result->AuthenticateResult;
        } else {
            throw new Exception('Authentication failed. Please check your company\'s Yuki accessKey.');
        }
    }

    /**
     * Get the currently loaded AdministrationID.
     * (small g because it's a class function rather than a web call)
     * @return ?string
     */
    public function getAdministrationID(): ?string {
        return $this->aid;
    }


    /**
     * Generic call method (it just passes it on to the SoapClient).
     * @param string $method
     * @param array $params
     * @return mixed Response
     * @throws Exception
     */
    public function __call(string $method, array $params) {
        try {
            return $this->soap->__soapCall($method, $params);
        } catch (Exception $e) {
            // Rethrow with a little more information in Message.
            throw new Exception("$method failed: " . @$e->faultcode . ' ' . $e->getMessage() . '.');
        }
    }

    /**
     * Yuki constructor (creates the SOAP client).
     *
     * @param ?string $apikey If provided, will immediately connect.
     * @param string $wsdl 'sales' or 'accounting'.
     * @throws Exception If the SOAP client could not be instantiated, or the login failed.
     */
    public function __construct(string $apikey = null, string $wsdl = 'sales') {
        $this->soap = new SoapClient($this->getWSDL($wsdl), ['trace' => true]);
        if ($apikey) {
            $this->login($apikey);
        }
    }

    /***
     * @param string $wsdl name of the corresponding WSDL.
     * @return string URL of the corresponding WSDL.
     */
    private function getWSDL(string $wsdl): string {
        switch ($wsdl) {
            case 'accounting':
                return self::ACCOUNTING_WSDL;
            case 'accountinginfo':
                return self::ACCOUNTINGINFO_WSDL;
            case 'sales':
            default:
                return self::SALES_WSDL;
        }
    }

    /**
     * @param string $start Start date.
     * @param string $end End date.
     * @return object
     * @throws Exception
     */
    public function GetAdministrationNetRevenue(string $start, string $end): object {
        try {
            return $this->NetRevenue(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'StartDate' => $start, 'EndDate' => $end]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve Net Revenue for administration ' . $this->aid . ' from ' . $start . ' to ' . $end);
        }
    }

    /**
     * @param string $date
     * @return object
     * @throws Exception
     */
    public function GetAccountBalance(string $date): object {
        try {
            return $this->GLAccountBalance(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'transactionDate' => $date]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve AccountBalance for administration ' . $this->aid . ' on date: ' . $date);
        }
    }

    /**
     * @return object
     * @throws Exception
     */
    public function GetAccountCodes(): object {
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
     * @return object
     * @throws Exception
     */
    public function GetTransactions($accountCode, $start, $end): object {
        try {
            return $this->GLAccountTransactions(['sessionID' => $this->sid, 'administrationID' => $this->aid, 'GLAccountCode' => $accountCode, 'StartDate' => $start, 'EndDate' => $end]);
        } catch (Exception $e) {
            throw new Exception('Could not retrieve Transactions for administration ' . $this->aid . ' and accountcode: ' . $accountCode . ' from ' . $start . ' to ' . $end);
        }
    }
}

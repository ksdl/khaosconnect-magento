<?php

/**
Copyright 2015 Keystone Software Development Ltd.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
 **/


class Ksdl_Khaosconnect_Helper_Webservice extends Ksdl_Khaosconnect_Helper_Basehelper
{   
    private function Connect()
    {
        $WebserviceUrl = Mage::helper('khaosconnect/systemvalues')->getSysValue('web_service_url');
        return new SoapClient($WebserviceUrl, array('cache_wsdl' => WSDL_CACHE_NONE));
    }
       
    private function getSoapDateTime($lastSyncTime)
    {
        return $this->getDateTime($lastSyncTime, "Europe/London");
    }
    
    private function unzipString($zippedString)
    {
        $result = '';
        $tmpDir = tempnam(Mage::getBaseDir('tmp'), md5(uniqid(microtime(true))));
        if ($tmpDir)
        {
            file_put_contents($tmpDir, $zippedString);
            chmod($tmpDir, 0777); //So we can remove the file afterwards.
            $zip = new ZipArchive;
            if ($zip->open($tmpDir) === true) 
                $result = (string)$zip->getFromIndex(0);
            unset($zip); //Release the file pending removal
            unlink($tmpDir);
        }
        return $result;
    }
    
    public function GetWebCategories($WebsiteName)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('ExportWebCategoriesCompressed', array(
            'Website' => $WebsiteName, 
            'CompressionMethod' => '0'
        ));
        $unzippedResult = $this->unzipString($result->Data);
        $xmlObj = simplexml_load_string($unzippedResult);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function getStockList($lastSyncTime)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('GetStockList', array(
            'DateType' => '-1',
            'DateValue' => $this->getSoapDateTime($lastSyncTime), 
            'StockItemType' => '0'
        ));
        return ($result != '') ? $result : false;
    }
    
    public function getCustomerList($lastSyncTime)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('GetCompanyList', array(
            'LastUpdated' => $this->getSoapDateTime($lastSyncTime)
        ));
        return ($result != '') ? $result : false;
    }
    
    public function exportStock($stockCodeArray)
    {
        $stockCodes = $this->arrayToCSV($stockCodeArray);
        
        $client = $this->Connect();
        $result = $client->__soapCall('ExportStockCompressed', array(
            'StockCode' => $stockCodes, 
            'MappingType' => '1', 
            'LastUpdated' => $this->getSoapDateTime(false), 
            'CompressionMethod' => '0'
        ));
        $unzippedResult = $this->unzipString($result->Data);
        $xmlObj = simplexml_load_string($unzippedResult);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function importOrder($xmlStr)
    {
        $client = $this->Connect();
        return $client->__soapCall('ImportOrders', array('Orders' => $xmlStr));
    }
    
    public function importCustomer($xmlStr)
    {
        $client = $this->Connect();
        return $client->__soapCall('ImportCompany', array('Customers' => $xmlStr, 'Method' => '0'));
    }
    
    public function exportCustomers($companyCodes, $mappingType)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('ExportCompany', array(
            'CompanyCode' => $companyCodes, 
            'MappingType' => $mappingType
        ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function getCompanyClasses()
    {
        $client = $this->Connect();
        return $client->__soapCall('GetCompanyClass', array('CompanyClassType' => '0'));
    }
    
    public function exportPriceLists($groups, $type)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('ExportPriceListEx', array(
            'PriceLists' => $groups, 
            'PriceListType' => $type
        ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function getHarmonisationCodes()
    {
        $client = $this->Connect();
        return $client->__soapCall('GetHarmonisationCodes', array('' => ''));
    }
    
    public function getSpecialOffers()
    {
        $client = $this->Connect();
        $result = $client->__soapCall('GetSOPData', array(
            'Courier' => '0',
            'DelRate' => '0',
            'CourierBanding' => '0',
            'BOGOF' => '0',
            'SpecialOffer' => '1',
            'TelesalePrompt' => '0',
            'KeyCode' => '0',
            'BarredItems' => '0'
            ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function getSites()
    {
        $client = $this->Connect();
        return $client->__soapCall('GetSiteList', array('' => ''));
    }
    
    public function getKeycodes()
    {
        $client = $this->Connect();
        $result = $client->__soapCall('GetSOPData', array(
            'Courier' => '0',
            'DelRate' => '0',
            'CourierBanding' => '0',
            'BOGOF' => '0',
            'SpecialOffer' => '0',
            'TelesalePrompt' => '0',
            'KeyCode' => '1',
            'BarredItems' => '0'
            ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function exportStockStatus($stockCodes, $siteId)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('ExportStockStatus', array(
            'StockCodes' => $this->arrayToCSV($stockCodes), 
            'MappingType' => '1',
            'LastUpdated' => $this->getSoapDateTime(false),
            'SiteID' => $siteId
        ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
    
    public function exportOrderStatus($associatedRefs)
    {
        $client = $this->Connect();
        $result = $client->__soapCall('ExportOrderStatusEx', array(
            'IDs' => $this->arrayToCSV($associatedRefs), 
            'MappingType' => '4'
            /*'WebOnly' => '',
            'ConfirmedOnly' => '',
            'IncludeTracking' => '',
            'IncludeCancelled' => '', 
            'DateFrom' => $this->getSoapDateTime($lastSyncTime),
            'DateTo' => $this->getSoapDateTime(strtotime("+1 day", time())),
            'InvoiceStageIDs' => '9,10,11,12,13,14,15,16,17,18,19,20,21'*/
        ));
        $xmlObj = simplexml_load_string($result);
        return ($xmlObj !== false) ? $xmlObj : false;
    }
}
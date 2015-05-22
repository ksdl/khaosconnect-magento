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


class Ksdl_Khaosconnect_Helper_Basehelper extends Mage_Core_Helper_Abstract
{ 
    const cLogTypeOrder = 0;
    const cLogTypeStock = 1;
    const cLogTypeCustomer = 2;
    const cLogTypeGroupPriceList = 3;
    const cLogTypeKeycode = 4;
    const cLogStatusSuccess = 0;
    const cLogStatusFailed = 1;
    const cLogStatusUpdate = 2;
    
    const cLinkedUpSell = 1;
    const cLinkedCrossSell = 2;
    const cLinkedPack = 3;
    const cLinkedAssociated = 4;
    
    protected $resource;
    protected $readDB;
    protected $writeDB;
    protected $systemValues;
    protected $lockFileName = "";
    
    function __construct()
    {
        $this->resource = Mage::getSingleton('core/resource');
        $this->readDB = $this->resource->getConnection('core_read');
        $this->writeDB = $this->resource->getConnection('core_write');
        $this->systemValues = Mage::helper("khaosconnect/systemvalues");
    }
    
    public function validId($val)
    {
        return ((is_numeric($val)) && ($val > 0));
    }
    
    protected function dbLog($entityId, $type, $message, $incrementId, $status)
    {
        $log = Mage::getModel('khaosconnect/logs');
        $log->setMessage($message);
        $log->setIncrementId($incrementId);
        $log->setStatus($status);
        $log->setType($type);
        $log->setEntityId($entityId);
        $log->setTimestamp(time());
        $log->save();
    }
    
    protected function readXmlFieldI($field)
    {
        return (string)$field;
    }
    
    protected function arrayToCSV($array)
    {
        if ($this->validArray($array))
            return implode(',', $array);
        else
            return false;
    }
    
    protected function validArray($var)
    {
        return (is_array($var) && !empty($var));
    }
    
    protected function getPropS($xmlObj, $field)
    {
        if (isset($xmlObj) && isset($xmlObj->$field))
            return (string)$xmlObj->$field;
        else
            return '';
    }
    
    protected function getPropI($xmlObj, $field)
    {
        return (int)$this->getPropS($xmlObj, $field);
    }
    
    protected function getWebserviceDate()
    {
        date_default_timezone_set('Europe/London');
        return date('Y-m-d\TH:i:s');
    }
    
    protected function getDateTime($timestamp, $timezone)
    {
        if (empty($timestamp)) $timestamp = 0;
        if (is_numeric($timestamp))
        {
            date_default_timezone_set($timezone);
            $date = date('Y-m-d\TH:i:s', $timestamp);
            date_default_timezone_set(Mage_Core_Model_Locale::DEFAULT_TIMEZONE);
            return $date;
        }
        else
        {
            return '1899-12-31T00:00:00';    
        }
    }
    
    protected function setAction($action)
    {
        $_SESSION['GeneralFatalErrorAction'] = $action;
    }
    
    protected function setCode($code)
    {
        $_SESSION['GeneralFatalErrorCode'] = $code;
    }
    
    protected function getLockFilePath($uniqueId)
    {
        return Mage::getBaseDir('log') . '/syncing_' . $this->lockFileName . '_' . $uniqueId . '.txt';
    }
    
    protected function removeCodeFromLockFile($code, $uniqueId)
    {
        $filePath = $this->getLockFilePath($uniqueId);
        if (file_exists($filePath))
        {
            $contents = file_get_contents($filePath, true);
            $allCodes = explode(",", $contents);
            $this->setLockFile($allCodes, $uniqueId, $code);
        }
    }
    
    protected function clearLockFile($uniqueId)
    {
        $filePath = $this->getLockFilePath($uniqueId);
        unlink($filePath);    
    }
    
    protected function setLockFile($codes, $uniqueId, $exclude = "")
    {
        $filePath = $this->getLockFilePath($uniqueId);
        $codeStr = "";
        foreach ($codes as $code)
        {
            if (strtolower($code) != strtolower($exclude) && $code != "")
                $codeStr .= $code . ",";
        }
        file_put_contents($filePath, $codeStr);
    }
    
    protected function getCodesFromLockFile($uniqueId)
    {
        $codes = array();
        $filePath = $this->getLockFilePath($uniqueId);
        if (file_exists($filePath))
        {
            $contents = file_get_contents($filePath, true);
            $codes = explode(',', $contents);
        }
        return $codes;
    }
    
    protected function isLockFileEmpty($uniqueId)
    {
        $filePath = $this->getLockFilePath($uniqueId);
        if (file_exists($filePath))
        {
            $contents = file_get_contents($filePath, true);
            return trim($contents) == "";
        }
        else
            return true;
    }
}

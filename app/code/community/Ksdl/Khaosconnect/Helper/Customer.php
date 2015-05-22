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


class Ksdl_Khaosconnect_Helper_Customer extends Ksdl_Khaosconnect_Helper_Basehelper
{
    protected $websiteIds;
    protected $customersPerWebsite;
    protected $importedCodes = array();
    protected $skippedCodes = array();
    protected $processingWebsiteId;
    
    function __construct()
    {
        parent::__construct();
    }   
    
    protected function init($websiteIds)
    {
        $this->websiteIds = $websiteIds;
        $this->customersPerWebsite = Mage::getStoreConfig('customer/account_share/scope') == '1'; 
    }
    
    public function doCustomerImport($websiteIds)
    {
        $this->init($websiteIds);
        $this->setAction("Customer Import");
        $this->lockFileName = "company_codes";
        
        if ($this->validArray($this->websiteIds))
        {
            $this->doCustomerGroupImport();
            try
            {
                $customersXml = null;
                
                foreach ($websiteIds as $websiteId)
                {
                    if ($websiteId != "0")
                    {
                        $currencyCode = Mage::app()->getWebsite($websiteId)->getBaseCurrencyCode();
                                        
                        $lastSyncTime = $this->systemValues->getSysValue($this->systemValues->cuLastSyncPrefix . $websiteId);
                        $customerCodesToSync = array_filter($this->getCodesFromLockFile($websiteId));
                        $codesToSync = "";
                    
                        if (!$this->validArray($customerCodesToSync))
                        {
                            $customerList = Mage::helper("khaosconnect/webservice")->getCustomerList($lastSyncTime);
                            $customerCodesToSync = $this->getCustomerListData($customerList);
                            $this->setLockFile($customerCodesToSync, $websiteId);
                        }
                    
                        $this->processBatchImport($customerCodesToSync, $websiteId, $currencyCode);
                    
                        if (!$this->validArray($customerCodesToSync))
                            $this->logCustomer($this->importedCodes);
                    
                        $this->clearLockFile($websiteId);                        
                                     
                        if (!$this->customersPerWebsite) //Don't need to continue if storing customers globally.
                            break; 
                    }
                }
            }
            catch (Exception $e)
            {
                $this->logCustomer($e);
                throw $e;
            }
        }
    }
    
    public function processBatchImport($customerCodes, $websiteId, $currencyCode)
    {
        if ($this->validArray($customerCodes))
        {
            $processAtOnce = 200;
            $codeCount = count($customerCodes);
            $count = 0;
            $iterations = ($codeCount > 0 && $codeCount < $processAtOnce) 
                ? 1
                : round($codeCount / $processAtOnce, 0, PHP_ROUND_HALF_UP);
             
            
            $this->logCustomer("Starting Import. " . count($customerCodes) . " to import in " . $iterations . " batches.", "");
            
            for ($i = 0; $i < $iterations; $i++)
            {
                $batchLog = $i + 1;
                $this->logCustomer("Processing customer import batch " . $batchLog . "/" . $iterations, "");
                $processCustomerCodes = array_slice($customerCodes, $i * $processAtOnce, $processAtOnce);
                $this->doCustomerBatchImport($processCustomerCodes, $websiteId, $currencyCode);
                
                if (count($this->skippedCodes) > 0)
                {
                    $this->logCustomer("Skipped " . count($this->skippedCodes) . " records with no email address.", "");
                    $this->skippedCodes = array();
                }
                
                $this->logCustomer("Customer import batch " . $batchLog . "/" . $iterations . " complete.", "");
            }
        }
    }
    
    public function doCustomerBatchImport($customerCodes, $websiteId, $currencyCode)
    {
        if ($this->validArray($customerCodes))
        {
            $codesToSync = "";
            foreach ($customerCodes as $customerCode)
                $codesToSync .= $customerCode . ",";
            
            if ($codesToSync != '')
                $customersXml = Mage::helper("khaosconnect/webservice")->exportCustomers($codesToSync, 1);

            if ($customersXml != null)
            {
                $this->importCustomers($customersXml, $currencyCode, $websiteId);
            }
        }
    }

    protected function importCustomers($customersXml, $currencyCode, $websiteId)
    {
        foreach($customersXml->COMPANY as $customerXml)
        {
            //Don't import customers that don't have a username as they can't log in anyways.
            $userName = $this->getPropS($customerXml, 'WEB_USER');
            $companyCode = $this->getPropS($customerXml, 'COMPANY_CODE');
            
            if ($userName != "")
            {
                if ($this->getPropS($customerXml, 'CURRENCY_CODE') == $currencyCode)
                {
                    $this->writeDB->beginTransaction();
                    
                    $customerObj = Mage::getModel('customer/customer');
                    if ($this->customersPerWebsite)
                        $customerObj->setWebsiteId($websiteId);
                    $customerObj->loadByEmail($customerXml->WEB_USER);
                    try
                    {
                        $this->setCode($companyCode);
                        $this->importCustomerUDAs($customerXml);
                        $this->importCustomer($customerObj, $customerXml, $websiteId);
                        $this->writeDB->commit();
                    }
                    catch(Exception $ce)
                    {
                        $this->writeDB->rollback();
                        $this->logCustomer($ce, 'Import', $companyCode);
                    }
                }
            }
            else
            {
                $this->skippedCodes[] = $companyCode;
            }
            
            //Clear code regardless as it shouldn't be processed again even if something goes wrong.
            $this->removeCodeFromLockFile($companyCode, $websiteId);
        }
    }
    
    public function doCustomerExport($websiteIds)
    {
        $this->init($websiteIds);
        $this->setAction("Customer Export");
        
        //Create an attribute to store a failed flag for customers.
        $this->createOrUpdateCustomerUDA("ImportFailed", "boolean", "int");
        
        if ($this->validArray($this->websiteIds))
        {
            foreach ($websiteIds as $websiteId)
            {
                $this->processingWebsiteId = $websiteId;
                $currencyCode = Mage::app()->getWebsite($websiteId)->getBaseCurrencyCode();
                $lastSyncTime = $this->systemValues->getSysValue($this->systemValues->cuLastSyncPrefix . $websiteId);
                
                $customerObjs = Mage::getModel('customer/customer')->getCollection()
                    ->addFieldToFilter('website_id', $websiteId)
                    ->addFieldToFilter('updated_at', array('gteq' => date('Y-m-d H:i:s', $lastSyncTime)));
                
                try
                {
                    $customersXml = $this->exportCustomers($customerObjs, $currencyCode);
                    if (count($customersXml->COMPANY) > 0)
                    {
                        $customersXmlStr = $customersXml->asXML();
                        $customersResult = Mage::helper('khaosconnect/webservice')->importCustomer($customersXmlStr);
                        $this->updateCustomerCodes($customersResult, $websiteId);
                    
                        if ($this->validArray($customersResult->CustomerImport))
                            $this->logCustomer($customersResult->CustomerImport, 'Export');
                    }
                }
                catch (Exception $e)
                {
                    $this->logCustomer($e);
                    $this->markCustomersAsFailedImport($customerObjs);
                    throw $e;
                }
            }
        }
    }
    
    protected function markCustomersAsFailedImport($customerObjs)
    {
        $ids = "";
        $count = 0;
        foreach ($customerObjs as $customerObj)
        {
            $ids .= $customerObj->getId();
            if ($count < count($customerObjs))
                $ids . ", ";
            
            $customer = Mage::getModel('customer/customer')->load($customerObj->getId());
            $customer->setKcImportfailed("1");
            $customer->save();
            $count++;
        }
        
        $this->logCustomer("Marked " . count($customerObjs) . " customer(s) as failed import as a result of previous error. IDs [$ids]", "Failed");
    }
    
    protected function exportCustomers($customerObjs, $currencyCode)
    {
        $xmlCustomers = new SimpleXMLElement('<COMPANYS></COMPANYS>');
        foreach ($customerObjs as $customerObj)
        {
            $customer = Mage::getModel('customer/customer')->load($customerObj->getId());
            if ($customer->getData("kc_importfailed") != "1")
            {
                if (count($customerObj->getAddresses()) > 0)
                {
                    $this->setCode($customer->getKcCompanyCode());
                    $this->exportCustomer($customer, $currencyCode, $xmlCustomers);
                }
            }
        }
        return $xmlCustomers;
    }
    
    protected function exportCustomer($customerObj, $currencyCode, &$xmlCustomers)
    {
        $xmlCustomer = $xmlCustomers->addChild('COMPANY');
        $this->exportCustomerHeader($customerObj, $xmlCustomer, $currencyCode);
        $this->exportCustomerAddresses($customerObj, $xmlCustomer);
        
        return $xmlCustomers;
    }
    
    protected function exportCustomerAddresses($customerObj, &$xmlCustomer)
    {
        $addressesXml = $xmlCustomer->addChild('ADDRESSES');
        $defaultBillingAddressId = $customerObj->getDefaultBilling();
        
        foreach ($customerObj->getAddresses() as $addressObj)
        {
            $isBillingAddress = $addressObj->getId() == $defaultBillingAddressId;
            
            if ($addressObj->getCompany() != "" && $isBillingAddress)
                $xmlCustomer->COMPANY_NAME = $addressObj->getCompany();
            
            $addressXml = $addressesXml->addChild('ADDRESS');
            $this->exportCustomerAddress($addressObj, $addressXml, $customerObj, $isBillingAddress);
            $addressXml->ADDRTYPE = $isBillingAddress ? "1" : "2";
            if ($isBillingAddress)
                $addressXml->EMAIL = $customerObj->getEmail();
        }
    }
    
    protected function exportCustomerAddress($addressObj, &$addressXml, $customerObj, $isBillingAddress)
    {
        $addressLines = $addressObj->getStreet();
        
        //If the user has specified a company name against the delivery address 
        //then switch things around a little so that company name becomes address1 and so on.
        if (!$isBillingAddress && $addressObj->getCompany() != "")
        {
            $currentAddressLines = $addressLines;
            
            $addressLines[0] = $addressObj->getCompany();
            $addressLines[1] = $currentAddressLines[0];
            if (count($currentAddressLines) > 1)
                $addressLines[2] = $currentAddressLines[1];
        }
            
        $count = 1;
        foreach ($addressLines as $addressLine)
        {
            $addressXml->{'ADDR'.$count} = $addressLine;
            $count++;
        }
        
        $addressXml->TOWN = $addressObj->getCity();
        $postcode = ($addressObj->getPostcode() == "") ? "." : $addressObj->getPostcode();
        $addressXml->POSTCODE = $postcode;
        $addressXml->TEL = $addressObj->getTelephone();
        $addressXml->COUNTRY_CODE = $addressObj->getCountryId();
        
        $contactsXml = $addressXml->addChild('CONTACTS');
        $contactXml = $contactsXml->addChild('CONTACT');
        $contactXml->TITLE = $customerObj->getPrefix();
        $contactXml->FORENAME = $addressObj->getFirstname();
        $contactXml->SURNAME = $addressObj->getLastname();        
    }
    
    protected function exportCustomerHeader($customerObj, &$xmlCustomer, $currencyCode)
    {
        $xmlCustomer->COMPANY_CODE = $customerObj->getKcCompanyCode();
        if ($customerObj->getKcCompanyCode() != '') //Don't send if this is the initial import as it will try and match on this over the web_user.
            $xmlCustomer->OTHER_REF = $customerObj->getId();
        $xmlCustomer->WEB_SITE = $customerObj->getWebsiteId();
        $xmlCustomer->COMPANY_CLASS = Mage::getModel('customer/group')->load($customerObj->getGroupId(), 'customer_group_id')->getCustomerGroupCode();
        $xmlCustomer->DATE_CREATED = $customerObj->getCreatedAt();
        $xmlCustomer->WEB_USER = $customerObj->getEmail();
        $xmlCustomer->WEB_PASSWORD = $customerObj->getPasswordHash();
        $xmlCustomer->CURRENCY_CODE = $currencyCode;
    }
    
    protected function importPriceLists($groups)
    {
        $groupsXml = Mage::helper("khaosconnect/webservice")->exportPriceLists($this->arrayToCSV($groups), '3');
        $pricelistData = array();
        
        foreach ($groupsXml[0] as $groupXml) //Having to use [0] as SimpleXml doesn't like "StockItem-CustomerClassifications".
        {
            $this->getStockItemPriceListData($groupXml, $pricelistData);
        }
        
        foreach ($pricelistData as $productId => $priceListItemData)
            $this->importStockPriceListItem($productId, $priceListItemData);
    }
    
    protected function getStockItemPriceListData($groupXml, &$pricelistData)
    {
        $storePriceIncTax = Mage::getStoreConfig('tax/calculation/price_includes_tax') == "1";
        $pricelistIsNet = (string)$groupXml->attributes()->PricelistNet == "-1";
        $groupId = Mage::getModel('customer/group')->load($groupXml->attributes()->CompClass, 'customer_group_code')->getId();
        $productData = array();
        
        $productCollection = Mage::getModel('catalog/product')->getCollection()->addAttributeToSelect('id')->addAttributeToSelect('sku');
        foreach($productCollection as $product) 
            $productData[$product->getSku()] = $product->getId();
        
        foreach ($groupXml->StockItem as $stockPriceItem)
        {
            $taxRate = (float)$stockPriceItem->TaxRate / 100 + 1;                    
            $stockCode = (string)$stockPriceItem->StockCode;
            
            if (array_key_exists($stockCode, $productData))
            {
                $type = (string)$stockPriceItem->QtyStart == "1" ? "group" : "tier";
                $price = (float)$stockPriceItem->CalculatedPrice;
                
                //TODO: Read proper tax values.
                if ($pricelistIsNet && $storePriceIncTax)
                    $price = $price * $taxRate;
                else if (!$pricelistIsNet && !$storePriceIncTax)
                    $price = $price / $taxRate;
                
                $pricelistData[$productData[$stockCode]][$type][] = array(
                    'price' => $price,
                    'qtyStart' => (string)$stockPriceItem->QtyStart,
                    'groupId' => $groupId
                );
            }
        }
    }
    
    protected function importStockPriceListItem($productId, $priceListItemData)
    {
        $product = Mage::getModel('catalog/product')->load($productId);
        if ($product->getId())
        {
            $tierPrices = array();
            $groupPrices = array();
        
            foreach ($priceListItemData as $type => $dataArray)
            {
                foreach ($dataArray as $data)
                {                    
                    if ($type == "tier")
                    {
                        $tierPrices[] = array(
                            'website_id'  => '0',
                            'cust_group'  => $data['groupId'],
                            'price_qty'   => $data['qtyStart'],
                            'price'       => $data['price'],
                        );
                    }
                    else
                    {
                        $groupPrices[] = array(
                            'website_id'  => '0',
                            'cust_group'  => $data['groupId'],
                            'price'       => $data['price'],
                        );
                    }
                }
            }
        
            $updated = false;
            if ($this->validArray($tierPrices))
            {
                $product->setTierPrice($tierPrices);
                $updated = true;
            }
            
            if ($this->validArray($groupPrices))
            {
                $product->setGroupPrice($groupPrices);
                $updated = true;
            }
            
            if ($updated)
                $product->save();
        }
    }
    
    protected function importCustomerUDAs($customerXml)
    {
        foreach ($customerXml->ADDITIONAL as $uda)
        {
            $this->createOrUpdateCustomerUDA($this->getPropS($uda->attributes(), "NAME"), "text", "varchar");
        }
    }
    
    protected function createOrUpdateCustomerUDA($value, $input, $type)
    {
        $setup = new Mage_Eav_Model_Entity_Setup('core_setup');

        $entityTypeId = $setup->getEntityTypeId('customer');
        $attributeSetId = $setup->getDefaultAttributeSetId($entityTypeId);
        $attributeGroupId = $setup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
        $attributeCode = $this->getAttributeCode($value);     
        
        $setup->addAttribute('customer', $attributeCode, array(
            'input'         => $input,
            'type'          => $type,
            'label'         => $value,
            'visible'       => 1,
            'required'      => 0,
            'user_defined'  => 1,
        ));

        $setup->addAttributeToGroup(
            $entityTypeId,
            $attributeSetId,
            $attributeGroupId,
            $attributeCode,
            '999'  //sort_order
        );

        $attribute = Mage::getSingleton('eav/config')->getAttribute('customer', $attributeCode);
        $attribute->setData('used_in_forms', array('adminhtml_customer'));
        $attribute->save();
        
        unset($setup);
    }
    
    protected function getAttributeCode($str)
    {
        return "kc_" . strtolower(str_replace(" ", "", $str));    
    }
    
    protected function importCustomer($customerObj, $customerXml, $websiteId)
    {
        list($addressesArray, $companyFirstName, $companyLastName) = $this->getAddressArray($customerXml);        
        
        if ($this->validArray($addressesArray))
        {
            $customerObj->setEmail($customerXml->WEB_USER);
            $customerObj->setFirstname($companyFirstName);
            $customerObj->setLastname($companyLastName);
            
            $password = $customerXml->WEB_PASSWORD;
            //Already hashed MD5 + Salt Key (I assume). Might need tweaking a little to check. 
            //Note: Changed to >= as Enterprise is doing something with the passwords I don't know about so the length is large and it's unlikely a user will have a normal password >= 35 chars.
            if (strlen($customerXml->WEB_PASSWORD) < 35) 
                $password = $customerObj->hashPassword($password);
            
            $customerObj->setPasswordHash($password);            
            $customerObj->setGroupId($this->getGroupId($this->getPropS($customerXml, "COMPANY_CLASS")));
            $customerObj->setKcCompanyCode($this->getPropS($customerXml, "COMPANY_CODE"));
            if ($this->customersPerWebsite)
                $customerObj->setWebsiteId($websiteId);
            
            foreach ($customerXml->ADDITIONAL as $uda)
            {
                $customerObj->{"set" . $this->getAttributeCode($this->getPropS($uda->attributes(), "NAME"))}((string)$uda);
            }
            
            $customerObj->save();
            $customerObj->setConfirmation(null);
            $customerObj->save();
            
            $count = 0;
            foreach ($addressesArray as $addressArray)
            {
                $addressObj = $this->getAddressByAttribute('kc_contact_id', $addressArray['kc_contact_id']);
                //If it's the first import after exporting to Khaos then this address won't have a kc_contact_id. Try and match on data.
                if (!$addressObj->getId()) 
                    $addressObj = $this->getAddressByMatching($addressArray);
                
                if ($addressObj->getId())
                    $addressObj->load($addressObj->getId());
                else
                {
                    $addressObj
                        ->setCustomerId($customerObj->getId())
                        ->setIsDefaultBilling($addressArray['is_default_billing'])
                        ->setIsDefaultShipping($addressArray['is_default_shipping'])
                        ->setSaveInAddressBook('1');
                }
                
                $addressObj->addData($addressArray)->save();
                $count++;
            }
            
            $this->importedCodes[] = $this->getPropS($customerXml, "COMPANY_CODE");
            unset($customerObj);
            unset($addressObj);
        }
    }
    
    protected function getAddressByMatching($addressData)
    {
        $addressObj = Mage::getModel('customer/address');
        
        $addresses = $addressObj->getCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('firstname', $addressData['firstname'])
            ->addAttributeToFilter('lastname', $addressData['lastname'])
            ->addAttributeToFilter('city', $addressData['city'])
            ->addAttributeToFilter('postcode', $addressData['postcode'])
            ->addAttributeToFilter('telephone', $addressData['telephone'])
            ->load();
        
        foreach ($addresses as $address)
            return $address;
        
        return $addressObj;
    }
    
    protected function getGroupId($groupName)
    {
        return Mage::getModel('customer/group')->load($groupName, 'customer_group_code')->getId();
    }
    
    protected function getAddressArray($customerXml)
    {
        $result = array();
        $companyFirstName = '';
        $companyLastName = '';
        $count = 0;
        
        if ($customerXml->ADDRESSES != null && count($customerXml->ADDRESSES->ADDRESS) > 0)
        {
            foreach ($customerXml->ADDRESSES->ADDRESS as $address)
            {
                foreach ($address->CONTACTS->CONTACT as $contact)
                {
                    if ($count == 0)
                    {
                        $companyFirstName = (string)$contact->FORENAME;
                        $companyLastName = (string)$contact->SURNAME;
                    }
                    $default = ($count == 0) ? '1' : '0';

                    $result[] = array (
                        'firstname' => (string)$contact->FORENAME,
                        'lastname' => (string)$contact->SURNAME,
                        'street' => array (
                            '0' => (string)$address->ADDR1,
                            '1' => (string)$address->ADDR2
                        ),
                        'city' => (string)$address->TOWN,
                        'region_id' => '',
                        'region' => '',
                        'postcode' => (string)$address->POSTCODE,
                        'country_id' => (string)$address->COUNTRY_CODE,
                        'telephone' => (string)$address->TEL,
                        'is_default_billing' => $default,
                        'is_default_shipping' => $default,
                        'kc_contact_id' => (string)$contact->CONTACT_ID,
                        'kc_address_id' => (string)$address->ADDRESS_ID
                    );

                    $count++;
                }
            }
        }
        return array($result, $companyFirstName, $companyLastName);
    }
    
    protected function logGroupPriceList($result)
    {
        $type = parent::cLogTypeGroupPriceList;
        
        if ($result instanceof Exception)
        {
            $message = "Error: {" . $result->getMessage() . "}";
            $status = parent::cLogStatusFailed;
        }
        else
        {
            $message = 'Groups: ' . $this->arrayToCSV($result);
            $status = parent::cLogStatusSuccess;
        }
        
        $this->dbLog('', $type, $message, '', $status);
    }
    
    protected function logCustomer($result, $actionType = 'Import', $companyCode = '')
    {
        $type = parent::cLogTypeCustomer;
        
        if ($result instanceof Exception)
        {
            $message =  $actionType . " Error: {" . $result->getMessage() . "}";
            if ($companyCode != '')
                $message = "Company Code {" . $companyCode . "} " . $message;
            if ($_SESSION["GeneralFatalErrorCode"] != "")
                $message = "Code {" . $_SESSION["GeneralFatalErrorCode"] . "} " . $message;
            $status = parent::cLogStatusFailed;
        }
        else if ($actionType == "Import")
        {
            $message = $actionType . 'ed ' . count($result) . ' customer(s)';
            $status = parent::cLogStatusSuccess;
        }
        else
        {
            $message = (string)$result;
            $status = parent::cLogStatusUpdate;
        }
        
        $this->dbLog('', $type, $message, '', $status);
    }
    
    protected function getAddressByAttribute($code, $value)
    {
        $addressObj = Mage::getModel('customer/address');
        
        $addresses = $addressObj->getCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter(array(array('attribute' => $code, 'eq' => $value)))
            ->load();
        
        foreach ($addresses as $address)
            return $address;
        
        return $addressObj;
    }
    
    protected function doCustomerGroupImport()
    {
        $companyClasses = Mage::helper("khaosconnect/webservice")->getCompanyClasses();
        $this->setAction("CustomerGroupImport");
        if ($this->validArray($companyClasses))
        {
            foreach ($companyClasses as $companyClass)
            {
                try
                {
                    $this->importCustomerGroup($companyClass);
                }
                catch(Exception $e)
                {
                    $this->logCustomer($e);
                }
            }
        }
    }
    
    protected function importCustomerGroup($companyClass)
    {
        if (strlen($companyClass) > 33)
            throw new Exception("Customer group name is to big to be imported: " . $companyClass . ".");
            
        $groupObj = Mage::getModel('customer/group');
        $existingGroup = $groupObj->load($companyClass, 'customer_group_code');
        $this->setCode($companyClass);
        
        if ($existingGroup->getCustomerGroupCode() == "")
        {
            $groupData = array('customer_group_code' => $companyClass);
            $groupObj->setData($groupData)->save();
        }
    }
    
    public function doGroupedPriceListImport($websiteIds)
    {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $this->setAction("Grouped Price List Import");
        $this->setCode("");
        
        if ($this->validArray($websiteIds))
        {
            $this->websiteIds = $websiteIds;
            $selSQL = 
                'select distinct ' .
                'cg.customer_group_code ' .
                'from ' .
                $this->resource->getTableName('customer_entity') . " as c " .
                'inner join ' . $this->resource->getTableName('customer_group') . ' as cg on c.group_id = cg.customer_group_id';
            
            $query = $this->readDB->query($selSQL);
            if ($query->rowCount() == 0)
                return;
            
            $groups = array();
            $groups[] = "NOT LOGGED IN";
            while ($row = $query->fetch())
                $groups[] = $row['customer_group_code'];
            
            try
            {
                if ($this->validArray($groups))
                {
                    $this->importPriceLists($groups);
                    $this->systemValues->setSysValue($this->systemValues->plgLastSyncPrefix, time());
                    $this->logGroupPriceList($groups);
                }
            }
            catch(Exception $e)
            {
                $this->logGroupPriceList($e);
            }
        }
    }
    
    protected function getCustomerListData($customerList)
    {
        $codesToSync = "";
        $customerCodes = array();
        foreach ($customerList->List as $customer)
        {
            $customerCodes[] = $customer->CompanyCode;
        }
        
        return $customerCodes;
    }
    
    protected function updateCustomerCodes($importResult, $websiteId)
    {
        foreach ($importResult->CustomerImport as $result)
        {
            $data[] = $this->writeDB->quoteInto('select ? AS email, "'.$result->URN.'" AS newvalue', $result->WebUser);
        }
        
        if(empty($data)) return;
        $values = implode(' union ', $data);
        $entityTable = $this->resource->getTableName('customer_entity');
        $attributeTable = $this->resource->getTableName('eav_attribute');
        $cev = $this->resource->getTableName('customer_entity_varchar');
        $this->writeDB->beginTransaction();
        $this->writeDB->query(<<<SQL
		update $entityTable
		join $attributeTable as eava on eava.attribute_code = 'kc_company_code'
		join $cev using (attribute_id, entity_id)
		join ($values) t1 using (email)
		set value = newvalue;
SQL
        );
	    $this->writeDB->commit();
    }
}

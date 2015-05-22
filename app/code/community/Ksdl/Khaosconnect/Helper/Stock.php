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

class Ksdl_Khaosconnect_Helper_Stock extends Ksdl_Khaosconnect_Helper_Basehelper
{
    protected $scsPriceMapping;
    protected $imageMappingTableName;         
    protected $exportedStockItems;            //The actual XML Object returned from the web service.
    protected $processedStockCodes = array(); //All imported items added here to stop it importing items that have already been imported (i.e forward syncing of related items)
    protected $syncSelectedStockCodes;
    protected $harmonisationData;
    protected $specialOfferData;
    protected $processingStoreId = 0;
    protected $processingWebsiteId = "";
    protected $syncImages = true;
    protected $exportedStockStatus = array(); //[stockCode => array('level'=>'1', 'status'=>'2')]
    protected $lastSyncTime = 0;
    protected $stockControlSiteId = "";
    protected $stockControlSites = array();
    protected $imagesToUpdate = array();
    protected $rootCategoryName = "";
    protected $additionalDataAsAttributes = array (
        "STOCK_TYPE" => "text",
        "STOCK_TTYPE" => "text",
        "STOCK_MID_TYPE" => "text",
        "STOCK_SUB_TYPE" => "text",
        "MANUFACTURER" => "select",
        "VAT_RELIEF_QUALIFIED" => "text",
        "MIN_LEVEL" => "text",
        "SAFE_LEVEL" => "text",
        "LAUNCH_TIME" => "text"
        );
    
    public $stockCategoryList;
    
    function __construct() {
        parent::__construct();
        $this->imageMappingTableName = $this->resource->getTableName('khaosconnect_product_image_mapping');
    }
    
    public function doStockSync($syncWebCategories, $syncSelectedStockCodes = null)
    {
        //Sync and build a list of categories for the stock sync routine.
        $stockCategoryList = $this->getStockCategoryList($syncWebCategories);
        if (!empty($stockCategoryList))
        {
            $this->stockCategoryList = $stockCategoryList;
            $this->syncSelectedStockCodes = $syncSelectedStockCodes;
            
            $this->syncStock();
        }
    }
    
    public function getStockCategoryList($syncWebCategories, $forcedAll = false)
    {
        $stockCategoryList = array();
        
        if ($this->validArray($syncWebCategories) || $forcedAll)
        {
            //Sync all categories as we need to know where the stock item belong before a stock sync.
            //This is because we have to set the categories against a stock item when we create / update it.
            //We can then assume if it isn't in this list then the item has been removed from the website and it can be removed.
            foreach (Mage::app()->getWebsites() as $website) 
            {
                foreach ($website->getStores() as $store) 
                {
                    $webCatValue = $this->systemValues->rcPrefix . $store->getId();
                    $syncThisCat = (!$forcedAll && (array_key_exists ($store->getId(), $syncWebCategories) && $syncWebCategories[$store->getId()] == $webCatValue)) ? '-1' : '0';
                    $webCategory = $this->systemValues->getSysValue($webCatValue);
                    
                    if ($webCategory != '') 
                    {
                        $webCatObj = Mage::helper("khaosconnect/webservice")->GetWebCategories($webCategory);
                        if ($webCatObj !== false)
                        {
                            $khaosStockItemCategories = Mage::helper("khaosconnect/category")->SyncWebCategories($webCatObj);
                            $allStockItemCategories = $this->getNonKhaosCategoryStockMapping($khaosStockItemCategories);
                            
                            $stockCategoryList[$webCatValue] = array(
                                'isBeingSyncd' => $syncThisCat, //This is so we know whether to sync the stock for this or not.
                                'websiteId' => $website->getId(),
                                'groupId' => $store->getId(),
                                'stockItemCategories' => $allStockItemCategories
                            );
                        }
                    }
                }
            }
        }
        return $stockCategoryList;
    }
    
    //Return a list of items that have been added to categories which Khaos isn't managing. 
    //These need to be merged with the global list that maps stock codes to category IDs
    protected function getNonKhaosCategoryStockMapping($khaosStockItemCategories)
    {
        $result = $khaosStockItemCategories;
        
        $sql = 
            "select " .
            "c.entity_id, pe.sku " .
            "from " . 
            $this->resource->getTableName('catalog_category_entity') . " as c " .
            "inner join " . $this->resource->getTableName('catalog_category_product') . " as cp on c.entity_id = cp.category_id " .
            "inner join " . $this->resource->getTableName('catalog_product_entity') . " as pe on cp.product_id = pe.entity_id " .
            "left join " . $this->resource->getTableName('khaosconnect_catalog_category_link') . " as kc on c.entity_id = kc.entity_id " .
            "where " .
            "kc.entity_id is null";
        
        $query = $this->readDB->query($sql);
        if ($query->rowCount() == 0)
            return $result;
        
        while ($row = $query->fetch())
        {
            $stockCode = $row['sku'];
            if (array_key_exists($stockCode, $result)) //Don't do anything with stock item's that don't exist in our web categories.
                $result[$stockCode]['catId'][] = (int)$row['entity_id'];
        }   
        return $result;
    }
    
    public function removeDeletedStock()
    {
        $collection = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToSelect('*');
        
        //Build a simple array of all the stock codes on the website.
        $activeStockCodes = array();
        foreach ($this->stockCategoryList as $key => $value)
        {
            foreach ($value['stockItemCategories'] as $stockCode => $catId)
            {
                if (!in_array($stockCode, $activeStockCodes))
                    $activeStockCodes[] = $stockCode;
            }
        }
        
        Mage::app("default")->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        foreach ($collection as $product)
        {
            $stockCode = $product->getSku();
            if (!in_array($stockCode, $activeStockCodes))
            {
                $product->delete();
            }
        }
    }
    
    public function doDefaultPricesSync($storeIds)
    {
        if (!empty($storeIds))
        {
            foreach ($storeIds as $storeId)
            {
                $this->syncDefaultPrices($storeId);   
            }
        }
    }
    
    public function syncStock()
    {
        $webService = Mage::helper("khaosconnect/webservice");
        $this->lockFileName = "stock_codes";
        
        //Make sure we are only doing the additional data exports from Khaos if stock items are being sync'd
        if ($this->isSyncing())
        {
            $this->harmonisationData = Mage::helper("khaosconnect/webservice")->getHarmonisationCodes();
            $this->stockControlSites = Mage::helper("khaosconnect/webservice")->getSites();
        }
        
        foreach ($this->stockCategoryList as $key => $value)
        {
            $this->processingStoreId = $value['groupId'];
            $_SESSION['ProcessingStoreId'] = $this->processingStoreId;
            if ($this->systemValues->getSysValue($this->systemValues->opUpdateStockWebsite . $value['websiteId']) == '-1')
                $this->processingWebsiteId = $value['websiteId'];
            $this->syncImages = $this->systemValues->getSysValue($this->systemValues->opUpdateStockImages) == '-1';
            $stockCodeArray = $value['stockItemCategories'];
            $this->rootCategoryName = $this->systemValues->getSysValue($this->systemValues->rcPrefix . $this->processingStoreId);
            $this->setStockControlSiteId();
            
            if ($value['isBeingSyncd'] == '-1')
            {
                $stockCodesToSync = array();
                try
                {
                    if ($this->validArray($this->syncSelectedStockCodes))
                    {
                        $thisStoresCodesToSync = array_map('trim', explode(',', $this->syncSelectedStockCodes[$this->processingStoreId]));
                        $stockCodesToSync = array_map('strtolower', $thisStoresCodesToSync);
                    }
                    else
                    {
                        $stockCodesToSync = array_filter($this->getStockCodesFromLockFile());
                        
                        if (!$this->validArray($stockCodesToSync))
                        {
                            if ($this->validArray($stockCodeArray))
                            {
                                $webCatStockArray = $this->getValidStockCodesFromCategoryArray($stockCodeArray);
                                $this->lastSyncTime = $this->systemValues->getSysValue($this->systemValues->rcLastSyncPrefix . $this->processingStoreId);
                                $stockArray = $this->buildStockArray($webService->getStockList($this->lastSyncTime));

                                if ($stockArray)
                                {
                                    $stockCodesToSync = array_intersect($webCatStockArray, $stockArray); //Get items that exist in the web category but have been updated within said timeframe.
                                    $this->setStockCodeLockFile($stockCodesToSync);
                                }
                            }
                        }
                    }
                    
                    if (!empty($stockCodesToSync))
                    {
                        $this->processBatchImport($stockCodesToSync);
                                                
                        $this->syncDefaultPrices($this->processingStoreId);
                        $this->syncSpecialOfferPrices($this->processingStoreId);
                        
                        if (!$this->validArray($this->syncSelectedStockCodes)) //Don't clear the sync list when syncing selected stock codes.
                            $this->clearStockCodeLockFile();
                    }
                    
                    //Even if nothing has changed, update the last sync time so we know when it last ran.
                    if (!$this->validArray($this->syncSelectedStockCodes)) //Don't update the last time sync'd when running specific stock code updates.
                        $this->systemValues->setSysValue($this->systemValues->rcLastSyncPrefix . $this->processingStoreId, time());
                    //When running this in a cron we need the value updated in the cahce or it will keep the same value.
                    Mage::app()->getStore()->resetConfig();
                    $this->logStock(null, $this->processingStoreId);
                    
                    $_SESSION['ProcessingStoreId'] = null;
                    $_SESSION['ProcessingStockCode'] = null;
                }
                catch (Exception $e)
                {
                    $this->logStock($e, $this->processingStoreId);
                    throw $e;
                }
            }
        }
    }
    
    protected function setStockControlSiteId()
    {
        if (empty($this->stockControlSites))
            $this->stockControlSites = Mage::helper("khaosconnect/webservice")->getSites();
        
        $selectedSite = $this->systemValues->getSysValue($this->systemValues->roSitePrefix . $this->processingStoreId);
        
        foreach ($this->stockControlSites->List as $site)
        {
            if (strtolower($site->SiteName) != strtolower($selectedSite))
                continue;
            
            $this->stockControlSiteId = $site->SiteID;
        }
        
        if ($this->stockControlSiteId == "")
            $this->stockControlSiteId = "-1";    
    }
    
    protected function processBatchImport($stockCodesToSync)
    {
        $processAtOnce = 50;
        $codeCount = count($stockCodesToSync);
        $count = 0;
        $iterations = ($codeCount > 0 && $codeCount < $processAtOnce) 
            ? 1
            : ceil($codeCount / $processAtOnce);
            
        for ($i = 0; $i < $iterations; $i++)
        {
            $batchLog = $i + 1;
            $processCodes = array_slice($stockCodesToSync, $i * $processAtOnce, $processAtOnce);
            $this->doBatchImport($processCodes);
        }
    }
    
    protected function doBatchImport($processCodes)
    {
        if ($this->validArray($processCodes))
        {
            $codesToSync = "";
            foreach ($processCodes as $stockCode)
                $codesToSync .= $stockCode . ",";
            
            if ($codesToSync != '')
            {
                $this->exportedStockItems = Mage::helper("khaosconnect/webservice")->exportStock($processCodes);
                $this->getStockStatus($processCodes);
                $this->doStockImport($this->exportedStockItems, $this->processingStoreId);
            }
        }
    }
    
    protected function getStockLockFilePath($storeId)
    {
        return $this->getLockFilePath($storeId);
    }
    
    public function removeStockCodeFromLockFile($stockCode, $storeId)
    {
        $this->removeCodeFromLockFile($stockCode, $storeId);
    }
    
    protected function clearStockCodeLockFile()
    {
        if ($this->isLockFileEmpty($this->processingStoreId))
            $this->clearLockFile($this->processingStoreId);
    }
    
    protected function setStockCodeLockFile($stockCodes)
    {
        $this->setLockFile($stockCodes, $this->processingStoreId);
    }
    
    protected function getStockCodesFromLockFile()
    {
        return $this->getCodesFromLockFile($this->processingStoreId);
    }
    
    protected function isSyncing()
    {
        foreach ($this->stockCategoryList as $key => $value)
        {
            if ($value['isBeingSyncd'] == '-1')
                return true;
        }
        
        return false;
    }
    
    protected function logStock($result, $storeId, $stockCode = '', $userMessage = '')
    {
        $type = parent::cLogTypeStock;
        
        if ($result instanceof Exception)
        {
            $message = "
                Site: {" . $this->rootCategoryName . "}<br>
                Error: {" . $result->getMessage() . "}<br>";
            if ($stockCode != '')
            {
                $message .= "StockCode: {" . $stockCode . "}<br>";
                $this->removeStockCodeFromLockFile($stockCode, $storeId);
            }
            $message .= "Stack Trace: {" . $result->getTraceAsString() . "}";
            $status = parent::cLogStatusFailed;
        }
        else
        {
            $message = "Site: {" . $this->rootCategoryName . "}";
            $status = parent::cLogStatusSuccess;
        }
        
        if ($userMessage != '')
            $message = $userMessage;
        
        $this->dbLog($storeId, $type, $message, $this->rootCategoryName, $status);
    }
    
    public function doStockImport($stockItems, $storeId)
    {
        Mage::init();
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

        foreach($stockItems->STOCK_ITEM as $stockItem)
        {
            $stockCode = $this->getPropS($stockItem, "STOCK_CODE");
            try
            {
                $_SESSION['ProcessingStockCode'] = $stockCode;
                $this->importStockItem($stockItem);
            }
            catch(Exception $e)
            {
                $this->logStock($e, $storeId, $stockCode);   
            }
        }
    }
    
    protected function importStockItem($stockItem)
    {
        $stockCode = $this->getPropS($stockItem, 'STOCK_CODE');
        
        if (!in_array($stockCode, $this->processedStockCodes))
        {
            //Make sure this stock item doens't get sync'd again (used when forward syncing related items).
            //Do this here because if we are syncing related items and more than one item references the same item, we will go around in circles.
            $this->processedStockCodes[] = $stockCode;
            
            if ($this->stockItemAllowed($stockItem))
            {
                //Clear the heading list for each stock item to avoid issues.
                $this->scsPriceMapping = array();

                $isSCSParent = isset($stockItem->SCS);
                $defaultSetId = Mage::getModel('catalog/product')->getResource()->getEntityType()->getDefaultAttributeSetId();
                $childProductIds = null;
                
                if ($isSCSParent)
                {
                    $scsHeadings = array();
                    
                    $setId = $this->syncSCSAttributes($stockItem, $defaultSetId, $scsHeadings);
                    $this->syncSCSChildren($stockItem->SCS->STYLE, $setId, 0, $childProductIds, $stockItem, null, $scsHeadings);
                    $this->syncSCSParent($stockItem, $setId, $childProductIds, $scsHeadings); 
                }
                else
                {
                    $this->createOrUpdateStockItem($stockItem, $defaultSetId, 'simple', false, null, null);
                }
            }
            else
            {
                $this->disableProduct($stockItem);
            }
            
            if (!$this->validArray($this->syncSelectedStockCodes))
                $this->removeStockCodeFromLockFile($stockCode, $this->processingStoreId);
        }
    }
    
    protected function disableProduct($stockItem)
    {
        $product = Mage::getModel('catalog/product');
        $product->load($product->getIdBySku($this->getPropS($stockItem, 'STOCK_CODE')));    
        if ($this->validId($product->getId()))
        {
            Mage::getModel('catalog/product_status')->updateProductStatus($product->getId(), $this->processingStoreId, Mage_Catalog_Model_Product_Status::STATUS_DISABLED);
        }
    }
    
    protected function stockItemAllowed($stockItem)
    {
        return $this->getPropS($stockItem, "DELETED") == "0";   
    }
    
    protected function syncStockImages($stockItem, &$product)
    {
        //Make sure we are clearing the images to be updated before we process another product / batch of images.
        $this->imagesToUpdate = array();
        
        //TODO: This whole method needs tidying up as it's a mess.
        
        $imageDir = Mage::getBaseDir('media') . DS . 'products';
        $stockCode = (string)$stockItem->STOCK_CODE;
        $stockItemImageArray = array(); //Used to track the images processed. Stores Magento File Path.
        $attributes = $product->getTypeInstance(true)->getSetAttributes($product);
        $mediaGalleryAttribute = $attributes['media_gallery'];
        $mediaBackend = $mediaGalleryAttribute->getBackend();
        $thisImageArray = array(); //Used to make sure we don't insert duplicate images.
        
        //Load the collection so we can get the gallery data.
        $collection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('entity_id', array('eq' => $product->getId()));        
        $collection->addAttributeToSelect('*');
        foreach ($collection as $product) 
        {
            $prod = Mage::helper('catalog/product')->getProduct($product->getId(), null, null);
            $attributes = $prod->getTypeInstance(true)->getSetAttributes($prod);
            $galleryData = $prod->getData('media_gallery');
            foreach ($galleryData['images'] as &$image) {
                $thisImageArray[] = $image["file"];
            }
        }
        
        if (isset($stockItem->STOCK_IMAGES))
        {
            $count = 0;
            $imageLabels = array();
            $imagePositions = array();
            $existingImageInfo = array();
            
            foreach ($stockItem->STOCK_IMAGES->STOCK_IMAGE as $image)
            {
                $pathInfo = pathinfo(str_replace("\\" , "/", $image->FILE_NAME));
                $fileName = $pathInfo['basename'];
                
                $magentoFileName = strtolower(preg_replace('/[^a-z0-9_\\-\\.]+/i', '_', $fileName));
                
                $filePath = $imageDir . DS . $fileName;
                $imageType = ($count == 0) ? array('image', 'small_image', 'thumbnail') : '';
                $label = (string)$image->IMAGE_DESC;
                
                $exists = false;
                if ($this->validArray($thisImageArray))
                {
                    foreach ($thisImageArray as $thisImage)
                    {
                        $thisImage = strtolower(basename($thisImage));
                        if (
                            ($thisImage == $magentoFileName || $this->getImageWithoutMagentoBits($thisImage) == $magentoFileName) ||
                            ($thisImage == $this->getAltFileName($magentoFileName) || $this->getImageWithoutMagentoBits($thisImage) == $this->getAltFileName($magentoFileName))
                            )
                        {
                            if (file_exists($filePath))
                            {
                                $oldcontents = file_get_contents($filePath);
                                
                                if ($prod->getId())
                                {
                                    $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
                                    $items = $mediaApi->items($product->getId());
                                    
                                    foreach($items as $item) 
                                    {
                                        if($this->getImageWithoutMagentoBits(strtolower(basename($item['url']))) == $magentoFileName)
                                        {
                                            
                                            //Save existing data.
                                            $newImage = $mediaApi->info($product->getId(), $item['file']);
                                            $existingImageInfo[$filePath] = array(
                                                "position" => $newImage["position"],
                                                "existing_file" => $newImage["file"]
                                            );
                                            
                                            if (@getimagesize($item['url']))
                                            {  
                                                $contents = file_get_contents($item['url']);
                                            }
                                            else
                                            {
                                                $contents = "";
                                            }
                                            
                                            if($oldcontents == $contents)
                                            {
                                                $exists = true;
                                                break;
                                            }
                                            else
                                            {
                                                //If product isnt in /product/media/ then it will remove?
                                                $mediaApi->remove($product->getId(), $item['file']);
                                                $exists = false;
                                                break;
                                            }
                                        }
                                        
                                    }
                                }
                                if ($exists) break;
                            }
                        }
                    }
                }
                
                if (!$this->validArray($thisImageArray) || !$exists)
                {                    
                    $useAltPath = file_exists($imageDir . DS . $this->getAltFileName($fileName));
                    if (file_exists($filePath) || $useAltPath)
                    {
                        if ($useAltPath)
                            $filePath = $imageDir . DS . $this->getAltFileName($fileName);
                        
                        $magentoFilePath = $mediaBackend->addImage($product, $filePath, $imageType, false, false);
                        
                        $stockItemImageArray[] = $magentoFilePath;
                        $imageLabels[$magentoFilePath] = $label;
                        
                        if (array_key_exists($filePath, $existingImageInfo))
                            $imagePositions[$magentoFilePath] = $existingImageInfo[$filePath]["position"];
                        
                    }
                }
                else
                    $stockItemImageArray[] = $fileName;
                
                $count++;
            }            
        }
        
        //Update labels
        $images = $product->getMediaGalleryImages();
        if (!empty($images))
        {
            $attributes = $product->getTypeInstance(true)->getSetAttributes($product);
            $gallery = $attributes['media_gallery'];
            foreach ($images as $image) 
            {
                if (array_key_exists($image->getFile(), $imageLabels))
                {
                    //doesnt exist?
                    if (array_key_exists($image->getFile(), $imagePositions))
                        $existingPosition = $imagePositions[$image->getFile()];
                    else
                        $existingPosition = null;
                    
                    $backend = $gallery->getBackend();
                    $backend->updateImage(
                        $product,
                        $image->getFile(),
                        array('label' => $imageLabels[$image->getFile()], "position" => $existingPosition)
                    );
                    
                }
            }
            $product->getResource()->saveAttribute($product, 'media_gallery');
        }
        
        //Work out what images need removing.
        $magentoImages = array();
        if (is_array($product->getMediaGalleryImages()))
            foreach ($product->getMediaGalleryImages() as $collectionImage)
                $magentoImages[] = $collectionImage->getFile();

        $deletedImages = array_diff($magentoImages, $stockItemImageArray);
        foreach ($deletedImages as $deletedImage)
        {
            $mediaBackend->removeImage($product, $deletedImage);
        }
    }
    
    protected function getAltFileName($fileName)
    {
        $fileParts = explode("\\", $fileName);
        $lastFilePart = end($fileParts);  
        return $lastFilePart;
    }
    
    protected function getImageWithoutMagentoBits($fileName)
    {
        $ext = "." . pathinfo($fileName, PATHINFO_EXTENSION);
        $fileName = str_replace($ext, " " . $ext, $fileName);
        $withoutExt = str_replace($ext, "", $fileName);
        $magentoCount = strrchr($withoutExt, "_");
        
        if(is_numeric(str_replace("_", "", str_replace(" ", "",$magentoCount))))
            $result = str_replace($magentoCount, "", $fileName);
        else
            $result = str_replace(" ", "", $fileName);
        
        if (is_numeric(str_replace("_", "", trim(str_replace($ext, "", strrchr($result, "_"))))))
        {
            return $this->getImageWithoutMagentoBits($result);
        }
        
        return preg_replace('/[^a-z0-9_\\-\\.]+/i', '_', $result);
    }
    
    protected function getValidStockCodesFromCategoryArray($stockCodeArray)
    {
        $stockCodes = array();
        foreach ($stockCodeArray as $key => $value)
        {
            if ($value['scsChild'] != '1') //Child SCS items are exported with their parent so we don't want to export them manually.
            {
                $stockCodes[] = $key;
            }
        }
        
        return $stockCodes;
    }
    
    protected function buildStockArray($stockList)
    {
        if ($stockList->Count == 0)
            return false;
        
        foreach ($stockList->List as $key)
            $result[] = $key->ParentCode == "" ? $key->StockCode : $key->ParentCode;
        
        return $result;
    }
    
    protected function syncSCSParent($stockItem, $setId, $childProductIds, $scsHeadings)
    {
        list($newItem, $productId) = $this->createOrUpdateStockItem($stockItem, $setId, 'configurable', null, null, $scsHeadings);
        $product = Mage::getModel('catalog/product')->load($productId); //Reopen the stock item to get any changes.
        
        if ($product->getTypeId() != "configurable")
            return;
        
        //Don't want to update these if nothing has changed as this will slow down the import.
        $configProductsChanged = $this->getHasAssociatedProductsChanged($product, $childProductIds);
        
        //Build a list of existing attributes for this config product so we can check existing attributes etc.
        $existingConfigAttributes = $this->ensureOptionsIntegrity($this->getExistingConfigurableAttributes($product), $product->getId(), $scsHeadings);
        $configurableAttributesData = $this->getConfigurableAttributeProductData($product, $existingConfigAttributes, $stockItem, $childProductIds, $scsHeadings);
            
        //Set the configurable attribute information - Will only add "new" information
        if (!empty($configurableAttributesData))
        {
            $product->setConfigurableAttributesData($configurableAttributesData);
            $product->setCanSaveConfigurableAttributes(1);
            $product->save();
            $product = Mage::getModel('catalog/product')->load($productId); //Need to reload the item so that we don't get Duplicate key issues once we try and assign the child items.
        }
        
        if ($configProductsChanged || $newItem)
        {
            $newIds = array();
            $currentIds = $product->getTypeInstance()->getUsedProductIds();
            $currentIds = array_merge($childProductIds, $currentIds);
            $currentIds = array_unique($currentIds);
            
            foreach($currentIds as $tempId)
                parse_str("position=", $newIds[$tempId]);

            $product->setConfigurableProductsData($newIds)->save();
        }
    }
    
    protected function ensureOptionsIntegrity($existingConfigAttributes, $productId, $scsHeadings)
    {
        $verifiedIds = array();
        foreach ($scsHeadings as $key => $value)
        {
            $verifiedIds[] = $value["attrId"];
        }
        
        $this->removeConfigurableOptions(array_diff($existingConfigAttributes, $verifiedIds), $productId);
        return array_intersect($verifiedIds, $existingConfigAttributes);
    }
    
    protected function removeConfigurableOptions($attributeIds, $productId)
    {
        //Note: This couldn't be avoided. Magento doesn't allow removal of options against configurable parents without direct database queries.
        //If a caption is renamed in Khaos then additional options will be added to the configurable product - we need to remove the old ones.
        if ($this->validArray($attributeIds))
        {
            $attributeIds = implode(",", $attributeIds);
            $disableSql = "delete from {$this->resource->getTableName('catalog_product_super_attribute')} where attribute_id in ({$attributeIds}) AND product_id = {$productId}";
            $this->writeDB->query($disableSql);
        }
    }
    
    protected function getHasAssociatedProductsChanged($product, $childProductIds)
    {
        $assIds = array();
        foreach ($product->getTypeInstance()->getUsedProducts($product) as $associatedProduct)
            $assIds[] = $associatedProduct->getId();
        
        return $this->validArray(array_diff($childProductIds, $assIds));
    }
    
    protected function createOrUpdateStockItem($stockItem, $setId, $productType, $parentStockItem = null, $scsValues = null, $scsHeadings)
    {
        //Create any attributes we require when assigning stock information prior to opening the stock item to ensure the new attributes are available to update.
        $this->createAttributesBeforeStockItemSave($stockItem, $setId);
        
        //Try and open for edit.
        $product = Mage::getModel('catalog/product');
        $product->load($product->getIdBySku($this->getPropS($stockItem, 'STOCK_CODE')));    
        $newItem = !$this->validId($product->getId());
        
        if ($newItem)
            $productType = $this->getProductType($stockItem, $productType); //Get productType from LINKED_ITEMS i.e could be grouped, bundled etc.
        else
            $productType = $product->getTypeId();
        
        //Build an array of product data that will be used to create the initial stock item.
        $productData = $this->getProductDataArray($stockItem, $parentStockItem, $newItem, $product->getWebsiteIds(), $product->getCategoryIds());
        if ($scsValues != null)
            $productData = $this->getProductDataArraySCSChild($productData, $stockItem, $scsValues, $parentStockItem, $newItem, $scsHeadings);
        //If this is a new item then it won't save the data that is applied directly to the object via function calls.
        //It doesn't seem to work adding these values directly to the $productData array so instead we create the item
        //with the $productData we have and then re-open the item ready for editing directly on the object.
        if ($newItem)
        {
            $productId = $this->saveStockItem($stockItem, $product, $productType, $setId, $productData);
            $product->load($productId);
        }           
        
        //Direct object changes to the product which cannot be added via $productData
        if ($this->syncImages)
            $this->syncStockImages($stockItem, $product);
        $this->syncLinkedItems($stockItem, $product);
        
        //Update the object changes
        $productId = $this->saveStockItem($stockItem, $product, $productType, $setId, $productData); 
        return array($newItem, $productId);
    }
    
    protected function updateExistingImageProperties($product)
    {
        $productId = $product->getId();
        if (array_key_exists($productId, $this->imagesToUpdate))
        {
            $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
            $file = $this->imagesToUpdate[$productId]["file"];
            $position = $this->imagesToUpdate[$productId]["position"];
            $mediaApi->update($productId, $file, array("position" => $position));    
            $product->save();        
        }
    }
    
    protected function createAttributesBeforeStockItemSave($stockItem, $setId)
    {
        $attributes = array();
        
        $attributes = array_merge($attributes, $this->importStockUDAsAsAttributes($stockItem));
        $attributes = array_merge($attributes, $this->importHarmonisationCodesAsAttributes($stockItem));
        $attributes = array_merge($attributes, $this->importGeneralStockDataAsAttributes($stockItem));
        
        if (!empty($attributes))
            $this->addAttributesToGroupName('UDAs', $attributes, $setId);
    }
    
    protected function importGeneralStockDataAsAttributes($stockItem)
    {
        $attributes = array();
        $attributes[] = $this->createAttribute($this->getAttributeName("parent_stock_code"), 'Parent Stock Code', 'text', '');
        $attributes[] = $this->createAttribute($this->getAttributeName("kc_last_updated"), 'Last Updated by Magento Module', 'text', '');
        $attributes[] = $this->createAttribute($this->getAttributeName("due_date"), 'Stock Due in (based on next purchase order in Khaos)', 'text', '');
        
        foreach ($this->additionalDataAsAttributes as $field => $type)
        {
            if ($this->getPropS($stockItem, $field) != '')
            {
                $code = $this->getAttributeName($field);
                switch ($type)
                {
                    case "select":
                        $this->createAttribute($code, $field, $type, '');
                        $attributes[] = $this->addOptionsToAttribute($code, array(array("value" => $this->getPropS($stockItem, $field), "order" => 0)));
                        break;
                    default:
                        $attributes[] = $this->createAttribute($code, $field, $type, '');
                        break;
                }
            }
        }
        return $attributes;
    }
    
    protected function importHarmonisationCodesAsAttributes($stockItem)
    {
        list($harmCode, $harmDuty) = $this->getHarmonisationCode($stockItem->STOCK_CODE);
        $attributes = array();
        if ($harmCode != "")
        {
            $attributes[] = $this->createAttribute('kc_harm_code', 'Harmonisation Code', 'text', '');
            $attributes[] = $this->createAttribute('kc_harm_duty', 'Harmonisation Duty', 'text', '');
        }
        return $attributes;
    }
    
    protected function getHarmonisationCode($stockCode)
    {
        foreach ($this->harmonisationData as $hObj)
        {
            foreach ($hObj->StockItems as $skItem)
            {
                if ($skItem->StockCode == $stockCode)
                {       
                    return array($hObj->Code, $skItem->Duty);
                }
            }
        }
    }
    
    protected function importStockUDAsAsAttributes($stockItem)
    {
        $attributes = array();
        if ($stockItem->USER_DEFINED != null)
        {
            foreach ($stockItem->USER_DEFINED as $uda)
            {
                $attribute = $this->createUDAAttribute($uda);
                if (!empty($attribute))
                    $attributes[] = $attribute;
            }
        }
        return $attributes;
    }
    
    protected function addAttributesToGroupName($groupName, $attributes, $setId)
    {
        if ($this->validArray($attributes))
        {
            $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
            $entityTypeId = Mage::getModel('catalog/product')->getResource()->getTypeId();
            $groupId = $setup->getAttributeGroup($entityTypeId, $setId, $groupName, 'attribute_group_id');
            
            $this->addAttributesToGroup($setup, $attributes, $groupId, $setId, $entityTypeId); 
        }
    }
    
    protected function createUDAAttribute($uda)
    {
        $udaValue = (string)$uda;
        if ($udaValue != '')
        {
            $udaName = $uda->attributes()->NAME;
            $type = isset($uda->attributes()->TYPE) ? $this->getAttributeTypeFromUDAType($uda->attributes()->TYPE) : "text";
            $udaAttrName = $this->getUDAAttributeName($udaName);
            if (strlen($udaAttrName) <= 30)
            {
                $attribute = $this->createAttribute($udaAttrName, $udaName, $type, '');
                
                switch ($type)
                {
                    case "select":
                    case "multiselect":
                        $attribute = $this->addOptionsToAttribute(str_replace(" ", "_", $udaAttrName), $this->getUDAOptionValues($udaValue, $type));
                        break;
                }
                
                return $attribute;
            }
        }
    }
    
    protected function getUDAOptionValues($udaValue, $type)
    {
        switch ($type)
        {
            case "select": return array(array("value" => $udaValue, "order" => 0)); beak;
            case "multiselect":
                $optionsArr = explode(",", $udaValue);
                $options = array();
                foreach ($optionsArr as $option)
                {
                    $options[] = array("value" => str_replace('"', '', $option), "order" => "0");
                }
                return $options;
                break;
        }
    }
    
    protected function getAttributeTypeFromUDAType($type)
    {
        switch ($type)
        {
            case "CHOICE": return "select"; break;   
            case "DATE": return "date"; break;
            case "LIST": return "multiselect"; break;
            case "YES/NO": return "boolean"; break;
            default: return "text"; break;
        }
    }
    
    protected function getProductType($stockItem, $productType)
    {
        if (isset($stockItem->LINKED_ITEMS) && count($stockItem->LINKED_ITEMS) > 0)
        {
            foreach ($stockItem->LINKED_ITEMS->LINK_ITEM as $linkedItem)
                if ($this->getPropI($linkedItem, 'LINK_TYPE') == parent::cLinkedPack)
                    return 'bundle';
                else if ($this->getPropI($linkedItem, 'LINK_TYPE') == parent::cLinkedAssociated)
                    return 'grouped';
        }
        return $productType;
    }
    
    protected function syncLinkedItems($stockItem, $parentProduct)
    {
        if (isset($stockItem->LINKED_ITEMS) && count($stockItem->LINKED_ITEMS) > 0)
        {
            $relatedProductIds = array();
            foreach ($stockItem->LINKED_ITEMS->LINK_ITEM as $linkedItem)
            {
                $this->syncLinkedItem($linkedItem, $relatedProductIds);
            }
            
            $this->syncRelatedItems($parentProduct, $relatedProductIds);
            $this->syncGroupedItems($parentProduct, $relatedProductIds);
            $this->syncBundledItems($parentProduct, $relatedProductIds);
        }
    }
    
    protected function syncLinkedItem($linkedItem, &$relatedProductIds)
    {
        $stockCode = $this->getPropS($linkedItem, 'STOCK_CODE');

        //Removed check to see if item already exists as we need to make sure the linked item is up to date as well
        //Not been imported yet so try and find it in the Xml Object, import it, then link again.
        $createStockItem = $this->getStockItemXml($stockCode);
        //Not in the web category tree so hasn't been exported. Export manually.
        //Commented out for now as it's causing eternal loop when 2 products reference each other.
        //if (!isset($createStockItem)) 
        //$createStockItem =  Mage::helper("khaosconnect/webservice")->exportStock(array($stockCode))->STOCK_ITEM;
        
        if (isset($createStockItem))
        {
            $this->importStockItem($createStockItem);    
            $product = Mage::getModel('catalog/product');
            $product->load($product->getIdBySku($this->getPropS($createStockItem, 'STOCK_CODE')));
            $this->getRelatedProductIds($product, $linkedItem, $relatedProductIds);
            unset($product);
        }
        else
        {
            $product = Mage::getModel('catalog/product');
            $product->load($product->getIdBySku($stockCode));
            $this->getRelatedProductIds($product, $linkedItem, $relatedProductIds);
        }
    }
    
    protected function syncRelatedItems($parentProduct, $relatedProducts)
    {
        $upSellItems = array();
        $crossSellItems = array();
        
        if ($this->validArray($relatedProducts))
        {
            if(array_key_exists(parent::cLinkedUpSell, $relatedProducts))
            {
                foreach ($relatedProducts[parent::cLinkedUpSell] as $relatedProduct)
                    $upSellItems[$relatedProduct['productId']] = array('position' => 0, 'qty' => '');
            }
            
            if(array_key_exists(parent::cLinkedCrossSell, $relatedProducts))
            {
                foreach ($relatedProducts[parent::cLinkedCrossSell] as $relatedProduct)
                    $crossSellItems[$relatedProduct['productId']] = array('position' => 0, 'qty' => '');
            }
            
        }
        $parentProduct->setUpSellLinkData($upSellItems);
        $parentProduct->setCrossSellLinkData($crossSellItems);
    }
    
    protected function syncGroupedItems($parentProduct, $relatedProductIds)
    {
        $items = array();
        if ($this->validArray($relatedProductIds) && array_key_exists(parent::cLinkedAssociated, $relatedProductIds))
        {
            foreach ($relatedProductIds[parent::cLinkedAssociated] as $relatedProduct)
                $items[$relatedProduct['productId']] = array('position' => 0, 'qty' => $relatedProduct['qty']);
        }
        $parentProduct->setGroupedLinkData($items);
    }
    
    protected function syncBundledItems($parentProduct, $relatedProductIds)
    {
        if ($parentProduct->getId() && $parentProduct->getTypeId() == Mage_Catalog_Model_Product_Type::TYPE_BUNDLE)
        {
            if ($this->validArray($relatedProductIds) && array_key_exists(parent::cLinkedPack, $relatedProductIds))
            {
                //Used in loop so could already be registered.
                Mage::unregister('current_product', $parentProduct);  
                Mage::unregister('product', $parentProduct);
                
                Mage::register('current_product', $parentProduct);
                Mage::register('product', $parentProduct);
                
                $optionCollection = $parentProduct->getTypeInstance(true)->getOptionsCollection($parentProduct);
                $selectionCollection = $parentProduct->getTypeInstance(true)->getSelectionsCollection(
                    $parentProduct->getTypeInstance(true)->getOptionsIds($parentProduct),
                    $parentProduct
                );
                $optionCollection->appendSelections($selectionCollection);
                
                $optionData = array();
                $optionSelectionData = array();

                $count = 0;
                foreach ($relatedProductIds[parent::cLinkedPack] as $relatedProduct)
                {
                    $title = 'Item ' . $count;
                    
                    $optionId = "";
                    foreach ($optionCollection as $option)
                    {
                        if ($option->getDefaultTitle() == $title)
                        {
                            $thisOptionId = $option->getOptionId();
                            foreach ($selectionCollection as $selectionProduct)
                            {
                                if ($selectionProduct->getProductId() == $relatedProduct['productId'])
                                {
                                    $optionId = $thisOptionId;
                                    break;
                                }
                                if ($optionId)
                                    break;
                            }
                        }
                    }
                    
                    $optionData[$count] = array(
                        'required' => 1,
                        'option_id' => $optionId,
                        'position' => 0,
                        'type' => 'select',
                        'title' => $title,
                        'default_title' => $title,
                        'delete' => '',
                    );
                    
                    $optionSelectionData[$count][] = array(
                        'product_id' => $relatedProduct['productId'],
                        'selection_qty' => $relatedProduct['qty'],
                        'selection_can_change_qty' => 0,
                        'position' => 0,
                        'is_default' => 1,
                        'selection_id' => '',
                        'selection_price_type' => 0,
                        'selection_price_value' => 0.0,
                        'option_id' => $optionId,
                        'delete' => ''
                    );
                    $count++;
                }
                
                $parentProduct->setCanSaveConfigurableAttributes(false);
                $parentProduct->setCanSaveCustomOptions(false);
                
                $parentProduct->setBundleOptionsData($optionData);
                $parentProduct->setBundleSelectionsData($optionSelectionData);
                $parentProduct->setCanSaveBundleSelections(true);
                $parentProduct->setAffectBundleProductSelections(true);
            }
        }
    }
    
    protected function getRelatedProductIds($product, $linkedItem, &$relatedProductIds)
    {
        $productId = $product->getId();
        if ($this->validId($productId))
            $relatedProductIds[$this->getPropI($linkedItem, 'LINK_TYPE')][] = array(
                'productId' => $productId, 
                'qty' => $this->getPropS($linkedItem, 'LINK_VALUE')
            );
    }
    
    protected function getStockItemXml($stockCode)
    {
        foreach ($this->exportedStockItems as $stockItem)
        {
            if ($this->getPropS($stockItem, 'STOCK_CODE') == $stockCode)        
                return $stockItem;
            else if (isset($stockItem->SCS) && $this->isChildOfStockItemXml($stockItem->SCS, $stockCode))
                return $stockItem;
        }
    }
    
    protected function isChildOfStockItemXml($styles, $stockCode)
    {
        $result = false;
        foreach ($styles->STYLE as $style)
        {
            if (isset($style->SCS_ITEM))
                if ($this->getPropS($style->SCS_ITEM, 'STOCK_CODE') == $stockCode)
                    return true;
            
            if (isset($style->STYLE))
                $result = $this->isChildOfStockItemXml($style->STYLE, $stockCode);
        }       
        return $result;
    }
    
    protected function getExistingConfigurableAttributes($product)
    {
        $existingConfigAttributes = array();
        if ($product)
        {
            $configAttributes = Mage::getModel('catalog/product_type_configurable')->getConfigurableAttributesAsArray($product);
            foreach ($configAttributes as $value)
                $existingConfigAttributes[] = $value['attribute_id'];
        }
        return $existingConfigAttributes;
    }
    
    protected function setStockControlStatus($product, $kcStockItem, $type)
    {
        //I have removed the configurable check below. I am not sure why it was there in the first place but not having it means that
        //when running the stock status re-index, the parent item is marked as stock_status = 0 because it doens't have an entry
        //in cataloginventory_stock_item table. This is a required entry and Magento re-adds it when saving the product manually from the admin panel.
        /*if ($type != 'configurable')
        {*/
            $stockCode = strtolower($product->getSku());            
            $stockLevel = (array_key_exists($stockCode, $this->exportedStockStatus)) ?
                $this->exportedStockStatus[$stockCode]['level'] : 0;
            $backorderable = $this->getPropS($kcStockItem, 'RUN_TO_ZERO') != "-1";
            $backorderVal = ($backorderable) ? Mage::getStoreConfig('cataloginventory/item_options/backorders') : Mage_CatalogInventory_Model_Stock::BACKORDERS_NO;
            $globalManageStock = Mage::getStoreConfig('cataloginventory/item_options/manage_stock') == "1";
            $isInStock = ($stockLevel > 0) || ($this->getPropS($kcStockItem, 'STOCK_CONTROLLED') != '-1') || !$globalManageStock || $backorderable;
            $manageStock = ($this->getPropS($kcStockItem, 'STOCK_CONTROLLED') == '-1') ? 1 : 0;
            if ($manageStock == 0)
                $stockLevel = 0; //When manage stock is No, default level to zero as Magento is still showing levels.
            
            $stockItem = Mage::getModel('cataloginventory/stock_item');
            $stockItem->assignProduct($product);
            $stockItem->setData('stock_id', 1);
            $stockItem->setData('qty', $stockLevel);
            $stockItem->setData('use_config_min_qty', 1);
            $stockItem->setData('use_config_backorders', 1);
            $stockItem->setData('min_sale_qty', 1);
            $stockItem->setData('use_config_min_sale_qty', 1);
            $stockItem->setData('use_config_max_sale_qty', 1);
            $stockItem->setData('is_in_stock', ($isInStock) ? 1 : 0);
            $stockItem->setData('use_config_notify_stock_qty', 1);
            $stockItem->setData('use_config_manage_stock', 0);
            $stockItem->setData('manage_stock', $manageStock); 
            $stockItem->setData('backorders', $backorderVal);
            $stockItem->setData('use_config_backorders', "0");
            $stockItem->save();
        //}
        
        $stockStatus = Mage::getModel('cataloginventory/stock_status');
        $stockStatus->assignProduct($product);
        $stockStatus->saveProductStatus($product->getId(), 1);
    }
    
    protected function syncSCSChildren($styleArray, $setId, $level, &$returnedProductIds, $parentStockItem, $scsValues = null, $scsHeadings)
    {
        foreach ($styleArray as $style)
        {
            $scsValues[$level] = $this->getPropS($style, 'DESCRIPTION');
            if (isset($style->SCS_ITEM))
            {
                $itemCount = count($style->SCS_ITEM);
                for ($i = 0; $i < $itemCount; $i++)
                {
                    $scsStockItem = $style->SCS_ITEM[$i];
                    if ($this->stockItemAllowed($scsStockItem))
                    {
                        $childStockCode = $this->getPropS($scsStockItem, 'STOCK_CODE');
                        
                        list($newItem, $returnedProductIds[]) = $this->createOrUpdateStockItem($scsStockItem, $setId, 'simple', $parentStockItem, $scsValues, $scsHeadings);
                        $this->scsPriceMapping[$childStockCode] = $this->getPropS($scsStockItem, 'SELL_PRICE');
                    }
                    else
                    {
                        $this->disableProduct($scsStockItem);
                    }
                }
            }
            else
                $this->syncSCSChildren($style, $setId, $level + 1, $returnedProductIds, $parentStockItem, $scsValues, $scsHeadings);
        }
    }
    
    protected function syncSCSAttributes($stockItem, $defaultSetId, &$scsHeadings)
    {
        $setName = 'scs_profile'; //i.e Will turn out as scs_profile_size_colour_style
        $levels = count($stockItem->SCS->HEADING);
        $scsValues[] = '';
        
        //Build a list of headings first so we can store the values in an array of [heading_name][array(val1, val2)]
        if ($levels > 1)
        {
            for ($i = 0; $i < $levels; $i++)
            {
                $headings[$i]["heading"] = $this->makeAttributeCode((string)$stockItem->SCS->HEADING[$i]);
                $headings[$i]["label"] = (string)$stockItem->SCS->HEADING[$i];
            }
        }
        else
        {
            $headings[0]["heading"] = $this->makeAttributeCode((string)$stockItem->SCS->HEADING);
            $headings[0]["label"] = (string)$stockItem->SCS->HEADING;
        }
        
        $this->getSCSValues(0, $stockItem->SCS->STYLE, $scsValues);
        $scsValues = array_unique($scsValues, SORT_REGULAR);
        
        $setName = "Default";
        foreach ($headings as $level => $heading)
        {
            //$setName .= '_' . $heading;
            $attributes[] = $this->setSCSAttributeValues($heading, $scsValues, $headings, $scsHeadings);
        }
        
        return $this->createAttributeSet($setName, $defaultSetId, $attributes);
    }
    
    protected function getSCSValues($level, $styles, &$scsValues)
    {
        foreach ($styles as $style)
        {
            $scsValues[$level][] = (string)$style->DESCRIPTION;
            
            if (isset($style->STYLE))
                $this->getSCSValues($level + 1, $style->STYLE, $scsValues);
        }
    }
    
    protected function setSCSAttributeValues($heading, $scsValues, $headings, &$scsHeadings) 
    {
        $newAttribute = $this->createAttribute($this->getSCSAttributeName($heading["heading"]), $heading["label"], "select", "");
        $attrId = $newAttribute->getId();
        
        foreach ($headings as $key => $value)
        {
            if ($value["heading"] == $heading["heading"])
                $headingLevel = $key;
        }
        
        $options = array();
        foreach ($scsValues as $level => $headingValues)
        {
            foreach ($headingValues as $order => $scsValue)
            {
                if ($level == $headingLevel)
                {   
                    if (!array_key_exists($level, $scsHeadings))
                        $scsHeadings[$level] = array('heading' => $heading["heading"], 'attrId' => $attrId, 'values' => array());
                    
                    if (!in_array($scsValue, $scsHeadings[$level]['values']))
                        $scsHeadings[$level]['values'][] = $scsValue;
                    
                    $options[] = array("value" => $scsValue, "order" => $order);
                }
            }
        }
        return $this->addOptionsToAttribute($this->getSCSAttributeName($heading["heading"]), $options);
    } 
    
    protected function addOptionsToAttribute($code, $values)
    {
        $attribute = $this->openAttribute($code);
        
        if (!$attribute->getId())
            return false;
        
        if ($attribute->getFrontendInput() == "select" || $attribute->getFrontendInput() == "multiselect")
        {        
            $existingOptions = $attribute->getSource()->getAllOptions(false);
            $options["attribute_id"] = $attribute->getId();
            $count = 1;
            
            foreach ($values as $index => $item)
            {
                if (!$this->validId($this->getOptionId($existingOptions, $item["value"])))
                {
                    $options["value"]["option_" . $count] = array($item["value"]);
                    $options["order"]["option_" . $count] = $item["order"];
                    $count++;
                }
            }   
            
            $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
            $setup->addAttributeOption($options);
            
            //This means it must of added at least 1 option.
            if ($count > 1) 
            {
                $attribute->save();
                return $this->openAttribute($code); //Re-open so it can use the options.
            }
        }
        return $attribute;
    }
    
    protected function openAttribute($code)
    {
        return Mage::getModel('eav/config')->getAttribute('catalog_product', $code);               
    }
    
    protected function getSCSAttributeName($heading)
    {
        //Map to existing values so they can just set the caption in Khaos and it will match existing attributes i.e Size
        return /*'scsattr_' . */strtolower($heading);
    }
    
    protected function getUDAAttributeName($udaName)
    {
        return 'udaattr_' . strtolower($udaName);
    }
    
    protected function getAttributeName($field)
    {
        return 'kc_' . strtolower($field);
    }
    
    protected function getOptionId($existingOptions, $code)
    {
        foreach ($existingOptions as $key => $value)
            if ($value['label'] == $code)
                return (int)$value['value'];
        
        return false;
    }

    protected function createAttribute($code, $label, $attribute_type, $product_type)
    {
        $spacelessCode = str_replace(" ", "_", $code);
        //Try and load the attribute first to see if it already exists. If so, return the object.
        $existingAttribute = Mage::getModel('eav/entity_attribute')
            ->getResourceCollection()
            ->addFieldToFilter('attribute_code', array(array('eq' => $code), array('eq' => $spacelessCode)))
            ->load()
            ->getFirstItem();
        
        //We originally created attributes with spaces in, try and "fix" them so they use _
        $existingAndHasSpaces = (($this->validId($existingAttribute->getId())) && (strpos($existingAttribute->getAttributeCode(), " ") != false));
        
        if (!$existingAndHasSpaces)
            if ($this->validId($existingAttribute->getId()))
                return $existingAttribute;
        
        $model = Mage::getModel('catalog/resource_eav_attribute');
        if ($existingAndHasSpaces)   
        {
            $model->setId($existingAttribute->getId()); //Updating existing record.
            $attributeData = array('attribute_code' => $spacelessCode);
        }
        else
        {
            //Doesn't exist so create a new one.
            $attributeData = array(
                'attribute_code' => $spacelessCode,
                'is_global' => '1',
                'frontend_input' => $attribute_type, //'boolean',
                'default_value_text' => '',
                'default_value_yesno' => '0',
                'default_value_date' => '',
                'default_value_textarea' => '',
                'is_unique' => '0',
                'is_required' => '0',
                'is_configurable' => '1',
                'is_searchable' => '0',
                'is_visible_in_advanced_search' => '0',
                'is_comparable' => '0',
                'is_used_for_price_rules' => '0',
                'is_wysiwyg_enabled' => '0',
                'is_html_allowed_on_front' => '1',
                'is_visible_on_front' => '0',
                'used_in_product_listing' => '0',
                'used_for_sort_by' => '0',
                'frontend_label' => $label,
                'is_filterable' => '0',
                'is_filterable_in_search' => '0'
            );
            
            if ($attribute_type == "multiselect")
                $attributeData["backend_model"] = "eav/entity_attribute_backend_array";
            
            if ($product_type != '')
                $attributeData['apply_to'] = array($product_type);
            
            if (is_null($model->getIsUserDefined()) || $model->getIsUserDefined() != 0) {
                $attributeData['backend_type'] = $model->getBackendTypeByInput($attributeData['frontend_input']);
            }
        }
        
        $model->addData($attributeData);
        $model->setEntityTypeId(Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId());
        $model->setIsUserDefined(1);
        try
        {
            $model->save();
            return $model;
        }
        catch(Exception $e)
        {
            throw($e);
            return false;
        }
    }
    
    protected function createAttributeSet($setName, $defaultSetId, $attributes)
    {
        $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
        $model = Mage::getModel('eav/entity_attribute_set');
        
        $entityTypeId = Mage::getModel('catalog/product')->getResource()->getTypeId();
        $setName = trim($setName);
        $groupName = 'General';//'SCSOptions';
        
        $setId = $setup->getAttributeSet($entityTypeId, $setName, 'attribute_set_id');
        if (!$this->validId($setId)) //Create new one to add attributes to.   
        {
            $model->setEntityTypeId($entityTypeId);
            $model->setAttributeSetName($setName);
            $model->validate();
            try
            {
                $model->save();
            }
            catch(Exception $ex)
            {
                return false;
            }

            if(($setId = $model->getId()) == false)
                return false;

            $model->initFromSkeleton($defaultSetId); //So we have access to all the core attributes required to set information.
            try
            {
                $model->save();
            }
            catch(Exception $ex)
            {
                return false;
            }
        }
        
        //Check for dupes and add the attributes.
        $newGroupId = $setup->getAttributeGroup($entityTypeId, $setId, $groupName, 'attribute_group_id');
        if (!$this->validId($newGroupId))
        {
            $setup->addAttributeGroup($entityTypeId, $setId, $groupName);
            $newGroupId = $setup->getAttributeGroup($entityTypeId, $setId, $groupName, 'attribute_group_id');
        }
        
        $this->addAttributesToGroup($setup, $attributes, $newGroupId, $setId, $entityTypeId);        
        return $setId;
    }
    
    protected function addAttributesToGroup($setup, $attributes, $groupId, $setId, $entityTypeId)
    {
        //Get a list of existing attributes in this group and stop insertion if they already exist.
        $attributesCollection = Mage::getResourceModel('catalog/product_attribute_collection');
        $attributesCollection->setAttributeGroupFilter($groupId);
        foreach ($attributesCollection as $attribute)
            $existingAttributes[] = $attribute->getId();
        
        //Add new attributes
        foreach ($attributes as $attribute)
        {
            if (is_object($attribute))
            {
                $doInsert = 
                    (!isset($existingAttributes)) ||
                    (is_array($existingAttributes) && !in_array($attribute->getId(), $existingAttributes));
                
                if ($doInsert === true)
                    $setup->addAttributeToGroup($entityTypeId, $setId, $groupId, $attribute->getId());
            }
        }
    }
    
    protected function saveStockItem($stockItem, $product, $type, $setId, $productData)
    {
        $mc = new Mage_Catalog_Model_Product_Api();
        $stockCode = (string)$stockItem->STOCK_CODE;
        $productId = false;
        
        if ($product)
            $productId = $product->getId();
        
        if ($this->validId($productId))
        {
            //SCS item in Khaos -> Simple item in Magento.
            if ($product->getTypeId() != $type)
                $productData["type_id"] = $type;
            
            $product->save();
            $mc->update($productId, $productData); //Save the information in the array.
        }
        else
        {
            $mc->create($type, $setId, $stockCode, $productData);
            $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $stockCode);
            $productId = $product->getId();
        }
        
        $this->setStockControlStatus($product, $stockItem, $type);
        return $productId;
    }
    
    protected function getProductDataArray($stockItem, $parentStockItem, $newItem, $currentWebsiteIds, $currentCategoryIds)
    {
        $stockCode = $this->getPropS($stockItem, 'STOCK_CODE');  
        $parentStockCode = $this->getPropS($parentStockItem, 'STOCK_CODE');  
        $isSCSChild = (!isset($stockItem->STOCK_DESC));
        $weight = $this->getPropS($stockItem, 'WEIGHT');
        $sellPrice = $this->getPropS($stockItem, 'SELL_PRICE');
        $productType = $this->getProductType($stockItem, '');
        $metaTitle = $this->getPropS($stockItem, 'META_TITLE');
        $metaDesc = $this->getPropS($stockItem, 'META_DESCRIPTION');
        $metaKeywords = $this->getPropS($stockItem, 'META_KEYWORDS');
        $vatRate = $this->getPropS($stockItem, 'VAT_RATE');
        
        $name = ($isSCSChild) ? 
            $this->getPropS($stockItem, 'DESCRIPTION') : 
            $this->getPropS($stockItem, 'STOCK_DESC');
        
        $longDesc = ($this->getPropS($stockItem, 'LONG_DESC') != '') ? 
            $this->getPropS($stockItem, 'LONG_DESC') : 
            $this->getPropS($parentStockItem, 'LONG_DESC');
        
        if ($longDesc == '') //Ensure required data is set to something.
            $longDesc = $this->getPropS($stockItem, 'STOCK_DESC');
        
        $shortDescription = ($this->getPropS($stockItem, 'WEB_TEASER') == '' && isset($parentStockItem->WEB_TEASER)) ? 
            $this->getPropS($parentStockItem, 'WEB_TEASER') : 
            $this->getPropS($stockItem, 'WEB_TEASER');
        if ($shortDescription == '')
            $shortDescription = $name; //Req field in Magento so make sure it's populated.
        
        $productWebsiteIds = array_filter(array_unique(array_merge($currentWebsiteIds, $this->getStockGroupCatIds($stockCode, 'website'), array($this->processingWebsiteId))), "strlen");
        $productData = array(
            'name'                              => $name,
            'websites'                          => $productWebsiteIds,
            'status'                            => 1,
            'weight'                            => $weight
        );
        
        if ($newItem) //Don't do this for existing products as we can only support Taxable / Non Taxable for this import so allow them to be updated in Magento without being changed.
        {
            $productData['tax_class_id'] = $vatRate == "0" ? 0 : 2; //0:None;2:Taxable Goods;4:Shipping            
        }
        
        if ($this->systemValues->getSysValue($this->systemValues->opUpdateStockDescriptions) == '-1')
        {
            $productData['short_description'] = $shortDescription;
            $productData['description'] = $longDesc;
        }
        
        //Don't override meta information if Khaos is empty.
        if ($metaTitle != "")
            $productData['meta_title'] = $metaTitle;
        
        if ($metaDesc != "")
            $productData['meta_description'] = $metaDesc;
        
        if ($metaKeywords != "")
            $productData['meta_keyword'] = $metaKeywords;
        
        if ($sellPrice != 0)
            $productData['price'] = $sellPrice;
        
        if ($newItem)
            $productData['news_from_date'] = date('Y-m-d');
        
        $productData['categories'] = array_merge($currentCategoryIds, $this->getStockGroupCatIds($isSCSChild ? $parentStockCode : $stockCode, 'cat')); 
        
        if ($parentStockItem === null) //$parentStockItem == null is a configurable item. $parentStockItem == false is a normal simple item.
        {
            $productData['has_options'] = 1;
            $productData['required_options'] = 1;            
        }
        else
        {
            $productData['has_options'] = 0;
            $productData['required_options'] = 0;            
        }
        
        if (!empty($productType) && $productType == 'bundle')
        {
            $productData['price_type'] = 1;
            $productData['price_view'] = 1;
        }
        
        if ($stockItem->USER_DEFINED != null)
        {
            foreach ($stockItem->USER_DEFINED as $uda)
            {
                $udaValue = (string)$uda;
                if ($udaValue != '')
                {
                    $udaName = (string)$uda->attributes()->NAME;
                    $type = isset($uda->attributes()->TYPE) ? $this->getAttributeTypeFromUDAType($uda->attributes()->TYPE) : "text";
                    $udaAttrName = str_replace(" ", "_", $this->getUDAAttributeName($udaName));
                    
                    switch ($type)
                    {
                        case "select":     
                        case "multiselect":
                            $attribute = $this->openAttribute($udaAttrName);
                            $options = $attribute->getSource()->getAllOptions(false);
                            if ($type == "select")
                                $productData[$udaAttrName] = $this->getOptionId($options, $udaValue);
                            else
                            {
                                foreach (explode(",", $udaValue) as $option)
                                {
                                    $productData[$udaAttrName][] = $this->getOptionId($options, str_replace('"', '', $option));
                                }
                            }
                            break;
                        case "boolean":
                            $productData[$udaAttrName] = $udaValue == "-1";
                            break;
                        default:
                            $productData[$udaAttrName] = $udaValue;
                            break;
                    }
                }
            }
        }
        
        list($harmCode, $harmDuty) = $this->getHarmonisationCode($stockItem->STOCK_CODE);
        if ($harmCode != '')
        {
            $productData['kc_harm_code'] = $harmCode;
            $productData['kc_harm_duty'] = $harmDuty;
        }
        
        if (!empty($this->additionalDataAsAttributes))
        {
            foreach ($this->additionalDataAsAttributes as $field => $type)
            {
                if ($this->getPropS($stockItem, $field) != '')
                {
                    switch ($type)
                    {
                        case "select":
                            $attribute = $this->openAttribute($this->getAttributeName($field));
                            $options = $attribute->getSource()->getAllOptions(false);
                            $productData[$this->getAttributeName($field)] = $this->getOptionId($options, $this->getPropS($stockItem, $field));
                            break;
                        default:
                            $productData[$this->getAttributeName($field)] = $this->getPropS($stockItem, $field);
                            break;
                    }
                }
            }
        }
        
        $productData[$this->getAttributeName("kc_last_updated")] = date('Y-m-d H:i:s');
        if (array_key_exists(strtolower($stockCode), $this->exportedStockStatus))
            $productData[$this->getAttributeName("due_date")] = $this->exportedStockStatus[strtolower($stockCode)]['due'];
        return $productData;
    }
    
    protected function getStockGroupCatIds($stockCode, $type)
    {
        $resultIds = array();
        foreach ($this->stockCategoryList as $key => $value)
        {
            foreach ($value['stockItemCategories'] as $stockCodeKey => $subValue)
            {
                if ($stockCodeKey == $stockCode)
                {
                    if ($type == 'website')
                    {
                        $id = $value['websiteId'];
                        if ($this->systemValues->getSysValue($this->systemValues->opUpdateStockWebsite . $id) == '-1')
                            $resultIds[] = $id;
                        break; //We know it's in this group now, so no point continuing. Can't do this with cat's as we need a full list.
                    }
                    else
                    {
                        foreach ($subValue['catId'] as $catId)
                            if (!in_array($catId, $resultIds))
                                $resultIds[] = $catId;
                    }
                }
            }
        }
        return $resultIds;
    }
    
    protected function getProductDataArraySCSChild($stockData, $stockItem, $scsValues, $parentStockItem, $newItem, $scsHeadings)
    {
        foreach ($scsValues as $level => $scsValue)
        {
            $heading = $this->getSCSHeading($scsValue, $level, $scsHeadings);
            $attrCode = $this->getSCSAttributeName($heading);
            
            $attribute = Mage::getModel('eav/entity_attribute')
                ->getResourceCollection()
                ->setCodeFilter($attrCode)
                ->load()
                ->getFirstItem();
            $existingOptions = $attribute->getSource()->getAllOptions(false);
            $stockData[$attrCode] = $this->getOptionId($existingOptions, $scsValue);
        }
        
        if ($newItem)
            $stockData['visibility'] = Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE;
        
        $stockData[$this->getAttributeName("parent_stock_code")] = $this->getPropS($parentStockItem, "STOCK_CODE");
        $stockData[$this->getAttributeName("manufacturer")] = $this->getPropS($parentStockItem, "MANUFACTURER");
        
        return $stockData;
    }
    
    protected function getSCSHeading($scsValue, $scsLevel, $scsHeadings) 
    {
        foreach ($scsHeadings as $level => $headings)
        {
            foreach ($headings['values'] as $value)
            {
                if (($value == $scsValue) && ($level == $scsLevel))
                    return $headings['heading'];
            }
        }
    }
    
    protected function getConfigurableAttributeProductData($product, $existingConfigAttributes, $stockItem, $childProductIds, $scsHeadings)
    {
        $configData = array();
        
        foreach ($scsHeadings as $level => $headings)
        {
            if (!in_array($headings['attrId'], $existingConfigAttributes))
            {
                $attrCode = $this->getSCSAttributeName($headings['heading']);
                
                if ($attrCode == "")
                    Mage::throwException('Caption for SCS Profile is required.');
                
                $attribute = Mage::getModel('eav/entity_attribute')
                    ->getResourceCollection()
                    ->setCodeFilter($attrCode)
                    ->load()
                    ->getFirstItem();

                $existingOptions = $attribute->getSource()->getAllOptions(false);
                $optionValues = array();
                
                foreach ($existingOptions as $key => $value)
                {
                    $childStockCode = $this->getSCSStockCodeFromSCSAttributesXml($stockItem->SCS, $value['label']);
                    if ($childStockCode && in_array($childStockCode, $this->scsPriceMapping)) //Could be excluded because of $this->stockItemAllowed() check.
                        $optionValues[] = array(
                            'attribute_id' => $attribute->getId(),
                            'label' => $value['label'],
                            'value_index' => $value['value'],
                            'is_percent' => 0,
                            'pricing_value' => $this->scsPriceMapping[$childStockCode]
                    );
                }

                $configData[$level] = array (
                    'label' => $headings['heading'], 
                    'values' => $optionValues,
                    'attribute_id' => $headings['attrId'], 
                    'attribute_code' => $attrCode, 
                    'frontend_label' => $headings['heading'],
                    'html_id' => 'config_super_product__attribute_0'
                );
            }
        }        
        return $configData;
    }
    
    protected function getSCSStockCodeFromSCSAttributesXml($styles, $scsValue)
    {
        foreach ($styles->STYLE as $style)
        {
            if ($style->DESCRIPTION == $scsValue)
                if (isset($style->SCS_ITEM))
                    return $this->getPropS($style->SCS_ITEM, 'STOCK_CODE');
            
            if (isset($style->STYLE))
                return $this->getSCSStockCodeFromSCSAttributesXml($style->STYLE, $scsValue);
        }       
        return false;
    }
    
    protected function makeAttributeCode($var)
    {
        $result = preg_replace("/[^A-Za-z0-9]/", '_', $var);
        return $result;
    }
    
    protected function syncDefaultPrices($storeId)
    {
        $storeCount = 0;
        $storePriceIncTax = Mage::getStoreConfig('tax/calculation/price_includes_tax') == "1";
        
        foreach (Mage::app()->getWebsites() as $website)
        {
            foreach ($website->getStores() as $store)
            {
                $storeCount++;
            }
        }
        
        $this->setAction("Sync Default Price Lists");
        $this->setCode("");
        
        //Do this so we get a price showing the grid if the stock item doens't have a price in Khaos but the price list does.
        $setStoreId = $storeId;
        if ($storeCount == 1)
            $setStoreId = Mage_Core_Model_App::ADMIN_STORE_ID;
        
        $defaultPriceList[] = $this->systemValues->getSysValue($this->systemValues->rcDefaultPriceList . $storeId); 
        if ($defaultPriceList[0] != '')
        {
            $groupsXml = Mage::helper("khaosconnect/webservice")->exportPriceLists($this->arrayToCSV($defaultPriceList), '3');
            
            foreach ($groupsXml[0] as $groupXml) //Having to use [0] as SimpleXml doesn't like "StockItem-CustomerClassifications".
            {               
                $stockCodesOnPriceList = array();
                $sql = "insert into tmp_product_import values ";
                $pricelistIsNet = (string)$groupXml->attributes()->PricelistNet == "-1";
                
                foreach ($groupXml->StockItem as $stockPriceItem)
                {
                    $taxRate = (float)$stockPriceItem->TaxRate / 100 + 1;                    
                    $stockCode = (string)$stockPriceItem->StockCode;
                    
                    if (!in_array($stockCode, $stockCodesOnPriceList))
                    {
                        $stockCodesOnPriceList[] = $stockCode;
                        $price = (float)$stockPriceItem->CalculatedPrice;
                        
                        //TODO: Read proper tax values.
                        if ($pricelistIsNet && $storePriceIncTax)
                            $price = $price * $taxRate;
                        else if (!$pricelistIsNet && !$storePriceIncTax)
                            $price = $price / $taxRate;
                        
                        $sql .= "(" . $this->writeDB->quote($stockCode) . "," . $this->writeDB->quote($price) . "),";
                    }               
                }
                
                //Remove last comma
                $sql = substr($sql, 0, strlen($sql) - 1);
                
                if ($this->validArray($stockCodesOnPriceList))
                {
                    $tempTableSql = "CREATE TEMPORARY TABLE tmp_product_import (sku VARCHAR(64) NOT NULL PRIMARY KEY, price DECIMAL(12, 4)) COLLATE utf8_general_ci;";
                    $this->writeDB->query('DROP TEMPORARY TABLE IF EXISTS tmp_product_import'); //Doing this instead of truncate as table won't exist initially and there isn't an "IF EXISTS" for truncate.
                    $this->writeDB->query($tempTableSql);
                    $this->writeDB->query($sql);
                    $mainSql = 
                        "INSERT INTO " .
	                    $this->resource->getTableName('catalog_product_entity_decimal') . " " .
	                    "( " .
		                    "entity_type_id, " .
		                    "attribute_id, " .
		                    "store_id, " .
		                    "entity_id, " .
		                    "value " .
	                    ") " .
	                    "SELECT " .
		                    "entity.entity_type_id as entity_type_id, " .
		                    "(select attribute_id from " . $this->resource->getTableName('eav_attribute') . " where entity_type_id = '4' and attribute_code = 'price') as attribute_id, " .
		                    $this->writeDB->quote($setStoreId) . " as store_id, " .
		                    "entity.entity_id as entity_id, " .
		                    "tmp_product_import.price as value " .
	                    "FROM " .
		                    "tmp_product_import " .
		                    "INNER JOIN " . $this->resource->getTableName('catalog_product_entity') . " as entity " .
			                    "ON entity.sku = tmp_product_import.sku " .
                        "ON " .
	                        "DUPLICATE KEY UPDATE value = VALUES(value)";
                    $this->writeDB->query($mainSql);
                }
                
                //Disable any items in this store that don't have a price in the price list
                $disableSql = 
                    "update " .
                    $this->resource->getTableName('catalog_product_entity_int') . " " .
                    "inner join " . $this->resource->getTableName('eav_entity_type') . " as eet " .
                           " on eet.entity_type_code = 'catalog_product' " .
                    "inner join " . $this->resource->getTableName('eav_attribute') . " as ea " . 
                           " on ea.attribute_code = 'status' and " . 
                              $this->resource->getTableName('catalog_product_entity_int') . ".attribute_id = ea.attribute_id and " . 
                              "ea.entity_type_id = eet.entity_type_id " .
                    "inner join " . $this->resource->getTableName('catalog_product_entity') . " as cpe " .
                           "on cpe.entity_id = " . $this->resource->getTableName('catalog_product_entity_int') . ".entity_id " .
                    "SET value = '" . Mage_Catalog_Model_Product_Status::STATUS_DISABLED . "' " .
                    "where " .
                      $this->resource->getTableName('catalog_product_entity_int') . ".store_id = $storeId and " . 
                      "sku NOT IN (" . $this->writeDB->quote($stockCodesOnPriceList) . ")";
                $this->writeDB->query($disableSql);
            }
        }
        
        $this->systemValues->setSysValue($this->systemValues->rcDefaultPriceListLastSyncPrefix . $storeId, time());
    }   
    
    protected function syncSpecialOfferPrices($storeId)
    {
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        
        $defaultPriceList = $this->systemValues->getSysValue($this->systemValues->rcDefaultPriceList . $storeId); 
        $specialOffersData = Mage::helper('khaosconnect/webservice')->getSpecialOffers();
        
        if ($specialOffersData && isset($specialOffersData->SPECIAL_OFFERS))
        {
            foreach ($specialOffersData->SPECIAL_OFFERS as $specialOffers)
            {
                if (isset($specialOffers->SPECIAL_OFFER))
                {
                    foreach ($specialOffers->SPECIAL_OFFER as $specialOffer)
                    {
                        $stockCode = $this->getPropS($specialOffer, "STOCK_CODE");
                        $companyClass = $this->getPropS($specialOffer, "COMPANY_CLASS");
                        $startDate = $this->getPropS($specialOffer, "START_DATE");
                        $endDate = $this->getPropS($specialOffer, "END_DATE");
                        $active = $this->getPropS($specialOffer, "ACTIVE") == -1;
                        $webUse = $this->getPropS($specialOffer, "WEB_USE") == -1;
                        $salePrice = $this->getPropS($specialOffer, "SELL_PRICE");
                        
                        $process = (
                            $active &&
                            $webUse && 
                            ((strtolower($companyClass) == strtolower($defaultPriceList)) || ((strtolower($companyClass) == 'unknown') && ($defaultPriceList == ''))) &&
                            (strtotime($endDate) >= time())
                            );
                        
                        if ($process)
                        {
                            $product = Mage::getModel('catalog/product');
                            $product->load($product->getIdBySku($stockCode))->setStoreId($storeId);
                            if ($product->getId())
                            {   
                                $oldPrice = $product->getSpecialPrice();
                                $newPrice = $salePrice; 
                                if ($oldPrice != $newPrice) {
                                    $product->setSpecialPrice($newPrice);
                                    $product->setSpecialFromDate($startDate);
                                    $product->setSpecialFromDateIsFormated(true);
                                    $product->setSpecialToDate($endDate);
                                    $product->setSpecialToDateIsFormated(true);
                                    $product->save();
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    protected function getStockStatus($stockCodes)
    {
        $this->setStockControlSiteId();
        
        $this->rootCategoryName = $this->systemValues->getSysValue($this->systemValues->rcPrefix . $this->processingStoreId);
        $stockCodesIncChildren = $this->getAllStockCodesIncludingChildren($stockCodes);
        $completedWithErrors = false;
        

        $processAtOnce = 50;
        $codeCount = count($stockCodesIncChildren);
        $count = 0;
        $iterations = ($codeCount > 0 && $codeCount < $processAtOnce) 
            ? 1
            : ceil($codeCount / $processAtOnce);
        
        for ($i = 0; $i < $iterations; $i++)
        {
            try
            {
                $processCodes = array_slice($stockCodesIncChildren, $i * $processAtOnce, $processAtOnce);
            
                $stockLevelsXml = Mage::helper("khaosconnect/webservice")->exportStockStatus($processCodes, $this->stockControlSiteId);
                if ($stockLevelsXml)
                {
                    if (count($stockLevelsXml->STOCK_ITEM) > 0)
                    {
                        foreach ($stockLevelsXml->STOCK_ITEM as $stockItem)
                        {
                            $this->exportedStockStatus[strtolower($stockItem->attributes()->CODE)] = array(
                                'level' => (int)$this->getPropS($stockItem, "LEVEL") + (int)$this->getPropS($stockItem, "BUILD_POTENTIAL_CHILDREN"),
                                'status' => $this->getPropS($stockItem, "STATUS"),
                                'due' => $this->getPropS($stockItem, "LEAD_TIME")
                            );
                        }
                    }
                }
            }
            catch (Exception $e)
            {
                $completedWithErrors = true;
                $message = "GetStockStatus Error for stock codes: [". $this->arrayToCSV($processCodes) ."] :: [". $e->getMessage() ."]";
                $this->logStock($e, $this->processingStoreId, "", $message);
                //throw $e;
            }
        }
        
        if ($completedWithErrors)
            throw new Exception("Completed with errors. Please check the logs.");
        else
            $this->logStock(null, $this->processingStoreId, "", "GetStockStatus for [" . count($stockCodesIncChildren) . "] codes successfull.");
        
    }
    
    protected function getAllStockCodesIncludingChildren($stockCodes)
    {
        $return = array();
        if ($this->exportedStockItems != null)
        {
            foreach ($this->exportedStockItems as $stockItem)
            {
                $stockCode = $this->getPropS($stockItem, 'STOCK_CODE');
                if (in_array(strtolower($stockCode), $stockCodes) || in_array(strtoupper($stockCode), $stockCodes))
                {
                    $return[] = $stockCode;
                    if (isset($stockItem->SCS))
                    {
                        foreach ($stockItem->SCS->STYLE as $style)
                        {
                            $this->findSCSStockCodeInStyle($style, $return);
                        }
                    }
                }
            }
            return $return;
        }
        else
            return $stockCodes;
    }
    
    protected function findSCSStockCodeInStyle($style, &$return)
    {
        if (isset($style->SCS_ITEM))
        {
            foreach ($style->SCS_ITEM as $scsItem)
            {
                $return[] = $this->getPropS($scsItem, 'STOCK_CODE');    
            }
        }
        else if (isset($style->STYLE))
        {
            foreach ($style->STYLE as $newStyle)
            {
                $this->findSCSStockCodeInStyle($newStyle, $return); 
            }
        }
    }
    
    public function syncAllStockLevels()
    {
        $this->setAction("Sync All Stock Levels");
        $this->setCode("");
        
        $stockCodes = array();
        $backorderStatus = $this->getBackOrderStatusArray();
        
        $collection = Mage::getModel('catalog/product')->getCollection();
        foreach ($collection as $product)
        {
            $stockCode = strtolower($product->getSku());
            $stockCodes[] = strtolower($stockCode);
            $productIds[$stockCode] = $product->getId();
        }
        
        $this->getStockStatus($stockCodes);
        $globalManageStock = Mage::getStoreConfig('cataloginventory/item_options/manage_stock') == "1";
        
        if (!empty($this->exportedStockStatus))
        {
            $this->writeDB->beginTransaction();
            foreach ($this->exportedStockStatus as $stockCode => $status)
            {
                if (array_key_exists(strtolower($stockCode), $productIds))
                {
                    $allowBackOrder = false;
                    if (array_key_exists($stockCode, $backorderStatus))
                        $allowBackOrder = $backorderStatus[$stockCode] == Mage_CatalogInventory_Model_Stock::BACKORDERS_YES_NOTIFY || $backorderStatus[$stockCode] == Mage_CatalogInventory_Model_Stock::BACKORDERS_YES_NONOTIFY;
                    
                    $stockCheck = ($status["level"] > 0) || ($status["status"] == "5") || !$globalManageStock || $allowBackOrder;
                    $isInStock = $stockCheck ? "1" : "0";
                    $productId = $this->writeDB->quote($productIds[strtolower($stockCode)]);
                    
                    $sql = "update " .
                        $this->resource->getTableName('cataloginventory_stock_status') . ", " .
                        $this->resource->getTableName('cataloginventory_stock_item') . " " .
                        "set " .
                        $this->resource->getTableName('cataloginventory_stock_status') . ".stock_status = '1', " . //" . $isInStock . "', " .
                        $this->resource->getTableName('cataloginventory_stock_item') . ".is_in_stock = '" . $isInStock . "', " .
                        $this->resource->getTableName('cataloginventory_stock_item') . ".qty = " . $this->writeDB->quote($status["level"]) . " " .
                        "where " .
                        $this->resource->getTableName('cataloginventory_stock_status') . ".product_id = " . $productId . " and " .
                        $this->resource->getTableName('cataloginventory_stock_item') . ".product_id = " . $productId;
                    $this->writeDB->query($sql);
                }
            }
            $this->writeDB->commit();
        }
    }
    
    protected function getBackOrderStatusArray()
    {
        $result = array();
        
        $sql = 
            "select " .
            "p.sku, pi.backorders " .
            "from " .
            $this->resource->getTableName('catalog_product_entity') . " as p " .
            "inner join " . $this->resource->getTableName('cataloginventory_stock_item') . " as pi on pi.product_id = p.entity_id";
        
        $query = $this->readDB->query($sql);
        if ($query->rowCount() == 0)
            return $result;
        
        while ($row = $query->fetch())
        {
            $result[strtolower($row['sku'])] = $row['backorders'];   
        }
        
        return $result;
    }
}

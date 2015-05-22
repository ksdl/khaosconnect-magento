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

class Ksdl_Khaosconnect_Helper_Category extends Ksdl_Khaosconnect_Helper_Basehelper
{
    private $WebsiteName;
    private $RootNodeID;
    private $CatTree = array();
    private $MagentoCatTree = array();
    private $tableName;
    private $stockCategoryArray = array();
    
    function __construct() {
        parent::__construct();
        $this->tableName = $this->resource->getTableName('khaosconnect_catalog_category_link');
    }
    
    public function getCategory($Param, $ParamType = 'name') 
    {
        $Collection = Mage::getModel('catalog/category')->getCollection()
        ->setStoreId('0')
        ->addAttributeToSelect(array('id' => 'entity_id'))
        ->addAttributeToSelect('name')
        ->addAttributeToSelect('is_active');
        foreach ($Collection as $Cat) {
            if (($ParamType == 'name' && $Cat->getName() == $Param) || ($ParamType == 'id' && $Cat->getId() == $Param)) {
                return $Cat;
            }
        }
    }
    
    public function getCategoryId($name)
    {
        $Cat = $this->getCategory($name);
        if ($Cat)
            return $Cat->getId();
        else
            return false;
    }
    
    protected function ConvertKToMCategory($KCategory, $ParentID)
    {
        $Cat["ID"] = (string)$KCategory->attributes()->ID;
        $Cat["NAME"] = (string)$KCategory->attributes()->NAME;
        $Cat["FILE_NAME"] = (string)$KCategory->attributes()->FILE_NAME;
        $Cat["LONG_DESC"] = (string)$KCategory->attributes()->LONG_DESC;
        $Cat["SHORT_DESC"] = (string)$KCategory->attributes()->SHORT_DESC;
        
        //Array seems to have some empty elements. Must be something wrong with simplexml_load_string.
        if ($KCategory->attributes()->NAME != '')
            return $this->UpdateCategory($Cat, $ParentID);
    }
    
    private  function GetFullPath($CatID, $AddCurrentCat = true)
    {
        //$AddCurrentCat is used becuase Magento will auto add the category ID of the item being inserted. Not using this means we end up with the category ID there 2 times.

        $Cat = $this->getCategory($CatID, 'id');
        if ($Cat->getParentId())
        {
            $ParentPath = $this->GetFullPath($Cat->getParentId(), false);
            $CurrentCat = ($AddCurrentCat) ? "/" . $CatID : "";
            $Result = ($ParentPath) ? $ParentPath . "/" . $Cat->getParentId() . $CurrentCat : $Cat->getParentId() . $CurrentCat;
            return $Result;
        }
    }
    
    protected function GetExistingMCatID($KCatID)
    {
        $sql = "select entity_id from $this->tableName where category_id = '{$KCatID}'";
                
        $query = $this->readDB->query($sql);
        if ($query->rowCount() == 0)
            return false;
        
        while ($row = $query->fetch())
        {
            return $row['entity_id'];
        }        
    }
    
    protected function CategoryExists($KCatID)
    {
        if (array_key_exists($KCatID, $this->MagentoCatTree) && is_array($this->MagentoCatTree[$KCatID]))
            return $this->MagentoCatTree[$KCatID]['ID'];
        else //Not in the array (based on Name) - Check to see if the entry is in the DB in case of name change.
            return $this->GetExistingMCatID($KCatID);
    }
    
    protected function InsertKCatMCatLink($EntityID, $KCatID, $Path)
    {
        $sql = "insert into $this->tableName (entity_id, category_id, path) values ('{$EntityID}', '{$KCatID}', '{$Path}')";
        $this->writeDB->query($sql);
    }

    protected function UpdateCategory($ValuesArr, $ParentID)
    {
        $ExistingCatID = $this->CategoryExists((string)$ValuesArr['ID']);
        
        $MCategory = new Mage_Catalog_Model_Category();
        if ($ExistingCatID)
            $MCategory = Mage::getModel('catalog/category')->load($ExistingCatID);
        
        $MCategory->setName($ValuesArr["NAME"]);
        
        $MCategory->setIsActive(1);
        //$MCategory->setDisplayMode('PRODUCTS');
                
        if (!$ExistingCatID)
        {
            $FullPath = ($ParentID == '1') ? $ParentID : $this->GetFullPath($ParentID);
            $MCategory->setPath($FullPath);
        }
        
        $MCategory->save();
        $ResultID = $MCategory->getId();
        
        if (!$ExistingCatID)
            $this->InsertKCatMCatLink($ResultID, $ValuesArr['ID'], $FullPath);
        
        unset($MCategory);
        return $ResultID;
    }
    
    protected function RemoveCategory($Name)
    {
        $Cat = $this->getCategory($Name);
        $Cat->delete();
    }
    
    protected function RecurseCategory($kCategories, $parentId)
    {
        if ($parentId)
        {
            foreach($kCategories as $kCategory)
            {
                if ($kCategory)
                {
                    $catId = $this->ConvertKToMCategory($kCategory, $parentId);
                    $this->updateStockCategoryArray($kCategory, $catId);
                    if (count($kCategory->CATEGORY) > 0)
                    {
                        $this->RecurseCategory($kCategory, $catId);
                    }
                }
            }
        }
    }
    
    protected function BuildCategoryTree($kCategories, $catTree, $count)
    {
        foreach($kCategories as $kCategory)
        {
            if ($kCategory)
            {
                if ((string)$kCategory->attributes()->ID != "")
                {
                    if ($count == 0)
                        $NewCatTree = $this->WebsiteName . "~" . $kCategory->attributes()->NAME;
                    else
                        $NewCatTree = $catTree . "~" . $kCategory->attributes()->NAME;
                
                    if (count($kCategory->CATEGORY) > 0)
                    {
                        $this->BuildCategoryTree($kCategory, $NewCatTree, $count+1);
                    }
                    $this->CatTree[(string)$kCategory->attributes()->ID] = $NewCatTree;
                }
            }
        }
    }
    
    protected function updateStockCategoryArray($kCategory, $catId)
    {
        foreach ($kCategory->STOCK_ITEM as $stockItem)
        {
            $this->stockCategoryArray[(string)$stockItem->STOCK_CODE]['catId'][] = (int)$catId;
            $this->stockCategoryArray[(string)$stockItem->STOCK_CODE]['scsChild'] = '0';
            
            if (isset($stockItem->SCS))
            {
                foreach ($stockItem->SCS->STOCK_CODE as $childStockCode)
                {
                    $this->stockCategoryArray[(string)$childStockCode]['catId'][] = (int)$catId;
                    $this->stockCategoryArray[(string)$childStockCode]['scsChild'] = '1';
                }
            }
        }
    }
    
    protected function RecurseMagentoCategoryTree($ParentCatID, $CatTree, $Count)
    {
        $MCatTable = $this->resource->getTableName('catalog_category_entity');
        $attributeTable = $this->resource->getTableName('eav_attribute');
        $MCatVarcharTable = $this->resource->getTableName('catalog_category_entity_varchar');
        
        $SQL = 
            "select c.entity_id as Id, cv.value as CatName " .
            "from " .
            "$MCatTable as c " .
            "inner join $MCatVarcharTable as cv on c.entity_id = cv.entity_id " .
            "where " .
            "c.parent_id = $ParentCatID and " .
            "cv.attribute_id = ( " .
                "select attribute_id from $attributeTable where attribute_code = 'name' and entity_type_id = 3" .
            ")";
                
        $query = $this->readDB->query($SQL);
        if ($query->rowCount() == 0)
            return;
        
        while ($row = $query->fetch())
        {
            $NewCatTree = $CatTree . "~" . $row['CatName'];
            $this->RecurseMagentoCategoryTree($row['Id'], $NewCatTree, $Count + 1);
            if (array_search($NewCatTree, $this->CatTree))
                $this->MagentoCatTree[array_search($NewCatTree, $this->CatTree)] = array("ID" => $row['Id'], "CATTREE" => $NewCatTree);
        }
    }
    
    public function SyncWebCategories($webCatObj)
    {
        $this->WebsiteName = (string)$webCatObj->attributes()->NAME;
        $this->RootNodeID = $this->getCategoryId($this->WebsiteName);
        
        if (!$this->RootNodeID)
        {
            $Cat["NAME"] = $this->WebsiteName;
            $Cat["ID"] = (string)$webCatObj->attributes()->ID;
            $this->UpdateCategory($Cat, '1');
            $this->RootNodeID = $this->getCategoryId($this->WebsiteName);
        }
        
        $this->BuildCategoryTree($webCatObj->CATEGORY, '', 0); //Need to build the list of categories for matching before inserting.    
        $this->RecurseMagentoCategoryTree($this->RootNodeID, $this->WebsiteName, 0);
        $this->RecurseCategory($webCatObj->CATEGORY, $this->RootNodeID);        
        return $this->stockCategoryArray;
        
    }
    
}
	 
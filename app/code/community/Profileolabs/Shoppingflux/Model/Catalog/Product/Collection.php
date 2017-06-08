<?php

class Profileolabs_Shoppingflux_Model_Catalog_Product_Collection extends Mage_Catalog_Model_Resource_Eav_Mysql4_Product_Collection
{
    public function getSize()
    {
        if (is_null($this->_totalRecords)) {
            $sql = $this->getSelectCountSql();
            $result = $this->getConnection()->fetchAll($sql, $this->_bindParams);

            foreach ($result as $row) {
                $this->_totalRecords += reset($row);
            }
        }
        return intval($this->_totalRecords);
    }
}

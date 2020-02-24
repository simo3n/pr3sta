<?php
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php');
include(dirname(__FILE__) . '/init.php');

$res = Db::getInstance()->executeS('SELECT `id_product` FROM `'._DB_PREFIX_.'product` ORDER BY `id_product` DESC LIMIT 5 ');
echo "<p>(".date('Y/m/d H:i:s').") Starting to delete products...</p>";
if ($res) {
    foreach ($res as $row) {
        echo "<p>(".date('Y/m/d H:i:s').") Deleting product with ID <b>".$row['id_product']."</b>...";
        $p = new Product($row['id_product']);
        if(!$p->delete()) {
            echo " <span style='color: red'>Error deleting this product!</span></p>";
        } else {
            echo " <span style='color: green'>DELETED</span></p>";
        }
    }
}
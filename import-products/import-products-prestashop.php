<?php

//DEBUG
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
define('DEBUG', false);

//Staging database
$hostname = "";
$dbname = "";
$user = "";
$pass = "";

//Source prestashop database to retrieve `reference` products fields
$psdb_hostname = "";
$psdb_dbname = "";
$psdb_username = "";
$psdb_password = "";


// Source Prestashop webservice to read product details
// Product resource must be enabled on GET 
define('PS_SHOP_PATH_SOURCE', 'here/source/prestashop/url');
define('PS_WS_AUTH_KEY_SOURCE', 'here/key/source/prestashop');

// Destination Prestashop webservice to insert product details 
// Product resource must be enabled on GET and PUT
define('PS_SHOP_PATH_DEST', 'here/destination/prestashop/url');
define('PS_WS_AUTH_KEY_DEST', 'here/key/destination/prestashop');

require_once('lib/PSWebServiceLibrary.php');

try {
    $stg_db = new PDO("mysql:host=$hostname;dbname=$dbname", $user, $pass);
} catch (PDOException $e) {
    echo "Database error:" . $e->getMessage();
    exit(1);
}

//import from prestashop to staging db
function readProduct($product_code)
{
    global $stg_db;
    $url = PS_SHOP_PATH_SOURCE . 'api/products?display=full&filter[reference]=' . $product_code;

    $request1 = curl_init();
    curl_setopt($request1, CURLOPT_URL, $url);
    curl_setopt($request1, CURLOPT_USERPWD, PS_WS_AUTH_KEY_SOURCE . ':');
    curl_setopt($request1, CURLOPT_RETURNTRANSFER, true);
    $response1  = curl_exec($request1);
    curl_close($request1);

    if ($response1 === false) {
        echo "Error : " . curl_error($request1) . "\n";
        return;
    } else {

        $xml = new SimpleXMLElement($response1);
        $resources = $xml->children()->children()->children();

        $sql = "INSERT INTO products(code, name, id_lang_pn, long_desc, id_lang_ld, short_desc, id_lang_sd, is_active) VALUES (:code, :name, :id_lang_pn, :long_desc, :id_lang_ld, :short_desc, :id_lang_sd, :is_active)";

        $stmt = $stg_db->prepare($sql);

        $is_active = '';
        $product_name = '';
        $id_lang_product_name = '';
        $long_description = '';
        $id_lang_long_description = '';
        $short_description = '';
        $id_lang_short_description = '';

        $stmt->bindParam(':code', $product_code, PDO::PARAM_STR);
        $stmt->bindParam(':name', $product_name, PDO::PARAM_STR);
        $stmt->bindParam(':id_lang_pn', $id_lang_product_name, PDO::PARAM_STR);
        $stmt->bindParam(':long_desc', $long_description, PDO::PARAM_STR);
        $stmt->bindParam(':id_lang_ld', $id_lang_long_description, PDO::PARAM_STR);
        $stmt->bindParam(':short_desc', $short_description, PDO::PARAM_STR);
        $stmt->bindParam(':id_lang_sd', $id_lang_short_description, PDO::PARAM_STR);
        $stmt->bindParam(':is_active', $is_active, PDO::PARAM_STR);

        $is_active = (string) $resources->active;
        $product_name = (string) $resources->name->language[0]; //nome prodotto
        $id_lang_product_name = (string) $resources->name->language[0]['id'];
        $long_description = (string) $resources->description->language[0]; //descrizione lunga
        $id_lang_long_description = (string) $resources->description->language[0]['id'];
        $short_description = (string) $resources->description_short->language[0]; //descrizione corta
        $id_lang_short_description = (string) $resources->description_short->language[0]['id'];

        echo "Processing: *$product_code* -> Product name: $product_name \n";

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            echo "Error:" . $e->getMessage();
            exit(2);
        }
    }
}


//Starting processing...

echo "Starting processing at " . date('Y-m-d H:i:s') . "\n\n";
echo "Starting import to staging database.. \n\n";
try {
    $sourceDb = new PDO("mysql:host=$psdb_hostname;dbname=$psdb_dbname", $psdb_username, $psdb_password);
    $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Some error occured. Exiting at " . date('Y-m-d H:i:s') . "\n";

    exit;
}

//retrieve reference product field and call readProduct to insert products on staging db
$stmt = $sourceDb->query("SELECT reference FROM ps_product");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $code = $row['reference'];
    readProduct($code);
}

echo '\nImport to staging db completed at ' . date('Y-m-d H:i:s') . "\n";

echo '\nStarting import from staging db to prestashop destination at ' . date('Y-m-d H:i:s') . "\n\n";

$stmt_stg_db = $stg_db->query("SELECT * FROM products");

while ($row = $stmt_stg_db->fetch(PDO::FETCH_ASSOC)) {

    $code = $row['code'];
    $product_name  = $row['name'];
    $id_lang_product_name = $row['id_lang_pn'];
    $long_description = $row['long_desc'];
    $id_lang_long_description = $row['id_lang_ld'];
    $short_description = $row['short_desc'];
    $id_lang_short_description = $row['id_lang_sd'];
    $is_active = $row['id_active'];

    try {
        $webService = new PrestaShopWebservice(PS_SHOP_PATH_DEST, PS_WS_AUTH_KEY_DEST, DEBUG);

        // call webservice to get id product by reference code
        $opt = array('resource' => 'products', 'display' => 'full', 'filter[reference]' => "$code");
        $xml = $webService->get($opt);
        $resources = $xml->children()->children()->children();
        $idPresta = $resources->id;

        //call webservice to get xml by id
        $opt = array('resource' => 'products', 'id' => "$idPresta");
        $xml = $webService->get($opt);
        $resources = $xml->children()->children();

        unset($resources->quantity);
        unset($resources->manufacturer_name); 
        unset($resources->associations->categories);

        $resources->active = $is_active;
        $resources->name->language[0] = $product_name; //product name
        $resources->name->language[0]['id'] = $id_lang_product_name;
        $resources->description->language[0] = $long_description; //long description
        $resources->description->language[0]['id'] = $id_lang_long_description;
        $resources->description_short->language[0] = $short_description; //short description
        $resources->description_short->language[0]['id'] = $id_lang_short_description;

        //call webservice to update product
        $opt = array('resource' => 'products');
        $opt['putXml'] = $xml->asXML();
        $opt['id'] = (string)$idPresta;
        $xml = $webService->edit($opt);

        echo "Product $idPresta updated.\n";
        
    } catch (PrestaShopWebserviceException $e) {
        // Here we are dealing with errors
        $trace = $e->getTrace();
        if ($trace[0]['args'][0] == 404) echo "Bad ID\n";
        else if ($trace[0]['args'][0] == 401) echo "Bad auth key\n";
        else echo "Other error: " . $e->getMessage() . "\n";
    }
}

echo '\nImport from staging db to prestashop destination completed at '. date('Y-m-d H:i:s') .' All done.';

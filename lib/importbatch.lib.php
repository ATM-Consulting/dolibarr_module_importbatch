<?php
/* Copyright (C) 2021 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    importbatch/lib/importbatch.lib.php
 * \ingroup importbatch
 * \brief   Library files with common functions for ImportBatch
 */

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
/**
 * @param DoliDB $db
 * @param string $filePath  Typically path to uploaded CSV file
 * @param string $srcEncoding
 * @param string $importKey
 * @return array|null  Array with
 */
function ibGetBatchSerialFromCSV($db, $filePath, $srcEncoding = 'latin1', $importKey='ecImportBatchLot') {

	/*
	Import / lot série (création et mise à jour des stocks et mouvements).

	*/
	global $conf, $user, $langs;
	$TLineValidated =array();
	$errors = 0;

	if (!is_file($filePath)) { return array(newImportLogLine('error', 'CSVFileNotFound')); }

	$TImportLog = array();
	$csvFile = fopen($filePath, 'r');

	$db->begin();
	for ($i = 0; $csvValues = fgetcsv($csvFile, '64000', ",", '"'); $i++) {
		$csvValues = array_map(
			function ($val) use ($srcEncoding) {
				if ($srcEncoding === 'UTF-8') return trim($val);
				return iconv($srcEncoding, 'UTF-8', trim($val));
			},
			$csvValues
		);
		$TcsvLine = $csvValues;
		if (empty(implode('', $csvValues))) continue;
		if ($i === 0) continue; // skip header row

		try {
			$objProduct = ibValidateCSVLine($i, $TcsvLine);
		} catch (ErrorException $e) {
			$TImportLog[] = newImportLogLine('error', $e->getMessage());
			$errors++;
			continue;
		}

		if (count($TImportLog) == 0){
			$TLineValidated[] = $objProduct;
		}
	}

	if ($errors == 0){

		try {
			validateDuplicateSerial($TLineValidated);
		} catch (ErrorException $e) {
			$TImportLog[] = newImportLogLine('error', $e->getMessage());
			$TLineValidated = array(); // on reset le tableau
		}


		// on injecte les données en bases
		foreach ($TLineValidated as $k => $line){
			try {
				//  create mouvement
				$successMessage = ibRegisterLotBatch($line, $k+1);
				$TImportLog[] = newImportLogLine('info', $successMessage);
			} catch (ErrorException $e) {
				$TImportLog[] = newImportLogLine('error', $e->getMessage());
				$db->rollback();
				$TImportLog[] = newImportLogLine('error rollback db');
			}
		}
	}

	$db->commit();
	return $TImportLog;
}

/**
 * @param string $type  'error', 'warning', 'info'
 * @param string $msg   Message
 * @return array
 */
function newImportLogLine($type, $msg) {
	return array('type' => $type, 'msg' => $msg);
}


/**
 * We don’t use price2num because price2num depends on the user configuration
 * while the numbers from those CSV are always with a comma as a decimal separator.
 * @param $value
 * @return float
 */
function parseNumberFromCSV($value, $type) {
	global $langs;
	$value = str_replace(',', '.', $value); // remplace virgule par point
	if (!is_numeric($value)) {
		return null;
	}
	if ($type === 'double') return doubleval($value);
	if ($type === 'int') return intval($value);
}

/**
 * @param int $lineNumber
 * @param array $lineArray
 * @return object  Object representing the parsed CSV line
 * @throws ErrorException
 */
function ibValidateCSVLine($lineNumber, $lineArray) {
	global $db, $langs;

	$TFieldName = array('ref_product', 'ref_warehouse','qty', 'batch');
	$arrayProduct = array();

	$ref_product = trim($lineArray[0]);
	$arrayProduct['ref_product'] = trim($lineArray[0]);
	$ref_entrepot = trim($lineArray[1]);
	$arrayProduct['ref_warehouse'] = trim($lineArray[1]);
	$qty = $lineArray[2];
	$batch = trim($lineArray[3]);

	//nb Columns
	try {
		nbColumnsValidation($lineArray, $TFieldName, $langs);
	}catch( ErrorException $e){
		throw $e;
	}
	//Product
	try {
		$p = validateProduct($db, $ref_product, $langs, $lineNumber);
		$arrayProduct['id_product'] = $p->id;
	}catch( ErrorException $e){
		throw $e;
	}
	//warehouse
	try {
		list($arrayProduct['id_warehouse'], $arrayProduct['ref_warehouse']) = validateWareHouse($db, $ref_entrepot, $p, $langs, $lineNumber);
//		$arrayProduct['ref_warehouse'] = getRefWarehouse($db, $arrayProduct['id_warehouse']);

	}catch( ErrorException $e){
		throw $e;
	}
	//Qty
	try {
		$arrayProduct['qty'] =  validateQty($qty, $langs,$p, $lineNumber);
	}catch( ErrorException $e){
		throw $e;
	}
	//Lot/serie
	try {
		$arrayProduct = validateLotSerie($batch, $langs, $lineNumber, $arrayProduct, $p, $db);
	}catch( ErrorException $e){
		throw $e;
	}

	return (object)$arrayProduct;
}

/**
 * @param $db
 * @param array $arrayProduct
 * @return array
 */
/*function getRefWarehouse($db, $idWarehouse)
{
	$e = new Entrepot($db);
	$res = $e->fetch($idWarehouse);
	if ($res > 0) {
		return  $e->ref;
	}
	return "no data";
}*/

/**
 * @param array $lineArray
 * @param array $TFieldName
 * @param $langs
 * @return void
 * @throws ErrorException
 */
function nbColumnsValidation(array $lineArray, array $TFieldName, $langs)
{
// nb columns validation
	if (count($lineArray) != count($TFieldName)) {
		throw new ErrorException($langs->trans(
			'CSVLineNotEnoughColumns'));
	}
}

/**
 * @param $db
 * @param $ref_product
 * @param $langs
 * @param $lineNumber
 * @return int
 * @throws ErrorException
 */
function validateProduct($db, $ref_product, $langs, $lineNumber)
{
	$p = new product($db);
	$res = $p->fetch('', $ref_product);

	if ($res <= 0) {
		throw new ErrorException($langs->trans(
			'RefProductNotExistError',
			$lineNumber + 1,
			$ref_product,
			'ref produit'

		));
	}
	if ($p->type == Product::TYPE_SERVICE) {
		throw new ErrorException($langs->trans(
			'ProductTypeServiceError',
			$lineNumber + 1,
			$ref_product,
			'ref produit'

		));

	}
	if ($p->status_batch == 0) {
		throw new ErrorException($langs->trans(
			'ProductTypeLotError',
			$lineNumber + 1,
			$ref_product,
			'ref produit'

		));
	}

	return $p;
}


/**
 * @param $db
 * @param $ref_entrepot
 * @param $p
 * @param $langs
 * @param $lineNumber
 * @return array
 * @throws ErrorException
 */
function validateWareHouse($db, $ref_entrepot, $p, $langs, $lineNumber)
{
	global $conf;
// test entrepot exist
	$msg ="";
	if (empty($ref_entrepot)){

		$msg = " ".$langs->transnoentities("isEmpty");
	}else{
		$msg = " ".$langs->transnoentities("notFound");
	}


	$e = new Entrepot($db);
	$res = $e->fetch('', $ref_entrepot);
	// invalid warehouse or empty cell
	if ($res <= 0) {

		if (!empty($conf->global->SET_WAREHOUSE_DEFAULT_PRODUCT_ON_EMPTY_WAREHOUSE_COLUMN)){
			// si le produit à un entrepot par default affectation sinon error
			if ($p->fk_default_warehouse == null) {
				throw new ErrorException($langs->trans(
					'RefWarehouseDefaultNotExistError',
					$lineNumber + 1,
					$ref_entrepot.$msg,
					$p->ref
				));

			}else{
				$ent = new Entrepot($db);
				$res = $ent->fetch($p->fk_default_warehouse);
				if($res <= 0) {
					throw new ErrorException($langs->trans(
	                                        'RefProductdefaultWarehouseNotExistError',
	                                        $lineNumber + 1,
	                                        $p->ref,
	                                        $p->fk_default_warehouse
	                                ));
				}
				return array($p->fk_default_warehouse, $ent->ref);
			}
		}else{
			throw new ErrorException($langs->trans(
				'RefWarehouseNotExistError',
				$lineNumber + 1,
				$ref_entrepot.$msg,
				$p->ref
			));
		}

	}
	return array($e->id, $e->ref);
}

/**
 * @param $qty
 * @param $langs
 * @param $lineNumber
 * @return int
 * @throws ErrorException
 */
function validateQty($qty, $langs,Product $p, $lineNumber)
{
	global $conf;
	$value = parseNumberFromCSV($qty, "int");
	// valeur null ou inférieur à zéro sur un lot
	if ((empty($value)  || $value < 1 ) && $p->status_batch == 1 ) {
		throw new ErrorException($langs->trans(
			'NumberExpectedError',
			$lineNumber + 1,
			$qty,
			"qty"
		));
	}

	// valeur > 1  sur un serial
	if ( $value > 1  && $p->status_batch == 2 ) {
		throw new ErrorException($langs->trans(
			'outNumberedForSerialError',
			$lineNumber + 1,
			$qty
		));
	}

	// conf qui permet de laisser la colonne qty vide sur un produit serialisé
	// elle sera retournée à 1 par defaut.
	if ((empty($value)  && $p->status_batch == 2)){
		if (empty($conf->global->ALLOW_EMPTY_QTY_COLUMN_ON_TYPE_SERIAL_PRODUCT)){
			throw new ErrorException($langs->trans(
				'NumberEmptyError',
				$lineNumber + 1,
				$qty
			));
		}else{
			return 1;
		}
	}

	return $value;
}

/**
 * @param $batch
 * @param $serial
 * @param $langs
 * @param $lineNumber
 * @param array $arrayProduct
 * @param product $p
 * @param $db
 * @return array
 * @throws ErrorException
 */
function validateLotSerie($batch, $langs, $lineNumber, array $arrayProduct, product $p, $db)
{
	$arrayProduct['batch'] = $batch;

	// product is not handling by lot/serie
	if ($p->status_batch < 1){

		throw new ErrorException($langs->trans(
			'ProductNotaBatchSerialTypeError',
			$lineNumber+1,
			$p->ref));
	}

	// LOTS/SERIAL empty
	if (empty($batch)) {
		throw new ErrorException($langs->trans(
			'NoSerialBatchError',
			$lineNumber + 1
		));
	}
	// serial
	if ($p->status_batch == 2){
		// test if serial already exist
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "product_lot WHERE fk_product = " . $arrayProduct['id_product'] . " and batch = '" . $batch . "'";
		$resql = $db->query($sql);
		$nbtotalofrecords = $db->num_rows($resql);

		if ($nbtotalofrecords > 0) {
			throw new ErrorException($langs->trans(
				'ProductSerialAlreadyExistError',
				$lineNumber+1,
				$batch
			));
		}
		//@todo check this one
		//$arrayProduct['qty'] = 1;
	}
	return $arrayProduct;
}

/**
 *  permet de tester les num series en doublon  dans la colonne  lot/serie pour les produits serialized
 * @param $TLineValidated
 * @return void
 */
function validateDuplicateSerial($TLineValidated){
	global $db, $langs;
	//
	$TProductSerialized = array();
	foreach ($TLineValidated as $k =>$line){
		$p = new Product($db);
		$res = $p->fetch($line->id_product);
		if ($res > 0 && $p->status_batch == 2){
			$TProductSerialized[] = $line->batch;
		}
	}

	$obj =  no_dupes($TProductSerialized);

	if (!$obj->result){
		$msg ="";
		foreach ($obj->arr as $key => $a){
			$msg = $key . " : ".$a ." unités  " ;
		}
		throw new ErrorException($langs->trans('duplicateSerialOnimportedFile',$msg));
	}

}

/**
 *
 * if we have duplicate batch it will return false in stdclass object with
 * how many duplicate batch we have and batch value
 * @param array $input_array
 * @return stdClass
 */
function no_dupes(array $input_array) {

	$obj = new stdClass();
	$obj->arr = array();
	$obj->result = count($input_array) === count(array_count_values($input_array));

	$arr = array_count_values($input_array);
	foreach ($arr as $key => $a){
		if ($a > 1){
			$obj->arr[$key] = $a;
		}
	}


	return $obj;

}


/**
 * @param $objProduct
 * @param $importKey
 * @return void
 */
function ibRegisterLotBatch($objProduct, $lineNumber) {
	global $db, $langs,$user;

	$type 			= 3; // augmentation du stock

	require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
	$ms = new MouvementStock($db);

	$resfetch = $ms->_create($user,
		$objProduct->id_product,
		$objProduct->id_warehouse,
		$objProduct->qty,
		$type,
		0,
		$langs->trans('addStockFromBatchSerial'),
		"",
		"",
		"",
		"",
		$objProduct->batch);

	if ($resfetch < 0) {
		throw new ErrorException($langs->trans(
			'SupplierFetchError',
			$objProduct->supplierRef,
			$ms->error . '<br>' . $db->lasterror())
		);
	}

	$p = new Product($db);
	$p->fetch($objProduct->ref_product);
	return $langs->trans('CSVBatchSerialCreateSuccess',$lineNumber, $objProduct->batch,$objProduct->ref_product.' | '.$objProduct->ref_warehouse,$objProduct->qty);

}


/**
 * Prepare admin pages header
 *
 * @return array
 */
function importbatchAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("importbatch@importbatch");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/importbatch/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/importbatch/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/importbatch/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@importbatch:/importbatch/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@importbatch:/importbatch/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'importbatch@importbatch');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'importbatch@importbatch', 'remove');

	return $head;
}

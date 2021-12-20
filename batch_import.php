<?php
/* Copyright (C) 2020 ATM Consulting <support@atm-consulting.fr>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require 'config.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
dol_include_once('importbatch/lib/importbatch.lib.php');

if(empty($user->rights->produit->creer)) accessforbidden();

$langs->load('importbatch@importbatch');

$action = GETPOST('action');

switch ($action) {
	case 'importCSV':
		$filename = GETPOST('CSVF$actionile', 'alpha');
		if (isset($_FILES['CSVFile'])) {
			$filePath = $_FILES['CSVFile']['tmp_name'];
			$TLog = ibGetBatchSerialFromCSV(
				$db,
				$filePath,
				GETPOST('srcEncoding', 'alpha'),
				'ib' . date('Ymd')
			);

			if (count(array_filter($TLog, function ($logLine) { return $logLine['type'] === 'error'; }))) {
				echo '<details open class="ib"><summary><h2>'. $langs->trans('Errors').'</h2></summary>';
			} else {
				echo '<details open class="ib"><summary><h2>'. $langs->trans('importDone').'</h2></summary>';
			}
			echo '<table class="ib import-log">';
			$lineNumber = 1;

			foreach ($TLog as $logLine) {

				$typeMsg ="mesgs";
				if ($logLine['type'] == 'error' ){
					$typeMsg ="errors";
				}
				setEventMessage($logLine['msg'],$typeMsg);
			}
			header('Location: '.$_SERVER['PHP_SELF']);
			exit;

		}
	default:
		llxHeader('<link rel="stylesheet" href="' . dol_buildpath('/importbatch/css/ib.css', 1) . '" />');
		$form = new Form($db);
		print_barre_liste($langs->trans("productImportTitle"), 0, $_SERVER["PHP_SELF"], '', '', '', '', 0, -1, '', 0, '', '', 0, 1, 1);
		showImportForm();
		showHelp();
}
// todo: mettre dans fonction show_form_create()

llxFooter();



function showImportForm() {
	global $langs;
	$acceptedEncodings = array(
		'UTF-8',
		'latin1',
		'ISO-8859-1',
		'ISO-8859-15',
		'macintosh'
	);
	?>
	<form method="POST" enctype="multipart/form-data">
		<label for="CSVFile">
			<?php echo $langs->trans('PickCSVFile'); ?> :
		</label>
		<input type="hidden" name="action" value="importCSV" />
		<input type="hidden" name="token" value="<?php echo newToken() ?>" />

		<input id="CSVFile" name="CSVFile" type="file" required />

		<br/>
		<label for="srcEncoding">
			<?php print $langs->trans('SelectFileEncoding'); ?>
		</label>
		<select id="srcEncoding" name="srcEncoding">
			<?php
			foreach ($acceptedEncodings as $encoding) {
				echo '<option value="' . $encoding . '">' . $encoding . '</option>';
			}
			?>
		</select>
		<br/>
		<input type="submit" class="button" name="save" value="<?php echo $langs->trans("SubmitCSVForImport") ?>" />
	</form>
	<?php
}

function showHelp() {

	global $langs;

	$key="csv";
	$param="&datatoimport=importbatch_1";
	?>
	<details class="ib" id="ibImportExplanation">
		<summary><h2><?php print $langs->trans("help"); ?></h2></summary>
		<hr>
		<h3>
		<p>
			<?php print img_picto('', 'download', 'class="paddingright opacitymedium"').'<a href="'.DOL_URL_ROOT.'/imports/emptyexample.php?format='.$key.$param.'" target="_blank">'.$langs->trans("DownloadEmptyExample").'</a>'; ?>
		</p>
		</h3>
		<hr>
		<h3><?php print $langs->trans("Columns"); ?></h3>
		<table class="ib help-table">
			<tr><th><?php print $langs->trans("refProductTitle"); ?></th>

				<td><?php print $langs->trans("refProdColumDesc"); ?> </td>
			</tr>
			<tr><th><?php print $langs->trans("refWarehouseTitle"); ?></th>
				<td><?php print $langs->trans("refWarehouseColumDesc"); ?></td>
			</tr>
			<tr><th><?php print $langs->trans("refQtyTitle"); ?></th>
				<td><?php print $langs->trans("refQtyColumDesc"); ?></td>
			</tr>
			<tr><th><?php print $langs->trans("refBatchTitle"); ?></th>
				<td><?php print $langs->trans("refBatchColumDesc"); ?></td>
			</tr>
		</table>
		<h3><?php print $langs->trans("TechDescCsvTitle"); ?></h3>
		<ul>
			<li><b><?php print $langs->trans("NumbersubTitle"); ?></b>
				<ul>
					<li><?php print $langs->trans("Numbersub-1"); ?></li>
					<li><?php print $langs->trans("Numbersub-2"); ?></li>
					<li><?php print $langs->trans("Numbersub-3"); ?></li></ul>
			<li><b><?php print $langs->trans("EncodeCharsSubTitle"); ?> </b><br><?php print $langs->trans("EncodeCharsSubTitle-2"); ?>
			</li>
			<li><?php print $langs->trans("FieldSeparatorsubTitle"); ?> </li>
			<li><?php print $langs->trans("StringSeparatorsubTitle"); ?></li>
			<li><?php print $langs->trans("EOLsubTitle"); ?></li>
		</ul>
	</details>
	<?php
}

<?php
require_once 'lib/db.php';

db_open();
if (isset($_GET['a'])) {
	switch ($_GET['a']) {
		case 'load':
			$res = db_select($_GET['t'], array());
			if (!is_array($res)) {
				die(json_encode(array('data'=>array('Error'=>$res))));
			} else {
				die(db_handsometable_encode($_GET['t'], $res));
			}
			break;
		case 'save':
			die(json_encode(db_handsometable_save($_GET['t'], $_POST)));
			break;
	}
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">
<html>
<head>
	<title>Identifications tracker</title>
	<script src="js/lib/jquery.min.js"></script>
	<script src="js/lib/jquery-ui.min.js"></script>
	<script src="js/lib/jquery.handsontable.full.js"></script>
	<link rel="stylesheet" media="screen" href="js/lib/jquery.handsontable.full.css">
	<link rel="stylesheet" media="screen" href="js/lib/css/ui-lightness/jquery-ui-1.10.4.custom.min.css">
	<script language="JavaScript">
		$(document).ready(function() {
			var $container = $("#dataTable");
			var $console = $("#console");
			var $parent = $container.parent();
			var handsontable;
			var threadAutosaver;
			var gChange;

			$parent.find('button[name=load]').click(function () {
				$.ajax({
					url: "?a=load&t="+$('#selectTable').find(":selected").text(),
					dataType: 'json',
					type: 'GET',
					success: function (res) {
						$container.handsontable({
							rowHeaders: false,
							minSpareRows: 0,
							contextMenu: true,
							data: res.data,
							colHeaders: res.colHeaders,
							columns: res.columns,
							afterChange: function (change, source) {
								if (source == 'edit' && !threadAutosaver) {
									gChange = JSON.stringify(change);
									gtransfer = JSON.stringify(handsontable.getData());
									threadAutosaver = setTimeout(function () {
										$.ajax({
											url: '?a=save&t='+$('#selectTable').find(':selected').text(),
											dataType: 'json',
											type: 'POST',
											data: {'data': gtransfer, 'changed': gChange},
											success: function (data, res) {
												if (data === true) {
													$parent.find('button[name=load]').click();
												} else {
													$console.text(data);
												}
											},
											error: function () {
												$console.text("Error saving data.");
											},
										});
										threadAutosaver = false;
									}, 500);
								}
							},
						});
						handsontable = $container.data('handsontable');
						$console.text('Data loaded successfuly.');
					},
				});
			});
			$('#selectTable').change(function () {$parent.find('button[name=load]').click();});

			$parent.find('button[name=insert]').click(function () {
				handsontable.alter('insert_row');
			});

            $parent.find('button[name=delete]').click(function () {
                handsontable.alter('remove_row');
            });

			$parent.find('button[name=save]').click(function () {

			});
		});
	</script>
</head>
<body>

	<select size="1" name"table" id="selectTable">
		<option>years</option>
		<option>authors</option>
		<option>customers</option>
		<option>sections</option>
		<option>documenttypes</option>
		<option>projecttypes</option>
		<option>quotationsNums</option>
		<option>projectsNums</option>
		<option>expedientsNums</option>
		<option>relation_qtn_pro_exp</option>
	</select>
	<button name="load">Load</button>
	<button name="save">Save</button>
	<div id="dataTable"></div>
	<div id="console"></div>
	<button name="insert">New</button>
    <button name="delete">Delete</button>

</body>
</html>

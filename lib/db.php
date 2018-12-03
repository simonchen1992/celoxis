<?php
$db = NULL;

function db_open() {
	global $db;
	try {
		$db = new PDO('sqlite:db/idtracker.db');
		$db->setAttribute(PDO::ATTR_ERRMODE,
		                  PDO::ERRMODE_EXCEPTION);
		return TRUE;
	} catch (PDOException $e) {
		return $e->getMessage();
	}
}

function db_insert($table, $data) {
	global $db;
	foreach ($data as $field => $value) {
		$fields[$field]=$field;
		$placeholders[$field]=":$field";
		$values[$field] = $value;
	}

	$sql = "INSERT INTO $table (".implode($fields,',').")
	               VALUES (".implode($placeholders,',').")";
	$stmt = $db->prepare($sql);

	foreach ($data as $field => $value) {
		$stmt->bindParam($placeholders[$field], $values[$field]);
	}
	try {
		$stmt->execute();
		return TRUE;
	} catch (PDOException $e) {
		return $e->getMessage() ." - $sql";
	}
}

function db_delete($table, $filter) {
	global $db;
	$where = "";
	foreach ($filter as $field => $value) {
		$fields[$field]=$field;
		$placeholders[$field]=":$field";
		$values[$field] = $value;
		$where .= "$field=:$field AND ";
	}
	if ($where !== "") {
		$where = substr($where, 0, -4);
		$where = " WHERE $where";
	}

	$sql = "DELETE FROM $table$where;";
	$stmt = $db->prepare($sql);

	foreach ($filter as $field => $value) {
		$stmt->bindParam($placeholders[$field], $values[$field]);
	}
	try {
		$stmt->execute();
		return TRUE;
	} catch (PDOException $e) {
		return $e->getMessage();
	}
}

function db_update($table, $data, $filter) {
	global $db;
	$setclauses = "";
	db_populate_serial($table, $data, $filter);
	# Generate AUTOGENNAME for following three tables.
    switch ($table) {
        case 'quotationsNums':
            $data['nameAutoGen'] = (string)$data['id_year'].'_'.(string)$data['id_section'].'_'.
                $data['id_documenttype']. $data['id_department'].'_'.$data['id_customer'].
                $data['id_projecttype'].'_'.$data['id_author'].substr('000'.(string)$data['serial'], -3);
            break;
        case 'expedientsNums':
            $data['nameAutoGen'] = (string)$data['id_year'].'_'.(string)$data['id_section'].'_'.
                $data['id_department'].$data['id_customer'].
                $data['id_projecttype'].substr('000'.(string)$data['serial'], -3).'_EXP';
            break;
        case 'projectsNums':
            $data['nameAutoGen'] = (string)$data['id_year'].'_'.(string)$data['id_section'].'_'.
                $data['id_department'].$data['id_customer'].
                $data['id_projecttype'].substr('000'.(string)$data['serial'], -3).'_PRO';
            break;
    }
	foreach ($data as $field => $value) {
		$fields[$field]=$field;
		$placeholders[$field]=":$field";
		$values[$field] = $value;
		$setclauses .= "$field=:$field, ";
	}
	if ($setclauses !== "") {
		$setclauses = substr($setclauses, 0, -2);
		$setclauses = " SET $setclauses";
	}
	$where = "";
	foreach ($filter as $field => $value) {
		$where .= "$field='$value' AND ";
	}
	if ($where !== "") {
		$where = substr($where, 0, -4);
		$where = " WHERE $where";
	}

	$sql = "UPDATE $table$setclauses$where;";
	$stmt = $db->prepare($sql);

	foreach ($data as $field => $value) {
		$stmt->bindParam($placeholders[$field], $values[$field]);
	}
	try {
		$stmt->execute();
		// Once you can only update for one cell of data, or it will insert a new one, which will confilic with "Need More Data..." part
		if ($stmt->rowCount()===0) {
			return db_insert($table, $data);
		} else {
			return TRUE;
		}
	} catch (PDOException $e) {
		return $e->getMessage() ." - $sql";
	}
}

function db_select($table, $filter) {
	global $db;
	$where = "";
	foreach ($filter as $field => $value) {
		$fields[$field]=$field;
		$placeholders[$field]=":$field";
		$values[$field] = $value;
		$where .= "$field=:$field AND ";
	}
	if ($where !== "") {
		$where = substr($where, 0, -4);
		$where = " WHERE $where";
	}

	$sql = "SELECT * FROM $table$where;";
	#  Exceptional case for customer and client which share one table in database.
	if ($table === 'customers'){
		$sql = "SELECT * FROM customers WHERE supplierORclient = 'client' or supplierORclient = 'client-supplier'";
	}elseif ($table === 'suppliers'){
		$sql = "SELECT * FROM customers WHERE supplierORclient = 'supplier' or supplierORclient = 'client-supplier'";
	}
	$stmt = $db->prepare($sql);

	foreach ($filter as $field => $value) {
		$stmt->bindParam($placeholders[$field], $values[$field]);
	}
	try {
		$stmt->execute();
		return $stmt->fetchAll();
	} catch (PDOException $e) {
		return $e->getMessage() ." - $sql";
	}
}

function db_describe($table) {
	global $db;
	$sql = "PRAGMA table_info([$table]);";
	$stmt = $db->prepare($sql);
	try {
		$stmt->execute();
		return $stmt->fetchAll();
	} catch (PDOException $e) {
		return $e->getMessage();
	}
}

function db_handsometable_encode($table, $res) {
	$data = array();
	foreach ($res as $rowid => $rowdata) {
		$row = array();
		foreach ($rowdata as $key => $value) {
			if (!is_numeric($key)) {
				$row[] = $value;
			}
		}
		$data[]=$row;
	}
	$columns = array();
	$colHeaders = array();
	#  Exceptional case for customer and client which share one table in database.
	if ($table === 'suppliers'){
		$table = 'customers';
	}
	$tabledef = db_describe($table);
	foreach ($tabledef as $field => $fdata) {
		$colHeaders[] = $fdata['name'];
		if ($fdata['type'] == 'LONG' && $fdata['name'] == 'date') {
			$columns[] = array('type'=>'date');
		} elseif ($fdata['name'] == 'serial' || $fdata['name'] == 'nameAutoGen') {
			$columns[] = array('readOnly'=>TRUE);
		} elseif ($fdata['name'] == 'supplierORclient'){
			$columns[] = array('type'=>'dropdown', 'source'=>['supplier', 'client', 'client-supplier']);
		} elseif (substr($fdata['name'],0,3) == 'id_') {
			if ($table=='quotationsNums'&& $fdata['name'] == 'id_documenttype'){  
				$columns[] = array('readOnly'=>TRUE);
			}else{
				$columns[] = db_handsometable_getdropdowndata($table, $fdata['name']);
			}
		} else {
			$columns[] = array();
		}
	}

	return json_encode(array(
		'table' => $table,
		'columns'=>$columns,
		'colHeaders'=>$colHeaders, 
		'data'=>$data,
		));
}

function db_handsometable_getdropdowndata($table, $idname) {
	$subtable = $idname=='id_department'? 'id_sections':$idname."s";
	$res = $table=='relation_qtn_pro_exp'? db_select(substr($subtable,3)."Nums", array()):db_select(substr($subtable,3), array());
	if ($res!==FALSE) {
		$items = array();
		foreach ($res as $key => $value) {
			if ($table=='relation_qtn_pro_exp'){
				$items[] = $value['nameAutoGen'];
			}
			else{
				$items[] = $idname=='id_department'? $value['name']:$value['id'];
			}
		}
		return array('type'=>'dropdown', 'source'=>$items);
	} else {
		return array();
	}
}

function db_handsometable_save($table, $input) {
    $input['changed'] = json_decode($input['changed']);
    $input['data'] = json_decode($input['data']);
	if (isset($input['changed'])) {
		foreach ($input['changed'] as $key => $changed) {
			$changed_row = $changed['0'];
			$changed_cell = $changed['1'];
			$original_val = $changed['2'];
			$new_val = $changed['3'];
			#  Exceptional case for customer and client which share one table in database.
			if ($table === 'suppliers'){
				$table = 'customers';
			}
			$tabledef = db_describe($table);
			$new_row = $input['data'][$changed_row];
			$i=0;
			foreach ($tabledef as $fkey => $fdata) {
				$new_data[$fdata['name']] = isset($new_row[$i]) ? $new_row[$i] : "";
				$i++;
			}
			$original_data = $new_data;
			$original_data[$tabledef[$changed_cell]['name']] = $original_val;

			return db_update($table, $new_data, $original_data);
		}
	}
}

function db_populate_serial($table, &$data, &$filter) {
	global $db;
	if ($table === 'suppliers' || $table === 'customers'){
		if (!in_array($data['supplierORclient'], ['supplier', 'client', 'client-supplier'])){
			die(json_encode("Please fill the blank properly which can be found in dropbox"));
		}
	}
	if ($table !== 'quotationsNums' && $table !== 'expedientsNums' && $table !== 'projectsNums' && $table !== 'relation_qtn_pro_exp') {
		return;
	}

	if ($table === 'quotationsNums'){
		$data['id_documenttype'] = 'QTN';
	}

	# Check if all data is filled without empty.
	foreach ($data as $key => $value) {
		if ((substr($key,0,3)==='id_' || $key === 'description' || $key === 'date') && $value==='' && $table !== 'relation_qtn_pro_exp') {
			die(json_encode("Need more data..."));
		}
	}

	# Check for columns that have dropbox, to ensure the fill-in data is involved in their dropbox.
	# Which is the only reason Relation table is here.
	foreach ($data as $key => $value) {
		if (substr($key,0,3)==='id_' && $value !== '') {
			$columns[] = db_handsometable_getdropdowndata($table, $key);
			if (!in_array($value, $columns[0]["source"])){
				die(json_encode("Please fill the blank properly which can be found in dropbox"));
			}
			array_pop($columns);
		}
	}

    $filter_counter = array();
    foreach ($data as $fkey => $fvalue) {
        if (substr($fkey,0,3)==='id_') {
            $filter_counter[$fkey]=$fvalue;
        }
	}
	
	# Relation table does not need to generate serial number AND there can be empty in some of cells.
	if ($table === 'relation_qtn_pro_exp') {
		$rows = db_select($table, $filter_counter);
		if (count($rows) !== 0){
			die(json_encode("Cannot input the same combination in relation tables."));
		}
		return;
	}

	
	# Do not need to calculate serial again if only modify "data" or "description" or "NOTHING"
	$ignoreInput = array('date', 'description', NULL);
	if (in_array(key(array_diff($filter,$data)), $ignoreInput) && (isset($data['serial']) && $data['serial'] !=='')){
		return;
	}


	switch ($table) {
		case 'quotationsNums':
			if (isset($data['id_documenttype']) && $data['id_documenttype']!=='' &&
                isset($data['id_section']) && $data['id_section']!=='' &&
                isset($data['id_customer']) && $data['id_customer']!=='' &&
                isset($data['id_projecttype']) && $data['id_projecttype']!=='' &&
                isset($data['id_author']) && $data['id_author']!=='' &&
                isset($data['id_department']) && $data['id_department']!=='' &&
                isset($data['id_year']) && $data['id_year']!=='') {
                $rows = db_select($table, $filter_counter);
                #$data['id_documenttype']='QTN';
                #$data['date']=now();
                #die(var_dump($filter_counter));
                $data['serial']=count($rows)+1;
			}
			break;
		case 'expedientsNums':
			if (isset($data['id_section']) && $data['id_section']!=='' &&
			    isset($data['id_customer']) && $data['id_customer']!=='' &&
			    isset($data['id_projecttype']) && $data['id_projecttype']!=='' &&
                isset($data['id_department']) && $data['id_department']!=='' &&
                isset($data['id_year']) && $data['id_year']!=='') {
				$rows = db_select($table, $filter_counter);
				#die(var_dump(filter_counter));
				$data['serial']=count($rows)+1;

			}
			break;
		case 'projectsNums':
			if (isset($data['id_section']) && $data['id_section']!=='' &&
                isset($data['id_customer']) && $data['id_customer']!=='' &&
                isset($data['id_projecttype']) && $data['id_projecttype']!=='' &&
                isset($data['id_department']) && $data['id_department']!=='' &&
                isset($data['id_year']) && $data['id_year']!=='') {
				$rows = db_select($table, $filter_counter);
				$data['serial']=count($rows)+1;
			}
			break;
	}
}

?>
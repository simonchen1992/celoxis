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
		#if ($fields[$field]=='id_documenttype'){
		#	$values[$field]='CARLOS_TEST';
		#}
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
	$tabledef = db_describe($table);
	foreach ($tabledef as $field => $fdata) {
		$colHeaders[] = $fdata['name'];
		if ($fdata['type'] == 'LONG' && $fdata['name'] == 'date') {
			$columns[] = array('type'=>'date');
		} elseif ($fdata['name'] == 'serial') {
			$columns[] = array('readOnly'=>TRUE);
		} elseif (substr($fdata['name'],0,3) == 'id_') {
			if ($table=='quotationsNums'&& $fdata['name'] == 'id_documenttyp'){  # need to be changed
				$columns[] = array('readOnly'=>TRUE);
			}else{
				$columns[] = db_handsometable_getdropdowndata($table, $fdata['name']);
			}
		} else {
			$columns[] = array();
		}
	}

	return json_encode(array(
		'columns'=>$columns,
		'colHeaders'=>$colHeaders, 
		'data'=>$data));
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
	if ($table !== 'quotationsNums' && $table !== 'expedientsNums' && $table !== 'projectsNums') {
		return;
	}

	foreach ($data as $key => $value) {
		if (substr($key,0,3)==='id_' && $value==='') {
			die(json_encode("Need more data..."));
		}
	}

    if (isset($filter['serial']) && $filter['serial'] !== '') {
		$sql = "SELECT * FROM $table";
		foreach ($db->query($sql) as $row) {
			if($key == count($db->query($sql)) -1){
				$count = $row;
		}
		}
		$flag = 0;   
		foreach ($filter as $temp) {   
			if (in_array($temp, $count)) {   
				continue;   
			}else {   
				$flag = 1;   
				break;   
			}   
		}   
		if ($flag) {
			die(json_encode("You can only modify previous data in database."));
		}
    }

    $filter_counter = array();
    foreach ($data as $fkey => $fvalue) {
        if (substr($fkey,0,3)==='id_') {
            $filter_counter[$fkey]=$fvalue;
        }
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
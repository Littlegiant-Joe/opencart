<?php
class ModelUpgrade extends Model {
	public function mysql() {
		// Upgrade script to opgrade opencart to the latst version. 
		// Oldest version supported is 1.3.2
		
		// Load the sql file
		$file = DIR_APPLICATION . 'opencart.sql';
		
		if (!file_exists($file)) { 
			exit('Could not load sql file: ' . $file); 
		}
		
		$string = '';	
		
		$lines = file($file);
		
		$status = false;	
		
		// Get only the create statements
		foreach($lines as $line) {
			// Set any prefix
			$line = str_replace("CREATE TABLE `oc_", "CREATE TABLE `" . DB_PREFIX, $line);
			
			// If line begins with create table we want to start recording
			if (substr($line, 0, 12) == 'CREATE TABLE') {
				$status = true;	
			}
			
			if ($status) {
				$string .= $line;
			}
			
			// If line contains with ; we want to stop recording
			if (preg_match('/;/', $line)) {
				$status = false;
			}
		}
		
		$table_new_data = array();
				
		// Trim any spaces
		$string = trim($string);
		
		// Trim any ;
		$string = trim($string, ';');
			
		// Start reading each create statement
		$statements = explode(';', $string);
		
		foreach ($statements as $sql) {
			// Get all fields		
			$field_data = array();
			
			preg_match_all('#`(\w[\w\d]*)`\s+((tinyint|smallint|mediumint|bigint|int|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|varchar|char|datetime|date|float|double|decimal|timestamp|time|year|enum|set|binary|varbinary)(\((\d+)(,\s*(\d+))?\))?){1}\s*(collate (\w+)\s*)?(unsigned\s*)?((NOT\s*NULL\s*)|(NULL\s*))?(auto_increment\s*)?(default \'([^\']*)\'\s*)?#i', $sql, $match);

			foreach(array_keys($match[0]) as $key) {
				$field_data[$match[1][$key]] = array(
					'name'          => trim($match[1][$key]),
					'type'          => trim($match[3][$key]),
					'size'          => trim($match[5][$key]),
					'sizeext'       => trim($match[8][$key]),
					'collation'     => trim($match[9][$key]),
					'unsigned'      => trim($match[10][$key]),
					'notnull'       => trim($match[11][$key]),
					'autoincrement' => trim($match[14][$key]),
					'default'       => trim($match[16][$key]),
				);
			}
						
			// Get primary keys
			$primary_data = array();
			
			preg_match('#primary\s*key\s*\([^)]+\)#i', $sql, $match);
			
			if (isset($match[0])) { 
				preg_match_all('#`(\w[\w\d]*)`#', $match[0], $match); 
			} else{ 
				$match = array();	
			}
			
			if ($match) {
				foreach($match[1] as $primary){
					$primary_data[] = $primary;
				}
			}
			
			// Get indexes
			$index_data = array();
			
			$indexes = array();
			
			preg_match_all('#key\s*`\w[\w\d]*`\s*\(.*\)#i', $sql, $match);

			foreach($match[0] as $key) {
				preg_match_all('#`(\w[\w\d]*)`#', $key, $match);
				
				$indexes[] = $match;
			}
			
			foreach($indexes as $index){
				$key = '';
				
				foreach($index[1] as $field) {
					if ($key == '') {
						$key = $field;
					} else{
						$index_data[$key][] = $field;
					}
				}
			}			
			
			// Table options
			$option_data = array();
			
			preg_match_all('#(\w+)=(\w+)#', $sql, $option);
			
			foreach(array_keys($option[0]) as $key) {
				$option_data[$option[1][$key]] = $option[2][$key];
			}

			// Get Table Name
			preg_match_all('#create\s*table\s*`(\w[\w\d]*)`#i', $sql, $table);
			
			if (isset($table[1][0])) {
				$table_new_data[] = array(
					'sql'     => $sql,
					'name'    => $table[1][0],
					'field'   => $field_data,
					'primary' => $primary_data,
					'index'   => $index_data,
					'option'  => $option_data
				);
			}
		}

		//$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
		$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, 'opencart_test');

		// Get all current tables, fields, type, size, etc..
		$table_old_data = array();
		
		$table_query = $db->query("SHOW TABLES FROM `" . 'opencart_test' . "`");
				
		foreach ($table_query->rows as $table) {
			if (utf8_substr($table['Tables_in_' . 'opencart_test'], 0, strlen(DB_PREFIX)) == DB_PREFIX) {
				$field_data = array(); 
				
				$field_query = $db->query("SHOW COLUMNS FROM `" . $table['Tables_in_' . 'opencart_test'] . "`");
				
				foreach ($field_query->rows as $field) {
					preg_match('/\((.*)\)/', $field['Type'], $match);
					
					$field_data[$field['Field']] = array(
						'name'    => $field['Field'],
						'type'    => preg_replace('/\(.*\)/', '', $field['Type']),
						'size'    => isset($match[1]) ? $match[1] : '',
						'null'    => $field['Null'],
						'key'     => $field['Key'],
						'default' => $field['Default'],
						'extra'   => $field['Extra']
					);
				}
				
				$table_old_data[$table['Tables_in_' . 'opencart_test']] = $field_data;
			}
		}
						
		foreach ($table_new_data as $table) {
			// If table is not found create it
			if (!isset($table_old_data[$table['name']])) {
				//$db->query($table['sql']);
				
				echo $table['sql'] . "\n\n";
			} else {
				foreach ($table['field'] as $field) {
					// If field is not found create it
					if (!isset($table_old_data[$table['name']][$field['name']])) {
						$sql = "ALTER TABLE `" . $table['name'] . "` ADD `" . $field['name'] . "` " . $field['type'];
						
						if ($field['size']) {
							$sql .= "(" . $field['size'] . ")";
						}
						
						if ($field['collation']) {
							$sql .= " " . $field['collation'];
						}
						 
						if ($field['notnull']) {
							$sql .= " " . $field['notnull'];
						}
						
						if ($field['default']) {
							$sql .= " DEFAULT '" . $field['default'] . "'";
						}
						
						if ($field['autoincrement']) {
							$sql .= " AUTO_INCREMENT";
						}
						
						//$db->query($sql);
												
						echo $sql . "\n";
					} else {
						$sql = "ALTER TABLE `" . $table['name'] . "` MODIFY `" . $field['name'] . "`";
						
						
						
						
						if ($field['type'] != $table_old_data[$table['name']][$field['name']]['type'] || $field['size']) {
							$sql .= " " . $field['type'];
							
							echo $field['type'];
							
							
							print_r($table_old_data[$table['name']][$field['name']]);
									
							if ($field['size']) {
								$sql .= "(" . $field['size'] . ")";
							}	
																			
							//(1) NOT NULL DEFAULT '1' COMMENT '';
						}
						
						

						
						/*
						'name'          => trim($match[1][$key]),
						'type'          => trim($match[3][$key]),
						'size'          => trim($match[5][$key]),
						'sizeext'       => trim($match[8][$key]),
						'collation'     => trim($match[9][$key]),
						'unsigned'      => trim($match[10][$key]),
						'notnull'       => trim($match[11][$key]),
						'autoincrement' => trim($match[14][$key]),
						'default'       => trim($match[16][$key]),
						*/						
						
						//$db->query($sql);
												
						echo $sql . "\n";
					}
				}
			}
		}
		
		/*
		// Settings
		$query = $db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '0' ORDER BY store_id ASC");

		foreach ($query->rows as $setting) {
			if (!$setting['serialized']) {
				$settings[$setting['key']] = $setting['value'];
			} else {
				$settings[$setting['key']] = unserialize($setting['value']);
			}
		}
		*/
				
		// We can do all the SQL changes here
		
		// ALTER TABLE  `oc_custom_field_value_description` ADD PRIMARY KEY (`custom_field_value_id`, `language_id`);
		// ALTER TABLE `oc_product` MODIFY `shipping` tinyint(1) NOT NULL DEFAULT '1' COMMENT '';
		// ALTER TABLE oc_customer ADD token varchar(255) NOT NULL DEFAULT '' COMMENT '' COLLATE utf8_bin AFTER approved;
		// ALTER TABLE oc_product_tag ADD INDEX product_id (product_id);
				
		//preg_match_all('/`(.+)` (\w+)\(? ?(\d*) ?\)?/', $string, $match);
		//preg_match_all('/CREATE\sTABLE\s`(.+)`\s^\((.+)\)\sENGINE\=MyISAM\sDEFAULT\sCHARSET\=utf8\sCOLLATE\=utf8_bin;/i', $string, $matches);
				
		// Sort the categories to take advantage of the nested set model
		//$this->path(0, 0);
	}
	
	protected function path($category_id = 0, $level) {
		$this->db->query("UPDATE " . DB_PREFIX . "category SET `left` = '" . (int)$level++ . "' WHERE category_id = '" . (int)$category_id . "'");
		
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category WHERE parent_id = '" . (int)$category_id . "' ORDER BY sort_order");
		
		foreach ($query->rows as $result) {
			$level = $this->path($result['category_id'], $level);
		}
		
		$this->db->query("UPDATE " . DB_PREFIX . "category SET `right` = '" . (int)$level++ . "' WHERE category_id = '" . (int)$category_id . "'");
	
		return $level;
	}	
}
?>
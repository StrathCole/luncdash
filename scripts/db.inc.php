<?php

/**
 * database class
 *
 * @method _read_query
 * @method _write_query
 * @method _build_query_string
 *
 */
class pdb {

	/** #@+
	 * @access private
	 */
	private $_iWriteId; // Connection ids
	private $_iReadId;
	private $_iWriteCount = 0; // nur für logging
	private $_iReadCount = 0;
	private $_bSeparate = false;
	private $_iQueryId;
	private $_iStartTime;
	private $_iEndTime;
	private $_iReconnects;
	public $errorNumber;
	
	public $_debuglog = false;
	
	private $_aQueryCache = array();

	/** #@- */


	/**
	 * database constructor
	 *
	 * initializes the database and starts a connection
	 *
	 * @access public
	 * @param string  $dbname
	 * @param string  $sHost
	 * @param string  $sUser
	 * @param string  $sPassword
	 * @param bool    $bPersistent use persistent connection on custom data connection [deprecated!]
	 *
	 */
	public function __construct($dbname, $sHost, $sUser, $sPassword, $bPersistent = false, $iReconnects = 0, $sPort = 3306) {
		$this->sHost = $sHost;
		$this->sUserName = $sUser;
		$this->sPassword = $sPassword;
		$this->sDataBase = $dbname;
		$this->sPort = intval($sPort);

		if(!isset($this->sPort) || !$this->sPort) {
			$this->sPort = 3306;
		}
		$this->_iReconnects = $iReconnects;
		$this->_connect();
	}

	/** #@+
	 * @access private
	 */
	public function __destruct() {
		$this->close();
	}

	public static function getInstance() {
		global $dba;
		return $dba;
	}

	private function _start_timer() {
		$iStart = microtime();
		list($iBegs, $iBegm) = explode(' ', $iStart);
		$this->_iStartTime = $iBegs + $iBegm;
	}

	private function _end_timer() {
		$iEnd = microtime();
		list($iBegs, $iBegm) = explode(' ', $iEnd);
		$this->_iEndTime = $iBegs + $iBegm;

		return $this->_iEndTime - $this->_iStartTime;
	}

	//Database-Connect
	//Datenbank-Verbindung aufbauen
	private function _connect() {
		if($this->_iReadId) {
			return true;
		}

		$this->_iReadId = mysqli_connect($this->sHost, $this->sUserName, $this->sPassword, '', $this->sPort);

		$try = 0;
		while((!is_object($this->_iReadId) || mysqli_connect_error()) && ($try <= $this->_iReconnects || $this->_iReconnects === true)) {
			if($try > 0) {
				sleep(1);
			}

			$try++;
			$this->_iReadId = mysqli_connect($this->sHost, $this->sUserName, $this->sPassword, '', $this->sPort);
		}
		if(!is_object($this->_iReadId)) {
			$this->_sqlerror('Zugriff auf Datenbankserver fehlgeschlagen! / Database server not accessible!');
			return false;
		}
		
		if($this->sDataBase) {
			if(!((bool) mysqli_query($this->_iReadId, 'USE `' . $this->sDataBase . '`'))) {
				$this->close();
				$this->_sqlerror('Datenbank nicht gefunden / Database not found');
				return false;
			}
		}

		// Wenn eine separate Verbindung für schreibende Zugriffe gewünscht ist, neue kreieren
		// sonst Verweis auf bestehende read Verbindung
		if($this->_bSeparate == true) {
			$this->_iWriteId = mysqli_connect($this->sHost, $this->sUserName, $this->sPassword, '', $this->sPort);
			while((!is_object($this->_iWriteId) || mysqli_connect_error()) && ($try <= $this->_iReconnects || $this->_iReconnects === true)) {
				if($try > 0) {
					sleep(1);
				}

				$try++;
				$this->_iWriteId = mysqli_connect($this->sHost, $this->sUserName, $this->sPassword, '', $this->sPort);
			}
			if(!is_object($this->_iWriteId)) {
				$this->_sqlerror('Zugriff auf Datenbankserver fehlgeschlagen! / Database server not accessible!', true);
				return false;
			}
			if($this->sDataBase) {
				if(!((bool) mysqli_query($this->_iWriteId, 'USE `' . $this->sDataBase . '`'))) {
					$this->close();
					$this->_sqlerror('Datenbank nicht gefunden / Database not found', true);
					return false;
				}
			}
		} else {
			$this->_iWriteId = & $this->_iReadId;
		}

		$this->_setCharset();
	}

	public function close() {
		if(is_object($this->_iReadId)) {
			mysqli_close($this->_iReadId);
		}
		if($this->_bSeparate == true && is_object($this->_iWriteId)) {
			mysqli_close($this->_iWriteId);
		}

		$this->_iReadId = null;
		$this->_iWriteId = null;
	}

	public function _build_query_string($sQuery = '') {

		global $config;

		$iArgs = func_num_args();
		if($iArgs > 1) {
			$aArgs = func_get_args();

			if($iArgs == 3 && $aArgs[1] === true && is_array($aArgs[2])) {
				$aArgs = $aArgs[2];
				$iArgs = count($aArgs);
			} else {
				array_shift($aArgs); // delete the query string that is the first arg!
			}

			$iPos = 0;
			$iPos2 = 0;
			foreach($aArgs as $sValue) {
				$iPos2 = strpos($sQuery, '??', $iPos2);
				$iPos = strpos($sQuery, '?', $iPos);

				if($iPos === false && $iPos2 === false) {
					break;
				}

				if($iPos2 !== false && ($iPos === false || $iPos2 <= $iPos)) {
					$sTxt = $this->escape($sValue);

					if(strpos($sTxt, '.') !== false) {
						$sTxt = preg_replace('/^(.+)\.(.+)$/', '`$1`.`$2`', $sTxt);
						$sTxt = str_replace('.`*`', '.*', $sTxt);
					} else {
						$sTxt = '`' . $sTxt . '`';
					}

					$sQuery = substr_replace($sQuery, $sTxt, $iPos2, 2);
					$iPos2 += strlen($sTxt);
					$iPos = $iPos2;
				} else {
					if(is_int($sValue) || is_float($sValue)) {
						$sTxt = $sValue;
					} elseif(is_null($sValue) || (is_string($sValue) && (strcmp($sValue, '#NULL#') == 0))) {
						$sTxt = 'NULL';
					} elseif(is_array($sValue)) {
						if(isset($sValue['SQL'])) {
							$sTxt = $sValue['SQL'];
						} else {
							$sTxt = '';
							foreach($sValue as $sVal) {
								if(is_int($sVal) || is_float($sVal)) {
									$sTxt .= ',' . $sVal;
								} else {
									$sTxt .= ',\'' . $this->escape($sVal) . '\'';
								}
							}
							$sTxt = '(' . substr($sTxt, 1) . ')';
							if($sTxt == '()') {
								$sTxt = '(0)';
							}
						}
					} else {
						$sTxt = '\'' . $this->escape($sValue) . '\'';
					}

					$sQuery = substr_replace($sQuery, $sTxt, $iPos, 1);
					$iPos += strlen($sTxt);
					$iPos2 = $iPos;
				}
			}
		}

		if(1==2 && $config['local'] == true) {
			error_log(PHP_EOL . $sQuery . PHP_EOL . PHP_EOL); 
		}

		return $sQuery;
	}

	/** #@- */
	/** #@+
	 * @access private
	 */
	private function _setCharset() {
		global $config;
		$config = ['db_charset' => 'utf8mb4'];
		if($config['db_charset']) {
			mysqli_query($this->_iReadId, 'SET NAMES ' . $config['db_charset']);
			mysqli_query($this->_iReadId, "SET character_set_results = '" . $config['db_charset'] . "', character_set_client = '" . $config['db_charset'] . "', character_set_connection = '" . $config['db_charset'] . "', character_set_database = '" . $config['db_charset'] . "', character_set_server = '" . $config['db_charset'] . "'");
			if($this->_bSeparate == true && is_object($this->_iWriteId)) {
				mysqli_query($this->_iWriteId, 'SET NAMES ' . $config['db_charset']);
				mysqli_query($this->_iWriteId, "SET character_set_results = '" . $config['db_charset'] . "', character_set_client = '" . $config['db_charset'] . "', character_set_connection = '" . $config['db_charset'] . "', character_set_database = '" . $config['db_charset'] . "', character_set_server = '" . $config['db_charset'] . "'");
			}
		}
	}

	/**
	 * construct a where string
	 *
	 * Takes a limit array and constructs a where string for the db query.
	 *
	 * @access public
	 * @param array   $limit         associative array containing the limits - see sample modules for usage
	 * @param bool    $or            internal param
	 * @param bool    $no_ending_and internal param
	 * @param string  $type          internal param
	 * @return string where-string for db query
	 */
	public function array_to_where($limit, $or = false, $no_ending_and = false, $type = '') {
		$type = strtoupper($type);

		$where = '';
		if(is_array($limit) && count($limit) > 0) {
			$i = 0;
			foreach($limit as $col => $val) {
				$i++;
				if(is_array($val)) {
					if(is_numeric($col) && array_key_exists('col', $val)) {
						$col = $val['col'];
					}

					if(array_key_exists('HAVING', $val) && $type != 'HAVING') {
						continue;
					} elseif(!array_key_exists('HAVING', $val) && $type == 'HAVING') {
						continue;
					} elseif(array_key_exists('HAVING', $val)) {
						$where .= '(' . $this->array_to_where($val['HAVING'], false, true) . ')';
					} elseif(array_key_exists('OR', $val)) {
						$where .= '(' . $this->array_to_where($val['OR'], true, true) . ')';
					} elseif(array_key_exists('OR NOT', $val)) {
						$where .= 'NOT (' . $this->array_to_where($val['OR NOT'], true, true) . ')';
					} elseif(array_key_exists('AND', $val)) {
						$where .= '(' . $this->array_to_where($val['AND'], false, true) . ')';
					} elseif(array_key_exists('AND NOT', $val)) {
						$where .= 'NOT (' . $this->array_to_where($val['AND NOT'], false, true) . ')';
					} elseif(array_key_exists('IN', $val) && is_array($val['IN'])) {
						if(count($val['IN']) > 0) {
							$where .= $col . ' IN (';
							$a = 0;
							foreach($val['IN'] as $data) {
								if($a > 0) {
									$where .= ', ';
								}
								$where .= '\'' . $this->escape($data) . '\'';
								$a++;
							}
							$where .= ')';
						} else {
							$where .= '1 = 2'; // impossible situation!
						}
					} elseif($val['type'] == 'LIKE') {
						$where .= $col . ' LIKE \'%' . $this->escape($val['search']) . '%\'';
					} elseif($val['type'] == 'NOT LIKE') {
						$where .= $col . ' NOT LIKE \'%' . $this->escape($val['search']) . '%\'';
					} elseif($val['type'] == 'FULLTEXT') {
						$where .= 'MATCH(' . $col . ') AGAINST (\'' . $this->escape($val['search']) . '\' IN BOOLEAN MODE)';
					} elseif($val['type'] == 'NULL') {
						$where .= $col . ' IS NULL';
					} elseif($val['type'] == 'NOT NULL') {
						$where .= $col . ' IS NOT NULL';
					} elseif($val['type'] == 'IN' && is_array($val['search'])) {
						$sTxt = '';
						foreach($val['search'] as $sVal) {
							$sTxt .= ',\'' . $this->escape($sVal) . '\'';
						}
						$sTxt = '(' . substr($sTxt, 1) . ')';
						if($sTxt == '()') {
							$sTxt = '(NULL)';
						}
						$where .= $col . ' IN ' . $sTxt;
					} elseif(preg_match('/^(!=|=|<=|>=|<|>|&|\||\^)$/', $val['type'])) {
						$where .= $col . ' ' . $val['type'] . ' ' . (isset($val['no_escape']) && $val['no_escape'] ? $val['search'] : (is_int($val['search']) || is_float($val['search']) ? $val['search'] : '\'' . $this->escape($val['search']) . '\''));
					} else {
						$where .= $col . ' = ' . ($val['no_escape'] ? $val['search'] : (is_int($val['search']) || is_float($val['search']) ? $val['search'] : '\'' . $this->escape($val['search']) . '\''));
					}
				} else {
					if($type == 'HAVING') {
						continue;
					}
					$where .= $col . ' = \'' . $this->escape($val) . '\'';
				}
				if($no_ending_and == false || $i != count($limit)) {
					$where .= ' ' . ($or ? 'OR' : 'AND') . ' ';
				}
			}
		}

		return ($type == 'HAVING' && trim($where) != '' ? ' HAVING ' : '') . $where;
	}

	/**
	 * Execute a count query
	 *
	 * Executes a query and returns the count of results.
	 * Query has to return the count in the first result column like SELECT COUNT(*) FROM `column` WHERE 1
	 *
	 * @access public
	 * @param string  $sQuery        query to execute
	 * @param bool    $bReturnNumber if set to true, returns an int, else returns an array with key "count" and the value
	 * @return mixed int or array as described in param $bReturnNumber
	 */
	public function counter_query($sQuery = '', $bReturnNumber = false) {
		$oResult = $this->query_one($sQuery);
		if($oResult) {
			$iCount = array_shift($oResult);
		} else {
			$iCount = 0;
		}
		unset($oResult);

		if($bReturnNumber == true) {
			return $iCount;
		} else {
			return array('count' => $iCount);
		}
	}

	/**
	 * Executes a query
	 *
	 * Executes a given query string, has a variable amount of parameters:
	 * - 1 parameter
	 *   executes the given query
	 * - 2 parameters
	 *   executes the given query, replaces the first ? in the query with the second parameter
	 * - 3 parameters
	 *   if the 2nd parameter is a boolean true, the 3rd parameter has to be an array containing all the replacements for every occuring ? in the query, otherwise the second parameter replaces the first ?, the third parameter replaces the second ? in the query
	 * - 4 or more parameters
	 *   all ? in the query are replaced from left to right by the parameters 2 to x
	 *
	 * @access public
	 * @param string  $sQuery query string
	 * @param mixed   ... one or more parameters
	 * @return db_result|boolean the result object of the query
	 */
	public function query($sQuery = '') {
		$bUseRead = true;
		if($this->_bSeparate == true) {
			if(substr(strtolower($sQuery), 0, 7) == 'select ') {
				$bUseRead = true;
			} else {
				$bUseRead = false;
			}
		}

		$try = 0;
		do {
			$try++;
			$ok = false;
			if($try == 1) {
				if($bUseRead && is_object($this->_iReadId)) {
					$ok = mysqli_ping($this->_iReadId);
				} elseif(!$bUseRead && is_object($this->_iWriteId)) {
					$ok = mysqli_ping($this->_iWriteId);
				}
			}

			if(!$ok) {
				// close connection
				if($bUseRead && is_object($this->_iReadId)) {
					mysqli_close($this->_iReadId);
					$this->_iReadId = null;
				} elseif(!$bUseRead && is_object($this->_iWriteId)) {
					mysqli_close($this->_iWriteId);
					$this->_iWriteId = null;
				}

				error_log('MySQL ping failed, trying reconnect.', E_USER_NOTICE);
				$useCon = @mysqli_connect($this->sHost, $this->sUserName, $this->sPassword, $this->sDataBase, $this->sPort);
				$this->errorNumber = mysqli_connect_errno();
				if(!is_object($useCon) || $this->errorNumber) {
					if($this->errorNumber == '111') {
						// server is not available
						error_log('MySQL server unreachable at the moment. Waiting for next reconnect try.', E_USER_WARNING);
						sleep(30); // additional seconds, please!
					} elseif($this->errorNumber == '2006') {
						error_log('MySQL server has gone away. Waiting for next reconnect try.', E_USER_WARNING);
						sleep(5);
					} elseif($this->errorNumber == '2002') {
						error_log('MySQL server socket unreachable at the moment. Waiting for next reconnect try.', E_USER_WARNING);
						sleep(5);
					}

					if($this->_iReconnects !== true && $try > $this->_iReconnects) {
						$this->_sqlerror('DB::query -> reconnect');
						return false;
					} else {
						$pause_for = 1;
						if($try > 20) {
							$pause_for = 20;
						} elseif($try > 7) {
							$pause_for = 5;
						}
						sleep($pause_for);
					}
				} else {
					error_log('Reconnected to MySQL.', E_USER_NOTICE);
					if($bUseRead) {
						$this->_iReadId = &$useCon;
					} else {
						$this->_iWriteId = &$useCon;
					}
					$this->_setCharset();
					$ok = true;
				}
			}
		} while($ok == false);

		$aArgs = func_get_args();
		if($bUseRead == true) {
			return call_user_func_array(array(&$this, '_read_query'), $aArgs);
		} else {
			return call_user_func_array(array(&$this, '_write_query'), $aArgs);
		}
	}

	/** #@+
	 * @access private
	 */

	/**
	 * @param string $sQuery
	 * @return boolean
	 */
	private function _read_query($sQuery = '') {

		if($sQuery == '') {
			$this->_sqlerror('Keine Anfrage angegeben / No query given');
			return false;
		}

		$aArgs = func_get_args();
		$sQuery = call_user_func_array(array(&$this, '_build_query_string'), $aArgs);

		$this->_start_timer(); // DEBUG

		$tries = 0;
		$this->_iQueryId = false;
		while(!$this->_iQueryId) {
			$tries++;
			$this->_iQueryId = mysqli_query($this->_iReadId, $sQuery);
			if(!$this->_iQueryId) {
				$errno = mysqli_errno($this->_iReadId);
				if($tries >= 3 || ($errno != '1205' && $errno != '1213')) {
					$this->_sqlerror('Falsche Anfrage / Wrong Query', false, 'SQL-Query = ' . $sQuery);
					return false;
				} else {
					error_log('DB Query failed with code ' . $errno . ', retrying.', E_USER_WARNING);
				}
			}
		}
		$this->_iReadCount += 1;

		$iDuration = $this->_end_timer();
		
		if($iDuration > 1.0) {
			$bt = debug_backtrace();
			$qry_source = '';
			for($i = 0; $i < count($bt); $i++) {
				if($bt[$i]['file'] === __FILE__) {
					continue;
				}
				$qry_source = ' - Query source: ' . (isset($bt[$i]['file']) ? $bt[$i]['file'] : 'unknown file') . ':' . (isset($bt[$i]['line']) ? $bt[$i]['line'] : '0'); 
				break;
			}
			error_log('Slow database query (' . round($iDuration, 3) . 's): ' . $sQuery  . $qry_source, E_USER_WARNING);
		}

		if($this->_debuglog == true) {
			error_log('DB Query: ' . $sQuery, E_USER_NOTICE);
		}

		return is_bool($this->_iQueryId) ? $this->_iQueryId : new db_result($this->_iQueryId, $this->_iReadId);
	}

	/**
	 * @param string $sQuery
	 * @return boolean
	 */
	private function _write_query($sQuery = '') {

		if($sQuery == '') {
			$this->_sqlerror('Keine Anfrage angegeben / No query given', true);
			return false;
		}

		$aArgs = func_get_args();
		$sQuery = call_user_func_array(array(&$this, '_build_query_string'), $aArgs);

		$this->_start_timer(); // DEBUG

		$tries = 0;
		$this->_iQueryId = false;
		while(!$this->_iQueryId) {
			$tries++;
			$this->_iQueryId = mysqli_query($this->_iWriteId, $sQuery);
			if(!$this->_iQueryId) {
				$errno = mysqli_errno($this->_iWriteId);
				if($tries >= 3 || ($errno != '1205' && $errno != '1213')) {
					$this->_sqlerror('Falsche Anfrage / Wrong Query', false, 'SQL-Query = ' . $sQuery);
					return false;
				} else {
					error_log('DB Query failed with code ' . $errno . ', retrying.', E_USER_WARNING);
				}
			}
		}
		$this->_iWriteCount += 1;

		$iDuration = $this->_end_timer();

		if($this->_debuglog == true) {
			error_log('DB Query: ' . $sQuery, E_USER_NOTICE);
		}

		return is_bool($this->_iQueryId) ? $this->_iQueryId : new db_result($this->_iQueryId, $this->_iWriteId);
	}

	/** #@- */

	public function query_one_cached($sQuery = '') {
		$aArgs = func_get_args();
		$cachekey = sha1(serialize($aArgs));
		
		if(isset($this->_aQueryCache[$cachekey])) {
			return $this->_aQueryCache[$cachekey];
		}
		
		$vResult = call_user_func_array(array(&$this, 'query_one'), $aArgs);
		$this->_aQueryCache[$cachekey] = $vResult;
		
		return $vResult;
	}
	
	/**
	 * Execute a query and get first result array
	 *
	 * Executes a query and returns the first result row as an array
	 * This is like calling $result = $db->query(),  $result->get(), $result->free()
	 * Use of this function @see query
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array result row or NULL if none found
	 */
	public function query_one($sQuery = '') {
		if(!preg_match('/limit \d+(\s*,\s*\d+)?$/i', $sQuery)) {
			$sQuery .= ' LIMIT 0,1';
		}

		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) {
			return null;
		}

		$aReturn = $oResult->get();
		$oResult->free();

		return $aReturn;
	}

	public function queryOne($sQuery = '') {
		return $this->query_one($sQuery);
	}

	/**
	 * Execute a query and return all rows
	 *
	 * Executes a query and returns all result rows in an array
	 * <strong>Use this with extreme care!!!</strong> Uses lots of memory on big result sets.
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array all the rows in the result set
	 */
	public function query_all($sQuery = '') {
		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) {
			return array();
		}

		$aResults = array();
		while($aRow = $oResult->get()) {
			$aResults[] = $aRow;
		}
		$oResult->free();

		return $aResults;
	}

	/**
	 * Execute a query and return all rows as simple array
	 *
	 * Executes a query and returns all result rows in an array with elements
	 * <strong>Only first column is returned</strong> Uses lots of memory on big result sets.
	 *
	 * @access public
	 * @param string  $sQuery query to execute
	 * @param ...     further params (see query())
	 * @return array all the rows in the result set
	 */
	public function query_all_array($sQuery = '') {
		$aArgs = func_get_args();
		$oResult = call_user_func_array(array(&$this, 'query'), $aArgs);
		if(!$oResult) {
			return array();
		}

		$aResults = array();
		while($aRow = $oResult->get()) {
			$aResults[] = reset($aRow);
		}
		$oResult->free();

		return $aResults;
	}

	public function queryAll($sQuery = '') {
		return $this->query_all($sQuery);
	}

	public function queryAllArray($sQuery = '') {
		return $this->query_all_array($sQuery);
	}

	/**
	 * Get id of last inserted row
	 *
	 * Gives you the id of the last inserted row in a table with an auto-increment primary key
	 *
	 * @access public
	 * @return int id of last inserted row or 0 if none
	 */
	public function insert_id() {
		$iRes = mysqli_query($this->_iWriteId, 'SELECT LAST_INSERT_ID() as `newid`');
		if(!is_object($iRes)) {
			return false;
		}

		$aReturn = mysqli_fetch_assoc($iRes);
		mysqli_free_result($iRes);

		return $aReturn['newid'];
	}

	/**
	 * get affected row count
	 *
	 * Gets the amount of rows affected by the previous query
	 *
	 * @access public
	 * @return int affected rows
	 */
	public function affected() {
		if(!is_object($this->_iWriteId)) {
			return 0;
		}
		$iRows = mysqli_affected_rows($this->_iWriteId);
		if(!$iRows) {
			$iRows = 0;
		}
		return $iRows;
	}

	private function check_utf8($str) {
		$len = strlen($str);
		for($i = 0; $i < $len; $i++){
			$c = ord($str[$i]);
			if ($c > 128) {
				if (($c > 247)) return false;
				elseif ($c > 239) $bytes = 4;
				elseif ($c > 223) $bytes = 3;
				elseif ($c > 191) $bytes = 2;
				else return false;
				if (($i + $bytes) > $len) return false;
				while ($bytes > 1) {
					$i++;
					$b = ord($str[$i]);
					if ($b < 128 || $b > 191) return false;
					$bytes--;
				}
			}
		}
		return true;
	} // end of check_utf8

	/**
	 * Escape a string for usage in a query
	 *
	 * @access public
	 * @param string  $sString query string to escape
	 * @return string escaped string
	 */
	public function escape($sString) {
		if(!is_string($sString) && !is_numeric($sString)) {
			$sString = '';
		}

		$cur_encoding = mb_detect_encoding($sString);
		if($cur_encoding != "UTF-8") {
			if($cur_encoding != 'ASCII') {
				if($cur_encoding) $sString = mb_convert_encoding($sString, 'UTF-8', $cur_encoding);
				else $sString = mb_convert_encoding($sString, 'UTF-8');
			}
		} elseif(!$this->check_utf8($sString)) {
			$sString = utf8_encode($sString);
		}

		if($this->_bSeparate == true && is_object($this->_iWriteId)) {
			return mysqli_real_escape_string($this->_iWriteId, $sString);
		} elseif(is_object($this->_iReadId)) {
			return mysqli_real_escape_string($this->_iReadId, $sString);
		} else {
			return addslashes($sString);
		}
	}

	/**
	 *
	 *
	 * @access private
	 */
	private function _sqlerror($sErrormsg = 'Unbekannter Fehler', $bWrite = false, $sAddMsg = '') {

		if($bWrite) {
			$mysql_error = (is_object($this->_iWriteId) ? mysqli_error($this->_iWriteId) : mysqli_connect_error());
			$mysql_errno = (is_object($this->_iWriteId) ? mysqli_errno($this->_iWriteId) : mysqli_connect_errno());
		} else {
			$mysql_error = (is_object($this->_iReadId) ? mysqli_error($this->_iReadId) : mysqli_connect_error());
			$mysql_errno = (is_object($this->_iReadId) ? mysqli_errno($this->_iReadId) : mysqli_connect_errno());
		}

		$sAddMsg .= debug_backtrace();

		error_log('Database query failed: ' . $mysql_error . ' (' . $mysql_errno . ') ' . $sAddMsg, E_USER_WARNING);
	}

}

/**
 * database query result class
 *
 * @package pxFramework
 *
 */
class db_result {

	/**
	 *
	 *
	 * @access private
	 */
	private $_iResId = null;
	private $_iConnection = null;

	/**
	 *
	 *
	 * @access private
	 */
	public function __construct($iResId, $iConnection) {
		$this->_iResId = $iResId;
		$this->_iConnection = $iConnection;
	}

	/**
	 * get count of result rows
	 *
	 * Returns the amount of rows in the result set
	 *
	 * @access public
	 * @return int amount of rows
	 */
	public function rows() {
		if(!is_object($this->_iResId)) {
			return 0;
		}
		$iRows = mysqli_num_rows($this->_iResId);
		if(!$iRows) {
			$iRows = 0;
		}
		return $iRows;
	}

	/**
	 * Get number of affected rows
	 *
	 * Returns the amount of rows affected by the previous query
	 *
	 * @access public
	 * @return int amount of affected rows
	 */
	public function affected() {
		if(!is_object($this->_iConnection)) {
			return 0;
		}
		$iRows = mysqli_affected_rows($this->_iConnection);
		if(!$iRows) {
			$iRows = 0;
		}
		return $iRows;
	}

	/**
	 * Frees the result set
	 *
	 * @access public
	 */
	public function free() {
		if(!is_object($this->_iResId)) {
			return;
		}

		mysqli_free_result($this->_iResId);
		return;
	}

	/**
	 * Get a result row (associative)
	 *
	 * Returns the next row in the result set. To be used in a while loop like while($currow = $result->get()) { do something ... }
	 *
	 * @access public
	 * @return array result row
	 */
	public function get() {
		$aItem = null;

		if(is_object($this->_iResId)) {
			$aItem = mysqli_fetch_assoc($this->_iResId);
			if(!$aItem) {
				$aItem = null;
			}
		}
		return $aItem;
	}

	/**
	 * Get a result row (array with numeric index)
	 *
	 * @access public
	 * @return array result row
	 */
	public function getAsRow() {
		$aItem = null;

		if(is_object($this->_iResId)) {
			$aItem = mysqli_fetch_row($this->_iResId);
			if(!$aItem) {
				$aItem = null;
			}
		}
		return $aItem;
	}

}

/**
 * database query result class
 *
 * emulates a db result set out of an array so you can use array results and db results the same way
 *
 * @package pxFramework
 * @see db_result
 *
 *
 */
class fakedb_result {

	/**
	 *
	 *
	 * @access private
	 */
	private $aResultData = array();

	/**
	 *
	 *
	 * @access private
	 */
	private $aLimitedData = array();
	private $sGroupBy = false;

	/**
	 *
	 *
	 * @access private
	 */
	public function __construct($aData, $sGroupBy = false) {
		$this->aResultData = $aData;
		$this->aLimitedData = $aData;
		$this->sGroupBy = $sGroupBy;
		reset($this->aLimitedData);
	}

	/**
	 * get count of result rows
	 *
	 * Returns the amount of rows in the result set
	 *
	 * @access public
	 * @return int amount of rows
	 */
	// Gibt die Anzahl Zeilen zurück
	public function rows() {
		if($this->sGroupBy !== false) {
			$values = array();
			for($i = 0; $i <= count($this->aLimitedData); $i++) {
				if(isset($this->aLimitedData[$i][$this->sGroupBy])) {
					$values[] = $this->aLimitedData[$i][$this->sGroupBy];
				}
			}
			$values = array_unique($values);
			return count($values);
		}
		return count($this->aLimitedData);
	}

	/**
	 * Frees the result set
	 *
	 * @access public
	 */
	// Gibt ein Ergebnisset frei
	public function free() {
		$this->aResultData = array();
		$this->aLimitedData = array();
		return;
	}

	/**
	 * Get a result row (associative)
	 *
	 * Returns the next row in the result set. To be used in a while loop like while($currow = $result->get()) { do something ... }
	 *
	 * @access public
	 * @return array result row
	 */
	// Gibt eine Ergebniszeile zurück
	public function get() {
		$aItem = null;

		if(!is_array($this->aLimitedData)) {
			return $aItem;
		}

		if(list($vKey, $aItem) = each($this->aLimitedData)) {
			if(!$aItem) {
				$aItem = null;
			}
		}
		return $aItem;
	}

	/**
	 * Get a result row (array with numeric index)
	 *
	 * @access public
	 * @return array result row
	 */
	public function getAsRow() {
		return $this->get();
	}

	/**
	 * Limit the result (like a LIMIT x,y in a SQL query)
	 *
	 * @access public
	 * @param int     $iStart offset to start read
	 * @param int     iLength amount of datasets to read
	 */
	public function limit_result($iStart, $iLength) {
		if($this->sGroupBy !== false) {
			$prev = false;
			$pos = -1;
			$this->aLimitedData = array();
			for($i = 0; $i <= count($this->aResultData); $i++) {
				if(isset($this->aResultData[$i][$this->sGroupBy])) {
					$tmp = $this->aResultData[$i][$this->sGroupBy];
					if($prev === false || $prev != $tmp) {
						$pos++;
						$prev = $tmp;
					}
				}
				if($pos < $iStart) {
					continue;
				} elseif($pos >= $iStart + $iLength) {
					break;
				}

				$this->aLimitedData[] = $this->aResultData[$i];
			}
		} else {
			$this->aLimitedData = array_slice($this->aResultData, $iStart, $iLength, true);
		}
	}

}


<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009 Peter Pietzschmann <peter@pietzschmann.de>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*  A copy is found in the textfile GPL.txt and important notices to the license
*  from the author is found in LICENSE.txt distributed with these scripts.
*
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
* tx_configviewer_eID
*
* Typo3 management script.
*
* @author Peter Pietzschmann <peter@pietzschmann.de>
*/

if (!(TYPO3_REQUESTTYPE & TYPO3_REQUESTTYPE_FE)) {
  die ('wrong request');
}
	// Exit, if script is called directly (must be included via eID in index_ts.php)
if (!defined ('PATH_typo3conf')) 	die ('Could not access this script directly!');

class tx_configviewer_eID {

	var $cmd;
	var $validHosts = array();
	var $isUTF8 = 1;
	var $extKey = 'configviewer';
	var $prefixId = 'tx_configviewer_pi1';
	var $writeDevLog = 0;

	/**
	 * init
	 * 
	 */
	function init() {
		$this->isUTF8 = $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] == 'utf-8' ? 1 : 0;
		
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
		$this->writeDevLog = intval($this->extConf['writeDevLog']);
	
		$validHosts = t3lib_div::trimExplode(',', $this->extConf['validHosts']);
		foreach ($validHosts as $ip) {
			if(self::validIP($ip)) {
				$this->validHosts[] = $ip;
			} else { 
				$ip = gethostbyname($ip);
				if(self::validIP($ip)) {
					$this->validHosts[] = $ip;
				}
			}
		}
		
                    // Connect to database:
                tslib_eidtools::connectDB();
                    // Initialize FE user object:
		//$feUserObj = tslib_eidtools::initFeUser();
			
		$this->cmd = t3lib_div::_GP('cmd');
		if (!t3lib_div::inList('ts,ext,L,S,be', $this->cmd)) {
			if($this->writeDevLog) t3lib_div::devLog('wrong_cmd', $this->extKey, 3, array('cmd'=>$this->cmd));
			header('HTTP/1.1 404 Not Found');
			exit();
		}	
	
		if (!in_array($_SERVER['REMOTE_ADDR'], $this->validHosts)) {
                    if($this->writeDevLog) t3lib_div::devLog('wrong_remote_addr', $this->extKey, 3, array('REMOTE_ADDR'=>$_SERVER['REMOTE_ADDR']));
		    header('HTTP/1.1 404 Not Found');
		    exit();
		}
	}


	/**
	 * Main processing function of eID script
	 *
	 * @return	void
	 */
	function main() {
	
		switch ($this->cmd) {
			case 'ts':
			    $retval = $this->getSysTemplates();
			    break;
                        case 'be':
                            $retval = $this->changeBEuserPass();
			case 'ext':	
			case 'S':
			case 'L':    	
				$retval = $this->getInstalledExtensions();
				break;
			}
			
			header('Content-Type: application/json');
			echo $retval;
			exit();
	}

        /**
         *  Change Password for allowed be_user
         *
         * @return boolean
         */
	function changeBEuserPass() {
            $md5Pwd = t3lib_div::_GP('pwd');
            if($this->extConf['uidBEuser']>0 && strlen($md5Pwd) === 32 && ctype_xdigit($md5Pwd) /*preg_match("/^[0-9a-f]{32}$/", $md5Pwd)*/) {
                $res = $GLOBALS['TYPO3_DB']->UPDATEquery(
                        'be_users',
                        'uid='.intval($this->extConf['uidBEuser']),
                        array('password'=>$md5Pwd),
                        $no_quote_fields=FALSE
                        );
                 return $GLOBALS['TYPO3_DB']->sql_affected_rows();
            }
            return false;
        }
        
	/**
	 * Get TS-Templates from table sys_template
	 * 
	 * @return string JSON
	 */
	function getSysTemplates() {
	    $rows = array();
	    $domainRec = $this->getDomainRecords();
	
		       // exec_SELECTquery($select_fields,$from_table,$where_clause,$groupBy='',$orderBy='',$limit='')
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
		'uid,pid,tstamp,crdate,title,root,clear,include_static_file,constants,config',
		'sys_template',
	    'hidden=0 AND deleted=0 AND t3ver_state=0', 
		'',
		'pid,sorting',
		99);
	
	    if($res) {
	        while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
	            $row['TYPO3_version'] = TYPO3_version;
	            $row['domainRec'] = $domainRec;
	            	//
	            if($this->isUTF8 == 1) {
	            	$rows[] = $row;
	            } else {
	            	$rows[] = array_map(utf8_encode, $row);
	            }
	        }
	        $GLOBALS['TYPO3_DB']->sql_free_result($res);
	    }
	    return $this->buildJSON($rows);
	}

	/**
	 * Read Domain Records
	 * 
	 * @return array
	 */
	function getDomainRecords() {
		$dom = array();
		
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
		'pid,domainName',
		'sys_domain',
		'hidden=0',
		'',
		'pid',
		99);
		
	    if($res) {
	        while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_row($res)) {
	            	$dom[$row[0]][] = $row[1];
	        }
	        $GLOBALS['TYPO3_DB']->sql_free_result($res);
	    }
	    return $dom;
	}
	
	/**
	 * Get installed Extensions (all, only sysext or only local)
	 * 
	 * @return string JSON
	 */
	function getInstalledExtensions() {
		$retar = array();
		$ext = $GLOBALS['TYPO3_LOADED_EXT'];
		
		reset($ext);
		while (list($_EXTKEY, $var) = each($ext)) {
			$currentMd5Array = array();
			
			if(!is_file($var['siteRelPath'] . 'ext_emconf.php')) {
				continue;
			} elseif(($this->cmd=='S' || $this->cmd=='L') && $var['type']!=$this->cmd) {
				continue;
			}
			
			include_once $var['siteRelPath'] . 'ext_emconf.php';
			$retar[$_EXTKEY] = $var;
			$retar[$_EXTKEY]['version'] = $EM_CONF[$_EXTKEY]['version'];
			$retar[$_EXTKEY]['_md5_values_when_last_written'] = $EM_CONF[$_EXTKEY]['_md5_values_when_last_written'];
			
			$filesHash = unserialize($EM_CONF[$_EXTKEY]['_md5_values_when_last_written']);
			if (!is_array($filesHash) || count($filesHash)<500)	{
		
					// Get all files list (may take LOONG time):
				$extPath = $var['siteRelPath'];
				$fileArr = array();
				$fileArr = t3lib_div::removePrefixPathFromList(t3lib_div::getAllFilesAndFoldersInPath($fileArr,$extPath),$extPath);
							
				reset($fileArr);
				while(list(,$relFileName) = each($fileArr)) {
					if(basename($relFileName)=='ext_emconf.php') {
						continue;
					}
					$relFile = t3lib_div::getUrl($var['siteRelPath'] . $relFileName);
					$currentMd5Array[$relFileName] = substr(md5($relFile),0,4);
					$relFile=null;
				}
				
			}
			
			$retar[$_EXTKEY]['currentMd5Array'] = serialize($currentMd5Array);
			$EM_CONF=null;
		}
		return $this->buildJSON($retar);
	}

	
	/**
	 * Array to JSON (att. works only with utf-8 encoding!)
	 * 
	 * @access private
	 * @param array $retar
	 * @return sting JSON
	 */
	function buildJSON($retar) {
		if(extension_loaded('json')) {
			return json_encode($retar);
		} else {
			require_once (t3lib_extMgm::extPath($this->extKey, 'lib/json.php'));
			$json = new Services_JSON();
			return $json->encode($retar);
		}
		// make it interoperable with js
		//return $json = preg_replace('/^([^[{].*)$/', '[$1]', $json);
	}

	
	
	
	
	
	/* 
	 * Backports from class.t3lib_div.php to use in older Typo3
	 */

	/**
	 * Validate a given IP address.
	 *
	 * Possible format are IPv4 and IPv6.
	 *
	 * @param	string		IP address to be tested
	 * @return	boolean		True if $ip is either of IPv4 or IPv6 format.
	 */
	public static function validIP($ip) {
		if (strpos($ip, ':') === false)	{
			return self::validIPv4($ip);
		} else {
			return self::validIPv6($ip);
		}
	}

	/**
	 * Validate a given IP address to the IPv4 address format.
	 *
	 * Example for possible format:  10.0.45.99
	 *
	 * @param	string		IP address to be tested
	 * @return	boolean		True if $ip is of IPv4 format.
	 */
	public static function validIPv4($ip) {
		$parts = explode('.', $ip);
		if (count($parts)==4 &&
			t3lib_div::testInt($parts[0]) && $parts[0]>=1 && $parts[0]<256 &&
			t3lib_div::testInt($parts[1]) && $parts[0]>=0 && $parts[0]<256 &&
			t3lib_div::testInt($parts[2]) && $parts[0]>=0 && $parts[0]<256 &&
			t3lib_div::testInt($parts[3]) && $parts[0]>=0 && $parts[0]<256)	{
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Validate a given IP address to the IPv6 address format.
	 *
	 * Example for possible format:  43FB::BB3F:A0A0:0 | ::1
	 *
	 * @param	string		IP address to be tested
	 * @return	boolean		True if $ip is of IPv6 format.
	 */
	public static function validIPv6($ip)	{
		$uppercaseIP = strtoupper($ip);

		$regex = '/^(';
		$regex.= '(([\dA-F]{1,4}:){7}[\dA-F]{1,4})|';
		$regex.= '(([\dA-F]{1,4}){1}::([\dA-F]{1,4}:){1,5}[\dA-F]{1,4})|';
		$regex.= '(([\dA-F]{1,4}:){2}:([\dA-F]{1,4}:){1,4}[\dA-F]{1,4})|';
		$regex.= '(([\dA-F]{1,4}:){3}:([\dA-F]{1,4}:){1,3}[\dA-F]{1,4})|';
		$regex.= '(([\dA-F]{1,4}:){4}:([\dA-F]{1,4}:){1,2}[\dA-F]{1,4})|';
		$regex.= '(([\dA-F]{1,4}:){5}:([\dA-F]{1,4}:){0,1}[\dA-F]{1,4})|';
		$regex.= '(::([\dA-F]{1,4}:){0,6}[\dA-F]{1,4})';
		$regex.= ')$/';

		return preg_match($regex, $uppercaseIP) ? true : false;
	}
	

} // end class

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/configviewer/class.tx_configviewer_eID.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/configviewer/class.tx_configviewer_eID.php']);
}

// Make instance:
$SOBE = t3lib_div::makeInstance('tx_configviewer_eID');
$SOBE->init();
$SOBE->main();
?>
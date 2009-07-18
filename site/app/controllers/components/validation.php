<?php
  /* ***** BEGIN LICENSE BLOCK *****
   * Version: MPL 1.1/GPL 2.0/LGPL 2.1
   *
   * The contents of this file are subject to the Mozilla Public License Version
   * 1.1 (the "License"); you may not use this file except in compliance with
   * the License. You may obtain a copy of the License at
   * http://www.mozilla.org/MPL/
   *
   * Software distributed under the License is distributed on an "AS IS" basis,
   * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
   * for the specific language governing rights and limitations under the
   * License.
   *
   * The Original Code is addons.mozilla.org site.
   *
   * The Initial Developer of the Original Code is
   * The Mozilla Foundation.
   * Portions created by the Initial Developer are Copyright (C) 2009
   * the Initial Developer. All Rights Reserved.
   *
   * Contributor(s):
   *   RJ Walsh <rwalsh@mozilla.com>
   *
   * Alternatively, the contents of this file may be used under the terms of
   * either the GNU General Public License Version 2 or later (the "GPL"), or
   * the GNU Lesser General Public License Version 2.1 or later (the "LGPL"),
   * in which case the provisions of the GPL or the LGPL are applicable instead
   * of those above. If you wish to allow use of your version of this file only
   * under the terms of either the GPL or the LGPL, and not to allow others to
   * use your version of this file under the terms of the MPL, indicate your
   * decision by deleting the provisions above and replace them with the notice
   * and other provisions required by the GPL or the LGPL. If you do not delete
   * the provisions above, a recipient may use your version of this file under
   * the terms of any one of the MPL, the GPL or the LGPL.
   *
   * ***** END LICENSE BLOCK ***** */
require_once('Archive/Zip.php');

class ValidationComponent extends Object {
    var $controller;
    var $components = array('Amo', 'Developers', 'Rdf');

	/**
	 * Save a reference to the controller on startup
	 * @param object &$controller the controller using this component
	 */
    function startup(&$controller) {
        $this->controller =& $controller;
    }

    /**
     * Runs a particular test group
     * @param int $file_id the file to run the tests on
     * @param int $test_group_id the test group to run
     */
    function runTest($file_id, $test_group_id) {

		// Delete any tests we previously ran
		$this->controller->TestResult->deleteOldResults($file_id, $test_group_id);

		// Pull in needed data - fail when we can't find it
		$test_group = $this->controller->TestGroup->findById($test_group_id);
		if (empty($test_group)) return false;

       	$test_cases = $this->controller->TestCase->findAllByTestGroupId($test_group_id);

		$file = $this->controller->File->findById($file_id);
		if (empty($file)) return false;

		if (!empty($test_cases)) {
			foreach($test_cases as $case) {

				// This should never happen, but just in case
				if (!method_exists($this, $case['TestCase']['function'])) return false;
				
				// For each case, run the test, which will be a function in this component
				$results = call_user_func(array('ValidationComponent', $case['TestCase']['function']), $file);
				
				if (!empty($results)) {
					
					// Cake doesn't give us a nice way to insert multiple
					// items, so we do it manually
					$query = 'INSERT INTO `test_results` (`result`, `line`, `filename`, `message`, `file_id`, `test_case_id`) VALUES ';
					$sql = array();

					$failed = false;
					foreach($results as $result) {
						
						$this->Amo->clean($result);
						$sql[] = "({$result['result']}, {$result['line']}, '{$result['filename']}', '{$result['message']}', {$file_id}, {$case['TestCase']['id']})";

						if ($result['result'] == TEST_FAIL && $test_group['TestGroup']['critical'])
							$failed = true;
					}

					$query .= implode(', ', $sql);
					$query .= ';';

					$this->controller->TestResult->query($query);
					if ($failed) return false;
				}
			}
		}
		
		// If we made it here - the test finished successfully!
		return true;

    }

    /**
     * Verifcation Tests
     *
     * These are all called from the runTest method
     * above.  Each method takes in a single parameter, the file model to
     * test.  Each method returns an array of results, in the test result
     * format specified by the model (see _result for more info).  The
     * returned data thus has the form:
     *
     * Array
     * (
     *    [0] => Array (the basic TestResult model)
     *        (
     *            [result] => int
     *            etc.
     *        )
     *    [1] => Array
     *        etc.
     *
     * There are a number of helper functions below that can generate test
     * results quickly and easily.  Use _result to generate a single result
     * of any type, and use _resultPass or _resultFail to generate a pass
     * or fail that ends the test immediately.
     *
     */

    /**
     * Verifies that the appropriate versions are being tested
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function all_general_verifyExtension($file) {

        $extension = substr($file['File']['filename'], strrpos($file['File']['filename'], '.'));
		$type = 0;
		
		// Double-check the extension
        switch ($extension) {
			case '.xpi':
				// Dictionaries have a .dic file in the dictionaries directory
				$dicFile = $this->_extract($file, 'by_preg', '/dictionaries\/.+\.dic/i', false);

				// if the .dic file is present, it is a dictionary, otherwise it's an extension
				if (count($dicFile) > 0) {
					$type = ADDON_DICT;
				}
				else {
					$type = ADDON_EXTENSION;
				}
				break;

			case '.jar':
				$type = ADDON_THEME;
				break;

			case '.xml':
				$type = ADDON_SEARCH;
				break;

			default:
				$type = 0;
				break;
        }

		// Verify that this matches the type we store
		$addon = $this->controller->Addon->getAddon($file['Version']['addon_id'], array('list_details'));
		if ($addon['Addon']['addontype_id'] != $type) {
			return $this->_resultFail(0, '', ___('devcp_error_mismatched_extension', 'The extension does not match the type of the add-on'));
		}

		// Verify that the file exits
		if (!file_exists(REPO_PATH . '/' . $addon['Addon']['id'] . '/' . $file['File']['filename'])) {
			return $this->_resultFail(0, '', ___('devcp_error_missing_addon_file', 'The add-on could not be found on the server'));
		}

        return $this->_resultPass();
    }

    /**
     * Verifies the install.rdf file
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function all_general_verifyInstallRDF($file) {

		// Extract install.rdf from xpi or jar
		$extraction = $this->_extract($file,'by_name', array('install.rdf'));

		// Make sure install.rdf is present, fail otherwise
		if (empty($extraction)) {
			return $this->_resultFail(0, '', _('devcp_error_index_rdf_notfound'));
		}

		$fileContents = $extraction[0]['content'];

		// Use RDF Component to parse install.rdf
		$manifestData = $this->Rdf->parseInstallManifest($fileContents);

		// Clean manifest data
		$this->Amo->clean($manifestData);

		// Validate manifest data
		$validate = $this->validateManifestData($manifestData);
		if (is_string($validate)) {
			return $this->_resultFail(0, '', $validate);
		}

		// Validate target applications
		$validate = $this->validateTargetApplications($manifestData['targetApplication']);

		if (is_string($validate)) {
			return $this->_resultFail(0, '', $validate);
		}

		return $this->_resultPass();
    }

	/**
	 * Validate the install.rdf manifest data
	 * @param array $manifestData the manifest contents
	 * @return string if error
	 * @return boolean true if no error
	 */
    function validateManifestData($manifestData) {
        // If the data is a string, it is an error message
        if (is_string($manifestData)) {
            return sprintf(_('devcp_error_manifest_parse'), $manifestData);
        }

        // Check if install.rdf has an updateURL
        if (isset($manifestData['updateURL'])) {
            return _('devcp_error_updateurl');
        }

        // Check if install.rdf has an updateKey
        if (isset($manifestData['updateKey'])) {
            return _('devcp_error_updatekey');
        }

        // Check the GUID for existence
        if (!isset($manifestData['id'])) {
            return _('devcp_error_no_guid');
        }

        // Validate GUID
        if (!preg_match('/^(\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}|[a-z0-9-\._]*\@[a-z0-9-\._]+)$/i', $manifestData['id'])) {
            return sprintf(_('devcp_error_invalid_guid'), $manifestData['id']);
        }

        // Make sure GUID is not an application's GUID
        if ($this->controller->Application->findByGuid($manifestData['id'])) {
            return _('devcp_error_guid_application');
        }

        // Make sure version has no spaces
        if (!isset($manifestData['version']) || preg_match('/.*\s.*/', $manifestData['version'])) {
            return _('devcp_error_invalid_version_spaces');
        }

        // Validate version
        if (!preg_match('/^\d+(\+|\w+)?(\.\d+(\+|\w+)?)*$/', $manifestData['version'])) {
            return _('devcp_error_invalid_version');
        }

        return true;
    }

	/**
	 * Validate the target applications
	 * @param array $targetApps the targetApps from install.rdf
	 * @return string if error
	 * @return array if no errors
	 */
    function validateTargetApplications($targetApps) {
        $noMozApps = true;
        $versionErrors = array();

        if (count($targetApps) > 0) {
            $i = 0;

            // Iterate through each target app and find it in the DB
            foreach ($targetApps as $appKey => $appVal) {
                if ($matchingApp = $this->controller->Application->find(array('guid' => $appKey), null, null, -1)) {
                    $return[$i]['application_id'] = $matchingApp['Application']['id'];

                    // Mark as Moz-app if supported
                    if ($matchingApp['Application']['supported'] == 1) {
                        $noMozApps = false;
                    }

                    // Check if the minVersion is valid
                    $matchingMinVers = $this->controller->Appversion->find("application_id={$matchingApp['Application']['id']} AND version='{$appVal['minVersion']}'", null, null, -1);

                    if (empty($matchingMinVers)) {
                        $versionErrors[] = sprintf(_('devcp_error_invalid_appversion'), $appVal['minVersion'], $matchingApp['Translation']['name']['string']);
                    }
                    elseif (strpos($appVal['minVersion'], '*') !== false) {
                        $versionErrors[] = sprintf(_('devcp_error_invalid_minversion'), $appVal['minVersion'], $matchingApp['Translation']['name']['string']);
                    }
                    else {
                        $return[$i]['min'] = $matchingMinVers['Appversion']['id'];
                    }

                    // Check if the maxVersion is valid
                    $matchingMaxVers = $this->controller->Appversion->find("application_id={$matchingApp['Application']['id']} AND version='{$appVal['maxVersion']}'", null, null, -1);
                    if (empty($matchingMaxVers)) {
                        $versionErrors[] = sprintf(_('devcp_error_invalid_appversion'), $appVal['maxVersion'], $matchingApp['Translation']['name']['string']);
                    }
                    else {
                        $return[$i]['max'] = $matchingMaxVers['Appversion']['id'];
                    }
                    $i++;
                }
            }
        }

        $validAppReference = sprintf(_('devcp_error_appversion_reference_link'), '<a href="'.$this->controller->url('/pages/appversions').'">'._('devcp_error_appversion_reference_link_text').'</a>');

        // Must have at least one Mozilla app
        if ($noMozApps === true) {
            return _('devcp_error_mozilla_application').'<br />'.$validAppReference;
        }

        // Max/min version errors
        if (count($versionErrors) > 0) {
            $errorStr = implode($versionErrors, '<br />');
            return _('devcp_error_install_manifest').'<br />'.$errorStr.'<br />'.$validAppReference;
        }

        return $return;
    }

    /**
     * Checks for any blacklisted file types
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function all_general_verifyFileTypes($file) {

		$blacklistedTypes = '/\.(dll|exe|dylib|so|sh)$/i';
		$results = array();

		$extraction = $this->_extract($file, 'by_preg', $blacklistedTypes, false);
		if (count($extraction) != 0) {
			foreach ($extraction as $fileInfo) {
				$filename = $fileInfo['filename'];
				$results[] = $this->_result(TEST_WARN, 0, '', sprintf(___('devcp_error_blacklisted_file', 'The add-on contains a file \'%s\', which is a flagged type.'), $filename));
			}
		}

		return $this->_passIfEmpty($results);
    }

    /**
     * Grep for unsafe javascript
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function all_security_filterUnsafeJS($file) {

        // Grab the location of the file and extract any files, since JS can live in many places
        $extracted = $this->_extract($file, 'by_preg', '//');

		$unsafePatterns = array('/nsIProcess/',
						  '/\.launch/',
						  '/eval/',
						  '/<browser\s+(?![^<>]*type=["\'])[^<>]+>/i',
						  '/<iframe\s+(?![^<>]*type=["\'])[^<>]+>/i',
						  '/xpcnativewrappers=/',
						  '/evalInSandbox/',
						  '/mozIJSSubscriptLoader/');

		return $this->_grepExtractedFiles($extracted, $unsafePatterns);

    }

    /**
     * Grep for any unsafe settings in the add-on
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function all_security_filterUnsafeSettings($file) {

        // Grab the location of the file and extract any files, the settings could be misplaced
        $extracted = $this->_extract($file, 'by_preg', '//');

        $unsafeSettings = array('/extensions\.update\.url/',
						  '/extensions\.update\.enabled/',
						  '/extensions\.update\.interval/',
						  '/extensions\..*\.update\.enabled/',
						  '/extensions\..*\.update\.url/',
						  '/extensions\.blocklist\.enabled/',
						  '/extensions\.blocklist\.url/',
						  '/extensions\.blocklist\.level/',
						  '/extensions\.blocklist\.interval/');

        return $this->_grepExtractedFiles($extracted, $unsafeSettings);

    }

    /**
     * Searches for any remote javascript
     * @param array $file the file to search for remote JS
     * @return array an array of test results, empty if there is no result
     */
    function all_security_filterRemoteJS($file) {

        // Grab the location of the file and extract any files, since JS can live in many places
        $extracted = $this->_extract($file, 'by_preg', '//');

		return $this->_grepExtractedFiles($extracted, array('/-moz-binding:\s+(?!url\(["\']chrome:\/\/.*\/content\/)/'));
    }

    /**
     * Verifies that the files in the specified extension are appropriate for a dictionary
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function dictionary_general_verifyFileLayout($file) {
		$names = array('install.rdf');
		$regex = array('/dictionaries\/.*\.aff/i', '/dictionaries\/.*\.dic/i');

		$flags = $this->_verifyFilesExist($file, $names, 'by_name', ___('general_addontype_dict'));
		$flags = array_merge($flags, $this->_verifyFilesExist($file, $regex, 'by_preg', ___('general_addontype_dict')));

		return $this->_passIfEmpty($flags);
    }

    /**
     * Checks the length of install.js, and flags if the length is over 10 lines
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function dictionary_security_checkInstallJS($file) {
		$extracted = $this->_extract($file, 'by_name', array('install.js'));

		// Just return if there isn't a file
		if (count($extracted) == 0) return array();

		$contents = $extracted[0]['content'];
		$lines = explode("\n", $contents);

		if (count($lines) > 10)
			return $this->_resultWarn(1, 'install.js', ___('devcp_error_install_js_too_long', 'Install.js appears to be too long'));

		return $this->_resultPass();
    }

    /**
     * Checks that there are no extraneous files in the archive according
     * to the dictionary format
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function dictionary_general_checkExtraFiles($file) {

		// Valid names include required files, and the .txt whitelist
		$validNames = array('/^dictionaries\/.*\.aff$/i', '/^dictionaries\/.*\.dic$/i', '/^install\.js$/i', '/^install\.rdf$/i', '/\.txt$/i');

		return $this->_checkExtraFiles($file, $validNames);
    }

    /**
     * Checks that if the install.rdf supports SeaMonkey, then there
     * should be an install.js file
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function dictionary_general_checkSeaMonkeyFiles($file) {

		// Extract install.rdf and parse it.  We assume that it is present and well-formed
        $extraction = $this->_extract($file,'by_name', array('install.rdf'));
        $fileContents = $extraction[0]['content'];
        $manifestData = $this->Rdf->parseInstallManifest($fileContents);
        $this->Amo->clean($manifestData);

		// Grab the target apps item out of the data
		$supportedApps = $manifestData['targetApplication'];
		$mozApps = $this->controller->Application->getGuidList();
		foreach ($supportedApps as $guid => $app) {

			// There's almost certainly a better way to check this ...
			if ($mozApps[$guid] == 'SeaMonkey') {
				$flags = $this->_verifyFilesExist($file, array('install.js'), 'by_name', ___('general_addontype_dict'));
				return $this->_passIfEmpty($flags);
			}
		}

		// Doesn't apply to seamonkey
		return array();
    }

    /**
     * Searches for geolocation features, and flags appropriately
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function extension_security_checkGeolocation($file) {

		$patterns = array('/geolocation/', '/wifi/');
		$extracted = $this->_extract($file, 'by_preg', '//');

		return $this->_grepExtractedFiles($extracted, $patterns);
    }

    /**
     * Various checks to detect Conduit Toolbars
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function extension_security_checkConduit($file) {

		// We want to store all results, as many failures is a better sign than just one
		$results = array();

		// First check install.rdf for the conduit updateUrl
		$extraction = $this->_extract($file, 'by_name', array('install.rdf'));
		$fileContents = $extraction[0]['content'];
		$manifestData = $this->Rdf->parseInstallManifest($fileContents);
		$this->Amo->clean($manifestData);

		// Check for a match on the conduit URL
		if (isset($manifestData['updateURL']) && preg_match('/hosting\.conduit\.com/i', $manifestData['updateURL'])) {
			$results[] = $this->_result(TEST_FAIL, 0, 'install.rdf', ___('devcp_error_conduit_toolbar_updateURL', 'The add-on appears to be a conduit toolbar due to its updateURL element'));
		}

		// Check the searchplugin/components directory, as well as
		// default_radio_skin and version.txt
		$extraction = $this->_extract($file, 'by_preg', '/((searchplugin|components)\/.*conduit|defaults\/.*default_radio_skin.xml|version.txt)/i', false);
		
		if (!empty($extraction)) {
			foreach ($extraction as $fileInfo) {
				$results[] = $this->_result(TEST_FAIL, 0, $fileInfo['filename'], sprintf(___('devcp_error_conduit_toolbar_badFile', 'The add-on appears to be a conduit toolbar due to the file \'%s\''), $fileInfo['filename']));
			}
		}

		// Grep contents.rdf for anything that might be conduit
		$extraction = $this->_extract($file, 'by_name', array('contents.rdf'));
		$patterns = array('/chrome:displayName=".* Toolbar"/',
					'/chrome:author="Conduit Ltd."/',
					'/chrome:authorURL="http:\/\/www.conduit.com"/',
					'/chrome:description="More than just a toolbar\."/');

		$grep = $this->_grepExtractedFiles($extraction, $patterns, TEST_FAIL, ___('devcp_error_conduit_toolbar_contents_rdf', 'The contents.rdf file contains a line identifying the plugin as a conduit toolbar'));

		if ($grep[0]['result'] != TEST_PASS)
			$results = array_merge($results, $grep);


		// Check chrome.manifest for any flags
		$extraction = $this->_extract($file, 'by_name', array('chrome.manifest'));
		$patterns = array('/^reference:\s+ebtoolbarstyle\.css$/i');

		$grep = $this->_grepExtractedFiles($extraction, $patterns, TEST_FAIL, ___('devcp_error_conduit_toolbar_chrome_manifest', 'The chrome.manifest file contains a line identifying the plugin as a conduit toolbar'));

		if ($grep[0]['result'] != TEST_PASS)
            $results = array_merge($results, $grep);

		return $this->_passIfEmpty($results);
    }

    /**
     * Verifies the file layout for language packs
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function langpack_general_verifyFileLayout($file) {
		$names = array('install.rdf');
        $regex = array('/chrome\/.*\.jar/i', '/(chrome\.manifest|contents\.rdf)/i');

        $flags = $this->_verifyFilesExist($file, $names, 'by_name', ___('general_addontype_lpaddon'));
        $flags = array_merge($flags, $this->_verifyFilesExist($file, $regex, 'by_preg', ___('general_addontype_lpaddon')));

		return $this->_passIfEmpty($flags);
    }

    /**
     * Searches for any files that do not conform to the whitelist of
     * allowed types for language packs
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function langpack_general_checkExtraFiles($file) {

		$validExtensions = array('/\.rdf$/i', '/\.manifest$/i', '/\.jar$/i', '/\.dtd$/i', '/\.properties$/i', '/\.xhtml$/i', '/\.css$/i');

		return $this->_checkExtraFiles($file, $validExtensions);
    }

    /**
     * Greps the add-on for any potentially unsafe HTML
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function langpack_security_filterUnsafeHTML($file) {

		$patterns = array('/<script/i', '/<object/i', '/<embed/i');
		$extracted = $this->_extract($file, 'by_preg', '//');

		return $this->_grepExtractedFiles($extracted, $patterns);
    }

    /**
     * Greps the add-on for any remote loading (non-chrome URLs)
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function langpack_security_checkRemoteLoading($file) {

		$patterns = array('/(href|src)=["\'](?!chrome:\/\/)/i');
		$extracted = $this->_extract($file, 'by_preg', '//');

		return $this->_grepExtractedFiles($extracted, $patterns);
    }

    /**
     * Verifies the chrome.manifest file in language packs
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function langpack_security_checkChromeManifest($file) {

		// We need to use a single expression here since we want any one
		// line that doesn't fit any of the allowed patterns.  These are:
		//  # <whatever>
		//  locale <whatever>
		//  override (chrome://<something>/locale/<something>)+
		$patterns = array('/^(?!(#|locale |override(\s+chrome:\/\/.*\/locale\/[^\s]*)+))/');
		$extracted = $this->_extract($file, 'by_name', array('chrome.manifest'));

		return $this->_grepExtractedFiles($extracted, $patterns);
    }

    /**
     * Verfies the reqired files are present for a theme
     * @param array $file the file in model format
     * @return array an arrat of test results, empty if there is no result
     */
    function theme_general_verifyFileLayout($file) {

		$names = array('install.rdf', 'chrome.manifest');

		return $this->_passIfEmpty($this->_verifyFilesExist($file, $names, 'by_name', ___('general_addontype_theme')));
    }

    /**
     * Checks chrome.manifest for remote URLs
     * @param array $file the file in model format
     * @return array an array of test results, empty if there is no result
     */
    function theme_security_checkChromeManifest($file) {

		// See above for justification on this regex
		$patterns = array('/^(?!(#|skin |style ))/');
		$extracted = $this->_extract($file, 'by_name', array('chrome.manifest'));

		return $this->_grepExtractedFiles($extracted, $patterns);
    }

    /**
     * Check all the files in an add-on and verify that they conform to the
     * given whitelist.
     * @param array $file the file in model format
     * @param array $whitelist regexs that specify the allowed files
     * @retrun array an array of tests results, empty if there are no problems
     */
    function _checkExtraFiles($file, $whitelist) {

		// We can use listContents here since we're only looking at the names
		$file_loc = REPO_PATH . '/' . $file['Version']['addon_id'] . '/' . $file['File']['filename'];
        $zip = new Archive_Zip($file_loc);
        $contents = $zip->listContent();

		// Every file gets checked individually
		$flags = array();
        foreach ($contents as $fileInfo) {

			// Assume the name is bad until we match it
            $nameOk = false;
			if (!empty($whitelist)) {
				foreach ($whitelist as $name) {
					if (preg_match($name, $fileInfo['filename'])) {
						$nameOk = true;
						break;
					}
				}
            }

            if (!$fileInfo['folder'] && !$nameOk) {
                $flags[] = $this->_result(TEST_WARN, 0, $fileInfo['filename'], sprintf(___('devcp_error_unsafe_filename', 'The file %s does not appear to belong in this add-on'), $fileInfo['filename']));
            }
        }

		return $this->_passIfEmpty($flags);
    }

    /**
     * Checks that the given names exist in the archive
     * @param array $file the file in model format
     * @param array $names the names to search for as strings or regexes
     * @param string $extract_how how to extract the files
     * @param string $type the type of the add-on (extension, dictionary, etc.)
     */
    function _verifyFilesExist($file, $names, $extract_how, $type) {

        $flags = array();
		if (!empty($names)) {
			foreach ($names as $name) {
				if (count($this->_extract($file, $extract_how, $name, false)) == 0)
					$flags[] = $this->_result(TEST_FAIL, 0, '', sprintf(___('devcp_error_missing_file', 'The %1$s was missing a required file: %2$s'), $type, $name));
			}
		}

		return $flags;
    }

    /**
     * Helper function to grep over the extracted files searching for an
     * array of potential patterns to match.
     * @param array $extracted the extracted data
     * @param array $patterns the regular expressions to match
     * @param int $action the action to take on a failure, defaults to warning
	 * @param string $message an overridden message to display instead of the default
     * @return array any matches that occurred
     */
    function _grepExtractedFiles($extracted, $patterns, $action = TEST_WARN, $message = '') {

		$flags = array();
        if (!empty($extracted)) {
            foreach($extracted as $file_info) {

                $lines = explode("\n", $file_info['content']);
				if (!empty($patterns)) {
					foreach ($patterns as $pattern) {

						$matches = preg_grep($pattern, $lines);						
						if (!empty($matches)) {
							foreach(array_keys($matches) as $line_num) {
								
								if($lines[$line_num] != '') {
									// Lines are 1-indexed, but the array is 0-indexed
									$flags[] = $this->_result($action, $line_num + 1, $file_info['filename'], empty($message) ? sprintf(___('devcp_error_grep_match', 'Matched Pattern: "%s"'), $pattern) : $message);
								}
							}
						}
					}
				}
            }
        }

        return $this->_passIfEmpty($flags);

    }

    /**
     * Helper function to extract from the addon file
     * @param array $file the file to extract, in model format
     * @param string $extract_by how to extract the file, either by_name or by_preg
     * @param mixed $extract_what what to extract, can be either a regex or array of names
	 * @param boolean $get_contents whether or not to get the contents of a file in addition to the name
	 * @param string $expires the time that the cached item expires
     * @return array the result of the extraction
     */
    function _extract($file, $extract_by, $extract_what, $get_contents = true, $expires = '+1 day') {

		// Cache location
		$tmp_loc = NETAPP_STORAGE . '/validate-' . $file['File']['id'];

		// Check to see if the file has expired if it exists
		if (file_exists($tmp_loc)) {
			$expires = strtotime($expires);
			$diff = $expires - filemtime($tmp_loc);
			
			if (time() - filemtime($tmp_loc) > $diff) {
				$this->_deleteDir($tmp_loc);
			}
		}

		// If the file doesn't exist, do the extraction
		if (!file_exists($tmp_loc)) {

			// We usually die extracting things, so boost the limit since
			// this is an infrequent operation
			ini_set('memory_limit', '128M');
			
			$file_loc = REPO_PATH . '/' . $file['Version']['addon_id'] . '/' . $file['File']['filename'];
			$zip = new Archive_Zip($file_loc);
			$zip->extract(array('add_path' => $tmp_loc));		  
		}

		// Wrap extraction if needed
		if (is_string($extract_what)) $extract_what = array($extract_what);

		// Use the appropriate extraction type
		switch ($extract_by) {
			case 'by_preg':
				ini_set('memory_limit', '128M');
				$files = $this->_findFiles($tmp_loc . '/', '', $extract_what);
				break;
			case 'by_name':
				$files = $extract_what;
				break;
			default:
				return false;
				break;
		}

		// Load in all the files we found
		$result = array();
		if (!empty($files)) {
			foreach ($files as $file) { 
				if (file_exists($tmp_loc . '/' . $file)) {
					$content = ($get_contents ? file_get_contents($tmp_loc . '/' . $file) : '');
					$result[] = array('filename' => $file, 'content' => $content);
				}
			}
		}		
		return $result;
    }

	/**
	 * Helper to recursively scan a directory and find any 
	 * files that match the regexes supplied
	 * @param string $root_dir the root extraction dir
	 * @param string $cur_dir the current extraction dir
	 * @param array $names the regexes to match on file names
	 * @return array an array of matching filenames
	 */
	function _findFiles($root_dir, $cur_dir, $names) { 
		if (is_dir($root_dir . $cur_dir) && $dh = opendir($root_dir . $cur_dir)) {
			$result = array();
			while (($file = readdir($dh)) !== false) {
				if ($file == '.' || $file == '..') continue;
				$dir = $this->_findFiles($root_dir, $cur_dir . '/' . $file, $names);
				$result = array_merge($result, $dir);
			}
			return $result;
		} else if (is_file($root_dir . $cur_dir)) {
			foreach ($names as $name) {
				if (preg_match($name, $cur_dir)) {
					// Trim leading slash
					return array(substr($cur_dir, 1));
				}
			}
		}
		return array();
	}

	/**
	 * Helper to delete a directory recursively
	 * Based on an example at http://us2.php.net/manual/en/function.rmdir.php
	 * @param string $dir_name the directory to delete
	 * @return boolean true if the function succeeds
	 */
	function _deleteDir($dir_name) {
		if (!file_exists($dir_name)) return true;
		if (is_dir($dir_name)) {
			foreach (scandir($dir_name) as $item) { 
				if ($item == '.' || $item == '..') continue;
				if (!$this->_deleteDir($dir_name . '/' . $item)) return false;
			}
			return @rmdir($dir_name);
		} else {
			return @unlink($dir_name);
		}
	}

    /**
     * Since many functions return a pass result if there are
     * no failures/warnings, this function simplifies the logic
     * @param array $results the results array returned from a test
     * @return array a pass result if the original array was empty, the original otherwise
     */
    function _passIfEmpty($results) {
		if (count($results) == 0)
			return $this->_resultPass();
		return $results;
    }

    /**
     * Helper function to generate a single result
     * @param int $status the status of the test
     * @param int $line the line number
     * @param string $file the file name
     * @param string $message the failure message
     */
    function _result($status, $line, $file, $message) {
		return array('result' => $status,
			'line' => $line,
			'filename' => $file,
			'message' => $message);
    }

    /**
     * Helper function to generate a single failure
     * @param int $line the line number
     * @param string $file the file name
     * @param string $message the failure message
     */
    function _resultFail($line, $file, $message) {
		return array($this->_result(TEST_FAIL, $line, $file, $message));
    }

    /**
     * Helper function to generate a single warning
     * @param int $line the line number
     * @param string $file the file name
     * @param string $message the warning message
     */
    function _resultWarn($line, $file, $message) {
		return array($this->_result(TEST_WARN, $line, $file, $message));
    }

    /**
     * Helper function to generate a single pass result
     * @return array the result format
     */
    function _resultPass() {
		return array($this->_result(TEST_PASS, 0, '', ''));
    }

}
?>
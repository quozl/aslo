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
 * Portions created by the Initial Developer are Copyright (C) 2007
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 *   Dave Dash <dd@mozilla.com> (Original Author) 
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

uses('sanitize');
vendor('sphinx/addonsSearch');
require_once('api_controller.php');

class Api15Controller extends ApiController
{

    public $name = 'Api15';

    public $newest_api_version = 1.5;   
    
    public function search($term) {
        $this->layout = 'rest'; 
        $as = new AddonSearch($this->Addon);
        list($matches, $total_results) = $as->query($term);
        
        $this->_getAddons($matches);
        
        // var_dump($matches);
        // var_dump($this->viewVars['addonsdata']);
        $this->publish('api_version', $this->api_version); 
        $this->publish('guids', array_flip($this->Application->getGUIDList()));
        $this->publish('app_names', $app_names = $this->Application->getIDList()); 
        $this->publish('total_results', $total_results); 
        $this->publish('os_translation', $this->os_translation);   
        $this->publish('addonsdata', $this->viewVars['addonsdata']);
    }
}

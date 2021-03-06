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
 *   Justin Scott <fligtar@mozilla.com> (Original Author)
 *
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

// Prevent running from the web
if (isset($_SERVER['HTTP_HOST'])) {
    exit;
}

// Include class files
require_once('../database.class.php');

$db = new Database();

$addon_qry = $db->read("SELECT id, totaldownloads FROM addons");

while ($addon = mysql_fetch_array($addon_qry)) {
    echo "# [Add-on {$addon['id']}]";
    $sum_qry = $db->read("SELECT SUM(count) FROM download_counts WHERE addon_id={$addon['id']} AND date != '0000-00-00'");
    $sum = mysql_fetch_array($sum_qry);
    if ($sum[0] > 0) {
        $undated = $addon['totaldownloads'] - $sum[0];
        echo " {$addon['totaldownloads']} total - {$sum[0]} dated = {$undated} undated";
        if ($undated > 0) {
            //$db->write("INSERT INTO download_counts (addon_id, count, date) VALUES({$addon['id']}, $undated, '0000-00-00')");
            echo "\nINSERT INTO download_counts (addon_id, count, date) VALUES({$addon['id']}, $undated, '0000-00-00');"; 
        }
    }
    echo "\n";
}

?>

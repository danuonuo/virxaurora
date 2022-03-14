<?php

use WHMCS\Database\Capsule;
include_once('virtualizor_conf.php');

// Any Admin User in WHMCS
$admin_user = "admin";

// The Hask Key in WHMCS
$hash_key = array();

// The Virtualizor Master IP
$connection_ip = array();


///////////////////////////////////
// DO NOT EDIT BEYOND THIS LINE 
///////////////////////////////////

if(empty($hash_key) || empty($connection_ip)){
	virt_callback_die('<error>ERROR: Callback NOT configured</error>');
}

function virt_callback_die($msg){
	die($msg);
}

// Include the WHMCS files
if(file_exists('../../../init.php')){
	require("../../../init.php");
}else{
	require("../../../dbconnect.php");
	require("../../../includes/functions.php");
}

// Get the variables
$hash = $_POST['hash'];
$acts = $_POST['act'];
$vpsid = (int) $_POST['vpsid'];
$extra = $_POST['data'];

if(!empty($extra)){
	$extra_var = unserialize(base64_decode($extra));
}

// Is the request from a valid server ?
if(!in_array($_SERVER['REMOTE_ADDR'], $connection_ip)  && (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && !in_array($_SERVER['HTTP_X_FORWARDED_FOR'], $connection_ip))){
	virt_callback_die('<error>ERROR: Connection from an INVALID IP !</error>');
}

// Does the KEY MATCH ?
if(!in_array($hash, $hash_key)){
	virt_callback_die('<error>ERROR: Your security HASH does not match</error>');
}

// Is it a valid
if(empty($vpsid) && $vpsid < 1){
	virt_callback_die('<error>ERROR: Invalid VPSID</error>');
}

//=====================
// Get the VPS details
//=====================

if(!empty($extra_var['callback_api'])){
	$res =	Capsule::table('tblcustomfields')
	->join('tblcustomfieldsvalues','tblcustomfieldsvalues.fieldid','=','tblcustomfields.id')
	->join('tblhosting','tblhosting.id','=','tblcustomfieldsvalues.relid')
	->join('tblservers','tblservers.id','=','tblhosting.server')
	->select('tblcustomfields.id','tblcustomfields.type','tblcustomfields.fieldname','tblcustomfieldsvalues.fieldid','tblcustomfieldsvalues.value','tblcustomfieldsvalues.relid','tblhosting.dedicatedip')
	->where('tblcustomfields.type','product')
	->where('tblcustomfields.fieldname','vpsid')
	->where('tblcustomfieldsvalues.value',$vpsid)
	->where('tblservers.username', $extra_var['callback_api'])
	->get();

// Backward Compatibility
}else{
	$res =	Capsule::table('tblcustomfields')
	->join('tblcustomfieldsvalues','tblcustomfieldsvalues.fieldid','=','tblcustomfields.id')
	->select('tblcustomfields.id','tblcustomfields.type','tblcustomfields.fieldname','tblcustomfieldsvalues.fieldid','tblcustomfieldsvalues.value','tblcustomfieldsvalues.relid')
	->where('tblcustomfields.type','product')
	->where('tblcustomfields.fieldname','vpsid')
	->where('tblcustomfieldsvalues.value',$vpsid)
	->get();
}

// We didnt find it !	
if(empty($res)){
	virt_callback_die('<error>ERROR: VPS does not exist in WHMCS Database</error>');
}

// Get the row
$row = (array) $res[0];
$hosting_ID = $row['relid'];

foreach($acts as $k => $act){
	
	// Do as necessary
	switch ($act){

		//===============
		// VPS Suspended
		//===============
		case 'suspend':

			$values['messagename'] = "Service Suspension Notification";
			$values['id'] = $hosting_ID;

			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['domainstatus'=>'Suspended']);

			if($extra_var['suspendreason']){
				mysql_real_escape_string($extra_var['suspendreason']);
			}else{
				$extra_var['suspendreason'] = "";
			}

			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['suspendreason'=>$extra_var['suspendreason']]);

			$output = localAPI('sendemail', $values, $admin_user);

			if($output['result'] == "success"){
				echo "<success>1</success>";
			}else{
				echo "<error>Error: " . $output['message']."</error>";
			}

			break;


		//==================
		// VPS Unsuspended
		//==================
		case 'unsuspend':

			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['domainstatus'=>'Active']);
			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['suspendreason'=>'']);

			echo "<success>1</success>";

			break;
			
		//==================
		// VPS Deleted
		//==================
		case 'terminate':

			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['domainstatus'=>'Terminated']);

			echo "<success>1</success>";

			break;
			
		//==================
		// HostName Changed
		//==================
		case 'changehostname':

			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['domain'=>$extra_var['newhostname']]);

			echo "<success>1</success>";

			break;

			
		//=============
		// IPs Changed
		//=============
		case 'changeip':

			$ip_list = array();

			if($extra_var['ipv4']){
				mysql_real_escape_string($extra_var['ipv4']);
				$ipv4_list = explode(",", $extra_var['ipv4']);
				foreach ($ipv4_list as $ipv4){
					$ip_list[] = $ipv4;
				}
			}

			if($extra_var['ipv6']){
				mysql_real_escape_string($extra_var['ipv6']);
				$ipv6_list = explode(",", $extra_var['ipv6']);
				foreach ($ipv6_list as $ipv6){
					$ip_list[] = $ipv6;
				}
			}

			if(count($ip_list) > 1){
				$tmplist = $ip_list;
				unset($tmplist[0]);
				$ips = implode("\n", $tmplist);
			}else{
				$ips = "";
			}
			
			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['dedicatedip'=>$ip_list[0]]);
			
			Capsule::table('tblhosting')->where('id', $hosting_ID)->update(['assignedips'=>$ips]);

			echo "<success>1</success>";

			break;

		case 'bandwidth_overusage':

			global $virtualizor_conf;

			$overused = $extra_var['overused'];

			$postData = array(
				'email' => $extra_var['email'],
			);
				
			$output = localAPI('GetClientsDetails', $postData, $admin_user);
		
			$command = 'AddBillableItem';
			$amount = $overused * $virtualizor_conf['bandwidth_overusage_charge'];

			$postData = array(
				'clientid' => $output['userid'],
				'description' => 'Overused Bandwidth : '.$overused.' GB',
				'amount' => $amount,
				'invoiceaction' => 'nextinvoice'
			);

			if(!empty($amount)){
				$results = localAPI($command, $postData, $admin_user);
				echo "<success>1</success>";
			}

			break;
		
		case 'reinstall':

			$serviceid = $row['relid'];

			// get the packageid
			$query1 = Capsule::table('tblhosting')
			->select('id', 'packageid')
			->where('id', '=', $serviceid)
			->get();

			$row1 = (array) $query1[0];
			$packageid = $row1['packageid'];

			// get the OS fieldoptions of the product
			$OS_field = Capsule::table('tblcustomfields')
			->select('id','fieldoptions', 'fieldname')
			->where('fieldname', 'like', '%OS%')
			->where('relid', '=', $packageid)
			->get();

			// check whether the resintalled OS is available in the OS fieldoptions
			$row = (array) $OS_field[0];
			$fieldoptions = $row['fieldoptions'];
			$id = $row['id'];
			$pattern = "/".$extra_var['os_name']."/i";

			if(!preg_match($pattern, $fieldoptions)){

				// if reinstalled OS is not available in the OS fieldoptions; update it.
				$fieldoptions = $fieldoptions.",".$extra_var['os_name'];

				$OS_field_update = Capsule::table('tblcustomfields')
				->where('id', '=', $id)
				->update(['fieldoptions' => $fieldoptions]);

			}

			// update customfield value to reinstalled OS
			$result = Capsule::table('tblcustomfields')
			->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
			->where('tblcustomfieldsvalues.relid', '=', $serviceid)
			->where('tblcustomfields.fieldname', '=', 'OS')
			->update(['value' => $extra_var['os_name']]);
			echo "<success>1</success>";

			break;
			
		//==================
		// Default Scenario
		//==================
		default:
			echo "1";
		
	}// End of Switch
}

?>

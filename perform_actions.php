<?php

/**
 * Author: Kaushal Vora
 * Purpose: To Integrate Complete Billing Solution of Virtualizor to WHMCS
 * Version: 1.1
 * Date: 13-11-2018 (DD-MM-YYYY)
 * Updated By: 
*/

// use WHMCS\Database\Capsule;

// Any Admin User in WHMCS
$admin_user = "";

// The Hask Key in WHMCS
$hash_key = "";

// The Virtualizor Master IP
$connection_ip = "";


///////////////////////////////////
// DO NOT EDIT BEYOND THIS LINE 
///////////////////////////////////

if(empty($hash_key) || empty($connection_ip)){
	virt_callback_die('<error>ERROR: Auth NOT configured</error>');
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
$act = $_POST['act'];
$extra = $_POST['data'];

if(!empty($extra)){
	$extra_var = unserialize(base64_decode($extra));
}

// Is the request from a valid server ?
if($_SERVER['REMOTE_ADDR'] != $connection_ip){
	virt_callback_die('<error>ERROR: Connection from an INVALID IP !</error>');
}

// Does the KEY MATCH ?
if($hash != $hash_key){
	virt_callback_die('<error>ERROR: Your security HASH does not match</error>');
}

switch ($act){

	//===============
	// Validate Login
	//===============
	case 'validate_login':

		$command = 'ValidateLogin';
		$postData = array(
			'email' => $extra_var['email'],
			'password2' => $extra_var['password'],
		);
		
		$output = localAPI($command, $postData, $admin_user);

		$data = array();
		if($output['result'] == "success"){
			$data['result'] = $output['result'];
			$data['userid'] = $output['userid'];
		}else{
			$data['result'] = 'failed';
		}

		echo json_encode($data);

		break;

	// =====================================
	// Create Invoice for Cloud Billing User
	// =====================================
	case 'create_invoice':

		$command = 'CreateInvoice';
		$postData = array(
			'userid' => $extra_var['userid'],
			'status' => 'Unpaid',
			'sendinvoice' => '1',
			'date' => $extra_var['date'],
			'duedate' => $extra_var['date'],
			'itemdescription1' => 'Cloud Billing',
			'itemamount1' => $extra_var['amount'],
			'itemtaxed1' => '1',
			'autoapplycredit' => '1'
		);
		
		$output = localAPI($command, $postData, $admin_user);

		$data = array();
		if($output['result'] == "success"){
			$data['result'] = $output['result'];
			$data['invoiceid'] = $output['invoiceid'];
		}else{
			$data['result'] = 'failed';
		}

		echo json_encode($data);

		break;

	case 'get_balance':

		$data = array();

		$userdata = $extra_var['userdata'];

		$command = 'GetClientsDetails';
		
		foreach($userdata as $uk => $uv){

			$postData = array(
				'clientid' => $uv
			);
	
			$output = localAPI($command, $postData, $admin_user);
	
			if($output['result'] == "success"){
				$data[$uk]['result'] = $output['result'];
				$data[$uk]['balance'] = $output['credit'];
			}else{
				$data[$uk]['result'] = 'failed';
			}

		}

		echo json_encode($data);

		break;

	// ==================================
	// Get Client ID of Virtualizor Users
	// ==================================
	case 'get_client_id':

		$data = array();

		$userdata = $extra_var['userdata'];
		
		$command = 'GetClientsDetails';

		foreach($userdata as $uk => $uv){
			$postData = array(
				'email' => $uv
			);
			
			$output = localAPI($command, $postData, $admin_user);

			if($output['result'] == 'success'){	
				$data[$uk]['result'] = $output['result'];
				$data[$uk]['userid'] = $output['userid'];
			}else{
				$data[$uk]['result'] = 'failed';
			}
		}

		echo json_encode($data);

		break;	
		
	//==================
	// Default Scenario
	//==================
	default:
		echo 'failed';
}// End of Switch

?>

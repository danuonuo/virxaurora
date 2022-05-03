<?php

// Last Updated : 30/07/2021
// Version : 2.5

//Redesigned by danuonuo, port forwarding and Aurora coordination functions are added.
//Copyright © Danuonuo From 2022 to Now
//Bug report: https://t.me/dann365

// Disable warning messages - in PHP 5.4
//error_reporting(E_ALL & ~E_STRICT & ~E_NOTICE);

// This is supported since WHMCS 6.0+
use WHMCS\Database\Capsule;
use WHMCS\Auth;


include_once('virtualizor_conf.php');
include_once(dirname(__FILE__).'/functions.php');

if(!empty($_GET['virt_net_speed'])){
    ob_start();
}

function virtualizor_ConfigOptions() {

    global $virtualizor_conf, $whmcsmysql;

    // Get the Servers
    $res = Capsule::table('tblservers')->where('type','virtualizor')->get();

    if(empty($res)){
        echo '<font color="#FF0000">The virtualizor servers could not be found. Please add the Virtualizor Server and Server group to proceed</font>';
        return;
    }
    $server_list = array();

    foreach($res as $re){
        $server_list[$re->id] = $re->id.' - '.trim($re->name);
        $server_data[$re->id] = (array) $re;
    }

    # Should return an array of the module options for each product - Minimum of 24
    $config_array = array(
        "Type" => array( "Type" => "dropdown", "Options" => "OpenVZ,Xen PV,Xen HVM,KVM,XCP HVM,XCP PV,LXC,Virtuozzo OpenVZ,Virtuozzo KVM,Proxmox KVM,Proxmox OpenVZ,Proxmox LXC"),
        "DiskSpace" => array( "Type" => "text", "Size" => "25", "Description" => "GB"),
        "Inodes" => array( "Type" => "text", "Size" => "25", "Description" => " (OpenVZ)"),
        "Guaranteed RAM" => array( "Type" => "text", "Size" => "25", "Description" => "MB"),
        "Burstable RAM" => array( "Type" => "text", "Size" => "25", "Description" => "MB (OpenVZ, Proxmox OpenVZ, Virtuozzo OpenVZ)"),
        "SWAP RAM" => array( "Type" => "text", "Size" => "25", "Description" => "MB (Xen, XCP, KVM, LXC, Virtuozzo KVM, Proxmox KVM, Proxmox LXC)"),
        "Bandwidth" => array( "Type" => "text", "Size" => "25", "Description" => "GB (Zero or empty for unlimited)"),
        "CPU Units" => array ( "Type" => "text", "Size" => "25", "Description" => "Units"),
        "CPU Cores" => array( "Type" => "text", "Size" => "25", "Description" => ""),
        "CPU%" => array( "Type" => "text", "Size" => "25", "Description" => ""),
        "I/O Priority" => array( "Type" => "dropdown", "Options" => "0,1,2,3,4,5,6,7", "Description" => "(OpenVZ)"),
        "VNC" => array( "Type" => "yesno", "Description" => "Enable VNC (Xen, XCP, KVM, Virtuozzo)" ),
        "IPs" => array( "Type" => "text", "Size" => "25", "Description" => "Number of IPs"),
        "Network Speed" => array( "Type" => "text", "Size" => "25", "Description" => "KB/s (Zero or empty for unlimited)"),
        "Server" => array( "Type" => "text", "Size" => "25", "Description" => "Slave Servers name if any"),
        "Server Group" => array( "Type" => "text", "Size" => "25", "Description" => "To choose a server"),
        "IPv6" => array( "Type" => "text", "Size" => "25", "Description" => "Number of IPv6 Address"),
        "IPv6 Subnets" => array( "Type" => "text", "Size" => "25", "Description" => "Number of IPv6 Subnets"),
        "Internal IP Address" => array( "Type" => "text", "Size" => "25", "Description" => "Number of Internal IP Address"),
    );

    // Get the product ID
    $pid = (int) $_REQUEST['id'];

    // First get the configoption1 to check if the user is on OLD method or New method.
    $res = Capsule::table('tblproducts')->where('id',$pid)->get();

    $row = (array) $res[0];
    //rprint($row);

    $configarray = array(
        'Virtualizor Servers' => array("Type" => "dropdown", "Options" => implode(',', array_values($server_list))),
        'Type' => array("Type" => "dropdown", "Options" => 'OpenVZ,Xen PV,Xen HVM,KVM,XCP HVM,XCP PV,LXC,Virtuozzo OpenVZ,Virtuozzo KVM,Proxmox KVM,Proxmox OpenVZ,Proxmox LXC'),
        'Select Plan' => array("Type" => "dropdown", "Options" => ''),
    );

    // If this is filled up then user is using the OLD method
    if((!empty($row['configoption1']) && in_array($row['configoption1'], array('OpenVZ', 'Xen PV', 'Xen HVM', 'KVM', 'XCP HVM', 'XCP PV', 'LXC', 'Virtuozzo OpenVZ' , 'Virtuozzo KVM', 'Proxmox KVM', 'Proxmox OpenVZ', 'Proxmox LXC'))) || !empty($virtualizor_conf['no_virt_plans'])){

        //array_values($server_list)
        $tmp_type = array('OpenVZ', 'Xen PV', 'Xen HVM', 'KVM', 'XCP HVM', 'XCP PV', 'LXC', 'Virtuozzo OpenVZ' , 'Virtuozzo KVM', 'Proxmox KVM', 'Proxmox OpenVZ', 'Proxmox LXC');
        array_push($tmp_type, implode(',', array_values($server_list)));

        $config_array['Type']['Options'] = implode(',', $tmp_type);
        $configarray = $config_array;

        // If we get the Virtualizor server in configoption1, we will make an API call and load other fields
    }elseif(!empty($row['configoption1']) && in_array($row['configoption1'], array_values($server_list))){

        // Get the server ID
        $ser_id = array_search($row['configoption1'], $server_list);
        $ser_data = $server_data[$ser_id];

        //$configarray['Virtualizor Servers'] = array("Type" => "dropdown", "Options" => implode(',', array_values($server_list)));
        $tmp_hostname = $ser_data['hostname'];
        if(empty($tmp_hostname)){
            $tmp_hostname = $ser_data['ipaddress'];
        }
        // Get the data from virtualizor
        $data = Virtualizor_Curl::make_api_call($tmp_hostname, get_server_pass_from_whmcs($ser_data["password"]), 'index.php?act=addvs');

        //rprint($data);
        //rprint($row);

        if(empty($data)){
            //echo '<font color="red">Could not load the server data.'.Virtualizor_Curl::error($ser_data["ipaddress"]).'</font>';
            return $configarray;
        }

        $virttype = (preg_match('/xen/is', $data['resources']['virt']) ? 'xen' : (preg_match('/xcp/is', $data['resources']['virt']) ? 'xcp' : strtolower($data['resources']['virt'])));

        $hvm = (preg_match('/hvm/is', $row['configoption2']) ? 1 : 0);

        // Build the options list to show Plans
        foreach($data['plans'] as $k => $v){
            $tmp_plans[$v['plid']] = $v['plid'].' - '.$v['plan_name'];
        }

        //rprint($data['oses']);
        if(!empty($row['configoption2']) && in_array($row['configoption2'], array('OpenVZ', 'Xen PV', 'Xen HVM', 'KVM', 'XCP HVM', 'XCP PV', 'LXC'))){

            // Build the options list to show OS
            foreach($data['oses'] as $ok => $ov){

                // If we do not get the virttype Which
                if(!preg_match('/'.$virttype.'/is', $ov['type'])){
                    continue;
                }

                // Xen/XCP Stuff!
                if($virttype == 'xen' || $virttype == 'xcp'){

                    // Xen/XCP HVM templates
                    if(!empty($hvm) && empty($ov['hvm'])){
                        continue;

                        // Xen/XCP PV templates
                    }elseif(empty($hvm) && !empty($ov['hvm'])){
                        continue;
                    }
                }

                $tmp_oses[$ok] = $ok.' - '.$ov['name'];
            }
        }
        //rprint($tmp_oses);

        // Build the default node / group field
        $tmp_default_node_grp['Auto Select Server'] = 'Auto Select Server';

        foreach ($data['servergroups'] as $k => $v){

            $tmp_default_node_grp[$k] = $k.' - [G] '.$v['sg_name'];

            foreach ($data['servers'] as $m => $n){
                if($n['sgid'] == $k){
                    $tmp_default_node_grp[$n['server_name']] = $m." - ".$n['server_name'];
                }
            }
        }

        $configarray['Select Plan'] = array("Type" => "dropdown", "Options" => implode(',', $tmp_plans));
        $configarray['Default Node/ Group'] = array("Type" => "dropdown", "Options" => implode(',', array_values($tmp_default_node_grp)), "Description" => '[G] = Group Name');
        //$configarray['Operating System'] = array("Type" => "dropdown", "Options" => ' -- ,'.implode(',', $tmp_oses));

    }
    #custom code
    $configarray['极光地址'] = array("Type"=>'text',"Size"=>'25',"Description" => "","Default" => "https://emample.com");
    $configarray['极光用户名'] = array("Type"=>'text',"Size"=>'25',"Description" => "","Default" => "Example");
    $configarray['极光密码'] = array("Type"=>'text',"Size"=>'25',"Description" => "","Default" => "Example");
    $configarray['极光服务器ID'] = array("Type"=>'text',"Size"=>'25',"Description" => "","Default" => "Example");
    $configarray['极光每用户分配端口数量'] = array("Type"=>'text',"Size"=>'25',"Description" => "","Default" => "20");
    $configarray['极光流量限制'] = array("Type"=>'text',"Size"=>'25',"Description" => "以GB计","Default" => "Example");
    $configarray['极光到达限制后策略'] = array("Type"=>'text',"Size"=>'25',"Description" => "0-无动作 1-限速10kb/s 2-限速100kb/s 3-限速1Mb/s 4-限速10Mb/s 8-删除转发规则","Default" => "1");
    $configarray['是否启用极光转发'] = array("Type"=>'text',"Size"=>'25',"Description" => "1-是 2-否","Default" => "Example");
    #Custom code ends.
    return $configarray;
}

function virtualizor_CreateAuroraAccount($params){
    
    if($configarray["configoption12"]=='2'){
        return;
    }
    
    $serviceid = $params["serviceid"]; # Unique ID of the product/service in the WHMCS Database
    $pid = $params["pid"]; # Product/Service ID
    $producttype = $params["producttype"]; # Product Type: hostingaccount, reselleraccount, server or other
    $domain = $params["domain"];
    $username = $params["username"];
    $password = $params["password"];
    $clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...
    $customfields = $params["customfields"]; # Array of custom field values for the product
    $configoptions = $params["configoptions"]; # Array of configurable option values for the product
    
    //创建转发-20端口
    
    $aurora_pf_panel_address = $params['configoption5'];
    $aurora_pf_panel_username = $params['configoption6'];
    $aurora_pf_panel_password = $params['configoption7'];
    $aurora_pf_panel_serverid = $params['configoption8'];
    $aurora_pf_panel_pfnumber = $params['configoption9'];
    $aurora_pf_panel_quota = intval($params['configoption10'])*1000*1000*1000;
    $aurora_pf_quota_action = $params['configoption11'];
    $clientEmail = $params['clientsdetails']['email'];
    
    /*
    $aurora_pf_panel_address = 'http://104.168.47.202:8000';
    $aurora_pf_panel_username = 'danuonuo365@gmail.com';
    $aurora_pf_panel_password = '13750201qwe';
    $clientEmail = '2333';
    */
    #die(var_dump($params));
    #auth
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    $post = array(
        'username' => $aurora_pf_panel_username,
        'password' => $aurora_pf_panel_password
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    $temp=json_decode($result);
    $aurora_auth_token=$temp -> {'access_token'};
    #die(print($aurora_auth_token));
    #auth_end
    #创建极光用户
    $aurora_pf_account_name='serviceid'.$params['serviceid'].'email'.$clientEmail;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v1/users');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n  \"email\": \"$aurora_pf_account_name\",\n  \"is_active\": true,\n  \"is_ops\": false,\n  \"is_superuser\": false,\n  \"first_name\": \"$clientEmail\",\n  \"last_name\": \"vmUser\",\n  \"password\": \"hellovmuser\",\n  \"notes\": \"vmUser\"\n}");

    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    $temp=json_decode($result);
    $aurora_user_id=$temp -> {'id'};
    
    #die(print($result));
    #为用户分配20个端口
    #查询所有端口
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v1/servers/'.$aurora_pf_panel_serverid.'/ports?offset=0&limit=9999');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    
    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    #找出可用端口
    $temp=json_decode($result,true);
    $i=0;
    $aurora_ports=array();
    $x=0;
    $i=0;
    while($i<=9999){
        if($temp[$x]['allowed_users'] == []){
            $aurora_ports[$i]=$temp[$x]['id'];
            $i++;
        }
        $x++;
    }
    $x=0;
    $i=0;
    
    #die(print($aurora_ports[20]));
    #die(var_dump($aurora_ports));
    #分配端口给用户
    for($i=1;$i<=$aurora_pf_panel_pfnumber;$i++){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v1/servers/'.$aurora_pf_panel_serverid.'/ports/'.$aurora_ports[$i].'/users');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n  \"user_id\": $aurora_user_id,\n  \"config\": {}\n}");
        
        $headers = array();
        $headers[] = 'Accept: application/json';
        $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);
        
        $ret1=$ret1.'<br/>'.$aurora_pf_panel_address.'/api/v1/servers/'.$aurora_pf_panel_serverid.'/ports/'.$aurora_ports[$i].'/users';
        #die(var_dump($aurora_ports));
        #die(print($ret1));
    }
    #创建流量限制
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v1/servers/'.$aurora_pf_panel_serverid.'/users/'.$aurora_user_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n  \"config\": {\n    \"valid_until\": 0,\n    \"due_action\": 0,\n    \"quota\": $aurora_pf_panel_quota,\n    \"quota_action\": $aurora_pf_quota_action\n  }\n}");
    
    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    
    #die(print($ret1));
    
    //Custom code ends.
}

function virtualizor_DeleteAuroraAccount($params){
    
    if($configarray["configoption12"]=='2'){
        return;
    }
    
    $id = $params['customfields']['vpsid'];
    #custom Code
    $aurora_pf_panel_address = $params['configoption5'];
    $aurora_pf_panel_username = $params['configoption6'];
    $aurora_pf_panel_password = $params['configoption7'];
    $aurora_pf_panel_serverid = $params['configoption8'];
    $clientEmail = $params['clientsdetails']['email'];
    
    #auth
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    $post = array(
        'username' => $aurora_pf_panel_username,
        'password' => $aurora_pf_panel_password
    );
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $result1 = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    $temp=json_decode($result1);
    $aurora_auth_token=$temp -> {'access_token'};
    #die(var_dump($aurora_auth_token));
    #getuserid
    $aurora_pf_account_name='serviceid'.$params['serviceid'].'email'.$clientEmail;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v2/users?query='.$aurora_pf_account_name.'&page=0&size=50');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    
    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result1 = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    $temp=json_decode($result1,true);
    $aurora_user_id=$temp['items'][0]['id'];
    #die(var_dump($aurora_user_id));
    #die(print($temp[0]));
    #delete user
    #$aurora_user_id=68;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $aurora_pf_panel_address.'/api/v1/users/'.$aurora_user_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n  \"remove_rule\": true\n}");
    
    $headers = array();
    $headers[] = 'Accept: application/json';
    $headers[] = 'Authorization: Bearer '.$aurora_auth_token;
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result1 = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }
    curl_close($ch);
    
    #custom code end
}

function virtualizor_CreateAccount($params) {

    #echo "<pre>";print_r($params);echo "<pre>";
    #die(print($params['serviceid']));
    virtualizor_CreateAuroraAccount($params);

    global $virtualizor_conf, $whmcsmysql;

    # ** The variables listed below are passed into all module functions **

    $loglevel = (int) @$_REQUEST['loglevel'];

    if(!empty($virtualizor_conf['loglevel'])){
        $loglevel = $virtualizor_conf['loglevel'];
    }

    $serviceid = $params["serviceid"]; # Unique ID of the product/service in the WHMCS Database
    $pid = $params["pid"]; # Product/Service ID
    $producttype = $params["producttype"]; # Product Type: hostingaccount, reselleraccount, server or other
    $domain = $params["domain"];
    $username = $params["username"];
    $password = $params["password"];
    $clientsdetails = $params["clientsdetails"]; # Array of clients details - firstname, lastname, email, country, etc...
    $customfields = $params["customfields"]; # Array of custom field values for the product
    $configoptions = $params["configoptions"]; # Array of configurable option values for the product

    if(empty($customfields)){
        $customfields = virtualizor_getcustomfields($params['serviceid']);
    }

    if(!empty($customfields['vpsid'])){
        return 'The VPS exists';
    }
    
    

    // New Module detection
    // If it is a new module then it will not have KVM or OPENVZ....
    if(!in_array($params['configoption1'], array('OpenVZ', 'Xen PV', 'Xen HVM', 'KVM', 'XCP HVM', 'XCP PV', 'LXC',  'Virtuozzo OpenVZ' , 'Virtuozzo KVM', 'Proxmox KVM', 'Proxmox OpenVZ', 'Proxmox LXC'))){

        $server_group = '';
        $slave_server = '';

        if(isset($params['configoptions'][v_fn('slave_server')]) && $params['configoptions'][v_fn('slave_server')] != 'none'){
            $params['configoption4'] = $params['configoptions'][v_fn('slave_server')];
        }

        // Is it a Server group ?
        if(preg_match('/\[G\]/s', $params['configoption4'])){
            //$server_group = str_replace('[G] ', '', $params['configoption4']);
            //$server_group = trim($server_group);
            $tmp_sg = array();
            $tmp_sg = explode('- [', $params['configoption4']);
            $server_group = trim($tmp_sg[0]);
        }

        // If we do not get server group we will search it for slave server
        if($server_group == ''){
            // Is user wants auto selection from server?
            if($params['configoption4'] == 'Auto Select Server'){

                $slave_server = 'auto';

                // Or is it a particular Slave server ?
            }else{

                $tmp_ss = array();
                $tmp_ss = explode("-", (string)$params['configoption4']);
                $slave_server = trim($tmp_ss[0]);
            }
        }

        $post['server_group'] = $server_group;
        if(strtolower($slave_server) != 'none'){
            $post['slave_server'] = $slave_server;
        }

        // Now get the plan ID to post
        $tmp_plid = explode('-', $params['configoption3']);
        $post['plid'] = trim($tmp_plid[0]);
        $virttype = (preg_match('/xen/is', $params['configoption2']) ? 'xen' : (preg_match('/xcp/is', $params['configoption2']) ? 'xcp' : strtolower($params['configoption2'])));

        //logActivity('Params : '.var_export($params, 1));

        // If its Virtuozzo
        if(preg_match('/virtuozzo/is', $virttype)){

            $tmp_virt = explode(' ', $virttype);

            if($tmp_virt[1] == 'openvz'){
                $virttype = 'vzo';
            }elseif($tmp_virt[1] == 'kvm'){
                $virttype = 'vzk';
            }
        }

        if(preg_match('/proxmox/is', $virttype)){

            $tmp_virt = explode(' ', $virttype);

            if($tmp_virt[1] == 'openvz'){
                $virttype = 'proxo';
            }elseif($tmp_virt[1] == 'kvm'){
                $virttype = 'proxk';
            }elseif($tmp_virt[1] == 'lxc'){
                $virttype = 'proxl';
            }
        }

        if(empty($virtualizor_conf['vps_control']['custom_hname'])){
            $post['hostname'] = $params['domain'];
        }else{

            // Select the Order ID
            $res = Capsule::table('tblhosting')->where('id',$params['serviceid'])->get();

            $hosting_details = (array) $res[0];

            $post['hostname'] = str_replace('{ID}', $hosting_details['orderid'], $virtualizor_conf['vps_control']['custom_hname']);
            if(preg_match('/(\{RAND(\d{1,3})\})/is', $post['hostname'], $matches)){
                $post['hostname'] = str_replace($matches[1], generateRandStr($matches[2]), $post['hostname']);
            }

            // Change the Hostname to the email
            Capsule::table('tblhosting')->where('id',$params['serviceid'])->update(
                array('domain' => $post['hostname'])
            );

        }

        $post['rootpass'] = $params['password'];

        // Pass the user details
        $post['user_email'] = $params["clientsdetails"]['email'];
        $post['user_pass'] = $params["password"];

        $post['fname'] = $params["clientsdetails"]['firstname'];
        $post['lname'] = $params["clientsdetails"]['lastname'];

        if($loglevel > 0) logActivity('params : '.var_export($params, 1));

        // Set the OS
        // Get the OS from the fields set
        $OS = strtolower(trim($params['configoptions'][v_fn('OS')]));
        if(empty($OS)){
            $OS = strtolower(trim($customfields['OS']));
        }

        if (!empty($params['configoptions']['webuzo_os'])) {

            $post['webuzo_spasswd'] = $params['password'];
            $post['webuzo_pd'] = $domain;
            $post['webuzo_stack'] = $params['configoptions']['webuzo_stack'];
            $post['webuzo_os'] = $params['configoptions']['webuzo_os'];

        }

        if($OS != 'none'){
            $post['os_name'] = $OS;
        }

        if(!empty($customfields['iso']) && strtolower($customfields['iso']) != 'none'){
            $post['iso'] = $customfields['iso'];
        }

        if(!empty($params['configoptions'][v_fn('ips')])){
            $post['num_ips'] = $params['configoptions'][v_fn('ips')];
        }

        if(!empty($params['configoptions'][v_fn('ips_int')])){
            $post['num_ips_int'] = $params['configoptions'][v_fn('ips_int')];
        }

        if(!empty($params['configoptions'][v_fn('ips6')])){
            $post['num_ips6'] = $params['configoptions'][v_fn('ips6')];
        }

        if(!empty($params['configoptions'][v_fn('ips6_subnet')])){
            $post['num_ips6_subnet'] = $params['configoptions'][v_fn('ips6_subnet')];
        }

        if(!empty($params['configoptions']['ippoolid'])){
            $post['ippoolid'] = $params['configoptions']['ippoolid'];
        }

        if(!empty($params['configoptions'][v_fn('space')])){
            $post['space'] = $params['configoptions'][v_fn('space')];
        }

        if(!empty($params['configoptions'][v_fn('ram')])){
            $post['ram'] = $params['configoptions'][v_fn('ram')];
            if(!empty($virtualizor_conf['ram_in_gb'])){
                $post['ram'] = $post['ram']*1024;
            }
        }

        if(!empty($params['configoptions'][v_fn('swapram')])){
            $post['swapram'] = $params['configoptions'][v_fn('swapram')];
        }

        if(!empty($params['configoptions'][v_fn('bandwidth')])){
            $post['bandwidth'] = $params['configoptions'][v_fn('bandwidth')];
        }

        if(!empty($params['configoptions'][v_fn('cores')])){
            $post['cores'] = $params['configoptions'][v_fn('cores')];
        }

        if(!empty($params['configoptions'][v_fn('network_speed')])){
            $post['network_speed'] = $params['configoptions'][v_fn('network_speed')];
        }

        if(!empty($params['configoptions'][v_fn('OS')])){
            $post['OS'] = $params['configoptions'][v_fn('OS')];
        }

        if(!empty($params['configoptions'][v_fn('ctrlpanel')])){
            $post['control_panel'] = $params['configoptions'][v_fn('ctrlpanel')];
        }

        if(isset($params['configoptions'][v_fn('server_group')])){
            $post['server_group'] = $params['configoptions'][v_fn('server_group')];
            $post['slave_server'] = '';
        }

        if(!empty($params['configoptions'][v_fn('recipe')])){
            $post['recipe'] = $params['configoptions'][v_fn('recipe')];
        }

        if(!empty($params['configoptions'][v_fn('total_iops_sec')])){
            $post['total_iops_sec'] = $params['configoptions'][v_fn('total_iops_sec')];
        }

        if(!empty($params['configoptions'][v_fn('read_bytes_sec')])){
            $post['read_bytes_sec'] = $params['configoptions'][v_fn('read_bytes_sec')];
        }

        if(!empty($params['configoptions'][v_fn('write_bytes_sec')])){
            $post['write_bytes_sec'] = $params['configoptions'][v_fn('write_bytes_sec')];
        }

        if(!empty($params['configoptions'][v_fn('cpu_percent')])){
            $post['cpu_percent'] = $params['configoptions'][v_fn('cpu_percent')];
        }

        // Are there any configurable options
        if(!empty($params['configoptions'])){
            foreach($params['configoptions'] as $k => $v){
                if(!isset($post[$k])){
                    $post[$k] = $v;
                }
            }
        }

        // Any custom code ?
        if(file_exists(dirname(__FILE__).'/custom.php')){
            include_once(dirname(__FILE__).'/custom.php');

            if(!empty($custom_error)){
                return $custom_error;
            }

        }

        // Check if there is a hostname custom field
        if(!empty($params['customfields']['hostname'])){
            $post['hostname'] = $params['customfields']['hostname'];
        }

        // No emails
        if(!empty($customfields['noemail'])){
            $post['noemail'] = 1;
        }

        $post['node_select'] = 1;
        $post['addvps'] = 1;

        if($loglevel > 0) logActivity('POST : '.var_export($post, 1));

        $ctrlpanel = (empty($params['configoptions'][v_fn('ctrlpanel')]) ? -1 : strtolower(trim($params['configoptions'][v_fn('ctrlpanel')])));

        $ret = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=addvs&virt='.$virttype, array(), $post, array());

        //logActivity('data to be posted: '.var_export($post, 1));

        if(empty($ret)){
            return 'Could not load the slave server data';
        }

        if(!empty($ret['error'])){
            return implode('<br>*', array_values($ret['error']));
        }

        //logActivity('New module Return data after post : '.var_export($ret['newvs'], 1));

        // Fill the variables as per the OLD module as it will be inserted to WHMCS. Like ips, ips6, etc..
        if(!empty($ret['newvs']['ips'])){
            $_ips = $ret['newvs']['ips'];
        }

        if(!empty($ret['newvs']['ipv6'])){
            $_ips6 = $ret['newvs']['ipv6'];
        }

        if(!empty($ret['newvs']['ipv6_subnet'])){
            $_ips6_subnet = $ret['newvs']['ipv6_subnet'];
        }



        // Setup cPanel licenses if cPanel configurable option is set
        if($ctrlpanel != -1 && $ctrlpanel != 'none'){

            if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['buy_cpanel_login']) && !empty($virtualizor_conf['cp']['buy_cpanel_apikey'])){
                logActivity("CPANEL : cPanel issued for ip $_ips[0] of ordertype $cpanel");

                $url = 'https://www.buycpanel.com/api/order.php?';
                $login = 'login='.$virtualizor_conf['cp']['buy_cpanel_login'].'&';
                $key = 'key='.$virtualizor_conf['cp']['buy_cpanel_apikey'].'&';
                $domain = 'domain='.$params['domain'].'&';
                $serverip = 'serverip='.$_ips[0].'&';
                $ordertype = 'ordertype=10';

                $url .= $login.$key.$domain.$serverip.$ordertype;

                $ret_ctrlpanel = Virtualizor_Curl::curl_call($url, 0, 5);

                $ret_ctrlpanel = json_decode($ret_ctrlpanel);

                if($ret_ctrlpanel->success == 0){
                    return 'Errors : cPanel Licensing : '.$ret_ctrlpanel->faultstring;
                }
            }
        }

        // Old Module compatibility
    }else{

        # Additional variables if the product/service is linked to a server
        $server = $params["server"]; # True if linked to a server
        $serverid = $params["serverid"];
        $serverip = $params["serverip"];
        $serverusername = $params["serverusername"];
        $serverpassword = $params["serverpassword"];
        $serveraccesshash = $params["serveraccesshash"];
        $serversecure = $params["serversecure"]; # If set, SSL Mode is enabled in the server config

        $virttype = (preg_match('/xen/is', $params['configoption1']) ? 'xen' : (preg_match('/xcp/is', $params['configoption1']) ? 'xcp' : strtolower($params['configoption1'])));

        // If its Virtuozzo
        if(preg_match('/virtuozzo/is', $virttype)){

            $tmp_virt = explode(' ', $virttype);

            if($tmp_virt[1] == 'openvz'){
                $virttype = 'vzo';
            }elseif($tmp_virt[1] == 'kvm'){
                $virttype = 'vzk';
            }
        }

        // If its Proxmox
        if(preg_match('/proxmox/is', $virttype)){

            $tmp_virt = explode(' ', $virttype);

            if($tmp_virt[1] == 'openvz'){
                $virttype = 'proxo';
            }elseif($tmp_virt[1] == 'kvm'){
                $virttype = 'proxk';
            }elseif($tmp_virt[1] == 'lxc'){
                $virttype = 'proxl';
            }
        }

        $hvm = (preg_match('/hvm/is', $params['configoption1']) ? 1 : 0);
        $numips = (empty($params['configoptions'][v_fn('ips')]) || $params['configoptions'][v_fn('ips')] == 0 ? $params['configoption13'] : $params['configoptions'][v_fn('ips')]);
        $numips_int = (empty($params['configoptions'][v_fn('ips_int')]) || $params['configoptions'][v_fn('ips_int')] == 0 ? $params['configoption19'] : $params['configoptions'][v_fn('ips_int')]);
        $numips6 = (empty($params['configoptions'][v_fn('ips6')]) || $params['configoptions'][v_fn('ips6')] == 0 ? $params['configoption17'] : $params['configoptions'][v_fn('ips6')]);
        $numips6_subnet = (empty($params['configoptions'][v_fn('ips6_subnet')]) || $params['configoptions'][v_fn('ips6_subnet')] == 0 ? $params['configoption18'] : $params['configoptions'][v_fn('ips6_subnet')]);
        $ctrlpanel = (empty($params['configoptions'][v_fn('ctrlpanel')]) ? -1 : strtolower(trim($params['configoptions'][v_fn('ctrlpanel')])));

        // Fixes for SolusVM imported ConfigOptions
        if(empty($numips) && !empty($params['configoptions']['Extra IP Address'])){
            $numips = $params['configoptions']['Extra IP Address'];
        }

        if($loglevel > 0) logActivity('VIRT : '.$virttype.' - '.$hvm);
        if($loglevel > 0) logActivity(var_export($params, 1));

        if(!empty($params['configoptions']['ippoolid'])){
            $post['ippoolid'] = $params['configoptions']['ippoolid'];
        }

        // Get the Data
        $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=addvs&virt='.$virttype, array(), $post);

        if(empty($data)){
            return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
        }

        $cookies = array();

        $slave_server = (empty($params['configoptions'][v_fn('slave_server')]) ? $params['configoption15'] : $params['configoptions'][v_fn('slave_server')]);
        $server_group = (empty($params['configoptions'][v_fn('server_group')]) ? $params['configoption16'] : $params['configoptions'][v_fn('server_group')]);

        // Overcommit RAM
        foreach($data['servers'] as $k => $v){
            $data['servers'][$k]['_ram'] = !empty($v['overcommit']) ? ($v['overcommit'] - $v['alloc_ram']) : $v['ram'];
        }

        // Post Variables
        $post = array();
        $post['space'] = (empty($params['configoptions'][v_fn('space')]) || $params['configoptions'][v_fn('space')] == 0 ? $params['configoption2'] : $params['configoptions'][v_fn('space')]);
        $post['ram'] = (empty($params['configoptions'][v_fn('ram')]) || $params['configoptions'][v_fn('ram')] == 0 ? $params['configoption4'] : $params['configoptions'][v_fn('ram')]);
        if(!empty($virtualizor_conf['ram_in_gb'])){
            $post['ram'] = $post['ram']*1024;
        }
        if($loglevel > 0) logActivity('GET DATA : '.var_export($data, 1));
        // Is there a Slave server ?
        if(!empty($slave_server) && $slave_server != 'localhost'){

            // Do we have to Auto Select
            if($slave_server == 'auto'){

                foreach($data['servers'] as $k => $v){

                    // Master servers cannot be here
                    if(empty($k)) continue;

                    // Only the Same type of Virtualization is supported
                    if(!in_array($virttype, $v['virts'])){
                        continue;
                    }

                    // Xen HVM additional check
                    if(!empty($hvm) && empty($v['hvm'])){
                        continue;
                    }

                    // Do you have enough space
                    if($v['space'] < $post['space']){
                        continue;
                    }

                    // Is the server locked ?
                    if(!empty($v['locked'])){
                        continue;
                    }

                    $ser_setting = unserialize($v['settings']);

                    // Reached the limit of vps creation ?
                    if(!empty($ser_setting['vpslimit']) && $v['numvps'] >= $ser_setting['vpslimit']){
                        continue;
                    }

                    // Do you have enough RAM
                    if($v['_ram'] < $post['ram']){
                        continue;
                    }

                    if(isset($customfields['node_ram_select']) || !empty($virtualizor_conf['node_ram_select'])){
                        $tmpsort[$k] = -$v['_ram'];
                    }else{
                        $tmpsort[$k] = $v['numvps'];
                    }

                }

                // Did we get a list of Slave Servers
                if(empty($tmpsort)){
                    return 'No server present in the Cluster which is of the Virtualization Type : '.$params['configoption1'];
                }

                asort($tmpsort);

                $newserid = key($tmpsort);
                //return 'Tests'.$newserid.var_export($tmpsort, 1);

            }else{

                foreach($data['servers'] as $k => $v){
                    if(trim(strtolower($v['server_name'])) == trim(strtolower($slave_server))){
                        $newserid = $k;
                    }
                }

            }

            // Is there a valid slave server ?
            if(empty($newserid)){
                return 'There is no slave server - '.$slave_server.'. Please correct the <b>Product / Service</b> with the right slave server name.';
            }

            if($loglevel > 1) logActivity('Slave Server : '.$newserid);

            // Is there a Server Group ?
        }elseif(!empty($server_group)){

            foreach($data['servergroups'] as $k => $v){

                // Match the Server Group
                if(trim(strtolower($v['sg_name'])) == trim(strtolower($server_group))){
                    $sgid = $k;
                }

            }

            // OH SHIT ! We didnt find anything
            if(!isset($sgid)){
                return 'Could not find the server group - '.$server_group.'. Please correct the <b>Product / Service</b> with the right slave server name.';
            }

            // Make an array of available servers in this group
            foreach($data['servers'] as $k => $v){

                // Do you belong to this group
                if($v['sgid'] != $sgid){
                    continue;
                }

                // Is the server locked ?
                if(!empty($v['locked'])){
                    continue;
                }

                $ser_setting = unserialize($v['settings']);

                // Reached the limit of vps creation ?
                if(!empty($ser_setting['vpslimit']) && $v['numvps'] >= $ser_setting['vpslimit']){
                    continue;
                }

                // Only the Same type of Virtualization is supported
                if(!in_array($virttype, $v['virts'])){
                    continue;
                }

                // Xen HVM additional check
                if(!empty($hvm) && empty($v['hvm'])){
                    continue;
                }

                //logActivity('Slave Server Selection Ram : '.$v['_ram'].' '.$v['overcommit'].' '.$v['alloc_ram'].' '.$post['ram'].' Space : '.$v['space'].' '.$post['space']);

                // Do you have enough space
                if($v['space'] < $post['space']){
                    continue;
                }

                // Do you have enough RAM
                if($v['_ram'] < $post['ram']){
                    continue;
                }

                if(isset($customfields['node_ram_select']) || !empty($virtualizor_conf['node_ram_select'])){
                    $tmpsort[$k] = -$v['_ram'];
                }else{
                    $tmpsort[$k] = $v['numvps'];
                }

            }

            asort($tmpsort);

            // Is there a valid slave server ?
            if(empty($tmpsort)){
                return 'No server present in the Server Group which is of the Virtualization Type : '.$params['configoption1'].'. Please correct the <b>Product / Service</b> with the right slave server name.';
            }

            $newserid = key($tmpsort);

            if($loglevel > 1) logActivity('Slave Group Server Chosen : '.$newserid);
            if($loglevel > 1) logActivity('Slave Server Details : '.var_export($data['servers'][$newserid], 1));
        }

        if(!empty($params['configoptions']['ippoolid'])){
            $post['ippoolid'] = $params['configoptions']['ippoolid'];
        }

        // If a new server ID was found. Even if its 0 (Zero) then there is no need to reload data as the DATA is by default of 0
        if(!empty($newserid)){

            $cookies[$data['globals']['cookie_name'].'_server'] = $newserid;


            $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=addvs&virt='.$virttype, array(), $post, $cookies);

            if(empty($data)){
                return 'Could not load the slave server data';
            }

        }

        if($loglevel > 2) logActivity(var_export($data, 1));

        // Search does the user exist
        foreach($data['users'] as $k => $v){
            if(strtolower($v['email']) == strtolower($params["clientsdetails"]['email'])){
                $post['uid'] = $v['uid'];
            }
        }

        // Was the user there ?
        if(empty($post['uid'])){
            $post['user_email'] = $params["clientsdetails"]['email'];
            $post['user_pass'] = $params["password"];

            // Just add teh fname and lname
            $post['fname'] = $params["clientsdetails"]['firstname'];
            $post['lname'] = $params["clientsdetails"]['lastname'];
        }

        // Get the OS from the fields set
        $OS = strtolower(trim($params['configoptions'][v_fn('OS')]));
        if(empty($OS)){
            $OS = strtolower(trim($customfields['OS']));
        }

        // Search the OS ID
        if($OS != 'none'){

            foreach($data['oslist'][$virttype] as $k => $v){
                foreach($v as $kk => $vv){

                    // Xen/XCP Stuff!
                    if($virttype == 'xen' || $virttype == 'xcp'){

                        // Xen/XCP HVM templates
                        if(!empty($hvm) && empty($vv['hvm'])){
                            continue;

                            // Xen/XCP PV templates
                        }elseif(empty($hvm) && !empty($vv['hvm'])){
                            continue;
                        }
                    }

                    // Does the String match ?
                    if(strtolower($vv['name']) == $OS){
                        $post['osid'] = $kk;
                    }

                }
            }

        }

        // Is the OS template there
        if(empty($post['osid']) && $OS != 'none'){
            return 'Could not find the OS Template '.$OS;
        }

        // Search the ISO
        if(!empty($customfields['iso']) && strtolower($customfields['iso']) != 'none'){

            // ISO restricted in OVZ and XEN-PV
            if(in_array($virttype, array('openvz', 'vzo', 'proxo', 'lxc')) || (($virttype == 'xen' || $virttype == 'xcp') && empty($hvm))){
                return 'You can not select ISO for OpenVZ, LXC, Virtuozzo OpenVZ, Proxmox OpenVZ, XEN-PV and XCP-PV VPS';
            }

            foreach($data['isos'] as $k => $v){

                foreach($v as $kk => $vv){

                    //echo $vv['name'].' - '.$params["customfields"]['iso'].'<br>';

                    // Does the String match ?
                    if(strtolower($vv) == strtolower(trim($customfields['iso']))){
                        $post['iso'] = $vv;
                    }
                }
            }

            // Is the ISO there
            if(empty($post['iso'])){
                return 'Could not find the ISO '.$customfields['iso'];
            }
        }

        // If ISO and OS both not selected ?
        if(empty($post['iso']) && empty($post['osid']) && strtolower($customfields['iso']) == 'none' && $OS == 'none'){
            return 'ISO or OS is not selected';
        }

        // No emails
        if(!empty($customfields['noemail'])){
            $post['noemail'] = 1;
        }

        // Are there any IPv4 to assign ?
        if($numips > 0){

            // Assign the IPs
            foreach($data['ips'] as $k => $v){
                $i = $numips;
                $_ips[] = $v['ip'];

                if($i == count($_ips)){
                    break;
                }
            }

            // Were there enough IPs
            if(empty($_ips) || count($_ips) < $numips){
                return 'There are insufficient IPs on the server';
            }

        }

        // Are there any Inernal IPs to assign ?
        if($numips_int > 0){

            // Assign the IPs
            foreach($data['ips_int'] as $k => $v){
                $i = $numips_int;
                $_ips_int[] = $v['ip'];

                if($i == count($_ips_int)){
                    break;
                }
            }

            // Were there enough IPs
            if(empty($_ips_int) || count($_ips_int) < $numips_int){
                return 'There are insufficient Internal IPs on the server';
            }

        }

        // Are there any IPv6 to assign ?
        if($numips6 > 0){

            $_ips6 = array();

            // Assign the IPs
            foreach($data['ips6'] as $k => $v){

                if($numips6 == count($_ips6)){
                    break;
                }

                $_ips6[] = $v['ip'];
            }

            // Were there enough IPs
            if(empty($_ips6) || count($_ips6) < $numips6){
                return 'There are insufficient IPv6 Addresses on the server';
            }

        }

        // Are there any IPv6 Subnets to assign ?
        if($numips6_subnet > 0){

            $_ips6_subnet = array();

            // Assign the IPs
            foreach($data['ips6_subnet'] as $k => $v){

                if($numips6_subnet == count($_ips6_subnet)){
                    break;
                }

                $_ips6_subnet[] = $v['ip'];
            }

            // Were there enough IPs
            if(empty($_ips6_subnet) || count($_ips6_subnet) < $numips6_subnet){
                return 'There are insufficient IPv6 Subnets on the server';
            }

        }

        if(empty($virtualizor_conf['vps_control']['custom_hname'])){
            $post['hostname'] = $params['domain'];
        }else{

            // Select the Order ID
            $res = Capsule::table('tblhosting')->where('id',$params['serviceid'])->get();

            $hosting_details = (array) $res[0];

            $post['hostname'] = str_replace('{ID}', $hosting_details['orderid'], $virtualizor_conf['vps_control']['custom_hname']);
            if(preg_match('/(\{RAND(\d{1,3})\})/is', $post['hostname'], $matches)){
                $post['hostname'] = str_replace($matches[1], generateRandStr($matches[2]), $post['hostname']);
            }

            // Change the Hostname to the email
            Capsule::table('tblhosting')
                ->where('id',$params['serviceid'])
                ->update(array('domain'=>$post['hostname']));

        }

        $post['rootpass'] = $params['password'];
        $post['bandwidth'] = (empty($params['configoptions'][v_fn('bandwidth')]) || $params['configoptions'][v_fn('bandwidth')] == 0 ? (empty($params['configoption7']) ? '0' : $params['configoption7']) : $params['configoptions'][v_fn('bandwidth')]);
        $post['cores'] = (empty($params['configoptions'][v_fn('cores')]) || $params['configoptions'][v_fn('cores')] == 0 ? $params['configoption9'] : $params['configoptions'][v_fn('cores')]);
        $post['network_speed'] = (empty($params['configoptions'][v_fn('network_speed')]) || $params['configoptions'][v_fn('network_speed')] == 0 ? $params['configoption14'] : $params['configoptions'][v_fn('network_speed')]);
        $post['cpu_percent'] = (empty($params['configoptions'][v_fn('cpu_percent')]) || $params['configoptions'][v_fn('cpu_percent')] == 0 ? $params['configoption10'] : $params['configoptions'][v_fn('cpu_percent')]);
        $post['cpu'] = $params['configoption8'];
        $post['addvps'] = 1;
        $post['band_suspend'] = 1;

        // Fixes for SolusVM imported ConfigOptions
        if(empty($post['ram']) && !empty($params['configoptions']['Memory'])){
            $post['ram'] = (int)$params['configoptions']['Memory'];
            if(!empty($virtualizor_conf['ram_in_gb'])){
                $post['ram'] = $post['ram']*1024;
            }
        }
        if(empty($post['space']) && !empty($params['configoptions']['Disk Space'])){
            $post['space'] = $params['configoptions']['Disk Space'];
        }
        if(empty($post['cores']) && !empty($params['configoptions']['CPU'])){
            $post['cores'] = $params['configoptions']['CPU'];
        }

        if(!empty($params['customfields']['hostname'])){
            $post['hostname'] = $params['customfields']['hostname'];
        }

        if(!empty($params['configoptions']['ippoolid'])){
            $post['ippoolid'] = $params['configoptions']['ippoolid'];
        }

        // Control Panel
        $control_panel = trim(strtolower($params['configoptions']['control_panel']));
        $post['control_panel'] = ((empty($control_panel) || $control_panel == 'none') ? 0 : $control_panel);

        // Is is OpenVZ
        if($virttype == 'openvz'){

            $post['inodes'] = $params['configoption3'];
            $post['burst'] = $params['configoption5'];
            $post['priority'] = $params['configoption11'];

            // Is it Xen PV?
        }elseif(($virttype == 'xen' || $virttype == 'xcp') && empty($hvm)){

            $post['swapram'] = (empty($params['configoptions'][v_fn('swapram')]) || $params['configoptions'][v_fn('swapram')] == 0 ? (empty($params['configoption6']) ? '0' : $params['configoption6']) : $params['configoptions'][v_fn('swapram')]);
            if($params['configoption12'] == 'yes' || $params['configoption12'] == 'on'){
                $post['vnc'] = 1;
                $post['vncpass'] = generateRandStr(8);
            }

            // Is it Xen HVM?
        }elseif(($virttype == 'xen' || $virttype == 'xcp') && !empty($hvm)){

            $post['hvm'] = 1;
            $post['shadow'] = 8;
            $post['swapram'] = (empty($params['configoptions'][v_fn('swapram')]) || $params['configoptions'][v_fn('swapram')] == 0 ? (empty($params['configoption6']) ? '0' : $params['configoption6']) : $params['configoptions'][v_fn('swapram')]);
            if($params['configoption12'] == 'yes' || $params['configoption12'] == 'on'){
                $post['vnc'] = 1;
                $post['vncpass'] = generateRandStr(8);
            }

            // Is it KVM ?
        }elseif($virttype == 'kvm'){

            $post['swapram'] = (empty($params['configoptions'][v_fn('swapram')]) || $params['configoptions'][v_fn('swapram')] == 0 ? (empty($params['configoption6']) ? '0' : $params['configoption6']) : $params['configoptions'][v_fn('swapram')]);
            if($params['configoption12'] == 'yes' || $params['configoption12'] == 'on'){
                $post['vnc'] = 1;
                $post['vncpass'] = generateRandStr(8);
            }

        }elseif($virttype == 'lxc'){
            $post['swapram'] = (empty($params['configoptions'][v_fn('swapram')]) || $params['configoptions'][v_fn('swapram')] == 0 ? (empty($params['configoption6']) ? '0' : $params['configoption6']) : $params['configoptions'][v_fn('swapram')]);
        }

        // Suspend on bandwidth
        //$post['band_suspend'] = 1;

        // Add the IPs
        if(!empty($_ips)){
            $post['ips'] = $_ips;
        }

        // Add the Internal IPs
        if(!empty($_ips_int)){
            $post['ips_int'] = $_ips_int;
        }

        // Add the IPv6
        if(!empty($_ips6)){
            $post['ipv6'] = $_ips6;
        }

        // Add the IPv6 Subnet
        if(!empty($_ips6_subnet)){
            $post['ipv6_subnet'] = $_ips6_subnet;
        }

        if($loglevel > 0) logActivity('configoption : '.var_export($params['configoptions'], 1));

        // Are there any configurable options
        if(!empty($params['configoptions'])){
            foreach($params['configoptions'] as $k => $v){
                if(!isset($post[$k])){
                    $post[$k] = $v;
                }
            }
        }

        // Any custom code ?
        if(file_exists(dirname(__FILE__).'/custom.php')){
            include_once(dirname(__FILE__).'/custom.php');

            if(!empty($custom_error)){
                return $custom_error;
            }

        }

        if($loglevel > 0) logActivity('POST : '.var_export($post, 1));

        //echo "<pre>";print_r($cookies);echo "</pre>";
        //echo "<pre>";print_r($post);echo "</pre>";
        // return 'TEST'.var_export($params, 1);

        // Setup cPanel licenses if cPanel configurable option is set
        if($ctrlpanel != -1 && $ctrlpanel != 'none'){

            if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['buy_cpanel_login']) && !empty($virtualizor_conf['cp']['buy_cpanel_apikey'])){
                logActivity("CPANEL : cPanel issued for ip $_ips[0] of ordertype $cpanel");

                $url = 'https://www.buycpanel.com/api/order.php?';
                $login = 'login='.$virtualizor_conf['cp']['buy_cpanel_login'].'&';
                $key = 'key='.$virtualizor_conf['cp']['buy_cpanel_apikey'].'&';
                $domain = 'domain='.$params['domain'].'&';
                $serverip = 'serverip='.$_ips[0].'&';
                $ordertype = 'ordertype=10';

                $url .= $login.$key.$domain.$serverip.$ordertype;

                $ret = file_get_contents($url);

                $ret = json_decode($ret);

                if($ret->success == 0){
                    return 'Errors : cPanel Licensing : '.$ret->faultstring;
                }
            }
        }

        $ret = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=addvs&virt='.$virttype, array(), $post, $cookies);

        if($loglevel > 0) logActivity('RETURN POST AFTER CREATION: '.var_export($ret['newvs'], 1));

    }// End of old module

    // Was the VPS Inserted
    if(!empty($ret['newvs']['vpsid'])){

        if($loglevel > 0) logActivity('Virtualizor DONE ? : '.var_export($ret['done'], 1));

        // vpsid of virtualizor
        $query = Capsule::table('tblcustomfields')
            ->where('relid', $pid)
            ->where('fieldname', 'vpsid')
            ->get();
        $res = (array) $query[0];

        // We will check if there is an entry if not we will insert it.
        $query = Capsule::table('tblcustomfieldsvalues')
            ->select('relid')
            ->where('relid', $serviceid)
            ->where('fieldid', $res['id'])
            ->get();
        $sel_res = (array) $query[0];

        if($loglevel > 0) logActivity('Did we found anything : '.var_export($sel_res, 1));

        // We will insert it if not found anything
        if(empty($sel_res['relid'])){
            Capsule::table('tblcustomfieldsvalues')
                ->insert(array(
                    'value' => $ret['newvs']['vpsid'],
                    'relid' => $serviceid,
                    'fieldid' => $res['id']
                ));
            //if($loglevel > 0) logActivity('After Updating tblcustomfieldsvalues : '.var_export(mysql_error($whmcsmysql), 1));

        }else{
            Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceid)
                ->where('fieldid', $res['id'])
                ->update(
                    array('value' => $ret['newvs']['vpsid'])
                );

            if($loglevel > 0) logActivity("UPDATE `tblcustomfieldsvalues` SET `value` = '".$ret['newvs']['vpsid']."' WHERE `relid` = '$serviceid' AND `fieldid` = '".$res['id']."'");
        }


        $uuid = $ret['newvs']['uuid'];

        // add vps_uuid field as well
        Virtualizor_Curl::create_uuid_field($pid, $serviceid, $uuid);

        // Change the Username to the email
        Capsule::table('tblhosting')
            ->where('id', $serviceid)
            ->update(
                array('username' => $params['clientsdetails']['email'])
            );

        // The Dedicated IP
        Capsule::table('tblhosting')
            ->where('id',$serviceid)
            ->update(
                array('dedicatedip' => (!empty($_ips[0]) ? $_ips[0] : $_ips6[0]))
            );

        if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['cpanel_manage2_username']) && !empty($virtualizor_conf['cp']['cpanel_manage2_password'])){

            virt_add_cpanel_license($params);

        }


        $tmp_ips = empty($_ips) ? array() : $_ips;

        if(!empty($_ips6_subnet)){
            foreach($_ips6_subnet as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        if(!empty($_ips6)){
            foreach($_ips6 as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        // Extra IPs
        if(count($tmp_ips) > 1){
            unset($tmp_ips[0]);
            Capsule::table('tblhosting')
                ->where('id', $serviceid)
                ->update(
                    array('assignedips' => implode("\n", $tmp_ips))
                );
        }

        // Did it start ?
        if(!empty($ret['done'])){
            return 'success';
        }else{
            return 'Errors : '.implode('<br>', $ret['error']);
        }

    }else {
        return 'Errors : '.implode('<br>', $ret['error']);
    }

}

function virtualizor_AdminServicesTabFields($params) {

    if(!empty($_GET['vapi_mode'])){
        ob_end_clean();
    }

    $code = virtualizor_newUI($params, 'clientsservices.php?vapi_mode=1&userid='.$params['userid'], '../modules/servers');

    $fieldsarray = array(
        'VPS Information' => '<div style="width:100%" id="tab1"></div>'.$code,
    );

    return $fieldsarray;

}


function virtualizor_TerminateAccount($params) {

    global $virtualizor_conf, $whmcsmysql;

    $loglevel = (int) @$_REQUEST['loglevel'];
    $serviceid = $params["serviceid"]; # Unique ID of the product/service in the WHMCS Database

    if(!empty($virtualizor_conf['loglevel'])){
        $loglevel = $virtualizor_conf['loglevel'];
    }

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $ctrlpanel = (empty($params['configoptions'][v_fn('ctrlpanel')]) ? -1 : $params['configoptions'][v_fn('ctrlpanel')]);

    if(!empty($virtualizor_conf['admin_ui']['disable_terminate'])){
        return 'Termination has been disabled by the Global Administrator';
    }

    if (empty($params['customfields']['vpsid'])) {
        $params['customfields']['vpsid'] = virtualizor_getvpsid($params['serviceid']);
    }

    // Setup cPanel licenses if cPanel configurable option is set
    if($ctrlpanel != -1 && $ctrlpanel != 'none'){

        if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['buy_cpanel_login']) && !empty($virtualizor_conf['cp']['buy_cpanel_apikey'])){

            $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=vs&vpsid='.$id.'&vps_uuid='.$uuid);

            $data = $data['vs'][$params['customfields']['vpsid']]['ips'];

            $cpanel_ip = array_shift($data);

            logActivity("CPANEL : cPanel delete for ip $cpanel_ip");

            $url = 'https://www.buycpanel.com/api/cancel.php?';
            $login = 'login='.$virtualizor_conf['cp']['buy_cpanel_login'].'&';
            $key = 'key='.$virtualizor_conf['cp']['buy_cpanel_apikey'].'&';
            $currentip = 'currentip='.$cpanel_ip.'&';
            $url .= $login.$key.$currentip;

            $ret = file_get_contents($url);

            $ret = json_decode($ret);

            if($ret->success == 0){
                return 'Errors : cPanel Licensing : '.$ret->faultstring;
            }
        }

        if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['cpanel_manage2_username']) && !empty($virtualizor_conf['cp']['cpanel_manage2_password'])){

            virt_remove_cpanel_license($params);

        }
    }

    $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=vs&delete='.$id.'&delete_uuid='.$uuid);

    if(empty($data)){
        return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
    }

    // echo "<pre>";print_r($params);echo "</pre>";
    // echo "<pre>";print_r($data);echo "</pre>";

    // If the VPS has been deleted
    if ($data['done']) {

        if($loglevel > 0) logActivity('Data after termination : '.var_dump($data, 1));

        // vpsid of virtualizor
        $query = Capsule::table('tblcustomfields')->select('id')->where('relid',$params["pid"])->where('fieldname','vpsid')->get();
        $res = (array) $query[0];

        // vps_uuid of virtualizor
        $query1 = Capsule::table('tblcustomfields')->select('id')->where('relid',$params["pid"])->where('fieldname','vps_uuid')->get();
        $res1 = (array) $query1[0];

        Capsule::table('tblcustomfieldsvalues')
            ->where('relid',$params["serviceid"])
            ->where('fieldid',$res['id'])
            ->update(
                array('value' => '')
            );

        Capsule::table('tblcustomfieldsvalues')
            ->where('relid',$params["serviceid"])
            ->where('fieldid',$res1['id'])
            ->update(
                array('value' => '')
            );

        if($loglevel > 0) logActivity("UPDATE `tblcustomfieldsvalues` SET `value` = '' WHERE `relid` = '".$params["serviceid"]."' AND `fieldid` = '".$res['id']."'");

        // Do we have to preserve th einformation about the IP
        if(empty($virtualizor_conf['admin_ui']['preserve_info'])){
            // The Dedicated IP
            Capsule::table('tblhosting')
                ->where('id',$params["serviceid"])
                ->update(array(
                    'dedicatedip' => '',
                    'assignedips' => ''
                ));

        }
        $result = "success";
    } else {
        $result = empty($data['error_msg']) ? "There was some error deleting the VPS" : $data['error_msg'];
    }
    
    #custom Code
    virtualizor_DeleteAuroraAccount($params);
    #custom code end.

    return $result;
}

function virtualizor_SuspendAccount($params) {

    global $virtualizor_conf;

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=vs&suspend='.$id.'&suspend_uuid='.$uuid);

    if(empty($data)){
        return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
    }

    $ctrlpanel = (empty($params['configoptions'][v_fn('ctrlpanel')]) ? -1 : strtolower(trim($params['configoptions'][v_fn('ctrlpanel')])));

    if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['cpanel_manage2_username']) && !empty($virtualizor_conf['cp']['cpanel_manage2_password'])){

        virt_remove_cpanel_license($params);

    }
    
    virtualizor_DeleteAuroraAccount($params);

// echo "<pre>";print_r($params);echo "</pre>";
// echo "<pre>";print_r($data);echo "</pre>";

    if ($data['done']) {
        $result = "success";
    } else {
        $result = "There was some error suspending the VPS";
    }
    
    
    
    return $result;
}

function virtualizor_UnsuspendAccount($params) {

    global $virtualizor_conf;
    
    virtualizor_CreateAuroraAccount($params);

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=vs&unsuspend='.$id.'&unsuspend_uuid='.$uuid);

    if(empty($data)){
        return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
    }

    // echo "<pre>";print_r($params);echo "</pre>";
    // echo "<pre>";print_r($data);echo "</pre>";

    $ctrlpanel = (empty($params['configoptions'][v_fn('ctrlpanel')]) ? -1 : strtolower(trim($params['configoptions'][v_fn('ctrlpanel')])));

    if($ctrlpanel == 'cpanel' && !empty($virtualizor_conf['cp']['cpanel_manage2_username']) && !empty($virtualizor_conf['cp']['cpanel_manage2_password'])){

        virt_add_cpanel_license($params);

    }

    if ($data['done']) {
        $result = "success";
    } else {
        $result = "There was some error unsuspending the VPS";
    }
    return $result;
}

function virtualizor_ChangePassword($params) {

    # Code to perform action goes here...
    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=editvs&vpsid='.$id.'&vps_uuid='.$uuid);

    if(empty($data)){
        return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
    }

    $post_vps = $data['vps'];

    // Are there any configurable options
    if(!empty($params['configoptions'])){

        foreach($params['configoptions'] as $k => $v){
            if(!isset($post_vps[$k])){
                $post_vps[$k] = $v;
            }
        }

    }

    $post_vps['editvps'] = 1;

    $post_vps['rootpass'] = $params['password'];

    //logActivity('Post Array : '.var_export($params, 1));

    if($loglevel > 0) logActivity('Post Array : '.var_export($post_vps, 1));

    $ret = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=editvs&vpsid='.$id.'&vps_uuid='.$uuid, array(), $post_vps);

    unset($ret['scripts']);
    unset($ret['iscripts']);
    unset($ret['ostemplates']);
    unset($ret['isos']);

    if($loglevel > 0) logActivity('Post Result : '.var_export($ret, 1));

    if(empty($ret)){
        return 'Could not load the server data after processing.'.Virtualizor_Curl::error($params["serverip"]);
    }

    if(!empty($ret['done'])){

        $result = "success";
    }else{

        if(!empty($ret['error'])){
            return 'Errors : '.implode('<br>', $ret['error']);
        }

        $result = 'Unknown error occured. Please check logs';
    }

    return $result;
}

function virtualizor_ChangePackage($params) {

    global $virtualizor_conf;

    $loglevel = (int) @$_REQUEST['loglevel'];
    $serviceid = $params["serviceid"]; # Unique ID of the product/service in the WHMCS Database

    if(!empty($virtualizor_conf['loglevel'])){
        $loglevel = $virtualizor_conf['loglevel'];
    }

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    // Get the Data
    $data = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=editvs&vpsid='.$id.'&vps_uuid='.$uuid);

    if(empty($data)){
        return 'Could not load the server data.'.Virtualizor_Curl::error($params["serverip"]);
    }

    $post_vps = $data['vps'];

    if($loglevel > 0) logActivity('Change Package Params : '.var_export($params, 1));
    if($loglevel > 0) logActivity('Orig VPS : '.var_export($post_vps, 1));

    // Are you using New module ?
    if(!in_array($params['configoption1'], array('OpenVZ', 'Xen PV', 'Xen HVM', 'KVM', 'XCP HVM', 'XCP PV', 'LXC', 'Virtuozzo OpenVZ' , 'Virtuozzo KVM', 'Proxmox KVM', 'Proxmox OpenVZ', 'Proxmox LXC'))){
        $post_vps = array();

        // Now get the plan ID to post
        $tmp_plid = explode('-', $params['configoption3']);
        $post_vps['plid'] = trim($tmp_plid[0]);
        $virttype = $data['vps']['virt'];
        $post_vps['user_email'] = $params["clientsdetails"]['email'];

        //logActivity('Params : '.var_export($params, 1));

        if($loglevel > 0) logActivity('params : '.var_export($params, 1));

        // Fixes for SolusVM imported ConfigOptions
        if(empty($post_vps['ram']) && !empty($params['configoptions']['Memory'])){
            $post_vps['ram'] = $params['configoptions']['Memory'];
            if(!empty($virtualizor_conf['ram_in_gb'])){
                $post_vps['ram'] = $post_vps['ram']*1024;
            }
        }
        if(empty($post_vps['space']) && !empty($params['configoptions']['Disk Space'])){
            $post_vps['space'] = $params['configoptions']['Disk Space'];
        }
        if(empty($post_vps['cores']) && !empty($params['configoptions']['CPU'])){
            $post_vps['cores'] = $params['configoptions']['CPU'];
        }

        if(!empty($params['configoptions'][v_fn('ips')])){
            $post_vps['num_ips'] = $params['configoptions'][v_fn('ips')];
        }

        if(!empty($params['configoptions'][v_fn('ips_int')])){
            $post_vps['num_ips_int'] = $params['configoptions'][v_fn('ips_int')];
        }

        if(!empty($params['configoptions'][v_fn('ips6')])){
            $post_vps['num_ips6'] = $params['configoptions'][v_fn('ips6')];
        }

        if(!empty($params['configoptions'][v_fn('ips6_subnet')])){
            $post_vps['num_ips6_subnet'] = $params['configoptions'][v_fn('ips6_subnet')];
        }

        if(!empty($params['configoptions'][v_fn('space')])){
            $post_vps['space'] = $params['configoptions'][v_fn('space')];
        }

        if(!empty($params['configoptions'][v_fn('ram')])){
            $post_vps['ram'] = $params['configoptions'][v_fn('ram')];
            if(!empty($virtualizor_conf['ram_in_gb'])){
                $post_vps['ram'] = $post_vps['ram']*1024;
            }
        }

        if(!empty($params['configoptions'][v_fn('swapram')])){
            $post['swapram'] = $params['configoptions'][v_fn('swapram')];
        }

        if(!empty($params['configoptions'][v_fn('bandwidth')])){
            $post_vps['bandwidth'] = $params['configoptions'][v_fn('bandwidth')];
        }

        if(!empty($params['configoptions'][v_fn('cores')])){
            $post_vps['cores'] = $params['configoptions'][v_fn('cores')];
        }

        if(!empty($params['configoptions'][v_fn('network_speed')])){
            $post_vps['network_speed'] = $params['configoptions'][v_fn('network_speed')];
        }

        if(!empty($params['configoptions'][v_fn('cpu_percent')])){
            $post_vps['cpu_percent'] = $params['configoptions'][v_fn('cpu_percent')];
        }

        if(!empty($params['configoptions'][v_fn('topology_sockets')])){
            $post_vps['topology_sockets'] = $params['configoptions'][v_fn('topology_sockets')];
        }

        if(!empty($params['configoptions'][v_fn('topology_cores')])){
            $post_vps['topology_cores'] = $params['configoptions'][v_fn('topology_cores')];
        }

        if(!empty($params['configoptions'][v_fn('topology_threads')])){
            $post_vps['topology_threads'] = $params['configoptions'][v_fn('topology_threads')];
        }

        // Are there any configurable options
        if(!empty($params['configoptions'])){
            foreach($params['configoptions'] as $k => $v){
                if(!isset($post_vps[$k])){
                    $post_vps[$k] = $v;
                }
            }
        }

        $post_vps['hostname'] = $data['vps']['hostname'];

        $post_vps['editvps'] = 1;

        if($loglevel > 0) logActivity('Post Array : '.var_export($post_vps, 1));

        $ret = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=editvs&vpsid='.$id.'&vps_uuid='.$uuid, array(), $post_vps);

        //if($loglevel > 0) logActivity('Return after Edit: '.var_export($ret, 1));

        // Fill the variables as per the OLD module as it will be inserted to WHMCS. Like ips, ips6, etc..
        if(!empty($ret['vps']['ips'])){
            $post_vps['ips'] = $ret['vps']['ips'];
        }

        if(!empty($ret['vps']['ips6'])){
            $post_vps['ipv6'] = $ret['vps']['ips6'];
        }

        if(!empty($ret['vps']['ips6_subnet'])){
            $post_vps['ipv6_subnet'] = $ret['vps']['ips6_subnet'];
        }

        if(!empty($ret['vps']['ips_int'])){
            $post_vps['ips_int'] = $ret['vps']['ips_int'];
        }

        // This is old method
    }else{

        // POST Variables
        $post_vps['space'] = (empty($params['configoptions'][v_fn('space')]) || $params['configoptions'][v_fn('space')] == 0 ? $params['configoption2'] : $params['configoptions'][v_fn('space')]);
        $post_vps['ram'] = (empty($params['configoptions'][v_fn('ram')]) || $params['configoptions'][v_fn('ram')] == 0 ? $params['configoption4'] : $params['configoptions'][v_fn('ram')]);
        if(!empty($virtualizor_conf['ram_in_gb'])){
            $post_vps['ram'] = $post_vps['ram']*1024;
        }
        $post_vps['bandwidth'] = (empty($params['configoptions'][v_fn('bandwidth')]) || $params['configoptions'][v_fn('bandwidth')] == 0 ? (empty($params['configoption7']) ? '0' : $params['configoption7']) : $params['configoptions'][v_fn('bandwidth')]);
        $post_vps['cores'] = (empty($params['configoptions'][v_fn('cores')]) || $params['configoptions'][v_fn('cores')] == 0 ? $params['configoption9'] : $params['configoptions'][v_fn('cores')]);
        $post_vps['network_speed'] = (empty($params['configoptions'][v_fn('network_speed')]) || $params['configoptions'][v_fn('network_speed')] == 0 ? $params['configoption14'] : $params['configoptions'][v_fn('network_speed')]);
        $post_vps['cpu_percent'] = (empty($params['configoptions'][v_fn('cpu_percent')]) || $params['configoptions'][v_fn('cpu_percent')] == 0 ? $params['configoption10'] : $params['configoptions'][v_fn('cpu_percent')]);
        $post_vps['cpu'] = $params['configoption8'];

        $post_vps['inodes'] = $params['configoption3'];
        $post_vps['burst'] = $params['configoption5'];
        $post_vps['priority'] = $params['configoption11'];
        $post_vps['swapram'] = $params['configoption6'];

        // Fixes for SolusVM imported ConfigOptions
        if(empty($post_vps['ram']) && !empty($params['configoptions']['Memory'])){
            $post_vps['ram'] = $params['configoptions']['Memory'];
            if(!empty($virtualizor_conf['ram_in_gb'])){
                $post_vps['ram'] = $post_vps['ram']*1024;
            }
        }
        if(empty($post_vps['space']) && !empty($params['configoptions']['Disk Space'])){
            $post_vps['space'] = $params['configoptions']['Disk Space'];
        }
        if(empty($post_vps['cores']) && !empty($params['configoptions']['CPU'])){
            $post_vps['cores'] = $params['configoptions']['CPU'];
        }

        if($params['configoption12'] == 'yes' || $params['configoption12'] == 'on'){
            $post_vps['vnc'] = 1;
            if(empty($vps['vnc'])){
                $post_vps['vncpass'] = generateRandStr(8);
            }
        }

        $virttype = $post_vps['virt'];

        // IPs are the same always
        $post_vps['ips'] = $post_vps['ips'];

        // Add the IPv6
        if(!empty($post_vps['ips6'])){
            $post_vps['ipv6'] = $post_vps['ips6'];
        }

        // Add the IPv6 Subnet
        if(!empty($post_vps['ips6_subnet'])){
            $post_vps['ipv6_subnet'] = $post_vps['ips6_subnet'];
            foreach($post_vps['ipv6_subnet'] as $k => $v){
                $tmp = explode('/', $v);
                $post_vps['ipv6_subnet'][$k] = $tmp[0];
            }
        }

        $numips = (empty($params['configoptions'][v_fn('ips')]) || $params['configoptions'][v_fn('ips')] == 0 ? $params['configoption13'] : $params['configoptions'][v_fn('ips')]);
        $numips6 = (empty($params['configoptions'][v_fn('ips6')]) || $params['configoptions'][v_fn('ips6')] == 0 ? $params['configoption17'] : $params['configoptions'][v_fn('ips6')]);
        $numips6_subnet = (empty($params['configoptions'][v_fn('ips6_subnet')]) || $params['configoptions'][v_fn('ips6_subnet')] == 0 ? $params['configoption18'] : $params['configoptions'][v_fn('ips6_subnet')]);

        // Fixes for SolusVM imported ConfigOptions
        if(empty($numips) && !empty($params['configoptions']['Extra IP Address'])){
            $numips = $params['configoptions']['Extra IP Address'];
        }

        // Remove some IPs
        if($numips < count($post_vps['ips'])){

            $i = 0;
            $newips = array();

            foreach($post_vps['ips'] as  $k => $v){

                // We have completed
                if($numips == $i){
                    break;
                }

                $newips[$k] = $v;
                $i++;
            }

            $post_vps['ips'] = $newips;

            // Add some IPs
        }elseif($numips > count($post_vps['ips'])){

            $toadd = $numips - count($post_vps['ips']);

            // Assign the IPs
            foreach($data['ips'] as $k => $v){

                if(in_array($v['ip'], $post_vps['ips'])){
                    continue;
                }

                $post_vps['ips'][$k] = $v['ip'];

                if($numips == count($post_vps['ips'])){
                    break;
                }
            }

            // Were there enough IPs
            if(count($post_vps['ips']) < $numips){
                return 'There are insufficient IPs on the server';
            }

        }

        // Remove some IPv6 Subnets
        if($numips6_subnet < count($post_vps['ipv6_subnet'])){

            $i = 0;
            $newips = array();

            foreach($post_vps['ipv6_subnet'] as  $k => $v){

                // We have completed
                if($numips6_subnet == $i){
                    break;
                }

                $newips[$k] = $v;
                $i++;

            }

            $post_vps['ipv6_subnet'] = $newips;

            // Add some IP Subnet
        }elseif($numips6_subnet > count($post_vps['ipv6_subnet'])){

            $toadd = $numips6_subnet - count($post_vps['ipv6_subnet']);

            // Assign the IP Subnets
            foreach($data['ips6_subnet'] as $k => $v){

                if(in_array($v['ip'], $post_vps['ipv6_subnet'])){
                    continue;
                }

                $post_vps['ipv6_subnet'][$k] = $v['ip'];

                if($numips6_subnet == count($post_vps['ipv6_subnet'])){
                    break;
                }
            }

            // Were there enough IPs
            if(count($post_vps['ipv6_subnet']) < $numips6_subnet){
                return 'There are insufficient IPv6 Subnets on the server';
            }

        }

        // Remove some IPv6
        if($numips6 < count($post_vps['ipv6'])){

            $i = 0;
            $newips = array();

            foreach($post_vps['ipv6'] as  $k => $v){

                // We have completed
                if($numips6 == $i){
                    break;
                }

                $newips[$k] = $v;
                $i++;

            }

            $post_vps['ipv6'] = $newips;

            // Add some IPs
        }elseif($numips6 > count($post_vps['ipv6'])){

            $toadd = $numips6 - count($post_vps['ipv6']);

            // Assign the IPs
            foreach($data['ips6'] as $k => $v){

                if(in_array($v['ip'], $post_vps['ipv6'])){
                    continue;
                }

                $post_vps['ipv6'][$k] = $v['ip'];

                if($numips6 == count($post_vps['ipv6'])){
                    break;
                }
            }

            // Were there enough IPs
            if(count($post_vps['ipv6']) < $numips6){
                return 'There are insufficient IPv6 Addresses on the server';
            }

        }

        // Are there any configurable options
        if(!empty($params['configoptions'])){
            foreach($params['configoptions'] as $k => $v){
                if(!isset($post_vps[$k])){
                    $post_vps[$k] = $v;
                }
            }
        }

        $post_vps['editvps'] = 1;

        if($loglevel > 0) logActivity('Post Array : '.var_export($post_vps, 1));

        $ret = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=editvs&vpsid='.$id.'&vps_uuid='.$uuid, array(), $post_vps);

    }// End of OLD module

    unset($ret['scripts']);
    unset($ret['iscripts']);
    unset($ret['ostemplates']);
    unset($ret['isos']);

    if($loglevel > 0) logActivity('Post Result : '.var_export($ret, 1));

    if(empty($ret)){
        return 'Could not load the server data after processing.'.Virtualizor_Curl::error($params["serverip"]);
    }

    if(!empty($ret['done'])){

        $result = "success";

        $tmp_ips = array();

        if(!empty($post_vps['ips'])){
            foreach($post_vps['ips'] as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        if(!empty($post_vps['ipv6_subnet'])){
            foreach($post_vps['ipv6_subnet'] as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        if(!empty($post_vps['ipv6'])){
            foreach($post_vps['ipv6'] as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        if(!empty($post_vps['ips_int'])){
            foreach($post_vps['ips_int'] as $k => $v){
                $tmp_ips[] = $v;
            }
        }

        //logActivity(var_export($tmp_ips, 1));

        // The Dedicated IP
        Capsule::table('tblhosting')
            ->where('id',$serviceid)
            ->update(array(
                'dedicatedip' => $tmp_ips[0]
            ));

        // Extra IPs
        $tmp_cnt = count($tmp_ips);
        if(!empty($tmp_cnt)){
            unset($tmp_ips[0]);
            Capsule::table('tblhosting')
                ->where('id',$serviceid)
                ->update(array(
                    'assignedips' => implode("\n", $tmp_ips)
                ));
        }

    }else{

        if(!empty($ret['error'])){
            return 'Errors : '.implode('<br>', $ret['error']);
        }

        $result = 'Unknown error occured. Please check logs';

    }

    return $result;

}

function virtualizor_AdminLink($params) {

    global $virtualizor_conf;

    // Find the servers hostname
    $res = Capsule::table('tblservers')->select('hostname')->where('id',$params['serverid'])->get();
    $server_details = (array) $res[0];
    $params['serverhostname'] = $server_details['hostname'];

    $serverip = empty($params['serverhostname']) ? $params['serverip'] : $params['serverhostname'];

    $code = '<a href="https://'.$serverip.':4085/index.php?act=login" target="_blank">Virtualizor Admin Panel</a>';
    return $code;
}

function virtualizor_LoginLink($params) {

    global $virtualizor_conf;

    // Find the servers hostname
    $res = Capsule::table('tblservers')->select('hostname')->where('id',$params['serverid'])->get();
    $server_details = (array) $res[0];
    $params['serverhostname'] = $server_details['hostname'];

    $serverip = empty($params['serverhostname']) ? $params['serverip'] : $params['serverhostname'];
    $port = (!empty($virtualizor_conf['use_sso_on_80']) ? 80 : 4083);
    $code = "<a href=\"https://".$serverip.":".$port."/\" target=\"_blank\" style=\"color:#cc0000\">Login to Virtualizor</a>";
    return $code;

}

function virtualizor_AdminCustomButtonArray() {
    # This function can define additional functions your module supports, the example here is a reboot button and then the reboot function is defined below
    $buttonarray = array(
        "Start VPS" => "start",
        "Reboot VPS" => "reboot",
        "Stop VPS"=> "stop",
        "Poweroff VPS"=> "poweroff",
        "Suspend Network"=> "suspend_net",
        "Unsuspend Network"=> "unsuspend_net",
        "重建极光转发"=> "rebuild_aurorapf"
    );
    return $buttonarray;
}

function virtualizor_rebuild_aurorapf($params){
    virtualizor_DeleteAuroraAccount($params);
    virtualizor_CreateAuroraAccount($params);
    return "success";
}

function virtualizor_ClientAreaCustomButtonArray() {

    global $virtualizor_conf;
    if(!empty($virtualizor_conf['client_ui']['hide_sidebar'])){
        return array();
    }

    # This function can define additional functions your module supports, the example here is a reboot button and then the reboot function is defined below
    $buttonarray = array(
        "Start VPS" => "start",
        "Reboot VPS" => "reboot",
        "Stop VPS"=> "stop",
        "Poweroff VPS"=> "poweroff",
        "To PortForward Panel"=> "portforwardpanel",
    );
    return $buttonarray;
}

function virtualizor_portforwardpanel($params) {
    $aurora_pf_panel_address = $params['configoption5'];
    $clientEmail = $params['clientsdetails']['email'];
    
    $aurora_pf_account_name='serviceid'.$params['serviceid'].'email'.$clientEmail;
    
    echo "<script language=\"JavaScript\">\r\n";
    echo " alert(\"账号：".$aurora_pf_account_name."，初始密码为 hellovmuser 。\\n点击确定即跳转\");\r\n";
    echo " location.replace(\"".$aurora_pf_panel_address."\");\r\n"; // 自己修改网址
    echo "</script>";
    #echo "<script>alert('账号为您注册本站的邮箱:，初始密码为您的邮箱。<br/>点击确定即跳转')";
    #echo '<meta http-equiv="Refresh" content="0;url='.$redirect_url.'">';
    exit;

}

class Virtualizor_Curl {

    public static function fix_uuid_field(){
        // vps_uuid of virtualizor
        $query = Capsule::table('tblcustomfields')
            ->select(Capsule::raw('relid, id'))
            ->where('fieldname', 'vps_uuid')
            ->get();

        $products = array();
        $check_products = 0;
        foreach($query as $q){
            $products[$q->relid][$q->id] = (array) $q;
            if(count($products[$q->relid]) > 1){
                $check_products = 1;
            }
        }

        if(!empty($products) && !empty($check_products)){

            foreach($products as $relid => $rows){
                if(count($rows) == 1){
                    unset($products[$relid]);
                    continue;
                }
                $delete = 0;
                foreach($rows as $id => $row){
                    //skip first value
                    if(!empty($delete)){
                        Capsule::table('tblcustomfieldsvalues')->where('fieldid', $id)->delete();
                        Capsule::table('tblcustomfields')->where('id', $id)->delete();
                    }
                    $delete = 1;
                }
            }

        }

    }

    public static function create_uuid_field($pid, $serviceid, $uuid){

        Virtualizor_Curl::fix_uuid_field();

        // vps_uuid of virtualizor
        $query = Capsule::table('tblcustomfields')
            ->where('relid', $pid)
            ->where('fieldname', 'vps_uuid')
            ->get();
        $result = (array) $query[0];
        $fieldid = $result['id'];

        //logActivity('$result:'.var_export($result,1));

        // We will check if there is an entry if not we will insert it.
        $query1 = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $serviceid)
            ->where('fieldid', $result['id'])
            ->get();
        $sel_res = (array) $query1[0];

        //logActivity('$sel_res:'.var_export($sel_res,1));

        if(empty($sel_res['value'])){

            Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceid)
                ->where('fieldid', $result['id'])
                ->update(
                    array('value' => $uuid)
                );
        }

        if(empty($result['relid'])){

            $fieldid = Capsule::table('tblcustomfields')
                ->insertGetId(array(
                    'type' => 'product',
                    'relid' => $pid,
                    'fieldname' => 'vps_uuid',
                    'fieldtype' => 'text',
                    'adminonly' => 'on'
                ));

        }

        if(!isset($sel_res['value'])){

            $insertvalues = Capsule::table('tblcustomfieldsvalues')
                ->insert(array(
                    'value' => $uuid,
                    'relid' => $serviceid,
                    'fieldid' => $fieldid
                ));

        }
    }

    public static function error($ip = ''){

        $err = '';

        if(!empty($GLOBALS['virt_curl_err'])){
            $err .= ' Curl Error: '.$GLOBALS['virt_curl_err'];
        }

        if(!empty($ip)){
            $err .= ' (Server IP : '.$ip.')';
        }

        return $err;
    }

    public static function make_api_call($ip, $pass, $path, $data = array(), $post = array(), $cookies = array()){

        global $virtualizor_conf, $whmcsmysql;

        $key = generateRandStr(8);
        $apikey = make_apikey($key, $pass);

        $url = 'https://'.$ip.':4085/'.$path;
        $url .= (strstr($url, '?') ? '' : '?');
        $url .= '&api=serialize&apikey='.rawurlencode($apikey).'&skip_callback=whmcs';

        // Pass some data if there
        if(!empty($data)){
            $url .= '&apidata='.rawurlencode(base64_encode(serialize($data)));
        }

        if($virtualizor_conf['loglevel'] > 0){
            logActivity('URL : '. $url);
        }

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        // Time OUT
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

        // UserAgent
        curl_setopt($ch, CURLOPT_USERAGENT, 'Softaculous');

        // Cookies
        if(!empty($cookies)){
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, http_build_query($cookies, '', '; '));
        }

        if(!empty($post)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Get response from the server.
        $resp = curl_exec($ch);

        if(empty($resp)){
            $GLOBALS['virt_curl_err'] = curl_error($ch);
        }

        curl_close($ch);

        // The following line is a method to test
        //if(preg_match('/sync/is', $url)) echo $resp;

        if(empty($resp)){
            return false;
        }

        // As a security prevention measure - Though this cannot happen
        $resp = str_replace($pass, '12345678901234567890123456789012', $resp);

        $r = _unserialize($resp);

        if(empty($r)){
            return false;
        }

        return $r;
    }

    public static function e_make_api_call($ip, $pass, $vid, $path, $post = array()){
        $key = generateRandStr(8);
        $apikey = make_apikey($key, $pass);

        $url = 'https://'.$ip.':4083/'.$path;
        $url .= (strstr($url, '?') ? '' : '?');
        $url .= '&svs='.$vid.'&api=serialize&apikey='.rawurlencode($apikey).'&skip_callback=whmcs';

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        // Time OUT
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

        // UserAgent and Cookies
        curl_setopt($ch, CURLOPT_USERAGENT, 'Softaculous');

        if(!empty($post)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Get response from the server.
        $resp = curl_exec($ch);
        curl_close($ch);

        // The following line is a method to test
        //if(preg_match('/os/is', $url)) echo $resp;

        if(empty($resp)){
            return false;
        }

        // As a security prevention measure - Though this cannot happen
        $resp = str_replace($pass, '12345678901234567890123456789012', $resp);

        $r = _unserialize($resp);

        if(empty($r)){
            return false;
        }

        return $r;
    }

    public static function action($params, $action, $post = array()){

        global $virt_verify, $virt_errors;

        $id = $params['customfields']['vpsid'];

        if(!empty($params['customfields']['vps_uuid'])){
            $post['uuid'] = $params['customfields']['vps_uuid'];
        }

        // Make the call
        $response = Virtualizor_Curl::e_make_api_call($params["serverip"], $params["serverpassword"], $id, 'index.php?'.$action, $post);

        if(empty($response)){
            $virt_errors[] = 'The action could not be completed as no response was received.';
            return false;
        }

        return $response;

    } // function virt_curl_action ends

    public static function curl_call($url, $header = 1, $time = 1, $post = array(), $cookie = ''){

        global $globals;

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $time);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

        // Follow redirects
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

        if(!empty($post)){
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        // Is there a Cookie
        if(!empty($cookie)){
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        if($header){

            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:2.0.1) Gecko/20100101 Firefox/4.0.1');

        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Get response from the server.
        $resp = curl_exec($ch);

        //echo curl_error($ch);

        return $resp;

    }


} // class virtualizor_curl ends


function virtualizor_newUI($params, $url_prefix = 'clientarea.php?action=productdetails', $modules_url = 'modules/servers'){

    global $virt_action_display, $virt_errors, $virt_resp, $virtualizor_conf, $whmcsmysql;

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }
    // Is the VPS there ?
    if(empty($id) && empty($uuid)){

        $params['customfields']['vpsid'] = virtualizor_getvpsid($params['serviceid']);

        if(empty($params['customfields']['vpsid'])){

            return 'VPS not provisioned';
        }

    }

    // New method of Virtualizor Module
    if(isset($_GET['give'])){

        //error_reporting(-1);

        $var['APP'] = 'Virtualizor'; // NOT USED
        $var['site_name'] = 'WHMCS';
        $var['API'] = $url_prefix.'&id='.$params['serviceid'].'&api=json&';
        $var['giver'] = $url_prefix.'&id='.$params['serviceid'].'&';
        $var['url'] = $url_prefix.'&id='.$params['serviceid'].'&';
        $var['copyright'] = 'Virtualizor';
        $var['version'] = '2.5';
        $var['logo'] = '';
        $var['theme'] = $modules_url.'/virtualizor/ui/';
        $var['theme_path'] = dirname(__FILE__).'/ui/';
        $var['images'] = $var['theme'].'images/';
        $var['virt_dev_license'] = ' ';
        $var['virt_pirated_license'] = ' ';

        if($_GET['give'] == 'index.html'){

            // We are zipping if possible
            if(function_exists('ob_gzhandler')){
                ob_start('ob_gzhandler');
            }

            // Read the file
            $data = file_get_contents($var['theme_path'].'index.html');

            $filetime = filemtime($var['theme_path'].'index.html');

        }

        if($_GET['give'] == 'combined.js'){

            // Read the file
            $data = '';
            $jspath = $var['theme_path'].'js2/';
            $files = array('jquery.min.js',
                'jquery.dataTables.min.js',
                'jquery.tablesorter.min.js',
                'jquery.flot.min.js',
                'jquery.flot.pie.min.js',
                'jquery.flot.stack.min.js',
                'jquery.flot.time.min.js',
                'jquery.flot.tooltip.min.js',
                'jquery.flot.symbol.min.js',
                'jquery.flot.axislabels.js',
                'jquery.flot.selection.min.js',
                'jquery.flot.resize.min.js',
                'jquery.scrollbar.min.js',
                'popper.min.js',
                'select2.js',
                'bootstrap.min.js',
                'jquery.responsivetabs.js',
                'virtualizor.js',
                'haproxy.js',
            );

            foreach($files as $k => $v){
                //echo $k.'<br>';
                $data .= file_get_contents($jspath.'/'.$v)."\n\n";
            }

            // We are zipping if possible
            if(function_exists('ob_gzhandler')){
                ob_start('ob_gzhandler');
            }

            // Type javascript
            header("Content-type: text/javascript; charset: UTF-8");

            // Set a zero Mtime
            $filetime = filemtime($var['theme_path'].'/js2/virtualizor.js');

        }

        if($_GET['give'] == 'style.css'){

            // Read the file
            $data = '';
            $jspath = $var['theme_path'].'css2/';
            $files = array('bootstrap.min.css',
                'all.min.css',
                'jquery.dataTables.min.css',
                'select2.css',
                'jquery.scrollbar.css',
                'style.css',
            );

            foreach($files as $k => $v){
                //echo $k.'<br>';
                $data .= file_get_contents($jspath.'/'.$v)."\n\n";
            }

            // Type CSS
            header("Content-type: text/css; charset: UTF-8");

            // We are zipping if possible
            if(function_exists('ob_gzhandler')){
                ob_start('ob_gzhandler');
            }

        }

        foreach($var as $k => $v){
            $data = str_replace('[['.$k.']]', $v, $data);
        }

        // Parse the languages
        vload_lang($params['clientsdetails']['language']);
        echo vparse_lang($data);

        die();
        exit(0);

    }

    if($_REQUEST['api'] == 'json'){

        // Overwrite certain variables
        $_GET['svs'] = $id;
        $_GET['uuid'] = $uuid;
        $_GET['SET_REMOTE_IP'] = $_SERVER['REMOTE_ADDR'];

        $res = Virtualizor_Curl::action($params, http_build_query($_GET), $_POST);

        $pid = $params["pid"]; # Product/Service ID
        $serviceid = $params["serviceid"];
        $uuid = $res['info']['vps']['uuid']; # VPS uuid

        Virtualizor_Curl::create_uuid_field($pid, $serviceid, $uuid);

        $res['uid'] = 0;

        echo json_encode($res);
        die();
        exit(0);
    }

    if($_GET['b'] == 'novnc' || (!empty($_REQUEST['novnc'])) && $_REQUEST['act'] == 'vnc'){

        $data = Virtualizor_Curl::action($params, 'act=vnc&novnc=1');

        // Find the servers hostname
        $res = Capsule::table('tblservers')->select('hostname')->where('id',$params['serverid'])->get();
        $server_details = (array) $res[0];
        $params['serverhostname'] = $server_details['hostname'];

        // fetch the novnc file
        $modules_url_vnc = $modules_url.'/virtualizor';
        $novnc_viewer = file_get_contents($modules_url_vnc.'/novnc/novnc.html');

        $novnc_password = $data['info']['password'];
        $vpsid = $params['customfields']['vpsid'];
        $novnc_serverip = empty($params['serverhostname']) ? $params['serverip'] : $params['serverhostname'];
        $proto = 'http';
        $port = 4081;
        $virt_port = 4082;
        $websockify = 'websockify';
        if(!empty($_SERVER['HTTPS']) || @$_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'){
            $proto = 'https';
            $port = 4083;
            $virt_port = 4083;
            $websockify = 'novnc/';
            $novnc_serverip = empty($params['serverhostname']) ? $params['serverip'] : $params['serverhostname'];
        }

        if($data['info']['virt'] == 'xcp'){
            $vpsid .= '-'.$data['info']['password'];

            if(!empty($data['info']['host']) && !empty($data['info']['serid'])){
                $novnc_serverip = $data['info']['host'];
            }
        }

        echo $novnc_viewer = vlang_vars_name($novnc_viewer, array('HOST' => $novnc_serverip,
            'PORT' => $port,
            'VIRTPORT' => $virt_port,
            'PROTO' => $proto,
            'WEBSOCKET' => $websockify,
            'TOKEN' => $vpsid,
            'PASSWORD' => $novnc_password,
            'MODULE_URL' => $modules_url_vnc));


        die();
    }

    // Java VNC
    if($_REQUEST['act'] == 'vnc' && !empty($_REQUEST['launch'])){

        $response = Virtualizor_Curl::action($params, 'act=vnc&launch=1&giveapplet=1', '', true);

        if(empty($response)){
            return false;
        }

        // Is the applet code in the API Response ?
        if(!empty($response['info']['applet'])){

            $applet = $response['info']['applet'];

        }else{

            $virttype = preg_match('/xcp/is', $params['configoption1']) ? 'xcp' : strtolower($params['configoption1']);

            // NonXCP
            if($virttype != 'xcp'){

                if(!empty($response['info']['port']) && !empty($response['info']['ip']) && !empty($response['info']['password'])){
                    $applet = '<APPLET ARCHIVE="https://s2.softaculous.com/a/virtualizor/files/VncViewer.jar" CODE="com.tigervnc.vncviewer.VncViewer" WIDTH="1" HEIGHT="1">
						<PARAM NAME="HOST" VALUE="'.$response['info']['ip'].'">
						<PARAM NAME="PORT" VALUE="'.$response['info']['port'].'">
						<PARAM NAME="PASSWORD" VALUE="'.$response['info']['password'].'">
						<PARAM NAME="Open New Window" VALUE="yes">
					</APPLET>';
                }

                // XCP
            }else{

                if(!empty($response['info']['port']) && !empty($response['info']['ip'])){
                    $applet = '<APPLET ARCHIVE="https://s2.softaculous.com/a/virtualizor/files/TightVncViewer.jar" CODE="com.tightvnc.vncviewer.VncViewer" WIDTH="1" HEIGHT="1">
						<PARAM NAME="SOCKETFACTORY" value="com.tightvnc.vncviewer.SshTunneledSocketFactory">
						<PARAM NAME="SSHHOST" value="'.$response['info']['ip'].'">
						<PARAM NAME="HOST" value="localhost">
						<PARAM NAME="PORT" value="'.$response['info']['port'].'">
						<PARAM NAME="Open New Window" VALUE="yes">
					</APPLET>';
                }

            }

        }

        echo $applet;

        die();

    }

    if(!empty($virtualizor_conf['client_ui']['direct_login'])){
        return "<center><a href=\"https://".$params["serverip"].":4083/\" target=\"_blank\">Login to Virtualizor</a></center>";
    }

    $code .= '<script data-cfasync="false" type="text/javascript">

var panel_checker = "";
var virt_page = "";
var panel_load_try_counter = 0;
function iResize(){
	try{
		document.getElementById("virtualizor_manager").style.height = 
		document.getElementById("virtualizor_manager").contentWindow.document.body.offsetHeight + "px";
	}catch(e){ };
}

function check_page_loaded(){
	var page_content = document.getElementsByClassName("page-content");
	if(page_content){
		$("#virtualizor_load_div").hide();
		clearInterval(virt_page);
	}
}

setInterval("iResize()", 1000);

function load_virtpanel(){
	var divID = "tab1";
	if (!document.getElementById(divID)) {
        divID = "domain";
    }
	
	// If we get the div with virtualizor_load_div then do not create new element
	if(document.getElementById("virtualizor_load_div")){
		myDiv = document.getElementById("virtualizor_load_div");
	}else{
		var myDiv = document.createElement("div");
		myDiv.id = "virtualizor_load_div";
	}
	
	myDiv.innerHTML = \'<center style="padding:10px; background-color: #FAFBD9;">Loading Panel options ...</center><br /><br /><br />\';
	
	document.getElementById(divID).appendChild(myDiv);
	
	// If we get the div with virtualizor_manager then do not create new element
	if(document.getElementById("virtualizor_manager")){
		iframe = document.getElementById("virtualizor_manager");
	}else{
		var iframe = document.createElement("iframe");
		iframe.id = "virtualizor_manager";
	}
	
	iframe.width = "100%";
	iframe.style.display = "none";
	iframe.style.border = "none";
	iframe.scrolling = "no";
	iframe.src = "'.$url_prefix.'&id='.$params['serviceid'].'&give=index.html#act=vpsmanage";
	document.getElementById(divID).appendChild(iframe);
	
	document.getElementById("virtualizor_manager").onload = function(){
		virt_page = setInterval(check_page_loaded, 2000);
		$(this).show();
		iResize();
	};
	
	$(".moduleoutput").each(function(){
		this.style.display = "none";
	});
};

function check_js_loaded(){
	
	if(panel_load_try_counter >= 30){
		clearInterval(panel_checker);
		var divID = "tab1";
		if (!document.getElementById(divID)) {
			divID = "domain";
		}
		document.getElementById(divID).innerHTML = "Failed to detect jQuery, please check jQuery is loaded properly or not";
		return false;
	}
	
	if(window.jQuery){
		load_virtpanel();
		clearInterval(panel_checker);
	}else{
		panel_load_try_counter++;
	}
};

panel_checker = setInterval(check_js_loaded,1000);

</script>';

    return $code;

}


function virtualizor_ClientArea($params) {

    global $virt_action_display, $virt_errors, $virt_resp, $virtualizor_conf, $whmcsmysql;

    // The new UI
    return virtualizor_newUI($params);

}

function virtualizor_start($params) {

    global $virt_action_display, $virt_errors;

    $virt_resp = Virtualizor_Curl::action($params, 'act=start&do=1');

    if(empty($virt_resp['done'])){
        $virt_action_display = 'The VPS failed to start';
        return $virt_action_display;
    }

    // Done
    return "success";

}

function virtualizor_stop($params) {

    global $virt_action_display, $virt_errors;

    $virt_resp = Virtualizor_Curl::action($params, 'act=stop&do=1');

    if(empty($virt_resp)){
        $virt_action_display = 'Failed to stop the VPS';
        return $virt_action_display;
    }

    // Done
    return "success";

}

function virtualizor_reboot($params) {

    global $virt_action_display, $virt_errors;

    $virt_resp = Virtualizor_Curl::action($params, 'act=restart&do=1');

    if(empty($virt_resp)){
        $virt_action_display = 'Failed to reboot the VPS';
        return $virt_action_display;
    }

    // Done
    return "success";

}


function virtualizor_poweroff($params) {

    global $virt_action_display, $virt_errors;

    $virt_resp = Virtualizor_Curl::action($params, 'act=poweroff&do=1');

    if(empty($virt_resp)){
        $virt_action_display = 'Failed to poweroff the VPS';
        return $virt_action_display;
    }

    // Done
    return "success";

}


function virtualizor_suspend_net($params) {

    global $virt_action_display, $virt_errors;

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $action = 'act=vs&suspend_net='.$id.'&suspend_net_uuid='.$uuid;

    $virt_resp = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?'.$action, array(), $post);

    if(empty($virt_resp['done'])){
        $virt_action_display = 'Failed to suspend the VPS network';
        return $virt_action_display;
    }

    // Done
    return "success";

}


function virtualizor_unsuspend_net($params) {

    global $virt_action_display, $virt_errors;

    $id = $params['customfields']['vpsid'];

    if(!empty($params['customfields']['vps_uuid'])){
        $uuid = $params['customfields']['vps_uuid'];
    }

    $action = 'act=vs&unsuspend_net='.$id.'&unsuspend_net_uuid='.$uuid;

    $virt_resp = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?'.$action, array(), $post);

    if(empty($virt_resp['done'])){
        $virt_action_display = 'Failed to unsuspend the VPS network';
        return $virt_action_display;
    }

    // Done
    return "success";

}

function virtualizor_TestConnection($params){

    $admin = Virtualizor_Curl::make_api_call($params["serverip"], $params["serverpassword"], 'index.php?act=addvs');
    $client = Virtualizor_Curl::e_make_api_call($params["serverip"], $params["serverpassword"], 0, 'index.php');

    if(empty($admin) || empty($client)){
        return array('error' => 'FAILED: Could not connect to Virtualizor. Please make sure that all Ports from 4081 to 4085 are open on your WHMCS Server or please check the server details entered are as displayed on Admin Panel >> Configuration >> Server Info.');
    }else{
        return array('success' => true);
    }
}

function virtualizor_enduser_panel($vars){

    global $virtualizor_conf;
    // echo '<pre>';
    // print_r($vars);

    // vpsid of virtualizor
    $query = Capsule::table('tblcustomfields')
        ->where('relid', $vars['pid'])
        ->where('fieldname', 'vpsid')
        ->get();
    $res = (array) $query[0];

    $query = Capsule::table('tblcustomfieldsvalues')
        ->select('value')
        ->where('relid', $vars['serviceid'])
        ->where('fieldid', $res['id'])
        ->get();
    $sel_res = (array) $query[0];

    // Make the Login system
    $pass = decrypt($vars['serverdata']['password']);
    $key = generateRandStr(8);
    $apikey = make_apikey($key, $pass);
    $_GET['SET_REMOTE_IP'] = $_SERVER['REMOTE_ADDR'];
    $tmp_hostname = $vars['serverdata']['hostname'];
    if(empty($tmp_hostname)){
        $tmp_hostname = $vars['serverdata']['ipaddress'];
    }

    $port = !empty($virtualizor_conf['use_sso_on_80']) ? 80 : 4083;

    // If $tmp_hostname is still empty that means $var does not have server data filled.
    // So now we have to find the server details byfrom DB.
    if(empty($tmp_hostname)){

        $query = Capsule::table('tblservers')
            ->where('type', 'virtualizor')
            ->get();
        $ser_data = (array) $query[0];
        //print_r($res);

        $tmp_hostname = $ser_data['hostname'];
        if(empty($tmp_hostname)){
            $tmp_hostname = $ser_data['ipaddress'];
        }
        $pass = decrypt($ser_data['password']);

    }

    $ret = Virtualizor_Curl::e_make_api_call($tmp_hostname, $pass, $sel_res['value'], '?act=sso&SET_REMOTE_IP='.$_SERVER['REMOTE_ADDR'].'&goto_cp='.rawurlencode(virtualizor_get_current_url()).'&svs='.$sel_res['value']);

    //$virtualizor_login = 'https://'.$tmp_hostname.':4083/index.php?act=login_sso&apikey='.$apikey.'&SET_REMOTE_IP='.$_SERVER['REMOTE_ADDR'].'&goto_cp='.rawurlencode(virtualizor_get_current_url()).'&svs='.$sel_res['value'].'&as='.$ret['sid'];

    $redirect_url = 'https://'.$tmp_hostname.':'.$port.'/'.$ret['token_key'].'/?as='.$ret['sid'].'&goto_cp='.rawurlencode(virtualizor_get_current_url()).'&svs='.$sel_res['value'];

    echo '<meta http-equiv="Refresh" content="0;url='.$redirect_url.'">';
    exit;
}

function virtualizor_login($vars){

    // Is is for virtualizor panel login
    if(empty($_REQUEST['vp_login'])){
        return true;
    }

    virtualizor_enduser_panel($vars);
    return true;

}

function virtualizor_get_current_url(){

    $protocol = (!empty($_SERVER['HTTPS']) ? "https://" : "http://");
    $server_port = ((!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) ? ':'.$_SERVER['SERVER_PORT'] : '');

    $parse = parse_url($_SERVER['HTTP_HOST']);
    if(empty($parse['port'])){
        $full_url = $protocol.$_SERVER['HTTP_HOST'].$server_port.$_SERVER['REQUEST_URI'];
    }else{
        $full_url = $protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    }

    $strpos = strpos($full_url, 'vp_login');
    $full_url = substr($full_url, 0, $strpos);
    $full_url = str_replace('&amp;', '&', $full_url);
    $full_url = rtrim($full_url, '&');

    return $full_url;
}

function virtualizor_primarySidebar($primarySidebar){
    global $virtualizor_conf;
    if(!empty($virtualizor_conf['client_ui']['disable_sso'])){
        return true;
    }

    //@var \WHMCS\View\Menu\Item $primarySidebar
    $newMenu = $primarySidebar->addChild(
        'Virtualizor',
        array(
            'name' => 'Virtualizor',
            'label' => 'Virtualizor',
            'order' => 99,
            'icon' => 'fa-cubes',
        )
    );
    $newMenu->addChild(
        'Virtualizor',
        array(
            'name' => 'Enduser Panel',
            'label' => 'Enduser Panel',
            'uri' => 'clientarea.php?action=productdetails&id='.$_GET['id'].'&vp_login='.md5(uniqid(rand(), true)),
            'order' => 10,
            'icon' => 'fa-share',
            'attributes' => array(
                'target' => '_blank'
            )
        )
    );
}

// Reports the error
function report_virtualizor_error($err){
    global $virtualizor_conf;

    $err = 'Virtualizor : '.$err;

    if(!empty($virtualizor_conf['debug_echo'])){
        echo $err.'<br>';
    }

    // Write to the file
    if(!empty($virtualizor_conf['debug_file'])){
        $fp = @fopen($virtualizor_conf['debug_file'], 'a');
        if($fp){
            @fwrite($fp, $err."\n");
            @fclose($fp);
        }
    }

    if(!empty($virtualizor_conf['log_error'])){
        error_log($err);
    }

    // Log Activity in WHMCS
    if(!empty($virtualizor_conf['logActivity'])){
        logActivity($err);
    }
}

add_hook('ClientAreaPage', 1, 'virtualizor_login');
add_hook('ClientAreaPrimarySidebar', 1, 'virtualizor_primarySidebar');

add_hook('ClientAreaPrimarySidebar', 1, 'virtualizor_hide_sidebar_menu');

function virtualizor_hide_sidebar_menu($primarySidebar){

    global $virtualizor_conf;
    if(empty($virtualizor_conf['client_ui']['hide_sidebar'])){
        return true;
    }

    //echo "<pre>";
    //print_r($primaryNavbar->getChild('Service Details Actions'));
    if (!is_null($primarySidebar->getChild('Service Details Actions'))) {
        $primarySidebar->getChild('Service Details Actions')->removeChild('Change Password');
    }
}

function virtualizor_getvpsid($serviceid){

    $vpsid = 0;

    $customfields = Capsule::table('tblcustomfields')
        ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
        ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
        ->where('tblcustomfieldsvalues.relid', '=', $serviceid)
        ->get();

    foreach ($customfields as $customfield) {
        if($customfield->fieldname == 'vpsid'){
            $vpsid = $customfield->value;
        }
    }

    return $vpsid;
}

function virtualizor_getcustomfields($serviceid){

    $data = array();

    $customfields = Capsule::table('tblcustomfields')
        ->join('tblcustomfieldsvalues', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
        ->select('tblcustomfields.fieldname', 'tblcustomfieldsvalues.value')
        ->where('tblcustomfieldsvalues.relid', '=', $serviceid)
        ->get();

    foreach ($customfields as $customfield) {

        if(strpos($customfield->fieldname, '|') !== false){
            $exploded = explode('|',$customfield->fieldname);
            $customfield->fieldname = $exploded[0];
        }


        if(strpos($customfield->value, '|') !== false){
            $exploded = explode('|',$customfield->value);
            $customfield->value = $exploded[0];
        }

        $data[$customfield->fieldname] = $customfield->value;

    }

    return $data;

}

/*
function r_print($re){
	echo '<pre>';
	print_r($re);
	echo '</pre>';	
}
function died(){
	print_r(error_get_last());
}

register_shutdown_function('died');
*/

?>

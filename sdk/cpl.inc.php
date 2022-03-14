<?php
//error_reporting(E_ALL & ~E_NOTICE);

class cPanelLicensing {
    function __construct ($user, $pass, $key = NULL) {
        if (!function_exists('curl_init')) {
            die("cPanelLicensing requires that curl+ssl support is compiled into the PHP interpreter\n");
        }
        $this->set_format("simplexml");
        $this->curl = curl_init();
        $this->user = $user;
        $this->pass = $pass;
        $this->key = $key;
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_HEADER, 1);
        curl_setopt($this->curl, CURLOPT_USERAGENT, 'cPanel Licensing Agent (php) 3.13' );
        if (isset($_SERVER["PHP_CURL_SSL_VERIFY_HOSTNAME"]) && !$_SERVER["PHP_CURL_SSL_VERIFY_HOSTNAME"]) {
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, 0);
        }
    }

    function __destruct () {
        curl_close($this->curl);
    }

    public function setCredentials ($user, $pass) {
        curl_setopt($this->curl, CURLOPT_USERPWD, $user . ':' . $pass);
    }

    public function set_format ($format) {
        $valid_formats = [
            "simplexml" => "xml",
            "xml" => "xml",
            "json" => "json",
            "yaml" => "yaml",
        ];
        if ($valid_formats[$format]) {
            $this->format = $format;
            $this->output = $valid_formats[$format];
            return $this->format;
        }
        else {
            die("set_format requires that the format is xml, json, yaml or simplexml\n");
        }
    }

    public function parseHTTP ($response) {
        # parse the response into a header array and content variables
        $pos = 0;
        if (preg_match("/(\S+) (\d+)\s*([^\r\n]*)\r?\n/", $response, $matches, 0, $pos)) {
            $status_code = $matches[2];
            $status_mesg = $matches[3];
            $pos += strlen($matches[0]);
        }
        $headers = [];
        while (1) {
            if (!isset($response[$pos])) {
                break;
            }
            elseif (preg_match("/\G([^:\s]+):\s*([^\r\n]*)\r?\n/", $response, $matches, 0, $pos)) {
                $headers[$matches[1]] = $matches[2];
                $pos += strlen($matches[0]);
            }
            elseif (preg_match("/\G\r?\n/", $response, $matches, 0, $pos)) {
                $pos += strlen($matches[0]);
                break;
            }
            else {
                break;
            }
        }
        $content = substr($response, $pos);
        $href = [
            "status_code" => $status_code,
            "status_mesg" => $status_mesg,
            "headers" => $headers,
            "content" => $content,
        ];
        return $href;
    }

    private function api_build_query ($query_data) {
        $query = [];
        foreach ($query_data as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $subvalue) {
                    $query[] = urlencode($name) . "=" . urlencode($subvalue);
                }
            } else {
                $query[] = urlencode($name) . "=" . urlencode($value);
            }
        }
        return implode($query, '&');
    }

    private function request ($function, $args = []) {
        if (!$function) {
            die("request requires that a function is defined\n");
        }
        $manage2_host = $_SERVER['MANAGE2_HOST'] ?: 'manage2.cpanel.net';
        $url = "https://$manage2_host/$function";
        $args["output"] = $this->output;
        if ($this->key) {
            $args["apikey"] = $this->key;
        }
        elseif ($this->user && $this->pass) {
            $this->setCredentials($this->user, $this->pass);
        }
        $query = $this->api_build_query($args);
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_POST, 1);
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $query);

        $response = curl_exec($this->curl);
        if ($response == false) {
            die('request failed: ' . curl_error($this->curl) . "\n" );
        }
        $href = $this->parseHTTP($response);
        if ($href["status_code"] != 200) {
            die("request failed: $href[status_code] $href[status_mesg]\n");
        }

        if ( $this->format == 'simplexml' || $this->format == 'xml' ) {
            $ref = simplexml_load_string($href["content"]);
            # convert xml objects into regular php array
            $ref = json_decode(json_encode($ref), TRUE);
            return $ref;
        }
        elseif ( $this->format == 'json' ) {
            return json_decode($href["content"]);
        }
        elseif ( $this->format == 'yaml' ) {
            return yaml_parse($href["content"]);
        }
        else {
            return $href["content"];
        }
    }

    private function validateID ($id) {
        if (preg_match("/^(L|P|G)?\d*$/", $id)) {
            return 1;
        }
        else {
            return 0;
        }
    }

    private function validateIP ($ip) {
        return preg_match("/^\d*\.\d*\.\d*\.\d*$/", $ip);
    }

    public function reactivateLicense ($args) {
        if (!array_key_exists('liscid', $args)) {
            die("reactivateLicense requires that the argument array contains element liscid\n");
        }
        if (!$this->validateID($args['liscid'])) {
            die("The liscid passed to was invalid\n");
        }
        return $this->request("XMLlicenseReActivate.cgi", $args);
    }

    public function expireLicense ($args) {
        if (!array_key_exists("liscid", $args)) {
            die("expireLicense requires that liscid elements exists in the array passed to it\n");
        }
        if (!$this->validateID($args['liscid'])) {
            die("the license ID was invalid\n");
        }
        return $this->request("XMLlicenseExpire.cgi", $args);
    }

    public function extendOnetimeUpdates ($args) {
        if (!array_key_exists("ip", $args)) {
            die("extendOnetimeUpdates requires that the element ip exists in the array is passed to it\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("extendOnetimeUpdates was passed an invalid ip\n");
        }
        return $this->request( "XMLonetimeext.cgi", $args );
    }

    public function changeip ($args) {
        if (!array_key_exists("oldip", $args) || !array_key_exists("newip", $args)) {
            die("changeip requires that oldip and newip elements exist in the array passed to it\n");
        }
        if (!$this->validateIP($args["newip"])) {
            die("the newip passed to changeip was invalid\n");
        }
        return $this->request("XMLtransfer.cgi", $args);
    }

    public function requestTransfer ($args) {
        if (!array_key_exists("ip", $args) || !array_key_exists("groupid", $args ) || !array_key_exists("packageid", $args)) {
            die("requestTransfer requires that ip, groupid and packageid elements exist in the array passed to it\n");
        }
        if (!$this->validateID($args["groupid"])) {
            die("The groupid passed to requestTransfer is invalid\n");
        }
        if (!$this->validateID( $args["packageid"])) {
            die("The package id passed to requestTransfer is invalid\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("the ip passed to requestTransfer was invalid\n");
        }
        return $this->request("XMLtransferRequest.cgi", $args);
    }

    public function activateLicense ($args) {
        if (!array_key_exists("ip", $args) || !array_key_exists("groupid", $args) || !array_key_exists("packageid", $args)) {
            die("activateLicense requires that ip, groupid and packageid elements exist in the array passed to it\n");
        }
        if (!$this->validateID($args['groupid'])) {
            die("acivateLicense was passed an invalid groupid\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("activateLicense was passed an invalid IP\n");
        }
        if (!$this->validateID($args['packageid'])) {
            die("activateLicense was passed an invalid packageid\n");
        }
        $args['legal'] = 1;
        return $this->request("XMLlicenseAdd.cgi", $args);
    }

    public function addPickupPass ($args) {
        if (!array_key_exists("pickup", $args)) {
            die("addPickupPass requires a pickup param\n");
        }
        return $this->request("XMLaddPickupPass.cgi", $args);
    }

    public function registerAuth ($args) {
        if (!array_key_exists("user", $args)) {
            die("registerAuth requires a user param\n");
        }
        if (!array_key_exists("pickup", $args)) {
            die("registerAuth requires a pickup param\n");
        }
        if (!array_key_exists("service", $args)) {
            die("registerAuth requires a service param\n");
        }
        $response = $this->request("XMLregisterAuth.cgi", $args);
        if ($this->format == "simplexml") {
            $this->setCredentials($args["user"], $response["key"]);
        }
        return $response;
    }

    public function fetchGroups () {
        return $this->request("XMLgroupInfo.cgi");
    }

    public function fetchLicenseRiskData ($args) {
        if (!array_key_exists("ip", $args)) {
            die("fetchLicenseRiskData requires that ip exists as an element in the array is passed to it\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("fetchLicenseRiskData was passed an invalid ip\n");
        }
        return $this->request("XMLsecverify.cgi", $args);
    }

    public function fetchLicenseRaw ($args) {
        $args = array_merge(["all" => 1], $args);
        if (!array_key_exists("ip", $args)) {
            die("cpanelLicesning::fetchLicenseRaw requires that ip exists as an element in the array is passed to it\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("fetchLicenseRaw was passed an invalid ip\n");
        }
        return $this->request("XMLRawlookup.cgi", $args);
    }

    public function fetchLicenseId ($args) {
        $args = array_merge(["all" => 1], $args);
        if (!array_key_exists('ip', $args)) {
            die("getLicenseId requires that an IP is passed to it\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("fetchLicenseId was passed an invalid ip\n");
        }
        return $this->request("XMLlookup.cgi", $args);
    }

    public function fetchPackages () {
        return $this->request("XMLpackageInfo.cgi");
    }

    public function fetchLicenses () {
        return $this->request("XMLlicenseInfo.cgi");
    }

    public function fetchExpiredLicenses () {
        return $this->request("XMLlicenseInfo.cgi", ["expired" => '1']);
    }

    public function findKey ($search, $ref) {
        if (array_key_exists("packages", $ref)) {
            $type = "packages";
        }
        elseif (array_key_exists("groups", $ref)) {
            $type = "groups";
        }
        else {
            die("findKey with an object that is not groups or packages\n");
        }
        foreach ($ref[$type] as $ref2) {
            foreach ($ref2 as $key => $value) {
                if ($value == $search) {
                    if (preg_match("/^[a-z](\d+)/i", $key, $matches)) {
                        $key = $matches[1];
                    }
                    return $key;
                }
            }
        }
        die("Could not find $type that matches $search\n");
    }

    public function updateGroup ($args) {
        $required_search_filters = [
            'ip',
            'package',
            'packageid',
            'oldgroup',
            'oldgroupid'
        ];

        $required_group_filters = [
            'group',
            'groupid'
        ];

        // At least one search filter is required
        if( !count(array_intersect($required_search_filters, array_keys($args)))) {
            die("updateGroup requires at least one of the following search filters: " . implode(', ', $required_search_filters) . "\n");
        }

        // At least one group filter is required
        if( !count(array_intersect($required_group_filters, array_keys($args)))) {
            die("updateGroup requires at least one of the following group filters: " . implode(', ', $required_group_filters) . "\n");
        }

        if (array_key_exists('ip', $args) && !$this->validateIP($args['ip'])) {
            die("updateGroup was passed an invalid ip\n");
        }

        return $this->request("XMLgroupUpdate.cgi", $args);
    }

    public function updatePackage ($args) {
        if (!array_key_exists("ip", $args)) {
            die("packageUpdate requires an ip param\n");
        }
        if (!$this->validateIP($args['ip'])) {
            die("packageUpdate was passed an invalid ip\n");
        }
        if (!(array_key_exists("newpackage", $args) || array_key_exists("newpackageid", $args))) {
          die("packageUpdate requires a newpackageid or newpackage parameter\n");
        }
        return $this->request("XMLpackageUpdate.cgi", $args);
    }

    public function addServiceCredit ($args) {
        if (!array_key_exists("liscid", $args)) {
            die("addServiceCredit requires a liscid param\n");
        }
        return $this->request("XMLserviceCredit.cgi", $args);
    }
}


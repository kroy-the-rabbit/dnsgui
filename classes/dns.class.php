<?php

    class dns {

        private $resolver = false;
        private $dns_servers = [];
        public $error = null;

        public $local_zones = [];
        public $clone_zones = [];
        public $local_zone_mappings = [];
        public $custom_config = "";
        public $needs_update = false;

        private function getDb() {
            $db = new SQLite3('../data/unbound.sqlite3');
            @$db->query("CREATE TABLE IF NOT EXISTS local_zones (id INTEGER PRIMARY KEY ASC, zone text, type varchar, active INTEGER default 1,primary_zone integer default 0,UNIQUE(zone))");
            @$db->query("CREATE TABLE IF NOT EXISTS local_zone_mapping (id INTEGER PRIMARY KEY ASC, hostname text, zone text, ip text, add_time TEXT,active INTEGER default 1,api_added INTEGER default 0,UNIQUE(hostname,ip))");
            @$db->query("CREATE TABLE clone_zones (zone text primary key, target_zone text,UNIQUE (zone))");
            return $db;
        }

        function load() {
            $db = $this->getDb(); 
            //Load up local zones
            $res = $db->query("select * from local_zones order by primary_zone desc, zone");
            while ($row = $res->fetchArray()) {
                $zone = [
                    'id'=>$row['id'],
                    'zone' =>$row['zone'],
                    'type' =>$row['type'],
                    'active' => $row['active'],
                    'primary_zone' => $row['primary_zone'],
                ];
                $this->local_zones[] = $zone;
            }
            $res = $db->query("select * from local_zone_mapping order by zone,hostname,ip");
            while ($row = $res->fetchArray()) {
                $mapping = [
                    'id'=>$row['id'],
                    'hostname' =>$row['hostname'],
                    'ip' =>$row['ip'],
                    'active' => $row['active'],
                    'add_time' => $row['add_time'],
                ];

                $this->local_zone_mappings[$row['zone']][] = $mapping;
            }
            $res = $db->query("select * from clone_zones");
            while ($row = $res->fetchArray()){
                $this->clone_zones[$row['target_zone']][] = $row['zone'];
            }

            //Get custom config file
            $this->custom_config = file_get_contents("../data/unbound.conf.d/custom.conf");
            if (file_exists("../data/ubound_needs_refresh.lck")) {
                $this->needs_update = true;            
            }
            
            
        }

        private function updateCustomConfig($config) {
            file_put_contents("../data/unbound.conf.d/custom.conf",$config);
            touch ("../data/ubound_needs_refresh.lck");
        }



        private function addLocalZone($zone, $type) {
            $db = $this->getDb(); 
            $stmt = $db->prepare("insert into local_zones (zone,type) values (:zone,:type)");
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            @$stmt->execute();
            touch ("../data/ubound_needs_refresh.lck");
        }

        private function addCloneZone($zone, $target_zone) {
            $db = $this->getDb(); 
            $stmt = $db->prepare("insert into clone_zones (zone,target_zone) values (:zone,:target_zone)");
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':target_zone', $target_zone, SQLITE3_TEXT);
            @$stmt->execute();
            touch ("../data/ubound_needs_refresh.lck");
        }

        public function deleteLocalZoneMapping($id) {
            $db = $this->getDb();
            $stmt = $db->prepare("delete from local_zone_mapping where id=:id");
            $stmt->bindValue(':id',$id,SQLITE3_INTEGER);
            @$stmt->execute();
            touch ("../data/ubound_needs_refresh.lck");

        }

        private function addLocalZoneMapping($hostname,$ip,$zone,$api_added=0) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return "{$ip} is an invalid IP address";
            }

            $db = $this->getDb(); 
            if ($api_added) {
                $str = "";
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $str = " and ip like '%:%' ";
                } else if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $str = " and ip like '%.%' ";
                } 
                $stmt = $db->prepare("delete from local_zone_mapping where hostname=:hostname and zone=:zone {$str}");
                $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
                $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
                @$stmt->execute();
            }
            error_reporting(E_ALL);
            ini_set("display_errors", 1);
            $hostname = str_replace(".{$zone}","",$hostname);
            $stmt = $db->prepare("insert into local_zone_mapping (hostname,zone,ip,add_time,active,api_added) values (:hostname,:zone,:ip,:add_time,1,:api_added)");
            $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':add_time', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':zone', $zone, SQLITE3_TEXT);
            $stmt->bindValue(':api_added', $api_added, SQLITE3_INTEGER);
            $stmt->execute();
            touch ("../data/ubound_needs_refresh.lck");
            return null;
        }

        private function generateZoneFile() {
            $fp = fopen("../data/unbound.conf.d/custom_hosts.conf","w");
            fwrite($fp,"server:\n");
            foreach ($this->local_zones as $zone=>$mapping) {
                if ($mapping['active'] == 1) {
                    fwrite($fp,"\nlocal-zone: \"{$mapping['zone']}\" {$mapping['type']}\n");
                    if ($mapping['primary_zone'] == 1) {
                        fwrite($fp,"local-data-ptr: \"127.0.0.1 localhost\"\n");
                        fwrite($fp,"local-data: \"localhost A 127.0.0.1\"\n");
                        fwrite($fp,"local-data-ptr: \"::1 localhost\"\n");
                        fwrite($fp,"local-data: \"localhost AAAA ::1\"\n");
                    }


                    $all_zones = [$mapping['zone']];
                    if (isset($this->clone_zones[$mapping['zone']]) && count($this->clone_zones[$mapping['zone']])) {
                        $all_zones = array_merge($all_zones,$this->clone_zones[$mapping['zone']]);
                    }
                    

                    foreach ($all_zones as $processing_zone) {
                        //Start a new zone
                        if ($processing_zone != $mapping['zone']) {
                            fwrite($fp,"\nlocal-zone: \"{$processing_zone}\" {$mapping['type']}\n");
                        }
                        if (isset($this->local_zone_mappings[$mapping['zone']]) && count($this->local_zone_mappings[$mapping['zone']])) {
                                foreach ($this->local_zone_mappings[$mapping['zone']] as $lzone=>$lzone_mapping) {
                                    if ($lzone_mapping['active'] == 1) {
                                        $record_type = "A";
                                        if (strstr($lzone_mapping['ip'],":")) {
                                            $record_type = "AAAA";
                                        }
                                        fwrite($fp,"local-data: \"{$lzone_mapping['hostname']}.{$processing_zone} {$record_type} {$lzone_mapping['ip']}\"\n");
					if ($mapping['zone'] == $processing_zone && $mapping['primary_zone'] == 1) {
				            fwrite($fp,"local-data-ptr: \"{$lzone_mapping['ip']} {$lzone_mapping['hostname']}.{$processing_zone}\"\n");
					}
                                    }
                                }

                        }
                    }
                    /*
                    if (count($this->local_zone_mappings[$mapping['zone']])) {
                        foreach ($this->local_zone_mappings[$mapping['zone']] as $lzone=>$lzone_mapping) {
                            $host = $lzone_mapping['hostname'];
                            $current_zone = 
                            fwrite($ub_fp,"local-data: \"{$host}.{$zone} {$record_type} {$ip}\"\n");
                            fwrite($ub_fp,"local-data-ptr: \"{$ip} {$host}.{$zone}\"\n");
                        }
                    }
                     */
                }
            }
            fclose($fp);


        }

        private function applyChanges() {
            $this->load();
            $this->generateZoneFile();
            @unlink("../data/ubound_needs_refresh.lck");
            touch("../data/ubound_force_reload.lck");
        }

        public function doPost($post) {
                foreach ($post as $key=>$val) {
                    $post[$key] = $val;
                }
                if (!empty($post['new_local_zone']) && strlen($post['new_local_zone'])>0 && strlen($post['new_local_zone_type'])>0) {
                    $this->addLocalZone($post['new_local_zone'],$post['new_local_zone_type']);
                }
                if (!empty($post['clone_zone']) && strlen($post['target_zone'])>0) {
                    $this->addCloneZone($post['clone_zone'],$post['target_zone']);
                }
                if (!empty($post['custom_config']) && strlen($post['custom_config'])>0) {
                    $this->updateCustomConfig($post['custom_config']);
                }
                if (!empty($post['new_local_zone_mapping']) && strlen($post['new_local_zone_mapping'])>0 && strlen($post['new_local_zone_mapping_ip'])>0 && strlen($post['zone'])>0)  {
                    $api_added = isset($post['api_added']) ? 1:0;
                    $post['new_local_zone_mapping_ip'] = trim($post['new_local_zone_mapping_ip']);
                    $this->error = $this->addLocalZoneMapping($post['new_local_zone_mapping'],$post['new_local_zone_mapping_ip'],$post['zone'],$api_added);
                    if (isset($post['force_update']) && $post['force_update'] == 1) {
                        $this->applyChanges(); 
                    }
                }
                if (!empty($post['applyChanges'])) {
                    $this->applyChanges(); 
                }

        }

        public function __construct($postvars=null) {
            $this->load();
            if ($postvars != null) {
                $this->doPost($postvars);
            }
        }
        
    }

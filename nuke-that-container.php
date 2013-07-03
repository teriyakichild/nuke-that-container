#!/usr/bin/php
<?php
function usage(){
  print "CloudFiles Container Deletion Tool\n";
  print "Written By: Tony Rogers\n";
  print "Usage: php nuke-that-container.php -u USERNAME -k APIKEY -r US|UK\n";
}

$options = getopt("u:k:r:");
if (count($options) > 0) {
  if (@$options['u'] != '') {
    $user = $options['u'];
  }else{
    $user = readline("Username: ");
  }
  if (@$options['k'] != '') {
    $key = $options['k'];
  }else{
    $key = readline("API Key: ");
  }
  if (@$options['r'] != '') {
    $region = $options['r'];
  }else{
    $region = readline("Region(US or UK): ");
  }
  
  #Do Some Authentication:
  if ($region == 'US') {
    $url='https://identity.api.rackspacecloud.com/v2.0/tokens';
  } elseif ($region == 'UK') {
    $url='https://lon.identity.api.rackspacecloud.com/v2.0/tokens';
  } else {
    die('FATAL: Region must be set to US or UK'."\n");
  }
  $data = '{
    "auth": {
        "RAX-KSKEY:apiKeyCredentials": {
            "username": "'.$user.'",
            "apiKey": "'.$key.'"
        }
    }
  }';
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $ret = curl_exec($ch);
  $tmp = json_decode($ret);
  curl_close($ch);
  $token = $tmp->{'access'}->{'token'}->{'id'};
  #print_r($tmp);
  print 'INFO: Token received('.$token.')'."\n";
  
  #Use ServiceCatalog to get available endpoints:
  foreach($tmp->{'access'}->{'serviceCatalog'} as $k) {
    if ($k->{'name'} == 'cloudFiles') {
      foreach($k->{'endpoints'} as $b) {
        $endpoints[$b->{'region'}]=$b->{'publicURL'};
      }
    }
  }
  print 'Select Endpoint:'."\n";
  foreach($endpoints as $nm=>$ep) {
    $names[]=$nm;
    print "\t".'['.$nm.'] = '.$ep."\n";
  }
  $endpoint = readline("Endpoint (".implode("|",$names)."):");
  if (!in_array($endpoint,$names)) {
    die('FATAL: '.$endpoint." is not valid\n");
  }

  #Get Containers in that Region:
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $endpoints[$endpoint].'?format=json&limit=10000');
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Auth-Token: '.$token));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $ret = curl_exec($ch);
  curl_close($ch);
  $tmp=json_decode($ret);
  #print_r($tmp);
  print 'List of Containers:'."\n";
  foreach($tmp as $k) {
    $names[]=$k->{'name'};
    print "\t".$k->{'name'}."\n";
  }
  $container = readline("Container: ");
  if (!in_array($container,$names)) {
    die('FATAL: '.$container." is not valid\n");
  }

  #Get total number of files to be deleted
  $ch = curl_init();
  #Hack to work on containers with spaces.
  curl_setopt($ch, CURLOPT_URL, $endpoints[$endpoint].'/'.addslashes(str_replace(' ','%20',$container)).'?format=json');
  curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Auth-Token: '.$token));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $ret = curl_exec($ch);
  curl_close($ch);
  $tmp=json_decode($ret);
  foreach($tmp as $k) {
    $files[] = $k->{'name'};
    @$i++;
  }
  #die(print_r($files));
  if (@$i == '') { $i=0; }
  print 'INFO: Number of Files: '.$i."\n";
  $confirm = readline("Are you sure that you want to delete these files and containeri (yes|no)? ");
  if ($confirm == 'yes') {
    foreach($files as $k) {
      #Delete the files one at a time
      $ch = curl_init();
      #Had to add a hack to get files with spaces in the name deleted.
      curl_setopt($ch, CURLOPT_URL, $endpoints[$endpoint].'/'.addslashes(str_replace(' ','%20',$container)).'/'.addslashes(str_replace(' ','%20',$k)));
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Auth-Token: '.$token));
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      $ret = curl_exec($ch);
      $httpstatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($httpstatus == '204') {
        @$success++;
      } else {
        @$fail++;
      }
    }
    if (@$success == '') { $success = 0; }
    if (@$fail == '') { $fail = 0; }
    print "INFO: ".$success." files deleted successfully\n";
    print "INFO: ".$fail." files failed to delete\n"; 
    if ($fail == 0) {
      #Delete the container
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $endpoints[$endpoint].'/'.addslashes(str_replace(' ','%20',$container)));
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Auth-Token: '.$token));
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      $ret = curl_exec($ch);
      $httpstatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($httpstatus == '204') {
        print 'INFO: Container successfully removed'."\n";
      } else {
        die("FATAL: Container wasn't removed\n");
      }
    }
  }
}else{
  usage();
}

?>

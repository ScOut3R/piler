<?php

$webuidir = "";
$verbose = 0;

$archivesizeraw = $sqlsizeraw = $sphinxsizeraw = 0;
$averagemessagesweekraw = $averagemessagesmonthraw = $averagemessagesizeraw = $averagesizedayraw = $averagesqlsizeraw = $averagesphinxsizeraw = 0;

ini_set("session.save_path", "/tmp");
   
$_SERVER['HTTP_USER_AGENT'] = "daily/cron";

$opts = 'h::v';
$lopts = array(
    'webui:',
    'verbose'
    );
    
if ( $options = getopt( $opts, $lopts ) )
{
    if ( isset($options['webui']) ) 
    {
        $webuidir = $options['webui'];
    } else
    {
        echo "\nError: must provide path to WebUI directory\n\n";
    
        display_help();
        exit;
    }
    
    if ( isset($options['h']) ) 
    {
        display_help();
        exit;
    }
    if ( isset($options['verbose']) )
    {
        $verbose = 1;
    }
} else {
    display_help();
    exit;   
}


require_once($webuidir . "/config.php");

require(DIR_SYSTEM . "/startup.php");

$loader = new Loader();
Registry::set('load', $loader);

$loader->load->model('user/user');
$loader->load->model('health/health');
$loader->load->model('stat/counter');
$loader->load->model('mail/mail');

$language = new Language();
Registry::set('language', $language);

extract($language->data);

Registry::set('admin_user', 1);


if(MEMCACHED_ENABLED) {
   $memcache = new Memcache();
   foreach ($memcached_servers as $m){
      $memcache->addServer($m[0], $m[1]);
   }

   Registry::set('memcache', $memcache);
}


Registry::set('counters', $counters);

Registry::set('health_smtp_servers', $health_smtp_servers);
Registry::set('partitions_to_monitor', $partitions_to_monitor);


$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PREFIX);
Registry::set('DB_DATABASE', DB_DATABASE);

Registry::set('db', $db);

Registry::set('DB_DRIVER', DB_DRIVER);

$date = date(DATE_TEMPLATE, NOW);

$fp = fopen(LOCK_FILE, "r");
if(!$fp) { die("cannot open: " . LOCK_FILE . "\n"); }
if(!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); die("cannot get a lock on " . LOCK_FILE . "\n"); }


$health = new ModelHealthHealth();
$counter = new ModelStatCounter();
$mail = new ModelMailMail();


      foreach (Registry::get('health_smtp_servers') as $smtp) {
         $_health[] = $health->checksmtp($smtp, $text_error);
      }

      $processed_emails = $health->count_processed_emails();

      list ($uptime, $cpuload) = $health->uptime();

      $x = exec(CPU_USAGE_COMMAND);
      $cpuinfo = 100 - (int)$x;

      list($totalmem, $meminfo, $totalswap, $swapinfo) = $health->meminfo();
      $shortdiskinfo = $health->diskinfo();

      list($archivesizeraw, $archivesizestored, $counters) = $counter->get_counters();

      $archive_size = nice_size($archivesizeraw, ' ');

      $sysinfo = $health->sysinfo();

      $options = $health->get_options();

      $averagemessagesizeraw = $averagesqlsizeraw = $averagesphinxsizeraw = $daysleftatcurrentrate = 0;

	  /* these next counters are for projecting space */
	  $averagemessagesweekraw = 0;
	  $averagemessagesmonthraw = 0;

          if($counters['rcvd'] > 0) {
             $averagemessagesizeraw = $archivesizeraw / $counters['rcvd'];
             $averagesqlsizeraw = $sqlsizeraw / $counters['rcvd'];
             $averagesphinxsizeraw = $sphinxsizeraw / $counters['rcvd'];
          }

	  $averagesizedayraw = ($averagemessagesizeraw+$averagesqlsizeraw+$averagesphinxsizeraw) * $averagemessagesweekraw;
          $datapart = 0;
	  foreach($shortdiskinfo as $part) {
		if( $part['partition'] == DATA_PARTITION ) { $datapart = $part['freespace']*1024; }
	  }
	  
	  $averagemessages = round($averagemessagesweekraw);							// average of messages over the past week
	  $averagemessagesize = nice_size($averagemessagesizeraw,' ');				// average message size on disk
	  $averagesqlsize = nice_size($averagesqlsizeraw,' ');						// average metadata size in sql
	  $averagesphinxsize = nice_size($averagesphinxsizeraw,' ');					// average sphinx index
	  $averagesizeday = nice_size($averagesizedayraw,' ');						// average size per day

	  if($averagesizedayraw > 0) {
             $daysleftatcurrentrate = convert_days_ymd($datapart / $averagesizedayraw);	// number of days of free space left
          }

	  if ( $averagemessagesweekraw > $averagemessagesmonthraw ) {
		$usagetrend = 1;
	  } elseif( $averagemessagesweekraw < $averagemessagesmonthraw ) {
	    $usagetrend = -1;
	  } else {
		$usagetrend = 0;
	  }
	  
	  
	  /* start email message */
	  
      $msg = "From: " . SMTP_FROMADDR . EOL;
      $msg .= "To: " . ADMIN_EMAIL . EOL;
      $msg .= "Subject: =?UTF-8?Q?" . preg_replace("/\n/", "", my_qp_encode($text_daily_piler_report)) . "?=" . EOL;
      $msg .= "MIME-Version: 1.0" . EOL;
      $msg .= "Content-Type: text/html; charset=\"utf-8\"" . EOL;
      $msg .= EOL . EOL;

      ob_start();
	  
      include($webuidir . "/view/theme/default/templates/health/daily-report.tpl");

      $msg .= ob_get_contents();

      ob_end_clean();

      $rcpt = array(ADMIN_EMAIL);

      if(SMARTHOST) {
         $x = $mail->send_smtp_email(SMARTHOST, SMARTHOST_PORT, SMTP_DOMAIN, SMTP_FROMADDR, $rcpt, $msg);
      }


if($fp) {
   flock($fp, LOCK_UN);
   fclose($fp);
}


function display_help() {
    $phpself = basename(__FILE__);
    echo "\nUsage: $phpself --webui [PATH] [OPTIONS...]\n\n";
    echo "\t--webui=\"[REQUIRED: path to the Piler WebUI Directory]\"\n\n";
    echo "options:\n";
    echo "\t-v Provide a verbose output\n";
    echo "\t-h Prints this help screen and exits\n";
}

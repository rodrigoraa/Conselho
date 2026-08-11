<?php declare(strict_types=1);

$root=dirname(__DIR__);
$phpUnit=$root.'/vendor/bin/phpunit';
if(!is_file($phpUnit)){fwrite(STDERR,"PHPUnit não está instalado. Execute composer install.\n");exit(1);}
$command=[PHP_BINARY];
if(!extension_loaded('fileinfo')){$command[]='-d';$command[]='extension=fileinfo';}
$command[]=$phpUnit;
$process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$root,null,['bypass_shell'=>true]);
if(!is_resource($process)){fwrite(STDERR,"Não foi possível iniciar o PHPUnit.\n");exit(1);}
exit(proc_close($process));

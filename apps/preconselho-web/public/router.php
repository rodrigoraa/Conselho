<?php declare(strict_types=1);

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$relative=ltrim(str_replace(['/', '\\'],DIRECTORY_SEPARATOR,rawurldecode($path)),DIRECTORY_SEPARATOR);
$asset=__DIR__.DIRECTORY_SEPARATOR.$relative;
if($path!=='/'&&is_file($asset))return false;
require __DIR__.'/index.php';

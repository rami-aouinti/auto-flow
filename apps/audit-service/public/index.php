<?php
declare(strict_types=1);
use App\Kernel;
use Symfony\Component\HttpFoundation\Request;
require dirname(__DIR__).'/vendor/autoload.php';
$kernel=new Kernel($_SERVER['APP_ENV']??'prod',filter_var($_SERVER['APP_DEBUG']??false,FILTER_VALIDATE_BOOL));
$request=Request::createFromGlobals();
$response=$kernel->handle($request);
$response->send();
$kernel->terminate($request,$response);

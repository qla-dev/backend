<?php
declare(strict_types=1);
$body=(string)file_get_contents('php://input');
if(strlen($body)>4096){http_response_code(413);exit;}
require_once dirname(__DIR__).'/deployment/authorize.php';
freightbookAuthorizeDeployment('demo-vehicles:'.hash('sha256',$body));
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
 $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);$service=app(\App\Services\DemoFleetVehicles::class);
 if(($data['operation']??'')==='inspect')$result=$service->inspect();
 elseif(($data['operation']??'')==='create')$result=$service->create((string)($data['fingerprint']??''));
 else throw new \InvalidArgumentException('Unknown operation.');
 echo json_encode(['data'=>$result],JSON_THROW_ON_ERROR);
}catch(\Throwable $e){$status=$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$e->getStatusCode():($e instanceof \Illuminate\Validation\ValidationException?422:500);http_response_code($status);echo json_encode(['error'=>$status<500?$e->getMessage():'Demo vehicle batch failed; no partial batch committed.']);}

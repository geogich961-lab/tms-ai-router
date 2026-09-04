<?php
declare(strict_types=1);
foreach(['app/Core/App.php','app/Core/Auth.php','app/Core/Crypto.php','app/Services/ProviderService.php','app/Services/UsageService.php','app/Services/ClientKeyService.php','app/Services/GatewayService.php'] as$f)require dirname(__DIR__).'/'.$f;
use TmsAi\Core\App;use TmsAi\Core\Auth;use TmsAi\Services\ProviderService;use TmsAi\Services\UsageService;use TmsAi\Services\ClientKeyService;use TmsAi\Services\GatewayService;
try{App::boot();$providers=new ProviderService();$usage=new UsageService();$keys=new ClientKeyService();$gateway=new GatewayService($providers,$usage);$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');$path=App::routePath();
if(!Auth::hasAdmin()&&!in_array($path,['/setup','/health'],true)){header('Location: /setup');exit;}
if($path==='/health')App::json(['ok'=>true,'name'=>App::config('name'),'version'=>App::config('version'),'time'=>time()]);
if($path==='/setup'){if(Auth::hasAdmin()){header('Location: /');exit;}$error='';if($method==='POST'){App::verifyCsrf();if(Auth::setup((string)($_POST['username']??''),(string)($_POST['password']??''))){Auth::login((string)$_POST['username'],(string)$_POST['password']);header('Location: /');exit;}$error='Username tối thiểu 3 ký tự, password tối thiểu 8 ký tự.';}$csrf=App::csrf();require dirname(__DIR__).'/views/setup.php';exit;}
if($path==='/login'){if(Auth::loggedIn()){header('Location: /');exit;}$error='';if($method==='POST'){App::verifyCsrf();if(Auth::login((string)($_POST['username']??''),(string)($_POST['password']??''))){header('Location: /');exit;}$error='Sai username hoặc password.';}$csrf=App::csrf();require dirname(__DIR__).'/views/login.php';exit;}
if($path==='/logout'&&$method==='POST'){Auth::logout();header('Location: /login');exit;}
if($path==='/v1/models'&&$method==='GET'){Auth::requireClientKey();App::json(['object'=>'list','data'=>$providers->publicModels()]);}
if($path==='/v1/chat/completions'&&$method==='POST'){$client=Auth::requireClientKey();$r=$gateway->chat(App::inputJson(),$client);App::json($r['body'],$r['status']);}
Auth::requireAdmin();
if($path==='/admin/api/status')App::json($usage->summary());
if($path==='/admin/api/provider'&&$method==='GET'){$r=$providers->find((int)($_GET['id']??0));if(!$r)App::json(['error'=>'Provider không tồn tại.'],404);$r['api_key']=$r['api_key']!==''?'••••••••':'';App::json($r);}
if($path==='/admin/api/provider/save'&&$method==='POST'){App::verifyCsrf();try{App::json(['ok'=>true,'id'=>$providers->save(App::inputJson())]);}catch(Throwable$e){App::json(['ok'=>false,'error'=>$e->getMessage()],422);}}
if($path==='/admin/api/provider/delete'&&$method==='POST'){App::verifyCsrf();$d=App::inputJson();$providers->delete((int)($d['id']??0));App::json(['ok'=>true]);}
if($path==='/admin/api/key/create'&&$method==='POST'){App::verifyCsrf();$d=App::inputJson();App::json(['ok'=>true,'key'=>$keys->create((string)($d['name']??'Default'))]);}
if($path==='/admin/api/key/revoke'&&$method==='POST'){App::verifyCsrf();$d=App::inputJson();$keys->revoke((int)($d['id']??0));App::json(['ok'=>true]);}
if($path==='/admin/api/settings'&&$method==='POST'){App::verifyCsrf();$d=App::inputJson();$v=(string)($d['routing_strategy']??'priority');if(!in_array($v,['priority','round_robin','least_used','quota_first'],true))$v='priority';$s=App::db()->prepare("INSERT INTO settings(key,value) VALUES('routing_strategy',:v) ON CONFLICT(key) DO UPDATE SET value=excluded.value");$s->bindValue(':v',$v,SQLITE3_TEXT);$s->execute();App::json(['ok'=>true]);}
if($path==='/'){$csrf=App::csrf();$summary=$usage->summary();$providerList=$providers->all();$keyList=$keys->all();require dirname(__DIR__).'/views/dashboard.php';exit;}App::json(['error'=>['message'=>'Route not found','type'=>'not_found']],404);
}catch(Throwable$e){App::json(['error'=>['message'=>$e->getMessage(),'type'=>'server_error']],500);}

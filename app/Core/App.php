<?php
declare(strict_types=1);
namespace TmsAi\Core;
use SQLite3; use RuntimeException;
final class App { private static array $config=[]; private static ?SQLite3 $db=null;
public static function boot():void { self::$config=require dirname(__DIR__,2).'/config/app.php'; date_default_timezone_set((string)self::config('timezone','Asia/Ho_Chi_Minh')); if(PHP_VERSION_ID<80000)throw new RuntimeException('TMS AI Router yêu cầu PHP 8.0+'); if(!class_exists(SQLite3::class))throw new RuntimeException('PHP extension SQLite3 chưa được cài.'); foreach(['storage','storage/secure','storage/logs','storage/cache'] as $dir){$p=dirname(__DIR__,2).'/'.$dir;if(!is_dir($p)&&!mkdir($p,0700,true)&&!is_dir($p))throw new RuntimeException('Không thể tạo thư mục: '.$p);} if(session_status()!==PHP_SESSION_ACTIVE){session_name((string)self::config('session_name','TMSAIRSESSID'));session_set_cookie_params(['httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'samesite'=>'Lax','path'=>'/']);session_start();} self::migrate(); }
public static function config(string $key,mixed $default=null):mixed{return self::$config[$key]??$default;}
public static function db():SQLite3 {if(self::$db)return self::$db;$db=new SQLite3((string)self::config('db_path'));$db->busyTimeout(5000);$db->exec('PRAGMA journal_mode=WAL');$db->exec('PRAGMA synchronous=NORMAL');$db->exec('PRAGMA foreign_keys=ON');return self::$db=$db;}
private static function migrate():void{$sql=file_get_contents(dirname(__DIR__,2).'/database/schema.sql');if($sql===false||!self::db()->exec($sql))throw new RuntimeException('Không thể khởi tạo database: '.self::db()->lastErrorMsg());}
public static function json(mixed $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
public static function inputJson():array{$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>(int)self::config('max_body_bytes'))self::json(['error'=>['message'=>'Request body quá lớn.','type'=>'invalid_request_error']],413);$raw=file_get_contents('php://input');$data=json_decode($raw?:'{}',true);if(!is_array($data))self::json(['error'=>['message'=>'JSON không hợp lệ.','type'=>'invalid_request_error']],400);return $data;}
public static function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return $_SESSION['csrf'];}
public static function verifyCsrf():void{$token=$_SERVER['HTTP_X_CSRF_TOKEN']??($_POST['_csrf']??'');if(!hash_equals(self::csrf(),(string)$token))self::json(['error'=>'CSRF token không hợp lệ.'],419);}
public static function routePath():string{$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';return '/'.ltrim($path,'/');}
}

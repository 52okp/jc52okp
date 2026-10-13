<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use app\service\AdminSecurity;
use app\service\WechatSession;
use app\service\AvatarImage;
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$options = WechatSession::options();
verify($options[CURLOPT_SSL_VERIFYPEER] && $options[CURLOPT_SSL_VERIFYHOST] === 2 && !$options[CURLOPT_FOLLOWLOCATION], 'TLS validation missing');
verify(AdminSecurity::sameOrigin('https://example.test', 'https://example.test:443'), 'equivalent origin rejected');
foreach (['http://example.test','https://example.test:444','https://evil.example.test','null'] as $origin) verify(!AdminSecurity::sameOrigin($origin, 'https://example.test'), 'unsafe origin accepted');
$user = ['id'=>1,'username'=>'admin','password'=>'hash','status'=>1,'is_deleted'=>0];
$stamp = AdminSecurity::stamp($user);
verify(AdminSecurity::validUser($user,$stamp), 'current session rejected');
foreach (['password'=>'new','status'=>0,'is_deleted'=>1,'username'=>'renamed'] as $key=>$value) verify(!AdminSecurity::validUser(array_replace($user,[$key=>$value]),$stamp),'revoked session accepted');
verify(!AdminSecurity::validUser($user,''),'legacy unstamped session accepted');
foreach ([['api.System','debug'],['Login','out'],['Oplog','clear'],['Article','state']] as [$controller,$action]) verify(AdminSecurity::requiresPost($controller,$action),'GET mutation remains');
verify(!AdminSecurity::requiresPost('Article','index'),'read endpoint blocked');
$marker = '</script><p id="marker">x</p>';
$json = json_encode([$marker], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
verify(!str_contains($json,'</script>') && json_decode($json,true)[0] === $marker, 'JSON context encoding broken');
try { AvatarImage::encode('not an image'); throw new RuntimeException('fake image accepted'); }
catch (RuntimeException $e) { verify($e->getMessage() !== 'fake image accepted', $e->getMessage()); }
$source = imagecreatetruecolor(1000,500); ob_start(); imagepng($source); $bytes=ob_get_clean(); imagedestroy($source);
$png = AvatarImage::encode($bytes . '<?php harmless marker ?>');
$info=getimagesizefromstring($png);
verify($info[0]===512 && $info[1]===256 && $info[2]===IMAGETYPE_PNG && !str_contains($png,'harmless marker'),'image re-encoding failed');
$app=\think\admin\service\RuntimeService::init();$app->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
verify($app->make(\think\admin\support\middleware\RbacAccess::class) instanceof \app\middleware\AdminAccess,'security middleware binding missing');
$index=(new ReflectionClass(\app\admin\controller\Index::class))->newInstanceWithoutConstructor();
$index->request=new class { public function isPost(){return true;} };
$data=['id'=>1,'nickname'=>'valid','password'=>'hash','status'=>1,'is_deleted'=>0,'usertype'=>'admin','authorize'=>[1]];
$method=new ReflectionMethod($index,'_info_form_filter');$method->setAccessible(true);$method->invokeArgs($index,[&$data]);
verify($data===['id'=>1,'nickname'=>'valid'],'profile mass assignment remains');

$app->http->name('admin');
$app->env->set('ADMIN_ORIGIN', 'https://example.test');
$app->config->set(['rbac_ignore'=>['admin']], 'app');
$guard = new \app\middleware\AdminAccess($app);
$makeRequest = static function(string $controller, string $action, string $method): \think\Request {
    $request = new \think\Request();
    $request->setController($controller); $request->setAction($action);
    return $request->withServer(['REQUEST_METHOD'=>$method, 'HTTP_HOST'=>'example.test', 'HTTPS'=>'on']);
};
$next = static function($request) { return json(['ok'=>true]); };
verify($guard->handle($makeRequest('api.System','debug','GET'),$next)->getCode()===405, 'GET mutation reached controller');
verify($guard->handle($makeRequest('Login','index','POST'),$next)->getCode()===403, 'missing CSRF reached controller');
$request=$makeRequest('Login','index','POST')->withHeader(['x-admin-csrf'=>AdminSecurity::csrf(),'origin'=>'https://evil.example.test']);
verify($guard->handle($request,$next)->getCode()===403, 'foreign origin reached controller');
$request=$makeRequest('Login','index','POST')->withHeader(['x-admin-csrf'=>AdminSecurity::csrf(),'origin'=>'https://example.test']);
verify($guard->handle($request,$next)->getCode()===200, 'valid CSRF request rejected');
try {
    $guard->handle($request,static function($request){throw new \think\exception\HttpResponseException(json(['ok'=>true]));});
    throw new RuntimeException('response exception swallowed');
} catch (\think\exception\HttpResponseException $e) {
    verify($e->getResponse()->getHeader('Cache-Control')==='no-store', 'exception response remains cacheable');
    verify($e->getResponse()->getHeader('X-Content-Type-Options')==='nosniff', 'exception response lacks nosniff');
}

echo "Audit security checks passed\n";

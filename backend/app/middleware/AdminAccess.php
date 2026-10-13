<?php

declare(strict_types=1);
namespace app\middleware;

use app\service\AdminSecurity;
use think\admin\model\SystemUser;
use think\admin\service\AdminService;
use think\admin\support\middleware\RbacAccess;
use think\Request;
use think\Response;

/** Runs at route middleware time, after session initialization and before RBAC. */
class AdminAccess extends RbacAccess
{
    public function handle(Request $request, \Closure $next): Response
    {
        if ($this->app->http->getName() !== 'admin') return parent::handle($request, $next);
        if ($id = AdminService::getUserId()) {
            $user = SystemUser::mk()->where('id', $id)->findOrEmpty()->toArray();
            if (!AdminSecurity::validUser($user, (string)$this->app->session->get('admin_auth', ''))) {
                $this->app->session->delete('user');
                $this->app->session->delete('admin_auth');
                return json(['code' => 0, 'info' => '登录状态已失效，请重新登录', 'url' => sysuri('admin/login/index')], 401);
            }
            // Reload role nodes before the framework checks this request's authorization.
            AdminService::apply();
        }
        if (AdminSecurity::requiresPost($request->controller(), $request->action()) && !$request->isPost()) {
            return json(['code' => 0, 'info' => '此操作只接受 POST 请求'], 405)->header(['Allow' => 'POST']);
        }
        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $capability = strtolower($request->controller()) === 'api.upload' && !AdminService::isLogin() &&
                !empty(AdminService::withUploadUnid()[0]);
            if (!$capability) {
                $origin = (string)$request->header('origin', '');
                $expected = (string)env('ADMIN_ORIGIN', $request->domain());
                $token = (string)$request->header('X-Admin-CSRF', $request->post('_admin_csrf', ''));
                if (($origin !== '' && !AdminSecurity::sameOrigin($origin, $expected)) ||
                    $token === '' || !hash_equals(AdminSecurity::csrf(), $token)) {
                    return json(['code' => 0, 'info' => '请求校验失败，请刷新页面后重试'], 403);
                }
            }
        }
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin'];
        try { return parent::handle($request, $next)->header($headers); }
        catch (\think\exception\HttpResponseException $e) {
            // ThinkAdmin returns views and JSON through this exception.
            $e->getResponse()->header($headers);
            throw $e;
        }
    }
}

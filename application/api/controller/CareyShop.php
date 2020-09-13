<?php
/**
 * @copyright   Copyright (c) http://careyshop.cn All rights reserved.
 *
 * CareyShop    Api基类控制器
 *
 * @author      zxm <252404501@qq.com>
 * @date        2017/3/22
 */

namespace app\api\controller;

use app\api\exception\ApiOutput;
use app\common\service\Auth;
use think\Controller;
use think\Cache;
use think\Db;
use think\Config;
use think\helper\Str;

class CareyShop extends Controller
{
    /**
     * 控制器错误信息
     * @var string
     */
    public $error;

    /**
     * AppKey
     * @var string
     */
    public $appkey;

    /**
     * AppSecret
     * @var string
     */
    public $appSecret;

    /**
     * Token
     * @var string
     */
    public $token;

    /**
     * Sign
     * @var string
     */
    public $sign;

    /**
     * 时间戳
     * @var int
     */
    public $timestamp;

    /**
     * 返回格式
     * @var string
     */
    public $format;

    /**
     * 业务方法
     * @var string
     */
    public $method;

    /**
     * 业务参数
     * @var array
     */
    public $params = [];

    /**
     * 方法路由器
     * @var array
     */
    protected static $route;

    /**
     * 对应模型
     * @var object
     */
    protected static $model;

    /**
     * 权限验证实例
     * @var object
     */
    protected static $auth;

    /**
     * 是否调试模式
     * @var bool
     */
    public $apiDebug = false;

    /**
     * 核心基础初始化
     * @access protected
     * @return void
     */
    protected function _initialize()
    {
        // 验证请求方式
        if (!in_array($this->request->method(), ['GET', 'POST', 'OPTIONS'])) {
            $this->outputError('不支持的请求方式');
        }

        // 获取系统配置参数
        $setting = Cache::remember('setting', function () {
            return Db::name('setting')->field('setting_id', true)->select();
        });

        if (false === $setting) {
            Cache::rm('setting');
            $this->outputError('系统配置初始化失败');
        }

        foreach ($setting as $value) {
            Config::set($value['code'], $value, $value['module']);
        }

        // 跨域 OPTIONS 请求友好返回
        if ($this->request->isOptions()) {
            $this->outputError('success', 200);
        }

        // 检测是否开启API接口
        if (Config::get('open_api.value', 'system_info') != 1) {
            $this->outputError(Config::get('close_reason.value', 'system_info'));
        }

        // API_DEBUG模式是否运行
        $this->apiDebug = Config::has('api_debug') ? Config::get('api_debug') : false;

        // 支持"text/plain"协议(仅限JSON)
        if (false !== strpos($this->request->contentType(), 'text/plain')) {
            $plain = json_decode($this->request->getInput(), true);
            $this->request->post($plain);
        }

        // 获取外部参数
        $this->params = $this->request->param();
        unset($this->params['version']);
        unset($this->params['controller']);
        unset($this->params[str_replace('.', '_', $_SERVER['PATH_INFO'])]);
        unset($this->params[$_SERVER['PATH_INFO']]);

        // 公共参数赋值
        $this->appkey = isset($this->params['appkey']) ? $this->params['appkey'] : '';
        $this->token = isset($this->params['token']) ? $this->params['token'] : '';
        $this->sign = isset($this->params['sign']) ? $this->params['sign'] : '';
        $this->timestamp = isset($this->params['timestamp']) ? $this->params['timestamp'] : 0;
        $this->format = !empty($this->params['format']) ? $this->params['format'] : Config::get('default_return_type');
        $this->method = isset($this->params['method']) ? $this->params['method'] : '';
        ApiOutput::$format = $this->format;

        // 验证Params
        $validate = $this->apiDebug ?: $this->validate($this->params, 'CareyShop');
        if (true !== $validate) {
            $this->outputError($validate);
        }

        // 验证Token
        $token = $this->checkToken();
        if (true !== $token) {
            // 未授权，请重新登录(401)
            $this->outputError($token, 401);
        }

        // 验证Auth
        $auth = $this->checkAuth();
        if (true !== $auth) {
            // 拒绝访问(403)
            $this->outputError($auth, 403);
        }

        // 验证APP
        $app = $this->checkApp();
        if (true !== $app) {
            $this->outputError($app);
        }

        // 验证Sign
        $sign = $this->apiDebug ?: $this->checkSign();
        if (true !== $sign) {
            $this->outputError($sign);
        }

        static::init();
    }

    /**
     * 自定义初始化
     * @access protected
     * @return void
     */
    protected static function init()
    {
    }

    /*
     * 方法路由器
     * @access protected
     * @return array
     */
    protected static function initMethod()
    {
        return [];
    }

    /**
     * api_debug模式下,尝试根据method命名规范查找模型方法(方便调试)
     * @access private
     * @return void
     */
    private function autoFindMethod()
    {
        if (true !== Config::get('api_debug')) {
            return;
        }

        foreach (self::$route as $value) {
            if (in_array($this->method, $value)) {
                self::$route = [$value[0] => [$value[0]]];
                !isset($value[1]) ?: self::$route[$value[0]][] = $value[1];
                return;
            }
        }

        $this->outputError(__FUNCTION__ . '模式,尝试查找的模型不存在');
    }

    /**
     * 核心基础首页
     * @access public
     * @return array
     */
    public function index()
    {
        // 获取控制器方法路由
        if (!isset(self::$route)) {
            self::$route = static::initMethod();
        }

        if (!array_key_exists($this->method, self::$route)) {
            $this->autoFindMethod();
        }

        // 删除多余数据,避免影响其他模块,并获取路由参数
        unset($this->params['appkey']);
        unset($this->params['token']);
        unset($this->params['timestamp']);
        unset($this->params['format']);
        unset($this->params['method']);

        // 调用自身成员函数或类成员方法
        $result = null;
        $callback = self::$route[$this->method];

        // 路由定义中如果数组[1]不存在,则表示默认对应model模型
        if (!isset($callback[1])) {
            $className = ucwords(str_replace(['-', '_'], ' ', $this->request->param('controller')));
            $className = str_replace(' ', '', $className);
            $callback[1] = 'app\\common\\model\\' . $className;
        }

        if (class_exists($callback[1])) {
            isset(static::$model) ?: static::$model = new $callback[1];
        } else if (false !== $callback[1]) {
            $this->outputError('模型类不存在');
        }

        try {
            if (method_exists($this, $callback[0])) {
                $result = call_user_func_array([$this, $callback[0]], []);
            } else if (method_exists(static::$model, $callback[0])) {
                $result = call_user_func([static::$model, $callback[0]], $this->getParams());
            } else {
                $this->outputError('method成员方法不存在');
            }
        } catch (\Exception $e) {
            $result = false;
            $this->error = $e->getMessage();
        }

        // 记录日志
        if (!is_null(self::$auth)) {
            $logError = empty($this->error) && isset(static::$model) ? static::$model->getError() : $this->error;
            self::$auth->saveLog($this->getAuthUrl(), $this->request, $result, get_called_class(), $logError);
        }

        // 输出结果
        if (false === $result && !isset($result['callback_return_type'])) {
            !empty($this->error) || !is_object(static::$model) ?: $this->error = static::$model->getError();
            $this->outputError($this->error);
        }

        return $this->outputResult($result);
    }

    /*
     * 设置控制器错误信息
     * @access public
     * @param  string $value 错误信息
     * @return false
     */
    public function setError($value)
    {
        $this->error = $value;
        return false;
    }

    /*
     * 获取控制器错误信息
     * @access public
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 只验证Token是否合法,否则一律按游客处理
     * @access private
     * @return string|true
     */
    private function checkToken()
    {
        // 初始账号数据
        $GLOBALS['client'] = [
            'type'        => config('ClientGroup.' . ($this->apiDebug ? 'admin' : 'visitor'))['value'],
            'group_id'    => $this->apiDebug ? AUTH_SUPER_ADMINISTRATOR : AUTH_GUEST,
            'client_id'   => $this->apiDebug ? 1 : 0,
            'shop_id'   => $this->apiDebug ? 1 : 0,
            'client_name' => $this->apiDebug ? 'CareyShop' : '游客',
            'token'       => $this->token,
        ];

        // Token为空则表示以游客身份访问
        if (empty($this->token) || $this->apiDebug) {
            return true;
        }

        // 从本地数据库获取Token
        $data = Cache::remember('token:' . $this->token, function () {
            return Db::name('token')->where(['token' => ['eq', $this->token]])->find();
        });

        // 存在Token则进行验证
        if (!is_null($data)) {
            // 必须先检测Token是否过期,不然下面的检测没意义
            if (empty($data['token_expires']) || time() > $data['token_expires']) {
                return 'token已过期';
            }

            // 还原Token加密过程
            $token = user_md5(sprintf('%d%d%s', $data['client_id'], $data['client_type'], $data['code']));

            // 取错的情况下第2个比较逻辑成立,否则为非法Token
            if (!hash_equals($token, $this->token) || $token != $data['token']) {
                return 'token错误';
            }

            // 设置全局变量并设置账号缓存标签
            $GLOBALS['client'] = [
                'type'        => $data['client_type'],
                'group_id'    => $data['group_id'],
                'client_id'   => $data['client_id'],
                'shop_id'     => $data['shop_id'],
                'client_name' => $data['username'],
                'token'       => $this->token,
            ];

            $cacheTag = 'token:' . (is_client_admin() ? 'admin_' : 'user_') . get_client_id();
            Cache::tag($cacheTag, ['token:' . $this->token]);
        } else if (!empty($this->token)) {
            // 不以白名单方式访问一律按Token未授权处理
            return '未授权或授权已过期';
        }

        return true;
    }

    /**
     * 验证Auth
     * @access private
     * @return string|true
     */
    private function checkAuth()
    {
        // 初始化规则模块
        if (is_null(self::$auth)) {
            $authCache = $this->request->module() . get_client_group();
            self::$auth = Cache::remember($authCache, function () {
                return new Auth($this->request->module(), get_client_group());
            });

            Cache::tag('CommonAuth', $authCache);
        }

        // 批量API调用或调试模式不需要权限验证
        if ($this->apiDebug || $this->request->controller() == 'Batch') {
            return true;
        }

        // 优先验证是否属于白名单接口(任何访问者都可访问)
        if (self::$auth->checkWhite($this->getAuthUrl())) {
            $this->apiDebug = true;
            return true;
        }

        // 再验证是否有权限
        if (self::$auth->check($this->getAuthUrl())) {
            return true;
        }

        return '权限不足';
    }

    /**
     * 验证APP状态
     * @access private
     * @return bool|string
     */
    private function checkApp()
    {
        // 白名单中排除的接口
        $exclude = [
            'login.admin.user',
            'login.user.user',
        ];

        if (!$this->apiDebug || in_array($this->method, $exclude)) {
            $appMap = ['app_key' => $this->appkey, 'status' => 1, 'is_delete' => 0];
            $appSecret = Db::name('app')->cache(true, null, 'app')->where($appMap)->value('app_secret');

            if ($appSecret) {
                $this->appSecret = $appSecret;
                return true;
            } else {
                return 'appkey已禁用或不存在';
            }
        }

        return true;
    }

    /*
     * 验证Sign是否合法
     * @access private
     * @return string|true
     */
    private function checkSign()
    {
        unset($this->params['sign']);
        $params = $this->params;
        ksort($params);

        $type = ['array', 'object', 'NULL'];
        $stringToBeSigned = $this->appSecret;
        foreach ($params as $key => $val) {
            if ($key != '' && !in_array(gettype($val), $type)) {
                $stringToBeSigned .= $key.$val;
            }
        }
        unset($key, $val);
        $stringToBeSigned .= $this->appSecret;

        if (!hash_equals(md5($stringToBeSigned), $this->sign)) {
            return 'sign错误';
        }

        return true;
    }

    /**
     * 输出请求结果
     * @access protected
     * @param  array $data 业务结果
     * @param  int   $code HTTP状态码
     * @return array
     */
    protected function outputResult($data = [], $code = 200)
    {
        return ApiOutput::outPut($data, $code);
    }

    /**
     * 输出错误结果
     * @access protected
     * @param  string $message 错误消息
     * @param  int    $code    错误编码
     * @return void
     */
    protected function outputError($message = '', $code = 500)
    {
        abort($code, $message);
    }

    /**
     * 获取公共参数
     * @access protected
     * @param  int/string $key 键值
     * @return mixed
     */
    protected function getParams($key = null)
    {
        return is_null($key) ? $this->params : $this->params[$key];
    }

    /**
     * 删除指定的公共参数
     * @access protected
     * @param  string/array $key 键值
     * @return mixed
     */
    protected function unParams($key)
    {
        if (isset($key)) {
            if (is_string($key)) {
                unset($this->params[$key]);
            } else if (is_array($key)) {
                foreach ($key as $val) {
                    unset($this->params[$val]);
                }
            }
        }

        return $this;
    }

    /**
     * 检测指定参数是否存在
     * @access protected
     * @param  string $key 键值
     * @return bool
     */
    protected function hasParams($key)
    {
        return isset($this->params[$key]);
    }

    /**
     * 返回权限验证需要的URL规则
     * @access private
     * @return string
     */
    private function getAuthUrl()
    {
        $module = $this->request->module();
        $version = $this->request->param('version');
        $controller = Str::snake($this->request->param('controller'));
        $method = $this->method;

        $url = sprintf('%s/%s/%s/%s', $module, $version, $controller, $method);
        return $url;
    }
}

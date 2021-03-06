<?php
/*
|--------------------------------------------------------------------------
| Creator: SuYang
| Date: 2018/3/28 0028 17:24
|--------------------------------------------------------------------------
|                                             JWT认证服务
|--------------------------------------------------------------------------
|checkAuth 负责认证，会根据配置need_nonce_str自动选择是否刷新。可
|以传入一个scene来设定应用场景
|
|generateToken 负责生成token，第一个参数是数据对象（不能是数组），
|第二个参数是唯一标识用户的“键”，默认是id
|
|getReturnToken返回将要返回的token值。如果是刷新操作就返回新生成
|的token，反之直接将请求token返回
|--------------------------------------------------------------------------
*/

namespace app\common\service;

use app\common\model\CommonModel;
use app\lib\enum\Status;
use app\lib\exception\CacheException;
use app\lib\exception\JWTException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Request;

class JWTAuth
{
    //用户有没有通过认证
    protected static $passAuth = false;
    //从前端带过来的token
    protected static $requestToken;
    //返回给前端的token
    protected static $returnToken;
    //存储已经认证过的用户信息
    protected static $account;
    //应用场景
    protected static $scene;

    /**
     * 对外认证的接口
     */
    public static function handle($scene = '')
    {
        //验证场景
        if( !empty($scene)) {
            self::setScene($scene);
        }

        //如果有随机串就说明是刷新的反之仅仅需要认证
        if(self::needNonce()) {
            $token = self::refresh();
        }
        else {
            $token = self::authenticate();
        }

        //返回给前端的token
        self::$returnToken = $token;
    }

    /**
     * 选择应用场景
     */
    public static function setScene($scene = '')
    {
        if(empty($scene)){
            throw new JWTException(12010);
        }
        self::$scene = $scene;
    }

    /**
     * 解析并验证账号信息
     */
    protected static function resolveAccount($payload)
    {
        if( !key_exists('uid', $payload) || !isPositiveInteger($payload['uid'])) {
            throw new JWTException(12007);
        }

        //获取account的方法需要具体的实现
        $class = self::getModel();
        $model = new $class;
        $account = $model->get((int)$payload['uid']);

        if(is_null($account)) {
            throw new JWTException(12011);
        }

        if($account->status != Status::NORMAL){
            throw new JWTException(12013);
        }

        self::$account = $account;
    }

    /**
     * @return mixed
     * 获取前端传过来的token
     */
    protected static function getTokenFromRequest()
    {
        if(Request::has('token')) {
            return Request::param('token');
        }

        return Request::header('token');
    }

    /**
     * 根据用户的model或数组生成token
     */
    public static function generateToken($account, $scene = '')
    {
        //验证场景
        if( !empty($scene)) {
            self::setScene($scene);
        }

        $payload = self::createPayload($account);

        self::$returnToken = JWT::encode($payload, self::getSecretKey());

        return self::$returnToken;
    }

    /**
     * 根据用户的model或数组生成payload
     */
    protected static function createPayload($account)
    {
        if($account instanceof CommonModel){
            $account = $account->toArray();
        }

        if( !is_array($account)) {
            throw new JWTException(12012);
        }

        if( !key_exists('id', $account)) {
            throw new JWTException(12008);
        }
        $payload['uid'] = $account['id'];
        // 过期时间 = 当前请求时间 + token过期时间
        $payload['exp'] = Request::time() + self::getExpireIn();
        if (self::needNonce()) {
            // 需要随机字符串
            $payload['nonce'] = self::createNonce();
        }

        return $payload;
    }

    /**
     * 验证token
     */
    protected static function verifyToken()
    {
        if (empty(self::$requestToken = self::getTokenFromRequest()))
                throw new JWTException(12001);

        try {
            $payLoad = (array)JWT::decode(self::$requestToken, self::getSecretKey(), ['HS256']);
        }
        catch (ExpiredException $e) {
            //过期了
            throw new JWTException(12003);
        }
        catch (SignatureInvalidException $e) {
            //签名认证失败
            throw new JWTException(12005);
        }
        catch (BeforeValidException $e) {
            //token被设置了nbf，现在还不能使用
            throw new JWTException(12002);
        }
        catch (\UnexpectedValueException $e) {
            //header 中缺少必要的声明或者加密算法不被接收或者payload为空
            throw new JWTException(12004);
        }

        return $payLoad;
    }

    /**
     * 验证随机串（刷新用）
     */
    protected static function verifyNonce($payload = [])
    {
        if( !key_exists('nonce', $payload)){
            throw new JWTException(12004);
        }

        $nonceStr = $payload['nonce'];

        //如果没有缓存就抛出异常
        if ( !Cache::has($nonceStr)) {
            throw new JWTException(12003);
        }
        else{
            Cache::rm($nonceStr);
        }
    }

    /**
     * @return mixed
     * @throws CacheException
     * 生成随机串并缓存
     */
    protected static function createNonce()
    {
        $nonce = self::uniqueNonceStr();
        if(Cache::has($nonce)){
           throw new CacheException(17001);
        }

        $bool = Cache::set($nonce, 1, self::getExpireIn());
        if( !$bool){
            throw new CacheException(17002);
        }

        return $nonce;
    }

    /**
     * 验证流程
     */
    protected static function authenticate()
    {
        //验证token
        $payload = self::verifyToken();

        //验证随机串
        if(self::needNonce()) {
            self::verifyNonce($payload);
        }

        //检查并存储用户信息
        self::resolveAccount($payload);

        //当且仅当上面三步都完成以后才会标记“认证成功”
       self::$passAuth = true;

       //返回请求的token
       return self::$requestToken;
    }

    /**
     * 刷新流程
     */
    protected static function refresh()
    {
        //前面没有认证需要先认证
        if(self::$passAuth === false) {
            self::authenticate();
        }

        //response给前端的token
        return self::generateToken(self::$account);
    }

    /**
     * @return mixed
     * 返回将要返回的token
     */
   public static function getReturnToken()
   {
        return self::$returnToken;
   }

    /**
     * @return mixed
     * 返回前端请求的token
     */
   public static function getRequestToken()
   {
       return self::$requestToken;
   }

    /**
     * @return mixed
     * 返回登录成功用户的信息
     */
   public static function getAccount()
   {
       return self::$account;
   }

    /**
     * 获取secret key
     */
    protected static function getSecretKey()
    {
       if(empty($secretKey = Config::get("jwt.".self::$scene.".secret_key"))){
           throw new JWTException(12009);
       }

       return$secretKey;
    }

    /**
     * 获取token的过期时间
     */
    protected static function getExpireIn()
    {
        if(empty($expireIn = Config::get("jwt.".self::$scene.".expires_in"))){
            return 3600;
        }

        return (int)$expireIn;
    }

    /**
     * 是否需要随机串
     */
    protected static function needNonce()
    {
        if(empty($needNonce = Config::get("jwt.".self::$scene.".need_nonce_str"))){
            return false;
        }

        return $needNonce;
    }

    /**
     *获取用户模型
     */
    protected static function getModel()
    {
        if(empty($model = Config::get("jwt.".self::$scene.".model"))) {
            throw new JWTException(12009);
        }

        return $model;
    }

    /**
     * @return mixed
     * 生成唯一的字符串
     */
    protected static function uniqueNonceStr()
    {
        return createUniqidNonceStr();
    }
}

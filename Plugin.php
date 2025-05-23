<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Redis Cache
 *
 * @package TypechoRedisCache
 * @author suaxi
 * @version 0.0.1 beta
 * @link http://www.wangchouchou.com
 */
class TypechoRedisCache_Plugin implements Typecho_Plugin_Interface
{
    private static $redis;

    private static $cache_key_prefix = 'ARTICLE:';

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Archive')->singleHandle = array('TypechoRedisCache_Plugin', 'cache');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('TypechoRedisCache_Plugin', 'clearCache');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishDelete = array('TypechoRedisCache_Plugin', 'clearCache');
        return _t('RedisCache 插件已激活');
    }

    public static function deactivate()
    {
        self::cleanCache();
        return _t('RedisCache 插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, '127.0.0.1', _t('Redis 服务器地址'));
        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '6379', _t('Redis 服务器端口'));
        $dbNum = new Typecho_Widget_Helper_Form_Element_Text('dbNum', NULL, '0', _t('Redis 数据库(0-15)'));
        $expire = new Typecho_Widget_Helper_Form_Element_Text('expire', NULL, '86400', _t('缓存过期时间（秒）'));
        $form->addInput($host);
        $form->addInput($port);
        $form->addInput($dbNum);
        $form->addInput($expire);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function cache($archive)
    {
        self::connectRedisServer();

        $key = self::$cache_key_prefix . md5($_SERVER['REQUEST_URI']);

        $cache_data = self::$redis->hGetAll($key);

        if (!empty($cache_data)) {
            foreach ($cache_data as $key => $value) {
                $archive->$key = $value;
            }
        } else {
            $cache_data['cid'] = $archive->cid;
            $cache_data['title'] = $archive->title;
            $cache_data['slug'] = $archive->slug;
            $cache_data['created'] = $archive->created;
            $cache_data['modified'] = $archive->modified;
            $cache_data['authorId'] = $archive->authorId;
            $cache_data['content'] = $archive->content;

            self::$redis->hMSet($key, $cache_data);
            self::$redis->expire($key, Typecho_Widget::widget('Widget_Options')->plugin('TypechoRedisCache')->expire);
        }
    }

    public static function clearCache($contents, $class)
    {
        self::connectRedisServer();
        $key = self::$cache_key_prefix . md5($contents['permalink']);
        self::$redis->del($key);
    }

    public static function cleanCache()
    {
        self::connectRedisServer();

        $script = <<<LUA
        local keys = redis.call('KEYS', ARGV[1])
        for i=1,#keys,5000 do
            redis.call('DEL', unpack(keys, i, math.min(i+4999, #keys)))
        end
        return #keys
        LUA;

        self::$redis->eval($script, [self::$cache_key_prefix . '*'], 0);
    }

    private static function connectRedisServer()
    {
        if (!self::$redis) {
            try {
                $options = Typecho_Widget::widget('Widget_Options')->plugin('TypechoRedisCache');
                self::$redis = new Redis();
                self::$redis->connect($options->host, $options->port);
                self::$redis->select($options->dbNum);
                return true;
            } catch (Exception $e) {
                throw new Exception("Redis服务端连接异常: " . $e->getMessage());
            }
        }
    }
}

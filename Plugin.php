<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Redis Cache
 *
 * @package TypechoRedisCache
 * @author suaxi
 * @version 0.0.2
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
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('TypechoRedisCache_Plugin', 'clearCache');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishDelete = array('TypechoRedisCache_Plugin', 'clearCache');
        return _t('RedisCache 插件已激活');
    }

    public static function deactivate()
    {
        self::cleanCache();
        return _t('RedisCache 插件已禁用');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $cacheKeyPrefix = self::$cache_key_prefix;

        if (isset($_POST['clear_article_cache']) && isset($_POST['cid'])) {
            $cid = $_POST['cid'];
            try {
                self::connectRedisServer(true);
                if (self::$redis && self::$redis->del($cacheKeyPrefix . $cid)) {
                    exit('clearCacheSuccess');
                }
                exit('clearCacheFail');
            } catch (Exception $e) {
                exit('clearCacheException: ' . $e->getMessage());
            }
        }
        
        // 已缓存文章数
        $cacheCount = 0;
        try {
            self::connectRedisServer(true);
            if (self::$redis) {
                $cacheCount = count(self::$redis->keys($cacheKeyPrefix . '*'));
            }
        } catch (Exception $e) {}

        // 清除所有缓存
        echo <<<HTML
            <div style="padding-top:8px;">
                <strong>当前已缓存文章数：$cacheCount</strong>
                <button type="button" id="clear_all_cache_btn" style="margin-left:8px;">清除所有缓存</button>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var btn = document.getElementById('clear_all_cache_btn');
                    if(btn) {
                        if ($cacheCount === 0) {
                            btn.disabled = true;
                        }

                        btn.onclick = function() {
                            btn.disabled = true;
                            btn.innerText = '清除中...';
                            var cacheKey = 'cacheKeyPrefix';
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'clear_all_cache=1&cacheKey=' + encodeURIComponent(cacheKey)
                            }).then(r => {
                                alert('清除所有缓存成功');
                                location.reload();
                            });
                        }
                    }
                });
            </script>
        HTML;

        if (isset($_POST['clear_all_cache'])) {
            try {
                self::connectRedisServer(true);
                self::cleanCache();
                exit('清除全部缓存成功');
            } catch (Exception $e) {
                exit('清除失败: ' . $e->getMessage());
            }
        }

        // 清除指定文章缓存
        echo <<<HTML
            <div style="padding-top:8px;">
                <strong>清除指定文章缓存：</strong>
                <input type="text" id="article_cid" placeholder="请输入文章cid" style="width:120px;" />
                <button type="button" id="clear_article_cache_btn" style="margin-left:5px;">清除</button>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var btn = document.getElementById('clear_article_cache_btn');
                    if(btn) {
                        if ($cacheCount === 0) {
                            document.getElementById('article_cid').disabled = true;
                            btn.disabled = true;
                        }

                        btn.onclick = function() {
                            var cid = document.getElementById('article_cid').value;
                            var cacheKey = '$cacheKeyPrefix';
                            if (!cid) { alert('请输入文章cid'); return; }
                            btn.disabled = true;
                            btn.innerText = '清除中...';
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'clear_article_cache=1&cid=' + encodeURIComponent(cid) + '&cacheKey=' + encodeURIComponent(cacheKey)
                            }).then(r => {
                                btn.disabled = false;
                                btn.innerText = '清除';
                                return r.text();
                            }).then(data => {
                                if (data.indexOf('clearCacheSuccess') !== -1) {
                                    alert('清除成功');
                                    location.reload();
                                }
                                if (data.indexOf('clearCacheFail') !== -1) {
                                    alert('文章未缓存或已清除');
                                }
                                if (data.indexOf('clearCacheException') !== -1) {
                                    alert(data.substr('clearCacheException:'));
                                }
                            });
                        }
                    }
                });
            </script>
        HTML;

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
        $article_id = $archive->cid;
        $key = self::$cache_key_prefix . $article_id;

        $cache_data = self::$redis->hGetAll($key);

        if (!empty($cache_data)) {
            foreach ($cache_data as $key => $value) {
                $archive->$key = $value;
            }
        } else {
            $cache_data['cid'] = $article_id;
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
        $key = self::$cache_key_prefix . $class->cid;
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

    private static function connectRedisServer($slient = false)
    {
        if (!self::$redis) {
            try {
                $options = Typecho_Widget::widget('Widget_Options')->plugin('TypechoRedisCache');
                self::$redis = new Redis();
                self::$redis->connect($options->host, $options->port);
                self::$redis->select($options->dbNum);
                return true;
            } catch (Exception $e) {
                if (!$slient) {
                    throw new Exception("Redis服务端连接异常: " . $e->getMessage());
                }
            }
        }
    }
}

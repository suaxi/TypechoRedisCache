<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Redis Cache
 *
 * @package TypechoRedisCache
 * @author suaxi
 * @version 1.0.0
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

        // 测试连接
        if (isset($_POST['test_redis_conn'])) {
            if (ob_get_length()) {
                ob_clean();
            }
            header('Content-Type: text/plain; charset=utf-8');

            // 检查Redis扩展是否加载
            if (!class_exists('Redis')) {
                exit('PHP-REDIS_CHECK_FAIL');
            }

            $host = isset($_POST['host']) ? base64_decode($_POST['host']) : '127.0.0.1';
            $pwd = isset($_POST['pwd']) ? base64_decode($_POST['pwd']) : '';
            $port = isset($_POST['port']) ? base64_decode($_POST['port']) : '6379';
            $dbNum = isset($_POST['dbNum']) ? base64_decode($_POST['dbNum']) : '0';
            
            try {
                $testRedis = new Redis();
                $connected = @$testRedis->connect($host, $port, 2);
                
                if ($connected) {
                    try {
                        if(!empty($pwd)) {
                            $testRedis->auth($pwd);
                        }
                        $testRedis->select((int)$dbNum);
                        $pong = $testRedis->ping();
                        $testRedis->close();
                        if ($pong === true || (is_string($pong) && stripos($pong, 'PONG') !== false)) {
                            exit('SUCCESS');
                        } else {
                            exit('FAIL');
                        }
                    } catch (Exception $e) {
                        exit('FAIL');
                    }
                } else {
                    exit('FAIL');
                }
            } catch (Exception $e) {
                exit('FAIL');
            }
        }

        // 清除所有缓存
        if (isset($_POST['clear_all_cache'])) {
            try {
                self::cleanCache();
                exit('SUCCESS');
            } catch (Exception $e) {
                exit('FAIL');
            }
        }

        // 清除指定文章缓存
        if (isset($_POST['clear_article_cache']) && isset($_POST['cid'])) {
            $cid = $_POST['cid'];
            try {
                self::connectRedisServer(true);
                
                if (self::$redis) {
                    $res = self::$redis->del($cacheKeyPrefix . $cid);
                    if ($res > 0) {
                        exit('SUCCESS');
                    } else {
                        exit('FAIL');
                    }
                } else {
                    exit('FAIL');
                }
            } catch (Exception $e) {
                exit('CLEAR_EXCEPTION');
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

        echo <<<HTML
            <div style="padding-top:8px; padding-bottom:12px; border-bottom:1px solid #e9e9e9; margin-bottom:12px;">
                <strong>测试 Redis 连接：</strong>
                <button type="button" id="test_redis_conn_btn" style="margin-left:8px;">测试连接</button>
            </div>

            <div style="padding-top:8px;">
                <strong>当前已缓存文章数：{$cacheCount}</strong>
                <button type="button" id="clear_all_cache_btn" style="margin-left:8px;">清除所有缓存</button>
            </div>

            <div style="padding-top:8px;">
                <strong>清除指定文章缓存：</strong>
                <input type="text" id="article_cid" placeholder="请输入文章cid" style="width:120px;" />
                <button type="button" id="clear_article_cache_btn" style="margin-left:5px;">清除</button>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    function encodeStr(name) {
                        var val = document.querySelector('input[name="' + name + '"]').value || '';
                        return btoa(val);
                    }

                    // 测试连接按钮
                    var testBtn = document.getElementById('test_redis_conn_btn');
                    if(testBtn) {
                        testBtn.onclick = function() {
                            testBtn.disabled = true;
                            testBtn.innerText = '测试中...';
                            
                            var host = document.querySelector('input[name="host"]').value || '127.0.0.1';
                            var pwd = document.querySelector('input[name="pwd"]').value || '';
                            var port = document.querySelector('input[name="port"]').value || '6379';
                            var dbNum = document.querySelector('input[name="dbNum"]').value || '0';
                            

                            var body = 
                                'test_redis_conn=1' +
                                '&host=' + encodeURIComponent(encodeStr('host')) +
                                '&pwd=' + encodeURIComponent(encodeStr('pwd')) +
                                '&port=' + encodeURIComponent(encodeStr('port')) +
                                '&dbNum=' + encodeURIComponent(encodeStr('dbNum'));

                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body
                            }).then(r => r.text())
                            .then(text => {
                                testBtn.disabled = false;
                                testBtn.innerText = '测试连接';
                                
                                if (text === 'SUCCESS') {
                                    alert('连接成功！');
                                } else if (text === 'PHP-REDIS_CHECK_FAIL') {
                                    alert('php-redis 扩展未安装！');
                                } else {
                                    alert('Redis 测试连接失败，请检查配置！');
                                }
                            }).catch(err => {
                                testBtn.disabled = false;
                                testBtn.innerText = '测试连接';
                                alert('请求失败，请检查配置！');
                            });
                        }
                    }

                    // 清除所有缓存
                    var clearBtn = document.getElementById('clear_all_cache_btn');
                    if(clearBtn) {
                        if ({$cacheCount} === 0) {
                            clearBtn.disabled = true;
                        }

                        clearBtn.onclick = function() {
                            if (!confirm('确定要清除所有缓存吗？')) {
                                return;
                            }

                            clearBtn.disabled = true;
                            clearBtn.innerText = '清除中...';
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'clear_all_cache=1'
                            }).then(r => {
                                alert('清除所有缓存成功');
                                location.reload();
                            }).catch(e => {
                                clearBtn.disabled = false;
                                clearBtn.innerText = '清除所有缓存';
                                alert('清除失败');
                            });
                        }
                    }

                    // 清除指定文章缓存
                    var clearArticleBtn = document.getElementById('clear_article_cache_btn');
                    var input = document.getElementById('article_cid');
                    if(clearArticleBtn) {
                        if ({$cacheCount} === 0) {
                            input.disabled = true;
                            clearArticleBtn.disabled = true;
                        }

                        clearArticleBtn.onclick = function() {
                            var cid = input.value.trim();
                            var cacheKey = '$cacheKeyPrefix';
                            if (!cid) { alert('请输入文章cid'); return; }
                            clearArticleBtn.disabled = true;
                            clearArticleBtn.innerText = '清除中...';
                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'clear_article_cache=1&cid=' + encodeURIComponent(cid) + '&cacheKey=' + encodeURIComponent(cacheKey)
                            }).then(r => r.text())
                            .then(data => {
                                clearArticleBtn.disabled = false;
                                clearArticleBtn.innerText = '清除';
                                if (data.indexOf('SUCCESS') !== -1) {
                                    alert('清除成功');
                                    location.reload();
                                } else if (data.indexOf('FAIL') !== -1) {
                                    alert('文章未缓存或已清除');
                                } else {
                                    alert('清除失败');
                                }
                            }).catch(e => {
                                clearArticleBtn.disabled = false;
                                clearArticleBtn.innerText = '清除';
                                alert('请求失败');
                            });
                        }
                    }

                    // 保存设置校验
                    var submitForm = document.querySelector('form');
                    var saveBtn = document.querySelector('button[type="submit"], input[type="submit"]');
                    if (submitForm) {
                        submitForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            
                            var host = document.querySelector('input[name="host"]').value || '127.0.0.1';
                            var pwd = document.querySelector('input[name="pwd"]').value || '';
                            var port = document.querySelector('input[name="port"]').value || '6379';
                            var dbNum = document.querySelector('input[name="dbNum"]').value || '0';
                            
                            if (saveBtn) saveBtn.disabled = true;

                            var body = 
                                'test_redis_conn=1' +
                                '&host=' + encodeURIComponent(encodeStr('host')) +
                                '&pwd=' + encodeURIComponent(encodeStr('pwd')) +
                                '&port=' + encodeURIComponent(encodeStr('port')) +
                                '&dbNum=' + encodeURIComponent(encodeStr('dbNum'));

                            fetch(window.location.href, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body
                            }).then(r => r.text())
                            .then(text => {
                                if (text === 'SUCCESS') {
                                    submitForm.submit();
                                } else if (text === 'PHP-REDIS_CHECK_FAIL') {
                                    alert('php-redis 扩展未安装！');
                                    if (saveBtn) saveBtn.disabled = false;
                                } else {
                                    alert('Redis 连接失败，请检查配置！');
                                    if (saveBtn) saveBtn.disabled = false;
                                }
                            }).catch(err => {
                                alert('Redis 连接失败，请检查配置！');
                                if (saveBtn) saveBtn.disabled = false;
                            });
                        })
                    }
                });
            </script>
        HTML;

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, '127.0.0.1', _t('Redis 服务器地址'));
        $pwd = new Typecho_Widget_Helper_Form_Element_Password('pwd', NULL, NULL, _t('Redis 服务器密码（可选）'));
        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '6379', _t('Redis 服务器端口'));
        $dbNum = new Typecho_Widget_Helper_Form_Element_Text('dbNum', NULL, '0', _t('Redis 数据库(0-15)'));
        $expire = new Typecho_Widget_Helper_Form_Element_Text('expire', NULL, '86400', _t('缓存过期时间（秒）'));
        $form->addInput($host);
        $form->addInput($pwd);
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
            $cache_data = array(
                'cid' => $article_id,
                'title' => $archive->title,
                'slug' => $archive->slug,
                'created' => $archive->created,
                'modified' => $archive->modified,
                'authorId' => $archive->authorId,
                'content' => $archive->content
            );
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

    private static function connectRedisServer($silent = false)
    {
        if (!self::$redis) {
            try {
                // 检查Redis扩展是否已加载
                if (!class_exists('Redis')) {
                    throw new Exception("php-redis 扩展未安装");
                }

                $options = Typecho_Widget::widget('Widget_Options')->plugin('TypechoRedisCache');
                self::$redis = new Redis();
                self::$redis->connect($options->host, $options->port);
                if (!empty($options->pwd)) {
                    self::$redis->auth($options->pwd);
                }
                self::$redis->select($options->dbNum);
                return true;
            } catch (Exception $e) {
                if (!$silent) {
                    throw new Exception("Redis服务端连接异常: " . $e->getMessage());
                }
            }
        }
    }
}

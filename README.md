### Typecho Redis 文章缓存插件

#### 使用说明：

1. 安装 `php-redis`
2. `php.ini ` 开启 `redis` 扩展
3. 将插件上传至 `typecho/plugins` 插件目录，并在后台 - 插件管理 - 启用



#### 功能：

1. 文章缓存



#### 开发计划：

- [x] ~~缓存可视化界面~~，请使用 RDM、RedisInsight 等工具类

- [ ] 按类别（文章标题、内容、分类、标签）缓存
- [ ] Redis 服务端连接配置优化
文件上传到七牛
=================================
* @author crazyfd <crazyfd@qq.com>
* @version 1.0

file upload for qiniu

推荐使用 composer 安装：

```shell
composer require --prefer-dist crazyfd/yii2-qiniu
```

或者手动编辑 composer.json

```json
{
  "require": {
    "crazyfd/yii2-qiniu": "*"
  }
}
```

然后执行安装命令：

```php
composer install
```

Usage
-----

```php
<?php
$ak = 'sss';
$sk = 'sss';
$domain = 'http://demo.domain.com/';
$bucket = 'demo';
$zone = 'south_china';
use crazyfd\qiniu\Qiniu;
$qiniu = new Qiniu($ak, $sk,$domain, $bucket,$zone);
$key = time();
$key .= strtolower(strrchr($_FILES['name'], '.'));

$qiniu->uploadFile($_FILES['tmp_name'],$key);
$url = $qiniu->getLink($key);
```

<?php

namespace crazyfd\qiniu;
use yii\web\HttpException;
/**
 * upload file to qiniu.
 *
 * To use this extension, you may insert the following code in controller:
 *
 * use crazyfd\qiniu\Qiniu;
 * $qiniu = new Qiniu($ak, $sk,$domain, $bucket);
 * $qiniu->uploadFile($file['tmp_name'], time());
 *
 * @author crazyfd <crazyfd@qq.com>
 * @version 1.0
 */


class Qiniu
{

    const UP_HOST = 'http://up.qiniu.com';
    const RS_HOST = 'http://rs.qbox.me';
    const RSF_HOST = 'http://rsf.qbox.me';

    protected $accessKey;
    protected $secretKey;
    protected $bucket;
    protected $domain;

    function __construct($accessKey, $secretKey, $domain, $bucket = '')
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->domain = $domain;
        $this->bucket = $bucket;
    }

    /**
     * 通过本地文件上传
     * @param $filePath
     * @param null $key
     * @param string $bucket
     * @throws HttpException
     */

    public function uploadFile($filePath, $key = null, $bucket = '')
    {
        if(!file_exists($filePath)){
            throw new HttpException(400, "上传的文件不存在");
        }
        $bucket = $bucket ? $bucket : $this->bucket;

        $uploadToken = $this->uploadToken(array('scope' => $bucket));
        $data = [];
        if (class_exists('\CURLFile')) {
			$data['file'] = new \CURLFile($filePath);
		} else {
			$data['file'] = '@' .$filePath;
		}
        $data['token'] = $uploadToken;
        if ($key) {
            $data['key'] = $key;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::UP_HOST);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = $this->response($result);
        if ($status == 200) {
            return $result;
        } else {
            throw new HttpException($status, $result['error']);
        }
    }

    /**
     * 通过图片URL上传
     * @param $url
     * @param null $key
     * @param string $bucket
     * @throws HttpException
     */
    public function uploadByUrl($url, $key = null, $bucket = '')
    {
        $filePath = tempnam(sys_get_temp_dir(), 'QN');
        copy($url, $filePath);
        $result = $this->uploadFile($filePath, $key, $bucket);
        unlink($filePath);
        return $result;
    }

    /**
     * 获取资源信息 http://developer.qiniu.com/docs/v6/api/reference/rs/stat.html
     * @param $key
     * @param string $bucket
     * @return array
     */

    public function stat($key, $bucket = '')
    {
        $bucket = $bucket ? $bucket : $this->bucket;
        $encodedEntryURI = self::urlBase64Encode("{$bucket}:{$key}");
        $url = "/stat/{$encodedEntryURI}";
        return $this->fileHandle($url);
    }

    /**
     * 移动资源 http://developer.qiniu.com/docs/v6/api/reference/rs/move.html
     * @param $key
     * @param $bucket2
     * @param bool $key2
     * @param string $bucket
     * @throws HttpException
     */
    public function move($key, $bucket2, $key2 = false, $bucket = '')
    {
        $bucket = $bucket ? $bucket : $this->bucket;
        if (!$key2) {
            $key2 = $bucket2;
            $bucket2 = $bucket;
        }
        $encodedEntryURISrc = self::urlBase64Encode("{$bucket}:{$key}");
        $encodedEntryURIDest = self::urlBase64Encode("{$bucket2}:{$key2}");
        $url = "/move/{$encodedEntryURISrc}/{$encodedEntryURIDest}";
        return $this->fileHandle($url);
    }

    /**
     * 将指定资源复制为新命名资源。http://developer.qiniu.com/docs/v6/api/reference/rs/copy.html
     * @param $key
     * @param $bucket2
     * @param bool $key2
     * @param string $bucket
     * @throws HttpException
     */
    public function copy($key, $bucket2, $key2 = false, $bucket = '')
    {
        $bucket = $bucket ? $bucket : $this->bucket;
        if (!$key2) {
            $key2 = $bucket2;
            $bucket2 = $bucket;
        }
        $encodedEntryURISrc = self::urlBase64Encode("{$bucket}:{$key}");
        $encodedEntryURIDest = self::urlBase64Encode("{$bucket2}:{$key2}");
        $url = "/copy/{$encodedEntryURISrc}/{$encodedEntryURIDest}";
        return $this->fileHandle($url);
    }

    /**
     * 删除指定资源  http://developer.qiniu.com/docs/v6/api/reference/rs/delete.html
     * @param $key
     * @param string $bucket
     * @throws HttpException
     */
    public function delete($key, $bucket = '')
    {
        $bucket = $bucket ? $bucket : $this->bucket;
        $encodedEntryURI = self::urlBase64Encode("{$bucket}:{$key}");
        $url = "/delete/{$encodedEntryURI}";
        return $this->fileHandle($url);
    }


    /**
     * 批量操作（batch） http://developer.qiniu.com/docs/v6/api/reference/rs/batch.html
     * @param $operator   [stat|move|copy|delete]
     * @param $files
     * @throws HttpException
     */
    public function batch($operator, $files)
    {
        $data = '';
        foreach ($files as $file) {
            if (!is_array($file)) {
                $encodedEntryURI = self::urlBase64Encode($file);
                $data .= "op=/{$operator}/{$encodedEntryURI}&";
            } else {
                $encodedEntryURI = self::urlBase64Encode($file[0]);
                $encodedEntryURIDest = self::urlBase64Encode($file[1]);
                $data .= "op=/{$operator}/{$encodedEntryURI}/{$encodedEntryURIDest}&";
            }
        }
        return $this->fileHandle('/batch', $data);
    }

    /**
     * 列举资源  http://developer.qiniu.com/docs/v6/api/reference/rs/list.html
     * @param string $limit
     * @param string $prefix
     * @param string $marker
     * @param string $bucket
     * @throws HttpException
     */
    public function listFiles($limit = '', $prefix = '', $marker = '', $bucket = '')
    {
        $bucket = $bucket ? $bucket : $this->bucket;
        $params = array_filter(compact('bucket', 'limit', 'prefix', 'marker'));
        $url = self::RSF_HOST . '/list?' . http_build_query($params);
        return $this->fileHandle($url);
    }

    protected function fileHandle($url, $data = array())
    {
        if (strpos($url, 'http://') !== 0){
            $url = self::RS_HOST . $url;
        }

        if (is_array($data)){
            $accessToken = $this->accessToken($url);
        }
        else{
            $accessToken = $this->accessToken($url, $data);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: QBox ' . $accessToken,
        ));

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = $this->response($result);
        if ($status == 200) {
            return $result;
        } else {
            throw new HttpException($status ,$result['error']);
        }
    }

    public function uploadToken($flags)
    {
        if (!isset($flags['deadline'])) {
            $flags['deadline'] = 3600 + time();
        }
        $encodedFlags = self::urlBase64Encode(json_encode($flags));
        $sign = hash_hmac('sha1', $encodedFlags, $this->secretKey, true);
        $encodedSign = self::urlBase64Encode($sign);
        $token = $this->accessKey . ':' . $encodedSign . ':' . $encodedFlags;
        return $token;
    }

    public function accessToken($url, $body = false)
    {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'];
        $access = $path;
        if (isset($parsed_url['query'])) {
            $access .= "?" . $parsed_url['query'];
        }
        $access .= "\n";
        if ($body) {
            $access .= $body;
        }
        $digest = hash_hmac('sha1', $access, $this->secretKey, true);
        return $this->accessKey . ':' . self::urlBase64Encode($digest);
    }

    /**
     * 可以传输的base64编码
     * @param $str
     * @return mixed
     */

    public static function urlBase64Encode($str)
    {
        $find = array("+", "/");
        $replace = array("-", "_");
        return str_replace($find, $replace, base64_encode($str));
    }

    /**
     * 获取文件下载资源链接
     * @param string $key
     * @return string
     */
    public function getLink($key = '')
    {
//         $url = "http://{$this->domain}/{$key}";
    	$url = rtrim($this->domain,'/')."/{$key}";
        return $url;
    }


    /**
     * 获取响应数据
     * @param  string $text 响应头字符串
     * @return array        响应数据列表
     */
    private function response($text)
    {
        return json_decode($text, true);
    }

    public function __destruct()
    {
    }
}

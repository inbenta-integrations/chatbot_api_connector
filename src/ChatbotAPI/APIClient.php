<?php
namespace Inbenta\ChatbotConnector\ChatbotAPI;

use \Exception;

class APIClient
{
    protected $url;
    protected $accessToken;
    protected $key;
    protected $ttl;
    protected $methods;
    protected $cachePath;
    const     TOKEN_REFRESH_OFFSET  = 180;       // Time in seconds before access-token-expiration when it should be refreshed

    function __construct($key, $secret)
    {
        $this->url = "https://api.inbenta.io/v1";
        $this->key = $key;
        $this->secret = $secret;
        $this->cachePath = rtrim(sys_get_temp_dir(), '/') . '/';
        $this->cachedAccessTokenFile = $this->cachePath . "cached-accesstoken-" . preg_replace("/[^A-Za-z0-9 ]/", '', $this->key);

        $this->updateAccessToken();
    }

    protected function updateAccessToken()
    {
        // Update access token if needed
        if (!$this->validAccessToken()) {
            // Get the access token from cache
            $this->getAccessTokenFromCache();
            // Get a new access token from API if it doesn't exists or it's expired
            if (!$this->validAccessToken()) {
                $this->getAccessTokenFromAPI();
            } elseif (($this->ttl - self::TOKEN_REFRESH_OFFSET) <= time()) {
                // Refresh access token before it expires (during the token_refresh_offset)
                $this->refreshAccessToken();
            }
        }
    }

    protected function validAccessToken() {
        return !is_null($this->accessToken) && !is_null($this->ttl) && $this->ttl > time();
    }

    /**
     *  Get the accessToken information from cache
     */
    protected function getAccessTokenFromCache()
    {
        $cachedAccessToken          = file_exists($this->cachedAccessTokenFile) ? json_decode(file_get_contents($this->cachedAccessTokenFile)) : null;
        $cachedAccessTokenExpired   = is_object($cachedAccessToken) && !empty($cachedAccessToken) ? $cachedAccessToken->expiration < time() : true;
        if (is_object($cachedAccessToken) && !empty($cachedAccessToken) && !$cachedAccessTokenExpired) {
            $this->accessToken = $cachedAccessToken->accessToken;
            $this->ttl = $cachedAccessToken->expiration;
            $this->methods = $cachedAccessToken->apis;
        }
    }

    protected function getAccessTokenFromAPI()
    {
        $headers = array("x-inbenta-key:".$this->key);
        $params = array("secret=".$this->secret);
        $accessInfo = $this->call("/auth","POST", $headers, $params);
        if (isset($accessInfo->messsage) && $accessInfo->message == 'Unauthorized') {
          throw new Exception("Invalid key/secret");
        }
        $this->accessToken  = $accessInfo->accessToken;
        $this->ttl          = $accessInfo->expiration;
        $this->methods      = $accessInfo->apis;
        file_put_contents($this->cachedAccessTokenFile, json_encode($accessInfo));
    }

    protected function refreshAccessToken()
    {
        // Update access token if needed
        $this->updateAccessToken();

        $headers = array("x-inbenta-key:".$this->key, "Authorization: Bearer ".$this->accessToken);
        $accessInfo = $this->call("/refreshToken", "POST", $headers);
        //Throw unauthorized exception
        if (isset($accessInfo->messsage) && $accessInfo->message == 'Unauthorized') {
          throw new Exception("Invalid key/secret");
        }
        $this->accessToken  = $accessInfo->accessToken;
        $this->ttl          = $accessInfo->expiration;
        // Set the API methods in the $accessInfo data from cache because the /refresToken endpoint does not return this data
        $accessInfo->apis   = $this->methods;
        file_put_contents($this->cachedAccessTokenFile, json_encode($accessInfo));
    }

    protected function call($path, $method, $headers = array(), $params = array())
    {
        $curl = curl_init();

        $opts = array(
            CURLOPT_URL => $this->url.$path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POST => ($method == "POST") ? 1 : 0,
            CURLOPT_POSTFIELDS => implode(",", $params),
            CURLOPT_HTTPHEADER => $headers,
        );

        curl_setopt_array($curl,$opts);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        } else {
            return json_decode($response);
        }
    }
}

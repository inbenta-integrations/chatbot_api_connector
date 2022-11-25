<?php
namespace Inbenta\ChatbotConnector\Utils;

use \Exception;
use Inbenta\ChatbotConnector\Utils\DotAccessor;

class SessionManager
{
    protected $data;

    function __construct($sessionId = null)
    {
        session_id($sessionId);
        session_start();

        $data = isset($_SESSION['data']) ? $_SESSION['data'] : array();
        $this->data = new DotAccessor($data);
    }

    public function get($key, $default = null)
    {
        if ($this->data->has($key)) {
            return $this->data->get($key);
        }
        return $default;
    }

    public function set($key, $value)
    {
        $this->data->set($key, $value);
        $_SESSION['data'] = $this->data->get();
    }

    public function add($key, $value)
    {
        $tmp = [];
        if ($this->data->has($key)) {
            $tmp = $this->data->get($key);
        }
        $tmp[] = $value;
        $this->data->set($key, $tmp);
        $_SESSION['data'] = $this->data->get();
    }

    public function has($key)
    {
        return $this->data->has($key);
    }

    public function delete($key)
    {
        $this->data->delete($key);
        $_SESSION['data'] = $this->data->get();
    }

    public function clear()
    {
        $this->data->clear();
        $_SESSION['data'] = $this->data->get();
    }
}

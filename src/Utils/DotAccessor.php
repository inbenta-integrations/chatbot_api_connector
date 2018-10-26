<?php
namespace Inbenta\ChatbotConnector\Utils;

/**
 * Allow accessing an array type memory storage
 * using dot notation.
 *
 * Ex: if data is
 *     array(
 *         'A' => array(
 *             'value' => 'A'
 *         )
 *     )
 *     we can access direclty to value
 *     DotAccessor::get('A.value');
 *
 */
class DotAccessor
{
    const SEPARATOR = '.';

    protected $data;

    /**
     * Build a dot accessor
     * @param array $data initial data
     */
    public function __construct($data = array())
    {
        $this->data = $data;
    }

    /**
     * Check if a value exists inside data
     * @param  string  $path
     * @return boolean
     */
    public function has($path)
    {
        $keys = $this->explodePath($path);
        $array = $this->data;
        foreach ($keys as $key) {
            if (is_array($array) && array_key_exists($key, $array)) {
                $array = $array[$key];
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * Retrieve a value from stored data
     * @param  string $path
     * @throws Inbenta\ChatbotConnector\Exception\AccessorException if value is not found inside data
     * @return mixed
     */
    public function get($path = '')
    {
        $array = $this->data;
        if (!empty($path)) {
            $keys = $this->explodePath($path);
            foreach ($keys as $key) {
                if (array_key_exists($key, $array)) {
                    $array = $array[$key];
                } else {
                    throw new AccessorException($path.' does not exists');
                }
            }
        }
        return $array;
    }

    /**
     * Store a value inside data
     * @param string $path
     * @return Inbenta\Bot\Support\DotAccessor
     */
    public function set($path, $value)
    {
        $keys = $this->explodePath($path);

        $array =& $this->data;
        while (count($keys) > 1) {
            $key = array_shift($keys);

            // If the key doesn't exist at this depth, we will just create an empty array
            // to hold the next value, allowing us to create the arrays to hold final
            // values at the correct depth. Then we'll keep digging into the array.
            if (!array_key_exists($key, $array) || !is_array($array[$key])) {
                $array[$key] = array();
            }

            $array =& $array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $this;
    }

    /**
     * Delete a value from data
     * @param  string $path
     * @return Inbenta\Bot\Support\DotAccessor
     */
    public function delete($path)
    {
        $keys = $this->explodePath($path);
        $array = &$this->data;
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if (array_key_exists($key, $array)) {
                $array =& $array[$key];
            }
        }

        unset($array[array_shift($keys)]);

        return $this;
    }

    /**
     * Return path first level key
     * @param  string $path
     * @return string
     */
    public function getFirstKey($path)
    {
        return $this->explodePath($path)[0];
    }

    /**
     * Split a path into several parts using the current separator
     * @param  string $path
     * @return array
     */
    protected function explodePath($path)
    {
        return explode(static::SEPARATOR, $path);
    }

    /**
     * Empties the data object
     * @return array
     */
    public function clear()
    {
        $this->data = array();
        return $this->data;
    }
}

class AccessorException extends \RuntimeException{}

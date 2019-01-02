<?php
namespace Inbenta\ChatbotConnector\Utils;

use \Exception;
use Inbenta\ChatbotConnector\Utils\DotAccessor;

class LanguageManager
{
	protected $data;

	function __construct($language, $appPath)
    {
        $path = $appPath . '/lang/' . $language . ".php";
        if (file_exists($path)) {
            $this->data =  new DotAccessor(require realpath($path));
        } else {
        	throw new Exception("Language '" . $language . "' not found at path '" . $path . "'", 1);        	
        }
	}

    /**
    *   Translates a key from the language files into the current configured language
    */
    public function translate($key, $parameters = array())
    {
    	if ($this->data->has($key)) {
    		$text = $this->data->get($key);

    		foreach ($parameters as $param => $value) {
    			$text = str_replace( '$'.$param, $value, $text);
    		}
    		return $text;
    	}
        //If translation not found, return the input key
        return $key;
    }

    /**
     *  Add translations to the dictionary
     */
    public function addTranslations($translations)
    {
        foreach ($translations as $label => $text) {
            $this->data->set($label, $text);
        }
    }

}

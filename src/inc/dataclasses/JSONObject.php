<?php

/**
 * Super class JSONObject able to recursively create new objects from JSON.
 */
class JSONObject extends stdClass {
    protected const USE_ARRAY_FLOW = false;

    /**
     * Create empty object, or initiate it from JSON.
     */
    public function __construct($json = null) {
        if($json){
            $this->__fromJson($json);
        }
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     */
    public function __fromJson($json) {
        $this->set(is_string($json)? json_decode($json, true): $json);
    }

    /**
     * Initiate the object based on provided data.
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    public function set($data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!static::USE_ARRAY_FLOW || !array_is_list($value)) {
                    // Keep old flow for the classes like Applications - to make sure that fields like statusHistory works before
                    $sub = new JSONObject;
                    $sub->set($value);
                    $value = $sub;
                }
            }
            // @TODO should this be also supported?
            //elseif (is_object($value)) {
            //    // wrap stdClass into JSONObject too
            //    $value = json_decode(json_encode($value), true);
            //    $sub = new JSONObject;
            //    $sub->set($value);
            //    $value = $sub;
            //}
            $this->{$key} = $value;
        }
    }

    public function __toString(){
        return serialize($this);
    }
}

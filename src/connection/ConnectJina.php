<?php

namespace DcoAi\PhpJina\connection;


use stdClass;

class ConnectJina
{
    private $url;
    private $endpoints;
    public function __construct($conf) {
        $this->url = $conf["url"].":".$conf["port"];
        $this->endpoints = $conf["endpoints"];
    }

    /**
     * @param $endpoint
     * @param $da
     * @param $clean
     * @return mixed|stdClass|void
     */
    public function callAPI($endpoint, $da, $clean){
        $data = $this->cleanDocArray($da);
        $method = "GET";
        if (array_key_exists($endpoint,$this->endpoints)) {
            $method = $this->endpoints[$endpoint];
        }
        $url = $this->url.$endpoint;
        $curl = curl_init();
        switch ($method){
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            default:
                $url = sprintf("%s?%s", $url, http_build_query($data));
        }
        // OPTIONS:
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        // EXECUTE:
        $result = curl_exec($curl);
        if(!$result){die("Connection Failure");}
        curl_close($curl);

        $res = json_decode($result);
        if ($clean) {
            $res = $this->cleanDocArray($res);
            $res = $this->identifyChunks($res);
        }
        return $res;
    }
    private function iterateChunks($d) {
        $newChunks = null;
        // check if there is anything in chunks
        if (is_object($d) && property_exists($d, "chunks") && !empty($d->chunks) ) {
            // now see if there is _metadata->multi_modal_schema
            if (property_exists($d, "_metadata") && property_exists($d->_metadata, "multi_modal_schema")) {
                // We are looking for the data to tie to the chunks
                foreach ($d->_metadata->multi_modal_schema as $class => $meta) {
                    /*
                     * if the key "position" exists then we know it's in the chunks else it is in the tags
                     * These will be in the chunks as attribute_type:
                     * 'document'
                     * 'iterable_document'
                     * 'nested'
                     * 'iterable_nested'
                    */
                    if (property_exists($meta, "position")) {
                        $newChunks[$class] = $d->chunks[$meta->position];
                    }
                }
                $d->chunks = $newChunks;
            }
            // make sure to get all those nested chunks
            foreach($d->chunks as $cls => $chunk) {
                $d->chunks[$cls] = $this->iterateChunks($chunk);
            }
        }
        return $d;
    }
    private function identifyChunks($da) {
        foreach ($da->data as $k => $d) {
            $da->data[$k] = $this->iterateChunks($d);
        }
        return $da;
    }
    private function is_it_empty_though($val): bool
    {
        if (is_null($val)) {
            return true;
        }
        if (is_array($val) && sizeof($val) == 0) {
            return true;
        }
        return false;
    }
    private function array_filter_recursive($array) {
        $array = json_decode(json_encode($array), true);
        foreach ($array as $key => &$value) {
            if ($this->is_it_empty_though($value)) {
                unset($array[$key]);
            }
            else {
                if (is_array($value)) {
                    $value = $this->array_filter_recursive($value);
                    if ($this->is_it_empty_though($value)) {
                        unset($array[$key]);
                    }
                }
            }
        }
        return json_decode(json_encode($array));
    }
    private function cleanDocArray($da): stdClass
    {
        return $this->array_filter_recursive($da);
    }

}

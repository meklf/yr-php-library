<?php

namespace eigan\yr;

require "Forecast.php";

/**
 * 
 * Please read the rules for using the yr api http://om.yr.no/verdata/vilkar/
 * This class will implement caching for you
 * 
 * @see http://om.yr.no/verdata/free-weather-data/
 * @author Einar Gangsø <einargangso@gmail.com>
 */
class Yr {

    /**
     * @var array
     */
    protected $forecasts_hourly;
    
    /**
     * Periodic data
     * @var array
     */
    protected $forecasts_periodic;

    /**
     * List of WheaterStation objects
     * @var array
     */
    protected $observations;

    /**
     * The location where we have weather data
     * @var array
     */
    protected $location;

    /**
     * @var array
     */
    protected $links;

    /**
     * Credit should be used...
     * @var String
     */
    protected $credit_url;

    /**
     * Credit derp
     * @var String
     */
    protected $credit_text;

    /**
     * Time when the web service was last refreshed
     * @var int
     */
    protected $last_update_date;

    /**
     * Time when the web service will update next
     * @var int
     */
    protected $next_update_date;        

    /**
     * This is the format used in the xml files. 
     * It is converted to DateTime everywhere 
     * @var string
     */
    const XML_DATE_FORMAT = "Y-m-d?H:i:s";

    /**
     * @param array $location 
     * @param array $forecasts_periodic
     * @param  array $forecasts_hourly
     * @return Yr
     */
    public function __construct(array $location, array $forecasts_periodic, array $forecasts_hourly)
    {
        $this->location = $location;
        $this->forecasts_periodic = $forecasts_periodic;
        $this->forecasts_hourly = $forecasts_hourly;

        $this->links = array();
    }

    /**
     * This method builds the Yr object from the freely available Yr api
     * 
     * @todo  Check data we are setting on the yr object (meta data, dates, etc)
     * @param String the location, like Vestfold/Sandefjord
     * @return Yr
     * @throws RuntimeException if cache path is not writeable
     * @throws RuntimeException if the location is not correct
     */
    public static function create($location, $cache_path, $cache_life = 10)
    {
        // Clean the cache path
        $cache_path = realpath($cache_path) . DIRECTORY_SEPARATOR;

        // Check if cache path is readable
        if(!is_writable($cache_path)) {
            throw new RuntimeException("Cache path is not writable");
        }

        // Cache paths for the location
        $xml_periodic_path = $cache_path . "phpyrno_" . md5($location) . "_periodic.xml";
        $xml_hourly_path = $cache_path . "phpyrno_" . md5($location) . "_hourly.xml";

        // Check if the location is valid by simply first check if cache is there
        // if not, then we would need to lookup later, so we check if we can get a 200 code
        // from the web service
        if(!is_readable($xml_periodic_path) || !is_readable($xml_hourly_path)) {
            $ch = curl_init("http://www.yr.no/place/$location/forecast_hour_by_hour.xml");

            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_exec($ch);
            $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if($retcode != 200) {
                throw new \RuntimeException("Invalid location! Make sure the format is Country/Fylke/City/City or something. Just check the url on yr.no");
            }
        }

        // Download the periodic xml if we doesnt have it
        if(!is_readable($xml_periodic_path) /* || is_exipired */) {
            file_put_contents($xml_periodic_path, fopen("http://www.yr.no/place/$location/forecast_hour_by_hour.xml", 'r'));
        }

        // Download the hourly xml if we doesnt have it
        if(!is_readable($xml_hourly_path) /* || is_exipired */) {
            file_put_contents($xml_hourly_path, fopen("http://www.yr.no/place/$location/forecast_hour_by_hour.xml", 'r'));
        }

        $xml_hourly = new \SimpleXMLElement($xml_hourly_path, null, true);
        $xml_periodic = new \SimpleXMLElement($xml_periodic_path, null, true);

        // Get all the hourly forecasts and create Forecast objects
        $forecasts_hourly = array();
        foreach($xml_hourly->forecast->tabular->time as $forecast) {
            try {
                $tmp = Forecast::getForecastFromXml($forecast);
                $forecasts_hourly[] = $tmp;
            } catch(RuntimeException $e) {}
        }

        // Get all the periodic forecasts and create Forecast objects
        $forecasts_periodic = array();
        foreach($xml_periodic->forecast->tabular->time as $forecast) {
            try {
                $tmp = Forecast::getForecastFromXml($forecast);
                $forecasts_periodic[] = $tmp;
            } catch(RuntimeException $e) {}
        }

        // Get other data for our object
        $location = self::xmlToArray($xml_periodic->location);
        $links = self::xmlToArray($xml_periodic->links);
        $credit = self::xmlToArray($xml_periodic->credit->link);
        $meta = self::xmlToArray($xml_hourly->meta);


        // Set the data on the object
        $yr = new Yr($location, $forecasts_hourly, $forecasts_periodic);

        if(isset($links['link'])) {
            foreach($links['link'] as $link) {
                $yr->addLink($link['id'], $link['url']);
            }  
        }

        if(isset($credit['text'], $credit['url'])) {
            $yr->setCreditText($credit['text']);
            $yr->setCreditUrl($credit['url']);
        }

        $yr->setLastUpdated(\DateTime::createFromFormat(self::XML_DATE_FORMAT, $meta['lastupdate']));
        $yr->setNextUpdate(\DateTime::createFromFormat(self::XML_DATE_FORMAT, $meta['nextupdate']));

        // Finally return the object
        return $yr;
    }

    /**
     * 
     * @return Location
     */
    public function getLocation($key = "name")
    {
        return isset($this->location[$key]) ? $this->location[$key] : null;
    }

    /**
     * 
     * @return array
     */
    public function getLinks()
    {
        return $this->links;
    }

    /**
     * Adds a link to the location 
     * @param String $name
     * @param String $url
     */
    public function addLink($name, $url)
    {
        $this->links[$name] = $url;
    }

    /**
     * Returns the current forecast (using periodic)
     *
     * @return Forecast
     */
    public function getCurrentForecast()
    {
        return reset($this->forecasts_periodic);
    }

    /**
     * Returns the upcoming forecasts as array of Forecast objects
     * @return array Forecast objects
     */
    public function getHourlyForecasts()
    {
        return $this->forecasts_hourly;
    }

    /**
     * There is 4 peridos in a day. Make sure to check the Forecast::getPeriod()
     * @return array Forecast objects
     */
    public function getPeriodicForecasts()
    {
        return $this->forecasts_periodic;
    }
    
    /**
     * Returns the time the hourly data was last updated
     * @return int unixtime
     */
    public function getLastUpdated()
    {
        return $this->last_update_date;
    }

    /**
     * Setter for last update
     * @param int $date Should be int sometime
     */
    public function setLastUpdated($date)
    {
        $this->last_update_date = $date;
    }

    /**
     * Returns the time this will update next time the hourly data will update
     * @return [type] [description]
     */
    public function getNextUpdate()
    {
        return $this->next_update_date;
    }

    /**
     * 
     * @param [type] $date [description]
     */
    public function setNextUpdate($date)
    {
        $this->next_update_date = $date;
    }

    /**
     * @return String 
     */
    public function getCreditUrl()
    {
        return $this->credit_url;
    }

    /**
     * @param String $url
     */
    public function setCreditUrl($url)
    {
        $this->credit_url = $url;
    }

    /**
     * @return String
     */
    public function getCreditText() 
    {
        return $this->credit_text;
    }

    /**
     * @param String $text 
     */
    public function setCreditText($text)
    {
        $this->credit_text = $text;
    }

    /**
     * Converts xml to array and hide comments
     * @return array
     */
    public static function xmlToArray($data, $out = array())
    {
        foreach((array) $data as $index => $node) {
            if($index == 'comment') continue;
            
            if($index == '@attributes') {
                $out = array_merge($out, is_object($node) || is_array($node) ? self::xmlToArray($node) : (string) $node);                
            } else {
                $out[$index] = is_object($node) || is_array($node) ? self::xmlToArray($node) : (string) $node;
            }
        }
        
        return $out;
    }
}
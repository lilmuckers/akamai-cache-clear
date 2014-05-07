<?php


class AkamaiCacheClear 
{
	/**
	 * Akamai cache REST api constants
	 */
	const AKAMAI_CCU_REST_ENDPOINT = 'https://api.ccu.akamai.com/ccu/v2/queues/default';
	const AKAMAI_SUCCESS_CODE      = 201;
	const AKAMAI_RESERVED_CODE     = 200;
	
	
	/**
	 * GitHub constants
	 */
	const GITHUB_COMPARE_URL	= 'https://api.github.com/repos/%s/%s/compare/%s...%s';
	const GITHUB_OAUTH_PARAM	= '?access_token=%s';
	const GITHUB_DEF_USER_AGENT	= 'Patricks Akamai Cache Clear Script (now with flavour)';
	
	/**
	 * The configuration cache
	 * 
	 * @var array
	 */
	protected $_config = array();
	
	/**
	 * The filename for the config file
	 * 
	 * @var string
	 */
	protected $_configPath;
	
	/**
	 * Debug flag for the curl requests
	 * 
	 * @var bool
	 */
	protected $_debug = false;
	
	/**
	 * Constructor loads the configuration path
	 * 
	 * @param string $configFilename
	 * @return void
	 */
	public function __construct($configFilename, $debug = false)
	{
		//check the file exists and then load it
		if(!file_exists($configFilename)){
			throw new Exception('Invalid config filename specified');
		}
		$this->_config = parse_ini_file($configFilename, true);
		
		//set the debug flag
		$this->_debug = $debug;
	}
	
	/**
	 * Run the cache clear
	 * 
	 * @param string $baseTag
	 * @param string $headTag
	 * @return bool
	 */
	public function run($baseTag, $headTag)
	{
		//first we need to get the file diffs
		$_fileDiffs = $this->_loadFileDiffs($baseTag, $headTag);
		
		//now we need to get the differences for the akamai cache
		$_akamaiDiffs = $this->_filterAkamai($_fileDiffs);
		
		//now we request that these are cleared
		$_akamaiResponse = $this->_clearAkamaiCache($_akamaiDiffs);
		
		//return the clear result
		return $_akamaiResponse;
	}
	
	/**
	 * Request the provided ARLs are cleared from Akamai CCU
	 * 
	 * @param array $arlsToClear
	 * @return int
	 */
	protected function _clearAkamaiCache($arlsToClear)
	{
		//check that there are ARLs to clear
		if(empty($arlsToClear)){
			return false;
		}
		
		//get the akamai configs
		$_akamaiConfig = $this->_config['akamai'];
		
		//the curl options
		$_curlOpts = array(
			CURLOPT_USERPWD => $_akamaiConfig['username'] . ":" . $_akamaiConfig['password']
		);
		
		//the content-type header
		$_headers = array("Content-Type:application/json");
		
		//set up the values to send to akamai
		$_options = $_akamaiConfig['options'];
		$_options['objects'] = $arlsToClear;
		
		//perform the request
		$_ccuResponse = $this->_curlRequest(self::AKAMAI_CCU_REST_ENDPOINT, $_options, $_headers, $_curlOpts);
		
		//decode the response
		$_response = json_decode($_ccuResponse);
		
		return $this->_processAkamaiResponse($_response);
	}
	
	/**
	 * Process the response from akamai
	 * 
	 * @param stdClass $response
	 * @return int
	 */
	protected function _processAkamaiResponse($response)
	{
		//process the response code
		switch($response->httpStatus)
		{
			case self::AKAMAI_SUCCESS_CODE:
			case self::AKAMAI_RESERVED_CODE:
				//it worked just fine!
				return $response->estimatedSeconds/60;
				break;
			default:
				throw new Exception('Akamai Cache Clear error: '.$response->detail);
				break;
		}
	}
	
	/**
	 * Filters out files that are of no concern to akamai
	 * 
	 * @param array $fileDiffs
	 * @return array
	 */
	protected function _filterAkamai($fileDiffs)
	{
		//initialise the akamai file
		$_akamaiFiles = array();
		
		//iterate through the files
		foreach($fileDiffs as $_file)
		{
			//check that this is a modification - we don't care about additions
			if($_file->status == 'modified'){
				//convert it to an ARL or bool as appropriate
				$_arl = $this->_convertArl($_file->filename);
				
				//if we got a valid ARL back - we add it to the array
				if($_arl){
					$_akamaiFiles[] = $_arl;
				}
			}
		}
		
		//return the arl list
		return $_akamaiFiles;
	}
	
	/**
	 * Convert a given path to an ARL for akamai
	 * 
	 * @param string $path
	 * @return string
	 */
	protected function _convertArl($path)
	{
		//get the path configs
		$_pathMap = $this->_config['akamai']['paths'];
		
		//start the arl return
		$_arl = false;
		
		//iterate through path map to match to this path
		foreach($_pathMap as $_pref=>$_arlBase)
		{	
			//get the equivilent prefix
			$_pathPrefix = substr($path, 0, strlen($_pref));
			
			//check it matches
			if($_pathPrefix == $_pref){
				$_arl = $_arlBase.$path;
			}
		}
		
		//and return the result
		return $_arl;
	}
	
	/**
	 * Load the file diffs between two references, tag names, shas, etc
	 * 
	 * @param string $baseTag
	 * @param string $headTag
	 * @return array
	 */
	protected function _loadFileDiffs($baseTag, $headTag)
	{
		//load up the json from github
		$_json = $this->_githubCompare($baseTag, $headTag);
		
		//decode the json
		$_data = json_decode($_json);
		
		//check for errors
		if(property_exists($_data, 'message')){
			throw new Exception('GitHub Error Message: '.$_data->message);
		}
		
		//get the file array
		$_files = $_data->files;
		
		//return it
		return $_files;
	}
	
	/**
	 * Pull the compare JSON string from github
	 * 
	 * @param string $baseTag
	 * @param string $headTag
	 * @return string
	 */
	protected function _githubCompare($baseTag, $headTag)
	{
		//get the github configuration
		$_githubConfig = $this->_config['github'];
		
		//build the URL
		$_url = sprintf(self::GITHUB_COMPARE_URL, $_githubConfig['owner'], $_githubConfig['repo'], $baseTag, $headTag);
		$_url .= sprintf(self::GITHUB_OAUTH_PARAM, $_githubConfig['access_token']);
		
		return $this->_curlRequest($_url);
	}
	
	/**
	 * Perform the cURL request
	 * 
	 * @param string $url      The URL to send to
	 * @param mixed  $dataBody The data to post to the URL (Arrays will be json_encoded)
	 * @param array  $headers  An array of additional headers to send
	 * @param array  $options  Additional curl options
	 * 
	 * @return mixed
	 */
	protected function _curlRequest($url, $dataBody = null, $headers = null, $options = null)
	{
		//start curl
		$_ch = curl_init();
		
		//set the url and that we want to get the response 
		curl_setopt($_ch, CURLOPT_URL, $url);
		curl_setopt($_ch, CURLOPT_RETURNTRANSFER, true);
		
		//We need to set a user agent to make GitHub happy
		curl_setopt($_ch, CURLOPT_USERAGENT, self::GITHUB_DEF_USER_AGENT);
		
		//Githubs SSL certificate causes issues.
		curl_setopt($_ch, CURLOPT_SSL_VERIFYPEER, false);
		
		//set a post body if required
		if (!is_null($dataBody)) {
			if (is_array($dataBody)) {
				$dataBody = json_encode($dataBody);
			}
			curl_setopt($_ch, CURLOPT_POSTFIELDS, $dataBody);
		}
		
		//set the headers
		if (!is_null($headers)) {
			curl_setopt($_ch, CURLOPT_HTTPHEADER, $headers);
		}
		
		if (!is_null($options)) {
			foreach ($options as $_option => $_value) {
				curl_setopt($_ch, $_option, $_value);
			}
		}
		
		//set verbose mode for debug
		if ($this->_debug) {
			curl_setopt($_ch, CURLOPT_VERBOSE, true);
		}
		
		//get the data
		return curl_exec($_ch);
	}
}

//get the cli args
$_options = array(
	'old:','new:'
);
$_options = getopt('', $_options);

//check the options
if(!array_key_exists('old', $_options) || !array_key_exists('new', $_options)){
	echo "Usage: php ".$argv[0]." --old=<old release tag> --new=<new release tag>\n";
} else {
	$_utility = new AkamaiCacheClear('akamai.ini', true);
	$_timer = $_utility->run($_options['old'], $_options['new']);
	
	//output for the user
	if(!$_timer){
		echo "Nothing to do\n";
	} else {
		echo "Cache will take an estimated ".$_timer." minutes to be cleared.\n";
	}
}

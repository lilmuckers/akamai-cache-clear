<?php


class AkamaiCacheClear 
{
	/**
	 * Akamai cache constants items
	 */
	const AKAMAI_CCUAPI_WSDL	= 'https://ccuapi.akamai.com/ccuapi.wsdl';
	const AKAMAI_CCUAPI_NETWORK	= '';
	const AKAMAI_SUCCESS_CODE	= 100;
	const AKAMAI_RESERVED_CODE	= 200;
	
	/**
	 * GitHub constants
	 */
	const GITHUB_COMPARE_URL	= 'https://api.github.com/repos/%s/%s/compare/%s...%s';
	const GITHUB_OAUTH_PARAM	= '?access_token=%s';
	
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
	 * Constructor loads the configuration path
	 * 
	 * @param string $configFilename
	 * @return void
	 */
	public function __construct($configFilename)
	{
		//check the file exists and then load it
		if(!file_exists($configFilename)){
			throw new Exception('Invalid config filename specified');
		}
		$this->_config = parse_ini_file($configFilename, true);
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
		
		//start the soap client
		$_client = new SoapClient(self::AKAMAI_CCUAPI_WSDL);
		
		//build the options
		$_options = array();
		foreach($_akamaiConfig['options'] as $_key => $_var){
			$_options[] = $_key.'='.$_var;
		}
		
		//run the request
		$_ccuResponse = $_client->purgeRequest(
			$_akamaiConfig['username'], 
			$_akamaiConfig['password'], 
			self::AKAMAI_CCUAPI_NETWORK,
			$_options,
			$arlsToClear
		);
		
		//process the response
		return $this->_processAkamaiResponse($_ccuResponse);
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
		switch($response->resultCode)
		{
			case self::AKAMAI_SUCCESS_CODE:
			case self::AKAMAI_RESERVED_CODE:
				//it worked just fine!
				return $response->estTime/60;
				break;
			default:
				throw new Exception('Akamai Cache Clear error: '.$response->resultMsg);
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
		
		//start curl
		$_ch = curl_init();
		
		//set the url and that we want to get the response 
		curl_setopt($_ch, CURLOPT_URL, $_url);
		curl_setopt($_ch, CURLOPT_RETURNTRANSFER, true);
		
		//Githubs SSL certificate causes issues.
		curl_setopt($_ch, CURLOPT_SSL_VERIFYPEER, false);
		
		//curl_setopt($_ch, CURLOPT_VERBOSE, true);
		
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
	$_utility = new AkamaiCacheClear('akamai.ini');
	$_timer = $_utility->run($_options['old'], $_options['new']);
	
	//output for the user
	if(!$_timer){
		echo "Nothing to do\n";
	} else {
		echo "Cache will take an estimated ".$_timer." minutes to be cleared.\n";
	}
}

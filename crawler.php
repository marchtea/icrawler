<?php

/*
 * written by summer @ 2013.9.2
 * contact: marchtea213@gmail.com
 * feel free to make change
 * 
*/



//rely on simple_html_dom 
require_once('simple_html_dom.php');

define('CRAWL_URL_ERROR', -1);
define('CRAWL_PROXY_HTTP', CURLPROXY_HTTP);
define('CRAWL_PROXY_SOCKS5', CURLPROXY_SOCKS5);

//Usage:
//first new a Crawler, call start() to crawl
class Crawler
{
	private $jobs; //array contain CrawlJob objects
	private $has_start;//
	private $mh;
	private $active;

	public function __construct()
	{
		$this->jobs = array();
		$this->mh = curl_multi_init();
		$this->has_start = false;
		$this->active = null;
	}
	public function __destruct()
	{
		curl_multi_close($this->mh);
	}

	public function getUrlContent()
	{
		$this->has_start = true;
		$mrc = $this->multiExec();

		echo "start get url content\n";
		//use select to get response
		//proceed select until all handle response
		//can refer php.net page, curl_multi_select() api
		//
	
		//do {  
		//        $mrc = curl_multi_exec($this->mh, $this->active);  
		//} while ($mrc == CURLM_CALL_MULTI_PERFORM);  
			
		//while ($this->active && $mrc == CURLM_OK) {
		//        //check for results and execute until everything is done

		//    if (curl_multi_select($this->mh) == -1) {
		//        //if it returns -1, wait a bit, but go forward anyways!
		//        usleep(100); 
		//    }

		//    //do something with the return values
		//    while(($info = curl_multi_info_read($this->mh)) !== false){
		//        $this->process($info);
		//        //if ($info["result"] == CURLE_OK){
		//        //    $content = curl_multi_getcontent($info["handle"]);
		//        //    do_something($content);
		//        //}
		//    }
		//    do {  
		//        $mrc = curl_multi_exec($this->mh, $this->active);  
		//    } while ($mrc == CURLM_CALL_MULTI_PERFORM);          
		//}


		while ($this->active && $mrc == CURLM_OK) 
		{
			while (curl_multi_exec($this->mh, $this->active) === CURLM_CALL_MULTI_PERFORM);
		   if (curl_multi_select($this->mh) != -1) 
		   {
			   do {
				   $mrc = curl_multi_exec($this->mh, $this->active);
				   if ($mrc == CURLM_OK)
				   {
					   while($info = curl_multi_info_read($this->mh))
					   {   
						   $this->process($info);
					   }    
				   }
			   } while ($mrc == CURLM_CALL_MULTI_PERFORM);
		   }
		}
		//if jobArray still has job objects when select is done,call job's jobDone() 
		foreach($this->jobs as $job)
		{
			$job->jobDone($this);
		}
	}

	//process handles
	//if error occur, report it
	//todo: error handling havn't completed
	private function multiExec()
	{
		do {
			$mrc = curl_multi_exec($this->mh, $this->active);
		} while ($mrc == CURLM_CALL_MULTI_PERFORM);

		foreach($this->jobs as $job)
		{
			foreach($job->urlArray as $url)
			{
				if (strlen(curl_error($url->hd)) !== 0)
				{
					$job->setError(curl_error($url->hd), $url);
					$job->onError();
					curl_multi_remove_handle($this->mh, $url->hd);
				}
			}
		}
		return $mrc;
	}

	//when a handle is available to process, process it
	//first find the handle object in jobs, then call job's urlDone function
	//if all urls of a job are completed, remove the job
	public function process($info)
	{
		echo "in process\n";
		$cjob = null;
		$jobkey = -1;
		$urlNum = -1;
		foreach($this->jobs as $key => &$job)
		{
			foreach($job->urlArray as $num => &$url)
			{
				//var_dump($info);
				//var_dump($url);
				if ($url->hd == $info['handle'])
				{
					$cjob = &$job;
					$jobkey = $key;
					$urlNum = $num;
					$url->processed = true;
					break 2;
				}
			}
		}

		if (is_null($cjob))
		{
			echo "handle could not be found.\n";
			//var_dump($info);
			return false;
		}
		if ($info['result'] != CURLE_OK)
		{
			$cjob->setError(array($info['result'], $info));
			$cjob->onError();
			return false;
		}
			
		if ($cjob->urlDone($urlNum, $this))
		{
			unset($this->jobs[$jobkey]);
		}	
		curl_multi_remove_handle($this->mh, $info['handle']);
	}

	//jobs: object or array contain objects
	public function addJobs($jobs)
	{

		if ($this->has_start)
		{
			if ($jobs instanceof CrawlJob)
			{ 
				$this->processJob($jobs);
				$this->jobs[] = $jobs;
			}else{
				foreach($jobs as &$job)
				{
					$this->processJob($job);
				}
				$this->jobs = array_merge($this->jobs, $jobs);
			}
			$this->multiExec();
		}else{
			if ($jobs instanceof CrawlJob)
			{ 
				$this->jobs[] = $jobs;
			}else{
				$this->jobs = array_merge($this->jobs, $jobs);
			}
		}

	}

	//after a job is added, process the job, get setting, init handler and set into multi_handler
	//$job: CrawlJob object
	private function processJob($job)
	{
		$setting = $job->getSetting();

		foreach($job->urlArray as &$url)
		{

			if ($url->processed) //pass processed job
				continue;

			$options = array(
				CURLOPT_AUTOREFERER => $setting['autoref'],
				CURLOPT_HEADER => false,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_USERAGENT => $setting['agent'],
				CURLOPT_CONNECTTIMEOUT => $setting['conn_timeout'],
				CURLOPT_TIMEOUT => $setting['resp_timeout']
			);

			if (!$setting['autoref'])
			{
				$options[CURLOPT_REFERER] = $setting['referer'];
			}

			if ($setting['useProxy'])
			{
				$options[CURLOPT_PROXYTYPE] = $setting['useProxy'];
				$options[CURLOPT_PROXY] = $setting['proxyAddr'];
				if (strlen($setting['proxyUsrPwd']))
				{
					$options[CURLOPT_PROXYUSERPWD] = $setting['proxyUsrPwd'];
				}
			}

			if (strcmp(strtoupper($url->method), 'GET' ) == 0)
			{
				$options[CURLOPT_URL] = $url->url;
			}else if (strcmp(strtoupper($url->method), 'POST') == 0)
			{
				$options[CURLOPT_URL] = $url->url;
				$options[CURLOPT_POST] = true;
				$options[CURLOPT_POSTFIELDS] = $url->data;
			}else{
				$job->setError(array(CRAWL_URL_ERROR=> $url));
				$job->onError();
				$url->processed = true;
				unset($url);
				continue;
			}

			echo "in crawler: $url->url \n";
			$hd = curl_init();
			curl_setopt_array($hd, $options);
			curl_multi_add_handle($this->mh,$hd);
			$url->hd = $hd;
		}
	}

	//jobs: CrawlJob object or array contain CrawlJob objects
	public function start($jobs = null)
	{
		if (!is_null($jobs))
		{
			if ($jobs instanceof CrawlJob)
			{ 
				$this->jobs[] = $jobs;
			}else{
				$this->jobs = array_merge($this->jobs, $jobs);
			}
		}

		if (count($this->jobs) == 0)
		{
			echo "empty job array\n";
			return false;
		}

		//add jobs
		foreach($this->jobs as &$job)
		{
			$this->processJob($job);
		}

		$this->getUrlContent();
		return true;
	}
}	

//Use this by extend it and complete process(), jobDone(), onError() three function
abstract class CrawlJob
{
	//array contain Url objects 
	public $urlArray; 
	protected $setting;
	protected $errors;
	protected $urlGetCount;//after a url object is completed, urlGetCount++
	protected $results;//array contain items that process() returns
	//$url:
	//     Url object or array('url' => '', 'method' => 'GET', 'data' => '')
	//     or Url object array
	//     or array of array contain 'url' key
	//$setting: array
	//         ('referer', 'agent', 'useProxy', 'proxyPort', 'proxyIp')
	public function __construct($url, $setting = array())
	{
		$this->urlArray = array();
		$this->processUrl($url);

		$setting = DefaultCrawlSetting::completeSetting($setting);
		$this->setting = $setting;
		$this->urlGetCount = 0;

		$this->results = array();
	}

	private function processUrl($url)
	{
		if ($url instanceof Url)
		{ 
			$this->urlArray[] = $url;
		}

		if (is_array($url))
		{
			if (array_key_exists('url', $url))
			{
				$obj = new Url($url['url']);
				foreach($url as $key => &$val)
				{
					$obj->$key = $val;
				}	
				$this->urlArray[] = $obj;
			}else{
				foreach($url as &$u)
				{
					if ($u instanceof Url)
						$this->urlArray[] = $u;
					else if (is_array($u) && array_key_exists('url', $u))
					{
						$obj = new Url($u['url']);
						foreach($u as $key => &$val)
						{
							$obj->$key = $val;
						}	
						$this->urlArray[] = $obj;
					}
				}
			}
		}
	}

	//when a url is completed, crawler will call urlDone()
	//$no: nth url object is complete
	//$crawler: Crawler object
	//return: bool
	//		true: all url object is done	
	//		false: not done
	public function urlDone($no, $crawler)
	{
		$this->urlGetCount++;

		$data = curl_multi_getcontent($this->urlArray[$no]->hd);
		$html = new simple_html_dom($data);

		//call job's process function
		$res = $this->process($html, $this->urlArray[$no], $crawler);
		$this->results[$no] = $res;
		//if all job is done, call jobDone()
		if ($this->urlGetCount === count($this->urlArray))
		{
			$this->jobDone($crawler);
			return true;
		}
		return false;
	}

	//add sub work to urlArray
	//if setting == null, then use current crawl setting
	protected function addSubWork($crawler, $url, $setting = null)
	{
		$this->processUrl($url);	
		$crawler->addJobs($this);
	}

	public function getSetting()
	{
		return $this->setting;
	}
	public function setError($err)
	{
		$this->errors = $err;
	}
	//$html: simple_html_dom object
	//$urlobj: Url object
	//$crawler: Crawler object
	//return processed data to handle in jobDone()
	//like:
	//$result = '123';
	//return $result;
	abstract public function process($html, $urlobj, $crawler);
	abstract public function onError();
	abstract public function jobDone($crawler);

}

class DefaultCrawlSetting
{
	private static $conn_timeout = 30;//sec
	private static $resp_timeout = 20;//sec
	private static $referer = '';
	private static $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_3) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.65 Safari/537.31';
	private static $useProxy = false;//default to false, otherwise should be CRAWL_PROXY_HTTP or CRAWL_PROXY_SOCKS5
	//proxy addr
	//192.168.0.1:8087
	private static $proxyAddr;
	private static $proxyUsrPwd = '';

	public static function completeSetting($setting)
	{
		if (is_array($setting))
		{
			$array = get_class_vars(get_class(new DefaultCrawlSetting));
			foreach($array as $key => $val)
			{
				if (!array_key_exists($key, $setting))
				{
					$setting[$key] = $val;
				}
			}
			if (strlen($setting['referer']) == 0)
			{
				$setting['autoref'] = true;
			}else{
				$setting['autoref'] = false;
			}
			//var_dump($setting);
		}
		return $setting;
	}

}

class Url
{
	public $url;
	public $method;
	//data could be string or array
	//ref: php curl_setopt CURLOPT_POSTFIELDS options
	public $data;
	public $hd;//curl handler
	public $processed;
	public function __construct($url, $method = 'GET', $data = null)
	{
		$this->url = $url;
		$this->method = $method;
		$this->data = $data;
		$this->hd = null;
		$this->processed = false;
	}
}
?>

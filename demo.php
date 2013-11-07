<?php
require_once('crawler.php');
class GeneralCrawler extends CrawlJob
{
	private $rssfilename;
	public function __construct($rule, $rssfile = 'software.rss', $setting = array())
	{
		$this->rssfilename = $rssfile;
		$url = $this->processRule($rule);
		parent::__construct($url, $setting);
	}

	private function processRule($rule)
	{
		$url = $rule['url'];
		if (is_array($url))
		{
			$url = new Url($url[count($url)-1]);
		}else{
			$url = new Url($rule['url']);
		}
		$url->rule = $rule;
		return $url;
	}


	private function getHost($url)
	{
		$tmp = null;

		$index = stripos($url, '/', 7);
		if ($index === false)
			return $url;
		else{
			return substr($url, 0, $index);
		}
	}
	//rule['result']:
	//				0: normal return
	//				1: redir
	//				2: 404
	public function process($html, $urlobj, $crawler)
	{
		echo curl_getinfo($urlobj->hd, CURLINFO_HTTP_CODE)."\n";

		$code = curl_getinfo($urlobj->hd, CURLINFO_HTTP_CODE)."\n";

		$rule = &$urlobj->rule;		
		switch(intval($code))
		{
		case 200:
			$items = $rule['items'];
			foreach($items as &$item)
			{
				$opstr = $item['operation'];
				$ops = preg_split('/\./', $opstr); 

				$doms = $html->find($item['dom']);
				print "dom: {$item['dom']} ".count($doms)."\n";
				if (strcmp($ops[0], 'property') == 0)//get property
				{
					print "property: $ops[1]\n";
					if (array_key_exists('autoRefer', $item) && (strcmp($item['autoRefer'], 'true') == 0 || $item['autoRefer'] == true))
					{
						$suburls = array();
						foreach($doms as $dom)
						{
							if (isset($dom->$ops[1]))//get some property
							{
								$property = $dom->$ops[1];
								if (preg_match('/^\//', $property))
								{
									$property = $this->getHost($urlobj->url).$property;
									echo "property: $property\n";
								}

								if (array_key_exists('rule', $item['rule']))
									$item['rule']['url'][] = $property;
								else{
									$item['rule']['url'] = array($property);
								}
									
								$suburls[] = $this->processRule($item['rule']);
								print "sub url $property\n";
							}
						}
						$this->addSubWork($crawler, $suburls);
					}else{
						foreach($doms as $dom)
						{
							if (isset($dom->$ops[1]))//get some property
							{
								$property = $dom->$ops[1];
								if (array_key_exists('value', $item)){
									$item['value'][] = $property;
								}else{
									$item['value'] = array($property);
								}
							}
						}
					}
				}
			}
			$rule['result'] = 0;
			break;
		case 302: //object removed
			$redir = $html->find('h2 a', 0);
			$rule['result'] = 1;
			$rule['value'] = $redir->href;
			break;
		case 404:
			$rule['result'] = 2;
			break;
		default:
			$rule['result'] = 3;
			$rule['value'] = $code;
		}

		$items = array();
		return $items;
	}
	public function jobDone($crawler)
	{
		echo " ?? all job done\n";

		/*
		//because of simplexml not supporting <xxx:xxx> tag, preprocess first
		$xmlstr = file_get_contents($this->rssfilename);
		$xmlstr = preg_replace('/<content:encoded>/', '<content_encoded>', $xmlstr);
		$xmlstr = preg_replace('/<\/content:encoded>/', '</content_encoded>', $xmlstr);
		$xmlstr = preg_replace('/<dc:creator>/', '<dc_creator>', $xmlstr);
		$xmlstr = preg_replace('/<\/dc:creator>/', '</dc_creator>', $xmlstr);
		$xmlstr = preg_replace('/<dc:creator\/>/', '<dc_creator/>', $xmlstr);
		$xml = simplexml_load_string($xmlstr, 'SimpleXMLExtended', LIBXML_NOCDATA);

		$items = $xml->xpath('/rss/channel/item');

		$newjobs = array();

		uasort($this->results, array('self', 'listCmp'));
		foreach($this->results as $k =>$result)
		{
			foreach($result as $key => $res)
			{
				if (strcmp($key, 'ref') == 0)
					continue;
				//echo 'url:  '.$res['url']."\n";
				$result[$key]['url'] = 'http://software.hit.edu.cn'.$res['url'];
				foreach($items as $item)
				{
					if (strcmp($item->link, $result[$key]['url']) == 0)
					{
						echo 'url:'.$result[$key]['url']."\n";
						unset($result[$key]);
						break;
					}
				}
			}
			$itemjob = new SoftwareItemJob($result, $this->rssfilename);
			$newjobs[] = $itemjob;
		}
		$crawler->addJobs($newjobs);
		 */
		//echo "add done\n";
	}
	public static function listCmp($a, $b)
	{
		return strcmp($a['ref'], $b['ref']);
	}
	public function onError()
	{
		echo "error occur\n";
		var_dump($this->errors);
	}
}





date_default_timezone_set('Asia/Shanghai');

$rulestr = '{"url":"http://software.hit.edu.cn/article/list.aspx", "title":"软件学院通知", "items":[{"dom":"ul.page_news_list li a", "autoRefer":"true", "operation":"property.href", "rule":{"items":[{"dom": "div.page_content", "operation":"property.innertext"}]}}]}';

$rule = json_decode($rulestr, true);
var_dump($rule);
$soft = new GeneralCrawler($rule);


$crawler = new Crawler;
$crawler->start($soft);
?>

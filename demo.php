<?php
require_once('crawler.php');

function simplexml_import_xml($parent, $xml, $before = false)
{
	$xml = (string)$xml;

	// check if there is something to add
	if ($nodata = !strlen($xml) or $parent[0] == NULL) {
		return $nodata;
	}

	// add the XML
	$node     = dom_import_simplexml($parent);
	$fragment = $node->ownerDocument->createDocumentFragment();
	$fragment->appendXML($xml);

	if ($before) {
		return (bool)$node->parentNode->insertBefore($fragment, $node);
	}

	return (bool)$node->appendChild($fragment);
}
/*
 *  insert SimpleXMLElement into SimpleXMLElement
 *
 *  * @param SimpleXMLElement $parent
 *   * @param SimpleXMLElement $child
 *    * @param bool $before
 *     * @return bool SimpleXMLElement added
 *      */
function simplexml_import_simplexml($parent, $child, $before = false)
{
	// check if there is something to add
	if ($child[0] == NULL) {
		return true;
	}

	// if it is a list of SimpleXMLElements default to the first one
	$child = $child[0];

	// insert attribute
	if ($child->xpath('.') != array($child)) {
		$parent[$child->getName()] = (string)$child;
		return true;
	}

	$xml = $child->asXML();

	// remove the XML declaration on document elements
	if ($child->xpath('/*') == array($child)) {
		$pos = strpos($xml, "\n");
		$xml = substr($xml, $pos + 1);
	}

	return simplexml_import_xml($parent, $xml, $before);
}

//extend SimpleXML support addCData
class SimpleXMLExtended extends SimpleXMLElement {
	public function addCData($cdata_text) {
		$node = dom_import_simplexml($this); 
		$no   = $node->ownerDocument; 
		$node->appendChild($no->createCDATASection($cdata_text)); 
	} 
}



class GeneralCrawler extends CrawlJob
{
	private $rssfilename;
	public function __construct($rule, $rssfile = 'software.rss', $setting = array())
	{
		$this->rssfilename = $rssfile;
		$url = $this->processRule($rule);
		parent::__construct($url, $setting);
	}

	private function processRule(&$rule)
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
		if (!array_key_exists('value', $urlobj->rule))
			$urlobj->rule['value'] = array();
		if (!array_key_exists('result', $urlobj->rule))
			$urlobj->rule['result'] = array();
		
		$num = 0;
		if (is_array($rule['url'])){
			foreach($rule['url'] as $n => $url){
				if (strcmp($url, $urlobj->url) == 0)
				{
					$num = $n;
					break;
				}
			}
		}
		//var_dump($rule);
		echo "num: $num $urlobj->url \n";
		switch(intval($code))
		{
		case 200:
			//$items = &$urlobj->rule['items'];
			foreach($urlobj->rule['items'] as &$item)
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
								}

								if (array_key_exists('url', $item['rule']))
									$item['rule']['url'][] = $property;
								else{
									$item['rule']['url'] = array($property);
								}
									
								$url = new Url($item['rule']['url'][count($item['rule']['url'])-1]);
								$url->rule = &$item['rule'];
								$suburls[] = $url;
							}
						}
						$this->addSubWork($crawler, $suburls);
					}else{
						foreach($doms as $dom)
						{
							if (isset($dom->$ops[1]))//get some property
							{
								$property = $dom->$ops[1];
								$item['value'][$num] = $property;
							}
						}
					}
				}
			}
			$urlobj->rule['result'][$num] = 0;
			break;
		case 302: //object removed
			$redir = $html->find('h2 a', 0);
			$urlobj->rule['result'][$num] = 1;
			$urlobj->rule['value'][$num] = $redir->href;
			break;
		case 404:
			$urlobj->rule['result'][$num] = 2;
			break;
		default:
			$urlobj->rule['result'] = 3;
			$urlobj->rule['value'] = $code;
		}

		$items = array();
		return $items;
	}
	public function jobDone($crawler)
	{
		echo " ?? all job done\n";
		var_dump($this->urlArray[0]);

		$xmlstr = file_get_contents("template.rss");
		$xmlstr = preg_replace('/<atom:link/', '<atomlink', $xmlstr);
		$xmlstr = preg_replace('/<\/atom:link>/', '</atomlink>', $xmlstr);
		$xmlstr = preg_replace('/<content:encoded>/', '<content_encoded>', $xmlstr);
		$xmlstr = preg_replace('/<\/content:encoded>/', '</content_encoded>', $xmlstr);
		$xmlstr = preg_replace('/<dc:creator>/', '<dc_creator>', $xmlstr);
		$xmlstr = preg_replace('/<\/dc:creator>/', '</dc_creator>', $xmlstr);
		$xmlstr = preg_replace('/<dc:creator\/>/', '<dc_creator/>', $xmlstr);
		$xml = simplexml_load_string($xmlstr, 'SimpleXMLExtended', LIBXML_NOCDATA);
		$channel = $xml->xpath('/rss/channel');
		$channel = $channel[0];
		$channel->lastBuildDate = date('r');
		$olditems = $channel->xpath('item');

		$rule = $this->urlArray[0]->rule;
		$channel->title = $rule['title'];
		$channel->link = $rule['url'];
		$channel->description = $rule['description'];
		$channel->atomlink->attributes()->href = $rule['url'];
		unset($channel->item);

		foreach($rule['items'] as $item)
		{
			//in list
			if (array_key_exists('autoRefer', $item) && strcmp($item['autoRefer'], 'true') == 0)
			{
				$ru = $item['rule'];
				foreach($ru['url'] as $num => $url)
				{
					$newnode = $channel->addChild('item');			
					$newnode->addChild('link', $url);
					$newnode->addChild('guid', $url);

					foreach($ru['items'] as $it)
					{
						$title = $newnode->addChild($it['as']);
						$title->addCData($it['value'][$num]);
					}
				}
			}else if (array_key_exists('value', $item))//has value
			{
				foreach($item['value'] as $value)
				{
					$newnode = $channel->addChild('item');			
					$title = $newnode->addChild('title');
					$title->addCData($item['dom']);
					//$newnode->title = '<![CDATA[ '.$result['title'].' ]]>';
					//$newnode->addChild(new SimpleXMLElement('<item><title></title></item>'));
					$newnode->addChild('link', $item['url']);
					$newnode->addChild('guid', $item['url']);

					$descnote = $newnode->addChild('description');
					$descnote->addCData($value);
					$content = $newnode->addChild('content_encoded');
					$content->addCData($value);
					$newnode->addChild('dc_creator', $result['admin']);
				}

			}
		}

		$xmlstr = $xml->asXML();
		$xmlstr = preg_replace('/<content_encoded>/', '<content:encoded>', $xmlstr);
		$xmlstr = preg_replace('/<\/content_encoded>/', '</content:encoded>', $xmlstr);
		$xmlstr = preg_replace('/<dc_creator>/', '<dc:creator>', $xmlstr);
		$xmlstr = preg_replace('/<\/dc_creator>/', '</dc:creator>', $xmlstr);
		$xmlstr = preg_replace('/<dc_creator\/>/', '<dc:creator/>', $xmlstr);
		file_put_contents($this->rssfilename, $xmlstr);
		echo "file: $this->rssfilename saved\n";	



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

$rulestr = '{"url":"http://software.hit.edu.cn/article/list.aspx", "title":"软件学院通知","description": "test",  "items":[{"dom": "div.grid_12 p", "operation": "property.innertext"},{"dom":"ul.page_news_list li a", "autoRefer":"true", "operation":"property.href", "rule":{"items":[{"dom": "h3.page_news_title", "operation":"property.innertext", "as": "title"},{"dom": "i.page_news_date", "operation": "property.innertext", "as": "description"}]}}]}';

$rule = json_decode($rulestr, true);
var_dump($rule);
$soft = new GeneralCrawler($rule);


$crawler = new Crawler;
$crawler->start($soft);
?>

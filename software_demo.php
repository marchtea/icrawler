<?php
require_once('crawler.php');


/**
	*  * Insert XML into a SimpleXMLElement
	*   *
	*    * @param SimpleXMLElement $parent
	*     * @param string $xml
	*      * @param bool $before
	*       * @return bool XML string added
	*        */
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


//can change this to anoymous function
//replace class 
//get list
class SoftwareListJob extends CrawlJob
{
	private $rssfilename;
	public function __construct($url, $rssfile = 'software.rss', $setting = array())
	{
		$this->rssfilename = $rssfile;
		parent::__construct($url, $setting);
	}
	public function process($html, $urlobj, $crawler)
	{
		echo curl_getinfo($urlobj->hd, CURLINFO_HTTP_CODE)."\n";
		$ul = $html->find('ul.page_news_list', 0);
		$lis = $ul->find('li');
		$items = array();
		foreach($lis as $li)
		{
			$item = array();
			$href = $li->find('a', 0);
			$item['url'] = $href->href;
			$item['title'] = $href->innertext;
			$item['time'] = $li->find('span', 0)->innertext;
			$items[] = $item;
		}
		$items['ref'] = $urlobj->url;
		//echo $html;
		return $items;
	}
	public function jobDone($crawler)
	{
		echo "all job done\n";

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
		echo "add done\n";
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

//process each item
class SoftwareItemJob extends CrawlJob
{
	private $rssfilename;
	public function __construct($url, $rssfile, $setting = array())
	{
		$this->rssfilename = $rssfile;
		parent::__construct($url, $setting);
	}
	public function process($html, $urlobj, $crawler)
	{
		$code = curl_getinfo($urlobj->hd, CURLINFO_HTTP_CODE)."\n";
		$item = array();
		switch(intval($code))
		{
		case 200: //normal return code
			$title = $html->find('h3.page_news_title', 0);
			if (!is_null($title))
				$item['title'] = $title->innertext;
			$newdate = $html->find('i.page_news_date', 0);
			if (!is_null($newdate))
			{
				$data = preg_split('/&nbsp;&nbsp;/', $newdate->innertext, -1, PREG_SPLIT_NO_EMPTY);
				$item['time'] = date('r', strtotime($data[0]));
				$srstr = preg_split('/：/', trim($data[1]));
				$item['source'] = trim($srstr[1]);
				$adstr = preg_split('/：/', $data[2]);
				$item['admin'] = trim($adstr[1]);
			}
			$content = $html->find('div.page_content', 0);
			$item['content'] = $content->innertext;
			break;
		case 302://object removed
			$redir = $html->find('h2 a', 0);
			$item['redir'] = $redir->href;
			$item['title'] = $urlobj->title;
			preg_match('/\(([^\).]+)\)/', $urlobj->time, $timearray);
			//var_dump($timearray);
			$item['time'] = date('r', strtotime(trim($timearray[1])));
			break;
		default:
			echo "unknown return code $code\n";
			break;
		}
		$item['url'] = $urlobj->url;

		return $item;
	}
	public function jobDone($crawler)
	{
		echo "done count: ".$this->urlGetCount."\n";
		if ($this->urlGetCount)//updated
		{
			$xmlstr = file_get_contents($this->rssfilename);
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
			$items = array();                                                      
			foreach($olditems as $item)
			{
				$items[] = clone $item;
			}
			unset($channel->item);

			uasort($this->results, array('self', 'itemCmp'));
			
			foreach($this->results as $result)
			{
				$newnode = $channel->addChild('item');			
				$title = $newnode->addChild('title');
				$title->addCData($result['title']);
				//$newnode->title = '<![CDATA[ '.$result['title'].' ]]>';
				//$newnode->addChild(new SimpleXMLElement('<item><title></title></item>'));
				$newnode->addChild('link', $result['url']);
				$newnode->addChild('pubDate', $result['time']);
				$newnode->addChild('guid', $result['url']);
				if (array_key_exists('redir', $result))
				{
					$newnode->addChild('description', 'refer to :'.$result['redir']);
					$newnode->addChild('content_encoded', 'refer to <a href="'.$result['redir'].'">'.$result['redir'].'</a>');
				}else{
					$desc = mb_substr(strip_tags($result['content']), 0, 501, 'UTF-8');
					$desc .= ' <span class="ellipsis">&#8230;</span> <span class="more-link-wrap"><a href="'.$result['url'].'" class="more-link"><span>Read More</span></a></span>';
					$descnote = $newnode->addChild('description');
					$descnote->addCData($desc);
					$content = $newnode->addChild('content_encoded');
					$content->addCData($result['content']);
					$newnode->addChild('dc_creator', $result['admin']);
				}
			}

			echo "copy old items\n";
			for($i = 0;$i < 20-$this->urlGetCount && $i < count($items);$i++)
			{
				if ($items[$i]->count())//item has child,which means has content 
				{
					simplexml_import_simplexml($channel, $items[$i]);
				}
			}
			$xmlstr = $xml->asXML();
			$xmlstr = preg_replace('/<content_encoded>/', '<content:encoded>', $xmlstr);
			$xmlstr = preg_replace('/<\/content_encoded>/', '</content:encoded>', $xmlstr);
			$xmlstr = preg_replace('/<dc_creator>/', '<dc:creator>', $xmlstr);
			$xmlstr = preg_replace('/<\/dc_creator>/', '</dc:creator>', $xmlstr);
			$xmlstr = preg_replace('/<dc_creator\/>/', '<dc:creator/>', $xmlstr);
			//file_put_contents($this->rssfilename, $xmlstr);
		echo "file: $this->rssfilename saved\n";	

		}
		echo "all job done\n";

	}
	public static function itemCmp($a, $b)
	{
		return strtotime($a['time']) < strtotime($b['time']);
	}
	public function onError()
	{
		echo "error occur\n";
		var_dump($this->errors);
	}
}


date_default_timezone_set('Asia/Shanghai');

$soft = new SoftwareListJob(
	array(
		new Url('http://software.hit.edu.cn/article/0/1.aspx'),
		new Url('http://software.hit.edu.cn/article/0/2.aspx')
	));

$crawler = new Crawler;
$crawler->start($soft);


?>

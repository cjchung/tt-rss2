<?php
use \andreskrey\Readability\Readability;
use \andreskrey\Readability\Configuration;

class toutiao extends Plugin{

	private static ?DateTimeZone $TZ=null;

	function __construct() {
		parent::__construct();
		if(!self::$TZ){
			self::$TZ = new DateTimeZone('Asia/Shanghai');
		}

	}

	function init($host) {
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
	}

	/**
	 * @inheritDoc
	 */
	function about() {
		return array(1.0, // version
			'toutiao', // description
			'jason', // author
			false, // is_system
		);
	}

	function api_version(): int {
		return 2;
	}



	public function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed) {
		if (strpos($fetch_url,'toutiao')===false) {
			return $feed_data;
		}

		$force_rehash = isset($_REQUEST["force_rehash"]);

		$doc = new DOMDocument();
		$sth_guid = $this->pdo->prepare("select 1 from  ttrss_entries where guid = ?");
		$sth_author_title = $this->pdo->prepare("select 1 from  ttrss_entries where author= ? and title= ?");

		$doc->loadHTML('<?xml encoding="utf-8" ?>'.$feed_data);
		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title/text())');
		if(!$rssTitleText){
			Debug::log('not html type');
			return $feed_data;
		}

		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';

		$itemNodes = $xpath->query('//div[@class="post"]');
		$counter=0;
		foreach ($itemNodes as $itemNode) {
			$linkNode=$xpath->query('.//h3/a', $itemNode)[0];
			$link=$linkNode->getAttribute('href');
			if(substr($link,0,4)!=='http'){
				$link = 'https://toutiao.io'.$link;
			}
			if(strpos($link,'https://toutiao.io/shares')===0){
				//subscriptions
				$link=$linkNode->parentNode->parentNode->getAttribute('data-url');
			}
			if(strpos($link,'https://toutiao.io/posts/')===0){
				//subscriptions
				$link='https://toutiao.io/k/'.substr($link,25);
			}
			$entry_guid = $link;
			$entry_guid_hashed = json_encode(["ver" => 2, "uid" => $owner_uid, "hash" => 'SHA1:' . sha1($entry_guid)]);
			Debug::log("link=$link",Debug::LOG_VERBOSE);

			$title=$linkNode->nodeValue;
			$author=$xpath->query('.//div[@class="subject-name"]/a', $itemNode)[0]->nodeValue;
			if(!$force_rehash){
				$sth_guid->execute([$entry_guid_hashed]);
				if ($row = $sth_guid->fetch()) {
					Debug::log("SKIP existing record...$entry_guid_hashed", Debug::LOG_VERBOSE);
					continue;
				}
				$sth_author_title->execute([$author, $title]);
				if ($row = $sth_author_title->fetch()) {
					Debug::log("SKIP existing record...$title [$author]",Debug::LOG_VERBOSE);
					continue;
				}
			}
			$meta=trim($xpath->evaluate('string(.//div[@class="meta"]/text())', $itemNode));
			$article=['link' => $link, 'author' => $author, 'title' => $title, 'meta'=>$meta];
			Debug::log("link: $link",Debug::LOG_VERBOSE);
			Debug::log("title: $title",Debug::LOG_VERBOSE);
			Debug::log("meta: $meta",Debug::LOG_VERBOSE);
//			$likes=(int)$xpath->evaluate('string(.//a[contains(@href,"/likes/post/")]/span)', $itemNode);
//			$likes=$xpath->evaluate('./div[0]/div[0]/a[0]/span[0]', $itemNode)[0]->nodeValue;
//			Debug::log("likes: $likes",Debug::LOG_VERBOSE);
//			Debug::log("dddddddddddd:".$doc->saveHTML($likes),Debug::LOG_VERBOSE);

//			if($likes&&$likes->length)$likes=$likes->item(0)->nodeValue;
//			$replies=(int)$xpath->evaluate('string(.//div[@class="meta"]/span)', $itemNode);
			$tmpHtml=$doc->saveHTML($itemNode);
			$likes=0;
			$replies=0;
			if(preg_match('/<span>(\d+)<\/span>/',$tmpHtml, $m)){
				$likes=$m[1];
			}
			if(preg_match('/i> (\d+)\s+<\/span>/',$tmpHtml, $m)){
				$replies=$m[1];
			}
			Debug::log("likes: $likes",Debug::LOG_VERBOSE);
			Debug::log("replies: $replies",Debug::LOG_VERBOSE);
			$likes += $replies * 20;
			Debug::log("comments(score): $likes",Debug::LOG_VERBOSE);
			if(!$this->parse_page($article)){
				continue;
			}

			$rss .= "<item><title>" . htmlspecialchars($article['title']) .
				"</title><link>" . htmlspecialchars($article['link']) .
				"</link><guid>".htmlspecialchars($entry_guid)."</guid><author>" . htmlspecialchars($article['author']) .
				"</author><description>".htmlspecialchars($article['content'])."</description>";

			if($likes)$rss .= "<slash:comments>$likes</slash:comments>";
			if(key_exists('tags', $article)){
				foreach ($article['tags'] as $tag){
					$rss .= "<category>$tag</category>";
				}
			}
			if(key_exists('pubDate', $article)){
				$rss .= "<pubDate>".$article['pubDate']."</pubDate>";
			}

			$rss .= "</item>";
			if($counter++>=10) break;

		}
		$rss .= "</channel></rss>";
		return $rss;

	}

	function hook_article_filter($article) {
		if (strpos($article['link'],'toutiao')===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}
		return $article;
	}


	protected function  parse_page(&$article){
		$html=UrlHelper::fetch(["url" => $article['link'],'followlocation'=>true]);
		if(!$html) return false;
		$fetch_effective_url = UrlHelper::$fetch_effective_url;
		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
		$xpath = new DOMXPath($doc);
		if(strpos($article['meta'],'weixin') || strpos($fetch_effective_url,'weixin.qq')){
			if(!strpos($fetch_effective_url,'weixin.qq')){
				Debug::log("not redirected to weixin");
				$link=$xpath->evaluate('string(//a[small[text()="(查看原文)"]]/@href)');
				if($link){
					if(strpos($link,'/k/')===0){
						//subscriptions
						$link='https://toutiao.io'.$link;
					}

					Debug::log("redirect link found: $link",Debug::LOG_EXTENDED);
					$html=UrlHelper::fetch(["url" => $link,'followlocation'=>true]);
					Debug::log("fetch_effective_url = ".UrlHelper::$fetch_effective_url,Debug::LOG_EXTENDED);
				}else{
					Debug::log("redirect link for weixin not exist.");
					return false;
				}
			}
			$contentNode = $xpath->query('//div[contains(@class,"rich_media_content")]')[0];
			foreach ($xpath->query('.//img', $contentNode) as $img){
				$img->setAttribute('src', $img->getAttribute('data-src'));
			}
			if(preg_match('/var o="\d+",n="\d+",t="(\d+-\d+-\d+ \d+:\d+)";/',$html,$m)){
				$article['pubDate'] = date(DATE_RFC2822,(DateTime::createFromFormat('Y-m-d G:i',$m[1],self::$TZ))->getTimestamp());
			}
			foreach ($xpath->query('(//*[@class="article-tag__item"]|//*[@id="copyright_logo"])') as $tag){
				$t=$tag->nodeValue;
				if($t[0]=='#')$t=substr($t,1);
				$article['tags'][]=$t;
			}
			$article['content']=$doc->saveHTML($contentNode);
		}elseif(strpos($article['meta'],'toutiao')!==false){
			$contentNode = $xpath->query('//div[contains(@class,"post-body")]')[0];
			$article['content']=$doc->saveHTML($contentNode);
			foreach ($xpath->query('//div[@class="post-tags"]/a') as $tag){
				$article['tags'][]=$tag->nodeValue;
			}
		}else{
			$article['content']='';
			Debug::log("empty content",Debug::LOG_EXTENDED);
		}
		return true;
	}

}



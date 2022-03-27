<?php
use \andreskrey\Readability\Readability;
use \andreskrey\Readability\Configuration;

class toutiao extends Plugin{
	private PluginHost $host;

	private static ?DateTimeZone $TZ=null;

	function __construct() {
		parent::__construct();
		if(!self::$TZ){
			self::$TZ = new DateTimeZone('Asia/Shanghai');
		}

	}

	function init($host) {
		$this->host = $host;

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
		$sth_guid = $this->pdo->prepare("select * from  ttrss_entries where guid = ?");
//		$sth_author_title = $this->pdo->prepare("select * from  ttrss_entries where author= ? and title= ?");

		$doc->loadHTML('<?xml encoding="utf-8" ?>'.$feed_data);
		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title/text())');
		if(!$rssTitleText){
			Debug::log('not html type');
			return $feed_data;
		}

		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';

		//直接先換頁，才不會一直卡在出錯的地方
		$nextLink=false;
		$nextLinkNode = $xpath->query('(//a[@rel="prev"]/@href)[1]');
		if($nextLinkNode->length && $nextLinkNode[0]->value){
			$nextLink='https://toutiao.io' . $nextLinkNode[0]->value;
		}else{
			$nextLinkNode = $xpath->query('(//a[contains(text(),"末页")]/@href)[1]');
			if($nextLinkNode->length && $nextLinkNode[0]->value){
				$nextLink='https://toutiao.io' . $nextLinkNode[0]->value;
			}
		}
		if($nextLink && !extension_loaded('xdebug')){
			$sth_update_feedUrl = $this->pdo->prepare("update ttrss_feeds set feed_url= ? where id=?");
			$sth_update_feedUrl->execute([$nextLink, $feed]);
		}


		$itemNodes = $xpath->query('//div[@class="post"]');
		$newArticleCount=0;
		foreach ($itemNodes as $itemNode) {
			$oldArticle=false;
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

			$title=$linkNode->nodeValue;

			$obj=$xpath->query('.//div[@class="subject-name"]/a', $itemNode)[0];
			// 如果是作者主頁 author欄位會是空的
			$author=is_object($obj)?$obj->nodeValue:'';
			$sth_guid->execute([$entry_guid_hashed]);
			if ($row = $sth_guid->fetch()) {
				$oldArticle=true;
			}
			$meta=trim($xpath->evaluate('string(.//div[@class="meta"]/text())', $itemNode));
			$article=['link' => $link, 'author' => $author, 'title' => $title, 'meta'=>$meta];
			Debug::log("link: $link",Debug::LOG_VERBOSE);
			Debug::log("title: $title",Debug::LOG_VERBOSE);
			Debug::log("meta: $meta",Debug::LOG_VERBOSE);

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

			if($oldArticle && $likes==$row['num_comments']){
				Debug::log("SKIP existing record [num_comments]",Debug::LOG_VERBOSE);
				continue;
			}
			$this->parse_page($article);
			$rss .= "<item><title>" . htmlspecialchars($article['title']) .
				"</title><link>" . htmlspecialchars($article['link']) .
				"</link><guid>".htmlspecialchars($entry_guid)."</guid><author>" . htmlspecialchars($article['author']) .
				"</author><description>".htmlspecialchars($article['content']?:'')."</description>";

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
			if(!$oldArticle) ++$newArticleCount;
//			if((!extension_loaded('xdebug'))&&$newArticleCount>=5) break;
		}
		$rss .= "</channel></rss>";
		return $rss;
	}

	function hook_article_filter($article) {
		if (strpos($article['guid'],'toutiao')===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}
		if(!$article['content']){
			$this->host->run_hooks_callback(PluginHost::HOOK_GET_FULL_TEXT,
				function ($result) use (&$article) {
					if ($result) {
						$article["content"]  = $result;
						return true;
					}
				},
				$article['link']);

		}
		return $article;
	}


	protected function  parse_page(&$article,$c=0){
		if($c>3) return true;
		$link=$article['link'];
		$html=UrlHelperExt::fetch_cached(["url" => $link,'followlocation'=>'mp.weixin.qq.com'==$article['meta']]);

		if(!$html) {
			if(UrlHelper::$fetch_last_error_code>300 && UrlHelper::$fetch_last_error_code<400){
				$article['link']=UrlHelper::$fetch_effective_url;
				//非 頭條 微信 文章 ,不處理
				return false;
			}
		}
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
					$html=UrlHelperExt::fetch_cached(["url" => $link,'followlocation'=>true]);
					Debug::log("fetch_effective_url = ".UrlHelper::$fetch_effective_url,Debug::LOG_EXTENDED);
				}else{
					Debug::log("redirect link for weixin not exist.");
					return false;
				}
			}
			// TODO: 微信文章已搬移時還需處裡
			if(strpos($html,'该公众号已迁移')){
				if(preg_match("/transferTargetLink = '(.+?)'/",$html,$m)){
					$article['link']=$m[1];
					return $this->parse_page($article,++$c);
				}
			}
			$contentNode = $xpath->query('//div[contains(@class,"rich_media_content")]')[0];
			foreach ($xpath->query('.//img', $contentNode) as $img){
				$img->setAttribute('src', 'https://imageproxy.pimg.tw/resize?url='.$img->getAttribute('data-src'));
			}
			if(preg_match('/="(\d{4}-\d{2}-\d{2} \d{2}:\d{2})";/',$html,$m)){
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
		$article['link']=UrlHelper::$fetch_effective_url;
		return true;
	}

}



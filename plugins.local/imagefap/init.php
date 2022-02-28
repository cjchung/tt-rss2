<?php


class imagefap extends Plugin{

//	private static ?DateTimeZone $TZ=null;
//
//	function __construct() {
//		parent::__construct();
//		if(!self::$TZ){
//			self::$TZ = new DateTimeZone('Asia/Shanghai');
//		}
//
//	}

	function init($host) {
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
	}

	/**
	 * @inheritDoc
	 */
	function about() {
		return array(1.0, // version
			__Class__, // description
			'jason', // author
			false, // is_system
		);
	}

	function api_version(): int {
		return 2;
	}

//	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass): string {
//	}

	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		if (strpos($fetch_url,'imagefap')===false) {
			return $feed_data;
		}

		$force_rehash = isset($_REQUEST["force_rehash"]);

		$argv = $_SERVER['argv'];
		if($argv){
			$key = array_search('--page', $argv);
			if($key){
				$page=$argv[$key+1];
				if($page){
					$fetch_url=preg_replace('/&page=\d+/',"&page=$page", $fetch_url);
					Debug::log('change page no='.$page);
				}
			}
		}

		$sth_guid = $this->pdo->prepare("select 1 from  ttrss_entries where guid = ? and date_updated > DATE_SUB(NOW(), INTERVAL 1 DAY)");
//		$sth_author_title = $this->pdo->prepare("select 1 from  ttrss_entries where author= ? and title= ?");
		$feed_data=UrlHelper::fetch(["url" => $fetch_url,'followlocation'=>true]);

		$doc = new DOMDocument();
		$doc->loadHTML('<?xml encoding="utf-8" ?>'.$feed_data);
		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title/text())');
		if(!$rssTitleText){
			Debug::log('not html type');
			return $feed_data;
		}

		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';

		$itemNodes = $xpath->query('//div[contains(@class,"gallerylist")]/table/tr');
		$counter=0;
		for($i=1;$i<$itemNodes->length;$i++){
			$tr=$itemNodes[$i];
			$a=$xpath->query('.//a[contains(@href,"/gallery.php")]', $tr)[0];
			$title=$a->nodeValue;
			Debug::log("title: $title", Debug::LOG_VERBOSE);
			$link='https://www.imagefap.com'.$a->getAttribute('href');
			$gid=substr($link,41);
			$tds=$a->parentNode->parentNode->getElementsByTagName('td');
			$imagesCount=trim($tds[1]->nodeValue, " \t\n\r\0\x0B\xC2\xA0");
			$imageSize=$tds[2]->getElementsByTagName('center')[0]->getElementsByTagName('img')[0]->getAttribute('src');
			if(preg_match('/\/img\/(.*)_img\.gif/', $imageSize, $m)){
				$imageSize = $m[1];
			}
			$pubDate=trim($tds[3]->nodeValue, " \t\n\r\0\x0B\xC2\xA0");

			$i++;
			if($imagesCount && $imagesCount < 10){
				Debug::log("SKIP imagesCount<10, $imagesCount", Debug::LOG_VERBOSE);
				continue;
			}
			if(strpos($pubDate, ':')!==false){
				Debug::log("SKIP pubDate: $pubDate", Debug::LOG_VERBOSE);
				continue;
			}
			$pubDate=$pubDate.'T00:00:00+08:00';

			if($imageSize && (strpos($imageSize, 'ge')===false)){
				Debug::log("SKIP, not large or huge", Debug::LOG_VERBOSE);
				continue;
			}
			$tr=$itemNodes[$i];
			$fans=$xpath->query('.//div[@class="subscribers"]', $tr)[0]->nodeValue;
			$fans=(int)substr($fans,1);
			if($fans < 200){
				Debug::log("SKIP fans: $fans", Debug::LOG_VERBOSE);
				continue;
			}
			$author=$xpath->query('.//div[@class="avatar"]/a', $tr)[0]->nodeValue;

			$entry_guid = 'imagefap:'.$gid;
			$entry_guid_hashed = json_encode(["ver" => 2, "uid" => $owner_uid, "hash" => 'SHA1:' . sha1($entry_guid)]);
			if(!$force_rehash){
				$sth_guid->execute([$entry_guid_hashed]);
				if ($row = $sth_guid->fetch()) {
					Debug::log("SKIP existing record...$entry_guid_hashed", Debug::LOG_VERBOSE);
					continue;
				}
			}

			$html=UrlHelper::fetch(["url" => $link.'&view=2','followlocation'=>true]);
			$docGalleries = new DOMDocument();
			$docGalleries->preserveWhiteSpace=false;
			$docGalleries->formatOutput=false;
			$docGalleries->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOBLANKS);
			$xpathGalleries = new DOMXPath($docGalleries);
			$anchors=$xpathGalleries->query('//a[@name]');
			$content='<div>';
			foreach ($anchors as $anchor){
				$anchor->removeAttribute('name');
				$img=$anchor->getElementsByTagName('img')[0];
				$img->removeAttribute('style');
				$img->removeAttribute('width');
				$img->removeAttribute('height');
				$img->removeAttribute('alt');
				$content .= $docGalleries->saveHTML($anchor);
			}
			$content.='</div>';
			$content=preg_replace('/>\s+</','><', $content);
			$title = "[".strtoupper($imageSize)."]$title [$imagesCount]";
			$rss .= "<item><title>" . htmlspecialchars($title) .
				"</title><link>" . htmlspecialchars($link) .
				"</link><pubDate>$pubDate</pubDate><guid>".htmlspecialchars($entry_guid)."</guid><author>" . htmlspecialchars($author) .
				"</author><description>".htmlspecialchars($content)."</description>";

			if(preg_match('/\((\d+) votes\)/', $html, $m)){
				$rss .= '<slash:comments>'.$m[1].'</slash:comments>';
			}

//			if($likes)$rss .= "<slash:comments>$likes</slash:comments>";
			$tags=$xpathGalleries->query('.//div[@id="cnt_cats" or @id="cnt_tags"]/a');
			foreach ($tags as $tag){
				$rss .= '<category>'.htmlspecialchars($tag->nodeValue).'</category>';
			}
//			if(key_exists('tags', $article)){
//				foreach ($article['tags'] as $tag){
//					$rss .= "<category>$tag</category>";
//				}
//			}
//			if(key_exists('pubDate', $article)){
//				$rss .= "<pubDate>".$article['pubDate']."</pubDate>";
//			}

			$rss .= "</item>";
//			if($counter++>=2) break;

		}
		$rss .= "</channel></rss>";
		return $rss;

	}

	function hook_article_filter($article) {
		if (strpos($article['link'],'imagefap')===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}
		return $article;
	}


	protected function  gallery_content($link){
//		$html=UrlHelper::fetch(["url" => $link.'&view=2','followlocation'=>true]);
//		$docGalleries = new DOMDocument();
//		$docGalleries->preserveWhiteSpace=false;
//		$docGalleries->formatOutput=false;
/*		$docGalleries->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOBLANKS);*/
//		$xpathGalleries = new DOMXPath($docGalleries);
//		$anchors=$xpathGalleries->query('//a[@name]');
//		$content='<div>';
//		foreach ($anchors as $anchor){
//			$content .= $docGalleries->saveHTML($anchor);
//		}
//		$content.='</div>';
////		$content= preg_replace('/>\s+</','><', $content);
//		return $content;
	}

}



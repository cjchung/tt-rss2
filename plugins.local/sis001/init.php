<?php

class sis001 extends Plugin {
//	private $host;
//	protected static $target_domain = 'sis001.com/';

	private $curr_fid=0;
	private $forumId=0;

	function init($host) {
//		$host->add_hook ( $host::HOOK_FEED_PARSED, $this );
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);


	}
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


	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass): string {
		if (strpos($fetch_url,'sis001')===false) {
			return $feed_data;
		}

		$force_rehash = isset($_REQUEST["force_rehash"]);

		$argv = $_SERVER['argv'];
		if($argv){
			$key = array_search('--page', $argv);
			if($key){
				$page=$argv[$key+1];
				if($page){
					$fetch_url=preg_replace('/-\d+\.html/',"-$page.html", $fetch_url);
					Debug::log('change page no='.$page);
				}
			}
		}

		$sth_guid = $this->pdo->prepare("select content from  ttrss_entries where guid = ?");

		$feed_data=UrlHelper::fetch(["url" => $fetch_url,'followlocation'=>true]);

		$i = strpos($feed_data, '<title>');
		if(!$i){
			Debug::log("feed_data error ****:" .$feed_data);
			return "";
		}else{
			$i += 7;
		}
		$j = strpos($feed_data, '</title>', $i);
		$rssTitleText = substr($feed_data, $i, $j - $i - 11);

		$i = strpos($feed_data, '<body');
		$j = strpos($feed_data, '</body>');
		$feed_data = substr($feed_data, $i, $j - $i);


		$doc = new DOMDocument();

		$feed_data = '<?xml encoding="utf8">' . $feed_data;
		@$doc->loadHTML($feed_data);

		$xpath = new DOMXPath($doc);
		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';


		$tbodies = $xpath->query('//tbody[starts-with(@id, \'normalthread_\') or starts-with(@id, \'stickthread_\')]');
		Debug::log("found tr row ****:" . $tbodies->length);

		$itemCount = 0;

		if (preg_match('/forum-([0-9]+)-/', $fetch_url, $m)) {
			$this->forumId = $m[1];
		}

		$rss_items=array();
		foreach ($tbodies as $tbody) {
			$thread_id=0;
			$a=$xpath->query('.//a[starts-with(@href, \'thread-\')]',$tbody)[1];
			if(preg_match("/thread-([0-9]*)-/", $a->getAttribute('href'), $m)){
				$thread_id = $m[1];
			}
			if(!$thread_id){
				continue;
			}
			$entry_guid = "sis001:$thread_id";
			$entry_guid_hashed = json_encode(["ver" => 2, "uid" => $owner_uid, "hash" => 'SHA1:' . sha1($entry_guid)]);
			$content=false;
			if(!$force_rehash){
				$sth_guid->execute([$entry_guid_hashed]);
				if ($row = $sth_guid->fetch()) {
					Debug::log("SKIP existing record...$entry_guid", Debug::LOG_VERBOSE);
//					$content=$row['content'];
					continue;
				}
			}



			$url = "http://www.sis001.com/forum/thread-$thread_id-1-1.html" ;
			if(!$content){
				$content=UrlHelper::fetch(["url" => $url,'followlocation'=>true]);
				$doc2 = new DOMDocument();
				$doc2->loadHTML('<?xml encoding="utf8">' . $content);
				$xpath2 = new DOMXPath($doc2);
				$post = $xpath2->query('//div[@class="postmessage defaultpost"][1]/div[@class="t_msgfont"]/div')[0];
//				$post = $xpath2->query('//div[contains(@class,"defaultpost")]')[0];
				if($post){
					$tb=$post->getElementsByTagName('table')[0];
//					$font=$post->getElementsByTagName('font')[0];
					try {
						if ($tb) $post->removeChild($tb);
//						if ($font) $post->removeChild($font);
					} catch (Exception $e) {
					}
					$content=$doc2->saveHTML($post);
					$content= preg_replace('/br>\r\n/','br>', $content);
					$content= preg_replace('/>\s+</','><', $content);

				}
			}
			$title = $a->nodeValue;

			$post_type=null;
			$a=$xpath->query('.//a[starts-with(@href, \'forumdisplay.php?fid\')]',$tbody)[0];
			if($a){
				$post_type=$a->nodeValue;
				if($post_type!=='------') {
					$title = "[$post_type]".$title;
				}
			}

			$a=$xpath->query('.//td[@class="author"]/cite/a',$tbody)[0];
			$author = $a->nodeValue;

			$cite=$xpath->query('.//td[@class="author"]/cite',$tbody)[0];
			$cite=trim($cite->nodeValue);

			$thumbsUp=substr($cite,strlen($author));
			if($thumbsUp){
				$title .= str_repeat('👍', intdiv($thumbsUp,10));
			}
			$replies=$xpath->query('.//td[@class="nums"]/strong',$tbody)[0]->nodeValue;
			$replies += $thumbsUp;
			$a=$xpath->query('.//td[@class="author"]/em',$tbody)[0];
			$pubDate = date('r', strtotime($a->nodeValue)-28800);


//			$tags[] = [];
			$tags=null;
			$gifs = $xpath->query('.//img', $tbody);
			foreach ($gifs as $gif) {
				$src = $gif->getAttribute('alt');
				if ($src == "recommend") {
					$tags[] = '推薦';
					$title .= '[👍]';
				} elseif ($src == "agree") {
					$tags[] = '加分';
					$title .= '[👍]';
				} elseif ($src == "精华 1") {
					$tags[] = '精華';
					$title .= '[💎]';
				} elseif ($src == "heatlevel") {
					$tags[] = '熱門';
					$title .= '[🔥]';
				}
			}


			$rss_item =
				"<item><title>" . htmlspecialchars($title) .
				"</title><guid>".htmlspecialchars($entry_guid)."</guid><link>" . htmlspecialchars($url) .
				"</link><author>" . htmlspecialchars($author) .
				"</author><description>".htmlspecialchars($content)."</description><pubDate>$pubDate</pubDate>";

			if($tags)foreach ($tags as $tag){
				$rss_item = $rss_item ."<category>$tag</category>";
			}
//			$tags=twbbs::fix_twbbs_tag($title);
//			if($tags)foreach ($tags as $tag){
//				$rss_item = $rss_item ."<category>$tag</category>";
//			}


			if($post_type)$rss_item = $rss_item ."<category>$post_type</category>";
			if(strpos($title,'合集')!==false) {
				$rss_item = $rss_item ."<category>合集</category>";
			}

			$rss_item .= "<slash:comments>$replies</slash:comments>";
			$rss_item = $rss_item . "</item>";
			$itemCount++;
			$rss_items[$thread_id]=$rss_item;
		}
		ksort($rss_items);
		foreach ($rss_items as $v){
			$rss = $rss . $v;
		}
		$rss = $rss . "</channel></rss>";

		return $rss;
	}

	function hook_article_filter($article) {
		if (strpos($article['link'],'sis001.com')===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}
		return $article;
	}

//	protected function hook_feed_basic_info_filtered($basic_info, $fetch_url, $owner_uid, $feed, $auth_login, $auth_pass) {
//		$contents = $this->fetch_file_contents([
//			"url" => $fetch_url
//		]);
//
//		$i = strpos($contents, '<title>', 0) + 7;
//		$j = strpos($contents, '</title>', $i);
//		$rssTitleText = substr($contents, $i, $j - $i - 11);
//
//		$basic_info['title'] = $rssTitleText;
//		$basic_info['site_url'] = $fetch_url;
//		return $basic_info;
//	}

//	protected function hook_article_filter($article) {
//		$debug_enabled = defined('DAEMON_EXTENDED_DEBUG') || $_REQUEST['xdebug'];
//
//		$url= $article['link'];
//		$thread_id=null;
//		if(preg_match("/tid=([0-9]*)/", $url, $m)){
//			$thread_id = $m[1];
//		}
//
//		$retryCount = 3;
//		$contents = '<div>Initial content</div>';
//		while ($retryCount--) {
//			$contents = $this->fetch_file_contents([
//				"url" => $url
//			]);
//
//			if (strpos($contents, $article['author'])) break;
////			_debug("ERROR CONTENT ****:" . htmlspecialchars($contents, ENT_IGNORE), true);
////			if(!$retryCount){
////				echo "end of retry count. exit!\n";
//////				exit(-1);
////			}
//			sleep(1);
//		}
//		$contents_to_cache = $contents;
//
//		$i = strpos($contents, '<body ');
//		$j = strpos($contents, '</body>');
//		if ($i) $contents = substr($contents, $i, $j - $i);
//
//		$contents = preg_replace('/ignore_js_op/', 'div', $contents);
//
//		$doc = new DOMDocument();
//		$contents = '<?xml encoding="utf8">' . $contents;
//		@$doc->loadHTML($contents);
//		$xpath = new DOMXPath($doc);
//		$download_count=0;
//		$mainDiv = $xpath->query('//div[contains(@id, \'postmessage_\')]')[0];
//		if ($mainDiv) {
//			$tmp=$mainDiv->getElementsByTagName('div')[0];
//			if($tmp)$mainDiv=$tmp;
////			$firstChile=$mainDiv->childNodes->item(0);
////			if($firstChile->tagName==='strong'){
////				// forum mod modified
////				$delete_flag=true;
////				$nl=$mainDiv->childNodes;
////				for ($i=0;$i< $nl->length;$i++){
////					$n=$nl->item($i);
////					if($n->tagName==='strong'){
////						$delete_flag=true;
////					}
////					if($delete_flag){
////						$mainDiv->removeChild($n);
////						if($n->tagName==='table'){
////							$delete_flag=false;
////						}
////					}
////				}
////			}
//
////			foreach ($xpath->query('//*/text()') as $a){
////				if(!str_replace(array("\r\n", "\n", "\r","\t"," "), "", $a->nodeValue))
////					$a->parentNode->removeChild($a);
////			}
//
//			$contents = $doc->saveHTML($mainDiv);
//		}
//
//		if($this->forumId==383 || $this->forumId==322 || $this->forumId==391 || $this->forumId==390){
//			$contents = preg_replace('/[\s\S]*<\/table>/','',$contents);
//			$contents = preg_replace('/<strong>[\s\S]*/','',$contents);
//
//		}
//		$contents = preg_replace('/.*本帖最后由[\s\S]*/','',$contents);
//
//
//		$contents = str_replace("&nbsp;","",$contents);
//		$i=mb_stripos($contents,"\xE3\xC2\x82");
//		if($i!==false){
//			$contents = iconv("UTF-8","UTF-8//IGNORE",$contents);
//		}
//
////		$contents=mb_ereg_replace("\xE3\xC2\x82","",$contents);
////		$contents = str_replace("\xc2\xa0","",$contents);
//
//		$article['content'] = $contents;
//
////		if($contents_to_cache) file_put_contents($cache_file, $contents_to_cache);
//		return $article;
//	}

}
?>

<?php

class sexinsex extends Plugin {

	function init($host) {
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
		$host->add_hook($host::HOOK_FEED_BASIC_INFO, $this);
		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
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
		if (strpos($fetch_url,'sexinsex')===false) {
			return $feed_data;
		}


		$argument=argument_parse(['page','fetch_url']);
		$page=$argument['page']??null;
		$fetch_url=$argument['fetch_url']??$fetch_url;
		if($page){
			if(strpos($fetch_url,'page=')){
				$fetch_url=preg_replace('/page=\d+/',"page=$page", $fetch_url);
			}else{
				$fetch_url=preg_replace('/-\d+\.html/',"-$page.html", $fetch_url);
			}
			Debug::log('change $fetch_url='.$fetch_url);
		}
		$force_rehash = $argument["force-rehash"]??false;

		$sth_guid = $this->pdo->prepare("select content, author, num_comments from  ttrss_entries where guid = ?");

		$feed_data=UrlHelper::fetch(["url" => $fetch_url,'followlocation'=>true, 'http_referrer'=>$fetch_url]);

		if(strpos($feed_data, 'charset=gbk')!==false){
			$feed_data=iconv('gbk', 'utf-8', $feed_data);
			$feed_data=str_replace('charset=gbk','charset=utf-8', $feed_data);
		}

		if(strpos($feed_data, 'logging.php?action=logout')===false){
			Debug::log('session/cookie invalid');
			user_error('session/cookie invalid', E_USER_ERROR);
			return $feed_data;
		}

		$doc = new DOMDocument();

		$feed_data = '<?xml encoding="utf8">' . $feed_data;
		@$doc->loadHTML($feed_data);

		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title)');
		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';


		$tbodies = $xpath->query('//tbody[starts-with(@id, \'normalthread_\') or starts-with(@id, \'stickthread_\')]');
		Debug::log("found tr row ****:" . $tbodies->length);

		$itemCount = 0;

		$rss_items=array();
		foreach ($tbodies as $tbody) {
			$thread_id=0;
			$a=$xpath->query('(.//a[starts-with(@href, \'thread-\')] | .//a[starts-with(@href, \'viewthread.php?tid=\')])',$tbody)[1];
			if(preg_match("/thread-([0-9]*)-/", $a->getAttribute('href'), $m)){
				$thread_id = $m[1];
			}elseif(preg_match("/tid=([0-9]*)&/", $a->getAttribute('href'), $m)){
				$thread_id = $m[1];
			}
			if(!$thread_id){
				continue;
			}
			$url = "http://sexinsex.net/bbs/thread-$thread_id-1-1.html" ;
			$entry_guid = "sexinsex:$thread_id";
			$entry_guid_hashed = json_encode(["ver" => 2, "uid" => $owner_uid, "hash" => 'SHA1:' . sha1($entry_guid)]);
			$title = $a->nodeValue;
			Debug::log("title: $title", Debug::LOG_EXTENDED);

			$post_type=null;
			$a=$xpath->query('.//a[starts-with(@href, \'forumdisplay.php?fid\')]',$tbody)[0];
			if($a){
				$post_type=$a->nodeValue;
				if($post_type!=='------') {
					$title = "[$post_type]".$title;
				}
			}

			$a = $xpath->query('.//td[@class="author"]/cite/a', $tbody)[0];
			$author = $a?$a->nodeValue:'匿名';
			$cite = $xpath->query('.//td[@class="author"]/cite', $tbody)[0];
			$cite = trim($cite->nodeValue);
			//thumbsUp, author, title 解析順序不能亂
			$thumbsUp = substr($cite, strlen($author));
			if(preg_match('/作者[：:](.+?)】?$/u', $title, $m)){
				$author=$m[1];
			}
			if ($thumbsUp) {
				$title .= str_repeat('👍', intdiv($thumbsUp, 10));
			}
			$replies=$xpath->query('.//td[@class="nums"]/strong',$tbody)[0]->nodeValue;
			$replies += $thumbsUp?:0;
			$a=$xpath->query('.//td[@class="author"]/em',$tbody)[0];
			$pubDate = date('r', strtotime($a->nodeValue)-28800);


			$content=false;
			if(!$force_rehash){
				$sth_guid->execute([$entry_guid_hashed]);
				if ($row = $sth_guid->fetch()) {
					$content=$row['content'];
					if($row['num_comments']==$replies && $row['author']==$author){
						Debug::log("SKIP existing/matching record...$title", Debug::LOG_VERBOSE);
						continue;
					}
				}
			}

			if(!$content){
				$content=UrlHelperExt::fetch_cached(["url" => $url,'followlocation'=>true, 'http_referrer'=>$fetch_url]);
				$doc2 = new DOMDocument();
				$doc2->loadHTML('<?xml encoding="utf8">' . $content);
				$xpath2 = new DOMXPath($doc2);
				$post = $xpath2->query('//div[@class="postmessage defaultpost"][1]/div[@class="t_msgfont"]/div')[0];
				if($post){
					$tb=$post->getElementsByTagName('table')[0];
					try {
						if ($tb) $post->removeChild($tb);
					} catch (Exception $e) {
					}
					foreach($xpath2->query('.//div[@style]', $post) as $div){
						$div->removeAttribute('style');
					}
					$content=$doc2->saveHTML($post);
					$content= preg_replace('/<font[^>]+>/','', $content);
					$content= preg_replace('/<\/font>/','', $content);
					$content= preg_replace('/br>\r\n/','br>', $content);
					$content= preg_replace('/>\s+</','><', $content);
				}
			}

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

			if($post_type)$rss_item = $rss_item ."<category>$post_type</category>";
			if(strpos($title,'合集')!==false) {
				$rss_item = $rss_item ."<category>合集</category>";
			}

			$rss_item .= "<slash:comments>$replies</slash:comments>";
			$rss_item = $rss_item . "</item>";
			$itemCount++;
			$rss_items[$thread_id]=$rss_item;
		}
//		ksort($rss_items);
		foreach ($rss_items as $v){
			$rss = $rss . $v;
		}
		return $rss . "</channel></rss>";
	}

	function hook_article_filter($article) {
		if (strpos($article['link'],'sexinsex')===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}
		return $article;
	}

	function hook_subscribe_feed($contents, $url, $auth_login, $auth_pass) {
		if (strpos($url,'sexinsex')===false) {
			return $contents;
		}

		return $this->hook_fetch_feed($contents,$url,1,0,0,$auth_login,$auth_pass);
	}

	function hook_feed_basic_info($basic_info, $fetch_url, $owner_uid, $feed_id, $auth_login, $auth_pass) {
		if (strpos($fetch_url,'sexinsex')===false) {
			return $basic_info;
		}

		$feed_data=UrlHelper::fetch(["url" => $fetch_url,'followlocation'=>true, 'http_referrer'=>$fetch_url]);

		if(strpos($feed_data, 'charset=gbk')!==false){
			$feed_data=iconv('gbk', 'utf-8', $feed_data);
			$feed_data=str_replace('charset=gbk','charset=utf-8', $feed_data);
		}

		if(strpos($feed_data, 'logging.php?action=logout')===false){
			Debug::log('session/cookie invalid');
			user_error('session/cookie invalid', E_USER_ERROR);
			return $basic_info;
		}

		$doc = new DOMDocument();

		$feed_data = '<?xml encoding="utf8">' . $feed_data;
		@$doc->loadHTML($feed_data);

		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title)');

		return [
			'title' => $rssTitleText,
			'site_url' => $fetch_url,
		];
	}

}

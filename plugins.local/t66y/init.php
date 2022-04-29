<?php

class t66y extends Plugin {
//	private $host;
	protected static $target_domain = 't66y.com';

	function init($host) {
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
//		$host->add_hook($host::HOOK_FEED_BASIC_INFO, $this);
//		$host->add_hook($host::HOOK_SUBSCRIBE_FEED, $this);
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

	private function purifyPage($mainDiv, $xpath, $doc) {
		$inputs = $xpath->query('.//input[@type="image"]', $mainDiv);
		foreach ($inputs as $input) {
			$img = $doc->createElement('img');
			$src = $input->getAttribute('src');
			if ($src) {
				$img->setAttribute('src', $this->changetohttps($src));
			}
			else {
				$img->setAttribute('src', $this->changetohttps($input->getAttribute('data-src')));
			}
			$input->parentNode->replaceChild($img, $input);
		}
		foreach ($xpath->query('.//img', $mainDiv) as $img) {
			$img->removeAttribute('onclick');
			$img->removeAttribute('style');
			$img->removeAttribute('data-link');
			$src = $img->getAttribute('src');
			if (!$src) {
				$datasrc = $img->getAttribute('data-src');
				if (!$datasrc) $datasrc = $img->getAttribute('ess-data');
				if ($datasrc) {
					$img->setAttribute('src', $this->changetohttps($datasrc));
					$img->removeAttribute('data-src');
				}
			}
		}

		foreach ($xpath->query('.//a', $mainDiv) as $a) {
			$href = $a->getAttribute('href');
			if (preg_match('/http:\/\/www\.viidii\.info\/\?(.*)&z/', $href, $matches)) {
				$href = $matches[1];
				$href = preg_replace('/______/', '.', $href);
				$a->setAttribute('href', $href);
				$a->removeAttribute('style');
				$a->removeAttribute('onmouseover');
				$a->removeAttribute('onmouseout');
			}
		}
		foreach ($xpath->query('//*/text()') as $a) {
			if (!str_replace(array("\r\n", "\n", "\r", "\t", " "), "", $a->nodeValue))
				$a->parentNode->removeChild($a);
		}
		return $doc->saveHTML($mainDiv);

	}

	protected function updateFeedLink($link, $owner_uid, $feed_id) {
//		$sth = $this->pdo->prepare("UPDATE  ttrss_feeds SET feed_url = ? , last_updated = null, last_update_started = null WHERE owner_uid = ? AND id = ?");
		$sth = $this->pdo->prepare("UPDATE  ttrss_feeds SET feed_url = ? WHERE owner_uid = ? AND id = ?");
		$sth->execute([$link, $owner_uid, $feed_id]);
	}

	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass) {
		if (strpos($fetch_url,$this::$target_domain)===false) {
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

		$sth_guid = $this->pdo->prepare("select content, num_comments from  ttrss_entries where guid = ?");

		$feed_data=UrlHelper::fetch(["url" => $fetch_url,'followlocation'=>true, 'http_referrer'=>$fetch_url]);

		$doc = new DOMDocument();

		$feed_data = '<?xml encoding="utf8">' . $feed_data;
		@$doc->loadHTML($feed_data);

		$xpath = new DOMXPath($doc);
		$rssTitleText=$xpath->evaluate('string(/html/head/title)');
		$rss = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:slash="http://purl.org/rss/1.0/modules/slash/"><channel><title>' . htmlspecialchars($rssTitleText) . '</title><link>' . htmlspecialchars($fetch_url) . '</link>';

		$trs = $xpath->query('//tbody[@id="tbody"]/tr[@class="tr3 t_one tac"]');

		$itemCount = 0;
		$forumId=0;
		if (preg_match('/\?fid=([0-9]*)/', $fetch_url, $m)) {
			$forumId = $m[1];
		}

		foreach ($trs as $tr) {
			$tds = $tr->getElementsByTagName('td');
			if ($tds->length == 5) {
				$tdlike = $tds->item(0);
				$tdTitle = $tds->item(1);
				$tdAuthor = $tds->item(2);
				$tdReplyCount = $tds->item(3);
				if ($tdTitle == null) continue;

				$a = $tdTitle->getElementsByTagName('h3')->item(0)->getElementsByTagName('a')->item(0);
				$title = $a->nodeValue;
				$url = 'http://www.t66y.com/' . $a->getAttribute('href');
				if (strpos($url, '?') !== false) continue;
				$thread_id=0;
				if(preg_match('/\/(\d+)\.html/',$url,$m)){
					$thread_id=$m[1];
				}elseif(preg_match('/tid=(\d+)$/',$url,$m)){
					$thread_id=$m[1];
				}
				if(!$thread_id){
					Debug::log("preg_match fail, thread_id not found");
					continue;
				}
				$replyCount = (int)trim($tdReplyCount->nodeValue);
				$likeCount = (int)trim($tdlike->nodeValue);
				$commentCount= $replyCount+$likeCount;
				$entry_guid = "t66y:$thread_id";
				$entry_guid_hashed = json_encode(["ver" => 2, "uid" => $owner_uid, "hash" => 'SHA1:' . sha1($entry_guid)]);
				$content=false;
				if(!$force_rehash){
					$sth_guid->execute([$entry_guid_hashed]);
					if ($row = $sth_guid->fetch()) {
						$content=$row['content'];
						if($row['num_comments']==$commentCount ){
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
					$post = $xpath2->query('//div[@class="tpc_content do_not_catch"]')[0];
					if($post){
//						$tb=$post->getElementsByTagName('table')[0];
//						try {
//							if ($tb) $post->removeChild($tb);
//						} catch (Exception $e) {
//						}
						foreach($xpath2->query('.//img', $post) as $img){
							$src=$img->getAttribute('ess-data');
							if($src)$img->setAttribute('src',$src);
							$img->removeAttribute('iyl-data');
							$img->removeAttribute('ess-data');
						}
						foreach($xpath2->query('.//div[@onclick]', $post) as $div){
							$div->parentNode->removeChild($div);
						}
						$content=$doc2->saveHTML($post);
					}else{
						// forbid
						user_error("get post content error.");
						UrlHelperExt::remove_cached(["url" => $url,'followlocation'=>true, 'http_referrer'=>$fetch_url]);
						return "";
					}
				}


				$author = $tdAuthor->getElementsByTagName('a')->item(0)->nodeValue;
				$pubDate = $tdAuthor->getElementsByTagName('div')->item(0)->nodeValue;

				if(strpos($pubDate,'昨天')!==false){
					$pubDate = date('r', strtotime("yesterday")-28800);
				}elseif (strpos($pubDate,'今天')!==false){
					$pubDate = date('r', strtotime("today")-28800);
				}else{
					$pubDate = date('r', strtotime($pubDate)-28800);
				}

				$trFullText = $doc->saveHTML($tr);
				if ($tdTitle->firstChild->nodeType == XML_TEXT_NODE) {
					$c = $tdTitle->firstChild->nodeValue;
					$c = preg_replace('/[\s\[\]]/', '', $c);
					$rss = $rss . "<category>" . htmlspecialchars($c) . "</category>";
					$title="[$c]$title";
				};
				$rss = $rss .
					"<item><title>" . htmlspecialchars($title) .
					"</title><link>" . htmlspecialchars($url) .
					"</link><author>" . htmlspecialchars($author) .
					"</author><slash:comments>$commentCount</slash:comments><pubDate>$pubDate</pubDate>";
				$rss .= "<description>".htmlspecialchars($content)."</description>";
				if (strpos($trFullText, ">熱<") !== false) {
					$rss = $rss . "<category>熱門帖</category>";
				}
				if (strpos($trFullText, "[精]") !== false) {
					$rss = $rss . "<category>熱門帖</category>";
				}
				if (strpos($trFullText, ">[積分+") !== false) {
					$rss = $rss . "<category>積分帖</category>";
				};

				if ($forumId == 21) {    //HTTP下載區
					if (preg_match('/\[(.*)\/(.*)\/(.*)\]/', $title, $m)) {
						$rss = $rss . "<category>" . htmlspecialchars($m[1]) . "</category>";
					}
				}
				else if ($forumId == 7) {    //技術討論區
					if ($title[0] === '[') {
						$p = strpos($title, ']');
						$tag = substr($title, 1, $p - 1);
						if (preg_match('/(\D*)([0-9-]+)(\D*)/', $tag, $m)) {
							$t = '';
							if ($m[1]) {
								$s = $m[1];
								$s1 = mb_substr($s, mb_strlen($s) - 1);
								if ($s1 == "第") $s = mb_substr($s, 0, mb_strlen($s) - 1);
								$t = $s;
							}
							if ($m[3]) {
								$s = $m[3];
								$s1 = mb_substr($s, 0, 1);
								if ($s1 == "期" || $s1 == "部") $s = mb_substr($s, 1);
								$t .= $s;
							}
							$tag = $t;
						}
						$rss = $rss . "<category>" . htmlspecialchars($tag) . "</category>";
					}
				}
				else {
					if (preg_match_all('/\[(.*)\]/U', $title, $matches)) {
						foreach ($matches[1] as $tag) {
							if ($tag === "MP4") continue;
							if (preg_match('/(.*)\/([0-9.]*)(\s)*([GMB]*)/', $tag, $m)) {
								if (($m[1] == "ALL") || ($m[1] == "MP4")) {
								}
								else {
									$rss = $rss . "<category>" . htmlspecialchars($m[1]) . "</category>";
								}
								$size = $m[2];
								if (strpos($m[4], 'G') !== false)
									$size = $size * 1024;
								if ($size > 2048) $rss = $rss . "<category>大檔</category>";
							}
							else if (preg_match('/^([0-9]+)P/', $tag, $m)) {
								if ($m[1] > 30 && $m[1] != 720 && $m[1] != 1080) $rss = $rss . "<category>多圖</category>";
							}
							else {
							}
						}
					}
				}
				if ($forumId == 4) {    //歐美原創區
					if (preg_match('/\((.+)\)/', $title, $m)) {
						foreach (explode(',', $m[1]) as $t)
							$rss = $rss . "<category>" . htmlspecialchars($t) . "</category>";
					}
				}
				$rss = $rss . "</item>";
				$itemCount++;
				if($itemCount>=10) break;
			}

		}

		if($itemCount==0&&(!$argument['fetch_url'])){
			if(preg_match('/page=(\d+)$/', $fetch_url, $m)){
				$p=(int)$m[1];
				if($p>1){
					$p--;
					$fetch_url=preg_replace('/page=(\d+)$/',"page=$p",$fetch_url);
					$r=$this->pdo->exec("update ttrss_feeds set feed_url='$fetch_url' where id=$feed");
				}

			}

		}
		return $rss . "</channel></rss>";
	}

	public function hook_feed_basic_info($basic_info, $fetch_url, $owner_uid, $feed, $auth_login, $auth_pass) {
		$contents = $this->fetch_file_contents([
			"url" => $fetch_url
		]);

		$contents = iconv('GBK', 'utf-8//IGNORE', $contents);
		$i = strpos($contents, '<title>', 0) + 7;
		$j = strpos($contents, '</title>', $i);
		$rssTitleText = substr($contents, $i, $j - $i - 11);

		$basic_info['title'] = $rssTitleText;
		$basic_info['site_url'] = $fetch_url;
		return $basic_info;
	}

	public function hook_article_filter($article) {
		if (strpos($article['link'],$this::$target_domain)===false) {
			return $article;
		}

		if($article["num_comments"]){
			$article["score_modifier"]=$article["num_comments"];
		}

		return $article;
	}

}


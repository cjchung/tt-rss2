<?php

class lpsg extends Plugin implements IHandler {

	private bool $manualMode = false;

	function init($host) {
		$host->add_hook($host::HOOK_FEED_FETCHED, $this);
		$host->add_hook($host::HOOK_FETCH_FEED, $this);
		$host->add_hook($host::HOOK_IFRAME_WHITELISTED, $this);
	}

	function hook_iframe_whitelisted($src): bool {
		return true;
	}

	public function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $last_article_timestamp, $auth_login, $auth_pass): string {
		if (substr($fetch_url, 0, 17) !== 'https://www.lpsg.') {
			return $feed_data;
		}
		$threadId = false;
		$pageNo = false;
		if (isset($_SERVER['argv'])) for ($i = 0; $i < sizeof($_SERVER['argv']); $i++) {
			switch ($_SERVER['argv'][$i]) {
				case '--lpsg-thread-id':
					$threadId = $_SERVER['argv'][++$i];
					break;
				case '--lpsg-page-no':
					$pageNo = $_SERVER['argv'][++$i];
					break;
			}
		}
		if (!($threadId && $pageNo))
			return $feed_data;

		$commentCount = $pageNo * 30 - 1;
		$this->manualMode = true;

		return "<rss xmlns:atom=\"http://www.w3.org/2005/Atom\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:content=\"http://purl.org/rss/1.0/modules/content/\" xmlns:slash=\"http://purl.org/rss/1.0/modules/slash/\" version=\"2.0\">
				<channel>
					<title></title>
					<description></description>
					<pubDate></pubDate>
					<lastBuildDate></lastBuildDate>
					<generator></generator>
					<link/>
					<atom:link rel=\"self\" type=\"application/rss+xml\" href=\"https://www.lpsg.com/forums/straight-adult-websites.32/index.rss\"/>
					<item>
						<title>.</title>
						<pubDate></pubDate>
						<link>https://www.lpsg.com/threads/$threadId/</link>
						<guid>https://www.lpsg.com/threads/$threadId/</guid>
						<author>invalid@example.com (_)</author>
						<dc:creator>_</dc:creator>
						<content:encoded></content:encoded>
						<slash:comments>$commentCount</slash:comments>
					</item>
				</channel>
			</rss>";
	}

	public function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed) {
		if (substr($fetch_url, 0, 17) !== 'https://www.lpsg.') {
			return $feed_data;
		}

		$doc = new DOMDocument();
		$sth = $this->pdo->prepare("select num_comments from  ttrss_entries where link= ?");

		$doc->loadXML($feed_data);
		$xpath = new DOMXPath($doc);
		$itemNodes = $xpath->query('/rss/channel/item');

		$itemsCount=0;
		foreach ($itemNodes as $itemNode) {
			$linkNode = $itemNode->getElementsByTagName('link')->item(0);
			$link = $linkNode->nodeValue;
			if (!preg_match('/https:\/\/www\.lpsg\.com\/threads\/\D*(\d+)/', $link, $m)) {
				throw new Exception("match link failed!($link)", 404);
			}
			$threadId = $m[1];
			$titleNode = $itemNode->getElementsByTagName('title')->item(0);
			$title = $titleNode->nodeValue;
			$commentsCount = $itemNode->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/slash/', 'comments')->item(0)->nodeValue;
			$pageNo = intdiv(($commentsCount), 30) + 1;
			$guid = "lpsg:$threadId:$pageNo";
			if ($pageNo > 1)
				$link .= "page-$pageNo";
			if($itemsCount>=10){
				Debug::log("SKIP! itemsCount >= 10...");
				$itemNode->parentNode->removeChild($itemNode);
				continue;
			}
			if (!$this->manualMode) {
				$sth->execute([$link]);
				if (($row = $sth->fetch()) && $row['num_comments'] == $commentsCount) {
					Debug::log("SKIP! for num_comments matched...$title");
					$itemNode->parentNode->removeChild($itemNode);
					continue;
				}
			}
			$pageInfo = $this->parsePage($link);

			$guidNode = $itemNode->getElementsByTagName('guid')->item(0);
			$authorNode = $itemNode->getElementsByTagName('author')->item(0);
			$titleNode->removeChild($titleNode->firstChild);
			$titleNode->appendChild($doc->createTextNode($pageInfo['title'] . " [P$pageNo]"));
			if ($this->manualMode) {
				$pubDateNode = $itemNode->getElementsByTagName('pubDate')->item(0);
				$pubDateNode->nodeValue = $pageInfo['pubDate'];
				$authorNode->nodeValue = $pageInfo['author'];
			}
			else {
				$authorNode->nodeValue = substr($authorNode->nodeValue, 21, -1);
			}

			$encodedNode = $itemNode->getElementsByTagNameNS('http://purl.org/rss/1.0/modules/content/', 'encoded')->item(0);
			foreach ($encodedNode->childNodes as $childNode) {
				$encodedNode->removeChild($childNode);
			}
			$encodedNode->appendChild($doc->createCDATASection($pageInfo['html']));
			$link = $pageInfo['fetch_effective_url'];
			$linkNode->nodeValue = $link;
			$guidNode->nodeValue = $guid;
			$category = $doc->createElement('category');
			$category->nodeValue = 'lpsg' . $threadId;
			$itemNode->appendChild($category);
			$itemsCount++;
		}
		return $doc->saveXML();

	}

	function parsePage($link): array {
		$pageInfo = [];
		$content = UrlHelper::fetch([
			"url" => $link,
			"useragent" => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.88 Safari/537.36'
		]);
		Debug::log("fetch $link", Debug::LOG_VERBOSE);
		$pageInfo['fetch_effective_url'] = (UrlHelper::$fetch_effective_url ?: $link);
		$doc = new DOMDocument();

		$content = '<?xml encoding="utf8">' . $content;
		@$doc->loadHTML($content);

		$xpath = new DOMXPath($doc);
		$title = $xpath->query('//title')[0]->nodeValue;
		$pageInfo['title'] = substr($title, 0, strpos($title, ' |'));
		$segmentNodes = $xpath->query('//div[@class="message-inner"]');
		if ($segmentNodes && $segmentNodes->length) {
			Debug::log($segmentNodes->length . " replies in page", Debug::LOG_VERBOSE);
		}
		else {
			throw new InvalidArgumentException("xpath not found error '//div[@class=\"message-inner\"]'");
		}
		$dateTimeOfLastPost = $xpath->query('(//time[@itemprop="datePublished"])[last()]')[0]->getAttribute('data-time');
		$pageInfo['pubDate'] = gmdate('r', $dateTimeOfLastPost);
		$html = false;
		$userName = '';
		$starTag = false;
		foreach ($segmentNodes as $segmentNode) {
			$contentNote = $xpath->query('.//article/div', $segmentNode)[0];
			$userName = $segmentNode->parentNode->getAttribute('data-author');// $xpath->query('.//a[contains(class,"username")]/text()',$segmentNode);//->item(0)->nodeValue;
			$id = $segmentNode->parentNode->getAttribute('id');
			// 計算like數
			$reactionsBarNotes = $xpath->query('//*[@id="' . $id . '"]/div/div[2]/div/div[2]/a');
			$likeStr = '';
			if ($reactionsBarNotes && $reactionsBarNotes[0]) {
				foreach ($reactionsBarNotes[0]->childNodes as $node) {
					switch ($node->nodeType) {
						case XML_ELEMENT_NODE:
							$likeStr .= '👍';
							break;
						case XML_TEXT_NODE:
							$t = $node->nodeValue;
							if (preg_match('/and (\d+) other/', $t, $m)) {
								$likeStr .= str_repeat('👍', $m[1]);
							}
							break;
					}
				}
			}

			if ((!$starTag) && (mb_strlen($likeStr) >= 5)) {
				$starTag = true;
			}

			$html .= "<div>$userName ✍ $likeStr</div>";
			$html .= '<div>' . preg_replace('/\s\s+/', '', $doc->saveHTML($contentNote)) . '</div><br><hr><br>';

		}
		$pageInfo['author'] = $userName;
		$pageInfo['html'] = $html;
		if ($starTag) {
			$pageInfo['title'] = '❤️'. $pageInfo['title'];
		}
		return $pageInfo;
	}

	function api_version(): int {
		return 2;
	}


	function csrf_ignore($method): bool {
		return false;
	}

	function before($method): bool {
		return true;
	}

	function after(): bool {
		return true;
	}

	function about(): array {
		return array(1.0, // version
			'', // description
			'XXXXX', // author
			false, // is_system
		);
	}
}


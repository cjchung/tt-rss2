<?php
class Handler_Article extends Handler_Protected {
	function redirect() {
		$article = ORM::for_table('ttrss_entries')
			->table_alias('e')
			->join('ttrss_user_entries', [ 'ref_id', '=', 'e.id'], 'ue')
				->where('ue.owner_uid', $_SESSION['uid'])
				->find_one((int)$_REQUEST['id']);

		if ($article) {
			$article_url = UrlHelper::validate($article->link);

			if ($article_url) {
				header("Location: $article_url");
				return;
			}
		}

		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
		print "Article not found or has an empty URL.";
	}

	function printArticleTags() {
		$id = (int) clean($_REQUEST['id'] ?? 0);

		print json_encode(["id" => $id,
			"tags" => Article::_get_tags($id)]);
	}

	function setScore() {
		$ids = array_map("intval", clean($_REQUEST['ids'] ?? []));
		$score = (int)clean($_REQUEST['score']);

		$ids_qmarks = arr_qmarks($ids);

		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET
			score = ? WHERE ref_id IN ($ids_qmarks) AND owner_uid = ?");

		$sth->execute(array_merge([$score], $ids, [$_SESSION['uid']]));

		print json_encode(["id" => $ids, "score" => $score]);
	}

	function setArticleTags() {

		$id = clean($_REQUEST["id"]);

		//$tags_str = clean($_REQUEST["tags_str"]);
		//$tags = array_unique(array_map('trim', explode(",", $tags_str)));

		$tags = FeedItem_Common::normalize_categories(explode(",", clean($_REQUEST["tags_str"])));

		$this->pdo->beginTransaction();

		$sth = $this->pdo->prepare("SELECT int_id FROM ttrss_user_entries WHERE
				ref_id = ? AND owner_uid = ? LIMIT 1");
		$sth->execute([$id, $_SESSION['uid']]);

		if ($row = $sth->fetch()) {

			$tags_to_cache = array();

			$int_id = $row['int_id'];

			$dsth = $this->pdo->prepare("DELETE FROM ttrss_tags WHERE
				post_int_id = ? AND owner_uid = ?");
			$dsth->execute([$int_id, $_SESSION['uid']]);

			$csth = $this->pdo->prepare("SELECT post_int_id FROM ttrss_tags
				WHERE post_int_id = ? AND owner_uid = ? AND tag_name = ?");

			$usth = $this->pdo->prepare("INSERT INTO ttrss_tags
				(post_int_id, owner_uid, tag_name)
				VALUES (?, ?, ?)");

			foreach ($tags as $tag) {
				$csth->execute([$int_id, $_SESSION['uid'], $tag]);

				if (!$csth->fetch()) {
					$usth->execute([$int_id, $_SESSION['uid'], $tag]);
				}

				array_push($tags_to_cache, $tag);
			}

			/* update tag cache */

			$tags_str = join(",", $tags_to_cache);

			$sth = $this->pdo->prepare("UPDATE ttrss_user_entries
				SET tag_cache = ? WHERE ref_id = ? AND owner_uid = ?");
			$sth->execute([$tags_str, $id, $_SESSION['uid']]);
		}

		$this->pdo->commit();

		// get latest tags from the database, original $tags is sometimes JSON-encoded as a hash ({}) - ???
		print json_encode(["id" => (int)$id, "tags" => Article::_get_tags($id)]);
	}


	/*function completeTags() {
		$search = clean($_REQUEST["search"]);

		$sth = $this->pdo->prepare("SELECT DISTINCT tag_name FROM ttrss_tags
				WHERE owner_uid = ? AND
				tag_name LIKE ? ORDER BY tag_name
				LIMIT 10");

		$sth->execute([$_SESSION['uid'], "$search%"]);

		print "<ul>";
		while ($line = $sth->fetch()) {
			print "<li>" . $line["tag_name"] . "</li>";
		}
		print "</ul>";
	}*/

	function assigntolabel() {
		return $this->_label_ops(true);
	}

	function removefromlabel() {
		return $this->_label_ops(false);
	}

	private function _label_ops($assign) {
		$reply = array();

		$ids = explode(",", clean($_REQUEST["ids"]));
		$label_id = clean($_REQUEST["lid"]);

		$label = Labels::find_caption($label_id, $_SESSION["uid"]);

		$reply["labels-for"] = [];

		if ($label) {
			foreach ($ids as $id) {
				if ($assign)
					Labels::add_article($id, $label, $_SESSION["uid"]);
				else
					Labels::remove_article($id, $label, $_SESSION["uid"]);

				array_push($reply["labels-for"],
					["id" => (int)$id, "labels" => Article::_get_labels($id)]);
			}
		}

		$reply["message"] = "UPDATE_COUNTERS";

		print json_encode($reply);
	}

	function getmetadatabyid() {
		$article = ORM::for_table('ttrss_entries')
			->join('ttrss_user_entries', ['ref_id', '=', 'id'], 'ue')
			->where('ue.owner_uid', $_SESSION['uid'])
			->find_one((int)$_REQUEST['id']);

		if ($article) {
			echo json_encode(["link" => $article->link, "title" => $article->title]);
		} else {
			echo json_encode([]);
		}
	}
}

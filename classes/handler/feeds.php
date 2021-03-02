<?php
require_once "colors.php";

class Handler_Feeds extends Handler_Protected {
   function csrf_ignore($method) {
		$csrf_ignored = array("index");

		return array_search($method, $csrf_ignored) !== false;
	}

	function catchupAll() {
		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET
						last_read = NOW(), unread = false WHERE unread = true AND owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function view() {
		$reply = array();

		$feed = $_REQUEST["feed"];
		$method = $_REQUEST["m"] ?? "";
		$view_mode = $_REQUEST["view_mode"] ?? "";
		$limit = 30;
		$cat_view = $_REQUEST["cat"] == "true";
		$next_unread_feed = $_REQUEST["nuf"] ?? 0;
		$offset = $_REQUEST["skip"] ?? 0;
		$order_by = $_REQUEST["order_by"] ?? "";
		$check_first_id = $_REQUEST["fid"] ?? 0;

		if (is_numeric($feed)) $feed = (int) $feed;

		/* Feed -5 is a special case: it is used to display auxiliary information
		 * when there's nothing to load - e.g. no stuff in fresh feed */

		if ($feed == -5) {
			print json_encode($this->_generate_dashboard_feed());
			return;
		}

		$sth = false;
		if ($feed < LABEL_BASE_INDEX) {

			$label_feed = Labels::feed_to_label_id($feed);

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_labels2 WHERE
							id = ? AND owner_uid = ?");
			$sth->execute([$label_feed, $_SESSION['uid']]);

		} else if (!$cat_view && is_numeric($feed) && $feed > 0) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE
							id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);

		} else if ($cat_view && is_numeric($feed) && $feed > 0) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feed_categories WHERE
							id = ? AND owner_uid = ?");

			$sth->execute([$feed, $_SESSION['uid']]);
		}

		if ($sth && !$sth->fetch()) {
			print json_encode($this->_generate_error_feed(__("Feed not found.")));
			return;
		}

		set_pref(Prefs::_DEFAULT_VIEW_MODE, $view_mode);
		set_pref(Prefs::_DEFAULT_VIEW_ORDER_BY, $order_by);

		/* bump login timestamp if needed */
		if (time() - $_SESSION["last_login_update"] > 3600) {
			$user = ORM::for_table('ttrss_users')->find_one($_SESSION["uid"]);
			$user->last_login = Db::NOW();
			$user->save();

			$_SESSION["last_login_update"] = time();
		}

		if (!$cat_view && is_numeric($feed) && $feed > 0) {
			$sth = $this->pdo->prepare("UPDATE ttrss_feeds SET last_viewed = NOW()
							WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);
		}

		$reply['headlines'] = [];

		list($override_order, $skip_first_id_check) = Feeds::_order_to_override_query($order_by);

		$ret = Feeds::_format_headlines_list($feed, $method,
			$view_mode, $limit, $cat_view, $offset,
			$override_order, true, $check_first_id, $skip_first_id_check, $order_by);

		$headlines_count = $ret[1];
		$disable_cache = $ret[3];
		$reply['headlines'] = $ret[4];

		if (!$next_unread_feed)
			$reply['headlines']['id'] = $feed;
		else
			$reply['headlines']['id'] = $next_unread_feed;

		$reply['headlines']['is_cat'] = $cat_view;

		$reply['headlines-info'] = ["count" => (int) $headlines_count,
            						"disable_cache" => (bool) $disable_cache];

		// this is parsed by handleRpcJson() on first viewfeed() to set cdm expanded, etc
		$reply['runtime-info'] = RPC::_make_runtime_info();

		print json_encode($reply);
	}

	private function _generate_dashboard_feed() {
		$reply = array();

		$reply['headlines']['id'] = -5;
		$reply['headlines']['is_cat'] = false;

		$reply['headlines']['toolbar'] = '';

		$reply['headlines']['content'] = "<div class='whiteBox'>".__('No feed selected.');

		$reply['headlines']['content'] .= "<p><span class=\"text-muted\">";

		$sth = $this->pdo->prepare("SELECT ".SUBSTRING_FOR_DATE."(MAX(last_updated), 1, 19) AS last_updated FROM ttrss_feeds
			WHERE owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);
		$row = $sth->fetch();

		$last_updated = TimeHelper::make_local_datetime($row["last_updated"], false);

		$reply['headlines']['content'] .= sprintf(__("Feeds last updated at %s"), $last_updated);

		$sth = $this->pdo->prepare("SELECT COUNT(id) AS num_errors
			FROM ttrss_feeds WHERE last_error != '' AND owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);
		$row = $sth->fetch();

		$num_errors = $row["num_errors"];

		if ($num_errors > 0) {
			$reply['headlines']['content'] .= "<br/>";
			$reply['headlines']['content'] .= "<a class=\"text-muted\" href=\"#\" onclick=\"CommonDialogs.showFeedsWithErrors()\">".
				__('Some feeds have update errors (click for details)')."</a>";
		}
		$reply['headlines']['content'] .= "</span></p>";

		$reply['headlines-info'] = array("count" => 0,
			"unread" => 0,
			"disable_cache" => true);

		return $reply;
	}

	private function _generate_error_feed($error) {
		$reply = array();

		$reply['headlines']['id'] = -7;
		$reply['headlines']['is_cat'] = false;

		$reply['headlines']['toolbar'] = '';
		$reply['headlines']['content'] = "<div class='whiteBox'>". $error . "</div>";

		$reply['headlines-info'] = array("count" => 0,
			"unread" => 0,
			"disable_cache" => true);

		return $reply;
	}

	function subscribeToFeed() {
		print json_encode([
			"cat_select" => \Controls\select_feeds_cats("cat")
		]);
	}

	function search() {
		print json_encode([
			"show_language" => Config::get(Config::DB_TYPE) == "pgsql",
			"show_syntax_help" => count(PluginHost::getInstance()->get_hooks(PluginHost::HOOK_SEARCH)) == 0,
			"all_languages" => Pref_Feeds::get_ts_languages(),
			"default_language" => get_pref(Prefs::DEFAULT_SEARCH_LANGUAGE)
		]);
	}

	function updatedebugger() {
		header("Content-type: text/html");

		$xdebug = isset($_REQUEST["xdebug"]) ? (int)$_REQUEST["xdebug"] : 1;

		Debug::set_enabled(true);
		Debug::set_loglevel($xdebug);

		$feed_id = (int)$_REQUEST["feed_id"];
		$do_update = ($_REQUEST["action"] ?? "") == "do_update";
		$csrf_token = $_POST["csrf_token"];

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
		    print "Access denied.";
		    return;
        }
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<title>Feed Debugger</title>
			<style type='text/css'>
				@media (prefers-color-scheme: dark) {
					body {
						background : #222;
					}
				}
				body.css_loading * {
					display : none;
				}
			</style>
			<script>
				dojoConfig = {
					async: true,
					cacheBust: "<?= get_scripts_timestamp(); ?>",
					packages: [
						{ name: "fox", location: "../../js" },
					]
				};
			</script>
			<?= javascript_tag("js/utility.js") ?>
			<?= javascript_tag("js/common.js") ?>
			<?= javascript_tag("lib/dojo/dojo.js") ?>
			<?= javascript_tag("lib/dojo/tt-rss-layer.js") ?>
		</head>
		<body class="flat ttrss_utility feed_debugger css_loading">
		<script type="text/javascript">
			require(['dojo/parser', "dojo/ready", 'dijit/form/Button','dijit/form/CheckBox', 'fox/form/Select', 'dijit/form/Form',
				'dijit/form/Select','dijit/form/TextBox','dijit/form/ValidationTextBox'],function(parser, ready){
				ready(function() {
					parser.parse();
				});
			});
		</script>

			<div class="container">
				<h1>Feed Debugger: <?= "$feed_id: " . $this->_get_title($feed_id) ?></h1>
				<div class="content">
					<form method="post" action="" dojoType="dijit.form.Form">
						<?= \Controls\hidden_tag("op", "feeds") ?>
						<?= \Controls\hidden_tag("method", "updatedebugger") ?>
						<?= \Controls\hidden_tag("csrf_token", $csrf_token) ?>
						<?= \Controls\hidden_tag("action", "do_update") ?>
						<?= \Controls\hidden_tag("feed_id", (string)$feed_id) ?>

						<fieldset>
							<label>
							<?= \Controls\select_hash("xdebug", $xdebug,
									[Debug::$LOG_VERBOSE => "LOG_VERBOSE", Debug::$LOG_EXTENDED => "LOG_EXTENDED"]);
							?></label>
						</fieldset>

						<fieldset>
							<label class="checkbox"><?= \Controls\checkbox_tag("force_refetch", isset($_REQUEST["force_refetch"])) ?> Force refetch</label>
						</fieldset>

						<fieldset class="narrow">
							<label class="checkbox"><?= \Controls\checkbox_tag("force_rehash", isset($_REQUEST["force_rehash"])) ?> Force rehash</label>
						</fieldset>

						<?= \Controls\submit_tag("Continue") ?>
					</form>

					<hr>

					<pre><?php

					if ($do_update) {
						RSSUtils::update_rss_feed($feed_id, true);
					}

					?></pre>
				</div>
			</div>
		</body>
		</html>
		<?php

	}

	function add() {
		$feed = clean($_REQUEST['feed']);
		$cat = clean($_REQUEST['cat'] ?? '');
		$need_auth = isset($_REQUEST['need_auth']);
		$login = $need_auth ? clean($_REQUEST['login']) : '';
		$pass = $need_auth ? clean($_REQUEST['pass']) : '';

		$rc = Feeds::_subscribe($feed, $cat, $login, $pass);

		print json_encode(array("result" => $rc));
	}

}


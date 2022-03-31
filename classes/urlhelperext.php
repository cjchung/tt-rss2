<?php
class UrlHelperExt {

	/**
	 * @param array<string, bool|int|string>|string $options
	 * @return false|string false if something went wrong, otherwise string contents
	 */
	public static function fetch_cached($options, $cache_time=0) {
//		$url= $options["url"];
		$cache_filename = Config::get(Config::CACHE_DIR) . "/feeds/" . sha1(serialize($options)) . ".ser";
		if(file_exists($cache_filename)&& ($cache_time<=0||filemtime($cache_filename)+$cache_time>time())){
			$a= unserialize(gzinflate(file_get_contents($cache_filename)));
			$html=$a['html'];
			UrlHelper::$fetch_effective_url=$a['fetch_effective_url'];
			UrlHelper::$fetch_last_error_code=$a['fetch_last_error_code'];
			Debug::log("UrlHelperExt: cached content replied ${options['url']}",Debug::LOG_VERBOSE);
		}else{
			$html=UrlHelper::fetch($options);
			if($html||(UrlHelper::$fetch_last_error_code>=300 && UrlHelper::$fetch_last_error_code <400)){
				file_put_contents($cache_filename, gzdeflate(serialize([
					'html'=>$html,
					'fetch_last_error_code'=>UrlHelper::$fetch_last_error_code,
					'fetch_effective_url'=>UrlHelper::$fetch_effective_url]),9));
			}
		}
		return $html;
	}


}

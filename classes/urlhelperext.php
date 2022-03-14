<?php
class UrlHelperExt {

	/**
	 * @param array<string, bool|int|string>|string $options
	 * @return false|string false if something went wrong, otherwise string contents
	 */
	public static function fetch_cached($options) {
		$url= $options["url"];
		$cache_filename = Config::get(Config::CACHE_DIR) . "/feeds/" . sha1($url) . ".ser";
		if(file_exists($cache_filename)){
			$a= unserialize(gzinflate(file_get_contents($cache_filename)));
			$html=$a['html'];
			UrlHelper::$fetch_effective_url=$a['fetch_effective_url'];
		}else{
			$html=UrlHelper::fetch($options);
			if($html){
				file_put_contents($cache_filename, gzdeflate(serialize(['html'=>$html,'fetch_effective_url'=>UrlHelper::$fetch_effective_url])));
			}
		}
		return $html;
	}


}

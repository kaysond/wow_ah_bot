<?php
/**
 * run_bot.php is an example script to construct and run wow_ah_bot
 *
 * Copyright (C) 2016 Aram Akhavan <kaysond@hotmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package wow_ah_bot
 * @author  Aram Akhavan <kaysond@hotmail.com>
 * @link    https://github.com/kaysond/wow_ah_bot
 * @copyright 2016 Aram Akhavan
 */
namespace wow_ah_bot;

require_once("wow_ah_bot.class.php");

$browser = array(
	"useragent"      => "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:50.0) Gecko/20100101 Firefox/50.0",
	"language"       => "en-US",
	"resolution"     => array(1080, 1920),
	"timezoneoffset" => 420,
	"randomseed"     => "somesalt",
	"headers"        => "Accept-Language: en-US,en;q=0.5\nDNT: 1"
);

try {
	$wow_ah_bot = new wow_ah_bot("config.json", "log.txt", "cookiefile.txt", "apilog.txt", $browser, "127.0.0.1", "wow_ah", "wow_ah", "wow_ah");
}
catch (Exception $e) {
	die("Error constructing bot: " . implode(" ", $e->getAllMessages()) . "\n");
}

try {
	$wow_ah_bot->run();
}
catch (Exception $e) {
	$wow_ah_bot->close();
	die("Killing bot due to error: " . implode(" ", $e->getAllMessages()) . "\n");
}

?>
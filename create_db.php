 <?php
/**
 * create_db.php sets up the database for storing searches executed by wow_ah_bot
 *
 * Adds one `auctions` table and a `times` view for fast viewing of collected data
 * The query is in wow_ah_bot_schema.sql
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

define("MYSQL_HOST", "127.0.0.1");
define("MYSQL_USER", "wow_ah");
define("MYSQL_PASS", "wow_ah");
define("MYSQL_DB", "wow_ah");

echo "Connecting to mysql database...\n";

mysqli_report(MYSQLI_REPORT_ALL);
$mysqli = new mysqli(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
if ($mysqli->connect_errno)
	die("Failed to connect to database. Error code: {$mysqli->connect_error}.\n");
$mysqli->set_charset("utf8");

try {
	echo "Creating database table...\n";
	$query = file_get_contents("wow_ah_bot_schema.sql");
	$result = $mysqli->query($query);
	if (!$result)
		die("Query error while creating database: " . $mysqli->error . "\n");
	
	echo "Creating view...\n";
	$query = file_get_contents("wow_ah_bot_view.sql");
	$result = $mysqli->query($query);
	if (!$result)
		die("Query error while creating view: " . $mysqli->error . "\n");
}
catch (mysqli_sql_exception $e) {
	die("Could not create table or view. Caught exception: " . $e->getMessage());
}
catch (Exception $e) {
	die("Unhandled exception: " . $e->getMessage());
}

echo "Done.\n";

?>
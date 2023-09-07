#!/usr/bin/env php
<?php

function usage() : void {
	global $argv;

	fwrite(STDERR, sprintf("usage: %s OPTIONS\n", $argv[0]));
	fwrite(STDERR, "\tOPTIONS:\n");
	fwrite(STDERR, "\t--ttrss-user USER\n");
	fwrite(STDERR, "\t[ --batch-size N ] (default: 1000)\n");
	fwrite(STDERR, "\t[ --output-dir PATH ] (default: .)\n");
	fwrite(STDERR, "\t[ --output-prefix STRING ] (default: ttrss)\n");
	exit(1);
} // usage


function sql_error(string $sql, $sth) : void {
	if( $sth->errorInfo()[2] != "") {
		die(sprintf("(%s/%s) %s in\n%s\n",
			$sth->errorInfo()[0],
			$sth->errorInfo()[1],
			$sth->errorInfo()[2],
			$sql,
		));
	}
} // sql_error

function sql_do(string $sql, array $params) : void {
	global $dbh, $sth;

	$sth=$dbh->prepare($sql);
	sql_error($sql, $sth);
	$sth->execute($params);
	sql_error($sql, $sth);
} // sql_do

function sql_fetch(string $sql, array $params) : stdClass {
	global $dbh, $sth;

	sql_do($sql, $params);
	sql_error($sql, $sth);
	return $sth->fetch(PDO::FETCH_OBJ);
} // sql_fetch

function sql_fetchAll(string $sql, array $params) : array {
	global $dbh, $sth;

	sql_do($sql, $params);
	sql_error($sql, $sth);
	return $sth->fetchAll(PDO::FETCH_ASSOC);
} // sql_fetchAll

function convert_guid(string $guid) : string {
	if (strpos($guid, 'SHA1:') === 0) {
		return $guid; // legacy format
	}

	$obj = json_decode($guid, true);
	if ($obj && $obj["ver"] == 2 && $obj["hash"]) {
		return $obj["hash"];
	}

	return $guid;
} // convert_guid

function export_translate($sth) : array {
	global $category;

	$output = [];

	while($row = $sth->fetch(PDO::FETCH_OBJ)) {
		$item = [];
		$item["categories"] = [];

		$item["guid"]=convert_guid($row->guid);
		$item["origin"]["title"]=$row->feed_title;
		$item["origin"]["feedUrl"]=$row->feed_url;
		$item["origin"]["htmlUrl"]=$row->site_url;
		if($row->cat_id != null) {
			$item["origin"]["category"]=$category[$row->cat_id];
		}

		if($row->author) {
			$item["author"]=$row->author;
		}

		// array_push($item["categories"], "user/-/label/bornheim/starred_or_not");

		if($row->marked) {
			array_push($item["categories"], "user/-/state/com.google/starred");
		}
		if($row->unread) {
			array_push($item["categories"], "user/-/state/com.google/unread");
		} else {
			array_push($item["categories"], "user/-/state/com.google/read");
		}
		$item["url"]=$row->link;
		$item["title"]=$row->title;
		$item["content"]=$row->content;
		$item["published"]=$row->updated;

		array_push($output, $item);
	}

	return($output);
} // export_translate

// ------------
// --- main ---
// ------------

if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        print "PHP version 7.4.0 or newer required. You're using " . PHP_VERSION . ".\n";
        exit;
}

$ttrss_user="";
$batch_size=1000;
$output_dir=".";
$output_prefix="ttrss";

require sprintf("%s.config.php", basename($argv[0], ".php"));

try {
	$dbh = new PDO($db_connect, $db_user, $db_pass);
} catch(PDOException $e) {
	die($e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "\n");
}

$options=getopt("",
	[
	"ttrss-user:",
	"batch-size:",
	"output-dir:",
	"output-prefix:",
	],
	$rest_index
);
if(count($options) == 0 || $rest_index < $argc) {
	usage();
}

if(isset($options["ttrss-user"])) {
	$ttrss_user=$options["ttrss-user"];
}
if($ttrss_user === "") {
	usage();
}
if(isset($options["batch-size"])) {
	$batch_size=$options["batch-size"];
}
if(isset($options["output-dir"])) {
	$output_dir=$options["output-dir"];
}
if(isset($options["output-prefix"])) {
	$output_prefix=$options["output-prefix"];
}

$row=sql_fetch('SELECT id owner_uid FROM ttrss_users WHERE login = ?',
	[$ttrss_user]);
if(! $row) {
	die("User " . $ttrss_user . " not found.\n");
}
$owner_uid=$row->owner_uid;

$row=sql_fetch('SELECT count(*) n FROM ttrss_feeds WHERE owner_uid = ?',
	[$owner_uid]);;
$n=$row->n;
if(! $n) {
	die("User " . $ttrss_user . " has no feeds.\n");
}

$rows=sql_fetchAll('SELECT id, title FROM ttrss_feed_categories WHERE owner_uid = ?',
	[$owner_uid]);;
$category=array();
foreach ($rows as $row) {
	$category[$row["id"]]=$row["title"];
}

fwrite(STDERR, sprintf("Exporting %s feeds for user %s ...\n",
	$n, $ttrss_user));

$n_file=0;
$offset=0;
$total=0;

while(true) {
	$sql = <<<SQL
		SELECT
			ttrss_entries.guid,
			ttrss_entries.title,
			content,
			marked,
			link,
			author,
			unread,
			ttrss_feeds.title AS feed_title,
			ttrss_feeds.cat_id AS cat_id,
			ttrss_feeds.feed_url AS feed_url,
			ttrss_feeds.site_url AS site_url,
			ttrss_entries.updated
		FROM
			ttrss_user_entries LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = feed_id),
			ttrss_entries
		WHERE
			ref_id = ttrss_entries.id AND
			ttrss_user_entries.owner_uid = ?
		ORDER BY
			ttrss_entries.id
		LIMIT	$batch_size
		OFFSET	$offset
	SQL;

	sql_do($sql, [$owner_uid]);

	$n=$sth->rowCount();
	if($n == 0) {
		break;
	}

	$batch = [ "items" => export_translate($sth) ];

	$n_file++;
	$filename=sprintf("%s/%s-%s.%08d.json",
		$output_dir,
		$output_prefix,
		$ttrss_user,
		$n_file);
	if(file_exists($filename)) {
		die(sprintf("Refusing to overwrite %s\n", $filename));
	}
	$export=fopen($filename, "w") or die("Unable to open $filename");
	fwrite($export, json_encode($batch));
	fclose($export);
	
	$total+=$n;
	$offset += $n;
}

fwrite(STDERR, sprintf("Exported %s articles for user %s into %d files.\n",
	$total, $ttrss_user, $n_file));

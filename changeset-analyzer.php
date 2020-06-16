<?php

set_time_limit(3600);

function get_url_contents($url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
	curl_setopt($ch, CURLOPT_TIMEOUT, 600);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$response = curl_exec($ch);
	curl_close($ch);     
	return $response;
}

$API_URL = "https://api.openstreetmap.org/api/0.6/";
# DISPLAY_NAME entweder aus POST-Parameter der index.php oder von der Kommandozeile als erstes Argument holen
$DISPLAY_NAME = (isset($_POST["display"]) ? $_POST["display"] : $_SERVER["argv"][1]);

$JSON_DIR = "changeset-analyzer-" . $DISPLAY_NAME . "/";
mkdir($JSON_DIR);

$CHANGESETS_JSON		= $JSON_DIR . "changesets-" . $DISPLAY_NAME . ".json";
$CHANGESET_JSON			= $JSON_DIR . "changeset-%d.json";
$ELEMENT_JSON			= $JSON_DIR . "%s-%dv%d.json";
$ELEMENT_JSON_C			= $JSON_DIR . "%s-%d-current.json";

$GET_CHANGESETS_INITIAL	= $API_URL . "changesets" . "?display_name=" . $DISPLAY_NAME;
$GET_CHANGESETS_OFFSET	= $GET_CHANGESETS_INITIAL . "&time=1900-01-01T00:00:00,%s";
$GET_CHANGESET_CHANGES	= $API_URL . "changeset/%d/download";

$GET_ELEMENTS           = $API_URL . "%ss?%ss=%s";
$LONG_TYPES				= Array("n" => "node", "w" => "way", "r" => "relation");

if (!file_exists($CHANGESETS_JSON)) {

	$fc = get_url_contents($GET_CHANGESETS_INITIAL);
	$xml = simplexml_load_string($fc);
	$cs_list = Array();
	
	while (isset($xml->changeset)) {
		foreach($xml->changeset as $c) {
			$this_comment = "";
			foreach($c->tag as $t) {
				if ($t->attributes()->k == "comment") $this_comment = strval($t->attributes()->v);
			}
			$cs_list[] = Array("id" => strval($c->attributes()->id), "created_at" => strval($c->attributes()->created_at), "comment" => $this_comment);
			$last_create = strval($c->attributes()->created_at);
		}
		$fc = file_get_contents(sprintf($GET_CHANGESETS_OFFSET, $last_create));
		$xml = simplexml_load_string($fc);
	}

	file_put_contents($CHANGESETS_JSON, json_encode($cs_list));

} else {

	#TODO: Neue Changesets holen

}

$cs_list = json_decode(file_get_contents($CHANGESETS_JSON));

foreach ($cs_list as $cs) {
	if (!file_exists(sprintf($CHANGESET_JSON, $cs->id))) {
		$these_changes = Array();

		$fc = get_url_contents(sprintf($GET_CHANGESET_CHANGES, $cs->id));
		$xml = simplexml_load_string($fc);

		foreach(array("create", "modify", "delete") as $mode) {
			if (isset($xml->{$mode})) {
				foreach($xml->{$mode} as $m) {
					foreach(array("node", "way", "relation") as $obj) {
						if (isset($m->{$obj})) $this_type = $obj;
					}
					$these_changes[] = Array("mode" => $mode, "type" => $this_type, "id" => strval($m->{$this_type}->attributes()->id), "version" => strval($m->{$this_type}->attributes()->version));
				}
			}
		}
		
		file_put_contents(sprintf($CHANGESET_JSON, $cs->id), json_encode($these_changes));
	} 
}

foreach(glob($JSON_DIR . "changeset-*.json") as $fn) {
	preg_match("/changeset\-(\d*)\.json/", $fn, $m);
	$j = json_decode(file_get_contents($fn));
	$ids = Array();
	foreach($j as $obj) {
		if (!file_exists(sprintf($ELEMENT_JSON, substr($obj->type, 0, 1), $obj->id, $obj->version))) {
			if (!isset($ids[$obj->type])) $ids[$obj->type] = "";
			$ids[$obj->type] .= ($ids[$obj->type] != "" ? "," : "").$obj->id."v".$obj->version;
		}
	}
	foreach(array_keys($ids) as $type) {
		$fc = get_url_contents(sprintf($GET_ELEMENTS, $type, $type, $ids[$type]));
		$xml = simplexml_load_string($fc);
		foreach($xml->{$type} as $oincs) {
			file_put_contents(sprintf($ELEMENT_JSON, substr($type, 0, 1), strval($oincs->attributes()->id), strval($oincs->attributes()->version)), json_encode($oincs));
		}
	}
}

foreach(glob($JSON_DIR . "*-*v*.json") as $fn) {
	preg_match("/([nwr])\-(\d*)v(\d+)\.json/", $fn, $m);
	$ids[$m[1]][] = $m[2];
}

foreach(array_keys($LONG_TYPES) as $t) {
	$these_ids = "";
	foreach($ids[$t] as $n) {
		if ($ids[$t] != null) {
			$this_obj = array_pop($ids[$t]);
			if ((strlen($these_ids) + strlen($this_obj) + 3) > 8000) {
				$fc = get_url_contents(sprintf($GET_ELEMENTS, $LONG_TYPES[$t], $LONG_TYPES[$t], $these_ids . "," . $this_obj));
				$xml = simplexml_load_string($fc);
				if ($xml != null) {
					foreach($xml->{$LONG_TYPES[$t]} as $o) {
						if ($o != null) {
							if (
								!file_exists(sprintf($ELEMENT_JSON, $t, strval($o->attributes()->id), strval($o->attributes()->version))) 
								&& 
								!file_exists(sprintf($ELEMENT_JSON_C, $t, strval($o->attributes()->id)))) 
							{
								file_put_contents(sprintf($ELEMENT_JSON_C, $t, strval($o->attributes()->id)), json_encode($o));
							}
						}
					}
				}
				$these_ids = "";
			} else {
				$these_ids .= ($these_ids != "" ? "," : "") . $this_obj;
			}
		}
	}
	$fc = get_url_contents(sprintf($GET_ELEMENTS, $LONG_TYPES[$t], $LONG_TYPES[$t], $these_ids . "," . $this_obj));
	$xml = simplexml_load_string($fc);

	if ($xml != null) {
		foreach($xml->{$LONG_TYPES[$t]} as $o) {
			foreach($xml->{$LONG_TYPES[$t]} as $o) {
				if (
					!file_exists(sprintf($ELEMENT_JSON, $t, strval($o->attributes()->id), strval($o->attributes()->version))) 
					&& 
					!file_exists(sprintf($ELEMENT_JSON_C, $t, strval($o->attributes()->id)))) 
				{
					file_put_contents(sprintf($ELEMENT_JSON_C, $t, strval($o->attributes()->id)), json_encode($o));
				}
			}
		}
	}
}

foreach(glob($JSON_DIR . "*-*-current.json") as $fn) {
	preg_match("/([nwr])\-(\d*)\-current\.json/", $fn, $m);
	foreach(glob($JSON_DIR . $m[1] . "-" . $m[2] . "v*.json") as $fn2) {
		$xml1 = json_decode(file_get_contents($fn));
		$xml2 = json_decode(file_get_contents($fn2));
		$these_changes = Array("type" => $LONG_TYPES[$m[1]], "id" => $m[2], "changed_with" => $xml1->{"@attributes"}->changeset, "changed_by" => $xml1->{"@attributes"}->user, "changed_at" => $xml1->{"@attributes"}->timestamp);
		if ($m[1] == "n") {
			foreach(array("lat", "lon") as $a) {
				if ($xml1->{"@attributes"}->{$a} <> $xml2->{"@attributes"}->{$a}) $these_changes["attributes"][$a] = Array("old" => $xml2->{"@attributes"}->{$a}, "new" => $xml1->{"@attributes"}->{$a});
			}
		}
		if ($xml1->{"@attributes"}->visible <> $xml2->{"@attributes"}->visible) $these_changes["attributes"]["visible"] = Array("old" => $xml2->{"@attributes"}->visible, "new" => $xml1->{"@attributes"}->visible);
		if ($m[1] == "w") {
			if (isset($xml1->nd)) {
				foreach($xml1->nd as $nd1) {
					$new_in_orig = false;
					foreach($xml2->nd as $nd2) if ($nd1->{"@attributes"}->ref == $nd2->{"@attributes"}->ref) $new_in_orig = true;
					if (!$new_in_orig) $these_changes["members"][] = Array("method" => "added", "id" => "n".strval($nd1->{"@attributes"}->ref));
				}
				foreach($xml2->nd as $nd2) {
					$orig_in_new = false;
					foreach($xml1->nd as $nd1) if ($nd1->{"@attributes"}->ref == $nd2->{"@attributes"}->ref) $orig_in_new = true;
					if (!$orig_in_new) $these_changes["members"][] = Array("method" => "removed", "id" => "n".strval($nd2->{"@attributes"}->ref));
				}
			}
		}
		if ($m[1] == "r") {
			if (isset($xml1->member)) {
				foreach($xml1->member as $nd1) {
					$new_in_orig = false;
					foreach($xml2->member as $nd2) if ($nd1->{"@attributes"}->ref == $nd2->{"@attributes"}->ref && $nd1->{"@attributes"}->type == $nd2->{"@attributes"}->type) $new_in_orig = true;
					if (!$new_in_orig) $these_changes["members"][] = Array("method" => "added", "id" => substr($nd1->{"@attributes"}->type, 0, 1).strval($nd1->{"@attributes"}->ref));
				}
				foreach($xml2->member as $nd2) {
					$orig_in_new = false;
					foreach($xml1->member as $nd1) if ($nd1->{"@attributes"}->ref == $nd2->{"@attributes"}->ref && $nd1->{"@attributes"}->type == $nd2->{"@attributes"}->type) $orig_in_new = true;
					if (!$orig_in_new) $these_changes["members"][] = Array("method" => "added", "id" => substr($nd2->{"@attributes"}->type, 0, 1).strval($nd2->{"@attributes"}->ref));
				}
			}
		}
		if (isset($xml1->tag)) {
			foreach($xml1->tag as $nd1) {
				$new_in_orig = false;
				foreach($xml2->tag as $nd2) if ($nd1->{"@attributes"}->k == $nd2->{"@attributes"}->k) $new_in_orig = true;
				if (!$new_in_orig) $these_changes["tags"][] = Array("method" => "added", "tag" => $nd1->{"@attributes"}->k."=".strval($nd1->{"@attributes"}->v));
			}
			foreach($xml2->tag as $nd2) {
				$orig_in_new = false;
				foreach($xml1->tag as $nd1) if ($nd1->{"@attributes"}->k == $nd2->{"@attributes"}->k) $orig_in_new = true;
				if (!$orig_in_new) $these_changes["tags"][] = Array("method" => "removed", "tag" => $nd2->{"@attributes"}->k);
			}
			foreach($xml1->tag as $nd1) {
				$new_in_orig = false;
				foreach($xml2->tag as $nd2) {
					if ($nd1->{"@attributes"}->k == $nd2->{"@attributes"}->k) {
						$old_val = strval($nd2->{"@attributes"}->v);
						$new_val = strval($nd1->{"@attributes"}->v);
					}
				}
				if ($new_val != $old_val) $these_changes["tags"][] = Array("method" => "changed", "tag" => Array("new" => $nd1->{"@attributes"}->k."=".$new_val, "old" => $nd1->{"@attributes"}->k."=".$old_val));
			}
		}
		$changes_by_cs[strval($xml2->{"@attributes"}->changeset)][] = $these_changes;
	}
}
$orig_cse = array_keys($changes_by_cs);
sort($orig_cse);

ob_start();
?>
<html>
	<head>
		<meta charset="utf-8">
		<title>Changeset Analyse f&uuml;r <?php echo $DISPLAY_NAME; ?></title>
		<style type="text/css">
		body {
			font-family: 'Arial',sans-serif;
		}
		span.pre {
			unicode-bidi: embed;
			font-family: monospace;
			white-space: pre;
		}
		</style>
	</head>
<body>
<?php
echo "<h1>&Auml;nderungen an deinen Objekten</h1>\n";
echo "Permalink zu dieser Auswertung: <a href='https://osm.poppe.dev/changeset-analyzer/changeset-analysis-" . $DISPLAY_NAME . ".html'>https://osm.poppe.dev/changeset-analyzer/changeset-analysis-" . $DISPLAY_NAME . ".html</a>\n";
echo "<hr/>";
foreach($orig_cse as $c) {
	foreach($cs_list as $list_cse) {
		if ($list_cse->id == $c) { $this_created = $list_cse->created_at; $this_comment = $list_cse->comment; }
	}
	echo "<h2>Original Changeset <a href='https://osm.org/changeset/$c' target='_blank'>$c</a> vom $this_created</h2>\n";
	foreach($changes_by_cs[$c] as $o) {
		if (isset($o["attributes"]) || isset($o["members"]) || isset($o["tags"])) {
			echo "<h3><a href='https://osm.org/{$o["type"]}/{$o["id"]}' target='_blank'>".strtoupper(substr($o["type"],0,1)).substr($o["type"],1)." {$o["id"]}</a></h3>\n";
			echo "Zuletzt ge&auml;ndert am {$o["changed_at"]} von <a href='https://osm.org/user/{$o["changed_by"]}' target='_blank'>{$o["changed_by"]}</a> mit <a href='https://osm.org/changeset/{$o["changed_with"]}' target='_blank'>Changeset {$o["changed_with"]}</a>\n";
			if (isset($o["attributes"])) {
				echo "<h4>Attribute</h4>\n<ul>";
				foreach(array_keys($o["attributes"]) as $a) {
					echo "<li><span class='pre'><b>$a</b></span> - Deins: <span class='pre'>{$o["attributes"][$a]["old"]}</span> - Jetzt: <span class='pre'>{$o["attributes"][$a]["new"]}</span></li>\n";
				}
				echo "</ul>\n";
			}
			if (isset($o["members"])) {
				echo "<h4>Mitglieder</h4><ul>\n";
				foreach($o["members"] as $m) {
					echo "<li><b>{$m["method"]}</b> <span class='pre'><a href='https://osm.org/{$LONG_TYPES[substr($m["id"],0,1)]}/".substr($m["id"],1)."' target='_blank'>{$m["id"]}</a></span></li>\n";
				}
				echo "</ul>\n";
			}
			if (isset($o["tags"])) {
				echo "<h4>Tags</h4><ul>\n";
				foreach($o["tags"] as $m) {
					echo "<li><b>{$m["method"]}</b> " . ($m["method"] == "changed" ? "- Deins: <span class='pre'>{$m["tag"]["old"]}</span> - Jetzt: <span class='pre'>{$m["tag"]["new"]}</span>" : "<span class='pre'>{$m["tag"]}</span>")."</li>\n";
				}
				echo "</ul>";
			}
		}
	}
	echo "<hr/>\n";
}

$fc = ob_get_clean();
file_put_contents("changeset-analysis-" . $DISPLAY_NAME . ".html", $fc);

header("Location: changeset-analysis-" . $DISPLAY_NAME . ".html");
?>

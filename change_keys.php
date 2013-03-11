#!/usr/bin/php
<?php
include_once 'Archive/Tar.php'; 

@ini_set('display_errors', 'on');

if(count($argv) < 3)
{
	echo "Usage: " . basename($argv[0]) . " gzip_to_modify english_gzip_or_csv_to_use [english_gzip_or_csv_to_use ...]\n";
	exit;
}

$target 	= $argv[1];

if(!file_exists($target))
{
	echo "The file '$target' doesn't seem to exist!\n";
	exit;
}
/*
if(!file_exists($en))
{
	echo "The file '$en' doesn't seem to exist!\n";
	exit;
}
if(basename($en) != "en.gzip")
{
	echo "The English pack to use must be named en.gzip!\n";
	exit;
}*/


$en_changes_in_files = array();
$en_uncontextualized_changes = array();
$en_count   = 0;

function slashify($str)
{
	return preg_replace('/\\\\*([\'"])/', "\\\\$1", $str);
}

function readGZIPChanges($src, &$en_changes_in_files)
{
	$total = 0;
	$en_gzip = new Archive_Tar($src);
	foreach($en_gzip->listContent() as $desc)
	{
		$filename = $desc['filename'];
		$source = $en_gzip->extractInString($filename);
		$m = array();
		if(preg_match('/\.php$/', $filename) and basename($filename) != 'index.php' and preg_match('/global\s+\$(_\w+)/', $source, $m))
		{
			$arr = $m[1];
			echo "Parsing $filename to extract array $arr\n";
			
			eval(substr($source, 5));
			$count = 0;
			if(!isset($en_changes_in_files[$filename]))
			{
				$en_changes_in_files[$filename] = array();
			}
			foreach($$arr as $key => $value)
			{
				$m = array();
				if(preg_match('/^(.*?)([a-f0-9]{32})$/', $key, $m))
				{
					$md5 = $m[2];
					$new_md5 = md5(slashify($value));
					if($md5 != $new_md5)
					{
						//if($arr == '_LANGADM' and strpos($value, '&lt') !== false)echo "Changed: $value\n";
						if(!isset($en_changes_in_files[$filename][$key]))$en_changes_in_files[$filename][$key] = array();
						$en_changes_in_files[$filename][$key][$m[1] . $new_md5] = true;
						$count += 1;
					}
				}
			}
			$total += $count;
			echo "$count of " . count($$arr) . " English strings were changed in the file $filename!\n\n";
		}
	}
	return $total;
}

function readCSVChanges($src, &$en_uncontextualized_changes)
{
	$count = 0;
	$f = fopen($src,'r');
	$headers = fgetcsv($f);
	$wanted  = array('Old Key', 'New English Text');
	if(count(array_diff($wanted, $headers)) > 0)
	{
		echo "Required headers not found in CSV (Old Key and New English Text)!\n";
		exit;
	}
	while($row = fgetcsv($f))
	{
		$row = array_combine($headers, $row);
		$key = $row['Old Key'];
		$m = array();
		if(preg_match('/^(.*?)([a-f0-9]{32})$/', $key, $m))
		{
			$md5 = $m[2];
			$new_md5 = md5(slashify($row['New English Text']));
			if($md5 != $new_md5)
			{
				$en_uncontextualized_changes[$key] = $m[1] . $new_md5;
				$count += 1;
			}
		}
	}

	return $count;
}

function readChanges($src, &$en_changes_in_files, &$en_uncontextualized_changes)
{
	if(!file_exists($src))
	{
		echo "Oops: file '$src' doesn't exist!\n";
		exit;
	}

	if(preg_match('/^en(?:_.*?)?\.gzip$/', basename($src)))
	{
		echo "Found GZIP $src!\n";
		return readGZIPChanges($src,$en_changes_in_files);
	}
	else if(preg_match('/\.csv$/', $src))
	{
		return readCSVChanges($src, $en_uncontextualized_changes);
	}
	else
	{
		echo "Unsupported file: '$src' :(\n";
		exit;
	}
}

$en_count = 0;
for($i = 2; $i < count($argv); $i+=1)
{
	$en_count += readChanges($argv[$i], $en_changes_in_files, $en_uncontextualized_changes);
}

$target_gzip = new Archive_Tar($target);
$iso = basename($target, ".gzip");

$dst = "$iso.gzip";
if(file_exists($dst))
{
	unlink($dst);
}

$new_target_gzip = new Archive_Tar($dst, 'gz');

$count = 0;
foreach($target_gzip->listContent() as $desc)
{
	$filename = $desc['filename'];
	echo "Changing keys in file $filename...\n";

	$en_filename = str_replace("/$iso.php", '/en.php', str_replace("/$iso/", '/en/', $filename));
	
	$source = $target_gzip->extractInString($filename);

	$m = array();
	if(preg_match('/global\s+\$(_\w+)/', $source, $m))
	{
		$nreplaced   =  0;
		$arr = $m[1];
		eval(substr($source, 5));
		$lang = $$arr;

		if(isset($en_changes_in_files[$en_filename]))
		{	
			
			
			foreach($en_changes_in_files[$en_filename] as $old_key => $new_keys)
			{
				foreach($new_keys as $new_key => $unused)
				{
					if(isset($lang[$old_key]))
					{
						$lang[$new_key] = $lang[$old_key];
						$nreplaced+=1;
					}
				}
			}
			
		}

		foreach($en_uncontextualized_changes as $old_key => $new_key)
		{
			if(isset($lang[$old_key]))
			{
				$lang[$new_key] = $lang[$old_key];
				$nreplaced+=1;
			}	
		}

		$source  = "<?php\n\nglobal \$$arr;\n\$$arr = array();";

		foreach($lang as $key => $translation)
		{
			$source .= "\n\$$arr"."['" . slashify($key) . "'] = '". slashify($translation) . "';";
		}
		$source .= "\n";

		echo "Changed $nreplaced keys in file $filename!\n\n";
		$count += $nreplaced;
	}

	$new_target_gzip->addString($filename, $source);

}

echo "\nChanged $count keys in $target (of $en_count)\n";
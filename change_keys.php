#!/usr/bin/php
<?php
include_once 'Archive/Tar.php'; 

@ini_set('display_errors', 'on');

/************************************************/
/*				HELPER FUNCTIONS 				*/
/************************************************/

//create a temporary directory
//warning, subject to tiny race condition, couldn't find any better
function tempdir()
{
    $tempfile=tempnam(sys_get_temp_dir(),'');
    if(file_exists($tempfile))
    { 
    	unlink($tempfile);
    	mkdir($tempfile);
    }

    if(is_dir($tempfile))return $tempfile;
    else return false;
}

//recursively delete directory
function rrmdir($dir) 
{ 
   if(is_dir($dir)) 
   { 
	     $objects = scandir($dir); 
	     foreach ($objects as $object)
	     { 
	       	if ($object != "." && $object != "..")
	       	{ 
	         	if (filetype($dir."/".$object) == "dir")rrmdir($dir."/".$object); 
	         	else unlink($dir."/".$object); 
	       	} 
    	} 
    	reset($objects); 
    	rmdir($dir); 
   } 
 }


//file put contents with directory structure creation
function file_put_contents_with_parents($path, $data)
{
	$dir = dirname($path);
	if(!is_dir($dir))
	{
		mkdir($dir, 0777, true);
	}
	file_put_contents($path, $data);
}

//return an array with the (recursive) list of files in a directory with full path
function file_list($dir)
{
	$files = array();
	//lol, php
	foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $path)
	{
		if(is_file($path) and !is_dir($path))$files[] = $path;
	}
	return $files;
}

/************************************************/
/************************************************/
/************************************************/

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
	$dir   = tempdir(); 
	// for some reason Archive_Tar's extractInString is painfully slow on some platforms, so we extract in a temporary dir
	$en_gzip->extract($dir);
	foreach($en_gzip->listContent() as $desc)
	{
		$filename = $desc['filename'];
		$path     = "$dir/$filename";
		$source   = file_get_contents($path);
		$m = array();
		if(preg_match('/\.php$/', $filename) and basename($filename) != 'index.php' and preg_match('/global\s+\$(_\w+)/', $source, $m))
		{
			$arr = $m[1];
			echo "Including $filename to extract array $arr\n";
			
			include_once($path);
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
						$en_changes_in_files[$filename][$key][$m[1] . $new_md5] = 0;
					}
				}
			}
			$count  = count($en_changes_in_files[$filename]);
			$total += $count;
			echo "$count of " . count($$arr) . " English strings were changed in the file $filename!\n\n";
		}
	}
	rrmdir($dir);
	return $total;
}

function readCSVChanges($src, &$en_uncontextualized_changes)
{
	$count = count($en_uncontextualized_changes);

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
				$en_uncontextualized_changes[$key][$m[1] . $new_md5] = 0;
			}
		}
	}

	return count($en_uncontextualized_changes) - $count;
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

$dir     = tempdir();
$out_dir = tempdir();

$target_gzip->extract($dir);
foreach($target_gzip->listContent() as $desc)
{
	$filename = $desc['filename'];
	$path     = "$dir/$filename";
	echo "Changing keys in file $filename...\n";

	$en_filename = str_replace("/$iso.php", '/en.php', str_replace("/$iso/", '/en/', $filename));
	
	$source = file_get_contents($path);

	$m = array();
	if(preg_match('/global\s+\$(_\w+)/', $source, $m))
	{
		$nreplaced   =  0;
		$arr = $m[1];
		include_once($path);
		$lang = $$arr;

		if(isset($en_changes_in_files[$en_filename]))
		{	
			foreach($en_changes_in_files[$en_filename] as $old_key => &$new_keys)
			{
				foreach($new_keys as $new_key => &$use_count)
				{
					if(isset($lang[$old_key]))
					{
						$use_count += 1;

						if(!isset($lang[$new_key]))
						{
							$lang[$new_key] = $lang[$old_key];
							$nreplaced += 1;
						}
					}
				}
			}
			
		}

		foreach($en_uncontextualized_changes as $old_key => &$new_keys)
		{
			foreach($new_keys as $new_key => &$use_count)
				{
					if(isset($lang[$old_key]))
					{
						$use_count += 1;

						if(!isset($lang[$new_key]))
						{
							$lang[$new_key] = $lang[$old_key];
							$nreplaced += 1;
						}
					}
				}		
		}

		$source  = "<?php\n\nglobal \$$arr;\n\$$arr = array();";

		foreach($lang as $key => $translation)
		{
			$source .= "\n\$$arr"."['" . slashify($key) . "'] = '". slashify($translation) . "';";
		}
		$source .= "\n";

		echo "Changed $nreplaced keys in file $filename!\n\n";
	}

	//$new_target_gzip->addString($filename, $source);
	file_put_contents_with_parents("$out_dir/$filename", $source);

}
rrmdir($dir);
$new_target_gzip->createModify(file_list($out_dir),'',$out_dir);
rrmdir($out_dir);

$count         = 0;
$show_not_used = function($arr) use (&$count)
{
	foreach($arr as $old_key => $new_keys)
	{
		$used = false;
		foreach($new_keys as $new_key => $use_count)
		{
			if($use_count > 0)
			{
				$used   = true;
				$count += 1;
				break;
			}
		}
		if(!$used)
		{
			echo "Key $old_key was never found, so not replaced!\n";
		}
	}
};

foreach($en_changes_in_files as $file => $changes)
{
	$show_not_used($changes);
}

$show_not_used($en_uncontextualized_changes);

echo "\nChanged $count keys in '$target' (of $en_count)\n";